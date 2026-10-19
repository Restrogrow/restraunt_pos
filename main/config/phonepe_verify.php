<?php
/**
 * PhonePe engine (Standard Checkout v2): keys, tokens, create payment, order status.
 *
 * Rules every payment flow follows:
 *  - Never trust a webhook body or a browser redirect: only PhonePe's order-status API, called from
 *    the server with our own OAuth keys, decides paid / failed. The amount PhonePe reports must match
 *    the amount we asked for (phonepeVerifyOrderState with $expectedPaise).
 *  - Sandbox keys are used only on a real local computer (loopback IP + localhost host, or CLI with
 *    APP_ENV=local). Never because of a Host header: a production server can't be talked into sandbox.
 *  - A restaurant's own keys (Payments › Payment Gateway, mode "own") are stored encrypted
 *    (PAYMENT_KEY_SECRET, else WA_KEY_SECRET); RestroGrow's own keys come from .env (PHONEPE_CLIENT_*).
 *    RestroGrow's own money (plan renewals, payment links) always uses RestroGrow's keys ($platformOnly).
 *
 * Settling a payment (orders, plans, wallet, VIP, meal plans, payment links) is config/payment_settle.php;
 * the single webhook is api/phonepe_webhook.php; stuck payments are rechecked by config/payment_reconcile.php.
 */

require_once __DIR__ . '/env_loader.php';

if (!function_exists('ppIsLocal')) {
    /** A developer's own computer: sandbox keys / demo payments are allowed only here. */
    function ppIsLocal(): bool {
        if (PHP_SAPI === 'cli') return strtolower((string)env('APP_ENV', '')) === 'local';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
        $localHost = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || substr($host, -10) === '.localhost';
        $proxied = !empty($_SERVER['HTTP_X_FORWARDED_FOR']) || !empty($_SERVER['HTTP_CF_CONNECTING_IP']);
        return in_array($ip, ['127.0.0.1', '::1'], true) && $localHost && !$proxied;
    }

    /* ── restaurant PhonePe secrets at rest: AES-256-GCM ── */
    function ppSecretKey(): ?string {
        $k = trim((string)env('PAYMENT_KEY_SECRET', '')) ?: trim((string)env('WA_KEY_SECRET', ''));
        if ($k === '') return null;
        $raw = base64_decode($k, true);
        return hash('sha256', 'phonepe|' . (($raw !== false && strlen($raw) >= 32) ? $raw : $k), true);
    }
    function ppSecretSeal(string $plain): string {
        if ($plain === '' || strpos($plain, 'enc:p1:') === 0) return $plain;
        $k = ppSecretKey();
        if ($k === null) return $plain;
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        return $ct === false ? $plain : 'enc:p1:' . base64_encode($iv . $tag . $ct);
    }
    function ppSecretOpen(?string $stored): string {
        $stored = (string)$stored;
        if (strpos($stored, 'enc:p1:') !== 0) return $stored;   // saved before encryption
        $k = ppSecretKey();
        $raw = base64_decode(substr($stored, 7), true);
        $plain = ($k !== null && $raw !== false && strlen($raw) > 28) ? openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)) : false;
        if ($plain === false) { error_log('PhonePe: a restaurant secret could not be decrypted (PAYMENT_KEY_SECRET / WA_KEY_SECRET changed?)'); return ''; }
        return $plain;
    }
    /** Last 4 characters, for showing a saved secret without revealing it. */
    function ppMask(?string $plain): string { $p = (string)$plain; return $p === '' ? '' : '••••' . substr($p, -4); }
}

