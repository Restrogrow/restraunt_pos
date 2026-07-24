<?php
// Suppress error display, log errors instead
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Ensure no output before headers
if (ob_get_level()) {
    ob_clean();
}

// Set error handler to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'A fatal error occurred: ' . $error['message'],
            'data' => []
        ]);
    }
});

try {
    // Include secure session configuration
    require_once __DIR__ . '/../config/session_config.php';
    startSecureSession();

    // Include authorization configuration
    require_once __DIR__ . '/../config/authorization_config.php';

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET');
    header('Access-Control-Allow-Headers: Content-Type');
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error initializing: ' . $e->getMessage(),
        'data' => []
    ]);
    exit();
}

// Allow access if:
// 1. Has PERMISSION_MANAGE_TABLES (admin/manager)
// 2. Is staff (waiter/chef) with matching restaurant_id
// 3. Not logged in but restaurant_id is provided in query
$hasPermission = false;
$requested_restaurant_id = $_GET['restaurant_id'] ?? null;

if (isLoggedIn()) {
    // Check if user has permission
    try {
        if (hasPermission(PERMISSION_MANAGE_TABLES)) {
            $hasPermission = true;
        }
    } catch (Exception $e) {
        // Permission check failed, continue to staff check
    }
    
    // If no permission, check if staff with matching restaurant_id
    if (!$hasPermission && isset($_SESSION['staff_id']) && isset($_SESSION['restaurant_id'])) {
        $check_restaurant_id = $requested_restaurant_id ?? $_SESSION['restaurant_id'] ?? null;
        if ($check_restaurant_id && $check_restaurant_id === $_SESSION['restaurant_id']) {
            $hasPermission = true;
        }
    }
    
    // If still no permission and no restaurant_id in query, return error
    if (!$hasPermission && !$requested_restaurant_id) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. Restaurant ID required.',
            'data' => []
        ]);
        exit();
    }
} elseif (!$requested_restaurant_id) {
    // If not logged in and no restaurant_id provided, return error
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Restaurant ID required',
        'data' => []
    ]);
    exit();
}

// Include database connection
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection file not found',
        'data' => []
    ]);
    exit();
}

// Check if user is logged in (admin or staff)
if (!isset($_SESSION['restaurant_id']) && (!isset($_SESSION['user_id']) && !isset($_SESSION['staff_id']) && !isset($_SESSION['branch_admin_id']))) {
    // Allow restaurant_id from query parameter for staff logins
    if (!isset($_GET['restaurant_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Please login to continue',
            'data' => []
        ]);
        exit();
    }
}

$restaurant_id = $requested_restaurant_id ?? $_SESSION['restaurant_id'] ?? null;

if (!$restaurant_id) {
    echo json_encode([
        'success' => false,
        'message' => 'Restaurant ID is required',
        'data' => []
    ]);
    exit();
}

try {
    // Get connection using getConnection() for lazy connection support
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        // Fallback to $pdo if getConnection() doesn't exist (backward compatibility)
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
        }
    }
    
    // Get all tables for this restaurant with area names
    $stmt = $conn->prepare("SELECT t.*, a.area_name FROM tables t JOIN areas a ON t.area_id = a.id WHERE t.restaurant_id = ? ORDER BY t.sort_order ASC, t.created_at DESC");
    $stmt->execute([$restaurant_id]);
    $tables = $stmt->fetchAll();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'data' => $tables,
        'tables' => $tables,
        'count' => count($tables)
    ]);
    
} catch (PDOException $e) {
    error_log("PDO Error in get_tables.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.',
        'data' => []
    ]);
    exit();
} catch (Exception $e) {
    error_log("Error in get_tables.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'data' => []
    ]);
    exit();
}
?>
