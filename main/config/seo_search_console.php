<?php
/**
 * Google Search Console (dashboard › SEO › Search performance).
 *
 * Unlike the Maps/Places key used by Google Business, Search Console data
 * belongs to the owner's Google account, so it needs per-restaurant OAuth:
 *   1. Owner clicks "Connect Google" → api/seo_sc.php?view=connect (state in session)
 *   2. Google → api/google_seo_callback.php (swaps the code for tokens, stores them encrypted)
 *   3. api/seo_sc.php reads clicks / impressions / position / top queries for the site
 *      they picked, from the Search Console API (webmasters v3).
 *
 * Scope: webmasters.readonly (read-only — nothing is written to their account).
 * Tokens are encrypted with WA_KEY_SECRET, the same AES-256-GCM scheme as the
 * Google Business tokens (see config/gbp_helpers.php).
 *
 * The site must be a verified Search Console property; owners with their own
 * domain verify it there, and the SEO page already collects the gsc_verification
 * meta tag for the HTML-tag method.
 */
if (!defined('SEO_SC_LOADED')) define('SEO_SC_LOADED', true);

const SEO_SC_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
const SEO_SC_DAYS = 28;   // Search Console data is ~2 days behind; 28 days is a stable window

function seoScEnv(string $k, string $d = ''): string { return function_exists('env') ? trim((string)env($k, $d)) : $d; }
function seoScClientId(): string { return seoScEnv('GOOGLE_SEO_CLIENT_ID'); }
function seoScClientSecret(): string { return seoScEnv('GOOGLE_SEO_CLIENT_SECRET'); }
function seoScOAuthReady(): bool { return seoScClientId() !== '' && seoScClientSecret() !== ''; }

/** Where Google sends the owner back after "Connect Google" (must be on the OAuth client). */
function seoScRedirectUri(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'restrogrow.com';
    $local = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host);
    $base = $local ? 'http://' . $host . '/menuwebsite' : 'https://' . $host;
    return $base . '/main/api/google_seo_callback.php';
}

/* ───────── tokens at rest (AES-256-GCM, WA_KEY_SECRET — same as Google Business) ───────── */

function seoScKey(): ?string
{
    $k = seoScEnv('WA_KEY_SECRET');
    if ($k === '') return null;
    $raw = base64_decode($k, true);
    return ($raw !== false && strlen($raw) >= 32) ? substr($raw, 0, 32) : hash('sha256', $k, true);
}
function seoScSeal(string $plain): string
{
    $k = seoScKey();
    if ($plain === '' || $k === null) return $plain;
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return $ct === false ? $plain : 'enc:v1:' . base64_encode($iv . $tag . $ct);
}
function seoScOpen(?string $stored): string
{
    $stored = (string)$stored;
    if (strpos($stored, 'enc:v1:') !== 0) return $stored;
    $k = seoScKey(); $raw = base64_decode(substr($stored, 7), true);
    $p = ($k !== null && $raw !== false && strlen($raw) > 28) ? openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)) : false;
    return $p === false ? '' : $p;
}

/* ───────── table ───────── */

function seoScEnsureTables(PDO $conn): void
{
    static $done = false;
    if ($done || $conn->inTransaction()) return;
    $done = true;
    $conn->exec("CREATE TABLE IF NOT EXISTS seo_search_console (
        restaurant_id VARCHAR(50) PRIMARY KEY,
        google_email VARCHAR(190) DEFAULT NULL,
        site_url VARCHAR(300) DEFAULT NULL,
        access_token TEXT DEFAULT NULL,
        refresh_token TEXT DEFAULT NULL,
        token_expires INT DEFAULT NULL,
        last_error VARCHAR(400) DEFAULT NULL,
        connected_at DATETIME DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function seoScRow(PDO $conn, string $rid): array
{
    seoScEnsureTables($conn);
    $st = $conn->prepare('SELECT * FROM seo_search_console WHERE restaurant_id = ?');
    $st->execute([$rid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}
function seoScSave(PDO $conn, string $rid, array $f): void
{
    seoScEnsureTables($conn);
    $cols = array_keys($f);
    $sql = 'INSERT INTO seo_search_console (restaurant_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')
        ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(function ($c) { return "$c = VALUES($c)"; }, $cols));
    $conn->prepare($sql)->execute(array_merge([$rid], array_values($f)));
}

/* ───────── HTTP ───────── */

function seoScHttp(string $method, string $url, array $headers = [], $body = null, int $timeout = 20): array
{
    $ch = curl_init($url);
    $h = $headers;
    if ($body !== null && !is_string($body)) { $body = json_encode($body, JSON_UNESCAPED_UNICODE); $h[] = 'Content-Type: application/json'; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => $h]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);   // local XAMPP's CA bundle is old
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = $res === false ? curl_error($ch) : '';
    curl_close($ch);
    $json = is_string($res) && $res !== '' ? json_decode($res, true) : null;
    return ['code' => $code, 'json' => is_array($json) ? $json : [], 'raw' => is_string($res) ? $res : '', 'error' => $err];
}

/* ───────── OAuth ───────── */

function seoScAuthUrl(string $state): string
{
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => seoScClientId(), 'redirect_uri' => seoScRedirectUri(), 'response_type' => 'code',
        'scope' => SEO_SC_SCOPE . ' openid email', 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state,
    ]);
}
function seoScExchange(string $code): array
{
    return seoScHttp('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'code' => $code, 'client_id' => seoScClientId(), 'client_secret' => seoScClientSecret(), 'redirect_uri' => seoScRedirectUri(), 'grant_type' => 'authorization_code',
    ]));
}

