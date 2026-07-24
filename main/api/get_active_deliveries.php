<?php
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Auth required', 'deliveries' => []]);
    exit;
}

try {
    require_once __DIR__ . '/../db_connection.php';
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        global $pdo;
        $conn = $pdo;
    }

    $restaurant_id = $_SESSION['restaurant_id'] ?? '';

    if (empty($restaurant_id)) {
        echo json_encode(['success' => false, 'message' => 'Restaurant ID required', 'deliveries' => []]);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT 
            dt.id AS tracking_id,
            dt.order_id,
            dt.delivery_status,
            dt.rider_name,
            dt.rider_phone,
            dt.qr_token,
            dt.current_lat,
            dt.current_lng,
            dt.location_updated_at,
            dt.created_at AS tracking_created_at,
            o.order_number,
            o.customer_name,
            o.customer_phone,
            o.delivery_address,
            o.delivery_charge,
            o.order_status,
            o.total,
            o.created_at AS order_created_at
        FROM delivery_tracking dt
        JOIN orders o ON dt.order_id = o.id
        WHERE dt.restaurant_id = ?
          AND dt.delivery_status IN ('Preparing', 'Assigned', 'Picked_Up', 'In_Transit')
        ORDER BY dt.location_updated_at DESC, dt.created_at DESC
    ");
    $stmt->execute([$restaurant_id]);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'deliveries' => $deliveries,
        'count' => count($deliveries)
    ]);

} catch (Exception $e) {
    error_log("Error in get_active_deliveries.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.', 'deliveries' => []]);
}
