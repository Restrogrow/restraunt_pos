<?php
// Clean any output buffers and prevent any output before JSON
if (ob_get_level()) {
    ob_clean();
}
ob_start();

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Max-Age: 86400'); // 24 hours
    http_response_code(200);
    ob_end_clean();
    exit();
}

// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true); // Skip timeout validation for public customer website

// Set headers
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
}

// Connection will be established inside the main try block

// Get JSON data
$input = json_decode(file_get_contents('php://input'), true);

$customer_name = $input['customer_name'] ?? '';
$customer_phone = $input['customer_phone'] ?? '';
$customer_email = $input['customer_email'] ?? '';
$customer_address = $input['customer_address'] ?? '';
$address_lat = isset($input['address_lat']) && $input['address_lat'] !== '' ? (float)$input['address_lat'] : null;
$address_lng = isset($input['address_lng']) && $input['address_lng'] !== '' ? (float)$input['address_lng'] : null;
$notes = $input['notes'] ?? '';
$order_type = $input['order_type'] ?? 'Delivery';
$table_id = $input['table_id'] ?? null;
// Ensure table_id is null if it's an empty string (e.g. for Delivery or non-Dine-in orders)
if ($table_id === '' || $table_id === 0) $table_id = null;
$items = $input['items'] ?? [];
$global_addons = $input['global_addons'] ?? [];
$total = $input['total'] ?? 0;
$total_payable = $input['total_payable'] ?? $total;
$payment_method = $input['payment_method'] ?? 'Cash';

// Optional payment-proof screenshot (customer uploads this when paying via
// the restaurant's business QR). Stored separately in payment_proofs, not on
// the orders row, so it starts life unconfirmed and never counts as revenue
// until the restaurant explicitly reviews and confirms it.
$paymentProofBytes = null;
$paymentProofMime = null;
$payment_proof_base64 = $input['payment_proof_base64'] ?? '';
if ($payment_proof_base64 !== '' && preg_match('/^data:(image\/(?:jpeg|jpg|png|webp|gif));base64,(.+)$/i', $payment_proof_base64, $proofMatch)) {
    $decodedProof = base64_decode($proofMatch[2], true);
    if ($decodedProof !== false && strlen($decodedProof) > 0 && strlen($decodedProof) <= 5 * 1024 * 1024) {
        $paymentProofBytes = $decodedProof;
        $paymentProofMime = strtolower($proofMatch[1]) === 'image/jpg' ? 'image/jpeg' : strtolower($proofMatch[1]);
    }
}
$coupon_code = $input['coupon_code'] ?? '';
$discount_amount = (float)($input['discount_amount'] ?? 0);
$delivery_zone_id = $input['delivery_zone_id'] ?? '';
$delivery_charge = (float)($input['delivery_charge'] ?? 0);
$packaging_charge = (float)($input['packaging_charge'] ?? 0);
if ($delivery_zone_id === '' || $delivery_zone_id === '0') $delivery_zone_id = null;

// Normalize order_type to valid enum value
$validOrderTypes = ['Dine-in', 'Takeaway', 'Delivery'];
$order_type = in_array($order_type, $validOrderTypes) ? $order_type : 'Delivery';

