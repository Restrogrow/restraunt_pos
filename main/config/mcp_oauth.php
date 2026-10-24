<?php
/**
 * "Connect RestroGrow" for ChatGPT, Claude and other AI apps (MCP + OAuth 2.1).
 *
 * The AI app talks to main/mcp/server.php (served at /mcp). Before it can, the owner links their account:
 *
 *  1. The app finds us through /.well-known/oauth-protected-resource and /.well-known/oauth-authorization-server
 *     (main/oauth/metadata.php) and registers itself: Dynamic Client Registration (main/oauth/register.php) or a
 *     Client ID Metadata Document (its client_id is an https URL on a known AI host, fetched here).
 *  2. It sends the owner to main/oauth/authorize.php: the normal RestroGrow login (WhatsApp OTP, Google), then
 *     "Allow ChatGPT to manage <restaurant>?". Allow gives a one-time code bound to the PKCE challenge.
 *  3. main/oauth/token.php swaps the code for an access token (1 hour) and a refresh token (90 days, rotated).
 *
 * Tokens are random strings; only their SHA-256 is stored. Each token belongs to one restaurant and one
 * resource URL (the audience), and stops working when the owner account is switched off.
 * Tables (created on first use): mcp_clients, mcp_requests, mcp_codes, mcp_tokens.
 * Plan and setup: docs/CHATGPT_APP.md.
 */

if (defined('MCP_OAUTH_LOADED')) return;
define('MCP_OAUTH_LOADED', true);

const MCP_SCOPES = ['read', 'write'];
const MCP_ACCESS_TTL = 3600;
const MCP_REFRESH_TTL = 90 * 86400;
// AI apps whose Client ID Metadata Document we fetch (anything else registers through DCR)
const MCP_CIMD_HOSTS = ['chatgpt.com', 'openai.com', 'claude.ai', 'claude.com', 'anthropic.com'];

