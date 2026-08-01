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

    $payStmt = $conn->prepare("SELECT p.id, p.order_id, p.amount, p.payment_status, o.restaurant_id FROM payments p JOIN orders o ON p.order_id = o.id WHERE p.transaction_id = ? AND p.payment_method = 'Online' LIMIT 1");
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

    // ========== SERVER-TO-SERVER STATUS VERIFICATION ==========
    // The webhook body itself is unauthenticated (no signature check is wired
    // up here), so it must never be trusted directly — anyone who learns a
    // transaction ID could otherwise POST a forged {"state":"COMPLETED"} and
    // get an order marked Paid for free. Instead, re-confirm the real status
    // by calling PhonePe's order-status API server-to-server with our own
    // OAuth credentials, the same way the client-side status poll does.
    $verifiedState = phonepeVerifyOrderState($conn, $payment['restaurant_id'], $merchant_transaction_id);
    if ($verifiedState === null) {
        // Could not reach/verify PhonePe right now — do not trust the webhook
        // body. Ack the webhook so PhonePe doesn't hammer retries; the
        // client-side status poll (phonepe_order_payment.php?action=status)
        // will confirm the real state on its own schedule.
        error_log('PhonePe v2 callback: txn=' . $merchant_transaction_id . ' could not be independently verified, ignoring webhook body');
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'verification pending']);
        exit();
    }
    $state = $verifiedState;

    // Only update terminal states; skip PENDING
    if ($state === 'COMPLETED') {
        $payment_status = 'Success';
    } elseif ($state === 'FAILED' || $state === 'REJECTED' || $state === 'CANCELLED') {
        $payment_status = 'Failed';
    } else {
        // PENDING or unknown — leave as Pending
        error_log('PhonePe v2 callback: txn=' . $merchant_transaction_id . ' non-terminal verified state=' . $state);
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

/**
 * Independently confirm a PhonePe order's real status by calling PhonePe's
 * order-status API server-to-server with our own OAuth credentials, instead
 * of trusting the (unauthenticated) webhook body. Mirrors the config/token
 * logic in phonepe_order_payment.php. Returns the verified state string
 * (e.g. 'COMPLETED', 'FAILED', 'PENDING') or null if it couldn't be verified.
 */
function phonepeVerifyOrderState($conn, $restaurantId, $merchantTransactionId) {
    try {
        $config = phonepeCallbackConfig($restaurantId);
        if (empty($config['client_id']) || empty($config['client_secret'])) {
            error_log('PhonePe v2 callback: no PhonePe credentials configured for restaurant ' . $restaurantId);
            return null;
        }

        $accessToken = phonepeCallbackAccessToken($config);
        if (!$accessToken) {
            return null;
        }

        $statusUrl = $config['api_url'] . '/checkout/v2/order/' . urlencode($merchantTransactionId) . '/status';
        $ch = curl_init($statusUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: O-Bearer ' . $accessToken,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode !== 200 || !$response) {
            error_log('PhonePe v2 callback: status verification call failed (http=' . $httpCode . ', curl_error=' . $curlError . ')');
            return null;
        }

        $data = json_decode($response, true);
        $state = $data['state'] ?? $data['orderStatus'] ?? null;
        if (!$state) {
            error_log('PhonePe v2 callback: status verification response had no state field');
            return null;
        }

        return $state;
    } catch (Exception $e) {
        error_log('PhonePe v2 callback: status verification exception - ' . $e->getMessage());
        return null;
    }
}

function phonepeCallbackConfig($restaurantId) {
    $isLocalhost = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false ||
                    strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false);

    if ($isLocalhost) {
        $clientId = env('PHONEPE_SANDBOX_CLIENT_ID', env('PHONEPE_SANDBOX_MERCHANT_ID', ''));
        $clientSecret = env('PHONEPE_SANDBOX_CLIENT_SECRET', env('PHONEPE_SANDBOX_SALT_KEY', ''));
        $clientVersion = env('PHONEPE_SANDBOX_CLIENT_VERSION', env('PHONEPE_SANDBOX_SALT_INDEX', '1'));
        $isSandbox = true;
    } else {
        $dbMerchantId = null;
        $dbSaltKey = null;
        $dbEnvironment = null;

        if ($restaurantId) {
            try {
                $conn = getConnection();
                $stmt = $conn->prepare("SELECT phonepe_merchant_id, phonepe_salt_key, phonepe_environment, payment_gateway_mode FROM users WHERE restaurant_id = ? LIMIT 1");
                $stmt->execute([$restaurantId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $gwMode = $row['payment_gateway_mode'] ?? 'own';
                if ($gwMode === 'own' && $row && !empty($row['phonepe_merchant_id']) && !empty($row['phonepe_salt_key'])) {
                    $dbMerchantId = $row['phonepe_merchant_id'];
                    $dbSaltKey = $row['phonepe_salt_key'];
                    $dbEnvironment = $row['phonepe_environment'] ?? 'SANDBOX';
                }
            } catch (Exception $e) {
            }
        }

        $environment = $dbEnvironment ?: env('PHONEPE_ENVIRONMENT', '');
        if (empty($environment)) {
            $environment = 'PRODUCTION';
        }
        $clientId = $dbMerchantId ?: env('PHONEPE_CLIENT_ID', '');
        $clientSecret = $dbSaltKey ?: env('PHONEPE_CLIENT_SECRET', '');
        $clientVersion = env('PHONEPE_CLIENT_VERSION', '1');
        $isSandbox = strtoupper($environment) !== 'PRODUCTION';
    }

    if ($isSandbox) {
        $apiBase = env('PHONEPE_BASE_URL_TEST', 'https://api-preprod.phonepe.com/apis/pg-sandbox');
        $authUrl = $apiBase . '/v1/oauth/token';
    } else {
        $apiBase = 'https://api.phonepe.com/apis/pg';
        $authUrl = 'https://api.phonepe.com/apis/identity-manager/v1/oauth/token';
    }

    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'client_version' => $clientVersion,
        'api_url' => $apiBase,
        'auth_url' => $authUrl,
    ];
}

function phonepeCallbackAccessToken($config) {
    $ch = curl_init($config['auth_url']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => $config['client_id'],
        'client_version' => $config['client_version'],
        'client_secret' => $config['client_secret'],
        'grant_type' => 'client_credentials',
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log('PhonePe v2 callback: auth connection error - ' . $curlError);
        return null;
    }

    $data = json_decode($response, true);
    if ($httpCode !== 200 || !isset($data['access_token'])) {
        error_log('PhonePe v2 callback: auth error - ' . ($data['message'] ?? ('http ' . $httpCode)));
        return null;
    }

    return $data['access_token'];
}
