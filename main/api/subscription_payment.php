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
            // Initiate PhonePe payment for subscription renewal
            $amount = floatval($_POST['amount'] ?? 999.00);
            $subscription_type = $_POST['subscription_type'] ?? 'monthly'; // monthly, yearly
            $duration_months = 1;

            if ($subscription_type === 'yearly') {
                $amount = floatval($_POST['amount'] ?? 9990.00); // ₹9,990/year
                $duration_months = 12;
            }

            // Validate amount
            if ($amount <= 0) {
                throw new Exception('Invalid amount');
            }

            if ($amount > 100000) {
                throw new Exception('Amount exceeds maximum limit');
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
            // Same credential resolution (restaurant's own > platform env) and
            // v2 API config as the order-payment flow (phonepe_order_payment.php).
            $config = phonepeCallbackConfig($restaurant_id);
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
                'amount' => (int)($amount * 100), // Convert to paise
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
            $transaction_id = trim($_GET['transaction_id'] ?? '');
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
                $verifiedState = phonepeVerifyOrderState($conn, $restaurant_id, $transaction_id);

                if ($verifiedState === 'COMPLETED' || $verifiedState === 'SUCCESS') {
                    $updateStmt = $conn->prepare("UPDATE subscription_payments SET payment_status = 'success', updated_at = NOW() WHERE id = ?");
                    $updateStmt->execute([$payment['id']]);

                    // Activate subscription
                    activateSubscription($conn, $user_id, $restaurant_id, $payment['duration_months']);

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
            $method = trim($_POST['method'] ?? 'phonepe');

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

} catch (Exception $e) {
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
    $renewal_date = date('Y-m-d', strtotime("+{$duration_months} months"));
    $stmt = $conn->prepare("UPDATE users SET subscription_status = 'active', renewal_date = ?, is_active = 1 WHERE id = ?");
    $stmt->execute([$renewal_date, $user_id]);

    error_log("Subscription activated for user {$user_id} (restaurant {$restaurant_id}) until {$renewal_date}");
}
