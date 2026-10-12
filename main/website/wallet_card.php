<?php
/**
 * "Add to Apple Wallet" / "Add to Google Wallet" for the logged-in member
 * (Loyalty Studio › Phone card).
 *
 *   wallet_card.php?restaurant_id=…&type=apple   — the signed .pkpass
 *   wallet_card.php?restaurant_id=…&type=google  — redirects to Google's save page
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/customer_session.php';
require_once __DIR__ . '/../config/wallet_card.php';
require_once __DIR__ . '/../config/vip_membership.php';

function wcFail(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font-family:system-ui;padding:32px;text-align:center;color:#374151">'
       . '<h2 style="color:#111827">' . htmlspecialchars($msg) . '</h2><p><a href="javascript:history.back()">Go back</a></p></body>';
    exit;
}

try {
    $conn = isset($pdo) ? $pdo : getConnection();
    $rid = substr(trim((string)($_GET['restaurant_id'] ?? '')), 0, 50);
    $type = ($_GET['type'] ?? '') === 'apple' ? 'apple' : 'google';
    if ($rid === '') wcFail('Restaurant not found', 404);
    ensureWalletCardSchema($conn);
    $customer = getLoggedInCustomer($conn, $rid);
    session_write_close();
    if (!$customer) wcFail('Please log in to add your card', 401);
    $cs = walletCardSettings($conn, $rid);
    if (!$cs['enabled'] || !$cs['loyalty_enabled']) wcFail('The rewards card is not available here yet', 404);
    if (!walletCardPlatforms()[$type]) wcFail(($type === 'apple' ? 'Apple' : 'Google') . ' Wallet cards are not available yet', 404);
    $st = $conn->prepare('SELECT * FROM customers WHERE id = ?');
    $st->execute([(int)$customer['id']]);
    $full = $st->fetch(PDO::FETCH_ASSOC);

    if ($type === 'google') {
        $url = walletCardGoogleUrl($conn, $rid, $full);
        if (!$url) wcFail('Could not create your card. Please try again.', 500);
        header('Location: ' . $url, true, 302);
        exit;
    }
    $pass = walletCardApplePass($conn, $rid, $full);
    if (!$pass) wcFail('Could not create your card. Please try again.', 500);
    header('Content-Type: application/vnd.apple.pkpass');
    header('Content-Disposition: attachment; filename="rewards.pkpass"');
    header('Content-Length: ' . strlen($pass));
    header('Cache-Control: no-store');
    echo $pass;
} catch (Throwable $e) {
    error_log('wallet_card.php: ' . $e->getMessage());
    wcFail('Something went wrong. Please try again.', 500);
}
