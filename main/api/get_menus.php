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
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Allow access if:
// 1. Has PERMISSION_MANAGE_MENU (admin/manager)
// 2. Is staff (waiter/chef) with matching restaurant_id
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
            'data' => []
        ]);
        exit();
    }
} elseif (!$requested_restaurant_id) {
    // If not logged in and no restaurant_id provided, return error
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Restaurant ID required',
        'data' => []
    ]);
    exit();
}

// Include database connection
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection file not found',
        'data' => []
    ]);
    exit();
}

// Get connection using getConnection() for lazy connection support
try {
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        // Fallback to $pdo if getConnection() doesn't exist (backward compatibility)
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
        }
    }
} catch (Exception $e) {
    error_log("Error in get_menus.php (connection): " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred connecting to the database. Please try again.',
        'data' => []
    ]);
    exit();
}

// Check if user is logged in (admin or staff)
if (!isset($_SESSION['restaurant_id']) && (!isset($_SESSION['user_id']) && !isset($_SESSION['staff_id']) && !isset($_SESSION['branch_admin_id']))) {
    // Allow restaurant_id from query parameter for staff logins
    if (!isset($_GET['restaurant_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Please login to continue',
            'data' => []
        ]);
        exit();
    }
}

$restaurant_id = $requested_restaurant_id ?? $_SESSION['restaurant_id'] ?? null;

if (!$restaurant_id) {
    echo json_encode([
        'success' => false,
        'message' => 'Restaurant ID is required',
        'data' => []
    ]);
    exit();
}

try {
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
    
    // Check if translations column exists on menu
    $hasMenuTranslations = false;
    try {
        $check = $conn->query("SHOW COLUMNS FROM menu LIKE 'translations'");
        $hasMenuTranslations = $check->rowCount() > 0;
    } catch (Exception $e) {}
    
    // Check if translations column exists on subcategories
    $hasSubTranslations = false;
    try {
        $check = $conn->query("SHOW COLUMNS FROM subcategories LIKE 'translations'");
        $hasSubTranslations = $check->rowCount() > 0;
    } catch (Exception $e) {}
    
    // Get all menus for this restaurant (include image columns if they exist)
    // Check if image columns exist first
    $checkCol = $conn->query("SHOW COLUMNS FROM menu LIKE 'menu_image'");
    $hasImageColumns = $checkCol->rowCount() > 0;
    
    $menuSelectCols = "id, menu_name, is_active, created_at, updated_at";
    if ($hasMenuTranslations) {
        $menuSelectCols .= ", translations";
    }
    
    if ($hasImageColumns) {
        $stmt = $conn->prepare("SELECT $menuSelectCols, menu_image FROM menu WHERE restaurant_id = ? ORDER BY sort_order ASC, created_at DESC");
    } else {
        $stmt = $conn->prepare("SELECT $menuSelectCols FROM menu WHERE restaurant_id = ? ORDER BY sort_order ASC, created_at DESC");
    }
    $stmt->execute([$restaurant_id]);
    $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch subcategories for each menu
    $subSelectCols = "id, menu_id, subcategory_name, sort_order";
    if ($hasSubTranslations) {
        $subSelectCols .= ", translations";
    }
    $subcategoriesStmt = $conn->prepare("SELECT $subSelectCols FROM subcategories WHERE menu_id IN (SELECT id FROM menu WHERE restaurant_id = ?) ORDER BY sort_order ASC, subcategory_name ASC");
    $subcategoriesStmt->execute([$restaurant_id]);
    $allSubcategories = $subcategoriesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group subcategories by menu_id
    $subcategoriesByMenu = [];
    foreach ($allSubcategories as $sub) {
        $subcategoriesByMenu[$sub['menu_id']][] = $sub;
    }
    
    // Attach subcategories to each menu
    foreach ($menus as &$menu) {
        $menu['subcategories'] = $subcategoriesByMenu[$menu['id']] ?? [];
        // Decode translations
        if ($hasMenuTranslations && !empty($menu['translations'])) {
            $menu['translations'] = json_decode($menu['translations'], true);
        } else {
            $menu['translations'] = null;
        }
        // Attach translated name
        if ($lang !== 'en' && !empty($menu['translations'][$lang]['name'])) {
            $menu['menu_name_translated'] = $menu['translations'][$lang]['name'];
        } else {
            $menu['menu_name_translated'] = $menu['menu_name'];
        }
        // Decode subcategory translations
        foreach ($menu['subcategories'] as &$sub) {
            if ($hasSubTranslations && !empty($sub['translations'])) {
                $sub['translations'] = json_decode($sub['translations'], true);
            } else {
                $sub['translations'] = null;
            }
            if ($lang !== 'en' && !empty($sub['translations'][$lang]['name'])) {
                $sub['subcategory_name_translated'] = $sub['translations'][$lang]['name'];
            } else {
                $sub['subcategory_name_translated'] = $sub['subcategory_name'];
            }
        }
        unset($sub);
    }
    unset($menu);
    
    // Return success response
    echo json_encode([
        'success' => true,
        'data' => $menus,
        'count' => count($menus)
    ]);
    
} catch (PDOException $e) {
    // Database error
    error_log("PDO Error in get_menus.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.',
        'data' => []
    ]);
    exit();
    
} catch (Exception $e) {
    // General error
    error_log("Error in get_menus.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again.',
        'data' => []
    ]);
    exit();
}
?>