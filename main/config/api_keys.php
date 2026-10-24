<?php
/**
 * API keys: an owner lets their own code, Claude Code, Cursor or any other agent work on their restaurant.
 *
 * The owner creates a key in the dashboard (API keys page, api/api_keys_admin.php + assets/js/api-keys-studio.js)
 * and ticks what it may do. The key works on:
 *  - the REST API at /api/v1 (main/api/v1/index.php, docs at /developers)
 *  - the MCP server at /mcp (mcp/server.php), so AI agents get the same tools ChatGPT has, limited to the ticked permissions.
 *
 * Keys look like rg_live_<40 hex>. Only their SHA-256 is stored; the full key is shown once. A key belongs to one
 * restaurant and the owner who made it, and stops working when it is revoked, expires, the owner account is
 * switched off, or Super Admin switches the API keys section off (Restaurants › Access).
 * Table (created on first use, the live DB needs no migration): api_keys. Plan: docs/API_KEYS.md.
 */

if (defined('API_KEYS_LOADED')) return;
define('API_KEYS_LOADED', true);

/** permission => [label, what it allows] */
const API_PERMS = [
    'restaurant:read' => ['Restaurant details', 'Name, currency, timezone and menu sections'],
    'menu:read'       => ['Read menu', 'Dishes, prices, sizes and which are switched on'],
    'menu:write'      => ['Change menu', 'Add dishes, edit names and prices, switch dishes on or off'],
    'orders:read'     => ['Read orders', 'Orders with dishes, customer name and phone, payment'],
    'orders:write'    => ['Change orders', 'Accept, prepare, mark ready, complete, cancel or reject orders'],
    'reports:read'    => ['Read reports', 'Sales, top dishes, peak hours, payments and other reports'],
];

/** The permission each MCP tool needs when it is called with an API key (mcp/tools.php). */
const API_TOOL_PERMS = [
    'get_restaurant' => 'restaurant:read',
    'get_sales_summary' => 'reports:read',
    'get_report' => 'reports:read',
    'list_orders' => 'orders:read',
    'get_order' => 'orders:read',
    'list_menu' => 'menu:read',
    'set_dish_availability' => 'menu:write',
    'update_dish' => 'menu:write',
    'add_dish' => 'menu:write',
    'update_order_status' => 'orders:write',
];

const API_KEY_PREFIX = 'rg_live_';
const API_KEYS_MAX = 20;   // live keys per restaurant

