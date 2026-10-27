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

// strip_tags(): these fields are free text from an anonymous, unauthenticated
// checkout and get rendered on staff-facing screens (KOT board, order
// details, customer lists) — some of which auto-refresh. Stripping markup
// here is defense in depth alongside escaping every render site; it doesn't
// replace that escaping, since this is the only checkpoint anything written
// here ever passes through.
// A text field sent as an array/object would crash trim()/strip_tags()/preg_match(): treat it as empty.
$inStr = function ($v, $default = '') { return is_scalar($v) ? (string)$v : $default; };
$customer_name = trim(strip_tags($inStr($input['customer_name'] ?? '')));
$customer_phone = $inStr($input['customer_phone'] ?? '');
$customer_email = $inStr($input['customer_email'] ?? '');
// a real address or none: order emails must not go to whatever was typed (or be used to spam someone)
if ($customer_email !== '' && !filter_var(trim($customer_email), FILTER_VALIDATE_EMAIL)) $customer_email = '';
$customer_email = trim($customer_email);
$customer_address = trim(strip_tags($inStr($input['customer_address'] ?? '')));
$landmark = trim(strip_tags($inStr($input['landmark'] ?? '')));
if ($landmark === '') $landmark = null;
$address_lat = isset($input['address_lat']) && $input['address_lat'] !== '' ? (float)$input['address_lat'] : null;
$address_lng = isset($input['address_lng']) && $input['address_lng'] !== '' ? (float)$input['address_lng'] : null;
$notes = trim(strip_tags($inStr($input['notes'] ?? '')));
$order_type = $input['order_type'] ?? 'Delivery';
$table_id = $input['table_id'] ?? null;
// Ensure table_id is null if it's an empty string (e.g. for Delivery or non-Dine-in orders)
if ($table_id === '' || $table_id === 0) $table_id = null;
$items = is_array($input['items'] ?? null) ? $input['items'] : [];
// Offers > Combos & Deals: [{id, quantity, picks: {slot id: choice id}}], priced on the server (config/combo_helpers.php)
$combos_in = is_array($input['combos'] ?? null) ? array_slice($input['combos'], 0, 20) : [];
$global_addons = $input['global_addons'] ?? [];
$total = $input['total'] ?? 0;
$total_payable = $input['total_payable'] ?? $total;
$payment_method = $inStr($input['payment_method'] ?? 'Cash', 'Cash');

// Optional payment-proof screenshot (customer uploads this when paying via
// the restaurant's business QR). Stored separately in payment_proofs, not on
// the orders row, so it starts life unconfirmed and never counts as revenue
// until the restaurant explicitly reviews and confirms it.
$paymentProofBytes = null;
$paymentProofMime = null;
$payment_proof_base64 = $inStr($input['payment_proof_base64'] ?? '');
if ($payment_proof_base64 !== '' && preg_match('/^data:(image\/(?:jpeg|jpg|png|webp|gif));base64,(.+)$/i', $payment_proof_base64, $proofMatch)) {
    $decodedProof = base64_decode($proofMatch[2], true);
    if ($decodedProof !== false && strlen($decodedProof) > 0 && strlen($decodedProof) <= 5 * 1024 * 1024) {
        $paymentProofBytes = $decodedProof;
        $paymentProofMime = strtolower($proofMatch[1]) === 'image/jpg' ? 'image/jpeg' : strtolower($proofMatch[1]);
    }
}
$coupon_code = $inStr($input['coupon_code'] ?? '');
$discount_amount = (float)($input['discount_amount'] ?? 0);
$redeem_points_requested = max(0, (int)($input['redeem_points'] ?? 0));
$use_wallet = !empty($input['use_wallet']);
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

