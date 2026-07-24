<?php
/**
 * Add-on Operations Controller
 * Handles CRUD operations for meal add-ons in the admin dashboard
 */

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

// Require permission to manage menu
requirePermission(PERMISSION_MANAGE_MENU);

// Include database connection
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found'], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed');
    }
    
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
        }
    }
    
    // Ensure meal_addons table exists
    try {
        $conn->query("SELECT 1 FROM meal_addons LIMIT 1");
    } catch (PDOException $e) {
        // Auto-create table
        $conn->exec("CREATE TABLE IF NOT EXISTS meal_addons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            addon_name VARCHAR(255) NOT NULL,
            addon_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            addon_image VARCHAR(500) DEFAULT NULL,
            is_available TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_addon_restaurant (restaurant_id),
            INDEX idx_addon_available (restaurant_id, is_available)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    

    
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    $addonId = isset($_POST['addonId']) ? (int)$_POST['addonId'] : 0;
    $restaurant_id = $_SESSION['restaurant_id'];
    
    if (empty($action)) {
        throw new Exception('Action is required');
    }
    
    switch ($action) {
        case 'add':
            handleAddAddon($conn, $restaurant_id);
            break;
        case 'update':
            handleUpdateAddon($conn, $addonId, $restaurant_id);
            break;
        case 'delete':
            handleDeleteAddon($conn, $addonId, $restaurant_id);
            break;
        case 'toggle_availability':
            handleToggleAvailability($conn, $addonId, $restaurant_id);
            break;
        default:
            throw new Exception('Invalid action');
    }
    
} catch (PDOException $e) {
    error_log("PDO Error in addon_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again later.'], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in addon_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.'], JSON_UNESCAPED_UNICODE);
    exit();
}

function handleAddAddon($conn, $restaurant_id) {
    $addonName = isset($_POST['addonName']) ? trim($_POST['addonName']) : '';
    $addonPrice = isset($_POST['addonPrice']) ? (float)$_POST['addonPrice'] : 0.00;
    $isAvailable = isset($_POST['isAvailable']) ? (int)$_POST['isAvailable'] : 1;
    
    if (empty($addonName)) {
        throw new Exception('Add-on name is required');
    }
    
    if ($addonPrice < 0) {
        throw new Exception('Price cannot be negative');
    }
    
    // Handle image upload
    $addonImagePath = null;
    $imageData = null;
    $imageMimeType = null;
    
    if (isset($_FILES['addonImage']) && $_FILES['addonImage']['error'] === UPLOAD_ERR_OK) {
        $imageInfo = handleAddonImageUpload($_FILES['addonImage']);
        if (is_array($imageInfo)) {
            $imageData = $imageInfo['data'];
            $imageMimeType = $imageInfo['mime_type'];
            $addonImagePath = 'db:' . uniqid('addon_');
        }
    }
    
    // Also check for base64 image
    if (empty($imageData) && isset($_POST['addonImageBase64']) && !empty($_POST['addonImageBase64'])) {
        $base64Data = $_POST['addonImageBase64'];
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $base64Data, $matches)) {
            $imageMimeType = 'image/' . $matches[1];
            $imageData = base64_decode($matches[2]);
            $addonImagePath = 'db:' . uniqid('addon_');
        }
    }
    
    $stmt = $conn->prepare("INSERT INTO meal_addons (restaurant_id, addon_name, addon_price, addon_image, image_data, image_mime_type, is_available) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $result = $stmt->execute([$restaurant_id, $addonName, $addonPrice, $addonImagePath, $imageData, $imageMimeType, $isAvailable]);
    
    if ($result) {
        $newId = $conn->lastInsertId();
        echo json_encode([
            'success' => true,
            'message' => 'Add-on added successfully',
            'data' => ['id' => $newId, 'addon_name' => $addonName]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception('Failed to add add-on');
    }
}

function handleUpdateAddon($conn, $addonId, $restaurant_id) {
    if ($addonId <= 0) {
        throw new Exception('Invalid add-on ID');
    }
    
    // Check if add-on exists
    $checkStmt = $conn->prepare("SELECT * FROM meal_addons WHERE id = ? AND restaurant_id = ?");
    $checkStmt->execute([$addonId, $restaurant_id]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existing) {
        throw new Exception('Add-on not found');
    }
    
    $addonName = isset($_POST['addonName']) ? trim($_POST['addonName']) : '';
    $addonPrice = isset($_POST['addonPrice']) ? (float)$_POST['addonPrice'] : 0.00;
    $isAvailable = isset($_POST['isAvailable']) ? (int)$_POST['isAvailable'] : 1;
    
    if (empty($addonName)) {
        throw new Exception('Add-on name is required');
    }
    
    if ($addonPrice < 0) {
        throw new Exception('Price cannot be negative');
    }
    
    $addonImagePath = $existing['addon_image'];
    $imageData = $existing['image_data'];
    $imageMimeType = $existing['image_mime_type'];
    
    // Handle new image upload
    if (isset($_FILES['addonImage']) && $_FILES['addonImage']['error'] === UPLOAD_ERR_OK) {
        $imageInfo = handleAddonImageUpload($_FILES['addonImage']);
        if (is_array($imageInfo)) {
            $imageData = $imageInfo['data'];
            $imageMimeType = $imageInfo['mime_type'];
            $addonImagePath = 'db:' . uniqid('addon_');
        }
    }
    
    // Also check for base64 image
    if (isset($_POST['addonImageBase64']) && !empty($_POST['addonImageBase64']) && strpos($_POST['addonImageBase64'], 'data:image') === 0) {
        $base64Data = $_POST['addonImageBase64'];
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $base64Data, $matches)) {
            $imageMimeType = 'image/' . $matches[1];
            $imageData = base64_decode($matches[2]);
            $addonImagePath = 'db:' . uniqid('addon_');
        }
    }
    
    $stmt = $conn->prepare("UPDATE meal_addons SET addon_name = ?, addon_price = ?, addon_image = ?, image_data = ?, image_mime_type = ?, is_available = ? WHERE id = ? AND restaurant_id = ?");
    $result = $stmt->execute([$addonName, $addonPrice, $addonImagePath, $imageData, $imageMimeType, $isAvailable, $addonId, $restaurant_id]);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Add-on updated successfully',
            'data' => ['id' => $addonId, 'addon_name' => $addonName]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception('Failed to update add-on');
    }
}

