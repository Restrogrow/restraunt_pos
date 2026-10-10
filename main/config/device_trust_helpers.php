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
 * dedicated purpose ('device_login'), plus that file's generic account-
 * lockout helpers: 5 wrong codes within 15 minutes locks the account out
 * of this device-verification step for 30 minutes (separate from, and in
 * addition to, the per-code 5-attempt cap that just invalidates one code).
 */

const DEVICE_OTP_PURPOSE = 'device_login';
const DEVICE_OTP_RESEND_COOLDOWN_SECONDS = 60;
const DEVICE_OTP_EXPIRY_SECONDS = 300;
const DEVICE_OTP_MAX_ATTEMPTS_PER_CODE = 5;
const DEVICE_OTP_LOCKOUT_MAX_ATTEMPTS = 5;
const DEVICE_OTP_LOCKOUT_WINDOW_SECONDS = 900;   // 15 minutes
const DEVICE_OTP_LOCKOUT_SECONDS = 1800;         // 30 minutes

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
 * Called right after password verification, before a session is granted.
 * Returns null when the caller should proceed with normal login (no
 * device_id sent, no phone on file, or device already trusted/now
 * verified). Returns a response array (to be echoed as JSON, then the
 * caller should return/exit) when the client needs to collect/retry an OTP
 * — or wait out a lockout — before the login can complete.
 */
function enforceDeviceTrust($pdo, string $userType, int $userId, ?string $phone, string $restaurantId): ?array {
    // Login-time WhatsApp OTP disabled — OTP is only required at account
    // creation (signup). Remove this line to re-enable the new-device gate.
    return null;

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

    $maskedPhone = maskPhoneForDisplay($phoneDigits);
    $lockoutScope = "device_otp_{$userType}_{$userId}";

    $lockedFor = otpAccountLockoutRemaining($lockoutScope);
    if ($lockedFor !== null) {
        return [
            'success' => false,
            'requires_otp' => true,
            'locked' => true,
            'message' => 'Too many incorrect codes. Please try again in ' . ceil($lockedFor / 60) . ' minute(s).',
            'masked_phone' => $maskedPhone,
        ];
    }

    $otpCode = isset($_POST['otp_code']) ? trim($_POST['otp_code']) : '';

    if ($otpCode === '') {
        $result = issuePurposeOtp($pdo, $restaurantId, $phoneDigits, DEVICE_OTP_PURPOSE, DEVICE_OTP_RESEND_COOLDOWN_SECONDS, DEVICE_OTP_EXPIRY_SECONDS);
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

    $verify = verifyPurposeOtpCode($pdo, $restaurantId, $phoneDigits, DEVICE_OTP_PURPOSE, $otpCode, DEVICE_OTP_MAX_ATTEMPTS_PER_CODE);
    if (!$verify['success']) {
        trackOtpAccountFailure($lockoutScope, DEVICE_OTP_LOCKOUT_MAX_ATTEMPTS, DEVICE_OTP_LOCKOUT_WINDOW_SECONDS, DEVICE_OTP_LOCKOUT_SECONDS);
        $justLocked = otpAccountLockoutRemaining($lockoutScope);
        if ($justLocked !== null) {
            return [
                'success' => false,
                'requires_otp' => true,
                'locked' => true,
                'message' => 'Too many incorrect codes. Please try again in ' . ceil($justLocked / 60) . ' minute(s).',
                'masked_phone' => $maskedPhone,
            ];
        }
        return [
            'success' => false,
            'requires_otp' => true,
            'message' => $verify['message'],
            'masked_phone' => $maskedPhone,
        ];
    }

    clearOtpAccountFailures($lockoutScope);
    trustDevice($pdo, $userType, $userId, $deviceId);
    return null;
}
