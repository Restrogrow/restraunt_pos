<?php
// Include secure session configuration (callback may not need auth, but session config is safe)
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Load environment variables
if (file_exists(__DIR__ . '/../config/env_loader.php')) {
    require_once __DIR__ . '/../config/env_loader.php';
}

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
}

// PhonePe API Configuration - Load from .env
if (!defined('PHONEPE_SALT_KEY')) {
    define('PHONEPE_SALT_KEY', env('PHONEPE_SALT_KEY', '099eb0cd-02cf-4e2a-8aca-3e6c6aff8719'));
}
if (!defined('PHONEPE_SALT_INDEX')) {
    define('PHONEPE_SALT_INDEX', env('PHONEPE_SALT_INDEX', '1'));
}

try {
    $conn = getConnection();
    
    // Get callback data
    $callback_data = file_get_contents('php://input');
    $decoded_data = json_decode($callback_data, true);
    
    if (!$decoded_data || !isset($decoded_data['response'])) {
        error_log('Invalid callback data: ' . $callback_data);
        http_response_code(400);
        exit();
    }
    
    $response = base64_decode($decoded_data['response']);
    $response_data = json_decode($response, true);
    
    if (!$response_data) {
        error_log('Invalid response data');
        http_response_code(400);
        exit();
    }
    
    $merchant_transaction_id = $response_data['merchantTransactionId'] ?? null;
    $transaction_id = $response_data['transactionId'] ?? null;
    $code = $response_data['code'] ?? null;
    $state = $response_data['state'] ?? null;
    
    if (!$merchant_transaction_id) {
        error_log('Missing merchant transaction ID');
        http_response_code(400);
        exit();
    }
    
    // ========== X-VERIFY SIGNATURE VERIFICATION ==========
    // PhonePe sends X-VERIFY header: SHA256(base64(payload) + "/pg/v1/status/" + merchantId + "." + merchantTransactionId + salt_key) + "###" + salt_index
    $xVerify = $_SERVER['HTTP_X_VERIFY'] ?? '';
    $merchantId = env('PHONEPE_CLIENT_ID', env('PHONEPE_MERCHANT_ID', ''));
    $saltKey = env('PHONEPE_CLIENT_SECRET', env('PHONEPE_SALT_KEY', ''));
    $saltIndex = env('PHONEPE_SALT_INDEX', '1');
    
    if (!empty($xVerify) && !empty($merchantId) && !empty($saltKey)) {
        $rawPayload = $decoded_data['response'] ?? $_POST['response'] ?? $callback_data;
        if ($rawPayload) {
            $expectedHash = hash('sha256', $rawPayload . '/pg/v1/status/' . $merchantId . '.' . $merchant_transaction_id . $saltKey);
            $expectedSignature = $expectedHash . '###' . $saltIndex;
            
            if ($xVerify !== $expectedSignature) {
                error_log('PhonePe callback: X-VERIFY mismatch!');
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Invalid signature']);
                exit();
            }
            error_log('PhonePe callback: X-VERIFY verified');
        }
    } else {
        error_log('PhonePe callback: X-VERIFY skipped (no credentials)');
    }
    // ========== END X-VERIFY VERIFICATION ==========
    
    // Update payment record
    $stmt = $conn->prepare("SELECT id, user_id, restaurant_id, amount, subscription_type FROM subscription_payments WHERE transaction_id = ? LIMIT 1");
    $stmt->execute([$merchant_transaction_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        error_log('Payment record not found: ' . $merchant_transaction_id);
        http_response_code(404);
        exit();
    }
    
    // Update payment status
    $payment_status = ($code === 'PAYMENT_SUCCESS' && $state === 'COMPLETED') ? 'success' : 'failed';
    $phonepe_transaction_id = $transaction_id;
    
    $update_stmt = $conn->prepare("UPDATE subscription_payments SET phonepe_transaction_id = ?, payment_status = ?, updated_at = NOW() WHERE id = ?");
    $update_stmt->execute([$phonepe_transaction_id, $payment_status, $payment['id']]);
    
    // If payment successful, activate subscription
    if ($payment_status === 'success') {
        // Read duration_months from payment record; fallback to 1 if not set
        $durationStmt = $conn->prepare("SELECT duration_months FROM subscription_payments WHERE id = ?");
        $durationStmt->execute([$payment['id']]);
        $durationMonths = (int)($durationStmt->fetchColumn() ?: 1);
        
        $renewal_date = date('Y-m-d', strtotime("+{$durationMonths} months"));
        $user_update_stmt = $conn->prepare("UPDATE users SET subscription_status = 'active', renewal_date = ?, is_active = 1 WHERE id = ?");
        $user_update_stmt->execute([$renewal_date, $payment['user_id']]);
        
        error_log('Subscription activated for user: ' . $payment['user_id'] . ' for ' . $durationMonths . ' month(s), renews ' . $renewal_date);
    }
    
    // Return success response to PhonePe
    http_response_code(200);
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    error_log('PhonePe callback error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred processing the payment callback']);
}
?>

