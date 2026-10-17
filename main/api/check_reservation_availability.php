<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
}

// Get parameters
$table_id = $_GET['table_id'] ?? null;
$reservation_date = $_GET['reservation_date'] ?? null;
$time_slot = $_GET['time_slot'] ?? null;

if (!$table_id || !$reservation_date || !$time_slot || !is_string($reservation_date) || !is_string($time_slot) || is_array($table_id)) {
    echo json_encode([
        'success' => false,
        'available' => false,
        'message' => 'Missing required parameters'
    ]);
    exit();
}

try {
    $conn = getConnection();
    
    // Resolve restaurant ID: query (the site being viewed) > session > default
    $restaurant_id = $_GET['restaurant_id'] ?? ($_SESSION['restaurant_id'] ?? 'RES001');
    session_write_close();
    if (!is_string($restaurant_id)) $restaurant_id = 'RES001';
    
    // Check if table is already reserved for this date and time
    // Only check active reservations (not cancelled or completed)
    $stmt = $conn->prepare("SELECT id 
                            FROM reservations 
                            WHERE restaurant_id = ? 
                            AND table_id = ? 
                            AND reservation_date = ? 
                            AND time_slot = ? 
                            AND status NOT IN ('Cancelled', 'Completed', 'No Show')");
    
    $stmt->execute([$restaurant_id, $table_id, $reservation_date, $time_slot]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        echo json_encode([
            'success' => true,
            'available' => false,
            'message' => 'This table is already reserved for the selected date and time'
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'available' => true,
            'message' => 'Table is available'
        ]);
    }
    
} catch (Throwable $e) {
    error_log("Error in check_reservation_availability.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'available' => false,
        'message' => 'An error occurred checking availability. Please try again.'
    ]);
}
?>
