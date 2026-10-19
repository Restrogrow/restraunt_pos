<?php
/**
 * Customer website: turn on push notifications for one order (config/customer_push.php).
 * POST JSON { order_number, customer_phone, subscription: PushSubscription.toJSON(), page_url, icon_url }
 * The order number and phone are checked the same way as the tracking page.
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/order_abuse_guard.php';
require_once __DIR__ . '/../config/guess_guard.php';
require_once __DIR__ . '/../config/customer_push.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only']);
    exit;
}

$in = json_decode((string)file_get_contents('php://input'), true);
$sub = is_array($in) ? ($in['subscription'] ?? null) : null;
$orderNumber = trim((string)($in['order_number'] ?? ''));
$phoneRaw = trim((string)($in['customer_phone'] ?? ''));
$phone = orderAbuseNormalizePhone($phoneRaw) ?? $phoneRaw;
if ($orderNumber === '' || $phone === '' || !is_array($sub) || !is_string($sub['endpoint'] ?? null) || !preg_match('#^https://#', $sub['endpoint'])
    || strlen($sub['endpoint']) > 600 || !is_string($sub['keys']['p256dh'] ?? null) || !is_string($sub['keys']['auth'] ?? null)
    || strlen($sub['keys']['p256dh']) > 200 || strlen($sub['keys']['auth']) > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    if (rgGuessBlocked()) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many tries. Please wait a few minutes.']);
        exit;
    }
    $conn = getConnection();
    $st = $conn->prepare("SELECT id, restaurant_id, order_status FROM orders WHERE order_number = ? AND customer_phone = ? AND source = 'website' LIMIT 1");
    $st->execute([$orderNumber, $phone]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) {
        rgGuessMiss();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }
    if (in_array($o['order_status'], ['Completed', 'Cancelled', 'Rejected'], true)) {
        echo json_encode(['success' => true, 'finished' => true]);
        exit;
    }
    cpSubscribe($conn, (int)$o['id'], (string)$o['restaurant_id'], $sub, (string)($in['page_url'] ?? ''), (string)($in['icon_url'] ?? ''));
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('customer_push.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not turn on notifications']);
}
