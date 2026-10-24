<?php
/**
 * Dashboard › API keys (assets/js/api-keys-studio.js). The keys themselves: config/api_keys.php; the API: api/v1.
 *
 * GET                                                     the restaurant's keys (never the key itself) and the permissions to pick from
 * POST {action:'create', name, perms[], expires, ips}     a new key: the full key comes back once, only here
 *      expires = 'never' or 'YYYY-MM-DD' (valid through that day); ips = comma separated, empty = any address
 * POST {action:'update', id, name, perms[], expires, ips} change a key's settings; the key itself stays the same
 * POST {action:'rotate', id, grace}                       a new secret with the same settings; the old one ends now (0),
 *                                                         in 60 or in 1440 minutes. The new key comes back once.
 * POST {action:'pause' | 'resume', id}                    stop / allow every call with that key, without losing it
 * POST {action:'revoke', id}                              that key stops working for good
 * POST {action:'delete', id}                              remove a revoked or expired key from the list
 *
 * Owner / manager with settings access can look; only the owner changes keys. Always the session's restaurant.
 */
require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin();
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || empty($_SESSION['restaurant_id']) || !hasPermission(PERMISSION_MANAGE_SETTINGS)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}
$rid = (string)$_SESSION['restaurant_id'];
$isOwner = ($_SESSION['user_type'] ?? '') === 'admin' && !empty($_SESSION['user_id']);
$userId = (int)($_SESSION['user_id'] ?? 0);
session_write_close();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/api_keys.php';

function akOut(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

/** "until 31 Dec 2026" / "never ends" for the activity log. */
function akUntil(?string $at): string { return $at ? 'valid until ' . date('j M Y', strtotime($at)) : 'never ends'; }

try {
    $conn = getConnection();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$isOwner) akOut(['success' => false, 'message' => 'Only the restaurant owner can change API keys.'], 403);
        $in = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($in['id'] ?? 0);
        require_once __DIR__ . '/../config/activity_log.php';
        $log = fn(string $action, string $summary) => logActivity($conn, $rid, 'api_key.' . $action, $summary, ['key_id' => $id ?: null]);
        switch ($in['action'] ?? '') {
            case 'create':
                $r = apiKeyCreate($conn, $rid, $userId, $in);
                if (isset($r['error'])) akOut(['success' => false, 'message' => $r['error']], 422);
                $id = $r['row']['id'];
                $log('create', 'Created API key "' . $r['row']['name'] . '" (' . implode(', ', $r['row']['perms']) . ', ' . akUntil($r['row']['expires_at']) . ')');
                akOut(['success' => true, 'key' => $r['key'], 'row' => $r['row']]);
            case 'update':
                $r = apiKeyUpdate($conn, $rid, $id, $in);
                if (isset($r['error'])) akOut(['success' => false, 'message' => $r['error']], 422);
                $log('update', 'Edited API key "' . $r['row']['name'] . '" (' . implode(', ', $r['row']['perms']) . ', ' . akUntil($r['row']['expires_at'])
                    . ($r['row']['allowed_ips'] ? ', IPs ' . implode(', ', $r['row']['allowed_ips']) : '') . ')');
                akOut(['success' => true, 'row' => $r['row']]);
            case 'rotate':
                $grace = (int)($in['grace'] ?? 0);
                $r = apiKeyRotate($conn, $rid, $userId, $id, $grace);
                if (isset($r['error'])) akOut(['success' => false, 'message' => $r['error']], 422);
                $log('rotate', 'Rotated API key "' . $r['name'] . '": the old key ' . ($grace === 1440 ? 'works 24 more hours' : ($grace === 60 ? 'works 1 more hour' : 'stopped at once')));
                akOut(['success' => true, 'key' => $r['key'], 'row' => $r['row']]);
            case 'pause':
            case 'resume':
                $pause = $in['action'] === 'pause';
                $name = apiKeyPause($conn, $rid, $id, $pause);
                if ($name === null) akOut(['success' => false, 'message' => 'That key is revoked or gone.'], 404);
                $log($in['action'], ($pause ? 'Paused' : 'Resumed') . ' API key "' . $name . '"');
                akOut(['success' => true, 'row' => apiKeyRow($conn, $rid, $id)]);
            case 'revoke':
                $name = apiKeyRevoke($conn, $rid, $id);
                if ($name === null) akOut(['success' => false, 'message' => 'That key is already revoked.'], 404);
                $log('revoke', 'Revoked API key "' . $name . '"');
                akOut(['success' => true]);
            case 'delete':
                $name = apiKeyDelete($conn, $rid, $id);
                if ($name === null) akOut(['success' => false, 'message' => 'Only revoked or expired keys can be deleted. Revoke it first.'], 422);
                $log('delete', 'Deleted ended API key "' . $name . '"');
                akOut(['success' => true]);
        }
        akOut(['success' => false, 'message' => 'Unknown action'], 400);
    }
    $perms = [];
    foreach (API_PERMS as $k => [$label, $what]) $perms[] = ['id' => $k, 'label' => $label, 'what' => $what];
    // always the main site's address (also when the dashboard is opened locally): that is where the API lives
    akOut(['success' => true, 'is_owner' => $isOwner, 'base_url' => 'https://restrogrow.com/api/v1', 'mcp_url' => 'https://restrogrow.com/mcp',
        'docs_url' => 'https://restrogrow.com/developers', 'perms' => $perms, 'max_keys' => API_KEYS_MAX, 'your_ip' => apiKeyClientIp(),
        'today' => date('Y-m-d'), 'keys' => apiKeyList($conn, $rid)]);
} catch (Throwable $e) {
    error_log('api_keys_admin: ' . $e->getMessage());
    akOut(['success' => false, 'message' => 'Could not do that. Please try again.'], 500);
}
