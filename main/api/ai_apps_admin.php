<?php
/**
 * Growth › ChatGPT & Claude (assets/js/ai-apps-studio.js). The MCP app itself: main/mcp, config/mcp_oauth.php.
 *
 * GET                                     the connect address and the apps connected to this restaurant
 * POST {action:'disconnect', client_id}   that app's tokens stop working at once (it has to connect again)
 *
 * Owner / manager with settings access. Always the session's restaurant.
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
$isOwner = ($_SESSION['user_type'] ?? '') === 'admin';
session_write_close();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/mcp_oauth.php';

function aiaOut(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

try {
    $conn = getConnection();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
        if (($in['action'] ?? '') !== 'disconnect') aiaOut(['success' => false, 'message' => 'Unknown action'], 400);
        $clientId = (string)($in['client_id'] ?? '');
        $label = 'the app';
        foreach (mcpConnections($conn, $rid) as $c) if ($c['client_id'] === $clientId) $label = $c['label'];
        if (!mcpDisconnect($conn, $rid, $clientId)) aiaOut(['success' => false, 'message' => 'That app is not connected any more.'], 404);
        try {
            require_once __DIR__ . '/../config/activity_log.php';
            logActivity($conn, $rid, 'ai_app.disconnect', 'Disconnected ' . $label, ['client' => $clientId]);
        } catch (Throwable $e) {}
        aiaOut(['success' => true]);
    }
    // always the main site's address (also when the dashboard is opened locally or on www.): ChatGPT can't reach a PC
    $url = 'https://restrogrow.com/mcp';
    aiaOut(['success' => true, 'url' => $url, 'is_owner' => $isOwner, 'connections' => mcpConnections($conn, $rid)]);
} catch (Throwable $e) {
    error_log('ai_apps_admin: ' . $e->getMessage());
    aiaOut(['success' => false, 'message' => 'Could not load. Please try again.'], 500);
}
