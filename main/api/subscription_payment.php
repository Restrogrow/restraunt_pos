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

require_once __DIR__ . '/../config/payment_settle.php';

header('Content-Type: application/json');

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
            // a renewal paid but not confirmed yet (window closed early, webhook late) is settled here first
            $open = $conn->prepare("SELECT transaction_id FROM subscription_payments WHERE user_id = ? AND payment_status = 'pending' AND transaction_id LIKE 'SUB\_%' AND created_at > NOW() - INTERVAL 2 DAY ORDER BY id DESC LIMIT 3");
            $open->execute([$user_id]);
            foreach ($open->fetchAll(PDO::FETCH_COLUMN) as $t) {
                try { ppSettle($conn, $t); } catch (Throwable $e) { error_log('subscription getStatus settle: ' . $e->getMessage()); }
            }

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

            // RestroGrow's own PhonePe account only (platform .env): with the restaurant's own keys the money
            // went to the restaurant, and a sandbox key could "pay" for free. Demo only on a local computer.
            $config = phonepeCallbackConfig(null, true);
            if (!$config['ready'] && !$config['demo']) {
                throw new Exception('Online renewal is not available right now. Please contact RestroGrow support.');
            }

            $transaction_id = ppTxnId('SUB_', (string)$restaurant_id);

            // Create subscription_payments record (pending)
            $stmt = $conn->prepare("INSERT INTO subscription_payments (user_id, restaurant_id, transaction_id, amount, subscription_type, duration_months, payment_status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([$user_id, $restaurant_id, $transaction_id, $amount, $subscription_type, $duration_months]);
            $payment_id = $conn->lastInsertId();

            if ($config['demo']) {
                // local computer without real keys: pretend (never on the live site, see ppIsLocal)
                echo json_encode([
                    'success' => true,
                    'message' => 'Demo payment mode',
                    'payment_url' => null,
                    'transaction_id' => $transaction_id,
                    'payment_id' => $payment_id,
                    'amount' => $amount,
                    'subscription_type' => $subscription_type,
                    'demo_mode' => true,
                ]);
                exit();
            }

            // back to the dashboard; the webhook (api/phonepe_webhook.php) and the safety net settle it too
            $app = rtrim((string)env('APP_URL', ''), '/');
            if ($app === '' || ppIsLocal()) {
                $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $base_path = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')));
                if ($base_path === '/' || $base_path === '\\' || $base_path === '.') $base_path = '';
                $app = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base_path;
            }
            $redirect_url = $app . '/main/views/dashboard.php?renewal=' . urlencode($transaction_id);

            $res = phonepeCreatePayment($config, $transaction_id, (float)$amount, $redirect_url, 1200, 'RestroGrow plan for ' . $restaurant_name);
            if (!$res['ok']) {
                $conn->prepare("UPDATE subscription_payments SET payment_status = 'failed', updated_at = NOW() WHERE id = ?")->execute([$payment_id]);
                error_log('subscription renewal PhonePe refused: ' . $res['error']);
                throw new Exception('Could not start the payment. Please try again in a minute.');
            }
            echo json_encode([
                'success' => true,
                'message' => 'Payment initiated successfully',
                'payment_url' => $res['url'],
                'transaction_id' => $transaction_id,
                'payment_id' => $payment_id,
                'amount' => $amount,
                'subscription_type' => $subscription_type,
                'demo_mode' => false
            ]);
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

            // still pending: PhonePe's own answer, amount checked, plan extended once (config/payment_settle.php)
            if ($payment['payment_status'] === 'pending') {
                ppSettle($conn, $transaction_id);
                $stmt->execute([$transaction_id, $user_id]);
                $payment = $stmt->fetch(PDO::FETCH_ASSOC);
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
            $method = in_array($_POST['method'] ?? '', ['phonepe', 'upi', 'card'], true) ? $_POST['method'] : 'phonepe';

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
