<?php
/**
 * "Connect RepeatGrow" (Growth › WhatsApp) — the RestroGrow side.
 *
 * RepeatGrow (https://repeatgrow.com, our WhatsApp CRM; code in "Desktop/Repeat grow/wacrm")
 * sends the WhatsApp messages. Instead of the owner copying an API key, they click
 * Connect and approve on RepeatGrow:
 *
 *   1. RestroGrow → {BASE}/connect/restrogrow?client_id&redirect_uri&state&scopes&restaurant
 *   2. Owner logs in to RepeatGrow, picks the account, clicks Allow
 *   3. RepeatGrow → redirect_uri?code=…&state=…           (or ?error=access_denied&state=…)
 *   4. RestroGrow → POST {BASE}/api/v1/connect/token {client_id, client_secret, code, redirect_uri}
 *                 ← { api_key, account: {id, name}, phone_number, scopes }
 *
 * With the key (scopes messages:send, templates:read, templates:write) RestroGrow can
 * also create every message template for the owner and follow Meta's review:
 *   POST {BASE}/api/v1/templates   {name, category, language, body_text, sample_values:{body:[…]}}
 *   GET  {BASE}/api/v1/templates?names=a,b,c   → [{name, language, status, rejection_reason}]
 *   POST {BASE}/api/v1/keys/revoke-self        (Disconnect)
 * The full contract for the RepeatGrow side is in docs/REPEATGROW_CONNECT.md.
 *
 * .env: REPEATGROW_CONNECT_URL (default https://repeatgrow.com),
 *       REPEATGROW_CLIENT_ID, REPEATGROW_CLIENT_SECRET (issued by RepeatGrow for RestroGrow).
 * Never calls the real RepeatGrow from a local computer unless REPEATGROW_CONNECT_URL
 * points at a local test server.
 *
 * Errors that stop a whole batch come back as RgcHalt (see rgcHaltOn): 'unauthorized' (401,
 * the owner disconnected RestroGrow in RepeatGrow › Settings › Connected apps) and
 * 'rate_limited' (429: 120 requests a minute per key; Meta allows ~100 new templates an
 * hour per number). The callers in whatsapp_engine.php clear the connection or try again
 * on the next run.
 */
require_once __DIR__ . '/env_loader.php';

if (!class_exists('RgcHalt')) {
    /** RepeatGrow said stop: $reason is 'unauthorized' (key revoked) or 'rate_limited'. */
    class RgcHalt extends RuntimeException {
        public $reason;
        public function __construct(string $reason, string $message = '') { $this->reason = $reason; parent::__construct($message !== '' ? $message : $reason); }
    }
}

