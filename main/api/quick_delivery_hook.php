<?php
/**
 * Shiprocket → RestroGrow: tracking updates for Shiprocket Quick rides (config/quick_delivery.php).
 * Set in Shiprocket › Settings › API › Webhooks: this URL, and the security token =
 * QUICK_DELIVERY_WEBHOOK_TOKEN in .env (sent as the x-api-key header). Shiprocket refuses URLs with
 * "shiprocket", "kartrocket", "sr" or "kr" in them, hence the name.
 *
 * Body: {awb, current_status, shipment_status, order_id, sr_order_id, scans[], …}. Answers 200 once the
 * token is right, even for a ride that isn't ours, so Shiprocket doesn't retry it.
 */
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config/quick_delivery.php';

function qdHookOut(int $code, string $msg): void { http_response_code($code); echo json_encode(['ok' => $code === 200, 'message' => $msg]); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') qdHookOut(405, 'POST only');
$want = trim((string)env('QUICK_DELIVERY_WEBHOOK_TOKEN', ''));
$got = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
// no token set = no webhook (a status from anyone could mark an order delivered)
if ($want === '' || !hash_equals($want, $got)) qdHookOut(401, 'Bad token');

$ev = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($ev)) qdHookOut(200, 'Ignored');
$awb = trim((string)($ev['awb'] ?? ''));
$srOrder = (int)($ev['sr_order_id'] ?? 0);
$status = (string)($ev['current_status'] ?? ($ev['shipment_status'] ?? ''));
if (($awb === '' && !$srOrder) || $status === '') qdHookOut(200, 'Ignored');

require_once __DIR__ . '/../db_connection.php';
try {
    $conn = getConnection();
    qdEnsure($conn);
    $st = $conn->prepare('SELECT * FROM qd_bookings WHERE ' . ($awb !== '' ? 'awb = ?' : 'sr_order_id = ?') . ' ORDER BY id DESC LIMIT 1');
    $st->execute([$awb !== '' ? $awb : $srOrder]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) qdHookOut(200, 'Not ours');
    qdApplyStatus($conn, $b, $status);
    qdHookOut(200, 'OK');
} catch (Throwable $e) {
    error_log('quick_delivery_hook: ' . $e->getMessage());
    qdHookOut(200, 'Saved for later');   // the cron reads the status from Shiprocket anyway
}
