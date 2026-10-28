<?php
/**
 * One bill for a table (Tables page, assets/js/tables-studio.js): every order at the table now.
 *
 * GET ?table_id=12 → { success, table, area, session: {id, since} | null, orders: [...with phone and items], sum }
 *   The table's open session (config/table_session.php); a table with no session (POS bills, orders from
 *   before sessions) shows its open unpaid orders of the last day, the same ones rgBusyTables counts.
 * POST JSON { action: 'free', table_id } → closes the session: only when nothing on it is left to pay.
 *   Paying goes through api/update_payment_status.php, one order at a time (Mark all paid on the page),
 *   which also closes the kitchen tickets; the session then closes by itself.
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/table_session.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
requirePermission(PERMISSION_MANAGE_ORDERS);
$rid = $_SESSION['restaurant_id'] ?? null;
if (!$rid && isset($_SESSION['staff_id'])) {
    try {
        $st = getConnection()->prepare('SELECT restaurant_id FROM staff WHERE id = ?');
        $st->execute([$_SESSION['staff_id']]);
        $rid = $st->fetchColumn() ?: null;
    } catch (Throwable $e) { $rid = null; }
}
session_write_close();

function tbOut(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
if (!$rid) tbOut(['success' => false, 'message' => 'Please log in'], 401);

try {
    $conn = getConnection();
    tsEnsure($conn);
    $in = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
    $tableId = (int)($in['table_id'] ?? 0);
    $t = $conn->prepare('SELECT t.id, t.table_number, a.area_name FROM tables t LEFT JOIN areas a ON a.id = t.area_id WHERE t.id = ? AND t.restaurant_id = ?');
    $t->execute([$tableId, $rid]);
    $table = $t->fetch(PDO::FETCH_ASSOC);
    if (!$table) tbOut(['success' => false, 'message' => 'Table not found'], 404);
    $session = tsCurrent($conn, (string)$rid, $tableId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($in['action'] ?? '') !== 'free') tbOut(['success' => false, 'message' => 'Unknown action'], 400);
        if ($session && tsOpenUnpaidCount($conn, (int)$session['id']) > 0) {
            tbOut(['success' => false, 'message' => 'This table still has an unpaid bill. Mark it paid first.']);
        }
        if ($session) tsClose($conn, (int)$session['id'], 'staff');
        tbOut(['success' => true]);
    }

    if ($session) {
        $bill = tsBill($conn, (int)$session['id']);
    } else {
        // no session: the table's open unpaid orders (rgBusyTables' rule)
        $ids = $conn->prepare("SELECT id FROM orders WHERE restaurant_id = ? AND table_id = ? AND deleted_at IS NULL
            AND order_status IN ('Pending', 'Accepted', 'Preparing', 'Ready', 'Served')
            AND payment_status IN ('Pending', 'Partially Paid') AND created_at >= NOW() - INTERVAL 1 DAY");
        $ids->execute([$rid, $tableId]);
        $bill = tsBillOfOrders($conn, array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN)));
    }
    $cur = $conn->prepare('SELECT currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1');
    $cur->execute([$rid]);
    tbOut(['success' => true, 'table' => $table['table_number'], 'area' => $table['area_name'], 'currency' => (string)($cur->fetchColumn() ?: '₹'),
        'session' => $session ? ['id' => (int)$session['id'], 'since' => $session['opened_at']] : null,
        'orders' => $bill['orders'], 'sum' => $bill['sum']]);
} catch (Throwable $e) {
    error_log('api/table_bill.php: ' . $e->getMessage());
    tbOut(['success' => false, 'message' => 'Could not load the table bill'], 500);
}
