<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';

// Require permission to manage reservations (or allow public if not logged in - for website)
// If user is logged in, check permission; if not, allow (public reservation)
if (isLoggedIn()) {
    requirePermission(PERMISSION_MANAGE_RESERVATIONS);
}
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
}

// Get JSON data
$input = json_decode(file_get_contents('php://input'), true);

$table_id = $input['table_id'] ?? null;
$reservation_date = $input['reservation_date'] ?? null;
$time_slot = $input['time_slot'] ?? null;
$no_of_guests = $input['no_of_guests'] ?? 1;
$meal_type = $input['meal_type'] ?? 'Lunch';
$customer_name = $input['customer_name'] ?? '';
$phone = $input['phone'] ?? '';
$email = $input['email'] ?? '';
$special_request = $input['special_request'] ?? '';

// Validation
foreach ([$table_id, $reservation_date, $time_slot, $no_of_guests, $meal_type, $customer_name, $phone, $email, $special_request] as $v) {
    if (is_array($v)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit();
    }
}

if (!$table_id) {
    echo json_encode(['success' => false, 'message' => 'Table ID is required']);
    exit();
}

if (!$reservation_date) {
    echo json_encode(['success' => false, 'message' => 'Reservation date is required']);
    exit();
}

if (!$time_slot) {
    echo json_encode(['success' => false, 'message' => 'Time slot is required']);
    exit();
}

if (empty($customer_name)) {
    echo json_encode(['success' => false, 'message' => 'Customer name is required']);
    exit();
}

if (empty($phone)) {
    echo json_encode(['success' => false, 'message' => 'Phone number is required']);
    exit();
}

if ($no_of_guests <= 0) {
    echo json_encode(['success' => false, 'message' => 'Number of guests must be greater than 0']);
    exit();
}

// A booking from the website (nobody logged in) is checked properly: it used to accept past dates,
// any guest count, any email (branded emails to strangers) and unlimited bookings from one person.
$publicBooking = !isLoggedIn();
$customer_name = mb_substr(trim(strip_tags((string)$customer_name)), 0, 80);
$special_request = mb_substr(trim(strip_tags((string)$special_request)), 0, 500);
$email = trim((string)$email);
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address, or leave it empty']);
    exit();
}
$no_of_guests = (int)$no_of_guests;
if ($publicBooking) {
    $digits = preg_replace('/\D/', '', (string)$phone);
    if (strlen($digits) < 7 || strlen($digits) > 15) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid phone number']);
        exit();
    }
    if ($customer_name === '') {
        echo json_encode(['success' => false, 'message' => 'Customer name is required']);
        exit();
    }
    if ($no_of_guests > 50) {
        echo json_encode(['success' => false, 'message' => 'For more than 50 guests, please call the restaurant']);
        exit();
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$reservation_date) || !preg_match('/^[0-9: APMapm-]{3,20}$/', (string)$time_slot)) {
        echo json_encode(['success' => false, 'message' => 'Please pick a valid date and time']);
        exit();
    }
    require_once __DIR__ . '/../config/rate_limit.php';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    foreach ([['resv_ip_' . $ip, 6, 3600], ['resv_phone_' . $digits, 4, 86400]] as [$rid_, $max, $win]) {
        $rl = checkRateLimit($rid_, $max, $win);
        if (!$rl['allowed']) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Too many bookings. Please call the restaurant to book more tables.']);
            exit();
        }
    }
}

