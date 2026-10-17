<?php
/**
 * Scratch card for the tracking page (Offers > Scratch card).
 *
 * GET  ?order_number=&customer_phone=          — the card's state (locked / new / scratched)
 * POST  order_number, customer_phone (JSON)     — scratch it: reveals the prize and, if won,
 *                                                 the private single-use code
 *
 * The same order number + phone pair the tracking page uses.
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/order_abuse_guard.php';
require_once __DIR__ . '/../config/scratch_helpers.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: $_POST) : $_GET;
if (!is_array($in)) $in = [];
$orderNumber = substr(trim((string)($in['order_number'] ?? '')), 0, 50);
$phone = (string)($in['customer_phone'] ?? '');
$phone = orderAbuseNormalizePhone($phone) ?? $phone;

if ($orderNumber === '' || $phone === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Order not found']);
    exit;
}

try {
    $conn = getConnection();
    ensureScratchTables($conn);
    $st = $conn->prepare("SELECT o.id, o.restaurant_id, o.order_status, o.total, o.created_at, o.customer_phone, u.currency_symbol
        FROM orders o JOIN users u ON u.restaurant_id = o.restaurant_id
        WHERE o.order_number = ? AND o.customer_phone = ? AND o.source = 'website' AND o.deleted_at IS NULL LIMIT 1");
    $st->execute([$orderNumber, $phone]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }
    $cur = $order['currency_symbol'] ?: '₹';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $card = scratchCardForOrder($conn, $order, $cur);
        if (!$card || $card['state'] === 'locked') {
            echo json_encode(['success' => false, 'message' => 'This card is not ready yet']);
            exit;
        }
        echo json_encode(['success' => true, 'prize' => scratchReveal($conn, $order, $cur)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['success' => true, 'card' => scratchCardForOrder($conn, $order, $cur), 'currency' => $cur], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('scratch_card.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
}
