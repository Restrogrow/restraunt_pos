<?php
/**
 * Shared PhonePe server-to-server status verification.
 *
 * Never trust a webhook body or a browser redirect directly — both can be
 * forged. Instead call PhonePe's order-status API with our own OAuth
 * credentials and treat that response as the only source of truth.
 *
 * Used by:
 *   - phonepe_order_callback.php (webhook handler)
 *   - tools/reconcile_pending_payments.php (safety-net reconciliation for
 *     orders where neither the webhook nor the customer's browser ever
 *     confirmed the payment)
 */

require_once __DIR__ . '/env_loader.php';

if (!function_exists('phonepeVerifyOrderState')) {
    /**
     * Returns the verified state string (e.g. 'COMPLETED', 'FAILED', 'PENDING')
     * or null if it couldn't be independently verified right now.
     */
    function phonepeVerifyOrderState($conn, $restaurantId, $merchantTransactionId) {
        try {
            $config = phonepeCallbackConfig($restaurantId);
            if (empty($config['client_id']) || empty($config['client_secret'])) {
                error_log('PhonePe verify: no PhonePe credentials configured for restaurant ' . $restaurantId);
                return null;
            }

            $accessToken = phonepeCallbackAccessToken($config);
            if (!$accessToken) {
                return null;
            }

            $statusUrl = $config['api_url'] . '/checkout/v2/order/' . urlencode($merchantTransactionId) . '/status';
            $ch = curl_init($statusUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: O-Bearer ' . $accessToken,
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError || $httpCode !== 200 || !$response) {
                error_log('PhonePe verify: status verification call failed (http=' . $httpCode . ', curl_error=' . $curlError . ')');
                return null;
            }

            $data = json_decode($response, true);
            $state = $data['state'] ?? $data['orderStatus'] ?? null;
            if (!$state) {
                error_log('PhonePe verify: status verification response had no state field');
                return null;
            }

            return $state;
        } catch (Exception $e) {
            error_log('PhonePe verify: status verification exception - ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('phonepeCallbackConfig')) {
    function phonepeCallbackConfig($restaurantId) {
        $isLocalhost = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false ||
                        strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false);

        if ($isLocalhost) {
            $clientId = env('PHONEPE_SANDBOX_CLIENT_ID', env('PHONEPE_SANDBOX_MERCHANT_ID', ''));
            $clientSecret = env('PHONEPE_SANDBOX_CLIENT_SECRET', env('PHONEPE_SANDBOX_SALT_KEY', ''));
            $clientVersion = env('PHONEPE_SANDBOX_CLIENT_VERSION', env('PHONEPE_SANDBOX_SALT_INDEX', '1'));
            $isSandbox = true;
        } else {
            $dbMerchantId = null;
            $dbSaltKey = null;
            $dbEnvironment = null;

            if ($restaurantId) {
                try {
                    $conn = getConnection();
                    $stmt = $conn->prepare("SELECT phonepe_merchant_id, phonepe_salt_key, phonepe_environment, payment_gateway_mode FROM users WHERE restaurant_id = ? LIMIT 1");
                    $stmt->execute([$restaurantId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $gwMode = $row['payment_gateway_mode'] ?? 'own';
                    if ($gwMode === 'own' && $row && !empty($row['phonepe_merchant_id']) && !empty($row['phonepe_salt_key'])) {
                        $dbMerchantId = $row['phonepe_merchant_id'];
                        $dbSaltKey = $row['phonepe_salt_key'];
                        $dbEnvironment = $row['phonepe_environment'] ?? 'SANDBOX';
                    }
                } catch (Exception $e) {
                }
            }

            $environment = $dbEnvironment ?: env('PHONEPE_ENVIRONMENT', '');
            if (empty($environment)) {
                $environment = 'PRODUCTION';
            }
            $clientId = $dbMerchantId ?: env('PHONEPE_CLIENT_ID', '');
            $clientSecret = $dbSaltKey ?: env('PHONEPE_CLIENT_SECRET', '');
            $clientVersion = env('PHONEPE_CLIENT_VERSION', '1');
            $isSandbox = strtoupper($environment) !== 'PRODUCTION';
        }

        if ($isSandbox) {
            $apiBase = env('PHONEPE_BASE_URL_TEST', 'https://api-preprod.phonepe.com/apis/pg-sandbox');
            $authUrl = $apiBase . '/v1/oauth/token';
        } else {
            $apiBase = 'https://api.phonepe.com/apis/pg';
            $authUrl = 'https://api.phonepe.com/apis/identity-manager/v1/oauth/token';
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'client_version' => $clientVersion,
            'api_url' => $apiBase,
            'auth_url' => $authUrl,
        ];
    }
}

if (!function_exists('phonepeCallbackAccessToken')) {
    function phonepeCallbackAccessToken($config) {
        $ch = curl_init($config['auth_url']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id' => $config['client_id'],
            'client_version' => $config['client_version'],
            'client_secret' => $config['client_secret'],
            'grant_type' => 'client_credentials',
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log('PhonePe verify: auth connection error - ' . $curlError);
            return null;
        }

        $data = json_decode($response, true);
        if ($httpCode !== 200 || !isset($data['access_token'])) {
            error_log('PhonePe verify: auth error - ' . ($data['message'] ?? ('http ' . $httpCode)));
            return null;
        }

        return $data['access_token'];
    }
}
