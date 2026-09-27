<?php
// Login security logs + blocked IPs — viewable by Admin and Manager only.
// Waiters/chefs don't get to see who logs in or manage the blocklist.

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

if (!getUserRole() || !in_array(getUserRole(), [ROLE_ADMIN, ROLE_MANAGER], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only Admin or Manager can view security logs'], JSON_UNESCAPED_UNICODE);
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
    loginLogEnsureTables($conn);

    // ── Filters ──
    // outcome: '' | success | failed | locked_out | blocked_ip | logout | new_device
    // days:    lookback window, default 7
    // limit:   max rows, capped at 200
    $outcomeFilter = $_GET['outcome'] ?? '';
    $days = max(1, min(90, (int)($_GET['days'] ?? 7)));
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));

    $whereConditions = ['l.restaurant_id = ?', 'l.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)'];
    $params = [$restaurant_id, $days];

    if ($outcomeFilter === 'new_device') {
        $whereConditions[] = 'l.is_new_device = 1 AND l.outcome = \'success\'';
    } elseif ($outcomeFilter !== '' && in_array($outcomeFilter, ['success', 'failed', 'locked_out', 'blocked_ip', 'logout'], true)) {
        $whereConditions[] = 'l.outcome = ?';
        $params[] = $outcomeFilter;
    }

    $whereClause = implode(' AND ', $whereConditions);

    $stmt = $conn->prepare("
        SELECT l.id, l.username_tried, l.user_type, l.actor_name, l.outcome,
               l.ip_address, l.device_label, l.is_new_device, l.created_at
        FROM login_logs l
        WHERE $whereClause
        ORDER BY l.created_at DESC, l.id DESC
        LIMIT $limit
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Summary counts for the same window (chips/cards at the top) ──
    $summaryStmt = $conn->prepare("
        SELECT
            SUM(l.outcome = 'success')     AS success_count,
            SUM(l.outcome = 'failed')      AS failed_count,
            SUM(l.outcome = 'locked_out')  AS locked_count,
            SUM(l.outcome = 'blocked_ip')  AS blocked_count,
            SUM(l.is_new_device = 1 AND l.outcome = 'success') AS new_device_count
        FROM login_logs l
        WHERE l.restaurant_id = ? AND l.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $summaryStmt->execute([$restaurant_id, $days]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── Blocked IPs for this restaurant (+ global rows any admin added) ──
    $blockedStmt = $conn->prepare("
        SELECT b.id, b.ip_address, b.reason, b.blocked_by, b.created_at
        FROM blocked_ips b
        ORDER BY b.created_at DESC
        LIMIT 100
    ");
    $blockedStmt->execute();
    $blockedIps = $blockedStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'logs' => $logs,
        'summary' => [
            'success_count' => (int)($summary['success_count'] ?? 0),
            'failed_count' => (int)($summary['failed_count'] ?? 0),
            'locked_count' => (int)($summary['locked_count'] ?? 0),
            'blocked_count' => (int)($summary['blocked_count'] ?? 0),
            'new_device_count' => (int)($summary['new_device_count'] ?? 0),
        ],
        'blocked_ips' => $blockedIps,
        'filters_applied' => [
            'outcome' => $outcomeFilter,
            'days' => $days,
        ],
        'policy' => [
            'max_attempts' => LOGIN_LOG_MAX_ATTEMPTS,
            'lockout_minutes' => LOGIN_LOG_LOCKOUT_MINUTES,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('PDO Error in get_login_logs.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again later.'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Error in get_login_logs.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred fetching logs. Please try again.'], JSON_UNESCAPED_UNICODE);
}
