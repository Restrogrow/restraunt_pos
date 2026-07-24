<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';

// Load environment variables
if (file_exists(__DIR__ . '/../config/env_loader.php')) {
    require_once __DIR__ . '/../config/env_loader.php';
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Require permission to manage payments
requirePermission(PERMISSION_MANAGE_PAYMENTS);

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
}

// PhonePe API Configuration
// ==========================================
// IMPORTANT: There are TWO types of credentials:
//
// 1. TEST/SANDBOX credentials:
//    - For testing (no real money transactions)
//    - Get from: https://developer.phonepe.com/ (free)
//    - URL: https://api-preprod.phonepe.com/apis/pg-sandbox
//    - Transactions are simulated, no actual payments
//
// 2. PRODUCTION/LIVE credentials:
//    - For real payments (real money)
//    - Need to apply and get approved by PhonePe
//    - URL: https://api.phonepe.com/apis/pg-sandbox (or production endpoint)
//    - REAL transactions with actual money
//
// ==========================================

// Environment: 'test' or 'production' - Load from .env
if (!defined('PHONEPE_ENVIRONMENT')) {
    define('PHONEPE_ENVIRONMENT', env('PHONEPE_ENVIRONMENT', 'test'));
}

// PhonePe Credentials - Load from .env
if (!defined('PHONEPE_MERCHANT_ID')) {
    define('PHONEPE_MERCHANT_ID', env('PHONEPE_MERCHANT_ID', 'YOUR_MERCHANT_ID'));
}
if (!defined('PHONEPE_SALT_KEY')) {
    define('PHONEPE_SALT_KEY', env('PHONEPE_SALT_KEY', 'YOUR_SALT_KEY'));
}
if (!defined('PHONEPE_SALT_INDEX')) {
    define('PHONEPE_SALT_INDEX', env('PHONEPE_SALT_INDEX', '1'));
}

// Set base URL based on environment
if (!defined('PHONEPE_BASE_URL')) {
    if (PHONEPE_ENVIRONMENT === 'production') {
        // PRODUCTION/LIVE - Real payments with real money
        define('PHONEPE_BASE_URL', env('PHONEPE_BASE_URL_PRODUCTION', 'https://api.phonepe.com/apis/pg-sandbox'));
    } else {
        // TEST/SANDBOX - No real money
        define('PHONEPE_BASE_URL', env('PHONEPE_BASE_URL_TEST', 'https://api-preprod.phonepe.com/apis/pg-sandbox'));
    }
}

// Callback URLs - Load from .env
if (!defined('PHONEPE_CALLBACK_URL')) {
    define('PHONEPE_CALLBACK_URL', env('PHONEPE_CALLBACK_URL', 'http://localhost/menu/phonepe_callback.php'));
}
if (!defined('PHONEPE_REDIRECT_URL')) {
    define('PHONEPE_REDIRECT_URL', env('PHONEPE_REDIRECT_URL', 'http://localhost/menu/dashboard.php'));
}

// DEMO MODE: Set to true to simulate payment without calling PhonePe API at all
// Set to false when you have PhonePe credentials (test or production)
if (!defined('PHONEPE_DEMO_MODE')) {
    $demoMode = env('PHONEPE_DEMO_MODE', 'true');
    define('PHONEPE_DEMO_MODE', $demoMode === 'true' || $demoMode === true);
}

