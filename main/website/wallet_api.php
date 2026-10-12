<?php
/**
 * Customer wallet API (profile page + cart). Logged-in customers only: the
 * wallet belongs to the phone of the account in this browser session
 * (customer_session.php), never to a phone number sent by the browser.
 *
 * GET  ?action=summary&restaurant_id=   — balance, history, the restaurant's top-up offers
 * POST  action=topup, restaurant_id, amount, return_path
 *                                       — starts a PhonePe payment; returns payment_url
 * GET  ?action=status&restaurant_id=&txn=
 *                                       — after PhonePe sends the customer back: confirms the
 *                                         payment with PhonePe's own status API and adds the money
 *
 * The money is added only once PhonePe confirms it (here or in the webhook,
 * api/phonepe_order_callback.php). On this computer (localhost) without
 * PhonePe test keys, a top-up is simulated so the flow can be tried.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_start();

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
ob_clean();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/customer_session.php';
require_once __DIR__ . '/../config/wallet_helpers.php';
require_once __DIR__ . '/../config/phonepe_verify.php';
require_once __DIR__ . '/../config/repeatgrow_whatsapp.php';
require_once __DIR__ . '/../config/order_abuse_guard.php';

function walletOut(array $data, int $code = 200): void {
    http_response_code($code);
    if (ob_get_level()) ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = isset($pdo) ? $pdo : getConnection();
    $action = $_GET['action'] ?? $_POST['action'] ?? 'summary';
    $rid = substr(trim((string)($_GET['restaurant_id'] ?? $_POST['restaurant_id'] ?? '')), 0, 50);
    if ($rid === '') walletOut(['success' => false, 'message' => 'Missing restaurant'], 400);

    $s = getWalletSettings($conn, $rid);
    $customer = getLoggedInCustomer($conn, $rid);
    session_write_close();
    $offers = ['name' => $s['name'], 'min_topup' => $s['min_topup'], 'max_topup' => $s['max_topup'], 'max_balance' => $s['max_balance'],
               'quick_amounts' => $s['quick_amounts'], 'bonus_tiers' => $s['bonus_tiers'], 'cashback_pct' => $s['cashback_pct'], 'cashback_max' => $s['cashback_max']];

    if (!$s['enabled']) walletOut(['success' => true, 'enabled' => false]);
    if (!$customer) walletOut(['success' => true, 'enabled' => true, 'logged_in' => false] + $offers);
    // Same 10-digit form orders use, so refunds and cashback land in the same wallet
    $phone = orderAbuseNormalizePhone((string)$customer['phone']) ?? (string)$customer['phone'];

    if ($action === 'summary') {
        walletOut(['success' => true, 'enabled' => true, 'logged_in' => true, 'phone_last4' => substr($phone, -4)] + $offers + walletSummary($conn, $rid, $phone));
    }

    if ($action === 'topup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        if ($amount < $s['min_topup'] || $amount > $s['max_topup']) {
            walletOut(['success' => false, 'message' => 'Add between ' . $s['min_topup'] . ' and ' . $s['max_topup']], 400);
        }
        $bonus = walletBonusFor($s, $amount);
        if (walletBalance($conn, $rid, $phone) + $amount + $bonus > $s['max_balance']) {
            walletOut(['success' => false, 'message' => 'Your wallet can hold up to ' . $s['max_balance'] . '. Try a smaller amount.'], 400);
        }
        $txn = 'WT' . date('ymdHis') . strtoupper(bin2hex(random_bytes(6)));
        $conn->prepare('INSERT INTO wallet_topups (restaurant_id, phone, amount, bonus, transaction_id) VALUES (?, ?, ?, ?, ?)')
             ->execute([$rid, $phone, $amount, $bonus, $txn]);

        // Back to the page the customer came from (a path on this site only)
        $path = (string)($_POST['return_path'] ?? '');
        if ($path === '' || $path[0] !== '/' || strpos($path, '//') === 0) $path = '/';
        $path = preg_replace('/([?&])(wallet_txn|wallet_demo)=[^&]*/', '$1', $path);
        $path = rtrim(str_replace(['?&', '&&'], ['?', '&'], $path), '?&');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $back = $scheme . '://' . $_SERVER['HTTP_HOST'] . $path . (strpos($path, '?') === false ? '?' : '&') . 'wallet_txn=' . $txn;

        $config = phonepeCallbackConfig($rid);
        if (empty($config['client_id']) || empty($config['client_secret'])) {
            if (isLocalDevRequest()) walletOut(['success' => true, 'payment_url' => $back . '&wallet_demo=1', 'demo' => true]);
            walletOut(['success' => false, 'message' => 'Adding money online is not available at this restaurant yet.'], 400);
        }
        $token = phonepeCallbackAccessToken($config);
        if (!$token) throw new RuntimeException('PhonePe auth failed');
        $ch = curl_init($config['api_url'] . '/checkout/v2/pay');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: O-Bearer ' . $token],
            CURLOPT_POSTFIELDS => json_encode([
                'merchantOrderId' => $txn,
                'amount' => (int)round($amount * 100),
                'expireAfter' => 1200,
                'paymentFlow' => ['type' => 'PG_CHECKOUT', 'message' => 'Add money to ' . $s['name'], 'merchantUrls' => ['redirectUrl' => $back]],
            ]),
        ]);
        $res = json_decode((string)curl_exec($ch), true);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || empty($res['redirectUrl'])) {
            error_log('wallet topup PhonePe error: ' . json_encode($res));
            $conn->prepare("UPDATE wallet_topups SET status = 'Failed', completed_at = NOW() WHERE transaction_id = ?")->execute([$txn]);
            walletOut(['success' => false, 'message' => 'Could not start the payment. Please try again.'], 502);
        }
        walletOut(['success' => true, 'payment_url' => $res['redirectUrl']]);
    }

    if ($action === 'status') {
        $st = $conn->prepare('SELECT * FROM wallet_topups WHERE transaction_id = ? AND restaurant_id = ? AND phone = ?');
        $st->execute([(string)($_GET['txn'] ?? ''), $rid, $phone]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) walletOut(['success' => false, 'message' => 'Payment not found'], 404);
        if ($t['status'] === 'Pending') {
            $config = phonepeCallbackConfig($rid);
            if (empty($config['client_id']) || empty($config['client_secret'])) {
                // Local try-out only (no PhonePe keys on this computer)
                if (isLocalDevRequest() && !empty($_GET['demo'])) { walletCompleteTopup($conn, $t); $t['status'] = 'Success'; }
            } else {
                $state = phonepeVerifyOrderState($conn, $rid, $t['transaction_id']);
                if ($state === 'COMPLETED') { walletCompleteTopup($conn, $t); $t['status'] = 'Success'; }
                elseif (in_array($state, ['FAILED', 'CANCELLED', 'EXPIRED', 'REJECTED'], true)) { walletFailTopup($conn, $t); $t['status'] = 'Failed'; }
            }
        }
        walletOut(['success' => true, 'status' => $t['status'], 'amount' => (float)$t['amount'], 'bonus' => (float)$t['bonus'],
                   'balance' => walletBalance($conn, $rid, $phone)]);
    }

    walletOut(['success' => false, 'message' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('wallet_api.php: ' . $e->getMessage());
    walletOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
