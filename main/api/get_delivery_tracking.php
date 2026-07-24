<?php
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Auth required']);
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
    $order_id = (int)($_GET['order_id'] ?? 0);

    if (empty($restaurant_id) || !$order_id) {
        echo json_encode(['success' => false, 'tracking' => null]);
        exit;
    }

    $stmt = $conn->prepare("SELECT dt.*, o.delivery_charge, o.delivery_address, o.order_type
        FROM delivery_tracking dt
        JOIN orders o ON dt.order_id = o.id
        WHERE dt.order_id = ? AND dt.restaurant_id = ?
        LIMIT 1");
    $stmt->execute([$order_id, $restaurant_id]);
    $tracking = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tracking) {
        echo json_encode(['success' => true, 'tracking' => $tracking]);
    } else {
        // Return basic order delivery info even if no tracking record
        $stmt = $conn->prepare("SELECT delivery_charge, delivery_address, delivery_zone_id, order_type FROM orders WHERE id = ? AND restaurant_id = ?");
        $stmt->execute([$order_id, $restaurant_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'success' => true,
            'tracking' => $order ? [
                'delivery_status' => 'Pending',
                'delivery_charge' => $order['delivery_charge'] ?? 0,
                'delivery_address' => $order['delivery_address'] ?? '',
                'rider_name' => null,
                'rider_phone' => null
            ] : null
        ]);
    }

} catch (Exception $e) {
    error_log("Error in get_delivery_tracking.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred.', 'tracking' => null]);
}