if (!function_exists('phonepeCallbackConfig')) {
    /**
     * Keys and URLs for one payment.
     * $restaurantId: whose customer pays (null = RestroGrow's own account).
     * $platformOnly: RestroGrow's own money (plans, payment links): never the restaurant's keys.
     */
    function phonepeCallbackConfig($restaurantId, bool $platformOnly = false) {
        $source = 'platform';
        if (ppIsLocal()) {
            $clientId = env('PHONEPE_SANDBOX_CLIENT_ID', env('PHONEPE_SANDBOX_MERCHANT_ID', ''));
            $clientSecret = env('PHONEPE_SANDBOX_CLIENT_SECRET', env('PHONEPE_SANDBOX_SALT_KEY', ''));
            $clientVersion = env('PHONEPE_SANDBOX_CLIENT_VERSION', env('PHONEPE_SANDBOX_SALT_INDEX', '1'));
            $isSandbox = true;
        } else {
            $own = null;
            if ($restaurantId && !$platformOnly) {
                try {
                    $stmt = getConnection()->prepare("SELECT phonepe_merchant_id, phonepe_salt_key, phonepe_environment, payment_gateway_mode FROM users WHERE restaurant_id = ? ORDER BY id LIMIT 1");
                    $stmt->execute([$restaurantId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row && ($row['payment_gateway_mode'] ?? '') === 'own' && !empty($row['phonepe_merchant_id']) && !empty($row['phonepe_salt_key'])) {
                        $secret = ppSecretOpen($row['phonepe_salt_key']);
                        if ($secret !== '') $own = ['id' => $row['phonepe_merchant_id'], 'secret' => $secret, 'env' => strtoupper((string)($row['phonepe_environment'] ?? '')) === 'SANDBOX' ? 'SANDBOX' : 'PRODUCTION'];
                    }
                } catch (Throwable $e) { /* no columns yet: platform keys */ }
            }
            if ($own) {
                $clientId = $own['id']; $clientSecret = $own['secret']; $isSandbox = $own['env'] === 'SANDBOX'; $source = 'own';
            } else {
                $clientId = env('PHONEPE_CLIENT_ID', ''); $clientSecret = env('PHONEPE_CLIENT_SECRET', '');
                // RestroGrow's own money is never taken in sandbox on the live site (a test "payment" would buy a real plan)
                $isSandbox = !$platformOnly && strtoupper((string)env('PHONEPE_ENVIRONMENT', 'PRODUCTION')) === 'SANDBOX';
            }
            $clientVersion = env('PHONEPE_CLIENT_VERSION', '1');
        }

        // PhonePe charges Indian rupees: a restaurant outside India gets no keys, so every customer
        // payment through it stays off (they show "not available")
        if ($restaurantId && !$platformOnly) {
            try {
                require_once __DIR__ . '/countries.php';
                $cst = getConnection()->prepare('SELECT country FROM users WHERE restaurant_id = ? ORDER BY id LIMIT 1');
                $cst->execute([$restaurantId]);
                if (!restaurantIsIndia((string)$cst->fetchColumn())) { $clientId = ''; $clientSecret = ''; }
            } catch (Throwable $e) { /* no country column: India */ }
        }

        if ($isSandbox) {
            $apiBase = rtrim((string)env('PHONEPE_BASE_URL_TEST', 'https://api-preprod.phonepe.com/apis/pg-sandbox'), '/');
            if (!preg_match('#^https://api-preprod\.phonepe\.com/#', $apiBase)) $apiBase = 'https://api-preprod.phonepe.com/apis/pg-sandbox';
            $authUrl = $apiBase . '/v1/oauth/token';
        } else {
            $apiBase = 'https://api.phonepe.com/apis/pg';
            $authUrl = 'https://api.phonepe.com/apis/identity-manager/v1/oauth/token';
        }
        $valid = $clientId !== '' && $clientSecret !== '' && $clientId !== 'YOUR_MERCHANT_ID' && strpos((string)$clientSecret, 'your_salt_key') === false;

        return [
            'client_id' => (string)$clientId,
            'client_secret' => (string)$clientSecret,
            'client_version' => (string)$clientVersion,
            'api_url' => $apiBase,
            'auth_url' => $authUrl,
            'pay_url' => $apiBase . '/checkout/v2/pay',
            'sandbox' => $isSandbox,
            'source' => $source,
            // demo (simulated) payments only ever on a local computer without sandbox keys
            'demo' => !$valid && ppIsLocal(),
            'ready' => $valid,
        ];
    }
}

if (!function_exists('phonepeCallbackAccessToken')) {
    /** OAuth token, kept until a minute before it expires (shared by every request and the cron). */
    function phonepeCallbackAccessToken($config) {
        if (empty($config['client_id']) || empty($config['client_secret'])) return null;
        $key = substr(hash('sha256', $config['auth_url'] . '|' . $config['client_id'] . '|' . $config['client_secret']), 0, 40);
        $conn = null;
        try {
            $conn = getConnection();
            $conn->exec("CREATE TABLE IF NOT EXISTS phonepe_token_cache (k CHAR(40) PRIMARY KEY, token TEXT NOT NULL, expires_at INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $st = $conn->prepare('SELECT token, expires_at FROM phonepe_token_cache WHERE k = ?');
            $st->execute([$key]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && (int)$row['expires_at'] > time() + 60) return $row['token'];
        } catch (Throwable $e) { $conn = null; }

        $ch = curl_init($config['auth_url']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query(['client_id' => $config['client_id'], 'client_version' => $config['client_version'],
                'client_secret' => $config['client_secret'], 'grant_type' => 'client_credentials'])]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($curlError) { error_log('PhonePe: auth connection error - ' . $curlError); return null; }
        $data = json_decode((string)$response, true);
        if ($httpCode !== 200 || empty($data['access_token'])) {
            error_log('PhonePe: auth refused (http ' . $httpCode . ', code ' . ($data['code'] ?? '-') . ')');
            return null;
        }
        $exp = (int)($data['expires_at'] ?? 0) ?: time() + 3000;
        if ($conn) {
            try { $conn->prepare('REPLACE INTO phonepe_token_cache (k, token, expires_at) VALUES (?, ?, ?)')->execute([$key, $data['access_token'], $exp]); } catch (Throwable $e) {}
        }
        return $data['access_token'];
    }
}

if (!function_exists('phonepeOrderStatus')) {
    /**
     * PhonePe's own answer for one payment: ['state' => COMPLETED|FAILED|PENDING|…, 'amount' => paise|null,
     * 'live_attempt' => true while a payment is being made or went through] or null. The last answer per order
     * is also kept in $GLOBALS['PP_LAST_STATUS'] so a caller can tell "never tried to pay" without asking again.
     */
    function phonepeOrderStatus(array $config, string $merchantOrderId): ?array {
        if (empty($config['client_id']) || empty($config['client_secret'])) return null;
        $token = phonepeCallbackAccessToken($config);
        if (!$token) return null;
        $ch = curl_init($config['api_url'] . '/checkout/v2/order/' . rawurlencode($merchantOrderId) . '/status');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: O-Bearer ' . $token]]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        $data = json_decode((string)$response, true);
        // an order PhonePe has never seen (the customer never reached the payment page) is as good as failed
        if ($httpCode === 404 || ($httpCode === 400 && in_array(($data['code'] ?? ''), ['ORDER_NOT_FOUND', 'INVALID_TRANSACTION_ID'], true))) {
            return $GLOBALS['PP_LAST_STATUS'][$merchantOrderId] = ['state' => 'NOT_FOUND', 'amount' => null, 'live_attempt' => false];
        }
        if ($curlError || $httpCode !== 200 || !is_array($data)) {
            error_log('PhonePe: status check failed for ' . $merchantOrderId . ' (http ' . $httpCode . ($curlError ? ', ' . $curlError : '') . ')');
            return null;
        }
        $state = strtoupper((string)($data['state'] ?? $data['orderStatus'] ?? ''));
        if ($state === '') return null;
        // paymentDetails lists each try (UPI, card…): none, or only failed ones, means nobody is paying right now
        $live = false;
        foreach ((array)($data['paymentDetails'] ?? []) as $try) {
            if (is_array($try) && in_array(strtoupper((string)($try['state'] ?? '')), ['PENDING', 'COMPLETED'], true)) $live = true;
        }
        return $GLOBALS['PP_LAST_STATUS'][$merchantOrderId] = ['state' => $state, 'amount' => isset($data['amount']) ? (int)$data['amount'] : null, 'live_attempt' => $live];
    }
}

if (!function_exists('phonepeVerifyOrderState')) {
    /**
     * The verified state ('COMPLETED', 'FAILED', 'PENDING', 'EXPIRED', 'NOT_FOUND'…) or null when PhonePe
     * couldn't be asked right now. With $expectedPaise, a COMPLETED payment for a different amount comes
     * back as 'AMOUNT_MISMATCH' (never treated as paid).
     */
    function phonepeVerifyOrderState($conn, $restaurantId, $merchantTransactionId, ?int $expectedPaise = null, bool $platformOnly = false) {
        try {
            $config = phonepeCallbackConfig($restaurantId, $platformOnly);
            if (empty($config['client_id']) || empty($config['client_secret'])) {
                error_log('PhonePe verify: no keys for ' . ($restaurantId ?: 'platform'));
                return null;
            }
            $r = phonepeOrderStatus($config, (string)$merchantTransactionId);
            if (!$r) return null;
            if ($r['state'] === 'COMPLETED' && $expectedPaise !== null && $r['amount'] !== null && abs($r['amount'] - $expectedPaise) > 1) {
                error_log('PhonePe verify: AMOUNT MISMATCH for ' . $merchantTransactionId . ' (paid ' . $r['amount'] . ' paise, expected ' . $expectedPaise . ')');
                return 'AMOUNT_MISMATCH';
            }
            return $r['state'];
        } catch (Throwable $e) {
            error_log('PhonePe verify: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('phonepeCreatePayment')) {
    /**
     * Start a payment. Returns ['ok' => true, 'url' => PhonePe page] or ['ok' => false, 'error' => text for logs].
     * $expireAfter: seconds the PhonePe page stays payable (300–3600).
     */
    function phonepeCreatePayment(array $config, string $merchantOrderId, float $rupees, string $redirectUrl, int $expireAfter = 1200, ?string $message = null): array {
        $token = phonepeCallbackAccessToken($config);
        if (!$token) return ['ok' => false, 'error' => 'PhonePe login failed'];
        $flow = ['type' => 'PG_CHECKOUT', 'merchantUrls' => ['redirectUrl' => $redirectUrl]];
        if ($message) $flow['message'] = mb_substr($message, 0, 60);
        $ch = curl_init($config['pay_url']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: O-Bearer ' . $token],
            CURLOPT_POSTFIELDS => json_encode(['merchantOrderId' => $merchantOrderId, 'amount' => (int)round($rupees * 100),
                'expireAfter' => max(300, min(3600, $expireAfter)), 'paymentFlow' => $flow])]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($curlError) return ['ok' => false, 'error' => 'connection: ' . $curlError];
        $d = json_decode((string)$response, true);
        if ($httpCode === 200 && !empty($d['redirectUrl'])) return ['ok' => true, 'url' => $d['redirectUrl'], 'phonepe_order_id' => $d['orderId'] ?? null];
        return ['ok' => false, 'error' => ($d['message'] ?? 'refused') . ' (code ' . ($d['code'] ?? $httpCode) . ')'];
    }
}

if (!function_exists('ppTxnId')) {
    /** A payment id PhonePe accepts (max 63 chars, letters/digits/_ -), hard to guess. */
    function ppTxnId(string $prefix, string $hint = ''): string {
        $hint = preg_replace('/[^A-Za-z0-9-]/', '', $hint);
        return substr($prefix . ($hint !== '' ? $hint . '_' : '') . bin2hex(random_bytes(10)), 0, 63);
    }
}

if (!function_exists('ppWebhookCreds')) {
    /**
     * Webhook a restaurant with its own PhonePe account creates in PhonePe (shown in Payments › Payment Gateway).
     * The password is derived from PAYMENT_KEY_SECRET / WA_KEY_SECRET, nothing is stored. Null if no key is set.
     */
    function ppWebhookCreds(string $rid): ?array {
        $k = ppSecretKey();
        if ($k === null || $rid === '') return null;
        $app = rtrim((string)env('APP_URL', ''), '/') ?: 'https://restrogrow.com';
        return ['url' => $app . '/main/api/phonepe_webhook.php?rid=' . rawurlencode($rid), 'username' => 'restrogrow',
                'password' => substr(hash_hmac('sha256', 'pp-webhook|' . $rid, $k), 0, 24)];
    }
}
