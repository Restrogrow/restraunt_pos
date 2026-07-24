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
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Require permission to manage tables
requirePermission(PERMISSION_MANAGE_TABLES);

// Include database connection
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found'], JSON_UNESCAPED_UNICODE);
    exit();
}

$restaurant_id = $_SESSION['restaurant_id'];

try {
    // Check if request method is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed');
    }
    
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
    
    // Get the action and data from POST
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    $tableId = isset($_POST['tableId']) ? (int)$_POST['tableId'] : 0;
    $tableNumber = isset($_POST['tableNumber']) ? trim($_POST['tableNumber']) : '';
    $capacity = isset($_POST['capacity']) ? (int)$_POST['capacity'] : 4;
    $areaId = isset($_POST['chooseArea']) ? (int)$_POST['chooseArea'] : 0;
    
    // Validate action
    if (empty($action)) {
        throw new Exception('Action is required');
    }
    
    // Validate required fields for add and update actions
    if (in_array($action, ['add', 'update'])) {
        if (empty($tableNumber)) {
            throw new Exception('Table number is required');
        }
        if ($areaId <= 0) {
            throw new Exception('Please select an area');
        }
        if ($capacity <= 0) {
            throw new Exception('Capacity must be greater than 0');
        }
    }
    
    // Validate table ID for update and delete actions
    if (in_array($action, ['update', 'delete']) && $tableId <= 0) {
        throw new Exception('Invalid table ID');
    }
    
    switch ($action) {
        case 'add':
            // Verify area belongs to this restaurant
            $areaCheckStmt = $conn->prepare("SELECT id FROM areas WHERE id = ? AND restaurant_id = ?");
            $areaCheckStmt->execute([$areaId, $restaurant_id]);
            if (!$areaCheckStmt->fetch()) {
                throw new Exception('Invalid area selection');
            }
            
            // Check if table number already exists in this area
            $checkStmt = $conn->prepare("SELECT COUNT(*) FROM tables WHERE table_number = ? AND area_id = ?");
            $checkStmt->execute([$tableNumber, $areaId]);
            $exists = $checkStmt->fetchColumn();
            
            if ($exists > 0) {
                throw new Exception('Table number already exists in this area');
            }
            
            // Insert new table
            $insertStmt = $conn->prepare("INSERT INTO tables (restaurant_id, area_id, table_number, capacity, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
            $result = $insertStmt->execute([$restaurant_id, $areaId, $tableNumber, $capacity]);
            
            if ($result) {
                $newTableId = $conn->lastInsertId();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Table added successfully',
                    'data' => [
                        'id' => $newTableId,
                        'table_number' => $tableNumber,
                        'capacity' => $capacity,
                        'created_at' => date('Y-m-d H:i:s')
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                throw new Exception('Failed to add table');
            }
            break;
            
        case 'update':
            // Check if table exists and belongs to this restaurant
            $checkStmt = $conn->prepare("SELECT id FROM tables WHERE id = ? AND restaurant_id = ?");
            $checkStmt->execute([$tableId, $restaurant_id]);
            if (!$checkStmt->fetch()) {
                throw new Exception('Table not found');
            }
            
            // Verify area belongs to this restaurant
            $areaCheckStmt = $conn->prepare("SELECT id FROM areas WHERE id = ? AND restaurant_id = ?");
            $areaCheckStmt->execute([$areaId, $restaurant_id]);
            if (!$areaCheckStmt->fetch()) {
                throw new Exception('Invalid area selection');
            }
            
            // Check if table number already exists in this area (excluding current table)
            $checkNameStmt = $conn->prepare("SELECT COUNT(*) FROM tables WHERE table_number = ? AND area_id = ? AND id != ?");
            $checkNameStmt->execute([$tableNumber, $areaId, $tableId]);
            $exists = $checkNameStmt->fetchColumn();
            
            if ($exists > 0) {
                throw new Exception('Table number already exists in this area');
            }
            
            // Update table
            $updateStmt = $conn->prepare("UPDATE tables SET area_id = ?, table_number = ?, capacity = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ?");
            $result = $updateStmt->execute([$areaId, $tableNumber, $capacity, $tableId, $restaurant_id]);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Table updated successfully',
                    'data' => [
                        'id' => $tableId,
                        'table_number' => $tableNumber,
                        'capacity' => $capacity,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                throw new Exception('Failed to update table');
            }
            break;
            
        case 'delete':
            // Check if table exists and belongs to this restaurant
            $checkStmt = $conn->prepare("SELECT table_number FROM tables WHERE id = ? AND restaurant_id = ?");
            $checkStmt->execute([$tableId, $restaurant_id]);
            $table = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$table) {
                throw new Exception('Table not found');
            }
            
            // Delete table
            $deleteStmt = $conn->prepare("DELETE FROM tables WHERE id = ? AND restaurant_id = ?");
            $result = $deleteStmt->execute([$tableId, $restaurant_id]);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Table deleted successfully',
                    'data' => [
                        'id' => $tableId,
                        'table_number' => $table['table_number']
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                throw new Exception('Failed to delete table');
            }
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (PDOException $e) {
    error_log("PDO Error in table_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in table_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}
?>

