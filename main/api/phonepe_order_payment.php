<?php
if (ob_get_level()) ob_clean();
ob_start();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    ob_end_clean();
    exit();
}

require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/order_state_machine.php';
startSecureSession(true);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
}

$action = $_GET['action'] ?? 'create';


if ($action === 'status') {
    handleStatusCheck();
} else {
    handleCreatePayment();
}

function getBaseUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($basePath === '/' || $basePath === '\\') $basePath = '';
    return $scheme . '://' . $host . $basePath;
}

function getPhonePeConfig($restaurantId = null) {
    $baseUrl = getBaseUrl();

    $isLocalhost = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false ||
                    strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false);

    if ($isLocalhost) {
        $environment = 'SANDBOX';
        $clientId = env('PHONEPE_SANDBOX_CLIENT_ID', env('PHONEPE_SANDBOX_MERCHANT_ID', ''));
        $clientSecret = env('PHONEPE_SANDBOX_CLIENT_SECRET', env('PHONEPE_SANDBOX_SALT_KEY', ''));
        $clientVersion = env('PHONEPE_SANDBOX_CLIENT_VERSION', env('PHONEPE_SANDBOX_SALT_INDEX', '1'));
        $demoMode = empty($clientId) || empty($clientSecret);
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

        $hasValidCreds = (!empty($clientId) && !empty($clientSecret) && $clientId !== 'YOUR_MERCHANT_ID' && strpos($clientSecret, 'your_salt_key') === false);
        $demoMode = !$hasValidCreds;
    }

    $isSandbox = strtoupper($environment) !== 'PRODUCTION';

    if ($isSandbox) {
        $apiBase = env('PHONEPE_BASE_URL_TEST', 'https://api-preprod.phonepe.com/apis/pg-sandbox');
        $authUrl = $apiBase . '/v1/oauth/token';
    } else {
        $apiBase = 'https://api.phonepe.com/apis/pg';
        $authUrl = 'https://api.phonepe.com/apis/identity-manager/v1/oauth/token';
    }

    $redirectUrl = getBaseUrl() . '/main/website/cart.php';

    return [
        'environment' => $environment,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'client_version' => $clientVersion,
        'api_url' => $apiBase,
        'auth_url' => $authUrl,
        'pay_url' => $apiBase . '/checkout/v2/pay',
        'redirect_url' => $redirectUrl,
        'demo_mode' => $demoMode,
        'is_sandbox' => $isSandbox,
    ];
}

function getAccessToken($config) {
    // Check session cache
    $cacheKey = 'pp_token_' . md5($config['client_id']);
    if (isset($_SESSION[$cacheKey]) && isset($_SESSION[$cacheKey . '_expires'])) {
        if (time() < $_SESSION[$cacheKey . '_expires'] - 60) {
            return $_SESSION[$cacheKey];
        }
    }

    $ch = curl_init($config['auth_url']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => $config['client_id'],
        'client_version' => $config['client_version'],
        'client_secret' => $config['client_secret'],
        'grant_type' => 'client_credentials',
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        throw new Exception('PhonePe auth connection error: ' . $curl_error);
    }

    $data = json_decode($response, true);

    if ($http_code !== 200 || !isset($data['access_token'])) {
        $msg = $data['message'] ?? 'Failed to get auth token';
        throw new Exception('PhonePe auth error: ' . $msg . ' (code: ' . ($data['code'] ?? $http_code) . ')');
    }

    // Cache token
    $_SESSION[$cacheKey] = $data['access_token'];
    $_SESSION[$cacheKey . '_expires'] = $data['expires_at'] ?? (time() + 3600);

    return $data['access_token'];
}

