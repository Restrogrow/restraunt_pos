<?php
/**
 * Dashboard › API keys (assets/js/api-keys-studio.js). The keys themselves: config/api_keys.php; the API: api/v1.
 *
 * GET                                             the restaurant's keys (never the key itself) and the permissions to pick from
 * POST {action:'create', name, perms[], days}     a new key: the full key comes back once, only here
 * POST {action:'revoke', id}                      that key stops working at once
 *
 * Owner / manager with settings access can look; only the owner creates and revokes. Always the session's restaurant.
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

try {
    $conn = getConnection();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$isOwner) akOut(['success' => false, 'message' => 'Only the restaurant owner can create or revoke API keys.'], 403);
        $in = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
        require_once __DIR__ . '/../config/activity_log.php';
        switch ($in['action'] ?? '') {
            case 'create':
                $r = apiKeyCreate($conn, $rid, $userId, (string)($in['name'] ?? ''), (array)($in['perms'] ?? []), (int)($in['days'] ?? 0));
                if (isset($r['error'])) akOut(['success' => false, 'message' => $r['error']], 422);
                logActivity($conn, $rid, 'api_key.create', 'Created API key "' . $r['row']['name'] . '" (' . implode(', ', $r['row']['perms']) . ')', ['key_id' => $r['row']['id']]);
                akOut(['success' => true, 'key' => $r['key'], 'row' => $r['row']]);
            case 'revoke':
                $name = apiKeyRevoke($conn, $rid, (int)($in['id'] ?? 0));
                if ($name === null) akOut(['success' => false, 'message' => 'That key is already revoked.'], 404);
                logActivity($conn, $rid, 'api_key.revoke', 'Revoked API key "' . $name . '"', ['key_id' => (int)$in['id']]);
                akOut(['success' => true]);
        }
        akOut(['success' => false, 'message' => 'Unknown action'], 400);
    }
    $perms = [];
    foreach (API_PERMS as $k => [$label, $what]) $perms[] = ['id' => $k, 'label' => $label, 'what' => $what];
    // always the main site's address (also when the dashboard is opened locally): that is where the API lives
    akOut(['success' => true, 'is_owner' => $isOwner, 'base_url' => 'https://restrogrow.com/api/v1', 'mcp_url' => 'https://restrogrow.com/mcp',
        'docs_url' => 'https://restrogrow.com/developers', 'perms' => $perms, 'keys' => apiKeyList($conn, $rid)]);
} catch (Throwable $e) {
    error_log('api_keys_admin: ' . $e->getMessage());
    akOut(['success' => false, 'message' => 'Could not load. Please try again.'], 500);
}
