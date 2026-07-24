<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true);

if (!file_exists(__DIR__ . '/../db_connection.php')) {
    echo json_encode(['success' => false]); exit();
}
require_once __DIR__ . '/../db_connection.php';

$orderNumber = $_GET['order_number'] ?? '';
$customerPhone = $_GET['customer_phone'] ?? '';

if (!$orderNumber || !$customerPhone) {
    echo json_encode(['success' => false]); exit();
}

try {
    $conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
    if (!$conn) throw new Exception('No connection');

    $stmt = $conn->prepare("SELECT o.id, o.order_status, o.order_type, o.total, o.source
        FROM orders o WHERE o.order_number = ? AND o.customer_phone = ? AND o.source = 'website' LIMIT 1");
    $stmt->execute([$orderNumber, $customerPhone]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false]);
        exit();
    }

    $tracking = null;
    if ($order['order_type'] === 'Delivery') {
        $tStmt = $conn->prepare("SELECT delivery_status, rider_name, rider_phone, current_lat, current_lng, location_updated_at
            FROM delivery_tracking WHERE order_id = ? LIMIT 1");
        $tStmt->execute([$order['id']]);
        $tracking = $tStmt->fetch(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'success' => true,
        'order_status' => $order['order_status'],
        'order_type' => $order['order_type'],
        'total' => $order['total'],
        'tracking' => $tracking
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false]);
}