// Validate required fields
if (empty($customer_name) || empty($customer_phone)) {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Name and phone are required'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

if (empty($items)) {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Cart is empty'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $conn = getConnection();
    
    // Resolve restaurant ID: session > query param > default
    $restaurant_id = $_SESSION['restaurant_id'] ?? ($_GET['restaurant_id'] ?? 'RES001');
    
    // Check minimum order value and fetch packaging charge from DB (server-authoritative)
    $minStmt = $conn->prepare("SELECT minimum_order_value, packaging_charge FROM users WHERE restaurant_id = ? LIMIT 1");
    $minStmt->execute([$restaurant_id]);
    $minRow = $minStmt->fetch(PDO::FETCH_ASSOC);
    $minOrderValue = (float)($minRow['minimum_order_value'] ?? 0);
    // Use DB packaging_charge instead of client-provided value to prevent price manipulation
    $packaging_charge = (float)($minRow['packaging_charge'] ?? 0);
    if ($minOrderValue > 0 && $total < $minOrderValue) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Minimum order value is ' . number_format($minOrderValue, 2) . '. Please add more items.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Check if restaurant is open (using Indian Standard Time IST consistently)
    $hoursStmt = $conn->prepare("SELECT opening_hours FROM users WHERE restaurant_id = ? LIMIT 1");
    $hoursStmt->execute([$restaurant_id]);
    $hoursRow = $hoursStmt->fetch(PDO::FETCH_ASSOC);
    if ($hoursRow && !empty($hoursRow['opening_hours'])) {
        $hours = json_decode($hoursRow['opening_hours'], true);
        if ($hours) {
            $india_tz = new DateTimeZone('Asia/Kolkata');
            $now = new DateTime('now', $india_tz);
            $day = strtolower($now->format('l'));
            $currentTime = $now->getTimestamp();
            if (!isset($hours[$day]) || empty($hours[$day]['open'])) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Our restaurant is currently closed. Please come back during our opening hours!'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $opening = $hours[$day]['opening'] ?? '09:00 AM';
            $closing = $hours[$day]['closing'] ?? '10:00 PM';
            $openDT = new DateTime($now->format('Y-m-d') . ' ' . $opening, $india_tz);
            $closeDT = new DateTime($now->format('Y-m-d') . ' ' . $closing, $india_tz);
            if ($closeDT <= $openDT) {
                $closeDT->modify('+1 day');
            }
            if ($currentTime < $openDT->getTimestamp() || $currentTime >= $closeDT->getTimestamp()) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Our restaurant is currently closed. Please come back during our opening hours!'], JSON_UNESCAPED_UNICODE);
                exit();
            }
        }
    }
    
    // BEGIN TRANSACTION for all writes
    // ── Delivery Radius Server-Side Check ──
    // TEMPORARILY DISABLED: re-enable once Google Maps based radius checking
    // is wired up. Currently also blocks every delivery order on databases
    // where the delivery_radius_km/restaurant_lat/restaurant_lng columns
    // haven't been migrated onto the users table yet.
    /*
$orderType = strtolower(trim($input['order_type'] ?? 'Delivery'));
if ($orderType === 'delivery') {
    $stmtRest = $conn->prepare("SELECT delivery_radius_km, restaurant_lat, restaurant_lng FROM users WHERE restaurant_id = ? LIMIT 1");
    $stmtRest->execute([$restaurant_id]);
    $restRow = $stmtRest->fetch(PDO::FETCH_ASSOC);
    if ($restRow) {
        $deliveryRadiusKm = (float)($restRow['delivery_radius_km'] ?? 0);
        $restLat = isset($restRow['restaurant_lat']) ? (float)$restRow['restaurant_lat'] : null;
        $restLng = isset($restRow['restaurant_lng']) ? (float)$restRow['restaurant_lng'] : null;
        $custLat = isset($input['address_lat']) ? (float)$input['address_lat'] : 0;
        $custLng = isset($input['address_lng']) ? (float)$input['address_lng'] : 0;

        if ($deliveryRadiusKm > 0 && $restLat && $restLng && $custLat && $custLng) {
            // Haversine distance calculation
            $earthRadius = 6371;
            $dLat = deg2rad($custLat - $restLat);
            $dLon = deg2rad($custLng - $restLng);
            $a = sin($dLat / 2) * sin($dLat / 2) +
                 cos(deg2rad($restLat)) * cos(deg2rad($custLat)) *
                 sin($dLon / 2) * sin($dLon / 2);
            $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
            $distance = $earthRadius * $c;

            if ($distance > $deliveryRadiusKm) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "Sorry, we don't deliver to your address. Your location is " . round($distance, 1) . " km away, but our delivery radius is only " . $deliveryRadiusKm . " km.",
                    'error_code' => 'OUTSIDE_DELIVERY_RADIUS'
                ], JSON_UNESCAPED_UNICODE);
                exit();
            }
        }
    }
}
    */

$conn->beginTransaction();
    
    // Validate coupon if provided (WITH row lock to prevent race condition)
    if (!empty($coupon_code)) {
        $cpnStmt = $conn->prepare("SELECT * FROM coupons WHERE restaurant_id = ? AND coupon_code = ? AND is_active = 1 AND (valid_from IS NULL OR valid_from <= CURDATE()) AND (valid_until IS NULL OR valid_until >= CURDATE()) AND (max_uses = 0 OR current_uses < max_uses) LIMIT 1 FOR UPDATE");
        $cpnStmt->execute([$restaurant_id, $coupon_code]);
        $coupon = $cpnStmt->fetch(PDO::FETCH_ASSOC);
        if (!$coupon) {
            $conn->rollBack();
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid or expired coupon code'], JSON_UNESCAPED_UNICODE);
            exit();
        }
    }

    // --- Server-side price verification ---
    // Verifies menu item prices, variation prices, AND addon prices
    // against the database to prevent price manipulation by clients.
    $verifiedItems = [];
    $calculatedSubtotal = 0;
    $calculatedAddonTotal = 0;
    foreach ($items as $item) {
        $menuItemId = (int)($item['id'] ?? 0);
        $quantity = (int)($item['quantity'] ?? 0);
        if ($quantity <= 0) continue;
        
        $menuStmt = $conn->prepare("SELECT id, base_price, is_available FROM menu_items WHERE id = ?");
        $menuStmt->execute([$menuItemId]);
        $menuRow = $menuStmt->fetch(PDO::FETCH_ASSOC);
        if (!$menuRow || !$menuRow['is_available']) continue;
        
        $unitPrice = (float)$menuRow['base_price'];
        $variationName = $item['variation_name'] ?? '';
        
        if (!empty($variationName)) {
            $varStmt = $conn->prepare("SELECT price FROM menu_item_variations WHERE menu_item_id = ? AND variation_name = ?");
            $varStmt->execute([$menuItemId, $variationName]);
            $varRow = $varStmt->fetch(PDO::FETCH_ASSOC);
            if ($varRow) {
                $unitPrice = (float)$varRow['price'];
            }
        }
        
        // --- Server-side addon price verification ---
        // Look up actual addon prices from DB and validate that each addon
        // exists, is available, and uses the correct price.
        $verifiedAddons = [];
        if (!empty($item['addons']) && is_array($item['addons'])) {
            foreach ($item['addons'] as $addon) {
                $addonId = (int)($addon['id'] ?? 0);
                if ($addonId <= 0) {
                    // Addon without an ID — skip silently (client-invented addon)
                    continue;
                }
                // Look up the actual addon from the database
                $addonStmt = $conn->prepare("SELECT id, addon_name, addon_price, is_available FROM meal_addons WHERE id = ? AND restaurant_id = ? AND is_available = 1");
                $addonStmt->execute([$addonId, $restaurant_id]);
                $dbAddon = $addonStmt->fetch(PDO::FETCH_ASSOC);
                if ($dbAddon) {
                    // Use the DB price — ignore whatever the client sent
                    // Multiply by item quantity so addon costs scale with the order
                    $addonPrice = (float)$dbAddon['addon_price'];
                    $verifiedAddons[] = [
                        'id'    => (int)$dbAddon['id'],
                        'name'  => $dbAddon['addon_name'],
                        'price' => $addonPrice,
                    ];
                    $calculatedAddonTotal += $addonPrice * $quantity;
                } else {
                    // Addon not found or not available — client sent an invalid/deleted addon
                    error_log("Addon validation: addon id={$addonId} not found or unavailable for restaurant {$restaurant_id}");
                }
            }
        }
        // --- End addon price verification ---
        
        $verifiedItems[] = [
            'id' => $menuRow['id'],
            'name' => $item['name'],
            'variation_name' => $variationName,
            'quantity' => $quantity,
            'price' => $unitPrice,
            'addons' => $verifiedAddons,   // Use verified addon data (DB prices)
        ];
        $calculatedSubtotal += $unitPrice * $quantity;
    }
    
    $items = $verifiedItems;
    // Include addon costs in the total so the final order amount reflects
    // both item prices and verified addon prices.
    $total = $calculatedSubtotal + $calculatedAddonTotal;

    // --- Server-side global addon verification ---
    // Global addons (added from Add-ons section on cart page) are validated
    // against the meal_addons table independently of menu items.
    $verifiedGlobalAddons = [];
    $calculatedGlobalAddonTotal = 0;
    if (!empty($global_addons) && is_array($global_addons)) {
        foreach ($global_addons as $gAddon) {
            $gAddonId = (int)($gAddon['id'] ?? 0);
            $gQty = (int)($gAddon['quantity'] ?? 1);
            if ($gAddonId <= 0 || $gQty <= 0) continue;
            $addonStmt = $conn->prepare("SELECT id, addon_name, addon_price, is_available FROM meal_addons WHERE id = ? AND restaurant_id = ? AND is_available = 1");
            $addonStmt->execute([$gAddonId, $restaurant_id]);
            $dbAddon = $addonStmt->fetch(PDO::FETCH_ASSOC);
            if ($dbAddon) {
                $gPrice = (float)$dbAddon['addon_price'];
                $verifiedGlobalAddons[] = [
                    'id'       => (int)$dbAddon['id'],
                    'name'     => $dbAddon['addon_name'],
                    'price'    => $gPrice,
                    'quantity' => $gQty,
                ];
                $calculatedGlobalAddonTotal += $gPrice * $gQty;
            } else {
                error_log("Global addon validation: addon id={$gAddonId} not found or unavailable for restaurant {$restaurant_id}");
            }
        }
    }
    $total += $calculatedGlobalAddonTotal;
    $global_addons = $verifiedGlobalAddons;
    // --- End server-side global addon verification ---

    // --- Server-side discount verification ---
    // Recompute the discount from the coupon's actual type/value in the DB.
    // Never trust the client-submitted discount_amount - it can be set to
    // any value to reduce the total, effectively making orders free.
    $discount_amount = 0;
    if (!empty($coupon_code) && isset($coupon)) {
        if ((float)$coupon['minimum_order_amount'] > 0 && $total < (float)$coupon['minimum_order_amount']) {
            $conn->rollBack();
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Minimum order of ' . number_format((float)$coupon['minimum_order_amount'], 2) . ' required for this coupon'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        if ($coupon['discount_type'] === 'percent') {
            $discount_amount = round($total * (float)$coupon['discount_value'] / 100, 2);
        } else {
            $discount_amount = (float)$coupon['discount_value'];
        }
        if ($discount_amount > $total) $discount_amount = $total;
    }
    // --- End server-side discount verification ---

    // Generate unique order number function
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
                    error_log("Error checking Order number uniqueness: " . $e->getMessage());
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
    
    // Generate unique order number with collision check
    $order_number = generateOrderNumber($conn, $restaurant_id);
    
    // Check if GST is enabled for this restaurant
    $gstEnabled = true;
    try {
        $gstStmt = $conn->prepare("SELECT enable_gst FROM users WHERE restaurant_id = ? LIMIT 1");
        $gstStmt->execute([$restaurant_id]);
        $gstRow = $gstStmt->fetch(PDO::FETCH_ASSOC);
        $gstEnabled = $gstRow ? (bool)$gstRow['enable_gst'] : true;
    } catch (Exception $e) {
        $gstEnabled = true;
    }

    // Calculate all financials NOW before any writes
    $deliveryCharge = (float)$delivery_charge;
    $subtotal = (float)$total;
    // GST is on the discounted subtotal only — packaging is NOT taxable
    $taxable = $subtotal - (float)$discount_amount;
    if ($taxable < 0) $taxable = 0;
    $tax = $gstEnabled ? round($taxable * 0.05, 2) : 0;
    $grand_total = $taxable + $tax + $deliveryCharge + (float)$packaging_charge;
    
    // Payment starts as Pending
    $paymentStatus = 'Pending';
    
    // --- All write operations inside transaction ---
    
    // Check if customer exists, if not create
    $customerStmt = $conn->prepare("SELECT id FROM customers WHERE restaurant_id = ? AND phone = ?");
    $customerStmt->execute([$restaurant_id, $customer_phone]);
    $customer = $customerStmt->fetch();
    
    if ($customer) {
        $customer_id = $customer['id'];
        $updateStmt = $conn->prepare("UPDATE customers SET total_visits = total_visits + 1, last_visit_date = CURDATE(), total_spent = total_spent + ? WHERE id = ?");
        $updateStmt->execute([$grand_total, $customer_id]);
    } else {
        $insertStmt = $conn->prepare("INSERT INTO customers (restaurant_id, customer_name, phone, email) VALUES (?, ?, ?, ?)");
        $insertStmt->execute([$restaurant_id, $customer_name, $customer_phone, $customer_email]);
        $customer_id = $conn->lastInsertId();
    }

    // Deduplication check: if an identical order from the same customer
    // exists within the last 2 minutes, return it instead of creating a duplicate.
    // This prevents email failures and timeouts from causing duplicate paid orders.
    $dedupStmt = $conn->prepare(
        "SELECT id, order_number FROM orders 
         WHERE restaurant_id = ? AND customer_phone = ? AND total = ? 
         AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE) 
         ORDER BY id DESC LIMIT 1"
    );
    $dedupStmt->execute([$restaurant_id, $customer_phone, $grand_total]);
    $dupOrder = $dedupStmt->fetch(PDO::FETCH_ASSOC);
    if ($dupOrder) {
        // Duplicate detected — rollback and return existing order
        $conn->rollBack();
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'order_id' => $dupOrder['id'],
            'order_number' => $dupOrder['order_number'],
            'message' => 'Order already placed. No duplicate created.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Create order
    $orderStmt = $conn->prepare("INSERT INTO orders (restaurant_id, table_id, order_number, customer_name, customer_phone, customer_email, customer_address, address_lat, address_lng, notes, coupon_code, discount_amount, order_type, delivery_zone_id, delivery_charge, payment_method, payment_status, order_status, subtotal, tax, total, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?, 'website')");
    $orderStmt->execute([$restaurant_id, $table_id, $order_number, $customer_name, $customer_phone, $customer_email, $customer_address, $address_lat, $address_lng, $notes, $coupon_code, $discount_amount, $order_type, $delivery_zone_id, $deliveryCharge, $payment_method, $paymentStatus, $subtotal, $tax, $grand_total]);
    $order_id = $conn->lastInsertId();

    if ($paymentProofBytes !== null) {
        try {
            $conn->prepare("INSERT INTO payment_proofs (order_id, restaurant_id, proof_data, proof_mime_type, status) VALUES (?, ?, ?, ?, 'Pending')")
                 ->execute([$order_id, $restaurant_id, $paymentProofBytes, $paymentProofMime]);
        } catch (Exception $e) {
            // Don't fail the whole order over a proof-storage hiccup — the
            // restaurant can still follow up with the customer manually.
            error_log('Failed to store payment proof for order ' . $order_id . ': ' . $e->getMessage());
        }
    }

    // Insert order items
    $itemStmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, item_name, variation_name, quantity, unit_price, total_price, addons) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    foreach ($items as $item) {
        $addonsJson = null;
        if (!empty($item['addons'])) {
            $addonsJson = json_encode($item['addons'], JSON_UNESCAPED_UNICODE);
        }
        $itemStmt->execute([
            $order_id,
            $item['id'],
            $item['name'],
            $item['variation_name'] ?? null,
            $item['quantity'],
            $item['price'],
            $item['price'] * $item['quantity'],
            $addonsJson
        ]);
        
        // Also store in order_item_addons table for backward compatibility
        $orderItemId = $conn->lastInsertId();
        if (!empty($item['addons']) && is_array($item['addons'])) {
            try {
                $addonItemStmt = $conn->prepare("INSERT INTO order_item_addons (order_item_id, addon_id, addon_name, addon_price, quantity) VALUES (?, ?, ?, ?, 1)");
                foreach ($item['addons'] as $addon) {
                    $addonItemStmt->execute([
                        $orderItemId,
                        $addon['id'] ?? null,
                        $addon['name'] ?? '',
                        $addon['price'] ?? 0
                    ]);
                }
            } catch (PDOException $e) {
                // order_item_addons table might not exist, skip
            }
        }
    }

    // --- Insert global addons as order items ---
    if (!empty($global_addons) && is_array($global_addons)) {
        foreach ($global_addons as $gAddon) {
            $gName = $gAddon['name'] ?? 'Add-on';
            $gPrice = (float)($gAddon['price'] ?? 0);
            $gQty = (int)($gAddon['quantity'] ?? 1);
            $gAddonId = (int)($gAddon['id'] ?? 0);
            $itemStmt->execute([
                $order_id,
                null, // no menu_item_id for global addons
                $gName,
                'Add-on',
                $gQty,
                $gPrice,
                $gPrice * $gQty,
                null // no per-item addons
            ]);
            // Also store in order_item_addons
            $gOrderItemId = $conn->lastInsertId();
            try {
                $addonItemStmt = $conn->prepare("INSERT INTO order_item_addons (order_item_id, addon_id, addon_name, addon_price, quantity) VALUES (?, ?, ?, ?, ?)");
                $addonItemStmt->execute([$gOrderItemId, $gAddonId, $gName, $gPrice, $gQty]);
            } catch (PDOException $e) {
                // order_item_addons table might not exist, skip
            }
        }
    }
    // --- End global addon insertion ---
    
    // Increment coupon usage AFTER order is created (fixes P1-12: usage before persist)
    // DEFENSIVE: Re-read coupon with lock to verify usage limit not exceeded by concurrent request
    if (!empty($coupon_code) && isset($coupon)) {
        $useStmt = $conn->prepare("UPDATE coupons SET current_uses = current_uses + 1 WHERE id = ?");
        $useStmt->execute([$coupon['id']]);
        
        // Verify we haven't exceeded max_uses (defensive — FOR UPDATE lock should prevent this)
        if ($coupon['max_uses'] > 0) {
            $verifyStmt = $conn->prepare("SELECT current_uses FROM coupons WHERE id = ? FOR UPDATE");
            $verifyStmt->execute([$coupon['id']]);
            $newCount = (int)$verifyStmt->fetchColumn();
            if ($newCount > $coupon['max_uses']) {
                // Rollback — coupon was over-used by concurrent request
                $conn->rollBack();
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Coupon usage limit reached. Please try again.'], JSON_UNESCAPED_UNICODE);
                exit();
            }
        }
    }
    
    // Record payment (skip for PhonePe — recorded via webhook/callback)
    if ($payment_method !== 'PhonePe' && $payment_method !== 'UPI / NetBanking') {
        try {
            $paymentStmt = $conn->prepare("INSERT INTO payments (restaurant_id, order_id, amount, payment_method, payment_status) VALUES (?, ?, ?, ?, 'Pending')");
            $paymentStmt->execute([$restaurant_id, $order_id, $grand_total, $payment_method]);
        } catch (Exception $e) {
            // Payments table might not exist, skip
        }
    }
    
    // Create KOT for Dine-in orders so table map shows as occupied
    if ($order_type === 'Dine-in' && !empty($table_id)) {
        $kotNumber = null;
        $maxAttempts = 100;
        $attempt = 0;
        do {
            $kotNumber = 'KOT-' . date('Ymd') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            try {
                $checkStmt = $conn->prepare("SELECT COUNT(*) FROM kot WHERE kot_number = ? AND restaurant_id = ?");
                $checkStmt->execute([$kotNumber, $restaurant_id]);
                $exists = $checkStmt->fetchColumn() > 0;
            } catch (PDOException $e) {
                $exists = false;
            }
            $attempt++;
            if ($attempt >= $maxAttempts) {
                $kotNumber = 'KOT-' . date('YmdHis') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
                break;
            }
        } while ($exists);

        $kotNotes = 'Order #' . ($order_number ?? '') . ' - Website Order';
        if (!empty($notes)) {
            $kotNotes .= "\n" . $notes;
        }

        $checkKotCols = $conn->query("SHOW COLUMNS FROM kot LIKE 'customer_phone'");
        if ($checkKotCols && $checkKotCols->rowCount() > 0) {
            $kotStmt = $conn->prepare("INSERT INTO kot (restaurant_id, kot_number, table_id, order_type, customer_name, customer_phone, customer_email, customer_address, subtotal, tax, total, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $kotStmt->execute([$restaurant_id, $kotNumber, $table_id, $order_type, $customer_name, $customer_phone, $customer_email, $customer_address, $subtotal, $tax, $grand_total, $kotNotes]);
        } else {
            $kotStmt = $conn->prepare("INSERT INTO kot (restaurant_id, kot_number, table_id, order_type, customer_name, subtotal, tax, total, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $kotStmt->execute([$restaurant_id, $kotNumber, $table_id, $order_type, $customer_name, $subtotal, $tax, $grand_total, $kotNotes]);
        }
        $kotId = $conn->lastInsertId();

        $kotItemsHasAddons = false;
        try {
            $checkAddonsCol = $conn->query("SHOW COLUMNS FROM kot_items LIKE 'addons'");
            $kotItemsHasAddons = $checkAddonsCol && $checkAddonsCol->rowCount() > 0;
        } catch (Exception $e) {}

        foreach ($items as $item) {
            $addonsJson = null;
            if (!empty($item['addons']) && $kotItemsHasAddons) {
                $verifiedAddons = [];
                foreach ($item['addons'] as $addon) {
                    $addonId = (int)($addon['id'] ?? 0);
                    if ($addonId <= 0) continue;
                    try {
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
                    } catch (Exception $e) {}
                }
                $addonsJson = !empty($verifiedAddons) ? json_encode($verifiedAddons, JSON_UNESCAPED_UNICODE) : null;
            }

            if ($kotItemsHasAddons) {
                $kotItemStmt = $conn->prepare("INSERT INTO kot_items (kot_id, menu_item_id, item_name, quantity, unit_price, total_price, addons) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $kotItemStmt->execute([
                    $kotId,
                    (int)$item['id'],
                    (string)$item['name'],
                    (int)$item['quantity'],
                    (float)$item['price'],
                    (float)($item['price'] * $item['quantity']),
                    $addonsJson
                ]);
            } else {
                $kotItemStmt = $conn->prepare("INSERT INTO kot_items (kot_id, menu_item_id, item_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
                $kotItemStmt->execute([
                    $kotId,
                    (int)$item['id'],
                    (string)$item['name'],
                    (int)$item['quantity'],
                    (float)$item['price'],
                    (float)($item['price'] * $item['quantity'])
                ]);
            }
        }
    }

    // Commit transaction
    $conn->commit();

    // Fetch WhatsApp & email settings before push notification (needed for currency symbol)
    $waStmt = $conn->prepare("SELECT whatsapp_orders, phone, email, currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1");
    $waStmt->execute([$restaurant_id]);
    $waSettings = $waStmt->fetch(PDO::FETCH_ASSOC);
    $currencySymbol = $waSettings ? trim($waSettings['currency_symbol'] ?? '₹') : '₹';

    // Send push notification to admin about new order
    try {
        require_once __DIR__ . '/../config/push_notification.php';
        $orderNum = $order_number ?? '';
        $restaurantName = $waSettings['restaurant_name'] ?? 'Restaurant';
        sendPushNotification(
            $conn,
            $restaurant_id,
            '📦 New Order!',
            'Order #' . $orderNum . ' received - ' . $currencySymbol . number_format($grand_total, 2),
            '../views/orders.php',
            $order_id
        );
    } catch (Exception $e) {
        // Don't let push notification failure break the order
        error_log('Push notification error: ' . $e->getMessage());
    }

    $whatsappEnabled = $waSettings ? (int)$waSettings['whatsapp_orders'] : 0;
    $whatsappPhone = $waSettings ? (string)($waSettings['phone'] ?? '') : '';
    $restaurantEmail = $waSettings ? trim($waSettings['email'] ?? '') : '';

    // Determine payment gateway
    $usePhonePe = false;
    if ($payment_method === 'UPI / NetBanking') {
        $gwStmt = $conn->prepare("SELECT payment_gateway_mode FROM users WHERE restaurant_id = ? LIMIT 1");
        $gwStmt->execute([$restaurant_id]);
        $gwRow = $gwStmt->fetch(PDO::FETCH_ASSOC);
        $gwMode = $gwRow['payment_gateway_mode'] ?? 'own';
        // PhonePe is always used; platform mode uses env credentials
        $usePhonePe = true;
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'order_id' => $order_id,
        'order_number' => $order_number,
        'order_type' => $order_type,
        'total_with_tax' => $grand_total,
        'phonepe_required' => $usePhonePe,
        'whatsapp_enabled' => $whatsappEnabled,
        'whatsapp_phone' => $whatsappPhone,
        'message' => $usePhonePe ? 'Redirecting to payment...' : 'Order placed successfully'
    ], JSON_UNESCAPED_UNICODE);
    
    // Send email notifications after response
    if (file_exists(__DIR__ . '/../config/email_config.php')) {
        try {
            require_once __DIR__ . '/../config/email_config.php';
    $itemsHtml = '';
    foreach ($items as $item) {
        $itemName = htmlspecialchars($item['name'] ?? '', ENT_QUOTES, 'UTF-8');
        $itemQty = (int)($item['quantity'] ?? 1);
        $itemPrice = (float)($item['price'] ?? 0);
        $itemTotal = $itemPrice * $itemQty;
        $itemsHtml .= '<tr><td style="padding:8px;border-bottom:1px solid #ddd;">' . $itemName . '</td><td style="padding:8px;border-bottom:1px solid #ddd;text-align:center;">' . $itemQty . '</td><td style="padding:8px;border-bottom:1px solid #ddd;text-align:right;">' . $currencySymbol . number_format($itemPrice, 2) . '</td><td style="padding:8px;border-bottom:1px solid #ddd;text-align:right;">' . $currencySymbol . number_format($itemTotal, 2) . '</td></tr>';
    }
    $safeOrderNumber = htmlspecialchars($order_number, ENT_QUOTES, 'UTF-8');
    $safeOrderType = htmlspecialchars($order_type, ENT_QUOTES, 'UTF-8');
    $safeCustomerName = htmlspecialchars($customer_name, ENT_QUOTES, 'UTF-8');
    $safeCustomerPhone = htmlspecialchars($customer_phone, ENT_QUOTES, 'UTF-8');
    $safeCustomerAddress = htmlspecialchars($customer_address, ENT_QUOTES, 'UTF-8');
    $safeNotes = htmlspecialchars($notes, ENT_QUOTES, 'UTF-8');
    $orderDetailsHtml = '
    <div style="max-width:600px;margin:0 auto;font-family:Arial,sans-serif;">
        <div style="background:#2c3e50;color:#fff;padding:20px;text-align:center;border-radius:8px 8px 0 0;">
            <h1 style="margin:0;font-size:20px;">Order Confirmation</h1>
            <p style="margin:5px 0 0;opacity:0.9;">' . $safeOrderNumber . '</p>
        </div>
        <div style="background:#fff;padding:20px;border:1px solid #ddd;border-top:none;">
            <p>Dear ' . $safeCustomerName . ',</p>
            <p>Thank you for your order! Here are the details:</p>
            <table style="width:100%;border-collapse:collapse;margin:15px 0;">
                <tr><td style="padding:6px 8px;font-weight:bold;width:120px;">Order Type:</td><td style="padding:6px 8px;">' . $safeOrderType . '</td></tr>
                <tr><td style="padding:6px 8px;font-weight:bold;">Order No:</td><td style="padding:6px 8px;">' . $safeOrderNumber . '</td></tr>
                <tr><td style="padding:6px 8px;font-weight:bold;">Name:</td><td style="padding:6px 8px;">' . $safeCustomerName . '</td></tr>
                <tr><td style="padding:6px 8px;font-weight:bold;">Phone:</td><td style="padding:6px 8px;">' . $safeCustomerPhone . '</td></tr>' .
                (!empty($customer_address) ? '<tr><td style="padding:6px 8px;font-weight:bold;">Address:</td><td style="padding:6px 8px;">' . $safeCustomerAddress . '</td></tr>' : '') .
                (!empty($notes) ? '<tr><td style="padding:6px 8px;font-weight:bold;">Notes:</td><td style="padding:6px 8px;">' . $safeNotes . '</td></tr>' : '') . '
            </table>
            <h3 style="margin:15px 0 10px;">Order Items</h3>
            <table style="width:100%;border-collapse:collapse;">
                <thead><tr style="background:#f8f9fa;"><th style="padding:8px;text-align:left;border-bottom:2px solid #dee2e6;">Item</th><th style="padding:8px;text-align:center;border-bottom:2px solid #dee2e6;">Qty</th><th style="padding:8px;text-align:right;border-bottom:2px solid #dee2e6;">Price</th><th style="padding:8px;text-align:right;border-bottom:2px solid #dee2e6;">Total</th></tr></thead>
                <tbody>' . $itemsHtml . '</tbody>
                <tfoot>
                    <tr><td colspan="3" style="padding:8px;text-align:right;font-weight:bold;">Subtotal:</td><td style="padding:8px;text-align:right;">' . $currencySymbol . number_format($subtotal, 2) . '</td></tr>' .
                    ($discount_amount > 0 ? '<tr><td colspan="3" style="padding:8px;text-align:right;font-weight:bold;color:#e74c3c;">Discount:</td><td style="padding:8px;text-align:right;color:#e74c3c;">-' . $currencySymbol . number_format($discount_amount, 2) . '</td></tr>' : '') .
                    ($packaging_charge > 0 ? '<tr><td colspan="3" style="padding:8px;text-align:right;font-weight:bold;">Packaging Charge:</td><td style="padding:8px;text-align:right;">' . $currencySymbol . number_format((float)$packaging_charge, 2) . '</td></tr>' : '') . '
                    <tr><td colspan="3" style="padding:8px;text-align:right;font-weight:bold;">Tax (5% GST):</td><td style="padding:8px;text-align:right;">' . $currencySymbol . number_format($tax, 2) . '</td></tr>' .
                    ($deliveryCharge > 0 ? '<tr><td colspan="3" style="padding:8px;text-align:right;font-weight:bold;">Delivery Fee:</td><td style="padding:8px;text-align:right;">' . $currencySymbol . number_format($deliveryCharge, 2) . '</td></tr>' : '') . '
                    <tr><td colspan="3" style="padding:8px;text-align:right;font-weight:bold;font-size:16px;">Total:</td><td style="padding:8px;text-align:right;font-weight:bold;font-size:16px;">' . $currencySymbol . number_format($grand_total, 2) . '</td></tr>
                </tfoot>
            </table>
            <p style="margin-top:20px;color:#666;font-size:13px;">If you have any questions, please contact the restaurant.</p>
        </div>
        <div style="background:#f8f9fa;padding:15px;text-align:center;border-radius:0 0 8px 8px;border:1px solid #ddd;border-top:none;color:#999;font-size:12px;">
            RestroGrow POS — Restaurant Management System
        </div>
    </div>';
            if (!empty($customer_email)) {
                sendEmail($customer_email, 'Order Confirmed - ' . $safeOrderNumber, $orderDetailsHtml);
            }
            if (!empty($restaurantEmail)) {
                sendEmail($restaurantEmail, 'New Order Received - ' . $safeOrderNumber, '<h2 style="color:#e74c3c;">New Order Alert</h2>' . $orderDetailsHtml);
            }
        } catch (Exception $emailErr) {
            error_log("Email notification error: " . $emailErr->getMessage());
        }
    }
    
} catch (Exception $e) {
    // Rollback transaction on any error
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    ob_end_clean();
    error_log("Order processing error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while processing your order. Please try again.'
    ], JSON_UNESCAPED_UNICODE);
}
?>