if (!function_exists('rgcBase')) {

    function rgcBase(): string { return rtrim((string)env('REPEATGROW_CONNECT_URL', 'https://repeatgrow.com'), '/'); }
    function rgcClientId(): string { return (string)env('REPEATGROW_CLIENT_ID', ''); }
    function rgcIsLocalTarget(): bool { $h = parse_url(rgcBase(), PHP_URL_HOST); return in_array($h, ['localhost', '127.0.0.1'], true); }
    /** Connect button available: RestroGrow has its client id/secret from RepeatGrow. */
    function rgcAvailable(): bool { return rgcClientId() !== '' && (string)env('REPEATGROW_CLIENT_SECRET', '') !== ''; }
    /** On a local computer only a local stand-in RepeatGrow is ever called. */
    function rgcBlocked(): bool { return function_exists('waDryRun') && waDryRun() && !rgcIsLocalTarget(); }

    function ensureRepeatgrowConnectSchema(PDO $conn): void {
        static $done = false;
        if ($done || $conn->inTransaction()) return;
        $done = true;
        $conn->exec("CREATE TABLE IF NOT EXISTS wa_connect_states (
            state VARCHAR(64) PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $cols = [
            'wa_settings' => ['connected_via' => "VARCHAR(10) NULL", 'rg_account_id' => 'VARCHAR(64) NULL', 'rg_phone' => 'VARCHAR(30) NULL', 'connected_at' => 'DATETIME NULL', 'templates_synced_at' => 'DATETIME NULL',
                              // lost_at: RepeatGrow stopped accepting the key (401); templates_retry: a template batch hit the rate limit
                              'lost_at' => 'DATETIME NULL', 'templates_retry' => 'TINYINT NOT NULL DEFAULT 0'],
            'wa_auto' => ['template_status' => 'VARCHAR(12) NULL', 'template_reason' => 'VARCHAR(255) NULL', 'pending_template' => 'VARCHAR(100) NULL', 'template_version' => 'INT NOT NULL DEFAULT 0',
                         'template_text' => 'VARCHAR(1024) NULL', 'template_vars' => 'VARCHAR(255) NULL', 'pending_text' => 'VARCHAR(1024) NULL', 'pending_vars' => 'VARCHAR(255) NULL'],
            'wa_campaigns' => ['template_status' => 'VARCHAR(12) NULL', 'template_reason' => 'VARCHAR(255) NULL', 'template_vars' => 'VARCHAR(255) NULL'],
        ];
        foreach ($cols as $table => $list) {
            foreach ($list as $col => $def) {
                try {
                    $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE " . $conn->quote($col));
                    if (!($q && $q->fetch())) $conn->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
                } catch (PDOException $e) { error_log("ensureRepeatgrowConnectSchema $table.$col: " . $e->getMessage()); }
            }
        }
        // the API key is stored encrypted (waKeySeal), which is longer than the key itself
        try {
            $c = $conn->query("SHOW COLUMNS FROM wa_settings LIKE 'api_key'")->fetch(PDO::FETCH_ASSOC);
            if ($c && preg_match('/varchar\((\d+)\)/i', (string)$c['Type'], $m) && (int)$m[1] < 512) $conn->exec('ALTER TABLE wa_settings MODIFY api_key VARCHAR(512) NULL');
        } catch (PDOException $e) { error_log('ensureRepeatgrowConnectSchema api_key: ' . $e->getMessage()); }
    }

    /** Where RepeatGrow sends the owner back to. */
    function rgcRedirectUri(): string {
        $base = rtrim((string)env('APP_URL', ''), '/');
        if ($base === '' || (isset($_SERVER['HTTP_HOST']) && stripos($base, (string)$_SERVER['HTTP_HOST']) === false)) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'restrogrow.com') . (strpos($_SERVER['REQUEST_URI'] ?? '', '/menuwebsite/') === 0 ? '/menuwebsite' : '');
        }
        return $base . '/main/api/repeatgrow_connect.php';
    }

    function rgcScopes(): array { return ['messages:send', 'templates:read', 'templates:write']; }

    /** The URL that starts the connection on RepeatGrow. */
    function rgcAuthorizeUrl(PDO $conn, string $rid, string $restaurantName): string {
        ensureRepeatgrowConnectSchema($conn);
        $conn->exec('DELETE FROM wa_connect_states WHERE created_at < NOW() - INTERVAL 1 HOUR');
        $state = bin2hex(random_bytes(24));
        $conn->prepare('INSERT INTO wa_connect_states (state, restaurant_id) VALUES (?, ?)')->execute([$state, $rid]);
        return rgcBase() . '/connect/restrogrow?' . http_build_query([
            'client_id' => rgcClientId(), 'redirect_uri' => rgcRedirectUri(), 'state' => $state,
            'scopes' => implode(',', rgcScopes()), 'restaurant' => mb_substr($restaurantName, 0, 80),
        ]);
    }

    /** One-time state check: returns the restaurant it was made for. */
    function rgcConsumeState(PDO $conn, string $state): ?string {
        ensureRepeatgrowConnectSchema($conn);
        $st = $conn->prepare('SELECT restaurant_id FROM wa_connect_states WHERE state = ? AND created_at >= NOW() - INTERVAL 30 MINUTE');
        $st->execute([$state]);
        $rid = $st->fetchColumn();
        $conn->prepare('DELETE FROM wa_connect_states WHERE state = ?')->execute([$state]);
        return $rid ?: null;
    }

    /** JSON call to RepeatGrow. Returns [httpCode, decoded body]. */
    function rgcRequest(string $method, string $path, ?string $key, ?array $body = null): array {
        if (rgcBlocked()) return [0, ['error' => ['message' => 'Local computer: RepeatGrow is not called from here']]];
        $ch = curl_init(rgcBase() . $path);
        $headers = ['Accept: application/json'];
        if ($key) $headers[] = 'Authorization: Bearer ' . $key;
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_HTTPHEADER => $headers]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) return [0, ['error' => ['message' => 'Could not reach RepeatGrow: ' . $err]]];
        return [$code, json_decode((string)$res, true) ?: []];
    }
    function rgcError(array $d, string $fallback): string { $e = $d['error'] ?? null; return is_array($e) ? (string)($e['message'] ?? $fallback) : (string)($e ?? $fallback); }
    /** RepeatGrow's machine-readable error code, e.g. 'unauthorized', 'rate_limited', 'opted_out'. */
    function rgcErrorCode(array $d): string { return is_array($d['error'] ?? null) ? (string)($d['error']['code'] ?? '') : ''; }
    /** 401 / 429 on a call made with a restaurant's key stop the whole batch. */
    function rgcHaltOn(int $http, array $d): void {
        if ($http === 401) throw new RgcHalt('unauthorized', rgcError($d, 'RepeatGrow no longer accepts this connection'));
        if ($http === 429) throw new RgcHalt('rate_limited', rgcError($d, 'RepeatGrow is busy, try again shortly'));
    }

    /** Step 4: the code from RepeatGrow → an API key for this restaurant. */
    function rgcExchangeCode(string $code): array {
        [$http, $d] = rgcRequest('POST', '/api/v1/connect/token', null, [
            'client_id' => rgcClientId(), 'client_secret' => (string)env('REPEATGROW_CLIENT_SECRET', ''),
            'code' => $code, 'redirect_uri' => rgcRedirectUri(),
        ]);
        $data = $d['data'] ?? $d;
        if ($http !== 200 || empty($data['api_key'])) return [false, rgcError($d, 'RepeatGrow did not accept the connection (' . $http . ')')];
        return [true, $data];
    }

    /** Ask RepeatGrow to send a template to Meta for review. Returns [ok, status|error, http code].
     *  409: a template with this name and language already exists with other wording. */
    function rgcCreateTemplate(string $key, string $name, string $language, string $bodyText, array $samples): array {
        [$http, $d] = rgcRequest('POST', '/api/v1/templates', $key, [
            'name' => $name, 'category' => 'MARKETING', 'language' => $language,
            'body_text' => $bodyText, 'sample_values' => ['body' => array_values($samples)],
        ]);
        rgcHaltOn($http, $d);
        $data = $d['data'] ?? $d;
        if ($http >= 200 && $http < 300) return [true, strtoupper((string)($data['status'] ?? 'PENDING')), $http];
        return [false, rgcError($d, 'RepeatGrow error ' . $http), $http];
    }

    /** Review status for templates by name: [name => [status, reason]]. */
    function rgcTemplateStatuses(string $key, array $names): array {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_filter($names))), 50) as $chunk) {
            [$http, $d] = rgcRequest('GET', '/api/v1/templates?names=' . rawurlencode(implode(',', $chunk)), $key);
            rgcHaltOn($http, $d);
            if ($http !== 200) continue;
            foreach ((array)($d['data'] ?? $d) as $t) {
                if (!empty($t['name'])) $out[$t['name']] = [strtoupper((string)($t['status'] ?? '')), (string)($t['rejection_reason'] ?? '')];
            }
        }
        return $out;
    }

    function rgcRevoke(string $key): void { rgcRequest('POST', '/api/v1/keys/revoke-self', $key, []); }

    /** RepeatGrow / Meta status → ours */
    function rgcStatus(string $s): string {
        $s = strtoupper($s);
        if ($s === 'APPROVED') return 'approved';
        if (in_array($s, ['REJECTED', 'DISABLED', 'PAUSED'], true)) return 'rejected';
        return 'pending';
    }
}
