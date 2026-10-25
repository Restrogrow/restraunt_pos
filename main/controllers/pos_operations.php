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
    if (file_exists(__DIR__ . '/../config/cors_helper.php')) {
        require_once __DIR__ . '/../config/cors_helper.php';
        allowAppOrigin();
    }

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
    require_once __DIR__ . '/../config/inventory_engine.php';   // stock follows orders (invKick)
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

        case 'list_held':
            handleListHeldOrders($conn, $restaurant_id);
            break;

        case 'resume_held':
            handleRetireHeldOrder($conn, $restaurant_id, true);
            break;

        case 'discard_held':
            handleRetireHeldOrder($conn, $restaurant_id, false);
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
        $variationName = $ci['variation_name'] ?? ($ci['variationName'] ?? '');
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

/**
 * Add-ons in a POS cart (assets/js/pos-addons.js), checked against meal_addons like the website does:
 *  - a dish's add-ons (cartItems[i].addons = [{id}]) are charged once per unit of that dish;
 *  - order add-ons (POST globalAddons = [{id, quantity}]) are their own lines.
 * Unknown or switched-off add-ons are dropped. Each cart item's addons are replaced with the DB
 * names and prices. Returns ['global' => [...verified], 'subtotal' => what the cart adds up to].
 */
function posVerifyAddons($conn, $restaurant_id, array &$cartItems, $globalRaw): array {
    $ids = [];
    foreach ($cartItems as $ci) foreach ((array)($ci['addons'] ?? []) as $a) if ((int)($a['id'] ?? 0) > 0) $ids[] = (int)$a['id'];
    $globalRaw = is_array($globalRaw) ? $globalRaw : [];
    foreach ($globalRaw as $g) if ((int)($g['id'] ?? 0) > 0) $ids[] = (int)$g['id'];
    $byId = [];
    if ($ids) {
        $ids = array_values(array_unique($ids));
        $st = $conn->prepare('SELECT id, addon_name, addon_price FROM meal_addons WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND restaurant_id = ? AND is_available = 1');
        $st->execute(array_merge($ids, [$restaurant_id]));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $byId[(int)$r['id']] = ['id' => (int)$r['id'], 'name' => $r['addon_name'], 'price' => (float)$r['addon_price']];
    }
    $sub = 0.0;
    foreach ($cartItems as &$ci) {
        $v = [];
        foreach ((array)($ci['addons'] ?? []) as $a) { $id = (int)($a['id'] ?? 0); if (isset($byId[$id])) $v[] = $byId[$id]; }
        $ci['addons'] = $v;
        $sub += ((float)($ci['price'] ?? 0) + array_sum(array_column($v, 'price'))) * (int)($ci['quantity'] ?? 0);
    }
    unset($ci);
    $global = [];
    foreach ($globalRaw as $g) {
        $id = (int)($g['id'] ?? 0); $q = max(0, (int)($g['quantity'] ?? 0));
        if (!isset($byId[$id]) || $q <= 0) continue;
        $global[] = $byId[$id] + ['quantity' => $q];
        $sub += $byId[$id]['price'] * $q;
    }
    return ['global' => $global, 'subtotal' => round($sub, 2)];
}

/** Save a cart's add-ons on an order: JSON on the dish's line, order add-ons as their own lines (like the website). */
function posSaveOrderAddons($conn, int $orderId, array $orderItemIds, array $cartItems, array $global): void {
    try {
        $aStmt = $conn->prepare('INSERT INTO order_item_addons (order_item_id, addon_id, addon_name, addon_price, quantity) VALUES (?, ?, ?, ?, ?)');
        foreach ($cartItems as $i => $ci) {
            if (empty($ci['addons']) || empty($orderItemIds[$i])) continue;
            $conn->prepare('UPDATE order_items SET addons = ? WHERE id = ?')->execute([json_encode($ci['addons'], JSON_UNESCAPED_UNICODE), $orderItemIds[$i]]);
            foreach ($ci['addons'] as $a) $aStmt->execute([$orderItemIds[$i], $a['id'], $a['name'], $a['price'], 1]);
        }
        $gStmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, item_name, variation_name, quantity, unit_price, total_price) VALUES (?, NULL, ?, 'Add-on', ?, ?, ?)");
        foreach ($global as $g) {
            $gStmt->execute([$orderId, $g['name'], $g['quantity'], $g['price'], $g['price'] * $g['quantity']]);
            $aStmt->execute([(int)$conn->lastInsertId(), $g['id'], $g['name'], $g['price'], $g['quantity']]);
        }
    } catch (PDOException $e) {
        error_log('posSaveOrderAddons order ' . $orderId . ': ' . $e->getMessage());
        throw $e;
    }
}

/** Kitchen ticket lines for order add-ons have no dish: kot_items.menu_item_id must allow NULL (once, outside any transaction). */
function posEnsureKotAddonLines($conn): void {
    static $done = false;
    if ($done || $conn->inTransaction()) return;
    $done = true;
    try {
        $nullable = $conn->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kot_items' AND COLUMN_NAME = 'menu_item_id'")->fetchColumn();
        if ($nullable === 'NO') $conn->exec('ALTER TABLE kot_items MODIFY menu_item_id INT NULL');
        // the line's add-on name (only admin/run_addons_table.php used to add it)
        if (!$conn->query("SHOW COLUMNS FROM kot_items LIKE 'addon_name'")->fetch()) $conn->exec('ALTER TABLE kot_items ADD COLUMN addon_name VARCHAR(500) DEFAULT NULL AFTER item_name');
    } catch (Throwable $e) { error_log('posEnsureKotAddonLines: ' . $e->getMessage()); }
}

/**
 * Columns a POS bill writes that older databases (the live one) may not have yet: added once,
 * outside any transaction (database/local_schema_upgrade.sql adds the same ones locally).
 */
