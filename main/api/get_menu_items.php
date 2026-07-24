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

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Include database connection
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection file not found',
        'data' => [],
        'categories' => []
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Menu items can be public (for website), but if logged in, check permissions
// Allow access if:
// 1. Logged in as admin/manager (has PERMISSION_MANAGE_MENU)
// 2. Logged in as staff (waiter/chef) with same restaurant_id
// 3. Not logged in but restaurant_id is provided in query
$hasPermission = false;
$requested_restaurant_id = $_GET['restaurant_id'] ?? null;

if (isLoggedIn()) {
    // Check if user has permission
    try {
        if (hasPermission(PERMISSION_MANAGE_MENU)) {
            $hasPermission = true;
        }
    } catch (Exception $e) {
        // Permission check failed, continue to staff check
    }
    
    // If no permission, check if staff with matching restaurant_id
    if (!$hasPermission && isset($_SESSION['staff_id']) && isset($_SESSION['restaurant_id'])) {
        $check_restaurant_id = $requested_restaurant_id ?? $_SESSION['restaurant_id'] ?? null;
        if ($check_restaurant_id && $check_restaurant_id === $_SESSION['restaurant_id']) {
            $hasPermission = true;
        }
    }
    
    // If still no permission and no restaurant_id in query, return error
    if (!$hasPermission && !$requested_restaurant_id) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. Restaurant ID required.',
            'data' => [],
            'categories' => []
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
} elseif (!$requested_restaurant_id) {
    // If not logged in and no restaurant_id provided, return error
    echo json_encode([
        'success' => false,
        'message' => 'Restaurant ID required',
        'data' => [],
        'categories' => []
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$restaurant_id = $requested_restaurant_id ?? $_SESSION['restaurant_id'] ?? null;

if (!$restaurant_id) {
    echo json_encode([
        'success' => false,
        'message' => 'Restaurant ID is required',
        'data' => [],
        'categories' => []
    ], JSON_UNESCAPED_UNICODE);
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
    
    // Get the restaurant's language setting
    $lang = $_SESSION['language'] ?? 'en';
    try {
        $langStmt = $conn->prepare("SELECT language, enable_language FROM users WHERE restaurant_id = ? LIMIT 1");
        $langStmt->execute([$restaurant_id]);
        $langRow = $langStmt->fetch(PDO::FETCH_ASSOC);
        if ($langRow && !empty($langRow['language']) && !empty($langRow['enable_language'])) {
            $lang = $langRow['language'];
            $_SESSION['language'] = $lang;
        } else {
            $lang = 'en';
            $_SESSION['language'] = 'en';
        }
    } catch (Exception $e) {}
    
    // Check if translations column exists on menu_items
    $hasItemTranslations = false;
    try {
        $check = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'translations'");
        $hasItemTranslations = $check->rowCount() > 0;
    } catch (Exception $e) {}
    
    // Get filter parameters
    $menuFilter = isset($_GET['menu']) ? (int)$_GET['menu'] : 0;
    $categoryFilter = isset($_GET['category']) ? trim($_GET['category']) : '';
    $typeFilter = isset($_GET['type']) ? trim($_GET['type']) : '';
    $subcategoryFilter = isset($_GET['subcategory']) ? (int)$_GET['subcategory'] : 0;
    
    // Check if subcategories table exists
    $hasSubcategoriesTable = false;
    try {
        $checkSubTable = $conn->query("SHOW TABLES LIKE 'subcategories'");
        $hasSubcategoriesTable = $checkSubTable->rowCount() > 0;
    } catch (PDOException $e) {
        // Table doesn't exist yet
    }
    
    // Check if subcategory_id column exists
    $hasSubcategoryColumn = false;
    try {
        $checkSubCol = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'subcategory_id'");
        $hasSubcategoryColumn = $checkSubCol->rowCount() > 0;
    } catch (PDOException $e) {
        // Column doesn't exist
    }
    
    // Build the query - explicitly select columns (exclude binary image_data to avoid JSON issues)
    $translationsCol = $hasItemTranslations ? ", mi.translations" : "";
            $sql = "SELECT mi.id, mi.restaurant_id, mi.menu_id, mi.item_name_en, mi.item_description_en, mi.description_format,
                           mi.item_category, mi.item_type, mi.preparation_time, mi.calories, mi.is_available, 
                           mi.base_price, mi.has_variations, mi.item_image, 
                           mi.sort_order, mi.created_at, mi.updated_at, m.menu_name" . $translationsCol;
    
    // Include subcategory info if column exists
    if ($hasSubcategoryColumn && $hasSubcategoriesTable) {
        $sql .= ", mi.subcategory_id, sc.subcategory_name";
    }
    
    $sql .= " FROM menu_items mi 
              JOIN menu m ON mi.menu_id = m.id ";
    
    if ($hasSubcategoryColumn && $hasSubcategoriesTable) {
        $sql .= "LEFT JOIN subcategories sc ON mi.subcategory_id = sc.id ";
    }
    
    $sql .= "WHERE mi.restaurant_id = ?";
    
    // Check if variations table exists
    $hasVariationsTable = false;
    try {
        $checkTable = $conn->query("SHOW TABLES LIKE 'menu_item_variations'");
        $hasVariationsTable = $checkTable->rowCount() > 0;
    } catch (PDOException $e) {
        // Table doesn't exist yet
    }
    $params = [$restaurant_id];
    
    // Add filters
    if ($menuFilter > 0) {
        $sql .= " AND mi.menu_id = ?";
        $params[] = $menuFilter;
    }
    
    if (!empty($categoryFilter)) {
        $sql .= " AND mi.item_category = ?";
        $params[] = $categoryFilter;
    }
    
    if ($subcategoryFilter > 0 && $hasSubcategoryColumn) {
        $sql .= " AND mi.subcategory_id = ?";
        $params[] = $subcategoryFilter;
    }
    
    if (!empty($typeFilter)) {
        $sql .= " AND mi.item_type = ?";
        $params[] = $typeFilter;
    }
    
    $sql .= " ORDER BY mi.sort_order ASC, mi.created_at DESC";
    
    // Pagination — get page and limit from query params
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
    $offset = ($page - 1) * $limit;
    
    // Get total count first
    $countSql = "SELECT COUNT(*) FROM menu_items mi WHERE mi.restaurant_id = ?";
    $countParams = [$restaurant_id];
    if ($menuFilter > 0) { $countSql .= " AND mi.menu_id = ?"; $countParams[] = $menuFilter; }
    if (!empty($categoryFilter)) { $countSql .= " AND mi.item_category = ?"; $countParams[] = $categoryFilter; }
    if ($subcategoryFilter > 0 && $hasSubcategoryColumn) { $countSql .= " AND mi.subcategory_id = ?"; $countParams[] = $subcategoryFilter; }
    if (!empty($typeFilter)) { $countSql .= " AND mi.item_type = ?"; $countParams[] = $typeFilter; }
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($countParams);
    $totalCount = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($totalCount / $limit);
    
    // Add LIMIT/OFFSET to main query
    $sql .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    // Execute query
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $menuItems = $stmt->fetchAll();
    } catch (PDOException $e) {
        // If columns don't exist, try with basic columns only
        if (strpos($e->getMessage(), 'image_data') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
            $sql = "SELECT mi.id, mi.restaurant_id, mi.menu_id, mi.item_name_en, mi.item_description_en, mi.description_format,
                   mi.item_category, mi.item_type, mi.preparation_time, mi.calories, mi.is_available, 
                   mi.base_price, mi.has_variations, mi.item_image, 
                   mi.sort_order, mi.created_at, mi.updated_at, m.menu_name" . $translationsCol;
            
            if ($hasSubcategoryColumn && $hasSubcategoriesTable) {
                $sql .= ", mi.subcategory_id, sc.subcategory_name";
            }
            
            $sql .= " FROM menu_items mi 
                      JOIN menu m ON mi.menu_id = m.id ";
            
            if ($hasSubcategoryColumn && $hasSubcategoriesTable) {
                $sql .= "LEFT JOIN subcategories sc ON mi.subcategory_id = sc.id ";
            }
            
            $sql .= "WHERE mi.restaurant_id = ?";
            
            if ($menuFilter > 0) {
                $sql .= " AND mi.menu_id = ?";
            }
            if (!empty($categoryFilter)) {
                $sql .= " AND mi.item_category = ?";
            }
            if ($subcategoryFilter > 0 && $hasSubcategoryColumn) {
                $sql .= " AND mi.subcategory_id = ?";
            }
            if (!empty($typeFilter)) {
                $sql .= " AND mi.item_type = ?";
            }
            $sql .= " ORDER BY mi.sort_order ASC, mi.created_at DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $menuItems = $stmt->fetchAll();
        } else {
            throw $e;
        }
    }
    
    // Load variations for each menu item if table exists
    if ($hasVariationsTable) {
        foreach ($menuItems as &$item) {
            try {
                $variationsStmt = $conn->prepare("
                    SELECT id, variation_name, price, sort_order, is_available 
                    FROM menu_item_variations 
                    WHERE menu_item_id = ?
                    ORDER BY sort_order ASC
                ");
                $variationsStmt->execute([$item['id']]);
                $item['variations'] = $variationsStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $item['variations'] = [];
            }
        }
        unset($item); // Break reference
    } else {
        // Add empty variations array if table doesn't exist
        foreach ($menuItems as &$item) {
            $item['variations'] = [];
        }
        unset($item);
    }
    
    // Get unique categories for this restaurant
    $categoryStmt = $conn->prepare("SELECT DISTINCT item_category FROM menu_items WHERE restaurant_id = ? AND item_category IS NOT NULL AND item_category != '' ORDER BY item_category");
    $categoryStmt->execute([$restaurant_id]);
    $categories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Note: image_data and image_mime_type are not selected to avoid binary data in JSON
    // Images are served via image.php endpoint using item_image reference
    
    // Add translated names to response
    foreach ($menuItems as &$item) {
        if (!empty($item['translations'])) {
            $item['translations'] = is_string($item['translations']) ? json_decode($item['translations'], true) : $item['translations'];
        } else {
            $item['translations'] = null;
        }
        if ($lang !== 'en' && !empty($item['translations'][$lang]['name'])) {
            $item['item_name_translated'] = $item['translations'][$lang]['name'];
            $item['item_description_translated'] = $item['translations'][$lang]['description'] ?? '';
        } else {
            $item['item_name_translated'] = $item['item_name_en'];
            $item['item_description_translated'] = $item['item_description_en'] ?? '';
        }
    }
    unset($item);
    
    // Return success response with pagination
    echo json_encode([
        'success' => true,
        'data' => $menuItems,
        'categories' => $categories,
        'count' => count($menuItems),
        'total_count' => $totalCount,
        'total_pages' => $totalPages,
        'page' => $page,
        'limit' => $limit
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log("PDO Error in get_menu_items.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.',
        'data' => [],
        'categories' => []
    ], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in get_menu_items.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again.',
        'data' => [],
        'categories' => []
    ], JSON_UNESCAPED_UNICODE);
    exit();
}
?>