function handleCreatePayment() {
    if (ob_get_level()) ob_clean();

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $orderId = (int)($input['order_id'] ?? 0);
        $customerPhone = trim($input['customer_phone'] ?? '');
        $embedMode = !empty($input['embed']);

        if (!$orderId) {
            throw new Exception('Missing order_id');
        }

        $conn = getConnection();

        $stmt = $conn->prepare("SELECT o.* FROM orders o WHERE o.id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception('Order not found');
        }

        // order_id is a single auto-increment shared across every tenant —
        // without checking it against the phone number the client also
        // submits (same value the checkout form already collects), anyone
        // who knows/enumerates an order_id could spin up a payment session
        // for someone else's order.
        if (empty($customerPhone) || $customerPhone !== (string)$order['customer_phone']) {
            throw new Exception('Order not found');
        }

        $config = getPhonePeConfig($order['restaurant_id']);
        if ($embedMode) {
            $config['redirect_url'] = str_replace('/website/cart.php', '/embed/embed.php', $config['redirect_url']);
        }
        $amount = (float)$order['total'];

        if ($amount <= 0) {
            throw new Exception('Invalid amount');
        }

        $transaction_id = 'PP_' . $order['order_number'] . '_' . bin2hex(random_bytes(12));

        $payStmt = $conn->prepare("INSERT INTO payments (restaurant_id, order_id, transaction_id, amount, payment_method, payment_status) VALUES (?, ?, ?, ?, 'Online', 'Pending')");
        $payStmt->execute([$order['restaurant_id'], $orderId, $transaction_id, $amount]);
        $payment_id = $conn->lastInsertId();

        if ($config['demo_mode'] || empty($config['client_id']) || empty($config['client_secret'])) {
            $demoUrl = $config['redirect_url'] . '?restaurant_id=' . urlencode($order['restaurant_id']) . '&order_id=' . urlencode($order['order_number']) . '&phonepe_demo=1&transaction_id=' . urlencode($transaction_id);

            ob_end_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Demo mode - simulating PhonePe payment',
                'payment_url' => $demoUrl,
                'transaction_id' => $transaction_id,
                'payment_id' => $payment_id,
                'demo_mode' => true
            ]);
            exit();
        }

        if (empty($config['client_id']) || empty($config['client_secret'])) {
            throw new Exception('PhonePe credentials not configured. Please configure in Payment Gateway settings.');
        }

        $redirectUrl = $config['redirect_url'] . '?restaurant_id=' . urlencode($order['restaurant_id']) . '&order_id=' . urlencode($order['order_number']);

        // v2 OAuth flow for both sandbox and production
        $accessToken = getAccessToken($config);

        $payload = [
            'merchantOrderId' => $transaction_id,
            'amount' => (int)($amount * 100),
            'expireAfter' => 1200,
            'paymentFlow' => [
                'type' => 'PG_CHECKOUT',
                'merchantUrls' => [
                    'redirectUrl' => $redirectUrl,
                ],
            ],
        ];

        $ch = curl_init($config['pay_url']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: O-Bearer ' . $accessToken,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            throw new Exception('Payment gateway connection error: ' . $curl_error);
        }

        $response_data = json_decode($response, true);

        if ($http_code === 200 && isset($response_data['redirectUrl'])) {
            $payment_url = $response_data['redirectUrl'];

            ob_end_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Redirecting to PhonePe...',
                'payment_url' => $payment_url,
                'transaction_id' => $transaction_id,
                'payment_id' => $payment_id,
                'phonepe_order_id' => $response_data['orderId'] ?? '',
            ]);
            exit();
        } else {
            $error_message = ($response_data['message'] ?? 'Payment initiation failed') . ' (client: ' . $config['client_id'] . ', code: ' . ($response_data['code'] ?? $http_code) . ')';
            throw new Exception($error_message);
        }
    } catch (Exception $e) {
        error_log("PhonePe order payment error: " . $e->getMessage());
        if (ob_get_level()) ob_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'An error occurred processing your payment. Please try again.'
        ]);
        exit();
    }
}

