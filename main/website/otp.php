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
//   restaurant_id, phone, purpose (optional, default "verify")
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

    // Per phone+restaurant throttle: max 3 sends per 5 minutes, on top of a
    // flat 60s cooldown between consecutive sends (checked against the DB
    // below) so a user can't bulk-trigger WhatsApp sends.
    if (function_exists('checkRateLimit')) {
        $rateId = 'otp_send_' . $restaurantId . '_' . $phone;
        $rate = checkRateLimit($rateId, 3, 300);
        if (!$rate['allowed']) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => $rate['message']]);
            return;
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
    sendWhatsappOtpViaRepeatGrow($phone, $code);

    echo json_encode([
        'success' => true,
        'message' => 'A verification code has been sent via WhatsApp.',
        'expires_in' => OTP_EXPIRY_SECONDS,
    ]);
}

function handleVerifyOtp($pdo) {
    [$restaurantId, $phone, $purpose] = readRequestBasics();
    $code = isset($_POST['code']) ? trim($_POST['code']) : '';

    if (empty($code)) {
        throw new Exception('Please enter the verification code');
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

    if ((int)$row['attempts'] >= OTP_MAX_ATTEMPTS) {
        echo json_encode(['success' => false, 'message' => 'Too many incorrect attempts. Please request a new code.']);
        return;
    }

    if (!hash_equals($row['code_hash'], hash('sha256', $code))) {
        $pdo->prepare("UPDATE whatsapp_otp_codes SET attempts = attempts + 1 WHERE id = ?")->execute([$row['id']]);
        $remaining = OTP_MAX_ATTEMPTS - ((int)$row['attempts'] + 1);
        echo json_encode([
            'success' => false,
            'message' => $remaining > 0 ? "Invalid code. {$remaining} attempt(s) remaining." : 'Invalid code. Please request a new one.',
        ]);
        return;
    }

    $pdo->prepare("UPDATE whatsapp_otp_codes SET consumed_at = NOW() WHERE id = ?")->execute([$row['id']]);

    echo json_encode(['success' => true, 'message' => 'Phone number verified.']);
}
