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

// Require permission to manage payments
requirePermission(PERMISSION_MANAGE_PAYMENTS);

// Include database connection
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found']);
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
    $restaurant_id = $_SESSION['restaurant_id'];
    
    $sql = "SELECT 
                p.*,
                o.order_number,
                o.table_id,
                t.table_number
            FROM payments p
            JOIN orders o ON p.order_id = o.id
            LEFT JOIN tables t ON o.table_id = t.id
            WHERE p.restaurant_id = ?";
    
    $params = [$restaurant_id];
    
    // Add filters
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $search = '%' . $_GET['search'] . '%';
        $sql .= " AND (o.order_number LIKE ? OR p.amount LIKE ? OR p.transaction_id LIKE ?)";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }
    
    if (isset($_GET['method']) && !empty($_GET['method'])) {
        $sql .= " AND p.payment_method = ?";
        $params[] = $_GET['method'];
    }
    
    if (isset($_GET['status']) && !empty($_GET['status'])) {
        $sql .= " AND p.payment_status = ?";
        $params[] = $_GET['status'];
    }
    
    $sql .= " ORDER BY p.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'payments' => $payments,
        'count' => count($payments)
    ]);
    
} catch (PDOException $e) {
    error_log("PDO Error in get_payments.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.',
        'payments' => []
    ]);
    exit();
} catch (Exception $e) {
    error_log("Error in get_payments.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching payments: ' . $e->getMessage(),
        'payments' => []
    ]);
    exit();
}
?>

