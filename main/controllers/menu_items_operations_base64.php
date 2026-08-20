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
    echo json_encode([
        'success' => false,
        'message' => 'Database connection file not found'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$restaurant_id = $_SESSION['restaurant_id'];

try {
    // Check if request method is POST
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
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
    $menuItemId = isset($_POST['menuItemId']) ? (int)$_POST['menuItemId'] : 0;
    $menuId = isset($_POST['chooseMenu']) ? (int)$_POST['chooseMenu'] : 0;
    $itemNameEn = isset($_POST['itemNameEn']) ? trim($_POST['itemNameEn']) : '';
    $itemDescriptionEn = isset($_POST['itemDescriptionEn']) ? trim($_POST['itemDescriptionEn']) : '';
    $itemCategory = isset($_POST['itemCategory']) ? trim($_POST['itemCategory']) : '';
    $subcategoryId = isset($_POST['subcategoryId']) ? (int)$_POST['subcategoryId'] : 0;
    $itemType = isset($_POST['itemType']) ? trim($_POST['itemType']) : 'Other';
    if ($itemType === '') {
        $itemType = 'Other';
    }
    $preparationTime = isset($_POST['preparationTime']) ? (int)$_POST['preparationTime'] : 0;
    $calories = isset($_POST['calories']) ? (int)$_POST['calories'] : 0;
    $isAvailable = isset($_POST['isAvailable']) ? (int)$_POST['isAvailable'] : 1;
    $basePrice = isset($_POST['basePrice']) ? (float)$_POST['basePrice'] : 0.00;
    $hasVariations = isset($_POST['hasVariations']) ? 1 : 0;
    $descriptionFormat = isset($_POST['description_format']) ? trim($_POST['description_format']) : 'paragraph';
    if (!in_array($descriptionFormat, ['paragraph', 'br'])) {
        $descriptionFormat = 'paragraph';
    }
    
    // Validate action
    if (empty($action)) {
        throw new Exception('Action is required');
    }
    
    // Validate required fields for add and update actions
    if (in_array($action, ['add', 'update'])) {
        if (empty($itemNameEn)) {
            throw new Exception('Item name is required');
        }
        if ($menuId <= 0) {
            throw new Exception('Please select a menu');
        }
    }
    
    // Validate menu item ID for update, delete, and toggle_hidden actions
    if (in_array($action, ['update', 'delete', 'toggle_hidden']) && $menuItemId <= 0) {
        throw new Exception('Invalid menu item ID');
    }
    
    switch ($action) {
        case 'add':
            handleAddMenuItemBase64($conn, $restaurant_id, $menuId, $itemNameEn, $itemDescriptionEn, $itemCategory, $subcategoryId, $itemType, $preparationTime, $calories, $isAvailable, $basePrice, $hasVariations, $descriptionFormat);
            break;
            
        case 'update':
            handleUpdateMenuItemBase64($conn, $restaurant_id, $menuItemId, $menuId, $itemNameEn, $itemDescriptionEn, $itemCategory, $subcategoryId, $itemType, $preparationTime, $calories, $isAvailable, $basePrice, $hasVariations, $descriptionFormat);
            break;
            
        case 'delete':
            handleDeleteMenuItemBase64($conn, $restaurant_id, $menuItemId);
            break;
            
        case 'reorder':
            $orderData = isset($_POST['order']) ? $_POST['order'] : '';
            if (empty($orderData)) {
                throw new Exception('Order data is required');
            }
            
            $items = is_array($orderData) ? $orderData : json_decode($orderData, true);
            if (!is_array($items) || empty($items)) {
                throw new Exception('Invalid order data format');
            }
            
            $updateStmt = $conn->prepare("UPDATE menu_items SET sort_order = ? WHERE id = ? AND restaurant_id = ?");
            $updated = 0;
            foreach ($items as $item) {
                $id = (int)($item['id'] ?? 0);
                $sortOrder = (int)($item['sort_order'] ?? 0);
                if ($id > 0) {
                    $updateStmt->execute([$sortOrder, $id, $restaurant_id]);
                    $updated++;
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Order saved successfully',
                'data' => ['updated' => $updated]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'toggle_hidden':
            handleToggleMenuItemHidden($conn, $restaurant_id, $menuItemId);
            break;

        default:
            throw new Exception('Invalid action');
    }
    
} catch (PDOException $e) {
    error_log("PDO Error in menu_items_operations_base64.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in menu_items_operations_base64.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * Hide/unhide a menu item: hidden items stay visible in this admin Menu
 * Items list (so staff can find and unhide them) but are excluded from POS,
 * the waiter's order screen, and the customer website — see the is_hidden
 * filtering in get_menu_items.php (admin/POS/waiter, opt-out via
 * include_hidden) and main/website/api.php (customer site, always
 * excluded). Deliberately separate from is_available ("in stock"), which
 * still shows an item everywhere just marked unorderable.
 */
function handleToggleMenuItemHidden($conn, $restaurant_id, $menuItemId) {
    if ($menuItemId <= 0) {
        throw new Exception('Invalid menu item ID');
    }

    try {
        $col = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'is_hidden'");
        if ($col->rowCount() === 0) {
            $conn->exec("ALTER TABLE menu_items ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (PDOException $e) {
        error_log('handleToggleMenuItemHidden: ensure column failed: ' . $e->getMessage());
    }

    $stmt = $conn->prepare("UPDATE menu_items SET is_hidden = CASE WHEN is_hidden = 1 THEN 0 ELSE 1 END WHERE id = ? AND restaurant_id = ?");
    $stmt->execute([$menuItemId, $restaurant_id]);

    if ($stmt->rowCount() > 0) {
        $fetchStmt = $conn->prepare("SELECT is_hidden FROM menu_items WHERE id = ?");
        $fetchStmt->execute([$menuItemId]);
        $row = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => $row && (int)$row['is_hidden'] === 1 ? 'Item hidden from POS and website' : 'Item unhidden',
            'is_hidden' => $row ? (int)$row['is_hidden'] : 0
        ], JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception('Menu item not found');
    }
}

function handleAddMenuItemBase64($conn, $restaurant_id, $menuId, $itemNameEn, $itemDescriptionEn, $itemCategory, $subcategoryId, $itemType, $preparationTime, $calories, $isAvailable, $basePrice, $hasVariations, $descriptionFormat = 'paragraph') {
    
    // ... existing code ...
    
    // Ensure the subcategory_id column exists
    try {
        $checkCol = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'subcategory_id'");
        $hasSubcategoryCol = $checkCol->rowCount() > 0;
    } catch (PDOException $e) {
        $hasSubcategoryCol = false;
    }
    
    // Ensure description_format column exists (auto-migration)
    
    // Ensure item_type column accepts empty strings (auto-migration)
    
    // Handle base64 image
    $itemImageData = null;
    $itemImageMimeType = null;
    $itemImagePath = null;
    $imageUrl = $_POST['itemImageUrl'] ?? '';
    if (!empty($imageUrl) && (strpos($imageUrl, 'http://') === 0 || strpos($imageUrl, 'https://') === 0)) {
        $itemImagePath = $imageUrl;
    } elseif (isset($_POST['itemImageBase64']) && !empty($_POST['itemImageBase64'])) {
        $imageInfo = handleBase64Image($_POST['itemImageBase64']);
        if (is_array($imageInfo)) {
            $itemImageData = $imageInfo['data'];
            $itemImageMimeType = $imageInfo['mime_type'];
            $itemImagePath = 'db:' . uniqid();
        }
    }
    
    // Insert new menu item with image data
    $insertSql = "
        INSERT INTO menu_items 
        (restaurant_id, menu_id, item_name_en, item_description_en, description_format, item_category,";
    if ($hasSubcategoryCol) {
        $insertSql .= " subcategory_id,";
    }
    $insertSql .= " item_type, preparation_time, calories, is_available, base_price, has_variations, item_image, image_data, image_mime_type, created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, ?,";
    if ($hasSubcategoryCol) {
        $insertSql .= " ?,";
    }
    $insertSql .= " ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
    
    $insertStmt = $conn->prepare($insertSql);
    
    $insertParams = [
        $restaurant_id, $menuId, $itemNameEn, $itemDescriptionEn, $descriptionFormat, $itemCategory
    ];
    if ($hasSubcategoryCol) {
        $insertParams[] = $subcategoryId > 0 ? $subcategoryId : null;
    }
    $insertParams = array_merge($insertParams, [
        $itemType, 
        $preparationTime, $calories, $isAvailable, $basePrice, $hasVariations, 
        $itemImagePath, $itemImageData, $itemImageMimeType
    ]);
    
    $result = $insertStmt->execute($insertParams);
    
    if ($result) {
        $newMenuItemId = $conn->lastInsertId();
        
        // Auto-translate to restaurant's language if not English
        try {
            $langStmt = $conn->prepare("SELECT language, enable_language FROM users WHERE restaurant_id = ? LIMIT 1");
            $langStmt->execute([$restaurant_id]);
            $langRow = $langStmt->fetch(PDO::FETCH_ASSOC);
            if ($langRow && !empty($langRow['language']) && $langRow['language'] !== 'en' && !empty($langRow['enable_language'])) {
                require_once __DIR__ . '/../config/translate_utils.php';
                translateSingleMenuItem($conn, $newMenuItemId, $restaurant_id, $langRow['language'], $itemNameEn, $itemDescriptionEn);
            }
        } catch (Exception $e) {}
        
        // Save variations if has_variations is true
        if ($hasVariations && isset($_POST['variations']) && !empty($_POST['variations'])) {
            saveMenuItemVariations($conn, $newMenuItemId, $_POST['variations']);
        }
        
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

function handleUpdateMenuItemBase64($conn, $restaurant_id, $menuItemId, $menuId, $itemNameEn, $itemDescriptionEn, $itemCategory, $subcategoryId, $itemType, $preparationTime, $calories, $isAvailable, $basePrice, $hasVariations, $descriptionFormat = 'paragraph') {
    // Check if menu item exists and belongs to this restaurant
    $checkStmt = $conn->prepare("SELECT * FROM menu_items WHERE id = ? AND restaurant_id = ?");
    $checkStmt->execute([$menuItemId, $restaurant_id]);
    $existingItem = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existingItem) {
        throw new Exception('Menu item not found');
    }
    
    // Check if menu exists and belongs to this restaurant
    $checkMenuStmt = $conn->prepare("SELECT id FROM menu WHERE id = ? AND restaurant_id = ?");
    $checkMenuStmt->execute([$menuId, $restaurant_id]);
    if (!$checkMenuStmt->fetch()) {
        throw new Exception('Selected menu does not exist');
    }
    
    // Handle image - priority: URL > base64 > keep existing
    $itemImageData = $existingItem['image_data'] ?? null;
    $itemImageMimeType = $existingItem['image_mime_type'] ?? null;
    $itemImagePath = $existingItem['item_image'] ?? null;
    $imageUrl = $_POST['itemImageUrl'] ?? '';

    if (!empty($imageUrl) && (strpos($imageUrl, 'http://') === 0 || strpos($imageUrl, 'https://') === 0)) {
        $itemImagePath = $imageUrl;
        $itemImageData = null;
        $itemImageMimeType = null;
    } elseif (isset($_POST['itemImageBase64']) && !empty($_POST['itemImageBase64'])) {
        // Delete old image file if exists (for backward compatibility)
        if (!empty($existingItem['item_image']) && strpos($existingItem['item_image'], 'db:') !== 0 && file_exists($existingItem['item_image'])) {
            @unlink($existingItem['item_image']);
        }
        
        $imageInfo = handleBase64Image($_POST['itemImageBase64']);
        if (is_array($imageInfo)) {
            $itemImageData = $imageInfo['data'];
            $itemImageMimeType = $imageInfo['mime_type'];
            $itemImagePath = 'db:' . uniqid();
        } else {
            $itemImagePath = $imageInfo;
        }
    }
    
    // Ensure columns exist
    
    // Ensure calories column exists
    
    
    // Check if subcategory_id column exists and handle subcategory
    $subcategoryValue = null;
    $hasSubcategoryCol = false;
    try {
        $checkSubCol = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'subcategory_id'");
        $hasSubcategoryCol = $checkSubCol->rowCount() > 0;
        if ($hasSubcategoryCol && $subcategoryId > 0) {
            $subcategoryValue = $subcategoryId;
        }
    } catch (PDOException $e) {
        $hasSubcategoryCol = false;
    }
    
    // Update menu item with image data
    $updateSql = "
        UPDATE menu_items SET 
        menu_id = ?, item_name_en = ?, item_description_en = ?, description_format = ?, item_category = ?,";
    if ($hasSubcategoryCol) {
        $updateSql .= " subcategory_id = ?,";
    }
    $updateSql .= " item_type = ?, preparation_time = ?, calories = ?, is_available = ?, base_price = ?, 
        has_variations = ?, item_image = ?, image_data = ?, image_mime_type = ?, updated_at = NOW()
        WHERE id = ? AND restaurant_id = ?";
    
    $updateStmt = $conn->prepare($updateSql);
    
    $updateParams = [
        $menuId, $itemNameEn, $itemDescriptionEn, $descriptionFormat, $itemCategory
    ];
    if ($hasSubcategoryCol) {
        $updateParams[] = $subcategoryValue;
    }
    $updateParams = array_merge($updateParams, [
        $itemType, 
        $preparationTime, $calories, $isAvailable, $basePrice, $hasVariations, 
        $itemImagePath, $itemImageData, $itemImageMimeType, $menuItemId, $restaurant_id
    ]);
    
    $result = $updateStmt->execute($updateParams);
    
    if ($result) {
        // Update variations if has_variations is true
        
        // Auto-translate to restaurant's language if not English
        try {
            $langStmt = $conn->prepare("SELECT language, enable_language FROM users WHERE restaurant_id = ? LIMIT 1");
            $langStmt->execute([$restaurant_id]);
            $langRow = $langStmt->fetch(PDO::FETCH_ASSOC);
            if ($langRow && !empty($langRow['language']) && $langRow['language'] !== 'en' && !empty($langRow['enable_language'])) {
                require_once __DIR__ . '/../config/translate_utils.php';
                translateSingleMenuItem($conn, $menuItemId, $restaurant_id, $langRow['language'], $itemNameEn, $itemDescriptionEn);
            }
        } catch (Exception $e) {}
        
        if ($hasVariations && isset($_POST['variations']) && !empty($_POST['variations'])) {
            saveMenuItemVariations($conn, $menuItemId, $_POST['variations']);
        } else {
            // Delete all variations if has_variations is false
            deleteMenuItemVariations($conn, $menuItemId);
        }
        
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

function handleDeleteMenuItemBase64($conn, $restaurant_id, $menuItemId) {
    // Check if menu item exists and belongs to this restaurant
    $checkStmt = $conn->prepare("SELECT * FROM menu_items WHERE id = ? AND restaurant_id = ?");
    $checkStmt->execute([$menuItemId, $restaurant_id]);
    $menuItem = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$menuItem) {
        throw new Exception('Menu item not found');
    }
    
    // Delete the image file if it exists
    if (!empty($menuItem['item_image']) && file_exists($menuItem['item_image'])) {
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

function handleBase64Image($base64String) {
    // Remove data URL prefix if present and extract MIME type
    $mimeType = 'image/jpeg'; // Default
    if (strpos($base64String, 'data:image/') === 0) {
        $mimePart = substr($base64String, 5, strpos($base64String, ';') - 5);
        $mimeType = str_replace('data:', '', $mimePart);
        $base64String = substr($base64String, strpos($base64String, ',') + 1);
    }
    
    // Decode base64
    $imageData = base64_decode($base64String);
    
    if ($imageData === false) {
        throw new Exception('Invalid base64 image data');
    }
    
    // Validate image size (5MB max)
    if (strlen($imageData) > 5 * 1024 * 1024) {
        throw new Exception('Image size too large. Maximum size is 5MB.');
    }
    
    // Get image info
    $imageInfo = getimagesizefromstring($imageData);
    if ($imageInfo === false) {
        throw new Exception('Invalid image format');
    }
    
    // Validate image type
    $allowedTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
    if (!in_array($imageInfo[2], $allowedTypes)) {
        throw new Exception('Invalid image type. Only JPEG, PNG, GIF, and WebP images are allowed.');
    }
    
    // Determine MIME type from image info
    $mimeTypes = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_GIF => 'image/gif',
        IMAGETYPE_WEBP => 'image/webp'
    ];
    $mimeType = $mimeTypes[$imageInfo[2]] ?? $mimeType;
    
    // Return array with image data and MIME type for database storage
    return [
        'data' => $imageData,
        'mime_type' => $mimeType,
        'size' => strlen($imageData)
    ];
}

// Helper function to save menu item variations
function saveMenuItemVariations($conn, $menuItemId, $variationsJson) {
    try {
        // Ensure variations table exists
        $checkTable = $conn->query("SHOW TABLES LIKE 'menu_item_variations'");
        if ($checkTable->rowCount() == 0) {
            // Create table if it doesn't exist
            $conn->exec("
                CREATE TABLE IF NOT EXISTS menu_item_variations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    menu_item_id INT NOT NULL,
                    variation_name VARCHAR(100) NOT NULL,
                    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    sort_order INT DEFAULT 0,
                    is_available BOOLEAN DEFAULT TRUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
                    INDEX idx_menu_item_id (menu_item_id),
                    INDEX idx_sort_order (sort_order),
                    INDEX idx_is_available (is_available),
                    UNIQUE KEY unique_variation_per_item (menu_item_id, variation_name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        
        // Delete existing variations
        deleteMenuItemVariations($conn, $menuItemId);
        
        // Parse variations JSON
        $variations = json_decode($variationsJson, true);
        if (!is_array($variations) || empty($variations)) {
            return;
        }
        
        // Insert new variations
        $insertStmt = $conn->prepare("
            INSERT INTO menu_item_variations (menu_item_id, variation_name, price, sort_order) 
            VALUES (?, ?, ?, ?)
        ");
        
        $sortOrder = 0;
        foreach ($variations as $variation) {
            if (isset($variation['variation_name']) && isset($variation['price'])) {
                $insertStmt->execute([
                    $menuItemId,
                    trim($variation['variation_name']),
                    (float)$variation['price'],
                    $sortOrder++
                ]);
            }
        }
    } catch (PDOException $e) {
        error_log("Error saving menu item variations: " . $e->getMessage());
        // Don't throw - variations are optional
    }
}

// Helper function to delete menu item variations
function deleteMenuItemVariations($conn, $menuItemId) {
    try {
        $checkTable = $conn->query("SHOW TABLES LIKE 'menu_item_variations'");
        if ($checkTable->rowCount() > 0) {
            $deleteStmt = $conn->prepare("DELETE FROM menu_item_variations WHERE menu_item_id = ?");
            $deleteStmt->execute([$menuItemId]);
        }
    } catch (PDOException $e) {
        // Table might not exist yet, ignore
        error_log("Error deleting menu item variations: " . $e->getMessage());
    }
}
?>