try {
    $conn = getConnection();
    
    // Resolve restaurant ID: query (the site being viewed) > session > default
    $restaurant_id = $_GET['restaurant_id'] ?? ($_SESSION['restaurant_id'] ?? 'RES001');
    session_write_close();
    if (!is_string($restaurant_id)) $restaurant_id = 'RES001';
    // a guest booking while Reservations is switched off by Super Admin (logged-in staff are stopped by the section guard)
    // ... or while the owner has Reservations switched off in Settings (the booking page redirects
    // away then, but an open / cached page or a direct call could still book)
    require_once __DIR__ . '/../config/access_control.php';
    require_once __DIR__ . '/../config/reservation_helpers.php';
    if (!isLoggedIn() && !reservationsFeatureEnabled($conn, $restaurant_id)) {
        echo json_encode(['success' => false, 'message' => 'Table booking is not available here.']);
        exit;
    }
    
    // Verify table belongs to this restaurant
    $tableCheckStmt = $conn->prepare("SELECT id, capacity FROM tables WHERE id = ? AND restaurant_id = ?");
    $tableCheckStmt->execute([$table_id, $restaurant_id]);
    $tableRow = $tableCheckStmt->fetch(PDO::FETCH_ASSOC);
    if (!$tableRow) {
        throw new Exception('Invalid table selection');
    }
    if ($publicBooking) {
        // in the restaurant's own time: not in the past, at most 60 days ahead, and guests that fit the table
        try {
            $tzq = $conn->prepare('SELECT timezone FROM users WHERE restaurant_id = ? LIMIT 1');
            $tzq->execute([$restaurant_id]);
            $tzName = (string)$tzq->fetchColumn();
            date_default_timezone_set(in_array($tzName, timezone_identifiers_list(), true) ? $tzName : 'Asia/Kolkata');
        } catch (Throwable $e) { date_default_timezone_set('Asia/Kolkata'); }
        $today = date('Y-m-d');
        $last = date('Y-m-d', strtotime('+60 days'));
        if ($reservation_date < $today || $reservation_date > $last) {
            echo json_encode(['success' => false, 'message' => 'Please pick a date from today up to 60 days ahead']);
            exit();
        }
        if ($reservation_date === $today && ($slotTs = strtotime($today . ' ' . $time_slot)) && $slotTs < time()) {
            echo json_encode(['success' => false, 'message' => 'That time has already passed. Please pick a later time.']);
            exit();
        }
        $cap = (int)($tableRow['capacity'] ?? 0);
        if ($cap > 0 && $no_of_guests > $cap) {
            echo json_encode(['success' => false, 'message' => 'This table seats ' . $cap . '. Please pick a bigger table or fewer guests.']);
            exit();
        }
    }
    
    // Check if table is already reserved for this date and time
    // Only check active reservations (not cancelled or completed)
    $checkStmt = $conn->prepare("SELECT id 
                                 FROM reservations 
                                 WHERE restaurant_id = ? 
                                 AND table_id = ? 
                                 AND reservation_date = ? 
                                 AND time_slot = ? 
                                 AND status NOT IN ('Cancelled', 'Completed', 'No Show')");
    
    $checkStmt->execute([$restaurant_id, $table_id, $reservation_date, $time_slot]);
    $existing = $checkStmt->fetch();
    
    if ($existing) {
        echo json_encode([
            'success' => false,
            'message' => 'This table is already reserved for ' . $reservation_date . ' at ' . $time_slot . '. Please choose a different time or table.'
        ]);
        exit();
    }
    
    // Insert reservation
    $sql = "INSERT INTO reservations (restaurant_id, table_id, reservation_date, time_slot, no_of_guests, meal_type, customer_name, phone, email, special_request, status, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW(), NOW())";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $restaurant_id, 
        $table_id, 
        $reservation_date, 
        $time_slot, 
        $no_of_guests, 
        $meal_type, 
        $customer_name, 
        $phone, 
        $email, 
        $special_request
    ]);
    
    $reservation_id = $conn->lastInsertId();
    try {
        require_once __DIR__ . '/../config/mail_engine.php';
        mailBooking($conn, (int)$reservation_id, 'received');
    } catch (Throwable $e) { error_log('booking email: ' . $e->getMessage()); }
    
    // Auto-add customer if not exists
    if (!empty($phone)) {
        // Check if customer already exists
        $customerCheckStmt = $conn->prepare("SELECT id FROM customers WHERE restaurant_id = ? AND phone = ?");
        $customerCheckStmt->execute([$restaurant_id, $phone]);
        
        if (!$customerCheckStmt->fetch()) {
            // Customer doesn't exist, add them
            // a booking is not a visit yet (visits count when they come and order)
            $addCustomerStmt = $conn->prepare("INSERT INTO customers (restaurant_id, customer_name, phone, email, total_visits) VALUES (?, ?, ?, ?, 0)");
            $addCustomerStmt->execute([$restaurant_id, $customer_name, $phone, $email]);
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Reservation created successfully! We will confirm your reservation shortly.',
        'reservation_id' => $reservation_id
    ]);
    
} catch (Throwable $e) {
    error_log("Error in create_reservation.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred creating your reservation. Please try again.'
    ]);
}
?>
