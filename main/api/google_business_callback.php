<?php
/**
 * Google sends the owner back here after "Connect Google" (dashboard › Google Business).
 * Checks the state from api/gbp.php?view=connect, swaps the code for tokens (kept encrypted, see
 * config/gbp_helpers.php) and returns to the dashboard, which then asks which location to use.
 * This address must be an authorised redirect URI on the OAuth client (restrogrow-gmb project).
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';

$back = function (string $result) {
    header('Location: ../views/dashboard.php?gbp=' . urlencode($result) . '#googleBusinessPage');
    exit;
};
if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) { header('Location: ../admin/login.php'); exit; }
$rid = (string)$_SESSION['restaurant_id'];
$expected = $_SESSION['gbp_state'] ?? '';
$stateRid = $_SESSION['gbp_state_rid'] ?? '';
unset($_SESSION['gbp_state'], $_SESSION['gbp_state_rid']);
session_write_close();

if (!empty($_GET['error'])) $back($_GET['error'] === 'access_denied' ? 'cancelled' : 'error');
if ($expected === '' || !hash_equals($expected, (string)($_GET['state'] ?? '')) || $stateRid !== $rid) $back('expired');
$code = (string)($_GET['code'] ?? '');
if ($code === '') $back('error');

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/gbp_helpers.php';
try {
    $conn = getConnection();
    $r = gbpExchange($code);
    if ($r['code'] !== 200 || empty($r['json']['access_token'])) { error_log('GBP connect: ' . $r['raw']); $back('error'); }
    $t = $r['json'];
    $old = gbpRow($conn, $rid);
    // a refresh token comes only on the first consent; keep the old one if Google sent none
    $refresh = !empty($t['refresh_token']) ? gbpSeal($t['refresh_token']) : ($old['refresh_token'] ?? null);
    if (!$refresh) $back('no_refresh');
    $email = null;
    if (!empty($t['id_token'])) {
        $parts = explode('.', $t['id_token']);
        $claims = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
        $email = $claims['email'] ?? null;
    }
    gbpSave($conn, $rid, ['access_token' => gbpSeal($t['access_token']), 'refresh_token' => $refresh, 'token_expires' => time() + (int)($t['expires_in'] ?? 3600),
        'google_email' => $email, 'connected_at' => date('Y-m-d H:i:s'), 'last_error' => null]);
    $back('connected');
} catch (Throwable $e) {
    error_log('GBP connect: ' . $e->getMessage());
    $back('error');
}
