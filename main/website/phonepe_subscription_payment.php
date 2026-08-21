<?php
/**
 * Online payment (PhonePe: UPI/cards/netbanking) for a customer's meal-plan
 * subscription. Mirrors main/api/phonepe_order_payment.php's own-vs-platform
 * gateway logic (users.payment_gateway_mode / phonepe_merchant_id /
 * phonepe_salt_key / phonepe_environment) but drives meal_plan_payments +
 * customer_meal_subscriptions instead of payments + orders.
 *
 * Unlike phonepe_order_payment.php (a JSON API called via fetch), this is a
 * full-page redirect target - subscribe.php navigates here directly after
 * create_subscription returns phonepe_required=true, so action=initiate
 * responds with an HTTP redirect (to PhonePe, or straight back for demo
 * mode/errors) rather than JSON. action=status is the one JSON endpoint,
 * polled from my-subscription.php the same way cart.php polls order status.
 */
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../config/meal_subscription_schema.php';
require_once __DIR__ . '/../config/phonepe_verify.php';

if (!mealSubscriptionsFeatureEnabled($conn, $restaurant_id)) {
    header('Location: ' . restaurantPageUrl('menu'));
    exit();
}

$action = $_GET['action'] ?? 'initiate';

if ($action === 'status') {
    handleStatusCheck($conn, $restaurant_id, $logged_in_customer);
} else {
    handleInitiate($conn, $restaurant_id, $logged_in_customer);
}

function getBaseUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($basePath === '/' || $basePath === '\\') $basePath = '';
    return $scheme . '://' . $host . $basePath;
}

function mySubscriptionUrl($restaurantId, $extraParams = []) {
    $params = array_merge(['restaurant_id' => $restaurantId], $extraParams);
    return getBaseUrl() . '/main/website/my-subscription.php?' . http_build_query($params);
}

function getPhonePeSubConfig($restaurantId) {
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
                $gwConn = getConnection();
                $stmt = $gwConn->prepare("SELECT phonepe_merchant_id, phonepe_salt_key, phonepe_environment, payment_gateway_mode FROM users WHERE restaurant_id = ? LIMIT 1");
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

    return [
        'environment' => $environment,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'client_version' => $clientVersion,
        'api_url' => $apiBase,
        'auth_url' => $authUrl,
        'pay_url' => $apiBase . '/checkout/v2/pay',
        'demo_mode' => $demoMode,
        'is_sandbox' => $isSandbox,
    ];
}

function getSubAccessToken($config) {
    $cacheKey = 'pps_token_' . md5($config['client_id']);
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
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

    $_SESSION[$cacheKey] = $data['access_token'];
    $_SESSION[$cacheKey . '_expires'] = $data['expires_at'] ?? (time() + 3600);

    return $data['access_token'];
}

/**
 * Loads the subscription (must belong to the logged-in customer) and its
 * most recent online-payment row. Amount is always read from this row, never
 * from the query string, the same server-authoritative-pricing rule used
 * throughout the codebase (process_website_order.php, phonepe_order_payment.php).
 */
function loadOwnSubscriptionForPayment($conn, $restaurantId, $customer, $subscriptionId) {
    if (!$customer) {
        throw new Exception('Please log in to continue');
    }
    if ($subscriptionId <= 0) {
        throw new Exception('Missing subscription');
    }

    $stmt = $conn->prepare("SELECT * FROM customer_meal_subscriptions WHERE id = ? AND customer_id = ? AND restaurant_id = ? LIMIT 1");
    $stmt->execute([$subscriptionId, $customer['id'], $restaurantId]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sub) {
        throw new Exception('Subscription not found');
    }

    $payStmt = $conn->prepare("SELECT * FROM meal_plan_payments WHERE subscription_id = ? AND payment_method = 'UPI / NetBanking' ORDER BY id DESC LIMIT 1");
    $payStmt->execute([$subscriptionId]);
    $payment = $payStmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) {
        throw new Exception('No online payment found for this subscription');
    }

    return [$sub, $payment];
}

