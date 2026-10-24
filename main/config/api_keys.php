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
 * restaurant and the owner who made it. The owner can edit its name, permissions, "valid until" date (or never) and
 * allowed IPs, pause and resume it, rotate it (new secret, the old one ends now / in 1 h / in 24 h), revoke it and
 * delete ended keys. It stops working when revoked, expired or paused, from an IP not on its list, when the owner
 * account is switched off, or when Super Admin switches the API keys section off (Restaurants › Access).
 * Table (created on first use, the live DB needs no migration): api_keys. Plan: docs/API_KEYS.md.
 */

if (defined('API_KEYS_LOADED')) return;
define('API_KEYS_LOADED', true);

/** permission => [label, what it allows, area]. The dashboard shows them by area, read and change side by side. */
const API_PERMS = [
    'restaurant:read'   => ['Restaurant details', 'Name, currency, timezone and menu sections', 'Restaurant'],
    'menu:read'         => ['Read menu', 'Dishes, prices, sizes, sections, add-ons', 'Menu'],
    'menu:write'        => ['Change menu', 'Add, edit, hide or delete dishes; photos, sizes, sections, add-ons', 'Menu'],
    'orders:read'       => ['Read orders', 'Orders with dishes, customer name and phone, payment', 'Orders'],
    'orders:write'      => ['Change orders', 'Accept, prepare, mark ready, complete, cancel or reject orders', 'Orders'],
    'reports:read'      => ['Read reports', 'Sales, top dishes, peak hours, payments and other reports', 'Reports'],
    'website:read'      => ['Read website', 'Theme, banners, about, hours, policy pages, SEO', 'Website'],
    'website:write'     => ['Change website', 'Theme and colours, banners, logo, about, hours, policy pages, SEO', 'Website'],
    'offers:read'       => ['Read offers', "Coupons, combos, today's special, scratch card, wallet settings", 'Offers'],
    'offers:write'      => ['Change offers', 'Create, edit, switch or delete coupons, combos and specials; scratch card and wallet settings', 'Offers'],
    'loyalty:read'      => ['Read loyalty', 'Points, cashback or stamps rules, rewards, tiers (no customer data)', 'Loyalty'],
    'loyalty:write'     => ['Change loyalty', 'Change the loyalty programme, rewards and tiers', 'Loyalty'],
    'tables:read'       => ['Read tables', 'Areas, tables with live status, waiter calls', 'Tables'],
    'tables:write'      => ['Change tables', 'Add, edit or delete tables and areas; answer waiter calls', 'Tables'],
    'reservations:read' => ['Read reservations', 'Bookings with guest name and phone', 'Reservations'],
    'reservations:write'=> ['Change reservations', 'Add bookings, confirm, seat, complete, cancel', 'Reservations'],
    'staff:read'        => ['Read staff', 'Staff names, roles, phone, active or paused (never passwords)', 'Staff'],
    'staff:write'       => ['Change staff', 'Add staff, change roles, pause or remove them', 'Staff'],
    'stock:read'        => ['Read stock', 'Stock items, levels, low stock', 'Stock'],
    'stock:write'       => ['Change stock', 'Add items, restock, record wastage, set counts', 'Stock'],
    'delivery:read'     => ['Read delivery zones', 'Delivery pincodes, charges and times', 'Delivery'],
    'delivery:write'    => ['Change delivery zones', 'Add, edit, switch or delete delivery zones', 'Delivery'],
    'catering:read'     => ['Read catering', 'Catering requests and price tiers', 'Catering'],
    'catering:write'    => ['Change catering', 'Confirm, quote or decline requests; price tiers', 'Catering'],
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
const API_KEYS_MAX = 20;               // live keys per restaurant
const API_KEY_MAX_DAYS = 1830;         // "valid until" at most 5 years ahead
const API_KEY_GRACE = [0, 60, 1440];   // rotate: minutes the old key keeps working

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
        perms VARCHAR(1000) NOT NULL,
        allowed_ips VARCHAR(500) NOT NULL DEFAULT '',
        expires_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME NULL,
        use_count INT UNSIGNED NOT NULL DEFAULT 0,
        paused_at DATETIME NULL,
        revoked_at DATETIME NULL,
        rotated_from INT NULL,
        INDEX (restaurant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // the live table was made before these columns existed (2026-10-24)
    $cols = array_column($conn->query('SHOW COLUMNS FROM api_keys')->fetchAll(PDO::FETCH_ASSOC), 'Type', 'Field');
    $have = array_keys($cols);
    // 255 was too short once every area got its own permissions (2026-10-24)
    if (($cols['perms'] ?? '') === 'varchar(255)') $conn->exec('ALTER TABLE api_keys MODIFY perms VARCHAR(1000) NOT NULL');
    $add = ['allowed_ips' => "VARCHAR(500) NOT NULL DEFAULT '' AFTER perms", 'use_count' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_used_at',
        'paused_at' => 'DATETIME NULL AFTER use_count', 'rotated_from' => 'INT NULL AFTER revoked_at'];
    foreach ($add as $col => $def) if (!in_array($col, $have, true)) $conn->exec("ALTER TABLE api_keys ADD COLUMN $col $def");
}

/** Known permissions out of a list, in the API_PERMS order. */
function apiKeyCleanPerms(array $perms): array
{
    return array_values(array_intersect(array_keys(API_PERMS), $perms));
}

/**
 * The settings a key can have, checked: name, perms, expires ('never' or a YYYY-MM-DD date: valid through the end of
 * that day), ips (comma / space separated IPv4 / IPv6, empty = any). Returns the clean values or ['error' => message].
 */
function apiKeyCleanSettings(array $in): array
{
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? ''))), 0, 80);
    if (mb_strlen($name) < 2) return ['error' => 'Give the key a name, e.g. "Claude Code" or "Stock sync".'];
    $perms = apiKeyCleanPerms((array)($in['perms'] ?? []));
    if (!$perms) return ['error' => 'Tick at least one permission.'];
    $exp = trim((string)($in['expires'] ?? 'never'));
    $expiresAt = null;
    if ($exp !== '' && $exp !== 'never') {
        $d = DateTime::createFromFormat('!Y-m-d', $exp);
        if (!$d || $d->format('Y-m-d') !== $exp) return ['error' => 'Pick a valid "valid until" date.'];
        $d->setTime(23, 59, 59);
        if ($d->getTimestamp() < time()) return ['error' => '"Valid until" must be today or later.'];
        if ($d->getTimestamp() > time() + API_KEY_MAX_DAYS * 86400) return ['error' => '"Valid until" can be at most 5 years ahead. Pick "Never" for no end date.'];
        $expiresAt = $d->format('Y-m-d H:i:s');
    }
    $ips = [];
    foreach (preg_split('/[\s,;]+/', trim((string)($in['ips'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return ['error' => '"' . mb_substr($ip, 0, 40) . '" is not an IP address. Use addresses like 203.0.113.7, separated by commas.'];
        $ips[] = $ip;
    }
    $ips = array_values(array_unique($ips));
    if (count($ips) > 20) return ['error' => 'At most 20 allowed IP addresses.'];
    return ['name' => $name, 'perms' => $perms, 'expires_at' => $expiresAt, 'ips' => implode(',', $ips)];
}

function apiKeyLiveCount(PDO $conn, string $rid): int
{
    $st = $conn->prepare('SELECT COUNT(*) FROM api_keys WHERE restaurant_id = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())');
    $st->execute([$rid]);
    return (int)$st->fetchColumn();
}

/** Insert a key row with a fresh secret → [key, id]. */
function apiKeyInsert(PDO $conn, string $rid, int $userId, array $c, ?int $rotatedFrom = null): array
{
    $key = API_KEY_PREFIX . bin2hex(random_bytes(20));
    $conn->prepare('INSERT INTO api_keys (restaurant_id, user_id, name, key_hash, key_hint, perms, allowed_ips, expires_at, rotated_from) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$rid, $userId, $c['name'], hash('sha256', $key), substr($key, 0, 12) . '…' . substr($key, -4), implode(' ', $c['perms']), $c['ips'], $c['expires_at'], $rotatedFrom]);
    return [$key, (int)$conn->lastInsertId()];
}

function apiKeyRow(PDO $conn, string $rid, int $id): ?array
{
    foreach (apiKeyList($conn, $rid) as $r) if ($r['id'] === $id) return $r;
    return null;
}

/** A new key → ['key' => full key (show once), 'row' => listed row] or ['error' => message]. */
function apiKeyCreate(PDO $conn, string $rid, int $userId, array $in): array
{
    apiKeysEnsure($conn);
    $c = apiKeyCleanSettings($in);
    if (isset($c['error'])) return $c;
    if (apiKeyLiveCount($conn, $rid) >= API_KEYS_MAX) return ['error' => 'You already have ' . API_KEYS_MAX . ' keys. Revoke one you no longer use first.'];
    [$key, $id] = apiKeyInsert($conn, $rid, $userId, $c);
    return ['key' => $key, 'row' => apiKeyRow($conn, $rid, $id)];
}

/** A key of this restaurant that is not revoked, raw row, or null. */
function apiKeyOpenRow(PDO $conn, string $rid, int $id): ?array
{
    apiKeysEnsure($conn);
    $st = $conn->prepare('SELECT * FROM api_keys WHERE id = ? AND restaurant_id = ? AND revoked_at IS NULL');
    $st->execute([$id, $rid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Change name, permissions, valid until and allowed IPs. The key itself stays the same. → ['row', 'before'] or ['error']. */
function apiKeyUpdate(PDO $conn, string $rid, int $id, array $in): array
{
    $k = apiKeyOpenRow($conn, $rid, $id);
    if (!$k) return ['error' => 'That key is revoked or gone.'];
    $c = apiKeyCleanSettings($in);
    if (isset($c['error'])) return $c;
    $conn->prepare('UPDATE api_keys SET name = ?, perms = ?, allowed_ips = ?, expires_at = ? WHERE id = ? AND restaurant_id = ?')
        ->execute([$c['name'], implode(' ', $c['perms']), $c['ips'], $c['expires_at'], $id, $rid]);
    return ['row' => apiKeyRow($conn, $rid, $id), 'before' => $k];
}

/**
 * Rotate: a new secret with the same settings (a new row that lists in its place); the old key stops working now or
 * after $graceMin minutes, so code using it can switch over. → ['key', 'row', 'name'] or ['error'].
 */
function apiKeyRotate(PDO $conn, string $rid, int $userId, int $id, int $graceMin): array
{
    $k = apiKeyOpenRow($conn, $rid, $id);
    if (!$k) return ['error' => 'That key is revoked or gone.'];
    if ($k['expires_at'] && strtotime($k['expires_at']) <= time()) return ['error' => 'This key has expired. Edit it to give it a new "valid until" date first, or create a new key.'];
    $graceMin = in_array($graceMin, API_KEY_GRACE, true) ? $graceMin : 0;
    $c = ['name' => $k['name'], 'perms' => explode(' ', $k['perms']), 'ips' => $k['allowed_ips'], 'expires_at' => $k['expires_at']];
    $conn->beginTransaction();
    try {
        [$key, $newId] = apiKeyInsert($conn, $rid, $userId, $c, (int)$k['id']);
        if ($graceMin === 0) {
            $conn->prepare('UPDATE api_keys SET revoked_at = NOW() WHERE id = ? AND restaurant_id = ?')->execute([$id, $rid]);
        } else {
            // the old key ends at the sooner of its own date and the grace period
            $conn->prepare('UPDATE api_keys SET expires_at = LEAST(COALESCE(expires_at, NOW() + INTERVAL ? MINUTE), NOW() + INTERVAL ? MINUTE) WHERE id = ? AND restaurant_id = ?')
                ->execute([$graceMin, $graceMin, $id, $rid]);
        }
        if ($k['paused_at']) $conn->prepare('UPDATE api_keys SET paused_at = ? WHERE id = ?')->execute([$k['paused_at'], $newId]);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    return ['key' => $key, 'row' => apiKeyRow($conn, $rid, $newId), 'name' => $k['name']];
}

/** Pause (refuse every call, keep the key) or resume. → key name or null. */
function apiKeyPause(PDO $conn, string $rid, int $id, bool $pause): ?string
{
    $k = apiKeyOpenRow($conn, $rid, $id);
    if (!$k) return null;
    $conn->prepare('UPDATE api_keys SET paused_at = ' . ($pause ? 'COALESCE(paused_at, NOW())' : 'NULL') . ' WHERE id = ? AND restaurant_id = ?')->execute([$id, $rid]);
    return (string)$k['name'];
}

/** The restaurant's keys (working ones first, then ended ones of the last 90 days), newest first. Never the key itself. */
function apiKeyList(PDO $conn, string $rid): array
{
    apiKeysEnsure($conn);
    $st = $conn->prepare("SELECT id, name, key_hint, perms, allowed_ips, expires_at, created_at, last_used_at, use_count, paused_at, revoked_at, rotated_from FROM api_keys
        WHERE restaurant_id = ? AND (revoked_at IS NULL OR revoked_at > NOW() - INTERVAL 90 DAY) ORDER BY id DESC");
    $st->execute([$rid]);
    $rows = array_map(function ($r) {
        $r['id'] = (int)$r['id'];
        $r['use_count'] = (int)$r['use_count'];
        $r['rotated_from'] = $r['rotated_from'] === null ? null : (int)$r['rotated_from'];
        $r['perms'] = $r['perms'] === '' ? [] : explode(' ', $r['perms']);
        $r['allowed_ips'] = $r['allowed_ips'] === '' ? [] : explode(',', $r['allowed_ips']);
        $r['status'] = $r['revoked_at'] ? 'revoked' : ($r['expires_at'] && strtotime($r['expires_at']) <= time() ? 'expired' : ($r['paused_at'] ? 'paused' : 'active'));
        $r['expires_in_minutes'] = $r['expires_at'] && !$r['revoked_at'] ? (int)floor((strtotime($r['expires_at']) - time()) / 60) : null;
        return $r;
    }, $st->fetchAll(PDO::FETCH_ASSOC));
    // a key that was rotated with a grace period: "old key, stops in …" (the new one names it in rotated_from)
    $replaced = array_flip(array_filter(array_column($rows, 'rotated_from')));
    foreach ($rows as &$r) $r['replaced'] = isset($replaced[$r['id']]);
    unset($r);
    $ended = fn($r) => in_array($r['status'], ['revoked', 'expired'], true);
    usort($rows, fn($a, $b) => [$ended($a), -$a['id']] <=> [$ended($b), -$b['id']]);
    return $rows;
}

function apiKeyRevoke(PDO $conn, string $rid, int $id): ?string
{
    $k = apiKeyOpenRow($conn, $rid, $id);
    if (!$k) return null;
    $conn->prepare('UPDATE api_keys SET revoked_at = NOW() WHERE id = ? AND restaurant_id = ?')->execute([$id, $rid]);
    return (string)$k['name'];
}

/** Remove a revoked or expired key from the list for good. → its name or null (a working key must be revoked first). */
function apiKeyDelete(PDO $conn, string $rid, int $id): ?string
{
    apiKeysEnsure($conn);
    $st = $conn->prepare('SELECT name FROM api_keys WHERE id = ? AND restaurant_id = ? AND (revoked_at IS NOT NULL OR (expires_at IS NOT NULL AND expires_at <= NOW()))');
    $st->execute([$id, $rid]);
    $name = $st->fetchColumn();
    if ($name === false) return null;
    $conn->prepare('DELETE FROM api_keys WHERE id = ? AND restaurant_id = ?')->execute([$id, $rid]);
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

/** The caller's IP (Cloudflare's header first, like the activity log). */
function apiKeyClientIp(): string
{
    return (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * The live key behind a token, or null (unknown, revoked, expired, paused, wrong IP, owner switched off or no longer
 * the owner); $why says which: invalid_key, key_paused or ip_not_allowed. Returns the shape mcp/server.php uses for its
 * tokens: restaurant_id, user_id, scopes (none: perms decide), plus key_id, key_name, perms and expires_at.
 */
function apiKeyVerify(PDO $conn, string $token, ?string &$why = null): ?array
{
    $why = 'invalid_key';
    if (!apiKeyLooksLike($token) || strlen($token) > 100) return null;
    apiKeysEnsure($conn);
    $st = $conn->prepare('SELECT id, restaurant_id, user_id, name, perms, allowed_ips, expires_at, paused_at FROM api_keys
        WHERE key_hash = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())');
    $st->execute([hash('sha256', $token)]);
    $k = $st->fetch(PDO::FETCH_ASSOC);
    if (!$k) return null;
    // compared in PHP: users and api_keys may use different collations
    $u = $conn->prepare('SELECT restaurant_id, is_active FROM users WHERE id = ?');
    $u->execute([(int)$k['user_id']]);
    $owner = $u->fetch(PDO::FETCH_ASSOC);
    if (!$owner || (int)$owner['is_active'] !== 1 || (string)$owner['restaurant_id'] !== (string)$k['restaurant_id']) return null;
    if ($k['paused_at']) { $why = 'key_paused'; return null; }
    if ($k['allowed_ips'] !== '' && !in_array(apiKeyClientIp(), explode(',', $k['allowed_ips']), true)) { $why = 'ip_not_allowed'; return null; }
    $why = '';
    $conn->prepare('UPDATE api_keys SET use_count = use_count + 1, last_used_at = NOW() WHERE id = ?')->execute([$k['id']]);
    return ['restaurant_id' => (string)$k['restaurant_id'], 'user_id' => (int)$k['user_id'], 'client_id' => '', 'scopes' => [],
        'key_id' => (int)$k['id'], 'key_name' => $k['name'], 'perms' => explode(' ', $k['perms']), 'expires_at' => $k['expires_at']];
}

/** How a key shows in the owner's Activity log: API key "Stock sync". */
function apiKeyLabel(array $tok): string
{
    return 'API key "' . $tok['key_name'] . '"';
}
