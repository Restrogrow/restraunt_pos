<?php
// Suppress error display, log errors instead
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Ensure no output before headers
if (ob_get_level()) {
    ob_clean();
}

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Max-Age: 86400'); // 24 hours
    http_response_code(200);
    exit();
}

// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
// Skip timeout validation for public customer website API - sessions are just for restaurant context
startSecureSession(true);
require_once 'db_config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
// No-cache headers for dynamic embed content
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Resolve restaurant id: explicit query param > session > default
$restaurantId = isset($_GET['restaurant_id']) && $_GET['restaurant_id'] !== ''
    ? $_GET['restaurant_id']
    : (isset($_SESSION['restaurant_id']) ? $_SESSION['restaurant_id'] : 'RES001');

$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
    // Get connection using getConnection() for lazy connection support
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        // Fallback to $pdo if getConnection() doesn't exist (backward compatibility)
        global $pdo;
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
        }
    }
    
    // Verify connection is valid
    if (!$conn) {
        throw new Exception('Database connection not available');
    }
    
    if (!$restaurantId) {
        // No restaurant context available
        echo json_encode(['error' => 'RESTAURANT_NOT_SET']);
        exit;
    }

    switch ($action) {
        case 'getRestaurantDetails':
            try {
                // Get user_id first
                $userStmt = $conn->prepare("SELECT id FROM users WHERE restaurant_id = ? LIMIT 1");
                $userStmt->execute([$restaurantId]);
                $userResult = $userStmt->fetch(PDO::FETCH_ASSOC);
                $user_id = $userResult ? $userResult['id'] : null;
                
                // Get restaurant details
                if ($user_id) {
                    $stmt = $conn->prepare("SELECT id, restaurant_name, restaurant_logo, currency_symbol FROM users WHERE id = ? LIMIT 1");
                    $stmt->execute([$user_id]);
                } else {
                    $stmt = $conn->prepare("SELECT id, restaurant_name, restaurant_logo, currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1");
                    $stmt->execute([$restaurantId]);
                }
                $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $result = ['success' => false];
                if ($userRow) {
                    $result['success'] = true;
                    $result['restaurant_name'] = $userRow['restaurant_name'] ?? 'Restaurant';
                    
                    // Logo URL
                    if (!empty($userRow['restaurant_logo'])) {
                        if (strpos($userRow['restaurant_logo'], 'db:') === 0) {
                            $result['restaurant_logo'] = '../api/image.php?type=logo&id=' . ($userRow['id'] ?? $user_id ?? '');
                        } elseif (strpos($userRow['restaurant_logo'], 'http') === 0) {
                            $result['restaurant_logo'] = $userRow['restaurant_logo'];
                        } else {
                            $logo = $userRow['restaurant_logo'];
                            if (strpos($logo, 'uploads/') !== 0) {
                                $logo = '../uploads/' . $logo;
                            } else {
                                $logo = '../' . $logo;
                            }
                            $result['restaurant_logo'] = $logo;
                        }
                    }
                    
                    // Currency
                    if (array_key_exists('currency_symbol', $userRow) && $userRow['currency_symbol'] !== null && $userRow['currency_symbol'] !== '') {
                        require_once __DIR__ . '/../config/unicode_utils.php';
                        $result['currency_symbol'] = fixCurrencySymbol($userRow['currency_symbol']);
                    }
                    
                    // Theme colors
                    $themeStmt = $conn->prepare("SELECT primary_red, dark_red, primary_yellow, font_family FROM website_settings WHERE restaurant_id = ? LIMIT 1");
                    $themeStmt->execute([$restaurantId]);
                    $themeRow = $themeStmt->fetch(PDO::FETCH_ASSOC);
                    if ($themeRow) {
                        $result['theme'] = [
                            'primary_red' => $themeRow['primary_red'] ?? '#F70000',
                            'dark_red' => $themeRow['dark_red'] ?? '#DA020E',
                            'primary_yellow' => $themeRow['primary_yellow'] ?? '#FFD100',
                            'font_family' => $themeRow['font_family'] ?? 'Poppins'
                        ];
                    }
                }
                echo json_encode($result);
            } catch (PDOException $e) {
                error_log("Error in getRestaurantDetails: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'An error occurred. Please try again.']);
            }
            break;
            
        case 'getMenus':
            // Get restaurant language
            $lang = $_SESSION['language'] ?? 'en';
            try {
                $ls = $conn->prepare("SELECT language, enable_language FROM users WHERE restaurant_id = :rid LIMIT 1");
                $ls->execute([':rid' => $restaurantId]);
                $lr = $ls->fetch(PDO::FETCH_ASSOC);
                if ($lr && !empty($lr['language']) && !empty($lr['enable_language'])) {
                    $lang = $lr['language'];
                    $_SESSION['language'] = $lang;
                } else {
                    $lang = 'en';
                    $_SESSION['language'] = 'en';
                }
            } catch (Exception $e) {}
            
            $hasMenuTrans = false;
            try { $hasMenuTrans = $conn->query("SHOW COLUMNS FROM menu LIKE 'translations'")->rowCount() > 0; } catch (Exception $e) {}
            $hasSubTrans = false;
            try { $hasSubTrans = $conn->query("SHOW COLUMNS FROM subcategories LIKE 'translations'")->rowCount() > 0; } catch (Exception $e) {}
            
            // Check if menu_image column exists
            $checkCol = $conn->query("SHOW COLUMNS FROM menu LIKE 'menu_image'");
            $hasImageColumns = $checkCol->rowCount() > 0;
            
            $menuCols = "id, menu_name, is_active, sort_order";
            if ($hasMenuTrans) $menuCols .= ", translations";
            
            if ($hasImageColumns) {
                $stmt = $conn->prepare("SELECT $menuCols, menu_image FROM menu WHERE restaurant_id = :rid AND is_active = 1 ORDER BY sort_order ASC, created_at DESC");
            } else {
                $stmt = $conn->prepare("SELECT $menuCols FROM menu WHERE restaurant_id = :rid AND is_active = 1 ORDER BY sort_order ASC, created_at DESC");
            }
            $stmt->execute([':rid' => $restaurantId]);
            $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Fetch subcategories for each menu
            try {
                $checkSubTable = $conn->query("SHOW TABLES LIKE 'subcategories'");
                $hasSubcategoriesTable = $checkSubTable->rowCount() > 0;
                
                if ($hasSubcategoriesTable) {
                    $subCols = "id, menu_id, subcategory_name, sort_order";
                    if ($hasSubTrans) $subCols .= ", translations";
                    $subcategoriesStmt = $conn->prepare("SELECT $subCols FROM subcategories WHERE menu_id IN (SELECT id FROM menu WHERE restaurant_id = :rid AND is_active = 1) ORDER BY sort_order ASC, subcategory_name ASC");
                    $subcategoriesStmt->execute([':rid' => $restaurantId]);
                    $allSubcategories = $subcategoriesStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Group subcategories by menu_id
                    $subcategoriesByMenu = [];
                    foreach ($allSubcategories as $sub) {
                        $subcategoriesByMenu[$sub['menu_id']][] = $sub;
                    }
                    
                    // Attach subcategories to each menu
                    foreach ($menus as &$menu) {
                        $menu['subcategories'] = $subcategoriesByMenu[$menu['id']] ?? [];
                        if ($hasMenuTrans && !empty($menu['translations'])) {
                            $menu['translations'] = json_decode($menu['translations'], true);
                        } else {
                            $menu['translations'] = null;
                        }
                        if ($lang !== 'en' && !empty($menu['translations'][$lang]['name'])) {
                            $menu['menu_name_translated'] = $menu['translations'][$lang]['name'];
                        } else {
                            $menu['menu_name_translated'] = $menu['menu_name'];
                        }
                        foreach ($menu['subcategories'] as &$sub) {
                            if ($hasSubTrans && !empty($sub['translations'])) {
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
                } else {
                    foreach ($menus as &$menu) {
                        $menu['subcategories'] = [];
                        if ($hasMenuTrans && !empty($menu['translations'])) {
                            $menu['translations'] = json_decode($menu['translations'], true);
                        } else {
                            $menu['translations'] = null;
                        }
                        if ($lang !== 'en' && !empty($menu['translations'][$lang]['name'])) {
                            $menu['menu_name_translated'] = $menu['translations'][$lang]['name'];
                        } else {
                            $menu['menu_name_translated'] = $menu['menu_name'];
                        }
                    }
                    unset($menu);
                }
            } catch (PDOException $e) {
                foreach ($menus as &$menu) {
                    $menu['subcategories'] = [];
                    $menu['menu_name_translated'] = $menu['menu_name'];
                }
                unset($menu);
            }
            
            echo json_encode($menus);
            break;
            
        case 'getMenuItems':
            $menuId = isset($_GET['menu_id']) ? $_GET['menu_id'] : null;
            $category = isset($_GET['category']) ? $_GET['category'] : null;
            $type = isset($_GET['type']) ? $_GET['type'] : null;
            
            // Get restaurant language
            $lang = $_SESSION['language'] ?? 'en';
            try {
                $ls = $conn->prepare("SELECT language, enable_language FROM users WHERE restaurant_id = :rid LIMIT 1");
                $ls->execute([':rid' => $restaurantId]);
                $lr = $ls->fetch(PDO::FETCH_ASSOC);
                if ($lr && !empty($lr['language']) && !empty($lr['enable_language'])) {
                    $lang = $lr['language'];
                    $_SESSION['language'] = $lang;
                } else {
                    $lang = 'en';
                    $_SESSION['language'] = 'en';
                }
            } catch (Exception $e) {}
            
            $hasItemTrans = false;
            try { $hasItemTrans = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'translations'")->rowCount() > 0; } catch (Exception $e) {}

            // Items an admin has hidden (see menu_items_operations_base64.php's
            // toggle_hidden) must never reach the customer website — unlike
            // get_menu_items.php (admin/POS), there is no opt-in here at all.
            $hasIsHiddenColumn = false;
            try { $hasIsHiddenColumn = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'is_hidden'")->rowCount() > 0; } catch (Exception $e) {}

            // Check if subcategory_id column exists
            $hasSubcategoryColumn = false;
            try {
                $checkSubCol = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'subcategory_id'");
                $hasSubcategoryColumn = $checkSubCol->rowCount() > 0;
            } catch (PDOException $e) {
                $hasSubcategoryColumn = false;
            }
            
            
            
            // Check if subcategories table exists
            $hasSubcategoriesTable = false;
            try {
                $checkSubTable = $conn->query("SHOW TABLES LIKE 'subcategories'");
                $hasSubcategoriesTable = $checkSubTable->rowCount() > 0;
            } catch (PDOException $e) {
                $hasSubcategoriesTable = false;
            }
            
            // Explicitly select columns to avoid issues with binary data and missing columns
            $itemTransCol = $hasItemTrans ? ", mi.translations" : "";
            $query = "SELECT mi.id, mi.restaurant_id, mi.menu_id, mi.item_name_en, mi.item_description_en, mi.description_format,
                             mi.item_category, mi.item_type, mi.preparation_time, mi.calories, mi.is_available, 
                             mi.base_price, mi.has_variations, mi.item_image, 
                             mi.sort_order, mi.created_at, mi.updated_at, m.menu_name" . $itemTransCol;
            
            // Include subcategory info if columns exist
            if ($hasSubcategoryColumn) {
                $query .= ", mi.subcategory_id";
                if ($hasSubcategoriesTable) {
                    $query .= ", sc.subcategory_name";
                }
            }
            
            $query .= " FROM menu_items mi 
                         JOIN menu m ON mi.menu_id = m.id";
            
            if ($hasSubcategoryColumn && $hasSubcategoriesTable) {
                $query .= " LEFT JOIN subcategories sc ON mi.subcategory_id = sc.id";
            }
            
            $query .= " WHERE mi.restaurant_id = :rid";
            if ($hasIsHiddenColumn) {
                $query .= " AND mi.is_hidden = 0";
            }

            $params = [':rid' => $restaurantId];

            if ($menuId) {
                $query .= " AND mi.menu_id = :menu_id";
                $params[':menu_id'] = $menuId;
            }

            if ($category) {
                $query .= " AND mi.item_category = :category";
                $params[':category'] = $category;
            }

            if ($type) {
                $query .= " AND mi.item_type = :type";
                $params[':type'] = $type;
            }

            $query .= " ORDER BY mi.sort_order, mi.item_name_en";
            
            try {
                $stmt = $conn->prepare($query);
                $stmt->execute($params);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Clean up any binary data that might have been included
                foreach ($items as &$item) {
                    if (isset($item['image_data'])) {
                        unset($item['image_data']); // Remove binary data from JSON
                    }
                    if (isset($item['image_mime_type'])) {
                        // Keep mime_type if needed, but we don't need it in the list
                        // unset($item['image_mime_type']);
                    }
                    
                    // Add translations
                    if ($hasItemTrans && !empty($item['translations'])) {
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
                    
                    // Load variations for this item
                    try {
                        $checkTable = $conn->query("SHOW TABLES LIKE 'menu_item_variations'");
                        if ($checkTable->rowCount() > 0) {
                            $variationsStmt = $conn->prepare("
                                SELECT id, variation_name, price, sort_order, is_available 
                                FROM menu_item_variations 
                                WHERE menu_item_id = ?
                                ORDER BY sort_order ASC
                            ");
                            $variationsStmt->execute([$item['id']]);
                            $item['variations'] = $variationsStmt->fetchAll(PDO::FETCH_ASSOC);
                        } else {
                            $item['variations'] = [];
                        }
                    } catch (PDOException $e) {
                        $item['variations'] = [];
                    }
                }
                unset($item);
                
                echo json_encode($items);
            } catch (PDOException $e) {
                // If columns don't exist, try with basic columns only
                if (strpos($e->getMessage(), 'image_data') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
                    $query = "SELECT mi.id, mi.restaurant_id, mi.menu_id, mi.item_name_en, mi.item_description_en, mi.description_format,
                                     mi.item_category, mi.item_type, mi.preparation_time, mi.is_available, 
                                     mi.base_price, mi.has_variations, mi.item_image, 
                                     mi.sort_order, mi.created_at, mi.updated_at, m.menu_name" . ($itemTransCol === ", mi.translations" ? "" : "");
                    
                    if ($hasSubcategoryColumn) {
                        $query .= ", mi.subcategory_id";
                        if ($hasSubcategoriesTable) {
                            $query .= ", sc.subcategory_name";
                        }
                    }
                    
                    $query .= " FROM menu_items mi 
                                 JOIN menu m ON mi.menu_id = m.id";
                    
                    if ($hasSubcategoryColumn && $hasSubcategoriesTable) {
                        $query .= " LEFT JOIN subcategories sc ON mi.subcategory_id = sc.id";
                    }
                    
                    $stmt = $conn->prepare($query);
                    $stmt->execute($params);
                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Add translations for fallback
                    foreach ($items as &$item) {
                        if ($hasItemTrans && !empty($item['translations'])) {
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
                    
                    // Load variations for each item
                    try {
                        $checkTable = $conn->query("SHOW TABLES LIKE 'menu_item_variations'");
                        if ($checkTable->rowCount() > 0) {
                            foreach ($items as &$item) {
                                $variationsStmt = $conn->prepare("
                                    SELECT id, variation_name, price, sort_order, is_available 
                                    FROM menu_item_variations 
                                    WHERE menu_item_id = ?
                                    ORDER BY sort_order ASC
                                ");
                                $variationsStmt->execute([$item['id']]);
                                $item['variations'] = $variationsStmt->fetchAll(PDO::FETCH_ASSOC);
                            }
                            unset($item);
                        } else {
                            foreach ($items as &$item) {
                                $item['variations'] = [];
                            }
                            unset($item);
                        }
                    } catch (PDOException $e) {
                        foreach ($items as &$item) {
                            $item['variations'] = [];
                        }
                        unset($item);
                    }
                    
                    echo json_encode($items);
                } else {
                    throw $e;
                }
            }
            break;
            
        case 'getCategories':
            $menuId = isset($_GET['menu_id']) ? trim($_GET['menu_id']) : null;
            // Handle string "null" or empty string
            if ($menuId === 'null' || $menuId === '' || $menuId === null) {
                $menuId = null;
            }
            
            try {
                if ($menuId) {
                    // Get item categories (sub-categories) for the selected menu
                    // Get distinct item categories with images from menu_items
                    $baseQuery = "SELECT DISTINCT mi.item_category
                                  FROM menu_items mi 
                                  WHERE mi.restaurant_id = :rid
                                  AND mi.menu_id = :menu_id
                                  AND mi.item_category IS NOT NULL 
                                  AND mi.item_category != ''";
                    
                    $baseQuery .= " ORDER BY mi.item_category";
                    
                    $stmt = $conn->prepare($baseQuery);
                    $stmt->execute([':rid' => $restaurantId, ':menu_id' => $menuId]);
                    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Get images for each category (first item's image in that category)
                    $result = [];
                    foreach ($categories as $cat) {
                        $categoryName = $cat['item_category'];
                        
                        // Get first item image for this category
                        $imageQuery = "SELECT item_image FROM menu_items 
                                       WHERE restaurant_id = :rid 
                                       AND menu_id = :menu_id
                                       AND item_category = :category
                                       AND item_image IS NOT NULL 
                                       AND item_image != ''
                                       LIMIT 1";
                        
                        $imageStmt = $conn->prepare($imageQuery);
                        $imageStmt->execute([
                            ':rid' => $restaurantId,
                            ':menu_id' => $menuId,
                            ':category' => $categoryName
                        ]);
                        $imageRow = $imageStmt->fetch(PDO::FETCH_ASSOC);
                        
                        $result[] = [
                            'name' => $categoryName,
                            'image' => $imageRow['item_image'] ?? null
                        ];
                    }
                    
                    echo json_encode($result);
                } else {
                    // Get menu categories (admin panel categories) when no menu is selected
                    $query = "SELECT id, menu_name, menu_image, is_active, sort_order 
                             FROM menu 
                             WHERE restaurant_id = :rid 
                             AND is_active = 1
                             ORDER BY sort_order ASC, menu_name ASC";
                    
                    $stmt = $conn->prepare($query);
                    $stmt->execute([':rid' => $restaurantId]);
                    $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Format as categories with name and image
                    $result = [];
                    foreach ($menus as $menu) {
                        $result[] = [
                            'id' => $menu['id'],
                            'name' => $menu['menu_name'],
                            'image' => $menu['menu_image'] ?? null
                        ];
                    }
                    
                    echo json_encode($result);
                }
            } catch (PDOException $e) {
                error_log("Error in getCategories: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(['error' => 'An error occurred. Please try again.']);
            } catch (Exception $e) {
                error_log("Error in getCategories: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(['error' => 'An error occurred. Please try again.']);
            }
            break;
            
        case 'searchItems':
            $searchTerm = isset($_GET['q']) ? trim($_GET['q']) : '';
            // 🛡️ SECURITY: Validate and sanitize search input
            // 1. Reject empty or whitespace-only searches early
            // 2. Limit max length to prevent abuse/DoS (reasonable search limit)
            // 3. Escape LIKE wildcards (% and _) to prevent unintended pattern matching
            //    (e.g., searching '50%' should find items with '50%', not everything)
            if ($searchTerm === '') {
                echo json_encode([]);
                break;
            }
            if (strlen($searchTerm) > 200) {
                $searchTerm = substr($searchTerm, 0, 200);
            }
            // Escape LIKE wildcards: % and _ are treated as literal chars, not wildcards
            // In PHP single-quoted strings, '\%' produces the string \% (backslash + percent)
            // MySQL's default LIKE escape character is \, so \% matches a literal %
            $searchTerm = str_replace(['%', '_'], ['\%', '\_'], $searchTerm);
            // Get restaurant language
            $lang = $_SESSION['language'] ?? 'en';
            try {
                $ls = $conn->prepare("SELECT language, enable_language FROM users WHERE restaurant_id = :rid LIMIT 1");
                $ls->execute([':rid' => $restaurantId]);
                $lr = $ls->fetch(PDO::FETCH_ASSOC);
                if ($lr && !empty($lr['language']) && !empty($lr['enable_language'])) {
                    $lang = $lr['language'];
                    $_SESSION['language'] = $lang;
                } else {
                    $lang = 'en';
                    $_SESSION['language'] = 'en';
                }
            } catch (Exception $e) {}
            // Explicitly select columns to avoid binary data issues
            // Check if subcategory_id column exists
            $searchHasSubCol = false;
            try {
                $checkSubCol = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'subcategory_id'");
                $searchHasSubCol = $checkSubCol->rowCount() > 0;
            } catch (PDOException $e) {
                $searchHasSubCol = false;
            }
            
            // Check if subcategories table exists
            $searchHasSubTable = false;
            try {
                $checkSubTable = $conn->query("SHOW TABLES LIKE 'subcategories'");
                $searchHasSubTable = $checkSubTable->rowCount() > 0;
            } catch (PDOException $e) {
                $searchHasSubTable = false;
            }
            
                    $itemTransCol = $hasItemTrans ? ", mi.translations" : "";
                    $query = "SELECT mi.id, mi.restaurant_id, mi.menu_id, mi.item_name_en, mi.item_description_en, mi.description_format,
                                     mi.item_category, mi.item_type, mi.preparation_time, mi.is_available, 
                                     mi.base_price, mi.has_variations, mi.item_image, 
                                     mi.sort_order, mi.created_at, mi.updated_at, m.menu_name" . $itemTransCol;
            
            if ($searchHasSubCol) {
                $query .= ", mi.subcategory_id";
                if ($searchHasSubTable) {
                    $query .= ", sc.subcategory_name";
                }
            }
            
            $query .= " FROM menu_items mi 
                         JOIN menu m ON mi.menu_id = m.id";
            
            if ($searchHasSubCol && $searchHasSubTable) {
                $query .= " LEFT JOIN subcategories sc ON mi.subcategory_id = sc.id";
            }
            
            $query .= " WHERE (mi.item_name_en LIKE :search1 OR mi.item_description_en LIKE :search2 OR mi.item_category LIKE :search3)
                      AND mi.restaurant_id = :rid
                      ORDER BY mi.item_name_en LIMIT 20";
            $like = '%' . $searchTerm . '%';
            try {
                $stmt = $conn->prepare($query);
                $stmt->execute([
                    ':search1' => $like,
                    ':search2' => $like,
                    ':search3' => $like,
                    ':rid' => $restaurantId
                ]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Clean up any binary data and load variations
                try {
                    $checkTable = $conn->query("SHOW TABLES LIKE 'menu_item_variations'");
                    $hasVariationsTable = $checkTable->rowCount() > 0;
                } catch (PDOException $e) {
                    $hasVariationsTable = false;
                }
                
                $searchLang = $lang;
                
                foreach ($items as &$item) {
                    if (isset($item['image_data'])) {
                        unset($item['image_data']);
                    }
                    
                    // Add translations
                    if ($hasItemTrans && !empty($item['translations'])) {
                        $item['translations'] = is_string($item['translations']) ? json_decode($item['translations'], true) : $item['translations'];
                    } else {
                        $item['translations'] = null;
                    }
                    if ($searchLang !== 'en' && !empty($item['translations'][$searchLang]['name'])) {
                        $item['item_name_translated'] = $item['translations'][$searchLang]['name'];
                        $item['item_description_translated'] = $item['translations'][$searchLang]['description'] ?? '';
                    } else {
                        $item['item_name_translated'] = $item['item_name_en'];
                        $item['item_description_translated'] = $item['item_description_en'] ?? '';
                    }
                    
                    // Load variations
                    if ($hasVariationsTable) {
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
                    } else {
                        $item['variations'] = [];
                    }
                }
                unset($item);
                
                echo json_encode($items);
            } catch (PDOException $e) {
                // If columns don't exist, try with basic columns
                if (strpos($e->getMessage(), 'image_data') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
                    $itemTransCol = $hasItemTrans ? ", mi.translations" : "";
                    $query = "SELECT mi.id, mi.restaurant_id, mi.menu_id, mi.item_name_en, mi.item_description_en, mi.description_format,
                                     mi.item_category, mi.item_type, mi.preparation_time, mi.is_available, 
                                     mi.base_price, mi.has_variations, mi.item_image, 
                                     mi.sort_order, mi.created_at, mi.updated_at, m.menu_name" . $itemTransCol;
                    
                    if ($searchHasSubCol) {
                        $query .= ", mi.subcategory_id";
                        if ($searchHasSubTable) {
                            $query .= ", sc.subcategory_name";
                        }
                    }
                    
                    $query .= " FROM menu_items mi 
                                 JOIN menu m ON mi.menu_id = m.id";
                    
                    if ($searchHasSubCol && $searchHasSubTable) {
                        $query .= " LEFT JOIN subcategories sc ON mi.subcategory_id = sc.id";
                    }
                    
                    $query .= " WHERE (mi.item_name_en LIKE :search1 OR mi.item_description_en LIKE :search2 OR mi.item_category LIKE :search3)
                              AND mi.restaurant_id = :rid
                              ORDER BY mi.item_name_en LIMIT 20";
                    $stmt = $conn->prepare($query);
                    $stmt->execute([
                        ':search1' => $like,
                        ':search2' => $like,
                        ':search3' => $like,
                        ':rid' => $restaurantId
                    ]);
                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($items as &$item) {
                        if ($hasItemTrans && !empty($item['translations'])) {
                            $item['translations'] = is_string($item['translations']) ? json_decode($item['translations'], true) : $item['translations'];
                        } else {
                            $item['translations'] = null;
                        }
                        if ($searchLang !== 'en' && !empty($item['translations'][$searchLang]['name'])) {
                            $item['item_name_translated'] = $item['translations'][$searchLang]['name'];
                            $item['item_description_translated'] = $item['translations'][$searchLang]['description'] ?? '';
                        } else {
                            $item['item_name_translated'] = $item['item_name_en'];
                            $item['item_description_translated'] = $item['item_description_en'] ?? '';
                        }
                    }
                    unset($item);
                    echo json_encode($items);
                } else {
                    error_log("Search error: " . $e->getMessage());
                    error_log("Search error: " . $e->getMessage());
                    echo json_encode(['error' => 'Search failed. Please try again.']);
                }
            }
            break;
            
        case 'getCustomerOrders':
            $phone = isset($_GET['phone']) ? $_GET['phone'] : '';
            if (!$phone) {
                echo json_encode(['error' => 'Phone number is required']);
                break;
            }
            
            // Query orders directly by customer_phone to avoid name mismatch issues
            $stmt = $conn->prepare("SELECT o.*, 
                                   (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) as item_count,
                                   (SELECT COUNT(*) FROM order_feedback f WHERE f.order_id = o.id) > 0 as has_feedback
                                   FROM orders o 
                                   WHERE o.customer_phone = :phone AND o.restaurant_id = :rid 
                                   ORDER BY o.created_at DESC LIMIT 20");
            $stmt->execute([':phone' => $phone, ':rid' => $restaurantId]);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($orders as &$order) {
                $itemsStmt = $conn->prepare("SELECT oi.item_name, oi.quantity, oi.unit_price, oi.total_price, oi.notes, oi.menu_item_id, mi.item_type, mi.item_image 
                                           FROM order_items oi 
                                           LEFT JOIN menu_items mi ON oi.menu_item_id = mi.id
                                           WHERE oi.order_id = :order_id");
                $itemsStmt->execute([':order_id' => $order['id']]);
                $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                $order['has_feedback'] = (bool)$order['has_feedback'];
            }
            
            echo json_encode($orders);
            break;
            
        case 'getDeals':
            try {
                $stmt = $conn->prepare("
                    SELECT d.id, d.deal_type, d.menu_id, m.menu_name
                    FROM deals d
                    JOIN menu m ON d.menu_id = m.id
                    WHERE d.restaurant_id = :rid AND d.is_active = 1
                    ORDER BY d.created_at DESC
                ");
                $stmt->execute([':rid' => $restaurantId]);
                echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            } catch (PDOException $e) {
                error_log("Error in getDeals: " . $e->getMessage());
                echo json_encode(['error' => 'An error occurred. Please try again.']);
            }
            break;

    case 'getAddons':
            try {
                // Auto-create meal_addons table if it doesn't exist
                $checkTable = $conn->query("SHOW TABLES LIKE 'meal_addons'");
                if ($checkTable->rowCount() == 0) {
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
                
                $stmt = $conn->prepare("SELECT id, addon_name, addon_price, addon_image, is_available FROM meal_addons WHERE restaurant_id = :rid AND is_available = 1 ORDER BY addon_name ASC");
                $stmt->execute([':rid' => $restaurantId]);
                $addons = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'data' => $addons,
                    'count' => count($addons)
                ]);
            } catch (PDOException $e) {
                error_log("Error in getAddons: " . $e->getMessage());
                echo json_encode(['success' => true, 'data' => []]);
            }
            break;

    default:
        echo json_encode(['error' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log("Database error in website/api.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred. Please try again.']);
} catch (Exception $e) {
    error_log("Error in website/api.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred. Please try again.']);
}
?>

