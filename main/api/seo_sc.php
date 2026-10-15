<?php
/**
 * Search Console (dashboard › SEO › Search performance). Engine: config/seo_search_console.php.
 *
 *   GET  ?view=connect   the Google sign-in address (Connect Google)
 *   GET  ?view=status    connection state, the site, metrics and the owner's sites
 *   GET  ?view=sites     the owner's verified Search Console properties
 *   POST {action: set_site | refresh | disconnect}
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}
$rid = (string)$_SESSION['restaurant_id'];
$canEdit = hasPermission(PERMISSION_MANAGE_SETTINGS);
$view = $_GET['view'] ?? '';
if ($view === 'connect' && $canEdit) {   // the sign-in round trip is tied to this login
    $_SESSION['seo_sc_state'] = bin2hex(random_bytes(16));
    $_SESSION['seo_sc_state_rid'] = $rid;
}
session_write_close();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/seo_search_console.php';

function scOut(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function scFail(string $m, int $code = 400) { scOut(['success' => false, 'message' => $m], $code); }

try {
    $conn = getConnection();
    seoScEnsureTables($conn);
    $row = seoScRow($conn, $rid);
    $connected = !empty($row['refresh_token']);
    $ready = seoScOAuthReady();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if ($view === 'connect') {
            if (!$canEdit) scFail('You don’t have permission to connect Google.', 403);
            if (!$ready) scFail('Google sign-in isn’t set up on the server yet (GOOGLE_SEO_CLIENT_ID / GOOGLE_SEO_CLIENT_SECRET).');
            scOut(['success' => true, 'url' => seoScAuthUrl($_SESSION['seo_sc_state'] ?? '')]);
        }
        if ($view === 'sites') {
            if (!$connected) scFail('Connect Google first.');
            $s = seoScSites($conn, $rid);
            if (!$s['ok']) scFail($s['error']);
            scOut(['success' => true, 'sites' => $s['sites']]);
        }
        // status (default)
        $data = ['success' => true, 'can_edit' => $canEdit, 'available' => $ready, 'connected' => $connected,
            'google_email' => $row['google_email'] ?? null, 'site' => $row['site_url'] ?? null,
            'redirect_uri' => seoScRedirectUri(), 'last_error' => $row['last_error'] ?? null];
        if ($connected) {
            $m = seoScQuery($conn, $rid);
            if ($m['ok']) { $data['metrics'] = $m; $data['last_error'] = null; }
            else { $data['metrics'] = null; $data['last_error'] = $m['error']; }
        }
        scOut($data);
    }

    // ── POST ──
    if (!$canEdit) scFail('You don’t have permission to change this.', 403);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $in['action'] ?? '';

    if ($action === 'set_site') {
        if (!$connected) scFail('Connect Google first.');
        $site = rtrim(trim((string)($in['site'] ?? '')), '/');
        if (!preg_match('#^https?://#', $site)) scFail('Choose your website from the list.');
        $s = seoScSites($conn, $rid);
        if (!$s['ok']) scFail($s['error']);
        $urls = array_column($s['sites'], 'url');
        if (!in_array($site, $urls, true)) scFail('That website isn’t in your Search Console. Verify it there first, then pick it again.');
        seoScSave($conn, $rid, ['site_url' => $site, 'last_error' => null]);
        scOut(['success' => true, 'metrics' => seoScQuery($conn, $rid, $site)]);
    }
    if ($action === 'refresh') {
        if (!$connected) scFail('Connect Google first.');
        if (empty($row['site_url'])) scFail('Choose your website in Google Search Console first.');
        $m = seoScQuery($conn, $rid);
        scOut(['success' => $m['ok'], 'message' => $m['ok'] ? 'Up to date' : $m['error'], 'metrics' => $m['ok'] ? $m : null]);
    }
    if ($action === 'disconnect') {
        if ($connected) seoScHttp('POST', 'https://oauth2.googleapis.com/revoke?token=' . urlencode(seoScOpen($row['refresh_token'])), ['Content-Type: application/x-www-form-urlencoded']);
        seoScSave($conn, $rid, ['access_token' => null, 'refresh_token' => null, 'token_expires' => null, 'google_email' => null, 'site_url' => null, 'last_error' => null]);
        scOut(['success' => true]);
    }
    scFail('Unknown action');
} catch (Throwable $e) {
    error_log('seo_sc api: ' . $e->getMessage());
    scFail('Something went wrong. Please try again.', 500);
}
