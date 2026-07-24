<?php
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../config/env_loader.php';
startSecureSession();

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
}

header('Content-Type: application/json; charset=UTF-8');

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';

    if ($method !== 'POST') {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'webhook active']);
        exit();
    }

    $conn = getConnection();

    $callback_data = file_get_contents('php://input');
    $decoded_data = json_decode($callback_data, true);

    error_log('PaymentLink callback: body=' . substr($callback_data, 0, 2000));

    if (!$decoded_data && !empty($_POST)) {
        $decoded_data = $_POST;
    }

    if (!$decoded_data && !empty($_POST['response'])) {
        $inner = json_decode(base64_decode($_POST['response']), true);
        if ($inner) {
            $decoded_data = $inner;
        }
    }

    if (!$decoded_data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid callback data']);
        exit();
    }

    if (!empty($decoded_data['payload']) && is_array($decoded_data['payload'])) {
        $decoded_data = $decoded_data['payload'];
    }
    if (!empty($decoded_data['data']) && is_array($decoded_data['data'])) {
        $decoded_data = $decoded_data['data'];
    }

    $merchant_transaction_id = $decoded_data['merchantOrderId'] ?? $decoded_data['merchantTransactionId'] ?? $decoded_data['transactionId'] ?? '';
    $state = $decoded_data['state'] ?? $decoded_data['orderStatus'] ?? $decoded_data['code'] ?? '';
    $phonepe_order_id = $decoded_data['orderId'] ?? '';

    if (!$merchant_transaction_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing transaction ID']);
        exit();
    }

    // ========== X-VERIFY SIGNATURE VERIFICATION ==========
    // PhonePe sends X-VERIFY header: SHA256(base64(payload) + "/pg/v1/status/" + merchantId + "." + merchantTransactionId + salt_key) + "###" + salt_index
    $xVerify = $_SERVER['HTTP_X_VERIFY'] ?? '';
    $merchantId = env('PHONEPE_MERCHANT_ID', '');
    $saltKey = env('PHONEPE_SALT_KEY', '');
    $saltIndex = env('PHONEPE_SALT_INDEX', '1');
    
    if (!empty($xVerify) && !empty($merchantId) && !empty($saltKey)) {
        // For v1-style callbacks: payload is the raw 'response' field from POST
        $rawPayload = $decoded_data['response'] ?? $_POST['response'] ?? $callback_data;
        if ($rawPayload) {
            $expectedHash = hash('sha256', $rawPayload . '/pg/v1/status/' . $merchantId . '.' . $merchant_transaction_id . $saltKey);
            $expectedSignature = $expectedHash . '###' . $saltIndex;
            
            if ($xVerify !== $expectedSignature) {
                error_log('PaymentLink callback: X-VERIFY mismatch! Expected=' . $expectedSignature . ' Got=' . $xVerify);
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Invalid signature']);
                exit();
            }
            error_log('PaymentLink callback: X-VERIFY verified successfully');
        }
    } else {
        // If credentials aren't configured, log warning but still process
        error_log('PaymentLink callback: X-VERIFY skipped (no credentials configured)');
    }
    // ========== END X-VERIFY VERIFICATION ==========

    $stmt = $conn->prepare("SELECT id, payment_status FROM payment_links WHERE transaction_id = ? LIMIT 1");
    $stmt->execute([$merchant_transaction_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment link not found: ' . $merchant_transaction_id]);
        exit();
    }

    if ($payment['payment_status'] === 'Success') {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'already processed']);
        exit();
    }

    if ($state === 'COMPLETED' || $state === 'PAYMENT_SUCCESS') {
        $stmt = $conn->prepare("UPDATE payment_links SET payment_status = 'Success', phonepe_order_id = ? WHERE id = ?");
        $stmt->execute([$phonepe_order_id, $payment['id']]);
        echo json_encode(['success' => true, 'message' => 'Payment marked completed']);
    } elseif (in_array($state, ['FAILED', 'REJECTED', 'CANCELLED', 'PAYMENT_FAILED', 'PAYMENT_ERROR'])) {
        $stmt = $conn->prepare("UPDATE payment_links SET payment_status = 'Failed', phonepe_order_id = ? WHERE id = ?");
        $stmt->execute([$phonepe_order_id, $payment['id']]);
        echo json_encode(['success' => true, 'message' => 'Payment marked failed']);
    } else {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'non-terminal state: ' . $state]);
    }

} catch (Exception $e) {
    error_log('PaymentLink callback error: ' . $e->getMessage());
    http_response_code(500);
    error_log("Payment callback error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred processing the payment.']);
}
