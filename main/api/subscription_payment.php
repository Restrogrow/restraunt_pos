<?php
/**
 * Subscription Payment API
 * Initiate PhonePe payment for subscription renewal
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/phonepe_verify.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../db_connection.php';

// Require login
if (!isset($_SESSION['user_id']) || !isset($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit();
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

try {
    $conn = getConnection();
    $user_id = $_SESSION['user_id'];
    $restaurant_id = $_SESSION['restaurant_id'];
    session_write_close(); // only reads the session — release its lock while PhonePe is called

    switch ($action) {

        case 'getStatus':
            // Get current subscription status for the logged-in user
            $stmt = $conn->prepare("SELECT subscription_status, trial_end_date, renewal_date, is_active, auto_pay, auto_pay_method FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new Exception('User not found');
            }

            // Calculate days remaining
            $expiryDate = $user['renewal_date'] ?? $user['trial_end_date'] ?? null;
            $daysLeft = 0;
            if ($expiryDate) {
                $expiry = new DateTime($expiryDate);
                $now = new DateTime();
                $daysLeft = max(0, (int)$now->diff($expiry)->days);
                if ($expiry < $now && $user['subscription_status'] !== 'disabled') {
                    $daysLeft = 0;
                }
            }

            // Get last payment
            $payStmt = $conn->prepare("SELECT * FROM subscription_payments WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
            $payStmt->execute([$user_id]);
            $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'status' => $user['subscription_status'] ?? 'trial',
                'trial_end_date' => $user['trial_end_date'],
                'renewal_date' => $user['renewal_date'],
                'is_active' => (bool)$user['is_active'],
                'days_left' => $daysLeft,
                'auto_pay' => (bool)$user['auto_pay'],
                'auto_pay_method' => $user['auto_pay_method'] ?? 'phonepe',
                'payments' => $payments
            ]);
            break;

        case 'initiatePayment':
            // Initiate PhonePe payment for subscription renewal. The amount is the plan price from
            // Super Admin › Settings, never the one the page sends; PhonePe (INR) is India-only.
            require_once __DIR__ . '/../config/platform_engine.php';
            pfAssertCanRenewOnline($conn, $restaurant_id);
            $subscription_type = ($_POST['subscription_type'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
            [$amount, $duration_months] = pfRenewalPrice($conn, $subscription_type);

            if ($amount <= 0) {
                throw new Exception('Online renewal is not available right now. Please contact RestroGrow support.');
            }

            // Get restaurant info
            $restStmt = $conn->prepare("SELECT restaurant_name FROM users WHERE id = ?");
            $restStmt->execute([$user_id]);
            $restaurant = $restStmt->fetch(PDO::FETCH_ASSOC);
            $restaurant_name = $restaurant['restaurant_name'] ?? 'Restaurant';

            // Generate unique transaction ID
            $transaction_id = 'SUB_' . strtoupper(substr($restaurant_id, 0, 6)) . '_' . time() . '_' . rand(100, 999);

            // Create subscription_payments record (pending)
            $stmt = $conn->prepare("INSERT INTO subscription_payments (user_id, restaurant_id, transaction_id, amount, subscription_type, duration_months, payment_status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([$user_id, $restaurant_id, $transaction_id, $amount, $subscription_type, $duration_months]);
            $payment_id = $conn->lastInsertId();

            // ========== PhonePe Integration (v2 OAuth checkout) ==========
            // RestroGrow's own PhonePe account only (null = platform .env): with the restaurant's
            // own keys the money went to the restaurant, and a sandbox key could "pay" for free.
            $config = phonepeCallbackConfig(null);
            $demoMode = empty($config['client_id']) || empty($config['client_secret']);

            if ($demoMode) {
                // Demo mode - simulate payment
                $demo_success_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/views/dashboard.php?demo_payment=success&txn=' . urlencode($transaction_id);

                echo json_encode([
                    'success' => true,
                    'message' => 'Demo payment mode',
                    'payment_url' => null,
                    'transaction_id' => $transaction_id,
                    'payment_id' => $payment_id,
                    'amount' => $amount,
                    'subscription_type' => $subscription_type,
                    'demo_mode' => true,
                    'demo_complete_url' => $demo_success_url
                ]);
                exit();
            }

            // Build redirect URL (dashboard) — v2 has no per-request callbackUrl;
            // the webhook is configured account-wide in the PhonePe dashboard.
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base_path = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
            if ($base_path === '/' || $base_path === '\\') $base_path = '';
            $site_url = $scheme . '://' . $host . $base_path;
            $redirect_url = $site_url . '/main/views/dashboard.php';

            $accessToken = phonepeCallbackAccessToken($config);
            if (!$accessToken) {
                throw new Exception('Failed to authenticate with payment gateway');
            }

            $payload = [
                'merchantOrderId' => $transaction_id,
                'amount' => (int)round($amount * 100), // Convert to paise
                'expireAfter' => 1200,
                'paymentFlow' => [
                    'type' => 'PG_CHECKOUT',
                    'merchantUrls' => [
                        'redirectUrl' => $redirect_url,
                    ],
                ],
            ];

            // Initiate payment via PhonePe v2 checkout API
            $ch = curl_init($config['api_url'] . '/checkout/v2/pay');
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
                echo json_encode([
                    'success' => true,
                    'message' => 'Payment initiated successfully',
                    'payment_url' => $response_data['redirectUrl'],
                    'transaction_id' => $transaction_id,
                    'payment_id' => $payment_id,
                    'amount' => $amount,
                    'subscription_type' => $subscription_type,
                    'demo_mode' => false
                ]);
            } else {
                $error_msg = $response_data['message'] ?? ($response_data['code'] ?? 'Payment initiation failed');
                throw new Exception($error_msg);
            }
            break;

        case 'checkPaymentStatus':
            // Check the status of a subscription payment
            $transaction_id = trim(is_string($_GET['transaction_id'] ?? null) ? $_GET['transaction_id'] : '');
            if (empty($transaction_id)) {
                throw new Exception('Transaction ID is required');
            }

            $stmt = $conn->prepare("SELECT * FROM subscription_payments WHERE transaction_id = ? AND user_id = ? LIMIT 1");
            $stmt->execute([$transaction_id, $user_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                throw new Exception('Payment record not found');
            }

            // If still pending, independently verify with PhonePe's v2 status
            // API (never trust a redirect/webhook body directly — see
            // config/phonepe_verify.php).
            if ($payment['payment_status'] === 'pending') {
                // checked against RestroGrow's own PhonePe account (see initiatePayment)
                $verifiedState = phonepeVerifyOrderState($conn, null, $transaction_id);

                if ($verifiedState === 'COMPLETED' || $verifiedState === 'SUCCESS') {
                    // only the request that flips it to success extends the plan (the webhook may race this poll)
                    $updateStmt = $conn->prepare("UPDATE subscription_payments SET payment_status = 'success', updated_at = NOW() WHERE id = ? AND payment_status <> 'success'");
                    $updateStmt->execute([$payment['id']]);

                    // Activate subscription
                    if ($updateStmt->rowCount() > 0) activateSubscription($conn, $user_id, $restaurant_id, $payment['duration_months']);

                    $payment['payment_status'] = 'success';
                } elseif (in_array($verifiedState, ['FAILED', 'REJECTED', 'CANCELLED'])) {
                    $updateStmt = $conn->prepare("UPDATE subscription_payments SET payment_status = 'failed', updated_at = NOW() WHERE id = ?");
                    $updateStmt->execute([$payment['id']]);
                    $payment['payment_status'] = 'failed';
                }
                // null or PENDING: leave as pending, the client will poll again
            }

            echo json_encode([
                'success' => true,
                'payment_status' => $payment['payment_status'],
                'payment' => $payment
            ]);
            break;

        case 'toggleAutoPay':
            // Enable/disable auto-pay for subscription
            $enable = !empty($_POST['enable']) ? 1 : 0;
            $method = trim(is_string($_POST['method'] ?? null) ? $_POST['method'] : 'phonepe');

            $stmt = $conn->prepare("UPDATE users SET auto_pay = ?, auto_pay_method = ? WHERE id = ?");
            $stmt->execute([$enable, $method, $user_id]);

            echo json_encode([
                'success' => true,
                'message' => $enable ? 'Auto-pay enabled' : 'Auto-pay disabled',
                'auto_pay' => (bool)$enable,
                'method' => $method
            ]);
            break;

        default:
            throw new Exception('Invalid action. Use: getStatus, initiatePayment, checkPaymentStatus, toggleAutoPay');
    }

} catch (Throwable $e) {
    // our own checks and the gateway's reply explain themselves; a database or code error stays in the log
    if ($e instanceof PDOException || !($e instanceof Exception)) {
        error_log('subscription_payment.php: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
        exit();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Helper: Activate subscription for a user
 */
function activateSubscription($conn, $user_id, $restaurant_id, $duration_months = 1) {
    // added on top of the days still left (an early renewal must not lose them), as Super Admin does
    $duration_months = max(1, (int)$duration_months);
    $stmt = $conn->prepare("UPDATE users SET subscription_status = 'active', renewal_date = DATE_ADD(GREATEST(COALESCE(renewal_date, CURDATE()), CURDATE()), INTERVAL ? MONTH), is_active = 1 WHERE id = ?");
    $stmt->execute([$duration_months, $user_id]);
    $renewal_date = (string)$conn->query('SELECT renewal_date FROM users WHERE id = ' . (int)$user_id)->fetchColumn();

    error_log("Subscription activated for user {$user_id} (restaurant {$restaurant_id}) until {$renewal_date}");
}
