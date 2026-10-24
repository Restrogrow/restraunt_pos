<?php
/**
 * Brands: one website address (and optionally one payment gateway) for a restaurant group with several outlets.
 *
 * A brand groups outlets (each outlet is still its own restaurant: menu, orders, customers, points, staff). Super Admin
 * creates a brand for a multi-branch owner (branch_admins) and picks its outlets; the owner then manages the domain,
 * the gateway and each outlet's web address from the Branches page.
 *
 * Addresses (website/brand_router.php):
 *   with the brand's own domain:  https://brand.com/              outlet picker
 *                                 https://brand.com/indiranagar/menu
 *   without a domain:             https://restrogrow.com/spice-garden/
 *                                 https://restrogrow.com/spice-garden/indiranagar/menu
 * An outlet's old addresses (restrogrow.com/<name>, main/website/*.php?restaurant_id=) redirect to its brand address.
 *
 * Tables (created on first use, the live DB needs no migration): brands, brand_outlets. An outlet belongs to at most
 * one brand. Restaurants that are in no brand work exactly as before.
 */

if (defined('BRAND_LOADED')) return;
define('BRAND_LOADED', true);

// first path parts that are never a brand or outlet name: website pages, folders and system paths
const BRAND_RESERVED = ['main', 'api', 'mcp', 'go', 'assets', 'uploads', 'website', 'admin', 'superadmin', 'embed', 'oauth', 'well-known',
    'menu', 'cart', 'about', 'contact', 'profile', 'login', 'reset-password', 'track', 'track-order', 'privacy-policy', 'terms-of-service',
    'refund-policy', 'shipping-policy', 'cookie-policy', 'plans', 'subscribe', 'my-subscription', 'catering', 'loyalty', 'reservations',
    'sitemap', 'robots', 'index', 'demo', 'blog', 'faq', 'developers', 'app-demo', 'restaurants', 'outlets', 'choose', 'brand'];

// a website page name in a brand address => its file in main/website
const BRAND_PAGES = ['' => 'index.php', 'menu' => 'menu.php', 'cart' => 'cart.php', 'about' => 'about.php', 'contact' => 'contact.php',
    'profile' => 'profile.php', 'login' => 'login.php', 'reset-password' => 'reset_password.php', 'track' => 'track.php', 'track-order' => 'track.php',
    'privacy-policy' => 'privacy-policy.php', 'terms-of-service' => 'terms-of-service.php', 'refund-policy' => 'refund-policy.php',
    'shipping-policy' => 'shipping-policy.php', 'cookie-policy' => 'cookie-policy.php', 'plans' => 'plans.php', 'subscribe' => 'subscribe.php',
    'my-subscription' => 'my-subscription.php', 'catering' => 'catering.php', 'loyalty' => 'loyalty.php', 'reservations' => 'reservations.php'];

