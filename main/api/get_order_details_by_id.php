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

// Require permission to manage orders
requirePermission(PERMISSION_MANAGE_ORDERS);

// Include database connection
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found']);
    exit();
}
if (!isset($_SESSION['restaurant_id']) && (!isset($_SESSION['user_id']) && !isset($_SESSION['staff_id']) && !isset($_SESSION['branch_admin_id']))) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$order_id = $_GET['id'] ?? null;

if (!$order_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Order ID required']);
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
    
    // Get order details
    $sql = "SELECT 
                o.*,
                t.table_number,
                a.area_name,
                COALESCE(CONCAT(a.area_name, ' - ', t.table_number), t.table_number) as table_name
            FROM orders o
            LEFT JOIN tables t ON o.table_id = t.id
            LEFT JOIN areas a ON t.area_id = a.id
            WHERE o.id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit();
    }
    
    // Verify restaurant_id matches
    $restaurant_id = $_GET['restaurant_id'] ?? $_SESSION['restaurant_id'] ?? null;
    if ($restaurant_id && $order['restaurant_id'] != $restaurant_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit();
    }
    
    // Get order items
    $itemsSql = "SELECT * FROM order_items WHERE order_id = ?";
    $itemsStmt = $conn->prepare($itemsSql);
    $itemsStmt->execute([$order_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $order['items'] = $items;
    
    echo json_encode([
        'success' => true,
        'order' => $order
    ]);
    
} catch (PDOException $e) {
    error_log("PDO Error in get_order_details_by_id.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.'
    ]);
    exit();
} catch (Exception $e) {
    error_log("Error in get_order_details_by_id.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
    exit();
}
?>

