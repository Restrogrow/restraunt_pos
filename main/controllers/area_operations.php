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

// Require permission to manage areas
requirePermission(PERMISSION_MANAGE_AREAS);

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
    $areaId = isset($_POST['areaId']) ? (int)$_POST['areaId'] : 0;
    $areaName = isset($_POST['areaName']) ? trim($_POST['areaName']) : '';
    
    // Validate action
    if (empty($action)) {
        throw new Exception('Action is required');
    }
    
    // Validate required fields for add and update actions
    if (in_array($action, ['add', 'update'])) {
        if (empty($areaName)) {
            throw new Exception('Area name is required');
        }
    }
    
    // Validate area ID for update and delete actions
    if (in_array($action, ['update', 'delete']) && $areaId <= 0) {
        throw new Exception('Invalid area ID');
    }
    
    switch ($action) {
        case 'add':
            // Check if area name already exists for this restaurant
            $checkStmt = $conn->prepare("SELECT COUNT(*) FROM areas WHERE area_name = ? AND restaurant_id = ?");
            $checkStmt->execute([$areaName, $restaurant_id]);
            $exists = $checkStmt->fetchColumn();
            
            if ($exists > 0) {
                throw new Exception('Area name already exists');
            }
            
            // Insert new area
            $insertStmt = $conn->prepare("INSERT INTO areas (restaurant_id, area_name, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
            $result = $insertStmt->execute([$restaurant_id, $areaName]);
            
            if ($result) {
                $newAreaId = $conn->lastInsertId();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Area added successfully',
                    'data' => [
                        'id' => $newAreaId,
                        'area_name' => $areaName,
                        'created_at' => date('Y-m-d H:i:s')
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                throw new Exception('Failed to add area');
            }
            break;
            
        case 'update':
            // Check if area exists and belongs to this restaurant
            $checkStmt = $conn->prepare("SELECT id FROM areas WHERE id = ? AND restaurant_id = ?");
            $checkStmt->execute([$areaId, $restaurant_id]);
            if (!$checkStmt->fetch()) {
                throw new Exception('Area not found');
            }
            
            // Check if area name already exists for this restaurant (excluding current area)
            $checkNameStmt = $conn->prepare("SELECT COUNT(*) FROM areas WHERE area_name = ? AND id != ? AND restaurant_id = ?");
            $checkNameStmt->execute([$areaName, $areaId, $restaurant_id]);
            $exists = $checkNameStmt->fetchColumn();
            
            if ($exists > 0) {
                throw new Exception('Area name already exists');
            }
            
            // Update area
            $updateStmt = $conn->prepare("UPDATE areas SET area_name = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ?");
            $result = $updateStmt->execute([$areaName, $areaId, $restaurant_id]);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Area updated successfully',
                    'data' => [
                        'id' => $areaId,
                        'area_name' => $areaName,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                throw new Exception('Failed to update area');
            }
            break;
            
        case 'delete':
            // Check if area exists and belongs to this restaurant
            $checkStmt = $conn->prepare("SELECT area_name FROM areas WHERE id = ? AND restaurant_id = ?");
            $checkStmt->execute([$areaId, $restaurant_id]);
            $area = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$area) {
                throw new Exception('Area not found');
            }
            
            // Delete area
            $deleteStmt = $conn->prepare("DELETE FROM areas WHERE id = ? AND restaurant_id = ?");
            $result = $deleteStmt->execute([$areaId, $restaurant_id]);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Area deleted successfully',
                    'data' => [
                        'id' => $areaId,
                        'area_name' => $area['area_name']
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                throw new Exception('Failed to delete area');
            }
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (PDOException $e) {
    error_log("PDO Error in area_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in area_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}



?>