function handleInitiate($conn, $restaurantId, $customer) {
    $subscriptionId = (int)($_GET['subscription_id'] ?? 0);

    try {
        list($sub, $payment) = loadOwnSubscriptionForPayment($conn, $restaurantId, $customer, $subscriptionId);

        if ($sub['status'] !== 'pending_payment') {
            // Already active (or cancelled) - nothing to pay, just go back.
            header('Location: ' . mySubscriptionUrl($restaurantId));
            exit();
        }

        $amount = (float)$payment['amount'];
        if ($amount <= 0) {
            throw new Exception('Invalid amount');
        }

        $config = getPhonePeSubConfig($restaurantId);
        $transactionId = 'PPS_' . $subscriptionId . '_' . bin2hex(random_bytes(12));

        $updStmt = $conn->prepare("UPDATE meal_plan_payments SET transaction_id = ?, payment_status = 'Pending' WHERE id = ?");
        $updStmt->execute([$transactionId, $payment['id']]);

        if ($config['demo_mode'] || empty($config['client_id']) || empty($config['client_secret'])) {
            header('Location: ' . mySubscriptionUrl($restaurantId, [
                'subscription_id' => $subscriptionId,
                'phonepe_demo' => 1,
                'transaction_id' => $transactionId,
            ]));
            exit();
        }

        $redirectUrl = mySubscriptionUrl($restaurantId, ['subscription_id' => $subscriptionId]);
        $accessToken = getSubAccessToken($config);

        $payload = [
            'merchantOrderId' => $transactionId,
            'amount' => (int)round($amount * 100),
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
            header('Location: ' . $response_data['redirectUrl']);
            exit();
        }

        throw new Exception(($response_data['message'] ?? 'Payment initiation failed') . ' (code: ' . ($response_data['code'] ?? $http_code) . ')');
    } catch (Exception $e) {
        error_log('PhonePe subscription payment initiate error: ' . $e->getMessage());
        header('Location: ' . mySubscriptionUrl($restaurantId, ['payment_error' => 1]));
        exit();
    }
}

function handleStatusCheck($conn, $restaurantId, $customer) {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json; charset=UTF-8');

    $subscriptionId = (int)($_GET['subscription_id'] ?? 0);

    try {
        list($sub, $payment) = loadOwnSubscriptionForPayment($conn, $restaurantId, $customer, $subscriptionId);

        if ($payment['payment_status'] === 'Pending' && !empty($payment['transaction_id'])) {
            $verifiedState = phonepeVerifyOrderState($conn, $restaurantId, $payment['transaction_id']);

            if ($verifiedState === 'COMPLETED') {
                $conn->beginTransaction();
                try {
                    $conn->prepare("UPDATE meal_plan_payments SET payment_status = 'Success' WHERE id = ?")->execute([$payment['id']]);
                    // Guard on status = pending_payment so a race between the
                    // poll and the webhook can't double-fire activation logic.
                    $conn->prepare("UPDATE customer_meal_subscriptions SET status = 'active' WHERE id = ? AND status = 'pending_payment'")->execute([$sub['id']]);
                    $conn->commit();
                    $payment['payment_status'] = 'Success';
                    $sub['status'] = 'active';
                } catch (Exception $tx) {
                    $conn->rollBack();
                    error_log('PhonePe subscription status: transaction failed - ' . $tx->getMessage());
                }
            } elseif (in_array($verifiedState, ['FAILED', 'REJECTED', 'CANCELLED', 'EXPIRED'], true)) {
                $conn->prepare("UPDATE meal_plan_payments SET payment_status = 'Failed' WHERE id = ?")->execute([$payment['id']]);
                $payment['payment_status'] = 'Failed';
            } else {
                // Could not independently verify yet, or still pending on
                // PhonePe's side - recheck the DB in case the webhook
                // (phonepe_subscription_callback.php) already updated it.
                $recheck = $conn->prepare("SELECT payment_status FROM meal_plan_payments WHERE id = ?");
                $recheck->execute([$payment['id']]);
                $current = $recheck->fetchColumn();
                if ($current && $current !== 'Pending') {
                    $payment['payment_status'] = $current;
                    if ($current === 'Success') {
                        $subRecheck = $conn->prepare("SELECT status FROM customer_meal_subscriptions WHERE id = ?");
                        $subRecheck->execute([$sub['id']]);
                        $sub['status'] = $subRecheck->fetchColumn() ?: $sub['status'];
                    }
                }
            }
        }

        echo json_encode([
            'success' => true,
            'payment_status' => $payment['payment_status'],
            'subscription_status' => $sub['status'],
        ]);
    } catch (Exception $e) {
        error_log('PhonePe subscription status check error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred checking payment status. Please try again.']);
    }
    exit();
}
