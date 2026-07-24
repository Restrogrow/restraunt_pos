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

try {
    $conn = getConnection();
    
    // Resolve restaurant ID: session > query > default
    $restaurant_id = $_SESSION['restaurant_id'] ?? ($_GET['restaurant_id'] ?? 'RES001');
    
    // Verify table belongs to this restaurant
    $tableCheckStmt = $conn->prepare("SELECT id FROM tables WHERE id = ? AND restaurant_id = ?");
    $tableCheckStmt->execute([$table_id, $restaurant_id]);
    if (!$tableCheckStmt->fetch()) {
        throw new Exception('Invalid table selection');
    }
    
    // Check if table is already reserved for this date and time
    // Only check active reservations (not cancelled or completed)
    $checkStmt = $conn->prepare("SELECT id, customer_name, phone 
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
            'message' => 'This table is already reserved for ' . $reservation_date . ' at ' . $time_slot . '. Please choose a different time or table.',
            'existing_reservation' => [
                'customer_name' => $existing['customer_name'],
                'phone' => $existing['phone']
            ]
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
    
    // Auto-add customer if not exists
    if (!empty($phone)) {
        // Check if customer already exists
        $customerCheckStmt = $conn->prepare("SELECT id FROM customers WHERE restaurant_id = ? AND phone = ?");
        $customerCheckStmt->execute([$restaurant_id, $phone]);
        
        if (!$customerCheckStmt->fetch()) {
            // Customer doesn't exist, add them
            $addCustomerStmt = $conn->prepare("INSERT INTO customers (restaurant_id, customer_name, phone, email, total_visits, last_visit_date) VALUES (?, ?, ?, ?, 1, ?)");
            $addCustomerStmt->execute([$restaurant_id, $customer_name, $phone, $email, $reservation_date]);
        } else {
            // Customer exists, update their visit count and last visit date
            $updateCustomerStmt = $conn->prepare("UPDATE customers SET total_visits = total_visits + 1, last_visit_date = ? WHERE restaurant_id = ? AND phone = ?");
            $updateCustomerStmt->execute([$reservation_date, $restaurant_id, $phone]);
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Reservation created successfully! We will confirm your reservation shortly.',
        'reservation_id' => $reservation_id
    ]);
    
} catch (Exception $e) {
    error_log("Error in create_reservation.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred creating your reservation. Please try again.'
    ]);
}
?>

