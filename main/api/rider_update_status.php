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
// Release the session file lock early — this script only needs the session for
// CSRF/epoch validation, not for writing. Freeing it now prevents the subsequent
// rider-page auto-refresh from blocking on this request's session lock.
session_write_close();

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

    // the delivery steps are shared with the rider app (config/rider_engine.php). A rider with only the QR
    // link hands over the food and the cash together, so "Delivered" counts cash on delivery as collected.
    require_once __DIR__ . '/../config/rider_engine.php';
    riderEnsure($conn);
    $message = riderAdvance($conn, $tracking, $status, true);

    echo json_encode([
        'success' => true,
        'message' => $message,
        'status' => $status
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(400);
    error_log("Error in rider_update_status.php: " . $e->getMessage());
    // the step rules explain themselves ("This order was cancelled…"); anything else stays generic
    $msg = $e->getMessage();
    echo json_encode(['success' => false, 'message' => preg_match('/^(This |Collect |Order not found|Invalid or expired|Missing|Invalid status)/', $msg) ? $msg : 'An error occurred. Please try again.']);
}