if (empty($items) && empty($combos_in)) {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Cart is empty'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// ── Abuse protection ────────────────────────────────────────────────────────
// The phone is a guest checkout's only identity — it is checked against the
// restaurant's country once the database is open (start of the try below).
require_once __DIR__ . '/../config/order_abuse_guard.php';

$abuse_ip = orderAbuseGetClientIp();
// INET6_ATON returns NULL on a malformed IP, which would silently NULL the
// customer_ip column (or trip strict mode) — only accept sane values.
if (!filter_var($abuse_ip, FILTER_VALIDATE_IP)) {
    $abuse_ip = null;
}
// WhatsApp orders (config/wa_order_engine.php) are sent by this server with a signed header: the
// request's IP is the server's own, so the per-IP limits below would block a busy restaurant.
// The per-phone limits still apply (the WhatsApp number is the customer's real number).
$rgInternalOrder = false;
if (!empty($_SERVER['HTTP_X_RG_INTERNAL'])) {
    require_once __DIR__ . '/../config/wa_order_engine.php';
    $rgInternalOrder = woVerifyInternal((string)file_get_contents('php://input'));
    if ($rgInternalOrder) $abuse_ip = null;
}

try {
    $conn = getConnection();
    require_once __DIR__ . '/../config/scheduled_order_helpers.php';
    ensureOrdersScheduleColumns($conn);

    // Resolve restaurant ID: the website page says which restaurant this cart is for, so that
    // comes first. A dashboard login in the same browser (owner, staff, the demo account) must
    // not send a customer's order to a different restaurant.
    $restaurant_id = trim((string)($_GET['restaurant_id'] ?? '')) !== ''
        ? trim((string)$_GET['restaurant_id'])
        : ($_SESSION['restaurant_id'] ?? 'RES001');

    // Reject implausible phones by the restaurant's country: 10 digits from 6-9 in
    // India, the local number length elsewhere (a UAE mobile has 9 digits)
    $restaurantCountry = null;
    try {
        $ctySt = $conn->prepare('SELECT country FROM users WHERE restaurant_id = ? LIMIT 1');
        $ctySt->execute([$restaurant_id]);
        $restaurantCountry = $ctySt->fetchColumn() ?: null;
    } catch (PDOException $e) { /* no country column yet: India */ }
    $phoneCheck = orderAbuseValidatePhone($customer_phone, $restaurantCountry);
    if (!$phoneCheck['valid']) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => $phoneCheck['message']], JSON_UNESCAPED_UNICODE);
        exit();
    }
    $customer_phone = $phoneCheck['value']; // store the normalized form

    // ── Abuse protection (needs DB + restaurant) ───────────────────────────
    // Restaurant-wide velocity cap: total website orders (any customer, any
    // IP) in the last 10 minutes. Checked first because it's the only cap
    // that can't be evaded by rotating phones or IPs — the Sept 2026 flood
    // used 7,670 sequential phones across rotating IPv6 addresses, so every
    // per-identity cap stayed under its threshold while 14.6k junk orders
    // piled up. 40/10min is far past a small restaurant's real throughput.
    $globalFlood = orderAbuseCountRecentOrdersGlobal($conn, $restaurant_id, 10);
    if ($globalFlood >= 40) {
        ob_end_clean();
        http_response_code(429);
        error_log(sprintf('order-abuse: GLOBAL velocity cap hit — restaurant %s has %d website orders in 10min', $restaurant_id, $globalFlood));
        echo json_encode([
            'success' => false,
            'message' => 'The restaurant is receiving an unusually high number of orders right now. Please try again in a few minutes or order by calling the restaurant.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // IP flood cap: 5 website orders per IP per 30 minutes. A real customer
    // places one or two; a script hammering the endpoint hits this first.
    $orderFlood = $rgInternalOrder ? 0 : orderAbuseCountRecentOrdersByIp($conn, $restaurant_id, $abuse_ip, 30);
    if ($orderFlood >= 5) {
        ob_end_clean();
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'Too many orders have been placed from your network recently. Please try again later or order by calling the restaurant.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // IP-prefix flood cap: 12 orders per IPv4 /24 (or IPv6 /64) per 30 min.
    // The flood bot rotated many IPv6 addresses inside the same /64 — exact-IP
    // caps each saw one order, the /64 bucket sees them all. 12 leaves headroom
    // for offices/campuses where many customers share one egress prefix.
    $ipPrefixFlood = $rgInternalOrder ? 0 : orderAbuseCountRecentOrdersByIpPrefix($conn, $restaurant_id, $abuse_ip, 30);
    if ($ipPrefixFlood >= 12) {
        ob_end_clean();
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'Too many orders have been placed from your network recently. Please try again later or order by calling the restaurant.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Per-phone daily cap: 8 website orders per number per 24h. Genuine
    // reorders are rare; this stops one number from spamming all day.
    $phoneFlood = orderAbuseCountRecentOrdersByPhone($conn, $restaurant_id, $customer_phone, 24);
    if ($phoneFlood >= 8) {
        ob_end_clean();
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'This mobile number has placed several orders today. Please call the restaurant to place more orders.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Phone-prefix cap: 15 orders sharing the first 6 digits in 24h. Catches
    // sequential-number rotation (the flood's numbers all shared '91111122').
    // 15 is above any plausible real cluster — a family sharing a carrier
    // prefix doesn't place 15 website orders at one restaurant in a day.
    $phonePrefixFlood = orderAbuseCountRecentOrdersByPhonePrefix(
        $conn, $restaurant_id, orderAbusePhonePrefix($customer_phone, 6), 24
    );
    if ($phonePrefixFlood >= 15) {
        ob_end_clean();
        http_response_code(429);
        error_log(sprintf('order-abuse: phone-prefix cap hit — restaurant %s phone %s (prefix %s)', $restaurant_id, $customer_phone, orderAbusePhonePrefix($customer_phone, 6)));
        echo json_encode([
            'success' => false,
            'message' => 'This number appears to be placing automated orders. Please call the restaurant to place your order.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Blocklist: reject phones/IPs the restaurant blocked manually, or that
    // were auto-blocked after 3 fake-order strikes.
    $abuseBlock = orderAbuseIsBlocked($conn, $restaurant_id, $customer_phone, $abuse_ip);
    if ($abuseBlock) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'You are unable to place orders at this restaurant. Please contact the restaurant directly if you believe this is a mistake.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Fetch every restaurant setting this endpoint needs in ONE round trip.
    // Previously this was 7 separate `SELECT ... FROM users WHERE
    // restaurant_id = ?` queries scattered through the file (subscription
    // status, min order/packaging, COD/payment gateway, opening hours,
    // KM-delivery settings, GST, WhatsApp) — each one a full network round
    // trip to the DB before the customer's order could even start being
    // validated. Only the columns this database really has are asked for: a
    // newer migration (km-delivery, cod_enabled, ...) missing on one server
    // used to send every order through a reduced fallback query that also
    // dropped the business-QR flag (every QR order refused), COD-off and
    // per-km delivery pricing. A missing column now just keeps its default.
    $wantedCols = ['subscription_status', 'minimum_order_value', 'packaging_charge', 'cod_enabled', 'payment_gateway_mode', 'phonepe_merchant_id',
        'opening_hours', 'timezone', 'enable_km_delivery', 'delivery_rate_per_km', 'delivery_radius_km', 'restaurant_lat', 'restaurant_lng',
        'enable_gst', 'tax_name', 'tax_percent', 'whatsapp_orders', 'phone', 'enable_dinein', 'enable_takeaway', 'enable_delivery'];
    $haveCols = [];
    foreach ($conn->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) as $col) $haveCols[$col['Field']] = true;
    $selectCols = array_values(array_filter($wantedCols, function ($c) use ($haveCols) { return isset($haveCols[$c]); }));
    if (isset($haveCols['business_qr_code_data'])) $selectCols[] = '(business_qr_code_data IS NOT NULL) AS has_business_qr';
    $userStmt = $conn->prepare('SELECT ' . ($selectCols ? implode(', ', $selectCols) : 'restaurant_id') . ' FROM users WHERE restaurant_id = ? LIMIT 1');
    $userStmt->execute([$restaurant_id]);
    $restaurantRow = $userStmt->fetch(PDO::FETCH_ASSOC);

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

    // Room service from a hotel room's QR code (config/rooms.php roomServiceFind, cart.php room_qr): a dine-in
    // order with no table, for that room, allowed even when dine-in is off. "Room bill" puts it on the stay's bill
    // (paid at checkout), only for the guest checked in to that room, with the phone number of that stay.
    $roomService = null;
    $room_qr = $inStr($input['room_qr'] ?? '');
    if ($room_qr !== '') {
        require_once __DIR__ . '/../config/rooms.php';
        $roomService = roomsFeatureEnabled($conn, $restaurant_id) ? roomServiceFind($conn, $restaurant_id, $room_qr) : null;
        if (!$roomService) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'This room\'s QR code doesn\'t work any more. Please call reception to order.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $order_type = 'Dine-in';
        $table_id = null;
        $notes = 'ROOM SERVICE - Room ' . $roomService['room'] . ($notes !== '' ? ' | ' . $notes : '');
    }
    if ($payment_method === 'Room bill') {
        $roomStay = $roomService['booking'] ?? null;
        if (!$roomStay || empty($roomService['room_charge'])) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Adding to the room bill is only for the guest staying in this room. Please choose another way to pay.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        if (!roomPhoneSame((string)$customer_phone, (string)$roomStay['guest_phone'])) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'To add this to your room bill, please use the phone number the room was booked with.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $use_wallet = false;   // the whole order goes on the room bill
    }

    // Enforce order-type availability server-side. index.php/header.php hide
    // a disabled type's tab and cart.php filters it out of the checkout
    // options, but neither of those is authoritative — a direct call to this
    // API could still submit a type the owner has turned off.
    $orderTypeColumnMap = ['Dine-in' => 'enable_dinein', 'Takeaway' => 'enable_takeaway', 'Delivery' => 'enable_delivery'];
    $orderTypeColumn = $orderTypeColumnMap[$order_type] ?? null;
    if ($orderTypeColumn && !$roomService && isset($restaurantRow[$orderTypeColumn]) && (int)$restaurantRow[$orderTypeColumn] === 0) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => $order_type . ' orders are not available for this restaurant right now.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Fetch packaging charge from DB (server-authoritative)
    $minRow = $restaurantRow;
    // Use DB packaging_charge instead of client-provided value to prevent price manipulation
    $packaging_charge = (float)($minRow['packaging_charge'] ?? 0);
    if ($order_type === 'Dine-in') $packaging_charge = 0;   // eaten at the table: no packing (the cart shows none either)
    // Minimum-order-value is checked further below, once $total has been
    // recalculated from verified menu-item prices — checking it here against
    // the raw client-submitted $total let a client submit an inflated total
    // just to clear this gate while the real (smaller) cart still got
    // through, silently defeating the restaurant's minimum-order policy.
    $minOrderValue = (float)($minRow['minimum_order_value'] ?? 0);

    // Only the methods the website offers. 'Wallet' is set by the server when the wallet covers the whole
    // order; a client sending 'Wallet' / 'Online' / anything else must not get an unpaid order that looks prepaid.
    if (in_array($payment_method, ['PhonePe', 'Online', 'UPI', 'NetBanking'], true)) $payment_method = 'UPI / NetBanking';
    if (!in_array($payment_method, ['Cash', '', 'UPI / NetBanking', 'QR Payment'], true) && $payment_method !== 'Room bill') {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Please choose how you want to pay.'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    if ($payment_method === 'QR Payment' && (int)($restaurantRow['has_business_qr'] ?? 0) !== 1) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Paying by QR is not available here. Please choose another way to pay.'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    // Online payment really available here (keys set, India, not switched to cash-only by
    // Super Admin)? Same rule as the cart page (cart.php $phonepe_configured), used both for
    // UPI orders and for deciding whether "COD off" can be honoured below.
    $onlinePaymentReady = function () use ($conn, $restaurant_id) {
        static $ready = null;
        if ($ready !== null) return $ready;
        require_once __DIR__ . '/../config/phonepe_verify.php';
        $ppCfg = phonepeCallbackConfig($restaurant_id);
        $gwType = '';
        try { $gt = $conn->prepare('SELECT payment_gateway_type FROM users WHERE restaurant_id = ? LIMIT 1'); $gt->execute([$restaurant_id]); $gwType = (string)$gt->fetchColumn(); } catch (Throwable $e) {}
        return $ready = !($gwType === '' || $gwType === 'cash_only' || (!$ppCfg['ready'] && !$ppCfg['demo']));
    };
    if ($payment_method === 'UPI / NetBanking' && !$onlinePaymentReady()) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Online payment is not available at this restaurant right now. Please choose cash or another way to pay.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Enforce COD availability server-side. The client already hides the Cash
    // option when disabled, but that alone isn't authoritative.
    $codEnabled = true;
    $codRow = $restaurantRow;
    if ($codRow && isset($codRow['cod_enabled']) && (int)$codRow['cod_enabled'] === 0) {
        // Online payment counts only when the customer can really use it (the cart's rule):
        // a stored merchant id with the gateway off used to refuse Cash while the cart
        // offered nothing else, so nobody could order.
        $hasOnlinePayment = (int)($codRow['has_business_qr'] ?? 0) === 1 || $onlinePaymentReady();
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

    // Restaurant's own configured timezone — not hardcoded IST. Opening/
    // closing times, and any scheduled_at a customer submits, are wall-clock
    // values meant in the restaurant's local time (e.g. Nepal restaurants
    // set them in NPT, UTC+5:45), so comparing them against IST would be off
    // by 15 minutes and could wrongly accept/reject orders near open/close.
    $hoursRow = $restaurantRow;
    $restaurantTzName = (!empty($hoursRow['timezone']) && in_array($hoursRow['timezone'], timezone_identifiers_list(), true))
        ? $hoursRow['timezone'] : 'Asia/Kolkata';
    $restaurantTz = new DateTimeZone($restaurantTzName);

    // "Schedule for later": customer wants the order prepared at a future
    // time instead of right now. Validated here (needs the restaurant's
    // timezone, already resolved above) — a valid request skips the
    // "currently closed" rejection below, since the whole point is placing
    // an order for a future slot while the kitchen may be closed right now.
    $is_scheduled_order = false;
    $scheduled_at_db = null;
    $scheduledDT = null;
    $scheduled_at_input = trim((string)($input['scheduled_at'] ?? ''));
    if ($scheduled_at_input !== '') {
        try {
            $scheduledDT = new DateTime($scheduled_at_input, $restaurantTz);
        } catch (Throwable $e) {
            $scheduledDT = null;
        }
        if (!$scheduledDT) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid scheduled time.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $nowInTz = new DateTime('now', $restaurantTz);
        $minLead = (clone $nowInTz)->modify('+15 minutes');
        $maxLead = (clone $nowInTz)->modify('+7 days');
        if ($scheduledDT < $minLead || $scheduledDT > $maxLead) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Please choose a time at least 15 minutes from now and within the next 7 days.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $is_scheduled_order = true;
        $scheduled_at_db = $scheduledDT->format('Y-m-d H:i:s');
    }

    // Check opening hours — for a normal order this is "right now"; for a
    // scheduled order it's the requested slot itself, so a customer can't
    // schedule for a time the kitchen will be closed.
    if ($hoursRow && !empty($hoursRow['opening_hours'])) {
        $hours = json_decode($hoursRow['opening_hours'], true);
        if ($hours) {
            $checkDT = $is_scheduled_order ? $scheduledDT : new DateTime('now', $restaurantTz);
            $closedMessage = $is_scheduled_order
                ? 'The restaurant is closed at that time. Please pick a different slot.'
                : 'Our restaurant is currently closed. Please come back during our opening hours!';
            // config/opening_hours.php: also counts yesterday's shift running past midnight
            require_once __DIR__ . '/../config/opening_hours.php';
            if (!rgIsOpenNow($hours, $restaurantTz, $checkDT)) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => $closedMessage], JSON_UNESCAPED_UNICODE);
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
        // Delivery radius (Settings), for every pricing model — it used to be checked only with
        // per-km pricing, so a 5 km restaurant took orders from 30 km away. Only when both
        // places have map coordinates (an address typed without a map pin is not refused).
        $radiusAll = (float)($kmRow['delivery_radius_km'] ?? 0);
        $rLat = isset($kmRow['restaurant_lat']) && $kmRow['restaurant_lat'] !== null ? (float)$kmRow['restaurant_lat'] : null;
        $rLng = isset($kmRow['restaurant_lng']) && $kmRow['restaurant_lng'] !== null ? (float)$kmRow['restaurant_lng'] : null;
        if ($radiusAll > 0 && $rLat && $rLng && $address_lat && $address_lng) {
            $dLatR = deg2rad($address_lat - $rLat);
            $dLonR = deg2rad($address_lng - $rLng);
            $aR = sin($dLatR / 2) ** 2 + cos(deg2rad($rLat)) * cos(deg2rad($address_lat)) * sin($dLonR / 2) ** 2;
            $distR = 6371 * 2 * atan2(sqrt($aR), sqrt(1 - $aR));
            if ($distR > $radiusAll) {
                ob_end_clean();
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "Sorry, we don't deliver to your address. Your location is " . round($distR, 1) . " km away, but our delivery radius is only " . $radiusAll . " km.",
                    'error_code' => 'OUTSIDE_DELIVERY_RADIUS'
                ], JSON_UNESCAPED_UNICODE);
                exit();
            }
        }
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
            } elseif ($radiusKm > 0 && $ratePerKm > 0) {
                // No map location sent: charge as for the far edge of the delivery area. Leaving the
                // location out used to make delivery free (it never trusts a charge sent by the browser).
                $delivery_charge = round($radiusKm * $ratePerKm, 2);
            } elseif ($ratePerKm > 0) {
                ob_end_clean();
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please pick your delivery location on the map so we can work out the delivery charge.',
                    'error_code' => 'LOCATION_REQUIRED'], JSON_UNESCAPED_UNICODE);
                exit();
            } else {
                $delivery_charge = 0;   // km pricing on but no rate set: free delivery, as the restaurant set it
            }
        } elseif ($delivery_zone_id) {
            // ── Pincode/zone-based delivery (the default model) ──
            // The client-submitted delivery_charge was previously used
            // verbatim — a modified checkout request could set it to 0 on
            // every order. delivery_zone_id itself isn't secret (it's
            // returned by the public check_pincode.php lookup), so re-fetch
            // the zone's actual rate from the DB and use that instead of
            // trusting the number that came with it.
            $zoneStmt = $conn->prepare("SELECT delivery_charge FROM delivery_zones WHERE id = ? AND restaurant_id = ? AND is_active = 1 LIMIT 1");
            $zoneStmt->execute([$delivery_zone_id, $restaurant_id]);
            $zoneRow = $zoneStmt->fetch(PDO::FETCH_ASSOC);
            $delivery_charge = $zoneRow ? (float)$zoneRow['delivery_charge'] : 0;
        } else {
            // No km pricing and no zone: the checkout never sends a charge here
            $delivery_charge = 0;
        }
    } else {
        // Dine-in / Takeaway carry no delivery charge (a negative one would discount the bill)
        $delivery_charge = 0;
    }

if ($use_wallet) {
    // Wallet tables must exist before the transaction (creating them would commit it)
    require_once __DIR__ . '/../config/wallet_helpers.php';
    require_once __DIR__ . '/../website/customer_session.php';
    ensureWalletTables($conn);
    $walletCustomer = getLoggedInCustomer($conn, $restaurant_id);   // may touch tables: before the transaction
}

// VIP membership perks (Loyalty Studio > VIP): only for a logged-in member ordering
// with their own phone number. Tables first (creating them would commit the transaction).
$vipCustomer = null;
try {
    require_once __DIR__ . '/../config/vip_membership.php';
    require_once __DIR__ . '/../website/customer_session.php';
    ensureVipSchema($conn);
    $vipCustomer = isset($walletCustomer) ? $walletCustomer : getLoggedInCustomer($conn, $restaurant_id);
} catch (Throwable $e) {
    error_log('VIP perks lookup: ' . $e->getMessage());
}

// A table from an old QR code or another restaurant (deleted, re-created) must not sink the
// whole order on the tables foreign key: keep the order as dine-in without a table instead.
if ($table_id !== null) {
    $tblCheck = $conn->prepare('SELECT id FROM tables WHERE id = ? AND restaurant_id = ? LIMIT 1');
    $tblCheck->execute([(int)$table_id, $restaurant_id]);
    $foundTable = $tblCheck->fetchColumn();
    if ($foundTable) {
        $table_id = (int)$foundTable;
    } else {
        error_log("Website order: table $table_id not found for $restaurant_id, saved without a table");
        $table_id = null;
    }
}

// bill rule columns are added before the transaction (an ALTER TABLE would end it)
require_once __DIR__ . '/../config/bill_rules.php';
billRulesEnsure($conn);
$conn->beginTransaction();
    
    // Validate coupon if provided (WITH row lock to prevent race condition)
    if (!empty($coupon_code)) {
        // valid dates are the restaurant's own calendar days (this connection's CURDATE() is India time)
        $todayLocal = (new DateTime('now', $restaurantTz))->format('Y-m-d');
        $cpnStmt = $conn->prepare("SELECT * FROM coupons WHERE restaurant_id = ? AND coupon_code = ? AND is_active = 1 AND (valid_from IS NULL OR valid_from <= ?) AND (valid_until IS NULL OR valid_until >= ?) AND (max_uses = 0 OR current_uses < max_uses) LIMIT 1 FOR UPDATE");
        $cpnStmt->execute([$restaurant_id, $coupon_code, $todayLocal, $todayLocal]);
        $coupon = $cpnStmt->fetch(PDO::FETCH_ASSOC);
        // Coupons switched off by Super Admin: only personal reward codes (loyalty, scratch card) still apply
        require_once __DIR__ . '/../config/access_control.php';
        if ($coupon && empty($coupon['owner_phone']) && !accessPageOn($conn, (string)$restaurant_id, 'couponsPage')) $coupon = false;
        if (!$coupon) {
            $conn->rollBack();
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid or expired coupon code'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        // Scratch card reward: only for the phone that won it, and not if that order was cancelled
        if (!empty($coupon['owner_phone'])) {
            // a personal code (gift, scratch card, birthday…) works only for its owner, logged in with that phone:
            // the phone typed at checkout proves nothing
            $ownerNorm = orderAbuseNormalizePhone((string)$coupon['owner_phone']) ?? (string)$coupon['owner_phone'];
            $loggedNorm = $vipCustomer ? (orderAbuseNormalizePhone((string)$vipCustomer['phone']) ?? (string)$vipCustomer['phone']) : null;
            if ($loggedNorm === null) {
                $conn->rollBack();
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Log in with your phone number to use this reward code.'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $rewardOk = $ownerNorm === $loggedNorm && $customer_phone === $loggedNorm;
            if ($rewardOk) {
                try {
                    $src = $conn->prepare("SELECT o.order_status FROM scratch_cards sc JOIN orders o ON o.id = sc.order_id WHERE sc.restaurant_id = ? AND sc.coupon_code = ? LIMIT 1");
                    $src->execute([$restaurant_id, $coupon['coupon_code']]);
                    $srcStatus = $src->fetchColumn();
                    if (in_array($srcStatus, ['Cancelled', 'Rejected'], true)) $rewardOk = false;
                } catch (PDOException $e) { /* table not there yet */ }
            }
            if (!$rewardOk) {
                $conn->rollBack();
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'This reward code can only be used by the customer who won it'], JSON_UNESCAPED_UNICODE);
                exit();
            }
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
        // a dish in a category the restaurant switched off counts as not available
        $menuStmt = $conn->prepare("SELECT mi.id, mi.menu_id, mi.item_name_en, mi.base_price, (mi.is_available = 1 AND (m.id IS NULL OR m.is_active = 1)) AS is_available
            FROM menu_items mi LEFT JOIN menu m ON m.id = mi.menu_id AND m.restaurant_id = mi.restaurant_id WHERE mi.id IN ($placeholders) AND mi.restaurant_id = ?");
        $menuStmt->execute(array_merge($menuItemIds, [$restaurant_id]));
        foreach ($menuStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $menuItemsById[(int)$row['id']] = $row;
        }
    }

    $variationsByItem = [];
    $soldOutVariations = [];
    if (!empty($menuItemIds)) {
        $placeholders = implode(',', array_fill(0, count($menuItemIds), '?'));
        // Also scoped to these menu items, which are already scoped to this
        // restaurant above, so a variation can't be borrowed from another
        // restaurant's item either.
        // is_available: a size marked sold out is refused below (the menu hides it, but one
        // already sitting in a cart used to go through)
        try {
            $varStmt = $conn->prepare("SELECT menu_item_id, variation_name, price, is_available FROM menu_item_variations WHERE menu_item_id IN ($placeholders)");
            $varStmt->execute($menuItemIds);
        } catch (PDOException $e) {
            $varStmt = $conn->prepare("SELECT menu_item_id, variation_name, price, 1 AS is_available FROM menu_item_variations WHERE menu_item_id IN ($placeholders)");
            $varStmt->execute($menuItemIds);
        }
        foreach ($varStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $variationsByItem[(int)$row['menu_item_id']][$row['variation_name']] = (float)$row['price'];
            if ($row['is_available'] !== null && (int)$row['is_available'] === 0) $soldOutVariations[(int)$row['menu_item_id']][$row['variation_name']] = true;
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

    // Customer's per-dish cooking instruction: plain text, one line, 150 chars max
    if (!function_exists('cleanItemInstruction')) {
        function cleanItemInstruction($s) {
            $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$s)));
            return mb_substr($s, 0, 150);
        }
    }

    // --- Server-side price verification ---
    // Verifies menu item prices, variation prices, AND addon prices
    // against the database to prevent price manipulation by clients.
    $verifiedItems = [];
    $calculatedSubtotal = 0;
    $calculatedAddonTotal = 0;
    // Bestseller offer prices (Offers > Bestsellers) are real prices — the
    // website menu shows them, so charge them too (see bestseller_helpers.php).
    require_once __DIR__ . '/../config/bestseller_helpers.php';
    $bestsellerMap = getBestsellerMap($conn, $restaurant_id);
    // menus by time of day (config/menu_hours.php): a dish whose category isn't served at the order
    // time (or the scheduled time) is refused with its hours, not dropped quietly
    require_once __DIR__ . '/../config/menu_hours.php';
    $closedCats = menuHoursClosedMap($conn, $restaurant_id, $is_scheduled_order && $scheduledDT ? $scheduledDT : null);
    if ($closedCats) {
        foreach ($items as $item) {
            $row = $menuItemsById[(int)($item['id'] ?? 0)] ?? null;
            if ($row && isset($closedCats[(int)$row['menu_id']])) {
                if ($conn->inTransaction()) $conn->rollBack();
                ob_end_clean();
                echo json_encode(['success' => false, 'code' => 'not_served_now', 'message' => $row['item_name_en'] . ' is ' . lcfirst($closedCats[(int)$row['menu_id']]) . '. Please remove it from the cart' . ($is_scheduled_order ? ' or pick another time.' : '.')], JSON_UNESCAPED_UNICODE);
                exit();
            }
        }
    }
    foreach ($items as $item) {
        $menuItemId = (int)($item['id'] ?? 0);
        $quantity = (int)($item['quantity'] ?? 0);
        if ($quantity <= 0) continue;
        if ($quantity > 50) {   // a real customer never needs more; fake giant orders pumped spend / loyalty tiers
            $conn->rollBack();
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'You can order up to 50 of one dish online. For a bigger order, please call the restaurant.'], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $menuRow = $menuItemsById[$menuItemId] ?? null;
        if (!$menuRow || !$menuRow['is_available']) {
            // sold out / removed since it went in the cart: say so (it used to be dropped without a word)
            $conn->rollBack();
            ob_end_clean();
            $gone = $inStr($item['name'] ?? '') !== '' ? $inStr($item['name']) : ($menuRow['item_name_en'] ?? 'A dish in your cart');
            echo json_encode(['success' => false, 'code' => 'item_unavailable', 'item_id' => $menuItemId,
                'message' => mb_substr(strip_tags($gone), 0, 80) . ' is not available right now. Please remove it from your cart and try again.'], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $unitPrice = (float)$menuRow['base_price'];
        $variationName = $inStr($item['variation_name'] ?? '');
        if ($variationName !== '' && !empty($variationsByItem[$menuItemId]) && !isset($variationsByItem[$menuItemId][$variationName])) {
            // a size that no longer exists (renamed / removed): don't quietly charge another price
            $conn->rollBack();
            ob_end_clean();
            echo json_encode(['success' => false, 'code' => 'item_changed', 'item_id' => $menuItemId,
                'message' => mb_substr(strip_tags((string)($menuRow['item_name_en'] ?? 'A dish')), 0, 80) . ' has changed (' . mb_substr(strip_tags($variationName), 0, 40) . ' is no longer offered). Please add it to your cart again.'], JSON_UNESCAPED_UNICODE);
            exit();
        }

        if ($variationName !== '' && isset($soldOutVariations[$menuItemId][$variationName])) {
            $conn->rollBack();
            ob_end_clean();
            echo json_encode(['success' => false, 'code' => 'item_unavailable', 'item_id' => $menuItemId,
                'message' => mb_substr(strip_tags((string)($menuRow['item_name_en'] ?? 'A dish')), 0, 80) . ' (' . mb_substr(strip_tags($variationName), 0, 40) . ') is sold out right now. Please remove it from your cart and try again.'], JSON_UNESCAPED_UNICODE);
            exit();
        }

        if (!empty($variationName) && isset($variationsByItem[$menuItemId][$variationName])) {
            $unitPrice = $variationsByItem[$menuItemId][$variationName];
            // Per-size bestseller offer (Offers > Bestsellers)
            $vOffer = bestsellerVariationOfferPrice($bestsellerMap, $menuItemId, $variationName, $unitPrice);
            if ($vOffer !== null) $unitPrice = $vOffer;
        } else {
            $offer = bestsellerOfferPrice($bestsellerMap, $menuItemId, $unitPrice, !empty($variationsByItem[$menuItemId]));
            if ($offer !== null) $unitPrice = $offer;
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
            // Customer's cooking instruction for this dish (optional, plain text)
            'notes' => cleanItemInstruction($item['notes'] ?? ''),
        ];
        $calculatedSubtotal += $unitPrice * $quantity;
    }

    // --- Combos: checked and priced here (on sale now, each choice allowed and available) ---
    // One priced line for the combo (no dish), then each dish at 0 marked with the combo in its
    // note, so the bill shows the combo price, the kitchen gets every dish and stock follows it.
    $comboSales = [];
    if (!empty($combos_in)) {
        require_once __DIR__ . '/../config/combo_helpers.php';
        $liveCombos = [];
        try { foreach (getCombos($conn, (string)$restaurant_id, true) as $lc) $liveCombos[$lc['id']] = $lc; } catch (Throwable $e) { error_log('combos at checkout: ' . $e->getMessage()); }
        foreach ($combos_in as $ci) {
            $cQty = max(0, min(50, (int)($ci['quantity'] ?? 0)));
            if ($cQty === 0) continue;
            $v = comboVerify($liveCombos, (int)($ci['id'] ?? 0), is_array($ci['picks'] ?? null) ? $ci['picks'] : []);
            if (!$v['ok']) {
                if ($conn->inTransaction()) $conn->rollBack();
                ob_end_clean();
                echo json_encode(['success' => false, 'code' => 'combo_unavailable', 'message' => $v['message']], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $verifiedItems[] = ['id' => null, 'name' => $v['name'], 'variation_name' => 'Combo', 'quantity' => $cQty,
                'price' => $v['unit_price'], 'addons' => [], 'notes' => ''];
            foreach ($v['lines'] as $ln) {
                $verifiedItems[] = ['id' => $ln['menu_item_id'], 'name' => $ln['name'], 'variation_name' => $ln['variation'] ?: '',
                    'quantity' => $ln['qty'] * $cQty, 'price' => 0, 'addons' => [], 'notes' => mb_substr('Combo: ' . $v['name'], 0, 150)];
            }
            $calculatedSubtotal += $v['unit_price'] * $cQty;
            $comboSales[] = [(int)$ci['id'], $cQty, round($v['unit_price'] * $cQty, 2)];
        }
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
            $gQty = min(50, (int)($gAddon['quantity'] ?? 1));
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

    // Minimum-order-value check, against the now server-verified $total —
    // checking this earlier against the raw client-submitted total let a
    // client submit an inflated number just to clear this gate while the
    // real (smaller, verified) cart still went through underneath it.
    if ($minOrderValue > 0 && $total < $minOrderValue) {
        $conn->rollBack();
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Minimum order value is ' . number_format($minOrderValue, 2) . '. Please add more items.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

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

    // --- Loyalty points redemption (server-authoritative) ---
    // Mirrors the coupon verification above: the client can only *request*
    // a number of points to redeem, never a discount amount — the server
    // re-validates the customer's real balance and settings, and caps the
    // points used so the discount never exceeds what's left of the order.
    $loyalty_points_to_redeem = 0;
    $loyalty_discount_amount = 0;
    $loyaltyCustomerId = null;
    // Spending points needs the customer to be logged in with this phone (like the wallet):
    // typing someone else's number must never spend their points.
    $pointsOwnerOk = $vipCustomer && (orderAbuseNormalizePhone((string)$vipCustomer['phone']) ?? (string)$vipCustomer['phone']) === (string)$customer_phone;
    if ($redeem_points_requested > 0 && !$pointsOwnerOk) {
        $conn->rollBack();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Log in with your phone number to use your points.'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    if ($redeem_points_requested > 0) {
        require_once __DIR__ . '/../config/growth_helpers.php';
        $growthSettings = getGrowthSettings($conn, $restaurant_id);
        if (!empty($growthSettings['loyalty_enabled'])) {
            $loyaltyCustLookup = $conn->prepare("SELECT id, loyalty_points_balance FROM customers WHERE restaurant_id = ? AND phone = ? FOR UPDATE");
            $loyaltyCustLookup->execute([$restaurant_id, $customer_phone]);
            $loyaltyCustomerRow = $loyaltyCustLookup->fetch(PDO::FETCH_ASSOC);
            if ($loyaltyCustomerRow) {
                $availablePoints = (int)$loyaltyCustomerRow['loyalty_points_balance'];
                $minRedeem = (int)($growthSettings['min_redeem_points'] ?? 0);
                $redeemValuePerPoint = (float)($growthSettings['redeem_value_per_point'] ?? 0);
                $remainingAfterCoupon = max(0, $total - $discount_amount);
                $maxUsefulPoints = $redeemValuePerPoint > 0 ? (int)floor($remainingAfterCoupon / $redeemValuePerPoint) : 0;
                $candidatePoints = min($redeem_points_requested, $availablePoints, $maxUsefulPoints);
                if ($candidatePoints > 0 && $candidatePoints >= $minRedeem) {
                    $loyalty_points_to_redeem = $candidatePoints;
                    $loyalty_discount_amount = round($loyalty_points_to_redeem * $redeemValuePerPoint, 2);
                    $loyaltyCustomerId = (int)$loyaltyCustomerRow['id'];
                }
            }
        }
    }
    // --- End loyalty points verification ---

    // --- VIP membership: % off the food and free delivery (server-authoritative) ---
    $vip_discount = 0.0;
    $vip_free_delivery = false;
    if ($vipCustomer && (orderAbuseNormalizePhone((string)$vipCustomer['phone']) ?? (string)$vipCustomer['phone']) === (string)$customer_phone) {
        $vipPerks = vipCheckoutPerks($conn, $restaurant_id, (string)$customer_phone, max(0, $total - $discount_amount - $loyalty_discount_amount), $order_type, (float)$total);
        if ($vipPerks) {
            $vip_discount = (float)$vipPerks['discount'];
            if ($vipPerks['free_delivery'] && $delivery_charge > 0) { $delivery_charge = 0; $vip_free_delivery = true; }
        }
    }

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
        $gstEnabled = ($gstRow && isset($gstRow['enable_gst'])) ? (bool)$gstRow['enable_gst'] : true;   // same default as the cart (header.php)
        if ($gstRow && !empty($gstRow['tax_name'])) $taxName = $gstRow['tax_name'];
        if ($gstRow && isset($gstRow['tax_percent']) && $gstRow['tax_percent'] !== null) $taxPercent = (float)$gstRow['tax_percent'];
    } catch (Throwable $e) {
        $gstEnabled = true;
    }

    // Calculate all financials NOW before any writes
    $deliveryCharge = (float)$delivery_charge;
    $subtotal = (float)$total;
    // Tax is on the discounted subtotal only — packaging is NOT taxable
    $taxable = $subtotal - (float)$discount_amount - (float)$loyalty_discount_amount - (float)$vip_discount;
    if ($taxable < 0) $taxable = 0;
    // tax on top or inside the price, and a service charge: the restaurant's bill rule (config/bill_rules.php)
    require_once __DIR__ . '/../config/bill_rules.php';
    $billCalc = billMath($taxable, billRules($conn, $restaurant_id));
    $tax = $billCalc['tax'];
    $grand_total = $billCalc['total'] + $deliveryCharge + (float)$packaging_charge;
    
    // Payment starts as Pending
    $paymentStatus = 'Pending';

    // --- Wallet (Offers > Wallet): pay all or part of the order from the wallet ---
    // Only for a logged-in customer paying with their own phone's wallet; the
    // server decides the amount (never more than the balance or the bill).
    $wallet_paid = 0.0;
    if ($use_wallet && $grand_total > 0) {
        $walletSettings = getWalletSettings($conn, $restaurant_id);
        if (!$walletSettings['enabled'] || !$walletCustomer || (orderAbuseNormalizePhone((string)$walletCustomer['phone']) ?? (string)$walletCustomer['phone']) !== (string)$customer_phone) {
            $conn->rollBack();
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Please log in with this phone number to pay from your wallet'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $walletLock = $conn->prepare('SELECT balance FROM wallets WHERE restaurant_id = ? AND phone = ? FOR UPDATE');
        $walletLock->execute([$restaurant_id, $customer_phone]);
        $wallet_paid = round(min((float)$walletLock->fetchColumn(), round($grand_total, 2)), 2);
        if ($wallet_paid > 0 && $wallet_paid >= round($grand_total, 2)) {
            $payment_method = 'Wallet';      // nothing left to pay
            $paymentStatus = 'Paid';
        }
    }
    
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
        // Initialize total_visits/total_spent/last_visit_date on the very
        // first order too — leaving them at column defaults (0/0.00/NULL)
        // made a brand-new customer look like they'd never ordered until
        // their *second* order, which threw off segmentation, CLV, and the
        // "every Nth order" review-prompt cadence for every first-time
        // customer. Matches the pattern already used in reservation_operations.php.
        $insertStmt = $conn->prepare("INSERT INTO customers (restaurant_id, customer_name, phone, email, total_visits, total_spent, last_visit_date) VALUES (?, ?, ?, ?, 1, ?, CURDATE())");
        $insertStmt->execute([$restaurant_id, $customer_name, $customer_phone, $customer_email, $grand_total]);
        $customer_id = $conn->lastInsertId();
    }

    // Marks this browser session as having just proven ownership of this
    // phone number by placing a real order through it — lets loyalty/review
    // features (see customer_session.php's getAuthorizedCustomer()) work for
    // guest checkout without requiring an account, while still requiring
    // actual proof of ownership rather than trusting a bare phone parameter.
    // WhatsApp consent ticked at checkout (Growth › WhatsApp). Only ever switches it on here,
    // and not for someone who replied STOP on WhatsApp (the box may just be remembered by their phone).
    if (!empty($input['whatsapp_optin'])) {
        try {
            $conn->prepare('UPDATE customers SET optin_at = IF(whatsapp_optin = 1, optin_at, NOW()), whatsapp_optin = 1 WHERE id = ? AND wa_stopped_at IS NULL')->execute([(int)$customer_id]);
        } catch (PDOException $e) {
            // wa_stopped_at not added on this server yet
            try { $conn->prepare('UPDATE customers SET optin_at = IF(whatsapp_optin = 1, optin_at, NOW()), whatsapp_optin = 1 WHERE id = ?')->execute([(int)$customer_id]); }
            catch (PDOException $e2) { error_log('checkout WhatsApp opt-in: ' . $e2->getMessage()); }
        }
    }

    // (the guest's "this is my phone" session is set after the commit, below)

    // A plain unlocked SELECT here only catches *sequential* retries — two
    // genuinely concurrent submissions (same customer, two tabs, or a slow
    // first request still mid-transaction) could both pass the check before
    // either commits. A MySQL named lock scoped to this exact customer+total
    // combination serializes concurrent attempts against each other; it's
    // released automatically when this (non-persistent) connection closes at
    // the end of the request, so there's no need to release it on every one
    // of this function's exit paths.
    $dedupLockName = 'order_dedup_' . md5($restaurant_id . '|' . $customer_phone . '|' . $grand_total);
    $dedupLockStmt = $conn->prepare('SELECT GET_LOCK(?, 5)');
    $dedupLockStmt->execute([$dedupLockName]);
    if (!$dedupLockStmt->fetchColumn()) {
        $conn->rollBack();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Please wait a moment and try again.'], JSON_UNESCAPED_UNICODE);
        exit();
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
        "SELECT id, order_number, payment_method, payment_status, order_status FROM orders
         WHERE restaurant_id = ? AND customer_phone = ? AND total = ?
         AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE) AND order_status NOT IN ('Cancelled', 'Rejected')
         ORDER BY id DESC LIMIT 1"
    );
    $dedupStmt->execute([$restaurant_id, $customer_phone, $grand_total]);
    $dupOrder = $dedupStmt->fetch(PDO::FETCH_ASSOC);
    $dupUnpaidOnline = $dupOrder && $dupOrder['payment_method'] === 'UPI / NetBanking' && $dupOrder['payment_status'] !== 'Paid'
        && in_array($dupOrder['order_status'], ['Pending', 'Scheduled'], true);
    if ($dupUnpaidOnline && $payment_method === 'UPI / NetBanking') {
        // the same order, still waiting for payment (Back pressed on PhonePe): pay for it again
        $conn->rollBack();
        ob_end_clean();
        echo json_encode(['success' => true, 'order_id' => $dupOrder['id'], 'order_number' => $dupOrder['order_number'], 'phonepe_required' => true,
            'message' => 'Your order is waiting for payment.'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    if ($dupUnpaidOnline) {
        // switching from online payment to cash / QR: the unpaid one is cancelled (coupon, points, wallet back) and a new one placed
        require_once __DIR__ . '/../config/order_state_machine.php';
        $cxl = validateAndUpdateOrderStatus($conn, (int)$dupOrder['id'], 'Cancelled', [], [], $restaurant_id, '[Replaced: the customer chose another way to pay]');
        if (empty($cxl['success'])) error_log('dedup replace: could not cancel order ' . $dupOrder['id'] . ': ' . ($cxl['message'] ?? ''));
        $dupOrder = null;
    }
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
    $orderStatusForInsert = $is_scheduled_order ? 'Scheduled' : 'Pending';
    try {
        $orderStmt = $conn->prepare("INSERT INTO orders (restaurant_id, table_id, order_number, customer_name, customer_phone, customer_email, customer_address, landmark, address_lat, address_lng, notes, coupon_code, discount_amount, loyalty_points_redeemed, loyalty_discount_amount, order_type, delivery_zone_id, delivery_charge, payment_method, payment_status, order_status, subtotal, tax, total, source, scheduled_at, is_scheduled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'website', ?, ?)");
        $orderStmt->execute([$restaurant_id, $table_id, $order_number, $customer_name, $customer_phone, $customer_email, $customer_address, $landmark, $address_lat, $address_lng, $notes, $coupon_code, $discount_amount, $loyalty_points_to_redeem, $loyalty_discount_amount, $order_type, $delivery_zone_id, $deliveryCharge, $payment_method, $paymentStatus, $orderStatusForInsert, $subtotal, $tax, $grand_total, $scheduled_at_db, $is_scheduled_order ? 1 : 0]);
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
            $orderStmt = $conn->prepare("INSERT INTO orders (restaurant_id, table_id, order_number, customer_name, customer_phone, customer_email, customer_address, address_lat, address_lng, notes, coupon_code, discount_amount, loyalty_points_redeemed, loyalty_discount_amount, order_type, delivery_zone_id, delivery_charge, payment_method, payment_status, order_status, subtotal, tax, total, source, scheduled_at, is_scheduled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'website', ?, ?)");
            $orderStmt->execute([$restaurant_id, $table_id, $order_number, $customer_name, $customer_phone, $customer_email, $customer_address, $address_lat, $address_lng, $notes, $coupon_code, $discount_amount, $loyalty_points_to_redeem, $loyalty_discount_amount, $order_type, $delivery_zone_id, $deliveryCharge, $payment_method, $paymentStatus, $orderStatusForInsert, $subtotal, $tax, $grand_total, $scheduled_at_db, $is_scheduled_order ? 1 : 0]);
        } else {
            $orderStmt = $conn->prepare("INSERT INTO orders (restaurant_id, table_id, order_number, customer_name, customer_phone, customer_email, customer_address, notes, coupon_code, discount_amount, loyalty_points_redeemed, loyalty_discount_amount, order_type, delivery_zone_id, delivery_charge, payment_method, payment_status, order_status, subtotal, tax, total, source, scheduled_at, is_scheduled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'website', ?, ?)");
            $orderStmt->execute([$restaurant_id, $table_id, $order_number, $customer_name, $customer_phone, $customer_email, $customer_address, $notes, $coupon_code, $discount_amount, $loyalty_points_to_redeem, $loyalty_discount_amount, $order_type, $delivery_zone_id, $deliveryCharge, $payment_method, $paymentStatus, $orderStatusForInsert, $subtotal, $tax, $grand_total, $scheduled_at_db, $is_scheduled_order ? 1 : 0]);
        }
    }
    $order_id = $conn->lastInsertId();
    billSaveExtras($conn, (int)$order_id, $billCalc['service_charge']);
    if (!empty($roomService)) roomServiceLink($conn, $restaurant_id, (int)$order_id, $roomService, $payment_method === 'Room bill');

    // Record the submitter IP for abuse blocking. Kept out of the INSERT so
    // order placement never breaks before the customer_ip migration
    // (add_order_abuse_protection.sql) is run — this UPDATE simply no-ops
    // (logged) until then, and the IP flood cap fails open meanwhile.
    if ($abuse_ip !== null) {
        try {
            $conn->prepare("UPDATE orders SET customer_ip = INET6_ATON(?) WHERE id = ?")
                 ->execute([$abuse_ip, $order_id]);
        } catch (Throwable $e) {
            error_log('process_website_order: could not record customer_ip (run add_order_abuse_protection.sql): ' . $e->getMessage());
        }
    }

    if ($paymentProofBytes !== null) {
        try {
            $conn->prepare("INSERT INTO payment_proofs (order_id, restaurant_id, proof_data, proof_mime_type, status) VALUES (?, ?, ?, ?, 'Pending')")
                 ->execute([$order_id, $restaurant_id, $paymentProofBytes, $paymentProofMime]);
        } catch (Throwable $e) {
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
        $itemRows[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
        array_push($itemParams,
            $order_id, $item['id'], $item['name'], $item['variation_name'] ?? null,
            $item['quantity'], $item['price'], $item['price'] * $item['quantity'], $addonsJson,
            ($item['notes'] ?? '') !== '' ? $item['notes'] : null
        );
    }
    if (!empty($global_addons) && is_array($global_addons)) {
        foreach ($global_addons as $gAddon) {
            $gName = $gAddon['name'] ?? 'Add-on';
            $gPrice = (float)($gAddon['price'] ?? 0);
            $gQty = (int)($gAddon['quantity'] ?? 1);
            $itemRows[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push($itemParams,
                $order_id, null, $gName, 'Add-on', $gQty, $gPrice, $gPrice * $gQty, null, null
            );
        }
    }

    $firstOrderItemId = null;
    if (!empty($itemRows)) {
        $itemSql = "INSERT INTO order_items (order_id, menu_item_id, item_name, variation_name, quantity, unit_price, total_price, addons, notes) VALUES " . implode(', ', $itemRows);
        $conn->prepare($itemSql)->execute($itemParams);
        $firstOrderItemId = (int)$conn->lastInsertId();
    }
    // how many of each combo sold (Offers > Combos & Deals)
    if (!empty($comboSales)) {
        $csSt = $conn->prepare('INSERT INTO combo_sales (restaurant_id, combo_id, order_id, qty, revenue) VALUES (?, ?, ?, ?, ?)');
        foreach ($comboSales as $cs) $csSt->execute([$restaurant_id, $cs[0], $order_id, $cs[1], $cs[2]]);
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
    
    // Apply the loyalty points redemption validated earlier, now that the
    // order exists to tie it to. Re-validates inside redeemLoyaltyPoints()
    // (its own row lock + balance check) as defense in depth; a failure
    // here means the balance changed between validation and now, which
    // should be rare given the FOR UPDATE lock held since validation.
    if ($loyalty_points_to_redeem > 0 && $loyaltyCustomerId) {
        try {
            redeemLoyaltyPoints($conn, $restaurant_id, $loyaltyCustomerId, $loyalty_points_to_redeem, (int)$order_id);
        } catch (Throwable $e) {
            $conn->rollBack();
            ob_end_clean();
            // redeemLoyaltyPoints() throws plain Exceptions meant for the customer; anything else is logged, not shown
            if (get_class($e) !== 'Exception') error_log('process_website_order loyalty: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Could not apply loyalty points: ' . (get_class($e) === 'Exception' ? $e->getMessage() : 'please try again.')], JSON_UNESCAPED_UNICODE);
            exit();
        }
    }

    if ($vip_discount > 0) {
        $conn->prepare('UPDATE orders SET vip_discount = ? WHERE id = ?')->execute([$vip_discount, $order_id]);
    }

    // Take the wallet part now that the order exists (row still locked from above)
    if ($wallet_paid > 0) {
        try {
            walletApply($conn, $restaurant_id, $customer_phone, -$wallet_paid, 'spend', 'Paid for order ' . $order_number, (int)$order_id);
            $conn->prepare('UPDATE orders SET wallet_paid = ? WHERE id = ?')->execute([$wallet_paid, $order_id]);
            $conn->prepare("INSERT INTO payments (restaurant_id, order_id, amount, payment_method, payment_status) VALUES (?, ?, ?, 'Wallet', 'Success')")
                 ->execute([$restaurant_id, $order_id, $wallet_paid]);
        } catch (Throwable $e) {
            $conn->rollBack();
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Could not pay from your wallet. Please try again.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
    }

    // Record payment (skip for PhonePe — recorded via webhook/callback)
    if ($payment_method !== 'PhonePe' && $payment_method !== 'UPI / NetBanking' && $payment_method !== 'Wallet') {
        try {
            $paymentStmt = $conn->prepare("INSERT INTO payments (restaurant_id, order_id, amount, payment_method, payment_status) VALUES (?, ?, ?, ?, 'Pending')");
            $paymentStmt->execute([$restaurant_id, $order_id, round($grand_total - $wallet_paid, 2), $payment_method]);
        } catch (Throwable $e) {
            // Payments table might not exist, skip
        }
    }

    // Determine payment gateway. Computed here (before KOT/notifications)
    // because PhonePe orders must NOT alert the kitchen/staff/customer yet —
    // nobody has actually paid until phonepe_order_callback.php or the
    // client status poll verifies the payment and calls
    // fireOrderConfirmedActions() itself.
    $usePhonePe = ($payment_method === 'UPI / NetBanking');

    // The dine-in kitchen ticket is created ONCE, by fireOrderConfirmedActions()
    // (config/order_confirmation.php → createDineInKOT()) right after this
    // commit. It used to also be created here, so every cash dine-in website
    // order got two tickets — and since neither was linked to the order, the
    // kitchen marking each one Ready created another 'Paid' order per ticket.

    // Commit transaction
    $conn->commit();
    // A guest who really placed this order may see that phone's points on the thank-you / loyalty page
    // (read-only: spending points, gift codes and changing details need a login, see loyalty_actions.php).
    // Not for an online order still waiting for payment: typing someone's number and leaving must give nothing.
    if ($payment_method !== 'UPI / NetBanking') {
        $_SESSION['ordering_customer_id'] = (int)$customer_id;
        $_SESSION['ordering_customer_restaurant_id'] = $restaurant_id;
    }
    // which campaign link (poster QR / caption link) brought this order, for Marketing › My campaigns
    // (the order is saved now: a problem in these extras must not tell the customer it failed, or they order twice)
    try {
        if (!empty($input['campaign_src'])) { require_once __DIR__ . '/../config/marketing_helpers.php'; mkRecordOrderSource($conn, (string)$restaurant_id, (int)$order_id, (string)$input['campaign_src']); }
        require_once __DIR__ . '/../config/inventory_engine.php';
        invKick($conn, $restaurant_id);   // with "when the order is placed", stock goes out now
    } catch (Throwable $e) {
        error_log('process_website_order: after-save extras failed for order ' . $order_id . ': ' . $e->getMessage());
    }

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
        'wallet_paid' => $wallet_paid,
        'vip_discount' => $vip_discount,
        'vip_free_delivery' => $vip_free_delivery,
        'phonepe_required' => $usePhonePe,
        'whatsapp_enabled' => $whatsappEnabled,
        'whatsapp_phone' => $whatsappPhone,
        'is_scheduled' => $is_scheduled_order,
        'scheduled_at' => $scheduled_at_db,
        'message' => $usePhonePe ? 'Redirecting to payment...' : ($is_scheduled_order ? 'Order scheduled successfully' : 'Order placed successfully')
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
    // A scheduled order also skips this for now — it fires later from
    // activateDueScheduledOrders() once scheduled_at actually arrives.
    if (!$usePhonePe && !$is_scheduled_order) {
        require_once __DIR__ . '/../config/order_confirmation.php';
        try {
            fireOrderConfirmedActions($conn, $order_id);
        } catch (Throwable $e) {
            error_log('Order confirmation actions error: ' . $e->getMessage());
        }
    }

} catch (Throwable $e) {
    // Rollback transaction on any error
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    if (ob_get_level()) ob_end_clean();
    error_log("Order processing error: " . $e->getMessage());
    // Keep the real reason where the team can see it (Super Admin › Error monitor), with what
    // the customer was ordering, so a failed order can be traced without server log access.
    $ref = false;
    try {
        require_once __DIR__ . '/../config/error_monitor.php';
        if (isset($conn) && function_exists('logErrorToDB')) {
            $ref = logErrorToDB($conn, 'api', 'critical', 'Website order failed: ' . $e->getMessage(), [
                'restaurant_id' => $restaurant_id ?? null,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'extra' => [
                    'order_type' => $order_type ?? null,
                    'payment_method' => $payment_method ?? null,
                    'table_id' => $table_id ?? null,
                    'items' => array_map(fn($it) => [($it['id'] ?? null), ($it['name'] ?? ''), ($it['quantity'] ?? 0)], is_array($items ?? null) ? $items : []),
                    'combos' => count($combos_in ?? []),
                    'coupon' => $coupon_code ?? '',
                ],
            ]);
        }
    } catch (Throwable $logErr) {
        error_log('Order error could not be logged: ' . $logErr->getMessage());
    }
    // status stays 200: the checkout retries 5xx answers, and this failure won't fix itself
    echo json_encode([
        'success' => false,
        'message' => 'Sorry, your order could not be placed. Please try again, or call the restaurant.' . ($ref ? " (Ref #$ref)" : '')
    ], JSON_UNESCAPED_UNICODE);
}
?>
