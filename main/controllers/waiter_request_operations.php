<?php
// Suppress error display, log errors instead
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Ensure no output before headers
if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json; charset=UTF-8');

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';

// Require permission to manage orders (waiter requests are part of order management)
requirePermission(PERMISSION_MANAGE_ORDERS);

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
            handleAddRequest($conn, $restaurant_id);
            break;
            
        case 'mark_attended':
            handleMarkAttended($conn, $restaurant_id);
            break;
            
        case 'delete':
            handleDeleteRequest($conn, $restaurant_id);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
    }
} catch (PDOException $e) {
    error_log("PDO Error in waiter_request_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again later.'], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in waiter_request_operations.php: " . $e->getMessage());
    http_response_code(500);
    error_log("Error in waiter_request_operations.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.'], JSON_UNESCAPED_UNICODE);
    exit();
}

function handleAddRequest($conn, $restaurant_id) {
    $tableId = $_POST['tableId'] ?? '';
    $requestType = $_POST['requestType'] ?? 'General';
    $notes = $_POST['notes'] ?? '';
    
    // Validation
    if (empty($tableId)) {
        echo json_encode(['success' => false, 'message' => 'Table ID is required'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Check if table belongs to restaurant
    $checkStmt = $conn->prepare("SELECT id FROM tables WHERE id = ? AND restaurant_id = ?");
    $checkStmt->execute([$tableId, $restaurant_id]);
    
    if (!$checkStmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Invalid table'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Insert waiter request
    $stmt = $conn->prepare("INSERT INTO waiter_requests (restaurant_id, table_id, request_type, notes) VALUES (?, ?, ?, ?)");
    $stmt->execute([$restaurant_id, $tableId, $requestType, $notes]);
    
    echo json_encode(['success' => true, 'message' => 'Request added successfully'], JSON_UNESCAPED_UNICODE);
}

function handleMarkAttended($conn, $restaurant_id) {
    $requestId = $_POST['requestId'] ?? '';
    
    if (empty($requestId)) {
        echo json_encode(['success' => false, 'message' => 'Request ID is required'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Check if request exists and belongs to restaurant
    $checkStmt = $conn->prepare("SELECT id FROM waiter_requests WHERE id = ? AND restaurant_id = ?");
    $checkStmt->execute([$requestId, $restaurant_id]);
    
    if (!$checkStmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Request not found'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Update request status
    $stmt = $conn->prepare("UPDATE waiter_requests SET status = 'Attended', attended_at = NOW() WHERE id = ? AND restaurant_id = ?");
    $stmt->execute([$requestId, $restaurant_id]);
    
    echo json_encode(['success' => true, 'message' => 'Request marked as attended'], JSON_UNESCAPED_UNICODE);
}

function handleDeleteRequest($conn, $restaurant_id) {
    $requestId = $_POST['requestId'] ?? '';
    
    if (empty($requestId)) {
        echo json_encode(['success' => false, 'message' => 'Request ID is required'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Check if request exists and belongs to restaurant
    $checkStmt = $conn->prepare("SELECT id FROM waiter_requests WHERE id = ? AND restaurant_id = ?");
    $checkStmt->execute([$requestId, $restaurant_id]);
    
    if (!$checkStmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Request not found'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Delete request
    $stmt = $conn->prepare("DELETE FROM waiter_requests WHERE id = ? AND restaurant_id = ?");
    $stmt->execute([$requestId, $restaurant_id]);
    
    echo json_encode(['success' => true, 'message' => 'Request deleted successfully'], JSON_UNESCAPED_UNICODE);
}