function posEnsureBillColumns($conn): void {
    static $done = false;
    if ($done || $conn->inTransaction()) return;
    $done = true;
    // the add-on tables (admin/run_addons_migration.php, never run on some databases)
    $tables = [
        'meal_addons' => "CREATE TABLE IF NOT EXISTS meal_addons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            addon_name VARCHAR(255) NOT NULL,
            addon_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            addon_image VARCHAR(500) DEFAULT NULL,
            image_data LONGBLOB DEFAULT NULL,
            image_mime_type VARCHAR(50) DEFAULT NULL,
            is_available TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_addon_restaurant (restaurant_id),
            INDEX idx_addon_available (restaurant_id, is_available)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'order_item_addons' => "CREATE TABLE IF NOT EXISTS order_item_addons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_item_id INT NOT NULL,
            addon_id INT DEFAULT NULL,
            addon_name VARCHAR(255) NOT NULL,
            addon_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            quantity INT NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order_item (order_item_id)%FK%
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($tables as $table => $sql) {
        try {
            if ($conn->query("SHOW TABLES LIKE '$table'")->fetch()) continue;
            try { $conn->exec(str_replace('%FK%', ",
            FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE", $sql)); }
            catch (Throwable $e) { $conn->exec(str_replace('%FK%', '', $sql)); }   // without the foreign key if order_items can't take one
            error_log("posEnsureBillColumns: created $table");
        } catch (Throwable $e) { error_log("posEnsureBillColumns $table: " . $e->getMessage()); }
    }
    $cols = [
        ['order_items', 'addons', 'TEXT DEFAULT NULL'],
        ['kot_items', 'addons', 'TEXT DEFAULT NULL'],
        ['kot', 'order_id', 'INT(11) DEFAULT NULL'],
        ['order_items', 'variation_name', 'VARCHAR(100) DEFAULT NULL'],
        ['order_items', 'notes', 'TEXT DEFAULT NULL'],
        ['orders', 'customer_phone', 'VARCHAR(20) DEFAULT NULL'],
        ['orders', 'customer_email', 'VARCHAR(100) DEFAULT NULL'],
        ['orders', 'customer_address', 'TEXT DEFAULT NULL'],
        ['orders', 'coupon_code', 'VARCHAR(50) DEFAULT NULL'],
        ['orders', 'discount_amount', 'DECIMAL(10,2) DEFAULT 0.00'],
        ['orders', 'wallet_paid', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00'],
        ['orders', 'deleted_at', 'DATETIME DEFAULT NULL'],
    ];
    foreach ($cols as [$table, $col, $def]) {
        try {
            if (!$conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetch()) {
                $conn->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
                error_log("posEnsureBillColumns: added $table.$col");
            }
        } catch (Throwable $e) { error_log("posEnsureBillColumns $table.$col: " . $e->getMessage()); }
    }
}

/**
 * Offline POS bills (assets/js/pos-offline.js) are sent later with a unique pos_ref. A ref that was
 * already saved returns its first result instead of saving the bill again (sync can run twice).
 */
function posEnsureSyncLog($conn): void {
    static $done = false;
    if ($done || $conn->inTransaction()) return;
    $done = true;
    $conn->exec("CREATE TABLE IF NOT EXISTS pos_sync_log (
        pos_ref VARCHAR(40) NOT NULL PRIMARY KEY,
        restaurant_id VARCHAR(50) NOT NULL,
        order_id INT NULL,
        response MEDIUMTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_psl_rest (restaurant_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function handleCreateKOT($conn, $restaurant_id) {
    // a bill made offline: once only, at the time it was made
    $posRef = preg_match('/^[A-Za-z0-9-]{8,40}$/', (string)($_POST['pos_ref'] ?? '')) ? (string)$_POST['pos_ref'] : '';
    $offlineAt = null;
    if ($posRef !== '') {
        posEnsureSyncLog($conn);
        $seen = $conn->prepare('SELECT order_id, response FROM pos_sync_log WHERE pos_ref = ? AND restaurant_id = ?');
        $seen->execute([$posRef, $restaurant_id]);
        if ($row = $seen->fetch(PDO::FETCH_ASSOC)) {
            $prev = $row['response'] ? json_decode($row['response'], true) : null;
            if (!$prev) {
                $on = $conn->prepare('SELECT order_number FROM orders WHERE id = ?'); $on->execute([(int)$row['order_id']]);
                $prev = ['success' => true, 'order_id' => (int)$row['order_id'], 'order_number' => $on->fetchColumn() ?: null, 'kot_id' => null, 'kot_number' => null];
            }
            $prev['already_synced'] = true;
            echo json_encode($prev, JSON_UNESCAPED_UNICODE);
            return;
        }
        $ts = strtotime((string)($_POST['offline_at'] ?? ''));
        if ($ts && $ts <= time() + 120 && $ts >= time() - 7 * 86400) $offlineAt = date('Y-m-d H:i:s', $ts);
    }
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
    // What the Pay screen worked out: fully paid, or part paid with some due
    $posPaymentStatus = ($_POST['paymentStatus'] ?? '') === 'Partially Paid' ? 'Partially Paid' : 'Paid';
    $posPaidAmount = isset($_POST['paidAmount']) && is_numeric($_POST['paidAmount']) ? (float)$_POST['paidAmount'] : null;
    $couponCode = strtoupper(trim($_POST['couponCode'] ?? ''));

    // The customer picked in the POS (assets/js/pos-customer.js): their saved name / phone go on
    // the order, and they can use points and their wallet on a paid bill (config/pos_loyalty.php)
    $customerId = (int)($_POST['customerId'] ?? 0);
    $redeemPoints = max(0, (int)($_POST['redeemPoints'] ?? 0));
    $walletRequested = max(0, round((float)($_POST['walletAmount'] ?? 0), 2));
    $posCustomer = null;
    $isPaidUpFront = !empty($paymentMethod) && $paymentMethod !== 'Due';
    if ($customerId > 0) {
        require_once __DIR__ . '/../config/pos_loyalty.php';
        posEnsureSchema($conn);   // before the transaction: it may create tables
        $cst = $conn->prepare('SELECT * FROM customers WHERE id = ? AND restaurant_id = ?');
        $cst->execute([$customerId, $restaurant_id]);
        $posCustomer = $cst->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$posCustomer) {
            echo json_encode(['success' => false, 'message' => 'That customer was not found. Pick them again.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $customerName = (string)$posCustomer['customer_name'];
        $customerPhone = (string)$posCustomer['phone'];
        if ($customerEmail === '') $customerEmail = (string)($posCustomer['email'] ?? '');
    }
    if (($redeemPoints > 0 || $walletRequested > 0) && (!$posCustomer || !$isPaidUpFront)) {
        echo json_encode(['success' => false, 'message' => 'Points and wallet can be used only for a customer, on a paid bill.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Combos (Offers > Combos & Deals): [{id, quantity, picks}], priced here from the database
    $combosIn = json_decode((string)($_POST['combos'] ?? '[]'), true);
    $combosIn = is_array($combosIn) ? array_slice($combosIn, 0, 20) : [];
    if (!is_array($cartItems)) $cartItems = [];

    // Validation
    if (empty($cartItems) && empty($combosIn)) {
        echo json_encode(['success' => false, 'message' => 'Cart is empty or invalid'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $posCombos = [];      // [id, qty, name, unit, lines] on this bill
    $comboTotal = 0.0;
    $liveCombos = [];
    if ($combosIn) {
        require_once __DIR__ . '/../config/combo_helpers.php';
        foreach (getCombos($conn, (string)$restaurant_id, true) as $lc) $liveCombos[$lc['id']] = $lc;
        foreach ($combosIn as $ci) {
            $cq = max(0, min(50, (int)($ci['quantity'] ?? 0)));
            if (!$cq) continue;
            $v = comboVerify($liveCombos, (int)($ci['id'] ?? 0), is_array($ci['picks'] ?? null) ? $ci['picks'] : []);
            if (!$v['ok']) { echo json_encode(['success' => false, 'message' => $v['message']], JSON_UNESCAPED_UNICODE); return; }
            $posCombos[] = ['id' => (int)$ci['id'], 'qty' => $cq, 'name' => $v['name'], 'unit' => $v['unit_price'], 'lines' => $v['lines']];
            $comboTotal += $v['unit_price'] * $cq;
        }
    }

    $priceError = $cartItems ? verifyPosCartItemPrices($conn, $restaurant_id, $cartItems) : null;   // a bill of only combos has no dishes to check
    if ($priceError !== null) {
        echo json_encode(['success' => false, 'message' => $priceError], JSON_UNESCAPED_UNICODE);
        return;
    }
    // add-ons from the DB, and the subtotal must be what the cart adds up to
    $addonCheck = posVerifyAddons($conn, $restaurant_id, $cartItems, json_decode($_POST['globalAddons'] ?? '[]', true));
    $globalAddons = $addonCheck['global'];
    if (abs($subtotal - ($addonCheck['subtotal'] + $comboTotal)) > 0.01) {
        echo json_encode(['success' => false, 'message' => 'The bill total changed (a price or add-on was updated). Refresh the menu and try again.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Coupon discount — recomputed from the coupon's actual type/value in the
    // DB, never trusted from the client, same as the website's checkout
    // (main/api/process_website_order.php). Discount comes off the raw item
    // subtotal before tax.
    $discountAmount = 0;
    $coupon = null;
    if (!empty($couponCode)) {
        $couponStmt = $conn->prepare("SELECT * FROM coupons WHERE restaurant_id = ? AND coupon_code = ? AND is_active = 1 AND (valid_from IS NULL OR valid_from <= CURDATE()) AND (valid_until IS NULL OR valid_until >= CURDATE()) AND (max_uses = 0 OR current_uses < max_uses) LIMIT 1");
        $couponStmt->execute([$restaurant_id, $couponCode]);
        $coupon = $couponStmt->fetch(PDO::FETCH_ASSOC);
        if (!$coupon) {
            echo json_encode(['success' => false, 'message' => 'Invalid or expired coupon code'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (!empty($coupon['owner_phone'])) {
            require_once __DIR__ . '/../config/order_abuse_guard.php';
            $ownerOk = $customerPhone !== '' && (orderAbuseNormalizePhone($customerPhone) ?? $customerPhone) === (orderAbuseNormalizePhone((string)$coupon['owner_phone']) ?? $coupon['owner_phone']);
            if (!$ownerOk) {
                echo json_encode(['success' => false, 'message' => 'This code belongs to another customer. Pick the right customer first.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        }
        if ((float)$coupon['minimum_order_amount'] > 0 && $subtotal < (float)$coupon['minimum_order_amount']) {
            echo json_encode(['success' => false, 'message' => 'Minimum order of ' . number_format((float)$coupon['minimum_order_amount'], 2) . ' required for this coupon'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $discountAmount = $coupon['discount_type'] === 'percent'
            ? round($subtotal * (float)$coupon['discount_value'] / 100, 2)
            : (float)$coupon['discount_value'];
        if ($discountAmount > $subtotal) $discountAmount = $subtotal;
    }
    $loyaltyPoints = 0;
    $loyaltyDiscount = 0.0;
    if ($redeemPoints > 0) {
        $gs = getGrowthSettings($conn, $restaurant_id);
        if (empty($gs['loyalty_enabled']) || loyaltyProgramType($gs) !== 'points') {
            echo json_encode(['success' => false, 'message' => 'Points can’t be used: the points program is off.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $rate = (float)$gs['redeem_value_per_point'];
        $maxUseful = $rate > 0 ? (int)floor(max(0, $subtotal - $discountAmount) / $rate) : 0;
        $pts = min($redeemPoints, (int)$posCustomer['loyalty_points_balance'], $maxUseful);
        if ($pts !== $redeemPoints || $pts < (int)$gs['min_redeem_points']) {
            echo json_encode(['success' => false, 'message' => 'The points to use changed (balance ' . (int)$posCustomer['loyalty_points_balance'] . ', at least ' . (int)$gs['min_redeem_points'] . '). Pick the customer again.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $loyaltyPoints = $pts;
        $loyaltyDiscount = round($pts * $rate, 2);
    }
    // The cashier's manual discount (assets/js/pos-discount.js): taken off BEFORE tax, after the
    // customer's coupon and points. Unlike a coupon the amount is a staff action, so it is used as
    // sent — but bounded here so the bill can never go below zero, and it is written into the KOT
    // notes / activity log so the owner sees who gave what.
    $manualDiscountType = (($_POST['manualDiscountType'] ?? '') === 'percent') ? 'percent' : 'amount';
    $manualDiscountValue = max(0, round((float)($_POST['manualDiscountValue'] ?? 0), 2));
    $manualDiscount = max(0, round((float)($_POST['manualDiscount'] ?? 0), 2));
    $manualDiscount = min($manualDiscount, max(0, round($subtotal - $discountAmount - $loyaltyDiscount, 2)));
    $taxableAmount = max(0, round($subtotal - $discountAmount - $loyaltyDiscount - $manualDiscount, 2));

    // Server-side tax validation: the restaurant's bill rule (tax on top or inside the price, service charge),
    // the same one the POS screen uses (config/bill_rules.php)
    require_once __DIR__ . '/../config/bill_rules.php';
    $billRule = billRules($conn, $restaurant_id);
    $gstEnabled = $billRule['tax_on'];
    $taxPercent = $billRule['tax_percent'];
    $billCalc = billMath($taxableAmount, $billRule);
    if (abs($total - $billCalc['total']) > 0.01) {
        echo json_encode(['success' => false, 'message' => 'Total mismatch: total does not match subtotal + tax'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (abs($tax - $billCalc['tax']) > 0.01) {
        echo json_encode(['success' => false, 'message' => 'Tax mismatch: calculated tax does not match expected value'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $walletPaid = 0.0;
    $cashMethod = $paymentMethod;
    if ($walletRequested > 0) {
        $wset = getWalletSettings($conn, $restaurant_id);
        $walletPaid = round(min($walletRequested, loyaltyWalletBalance($conn, $restaurant_id, $customerPhone), $total), 2);
        if (!$wset['enabled'] || abs($walletPaid - $walletRequested) > 0.01) {
            echo json_encode(['success' => false, 'message' => 'The wallet balance changed. Pick the customer again.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($paymentMethod !== 'Wallet') $paymentMethod .= '+Wallet';
    }

    // What goes to the kitchen now. The POS "KOT" button sends only dishes not sent before
    // (kitchenItems); Pay without it sends the whole cart, as before. An empty list = no new ticket.
    $kitchenItems = $cartItems;
    if (isset($_POST['kitchenItems'])) {
        $kitchenItems = [];
        $byId = [];
        foreach ($cartItems as $ci) $byId[(int)$ci['id']][] = $ci;
        foreach ((array)json_decode((string)$_POST['kitchenItems'], true) as $k) {
            $mid = (int)($k['id'] ?? 0); $q = (int)($k['quantity'] ?? 0);
            if ($q <= 0 || empty($byId[$mid])) continue;   // only dishes that are on this bill
            $base = $byId[$mid][0];
            $kitchenItems[] = ['id' => $mid, 'name' => (string)($k['name'] ?? $base['name']), 'price' => (float)$base['price'], 'quantity' => $q,
                'addons' => array_values(array_filter((array)($k['addons'] ?? []), function ($a) { return (int)($a['id'] ?? 0) > 0; }))];
        }
    }
    // order add-ons go to the kitchen with the first ticket that carries them (kitchenAddons = 1)
    $addonsToKitchen = $globalAddons && (!isset($_POST['kitchenItems']) || !empty($_POST['kitchenAddons']));
    // combos for the kitchen: all of them (Pay), or the ones not sent before (KOT: kitchenCombos)
    $kitchenCombos = $posCombos;
    if (isset($_POST['kitchenItems'])) {
        $kitchenCombos = [];
        foreach ((array)json_decode((string)($_POST['kitchenCombos'] ?? '[]'), true) as $kc) {
            $q = max(0, min(50, (int)($kc['quantity'] ?? 0)));
            $v = $q ? comboVerify($liveCombos, (int)($kc['id'] ?? 0), is_array($kc['picks'] ?? null) ? $kc['picks'] : []) : ['ok' => false];
            if ($v['ok']) $kitchenCombos[] = ['id' => (int)$kc['id'], 'qty' => $q, 'name' => $v['name'], 'unit' => $v['unit_price'], 'lines' => $v['lines']];
        }
    }
    // Drinks made at the bar go on their own Bar Order Ticket (config/prep_stations.php): the
    // dishes and combo dishes split by where they're made; order add-ons stay on the KOT
    require_once __DIR__ . '/../config/prep_stations.php';
    $barIds = [];
    foreach ($kitchenItems as $ki) $barIds[] = (int)$ki['id'];
    foreach ($kitchenCombos as $kc) foreach ($kc['lines'] as $ln) $barIds[] = (int)$ln['menu_item_id'];
    $barMap = stationBarItems($conn, (string)$restaurant_id, $barIds);
    $tickets = ['kitchen' => ['items' => [], 'combos' => [], 'sub' => 0.0], 'bar' => ['items' => [], 'combos' => [], 'sub' => 0.0]];
    foreach ($kitchenItems as $ki) {
        $st = isset($barMap[(int)$ki['id']]) ? 'bar' : 'kitchen';
        $tickets[$st]['items'][] = $ki;
        $tickets[$st]['sub'] += (float)$ki['price'] * (int)$ki['quantity'];
    }
    foreach ($kitchenCombos as $kc) {
        $byStation = [];
        foreach ($kc['lines'] as $ln) $byStation[isset($barMap[(int)$ln['menu_item_id']]) ? 'bar' : 'kitchen'][] = $ln;
        foreach ($byStation as $st => $lines) $tickets[$st]['combos'][] = ['name' => $kc['name'], 'qty' => $kc['qty'], 'lines' => $lines];
        // the combo's price counts on the kitchen ticket when it has food, else on the bar's
        $tickets[isset($byStation['kitchen']) ? 'kitchen' : 'bar']['sub'] += $kc['unit'] * $kc['qty'];
    }
    $sendKot = $tickets['kitchen']['items'] || $tickets['kitchen']['combos'] || $addonsToKitchen;
    $sendBot = $tickets['bar']['items'] || $tickets['bar']['combos'];
    $sendTicket = $sendKot || $sendBot;

    // An open bill (sent to the kitchen with the KOT button, not paid yet) that this request adds to or settles
    $openOrderId = (int)($_POST['openOrderId'] ?? 0);

    if ($addonsToKitchen) posEnsureKotAddonLines($conn);
    posEnsureBillColumns($conn);
    if ($sendBot) stationEnsureSchema($conn);

    // Generate unique KOT / BOT numbers with collision check
    $kotNumber = $sendKot ? generateKOTNumber($conn, $restaurant_id) : null;
    $botNumber = $sendBot ? stationTicketNumber($conn, (string)$restaurant_id, 'bar') : null;

    // Begin transaction
    $conn->beginTransaction();

    try {
        $kotId = null;
        $botId = null;
        $openOrder = null;
        if ($openOrderId > 0) {
            $oo = $conn->prepare("SELECT id, order_number, order_status, notes FROM orders WHERE id = ? AND restaurant_id = ? AND payment_status = 'Pending'
                AND order_status NOT IN ('Cancelled','Rejected') AND deleted_at IS NULL AND notes LIKE '%[POS open bill]%' FOR UPDATE");
            $oo->execute([$openOrderId, $restaurant_id]);
            $openOrder = $oo->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$openOrder) {
                $conn->rollBack();
                echo json_encode(['success' => false, 'message' => 'This bill was already paid or cancelled in Orders. Clear the cart and start again.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        // Append payment method to notes if not Cash
        $kotNotes = $notes;
        if (!empty($paymentMethod) && $paymentMethod !== 'Cash' && $paymentMethod !== 'Due') { // 'Due' = nothing collected: no paid marker
            if (!empty($kotNotes)) {
                $kotNotes .= "\n[Payment: " . $paymentMethod . "]";
            } else {
                $kotNotes = "[Payment: " . $paymentMethod . "]";
            }
        }
        // The kot table has no dedicated coupon/discount columns, so the
        // applied coupon is recorded as a readable note instead (same place
        // the payment-method breakdown above already lives) rather than
        // adding a migration for this.
        if ($discountAmount > 0) {
            $couponNote = "[Coupon: " . $couponCode . " -" . number_format($discountAmount, 2) . "]";
            $kotNotes = !empty($kotNotes) ? ($kotNotes . "\n" . $couponNote) : $couponNote;
        }
        if ($loyaltyPoints > 0) $kotNotes = trim($kotNotes . "\n[Points: " . $loyaltyPoints . " -" . number_format($loyaltyDiscount, 2) . "]");
        if ($manualDiscount > 0) $kotNotes = trim($kotNotes . "\n[Discount" . ($manualDiscountType === 'percent' ? ' ' . rtrim(rtrim(number_format($manualDiscountValue, 2), '0'), '.') . '%' : '') . ": -" . number_format($manualDiscount, 2) . "]");
        if ($walletPaid > 0) $kotNotes = trim($kotNotes . "\n[Wallet: " . number_format($walletPaid, 2) . "]");
        // order add-ons (not tied to a dish): the kitchen reads them on the ticket's note
        $ticketNotes = $kotNotes;

        if ($sendTicket) {
            $checkKotCols = $conn->query("SHOW COLUMNS FROM kot LIKE 'customer_phone'");
            $kotHasCustomer = $checkKotCols->rowCount() > 0;
            // dish add-ons on the ticket, checked against the menu
            $addonLookup = $conn->prepare("SELECT id, addon_name, addon_price FROM meal_addons WHERE id = ? AND restaurant_id = ? AND is_available = 1");
            $itemStmt = $conn->prepare("INSERT INTO kot_items (kot_id, menu_item_id, item_name, quantity, unit_price, total_price, addons) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $cStmt = $conn->prepare("INSERT INTO kot_items (kot_id, menu_item_id, item_name, quantity, unit_price, total_price, notes) VALUES (?, ?, ?, ?, 0, 0, ?)");
            foreach (['kitchen' => $kotNumber, 'bar' => $botNumber] as $station => $ticketNumber) {
                if (!$ticketNumber) continue;
                $t = $tickets[$station];
                // the ticket's own amounts: just the dishes on it
                $kotCalc = billMath($t['sub'], $billRule);
                if ($kotHasCustomer) {
                    $stmt = $conn->prepare("INSERT INTO kot (restaurant_id, kot_number, table_id, order_type, customer_name, customer_phone, customer_email, customer_address, subtotal, tax, total, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$restaurant_id, $ticketNumber, $tableIdParam, $orderType, $customerName, $customerPhone, $customerEmail, $customerAddress, $t['sub'], $kotCalc['tax'], $kotCalc['total'], $ticketNotes]);
                } else {
                    $stmt = $conn->prepare("INSERT INTO kot (restaurant_id, kot_number, table_id, order_type, customer_name, subtotal, tax, total, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$restaurant_id, $ticketNumber, $tableIdParam, $orderType, $customerName, $t['sub'], $kotCalc['tax'], $kotCalc['total'], $ticketNotes]);
                }
                $ticketId = (int)$conn->lastInsertId();
                stationMarkTicket($conn, $ticketId, $station);
                if ($station === 'bar') $botId = $ticketId; else $kotId = $ticketId;

                foreach ($t['items'] as $item) {
                    if (!isset($item['id']) || !isset($item['name']) || !isset($item['price']) || !isset($item['quantity'])) {
                        error_log("Invalid cart item structure: " . json_encode($item));
                        throw new Exception("Invalid cart item structure. Missing required fields.");
                    }
                    $verifiedAddons = [];
                    foreach ((array)($item['addons'] ?? []) as $addon) {
                        $addonLookup->execute([(int)($addon['id'] ?? 0), $restaurant_id]);
                        if ($a = $addonLookup->fetch(PDO::FETCH_ASSOC)) $verifiedAddons[] = ['id' => (int)$a['id'], 'name' => $a['addon_name'], 'price' => (float)$a['addon_price']];
                    }
                    $itemStmt->execute([$ticketId, (int)$item['id'], (string)$item['name'], (int)$item['quantity'], (float)$item['price'], (float)($item['price'] * $item['quantity']),
                        $verifiedAddons ? json_encode($verifiedAddons, JSON_UNESCAPED_UNICODE) : null]);
                }
                // order add-ons: their own ticket lines (no dish), marked by addon_name; always the kitchen's
                if ($addonsToKitchen && $station === 'kitchen') {
                    $aStmt = $conn->prepare("INSERT INTO kot_items (kot_id, menu_item_id, item_name, addon_name, quantity, unit_price, total_price) VALUES (?, NULL, ?, ?, ?, ?, ?)");
                    foreach ($globalAddons as $g) $aStmt->execute([$ticketId, $g['name'], $g['name'], $g['quantity'], $g['price'], $g['price'] * $g['quantity']]);
                }
                // a combo's dishes, each noted with the combo (the price is on the bill)
                foreach ($t['combos'] as $kc) foreach ($kc['lines'] as $ln) {
                    $cStmt->execute([$ticketId, $ln['menu_item_id'], $ln['name'] . ($ln['variation'] ? ' (' . $ln['variation'] . ')' : ''), $ln['qty'] * $kc['qty'], mb_substr('Combo: ' . $kc['name'], 0, 150)]);
                }
            }
        }

        // Every POS bill is an order straight away, so it shows in Orders and Reports even if the
        // kitchen never advances the ticket:
        //  - Pay: a paid order ('Ready'), or the open bill it settles;
        //  - KOT / Due: an unpaid open bill ('Pending'), marked [POS open bill], that later KOTs add to
        //    and Pay settles. kot.order_id links each ticket, so the kitchen's Ready step never makes
        //    a second order (kot_operations.php) and moves this one along instead.
        $billPaymentStatus = $isPaidUpFront ? $posPaymentStatus : 'Pending';
        $billMethod = $isPaidUpFront ? $paymentMethod : 'Due';
        $kotTag = trim(($kotNumber ? "[KOT: " . $kotNumber . "]" : '') . ($botNumber ? " [BOT: " . $botNumber . "]" : ''));
        if ($openOrder) {
            $orderId = (int)$openOrder['id'];
            $orderNumber = $openOrder['order_number'];
            $orderNotes = trim(preg_replace('/\[POS open bill\]\s*/', '', (string)$openOrder['notes']) . "\n" . $kotNotes . "\n" . $kotTag);
            if (!$isPaidUpFront) $orderNotes = trim($orderNotes . "\n[POS open bill]");
            $conn->prepare("UPDATE orders SET table_id = ?, customer_name = ?, customer_phone = ?, customer_email = ?, customer_address = ?, order_type = ?, payment_method = ?, payment_status = ?,
                    subtotal = ?, tax = ?, total = ?, notes = ? WHERE id = ?")
                 ->execute([$tableIdParam, $customerName, $customerPhone, $customerEmail, $customerAddress, $orderType, $billMethod, $billPaymentStatus, $subtotal, $tax, $total, $orderNotes, $orderId]);
            // the bill's lines are whatever the cart is now
            $conn->prepare('DELETE a FROM order_item_addons a JOIN order_items i ON i.id = a.order_item_id WHERE i.order_id = ?')->execute([$orderId]);
            $conn->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);
        } else {
            $orderNumber = generateOrderNumber($conn, $restaurant_id);
            $orderNotes = trim($kotNotes . "\n" . $kotTag . ($isPaidUpFront ? '' : "\n[POS open bill]"));
            $orderStmt = $conn->prepare("INSERT INTO orders (restaurant_id, table_id, order_number, customer_name, customer_phone, customer_email, customer_address, order_type, payment_method, payment_status, order_status, subtotal, tax, total, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $orderStmt->execute([$restaurant_id, $tableIdParam, $orderNumber, $customerName, $customerPhone, $customerEmail, $customerAddress, $orderType, $billMethod, $billPaymentStatus, $isPaidUpFront ? 'Ready' : 'Pending', $subtotal, $tax, $total, $orderNotes]);
            $orderId = (int)$conn->lastInsertId();
        }

        billSaveExtras($conn, (int)$orderId, $billCalc['service_charge'], isset($_POST['buyerPan']) ? (string)$_POST['buyerPan'] : null, isset($_POST['buyerName']) ? (string)$_POST['buyerName'] : null);
        $orderItemIds = [];
        foreach ($cartItems as $i => $item) {
            $oiStmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
            $oiStmt->execute([$orderId, (int)$item['id'], (string)$item['name'], (int)$item['quantity'], (float)$item['price'], (float)($item['price'] * $item['quantity'])]);
            $orderItemIds[$i] = (int)$conn->lastInsertId();
        }
        posSaveOrderAddons($conn, $orderId, $orderItemIds, $cartItems, $globalAddons);
        // combos: one priced line, then each dish at 0 noted "Combo: <name>" (same as website orders)
        if ($posCombos) {
            $hStmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, item_name, variation_name, quantity, unit_price, total_price) VALUES (?, NULL, ?, 'Combo', ?, ?, ?)");
            $dStmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, item_name, variation_name, quantity, unit_price, total_price, notes) VALUES (?, ?, ?, ?, ?, 0, 0, ?)");
            foreach ($posCombos as $pc) {
                $hStmt->execute([$orderId, $pc['name'], $pc['qty'], $pc['unit'], round($pc['unit'] * $pc['qty'], 2)]);
                foreach ($pc['lines'] as $ln) $dStmt->execute([$orderId, $ln['menu_item_id'], $ln['name'], $ln['variation'] ?: null, $ln['qty'] * $pc['qty'], mb_substr('Combo: ' . $pc['name'], 0, 150)]);
            }
        }
        // combos sold, counted once: when the bill is paid
        if ($isPaidUpFront) {
            try {
                $conn->prepare('DELETE FROM combo_sales WHERE order_id = ?')->execute([$orderId]);
                if ($posCombos) {
                    $csSt = $conn->prepare('INSERT INTO combo_sales (restaurant_id, combo_id, order_id, qty, revenue) VALUES (?, ?, ?, ?, ?)');
                    foreach ($posCombos as $pc) $csSt->execute([$restaurant_id, $pc['id'], $orderId, $pc['qty'], round($pc['unit'] * $pc['qty'], 2)]);
                }
            } catch (PDOException $e) { if ($posCombos) throw $e; }   // no combo tables yet and no combos: nothing to count
        }

        if ($isPaidUpFront) {
            $payStmt = $conn->prepare("INSERT INTO payments (restaurant_id, order_id, amount, payment_method, payment_status) VALUES (?, ?, ?, ?, 'Success')");
            // Record the money actually received (part payments leave the rest due)
            $received = $total;
            if ($posPaymentStatus === 'Partially Paid' && $posPaidAmount !== null) {
                $received = max(0, min($posPaidAmount, $total));
            }
            if ($walletPaid > 0) $payStmt->execute([$restaurant_id, $orderId, $walletPaid, 'Wallet']);
            $restPaid = round($received - $walletPaid, 2);
            if ($restPaid > 0 || $walletPaid <= 0) $payStmt->execute([$restaurant_id, $orderId, max(0, $restPaid), $cashMethod]);

            // coupon + the cashier's manual discount both land in discount_amount (the
            // order's discount); coupon_code stays just the coupon so it can still be shown
            $orderDiscount = round($discountAmount + $manualDiscount, 2);
            if ($orderDiscount > 0 || $walletPaid > 0) {
                $conn->prepare('UPDATE orders SET coupon_code = ?, discount_amount = ?, wallet_paid = ? WHERE id = ?')
                     ->execute([$discountAmount > 0 ? $couponCode : null, $orderDiscount, $walletPaid, $orderId]);
            }
            if ($posCustomer) {
                if ($loyaltyPoints > 0) redeemLoyaltyPoints($conn, $restaurant_id, $customerId, $loyaltyPoints, $orderId);
                if ($walletPaid > 0) walletApply($conn, $restaurant_id, $customerPhone, -$walletPaid, 'spend', 'Paid at the counter, order ' . $orderNumber, $orderId);
                $conn->prepare('UPDATE customers SET total_visits = total_visits + 1, total_spent = total_spent + ?, last_visit_date = CURDATE() WHERE id = ?')
                     ->execute([$total, $customerId]);
            }
        }

        // Link the tickets (KOT and BOT) to their order
        foreach (array_filter([$kotId, $botId]) as $tid) {
            $conn->prepare("UPDATE kot SET order_id = ? WHERE id = ?")->execute([$orderId, $tid]);
        }
        // An open dine-in bill paid in full: its earlier tickets are done and the table frees up
        // (a ticket made by this Pay, for dishes added now, still goes to the kitchen)
        if ($openOrder && $isPaidUpFront && $posPaymentStatus === 'Paid' && $orderType === 'Dine-in' && $tableIdParam) {
            require_once __DIR__ . '/../config/table_busy.php';
            rgCloseOrderKots($conn, (string)$restaurant_id, (int)$orderId, array_filter([(int)$kotId, (int)$botId]));
        }
        if ($posRef !== '') {
            if ($offlineAt && !$openOrder) {   // a new bill made offline keeps its time
                $conn->prepare("UPDATE orders SET created_at = ?, notes = TRIM(CONCAT(IFNULL(notes, ''), '\n[Offline bill]')) WHERE id = ?")->execute([$offlineAt, $orderId]);
                foreach (array_filter([$kotId, $botId]) as $tid) $conn->prepare('UPDATE kot SET created_at = ? WHERE id = ?')->execute([$offlineAt, $tid]);
            }
            $conn->prepare('INSERT INTO pos_sync_log (pos_ref, restaurant_id, order_id) VALUES (?, ?, ?)')->execute([$posRef, $restaurant_id, $orderId]);
        }

        // Use the coupon inside the same transaction, only while it still has a use left: the check
        // above didn't lock the row, so another till or the website may have taken the last use since
        if ($coupon && $discountAmount > 0) {
            $useCpn = $conn->prepare("UPDATE coupons SET current_uses = current_uses + 1 WHERE id = ? AND (max_uses = 0 OR current_uses < max_uses)");
            $useCpn->execute([$coupon['id']]);
            if ($useCpn->rowCount() === 0) {
                $conn->rollBack();
                echo json_encode(['success' => false, 'message' => 'This coupon was just used up. Remove it and try again.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        // Commit transaction
        $conn->commit();
        try { invKick($conn, $restaurant_id); }   // stock, sold-out dishes, low-stock alerts
        catch (Throwable $e) { error_log('handleCreateKOT invKick: ' . $e->getMessage()); }

        $loyaltyOut = null;
        if ($posCustomer && $isPaidUpFront) {
            try {   // the bill is saved: loyalty trouble must not hide it
                $earned = $posPaymentStatus === 'Paid' ? posCreditOnPayment($conn, $restaurant_id, $orderId) : null;
                $cst->execute([$customerId, $restaurant_id]);
                $loyaltyOut = ['earned' => $earned, 'redeemed_points' => $loyaltyPoints, 'points_discount' => $loyaltyDiscount, 'wallet_paid' => $walletPaid,
                    'customer' => posCustomerSummary($conn, $restaurant_id, $cst->fetch(PDO::FETCH_ASSOC))];
            } catch (Throwable $e) { error_log('handleCreateKOT loyalty: ' . $e->getMessage()); }
        }

        $resp = [
            'success' => true,
            'message' => $isPaidUpFront
                ? ($kotId || $botId ? ($kotId && $botId ? 'KOT and BOT sent' : ($botId ? 'BOT sent to the bar' : 'KOT sent to kitchen')) . '. Payment recorded — order is in sales now.' : 'Payment recorded — order is in sales now.')
                : 'Sent to the ' . ($kotId && $botId ? 'kitchen and the bar' : ($botId ? 'bar' : 'kitchen')) . '. The bill stays open until it is paid.',
            'kot_number' => $kotNumber,
            'kot_id' => $kotId,
            // the bar's ticket for drinks (config/prep_stations.php); bar_item_ids = the dishes on it
            'bot_number' => $botNumber,
            'bot_id' => $botId,
            'bar_item_ids' => array_keys($barMap),
            'discount_amount' => round($discountAmount + $manualDiscount, 2),
            'manual_discount' => $manualDiscount,
            'loyalty' => $loyaltyOut,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'open_bill' => !$isPaidUpFront,
        ];
        // the bill number that counts up per restaurant (config/bill_numbers.php)
        require_once __DIR__ . '/../config/bill_numbers.php';
        $resp['bill_no'] = rgBillNo($conn, $restaurant_id, (int)$orderId);
        // Nepal: the number per fiscal year ("2083/84-0142") and the Bikram Sambat date for the printed bill
        $lbl = rgBillLabel($conn, $restaurant_id, (int)$orderId, $resp['bill_no']);
        $resp['bill_label'] = $lbl['label'];
        $resp['bill_date_bs'] = $lbl['date_bs'];
        $resp['service_charge'] = $billCalc['service_charge'];
        // owner's activity log: discounts given at the POS, and bills made offline
        if ($discountAmount > 0 || $manualDiscount > 0 || $loyaltyPoints > 0 || $posRef !== '') try {
            require_once __DIR__ . '/../config/activity_log.php';
            $bits = [];
            if ($discountAmount > 0) $bits[] = 'reward ' . $couponCode . ' (' . number_format($discountAmount, 2) . ' off)';
            if ($manualDiscount > 0) $bits[] = 'manual discount ' . ($manualDiscountType === 'percent' ? rtrim(rtrim(number_format($manualDiscountValue, 2), '0'), '.') . '%' : '') . ' (' . number_format($manualDiscount, 2) . ' off)';
            if ($loyaltyPoints > 0) $bits[] = $loyaltyPoints . ' points (' . number_format((float)($loyaltyDiscount ?? 0), 2) . ' off)';
            if ($bits) logActivity($conn, $restaurant_id, 'pos.discount', 'Bill ' . $orderNumber . ': ' . implode(', ', $bits), array_filter(['coupon' => $couponCode, 'coupon_off' => $discountAmount, 'manual_discount' => $manualDiscount, 'points' => $loyaltyPoints, 'total' => $total]), 'order', $orderId);
            if ($posRef !== '' && !$openOrder) logActivity($conn, $restaurant_id, 'pos.offline', 'Bill ' . $orderNumber . ' made offline' . ($offlineAt ? ' at ' . date('j M, g:i a', strtotime($offlineAt)) : '') . ', synced now', ['total' => $total], 'order', $orderId);
        } catch (Throwable $e) { error_log('handleCreateKOT activity log: ' . $e->getMessage()); }
        if ($posRef !== '') {
            try { $conn->prepare('UPDATE pos_sync_log SET response = ? WHERE pos_ref = ?')->execute([json_encode($resp, JSON_UNESCAPED_UNICODE), $posRef]); } catch (Throwable $e) {}
        }
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        // Rollback transaction on error (only while it's still open: after commit the bill is saved)
        if ($conn->inTransaction()) $conn->rollBack();
        error_log("Error in handleCreateKOT: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        if (ob_get_level()) {
            ob_clean();
        }
        echo json_encode(['success' => false, 'message' => 'Error creating KOT. Please try again.'], JSON_UNESCAPED_UNICODE);
    }
}

// ── Held orders (POS "Hold") ────────────────────────────────────────────────
// A held order is saved as an order row marked notes = 'Order on hold'. It is
// NOT a sale: reports skip it. Resume loads it back into the POS cart and
// retires the held row (soft delete), so paying for it can never count twice.
function heldOrderRow($conn, $restaurant_id, $orderId, $forUpdate = false) {
    $stmt = $conn->prepare("SELECT id, order_number, table_id, total FROM orders
        WHERE id = ? AND restaurant_id = ? AND notes = 'Order on hold'
          AND order_status NOT IN ('Cancelled','Rejected') AND deleted_at IS NULL" . ($forUpdate ? " FOR UPDATE" : ""));
    $stmt->execute([$orderId, $restaurant_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function handleListHeldOrders($conn, $restaurant_id) {
    require_once __DIR__ . '/../config/soft_delete_helpers.php';
    ensureOrderSoftDeleteColumns($conn);
    $stmt = $conn->prepare("SELECT o.id, o.order_number, o.table_id, t.table_number, o.total, o.created_at,
            (SELECT COALESCE(SUM(quantity), 0) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
        FROM orders o LEFT JOIN tables t ON t.id = o.table_id
        WHERE o.restaurant_id = ? AND o.notes = 'Order on hold'
          AND o.order_status NOT IN ('Cancelled','Rejected') AND o.deleted_at IS NULL
        ORDER BY o.created_at DESC LIMIT 50");
    $stmt->execute([$restaurant_id]);
    echo json_encode(['success' => true, 'orders' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
}

function handleRetireHeldOrder($conn, $restaurant_id, $resume) {
    require_once __DIR__ . '/../config/soft_delete_helpers.php';
    ensureOrderSoftDeleteColumns($conn);
    $orderId = (int)($_POST['orderId'] ?? 0);
    $conn->beginTransaction();
    $held = heldOrderRow($conn, $restaurant_id, $orderId, true);
    if (!$held) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => 'This held order was already resumed or discarded.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $items = [];
    if ($resume) {
        $st = $conn->prepare("SELECT oi.menu_item_id, oi.item_name, oi.variation_name, oi.quantity, oi.unit_price, oi.addons,
                (SELECT a.addon_id FROM order_item_addons a WHERE a.order_item_id = oi.id LIMIT 1) addon_id
            FROM order_items oi WHERE oi.order_id = ? ORDER BY oi.id");
        $st->execute([$orderId]);
        $items = array_map(function ($r) { $r['addons'] = $r['addons'] ? (json_decode($r['addons'], true) ?: []) : []; return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
    }
    $who = $_SESSION['username'] ?? ($_SESSION['staff_name'] ?? 'POS');
    $conn->prepare("UPDATE orders SET deleted_at = NOW(), deleted_by = ?, delete_reason = ? WHERE id = ?")
         ->execute([$who, $resume ? 'Held order resumed in POS' : 'Held order discarded in POS', $orderId]);
    $conn->commit();
    invKick($conn, $restaurant_id);   // stock, sold-out dishes, low-stock alerts
    // the held order's customer, so the POS can pick them again
    $custId = null;
    if ($resume) {
        $hc = $conn->prepare("SELECT c.id FROM orders o JOIN customers c ON c.restaurant_id = o.restaurant_id AND c.phone = o.customer_phone WHERE o.id = ? AND o.customer_phone <> '' LIMIT 1");
        $hc->execute([$orderId]);
        $custId = $hc->fetchColumn() ?: null;
    }
    echo json_encode(['success' => true, 'order_number' => $held['order_number'], 'table_id' => $held['table_id'], 'items' => $items, 'customer_id' => $custId ? (int)$custId : null], JSON_UNESCAPED_UNICODE);
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
    $addonCheck = posVerifyAddons($conn, $restaurant_id, $cartItems, json_decode($_POST['globalAddons'] ?? '[]', true));
    $globalAddons = $addonCheck['global'];
    if (abs($subtotal - $addonCheck['subtotal']) > 0.01) {
        echo json_encode(['success' => false, 'message' => 'The bill total changed (a price or add-on was updated). Refresh the menu and try again.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Server-side tax validation (config/bill_rules.php, as for a paid bill)
    require_once __DIR__ . '/../config/bill_rules.php';
    $holdCalc = billMath($subtotal, billRules($conn, $restaurant_id));
    if (abs($total - $holdCalc['total']) > 0.01) {
        echo json_encode(['success' => false, 'message' => 'Total mismatch: total does not match subtotal + tax'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (abs($tax - $holdCalc['tax']) > 0.01) {
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
    
    posEnsureBillColumns($conn);
    // Begin transaction
    $conn->beginTransaction();
    
    try {
        // Insert order with "Pending" payment status
        $orderType = empty($tableId) ? 'Takeaway' : 'Dine-in';
        // the customer picked in the POS stays with the held order (pos-customer.js)
        $holdCust = null;
        if ((int)($_POST['customerId'] ?? 0) > 0) {
            $hc = $conn->prepare('SELECT customer_name, phone, email FROM customers WHERE id = ? AND restaurant_id = ?');
            $hc->execute([(int)$_POST['customerId'], $restaurant_id]);
            $holdCust = $hc->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $stmt = $conn->prepare("INSERT INTO orders (restaurant_id, table_id, order_number, customer_name, customer_phone, customer_email, order_type, payment_method, payment_status, order_status, subtotal, tax, total, notes) VALUES (?, ?, ?, ?, ?, ?, ?, 'Cash', 'Pending', 'Pending', ?, ?, ?, 'Order on hold')");
        $stmt->execute([$restaurant_id, $tableId ?: null, $orderNumber, $holdCust['customer_name'] ?? '', $holdCust['phone'] ?? null, $holdCust['email'] ?? null, $orderType, $subtotal, $tax, $total]);
        
        $orderId = $conn->lastInsertId();
        billSaveExtras($conn, (int)$orderId, $holdCalc['service_charge']);
        
        // Insert order items
        $itemStmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, item_name, variation_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        $orderItemIds = [];
        foreach ($cartItems as $i => $item) {
            // Validate item structure
            if (!isset($item['id']) || !isset($item['name']) || !isset($item['price']) || !isset($item['quantity'])) {
                error_log("Invalid cart item structure: " . json_encode($item));
                throw new Exception("Invalid cart item structure. Missing required fields.");
            }
            
            $itemStmt->execute([
                $orderId,
                (int)$item['id'],
                (string)($item['originalName'] ?? $item['name']),
                ($item['variationName'] ?? ($item['variation_name'] ?? null)) ?: null,
                (int)$item['quantity'],
                (float)$item['price'],
                (float)($item['price'] * $item['quantity'])
            ]);
            $orderItemIds[$i] = (int)$conn->lastInsertId();
        }
        posSaveOrderAddons($conn, (int)$orderId, $orderItemIds, $cartItems, $globalAddons);
        
        // Commit transaction
        $conn->commit();
        try { invKick($conn, $restaurant_id); }   // stock, sold-out dishes, low-stock alerts
        catch (Throwable $e) { error_log('handleHoldOrder invKick: ' . $e->getMessage()); }
        
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

