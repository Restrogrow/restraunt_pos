<?php
/**
 * PhonePe webhook for meal-plan subscription payments. Mirrors
 * phonepe_order_callback.php exactly (same unauthenticated-body distrust,
 * same server-to-server re-verification via phonepeVerifyOrderState), but
 * updates meal_plan_payments + customer_meal_subscriptions instead of
 * payments + orders. The client-side poll in phonepe_subscription_payment.php
 * (action=status) is the primary confirmation path; this webhook is the
 * same defense-in-depth backstop the order flow already relies on.
 */
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/phonepe_verify.php';
startSecureSession(true);

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

    error_log('PhonePe subscription webhook: body=' . substr($callback_data, 0, 2000));

    if (!$decoded_data && !empty($_POST)) {
        $decoded_data = $_POST;
    }
    if (!$decoded_data && !empty($_POST['response'])) {
        $inner = json_decode(base64_decode($_POST['response']), true);
        if ($inner) $decoded_data = $inner;
    }

    if (!$decoded_data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
        exit();
    }

    if (!empty($decoded_data['payload']) && is_array($decoded_data['payload'])) {
        $decoded_data = $decoded_data['payload'];
    }
    if (!empty($decoded_data['data']) && is_array($decoded_data['data'])) {
        $decoded_data = $decoded_data['data'];
    }

    $merchant_transaction_id = $decoded_data['merchantOrderId'] ?? $decoded_data['merchantTransactionId'] ?? $decoded_data['transactionId'] ?? '';

    if (!$merchant_transaction_id) {
        $keys = array_keys($decoded_data);
        error_log('PhonePe subscription webhook unknown format. Keys: ' . implode(', ', $keys));
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing transaction ID']);
        exit();
    }

    // Only transaction IDs minted by phonepe_subscription_payment.php (prefix
    // "PPS_") belong to this table - anything else isn't ours to process.
    if (strpos($merchant_transaction_id, 'PPS_') !== 0) {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'not a subscription payment']);
        exit();
    }

    $payStmt = $conn->prepare("SELECT p.id, p.subscription_id, p.payment_status, s.restaurant_id FROM meal_plan_payments p JOIN customer_meal_subscriptions s ON p.subscription_id = s.id WHERE p.transaction_id = ? LIMIT 1");
    $payStmt->execute([$merchant_transaction_id]);
    $payment = $payStmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment not found for txn: ' . $merchant_transaction_id]);
        exit();
    }

    if ($payment['payment_status'] === 'Success') {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'already processed']);
        exit();
    }

    $verifiedState = phonepeVerifyOrderState($conn, $payment['restaurant_id'], $merchant_transaction_id);
    if ($verifiedState === null) {
        error_log('PhonePe subscription webhook: txn=' . $merchant_transaction_id . ' could not be independently verified, ignoring webhook body');
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'verification pending']);
        exit();
    }

    if ($verifiedState === 'COMPLETED') {
        $payment_status = 'Success';
    } elseif (in_array($verifiedState, ['FAILED', 'REJECTED', 'CANCELLED', 'EXPIRED'], true)) {
        $payment_status = 'Failed';
    } else {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'non-terminal state: ' . $verifiedState]);
        exit();
    }

    $conn->beginTransaction();
    try {
        $conn->prepare("UPDATE meal_plan_payments SET payment_status = ? WHERE id = ?")->execute([$payment_status, $payment['id']]);
        if ($payment_status === 'Success') {
            $conn->prepare("UPDATE customer_meal_subscriptions SET status = 'active' WHERE id = ? AND status = 'pending_payment'")->execute([$payment['subscription_id']]);
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

    error_log('PhonePe subscription webhook processed: txn=' . $merchant_transaction_id . ' -> ' . $payment_status);

    http_response_code(200);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log('PhonePe subscription webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred processing the payment callback']);
}
