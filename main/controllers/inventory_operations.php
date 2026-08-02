<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
requirePermission(PERMISSION_MANAGE_INVENTORY);

require_once __DIR__ . '/../db_connection.php';
if (function_exists('getConnection')) { $conn = getConnection(); } else { global $pdo; $conn = $pdo; }
require_once __DIR__ . '/../config/inventory_tables.php';

$action = $_POST['action'] ?? '';

try {
    $restaurant_id = $_SESSION['restaurant_id'] ?? '';
    if (empty($restaurant_id)) throw new Exception('No restaurant session');

    ensureInventoryTables($conn);

    switch ($action) {
        case 'add':
            $name = trim($_POST['item_name'] ?? '');
            $unit = trim($_POST['unit'] ?? 'unit');
            $category = trim($_POST['category'] ?? '');
            $threshold = (float)($_POST['low_stock_threshold'] ?? 0);
            $cost = (float)($_POST['cost_per_unit'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');

            if (!$name) throw new Exception('Item name is required');
            if ($threshold < 0) throw new Exception('Low stock threshold cannot be negative');
            if ($cost < 0) throw new Exception('Cost per unit cannot be negative');

            $check = $conn->prepare("SELECT id FROM inventory_items WHERE restaurant_id = ? AND item_name = ? AND is_active = 1");
            $check->execute([$restaurant_id, $name]);
            if ($check->fetch()) throw new Exception('An inventory item with this name already exists');

            // New items always start at zero stock — stock is only ever added
            // via "Restock" so every unit on hand has a recorded cost and, in
            // turn, a matching expense entry (single source of truth).
            $stmt = $conn->prepare("INSERT INTO inventory_items (restaurant_id, item_name, unit, category, quantity_in_stock, low_stock_threshold, cost_per_unit, notes) VALUES (?, ?, ?, ?, 0, ?, ?, ?)");
            $stmt->execute([$restaurant_id, $name, $unit, $category, $threshold, $cost, $notes]);
            echo json_encode(['success' => true, 'message' => 'Inventory item created. Use "Restock" to add stock.']);
            break;

        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['item_name'] ?? '');
            $unit = trim($_POST['unit'] ?? 'unit');
            $category = trim($_POST['category'] ?? '');
            $threshold = (float)($_POST['low_stock_threshold'] ?? 0);
            $cost = (float)($_POST['cost_per_unit'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');

            if ($id <= 0) throw new Exception('Invalid item id');
            if (!$name) throw new Exception('Item name is required');
            if ($threshold < 0) throw new Exception('Low stock threshold cannot be negative');
            if ($cost < 0) throw new Exception('Cost per unit cannot be negative');

            $check = $conn->prepare("SELECT id FROM inventory_items WHERE restaurant_id = ? AND item_name = ? AND is_active = 1 AND id != ?");
            $check->execute([$restaurant_id, $name, $id]);
            if ($check->fetch()) throw new Exception('An inventory item with this name already exists');

            $stmt = $conn->prepare("UPDATE inventory_items SET item_name=?, unit=?, category=?, low_stock_threshold=?, cost_per_unit=?, notes=? WHERE id=? AND restaurant_id=?");
            $stmt->execute([$name, $unit, $category, $threshold, $cost, $notes, $id, $restaurant_id]);
            echo json_encode(['success' => true, 'message' => 'Inventory item updated']);
            break;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid item id');
            // Soft delete — keeps the transaction/expense history for past
            // purchases of this item intact for reporting.
            $stmt = $conn->prepare("UPDATE inventory_items SET is_active = 0 WHERE id = ? AND restaurant_id = ?");
            $stmt->execute([$id, $restaurant_id]);
            echo json_encode(['success' => true, 'message' => 'Inventory item deleted']);
            break;

        case 'restock':
            $id = (int)($_POST['id'] ?? 0);
            $quantity = (float)($_POST['quantity'] ?? 0);
            $costPerUnit = $_POST['cost_per_unit'] !== '' ? (float)($_POST['cost_per_unit'] ?? 0) : null;
            $notes = trim($_POST['notes'] ?? '');
            $expenseDate = $_POST['expense_date'] ?: date('Y-m-d');

            if ($id <= 0) throw new Exception('Invalid item id');
            if ($quantity <= 0) throw new Exception('Restock quantity must be greater than zero');
            if ($costPerUnit !== null && $costPerUnit < 0) throw new Exception('Cost per unit cannot be negative');

            $itemStmt = $conn->prepare("SELECT * FROM inventory_items WHERE id = ? AND restaurant_id = ?");
            $itemStmt->execute([$id, $restaurant_id]);
            $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) throw new Exception('Inventory item not found');

            $useCost = $costPerUnit !== null ? $costPerUnit : (float)$item['cost_per_unit'];
            $totalCost = $quantity * $useCost;

            $conn->beginTransaction();
            try {
                $txStmt = $conn->prepare("INSERT INTO inventory_transactions (restaurant_id, inventory_item_id, type, quantity, cost_per_unit, total_cost, notes) VALUES (?, ?, 'restock', ?, ?, ?, ?)");
                $txStmt->execute([$restaurant_id, $id, $quantity, $useCost, $totalCost, $notes]);
                $txId = $conn->lastInsertId();

                $updStmt = $conn->prepare("UPDATE inventory_items SET quantity_in_stock = quantity_in_stock + ?, cost_per_unit = ? WHERE id = ? AND restaurant_id = ?");
                $updStmt->execute([$quantity, $useCost, $id, $restaurant_id]);

                if ($totalCost > 0) {
                    $expDesc = 'Restock: ' . $item['item_name'] . ' x' . $quantity . ' ' . $item['unit'];
                    $expStmt = $conn->prepare("INSERT INTO expenses (restaurant_id, category, amount, description, expense_date, source, reference_id) VALUES (?, 'Inventory Purchase', ?, ?, ?, 'inventory', ?)");
                    $expStmt->execute([$restaurant_id, $totalCost, $expDesc, $expenseDate, $txId]);
                }

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }

            echo json_encode(['success' => true, 'message' => 'Stock added' . ($totalCost > 0 ? ' and expense recorded' : '')]);
            break;

        case 'adjust':
            // Manual correction (physical count) or wastage. Wastage records
            // a loss expense since that stock was already paid for and is
            // now gone — a real cost the owner should see in reports.
            $id = (int)($_POST['id'] ?? 0);
            $type = $_POST['adjust_type'] ?? 'adjustment'; // 'adjustment' or 'wastage'
            $quantity = (float)($_POST['quantity'] ?? 0); // positive number; direction implied by type
            $notes = trim($_POST['notes'] ?? '');
            $expenseDate = $_POST['expense_date'] ?: date('Y-m-d');

            if ($id <= 0) throw new Exception('Invalid item id');
            if ($quantity == 0) throw new Exception('Quantity must not be zero');
            if (!in_array($type, ['adjustment', 'wastage'], true)) $type = 'adjustment';

            $itemStmt = $conn->prepare("SELECT * FROM inventory_items WHERE id = ? AND restaurant_id = ?");
            $itemStmt->execute([$id, $restaurant_id]);
            $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) throw new Exception('Inventory item not found');

            // Wastage always reduces stock; adjustment can go either way via
            // the sign the client sends (e.g. "-2" for a shortfall found).
            $delta = $type === 'wastage' ? -abs($quantity) : $quantity;
            $newQty = (float)$item['quantity_in_stock'] + $delta;
            if ($newQty < 0) throw new Exception('Cannot reduce stock below zero');

            $cost = (float)$item['cost_per_unit'];
            $totalCost = abs($delta) * $cost;

            $conn->beginTransaction();
            try {
                $txStmt = $conn->prepare("INSERT INTO inventory_transactions (restaurant_id, inventory_item_id, type, quantity, cost_per_unit, total_cost, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $txStmt->execute([$restaurant_id, $id, $type, $delta, $cost, $totalCost, $notes]);
                $txId = $conn->lastInsertId();

                $updStmt = $conn->prepare("UPDATE inventory_items SET quantity_in_stock = ? WHERE id = ? AND restaurant_id = ?");
                $updStmt->execute([$newQty, $id, $restaurant_id]);

                if ($type === 'wastage' && $totalCost > 0) {
                    $expDesc = 'Wastage: ' . $item['item_name'] . ' x' . abs($delta) . ' ' . $item['unit'] . ($notes ? ' (' . $notes . ')' : '');
                    $expStmt = $conn->prepare("INSERT INTO expenses (restaurant_id, category, amount, description, expense_date, source, reference_id) VALUES (?, 'Inventory Wastage', ?, ?, ?, 'inventory', ?)");
                    $expStmt->execute([$restaurant_id, $totalCost, $expDesc, $expenseDate, $txId]);
                }

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }

            echo json_encode(['success' => true, 'message' => $type === 'wastage' ? 'Wastage recorded' : 'Stock adjusted']);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    error_log("Error in inventory_operations.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
