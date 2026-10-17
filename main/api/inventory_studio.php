<?php
/**
 * Menu › Inventory (assets/js/inventory-studio.js).
 *
 *   GET ?view=overview   numbers, what to reorder, use-by dates, recent movements, settings
 *   GET ?view=items      stock items with supplier, dishes that use them, daily use and days left
 *   GET ?view=recipes    dishes with their recipe, cost per plate, food cost % and plates left
 *   GET ?view=suppliers  suppliers with items, open orders and 90-day spend
 *   GET ?view=orders     purchase orders with their lines
 *   GET ?view=activity&type=&item=&from=&to=   stock movements (latest 300)
 *   GET ?view=reports&days=30   use, wastage, purchases and food cost for a period
 *
 *   POST JSON {action: item_save | item_delete | restock | adjust | count | recipe_save | settings_save |
 *                      supplier_save | supplier_delete | po_save | po_status | po_receive}
 *
 * Every view first brings stock up to date with orders (config/inventory_engine.php → invSyncSales).
 * Purchases add an "Inventory Purchase" expense and wastage an "Inventory Wastage" expense, as before.
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}
if (!hasPermission(PERMISSION_MANAGE_INVENTORY)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have access to inventory.']);
    exit;
}
$rid = $_SESSION['restaurant_id'];
$who = (string)($_SESSION['username'] ?? $_SESSION['staff_name'] ?? $_SESSION['user_name'] ?? '');
session_write_close();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/inventory_engine.php';

function ivOut(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function ivFail(string $m, int $code = 400) { ivOut(['success' => false, 'message' => $m], $code); }
function ivNum($v): float { return is_numeric($v) ? (float)$v : 0.0; }
function ivDate($v): ?string { return (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null; }

try {
    $conn = getConnection();
    invEnsure($conn);
    $q = function (string $sql, array $args = []) use ($conn) { $st = $conn->prepare($sql); $st->execute($args); return $st; };
    $cur = (string)($q('SELECT currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1', [$rid])->fetchColumn() ?: '₹');
    $UNITS = ['kg', 'g', 'L', 'ml', 'pcs', 'dozen', 'box', 'packet', 'bottle', 'can', 'tray', 'bunch', 'unit'];

    $item = function (int $id) use ($q, $rid) {
        $r = $q('SELECT * FROM inventory_items WHERE id = ? AND restaurant_id = ? AND is_active = 1', [$id, $rid])->fetch(PDO::FETCH_ASSOC);
        if (!$r) ivFail('That stock item was not found.');
        return $r;
    };
    // a stock movement; $expense = [category, description] adds a matching expense
    $move = function (int $iid, string $type, float $qty, float $cost, string $notes, ?array $expense = null, ?string $refType = null, ?int $refId = null, ?string $date = null) use ($q, $conn, $rid, $who) {
        $total = round(abs($qty) * $cost, 2);
        $q('INSERT INTO inventory_transactions (restaurant_id, inventory_item_id, type, quantity, cost_per_unit, total_cost, notes, reference_type, reference_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$rid, $iid, $type, $qty, $cost, $total, mb_substr($notes, 0, 255), $refType, $refId, mb_substr($who, 0, 100)]);
        $txId = (int)$conn->lastInsertId();
        $q('UPDATE inventory_items SET quantity_in_stock = quantity_in_stock + ? WHERE id = ? AND restaurant_id = ?', [$qty, $iid, $rid]);
        if ($expense && $total > 0) {
            $q("INSERT INTO expenses (restaurant_id, category, amount, description, expense_date, source, reference_id) VALUES (?, ?, ?, ?, ?, 'inventory', ?)",
                [$rid, $expense[0], $total, mb_substr($expense[1], 0, 255), $date ?: date('Y-m-d'), $txId]);
        }
        return $txId;
    };
    $fmtQty = function ($n) { $n = round((float)$n, 2); return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'); };

    /* ───────────── POST ───────────── */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = (string)($in['action'] ?? '');

        if ($action === 'item_save') {
            $id = (int)($in['id'] ?? 0);
            $name = trim((string)($in['item_name'] ?? ''));
            $unit = in_array($in['unit'] ?? '', $UNITS, true) ? $in['unit'] : 'unit';
            $cat = mb_substr(trim((string)($in['category'] ?? '')), 0, 100);
            $low = max(0, ivNum($in['low_stock_threshold'] ?? 0));
            $par = max(0, ivNum($in['par_level'] ?? 0));
            $cost = max(0, ivNum($in['cost_per_unit'] ?? 0));
            $sku = mb_substr(trim((string)($in['sku'] ?? '')), 0, 60);
            $notes = mb_substr(trim((string)($in['notes'] ?? '')), 0, 255);
            $sup = (int)($in['supplier_id'] ?? 0) ?: null;
            $exp = ivDate($in['expiry_date'] ?? '');
            if ($name === '') ivFail('Give the item a name.');
            if ($sup && !$q('SELECT id FROM inventory_suppliers WHERE id = ? AND restaurant_id = ? AND is_active = 1', [$sup, $rid])->fetchColumn()) $sup = null;
            if ($q('SELECT id FROM inventory_items WHERE restaurant_id = ? AND item_name = ? AND is_active = 1 AND id != ?', [$rid, $name, $id])->fetchColumn()) ivFail('You already have a stock item with this name.');
            if ($id) {
                $old = $item($id);
                $msg = 'Saved';
                if ($unit !== $old['unit']) {
                    [$fa, $fm] = invUnitBase($old['unit']);
                    [$ta, $tm] = invUnitBase($unit);
                    if ($fa === $ta) {
                        // same kind (kg ↔ g, L ↔ ml): stock, reorder levels and cost all move to the new unit
                        $f = $fm / $tm;
                        $q('UPDATE inventory_items SET quantity_in_stock = quantity_in_stock * ? WHERE id = ? AND restaurant_id = ?', [$f, $id, $rid]);
                        $low = (float)$old['low_stock_threshold'] * $f;
                        $par = (float)$old['par_level'] * $f;
                        $cost = round((float)$old['cost_per_unit'] / $f, 4);
                        $msg = 'Saved. Stock, levels and cost converted to ' . $unit . '.';
                    } elseif ($q('SELECT COUNT(*) FROM inventory_recipes WHERE inventory_item_id = ? AND restaurant_id = ?', [$id, $rid])->fetchColumn()) {
                        ivFail('Recipes measure this item in ' . $old['unit'] . '. Remove it from those recipes before changing it to ' . $unit . '.');
                    }
                }
                $q('UPDATE inventory_items SET item_name=?, unit=?, category=?, low_stock_threshold=?, par_level=?, cost_per_unit=?, sku=?, notes=?, supplier_id=?, expiry_date=? WHERE id=? AND restaurant_id=?',
                    [$name, $unit, $cat, $low, $par, $cost, $sku, $notes, $sup, $exp, $id, $rid]);
                ivOut(['success' => true, 'id' => $id, 'message' => $msg]);
            }
            $conn->beginTransaction();
            $q('INSERT INTO inventory_items (restaurant_id, item_name, unit, category, quantity_in_stock, low_stock_threshold, par_level, cost_per_unit, sku, notes, supplier_id, expiry_date) VALUES (?,?,?,?,0,?,?,?,?,?,?,?)',
                [$rid, $name, $unit, $cat, $low, $par, $cost, $sku, $notes, $sup, $exp]);
            $newId = (int)$conn->lastInsertId();
            $opening = max(0, ivNum($in['opening_stock'] ?? 0));
            // stock already on the shelf: counted in, no expense (it was paid for before)
            if ($opening > 0) $move($newId, 'opening', $opening, $cost, 'Opening stock');
            $conn->commit();
            ivOut(['success' => true, 'id' => $newId, 'message' => 'Item added']);
        }

        if ($action === 'item_delete') {
            $id = (int)($in['id'] ?? 0);
            $item($id);
            $conn->beginTransaction();
            $q('UPDATE inventory_items SET is_active = 0 WHERE id = ? AND restaurant_id = ?', [$id, $rid]);
            $q('DELETE FROM inventory_recipes WHERE inventory_item_id = ? AND restaurant_id = ?', [$id, $rid]);
            $conn->commit();
            ivOut(['success' => true, 'message' => 'Item removed. Its history stays in reports.']);
        }

        if ($action === 'restock') {
            $id = (int)($in['id'] ?? 0);
            $it = $item($id);
            $qty = ivNum($in['quantity'] ?? 0);
            $cost = isset($in['cost_per_unit']) && $in['cost_per_unit'] !== '' ? max(0, ivNum($in['cost_per_unit'])) : (float)$it['cost_per_unit'];
            if ($qty <= 0) ivFail('Enter how much came in.');
            $date = ivDate($in['date'] ?? '') ?: date('Y-m-d');
            $notes = trim((string)($in['notes'] ?? ''));
            $exp = ivDate($in['expiry_date'] ?? '');
            $conn->beginTransaction();
            $move($id, 'restock', $qty, $cost, $notes, ['Inventory Purchase', 'Restock: ' . $it['item_name'] . ' x' . $fmtQty($qty) . ' ' . $it['unit']], null, null, $date);
            $q('UPDATE inventory_items SET cost_per_unit = ?' . ($exp ? ', expiry_date = ?' : '') . ' WHERE id = ? AND restaurant_id = ?', $exp ? [$cost, $exp, $id, $rid] : [$cost, $id, $rid]);
            $conn->commit();
            ivOut(['success' => true, 'message' => 'Stock added' . ($qty * $cost > 0 ? ' and the purchase recorded as an expense' : '')]);
        }

        if ($action === 'adjust') {
            $id = (int)($in['id'] ?? 0);
            $it = $item($id);
            $kind = in_array($in['kind'] ?? '', ['wastage', 'set'], true) ? $in['kind'] : 'adjustment';
            $qty = ivNum($in['quantity'] ?? 0);
            if ($kind === 'set') {
                // "Set stock to": the typed amount becomes the stock; logged as a correction
                if (!isset($in['quantity']) || $in['quantity'] === '' || $qty < 0) ivFail('Enter how much you have now (0 or more).');
                $qty = round($qty - (float)$it['quantity_in_stock'], 3);
                if (abs($qty) < 0.0005) ivOut(['success' => true, 'message' => 'Stock already shows that amount']);
                $kind = 'adjustment';
            } elseif ($qty == 0) ivFail('Enter a quantity.');
            $delta = $kind === 'wastage' ? -abs($qty) : $qty;
            if ($delta < 0 && (float)$it['quantity_in_stock'] + $delta < -0.0005) ivFail('That is more than you have in stock (' . $fmtQty(max(0, (float)$it['quantity_in_stock'])) . ' ' . $it['unit'] . '). For a full recount use Stock count.');
            $reason = trim((string)($in['reason'] ?? ''));
            $notes = trim((string)($in['notes'] ?? ''));
            $label = trim($reason . ($reason && $notes ? ': ' : '') . $notes);
            $conn->beginTransaction();
            $move($id, $kind, $delta, (float)$it['cost_per_unit'], $label,
                $kind === 'wastage' ? ['Inventory Wastage', 'Wastage: ' . $it['item_name'] . ' x' . $fmtQty(abs($delta)) . ' ' . $it['unit'] . ($label ? ' (' . $label . ')' : '')] : null);
            $conn->commit();
            ivOut(['success' => true, 'message' => $kind === 'wastage' ? 'Wastage recorded' : 'Stock corrected']);
        }

        if ($action === 'count') {
            // stock count: the counted amount becomes the stock; the difference is logged per item
            $lines = is_array($in['lines'] ?? null) ? $in['lines'] : [];
            $changed = 0; $value = 0.0;
            $conn->beginTransaction();
            foreach ($lines as $l) {
                if (!isset($l['counted']) || $l['counted'] === '' || !is_numeric($l['counted'])) continue;
                $it = $q('SELECT * FROM inventory_items WHERE id = ? AND restaurant_id = ? AND is_active = 1', [(int)($l['id'] ?? 0), $rid])->fetch(PDO::FETCH_ASSOC);
                if (!$it) continue;
                $counted = max(0, (float)$l['counted']);
                $diff = round($counted - (float)$it['quantity_in_stock'], 3);
                if (abs($diff) < 0.0005) continue;
                $move((int)$it['id'], 'count', $diff, (float)$it['cost_per_unit'], 'Stock count' . (!empty($in['note']) ? ': ' . mb_substr((string)$in['note'], 0, 120) : ''), null, 'count');
                $changed++;
                $value += $diff * (float)$it['cost_per_unit'];
            }
            $conn->commit();
            ivOut(['success' => true, 'changed' => $changed, 'value' => round($value, 2), 'message' => $changed ? "Stock count saved: $changed item" . ($changed === 1 ? '' : 's') . ' corrected' : 'Everything matched. Nothing to change.']);
        }

        if ($action === 'recipe_save') {
            $mid = (int)($in['menu_item_id'] ?? 0);
            if (!$q('SELECT id FROM menu_items WHERE id = ? AND restaurant_id = ?', [$mid, $rid])->fetchColumn()) ivFail('That dish was not found.');
            $lines = is_array($in['lines'] ?? null) ? $in['lines'] : [];
            $conn->beginTransaction();
            $q('DELETE FROM inventory_recipes WHERE menu_item_id = ? AND restaurant_id = ?', [$mid, $rid]);
            $n = 0;
            foreach ($lines as $l) {
                $iid = (int)($l['inventory_item_id'] ?? 0);
                $qty = ivNum($l['quantity'] ?? 0);
                if ($iid <= 0 || $qty <= 0) continue;
                $it = $q('SELECT unit FROM inventory_items WHERE id = ? AND restaurant_id = ? AND is_active = 1', [$iid, $rid])->fetchColumn();
                if ($it === false) continue;
                $unit = in_array($l['unit'] ?? '', $UNITS, true) ? $l['unit'] : $it;
                if (invUnitBase($unit)[0] !== invUnitBase($it)[0]) $unit = $it;   // e.g. "ml" for an item kept in kg
                $q('INSERT INTO inventory_recipes (restaurant_id, menu_item_id, inventory_item_id, quantity, unit) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), unit = VALUES(unit)',
                    [$rid, $mid, $iid, $qty, $unit]);
                $n++;
            }
            // sales start using stock from the first recipe onwards
            $q('INSERT INTO inventory_settings (restaurant_id, auto_deduct, track_from) VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE track_from = IFNULL(track_from, NOW())', [$rid]);
            $conn->commit();
            ivOut(['success' => true, 'message' => $n ? 'Recipe saved' : 'Recipe cleared']);
        }

        if ($action === 'settings_save') {
            $q('INSERT IGNORE INTO inventory_settings (restaurant_id, auto_deduct) VALUES (?, 1)', [$rid]);
            if (array_key_exists('auto_deduct', $in)) {
                $on = !empty($in['auto_deduct']) ? 1 : 0;
                // turning it back on starts counting from now, so orders made while it was off aren't taken twice later
                $q('UPDATE inventory_settings SET track_from = IF(? = 1 AND (auto_deduct = 0 OR track_from IS NULL), NOW(), track_from), auto_deduct = ? WHERE restaurant_id = ?', [$on, $on, $rid]);
            }
            if (array_key_exists('deduct_on', $in)) $q('UPDATE inventory_settings SET deduct_on = ? WHERE restaurant_id = ?', [$in['deduct_on'] === 'placed' ? 'placed' : 'kitchen', $rid]);
            if (array_key_exists('auto_soldout', $in)) $q('UPDATE inventory_settings SET auto_soldout = ? WHERE restaurant_id = ?', [!empty($in['auto_soldout']) ? 1 : 0, $rid]);
            if (array_key_exists('low_alert', $in)) $q('UPDATE inventory_settings SET low_alert = ? WHERE restaurant_id = ?', [!empty($in['low_alert']) ? 1 : 0, $rid]);
            $r = invRefresh($conn, $rid);   // sold-out dishes follow the new setting straight away
            ivOut(['success' => true, 'availability' => $r['availability']]);
        }

        if ($action === 'supplier_save') {
            $id = (int)($in['id'] ?? 0);
            $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 150);
            if ($name === '') ivFail('Give the supplier a name.');
            $vals = [$name, mb_substr(trim((string)($in['contact_person'] ?? '')), 0, 100), mb_substr(preg_replace('/[^0-9+ ]/', '', (string)($in['phone'] ?? '')), 0, 30),
                mb_substr(trim((string)($in['email'] ?? '')), 0, 150), mb_substr(trim((string)($in['notes'] ?? '')), 0, 255)];
            if ($id) {
                $q('UPDATE inventory_suppliers SET name=?, contact_person=?, phone=?, email=?, notes=? WHERE id=? AND restaurant_id=?', array_merge($vals, [$id, $rid]));
            } else {
                $q('INSERT INTO inventory_suppliers (name, contact_person, phone, email, notes, restaurant_id) VALUES (?,?,?,?,?,?)', array_merge($vals, [$rid]));
                $id = (int)$conn->lastInsertId();
            }
            $items = array_map('intval', is_array($in['item_ids'] ?? null) ? $in['item_ids'] : []);
            if (isset($in['item_ids'])) {
                $q('UPDATE inventory_items SET supplier_id = NULL WHERE supplier_id = ? AND restaurant_id = ?', [$id, $rid]);
                foreach ($items as $iid) $q('UPDATE inventory_items SET supplier_id = ? WHERE id = ? AND restaurant_id = ?', [$id, $iid, $rid]);
            }
            ivOut(['success' => true, 'id' => $id, 'message' => 'Supplier saved']);
        }

        if ($action === 'supplier_delete') {
            $id = (int)($in['id'] ?? 0);
            $q('UPDATE inventory_suppliers SET is_active = 0 WHERE id = ? AND restaurant_id = ?', [$id, $rid]);
            $q('UPDATE inventory_items SET supplier_id = NULL WHERE supplier_id = ? AND restaurant_id = ?', [$id, $rid]);
            ivOut(['success' => true, 'message' => 'Supplier removed']);
        }

        if ($action === 'po_save') {
            $id = (int)($in['id'] ?? 0);
            $sup = (int)($in['supplier_id'] ?? 0) ?: null;
            if ($sup && !$q('SELECT id FROM inventory_suppliers WHERE id = ? AND restaurant_id = ?', [$sup, $rid])->fetchColumn()) $sup = null;
            $lines = [];
            foreach ((is_array($in['lines'] ?? null) ? $in['lines'] : []) as $l) {
                $iid = (int)($l['inventory_item_id'] ?? 0); $qty = ivNum($l['quantity'] ?? 0);
                if ($iid <= 0 || $qty <= 0) continue;
                if (!$q('SELECT id FROM inventory_items WHERE id = ? AND restaurant_id = ? AND is_active = 1', [$iid, $rid])->fetchColumn()) continue;
                $lines[] = [$iid, $qty, max(0, ivNum($l['cost_per_unit'] ?? 0))];
            }
            if (!$lines) ivFail('Add at least one item to the order.');
            $total = array_sum(array_map(function ($l) { return $l[1] * $l[2]; }, $lines));
            if ($id) {
                $st = $q('SELECT status FROM inventory_purchase_orders WHERE id = ? AND restaurant_id = ?', [$id, $rid])->fetchColumn();
                if ($st === false) ivFail('That purchase order was not found.');
                if (!in_array($st, ['draft', 'sent'], true)) ivFail('This order is already ' . $st . ' and can’t be changed.');
            }
            $conn->beginTransaction();
            if ($id) {
                $q('UPDATE inventory_purchase_orders SET supplier_id=?, expected_date=?, notes=?, total=? WHERE id=?', [$sup, ivDate($in['expected_date'] ?? ''), mb_substr((string)($in['notes'] ?? ''), 0, 255), $total, $id]);
                $q('DELETE FROM inventory_po_lines WHERE po_id = ?', [$id]);
            } else {
                $n = (int)$q('SELECT COUNT(*) FROM inventory_purchase_orders WHERE restaurant_id = ?', [$rid])->fetchColumn() + 1;
                $q('INSERT INTO inventory_purchase_orders (restaurant_id, po_number, supplier_id, expected_date, notes, total) VALUES (?,?,?,?,?,?)',
                    [$rid, 'PO-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT), $sup, ivDate($in['expected_date'] ?? ''), mb_substr((string)($in['notes'] ?? ''), 0, 255), $total]);
                $id = (int)$conn->lastInsertId();
            }
            foreach ($lines as $l) $q('INSERT INTO inventory_po_lines (po_id, inventory_item_id, quantity, cost_per_unit) VALUES (?,?,?,?)', [$id, $l[0], $l[1], $l[2]]);
            $conn->commit();
            ivOut(['success' => true, 'id' => $id, 'message' => 'Purchase order saved']);
        }

        if ($action === 'po_status') {
            $id = (int)($in['id'] ?? 0);
            $to = in_array($in['status'] ?? '', ['sent', 'cancelled', 'draft'], true) ? $in['status'] : null;
            $st = $q('SELECT status FROM inventory_purchase_orders WHERE id = ? AND restaurant_id = ?', [$id, $rid])->fetchColumn();
            if ($st === false || !$to) ivFail('That purchase order was not found.');
            if ($st === 'received') ivFail('This order has already been received.');
            $q('UPDATE inventory_purchase_orders SET status = ?' . ($to === 'sent' ? ', sent_at = NOW()' : '') . ' WHERE id = ?', [$to, $id]);
            ivOut(['success' => true]);
        }

        if ($action === 'po_receive') {
            // goods arrived: each received line is added to stock as a purchase (with its expense)
            $id = (int)($in['id'] ?? 0);
            $po = $q('SELECT * FROM inventory_purchase_orders WHERE id = ? AND restaurant_id = ?', [$id, $rid])->fetch(PDO::FETCH_ASSOC);
            if (!$po) ivFail('That purchase order was not found.');
            if (in_array($po['status'], ['received', 'cancelled'], true)) ivFail('This order is already ' . $po['status'] . '.');
            $got = [];
            foreach ((is_array($in['lines'] ?? null) ? $in['lines'] : []) as $l) $got[(int)($l['id'] ?? 0)] = $l;
            $supName = $po['supplier_id'] ? (string)$q('SELECT name FROM inventory_suppliers WHERE id = ?', [$po['supplier_id']])->fetchColumn() : '';
            $date = ivDate($in['date'] ?? '') ?: date('Y-m-d');
            $conn->beginTransaction();
            // a double tap (or a second device) waits here and must not add the same delivery and expense twice
            $stNow = $q('SELECT status FROM inventory_purchase_orders WHERE id = ? FOR UPDATE', [$id])->fetchColumn();
            if (in_array($stNow, ['received', 'cancelled'], true)) { $conn->rollBack(); ivFail('This order is already ' . $stNow . '.'); }
            $total = 0.0;
            // items removed since the order was made are skipped
            foreach ($q('SELECT l.*, i.item_name, i.unit FROM inventory_po_lines l JOIN inventory_items i ON i.id = l.inventory_item_id AND i.is_active = 1 WHERE l.po_id = ?', [$id])->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $g = $got[(int)$l['id']] ?? null;
                $qty = $g !== null ? max(0, ivNum($g['received_qty'] ?? 0)) : (float)$l['quantity'];
                $cost = $g !== null && isset($g['cost_per_unit']) && $g['cost_per_unit'] !== '' ? max(0, ivNum($g['cost_per_unit'])) : (float)$l['cost_per_unit'];
                $q('UPDATE inventory_po_lines SET received_qty = ?, cost_per_unit = ? WHERE id = ?', [$qty, $cost, $l['id']]);
                if ($qty <= 0) continue;
                $move((int)$l['inventory_item_id'], 'restock', $qty, $cost, $po['po_number'] . ($supName ? ' from ' . $supName : ''),
                    ['Inventory Purchase', 'Restock: ' . $l['item_name'] . ' x' . $fmtQty($qty) . ' ' . $l['unit'] . ' (' . $po['po_number'] . ($supName ? ', ' . $supName : '') . ')'], 'po', $id, $date);
                $q('UPDATE inventory_items SET cost_per_unit = ? WHERE id = ? AND restaurant_id = ?', [$cost, $l['inventory_item_id'], $rid]);
                $total += $qty * $cost;
            }
            $q("UPDATE inventory_purchase_orders SET status = 'received', received_at = NOW(), total = ? WHERE id = ?", [round($total, 2), $id]);
            $conn->commit();
            ivOut(['success' => true, 'message' => 'Received. Stock and expenses are updated.']);
        }

        ivFail('Unknown action');
    }

    /* ───────────── GET ───────────── */
    $ref = invRefresh($conn, $rid);   // orders, sold-out dishes and low-stock alerts
    $sync = $ref['synced'];
    $view = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'overview';
    $set = invSettings($conn, $rid);

    // items with what they are used for and how fast they go (last 30 days of sales)
    $loadItems = function () use ($q, $rid) {
        $items = $q("SELECT i.*, s.name AS supplier_name FROM inventory_items i LEFT JOIN inventory_suppliers s ON s.id = i.supplier_id AND s.is_active = 1
            WHERE i.restaurant_id = ? AND i.is_active = 1 ORDER BY i.item_name", [$rid])->fetchAll(PDO::FETCH_ASSOC);
        $use = [];
        foreach ($q("SELECT inventory_item_id, -SUM(quantity) q FROM inventory_transactions WHERE restaurant_id = ? AND type IN ('sale','return') AND created_at >= NOW() - INTERVAL 30 DAY GROUP BY inventory_item_id", [$rid])->fetchAll(PDO::FETCH_ASSOC) as $r) $use[(int)$r['inventory_item_id']] = (float)$r['q'];
        $dishes = [];
        foreach ($q('SELECT r.inventory_item_id, m.item_name_en FROM inventory_recipes r JOIN menu_items m ON m.id = r.menu_item_id WHERE r.restaurant_id = ?', [$rid])->fetchAll(PDO::FETCH_ASSOC) as $r) $dishes[(int)$r['inventory_item_id']][] = $r['item_name_en'];
        $today = date('Y-m-d');
        foreach ($items as &$it) {
            $id = (int)$it['id'];
            $qty = (float)$it['quantity_in_stock']; $low = (float)$it['low_stock_threshold']; $par = (float)$it['par_level'];
            $daily = ($use[$id] ?? 0) / 30;
            $it['id'] = $id;
            $it['quantity_in_stock'] = $qty; $it['low_stock_threshold'] = $low; $it['par_level'] = $par; $it['cost_per_unit'] = (float)$it['cost_per_unit'];
            $it['supplier_id'] = $it['supplier_id'] ? (int)$it['supplier_id'] : null;
            $it['stock_value'] = round(max(0, $qty) * (float)$it['cost_per_unit'], 2);
            $it['daily_use'] = round($daily, 3);
            $it['days_left'] = $daily > 0 ? max(0, floor($qty / $daily)) : null;
            $it['dishes'] = $dishes[$id] ?? [];
            $it['status'] = $qty < 0 ? 'negative' : ($qty == 0 ? 'out' : ($low > 0 && $qty <= $low ? 'low' : 'ok'));
            $target = $par > 0 ? $par : ($low > 0 ? $low * 2 : 0);
            $it['reorder_qty'] = ($it['status'] !== 'ok' && $target > $qty) ? round($target - max(0, $qty), 2) : 0;
            $it['expiry_state'] = $it['expiry_date'] ? ($it['expiry_date'] < $today ? 'expired' : ((strtotime($it['expiry_date']) - strtotime($today)) / 86400 <= 3 ? 'soon' : 'ok')) : null;
        }
        unset($it);
        return $items;
    };
    $suppliers = function () use ($q, $rid) {
        return array_map(function ($s) { $s['id'] = (int)$s['id']; return $s; },
            $q('SELECT id, name, contact_person, phone, email, notes FROM inventory_suppliers WHERE restaurant_id = ? AND is_active = 1 ORDER BY name', [$rid])->fetchAll(PDO::FETCH_ASSOC));
    };
    $autoOff = $q('SELECT menu_item_id FROM inventory_auto_off WHERE restaurant_id = ? AND override = 0', [$rid])->fetchAll(PDO::FETCH_COLUMN);
    $base = ['success' => true, 'currency' => $cur, 'units' => $UNITS, 'settings' => $set, 'synced' => $sync, 'availability' => $ref['availability'], 'auto_off' => array_map('intval', $autoOff)];

    if ($view === 'overview') {
        $items = $loadItems();
        $count = function ($s) use ($items) { return count(array_filter($items, function ($i) use ($s) { return in_array($i['status'], (array)$s, true); })); };
        $month = date('Y-m-01');
        $spend = (float)$q("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE restaurant_id = ? AND category = 'Inventory Purchase' AND expense_date >= ?", [$rid, $month])->fetchColumn();
        $waste = (float)$q("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE restaurant_id = ? AND category = 'Inventory Wastage' AND expense_date >= ?", [$rid, $month])->fetchColumn();
        $usedVal = (float)$q("SELECT COALESCE(-SUM(quantity * cost_per_unit),0) FROM inventory_transactions WHERE restaurant_id = ? AND type IN ('sale','return') AND created_at >= NOW() - INTERVAL 30 DAY", [$rid])->fetchColumn();
        $recipeDishes = (int)$q('SELECT COUNT(DISTINCT menu_item_id) FROM inventory_recipes WHERE restaurant_id = ?', [$rid])->fetchColumn();
        $dishTotal = (int)$q('SELECT COUNT(*) FROM menu_items WHERE restaurant_id = ? AND is_hidden = 0', [$rid])->fetchColumn();
        $recent = $q("SELECT t.type, t.quantity, t.total_cost, t.notes, t.created_at, i.item_name, i.unit FROM inventory_transactions t JOIN inventory_items i ON i.id = t.inventory_item_id
            WHERE t.restaurant_id = ? ORDER BY t.id DESC LIMIT 8", [$rid])->fetchAll(PDO::FETCH_ASSOC);
        $openPo = (int)$q("SELECT COUNT(*) FROM inventory_purchase_orders WHERE restaurant_id = ? AND status IN ('draft','sent')", [$rid])->fetchColumn();
        usort($items, function ($a, $b) { return $b['daily_use'] * $b['cost_per_unit'] <=> $a['daily_use'] * $a['cost_per_unit']; });   // by value, so kg and pieces compare
        $top = array_slice(array_values(array_filter($items, function ($i) { return $i['daily_use'] > 0; })), 0, 5);
        $reorder = array_values(array_filter($items, function ($i) { return $i['status'] !== 'ok'; }));
        usort($reorder, function ($a, $b) { $o = ['negative' => 0, 'out' => 1, 'low' => 2]; return ($o[$a['status']] ?? 3) <=> ($o[$b['status']] ?? 3); });
        $expiring = array_values(array_filter($items, function ($i) { return in_array($i['expiry_state'], ['expired', 'soon'], true) && $i['quantity_in_stock'] > 0; }));
        ivOut($base + [
            'stats' => [
                'items' => count($items), 'value' => round(array_sum(array_column($items, 'stock_value')), 2),
                'low' => $count('low'), 'out' => $count(['out', 'negative']), 'spend_month' => $spend, 'waste_month' => $waste,
                'used_30' => round($usedVal, 2), 'recipe_dishes' => $recipeDishes, 'dishes' => $dishTotal, 'open_po' => $openPo,
            ],
            'reorder' => $reorder, 'expiring' => $expiring, 'recent' => $recent, 'top' => $top, 'suppliers' => $suppliers(),
        ]);
    }

    if ($view === 'items') {
        ivOut($base + ['items' => $loadItems(), 'suppliers' => $suppliers()]);
    }

    if ($view === 'recipes') {
        $items = $loadItems();
        $byId = [];
        foreach ($items as $i) $byId[$i['id']] = $i;
        $dishes = $q('SELECT m.id, m.item_name_en AS name, m.base_price, m.item_type, m.item_image, m.is_hidden, m.is_available, mn.menu_name
            FROM menu_items m LEFT JOIN menu mn ON mn.id = m.menu_id WHERE m.restaurant_id = ? ORDER BY mn.sort_order, mn.menu_name, m.sort_order, m.item_name_en', [$rid])->fetchAll(PDO::FETCH_ASSOC);
        $lines = [];
        foreach ($q('SELECT menu_item_id, inventory_item_id, quantity, unit FROM inventory_recipes WHERE restaurant_id = ?', [$rid])->fetchAll(PDO::FETCH_ASSOC) as $r) $lines[(int)$r['menu_item_id']][] = $r;
        $sold = [];
        foreach ($q("SELECT oi.menu_item_id, SUM(oi.quantity) n FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.restaurant_id = ? AND o.order_status = 'Completed' AND o.deleted_at IS NULL AND o.created_at >= NOW() - INTERVAL 30 DAY GROUP BY oi.menu_item_id", [$rid])->fetchAll(PDO::FETCH_ASSOC) as $r) $sold[(int)$r['menu_item_id']] = (int)$r['n'];
        foreach ($dishes as &$d) {
            $id = (int)$d['id'];
            $cost = 0.0; $can = null; $rl = [];
            foreach ($lines[$id] ?? [] as $l) {
                $it = $byId[(int)$l['inventory_item_id']] ?? null;
                if (!$it) continue;
                $inStock = invConvert((float)$l['quantity'], $l['unit'], $it['unit']);
                $cost += $inStock * $it['cost_per_unit'];
                $plates = $inStock > 0 ? (int)floor(max(0, $it['quantity_in_stock']) / $inStock) : null;
                if ($plates !== null) $can = $can === null ? $plates : min($can, $plates);
                $rl[] = ['inventory_item_id' => $it['id'], 'quantity' => (float)$l['quantity'], 'unit' => $l['unit'], 'item_name' => $it['item_name'], 'stock_unit' => $it['unit'], 'cost' => round($inStock * $it['cost_per_unit'], 2)];
            }
            $price = (float)$d['base_price'];
            $d = ['id' => $id, 'name' => $d['name'], 'menu' => $d['menu_name'] ?: 'Other', 'price' => $price, 'type' => $d['item_type'], 'image' => $d['item_image'],
                'hidden' => (int)$d['is_hidden'], 'available' => (int)$d['is_available'], 'lines' => $rl, 'cost' => round($cost, 2),
                'food_cost_pct' => ($rl && $price > 0) ? round($cost / $price * 100, 1) : null, 'margin' => $rl ? round($price - $cost, 2) : null,
                'can_make' => $rl ? $can : null, 'sold_30' => $sold[$id] ?? 0];
        }
        unset($d);
        ivOut($base + ['dishes' => $dishes, 'items' => $items]);
    }

    if ($view === 'suppliers') {
        $list = $suppliers();
        // per-supplier items and order totals, grouped in two queries instead of four per supplier
        $supItems = [];
        foreach ($q('SELECT supplier_id, id FROM inventory_items WHERE restaurant_id = ? AND is_active = 1 AND supplier_id IS NOT NULL', [$rid])->fetchAll(PDO::FETCH_ASSOC) as $r) $supItems[(int)$r['supplier_id']][] = (int)$r['id'];
        $supPo = [];
        foreach ($q("SELECT supplier_id, SUM(status IN ('draft','sent')) open_po,
                COALESCE(SUM(CASE WHEN status = 'received' AND received_at >= NOW() - INTERVAL 90 DAY THEN total ELSE 0 END),0) spend_90, MAX(created_at) last_order
            FROM inventory_purchase_orders WHERE restaurant_id = ? AND supplier_id IS NOT NULL GROUP BY supplier_id", [$rid])->fetchAll(PDO::FETCH_ASSOC) as $r) $supPo[(int)$r['supplier_id']] = $r;
        foreach ($list as &$s) {
            $po = $supPo[$s['id']] ?? null;
            $s['items'] = $supItems[$s['id']] ?? [];
            $s['open_po'] = (int)($po['open_po'] ?? 0);
            $s['spend_90'] = (float)($po['spend_90'] ?? 0);
            $s['last_order'] = ($po['last_order'] ?? null) ?: null;
        }
        unset($s);
        ivOut($base + ['suppliers' => $list, 'items' => $loadItems()]);
    }

    if ($view === 'orders') {
        $pos = $q('SELECT p.*, s.name AS supplier_name, s.phone AS supplier_phone, s.email AS supplier_email FROM inventory_purchase_orders p LEFT JOIN inventory_suppliers s ON s.id = p.supplier_id
            WHERE p.restaurant_id = ? ORDER BY FIELD(p.status, "draft", "sent", "received", "cancelled"), p.id DESC LIMIT 200', [$rid])->fetchAll(PDO::FETCH_ASSOC);
        // lines of every listed order in one query instead of one per order
        $poLines = [];
        if ($pos) {
            $poIds = array_column($pos, 'id');
            foreach ($q('SELECT l.po_id, l.id, l.inventory_item_id, l.quantity, l.cost_per_unit, l.received_qty, i.item_name, i.unit FROM inventory_po_lines l JOIN inventory_items i ON i.id = l.inventory_item_id
                    WHERE l.po_id IN (' . implode(',', array_fill(0, count($poIds), '?')) . ') ORDER BY l.id', $poIds)->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $pid = (int)$l['po_id'];
                unset($l['po_id']);
                $poLines[$pid][] = $l;
            }
        }
        foreach ($pos as &$p) {
            $p['id'] = (int)$p['id']; $p['supplier_id'] = $p['supplier_id'] ? (int)$p['supplier_id'] : null; $p['total'] = (float)$p['total'];
            $p['lines'] = array_map(function ($l) { foreach (['quantity', 'cost_per_unit', 'received_qty'] as $k) $l[$k] = (float)$l[$k]; $l['id'] = (int)$l['id']; $l['inventory_item_id'] = (int)$l['inventory_item_id']; return $l; }, $poLines[$p['id']] ?? []);
        }
        unset($p);
        ivOut($base + ['orders' => $pos, 'suppliers' => $suppliers(), 'items' => $loadItems()]);
    }

    if ($view === 'activity') {
        $w = ['t.restaurant_id = ?']; $a = [$rid];
        $type = (string)($_GET['type'] ?? '');
        if ($type !== '') { $w[] = 't.type IN (' . implode(',', array_fill(0, count(explode(',', $type)), '?')) . ')'; $a = array_merge($a, explode(',', $type)); }
        if ((int)($_GET['item'] ?? 0)) { $w[] = 't.inventory_item_id = ?'; $a[] = (int)$_GET['item']; }
        if ($f = ivDate($_GET['from'] ?? '')) { $w[] = 't.created_at >= ?'; $a[] = $f . ' 00:00:00'; }
        if ($t = ivDate($_GET['to'] ?? '')) { $w[] = 't.created_at <= ?'; $a[] = $t . ' 23:59:59'; }
        $rows = $q('SELECT t.id, t.type, t.quantity, t.cost_per_unit, t.total_cost, t.notes, t.created_at, t.reference_type, t.reference_id, t.created_by, i.item_name, i.unit, i.id AS item_id
            FROM inventory_transactions t JOIN inventory_items i ON i.id = t.inventory_item_id WHERE ' . implode(' AND ', $w) . ' ORDER BY t.id DESC LIMIT 300', $a)->fetchAll(PDO::FETCH_ASSOC);
        ivOut($base + ['rows' => $rows, 'items' => array_map(function ($i) { return ['id' => $i['id'], 'item_name' => $i['item_name']]; }, $loadItems())]);
    }

    if ($view === 'reports') {
        $days = max(1, min(365, (int)($_GET['days'] ?? 30)));
        $since = (string)$q('SELECT NOW() - INTERVAL ' . $days . ' DAY')->fetchColumn();
        $by = function (string $types) use ($q, $rid, $since) {
            $t = explode(',', $types);
            return $q('SELECT i.id, i.item_name, i.unit, -SUM(t.quantity) qty, -SUM(t.quantity * t.cost_per_unit) value FROM inventory_transactions t JOIN inventory_items i ON i.id = t.inventory_item_id
                WHERE t.restaurant_id = ? AND t.created_at >= ? AND t.type IN (' . implode(',', array_fill(0, count($t), '?')) . ') GROUP BY i.id HAVING qty > 0 ORDER BY value DESC LIMIT 15', array_merge([$rid, $since], $t))->fetchAll(PDO::FETCH_ASSOC);
        };
        $usage = $by('sale,return');
        $waste = $by('wastage');
        $count = $q("SELECT COALESCE(SUM(quantity * cost_per_unit),0) FROM inventory_transactions WHERE restaurant_id = ? AND type = 'count' AND created_at >= ?", [$rid, $since])->fetchColumn();
        $bySup = $q("SELECT COALESCE(s.name, 'No supplier') name, SUM(t.total_cost) value, COUNT(*) n FROM inventory_transactions t JOIN inventory_items i ON i.id = t.inventory_item_id LEFT JOIN inventory_suppliers s ON s.id = i.supplier_id
            WHERE t.restaurant_id = ? AND t.type = 'restock' AND t.created_at >= ? GROUP BY name ORDER BY value DESC", [$rid, $since])->fetchAll(PDO::FETCH_ASSOC);
        $byDay = $q("SELECT DATE(created_at) d, SUM(CASE WHEN type = 'restock' THEN total_cost ELSE 0 END) bought, -SUM(CASE WHEN type IN ('sale','return') THEN quantity * cost_per_unit ELSE 0 END) used, SUM(CASE WHEN type = 'wastage' THEN total_cost ELSE 0 END) wasted
            FROM inventory_transactions WHERE restaurant_id = ? AND created_at >= ? GROUP BY DATE(created_at) ORDER BY d", [$rid, $since])->fetchAll(PDO::FETCH_ASSOC);
        $sales = (float)$q("SELECT COALESCE(SUM(subtotal),0) FROM orders WHERE restaurant_id = ? AND order_status = 'Completed' AND deleted_at IS NULL AND created_at >= ?" . ($set['track_from'] ? ' AND created_at >= ?' : ''), $set['track_from'] ? [$rid, $since, $set['track_from']] : [$rid, $since])->fetchColumn();
        $usedVal = array_sum(array_map(function ($r) { return (float)$r['value']; }, $usage));
        $usedAll = (float)$q("SELECT COALESCE(-SUM(quantity * cost_per_unit),0) FROM inventory_transactions WHERE restaurant_id = ? AND type IN ('sale','return') AND created_at >= ?", [$rid, $since])->fetchColumn();
        ivOut($base + ['days' => $days, 'usage' => $usage, 'waste' => $waste, 'count_diff' => (float)$count, 'by_supplier' => $bySup, 'by_day' => $byDay,
            'sales' => $sales, 'used_value' => $usedAll, 'food_cost_pct' => $sales > 0 && $usedAll > 0 ? round($usedAll / $sales * 100, 1) : null]);
    }

    ivFail('Unknown view');
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('inventory_studio: ' . $e->getMessage());
    ivFail('Something went wrong. Please try again.', 500);
}
