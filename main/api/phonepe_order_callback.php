<?php
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/order_state_machine.php';
startSecureSession();

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
}

header('Content-Type: application/json; charset=UTF-8');

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';

    // Return OK for GET requests (health checks / direct browser visits)
    if ($method !== 'POST') {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'webhook active']);
        exit();
    }

    $conn = getConnection();

    $contentType = $_SERVER['CONTENT_TYPE'] ?? 'none';
    $callback_data = file_get_contents('php://input');
    $decoded_data = json_decode($callback_data, true);

    // Log raw payload for debugging
    error_log('PhonePe webhook: method=' . $method . ' content-type=' . $contentType . ' body=' . substr($callback_data, 0, 2000));

    // Try form-encoded POST fallback if JSON body is empty
    if (!$decoded_data && !empty($_POST)) {
        $decoded_data = $_POST;
        error_log('PhonePe webhook: using $_POST fallback');
    }

    // Try base64 decode if 'response' field is present (v1 format)
    if (!$decoded_data && !empty($_POST['response'])) {
        $inner = json_decode(base64_decode($_POST['response']), true);
        if ($inner) {
            $decoded_data = $inner;
            error_log('PhonePe webhook: decoded v1 base64 response');
        }
    }

    if (!$decoded_data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON. method=' . $method . ' ct=' . $contentType]);
        exit();
    }

    // Unwrap event+payload format (e.g. pg.order.completed)
    if (!empty($decoded_data['payload']) && is_array($decoded_data['payload'])) {
        $decoded_data = $decoded_data['payload'];
    }

    // Unwrap nested data field
    if (!empty($decoded_data['data']) && is_array($decoded_data['data'])) {
        $decoded_data = $decoded_data['data'];
    }

    // Accept multiple field name formats
    $merchant_transaction_id = $decoded_data['merchantOrderId'] ?? $decoded_data['merchantTransactionId'] ?? $decoded_data['transactionId'] ?? '';
    $state = $decoded_data['state'] ?? $decoded_data['orderStatus'] ?? $decoded_data['code'] ?? '';
    $phonepe_order_id = $decoded_data['orderId'] ?? '';

    if (!$merchant_transaction_id) {
        $keys = array_keys($decoded_data);
        error_log('PhonePe webhook unknown format. Keys: ' . implode(', ', $keys) . ' | Raw: ' . substr($callback_data, 0, 500));
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing transaction ID. Keys: ' . implode(', ', $keys)]);
        exit();
    }

    $payStmt = $conn->prepare("SELECT p.id, p.order_id, p.amount, o.restaurant_id FROM payments p JOIN orders o ON p.order_id = o.id WHERE p.transaction_id = ? AND p.payment_method = 'Online' LIMIT 1");
    $payStmt->execute([$merchant_transaction_id]);
    $payment = $payStmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment not found for txn: ' . $merchant_transaction_id]);
        exit();
    }

    // Skip if already processed as Success (don't overwrite)
    if ($payment['payment_status'] === 'Success') {
        error_log('PhonePe v2 callback: txn=' . $merchant_transaction_id . ' already Success');
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'already processed']);
        exit();
    }

    // Only update terminal states; skip PENDING
    if ($state === 'COMPLETED') {
        $payment_status = 'Success';
    } elseif ($state === 'FAILED' || $state === 'REJECTED' || $state === 'CANCELLED') {
        $payment_status = 'Failed';
    } else {
        // PENDING or unknown — leave as Pending
        error_log('PhonePe v2 callback: txn=' . $merchant_transaction_id . ' non-terminal state=' . $state);
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'non-terminal state: ' . $state]);
        exit();
    }

    $conn->beginTransaction();
    try {
        $updatePay = $conn->prepare("UPDATE payments SET payment_status = ? WHERE id = ?");
        $updatePay->execute([$payment_status, $payment['id']]);

        if ($payment_status === 'Success') {
            // Use state machine for atomic payment status update with row lock
            $result = validateAndUpdatePaymentStatus($conn, (int)$payment['order_id'], 'Paid');
            if (!$result['success']) {
                error_log('PhonePe v2 callback: payment_status update skipped - ' . $result['message']);
            }
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

    error_log('PhonePe v2 callback processed: txn=' . $merchant_transaction_id . ' state=' . $state . ' -> ' . $payment_status);

    http_response_code(200);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log('PhonePe v2 callback error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred processing the payment callback']);
}