function handleDeleteAddon($conn, $addonId, $restaurant_id) {
    if ($addonId <= 0) {
        throw new Exception('Invalid add-on ID');
    }
    
    $stmt = $conn->prepare("DELETE FROM meal_addons WHERE id = ? AND restaurant_id = ?");
    $result = $stmt->execute([$addonId, $restaurant_id]);
    
    if ($result && $stmt->rowCount() > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Add-on deleted successfully'
        ], JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception('Add-on not found or already deleted');
    }
}

function handleToggleAvailability($conn, $addonId, $restaurant_id) {
    if ($addonId <= 0) {
        throw new Exception('Invalid add-on ID');
    }
    
    $stmt = $conn->prepare("UPDATE meal_addons SET is_available = CASE WHEN is_available = 1 THEN 0 ELSE 1 END WHERE id = ? AND restaurant_id = ?");
    $result = $stmt->execute([$addonId, $restaurant_id]);
    
    if ($result && $stmt->rowCount() > 0) {
        // Get new status
        $fetchStmt = $conn->prepare("SELECT is_available FROM meal_addons WHERE id = ?");
        $fetchStmt->execute([$addonId]);
        $row = $fetchStmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'message' => 'Availability toggled successfully',
            'is_available' => $row ? (int)$row['is_available'] : 1
        ], JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception('Add-on not found');
    }
}

function handleAddonImageUpload($file) {
    // Validate file extension
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions)) {
        throw new Exception('Invalid file extension. Only JPG, JPEG, PNG, GIF, and WebP files are allowed.');
    }
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $actualMimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($actualMimeType, $allowedTypes)) {
        throw new Exception('Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.');
    }
    
    if ($file['size'] > $maxSize) {
        throw new Exception('File size too large. Maximum size is 5MB.');
    }
    
    $imageData = file_get_contents($file['tmp_name']);
    if ($imageData === false) {
        throw new Exception('Failed to read image file');
    }
    
    return [
        'data' => $imageData,
        'mime_type' => $actualMimeType,
        'size' => $file['size']
    ];
}
