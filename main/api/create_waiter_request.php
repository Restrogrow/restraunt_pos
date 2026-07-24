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

// Require permission to manage orders (waiter requests are part of order management)
// Allow public access for customers calling waiter
if (isLoggedIn()) {
    requirePermission(PERMISSION_MANAGE_ORDERS);
}
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
}

// Get JSON data
$input = json_decode(file_get_contents('php://input'), true);

$table_id = $input['table_id'] ?? null;
$request_type = $input['request_type'] ?? 'General';
$notes = $input['notes'] ?? '';
$has_items = $input['has_items'] ?? 0;

if (!$table_id) {
    echo json_encode(['success' => false, 'message' => 'Table ID is required']);
    exit();
}

try {
    $conn = getConnection();
    
    // Get table info
    $stmt = $conn->prepare("SELECT t.*, a.area_name FROM tables t 
                           JOIN areas a ON t.area_id = a.id 
                           WHERE t.id = ?");
    $stmt->execute([$table_id]);
    $table = $stmt->fetch();
    
    if (!$table) {
        throw new Exception('Table not found');
    }
    
    // Resolve restaurant ID: session > query
    $restaurant_id = $_SESSION['restaurant_id'] ?? ($_GET['restaurant_id'] ?? 'RES001');
    
    // Insert waiter request with has_items flag
    $sql = "INSERT INTO waiter_requests (restaurant_id, table_id, request_type, notes, status) 
            VALUES (?, ?, ?, ?, 'Pending')";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$restaurant_id, $table_id, $request_type, $notes]);
    
    // Store cart items if this is an order request
    if ($has_items) {
        $cartData = $input['cart_items'] ?? [];
        if (!empty($cartData)) {
            // Store cart items in localStorage key for this table (handled by frontend)
            // For backend, we already have the items in the notes field
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Waiter has been notified',
        'request_id' => $conn->lastInsertId()
    ]);
    
} catch (Exception $e) {
    error_log("Error in create_waiter_request.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again.'
    ]);
}
?>

