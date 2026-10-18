<?php
// WhatsApp OTP verification — generic phone-verification endpoint usable
// from signup, checkout, or anywhere else a phone number needs proving.
// Delivery goes through RepeatGrow's public API (main/config/repeatgrow_whatsapp.php),
// which owns the actual WhatsApp/Meta connection. This endpoint only
// generates, stores (hashed), and checks the 6-digit code.
//
// Stateless by design — no session required, so it works the same from the
// customer website and the Expo app.
//
// POST action=send
//   restaurant_id, phone, purpose (optional, default "verify"),
//   country (owner signup only: the country name picked on the form, for the dial code)
//   -> { success, message, expires_in }
//
// POST action=verify
//   restaurant_id, phone, code, purpose (optional, default "verify")
//   -> { success, message }

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (ob_get_level()) {
    ob_clean();
}

require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin(true); // public, unauthenticated endpoint — wildcard is fine, no cookies involved

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/../config/repeatgrow_whatsapp.php';

const OTP_CODE_LENGTH = 6;
const OTP_EXPIRY_SECONDS = 300;   // 5 minutes
const OTP_MAX_ATTEMPTS = 5;
const OTP_RESEND_COOLDOWN_SECONDS = 60;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed');
    }

    if (file_exists(__DIR__ . '/../config/rate_limit.php')) {
        require_once __DIR__ . '/../config/rate_limit.php';
    }

    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    if (empty($action)) {
        throw new Exception('Action is required');
    }

    $pdo = getConnection();
    ensureWhatsappOtpSchema($pdo);

    switch ($action) {
        case 'send':
            handleSendOtp($pdo);
            break;
        case 'verify':
            handleVerifyOtp($pdo);
            break;
        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function readRequestBasics() {
    $restaurantId = isset($_POST['restaurant_id']) ? trim($_POST['restaurant_id']) : '';
    $phone = isset($_POST['phone']) ? preg_replace('/\D/', '', $_POST['phone']) : '';
    $purpose = isset($_POST['purpose']) ? trim($_POST['purpose']) : 'verify';
    $purpose = preg_replace('/[^a-zA-Z0-9_]/', '', $purpose) ?: 'verify';

    if (empty($restaurantId)) {
        throw new Exception('Restaurant context is missing. Please reload the page and try again.');
    }
    if (strlen($phone) < 6 || strlen($phone) > 15) {
        throw new Exception('Please enter a valid phone number');
    }

    return [$restaurantId, $phone, $purpose];
}

function handleSendOtp($pdo) {
    [$restaurantId, $phone, $purpose] = readRequestBasics();

    // Only a real restaurant (or owner sign-up): a made-up restaurant_id used to get a fresh throttle
    // every time, so anyone could flood a phone with WhatsApp codes (and run up the WhatsApp bill)
    if ($restaurantId !== 'SIGNUP') {
        $real = $pdo->prepare('SELECT 1 FROM users WHERE restaurant_id = ? LIMIT 1');
        $real->execute([$restaurantId]);
        if (!$real->fetchColumn()) throw new Exception('Restaurant not found. Please reload the page and try again.');
    }

    // Throttles, on top of the 60s cooldown checked against the database below:
    //  - 3 per 5 minutes for this phone at this restaurant
    //  - 5 per hour for this phone across all restaurants
    //  - 10 per hour from one internet address (owner sign-up: 5)
    if (function_exists('checkRateLimit')) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        foreach ([['otp_send_' . $restaurantId . '_' . $phone, 3, 300], ['otp_phone_' . $phone, 5, 3600],
                  ['otp_ip_' . ($restaurantId === 'SIGNUP' ? 'signup_' : '') . $ip, $restaurantId === 'SIGNUP' ? 5 : 10, 3600]] as [$rateId, $max, $win]) {
            $rate = checkRateLimit($rateId, $max, $win);
            if (!$rate['allowed']) {
                http_response_code(429);
                echo json_encode(['success' => false, 'message' => 'Too many codes requested. Please try again a little later.']);
                return;
            }
        }
    }

    $stmt = $pdo->prepare("
        SELECT created_at FROM whatsapp_otp_codes
        WHERE restaurant_id = ? AND phone = ? AND purpose = ?
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$restaurantId, $phone, $purpose]);
    $last = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($last) {
        $secondsSince = time() - strtotime($last['created_at']);
        if ($secondsSince < OTP_RESEND_COOLDOWN_SECONDS) {
            $wait = OTP_RESEND_COOLDOWN_SECONDS - $secondsSince;
            echo json_encode(['success' => false, 'message' => "Please wait {$wait}s before requesting another code."]);
            return;
        }
    }

    $code = (string) random_int(10 ** (OTP_CODE_LENGTH - 1), (10 ** OTP_CODE_LENGTH) - 1);
    $codeHash = hash('sha256', $code);
    $expiresAt = date('Y-m-d H:i:s', time() + OTP_EXPIRY_SECONDS);

    $pdo->prepare("
        INSERT INTO whatsapp_otp_codes (restaurant_id, phone, purpose, code_hash, expires_at)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$restaurantId, $phone, $purpose, $codeHash, $expiresAt]);

    // Send AFTER the row is committed — if RepeatGrow fails, the row still
    // exists but is unreachable (expires naturally), rather than the user
    // having a "valid" code that was never actually delivered.
    // the number gets the restaurant's country code (+977 for a Nepal restaurant); owner signup has no
    // restaurant yet, so it sends the country picked on the signup form
    require_once __DIR__ . '/../config/countries.php';
    $country = $restaurantId === 'SIGNUP'
        ? getCountryByName(trim((string)($_POST['country'] ?? '')))
        : restaurantCountryInfo($pdo, $restaurantId);
    sendWhatsappOtpViaRepeatGrow($phone, $code, $country);

    echo json_encode(array_merge([
        'success' => true,
        'message' => 'A verification code has been sent via WhatsApp.',
        'expires_in' => OTP_EXPIRY_SECONDS,
    ], localOtpFields()));
}

