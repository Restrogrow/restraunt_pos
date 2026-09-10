<?php
// PhonePe webhook for platform subscription payments (subscription_payments
// table / users.subscription_status) — the restaurant owner paying
// Restrogrow itself, not a customer paying a restaurant.
//
// This used to speak PhonePe's old v1 callback format (base64 "response"
// field + X-VERIFY salt-key signature), but subscription_payment.php now
// creates payments via the v2 Checkout API. PhonePe only ever sends v2-shaped
// webhook bodies for those orders, so the v1 parser rejected every real
// callback with a 400 before it could update anything — the only thing that
// ever confirmed a subscription payment was the client-side poll in
// dashboard.php independently re-checking PhonePe's status API, which is why
// it could take minutes even for a payment that actually succeeded instantly.
// This mirrors phonepe_order_callback.php's v2 handling instead.
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/phonepe_verify.php';
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

    error_log('PhonePe subscription webhook: method=' . $method . ' content-type=' . $contentType . ' body=' . substr($callback_data, 0, 2000));

    // Try form-encoded POST fallback if JSON body is empty
    if (!$decoded_data && !empty($_POST)) {
        $decoded_data = $_POST;
    }

    // Try base64 decode if 'response' field is present (legacy v1 format,
    // kept only in case an old subscription/gateway is still configured
    // to send it)
    if (!$decoded_data && !empty($_POST['response'])) {
        $inner = json_decode(base64_decode($_POST['response']), true);
        if ($inner) {
            $decoded_data = $inner;
        }
    }

    if (!$decoded_data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON. method=' . $method . ' ct=' . $contentType]);
        exit();
    }

    // Unwrap event+payload format (e.g. pg.order.completed) and nested data
    if (!empty($decoded_data['payload']) && is_array($decoded_data['payload'])) {
        $decoded_data = $decoded_data['payload'];
    }
    if (!empty($decoded_data['data']) && is_array($decoded_data['data'])) {
        $decoded_data = $decoded_data['data'];
    }

    // Accept multiple field name formats (v2 uses merchantOrderId; some
    // legacy/alternate shapes use merchantTransactionId or transactionId)
    $merchant_transaction_id = $decoded_data['merchantOrderId'] ?? $decoded_data['merchantTransactionId'] ?? $decoded_data['transactionId'] ?? '';

    if (!$merchant_transaction_id) {
        $keys = array_keys($decoded_data);
        error_log('PhonePe subscription webhook unknown format. Keys: ' . implode(', ', $keys) . ' | Raw: ' . substr($callback_data, 0, 500));
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing transaction ID. Keys: ' . implode(', ', $keys)]);
        exit();
    }

    $payStmt = $conn->prepare("SELECT id, user_id, restaurant_id, payment_status, duration_months FROM subscription_payments WHERE transaction_id = ? LIMIT 1");
    $payStmt->execute([$merchant_transaction_id]);
    $payment = $payStmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment not found for txn: ' . $merchant_transaction_id]);
        exit();
    }

    // Skip if already processed as success (don't overwrite / re-activate)
    if ($payment['payment_status'] === 'success') {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'already processed']);
        exit();
    }

    // ========== SERVER-TO-SERVER STATUS VERIFICATION ==========
    // The webhook body itself is unauthenticated here, so — same as the
    // order-payment webhook — never trust it directly; re-confirm the real
    // status by calling PhonePe's order-status API server-to-server with
    // our own OAuth credentials.
    $verifiedState = phonepeVerifyOrderState($conn, $payment['restaurant_id'], $merchant_transaction_id);
    if ($verifiedState === null) {
        error_log('PhonePe subscription webhook: txn=' . $merchant_transaction_id . ' could not be independently verified, ignoring webhook body');
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'verification pending']);
        exit();
    }

    if ($verifiedState === 'COMPLETED' || $verifiedState === 'SUCCESS') {
        $payment_status = 'success';
    } elseif (in_array($verifiedState, ['FAILED', 'REJECTED', 'CANCELLED', 'EXPIRED'])) {
        $payment_status = 'failed';
    } else {
        // PENDING or unknown — leave as pending, the client poll or a later
        // webhook retry will pick up the terminal state.
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'non-terminal state: ' . $verifiedState]);
        exit();
    }

    $updateStmt = $conn->prepare("UPDATE subscription_payments SET payment_status = ?, updated_at = NOW() WHERE id = ?");
    $updateStmt->execute([$payment_status, $payment['id']]);

    if ($payment_status === 'success') {
        $durationMonths = (int)($payment['duration_months'] ?: 1);
        $renewal_date = date('Y-m-d', strtotime("+{$durationMonths} months"));
        $userUpdate = $conn->prepare("UPDATE users SET subscription_status = 'active', renewal_date = ?, is_active = 1 WHERE id = ?");
        $userUpdate->execute([$renewal_date, $payment['user_id']]);
        error_log('PhonePe subscription webhook: activated user=' . $payment['user_id'] . ' for ' . $durationMonths . ' month(s), renews ' . $renewal_date);
    }

    http_response_code(200);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log('PhonePe subscription webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred processing the payment callback']);
}
