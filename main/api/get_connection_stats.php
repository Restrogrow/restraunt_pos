<?php
/**
 * JSON data source for admin/connection_monitor.php's live polling.
 * Returns the same stats the page renders server-side on first load, so the
 * page can refresh in place instead of doing a full window.location.reload().
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

header('Content-Type: application/json; charset=UTF-8');

if (!isSessionValid() || (!isset($_SESSION['user_id']) && !isset($_SESSION['staff_id']) && !isset($_SESSION['branch_admin_id']))) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

require_once __DIR__ . '/../db_connection.php';

try {
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        global $pdo;
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
        }
    }

    $stmt = $conn->query("SHOW VARIABLES LIKE 'max_connections'");
    $max_connections = (int)(($stmt->fetch(PDO::FETCH_ASSOC))['Value'] ?? 151);

    $stmt = $conn->query("SHOW VARIABLES LIKE 'max_connections_per_hour'");
    $max_connections_per_hour = (int)(($stmt->fetch(PDO::FETCH_ASSOC))['Value'] ?? 500);

    $stmt = $conn->query("SHOW STATUS LIKE 'Threads_connected'");
    $current_connections = (int)(($stmt->fetch(PDO::FETCH_ASSOC))['Value'] ?? 0);

    $stmt = $conn->query("SHOW STATUS LIKE 'Max_used_connections'");
    $max_used_connections = (int)(($stmt->fetch(PDO::FETCH_ASSOC))['Value'] ?? 0);

    $stmt = $conn->query("SHOW STATUS LIKE 'Connection_errors_max_connections'");
    $connection_errors = (int)(($stmt->fetch(PDO::FETCH_ASSOC))['Value'] ?? 0);

    $stmt = $conn->query("SHOW STATUS LIKE 'Threads_running'");
    $running_threads = (int)(($stmt->fetch(PDO::FETCH_ASSOC))['Value'] ?? 0);

    $process_list = [];
    try {
        $stmt = $conn->query("SHOW PROCESSLIST");
        $process_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // May not have permission
    }

    $connection_percentage = $max_connections > 0 ? ($current_connections / $max_connections) * 100 : 0;
    $status_class = 'success';
    if ($connection_percentage > 80) {
        $status_class = 'danger';
    } elseif ($connection_percentage > 60) {
        $status_class = 'warning';
    }

    global $host, $dbname, $options;
    $is_persistent = isset($options[PDO::ATTR_PERSISTENT]) && $options[PDO::ATTR_PERSISTENT];
    $connectionStats = $GLOBALS['db_connection_stats'] ?? null;

    echo json_encode([
        'success' => true,
        'current_connections' => $current_connections,
        'max_connections' => $max_connections,
        'max_connections_per_hour' => $max_connections_per_hour,
        'max_used_connections' => $max_used_connections,
        'connection_errors' => $connection_errors,
        'running_threads' => $running_threads,
        'connection_percentage' => round($connection_percentage, 1),
        'status_class' => $status_class,
        'process_list' => array_map(function ($p) {
            return [
                'id' => $p['Id'] ?? '',
                'user' => $p['User'] ?? '',
                'host' => $p['Host'] ?? '',
                'db' => $p['db'] ?? '-',
                'command' => $p['Command'] ?? '',
                'time' => $p['Time'] ?? '',
                'state' => $p['State'] ?? '-',
                'info' => substr($p['Info'] ?? '-', 0, 100),
            ];
        }, $process_list),
        'connection_info' => [
            'host' => $host ?? 'localhost',
            'db_name' => $dbname ?? 'N/A',
            'is_persistent' => $is_persistent,
            'php_version' => PHP_VERSION,
            'driver' => $conn->getAttribute(PDO::ATTR_DRIVER_NAME),
            'server_info' => $conn->getAttribute(PDO::ATTR_SERVER_INFO),
            'stats' => $connectionStats,
        ],
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
