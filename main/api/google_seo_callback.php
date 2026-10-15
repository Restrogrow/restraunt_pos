<?php
/**
 * Google sends the owner back here after "Connect Google" (dashboard › SEO › Search performance).
 * Checks the state from api/seo_sc.php?view=connect, swaps the code for tokens (kept encrypted,
 * see config/seo_search_console.php) and returns to the SEO page.
 * This address must be an authorised redirect URI on the OAuth client.
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';

$back = function (string $result) {
    header('Location: ../views/dashboard.php?seo_sc=' . urlencode($result) . '#seoPage');
    exit;
};
if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) { header('Location: ../admin/login.php'); exit; }
$rid = (string)$_SESSION['restaurant_id'];
$expected = $_SESSION['seo_sc_state'] ?? '';
$stateRid = $_SESSION['seo_sc_state_rid'] ?? '';
unset($_SESSION['seo_sc_state'], $_SESSION['seo_sc_state_rid']);
session_write_close();

if (!empty($_GET['error'])) $back($_GET['error'] === 'access_denied' ? 'cancelled' : 'error');
if ($expected === '' || !hash_equals($expected, (string)($_GET['state'] ?? '')) || $stateRid !== $rid) $back('expired');
$code = (string)($_GET['code'] ?? '');
if ($code === '') $back('error');

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/seo_search_console.php';
try {
    $conn = getConnection();
    $r = seoScExchange($code);
    if ($r['code'] !== 200 || empty($r['json']['access_token'])) { error_log('SEO Search Console connect: ' . $r['raw']); $back('error'); }
    $t = $r['json'];
    $old = seoScRow($conn, $rid);
    // a refresh token comes only on the first consent; keep the old one if Google sent none
    $refresh = !empty($t['refresh_token']) ? seoScSeal($t['refresh_token']) : ($old['refresh_token'] ?? null);
    if (!$refresh) $back('no_refresh');
    $email = null;
    if (!empty($t['id_token'])) {
        $parts = explode('.', $t['id_token']);
        $claims = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
        $email = $claims['email'] ?? null;
    }
    seoScSave($conn, $rid, ['access_token' => seoScSeal($t['access_token']), 'refresh_token' => $refresh, 'token_expires' => time() + (int)($t['expires_in'] ?? 3600),
        'google_email' => $email, 'connected_at' => date('Y-m-d H:i:s'), 'last_error' => null]);
    $back('connected');
} catch (Throwable $e) {
    error_log('SEO Search Console connect: ' . $e->getMessage());
    $back('error');
}
