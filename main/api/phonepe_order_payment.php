<?php
/**
 * Customer website order paid online with PhonePe (website/cart.php, embed/embed.php).
 *
 *   POST {order_id, customer_phone, embed?}            start (or restart) the payment → {payment_url}
 *   GET  ?action=status&order_id=<number>&restaurant_id  where it stands → {payment_status: Success|Pending|Failed, can_retry, can_cash}
 *   POST ?action=cancel {order_id, customer_phone}      the customer gives up: order cancelled, coupon / points / wallet / stock back
 *   POST ?action=cash   {order_id, customer_phone}      pay cash instead (only where the restaurant takes cash): order goes to the kitchen
 *
 * Paid / failed is only ever decided by PhonePe's status API (config/payment_settle.php). The order already
 * exists (created by process_website_order.php, payment Pending, hidden from the kitchen until paid).
 * Orders nobody pays are cancelled after 30 minutes by config/payment_reconcile.php.
 */
if (ob_get_level()) ob_clean();
ob_start();
require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/payment_settle.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function ppoOut(array $d, int $code = 200): void { if (ob_get_level()) ob_clean(); http_response_code($code); echo json_encode($d); exit; }

/** The order this customer may act on: by id + the phone they ordered with. */
function ppoOwnOrder(PDO $conn, array $in): array {
    $orderId = (int)($in['order_id'] ?? 0);
    $phone = is_scalar($in['customer_phone'] ?? null) ? trim((string)$in['customer_phone']) : '';
    if (!$orderId || $phone === '') ppoOut(['success' => false, 'message' => 'Order not found'], 404);
    require_once __DIR__ . '/../config/guess_guard.php';
    if (rgGuessBlocked()) ppoOut(['success' => false, 'message' => 'Order not found'], 404);
    $st = $conn->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $st->execute([$orderId]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    // order ids are shared across restaurants: the phone it was placed with proves it is theirs
    if (!$o || $phone !== (string)$o['customer_phone']) { rgGuessMiss(); ppoOut(['success' => false, 'message' => 'Order not found'], 404); }
    return $o;
}

/** Ask PhonePe about this order's open attempts; true when one of them is paid. */
function ppoSettleOpen(PDO $conn, int $orderId): bool {
    $st = $conn->prepare("SELECT transaction_id FROM payments WHERE order_id = ? AND payment_method = 'Online' AND payment_status IN ('Pending', 'Success') AND transaction_id LIKE 'PP\\_%' ORDER BY id DESC LIMIT 4");
    $st->execute([$orderId]);
    $paid = false;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $r = ppSettle($conn, $t);
        if (in_array($r['outcome'], ['paid', 'already'], true)) $paid = true;
    }
    return $paid;
}

function ppoCashAllowed(PDO $conn, string $rid): bool {
    try {
        $st = $conn->prepare('SELECT cod_enabled FROM users WHERE restaurant_id = ? ORDER BY id LIMIT 1');
        $st->execute([$rid]);
        $v = $st->fetchColumn();
        return $v === false || $v === null || (int)$v === 1;
    } catch (Throwable $e) { return true; }
}

function ppoReturnBase(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'restrogrow.com'));
    $basePath = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($basePath === '/' || $basePath === '\\') $basePath = '';
    return $scheme . '://' . $host . $basePath;
}

