<?php
// Customer account auth for the customer-facing website: signup, login,
// logout, forgot/reset password, guarded by the same rate-limit tiers as
// the restaurant-owner login (main/admin/auth.php).
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

ob_start();

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
ob_clean();

header('Content-Type: application/json; charset=UTF-8');

session_write_close();

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/customer_session.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed');
    }

    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    if (empty($action)) {
        throw new Exception('Action is required');
    }

    if (file_exists(__DIR__ . '/../config/rate_limit.php')) {
        require_once __DIR__ . '/../config/rate_limit.php';
        switch ($action) {
            case 'login': applyAuthRateLimit('login'); break;
            case 'signup': applyAuthRateLimit('signup'); break;
            case 'forgotPassword': applyAuthRateLimit('forgotPwd'); break;
            case 'resetPassword': applyAuthRateLimit('resetPwd'); break;
        }
    }

    $pdo = getConnection();
    ensureCustomerAuthSchema($pdo);

    switch ($action) {
        case 'signup': handleCustomerSignup($pdo); break;
        case 'login': handleCustomerLogin($pdo); break;
        case 'logout': handleCustomerLogout($pdo); break;
        case 'forgotPassword': handleCustomerForgotPassword($pdo); break;
        case 'resetPassword': handleCustomerResetPassword($pdo); break;
        default: throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function requireRestaurantId() {
    $restaurantId = isset($_POST['restaurant_id']) ? trim($_POST['restaurant_id']) : '';
    if (empty($restaurantId)) {
        throw new Exception('Restaurant context is missing. Please reload the page and try again.');
    }
    return $restaurantId;
}

function handleCustomerSignup($pdo) {
    startSecureSession();

    $restaurantId = requireRestaurantId();
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $phone = isset($_POST['phone']) ? preg_replace('/\D/', '', $_POST['phone']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $confirmPassword = isset($_POST['confirmPassword']) ? $_POST['confirmPassword'] : '';
    $remember = !empty($_POST['remember']);

    if (empty($name)) throw new Exception('Please enter your name');
    if (strlen($phone) < 6 || strlen($phone) > 15) throw new Exception('Please enter a valid phone number');
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('A valid email is required (used for password recovery)');
    if (strlen($password) < 6) throw new Exception('Password must be at least 6 characters long');
    if ($password !== $confirmPassword) throw new Exception('Passwords do not match');

    $stmt = $pdo->prepare("SELECT id, password_hash FROM customers WHERE restaurant_id = ? AND phone = ? LIMIT 1");
    $stmt->execute([$restaurantId, $phone]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    $hash = password_hash($password, PASSWORD_DEFAULT);

    if ($existing) {
        if (!empty($existing['password_hash'])) {
            throw new Exception('An account already exists for this phone number. Please login instead.');
        }
        // This phone already has order history as a guest - claim it into a real account.
        $pdo->prepare("UPDATE customers SET customer_name = ?, email = ?, password_hash = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$name, $email, $hash, $existing['id']]);
        $customerId = $existing['id'];
    } else {
        $signupIp = $_SERVER['REMOTE_ADDR'] ?? '';
        require_once __DIR__ . '/../config/growth_helpers.php';
        ensureGrowthSchema($pdo);
        $pdo->prepare("INSERT INTO customers (restaurant_id, customer_name, phone, email, password_hash, signup_ip) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$restaurantId, $name, $phone, $email, $hash, $signupIp ?: null]);
        $customerId = $pdo->lastInsertId();

        // Referral capture: only for brand-new customers, not guests being
        // claimed into an account (they may already have unrelated order
        // history, which would make a post-hoc referral credit gameable).
        $refCode = isset($_POST['ref']) ? trim($_POST['ref']) : '';
        if ($refCode !== '') {
            try {
                recordPendingReferral($pdo, $restaurantId, $refCode, (int)$customerId, $signupIp);
            } catch (Exception $e) {
                error_log('Referral capture failed during signup: ' . $e->getMessage());
            }
        }
    }

    startSecureSession();
    startCustomerSession($pdo, ['id' => $customerId, 'restaurant_id' => $restaurantId], $remember);

    echo json_encode([
        'success' => true,
        'message' => 'Account created!',
        'customer' => ['name' => $name, 'phone' => $phone, 'email' => $email]
    ]);
}

function handleCustomerLogin($pdo) {
    startSecureSession();

    $restaurantId = requireRestaurantId();
    $phone = isset($_POST['phone']) ? preg_replace('/\D/', '', $_POST['phone']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $remember = !empty($_POST['remember']);

    if (empty($phone) || empty($password)) {
        throw new Exception('Please enter your phone number and password');
    }

    $rateId = 'cust_login_' . $restaurantId . '_' . $phone;

    // trackFailedAttempt($rateId) below writes a per-account lockout file
    // after repeated failures, but nothing was ever reading it back — the
    // only rate limiting actually enforced was the generic per-IP one
    // (applyAuthRateLimit('login'), keyed on IP not phone number), so an
    // attacker rotating IPs (or just staying under that per-IP cap) could
    // brute-force a single customer's password indefinitely. checkRateLimit()
    // checks the same lockout file trackFailedAttempt() writes, keyed on
    // this identifier, so calling it here actually enforces it.
    if (function_exists('checkRateLimit')) {
        $lockoutCheck = checkRateLimit($rateId, 1000, 900);
        if (!$lockoutCheck['allowed']) {
            throw new Exception($lockoutCheck['message'] ?? 'Too many failed attempts. Please try again later.');
        }
    }

    $stmt = $pdo->prepare("SELECT id, restaurant_id, customer_name, phone, email, password_hash FROM customers WHERE restaurant_id = ? AND phone = ? LIMIT 1");
    $stmt->execute([$restaurantId, $phone]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer || empty($customer['password_hash']) || !password_verify($password, $customer['password_hash'])) {
        if (function_exists('trackFailedAttempt')) trackFailedAttempt($rateId);
        throw new Exception('Invalid phone number or password');
    }

    if (function_exists('clearFailedAttempts')) clearFailedAttempts($rateId);

    startSecureSession();
    startCustomerSession($pdo, $customer, $remember);

    echo json_encode([
        'success' => true,
        'message' => 'Welcome back, ' . $customer['customer_name'] . '!',
        'customer' => ['name' => $customer['customer_name'], 'phone' => $customer['phone'], 'email' => $customer['email']]
    ]);
}

function handleCustomerLogout($pdo) {
    startSecureSession();
    clearCustomerSession($pdo);
    echo json_encode(['success' => true, 'message' => 'Logged out']);
}

function handleCustomerForgotPassword($pdo) {
    $restaurantId = requireRestaurantId();
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please enter a valid email address');
    }

    // Progressive cooldown, mirrored from the admin forgot-password flow.
    $cooldownSeconds = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as request_count, TIMESTAMPDIFF(SECOND, MAX(prt.created_at), NOW()) as seconds_since_last
            FROM customer_password_reset_tokens prt
            JOIN customers c ON prt.customer_id = c.id
            WHERE c.restaurant_id = ? AND c.email = ?
            AND prt.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$restaurantId, $email]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($data && (int)$data['request_count'] > 0) {
            $requestCount = (int)$data['request_count'];
            $secondsSinceLast = (int)($data['seconds_since_last'] ?? 0);
            $requiredCooldown = $requestCount >= 4 ? 1800 : ($requestCount >= 2 ? 60 : 0);
            if ($requiredCooldown > 0 && $secondsSinceLast < $requiredCooldown) {
                $cooldownSeconds = $requiredCooldown - $secondsSinceLast;
            }
        }
    } catch (PDOException $e) {
        error_log('handleCustomerForgotPassword cooldown check: ' . $e->getMessage());
    }

    if ($cooldownSeconds > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Please wait ' . $cooldownSeconds . ' second(s) before requesting another password reset.',
            'cooldown_seconds' => $cooldownSeconds
        ]);
        return;
    }

    $stmt = $pdo->prepare("SELECT id, customer_name, email FROM customers WHERE restaurant_id = ? AND email = ? AND password_hash IS NOT NULL LIMIT 1");
    $stmt->execute([$restaurantId, $email]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    // Always report success to avoid revealing whether the email has an account.
    $genericMessage = 'If an account exists for that email, a password reset link has been sent.';
    if (!$customer) {
        echo json_encode(['success' => true, 'message' => $genericMessage]);
        return;
    }

    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

    $pdo->prepare("UPDATE customer_password_reset_tokens SET used = TRUE WHERE customer_id = ? AND used = FALSE")->execute([$customer['id']]);
    $pdo->prepare("INSERT INTO customer_password_reset_tokens (customer_id, token, expires_at) VALUES (?, ?, ?)")->execute([$customer['id'], $token, $expiresAt]);

    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $resetLink = $protocol . '://' . $host . dirname($_SERVER['PHP_SELF']) . '/reset_password.php?token=' . $token . '&restaurant_id=' . urlencode($restaurantId);

    if (file_exists(__DIR__ . '/../config/email_config.php')) {
        require_once __DIR__ . '/../config/email_config.php';
    }

    $subject = 'Reset your password';
    $message = "
    <html><body style='font-family:Arial,sans-serif;line-height:1.6;color:#333;'>
    <div style='max-width:600px;margin:0 auto;padding:20px;'>
        <div style='background:#d63031;color:#fff;padding:20px;text-align:center;'><h2>Password Reset Request</h2></div>
        <div style='padding:20px;background:#f9f9f9;'>
            <p>Hello " . htmlspecialchars($customer['customer_name']) . ",</p>
            <p>We received a request to reset your account password.</p>
            <p style='text-align:center;'><a href='" . $resetLink . "' style='display:inline-block;padding:12px 24px;background:#d63031;color:#fff;text-decoration:none;border-radius:5px;margin:20px 0;'>Reset Password</a></p>
            <p>Or copy and paste this link into your browser:</p>
            <p style='word-break:break-all;color:#666;'>" . $resetLink . "</p>
            <p><strong>This link will expire in 30 minutes.</strong></p>
            <p>If you did not request this, you can safely ignore this email.</p>
        </div>
    </div>
    </body></html>
    ";

    $mailSent = false;
    if (function_exists('sendEmail')) {
        $result = sendEmail($email, $subject, $message);
        $mailSent = is_array($result) ? ($result['success'] ?? false) : (bool)$result;
        if (!$mailSent) error_log('Customer password reset email failed for ' . $email);
    }

    echo json_encode(['success' => true, 'message' => $genericMessage, 'email_sent' => $mailSent]);
}

function handleCustomerResetPassword($pdo) {
    $token = isset($_POST['token']) ? trim($_POST['token']) : '';
    $newPassword = isset($_POST['newPassword']) ? $_POST['newPassword'] : '';
    $confirmPassword = isset($_POST['confirmPassword']) ? $_POST['confirmPassword'] : '';

    if (empty($token)) throw new Exception('Reset token is required');
    if (strlen($newPassword) < 6) throw new Exception('Password must be at least 6 characters long');
    if ($newPassword !== $confirmPassword) throw new Exception('Passwords do not match');

    $stmt = $pdo->prepare("
        SELECT prt.id, prt.customer_id, prt.expires_at, prt.used
        FROM customer_password_reset_tokens prt
        WHERE prt.token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tokenData) throw new Exception('Invalid reset link. Please request a new one.');
    if ($tokenData['used']) throw new Exception('This reset link has already been used. Please request a new one.');
    if (strtotime($tokenData['expires_at']) < time()) throw new Exception('This reset link has expired. Please request a new one.');

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE customers SET password_hash = ?, updated_at = NOW() WHERE id = ?")->execute([$hash, $tokenData['customer_id']]);
    $pdo->prepare("UPDATE customer_password_reset_tokens SET used = TRUE WHERE id = ?")->execute([$tokenData['id']]);

    // Reset any remember-me sessions so a stolen device is logged out on password change.
    $pdo->prepare("DELETE FROM customer_remember_tokens WHERE customer_id = ?")->execute([$tokenData['customer_id']]);

    echo json_encode(['success' => true, 'message' => 'Password reset successfully. You can now log in with your new password.']);
}
