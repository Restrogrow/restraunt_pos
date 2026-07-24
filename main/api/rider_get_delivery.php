<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true);

require_once __DIR__ . '/../db_connection.php';

try {
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        global $pdo;
        $conn = $pdo;
    }

    $token = $_GET['token'] ?? '';

    if (empty($token)) {
        echo json_encode(['success' => false, 'message' => 'Missing token']);
        exit;
    }

    // Find tracking record by token
    $stmt = $conn->prepare("SELECT dt.*, o.order_number, o.customer_name, o.customer_phone, o.customer_address, o.delivery_address, o.restaurant_id, u.restaurant_name
        FROM delivery_tracking dt
        JOIN orders o ON dt.order_id = o.id
        JOIN users u ON o.restaurant_id = u.restaurant_id
        WHERE dt.qr_token = ? AND dt.qr_expires_at > NOW()
        LIMIT 1");
    $stmt->execute([$token]);
    $tracking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tracking) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired QR code']);
        exit;
    }

    // Get order items
    $itemsStmt = $conn->prepare("SELECT item_name, quantity, unit_price, total_price FROM order_items WHERE order_id = ?");
    $itemsStmt->execute([$tracking['order_id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'delivery' => [
            'id' => $tracking['id'],
            'order_id' => $tracking['order_id'],
            'order_number' => $tracking['order_number'],
            'restaurant_name' => $tracking['restaurant_name'],
            'restaurant_id' => $tracking['restaurant_id'],
            'customer_name' => $tracking['customer_name'],
            'customer_phone' => $tracking['customer_phone'],
            'delivery_address' => $tracking['delivery_address'] ?: $tracking['customer_address'],
            'delivery_status' => $tracking['delivery_status'],
            'rider_name' => $tracking['rider_name'],
            'rider_phone' => $tracking['rider_phone'],
            'current_lat' => $tracking['current_lat'],
            'current_lng' => $tracking['current_lng'],
            'items' => $items
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    error_log("Error in rider_get_delivery.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