$action = $_GET['action'] ?? 'create';
try {
    $conn = getConnection();

    /* ── where the payment stands ── */
    if ($action === 'status') {
        $number = is_string($_GET['order_id'] ?? null) ? trim($_GET['order_id']) : '';
        $rid = is_string($_GET['restaurant_id'] ?? null) ? preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['restaurant_id']) : '';
        if ($number === '') ppoOut(['success' => false, 'message' => 'Missing order_id'], 400);
        // order numbers repeat between restaurants: scope by restaurant when the page knows it
        $sql = 'SELECT id, restaurant_id, order_status, payment_status, payment_method FROM orders WHERE order_number = ?' . ($rid !== '' ? ' AND restaurant_id = ?' : '') . ' ORDER BY id DESC LIMIT 1';
        $st = $conn->prepare($sql);
        $st->execute($rid !== '' ? [$number, $rid] : [$number]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) ppoOut(['success' => false, 'message' => 'Order not found'], 404);
        if ($o['payment_status'] !== 'Paid' && !in_array($o['order_status'], ['Cancelled', 'Rejected'], true)) ppoSettleOpen($conn, (int)$o['id']);
        $st = $conn->prepare('SELECT order_status, payment_status, payment_method FROM orders WHERE id = ?');
        $st->execute([$o['id']]);
        $o = array_merge($o, $st->fetch(PDO::FETCH_ASSOC) ?: []);
        $open = $conn->prepare("SELECT SUM(payment_status = 'Pending') pending, SUM(payment_status = 'Failed') failed, COUNT(*) n FROM payments WHERE order_id = ? AND payment_method = 'Online'");
        $open->execute([$o['id']]);
        $a = $open->fetch(PDO::FETCH_ASSOC);
        $cancelled = in_array($o['order_status'], ['Cancelled', 'Rejected'], true);
        if ($o['payment_status'] === 'Paid') $status = 'Success';
        elseif ($o['payment_method'] === 'Cash' && !$cancelled) $status = 'Cash';
        elseif ($cancelled || ((int)$a['n'] > 0 && (int)$a['pending'] === 0)) $status = 'Failed';
        else $status = 'Pending';
        // Back from PhonePe without paying: PhonePe still says PENDING (until the page expires) but shows no
        // payment being made. The page then offers the choices at once instead of waiting.
        $notStarted = false;
        if ($status === 'Pending') {
            $t = $conn->prepare("SELECT transaction_id FROM payments WHERE order_id = ? AND payment_method = 'Online' AND payment_status = 'Pending' AND transaction_id LIKE 'PP\_%' ORDER BY id DESC LIMIT 1");
            $t->execute([$o['id']]);
            $last = $GLOBALS['PP_LAST_STATUS'][(string)$t->fetchColumn()] ?? null;
            $notStarted = $last && empty($last['live_attempt']) && in_array($last['state'], ['PENDING', 'NOT_FOUND'], true);
        }
        $unpaid = !$cancelled && $o['payment_status'] !== 'Paid' && $o['payment_method'] === 'UPI / NetBanking';
        ppoOut(['success' => true, 'payment_status' => $status, 'order_status' => $o['order_status'], 'cancelled' => $cancelled,
                'not_started' => $notStarted, 'can_retry' => $unpaid, 'can_cash' => $unpaid && ppoCashAllowed($conn, $o['restaurant_id'])]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') ppoOut(['success' => false, 'message' => 'POST only'], 405);
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $order = ppoOwnOrder($conn, $in);
    $orderId = (int)$order['id'];
    $rid = (string)$order['restaurant_id'];
    $finished = in_array($order['order_status'], ['Cancelled', 'Rejected', 'Completed'], true);

    /* ── the customer gives up ── */
    if ($action === 'cancel') {
        if ($order['payment_status'] === 'Paid' || ppoSettleOpen($conn, $orderId)) ppoOut(['success' => true, 'paid' => true, 'message' => 'This order is already paid.']);
        if ($finished) ppoOut(['success' => true, 'cancelled' => true]);
        if (!in_array($order['order_status'], ['Pending', 'Scheduled'], true) || $order['payment_method'] !== 'UPI / NetBanking') ppoOut(['success' => false, 'message' => 'The restaurant has already started this order. Please call them.'], 409);
        $conn->beginTransaction();
        $r = validateAndUpdateOrderStatus($conn, $orderId, 'Cancelled', [], [], $rid, '[Cancelled by the customer before paying]');
        $conn->commit();
        $conn->prepare("UPDATE payments SET payment_status = 'Failed' WHERE order_id = ? AND payment_status = 'Pending' AND payment_method = 'Online'")->execute([$orderId]);
        ppoOut(['success' => !empty($r['success']), 'cancelled' => true]);
    }

    /* ── pay cash instead ── */
    if ($action === 'cash') {
        if ($order['payment_status'] === 'Paid' || ppoSettleOpen($conn, $orderId)) ppoOut(['success' => true, 'paid' => true, 'message' => 'This order is already paid.']);
        if ($finished || !in_array($order['order_status'], ['Pending', 'Scheduled'], true)) ppoOut(['success' => false, 'message' => 'This order can no longer be changed.'], 409);
        if (!ppoCashAllowed($conn, $rid)) ppoOut(['success' => false, 'message' => 'This restaurant only takes online payment.'], 400);
        $u = $conn->prepare("UPDATE orders SET payment_method = 'Cash', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND payment_method = 'UPI / NetBanking' AND payment_status = 'Pending'");
        $u->execute([$orderId]);
        $conn->prepare("UPDATE payments SET payment_status = 'Failed' WHERE order_id = ? AND payment_status = 'Pending' AND payment_method = 'Online'")->execute([$orderId]);
        if ($u->rowCount() > 0 && $order['order_status'] === 'Pending') {
            // now it is a normal cash order: kitchen ticket, alerts and emails, exactly like placing it with cash
            require_once __DIR__ . '/../config/order_confirmation.php';
            try { fireOrderConfirmedActions($conn, $orderId); } catch (Throwable $e) { error_log('cash switch confirm: ' . $e->getMessage()); }
        }
        ppoOut(['success' => true, 'cash' => true, 'order_number' => $order['order_number']]);
    }

    /* ── start (or restart) the online payment ── */
    if ($order['payment_status'] === 'Paid') ppoOut(['success' => true, 'paid' => true, 'message' => 'This order is already paid.']);
    if ($finished || !in_array($order['order_status'], ['Pending', 'Scheduled'], true)) ppoOut(['success' => false, 'message' => 'This order can no longer be paid. Please place it again.'], 409);
    if ($order['payment_method'] !== 'UPI / NetBanking') ppoOut(['success' => false, 'message' => 'This order is not set to pay online.'], 409);
    // an earlier try may have gone through after all: never charge twice
    if (ppoSettleOpen($conn, $orderId)) ppoOut(['success' => true, 'paid' => true, 'message' => 'This order is already paid.']);

    require_once __DIR__ . '/../config/countries.php';
    $cst = $conn->prepare('SELECT country FROM users WHERE restaurant_id = ? ORDER BY id LIMIT 1');
    $cst->execute([$rid]);
    if (!restaurantIsIndia((string)$cst->fetchColumn())) ppoOut(['success' => false, 'message' => 'Online payment is not available for this restaurant. Please pay cash on delivery or at the counter.'], 400);

    $amount = round((float)$order['total'] - (float)($order['wallet_paid'] ?? 0), 2);   // part may be paid from the wallet
    if ($amount < 1) ppoOut(['success' => false, 'message' => 'Nothing left to pay on this order.'], 400);
    $config = phonepeCallbackConfig($rid);
    if (!$config['ready'] && !$config['demo']) ppoOut(['success' => false, 'message' => 'Online payment is not set up at this restaurant right now. Please pay cash, or try again later.'], 503);

    $txn = ppTxnId('PP_', (string)$order['order_number']);
    $conn->prepare("INSERT INTO payments (restaurant_id, order_id, transaction_id, amount, payment_method, payment_status) VALUES (?, ?, ?, ?, 'Online', 'Pending')")->execute([$rid, $orderId, $txn, $amount]);
    $back = ppoReturnBase() . (!empty($in['embed']) ? '/main/embed/embed.php' : '/main/website/cart.php') . '?restaurant_id=' . rawurlencode($rid) . '&order_id=' . rawurlencode((string)$order['order_number']);

    if ($config['demo']) {
        // local computer without PhonePe sandbox keys: nothing is charged; the order stays unpaid
        ppoOut(['success' => true, 'payment_url' => $back . '&phonepe_demo=1', 'transaction_id' => $txn, 'demo_mode' => true]);
    }
    $r = phonepeCreatePayment($config, $txn, $amount, $back, 1200);
    if (!$r['ok']) {
        $conn->prepare("UPDATE payments SET payment_status = 'Failed' WHERE transaction_id = ?")->execute([$txn]);
        error_log('PhonePe order payment for order ' . $orderId . ' refused: ' . $r['error']);
        ppoOut(['success' => false, 'message' => 'Could not open PhonePe right now. Please try again, or pay cash.'], 502);
    }
    ppoOut(['success' => true, 'payment_url' => $r['url'], 'transaction_id' => $txn]);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('phonepe_order_payment: ' . $e->getMessage());
    ppoOut(['success' => false, 'message' => 'Something went wrong with the payment. Please try again.'], 500);
}