/** A working access token for the restaurant (refreshed when it's about to run out), or null. */
function seoScToken(PDO $conn, string $rid): ?string
{
    $row = seoScRow($conn, $rid);
    if (!$row || empty($row['refresh_token'])) return null;
    if (!empty($row['access_token']) && (int)$row['token_expires'] > time() + 60) return seoScOpen($row['access_token']) ?: null;
    $r = seoScHttp('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'client_id' => seoScClientId(), 'client_secret' => seoScClientSecret(), 'refresh_token' => seoScOpen($row['refresh_token']), 'grant_type' => 'refresh_token',
    ]));
    if ($r['code'] !== 200 || empty($r['json']['access_token'])) {
        seoScSave($conn, $rid, ['last_error' => mb_substr('Google sign-in could not be renewed: ' . seoScExplain($r), 0, 400)]);
        return null;
    }
    seoScSave($conn, $rid, ['access_token' => seoScSeal($r['json']['access_token']), 'token_expires' => time() + (int)($r['json']['expires_in'] ?? 3600)]);
    return $r['json']['access_token'];
}

/** A Google error in words the owner can act on. */
function seoScExplain(array $r): string
{
    $msg = $r['json']['error']['message'] ?? ($r['error'] ?: ('HTTP ' . $r['code']));
    $status = $r['json']['error']['status'] ?? '';
    if ($r['code'] === 403 && (stripos($msg, 'has not been used') !== false || stripos($msg, 'disabled') !== false)) return 'The Search Console API is switched off in the Google Cloud project: ' . $msg;
    if ($r['code'] === 403) return 'Google refused this (' . $status . '): ' . $msg;
    if ($r['code'] === 401) return 'Google sign-in expired. Connect Google again.';
    return $msg;
}

/** Calls the Search Console API with the restaurant's token. ['ok', 'json', 'error', 'code']. */
function seoScApi(PDO $conn, string $rid, string $method, string $url, $body = null): array
{
    $t = seoScToken($conn, $rid);
    if (!$t) return ['ok' => false, 'json' => [], 'code' => 401, 'error' => 'Connect Google first.'];
    $r = seoScHttp($method, $url, ['Authorization: Bearer ' . $t], $body);
    return ['ok' => $r['code'] >= 200 && $r['code'] < 300, 'json' => $r['json'], 'code' => $r['code'], 'error' => seoScExplain($r)];
}

/** The owner's verified Search Console properties (they pick which one is this website). */
function seoScSites(PDO $conn, string $rid): array
{
    $r = seoScApi($conn, $rid, 'GET', 'https://www.googleapis.com/webmasters/v3/sites');
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error']];
    $out = [];
    foreach ($r['json']['siteEntry'] ?? [] as $s) $out[] = ['url' => $s['siteUrl'] ?? '', 'permission' => $s['permissionLevel'] ?? ''];
    return ['ok' => true, 'sites' => $out];
}

/**
 * Search performance for one site: totals plus the top queries.
 * Returns ['ok' => bool, 'error' => ..., 'days' => n, 'totals' => [...], 'queries' => [...]].
 */
function seoScQuery(PDO $conn, string $rid, ?string $siteUrl = null, int $days = SEO_SC_DAYS): array
{
    $row = seoScRow($conn, $rid);
    $site = $siteUrl ?: ($row['site_url'] ?? '');
    if ($site === '') return ['ok' => false, 'error' => 'Choose your website in Google Search Console first.'];
    $enc = rawurlencode($site);
    $end = date('Y-m-d', strtotime('-2 days'));
    $start = date('Y-m-d', strtotime('-' . ($days + 1) . ' days'));

    // Totals (no dimensions)
    $t = seoScApi($conn, $rid, 'POST', "https://www.googleapis.com/webmasters/v3/sites/$enc/searchAnalytics/query", ['startDate' => $start, 'endDate' => $end]);
    if (!$t['ok']) return ['ok' => false, 'error' => $t['error']];
    $tot = $t['json']['rows'][0] ?? [];
    $totals = ['clicks' => (int)($tot['clicks'] ?? 0), 'impressions' => (int)($tot['impressions'] ?? 0),
        'ctr' => isset($tot['ctr']) ? round((float)$tot['ctr'] * 100, 2) : 0, 'position' => isset($tot['position']) ? round((float)$tot['position'], 1) : null];

    // Top queries
    $q = seoScApi($conn, $rid, 'POST', "https://www.googleapis.com/webmasters/v3/sites/$enc/searchAnalytics/query", ['startDate' => $start, 'endDate' => $end, 'dimensions' => ['query'], 'rowLimit' => 10]);
    $queries = [];
    if ($q['ok']) {
        foreach ($q['json']['rows'] ?? [] as $r) {
            $queries[] = ['query' => $r['keys'][0] ?? '', 'clicks' => (int)($r['clicks'] ?? 0), 'impressions' => (int)($r['impressions'] ?? 0),
                'ctr' => isset($r['ctr']) ? round((float)$r['ctr'] * 100, 1) : 0, 'position' => isset($r['position']) ? round((float)$r['position'], 1) : null];
        }
    }
    return ['ok' => true, 'days' => $days, 'start' => $start, 'end' => $end, 'site' => $site, 'totals' => $totals, 'queries' => $queries];
}