try {
    $conn = getConnection();
    $user_id = $_SESSION['user_id'];
    $restaurant_id = $_SESSION['restaurant_id'];
    
    // Check if subscription_payments table exists
    $check_table = $conn->query("SHOW TABLES LIKE 'subscription_payments'");
    if ($check_table->rowCount() === 0) {
        throw new Exception('Subscription payments table not found. Please run the migration: migrations/run_subscription_payments_migration.php');
    }
    
    $amount = floatval($_POST['amount'] ?? 999.00); // Default ₹999 for subscription renewal
    $subscription_type = $_POST['subscription_type'] ?? 'monthly'; // monthly or yearly
    
    // Validate amount
    if ($amount <= 0) {
        throw new Exception('Invalid amount');
    }
    
    // Generate unique transaction ID
    $transaction_id = 'TXN_' . time() . '_' . uniqid();
    
    // Create payment record in database (pending status)
    try {
        $stmt = $conn->prepare("INSERT INTO subscription_payments (user_id, restaurant_id, transaction_id, amount, subscription_type, payment_status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
        $stmt->execute([$user_id, $restaurant_id, $transaction_id, $amount, $subscription_type]);
        $payment_id = $conn->lastInsertId();
    } catch (PDOException $e) {
        // If table doesn't exist or there's a SQL error
        if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "Unknown table") !== false) {
            throw new Exception('Database table error. Please run the migration: http://localhost/menu/migrations/run_subscription_payments_migration.php');
        }
        throw $e;
    }
    
    // DEMO MODE: Simulate payment without calling PhonePe API
    if (defined('PHONEPE_DEMO_MODE') && PHONEPE_DEMO_MODE === true) {
        // In demo mode, redirect to a demo payment page
        $demo_payment_url = str_replace('/dashboard.php', '/demo_payment.php', PHONEPE_REDIRECT_URL);
        $demo_payment_url .= '?transaction_id=' . urlencode($transaction_id) . '&amount=' . urlencode($amount);
        
        echo json_encode([
            'success' => true,
            'message' => 'Demo payment mode - redirecting to demo page',
            'payment_url' => $demo_payment_url,
            'transaction_id' => $transaction_id,
            'payment_id' => $payment_id,
            'demo_mode' => true
        ]);
        exit();
    }
    
    // Real PhonePe API Integration
    // Check if credentials are set
    if (PHONEPE_MERCHANT_ID === 'YOUR_MERCHANT_ID' || PHONEPE_SALT_KEY === 'YOUR_SALT_KEY') {
        throw new Exception('Please configure PhonePe credentials or enable DEMO_MODE. Register at https://developer.phonepe.com/ to get test credentials.');
    }
    
    $merchant_transaction_id = $transaction_id;
    $merchant_user_id = 'USER_' . $user_id;
    $callback_url = PHONEPE_CALLBACK_URL;
    $redirect_url = PHONEPE_REDIRECT_URL;
    
    // Payment payload
    $payload = [
        'merchantId' => PHONEPE_MERCHANT_ID,
        'merchantTransactionId' => $merchant_transaction_id,
        'merchantUserId' => $merchant_user_id,
        'amount' => (int)($amount * 100), // Amount in paise (multiply by 100)
        'redirectUrl' => $redirect_url,
        'redirectMode' => 'POST',
        'callbackUrl' => $callback_url,
        'mobileNumber' => '',
        'paymentInstrument' => [
            'type' => 'PAY_PAGE'
        ]
    ];
    
    // Create base64 encoded payload
    $base64_payload = base64_encode(json_encode($payload));
    
    // Create X-VERIFY header (SHA256 hash)
    $string_to_hash = $base64_payload . '/pg/v1/pay' . PHONEPE_SALT_KEY;
    $sha256_hash = hash('sha256', $string_to_hash);
    $x_verify = $sha256_hash . '###' . PHONEPE_SALT_INDEX;
    
    // Prepare request data
    $request_data = [
        'request' => $base64_payload
    ];
    
    // Initialize cURL
    $ch = curl_init(PHONEPE_BASE_URL . '/pg/v1/pay');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-VERIFY: ' . $x_verify
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        throw new Exception('Payment gateway error: ' . $curl_error);
    }
    
    $response_data = json_decode($response, true);
    
    if ($http_code === 200 && isset($response_data['success']) && $response_data['success'] === true) {
        // Payment initiated successfully
        $redirect_url = $response_data['data']['instrumentResponse']['redirectInfo']['url'] ?? null;
        
        if ($redirect_url) {
            echo json_encode([
                'success' => true,
                'message' => 'Payment initiated successfully',
                'payment_url' => $redirect_url,
                'transaction_id' => $transaction_id,
                'payment_id' => $payment_id
            ]);
        } else {
            throw new Exception('Payment URL not received from gateway');
        }
    } else {
        $error_message = $response_data['message'] ?? 'Payment initiation failed';
        throw new Exception($error_message);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error initiating payment: ' . $e->getMessage()
    ]);
}
?>

