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

header('Content-Type: application/json; charset=UTF-8');

// Require permission to manage customers
requirePermission(PERMISSION_MANAGE_CUSTOMERS);

// Include database connection
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found'], JSON_UNESCAPED_UNICODE);
    exit();
}

$restaurant_id = $_SESSION['restaurant_id'];
$action = $_POST['action'] ?? '';

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
    
    switch ($action) {
        case 'add':
            handleAddCustomer($conn, $restaurant_id);
            break;
            
        case 'update':
            handleUpdateCustomer($conn, $restaurant_id);
            break;
            
        case 'delete':
            handleDeleteCustomer($conn, $restaurant_id);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
    }
} catch (PDOException $e) {
    error_log("PDO Error in customer_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again later.'], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in customer_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.'], JSON_UNESCAPED_UNICODE);
    exit();
}

function handleAddCustomer($conn, $restaurant_id) {
    $customerName = trim($_POST['customerName'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    // Validation
    if (empty($customerName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Customer name is required'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (empty($phone)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Phone number is required'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Check if customer already exists
    $checkStmt = $conn->prepare("SELECT id FROM customers WHERE restaurant_id = ? AND phone = ?");
    $checkStmt->execute([$restaurant_id, $phone]);
    
    if ($checkStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Customer with this phone number already exists'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Insert customer
    $stmt = $conn->prepare("INSERT INTO customers (restaurant_id, customer_name, phone, email) VALUES (?, ?, ?, ?)");
    $stmt->execute([$restaurant_id, $customerName, $phone, $email]);
    
    echo json_encode(['success' => true, 'message' => 'Customer added successfully'], JSON_UNESCAPED_UNICODE);
}

function handleUpdateCustomer($conn, $restaurant_id) {
    $customerId = $_POST['customerId'] ?? '';
    $customerName = trim($_POST['customerName'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    // Validation
    if (empty($customerName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Customer name is required'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (empty($phone)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Phone number is required'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Check if customer exists and belongs to restaurant
    $checkStmt = $conn->prepare("SELECT id FROM customers WHERE id = ? AND restaurant_id = ?");
    $checkStmt->execute([$customerId, $restaurant_id]);
    
    if (!$checkStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Customer not found'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Check if phone number already exists for another customer
    $phoneCheckStmt = $conn->prepare("SELECT id FROM customers WHERE restaurant_id = ? AND phone = ? AND id != ?");
    $phoneCheckStmt->execute([$restaurant_id, $phone, $customerId]);
    
    if ($phoneCheckStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Phone number already exists for another customer'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Update customer
    $stmt = $conn->prepare("UPDATE customers SET customer_name = ?, phone = ?, email = ? WHERE id = ? AND restaurant_id = ?");
    $stmt->execute([$customerName, $phone, $email, $customerId, $restaurant_id]);
    
    echo json_encode(['success' => true, 'message' => 'Customer updated successfully'], JSON_UNESCAPED_UNICODE);
}

function handleDeleteCustomer($conn, $restaurant_id) {
    $customerId = $_POST['customerId'] ?? '';
    
    if (empty($customerId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Customer ID is required'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Check if customer exists and belongs to restaurant
    $checkStmt = $conn->prepare("SELECT id FROM customers WHERE id = ? AND restaurant_id = ?");
    $checkStmt->execute([$customerId, $restaurant_id]);
    
    if (!$checkStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Customer not found'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Delete customer
    $stmt = $conn->prepare("DELETE FROM customers WHERE id = ? AND restaurant_id = ?");
    $stmt->execute([$customerId, $restaurant_id]);
    
    echo json_encode(['success' => true, 'message' => 'Customer deleted successfully'], JSON_UNESCAPED_UNICODE);
}
?>