function handleVerifyOtp($pdo) {
    [$restaurantId, $phone, $purpose] = readRequestBasics();
    $code = isset($_POST['code']) ? trim($_POST['code']) : '';

    if (empty($code)) {
        throw new Exception('Please enter the verification code');
    }
    if (function_exists('checkRateLimit')) {
        $rate = checkRateLimit('otp_verify_ip_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 30, 600);
        if (!$rate['allowed']) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Too many tries. Please wait a few minutes.']);
            return;
        }
    }

    $stmt = $pdo->prepare("
        SELECT id, code_hash, expires_at, attempts
        FROM whatsapp_otp_codes
        WHERE restaurant_id = ? AND phone = ? AND purpose = ? AND consumed_at IS NULL
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$restaurantId, $phone, $purpose]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'No active code found. Please request a new one.']);
        return;
    }

    if (strtotime($row['expires_at']) < time()) {
        echo json_encode(['success' => false, 'message' => 'This code has expired. Please request a new one.']);
        return;
    }

    // Count this try first, in one step: many guesses sent at the same moment used to all pass the
    // "fewer than 5 tries" check before any of them was counted
    $claim = $pdo->prepare("UPDATE whatsapp_otp_codes SET attempts = attempts + 1 WHERE id = ? AND attempts < ? AND consumed_at IS NULL");
    $claim->execute([$row['id'], OTP_MAX_ATTEMPTS]);
    if ($claim->rowCount() !== 1) {
        echo json_encode(['success' => false, 'message' => 'Too many incorrect attempts. Please request a new code.']);
        return;
    }

    if (!hash_equals($row['code_hash'], hash('sha256', $code))) {
        $remaining = OTP_MAX_ATTEMPTS - ((int)$row['attempts'] + 1);
        echo json_encode([
            'success' => false,
            'message' => $remaining > 0 ? "Invalid code. {$remaining} attempt(s) remaining." : 'Invalid code. Please request a new one.',
        ]);
        return;
    }

    // a code works once: two requests with the right code at the same moment can't both succeed
    $use = $pdo->prepare("UPDATE whatsapp_otp_codes SET consumed_at = NOW() WHERE id = ? AND consumed_at IS NULL");
    $use->execute([$row['id']]);
    if ($use->rowCount() !== 1) {
        echo json_encode(['success' => false, 'message' => 'This code was already used. Please request a new one.']);
        return;
    }

    echo json_encode(['success' => true, 'message' => 'Phone number verified.']);
}
