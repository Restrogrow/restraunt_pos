<?php
// Suppress error display, log errors instead
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Debug log file for catching fatal errors
$debugLog = __DIR__ . '/../logs/kot_debug.log';
$logDir = dirname($debugLog);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}

// Global error handler â€” ensures any PHP error returns valid JSON
register_shutdown_function(function() use ($debugLog) {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        $msg = "[FATAL] {$error['message']} in {$error['file']}:{$error['line']}";
        @file_put_contents($debugLog, date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL, FILE_APPEND);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(500);
        }
        echo json_encode(['success' => false, 'message' => 'Internal server error: ' . $error['message']], JSON_UNESCAPED_UNICODE);
    }
});
set_exception_handler(function($e) use ($debugLog) {
    $msg = "[EXCEPTION] {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}";
    @file_put_contents($debugLog, date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL, FILE_APPEND);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
    }
    echo json_encode(['success' => false, 'message' => 'Internal server error'], JSON_UNESCAPED_UNICODE);
    exit();
});
set_error_handler(function($severity, $message, $file, $line) use ($debugLog) {
    @file_put_contents($debugLog, date('Y-m-d H:i:s') . " [HANDLED_ERROR] [$severity] $message in $file:$line" . PHP_EOL, FILE_APPEND);
    error_log("Error [$severity] $message in $file:$line");
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Internal server error'], JSON_UNESCAPED_UNICODE);
        exit();
    }
});

// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Ensure no output before headers
if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json; charset=UTF-8');

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';

// Require permission to view KOT
requirePermission(PERMISSION_VIEW_KOT);

// Include Order State Machine
if (file_exists(__DIR__ . '/../config/order_state_machine.php')) {
    require_once __DIR__ . '/../config/order_state_machine.php';
}

// Include database connection
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection file not found'], JSON_UNESCAPED_UNICODE);
    exit();
}

if (!function_exists('ensureKotSchema')) {
    /**
     * Self-healing schema fix for `kot` — same pattern as growth_helpers.php's
     * ensureGrowthSchema(). The kot_status ENUM only ever allowed
     * Pending/Preparing/Ready/Completed even though the app writes
     * 'Cancelled' to it (see handleUpdateKOTStatus() below) — every cancel
     * attempt threw a PDOException and returned a 500. MODIFY on an ENUM is
     * additive-only here, so existing rows' values stay valid.
     */
    function ensureKotSchema(PDO $conn): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $conn->exec("ALTER TABLE kot MODIFY COLUMN kot_status ENUM('Pending','Preparing','Ready','Completed','Cancelled') DEFAULT 'Pending'");
        } catch (PDOException $e) {
            error_log('ensureKotSchema (kot_status ENUM): ' . $e->getMessage());
        }

        // kot.order_id — there was previously no real link between a KOT and
        // the order eventually created from it; every "does an order already
        // exist for this KOT" check had to guess using table_id + total +
        // a created_at time window, which could cross-match two different
        // tables that happened to order an identical total close together,
        // and gave order cancellation nothing reliable to cascade to (see
        // handleUpdateKOTStatus() / handleCompleteKOT() below).
        try {
            $conn->query("SELECT order_id FROM kot LIMIT 1");
        } catch (PDOException $e) {
            try {
                $conn->exec("ALTER TABLE kot ADD COLUMN order_id INT DEFAULT NULL, ADD INDEX idx_order_id (order_id)");
            } catch (PDOException $e2) {
                error_log('ensureKotSchema (order_id): ' . $e2->getMessage());
            }
        }
    }
}

if (!function_exists('verifyKotCartItemPrices')) {
    /**
     * Verifies every cart item's price against menu_items/menu_item_variations
     * before it's trusted for a counter/KOT order — mirrors
     * process_website_order.php's server-side price verification and
     * pos_operations.php's verifyPosCartItemPrices(). Without this, $item['price']
     * from the POST body was inserted into kot_items unchecked, so a modified
     * request (or a page CSRFing a logged-in staff session) could under-ring
     * every item.
     *
     * @return string|null An error message if any item fails verification, or
     *                      null if every item is valid.
     */
    function verifyKotCartItemPrices($conn, $restaurant_id, array $cartItems): ?string {
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
                error_log("KOT price mismatch for menu item $mid (restaurant $restaurant_id): client sent " . ($ci['price'] ?? 'null') . ", expected $expectedPrice");
                return 'Item price mismatch — please refresh the menu and try again';
            }
        }

        return null;
    }
}

