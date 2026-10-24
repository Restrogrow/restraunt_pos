<?php
/**
 * Brand addresses → what to serve (config/brand.php). Used by custom-domain.php (the brand's own domain, and
 * restrogrow.com/<brand>/<outlet>/…) and header.php (restrogrow.com/<brand> and restrogrow.com/<brand>/<page>,
 * which the root .htaccess sends straight to a page file).
 *
 * brandResolve() only decides; the caller includes the page file at the top level of its script, because the
 * website pages and header.php share global variables.
 *
 *   ''                       → the outlet picker
 *   <outlet>[/<page>]        → that outlet's page (restaurant_id set, RG_BRAND_ROUTED defined)
 *   <page> (an old flat link) → the remembered outlet's page, else the picker
 */
require_once __DIR__ . '/../config/brand.php';

const BRAND_COOKIE_DAYS = 365;

function brandCookieName(array $brand): string { return 'rg_outlet_' . (int)$brand['id']; }

/** The outlet this visitor picked last time, if it is still in the brand. */
function brandRememberedOutlet(PDO $conn, array $brand): ?array
{
    $key = (string)($_COOKIE[brandCookieName($brand)] ?? '');
    if ($key === '') return null;
    foreach (brandOutlets($conn, (int)$brand['id']) as $o) if ($o['url_key'] === $key) return $o;
    return null;
}

function brandRemember(array $brand, array $outlet): void
{
    if (headers_sent() || ($_COOKIE[brandCookieName($brand)] ?? '') === $outlet['url_key']) return;
    setcookie(brandCookieName($brand), $outlet['url_key'], ['expires' => time() + BRAND_COOKIE_DAYS * 86400, 'path' => '/', 'samesite' => 'Lax', 'secure' => brandScheme() === 'https']);
}

/** The query string to carry over (?table=T3, ?order_id=…), without the parameters that only named the restaurant. */
function brandKeepQuery(): string
{
    $q = $_GET;
    unset($q['restaurant_id'], $q['restaurant'], $q['page'], $q['brand_path']);
    return $q ? '?' . http_build_query($q) : '';
}

/**
 * $rest: the address after the brand's base ('' / 'indiranagar' / 'indiranagar/menu' / 'menu').
 * → ['type' => 'picker'] | ['type' => 'file', 'file' => …, 'outlet' => …] | ['type' => 'redirect', 'url' => …] | ['type' => 'notfound']
 */
function brandResolve(PDO $conn, array $brand, string $rest): array
{
    $parts = array_values(array_filter(explode('/', trim($rest, '/')), 'strlen'));
    if (!$parts) return ['type' => 'picker'];
    $first = strtolower($parts[0]);
    foreach (brandOutlets($conn, (int)$brand['id']) as $o) {
        if ($o['url_key'] !== $first) continue;
        $page = strtolower($parts[1] ?? '');
        if (count($parts) > 2 || !isset(BRAND_PAGES[$page])) return ['type' => 'notfound'];
        return ['type' => 'file', 'file' => __DIR__ . '/' . BRAND_PAGES[$page], 'outlet' => $o];
    }
    // an old flat link (brand.com/menu, a bookmarked /cart): the outlet they used last, else let them pick
    if (count($parts) === 1 && isset(BRAND_PAGES[$first])) {
        $o = brandRememberedOutlet($conn, $brand);
        if ($o) return ['type' => 'redirect', 'url' => brandOutletUrl($brand, $o, $first) . brandKeepQuery()];
        return ['type' => 'picker', 'next' => $first];
    }
    return ['type' => 'notfound'];
}

/** Get ready to serve an outlet page: the page files read the restaurant from $_GET['restaurant_id']. */
function brandPrepareOutlet(array $brand, array $outlet): void
{
    $_GET['restaurant_id'] = $outlet['restaurant_id'];
    unset($_GET['restaurant']);
    if (!defined('RG_BRAND_ROUTED')) define('RG_BRAND_ROUTED', true);
    brandRemember($brand, $outlet);
}

/** A brand's robots.txt on its own domain. */
function brandRobots(array $brand): void
{
    header('Content-Type: text/plain; charset=UTF-8');
    echo "User-agent: *\nAllow: /\nDisallow: /*/cart\nDisallow: /*/profile\nDisallow: /*/login\nDisallow: /*/reset-password\nDisallow: /*/track\nDisallow: /main/api/\n\n"
        . 'Sitemap: ' . brandBaseUrl($brand, true) . "/sitemap.xml\n";
}

/** A brand's sitemap: the picker and each listed outlet's public pages. */
function brandSitemap(PDO $conn, array $brand): void
{
    header('Content-Type: application/xml; charset=UTF-8');
    $urls = [brandPickerUrl($brand, true)];
    foreach (brandOutlets($conn, (int)$brand['id'], true) as $o) {
        foreach (['', 'menu', 'about', 'contact'] as $p) $urls[] = brandOutletUrl($brand, $o, $p, true);
    }
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $u) echo '  <url><loc>' . htmlspecialchars($u, ENT_XML1) . "</loc></url>\n";
    echo "</urlset>\n";
}

/** The slim bar on top of an outlet's pages: "Ordering from Indiranagar · Change outlet" (only when there is a choice). */
function brandSwitcherBar(array $ctx): string
{
    static $count = [];
    $bid = (int)$ctx['brand']['id'];
    if (!isset($count[$bid])) $count[$bid] = count(brandOutlets(getConnection(), $bid, true));
    if ($count[$bid] < 2) return '';
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    return '<div class="rg-brandbar" style="display:flex;justify-content:center;align-items:center;gap:8px;flex-wrap:wrap;padding:6px 12px;background:#111827;color:#fff;font:500 13px/1.4 Poppins,system-ui,sans-serif;position:relative;z-index:1000">'
        . '<span>📍 Ordering from <b>' . $h($ctx['outlet']['label']) . '</b></span>'
        . '<a href="' . $h(brandPickerUrl($ctx['brand'])) . '" style="color:#fff;text-decoration:underline;opacity:.9">Change outlet</a></div>';
}
