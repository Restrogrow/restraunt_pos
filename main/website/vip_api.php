<?php
/**
 * VIP membership API (rewards page + cart). Joining needs a logged-in
 * customer: the membership belongs to the phone of the account in this
 * browser session, never to a phone number sent by the browser.
 *
 * GET  ?action=summary&restaurant_id=   — the club, its plans, and the customer's membership
 * POST  action=join, restaurant_id, plan_id, pay=phonepe|wallet, return_path
 *                                       — wallet: pays and activates now
 *                                         phonepe: returns payment_url ('VM…' transaction)
 * GET  ?action=status&restaurant_id=&txn=
 *                                       — after PhonePe sends the customer back: confirms the
 *                                         payment with PhonePe's own status API, then activates
 *
 * On this computer (localhost) without PhonePe keys, a PhonePe payment is
 * simulated so the flow can be tried (same as wallet top-ups).
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
require_once __DIR__ . '/../config/growth_helpers.php';
require_once __DIR__ . '/../config/vip_membership.php';
require_once __DIR__ . '/../config/wallet_helpers.php';
require_once __DIR__ . '/../config/phonepe_verify.php';
require_once __DIR__ . '/../config/repeatgrow_whatsapp.php';
require_once __DIR__ . '/../config/order_abuse_guard.php';

function vipOut(array $data, int $code = 200): void {
    http_response_code($code);
    if (ob_get_level()) ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = isset($pdo) ? $pdo : getConnection();
    $action = $_GET['action'] ?? $_POST['action'] ?? 'summary';
    $rid = substr(trim((string)($_GET['restaurant_id'] ?? $_POST['restaurant_id'] ?? '')), 0, 50);
    if ($rid === '') vipOut(['success' => false, 'message' => 'Missing restaurant'], 400);

    require_once __DIR__ . '/../config/schema_cache.php';   // table setup once per deploy
    rgEnsureOnce('ensureVipSchema', $conn);
    rgEnsureOnce('ensureWalletTables', $conn);
    $vs = vipSettings($conn, $rid);
    $customer = getLoggedInCustomer($conn, $rid);
    session_write_close();
    if (!$vs['enabled'] || !$vs['loyalty_enabled']) vipOut(['success' => true, 'enabled' => false]);

    $cs = $conn->prepare('SELECT currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1');
    $cs->execute([$rid]);
    $cur = (string)($cs->fetchColumn() ?: '₹');
    $plans = vipPlans($conn, $rid, true);
    $phone = $customer ? (orderAbuseNormalizePhone((string)$customer['phone']) ?? (string)$customer['phone']) : '';

    if ($action === 'summary') {
        $out = ['success' => true, 'enabled' => (bool)$plans, 'name' => $vs['name'], 'tagline' => $vs['tagline'], 'program_type' => $vs['program_type'],
                'logged_in' => (bool)$customer, 'currency' => $cur,
                'plans' => array_map(function ($p) use ($conn, $rid, $cur) { return $p + ['gift' => vipGiftLabel($conn, $rid, $p, $cur)]; }, $plans)];
        if ($customer) {
            $m = vipActiveMembership($conn, $rid, $phone);
            $out['wallet_balance'] = getWalletSettings($conn, $rid)['enabled'] ? walletBalance($conn, $rid, $phone) : null;
            if ($m) {
                $first = $conn->prepare("SELECT MIN(starts_at) FROM vip_members WHERE restaurant_id = ? AND phone = ? AND status = 'Active'");
                $first->execute([$rid, $phone]);
                $since = (string)($first->fetchColumn() ?: $m['since']);
                $out['membership'] = ['plan' => $m['plan_name'], 'plan_id' => $m['id'], 'since' => $since, 'ends_at' => $m['ends_at'],
                    'days_left' => max(0, (int)ceil((strtotime($m['ends_at']) - time()) / 86400)),
                    'discount_pct' => $m['discount_pct'], 'discount_max' => $m['discount_max'], 'free_delivery' => $m['free_delivery'],
                    'free_delivery_min' => $m['free_delivery_min'], 'earn_boost' => $m['earn_boost'], 'saved' => vipSavings($conn, $rid, $phone, $since)];
            }
        }
        vipOut($out);
    }

    if (!$customer) vipOut(['success' => false, 'message' => 'Please log in to join', 'login' => true], 401);

    if ($action === 'join' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $plan = null;
        foreach ($plans as $p) if ($p['id'] === $planId) $plan = $p;
        if (!$plan) vipOut(['success' => false, 'message' => 'That plan is no longer available'], 400);
        $pay = ($_POST['pay'] ?? '') === 'wallet' ? 'wallet' : 'phonepe';

        if ($pay === 'wallet') {
            $ws = getWalletSettings($conn, $rid);
            if (!$ws['enabled']) vipOut(['success' => false, 'message' => 'The wallet is not available here'], 400);
            if (walletBalance($conn, $rid, $phone) < $plan['price']) vipOut(['success' => false, 'message' => 'Not enough money in your wallet'], 400);
            $conn->beginTransaction();
            try {
                $m = vipCreatePending($conn, $rid, (int)$customer['id'], $phone, $plan, 'wallet');
                walletApply($conn, $rid, $phone, -$plan['price'], 'spend', 'Joined ' . $vs['name'] . ' (' . $plan['name'] . ')');
                vipActivate($conn, $m);
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                if ($e instanceof RuntimeException) vipOut(['success' => false, 'message' => 'Not enough money in your wallet'], 400);
                throw $e;
            }
            vipOut(['success' => true, 'joined' => true]);
        }

        $m = vipCreatePending($conn, $rid, (int)$customer['id'], $phone, $plan, 'phonepe');
        $txn = $m['transaction_id'];
        $path = (string)($_POST['return_path'] ?? '');
        if ($path === '' || $path[0] !== '/' || strpos($path, '//') === 0) $path = '/';
        $path = preg_replace('/([?&])(vip_txn|vip_demo)=[^&]*/', '$1', $path);
        $path = rtrim(str_replace(['?&', '&&'], ['?', '&'], $path), '?&');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $back = $scheme . '://' . $_SERVER['HTTP_HOST'] . $path . (strpos($path, '?') === false ? '?' : '&') . 'vip_txn=' . $txn;

        $config = phonepeCallbackConfig($rid);
        if (empty($config['client_id']) || empty($config['client_secret'])) {
            if (isLocalDevRequest()) vipOut(['success' => true, 'payment_url' => $back . '&vip_demo=1', 'demo' => true]);
            vipFail($conn, $m);
            vipOut(['success' => false, 'message' => 'Online payment is not available at this restaurant yet.'], 400);
        }
        $res = phonepeCreatePayment($config, $txn, (float)$plan['price'], $back, 1200, 'Join ' . $vs['name']);
        if (!$res['ok']) {
            error_log('VIP join PhonePe refused: ' . $res['error']);
            vipFail($conn, $m);
            vipOut(['success' => false, 'message' => 'Could not start the payment. Please try again.'], 502);
        }
        vipOut(['success' => true, 'payment_url' => $res['url']]);
    }

    if ($action === 'status') {
        $st = $conn->prepare('SELECT * FROM vip_members WHERE transaction_id = ? AND restaurant_id = ? AND phone = ?');
        $st->execute([(string)($_GET['txn'] ?? ''), $rid, $phone]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) vipOut(['success' => false, 'message' => 'Payment not found'], 404);
        if ($m['status'] === 'Pending') {
            $config = phonepeCallbackConfig($rid);
            if (empty($config['client_id']) || empty($config['client_secret'])) {
                if (isLocalDevRequest() && !empty($_GET['demo'])) { vipActivate($conn, $m); $m['status'] = 'Active'; }
            } else {
                require_once __DIR__ . '/../config/payment_settle.php';
                $o = ppSettle($conn, $m['transaction_id'])['outcome'];
                if (in_array($o, ['paid', 'already'], true)) $m['status'] = 'Active';
                elseif (in_array($o, ['failed', 'mismatch'], true)) $m['status'] = 'Failed';
            }
        }
        vipOut(['success' => true, 'status' => $m['status'], 'plan' => $m['plan_name']]);
    }

    vipOut(['success' => false, 'message' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('vip_api.php: ' . $e->getMessage());
    vipOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