if (!function_exists('handleCreateKOT')) {
function handleCreateKOT() {
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
    $restaurant_id = $_SESSION['restaurant_id'];
    $table_id = $_POST['tableId'] ?? null;
    $order_type = $_POST['orderType'] ?? 'Dine-in';
    $customer_name = $_POST['customerName'] ?? '';
    $cart_items = json_decode($_POST['cartItems'], true);
    $subtotal = floatval($_POST['subtotal']);
    $tax = floatval($_POST['tax']);
    $total = floatval($_POST['total']);
    $notes = $_POST['notes'] ?? '';
    
    if (empty($cart_items)) {
        echo json_encode(['success' => false, 'message' => 'No items in cart'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $priceError = verifyKotCartItemPrices($conn, $restaurant_id, $cart_items);
    if ($priceError !== null) {
        echo json_encode(['success' => false, 'message' => $priceError], JSON_UNESCAPED_UNICODE);
        return;
    }

    $kot_number = generateKOTNumber($conn, $restaurant_id);
    
    // Get customer details if provided
    $customer_phone = $_POST['customerPhone'] ?? '';
    $customer_email = $_POST['customerEmail'] ?? '';
    $customer_address = $_POST['customerAddress'] ?? '';
    
    
    try {
        $conn->beginTransaction();
        
        // Check if customer columns exist
        $checkKotCols = $conn->query("SHOW COLUMNS FROM kot LIKE 'customer_phone'");
        if ($checkKotCols->rowCount() > 0) {
            // Insert KOT with customer details
            $kot_sql = "INSERT INTO kot (restaurant_id, kot_number, table_id, order_type, customer_name, customer_phone, customer_email, customer_address, subtotal, tax, total, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $kot_stmt = $conn->prepare($kot_sql);
            $kot_stmt->execute([$restaurant_id, $kot_number, $table_id, $order_type, $customer_name, $customer_phone, $customer_email, $customer_address, $subtotal, $tax, $total, $notes]);
        } else {
            // Insert KOT without customer details (fallback)
            $kot_sql = "INSERT INTO kot (restaurant_id, kot_number, table_id, order_type, customer_name, subtotal, tax, total, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $kot_stmt = $conn->prepare($kot_sql);
            $kot_stmt->execute([$restaurant_id, $kot_number, $table_id, $order_type, $customer_name, $subtotal, $tax, $total, $notes]);
        }
        $kot_id = $conn->lastInsertId();
        
        // Insert KOT items
        foreach ($cart_items as $item) {
            // Check if addons column exists in kot_items
            $kotItemsHasAddons = false;
            try {
                $checkAddonsCol = $conn->query("SHOW COLUMNS FROM kot_items LIKE 'addons'");
                $kotItemsHasAddons = $checkAddonsCol && $checkAddonsCol->rowCount() > 0;
            } catch (Exception $e) {}
            
            if ($kotItemsHasAddons) {
                // --- Server-side addon price verification ---
                // Look up actual addon prices from DB; ignore client-provided prices
                $verifiedAddons = [];
                if (!empty($item['addons']) && is_array($item['addons'])) {
                    foreach ($item['addons'] as $addon) {
                        $addonId = (int)($addon['id'] ?? 0);
                        if ($addonId <= 0) continue; // skip client-invented addons without IDs
                        $addonStmt = $conn->prepare("SELECT id, addon_name, addon_price, is_available FROM meal_addons WHERE id = ? AND restaurant_id = ? AND is_available = 1");
                        $addonStmt->execute([$addonId, $restaurant_id]);
                        $dbAddon = $addonStmt->fetch(PDO::FETCH_ASSOC);
                        if ($dbAddon) {
                            $verifiedAddons[] = [
                                'id'    => (int)$dbAddon['id'],
                                'name'  => $dbAddon['addon_name'],
                                'price' => (float)$dbAddon['addon_price'],
                            ];
                        } else {
                            error_log("KOT addon validation: addon id={$addonId} not found or unavailable for restaurant {$restaurant_id}");
                        }
                    }
                }
                $addonsJson = !empty($verifiedAddons) ? json_encode($verifiedAddons, JSON_UNESCAPED_UNICODE) : null;
                // --- End addon price verification ---
                $item_sql = "INSERT INTO kot_items (kot_id, menu_item_id, item_name, quantity, unit_price, total_price, addons) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $item_stmt = $conn->prepare($item_sql);
                $item_stmt->execute([$kot_id, $item['id'], $item['name'], $item['quantity'], $item['price'], $item['price'] * $item['quantity'], $addonsJson]);
            } else {
                $item_sql = "INSERT INTO kot_items (kot_id, menu_item_id, item_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)";
                $item_stmt = $conn->prepare($item_sql);
                $item_stmt->execute([$kot_id, $item['id'], $item['name'], $item['quantity'], $item['price'], $item['price'] * $item['quantity']]);
            }
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'KOT created successfully',
            'kot_id' => $kot_id,
            'kot_number' => $kot_number
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}
}

if (!function_exists('handleUpdateKOTStatus')) {
function handleUpdateKOTStatus() {
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
    ensureKotSchema($conn);

    $kot_id = intval($_POST['kotId']);
    $status = $_POST['status'];
    $restaurant_id = $_SESSION['restaurant_id'] ?? $_GET['restaurant_id'] ?? null;

    if (!$restaurant_id) {
        // Check session for staff_id
        if (isset($_SESSION['staff_id'])) {
            // Get restaurant_id from staff
            $staff_sql = "SELECT restaurant_id FROM staff WHERE id = ?";
            $staff_stmt = $conn->prepare($staff_sql);
            $staff_stmt->execute([$_SESSION['staff_id']]);
            $staff = $staff_stmt->fetch();
            if ($staff) {
                $restaurant_id = $staff['restaurant_id'];
            }
        }
    }

    if (!$restaurant_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Restaurant ID not found'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $valid_statuses = ['Pending', 'Preparing', 'Ready', 'Completed', 'Cancelled'];
    if (!in_array($status, $valid_statuses)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid status'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    try {
        $conn->beginTransaction();
        
        // Lock the KOT row to prevent concurrent updates (SELECT FOR UPDATE)
        // This ensures only one transaction can process this KOT at a time
        $lock_sql = "SELECT * FROM kot WHERE id = ? AND restaurant_id = ? FOR UPDATE";
        $lock_stmt = $conn->prepare($lock_sql);
        $lock_stmt->execute([$kot_id, $restaurant_id]);
        $kot = $lock_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Check if customer columns exist in kot table
        $hasCustomerCols = false;
        try {
            $checkKotCols = $conn->query("SHOW COLUMNS FROM kot LIKE 'customer_phone'");
            $hasCustomerCols = $checkKotCols && $checkKotCols->rowCount() > 0;
        } catch (Exception $e) {
            // Columns don't exist, continue without them
            $hasCustomerCols = false;
        }
        
        if (!$kot) {
            $conn->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'KOT not found'], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // Check if KOT status is already being processed (prevent duplicate processing)
        // If status is already "Ready" or "Completed", don't process again
        if ($kot['kot_status'] === 'Ready' || $kot['kot_status'] === 'Completed') {
            if (!empty($kot['order_id'])) {
                // Order already exists, just update status if needed
                $conn->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'KOT status updated. Order already exists.',
                    'order_id' => $kot['order_id']
                ], JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        // KOT status transitions follow the same shape as orders (no jumping
        // back to an earlier stage, nothing leaves a terminal state) — this
        // used to accept any of the 5 valid strings regardless of the
        // current status, so a request could reopen an already-Completed
        // ticket back to Preparing.
        $kotTransitions = [
            'Pending'   => ['Preparing', 'Cancelled'],
            'Preparing' => ['Ready', 'Cancelled'],
            'Ready'     => ['Completed', 'Cancelled'],
            'Completed' => [],
            'Cancelled' => [],
        ];
        if ($kot['kot_status'] !== $status && !in_array($status, $kotTransitions[$kot['kot_status']] ?? [], true)) {
            $conn->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Cannot change KOT from {$kot['kot_status']} to {$status}"], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Update KOT status
        $sql = "UPDATE kot SET kot_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND restaurant_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$status, $kot_id, $restaurant_id]);

        // If status is "Ready", create order from KOT
        if ($status === 'Ready') {
            if (!$kot['order_id']) {
                $order_number = generateOrderNumber($conn, $restaurant_id);
                
                // Extract payment method from KOT notes if available
                $kotPaymentMethod = 'Cash';
                if (!empty($kot['notes']) && preg_match('/\[Payment:\s*([^\]]+)\]/', $kot['notes'], $pm)) {
                    $kotPaymentMethod = trim($pm[1]);
                }
                
                // Re-verify tax against current enable_gst/tax_percent setting
                $gstStmt2 = $conn->prepare("SELECT enable_gst, tax_percent FROM users WHERE restaurant_id = ? LIMIT 1");
                $gstStmt2->execute([$restaurant_id]);
                $gstRow2 = $gstStmt2->fetch(PDO::FETCH_ASSOC);
                $gstEnabled2 = $gstRow2 ? (bool)$gstRow2['enable_gst'] : true;
                $taxPercent2 = ($gstRow2 && isset($gstRow2['tax_percent']) && $gstRow2['tax_percent'] !== null) ? (float)$gstRow2['tax_percent'] : 5.00;
                $recalculatedTax = $gstEnabled2 ? round((float)$kot['subtotal'] * ($taxPercent2 / 100), 2) : 0;
                if (abs((float)$kot['tax'] - $recalculatedTax) > 0.01) {
                    $kot['tax'] = $recalculatedTax;
                    $kot['total'] = round((float)$kot['subtotal'] + (float)$kot['tax'], 2);
                }
                
                // Create order from KOT - include KOT number in notes for tracking
                $order_notes = trim($kot['notes'] ?? '');
                if (!empty($order_notes)) {
                    $order_notes .= "\n[KOT: " . $kot['kot_number'] . "]";
                } else {
                    $order_notes = "[KOT: " . $kot['kot_number'] . "]";
                }
                
                // Check if customer columns exist in orders table
                $hasOrderCustomerCols = false;
                try {
                    $checkOrderCols = $conn->query("SHOW COLUMNS FROM orders LIKE 'customer_phone'");
                    $hasOrderCustomerCols = $checkOrderCols && $checkOrderCols->rowCount() > 0;
                } catch (Exception $e) {
                    $hasOrderCustomerCols = false;
                }
                
                if ($hasOrderCustomerCols && $hasCustomerCols) {
                    $order_sql = "INSERT INTO orders (restaurant_id, table_id, order_number, customer_name, customer_phone, customer_email, customer_address, order_type, payment_method, payment_status, order_status, subtotal, tax, total, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Paid', 'Ready', ?, ?, ?, ?)";
                    $order_stmt = $conn->prepare($order_sql);
                    $order_stmt->execute([
                        $restaurant_id, 
                        $kot['table_id'], 
                        $order_number, 
                        $kot['customer_name'] ?? '', 
                        $kot['customer_phone'] ?? '',
                        $kot['customer_email'] ?? '',
                        $kot['customer_address'] ?? '',
                        $kot['order_type'], 
                        $kotPaymentMethod,
                        $kot['subtotal'], 
                        $kot['tax'], 
                        $kot['total'], 
                        $order_notes
                    ]);
                } else {
                    $order_sql = "INSERT INTO orders (restaurant_id, table_id, order_number, customer_name, order_type, payment_method, payment_status, order_status, subtotal, tax, total, notes) VALUES (?, ?, ?, ?, ?, ?, 'Paid', 'Ready', ?, ?, ?, ?)";
                    $order_stmt = $conn->prepare($order_sql);
                    $order_stmt->execute([$restaurant_id, $kot['table_id'], $order_number, $kot['customer_name'] ?? '', $kot['order_type'], $kotPaymentMethod, $kot['subtotal'], $kot['tax'], $kot['total'], $order_notes]);
                }
                $order_id = $conn->lastInsertId();

                // Real link between this KOT and the order it produced —
                // everything that used to guess via table_id+total+time-window
                // (order cancellation cascade, duplicate-order checks above)
                // can now just look this up directly.
                $conn->prepare("UPDATE kot SET order_id = ? WHERE id = ?")->execute([$order_id, $kot_id]);

                // Get KOT items and create order items
                $items_sql = "SELECT * FROM kot_items WHERE kot_id = ?";
                $items_stmt = $conn->prepare($items_sql);
                $items_stmt->execute([$kot_id]);
                $items = $items_stmt->fetchAll();

                foreach ($items as $item) {
                    $order_item_sql = "INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)";
                    $order_item_stmt = $conn->prepare($order_item_sql);
                    $order_item_stmt->execute([$order_id, $item['menu_item_id'], $item['item_name'], $item['quantity'], $item['unit_price'], $item['total_price']]);
                }

                // Record payment
                $payment_sql = "INSERT INTO payments (restaurant_id, order_id, amount, payment_method, payment_status) VALUES (?, ?, ?, ?, 'Success')";
                $payment_stmt = $conn->prepare($payment_sql);
                $payment_stmt->execute([$restaurant_id, $order_id, $kot['total'], $kotPaymentMethod]);
                
                $conn->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'KOT marked as Ready and order created successfully',
                    'order_id' => $order_id,
                    'order_number' => $order_number
                ], JSON_UNESCAPED_UNICODE);
                return;
            }
        }
        
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'KOT status updated successfully'], JSON_UNESCAPED_UNICODE);
        
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("PDO Error in handleUpdateKOTStatus: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again later.'], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Error in handleUpdateKOTStatus: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error updating KOT status. Please try again.'], JSON_UNESCAPED_UNICODE);
    }
}
}

if (!function_exists('handleCompleteKOT')) {
function handleCompleteKOT() {
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
    $kot_id = intval($_POST['kotId']);
    $restaurant_id = $_SESSION['restaurant_id'] ?? $_GET['restaurant_id'] ?? null;
    
    if (!$restaurant_id) {
        // Check session for staff_id
        if (isset($_SESSION['staff_id'])) {
            // Get restaurant_id from staff
            $staff_sql = "SELECT restaurant_id FROM staff WHERE id = ?";
            $staff_stmt = $conn->prepare($staff_sql);
            $staff_stmt->execute([$_SESSION['staff_id']]);
            $staff = $staff_stmt->fetch();
            if ($staff) {
                $restaurant_id = $staff['restaurant_id'];
            }
        }
    }
    
    if (!$restaurant_id) {
        echo json_encode(['success' => false, 'message' => 'Restaurant ID not found']);
        return;
    }
    
    try {
        $conn->beginTransaction();
        
        // Get KOT details
        $kot_sql = "SELECT * FROM kot WHERE id = ? AND restaurant_id = ?";
        $kot_stmt = $conn->prepare($kot_sql);
        $kot_stmt->execute([$kot_id, $restaurant_id]);
        $kot = $kot_stmt->fetch();
        
        if (!$kot) {
            throw new Exception('KOT not found');
        }
        
        // If order already exists, update its status to "Ready" and mark KOT as completed
        if (!empty($kot['order_id'])) {
            $order_id = $kot['order_id'];

            // Use state machine for atomic order status update
            $stateResult = validateAndUpdateOrderStatus($conn, (int)$order_id, 'Ready');
            if (!$stateResult['success']) {
                // Order might already be Ready or further along — that's OK, just log
                error_log('handleCompleteKOT: order status update skipped - ' . $stateResult['message']);
            }

            // Update KOT status to completed
            $update_kot_sql = "UPDATE kot SET kot_status = 'Completed', updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $update_kot_stmt = $conn->prepare($update_kot_sql);
            $update_kot_stmt->execute([$kot_id]);

            $conn->commit();

            if ($stateResult['success']) {
                require_once __DIR__ . '/../config/push_notification.php';
                notifyWaitersOrderReady($conn, $restaurant_id, (int)$order_id);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Order served and KOT completed successfully',
                'order_id' => $order_id
            ]);
            return;
        }
        
        // No linked order yet — create one now (fallback case; the order
        // should normally already exist from when status was set to "Ready"
        // via handleUpdateKOTStatus()). Only valid when the KOT is actually
        // Ready: completing a KOT that's still Pending/Preparing used to
        // silently mark the ticket Completed with no order ever created —
        // reject instead, so the caller finds out rather than losing the order.
        if ($kot['kot_status'] === 'Ready') {
                $order_number = generateOrderNumber($conn, $restaurant_id);
                
                // Extract payment method from KOT notes if available
                $kotPaymentMethod = 'Cash';
                if (!empty($kot['notes']) && preg_match('/\[Payment:\s*([^\]]+)\]/', $kot['notes'], $pm)) {
                    $kotPaymentMethod = trim($pm[1]);
                }
                
                // Create order from KOT - include KOT number in notes for tracking
                $order_notes = trim($kot['notes'] ?? '');
                if (!empty($order_notes)) {
                    $order_notes .= "\n[KOT: " . $kot['kot_number'] . "]";
                } else {
                    $order_notes = "[KOT: " . $kot['kot_number'] . "]";
                }
                
                // Re-verify tax against current enable_gst/tax_percent setting
                $gstStmt3 = $conn->prepare("SELECT enable_gst, tax_percent FROM users WHERE restaurant_id = ? LIMIT 1");
                $gstStmt3->execute([$restaurant_id]);
                $gstRow3 = $gstStmt3->fetch(PDO::FETCH_ASSOC);
                $gstEnabled3 = $gstRow3 ? (bool)$gstRow3['enable_gst'] : true;
                $taxPercent3 = ($gstRow3 && isset($gstRow3['tax_percent']) && $gstRow3['tax_percent'] !== null) ? (float)$gstRow3['tax_percent'] : 5.00;
                $recalculatedTax2 = $gstEnabled3 ? round((float)$kot['subtotal'] * ($taxPercent3 / 100), 2) : 0;
                if (abs((float)$kot['tax'] - $recalculatedTax2) > 0.01) {
                    $kot['tax'] = $recalculatedTax2;
                    $kot['total'] = round((float)$kot['subtotal'] + (float)$kot['tax'], 2);
                }
                
                // Create order from KOT with status "Ready" so waiters can deliver it
                // Check if customer columns exist in kot table
                $kotHasCustomerCols = false;
                try {
                    $checkKotColsHere = $conn->query("SHOW COLUMNS FROM kot LIKE 'customer_phone'");
                    $kotHasCustomerCols = $checkKotColsHere && $checkKotColsHere->rowCount() > 0;
                } catch (Exception $e) {
                    $kotHasCustomerCols = false;
                }
                // Check if customer columns exist in orders table
                $hasOrderCustomerCols = false;
                try {
                    $checkOrderCols = $conn->query("SHOW COLUMNS FROM orders LIKE 'customer_phone'");
                    $hasOrderCustomerCols = $checkOrderCols && $checkOrderCols->rowCount() > 0;
                } catch (Exception $e) {
                    $hasOrderCustomerCols = false;
                }
                
                if ($hasOrderCustomerCols && $kotHasCustomerCols) {
                    $order_sql = "INSERT INTO orders (restaurant_id, table_id, order_number, customer_name, customer_phone, customer_email, customer_address, order_type, payment_method, payment_status, order_status, subtotal, tax, total, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Paid', 'Ready', ?, ?, ?, ?)";
                    $order_stmt = $conn->prepare($order_sql);
                    $order_stmt->execute([
                        $restaurant_id, 
                        $kot['table_id'], 
                        $order_number, 
                        $kot['customer_name'] ?? '', 
                        $kot['customer_phone'] ?? '',
                        $kot['customer_email'] ?? '',
                        $kot['customer_address'] ?? '',
                        $kot['order_type'], 
                        $kotPaymentMethod,
                        $kot['subtotal'], 
                        $kot['tax'], 
                        $kot['total'], 
                        $order_notes
                    ]);
                } else {
                    $order_sql = "INSERT INTO orders (restaurant_id, table_id, order_number, customer_name, order_type, payment_method, payment_status, order_status, subtotal, tax, total, notes) VALUES (?, ?, ?, ?, ?, ?, 'Paid', 'Ready', ?, ?, ?, ?)";
                    $order_stmt = $conn->prepare($order_sql);
                    $order_stmt->execute([$restaurant_id, $kot['table_id'], $order_number, $kot['customer_name'] ?? '', $kot['order_type'], $kotPaymentMethod, $kot['subtotal'], $kot['tax'], $kot['total'], $order_notes]);
                }
                $order_id = $conn->lastInsertId();

                // Real link between this KOT and the order it produced —
                // everything that used to guess via table_id+total+time-window
                // (order cancellation cascade, duplicate-order checks above)
                // can now just look this up directly.
                $conn->prepare("UPDATE kot SET order_id = ? WHERE id = ?")->execute([$order_id, $kot_id]);

                // Get KOT items and create order items
                $items_sql = "SELECT * FROM kot_items WHERE kot_id = ?";
                $items_stmt = $conn->prepare($items_sql);
                $items_stmt->execute([$kot_id]);
                $items = $items_stmt->fetchAll();

                foreach ($items as $item) {
                    $order_item_sql = "INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)";
                    $order_item_stmt = $conn->prepare($order_item_sql);
                    $order_item_stmt->execute([$order_id, $item['menu_item_id'], $item['item_name'], $item['quantity'], $item['unit_price'], $item['total_price']]);
                }

                // Record payment
                $payment_sql = "INSERT INTO payments (restaurant_id, order_id, amount, payment_method, payment_status) VALUES (?, ?, ?, ?, 'Success')";
                $payment_stmt = $conn->prepare($payment_sql);
                $payment_stmt->execute([$restaurant_id, $order_id, $kot['total'], $kotPaymentMethod]);
        } else {
            $conn->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Cannot complete KOT — it is {$kot['kot_status']}, not Ready"], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Update KOT status to completed
        $update_kot_sql = "UPDATE kot SET kot_status = 'Completed', updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        $update_kot_stmt = $conn->prepare($update_kot_sql);
        $update_kot_stmt->execute([$kot_id]);
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Order served and KOT completed successfully'
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}
}

/**
 * Generate unique KOT number with collision check
 * 
 * @param PDO $conn Database connection
 * @param string $restaurant_id Restaurant ID
 * @return string Unique KOT number
 */
if (!function_exists('generateKOTNumber')) {
function generateKOTNumber($conn = null, $restaurant_id = null) {
    global $pdo;
    
    // Use provided connection or global
    $db = $conn ?? $pdo;
    
    // If no connection or restaurant_id, generate without check (fallback)
    if (!$db || !$restaurant_id) {
        return 'KOT-' . date('Ymd') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
    }
    
    // Generate unique number with collision check
    $maxAttempts = 100;
    $attempt = 0;
    
    do {
        $kotNumber = 'KOT-' . date('Ymd') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        
        // Check if number already exists
        try {
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM kot WHERE kot_number = ? AND restaurant_id = ?");
            $checkStmt->execute([$kotNumber, $restaurant_id]);
            $exists = $checkStmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            // If query fails, return generated number (fallback)
            error_log("Error checking KOT number uniqueness: " . $e->getMessage());
            return $kotNumber;
        }
        
        $attempt++;
        
        // Safety check to prevent infinite loop
        if ($attempt >= $maxAttempts) {
            // Add timestamp to make it unique if we can't find a unique number
            $kotNumber = 'KOT-' . date('YmdHis') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            break;
        }
    } while ($exists);
    
    return $kotNumber;
}
}

/**
 * Generate unique Order number with collision check
 * 
 * @param PDO $conn Database connection
 * @param string $restaurant_id Restaurant ID
 * @return string Unique Order number
 */
if (!function_exists('generateOrderNumber')) {
function generateOrderNumber($conn = null, $restaurant_id = null) {
    global $pdo;
    
    // Use provided connection or global
    $db = $conn ?? $pdo;
    
    // If no connection or restaurant_id, generate without check (fallback)
    if (!$db || !$restaurant_id) {
        return 'ORD-' . date('Ymd') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
    }
    
    // Generate unique number with collision check
    $maxAttempts = 100;
    $attempt = 0;
    
    do {
        $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        
        // Check if number already exists
        try {
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE order_number = ? AND restaurant_id = ?");
            $checkStmt->execute([$orderNumber, $restaurant_id]);
            $exists = $checkStmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            // If query fails, return generated number (fallback)
            error_log("Error checking Order number uniqueness: " . $e->getMessage());
            return $orderNumber;
        }
        
        $attempt++;
        
        // Safety check to prevent infinite loop
        if ($attempt >= $maxAttempts) {
            // Add timestamp to make it unique if we can't find a unique number
            $orderNumber = 'ORD-' . date('YmdHis') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            break;
        }
    } while ($exists);
    
    return $orderNumber;
}
}

// --- Execute action ---
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'create_kot':
            handleCreateKOT();
            break;
        case 'update_kot_status':
            handleUpdateKOTStatus();
            break;
        case 'complete_kot':
            handleCompleteKOT();
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
    }
} catch (PDOException $e) {
    error_log("PDO Error in kot_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again later.'], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in kot_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.'], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Error $e) {
    error_log("Error in kot_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.'], JSON_UNESCAPED_UNICODE);
    exit();
}
?>
