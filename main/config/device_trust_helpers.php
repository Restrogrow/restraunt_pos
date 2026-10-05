<?php
/**
 * Per-device WhatsApp OTP gate for every login surface: the companion app
 * (app/), the restaurant-owner/staff web login (main/admin/login.php), and
 * the customer-facing website login (main/website/login.php).
 *
 * The first login from a given device_id (a random id the client generates
 * once and keeps — AsyncStorage in the app, localStorage on the web)
 * requires a WhatsApp OTP sent to the account's own phone number; once
 * verified, that device_id is trusted forever for that account, so the
 * client never asks again from the same install/browser. Reinstalling the
 * app, clearing site data, or logging in from a different device generates
 * a new device_id and starts the check over.
 *
 * A caller that never sends a device_id at all (an old cached page, a build
 * that predates this feature) simply isn't gated — fail-open, so a rollout
 * never locks anyone out. Accounts with no phone on file (e.g. branch
 * admins, or admins/customers that predate the phone requirement) are not
 * gated either, since there's nowhere to send the code.
 *
 * Reuses the whatsapp_otp_codes table (repeatgrow_whatsapp.php) under a
 * dedicated purpose ('device_login') so this doesn't need its own
 * send/verify plumbing against RepeatGrow.
 */

const DEVICE_OTP_PURPOSE = 'device_login';
const DEVICE_OTP_RESEND_COOLDOWN_SECONDS = 60;
const DEVICE_OTP_EXPIRY_SECONDS = 300;
const DEVICE_OTP_MAX_ATTEMPTS = 5;

function ensureTrustedDevicesSchema($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS trusted_devices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_type VARCHAR(20) NOT NULL,
                user_id INT NOT NULL,
                device_id VARCHAR(100) NOT NULL,
                trusted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_device (user_type, user_id, device_id)
            )
        ");
    } catch (PDOException $e) {
        error_log('ensureTrustedDevicesSchema: ' . $e->getMessage());
    }
}

function isDeviceTrusted($pdo, string $userType, int $userId, string $deviceId): bool {
    ensureTrustedDevicesSchema($pdo);
    $stmt = $pdo->prepare("SELECT id FROM trusted_devices WHERE user_type = ? AND user_id = ? AND device_id = ? LIMIT 1");
    $stmt->execute([$userType, $userId, $deviceId]);
    return (bool) $stmt->fetch();
}

function trustDevice($pdo, string $userType, int $userId, string $deviceId): void {
    ensureTrustedDevicesSchema($pdo);
    $pdo->prepare("
        INSERT INTO trusted_devices (user_type, user_id, device_id)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE trusted_at = trusted_at
    ")->execute([$userType, $userId, $deviceId]);
}

/** "9876543210" -> "••••••3210" — enough for the user to recognize the number, not enough to leak it. */
function maskPhoneForDisplay(string $phone): string {
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) <= 4) return $digits;
    return str_repeat('•', strlen($digits) - 4) . substr($digits, -4);
}

/**
 * Sends a fresh device-login OTP to $phone, respecting the same 60s resend
 * cooldown as otp.php. Returns ['sent' => bool, 'wait' => int|null].
 */
function issueDeviceOtp($pdo, string $restaurantId, string $phone): array {
    ensureWhatsappOtpSchema($pdo);

    $stmt = $pdo->prepare("
        SELECT created_at FROM whatsapp_otp_codes
        WHERE restaurant_id = ? AND phone = ? AND purpose = ?
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$restaurantId, $phone, DEVICE_OTP_PURPOSE]);
    $last = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($last) {
        $secondsSince = time() - strtotime($last['created_at']);
        if ($secondsSince < DEVICE_OTP_RESEND_COOLDOWN_SECONDS) {
            return ['sent' => false, 'wait' => DEVICE_OTP_RESEND_COOLDOWN_SECONDS - $secondsSince];
        }
    }

    $code = (string) random_int(100000, 999999);
    $codeHash = hash('sha256', $code);
    $expiresAt = date('Y-m-d H:i:s', time() + DEVICE_OTP_EXPIRY_SECONDS);

    $pdo->prepare("
        INSERT INTO whatsapp_otp_codes (restaurant_id, phone, purpose, code_hash, expires_at)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$restaurantId, $phone, DEVICE_OTP_PURPOSE, $codeHash, $expiresAt]);

    sendWhatsappOtpViaRepeatGrow($phone, $code);

    return ['sent' => true, 'wait' => null];
}

