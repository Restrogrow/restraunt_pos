<?php
// Register error handler to catch all errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false, 
            'message' => 'Fatal error: ' . $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
});

// Suppress error display, log errors instead
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any unexpected output
ob_start();

try {
    // Include secure session configuration
    if (!file_exists(__DIR__ . '/../config/session_config.php')) {
        throw new Exception('session_config.php not found');
    }
    require_once __DIR__ . '/../config/session_config.php';
    
    if (!function_exists('startSecureSession')) {
        throw new Exception('startSecureSession function not found');
    }
    startSecureSession();
    
    // Load Order State Machine
    if (file_exists(__DIR__ . '/../config/order_state_machine.php')) {
        require_once __DIR__ . '/../config/order_state_machine.php';
    }

    // Clean any output that might have been generated
    ob_clean();

    header('Content-Type: application/json; charset=UTF-8');

    // Include authorization configuration
    if (!file_exists(__DIR__ . '/../config/authorization_config.php')) {
        throw new Exception('authorization_config.php not found');
    }
    require_once __DIR__ . '/../config/authorization_config.php';

    // Require permission to manage orders
    if (!function_exists('requirePermission')) {
        throw new Exception('requirePermission function not found');
    }
    if (!defined('PERMISSION_MANAGE_ORDERS')) {
        throw new Exception('PERMISSION_MANAGE_ORDERS constant not defined');
    }
    requirePermission(PERMISSION_MANAGE_ORDERS);
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    error_log("Initialization error in pos_operations.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false,        'message' => 'An initialization error occurred. Please try again.'], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Error $e) {
    ob_clean();
    http_response_code(500);
    error_log("Fatal initialization error in pos_operations.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'An initialization error occurred. Please try again.'], JSON_UNESCAPED_UNICODE);
    exit();
}

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

// ── Shared helper functions ──
// These are defined here to avoid dependency on kot_operations.php
// (which has its own request routing that would execute if included)

if (!function_exists('generateKOTNumber')) {
    function generateKOTNumber($conn = null, $restaurant_id = null) {
        global $pdo;
        $db = $conn ?? $pdo;
        if (!$db || !$restaurant_id) {
            return 'KOT-' . date('Ymd') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        }
        $maxAttempts = 100;
        $attempt = 0;
        do {
            $kotNumber = 'KOT-' . date('Ymd') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            try {
                $checkStmt = $db->prepare("SELECT COUNT(*) FROM kot WHERE kot_number = ? AND restaurant_id = ?");
                $checkStmt->execute([$kotNumber, $restaurant_id]);
                $exists = $checkStmt->fetchColumn() > 0;
            } catch (PDOException $e) {
                error_log("Error checking KOT number: " . $e->getMessage());
                return $kotNumber;
            }
            $attempt++;
            if ($attempt >= $maxAttempts) {
                $kotNumber = 'KOT-' . date('YmdHis') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
                break;
            }
        } while ($exists);
        return $kotNumber;
    }
}

if (!function_exists('generateOrderNumber')) {
    function generateOrderNumber($conn = null, $restaurant_id = null) {
        global $pdo;
        $db = $conn ?? $pdo;
        if (!$db || !$restaurant_id) {
            return 'ORD-' . date('Ymd') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        }
        $maxAttempts = 100;
        $attempt = 0;
        do {
            $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            try {
                $checkStmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE order_number = ? AND restaurant_id = ?");
                $checkStmt->execute([$orderNumber, $restaurant_id]);
                $exists = $checkStmt->fetchColumn() > 0;
            } catch (PDOException $e) {
                error_log("Error checking Order number: " . $e->getMessage());
                return $orderNumber;
            }
            $attempt++;
            if ($attempt >= $maxAttempts) {
                $orderNumber = 'ORD-' . date('YmdHis') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
                break;
            }
        } while ($exists);
        return $orderNumber;
    }
}

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
    
    // Validate action
    if (empty($action)) {
        echo json_encode(['success' => false, 'message' => 'Action is required'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    switch ($action) {
        case 'create_kot':
            handleCreateKOT($conn, $restaurant_id);
            break;
            
        case 'hold_order':
            handleHoldOrder($conn, $restaurant_id);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action], JSON_UNESCAPED_UNICODE);
    }
} catch (PDOException $e) {
    error_log("PDO Error in pos_operations.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again later.'], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in pos_operations.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.'], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Error $e) {
    // Catch fatal errors (PHP 7+)
    error_log("Fatal Error in pos_operations.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.'], JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * Verifies every cart item's price against menu_items/menu_item_variations
 * before it's trusted for a POS/counter order. The existing subtotal/tax/
 * total checks in handleCreateKOT()/handleHoldOrder() only caught internal
 * inconsistency (does subtotal+tax=total) — they never verified an item's
 * price actually matched the menu, so a modified request (or a page CSRFing
 * a logged-in staff session) could under-ring every item. Mirrors the
 * verification process_website_order.php already does for website orders.
 *
 * @return string|null An error message if any item fails verification, or
 *                      null if every item is valid.
 */
function verifyPosCartItemPrices($conn, $restaurant_id, array $cartItems): ?string {
    $menuItemIds = [];
    foreach ($cartItems as $ci) {
        $mid = (int)($ci['id'] ?? 0);
        if ($mid > 0) $menuItemIds[] = $mid;
    }
    $menuItemIds = array_values(array_unique($menuItemIds));
    if (empty($menuItemIds)) {
        return 'Cart contains no valid items';
    }

    $placeholders = implode(',', array_fill(0, count($menuItemIds), '?'));
    $menuStmt = $conn->prepare("SELECT id, base_price, is_available FROM menu_items WHERE id IN ($placeholders) AND restaurant_id = ?");
    $menuStmt->execute(array_merge($menuItemIds, [$restaurant_id]));
    $menuItemsById = [];
    foreach ($menuStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $menuItemsById[(int)$row['id']] = $row;
    }

    $varStmt = $conn->prepare("SELECT menu_item_id, variation_name, price FROM menu_item_variations WHERE menu_item_id IN ($placeholders)");
    $varStmt->execute($menuItemIds);
    $variationsByItem = [];
    foreach ($varStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $variationsByItem[(int)$row['menu_item_id']][$row['variation_name']] = (float)$row['price'];
    }

    foreach ($cartItems as $ci) {
        $mid = (int)($ci['id'] ?? 0);
        $menuRow = $menuItemsById[$mid] ?? null;
        if (!$menuRow || !$menuRow['is_available']) {
            return 'One or more items are no longer available';
        }

        $expectedPrice = (float)$menuRow['base_price'];
        $variationName = $ci['variation_name'] ?? '';
        if (!empty($variationName)) {
            if (!isset($variationsByItem[$mid][$variationName])) {
                return 'Invalid item variation';
            }
            $expectedPrice = $variationsByItem[$mid][$variationName];
        }

        if (abs((float)($ci['price'] ?? 0) - $expectedPrice) > 0.01) {
            error_log("POS price mismatch for menu item $mid (restaurant $restaurant_id): client sent " . ($ci['price'] ?? 'null') . ", expected $expectedPrice");
            return 'Item price mismatch — please refresh the menu and try again';
        }
    }

    return null;
}

function handleCreateKOT($conn, $restaurant_id) {
    $tableId = $_POST['tableId'] ?? null;
    $tableIdParam = $tableId ? $tableId : null;
    $orderType = $_POST['orderType'] ?? 'Dine-in';
    $customerName = $_POST['customerName'] ?? '';
    $customerPhone = $_POST['customerPhone'] ?? '';
    $customerEmail = $_POST['customerEmail'] ?? '';
    $customerAddress = $_POST['customerAddress'] ?? '';
    
    // Decode cart items with error handling
    $cartItemsJson = $_POST['cartItems'] ?? '[]';
    $cartItems = json_decode($cartItemsJson, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error in handleCreateKOT: " . json_last_error_msg());
        error_log("Cart items JSON: " . substr($cartItemsJson, 0, 500));
        echo json_encode(['success' => false, 'message' => 'Invalid cart data: ' . json_last_error_msg()], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $subtotal = floatval($_POST['subtotal'] ?? 0);
    $tax = floatval($_POST['tax'] ?? 0);
    $total = floatval($_POST['total'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $paymentMethod = $_POST['paymentMethod'] ?? 'Cash';
    
    // Validation
    if (empty($cartItems) || !is_array($cartItems)) {
        echo json_encode(['success' => false, 'message' => 'Cart is empty or invalid'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $priceError = verifyPosCartItemPrices($conn, $restaurant_id, $cartItems);
    if ($priceError !== null) {
        echo json_encode(['success' => false, 'message' => $priceError], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Server-side tax validation
    $expectedTotal = round($subtotal + $tax, 2);
    if (abs($total - $expectedTotal) > 0.01) {
        echo json_encode(['success' => false, 'message' => 'Total mismatch: total does not match subtotal + tax'], JSON_UNESCAPED_UNICODE);
        return;
    }
    // Check if tax is enabled for this restaurant, and at what rate
    $gstCheck = $conn->prepare("SELECT enable_gst, tax_percent FROM users WHERE restaurant_id = ? LIMIT 1");
    $gstCheck->execute([$restaurant_id]);
    $gstRow = $gstCheck->fetch(PDO::FETCH_ASSOC);
    $gstEnabled = $gstRow ? (bool)$gstRow['enable_gst'] : true;
    $taxPercent = ($gstRow && isset($gstRow['tax_percent']) && $gstRow['tax_percent'] !== null) ? (float)$gstRow['tax_percent'] : 5.00;
    $expectedTax = $gstEnabled ? round($subtotal * ($taxPercent / 100), 2) : 0;
    if (abs($tax - $expectedTax) > 0.01) {
        echo json_encode(['success' => false, 'message' => 'Tax mismatch: calculated tax does not match expected value'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Generate unique KOT number with collision check
    $kotNumber = generateKOTNumber($conn, $restaurant_id);
    
    // Begin transaction
    $conn->beginTransaction();
    
    try {
        // Both Dine-in and Takeaway orders go through KOT first
        // Order will be created when KOT is marked as Ready in the KOT page
        $kotId = null;
        
        // Append payment method to notes if not Cash
        $kotNotes = $notes;
        if (!empty($paymentMethod) && $paymentMethod !== 'Cash') {
            if (!empty($kotNotes)) {
                $kotNotes .= "\n[Payment: " . $paymentMethod . "]";
            } else {
                $kotNotes = "[Payment: " . $paymentMethod . "]";
            }
        }
        
        // Check if customer columns exist in kot table
        $checkKotCols = $conn->query("SHOW COLUMNS FROM kot LIKE 'customer_phone'");
        if ($checkKotCols->rowCount() > 0) {
            $stmt = $conn->prepare("INSERT INTO kot (restaurant_id, kot_number, table_id, order_type, customer_name, customer_phone, customer_email, customer_address, subtotal, tax, total, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$restaurant_id, $kotNumber, $tableIdParam, $orderType, $customerName, $customerPhone, $customerEmail, $customerAddress, $subtotal, $tax, $total, $kotNotes]);
        } else {
            $stmt = $conn->prepare("INSERT INTO kot (restaurant_id, kot_number, table_id, order_type, customer_name, subtotal, tax, total, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$restaurant_id, $kotNumber, $tableIdParam, $orderType, $customerName, $subtotal, $tax, $total, $kotNotes]);
        }
        $kotId = $conn->lastInsertId();
        
        // Check if addons column exists in kot_items
        $kotItemsHasAddons = false;
        try {
            $checkAddonsCol = $conn->query("SHOW COLUMNS FROM kot_items LIKE 'addons'");
            $kotItemsHasAddons = $checkAddonsCol && $checkAddonsCol->rowCount() > 0;
        } catch (Exception $e) {}
        
        foreach ($cartItems as $item) {
            // Validate item structure
            if (!isset($item['id']) || !isset($item['name']) || !isset($item['price']) || !isset($item['quantity'])) {
                error_log("Invalid cart item structure: " . json_encode($item));
                throw new Exception("Invalid cart item structure. Missing required fields.");
            }
            
            if ($kotItemsHasAddons && !empty($item['addons']) && is_array($item['addons'])) {
                $verifiedAddons = [];
                foreach ($item['addons'] as $addon) {
                    $addonId = (int)($addon['id'] ?? 0);
                    if ($addonId <= 0) continue;
                    $addonStmt = $conn->prepare("SELECT id, addon_name, addon_price, is_available FROM meal_addons WHERE id = ? AND restaurant_id = ? AND is_available = 1");
                    $addonStmt->execute([$addonId, $restaurant_id]);
                    $dbAddon = $addonStmt->fetch(PDO::FETCH_ASSOC);
                    if ($dbAddon) {
                        $verifiedAddons[] = [
                            'id'    => (int)$dbAddon['id'],
                            'name'  => $dbAddon['addon_name'],
                            'price' => (float)$dbAddon['addon_price'],
                        ];
                    }
                }
                $addonsJson = !empty($verifiedAddons) ? json_encode($verifiedAddons, JSON_UNESCAPED_UNICODE) : null;
                $itemStmt = $conn->prepare("INSERT INTO kot_items (kot_id, menu_item_id, item_name, quantity, unit_price, total_price, addons) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $itemStmt->execute([
                    $kotId,
                    (int)$item['id'],
                    (string)$item['name'],
                    (int)$item['quantity'],
                    (float)$item['price'],
                    (float)($item['price'] * $item['quantity']),
                    $addonsJson
                ]);
            } else {
                $itemStmt = $conn->prepare("INSERT INTO kot_items (kot_id, menu_item_id, item_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
                $itemStmt->execute([
                    $kotId,
                    (int)$item['id'],
                    (string)$item['name'],
                    (int)$item['quantity'],
                    (float)$item['price'],
                    (float)($item['price'] * $item['quantity'])
                ]);
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        $resp = [
            'success' => true,
            'message' => 'KOT created successfully. Order will be created when KOT is marked as Ready.',
            'kot_number' => $kotNumber,
            'kot_id' => $kotId
        ];
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        error_log("Error in handleCreateKOT: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        if (ob_get_level()) {
            ob_clean();
        }
        echo json_encode(['success' => false, 'message' => 'Error creating KOT. Please try again.'], JSON_UNESCAPED_UNICODE);
    }
}

function handleHoldOrder($conn, $restaurant_id) {
    $tableId = $_POST['tableId'] ?? null;
    $cartItems = json_decode($_POST['cartItems'] ?? '[]', true);
    $subtotal = floatval($_POST['subtotal'] ?? 0);
    $tax = floatval($_POST['tax'] ?? 0);
    $total = floatval($_POST['total'] ?? 0);
    
    // Validation
    if (empty($cartItems)) {
        echo json_encode(['success' => false, 'message' => 'Cart is empty'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $priceError = verifyPosCartItemPrices($conn, $restaurant_id, $cartItems);
    if ($priceError !== null) {
        echo json_encode(['success' => false, 'message' => $priceError], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Server-side tax validation
    $expectedTotal = round($subtotal + $tax, 2);
    if (abs($total - $expectedTotal) > 0.01) {
        echo json_encode(['success' => false, 'message' => 'Total mismatch: total does not match subtotal + tax'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $gstCheck = $conn->prepare("SELECT enable_gst, tax_percent FROM users WHERE restaurant_id = ? LIMIT 1");
    $gstCheck->execute([$restaurant_id]);
    $gstRow = $gstCheck->fetch(PDO::FETCH_ASSOC);
    $gstEnabled = $gstRow ? (bool)$gstRow['enable_gst'] : true;
    $taxPercent = ($gstRow && isset($gstRow['tax_percent']) && $gstRow['tax_percent'] !== null) ? (float)$gstRow['tax_percent'] : 5.00;
    $expectedTax = $gstEnabled ? round($subtotal * ($taxPercent / 100), 2) : 0;
    if (abs($tax - $expectedTax) > 0.01) {
        echo json_encode(['success' => false, 'message' => 'Tax mismatch: calculated tax does not match expected value'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Generate unique order number for held order (using same function but with HOLD prefix)
    // For held orders, we'll use a similar pattern but check against orders table
    $maxAttempts = 100;
    $attempt = 0;
    do {
        $orderNumber = 'HOLD-' . date('Ymd') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE order_number = ? AND restaurant_id = ?");
        $checkStmt->execute([$orderNumber, $restaurant_id]);
        $exists = $checkStmt->fetchColumn() > 0;
        $attempt++;
        if ($attempt >= $maxAttempts) {
            $orderNumber = 'HOLD-' . date('YmdHis') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            break;
        }
    } while ($exists);
    
    // Begin transaction
    $conn->beginTransaction();
    
    try {
        // Insert order with "Pending" payment status
        $orderType = empty($tableId) ? 'Takeaway' : 'Dine-in';
        $stmt = $conn->prepare("INSERT INTO orders (restaurant_id, table_id, order_number, order_type, payment_method, payment_status, order_status, subtotal, tax, total, notes) VALUES (?, ?, ?, ?, 'Cash', 'Pending', 'Pending', ?, ?, ?, 'Order on hold')");
        $stmt->execute([$restaurant_id, $tableId ?: null, $orderNumber, $orderType, $subtotal, $tax, $total]);
        
        $orderId = $conn->lastInsertId();
        
        // Insert order items
        $itemStmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
        
        foreach ($cartItems as $item) {
            // Validate item structure
            if (!isset($item['id']) || !isset($item['name']) || !isset($item['price']) || !isset($item['quantity'])) {
                error_log("Invalid cart item structure: " . json_encode($item));
                throw new Exception("Invalid cart item structure. Missing required fields.");
            }
            
            $itemStmt->execute([
                $orderId,
                (int)$item['id'],
                (string)$item['name'],
                (int)$item['quantity'],
                (float)$item['price'],
                (float)($item['price'] * $item['quantity'])
            ]);
        }
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Order held successfully',
            'order_number' => $orderNumber,
            'order_id' => $orderId
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        error_log("Error in handleHoldOrder: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        if (ob_get_level()) {
            ob_clean();
        }
        echo json_encode(['success' => false, 'message' => 'Error holding order. Please try again.'], JSON_UNESCAPED_UNICODE);
    }
}

