<?php
/**
 * SEO for restaurant websites (dashboard › SEO, assets/js/seo-studio.js, api/seo.php).
 *
 * Per restaurant: a title and description for each page (home, menu, about, contact), a share image,
 * focus keywords, cuisine and price range, a Google Search Console verification code, a Google
 * Analytics ID and a Meta Pixel ID. Empty fields fall back to defaults written from the restaurant's
 * own details, so every site gets a proper title and description from day one.
 *
 * The website pages call seoTitle() and seoHeadTags() (website/header.php has loaded the restaurant):
 * description, canonical link, Open Graph / Twitter share preview, verification, analytics, and
 * schema.org JSON-LD (Restaurant with address, map point, hours, cuisine, price range, rating and,
 * on the menu page, the menu with prices). website/sitemap.php lists the pages for Google.
 */

const SEO_PAGES = ['home' => 'Home', 'menu' => 'Menu', 'about' => 'About', 'contact' => 'Contact'];

function seoEnsure(PDO $conn): void
{
    static $done = false;
    if ($done || $conn->inTransaction()) return;
    $done = true;
    require_once __DIR__ . '/schema_cache.php';
    if (rgHasTable($conn, 'seo_settings')) return;   // remembered: no CREATE TABLE on every page
    $conn->exec("CREATE TABLE IF NOT EXISTS seo_settings (
        restaurant_id VARCHAR(50) PRIMARY KEY,
        pages_json TEXT DEFAULT NULL,
        keywords VARCHAR(300) DEFAULT NULL,
        cuisine VARCHAR(120) DEFAULT NULL,
        price_range VARCHAR(10) DEFAULT NULL,
        share_image VARCHAR(255) DEFAULT NULL,
        gsc_verification VARCHAR(120) DEFAULT NULL,
        ga_id VARCHAR(30) DEFAULT NULL,
        meta_pixel_id VARCHAR(30) DEFAULT NULL,
        noindex TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** The saved settings (pages decoded), or empty ones. */
function seoGet(PDO $conn, string $rid): array
{
    $row = [];
    try {
        seoEnsure($conn);
        $st = $conn->prepare('SELECT * FROM seo_settings WHERE restaurant_id = ?');
        $st->execute([$rid]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $row = []; }
    $pages = json_decode((string)($row['pages_json'] ?? ''), true);
    $row['pages'] = is_array($pages) ? $pages : [];
    unset($row['pages_json']);
    return $row;
}

/** The town from an address: the part before the PIN / state, e.g. "…, Koramangala, Bengaluru" -> Bengaluru. */
function seoCity(?string $address): string
{
    $parts = array_values(array_filter(array_map('trim', explode(',', (string)$address)), function ($p) {
        return $p !== '' && !preg_match('/^\d{5,6}$/', $p) && !preg_match('/^(india|karnataka|maharashtra|tamil nadu|kerala|telangana|delhi|gujarat|rajasthan|west bengal|uttar pradesh|punjab|haryana|goa)$/i', preg_replace('/\s*\d{5,6}$/', '', $p));
    }));
    $last = $parts ? preg_replace('/\s*\d{5,6}$/', '', end($parts)) : '';
    return trim((string)$last);
}
function seoArea(?string $address): string
{
    $parts = array_values(array_filter(array_map('trim', explode(',', (string)$address))));
    return count($parts) >= 2 ? preg_replace('/\s*\d{5,6}$/', '', $parts[count($parts) - 2]) : '';
}

/** Default title / description per page, written from the restaurant's details. */
function seoDefaults(array $r, array $s = []): array
{
    $name = trim((string)($r['name'] ?? 'Our restaurant'));
    $city = seoCity($r['address'] ?? ''); $area = seoArea($r['address'] ?? '');
    $where = trim($area && $city && strcasecmp($area, $city) ? "$area, $city" : ($city ?: $area));
    $cuisine = trim(explode(',', (string)($s['cuisine'] ?? ''))[0]);
    $food = $cuisine !== '' ? $cuisine . ' restaurant' : 'restaurant';
    $about = trim(preg_replace('/\s+/', ' ', strip_tags((string)($r['description'] ?? ''))));
    $cut = function (string $t, int $n) { return mb_strlen($t) <= $n ? $t : rtrim(preg_replace('/\s+\S*$/u', '', mb_substr($t, 0, $n - 1)), " ,.;:—-") . '…'; };
    // the first title that fits, so nothing gets cut mid-word
    $fit = function (array $opts, int $n) use ($cut) { foreach ($opts as $o) { $o = trim(preg_replace('/\s+in\s*$/', '', $o)); if ($o !== '' && mb_strlen($o) <= $n) return $o; } return $cut(end($opts), $n); };
    return [
        'home' => ['title' => $fit(["$name | $food in $where", "$name | $food in $city", "$name | " . ucfirst($food) . " in $area", "$name | $food", $name], 60),
            'description' => $cut(($about !== '' ? $about . ' ' : "$name is a $food" . ($where ? " in $where" : '') . '. ') . 'See the menu, order online for delivery or takeaway, or book a table.', 158)],
        'menu' => ['title' => $cut("Menu & prices | $name" . ($city ? ", $city" : ''), 60),
            'description' => $cut("Browse the full menu of $name" . ($where ? " in $where" : '') . " with prices and photos. Order online for delivery or takeaway.", 158)],
        'about' => ['title' => $cut("About us | $name", 60),
            'description' => $cut($about !== '' ? $about : "The story of $name" . ($where ? ", a $food in $where" : '') . ': our food, our kitchen and our team.', 158)],
        'contact' => ['title' => $cut("Contact & location | $name", 60),
            'description' => $cut("Find $name" . ($r['address'] ? ' at ' . $r['address'] : '') . ($r['phone'] ? '. Call ' . $r['phone'] : '') . '. Opening hours, map and directions.', 158)],
    ];
}

/** Absolute URL of a site page: the restaurant's own domain when it has one, else restrogrow.com/<slug>. */
function seoPageUrl(string $page): string
{
    global $custom_domain, $embed_enabled, $is_custom_domain, $rg_brand_ctx;
    $slug = $page === 'home' ? '' : $page;
    // an outlet of a brand: brand.com/<outlet>/<page> or restrogrow.com/<brand>/<outlet>/<page> (config/brand.php)
    if (!empty($rg_brand_ctx)) return brandOutletUrl($rg_brand_ctx['brand'], $rg_brand_ctx['outlet'], $slug, true);
    $host = $_SERVER['HTTP_HOST'] ?? 'restrogrow.com';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || !preg_match('/^(localhost|127\.0\.0\.1)/', $host) ? 'https' : 'http';
    if (!empty($custom_domain) && !empty($embed_enabled)) return 'https://' . preg_replace('#^https?://|/+$#', '', $custom_domain) . '/' . $slug;
    if (!empty($is_custom_domain)) return $scheme . '://' . $host . '/' . $slug;
    $path = function_exists('restaurantPageUrl') ? restaurantPageUrl($slug) : '/';
    if (preg_match('#^https?://#', $path)) return $path;
    if ($path === '' || $path[0] !== '/') $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/' . $path;
    return $scheme . '://' . $host . $path;
}
function seoAbs(?string $u): ?string
{
    if (!$u || strpos($u, 'data:') === 0) return null;
    if (preg_match('#^https?://#', $u)) return $u;
    $host = $_SERVER['HTTP_HOST'] ?? 'restrogrow.com';
    $scheme = preg_match('/^(localhost|127\.0\.0\.1)/', $host) ? 'http' : 'https';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/main/website/index.php'), '/');
    return $scheme . '://' . $host . ($u[0] === '/' ? $u : $base . '/' . $u);
}

/** Absolute URL of a file under main/ (e.g. uploads/seo/x.jpg), from a script in main/<folder>/. */
function seoMainUrl(string $rel): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'restrogrow.com';
    $scheme = preg_match('/^(localhost|127\.0\.0\.1)/', $host) ? 'http' : 'https';
    $main = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/main/website/index.php'))), '/');
    return $scheme . '://' . $host . $main . '/' . ltrim($rel, '/');
}

/** The settings + restaurant for this request (website pages; header.php sets the globals). */
function seoContext(): array
{
    static $ctx = null;
    if ($ctx !== null) return $ctx;
    global $restaurant_id, $restaurant_name, $restaurant_address, $restaurant_phone, $restaurant_description, $restaurant_logo, $opening_hours,
        $restaurant_lat, $restaurant_lng, $google_maps_link, $instagram_link, $facebook_link, $twitter_link, $youtube_link, $currency_symbol;
    $r = ['id' => (string)$restaurant_id, 'name' => (string)$restaurant_name, 'address' => (string)$restaurant_address, 'phone' => (string)$restaurant_phone,
        'description' => (string)$restaurant_description, 'logo' => $restaurant_logo, 'hours' => $opening_hours, 'lat' => $restaurant_lat, 'lng' => $restaurant_lng,
        'maps' => $google_maps_link, 'social' => array_values(array_filter([$instagram_link, $facebook_link, $twitter_link, $youtube_link])), 'currency' => $currency_symbol];
    $s = [];
    $conn = null;
    try { $conn = function_exists('getConnection') ? getConnection() : ($GLOBALS['pdo'] ?? null); if ($conn && $r['id']) $s = seoGet($conn, $r['id']); } catch (Throwable $e) { $s = []; }
    return $ctx = ['r' => $r, 's' => $s, 'd' => seoDefaults($r, $s), 'conn' => $conn];
}

function seoTitle(string $page, string $fallback = ''): string
{
    $c = seoContext();
    $t = trim((string)($c['s']['pages'][$page]['title'] ?? ''));
    return $t !== '' ? $t : ($c['d'][$page]['title'] ?? $fallback);
}

/** Opening hours JSON (monday: {open, opening "12:00 PM", closing}) -> schema.org openingHoursSpecification. */
function seoHoursSpec($json): array
{
    $days = is_array($json) ? $json : json_decode((string)$json, true);
    if (!is_array($days)) return [];
    $out = [];
    foreach ($days as $day => $h) {
        if (!is_array($h) || empty($h['open']) || empty($h['opening']) || empty($h['closing'])) continue;
        $o = date('H:i', strtotime($h['opening'])); $cl = date('H:i', strtotime($h['closing']));
        $out[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ucfirst(strtolower($day)), 'opens' => $o, 'closes' => $cl === '23:59' ? '23:59' : $cl];
    }
    return $out;
}

/**
 * Restaurant pages Google shouldn't list even when the owner hasn't ticked "hide": the demo
 * restaurant, test-style accounts and sites with almost no menu yet. Real restaurants rank
 * normally; this keeps empty and test pages from showing up under a "restrogrow" search.
 */
function seoAutoHidden(?PDO $conn, string $rid, string $name): bool
{
    static $cache = [];
    if (isset($cache[$rid])) return $cache[$rid];
    if (in_array($rid, ['RESSGI002', 'RESDEMO01'], true)) return $cache[$rid] = true;
    if (preg_match('/(test|testing|demo|dummy|sample)|^test\d*$/i', $name)) return $cache[$rid] = true;
    if (!$conn) return $cache[$rid] = false;
    try {
        $st = $conn->prepare('SELECT COUNT(*) FROM menu_items WHERE restaurant_id = ? AND is_available = 1 AND COALESCE(is_hidden, 0) = 0');
        $st->execute([$rid]);
        return $cache[$rid] = (int)$st->fetchColumn() < 5;
    } catch (Throwable $e) {
        return $cache[$rid] = false;
    }
}

/** Everything for <head> after <title>. $page: home | menu | about | contact. */
function seoHeadTags(string $page): string
{
    $c = seoContext(); $r = $c['r']; $s = $c['s'];
    // an outlet of a brand: the brand's Analytics / Pixel / Search Console codes when the outlet has none (config/brand.php)
    global $rg_brand_ctx;
    if (!empty($rg_brand_ctx)) foreach (['ga_id', 'meta_pixel_id', 'gsc_verification'] as $k) if (empty($s[$k]) && !empty($rg_brand_ctx['brand'][$k])) $s[$k] = $rg_brand_ctx['brand'][$k];
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $desc = trim((string)($s['pages'][$page]['description'] ?? '')) ?: ($c['d'][$page]['description'] ?? '');
    $title = seoTitle($page, $r['name']);
    $url = seoPageUrl($page);
    $img = !empty($s['share_image']) ? seoMainUrl($s['share_image']) : seoAbs($r['logo']);
    $h = [];
    $h[] = '<meta name="description" content="' . $e($desc) . '">';
    if (!empty($s['keywords'])) $h[] = '<meta name="keywords" content="' . $e($s['keywords']) . '">';
    $hidden = !empty($s['noindex']) || seoAutoHidden($c['conn'], $r['id'], $r['name']);
    $h[] = $hidden ? '<meta name="robots" content="noindex, nofollow">' : '<meta name="robots" content="index, follow, max-image-preview:large">';
    $h[] = '<link rel="canonical" href="' . $e($url) . '">';
    // share preview: WhatsApp, Facebook, Instagram, X, LinkedIn
    $h[] = '<meta property="og:type" content="' . ($page === 'home' ? 'restaurant.restaurant' : 'website') . '">';
    $h[] = '<meta property="og:site_name" content="' . $e($r['name']) . '">';
    $h[] = '<meta property="og:title" content="' . $e($title) . '">';
    $h[] = '<meta property="og:description" content="' . $e($desc) . '">';
    $h[] = '<meta property="og:url" content="' . $e($url) . '">';
    $h[] = '<meta property="og:locale" content="en_IN">';
    if ($img) { $h[] = '<meta property="og:image" content="' . $e($img) . '">'; $h[] = '<meta name="twitter:image" content="' . $e($img) . '">'; }
    $h[] = '<meta name="twitter:card" content="' . ($img ? 'summary_large_image' : 'summary') . '">';
    $h[] = '<meta name="twitter:title" content="' . $e($title) . '">';
    $h[] = '<meta name="twitter:description" content="' . $e($desc) . '">';
    if (!empty($s['gsc_verification'])) $h[] = '<meta name="google-site-verification" content="' . $e($s['gsc_verification']) . '">';

    // structured data
    $ld = ['@context' => 'https://schema.org', '@type' => 'Restaurant', '@id' => seoPageUrl('home') . '#restaurant', 'name' => $r['name'], 'url' => seoPageUrl('home')];
    $homeDesc = trim((string)($s['pages']['home']['description'] ?? '')) ?: ($c['d']['home']['description'] ?? '');
    if ($homeDesc) $ld['description'] = $homeDesc;
    if ($img) $ld['image'] = $img;
    if ($r['logo'] && ($lg = seoAbs($r['logo']))) $ld['logo'] = $lg;
    if ($r['phone']) $ld['telephone'] = $r['phone'];
    if ($r['address']) {
        $ld['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $r['address'], 'addressCountry' => 'IN'];
        if ($city = seoCity($r['address'])) $ld['address']['addressLocality'] = $city;
        if (preg_match('/\b(\d{6})\b/', $r['address'], $m)) $ld['address']['postalCode'] = $m[1];
    }
    if ($r['lat'] && $r['lng']) $ld['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float)$r['lat'], 'longitude' => (float)$r['lng']];
    if ($r['maps']) $ld['hasMap'] = $r['maps'];
    if (!empty($s['cuisine'])) $ld['servesCuisine'] = array_values(array_filter(array_map('trim', explode(',', $s['cuisine']))));
    if (!empty($s['price_range'])) $ld['priceRange'] = $s['price_range'];
    if ($hrs = seoHoursSpec($r['hours'])) $ld['openingHoursSpecification'] = $hrs;
    if ($r['social']) $ld['sameAs'] = $r['social'];
    $ld['hasMenu'] = seoPageUrl('menu');
    $ld['acceptsReservations'] = 'True';
    // Google rating from Google Business (Places), when the restaurant has found itself there
    if ($c['conn']) {
        try {
            $g = $c['conn']->prepare('SELECT rating, rating_count FROM gbp_connections WHERE restaurant_id = ?');
            $g->execute([$r['id']]);
            $gr = $g->fetch(PDO::FETCH_ASSOC);
            if ($gr && $gr['rating'] && $gr['rating_count']) $ld['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => round((float)$gr['rating'], 1), 'reviewCount' => (int)$gr['rating_count'], 'bestRating' => 5];
        } catch (Throwable $e2) {}
    }
    // the menu with prices on the menu page
    if ($page === 'menu' && $c['conn']) {
        try {
            $mq = $c['conn']->prepare("SELECT c.menu_name, m.item_name_en, m.item_description_en, m.base_price FROM menu_items m JOIN menu c ON c.id = m.menu_id
                WHERE m.restaurant_id = ? AND m.is_available = 1 AND COALESCE(m.is_hidden, 0) = 0 ORDER BY c.sort_order, c.id, m.sort_order, m.id LIMIT 300");
            $mq->execute([$r['id']]);
            $sections = [];
            foreach ($mq->fetchAll(PDO::FETCH_ASSOC) as $it) {
                $item = ['@type' => 'MenuItem', 'name' => $it['item_name_en'], 'offers' => ['@type' => 'Offer', 'price' => number_format((float)$it['base_price'], 2, '.', ''), 'priceCurrency' => 'INR']];
                $d2 = trim(strip_tags((string)$it['item_description_en']));
                if ($d2 !== '') $item['description'] = mb_substr($d2, 0, 200);
                $sections[$it['menu_name']][] = $item;
            }
            if ($sections) {
                $ld['hasMenu'] = ['@type' => 'Menu', 'url' => seoPageUrl('menu'), 'hasMenuSection' => array_map(function ($n, $items) { return ['@type' => 'MenuSection', 'name' => $n, 'hasMenuItem' => $items]; }, array_keys($sections), $sections)];
            }
        } catch (Throwable $e3) {}
    }
    $h[] = '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>';

    // analytics (only well-formed IDs get printed)
    if (!empty($s['ga_id']) && preg_match('/^G-[A-Z0-9]{4,15}$/', $s['ga_id'])) {
        $h[] = '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $e($s['ga_id']) . '"></script>';
        $h[] = "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . $e($s['ga_id']) . "');</script>";
    }
    if (!empty($s['meta_pixel_id']) && preg_match('/^\d{8,20}$/', $s['meta_pixel_id'])) {
        $h[] = "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','" . $e($s['meta_pixel_id']) . "');fbq('track','PageView');</script>";
    }
    return implode("\n", $h) . "\n";
}
