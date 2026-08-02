<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
requirePermission(PERMISSION_MANAGE_INVENTORY);

require_once __DIR__ . '/../db_connection.php';
if (function_exists('getConnection')) { $conn = getConnection(); } else { global $pdo; $conn = $pdo; }
require_once __DIR__ . '/../config/inventory_tables.php';

try {
    $restaurant_id = $_SESSION['restaurant_id'] ?? '';
    if (empty($restaurant_id)) {
        echo json_encode(['success' => false, 'message' => 'No restaurant session']);
        exit;
    }

    ensureInventoryTables($conn);

    $stmt = $conn->prepare("SELECT * FROM inventory_items WHERE restaurant_id = ? AND is_active = 1 ORDER BY item_name ASC");
    $stmt->execute([$restaurant_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalValue = 0;
    $lowStockCount = 0;
    foreach ($items as &$item) {
        $qty = (float)$item['quantity_in_stock'];
        $threshold = (float)$item['low_stock_threshold'];
        $cost = (float)$item['cost_per_unit'];
        $value = $qty * $cost;
        $item['stock_value'] = $value;
        $item['is_low_stock'] = ($qty <= $threshold);
        $totalValue += $value;
        if ($item['is_low_stock']) $lowStockCount++;
    }
    unset($item);

    echo json_encode([
        'success' => true,
        'items' => $items,
        'summary' => [
            'total_items' => count($items),
            'low_stock_count' => $lowStockCount,
            'total_stock_value' => $totalValue
        ]
    ]);
} catch (Exception $e) {
    error_log("Error in get_inventory.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