function handleStatusCheck() {
    $orderNumber = $_GET['order_id'] ?? '';
    $phonepeRedirectCode = $_GET['code'] ?? '';
    $allGet = $_GET;
    unset($allGet['action']);
    error_log('[PhonePe] Status check for order: ' . $orderNumber);

    if (!$orderNumber) {
        error_log('[PhonePe] Missing order_id in status check');
        if (ob_get_level()) ob_clean();
        echo json_encode(['success' => false, 'message' => 'Missing order_id']);
        exit();
    }

    try {
        $conn = getConnection();


        $stmt = $conn->prepare("SELECT p.id as payment_id, p.order_id, p.transaction_id, p.payment_status, o.restaurant_id FROM payments p JOIN orders o ON p.order_id = o.id WHERE o.order_number = ? AND p.payment_method = 'Online' AND p.transaction_id LIKE 'PP_%' ORDER BY p.id DESC LIMIT 1");
        $stmt->execute([$orderNumber]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            error_log('[PhonePe] No payment record found for order: ' . $orderNumber);
            if (ob_get_level()) ob_clean();
            echo json_encode(['success' => false, 'message' => 'Payment record not found']);
            exit();
        }

        error_log('[PhonePe] Found payment id=' . $payment['payment_id'] . ' status=' . $payment['payment_status']);

        // If Pending in DB, check real-time status with PhonePe API
        if ($payment['payment_status'] === 'Pending') {
            error_log('[PhonePe] Payment is Pending, checking PhonePe API...');
            $apiStatusConfirmed = false;

            // IMPORTANT: Do NOT auto-confirm via PAYMENT_SUCCESS redirect code alone.
            // The redirect URL can be faked. Only trust:
            // 1) PhonePe webhook (phonepe_order_callback.php)
            // 2) PhonePe status API check (below)
            // 
            // The $phonepeRedirectCode is logged for debugging but never used for payment confirmation.


            // Always use status API/webhook, never auto-confirm from redirect
            try {
                $config = getPhonePeConfig($payment['restaurant_id']);

                if (!$config['demo_mode'] && !empty($config['client_id'])) {
                    try {
                        $accessToken = getAccessToken($config);
                        $statusUrl = $config['api_url'] . '/checkout/v2/order/' . urlencode($payment['transaction_id']) . '/status';

                        $ch = curl_init($statusUrl);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Authorization: O-Bearer ' . $accessToken,
                            'Accept: application/json',
                        ]);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

                        $statusResponse = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        if ($httpCode === 200 && $statusResponse) {
                            $statusData = json_decode($statusResponse, true);
                            $state = $statusData['state'] ?? $statusData['orderStatus'] ?? '';

                            if (in_array($state, ['COMPLETED', 'SUCCESS', 'PAYMENT_SUCCESS'])) {
                                $justConfirmedOrderId = null;
                                $conn->beginTransaction();
                                try {
                                    $updatePay = $conn->prepare("UPDATE payments SET payment_status = 'Success' WHERE id = ?");
                                    $updatePay->execute([$payment['payment_id']]);
                                    // Use state machine for atomic payment status on orders
                                    $payResult = validateAndUpdatePaymentStatus($conn, (int)($payment['order_id'] ?? 0), 'Paid');
                                    if (!$payResult['success']) {
                                        error_log('[PhonePe] State machine rejected: ' . $payResult['message']);
                                    } elseif (!empty($payResult['transitioned'])) {
                                        $justConfirmedOrderId = (int)($payment['order_id'] ?? 0);
                                    }
                                    $conn->commit();
                                    $payment['payment_status'] = 'Success';
                                    $apiStatusConfirmed = true;
                                } catch (Exception $tx) {
                                    $conn->rollBack();
                                    error_log('[PhonePe] Transaction failed: ' . $tx->getMessage());
                                }
                                if ($justConfirmedOrderId !== null) {
                                    require_once __DIR__ . '/../config/order_confirmation.php';
                                    try {
                                        fireOrderConfirmedActions($conn, $justConfirmedOrderId);
                                    } catch (Exception $e) {
                                        error_log('Order confirmation actions error (status poll): ' . $e->getMessage());
                                    }
                                }
                            } elseif (in_array($state, ['FAILED', 'REJECTED', 'CANCELLED', 'EXPIRED'])) {
                                $updatePay = $conn->prepare("UPDATE payments SET payment_status = 'Failed' WHERE id = ?");
                                $updatePay->execute([$payment['payment_id']]);
                                $payment['payment_status'] = 'Failed';
                            }
                        }
                    } catch (Exception $e) {
                        error_log('[PhonePe] Status API error: ' . $e->getMessage());
                    }
                }

                // Recheck DB for webhook update
                if (!$apiStatusConfirmed && $payment['payment_status'] === 'Pending') {
                    $recheck = $conn->prepare("SELECT payment_status FROM payments WHERE id = ?");
                    $recheck->execute([$payment['payment_id']]);
                    $current = $recheck->fetchColumn();
                    if ($current && $current !== 'Pending') {
                        $payment['payment_status'] = $current;
                        $apiStatusConfirmed = true;
                    }
                }
            } catch (Exception $e) {
                error_log('[PhonePe] Exception in status check: ' . $e->getMessage());
            }
        } else {

        }

        $response = ['success' => true, 'payment_status' => $payment['payment_status']];
        // Include debug info only when ?debug=1 is passed (for debugging tools)
        if (isset($_GET['debug']) && $_GET['debug'] === '1') {
            $response['debug_txn'] = $payment['transaction_id'];
        }

        
        if (ob_get_level()) ob_clean();
        echo json_encode($response);
        exit();
    } catch (Exception $e) {
        error_log('[PhonePe] Database/connection error: ' . $e->getMessage());
        if (ob_get_level()) ob_clean();
        echo json_encode(['success' => false, 'message' => 'An error occurred checking payment status. Please try again.']);
        exit();
    }
}
