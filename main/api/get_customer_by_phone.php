<?php
// Suppress error display, log errors instead
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Ensure no output before headers
if (ob_get_level()) {
    ob_clean();
}

// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json');

// Require permission to manage customers
requirePermission(PERMISSION_MANAGE_CUSTOMERS);

// Include database connection
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found']);
    exit();
}

$restaurant_id = $_SESSION['restaurant_id'];
$phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';

if (empty($phone)) {
    echo json_encode(['success' => false, 'message' => 'Phone number is required']);
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
    
    // First check customers table
    $stmt = $conn->prepare("SELECT * FROM customers WHERE restaurant_id = ? AND phone = ? LIMIT 1");
    $stmt->execute([$restaurant_id, $phone]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If not found, check orders table for customer details
    if (!$customer) {
        $orderStmt = $conn->prepare("
            SELECT DISTINCT 
                customer_name, 
                customer_phone as phone, 
                customer_email as email,
                customer_address as address
            FROM orders 
            WHERE restaurant_id = ? 
            AND customer_phone = ?
            AND customer_name IS NOT NULL 
            AND customer_name != '' 
            AND customer_name != 'Table Customer'
            AND customer_name != 'Takeaway'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $orderStmt->execute([$restaurant_id, $phone]);
        $orderCustomer = $orderStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($orderCustomer) {
            $customer = [
                'customer_name' => $orderCustomer['customer_name'],
                'phone' => $orderCustomer['phone'],
                'email' => $orderCustomer['email'] ?? '',
                'address' => $orderCustomer['address'] ?? ''
            ];
        }
    }
    
    if ($customer) {
        echo json_encode([
            'success' => true,
            'customer' => [
                'customer_name' => $customer['customer_name'] ?? $customer['name'] ?? '',
                'phone' => $customer['phone'] ?? '',
                'email' => $customer['email'] ?? '',
                'address' => $customer['address'] ?? ''
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
    }
} catch (PDOException $e) {
    error_log("PDO Error in get_customer_by_phone.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.'
    ]);
    exit();
} catch (Exception $e) {
    error_log("Error in get_customer_by_phone.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again.'
    ]);
    exit();
}