function brandEnsure(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->exec("CREATE TABLE IF NOT EXISTS brands (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        slug VARCHAR(60) NOT NULL UNIQUE,
        branch_admin_id INT NULL,
        default_restaurant_id VARCHAR(10) NULL,
        custom_domain VARCHAR(255) NULL UNIQUE,
        domain_enabled TINYINT(1) NOT NULL DEFAULT 0,
        tagline VARCHAR(160) NULL,
        pay_client_id VARCHAR(255) NULL,
        pay_client_secret TEXT NULL,
        pay_environment VARCHAR(12) NOT NULL DEFAULT 'PRODUCTION',
        ga_id VARCHAR(30) NULL,
        meta_pixel_id VARCHAR(30) NULL,
        gsc_verification VARCHAR(120) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        INDEX (branch_admin_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS brand_outlets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        brand_id INT NOT NULL,
        restaurant_id VARCHAR(10) NOT NULL UNIQUE,
        url_key VARCHAR(40) NOT NULL,
        label VARCHAR(80) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        gateway VARCHAR(10) NOT NULL DEFAULT 'brand',
        is_listed TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY brand_key (brand_id, url_key),
        INDEX (brand_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** A name in address form: "Spice Garden HSR" → "spice-garden-hsr" (same rule as restaurant slugs). */
function brandSlugify(string $s): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
}

/** Same normalising as website/custom-domain.php: lower case, no scheme, no "www.", no trailing slash. */
function brandNormalizeDomain(?string $d): string
{
    $d = strtolower(trim((string)$d));
    $d = preg_replace('#^https?://#', '', $d);
    $d = preg_replace('#/.*$#', '', $d);
    $d = preg_replace('/:\d+$/', '', $d);
    return preg_replace('/^www\./', '', $d);
}

/** restrogrow.com, www, localhost, Hostinger's preview and ngrok are the platform; anything else is someone's own domain. */
function rgIsCustomHost(?string $host = null): bool
{
    $host = strtolower((string)($host ?? ($_SERVER['HTTP_HOST'] ?? '')));
    if (strpos($host, ':') !== false) $host = explode(':', $host)[0];
    return $host !== '' && $host !== 'restrogrow.com' && $host !== 'www.restrogrow.com' && $host !== 'localhost' && $host !== '127.0.0.1'
        && strpos($host, 'hstgr.io') === false && !preg_match('/\.ngrok-free\.(app|dev)$/', $host);
}

/** The folder the site lives in on this host: '' on restrogrow.com, '/menuwebsite' on a local copy. */
function brandSiteRoot(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $p = strpos($script, '/main/');
    return $p === false ? '' : rtrim(substr($script, 0, $p), '/');
}

function brandScheme(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    return $https ? 'https' : 'http';
}

function brandRow(PDO $conn, string $where, array $args): ?array
{
    brandEnsure($conn);
    $st = $conn->prepare("SELECT * FROM brands WHERE $where LIMIT 1");
    $st->execute($args);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) return null;
    $b['id'] = (int)$b['id'];
    $b['domain_live'] = !empty($b['custom_domain']) && (int)$b['domain_enabled'] === 1;
    return $b;
}

function brandGet(PDO $conn, int $id): ?array { return brandRow($conn, 'id = ?', [$id]); }

function brandForOwner(PDO $conn, int $branchAdminId): ?array { return brandRow($conn, 'branch_admin_id = ?', [$branchAdminId]); }

/** An active brand at restrogrow.com/<slug>. */
function brandBySlug(PDO $conn, string $slug): ?array
{
    if (!preg_match('/^[a-z0-9-]{3,60}$/', $slug)) return null;
    return brandRow($conn, 'slug = ? AND is_active = 1', [$slug]);
}

/** The active brand whose own domain is this host (www. or not). */
function brandByHost(PDO $conn, string $host): ?array
{
    $h = brandNormalizeDomain($host);
    if ($h === '' || !rgIsCustomHost($h)) return null;
    return brandRow($conn, 'custom_domain = ? AND domain_enabled = 1 AND is_active = 1', [$h]);
}

/**
 * The brand's outlets in picker order, each with what the picker and the owner page show (name, address, location,
 * logo, opening hours, open now). $listedOnly leaves out outlets hidden from the picker; outlets switched off by
 * Super Admin (users.is_active = 0) never show.
 */
function brandOutlets(PDO $conn, int $brandId, bool $listedOnly = false): array
{
    brandEnsure($conn);
    $st = $conn->prepare('SELECT * FROM brand_outlets WHERE brand_id = ?' . ($listedOnly ? ' AND is_listed = 1' : '') . ' ORDER BY sort_order, id');
    $st->execute([$brandId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    // users read separately: brand_outlets and users may use different collations
    $ids = array_column($rows, 'restaurant_id');
    $u = $conn->prepare('SELECT id, restaurant_id, restaurant_name, address, phone, restaurant_lat, restaurant_lng, restaurant_logo, opening_hours, timezone, is_active, payment_gateway_type
        FROM users WHERE restaurant_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $u->execute($ids);
    $users = [];
    foreach ($u->fetchAll(PDO::FETCH_ASSOC) as $r) $users[(string)$r['restaurant_id']] = $r;
    $out = [];
    foreach ($rows as $o) {
        $r = $users[(string)$o['restaurant_id']] ?? null;
        if (!$r || (int)$r['is_active'] !== 1) continue;
        $o['id'] = (int)$o['id'];
        $o['brand_id'] = (int)$o['brand_id'];
        $o['sort_order'] = (int)$o['sort_order'];
        $o['is_listed'] = (int)$o['is_listed'] === 1;
        $o['restaurant_name'] = $r['restaurant_name'];
        $o['address'] = $r['address'];
        $o['phone'] = $r['phone'];
        $o['lat'] = $r['restaurant_lat'] !== null && $r['restaurant_lat'] !== '' ? (float)$r['restaurant_lat'] : null;
        $o['lng'] = $r['restaurant_lng'] !== null && $r['restaurant_lng'] !== '' ? (float)$r['restaurant_lng'] : null;
        $o['user_id'] = (int)$r['id'];
        $o['has_logo'] = !empty($r['restaurant_logo']);
        $o['open_now'] = brandOpenNow($r);
        $o['online_payments'] = ($r['payment_gateway_type'] ?? 'cash_only') !== 'cash_only';   // Super Admin switches online payments per outlet
        $out[] = $o;
    }
    return $out;
}

/** Open right now by its opening hours (no hours saved = open). */
function brandOpenNow(array $userRow): bool
{
    $hours = json_decode((string)($userRow['opening_hours'] ?? ''), true);
    if (!is_array($hours) || !$hours) return true;
    require_once __DIR__ . '/opening_hours.php';
    try { $tz = new DateTimeZone((string)($userRow['timezone'] ?? '') ?: 'Asia/Kolkata'); } catch (Throwable $e) { $tz = new DateTimeZone('Asia/Kolkata'); }
    try { return rgIsOpenNow($hours, $tz); } catch (Throwable $e) { return true; }
}

/** ['brand' => …, 'outlet' => …] for a restaurant that is an outlet of an active brand, else null. Cached per request. */
function brandOfRestaurant(PDO $conn, ?string $rid): ?array
{
    static $cache = [];
    $rid = (string)$rid;
    if ($rid === '') return null;
    if (array_key_exists($rid, $cache)) return $cache[$rid];
    try {
        brandEnsure($conn);
        $st = $conn->prepare('SELECT * FROM brand_outlets WHERE restaurant_id = ? LIMIT 1');
        $st->execute([$rid]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        $b = $o ? brandGet($conn, (int)$o['brand_id']) : null;
        return $cache[$rid] = ($o && $b && (int)$b['is_active'] === 1) ? ['brand' => $b, 'outlet' => $o] : null;
    } catch (Throwable $e) {
        error_log('brandOfRestaurant: ' . $e->getMessage());
        return $cache[$rid] = null;
    }
}

/**
 * Where a brand's site starts: https://brand.com (own domain), else <site root>/<slug> on the platform.
 * $absolute: a full https:// address for emails, WhatsApp and QR codes (APP_URL for the platform).
 */
function brandBaseUrl(array $brand, bool $absolute = false): string
{
    if (!empty($brand['domain_live'])) return ($absolute || !rgIsCustomHost() ? 'https://' : brandScheme() . '://') . $brand['custom_domain'];
    if ($absolute) {
        $app = rtrim((string)(function_exists('env') ? env('APP_URL', '') : '') ?: 'https://restrogrow.com', '/');
        return $app . '/' . $brand['slug'];
    }
    return brandSiteRoot() . '/' . $brand['slug'];
}

/** An outlet page's address: <base>/<url_key>[/<page>]. On the brand's own domain the address is relative (/indiranagar/menu). */
function brandOutletUrl(array $brand, array $outlet, string $page = '', bool $absolute = false): string
{
    $tail = '/' . $outlet['url_key'] . ($page !== '' ? '/' . ltrim($page, '/') : '');
    if (!$absolute && !empty($brand['domain_live']) && brandByHostIsThis($brand)) return brandSiteRoot() . $tail;   // '' on the live server
    return brandBaseUrl($brand, $absolute) . $tail;
}

/** The picker's address (brand.com/ or restrogrow.com/<slug>/). */
function brandPickerUrl(array $brand, bool $absolute = false): string
{
    if (!$absolute && !empty($brand['domain_live']) && brandByHostIsThis($brand)) return brandSiteRoot() . '/';
    return brandBaseUrl($brand, $absolute) . '/';
}

/** Is this request on the brand's own domain? */
function brandByHostIsThis(array $brand): bool
{
    return !empty($brand['custom_domain']) && brandNormalizeDomain($_SERVER['HTTP_HOST'] ?? '') === $brand['custom_domain'];
}

/**
 * A restaurant's public page address when it is a brand outlet (else null, and callers keep their own logic).
 * Used by the website's link builders, emails, WhatsApp links, QR codes, canonical links and the sitemap.
 */
function brandRestaurantUrl(PDO $conn, ?string $rid, string $page = '', bool $absolute = false): ?string
{
    $bo = brandOfRestaurant($conn, $rid);
    return $bo ? brandOutletUrl($bo['brand'], $bo['outlet'], $page, $absolute) : null;
}

/** Why a brand address part can't be used, or ''. $kind 'brand' (restrogrow.com/<slug>) or 'outlet' (…/<url_key>). */
function brandKeyProblem(PDO $conn, string $key, string $kind, int $brandId = 0): string
{
    brandEnsure($conn);
    $min = $kind === 'brand' ? 3 : 2;
    $max = $kind === 'brand' ? 60 : 40;
    if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $key) || strlen($key) < $min || strlen($key) > $max) return "Use {$min}–{$max} small letters, numbers and dashes, e.g. " . ($kind === 'brand' ? 'spice-garden' : 'indiranagar') . '.';
    if (in_array($key, BRAND_RESERVED, true)) return "\"$key\" is a word the website already uses. Pick another.";
    if ($kind === 'brand') {
        $root = dirname(__DIR__, 2);
        if (is_dir($root . '/' . $key) || is_file($root . '/' . $key . '.html')) return "\"$key\" is already a page of restrogrow.com. Pick another.";
        $dup = $conn->prepare('SELECT id FROM brands WHERE slug = ? AND id <> ?');
        $dup->execute([$key, $brandId]);
        if ($dup->fetchColumn()) return "Another brand already uses \"$key\".";
        // restrogrow.com/<slug> must not already be a restaurant's address
        $tokens = explode('-', $key);
        $st = $conn->prepare("SELECT restaurant_name FROM users WHERE restaurant_name LIKE ?");
        $st->execute(['%' . implode('%', $tokens) . '%']);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) if (brandSlugify((string)$n) === $key) return "restrogrow.com/$key is already the address of the restaurant \"$n\". Pick another, e.g. $key-group.";
    }
    return '';
}

/** Brand row for the Super Admin and owner screens, with outlets. */
function brandOut(PDO $conn, array $b): array
{
    $outlets = brandOutlets($conn, (int)$b['id']);
    return ['id' => (int)$b['id'], 'name' => $b['name'], 'slug' => $b['slug'], 'tagline' => $b['tagline'], 'branch_admin_id' => $b['branch_admin_id'] !== null ? (int)$b['branch_admin_id'] : null,
        'default_restaurant_id' => $b['default_restaurant_id'], 'custom_domain' => $b['custom_domain'], 'domain_enabled' => (int)$b['domain_enabled'] === 1,
        'is_active' => (int)$b['is_active'] === 1, 'has_gateway' => !empty($b['pay_client_id']) && !empty($b['pay_client_secret']), 'pay_environment' => $b['pay_environment'],
        'ga_id' => $b['ga_id'], 'meta_pixel_id' => $b['meta_pixel_id'], 'gsc_verification' => $b['gsc_verification'],
        'address' => brandPickerUrl($b, true),
        'outlets' => array_map(fn($o) => ['restaurant_id' => $o['restaurant_id'], 'restaurant_name' => $o['restaurant_name'], 'url_key' => $o['url_key'], 'label' => $o['label'],
            'sort_order' => $o['sort_order'], 'gateway' => $o['gateway'], 'is_listed' => $o['is_listed'], 'address' => brandOutletUrl($b, $o, '', true)], $outlets)];
}

/**
 * Super Admin: create or change a brand. $in: id?, name, slug, branch_admin_id, is_active, outlets [{restaurant_id, url_key?, label?}]
 * (only outlets of that owner). Outlets taken out of the list leave the brand. → brand id, or throws InvalidArgumentException.
 */
function brandSaveAdmin(PDO $conn, array $in): int
{
    brandEnsure($conn);
    $id = (int)($in['id'] ?? 0);
    $name = trim(mb_substr(strip_tags((string)($in['name'] ?? '')), 0, 120));
    if (mb_strlen($name) < 2) throw new InvalidArgumentException('Give the brand a name, e.g. Spice Garden.');
    $slug = brandSlugify((string)($in['slug'] ?? '') ?: $name);
    if ($p = brandKeyProblem($conn, $slug, 'brand', $id)) throw new InvalidArgumentException('Brand address: ' . $p);
    $owner = (int)($in['branch_admin_id'] ?? 0);
    $st = $conn->prepare('SELECT id FROM branch_admins WHERE id = ?');
    $st->execute([$owner]);
    if (!$st->fetchColumn()) throw new InvalidArgumentException('Pick the multi-branch owner this brand belongs to.');
    $other = $conn->prepare('SELECT id FROM brands WHERE branch_admin_id = ? AND id <> ?');
    $other->execute([$owner, $id]);
    if ($other->fetchColumn()) throw new InvalidArgumentException('This owner already has a brand. Edit that one.');
    $links = $conn->prepare('SELECT restaurant_id, label FROM branch_restaurant_links WHERE branch_admin_id = ?');
    $links->execute([$owner]);
    $mine = [];
    foreach ($links->fetchAll(PDO::FETCH_ASSOC) as $l) $mine[(string)$l['restaurant_id']] = (string)($l['label'] ?? '');
    $outlets = [];
    $keys = [];
    foreach ((array)($in['outlets'] ?? []) as $o) {
        $rid = (string)($o['restaurant_id'] ?? '');
        if (!isset($mine[$rid])) throw new InvalidArgumentException("Outlet $rid is not one of this owner's branches.");
        $label = trim(mb_substr(strip_tags((string)($o['label'] ?? '')), 0, 80));
        if ($label === '') $label = $mine[$rid] ?: brandOutletName($conn, $rid, $name);
        $key = brandSlugify((string)($o['url_key'] ?? '') ?: $label);
        if ($p = brandKeyProblem($conn, $key, 'outlet')) throw new InvalidArgumentException("Outlet $label: $p");
        if (isset($keys[$key])) throw new InvalidArgumentException("Two outlets would both be at /$key. Give each its own address.");
        $keys[$key] = true;
        $taken = $conn->prepare('SELECT brand_id FROM brand_outlets WHERE restaurant_id = ? AND brand_id <> ?');
        $taken->execute([$rid, $id]);
        if ($taken->fetchColumn()) throw new InvalidArgumentException("$label is already an outlet of another brand.");
        $outlets[] = [$rid, $key, $label];
    }
    if (count($outlets) < 1) throw new InvalidArgumentException('Pick at least one outlet.');
    // an outlet's own custom domain would fight with the brand address
    $rids = array_column($outlets, 0);
    $cd = $conn->prepare("SELECT restaurant_name FROM users WHERE restaurant_id IN (" . implode(',', array_fill(0, count($rids), '?')) . ") AND embed_enabled = 1 AND custom_domain IS NOT NULL AND custom_domain <> ''");
    $cd->execute($rids);
    if ($n = $cd->fetchColumn()) throw new InvalidArgumentException("$n has its own website domain switched on. Switch it off first (that outlet › Settings › Domain), then add it to the brand.");
    $default = in_array((string)($in['default_restaurant_id'] ?? ''), $rids, true) ? (string)$in['default_restaurant_id'] : $rids[0];
    $active = array_key_exists('is_active', $in) ? (!empty($in['is_active']) ? 1 : 0) : 1;

    $conn->beginTransaction();
    try {
        if ($id) {
            $conn->prepare('UPDATE brands SET name = ?, slug = ?, branch_admin_id = ?, default_restaurant_id = ?, is_active = ?, updated_at = NOW() WHERE id = ?')->execute([$name, $slug, $owner, $default, $active, $id]);
        } else {
            $conn->prepare('INSERT INTO brands (name, slug, branch_admin_id, default_restaurant_id, is_active) VALUES (?, ?, ?, ?, ?)')->execute([$name, $slug, $owner, $default, $active]);
            $id = (int)$conn->lastInsertId();
        }
        $cur = $conn->prepare('SELECT restaurant_id, gateway, is_listed FROM brand_outlets WHERE brand_id = ?');
        $cur->execute([$id]);
        $old = [];
        foreach ($cur->fetchAll(PDO::FETCH_ASSOC) as $r) $old[(string)$r['restaurant_id']] = $r;
        $conn->prepare('DELETE FROM brand_outlets WHERE brand_id = ?')->execute([$id]);
        $ins = $conn->prepare('INSERT INTO brand_outlets (brand_id, restaurant_id, url_key, label, sort_order, gateway, is_listed) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($outlets as $i => [$rid, $key, $label]) {
            $ins->execute([$id, $rid, $key, $label, $i, $old[$rid]['gateway'] ?? 'brand', isset($old[$rid]) ? (int)$old[$rid]['is_listed'] : 1]);
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    return $id;
}

/** "Indiranagar" from "Spice Garden Indiranagar" (the brand name taken off the front), else the full name. */
function brandOutletName(PDO $conn, string $rid, string $brandName): string
{
    $st = $conn->prepare('SELECT restaurant_name FROM users WHERE restaurant_id = ? LIMIT 1');
    $st->execute([$rid]);
    $n = trim((string)$st->fetchColumn());
    $rest = trim(preg_replace('/^' . preg_quote($brandName, '/') . '\b[\s,–-]*/i', '', $n));
    return $rest !== '' ? $rest : ($n ?: $rid);
}

/**
 * Owner (Branches page): change the outlets' web addresses, names, order, picker visibility and gateway choice.
 * $list: [{restaurant_id, url_key, label, is_listed, gateway}] in picker order (only this brand's outlets).
 */
function brandSaveOutletsOwner(PDO $conn, array $brand, array $list): void
{
    $outlets = [];
    foreach (brandOutlets($conn, (int)$brand['id']) as $o) $outlets[(string)$o['restaurant_id']] = $o;
    $keys = [];
    $rows = [];
    foreach ($list as $i => $o) {
        $rid = (string)($o['restaurant_id'] ?? '');
        if (!isset($outlets[$rid])) throw new InvalidArgumentException('That outlet is not part of this brand.');
        $label = trim(mb_substr(strip_tags((string)($o['label'] ?? $outlets[$rid]['label'])), 0, 80));
        if ($label === '') throw new InvalidArgumentException('Every outlet needs a name, e.g. Indiranagar.');
        $key = brandSlugify((string)($o['url_key'] ?? $outlets[$rid]['url_key']));
        if ($p = brandKeyProblem($conn, $key, 'outlet')) throw new InvalidArgumentException("$label: $p");
        if (isset($keys[$key])) throw new InvalidArgumentException("Two outlets would both be at /$key.");
        $keys[$key] = true;
        $gw = in_array($o['gateway'] ?? '', ['brand', 'own'], true) ? $o['gateway'] : $outlets[$rid]['gateway'];
        $rows[] = [$key, $label, $i, $gw, array_key_exists('is_listed', $o) ? (!empty($o['is_listed']) ? 1 : 0) : ((int)$outlets[$rid]['is_listed']), (int)$brand['id'], $rid];
    }
    if (count($rows) !== count($outlets)) throw new InvalidArgumentException('Send every outlet of the brand.');
    if (!array_filter($rows, fn($r) => $r[4] === 1)) throw new InvalidArgumentException('At least one outlet must show on the website.');
    $conn->beginTransaction();
    try {
        $tmp = $conn->prepare('UPDATE brand_outlets SET url_key = CONCAT("tmp-", id) WHERE brand_id = ?');   // so keys can swap places
        $tmp->execute([(int)$brand['id']]);
        $up = $conn->prepare('UPDATE brand_outlets SET url_key = ?, label = ?, sort_order = ?, gateway = ?, is_listed = ? WHERE brand_id = ? AND restaurant_id = ?');
        foreach ($rows as $r) $up->execute($r);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
}

/** The brand's own PhonePe keys, opened: ['id', 'secret', 'env'], or null when not set (outlets then use their own setting). */
function brandGatewayKeys(array $brand): ?array
{
    if (empty($brand['pay_client_id']) || empty($brand['pay_client_secret'])) return null;
    require_once __DIR__ . '/phonepe_verify.php';
    $secret = ppSecretOpen((string)$brand['pay_client_secret']);
    if ($secret === '') return null;
    return ['id' => (string)$brand['pay_client_id'], 'secret' => $secret, 'env' => strtoupper((string)$brand['pay_environment']) === 'SANDBOX' ? 'SANDBOX' : 'PRODUCTION'];
}

/**
 * Owner: save (or clear, with empty client id) the brand's PhonePe keys. The secret is kept encrypted like a
 * restaurant's (ppSecretSeal); leaving it empty on a change keeps the saved one.
 */
function brandSaveGateway(PDO $conn, array $brand, string $clientId, string $secret, string $env): void
{
    require_once __DIR__ . '/phonepe_verify.php';
    $clientId = trim($clientId);
    $env = strtoupper($env) === 'SANDBOX' ? 'SANDBOX' : 'PRODUCTION';
    if ($clientId === '') {
        $conn->prepare('UPDATE brands SET pay_client_id = NULL, pay_client_secret = NULL, updated_at = NOW() WHERE id = ?')->execute([(int)$brand['id']]);
        return;
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{4,120}$/', $clientId)) throw new InvalidArgumentException('That Client ID does not look right. Copy it from the PhonePe Business dashboard › Developer settings.');
    $secret = trim($secret);
    if ($secret === '' && empty($brand['pay_client_secret'])) throw new InvalidArgumentException('Paste the Client Secret too.');
    if ($secret !== '' && ppSecretKey() === null) throw new InvalidArgumentException('Payment keys can’t be saved safely on this server yet (PAYMENT_KEY_SECRET is missing). Ask RestroGrow support.');
    $sealed = $secret !== '' ? ppSecretSeal($secret) : (string)$brand['pay_client_secret'];
    $conn->prepare('UPDATE brands SET pay_client_id = ?, pay_client_secret = ?, pay_environment = ?, updated_at = NOW() WHERE id = ?')->execute([$clientId, $sealed, $env, (int)$brand['id']]);
}
