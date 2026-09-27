<?php
// Block / unblock an IP address. Admin only — Managers can view the logs
// but only the owner decides who gets cut off.

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (ob_get_level()) {
    ob_clean();
}

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin();

requirePermission(PERMISSION_MANAGE_ORDERS);

if (getUserRole() !== ROLE_ADMIN) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only Admin can block or unblock IPs'], JSON_UNESCAPED_UNICODE);
    exit();
}

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found'], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
    if (!$conn) {
        throw new Exception('Database connection not available');
    }

    $restaurant_id = $_SESSION['restaurant_id'] ?? null;
    if (!$restaurant_id) {
        throw new Exception('Restaurant ID is required');
    }

    require_once __DIR__ . '/../config/login_log_helpers.php';
    // getActorName() (session identity for the audit columns) lives here.
    require_once __DIR__ . '/../config/soft_delete_helpers.php';

    $action = $_POST['action'] ?? '';
    $ip = trim($_POST['ip'] ?? '');

    if (!in_array($action, ['block_ip', 'unblock_ip'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        echo json_encode(['success' => false, 'message' => 'A valid IP address is required'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Safety valve: never let the owner lock themselves out of their own
    // server. If this request is coming from the IP being blocked, refuse.
    if ($action === 'block_ip' && $ip === loginLogClientIp()) {
        echo json_encode(['success' => false, 'message' => "You can't block the IP you're currently using"], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($action === 'block_ip') {
        $reason = trim($_POST['reason'] ?? '');
        $ok = loginLogBlockIp($conn, $restaurant_id, $ip, $reason !== '' ? $reason : null, getActorName());
        if (!$ok) {
            throw new Exception('Could not save the block');
        }
        error_log(sprintf('SECURITY: IP %s blocked by %s (restaurant %s)%s', $ip, getActorName(), $restaurant_id, $reason !== '' ? ' — ' . $reason : ''));
        echo json_encode(['success' => true, 'message' => $ip . ' is now blocked'], JSON_UNESCAPED_UNICODE);
    } else {
        $ok = loginLogUnblockIp($conn, $ip);
        error_log(sprintf('SECURITY: IP %s unblocked by %s (restaurant %s)', $ip, getActorName(), $restaurant_id));
        echo json_encode(['success' => $ok, 'message' => $ok ? $ip . ' unblocked' : 'IP was not on the blocklist'], JSON_UNESCAPED_UNICODE);
    }

} catch (PDOException $e) {
    error_log('PDO Error in security_operations.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again later.'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Error in security_operations.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.'], JSON_UNESCAPED_UNICODE);
}
