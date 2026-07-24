<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true);

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/order_state_machine.php';

try {
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        global $pdo;
        $conn = $pdo;
    }

    $token = $_POST['token'] ?? '';
    $status = $_POST['status'] ?? '';

    if (empty($token) || empty($status)) {
        throw new Exception('Missing parameters');
    }

    $validStatuses = ['Picked_Up', 'In_Transit', 'Delivered'];
    if (!in_array($status, $validStatuses)) {
        throw new Exception('Invalid status');
    }

    // Validate token and get tracking record
    $stmt = $conn->prepare("SELECT dt.*, o.restaurant_id FROM delivery_tracking dt JOIN orders o ON dt.order_id = o.id WHERE dt.qr_token = ? AND dt.qr_expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $tracking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tracking) {
        throw new Exception('Invalid or expired QR code');
    }

    // Validate status transition
    $currentStatus = $tracking['delivery_status'];
    $validTransitions = [
        'Preparing' => ['Picked_Up'],
        'Assigned' => ['Picked_Up'],
        'Picked_Up' => ['In_Transit'],
        'In_Transit' => ['Delivered']
    ];

    if (!isset($validTransitions[$currentStatus]) || !in_array($status, $validTransitions[$currentStatus])) {
        throw new Exception('Cannot transition from ' . $currentStatus . ' to ' . $status);
    }

    // Begin transaction for atomic updates
    $conn->beginTransaction();

    // Update delivery_tracking status
    $updateFields = ['delivery_status = ?', 'updated_at = NOW()'];
    $updateParams = [$status];

    if ($status === 'Picked_Up') {
        $updateFields[] = 'picked_up_at = NOW()';
    } elseif ($status === 'In_Transit') {
        $updateFields[] = 'in_transit_at = NOW()';
    } elseif ($status === 'Delivered') {
        $updateFields[] = 'delivered_at = NOW()';
    }

    $sql = "UPDATE delivery_tracking SET " . implode(', ', $updateFields) . " WHERE id = ?";
    $updateParams[] = $tracking['id'];
    $stmt = $conn->prepare($sql);
    $stmt->execute($updateParams);

    // If delivered, update order status to Completed using state machine
    if ($status === 'Delivered') {
        $result = validateAndUpdateOrderStatus($conn, (int)$tracking['order_id'], 'Completed');
        if (!$result['success']) {
            $conn->rollBack();
            throw new Exception($result['message']);
        }
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => $status === 'Picked_Up' ? 'Marked as Picked Up' : ($status === 'In_Transit' ? 'Marked as In Transit' : 'Marked as Delivered'),
        'status' => $status
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(400);
    error_log("Error in rider_update_status.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
