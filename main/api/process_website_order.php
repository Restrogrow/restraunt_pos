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
$landmark = trim($input['landmark'] ?? '');
if ($landmark === '') $landmark = null;
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

    // Fetch every restaurant setting this endpoint needs in ONE round trip.
    // Previously this was 7 separate `SELECT ... FROM users WHERE
    // restaurant_id = ?` queries scattered through the file (subscription
    // status, min order/packaging, COD/payment gateway, opening hours,
    // KM-delivery settings, GST, WhatsApp) — each one a full network round
    // trip to the DB before the customer's order could even start being
    // validated. Falls back to a reduced column set if a newer migration
    // (km-delivery, cod_enabled, etc.) hasn't been run on this DB yet.
    try {
        $userStmt = $conn->prepare("SELECT subscription_status, minimum_order_value, packaging_charge, cod_enabled, payment_gateway_mode, phonepe_merchant_id, (business_qr_code_data IS NOT NULL) AS has_business_qr, opening_hours, timezone, enable_km_delivery, delivery_rate_per_km, delivery_radius_km, restaurant_lat, restaurant_lng, enable_gst, tax_name, tax_percent, whatsapp_orders, phone FROM users WHERE restaurant_id = ? LIMIT 1");
        $userStmt->execute([$restaurant_id]);
        $restaurantRow = $userStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $userStmt = $conn->prepare("SELECT subscription_status, minimum_order_value, packaging_charge, opening_hours, timezone, enable_gst, tax_name, tax_percent, whatsapp_orders, phone FROM users WHERE restaurant_id = ? LIMIT 1");
        $userStmt->execute([$restaurant_id]);
        $restaurantRow = $userStmt->fetch(PDO::FETCH_ASSOC);
    }

    // Reject orders for restaurants whose subscription has lapsed. The
    // customer website already blocks browsing in this state (header.php),
    // but this is the authoritative server-side check for direct API calls.
    $subStatus = $restaurantRow['subscription_status'] ?? null;
    if (in_array($subStatus, ['expired', 'disabled'], true)) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'This restaurant is not currently accepting online orders.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Check minimum order value and fetch packaging charge from DB (server-authoritative)
    $minRow = $restaurantRow;
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

    // Enforce COD availability server-side. The client already hides the Cash
    // option when disabled, but that alone isn't authoritative.
    $codEnabled = true;
    $codRow = $restaurantRow;
    if ($codRow && isset($codRow['cod_enabled']) && (int)$codRow['cod_enabled'] === 0) {
        $hasOnlinePayment = !empty($codRow['phonepe_merchant_id']) || (int)($codRow['has_business_qr'] ?? 0) === 1;
        // Never fully block checkout: if no online payment method is
        // configured either, keep accepting Cash as the only option.
        $codEnabled = !$hasOnlinePayment;
    }
    if (!$codEnabled && ($payment_method === 'Cash' || $payment_method === '')) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Cash on Delivery is not available for this restaurant. Please choose an online payment method.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Check if restaurant is open, using the restaurant's own configured
    // timezone — not hardcoded IST. Opening/closing times are wall-clock
    // values meant in the restaurant's local time (e.g. Nepal restaurants
    // set them in NPT, UTC+5:45), so comparing them against IST would be off
    // by 15 minutes and could wrongly accept/reject orders near open/close.
    $hoursRow = $restaurantRow;
    if ($hoursRow && !empty($hoursRow['opening_hours'])) {
        $hours = json_decode($hoursRow['opening_hours'], true);
        if ($hours) {
            $restaurantTzName = !empty($hoursRow['timezone']) ? $hoursRow['timezone'] : 'Asia/Kolkata';
            if (!in_array($restaurantTzName, timezone_identifiers_list(), true)) {
                $restaurantTzName = 'Asia/Kolkata';
            }
            $india_tz = new DateTimeZone($restaurantTzName);
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

    // ── KM-Based Delivery Charge (server-authoritative) ──
    // Only runs when the restaurant has explicitly turned this on in settings
    // (users.enable_km_delivery) — existing flat/pincode-zone delivery charges
    // are untouched otherwise. Recomputes the charge from the customer's
    // coordinates rather than trusting the client-submitted delivery_charge
    // (which is just a hidden form field anyone could edit via devtools), and
    // rejects orders whose address falls outside the configured radius.
    if ($order_type === 'Delivery') {
        $kmRow = $restaurantRow;
        if ($kmRow && !empty($kmRow['enable_km_delivery'])) {
            $ratePerKm = (float)($kmRow['delivery_rate_per_km'] ?? 0);
            $radiusKm = (float)($kmRow['delivery_radius_km'] ?? 0);
            $restLat = isset($kmRow['restaurant_lat']) && $kmRow['restaurant_lat'] !== null ? (float)$kmRow['restaurant_lat'] : null;
            $restLng = isset($kmRow['restaurant_lng']) && $kmRow['restaurant_lng'] !== null ? (float)$kmRow['restaurant_lng'] : null;

            if ($restLat && $restLng && $address_lat && $address_lng) {
                $earthRadius = 6371;
                $dLat = deg2rad($address_lat - $restLat);
                $dLon = deg2rad($address_lng - $restLng);
                $a = sin($dLat / 2) * sin($dLat / 2) +
                     cos(deg2rad($restLat)) * cos(deg2rad($address_lat)) *
                     sin($dLon / 2) * sin($dLon / 2);
                $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
                $distanceKm = $earthRadius * $c;

                if ($radiusKm > 0 && $distanceKm > $radiusKm) {
                    ob_end_clean();
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'message' => "Sorry, we don't deliver to your address. Your location is " . round($distanceKm, 1) . " km away, but our delivery radius is only " . $radiusKm . " km.",
                        'error_code' => 'OUTSIDE_DELIVERY_RADIUS'
                    ], JSON_UNESCAPED_UNICODE);
                    exit();
                }

                $delivery_charge = round($distanceKm * $ratePerKm, 2);
            } else {
                // No usable coordinates to calculate distance — fall back to 0
                // rather than trusting an unverifiable client-submitted charge.
                $delivery_charge = 0;
            }
        }
    }

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

    // --- Batch-fetch menu items, variations, and addons up front ---
    // Previously this ran one query per cart item for menu_items, plus one
    // more per variation, plus one more per addon (and again for global
    // addons) — a 10-item cart with addons could mean 20-40 sequential DB
    // round trips, all while the transaction below is open. Now it's 3
    // queries total regardless of cart size.
    $menuItemIds = [];
    foreach ($items as $item) {
        $id = (int)($item['id'] ?? 0);
        if ($id > 0) $menuItemIds[] = $id;
    }
    $menuItemIds = array_values(array_unique($menuItemIds));

    $menuItemsById = [];
    if (!empty($menuItemIds)) {
        $placeholders = implode(',', array_fill(0, count($menuItemIds), '?'));
        // Scope to this restaurant — without this filter a menu_item id belonging
        // to a different restaurant could be used to bill an unrelated price.
        $menuStmt = $conn->prepare("SELECT id, item_name_en, base_price, is_available FROM menu_items WHERE id IN ($placeholders) AND restaurant_id = ?");
        $menuStmt->execute(array_merge($menuItemIds, [$restaurant_id]));
        foreach ($menuStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $menuItemsById[(int)$row['id']] = $row;
        }
    }

    $variationsByItem = [];
    if (!empty($menuItemIds)) {
        $placeholders = implode(',', array_fill(0, count($menuItemIds), '?'));
        // Also scoped to these menu items, which are already scoped to this
        // restaurant above, so a variation can't be borrowed from another
        // restaurant's item either.
        $varStmt = $conn->prepare("SELECT menu_item_id, variation_name, price FROM menu_item_variations WHERE menu_item_id IN ($placeholders)");
        $varStmt->execute($menuItemIds);
        foreach ($varStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $variationsByItem[(int)$row['menu_item_id']][$row['variation_name']] = (float)$row['price'];
        }
    }

    // Collect every addon ID referenced anywhere (per-item + global) so all
    // of them can be verified with a single query.
    $addonIds = [];
    foreach ($items as $item) {
        if (!empty($item['addons']) && is_array($item['addons'])) {
            foreach ($item['addons'] as $addon) {
                $aid = (int)($addon['id'] ?? 0);
                if ($aid > 0) $addonIds[] = $aid;
            }
        }
    }
    if (!empty($global_addons) && is_array($global_addons)) {
        foreach ($global_addons as $gAddon) {
            $aid = (int)($gAddon['id'] ?? 0);
            if ($aid > 0) $addonIds[] = $aid;
        }
    }
    $addonIds = array_values(array_unique($addonIds));

    $addonsById = [];
    if (!empty($addonIds)) {
        $placeholders = implode(',', array_fill(0, count($addonIds), '?'));
        $addonStmt = $conn->prepare("SELECT id, addon_name, addon_price, is_available FROM meal_addons WHERE id IN ($placeholders) AND restaurant_id = ? AND is_available = 1");
        $addonStmt->execute(array_merge($addonIds, [$restaurant_id]));
        foreach ($addonStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $addonsById[(int)$row['id']] = $row;
        }
    }
    // --- End batch-fetch ---

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

        $menuRow = $menuItemsById[$menuItemId] ?? null;
        if (!$menuRow || !$menuRow['is_available']) continue;

        $unitPrice = (float)$menuRow['base_price'];
        $variationName = $item['variation_name'] ?? '';

        if (!empty($variationName) && isset($variationsByItem[$menuItemId][$variationName])) {
            $unitPrice = $variationsByItem[$menuItemId][$variationName];
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
                $dbAddon = $addonsById[$addonId] ?? null;
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
            // Always use the DB-verified name, never the client-submitted one —
            // otherwise a mispriced/foreign item could be displayed under an
            // arbitrary fabricated name on the order, receipt, and kitchen ticket.
            'name' => $menuRow['item_name_en'],
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
            $dbAddon = $addonsById[$gAddonId] ?? null;
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
    
    // Check if tax is enabled for this restaurant, and at what name/rate
    $gstEnabled = true;
    $taxName = 'GST';
    $taxPercent = 5.00;
    try {
        $gstRow = $restaurantRow;
        $gstEnabled = $gstRow ? (bool)$gstRow['enable_gst'] : true;
        if ($gstRow && !empty($gstRow['tax_name'])) $taxName = $gstRow['tax_name'];
        if ($gstRow && isset($gstRow['tax_percent']) && $gstRow['tax_percent'] !== null) $taxPercent = (float)$gstRow['tax_percent'];
    } catch (Exception $e) {
        $gstEnabled = true;
    }

    // Calculate all financials NOW before any writes
    $deliveryCharge = (float)$delivery_charge;
    $subtotal = (float)$total;
    // Tax is on the discounted subtotal only — packaging is NOT taxable
    $taxable = $subtotal - (float)$discount_amount;
    if ($taxable < 0) $taxable = 0;
    $tax = $gstEnabled ? round($taxable * ($taxPercent / 100), 2) : 0;
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
    // exists within the last 10 minutes, return it instead of creating a
    // duplicate. This prevents network failures/timeouts around order
    // placement or payment initiation from causing duplicate orders when the
    // customer retries. 10 minutes (rather than the original 2) covers a
    // customer who checks their bank app before retrying, at the cost of a
    // rare false-positive if they intentionally reorder the exact same cart
    // total within that window — acceptable trade-off for launch.
    $dedupStmt = $conn->prepare(
        "SELECT id, order_number FROM orders
         WHERE restaurant_id = ? AND customer_phone = ? AND total = ?
         AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
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
    try {
        $orderStmt = $conn->prepare("INSERT INTO orders (restaurant_id, table_id, order_number, customer_name, customer_phone, customer_email, customer_address, landmark, address_lat, address_lng, notes, coupon_code, discount_amount, order_type, delivery_zone_id, delivery_charge, payment_method, payment_status, order_status, subtotal, tax, total, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?, 'website')");
        $orderStmt->execute([$restaurant_id, $table_id, $order_number, $customer_name, $customer_phone, $customer_email, $customer_address, $landmark, $address_lat, $address_lng, $notes, $coupon_code, $discount_amount, $order_type, $delivery_zone_id, $deliveryCharge, $payment_method, $paymentStatus, $subtotal, $tax, $grand_total]);
    } catch (PDOException $e) {
        // landmark / address_lat / address_lng migrations may not have been
        // run on this DB yet - fall back so order placement never breaks
        // over an unrun migration.
        $missingLandmark = stripos($e->getMessage(), 'landmark') !== false;
        $missingLatLng = stripos($e->getMessage(), 'address_lat') !== false || stripos($e->getMessage(), 'address_lng') !== false;
        if (!$missingLandmark && !$missingLatLng && stripos($e->getMessage(), 'Unknown column') === false) {
            throw $e;
        }
        if ($missingLandmark && !$missingLatLng) {
            $orderStmt = $conn->prepare("INSERT INTO orders (restaurant_id, table_id, order_number, customer_name, customer_phone, customer_email, customer_address, address_lat, address_lng, notes, coupon_code, discount_amount, order_type, delivery_zone_id, delivery_charge, payment_method, payment_status, order_status, subtotal, tax, total, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?, 'website')");
            $orderStmt->execute([$restaurant_id, $table_id, $order_number, $customer_name, $customer_phone, $customer_email, $customer_address, $address_lat, $address_lng, $notes, $coupon_code, $discount_amount, $order_type, $delivery_zone_id, $deliveryCharge, $payment_method, $paymentStatus, $subtotal, $tax, $grand_total]);
        } else {
            $orderStmt = $conn->prepare("INSERT INTO orders (restaurant_id, table_id, order_number, customer_name, customer_phone, customer_email, customer_address, notes, coupon_code, discount_amount, order_type, delivery_zone_id, delivery_charge, payment_method, payment_status, order_status, subtotal, tax, total, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?, 'website')");
            $orderStmt->execute([$restaurant_id, $table_id, $order_number, $customer_name, $customer_phone, $customer_email, $customer_address, $notes, $coupon_code, $discount_amount, $order_type, $delivery_zone_id, $deliveryCharge, $payment_method, $paymentStatus, $subtotal, $tax, $grand_total]);
        }
    }
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

    // Insert order items + their addons as single multi-row INSERTs instead
    // of one round trip per item/addon. A cart with several items each
    // carrying addons used to mean 10-20+ sequential queries here, all while
    // the transaction (and the customer's "Placing order..." spinner) is
    // open. MySQL/InnoDB guarantees auto-increment ids are consecutive for a
    // single multi-row INSERT (its row count is known up front, so it's
    // treated as a "simple insert" regardless of innodb_autoinc_lock_mode),
    // so the first row's id from lastInsertId() plus its position in the
    // VALUES list gives every row's real id without needing individual
    // executes.
    $itemRows = [];
    $itemParams = [];
    foreach ($items as $item) {
        $addonsJson = !empty($item['addons']) ? json_encode($item['addons'], JSON_UNESCAPED_UNICODE) : null;
        $itemRows[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
        array_push($itemParams,
            $order_id, $item['id'], $item['name'], $item['variation_name'] ?? null,
            $item['quantity'], $item['price'], $item['price'] * $item['quantity'], $addonsJson
        );
    }
    if (!empty($global_addons) && is_array($global_addons)) {
        foreach ($global_addons as $gAddon) {
            $gName = $gAddon['name'] ?? 'Add-on';
            $gPrice = (float)($gAddon['price'] ?? 0);
            $gQty = (int)($gAddon['quantity'] ?? 1);
            $itemRows[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
            array_push($itemParams,
                $order_id, null, $gName, 'Add-on', $gQty, $gPrice, $gPrice * $gQty, null
            );
        }
    }

    $firstOrderItemId = null;
    if (!empty($itemRows)) {
        $itemSql = "INSERT INTO order_items (order_id, menu_item_id, item_name, variation_name, quantity, unit_price, total_price, addons) VALUES " . implode(', ', $itemRows);
        $conn->prepare($itemSql)->execute($itemParams);
        $firstOrderItemId = (int)$conn->lastInsertId();
    }

    // Also store per-item and global addons in order_item_addons for
    // backward compatibility, batched into a single multi-row INSERT.
    if ($firstOrderItemId !== null) {
        $addonRows = [];
        $addonParams = [];
        $rowIndex = 0;
        foreach ($items as $item) {
            $orderItemId = $firstOrderItemId + $rowIndex;
            $rowIndex++;
            if (!empty($item['addons']) && is_array($item['addons'])) {
                foreach ($item['addons'] as $addon) {
                    $addonRows[] = '(?, ?, ?, ?, 1)';
                    array_push($addonParams, $orderItemId, $addon['id'] ?? null, $addon['name'] ?? '', $addon['price'] ?? 0);
                }
            }
        }
        if (!empty($global_addons) && is_array($global_addons)) {
            foreach ($global_addons as $gAddon) {
                $gOrderItemId = $firstOrderItemId + $rowIndex;
                $rowIndex++;
                $addonRows[] = '(?, ?, ?, ?, ?)';
                array_push($addonParams, $gOrderItemId, (int)($gAddon['id'] ?? 0), $gAddon['name'] ?? 'Add-on', (float)($gAddon['price'] ?? 0), (int)($gAddon['quantity'] ?? 1));
            }
        }
        if (!empty($addonRows)) {
            try {
                $addonSql = "INSERT INTO order_item_addons (order_item_id, addon_id, addon_name, addon_price, quantity) VALUES " . implode(', ', $addonRows);
                $conn->prepare($addonSql)->execute($addonParams);
            } catch (PDOException $e) {
                // order_item_addons table might not exist, skip
            }
        }
    }
    // --- End order item + addon insertion ---
    
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

    // Determine payment gateway. Computed here (before KOT/notifications)
    // because PhonePe orders must NOT alert the kitchen/staff/customer yet —
    // nobody has actually paid until phonepe_order_callback.php or the
    // client status poll verifies the payment and calls
    // fireOrderConfirmedActions() itself.
    $usePhonePe = ($payment_method === 'UPI / NetBanking');

    // Create KOT for Dine-in orders so table map shows as occupied.
    // Skipped here for PhonePe orders — fired later once payment is verified.
    if (!$usePhonePe && $order_type === 'Dine-in' && !empty($table_id)) {
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

        // Batched into a single multi-row INSERT (same reasoning as
        // order_items above) instead of one round trip per item.
        $kotItemRows = [];
        $kotItemParams = [];
        foreach ($items as $item) {
            if ($kotItemsHasAddons) {
                $addonsJson = !empty($item['addons']) ? json_encode($item['addons'], JSON_UNESCAPED_UNICODE) : null;
                $kotItemRows[] = '(?, ?, ?, ?, ?, ?, ?)';
                array_push($kotItemParams,
                    $kotId, (int)$item['id'], (string)$item['name'], (int)$item['quantity'],
                    (float)$item['price'], (float)($item['price'] * $item['quantity']), $addonsJson
                );
            } else {
                $kotItemRows[] = '(?, ?, ?, ?, ?, ?)';
                array_push($kotItemParams,
                    $kotId, (int)$item['id'], (string)$item['name'], (int)$item['quantity'],
                    (float)$item['price'], (float)($item['price'] * $item['quantity'])
                );
            }
        }
        if (!empty($kotItemRows)) {
            $kotItemCols = $kotItemsHasAddons
                ? "(kot_id, menu_item_id, item_name, quantity, unit_price, total_price, addons)"
                : "(kot_id, menu_item_id, item_name, quantity, unit_price, total_price)";
            $kotItemSql = "INSERT INTO kot_items $kotItemCols VALUES " . implode(', ', $kotItemRows);
            $conn->prepare($kotItemSql)->execute($kotItemParams);
        }
    }

    // Commit transaction
    $conn->commit();

    // WhatsApp settings needed for the response payload below
    $waSettings = $restaurantRow;
    $whatsappEnabled = $waSettings ? (int)($waSettings['whatsapp_orders'] ?? 0) : 0;
    $whatsappPhone = $waSettings ? (string)($waSettings['phone'] ?? '') : '';

    // Send the response to the browser NOW, before any slow post-commit work
    // (push notifications, SMTP email) — the order is already safely saved,
    // so the customer's "Placing order..." button shouldn't sit frozen
    // waiting on a mail server that might be slow or unreachable.
    ob_end_clean();
    $responseBody = json_encode([
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

    if (function_exists('fastcgi_finish_request')) {
        // PHP-FPM: this genuinely closes the client connection while the
        // worker keeps running the code below.
        echo $responseBody;
        fastcgi_finish_request();
    } else {
        // Classic Apache mod_php (this app's own XAMPP dev setup, and common
        // on shared hosting) has no fastcgi_finish_request. Without an
        // explicit Content-Length, PHP falls back to chunked transfer
        // encoding, and a browser's fetch() doesn't consider a chunked
        // response "done" until the terminating chunk — which only gets
        // written once the whole script (including the SMTP/push work
        // below) finishes. So the earlier ob_end_clean()+echo alone did NOT
        // actually get the customer their response early on this SAPI.
        // Setting Content-Length forces a plain, non-chunked body that ends
        // exactly at these bytes, so the connection can close (and the
        // browser resolve) right here instead.
        ignore_user_abort(true);
        if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }
        @ini_set('zlib.output_compression', '0');
        header('Content-Length: ' . strlen($responseBody));
        header('Connection: close');
        echo $responseBody;
        if (session_id()) { session_write_close(); }
        // Pop every buffering level (this app's own ob_start() at the top of
        // the file, plus XAMPP/php.ini's own implicit output_buffering=4096
        // wrapping the whole script) so the bytes actually leave PHP instead
        // of sitting in a buffer until script end.
        while (ob_get_level() > 0) { @ob_end_flush(); }
        @flush();
    }

    // Payment methods that are confirmed at order time (Cash, Business QR
    // proof) get the kitchen ticket + push notification + confirmation
    // emails right away. PhonePe orders wait until payment is independently
    // verified — fireOrderConfirmedActions() is called from
    // phonepe_order_callback.php / phonepe_order_payment.php instead.
    if (!$usePhonePe) {
        require_once __DIR__ . '/../config/order_confirmation.php';
        try {
            fireOrderConfirmedActions($conn, $order_id);
        } catch (Exception $e) {
            error_log('Order confirmation actions error: ' . $e->getMessage());
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