function apiKeysEnsure(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->exec("CREATE TABLE IF NOT EXISTS api_keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(50) NOT NULL,
        user_id INT NOT NULL,
        name VARCHAR(80) NOT NULL,
        key_hash CHAR(64) NOT NULL UNIQUE,
        key_hint VARCHAR(40) NOT NULL,
        perms VARCHAR(255) NOT NULL,
        expires_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME NULL,
        revoked_at DATETIME NULL,
        INDEX (restaurant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Known permissions out of a list, in the API_PERMS order. */
function apiKeyCleanPerms(array $perms): array
{
    return array_values(array_intersect(array_keys(API_PERMS), $perms));
}

/** A new key → ['key' => full key (show once), 'row' => listed row] or ['error' => message]. */
function apiKeyCreate(PDO $conn, string $rid, int $userId, string $name, array $perms, int $days): array
{
    apiKeysEnsure($conn);
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', $name)), 0, 80);
    if (mb_strlen($name) < 2) return ['error' => 'Give the key a name, e.g. "Claude Code" or "Stock sync".'];
    $perms = apiKeyCleanPerms($perms);
    if (!$perms) return ['error' => 'Tick at least one permission.'];
    $live = $conn->prepare('SELECT COUNT(*) FROM api_keys WHERE restaurant_id = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())');
    $live->execute([$rid]);
    if ((int)$live->fetchColumn() >= API_KEYS_MAX) return ['error' => 'You already have ' . API_KEYS_MAX . ' keys. Revoke one you no longer use first.'];
    $key = API_KEY_PREFIX . bin2hex(random_bytes(20));
    $hint = substr($key, 0, 12) . '…' . substr($key, -4);
    $days = in_array($days, [30, 90, 365], true) ? $days : 0;
    $conn->prepare('INSERT INTO api_keys (restaurant_id, user_id, name, key_hash, key_hint, perms, expires_at) VALUES (?, ?, ?, ?, ?, ?, ' . ($days ? 'NOW() + INTERVAL ' . $days . ' DAY' : 'NULL') . ')')
        ->execute([$rid, $userId, $name, hash('sha256', $key), $hint, implode(' ', $perms)]);
    $id = (int)$conn->lastInsertId();
    foreach (apiKeyList($conn, $rid) as $r) if ($r['id'] === $id) return ['key' => $key, 'row' => $r];
    return ['key' => $key, 'row' => null];
}

/** The restaurant's keys (live and recently ended), newest first. Never the key itself. */
function apiKeyList(PDO $conn, string $rid): array
{
    apiKeysEnsure($conn);
    $st = $conn->prepare("SELECT id, name, key_hint, perms, expires_at, created_at, last_used_at, revoked_at FROM api_keys
        WHERE restaurant_id = ? AND (revoked_at IS NULL OR revoked_at > NOW() - INTERVAL 30 DAY) ORDER BY revoked_at IS NOT NULL, id DESC");
    $st->execute([$rid]);
    return array_map(function ($r) {
        $r['id'] = (int)$r['id'];
        $r['perms'] = $r['perms'] === '' ? [] : explode(' ', $r['perms']);
        $r['status'] = $r['revoked_at'] ? 'revoked' : ($r['expires_at'] && strtotime($r['expires_at']) <= time() ? 'expired' : 'active');
        return $r;
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}

function apiKeyRevoke(PDO $conn, string $rid, int $id): ?string
{
    apiKeysEnsure($conn);
    $st = $conn->prepare('SELECT name FROM api_keys WHERE id = ? AND restaurant_id = ? AND revoked_at IS NULL');
    $st->execute([$id, $rid]);
    $name = $st->fetchColumn();
    if ($name === false) return null;
    $conn->prepare('UPDATE api_keys SET revoked_at = NOW() WHERE id = ? AND restaurant_id = ?')->execute([$id, $rid]);
    return (string)$name;
}

/** The key from the request: Authorization: Bearer rg_live_… or X-API-Key: rg_live_…, else ''. */
function apiKeyFromRequest(): string
{
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) return $m[1];
    return trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
}

function apiKeyLooksLike(string $token): bool
{
    return str_starts_with($token, API_KEY_PREFIX);
}

/**
 * The live key behind a token, or null (unknown, revoked, expired, owner switched off or no longer the owner).
 * Returns the shape mcp/server.php uses for its tokens: restaurant_id, user_id, scopes (none: perms decide), plus
 * key_id, key_name and perms.
 */
function apiKeyVerify(PDO $conn, string $token): ?array
{
    if (!apiKeyLooksLike($token) || strlen($token) > 100) return null;
    apiKeysEnsure($conn);
    $st = $conn->prepare('SELECT id, restaurant_id, user_id, name, perms FROM api_keys WHERE key_hash = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())');
    $st->execute([hash('sha256', $token)]);
    $k = $st->fetch(PDO::FETCH_ASSOC);
    if (!$k) return null;
    // compared in PHP: users and api_keys may use different collations
    $u = $conn->prepare('SELECT restaurant_id, is_active FROM users WHERE id = ?');
    $u->execute([(int)$k['user_id']]);
    $owner = $u->fetch(PDO::FETCH_ASSOC);
    if (!$owner || (int)$owner['is_active'] !== 1 || (string)$owner['restaurant_id'] !== (string)$k['restaurant_id']) return null;
    $conn->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE id = ? AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL 1 MINUTE)')->execute([$k['id']]);
    return ['restaurant_id' => (string)$k['restaurant_id'], 'user_id' => (int)$k['user_id'], 'client_id' => '', 'scopes' => [],
        'key_id' => (int)$k['id'], 'key_name' => $k['name'], 'perms' => explode(' ', $k['perms'])];
}

/** How a key shows in the owner's Activity log: API key "Stock sync". */
function apiKeyLabel(array $tok): string
{
    return 'API key "' . $tok['key_name'] . '"';
}
