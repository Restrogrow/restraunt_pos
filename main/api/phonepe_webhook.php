<?php
/**
 * THE PhonePe webhook for everything (register this one URL in PhonePe Business › Developer Settings › Webhook):
 *   RestroGrow's account:   https://restrogrow.com/main/api/phonepe_webhook.php
 *                           username / password = PHONEPE_WEBHOOK_USERNAME / PHONEPE_WEBHOOK_PASSWORD (.env)
 *   a restaurant's own account (Payments › Payment Gateway, "own"):
 *                           https://restrogrow.com/main/api/phonepe_webhook.php?rid=<restaurant id>
 *                           username / password shown on that settings page (ppWebhookCreds)
 * Events: checkout.order.completed, checkout.order.failed (pg.order.* is accepted too).
 *
 * PhonePe sends  Authorization: SHA256(username:password). A wrong one is refused (401). Either way the body
 * is never trusted: config/payment_settle.php asks PhonePe's status API and checks the amount.
 * If PhonePe can't be asked right now we answer 503, so PhonePe sends the webhook again later.
 * The older callback files (phonepe_order_callback.php, phonepe_callback.php, phonepe_subscription_callback.php,
 * superadmin/payment_link_callback.php) now just run this file.
 */
require_once __DIR__ . '/../config/env_loader.php';
if (file_exists(__DIR__ . '/../db_connection.php')) require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/payment_settle.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function phwOut(int $code, array $d): void { http_response_code($code); echo json_encode($d); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') phwOut(200, ['success' => true, 'message' => 'webhook active']);


try {
    $rid = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['rid'] ?? ''));
    $hdr = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($hdr === '' && function_exists('getallheaders')) foreach ((array)getallheaders() as $hk => $hv) if (strcasecmp($hk, 'Authorization') === 0) $hdr = (string)$hv;
    $auth = strtolower(trim(preg_replace('/^(SHA256|Basic|Bearer)\s+/i', '', $hdr)));
    $expected = [];
    $pu = (string)env('PHONEPE_WEBHOOK_USERNAME', ''); $pw = (string)env('PHONEPE_WEBHOOK_PASSWORD', '');
    if ($pu !== '' && $pw !== '') $expected[] = hash('sha256', $pu . ':' . $pw);
    if ($rid !== '' && ($c = ppWebhookCreds($rid))) $expected[] = hash('sha256', $c['username'] . ':' . $c['password']);
    // a brand's own PhonePe account (config/brand.php): one webhook for all its outlets
    $bid = (int)($_GET['bid'] ?? 0);
    if ($bid > 0 && ($c = ppBrandWebhookCreds($bid))) $expected[] = hash('sha256', $c['username'] . ':' . $c['password']);
    if ($expected) {
        $ok = false;
        foreach ($expected as $e) if ($auth !== '' && hash_equals($e, $auth)) $ok = true;
        if (!$ok) { error_log('PhonePe webhook: wrong or missing Authorization' . ($rid ? ' (rid ' . $rid . ')' : '')); phwOut(401, ['success' => false, 'message' => 'Unauthorized']); }
    } elseif (random_int(1, 20) === 1) {
        error_log('PhonePe webhook: PHONEPE_WEBHOOK_USERNAME / PASSWORD not set; relying on the status API check only');
    }

    $raw = (string)file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body) && !empty($_POST['response'])) $body = json_decode((string)base64_decode((string)$_POST['response']), true);   // old v1 shape
    if (!is_array($body)) phwOut(400, ['success' => false, 'message' => 'Invalid body']);
    $event = (string)($body['event'] ?? $body['type'] ?? '');
    $p = is_array($body['payload'] ?? null) ? $body['payload'] : $body;
    if (is_array($p['data'] ?? null)) $p = $p['data'];
    $txn = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($p['merchantOrderId'] ?? $p['merchantTransactionId'] ?? $p['originalMerchantOrderId'] ?? ''));
    if ($txn === '') phwOut(400, ['success' => false, 'message' => 'Missing merchantOrderId']);
    if (stripos($event, 'refund') !== false) phwOut(200, ['success' => true, 'message' => 'refund event noted']);   // refunds are handled from the dashboard

    $conn = getConnection();
    $r = ppSettle($conn, $txn);
    error_log('PhonePe webhook: ' . $txn . ' (' . ($event ?: 'no event') . ') -> ' . $r['kind'] . '/' . $r['outcome']);
    if ($r['outcome'] === 'unverified') phwOut(503, ['success' => false, 'message' => 'Could not confirm with PhonePe yet, retry later']);
    phwOut(200, ['success' => true, 'outcome' => $r['outcome']]);
} catch (Throwable $e) {
    error_log('PhonePe webhook error: ' . $e->getMessage());
    phwOut(500, ['success' => false, 'message' => 'Error']);
}
