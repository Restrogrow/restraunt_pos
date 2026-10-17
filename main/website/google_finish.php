<?php
/**
 * Last step of "Continue with Google" on a restaurant website (config/google_login.php).
 * Runs on the restaurant's own host (restrogrow.com or its own domain), so the login sticks there.
 *
 *  - An account here already uses this Google email: logged in straight away.
 *  - Someone new: back to the restaurant's normal Sign Up form with name and email filled in.
 *    Google stands in for the WhatsApp code there (website/customer_auth.php), unless the phone
 *    they enter already has orders or an account here.
 */
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/google_login.php';
require_once __DIR__ . '/customer_session.php';
startSecureSession();

$safePath = function ($p) {
    $p = (string)$p;
    return ($p !== '' && $p[0] === '/' && !str_starts_with($p, '//') && !preg_match('/[\r\n\\\\]/', $p)) ? $p : '/';
};

$conn = getConnection();
$p = googleLoginConsumeToken($conn, (string)($_GET['t'] ?? ''), 'customer');
if (!$p) {
    header('Location: /');
    exit;
}
$rid = (string)$p['restaurant_id'];

$st = $conn->prepare("SELECT id, restaurant_id, customer_name, phone, email FROM customers
    WHERE restaurant_id = ? AND LOWER(email) = ? AND COALESCE(phone, '') <> ''
    ORDER BY password_hash IS NOT NULL DESC, total_visits DESC, id LIMIT 1");
$st->execute([$rid, $p['email']]);
$existing = $st->fetch(PDO::FETCH_ASSOC);
if ($existing) {
    startCustomerSession($conn, $existing, true);
    header('Location: ' . $safePath($p['ret'] ?? '/'));
    exit;
}

// new here: the normal sign-up, filled in (remembered on the server, not trusted from the URL)
$_SESSION['google_pending'] = ['rid' => $rid, 'email' => $p['email'], 'name' => $p['name'], 'exp' => time() + 1800];
$back = $safePath($p['back'] ?? ($p['ret'] ?? '/'));
header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'google_signup=1');
exit;
