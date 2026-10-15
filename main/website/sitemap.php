<?php
/**
 * Sitemaps for restaurant websites (config/seo_helpers.php, dashboard › SEO).
 *
 *   /<slug>/sitemap.xml          one restaurant: home, menu, about, contact (+ reservations), with the logo
 *                                and dish photos as image entries so they show in Google Images
 *   <custom domain>/sitemap.xml  the same, via custom-domain.php
 *   /sitemap-restaurants.xml     index of every live restaurant on restrogrow.com (listed in robots.txt);
 *                                restaurants on their own domain are left out, their domain has its own
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/seo_helpers.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
$x = function ($v) { return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
$slugOf = function ($name) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$name)), '-'); };
$host = $_SERVER['HTTP_HOST'] ?? 'restrogrow.com';
$scheme = preg_match('/^(localhost|127\.0\.0\.1)/', $host) ? 'http' : 'https';
$root = rtrim(str_replace('\\', '/', dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/main/website/sitemap.php')))), '/');

try { $conn = getConnection(); } catch (Throwable $e) { http_response_code(503); exit('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>'); }
seoEnsure($conn);
$live = "restaurant_name IS NOT NULL AND restaurant_name <> '' AND COALESCE(is_active, 1) = 1 AND COALESCE(subscription_status, '') NOT IN ('expired', 'disabled')";

// index of all restaurants
if (isset($_GET['index'])) {
    $rows = $conn->query("SELECT u.restaurant_id, u.restaurant_name, MAX(u.updated_at) AS updated_at, MAX(COALESCE(s.noindex, 0)) AS noindex,
            MAX(CASE WHEN u.custom_domain <> '' AND u.embed_enabled = 1 THEN 1 ELSE 0 END) AS own_domain
        FROM users u LEFT JOIN seo_settings s ON s.restaurant_id = u.restaurant_id WHERE $live GROUP BY u.restaurant_id, u.restaurant_name")->fetchAll(PDO::FETCH_ASSOC);
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    $seen = [];
    foreach ($rows as $r) {
        $slug = $slugOf($r['restaurant_name']);
        if ($slug === '' || isset($seen[$slug]) || $r['noindex'] || $r['own_domain']) continue;
        $seen[$slug] = true;
        echo '<sitemap><loc>' . $x("$scheme://$host$root/$slug/sitemap.xml") . '</loc>' . ($r['updated_at'] ? '<lastmod>' . date('Y-m-d', strtotime($r['updated_at'])) . '</lastmod>' : '') . "</sitemap>\n";
    }
    echo '</sitemapindex>';
    exit;
}

// one restaurant: by id (custom domain sets it) or by slug
$r = null;
if (!empty($_GET['restaurant_id'])) {
    $st = $conn->prepare("SELECT * FROM users WHERE restaurant_id = ? AND $live ORDER BY id LIMIT 1");
    $st->execute([(string)$_GET['restaurant_id']]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} elseif (!empty($_GET['restaurant'])) {
    $want = strtolower(trim((string)$_GET['restaurant']));
    foreach ($conn->query("SELECT * FROM users WHERE $live ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($slugOf($row['restaurant_name']) === $want) { $r = $row; break; }
    }
}
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
if (!$r) { http_response_code(404); echo '</urlset>'; exit; }
$seo = seoGet($conn, $r['restaurant_id']);
if (!empty($seo['noindex'])) { echo '</urlset>'; exit; }

$onCustomHost = !preg_match('/^(localhost|127\.0\.0\.1|(www\.)?restrogrow\.com|.*hstgr\.io|.*\.ngrok-free\.(app|dev))(:\d+)?$/i', $host);
if ($onCustomHost) $base = "$scheme://$host/";
elseif (!empty($r['custom_domain']) && !empty($r['embed_enabled'])) $base = 'https://' . preg_replace('#^https?://|/+$#', '', trim($r['custom_domain'])) . '/';
else $base = "$scheme://$host$root/" . $slugOf($r['restaurant_name']) . '/';
$img = function ($rel) use ($scheme, $host, $root) { return "$scheme://$host$root/main/" . ltrim($rel, '/'); };

$last = date('Y-m-d', strtotime(max((string)$r['updated_at'], (string)($seo['updated_at'] ?? ''))) ?: time());
$logo = null;
if (!empty($r['logo_data'])) $logo = $img('website/image.php?type=logo&id=' . urlencode($r['id']));
elseif (!empty($r['restaurant_logo'])) $logo = preg_match('#^https?://#', $r['restaurant_logo']) ? $r['restaurant_logo'] : $img(ltrim($r['restaurant_logo'], '/'));

// dish photos for the menu page
$dishes = [];
try {
    $st = $conn->prepare("SELECT item_name_en, item_image FROM menu_items WHERE restaurant_id = ? AND is_available = 1 AND COALESCE(is_hidden, 0) = 0 AND COALESCE(item_image, '') <> '' ORDER BY sort_order, id LIMIT 900");
    $st->execute([$r['restaurant_id']]);
    $dishes = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
$maxItem = $conn->prepare('SELECT MAX(updated_at) FROM menu_items WHERE restaurant_id = ?');
$maxItem->execute([$r['restaurant_id']]);
$menuLast = ($m = $maxItem->fetchColumn()) ? date('Y-m-d', strtotime($m)) : $last;

$pages = ['' => ['1.0', 'weekly', $last], 'menu' => ['0.9', 'daily', $menuLast], 'about' => ['0.6', 'monthly', $last], 'contact' => ['0.7', 'monthly', $last]];
if (!empty($r['enable_reservations'])) $pages['reservations'] = ['0.6', 'monthly', $last];
foreach ($pages as $p => [$prio, $freq, $mod]) {
    echo '<url><loc>' . $x($p === "" && substr_count($base, "/") > 3 ? rtrim($base, "/") : $base . $p) . '</loc><lastmod>' . $mod . '</lastmod><changefreq>' . $freq . '</changefreq><priority>' . $prio . '</priority>';
    if ($p === '' && $logo) echo '<image:image><image:loc>' . $x($logo) . '</image:loc></image:image>';
    if ($p === 'menu') {
        foreach ($dishes as $d) {
            $path = (string)$d['item_image'];
            if ($path === '') continue;
            $u = preg_match('#^https?://#', $path) ? $path : $img('website/image.php?id=' . rawurlencode(strpos($path, 'db:') === 0 ? substr($path, 3) : $path));
            echo '<image:image><image:loc>' . $x($u) . '</image:loc></image:image>';
        }
    }
    echo "</url>\n";
}
echo '</urlset>';
