<?php
/**
 * Smart QR Menu kit orders from restrogrow.com/qr-menu-ordering/ (config/qr_kit.php).
 *
 *   POST {package, design, extra, delivery, payments, full, name, phone, restaurant, address, city, state, pincode}
 *        → saves the booking, opens a PhonePe payment on RestroGrow's own account for ₹500 (or everything with full)
 *          and answers {success, payment_url, txn}. Prices are worked out here (qkPrice), not taken from the page.
 *   GET  ?txn=QRK_…  → {success, status: paid|pending|failed, order} after asking PhonePe (back from the payment page)
 *
 * On a local computer without real keys the payment is pretended (marked paid at once), never on the live site.
 * Rate limited per IP and per phone.
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/qr_kit.php';
require_once __DIR__ . '/../config/payment_settle.php';

function qkOut(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    $conn = getConnection();
    qkEnsure($conn);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $txn = (string)($_GET['txn'] ?? '');
        if (!preg_match('/^QRK_[A-Za-z0-9_-]{8,59}$/', $txn)) qkOut(['success' => false, 'message' => 'Booking not found.'], 404);
        $st = $conn->prepare('SELECT * FROM qr_kit_orders WHERE transaction_id = ? LIMIT 1');
        $st->execute([$txn]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) qkOut(['success' => false, 'message' => 'Booking not found.'], 404);
        if ($o['payment_status'] === 'pending') {
            ppSettle($conn, $txn);
            $st->execute([$txn]);
            $o = $st->fetch(PDO::FETCH_ASSOC);
        }
        // the payment id is the proof: only what the buyer typed themselves and the money comes back
        qkOut(['success' => true, 'status' => $o['payment_status'], 'order' => [
            'package' => QK_PACKAGES[$o['package']]['name'] ?? $o['package'], 'design' => $o['design'], 'standees' => (int)$o['standees'],
            'total' => (float)$o['total'], 'pay_now' => (float)$o['pay_now'], 'pay_on_delivery' => (float)$o['pay_on_delivery'],
            'name' => $o['name'], 'phone' => $o['phone'], 'restaurant' => $o['restaurant']]]);
    }

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $price = qkPrice($in);
    $buyer = qkBuyer($in);

    require_once __DIR__ . '/../config/rate_limit.php';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    foreach ([['qrkit_ip_' . $ip, 10, 3600], ['qrkit_phone_' . $buyer['phone'], 6, 86400]] as [$key, $max, $win]) {
        if (!checkRateLimit($key, $max, $win)['allowed']) qkOut(['success' => false, 'message' => 'Too many tries. Please WhatsApp us and we\'ll help you book.'], 429);
    }

    // RestroGrow's own PhonePe account (platform .env) only; pretend only on a local computer
    $config = phonepeCallbackConfig(null, true);
    if (!$config['ready'] && !$config['demo']) {
        error_log('qr_kit_order: PhonePe platform keys are not set');
        qkOut(['success' => false, 'message' => 'Online payment is not available right now. Please WhatsApp us and we\'ll send a payment link.'], 503);
    }
    $txn = ppTxnId('QRK_', $buyer['pincode']);
    $id = qkCreate($conn, $txn, $price, $buyer);

    // back to the product page, which asks GET ?txn= how it went
    $app = rtrim((string)env('APP_URL', ''), '/');
    if ($app === '' || ppIsLocal()) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = str_replace('\\', '/', dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))));
        if ($base === '/' || $base === '.') $base = '';
        $app = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base;
    }
    $back = $app . '/qr-menu-ordering/?booking=' . urlencode($txn);

    if ($config['demo']) {
        qkMarkPaid($conn, $id);   // local computer without keys (ppIsLocal): pretend it was paid
        qkOut(['success' => true, 'txn' => $txn, 'payment_url' => $back, 'demo' => true]);
    }
    $res = phonepeCreatePayment($config, $txn, $price['pay_now'], $back, 1200, 'RestroGrow Smart QR Menu for ' . $buyer['restaurant']);
    if (!$res['ok']) {
        $conn->prepare("UPDATE qr_kit_orders SET payment_status = 'failed' WHERE id = ?")->execute([$id]);
        error_log('qr_kit_order PhonePe refused: ' . $res['error']);
        qkOut(['success' => false, 'message' => 'Could not start the payment. Please try again in a minute.'], 502);
    }
    qkOut(['success' => true, 'txn' => $txn, 'payment_url' => $res['url']]);
} catch (InvalidArgumentException $e) {
    qkOut(['success' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('qr_kit_order.php: ' . $e->getMessage());
    qkOut(['success' => false, 'message' => 'Something went wrong. Please try again, or WhatsApp us.'], 500);
}
