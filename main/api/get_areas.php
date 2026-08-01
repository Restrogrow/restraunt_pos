<?php
// Suppress error display, log errors instead
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';

// Ensure no output before headers
if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Require permission to manage areas
requirePermission(PERMISSION_MANAGE_AREAS);

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
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Please login to continue',
            'data' => []
        ]);
        exit();
    }
}

// Always scope to the authenticated session's own tenant — never trust a
// client-supplied restaurant_id, or one restaurant's staff could read another's areas.
$restaurant_id = $_SESSION['restaurant_id'] ?? null;

if (!$restaurant_id) {
    http_response_code(400);
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
    
    // Get all areas for this restaurant with table count
    $stmt = $conn->prepare("
        SELECT a.id, a.area_name, a.created_at, a.updated_at, 
               COUNT(t.id) as no_of_tables 
        FROM areas a 
        LEFT JOIN tables t ON a.id = t.area_id 
        WHERE a.restaurant_id = ? 
        GROUP BY a.id, a.area_name, a.created_at, a.updated_at
        ORDER BY a.sort_order ASC, a.created_at DESC
    ");
    $stmt->execute([$restaurant_id]);
    $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return success response
    echo json_encode([
        'success' => true,
        'data' => $areas,
        'count' => count($areas)
    ]);
    
} catch (PDOException $e) {
    error_log("PDO Error in get_areas.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.',
        'data' => []
    ]);
    exit();
} catch (Exception $e) {
    error_log("Error in get_areas.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again.',
        'data' => []
    ]);
    exit();
}
?>

