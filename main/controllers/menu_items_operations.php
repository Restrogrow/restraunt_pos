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

// Create uploads directory if it doesn't exist
$uploadDir = __DIR__ . '/../uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

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
    
    // Get restaurant_id from session
    $restaurant_id = $_SESSION['restaurant_id'];
    
    // Get the action and data from POST
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    $menuItemId = isset($_POST['menuItemId']) ? (int)$_POST['menuItemId'] : 0;
    
    // Validate action
    if (empty($action)) {
        throw new Exception('Action is required');
    }
    
    switch ($action) {
        case 'add':
            handleAddMenuItem($conn, $restaurant_id, $uploadDir);
            break;
            
        case 'update':
            handleUpdateMenuItem($conn, $restaurant_id, $menuItemId, $uploadDir);
            break;
            
        case 'delete':
            handleDeleteMenuItem($conn, $restaurant_id, $menuItemId);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (PDOException $e) {
    error_log("PDO Error in menu_items_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in menu_items_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

function handleAddMenuItem($conn, $restaurant_id, $uploadDir) {
    // Validate required fields
    $requiredFields = ['itemNameEn', 'chooseMenu'];
    foreach ($requiredFields as $field) {
        if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
            throw new Exception("Field '$field' is required");
        }
    }
    
    $itemNameEn = trim($_POST['itemNameEn']);
    $menuId = (int)$_POST['chooseMenu'];
    $itemDescriptionEn = isset($_POST['itemDescriptionEn']) ? trim($_POST['itemDescriptionEn']) : '';
    $itemCategory = isset($_POST['itemCategory']) ? trim($_POST['itemCategory']) : '';
    $itemType = isset($_POST['itemType']) ? trim($_POST['itemType']) : '';
    $preparationTime = isset($_POST['preparationTime']) ? (int)$_POST['preparationTime'] : 0;
    $isAvailable = isset($_POST['isAvailable']) ? (int)$_POST['isAvailable'] : 1;
    $basePrice = isset($_POST['basePrice']) ? (float)$_POST['basePrice'] : 0.00;
    $hasVariations = isset($_POST['hasVariations']) ? 1 : 0;
    
    // Validate menu exists and belongs to this restaurant
    $checkMenuStmt = $conn->prepare("SELECT id FROM menu WHERE id = ? AND restaurant_id = ?");
    $checkMenuStmt->execute([$menuId, $restaurant_id]);
    if (!$checkMenuStmt->fetch()) {
        throw new Exception('Selected menu does not exist');
    }
    
    // Handle file upload - store in database
    $itemImageData = null;
    $itemImageMimeType = null;
    $itemImagePath = null;
    
    if (isset($_FILES['itemImage']) && $_FILES['itemImage']['error'] === UPLOAD_ERR_OK) {
        $imageInfo = handleFileUpload($_FILES['itemImage'], $uploadDir);
        if (is_array($imageInfo)) {
            $itemImageData = $imageInfo['data'];
            $itemImageMimeType = $imageInfo['mime_type'];
            $itemImagePath = 'db:' . uniqid(); // Reference ID for database storage
        } else {
            $itemImagePath = $imageInfo; // Fallback for old format
        }
    }
    
    // Insert new menu item with image data
    $insertStmt = $conn->prepare("
        INSERT INTO menu_items 
        (restaurant_id, menu_id, item_name_en, item_description_en, item_category, item_type, preparation_time, is_available, base_price, has_variations, item_image, image_data, image_mime_type, created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    
    $result = $insertStmt->execute([
        $restaurant_id, $menuId, $itemNameEn, $itemDescriptionEn, $itemCategory, $itemType, 
        $preparationTime, $isAvailable, $basePrice, $hasVariations, 
        $itemImagePath, $itemImageData, $itemImageMimeType
    ]);
    
    if ($result) {
        $newMenuItemId = $conn->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'Menu item added successfully',
            'data' => [
                'id' => $newMenuItemId,
                'item_name_en' => $itemNameEn,
                'created_at' => date('Y-m-d H:i:s')
            ]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception('Failed to add menu item');
    }
}

function handleUpdateMenuItem($conn, $restaurant_id, $menuItemId, $uploadDir) {
    if ($menuItemId <= 0) {
        throw new Exception('Invalid menu item ID');
    }
    
    // Check if menu item exists and belongs to this restaurant
    $checkStmt = $conn->prepare("SELECT * FROM menu_items WHERE id = ? AND restaurant_id = ?");
    $checkStmt->execute([$menuItemId, $restaurant_id]);
    $existingItem = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existingItem) {
        throw new Exception('Menu item not found');
    }
    
    // Validate required fields
    $requiredFields = ['itemNameEn', 'chooseMenu'];
    foreach ($requiredFields as $field) {
        if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
            throw new Exception("Field '$field' is required");
        }
    }
    
    $itemNameEn = trim($_POST['itemNameEn']);
    $menuId = (int)$_POST['chooseMenu'];
    $itemDescriptionEn = isset($_POST['itemDescriptionEn']) ? trim($_POST['itemDescriptionEn']) : '';
    $itemCategory = isset($_POST['itemCategory']) ? trim($_POST['itemCategory']) : '';
    $itemType = isset($_POST['itemType']) ? trim($_POST['itemType']) : '';
    $preparationTime = isset($_POST['preparationTime']) ? (int)$_POST['preparationTime'] : 0;
    $isAvailable = isset($_POST['isAvailable']) ? (int)$_POST['isAvailable'] : 1;
    $basePrice = isset($_POST['basePrice']) ? (float)$_POST['basePrice'] : 0.00;
    $hasVariations = isset($_POST['hasVariations']) ? 1 : 0;
    
    // Validate menu exists and belongs to this restaurant
    $checkMenuStmt = $conn->prepare("SELECT id FROM menu WHERE id = ? AND restaurant_id = ?");
    $checkMenuStmt->execute([$menuId, $restaurant_id]);
    if (!$checkMenuStmt->fetch()) {
        throw new Exception('Selected menu does not exist');
    }
    
    // Handle file upload - store in database
    $itemImageData = $existingItem['image_data'] ?? null; // Keep existing image by default
    $itemImageMimeType = $existingItem['image_mime_type'] ?? null;
    $itemImagePath = $existingItem['item_image'] ?? null;
    
    if (isset($_FILES['itemImage']) && $_FILES['itemImage']['error'] === UPLOAD_ERR_OK) {
        // Delete old image file if exists (for backward compatibility)
        if ($existingItem['item_image'] && strpos($existingItem['item_image'], 'db:') !== 0 && file_exists($existingItem['item_image'])) {
            @unlink($existingItem['item_image']);
        }
        
        $imageInfo = handleFileUpload($_FILES['itemImage'], $uploadDir);
        if (is_array($imageInfo)) {
            $itemImageData = $imageInfo['data'];
            $itemImageMimeType = $imageInfo['mime_type'];
            $itemImagePath = 'db:' . uniqid(); // Reference ID for database storage
        } else {
            $itemImagePath = $imageInfo; // Fallback for old format
        }
    }
    
    // Update menu item with image data
    $updateStmt = $conn->prepare("
        UPDATE menu_items SET 
        menu_id = ?, item_name_en = ?, item_description_en = ?, item_category = ?, 
        item_type = ?, preparation_time = ?, is_available = ?, base_price = ?, 
        has_variations = ?, item_image = ?, image_data = ?, image_mime_type = ?, updated_at = NOW()
        WHERE id = ? AND restaurant_id = ?
    ");
    
    $result = $updateStmt->execute([
        $menuId, $itemNameEn, $itemDescriptionEn, $itemCategory, $itemType, 
        $preparationTime, $isAvailable, $basePrice, $hasVariations, 
        $itemImagePath, $itemImageData, $itemImageMimeType, $menuItemId, $restaurant_id
    ]);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Menu item updated successfully',
            'data' => [
                'id' => $menuItemId,
                'item_name_en' => $itemNameEn,
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception('Failed to update menu item');
    }
}

function handleDeleteMenuItem($conn, $restaurant_id, $menuItemId) {
    if ($menuItemId <= 0) {
        throw new Exception('Invalid menu item ID');
    }
    
    // Check if menu item exists, belongs to this restaurant, and get its details
    $checkStmt = $conn->prepare("SELECT * FROM menu_items WHERE id = ? AND restaurant_id = ?");
    $checkStmt->execute([$menuItemId, $restaurant_id]);
    $menuItem = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$menuItem) {
        throw new Exception('Menu item not found');
    }
    
    // Delete the image file if exists
    if ($menuItem['item_image'] && file_exists($menuItem['item_image'])) {
        unlink($menuItem['item_image']);
    }
    
    // Delete menu item
    $deleteStmt = $conn->prepare("DELETE FROM menu_items WHERE id = ? AND restaurant_id = ?");
    $result = $deleteStmt->execute([$menuItemId, $restaurant_id]);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Menu item deleted successfully',
            'data' => [
                'id' => $menuItemId,
                'item_name_en' => $menuItem['item_name_en']
            ]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception('Failed to delete menu item');
    }
}

function handleFileUpload($file, $uploadDir) {
    // Validate file extension
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions)) {
        throw new Exception('Invalid file extension. Only JPG, JPEG, PNG, GIF, and WebP files are allowed.');
    }
    
    // Validate file type via content inspection
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $actualMimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($actualMimeType, $allowedTypes)) {
        throw new Exception('Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.');
    }
    
    // Validate file size (5MB max)
    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) {
        throw new Exception('File size too large. Maximum size is 5MB.');
    }
    
    // Read image data
    $imageData = file_get_contents($file['tmp_name']);
    if ($imageData === false) {
        throw new Exception('Failed to read image file');
    }
    
    // Return image data for database storage
    return [
        'data' => $imageData,
        'mime_type' => $actualMimeType,
        'size' => $file['size']
    ];
}
?>

