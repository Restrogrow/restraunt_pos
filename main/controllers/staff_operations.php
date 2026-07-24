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

// Require admin permission to manage staff
requireAdmin();

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
            handleAddStaff($conn, $restaurant_id);
            break;
            
        case 'update':
            handleUpdateStaff($conn, $restaurant_id);
            break;
            
        case 'delete':
            handleDeleteStaff($conn, $restaurant_id);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
    }
} catch (PDOException $e) {
    error_log("PDO Error in staff_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again later.'], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in staff_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.'], JSON_UNESCAPED_UNICODE);
    exit();
}

function handleAddStaff($conn, $restaurant_id) {
    $memberName = trim($_POST['memberName'] ?? '');
    $email = trim($_POST['memberEmail'] ?? '');
    $countryCode = trim($_POST['countryCode'] ?? '');
    $phone = trim($_POST['restaurantPhone'] ?? '');
    $password = $_POST['memberPassword'] ?? '';
    $role = trim($_POST['memberRole'] ?? 'Waiter');
    
    // Validation
    if (empty($memberName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Member name is required'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email address is required'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid email address'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (empty($phone)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Phone number is required'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Password is required'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Combine country code with phone
    $fullPhone = $countryCode . '-' . $phone;
    
    // Check if staff with this email already exists
    $checkStmt = $conn->prepare("SELECT id FROM staff WHERE restaurant_id = ? AND email = ?");
    $checkStmt->execute([$restaurant_id, $email]);
    
    if ($checkStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Staff member with this email already exists'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert staff
    $stmt = $conn->prepare("INSERT INTO staff (restaurant_id, member_name, email, phone, password, role) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$restaurant_id, $memberName, $email, $fullPhone, $hashedPassword, $role]);
    
    echo json_encode(['success' => true, 'message' => 'Staff member added successfully'], JSON_UNESCAPED_UNICODE);
}

function handleUpdateStaff($conn, $restaurant_id) {
    $staffId = $_POST['staffId'] ?? '';
    $memberName = trim($_POST['memberName'] ?? '');
    $email = trim($_POST['memberEmail'] ?? '');
    $countryCode = trim($_POST['countryCode'] ?? '');
    $phone = trim($_POST['restaurantPhone'] ?? '');
    $password = $_POST['memberPassword'] ?? '';
    $role = trim($_POST['memberRole'] ?? 'Waiter');
    
    // Validation
    if (empty($memberName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Member name is required'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email address is required'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid email address'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (empty($phone)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Phone number is required'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Check if staff exists and belongs to restaurant
    $checkStmt = $conn->prepare("SELECT id FROM staff WHERE id = ? AND restaurant_id = ?");
    $checkStmt->execute([$staffId, $restaurant_id]);
    
    if (!$checkStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Staff member not found'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Check if email already exists for another staff member
    $emailCheckStmt = $conn->prepare("SELECT id FROM staff WHERE restaurant_id = ? AND email = ? AND id != ?");
    $emailCheckStmt->execute([$restaurant_id, $email, $staffId]);
    
    if ($emailCheckStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email already exists for another staff member'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Combine country code with phone
    $fullPhone = $countryCode . '-' . $phone;
    
    // Update staff
    if (!empty($password)) {
        if (strlen($password) < 6) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE staff SET member_name = ?, email = ?, phone = ?, password = ?, role = ? WHERE id = ? AND restaurant_id = ?");
        $stmt->execute([$memberName, $email, $fullPhone, $hashedPassword, $role, $staffId, $restaurant_id]);
    } else {
        $stmt = $conn->prepare("UPDATE staff SET member_name = ?, email = ?, phone = ?, role = ? WHERE id = ? AND restaurant_id = ?");
        $stmt->execute([$memberName, $email, $fullPhone, $role, $staffId, $restaurant_id]);
    }
    
    echo json_encode(['success' => true, 'message' => 'Staff member updated successfully'], JSON_UNESCAPED_UNICODE);
}

function handleDeleteStaff($conn, $restaurant_id) {
    $staffId = $_POST['staffId'] ?? '';
    
    if (empty($staffId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Staff ID is required'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Check if staff exists and belongs to restaurant
    $checkStmt = $conn->prepare("SELECT id FROM staff WHERE id = ? AND restaurant_id = ?");
    $checkStmt->execute([$staffId, $restaurant_id]);
    
    if (!$checkStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Staff member not found'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Delete staff
    $stmt = $conn->prepare("DELETE FROM staff WHERE id = ? AND restaurant_id = ?");
    $stmt->execute([$staffId, $restaurant_id]);
    
    echo json_encode(['success' => true, 'message' => 'Staff member deleted successfully'], JSON_UNESCAPED_UNICODE);
}
?>

