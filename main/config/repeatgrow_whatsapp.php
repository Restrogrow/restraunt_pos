<?php
/**
 * RepeatGrow WhatsApp client.
 *
 * Thin wrapper around RepeatGrow's public API (POST /api/v1/messages) used
 * to deliver WhatsApp OTP codes. RepeatGrow owns the actual Meta Cloud API
 * connection (phone number, access token, template approval) — this app
 * only needs an API key with the `messages:send` scope, created in
 * RepeatGrow under Settings -> API Keys.
 *
 * Required .env vars (see .env.example):
 *   REPEATGROW_API_URL              e.g. https://app.repeatgrow.com/api/v1/messages
 *   REPEATGROW_API_KEY              wacrm_live_...
 *   REPEATGROW_OTP_TEMPLATE_NAME    name of the APPROVED Authentication-category
 *                                    template in RepeatGrow (e.g. "otp_verify")
 *   REPEATGROW_OTP_TEMPLATE_LANG    defaults to en_US
 *   REPEATGROW_DEFAULT_COUNTRY_CODE defaults to 91 (India) — prepended to
 *                                    phone numbers that look like local
 *                                    10-digit numbers with no country code
 *   REPEATGROW_OTP_SEND_MODE        "template" (default, production) or "text"
 *                                    (TEMPORARY test mode — sends a plain-text
 *                                    message instead of a template, so it works
 *                                    with zero template approval. Only works
 *                                    inside WhatsApp's 24h window, i.e. the
 *                                    recipient must have messaged the business
 *                                    number first. Switch back to "template"
 *                                    once a real Authentication template is
 *                                    approved — Meta will reject unsolicited
 *                                    free-text sends outside that window.)
 */

require_once __DIR__ . '/env_loader.php';

/**
 * Shared by otp.php (send/verify) and any other flow that needs to gate on
 * a verified phone (e.g. restaurant-owner signup in admin/auth.php) —
 * defined here rather than in otp.php so it can be required without
 * pulling in otp.php's top-level request-handling code.
 */
function ensureWhatsappOtpSchema($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS whatsapp_otp_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id VARCHAR(10) NOT NULL,
                phone VARCHAR(20) NOT NULL,
                purpose VARCHAR(32) NOT NULL DEFAULT 'verify',
                code_hash VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                attempts INT NOT NULL DEFAULT 0,
                consumed_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_lookup (restaurant_id, phone, purpose, created_at),
                INDEX idx_expires_at (expires_at)
            )
        ");
    } catch (PDOException $e) {
        error_log('ensureWhatsappOtpSchema: ' . $e->getMessage());
    }
}

/**
 * True if $phone was WhatsApp-verified (via otp.php) under the given
 * restaurant_id/purpose scope within the last $withinMinutes. Used to gate
 * a follow-up action (e.g. account creation) on a prior successful
 * action=verify call, without otp.php and the caller needing to share any
 * state beyond this table.
 */
function isPhoneVerifiedRecently($pdo, string $restaurantId, string $phone, string $purpose, int $withinMinutes = 15): bool {
    ensureWhatsappOtpSchema($pdo);
    $stmt = $pdo->prepare("
        SELECT id FROM whatsapp_otp_codes
        WHERE restaurant_id = ? AND phone = ? AND purpose = ?
          AND consumed_at IS NOT NULL AND consumed_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ORDER BY consumed_at DESC LIMIT 1
    ");
    $stmt->execute([$restaurantId, $phone, $purpose, $withinMinutes]);
    return (bool) $stmt->fetch();
}

/**
 * Send a WhatsApp OTP code to $phone via RepeatGrow's send API.
 * Throws on any failure (missing config, network error, Meta rejection) —
 * callers decide how to surface that to the end user.
 *
 * @return array{message_id:?string}
 */
function sendWhatsappOtpViaRepeatGrow(string $phone, string $code): array {
    $apiUrl = env('REPEATGROW_API_URL');
    $apiKey = env('REPEATGROW_API_KEY');
    $sendMode = env('REPEATGROW_OTP_SEND_MODE', 'template');

    if (!$apiUrl || !$apiKey) {
        throw new Exception('WhatsApp OTP is not configured. Set REPEATGROW_API_URL and REPEATGROW_API_KEY in .env.');
    }

    if ($sendMode === 'text') {
        // TEST MODE ONLY — see REPEATGROW_OTP_SEND_MODE doc above.
        $payload = [
            'to' => normalizePhoneForWhatsapp($phone),
            'type' => 'text',
            'text' => "Your verification code is: {$code}",
        ];
    } else {
        $templateName = env('REPEATGROW_OTP_TEMPLATE_NAME', 'otp_verify');
        $templateLang = env('REPEATGROW_OTP_TEMPLATE_LANG', 'en_US');
        $payload = [
            'to' => normalizePhoneForWhatsapp($phone),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => $templateLang,
                // Structured params (object, not array) — an Authentication
                // template's "Copy code" button is stored by Meta as a URL
                // button whose url contains {{1}}, so it needs its own
                // button param at send time, same value as the body code.
                // An array here would only fill the body and the send
                // fails with "URL button #1 ... requires a buttonParams[0] value".
                'params' => [
                    'body' => [$code],
                    // Cast to object: a PHP array with key "0" re-indexes to
                    // a plain sequential array, which json_encode then
                    // serializes as a JSON ARRAY ([code]) instead of the
                    // required object ({"0": code}) — the API route checks
                    // `!Array.isArray(...)` to decide it's structured params.
                    'buttonParams' => (object) ['0' => $code],
                ],
            ],
        ];
    }

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlErrno !== 0 || $responseBody === false) {
        error_log('sendWhatsappOtpViaRepeatGrow: cURL error — ' . $curlError);
        throw new Exception('Could not reach the WhatsApp service. Please try again.');
    }

    $data = json_decode($responseBody, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['message_id' => $data['data']['whatsapp_message_id'] ?? $data['data']['message_id'] ?? null];
    }

    $errMsg = $data['error']['message'] ?? ('RepeatGrow API error (HTTP ' . $httpCode . ')');
    error_log('sendWhatsappOtpViaRepeatGrow: ' . $errMsg . ' | payload=' . json_encode($payload));
    throw new Exception('Could not send the WhatsApp message: ' . $errMsg);
}

/**
 * RepeatGrow's API requires E.164 (+<countrycode><number>). The rest of
 * this app stores phone numbers as bare digits (see
 * preg_replace('/\D/', '', ...) in customer_auth.php), so prepend a
 * default country code when the number looks like a local 10-digit one.
 */
function normalizePhoneForWhatsapp(string $phone): string {
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) > 10) {
        return '+' . $digits;
    }
    $defaultCc = env('REPEATGROW_DEFAULT_COUNTRY_CODE', '91');
    return '+' . $defaultCc . $digits;
}