/**
 * Verifies $code for $phone under the device_login purpose. Returns
 * ['success' => bool, 'message' => string].
 */
function verifyDeviceOtp($pdo, string $restaurantId, string $phone, string $code): array {
    ensureWhatsappOtpSchema($pdo);

    $stmt = $pdo->prepare("
        SELECT id, code_hash, expires_at, attempts
        FROM whatsapp_otp_codes
        WHERE restaurant_id = ? AND phone = ? AND purpose = ? AND consumed_at IS NULL
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$restaurantId, $phone, DEVICE_OTP_PURPOSE]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['success' => false, 'message' => 'No active code found. Please request a new one.'];
    }
    if (strtotime($row['expires_at']) < time()) {
        return ['success' => false, 'message' => 'This code has expired. Please request a new one.'];
    }
    if ((int)$row['attempts'] >= DEVICE_OTP_MAX_ATTEMPTS) {
        return ['success' => false, 'message' => 'Too many incorrect attempts. Please request a new code.'];
    }
    if (!hash_equals($row['code_hash'], hash('sha256', $code))) {
        $pdo->prepare("UPDATE whatsapp_otp_codes SET attempts = attempts + 1 WHERE id = ?")->execute([$row['id']]);
        $remaining = DEVICE_OTP_MAX_ATTEMPTS - ((int)$row['attempts'] + 1);
        return [
            'success' => false,
            'message' => $remaining > 0 ? "Invalid code. {$remaining} attempt(s) remaining." : 'Invalid code. Please request a new one.',
        ];
    }

    $pdo->prepare("UPDATE whatsapp_otp_codes SET consumed_at = NOW() WHERE id = ?")->execute([$row['id']]);
    return ['success' => true, 'message' => 'Device verified.'];
}

/**
 * Called right after password verification, before a session is granted.
 * Returns null when the caller should proceed with normal login (no
 * device_id sent, no phone on file, or device already trusted/now
 * verified). Returns a response array (to be echoed as JSON, then the
 * caller should return/exit) when the client needs to collect/retry an OTP
 * before the login can complete.
 */
function enforceDeviceTrust($pdo, string $userType, int $userId, ?string $phone, string $restaurantId): ?array {
    $phoneDigits = preg_replace('/\D/', '', (string)$phone);
    if ($phoneDigits === '') {
        // Nowhere to send a code — fail open rather than lock the account out.
        return null;
    }

    $deviceId = isset($_POST['device_id']) ? trim($_POST['device_id']) : '';
    if ($deviceId === '') {
        // Caller doesn't send a device id yet (old cached page/app build) —
        // fail open rather than break login during rollout.
        return null;
    }

    if (isDeviceTrusted($pdo, $userType, $userId, $deviceId)) {
        return null;
    }

    $otpCode = isset($_POST['otp_code']) ? trim($_POST['otp_code']) : '';
    $maskedPhone = maskPhoneForDisplay($phoneDigits);

    if ($otpCode === '') {
        $result = issueDeviceOtp($pdo, $restaurantId, $phoneDigits);
        if (!$result['sent']) {
            return [
                'success' => false,
                'requires_otp' => true,
                'message' => "A code was already sent. Please wait {$result['wait']}s before requesting another.",
                'masked_phone' => $maskedPhone,
            ];
        }
        return [
            'success' => false,
            'requires_otp' => true,
            'message' => "New device detected. We sent a WhatsApp verification code to {$maskedPhone}.",
            'masked_phone' => $maskedPhone,
        ];
    }

    $verify = verifyDeviceOtp($pdo, $restaurantId, $phoneDigits, $otpCode);
    if (!$verify['success']) {
        return [
            'success' => false,
            'requires_otp' => true,
            'message' => $verify['message'],
            'masked_phone' => $maskedPhone,
        ];
    }

    trustDevice($pdo, $userType, $userId, $deviceId);
    return null;
}
