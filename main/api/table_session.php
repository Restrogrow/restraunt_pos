<?php
/**
 * The table's shared order for guests (config/table_session.php): who at the table ordered what, and the
 * table's total. Used by the tracking page (website/track.php) and the bar on the menu / cart pages
 * (website/table_session_bar.php).
 *
 * GET ?restaurant_id=RES..&table=T4[&order=ORD-…]
 *   → { success, open, table, area, guests: [first names], orders: [{number, name, status, paid, total, items, mine}], sum }
 * First names only, no phone numbers: anyone at the table (anyone with its QR code) can see this.
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/table_session.php';

function tsApiOut(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$rid = trim((string)($_GET['restaurant_id'] ?? ''));
$table = mb_substr(trim((string)($_GET['table'] ?? '')), 0, 50);
$mine = mb_substr(trim((string)($_GET['order'] ?? '')), 0, 40);
if (!preg_match('/^[A-Za-z0-9]{3,20}$/', $rid) || $table === '') tsApiOut(['success' => false, 'message' => 'Table not found.'], 400);

try {
    $conn = getConnection();
    tsEnsure($conn);
    $t = tsTableByNumber($conn, $rid, $table);
    if (!$t) tsApiOut(['success' => false, 'message' => 'Table not found.'], 404);
    $s = tsCurrent($conn, $rid, (int)$t['id']);
    $base = ['success' => true, 'table' => $t['table_number'], 'area' => $t['area_name']];
    if (!$s) tsApiOut($base + ['open' => false, 'guests' => [], 'orders' => [], 'sum' => null]);
    $bill = tsBill($conn, (int)$s['id'], true);
    $guests = [];
    foreach ($bill['orders'] as &$o) {
        $o['mine'] = $mine !== '' && $o['number'] === $mine;
        if (!in_array($o['name'], $guests, true)) $guests[] = $o['name'];
        unset($o['id'], $o['payment_method'], $o['subtotal'], $o['tax'], $o['discount']);
    }
    unset($o);
    $cur = $conn->prepare('SELECT currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1');
    $cur->execute([$rid]);
    tsApiOut($base + ['open' => true, 'since' => $s['opened_at'], 'currency' => (string)($cur->fetchColumn() ?: '₹'),
        'guests' => $guests, 'orders' => $bill['orders'], 'sum' => $bill['sum']]);
} catch (Throwable $e) {
    error_log('api/table_session.php: ' . $e->getMessage());
    tsApiOut(['success' => false, 'message' => 'Could not load the table.'], 500);
}