function mcpEnsure(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->exec("CREATE TABLE IF NOT EXISTS mcp_clients (
        client_id VARCHAR(255) PRIMARY KEY,
        name VARCHAR(120) NOT NULL DEFAULT '',
        redirect_uris TEXT NOT NULL,
        kind VARCHAR(10) NOT NULL DEFAULT 'dcr',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        fetched_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS mcp_requests (
        id CHAR(32) PRIMARY KEY,
        client_id VARCHAR(255) NOT NULL,
        redirect_uri VARCHAR(500) NOT NULL,
        state VARCHAR(500) NULL,
        code_challenge VARCHAR(128) NOT NULL,
        scope VARCHAR(100) NOT NULL,
        resource VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS mcp_codes (
        code_hash CHAR(64) PRIMARY KEY,
        client_id VARCHAR(255) NOT NULL,
        redirect_uri VARCHAR(500) NOT NULL,
        code_challenge VARCHAR(128) NOT NULL,
        scope VARCHAR(100) NOT NULL,
        resource VARCHAR(255) NOT NULL,
        restaurant_id VARCHAR(50) NOT NULL,
        user_id INT NOT NULL,
        expires_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS mcp_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token_hash CHAR(64) NOT NULL UNIQUE,
        kind VARCHAR(8) NOT NULL,
        client_id VARCHAR(255) NOT NULL,
        scope VARCHAR(100) NOT NULL,
        resource VARCHAR(255) NOT NULL,
        restaurant_id VARCHAR(50) NOT NULL,
        user_id INT NOT NULL,
        expires_at DATETIME NOT NULL,
        revoked TINYINT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME NULL,
        INDEX (restaurant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** https://restrogrow.com on the live site, http://localhost/menuwebsite locally: where /mcp and /.well-known live. */
function mcpBase(): string
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? 'restrogrow.com'));
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || !preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host);
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $p = strpos($script, '/main/');
    $prefix = $p === false ? '' : substr($script, 0, $p);
    return ($https ? 'https' : 'http') . '://' . $host . $prefix;
}

function mcpIssuer(): string { return mcpBase(); }
function mcpResource(): string { return mcpBase() . '/mcp'; }

function mcpRandom(): string { return bin2hex(random_bytes(32)); }
function mcpHash(string $t): string { return hash('sha256', $t); }

function mcpJson(int $status, array $body, array $headers = []): void
{
    while (ob_get_level()) ob_end_clean();   // stray output from included code would spoil the JSON
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    foreach ($headers as $h) header($h);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** The token and registration endpoints are called from browsers too (MCP Inspector): open CORS, no cookies. */
function mcpCors(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Protocol-Version, Mcp-Session-Id');
    header('Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}

/** A redirect URI an AI app may use: https anywhere, http only for a local test tool. */
function mcpRedirectOk(string $u): bool
{
    if (strlen($u) > 500 || preg_match('/[\s#]/', $u)) return false;
    $p = parse_url($u);
    if (!$p || empty($p['scheme']) || empty($p['host'])) return false;
    if ($p['scheme'] === 'https') return true;
    return $p['scheme'] === 'http' && in_array($p['host'], ['localhost', '127.0.0.1'], true);
}

/** Register a client (DCR). Returns the stored client or an error message. */
function mcpRegister(PDO $conn, array $in): array
{
    mcpEnsure($conn);
    $uris = array_values(array_filter((array)($in['redirect_uris'] ?? []), 'is_string'));
    if (!$uris || count($uris) > 10) return ['error' => 'redirect_uris is required (at most 10)'];
    foreach ($uris as $u) if (!mcpRedirectOk($u)) return ['error' => 'redirect_uri not allowed: ' . mb_substr($u, 0, 100)];
    $auth = (string)($in['token_endpoint_auth_method'] ?? 'none');
    if ($auth !== 'none') return ['error' => 'only public clients (token_endpoint_auth_method "none") are supported'];
    $id = 'rgc_' . bin2hex(random_bytes(16));
    $name = mb_substr(trim((string)($in['client_name'] ?? '')), 0, 120);
    $conn->prepare("INSERT INTO mcp_clients (client_id, name, redirect_uris, kind) VALUES (?, ?, ?, 'dcr')")
        ->execute([$id, $name, json_encode($uris, JSON_UNESCAPED_SLASHES)]);
    return ['client_id' => $id, 'client_name' => $name, 'redirect_uris' => $uris];
}

/** A client by id: a registered one, or a Client ID Metadata Document on a known AI host (cached a day). */
function mcpClient(PDO $conn, string $clientId): ?array
{
    mcpEnsure($conn);
    $st = $conn->prepare('SELECT * FROM mcp_clients WHERE client_id = ?');
    $st->execute([$clientId]);
    $c = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $isUrl = str_starts_with($clientId, 'https://');
    if ($c && (!$isUrl || strtotime((string)$c['fetched_at']) > time() - 86400)) {
        $c['redirect_uris'] = json_decode((string)$c['redirect_uris'], true) ?: [];
        return $c;
    }
    if (!$isUrl) return null;
    $host = strtolower((string)parse_url($clientId, PHP_URL_HOST));
    $known = false;
    foreach (MCP_CIMD_HOSTS as $h) if ($host === $h || str_ends_with($host, '.' . $h)) $known = true;
    if (!$known) return null;
    $ch = curl_init($clientId);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_MAXFILESIZE => 65536]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $doc = $code === 200 && is_string($body) ? json_decode($body, true) : null;
    if (!is_array($doc) || ($doc['client_id'] ?? '') !== $clientId) { error_log("mcpClient: bad metadata document at $clientId ($code)"); return null; }
    $uris = array_values(array_filter((array)($doc['redirect_uris'] ?? []), fn($u) => is_string($u) && mcpRedirectOk($u)));
    if (!$uris) return null;
    $name = mb_substr((string)($doc['client_name'] ?? $host), 0, 120);
    $conn->prepare("INSERT INTO mcp_clients (client_id, name, redirect_uris, kind, fetched_at) VALUES (?, ?, ?, 'cimd', NOW())
        ON DUPLICATE KEY UPDATE name = VALUES(name), redirect_uris = VALUES(redirect_uris), fetched_at = NOW()")
        ->execute([$clientId, $name, json_encode($uris, JSON_UNESCAPED_SLASHES)]);
    return ['client_id' => $clientId, 'name' => $name, 'redirect_uris' => $uris, 'kind' => 'cimd'];
}

/** 'ChatGPT' / 'Claude' when a redirect host really is theirs (exact domain or a subdomain of it), else ''. */
function mcpBrandForHost(string $host): string
{
    $host = strtolower(rtrim($host, '.'));
    $is = function (array $domains) use ($host) {
        foreach ($domains as $d) if ($host === $d || str_ends_with($host, '.' . $d)) return true;
        return false;
    };
    if ($is(['chatgpt.com', 'openai.com'])) return 'ChatGPT';
    if ($is(['claude.ai', 'claude.com', 'anthropic.com'])) return 'Claude';
    return '';
}

/**
 * "ChatGPT", "Claude" … for the consent screen. Pass only the redirect URI the code will go to: the brand
 * is shown only when EVERY given URI is on that brand's own domain (a client registering chatgpt.com plus
 * evil.net, or chatgpt.com.evil.net, used to be labelled "ChatGPT" while the code went to evil.net).
 */
function mcpClientLabel(array $c): string
{
    $brands = [];
    foreach ((array)($c['redirect_uris'] ?? []) as $u) $brands[] = mcpBrandForHost((string)parse_url((string)$u, PHP_URL_HOST));
    $brands = array_unique($brands);
    if (count($brands) === 1 && $brands[0] !== '') return $brands[0];
    return trim((string)($c['name'] ?? '')) ?: 'An AI app';
}

/** The granted scope: what was asked for, out of read / write (nothing asked = both). */
function mcpScope(string $asked): string
{
    $s = array_values(array_intersect(MCP_SCOPES, preg_split('/\s+/', trim($asked)) ?: []));
    return implode(' ', $s ?: MCP_SCOPES);
}

/** Back to the AI app with the code (or an error), with iss as RFC 9207 asks. */
function mcpRedirectBack(string $uri, array $params): void
{
    $params['iss'] = mcpIssuer();
    header('Location: ' . $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params), true, 302);
    exit;
}

function mcpIssueCode(PDO $conn, array $req, string $rid, int $userId): string
{
    $code = mcpRandom();
    $conn->prepare('INSERT INTO mcp_codes (code_hash, client_id, redirect_uri, code_challenge, scope, resource, restaurant_id, user_id, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL 5 MINUTE)')
        ->execute([mcpHash($code), $req['client_id'], $req['redirect_uri'], $req['code_challenge'], $req['scope'], $req['resource'], $rid, $userId]);
    $conn->prepare('DELETE FROM mcp_requests WHERE id = ? OR expires_at < NOW()')->execute([$req['id']]);
    return $code;
}

/** A fresh access + refresh token pair (the token endpoint's JSON body). */
function mcpIssueTokens(PDO $conn, string $clientId, string $scope, string $resource, string $rid, int $userId): array
{
    $access = 'rgat_' . mcpRandom();
    $refresh = 'rgrt_' . mcpRandom();
    $ins = $conn->prepare('INSERT INTO mcp_tokens (token_hash, kind, client_id, scope, resource, restaurant_id, user_id, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL ? SECOND)');
    $ins->execute([mcpHash($access), 'access', $clientId, $scope, $resource, $rid, $userId, MCP_ACCESS_TTL]);
    $ins->execute([mcpHash($refresh), 'refresh', $clientId, $scope, $resource, $rid, $userId, MCP_REFRESH_TTL]);
    $conn->exec('DELETE FROM mcp_tokens WHERE expires_at < NOW() - INTERVAL 1 DAY');
    return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => MCP_ACCESS_TTL, 'refresh_token' => $refresh, 'scope' => $scope];
}

/** The restaurant behind a bearer token for this server, or null (expired, revoked, other audience, owner switched off). */
function mcpVerifyAccess(PDO $conn, string $token): ?array
{
    if (!str_starts_with($token, 'rgat_')) return null;
    mcpEnsure($conn);
    $st = $conn->prepare("SELECT t.id, t.scope, t.resource, t.restaurant_id, t.user_id, t.client_id FROM mcp_tokens t
        WHERE t.token_hash = ? AND t.kind = 'access' AND t.revoked = 0 AND t.expires_at > NOW()");
    $st->execute([mcpHash($token)]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t || $t['resource'] !== mcpResource() || !mcpOwnerActive($conn, $t)) return null;
    $conn->prepare('UPDATE mcp_tokens SET last_used_at = NOW() WHERE id = ? AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL 5 MINUTE)')->execute([$t['id']]);
    $t['scopes'] = explode(' ', $t['scope']);
    return $t;
}

/** The owner account behind a token still exists, still owns that restaurant and is switched on. */
function mcpOwnerActive(PDO $conn, array $t): bool
{
    // compared in PHP: users and the mcp_ tables may use different collations
    $u = $conn->prepare('SELECT restaurant_id, is_active FROM users WHERE id = ?');
    $u->execute([(int)$t['user_id']]);
    $r = $u->fetch(PDO::FETCH_ASSOC);
    return $r && (int)$r['is_active'] === 1 && (string)$r['restaurant_id'] === (string)$t['restaurant_id'];
}

/** The 401 that tells an AI app where to log in. */
function mcpChallenge(string $error = '', string $desc = ''): string
{
    $h = 'WWW-Authenticate: Bearer resource_metadata="' . mcpBase() . '/.well-known/oauth-protected-resource"';
    if ($error !== '') $h .= ', error="' . $error . '", error_description="' . str_replace('"', "'", $desc) . '"';
    return $h;
}

/** New owner password: every AI app connection of that owner stops working (they connect again). */
function mcpRevokeUser(PDO $conn, int $userId): void
{
    try {
        $conn->prepare('UPDATE mcp_tokens SET revoked = 1 WHERE user_id = ? AND revoked = 0')->execute([$userId]);
    } catch (Throwable $e) { /* no AI app connected yet: no table */ }
}

/** Owner's "Connected AI apps": one row per app that holds a live refresh token. */
function mcpConnections(PDO $conn, string $rid): array
{
    mcpEnsure($conn);
    $st = $conn->prepare("SELECT t.client_id, COALESCE(c.name, '') name, c.redirect_uris, MIN(t.created_at) since, MAX(t.last_used_at) last_used
        FROM mcp_tokens t LEFT JOIN mcp_clients c ON c.client_id = t.client_id
        WHERE t.restaurant_id = ? AND t.revoked = 0 AND t.expires_at > NOW() GROUP BY t.client_id, c.name, c.redirect_uris");
    $st->execute([$rid]);
    return array_map(function ($r) {
        $r['label'] = mcpClientLabel(['name' => $r['name'], 'redirect_uris' => json_decode((string)$r['redirect_uris'], true) ?: []]);
        unset($r['redirect_uris']);
        return $r;
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}

function mcpDisconnect(PDO $conn, string $rid, string $clientId): int
{
    mcpEnsure($conn);
    $st = $conn->prepare('UPDATE mcp_tokens SET revoked = 1 WHERE restaurant_id = ? AND client_id = ? AND revoked = 0');
    $st->execute([$rid, $clientId]);
    return $st->rowCount();
}
