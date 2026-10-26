<?php
ob_start();

// Always checked with the server (menu, prices and stock stay live), but not "no-store": that also turned off
// the browser's instant Back / Forward. A logged-in customer's pages stay no-store (see below), so their
// details are never kept after they log out.
header('Cache-Control: private, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../config/countries.php';
require_once __DIR__ . '/../config/website_theme_helpers.php';
startSecureSession(true);

function createRestaurantSlug($name) {
    $slug = strtolower($name);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

/**
 * A data: URI photo as a file URL: decoded once into main/uploads/cache/bg (WebP, max 1600 px wide, or the
 * original bytes when GD can't read it), named by its hash so a new photo gets a new URL and the old one can be
 * cached for a year. '' when it can't be written (the caller then keeps the data: URI).
 */
function websiteInlineImageUrl(string $dataUri): string {
    if (!preg_match('#^data:image/(jpeg|jpg|png|webp|gif);base64,#i', $dataUri, $m)) return '';
    $hash = substr(sha1($dataUri), 0, 20);
    $dir = __DIR__ . '/../uploads/cache/bg';
    $url = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/main/website/index.php'))), '/') . '/uploads/cache/bg/';
    foreach (['webp', strtolower($m[1]) === 'jpg' ? 'jpeg' : strtolower($m[1])] as $ext) {
        if (is_file("$dir/$hash.$ext")) return $url . "$hash.$ext";
    }
    $raw = base64_decode(substr($dataUri, strlen($m[0])), true);
    if ($raw === false || $raw === '') return '';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return '';
    $src = function_exists('imagewebp') ? @imagecreatefromstring($raw) : false;
    if ($src) {
        $w = imagesx($src); $h = imagesy($src);
        if ($w > 1600) {
            $dst = imagecreatetruecolor(1600, max(1, (int)round($h * 1600 / $w)));
            imagealphablending($dst, false); imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, 1600, imagesy($dst), $w, $h);
            imagedestroy($src); $src = $dst;
        }
        $ok = @imagewebp($src, "$dir/$hash.webp.tmp", 80);
        imagedestroy($src);
        if ($ok && @rename("$dir/$hash.webp.tmp", "$dir/$hash.webp")) return $url . "$hash.webp";
        @unlink("$dir/$hash.webp.tmp");
    }
    $ext = strtolower($m[1]) === 'jpg' ? 'jpeg' : strtolower($m[1]);
    if (@file_put_contents("$dir/$hash.$ext.tmp", $raw) !== false && @rename("$dir/$hash.$ext.tmp", "$dir/$hash.$ext")) return $url . "$hash.$ext";
    return '';
}

/** The website page being served: '' (home), 'menu', 'cart' … (from the page file, also when custom-domain.php
 *  included it); null when header.php runs for something else (manifest.php, a payment script). */
function rgCurrentPageName(): ?string {
    require_once __DIR__ . '/../config/brand.php';
    foreach (get_included_files() as $f) {
        $k = array_search(basename($f), BRAND_PAGES, true);
        if ($k !== false) return (string)$k;
    }
    return null;
}

function findRestaurantBySlug($conn, $slug) {
    // A slug is the name's lowercase a-z0-9 runs joined by "-", so the
    // matching restaurant's name must contain those runs in order. Narrow the
    // candidates with LIKE '%run1%run2%' (a superset of the true matches)
    // instead of loading every restaurant on every page view; the exact
    // createRestaurantSlug() comparison below still decides. Falls back to the
    // full scan if the narrowed search finds nothing.
    $tokens = array_values(array_filter(explode('-', (string)$slug), 'strlen'));
    $narrow = $tokens && !preg_grep('/[^a-z0-9]/', $tokens);
    foreach ($narrow ? [true, false] : [false] as $useLike) {
        // Prefer users with menu entries, then most recently created
        $stmt = $conn->prepare("
            SELECT u.restaurant_id, u.restaurant_name,
                   (SELECT COUNT(*) FROM menu m WHERE m.restaurant_id = u.restaurant_id AND m.is_active = 1) AS cnt
            FROM users u
            WHERE u.restaurant_name IS NOT NULL AND u.restaurant_name != ''" .
            ($useLike ? " AND u.restaurant_name LIKE ?" : "") . "
            ORDER BY cnt DESC, u.id DESC
        ");
        $stmt->execute($useLike ? ['%' . implode('%', $tokens) . '%'] : []);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $restaurant) {
            if (createRestaurantSlug($restaurant['restaurant_name']) === $slug) {
                return [
                    'restaurant_id' => $restaurant['restaurant_id'],
                    'restaurant_name' => $restaurant['restaurant_name']
                ];
            }
        }
    }
    return null;
}

$restaurant_id = null;
$restaurant_id_param = isset($_GET['restaurant_id']) && is_string($_GET['restaurant_id']) ? trim($_GET['restaurant_id']) : '';
$restaurant_slug = isset($_GET['restaurant']) && is_string($_GET['restaurant']) ? trim($_GET['restaurant']) : '';
$has_id_param = $restaurant_id_param !== '';
$has_slug_param = $restaurant_slug !== '';

if ($has_id_param) {
    $restaurant_id = $restaurant_id_param;
    // The dashboard shares this session: never move a signed-in owner/staff
    // member's session onto the restaurant whose website they're looking at
    if (empty($_SESSION['user_id']) && empty($_SESSION['staff_id']) && empty($_SESSION['branch_admin_id'])) {
        $_SESSION['restaurant_id'] = $restaurant_id;
    }
} elseif ($has_slug_param) {
} elseif (isset($_SESSION['restaurant_id']) && $_SESSION['restaurant_id'] !== '') {
    $restaurant_id = $_SESSION['restaurant_id'];
}

// Table from a QR code scan (?table=T4): resolved further down, once the restaurant is known
$qr_table = null;

// Handle referral code from a shared loyalty link (?ref=CODE), persisted
// through navigation the same way qr_table is, so it survives from landing
// page through to the signup form.
$referral_code = isset($_GET['ref']) && trim($_GET['ref']) !== '' ? trim($_GET['ref']) : (isset($_SESSION['referral_code']) ? $_SESSION['referral_code'] : null);
if ($referral_code) {
    $_SESSION['referral_code'] = $referral_code;
}

$currency_symbol = '₹';
$country = 'India';
$tax_name = 'GST';
$tax_percent = 5.00;
$restaurant_name = 'Restaurant';
// Local SVG placeholder as data URI (avoids external via.placeholder.com which often fails)
$local_placeholder_svg = 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="192" height="192" viewBox="0 0 192 192"><rect width="100%" height="100%" fill="#f0f0f0"/><text x="50%" y="50%" font-family="Arial,sans-serif" font-size="16" fill="#999" text-anchor="middle" dy=".3em">Logo</text></svg>');
$local_favicon_svg = 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32"><rect width="100%" height="100%" fill="#e17055" rx="4"/><text x="50%" y="50%" font-family="Arial,sans-serif" font-size="14" fill="#fff" text-anchor="middle" dy=".3em" font-weight="bold">R</text></svg>');
$restaurant_logo = null;
$restaurant_address = null;
$restaurant_description = null;
$restaurant_description_format = 'paragraph';
$restaurant_phone = null;
$restaurant_email = null;
$opening_hours = null;
$restaurant_open = true;
$minimum_order_value = 0;
$packaging_charge = 0;
$payment_gateway_type = 'cash_only';
$background_theme = 'https://plain-apac-prod-public.komododecks.com/202607/06/B1IA4E4QcKAeMAwsaYkq/image.png';
$show_install_app = false;
$delivery_radius_km = 0;
$restaurant_lat = null;
$restaurant_lng = null;
$enable_km_delivery = 0;
$delivery_rate_per_km = 0;
$instagram_link = null;
$facebook_link = null;
$twitter_link = null;
$youtube_link = null;
$linkedin_link = null;
$enable_delivery = 1;
$enable_takeaway = 1;
$enable_dinein = 1;
$cod_enabled = 1;
$navIcons = NAV_ICON_STYLES['classic'];
$navLabels = DEFAULT_NAV_LABELS;
$navIconOverrides = [];
$favicon_url_override = null;

if ($has_slug_param && !$has_id_param) {
    try {
        require_once __DIR__ . '/db_config.php';
        if (function_exists('getConnection')) {
            $conn = getConnection();
            // restrogrow.com/<brand> (or /<brand>/menu): a brand's outlet picker (website/brand_router.php)
            require_once __DIR__ . '/brand_router.php';
            if ($rgSlugBrand = brandBySlug($conn, $restaurant_slug)) {
                ob_end_clean();
                $rgPage = rgCurrentPageName() ?? '';
                if ($rgSlugBrand['domain_live']) { header('Location: ' . brandBaseUrl($rgSlugBrand, true) . '/' . $rgPage . brandKeepQuery(), true, 301); exit(); }
                $rgRoute = brandResolve($conn, $rgSlugBrand, $rgPage);
                if ($rgRoute['type'] === 'redirect') { header('Location: ' . $rgRoute['url'], true, 302); exit(); }
                $brand = $rgSlugBrand;
                $brandNext = $rgRoute['next'] ?? '';
                include __DIR__ . '/brand_picker.php';
                exit();
            }
            $restaurant_info = findRestaurantBySlug($conn, $restaurant_slug);
            if ($restaurant_info) {
                $restaurant_id = $restaurant_info['restaurant_id'];
                $restaurant_name = $restaurant_info['restaurant_name'];
            } else {
                ob_end_clean();
                http_response_code(404);
                include __DIR__ . '/404.php';
                exit();
            }
        }
    } catch (Exception $e) {
    }
}

if (!$restaurant_id) {
    $domain = $_SERVER['HTTP_HOST'];
    if (strpos($domain, ':') !== false) $domain = explode(':', $domain)[0];
    try {
        require_once __DIR__ . '/db_config.php';
        if (function_exists('getConnection')) {
            $conn = getConnection();
            $stmt = $conn->prepare("SELECT restaurant_id FROM users WHERE custom_domain = ? AND embed_enabled = 1 LIMIT 1");
            $stmt->execute([$domain]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $restaurant_id = $row['restaurant_id'];
                $_GET['restaurant_id'] = $restaurant_id;
            }
        }
    } catch (Exception $e) {}
}

if ($restaurant_id) {
    try {
        require_once __DIR__ . '/db_config.php';
        if (function_exists('getConnection')) {
            $conn = getConnection();
            try {
$stmt = $conn->prepare("SELECT id, restaurant_name, restaurant_logo, currency_symbol, country, tax_name, tax_percent, timezone, address, description, description_format, phone, email, opening_hours, minimum_order_value, packaging_charge, enable_gst, payment_gateway_type, show_install_app, google_maps_link, owner_name, instagram_link, facebook_link, twitter_link, youtube_link, linkedin_link, enable_delivery, enable_takeaway, enable_dinein, cod_enabled, custom_domain, embed_enabled, delivery_radius_km, restaurant_lat, restaurant_lng, enable_km_delivery, delivery_rate_per_km, subscription_status FROM users WHERE restaurant_id = ? LIMIT 1");
                $stmt->execute([$restaurant_id]);
            } catch (Exception $e2) {
                // Fallback: a newer column (delivery_radius_km, etc.) is missing on this database - ask
                // only for the columns it has, so one missing column doesn't also drop the tax rate,
                // country or COD setting (the cart then showed 5% GST while the order was billed the real rate)
                $hdrCols = ['id', 'restaurant_name', 'restaurant_logo', 'currency_symbol', 'country', 'tax_name', 'tax_percent', 'timezone', 'address', 'description', 'description_format', 'phone', 'email', 'opening_hours', 'minimum_order_value', 'packaging_charge', 'enable_gst', 'payment_gateway_type', 'show_install_app', 'google_maps_link', 'owner_name', 'instagram_link', 'facebook_link', 'twitter_link', 'youtube_link', 'linkedin_link', 'enable_delivery', 'enable_takeaway', 'enable_dinein', 'cod_enabled', 'custom_domain', 'embed_enabled', 'delivery_radius_km', 'restaurant_lat', 'restaurant_lng', 'enable_km_delivery', 'delivery_rate_per_km', 'subscription_status'];
                $hdrHave = [];
                foreach ($conn->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) as $hc) $hdrHave[$hc['Field']] = true;
                $hdrSel = array_map(function ($c) use ($hdrHave) { return isset($hdrHave[$c]) ? $c : "NULL AS $c"; }, $hdrCols);
                $stmt = $conn->prepare("SELECT " . implode(', ', $hdrSel) . " FROM users WHERE restaurant_id = ? LIMIT 1");
                $stmt->execute([$restaurant_id]);
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                // Restaurant's subscription has lapsed - block the customer-facing
                // ordering site (admin dashboard still works so they can renew).
                if (in_array($row['subscription_status'] ?? '', ['expired', 'disabled'], true)) {
                    $restaurant_name = $row['restaurant_name'] ?? $restaurant_name;
                    ob_end_clean();
                    http_response_code(503);
                    include __DIR__ . '/unavailable.php';
                    exit();
                }
                $restaurant_name = $row['restaurant_name'] ?? $restaurant_name;
                if ($row['currency_symbol'] && $row['currency_symbol'] !== '') {
                    $currency_symbol = $row['currency_symbol'];
                }
                if (!empty($row['country'])) {
                    $country = $row['country'];
                }
                if (!empty($row['tax_name'])) {
                    $tax_name = $row['tax_name'];
                }
                if (isset($row['tax_percent']) && $row['tax_percent'] !== null) {
                    $tax_percent = (float)$row['tax_percent'];
                }
                if (!empty($row['timezone'])) {
                    require_once __DIR__ . '/../config/timezone_utils.php';
                    applyRestaurantTimezone($row['timezone'], $conn);
                }
                $restaurant_address = $row['address'] ?? null;
                $restaurant_description = $row['description'] ?? null;
                $restaurant_description_format = $row['description_format'] ?? 'paragraph';
                $restaurant_phone = $row['phone'] ?? null;
                $restaurant_email = $row['email'] ?? null;
                $opening_hours = $row['opening_hours'] ?? null;
                $google_maps_link = $row['google_maps_link'] ?? null;
                $owner_name = $row['owner_name'] ?? null;
                $instagram_link = $row['instagram_link'] ?? null;
                $facebook_link = $row['facebook_link'] ?? null;
                $twitter_link = $row['twitter_link'] ?? null;
                $youtube_link = $row['youtube_link'] ?? null;
                $linkedin_link = $row['linkedin_link'] ?? null;
                $enable_delivery = isset($row['enable_delivery']) ? (int)$row['enable_delivery'] : 1;
                $enable_takeaway = isset($row['enable_takeaway']) ? (int)$row['enable_takeaway'] : 1;
                $enable_dinein = isset($row['enable_dinein']) ? (int)$row['enable_dinein'] : 1;
                $minimum_order_value = (float)($row['minimum_order_value'] ?? 0);
                $packaging_charge = (float)($row['packaging_charge'] ?? 0);
                $enable_gst = isset($row['enable_gst']) ? (int)$row['enable_gst'] : 1;
                $payment_gateway_type = $row['payment_gateway_type'] ?? 'cash_only';
                // PhonePe charges Indian rupees: customers of a restaurant outside India never see it
                require_once __DIR__ . '/../config/countries.php';
                if (!restaurantIsIndia($row['country'] ?? '')) $payment_gateway_type = 'cash_only';
                $show_install_app = !empty($row['show_install_app']);
                $custom_domain = $row['custom_domain'] ?? '';
                $embed_enabled = !empty($row['embed_enabled']);
                // New columns - silently fall back to defaults if they don't exist
                if (isset($row['cod_enabled'])) {
                    $cod_enabled = (int)$row['cod_enabled'];
                }
                if (isset($row['delivery_radius_km'])) {
                    $delivery_radius_km = (float)($row['delivery_radius_km'] ?? 0);
                }
                if (isset($row['restaurant_lat'])) {
                    $restaurant_lat = $row['restaurant_lat'] !== '' && $row['restaurant_lat'] !== null ? (float)$row['restaurant_lat'] : null;
                }
                if (isset($row['restaurant_lng'])) {
                    $restaurant_lng = $row['restaurant_lng'] !== '' && $row['restaurant_lng'] !== null ? (float)$row['restaurant_lng'] : null;
                }
                if (isset($row['enable_km_delivery'])) {
                    $enable_km_delivery = (int)$row['enable_km_delivery'];
                }
                if (isset($row['delivery_rate_per_km'])) {
                    $delivery_rate_per_km = (float)($row['delivery_rate_per_km'] ?? 0);
                }
                if ($row['restaurant_logo']) {
                    $logo = $row['restaurant_logo'];
                    if (strpos($logo, 'http') === 0) {
                        $restaurant_logo = $logo;
                    } elseif (strpos($logo, 'db:') === 0) {
                        $restaurant_logo = 'image.php?type=logo&id=' . urlencode($row['id']);
                    } elseif ($logo) {
                        $restaurant_logo = 'image.php?type=file&path=' . urlencode('../' . $logo);
                    }
                } elseif ($row['id']) {
                    // No restaurant_logo set, try BLOB logo_data
                    $restaurant_logo = 'image.php?type=logo&id=' . urlencode($row['id']);
                }
            } elseif ($has_id_param) {
                // ?restaurant_id= names no restaurant: real 404, same as an unknown slug
                ob_end_clean();
                http_response_code(404);
                include __DIR__ . '/404.php';
                exit();
            }
        }
    } catch (Exception $e) {
    }
    }
    // the logo as the pages show it (~36-120 px): a 256 px WebP of an uploaded logo instead of the full file
    // (image.php &w=). The full $restaurant_logo stays for the home-screen icon and link previews.
    $restaurant_logo_img = (isset($restaurant_logo) && is_string($restaurant_logo) && strpos($restaurant_logo, 'image.php?type=logo&id=') === 0)
        ? $restaurant_logo . '&w=256' : ($restaurant_logo ?? null);
    // Load website appearance settings (background, logo, brand colors, font) in one query
    // so they can be rendered server-side and applied before first paint (no flash of default colors).
    // Unconditional (even if $restaurant_id never resolved) - every website
    // page references these in its <style> block, so leaving them undefined
    // when no restaurant matches breaks CSS output (empty --primary-red: ;
    // etc.) and floods error_logs with "Undefined variable" warnings across
    // the whole customer site, not just this page.
    $logo_shape = 'circle';
    $logo_size = 90;
    $primary_red = '#F70000';
    $dark_red = '#DA020E';
    $primary_yellow = '#FFD100';
    $font_family = 'Poppins';
    $card_style = 'rounded';
    $layout_style = 'grid';
    $header_style = 'hero';
    $menu_nav_position = 'left';
    $menu_card_style = 'classic';
    $home_card_style = 'classic';
    $profile_style = 'midnight';
    $nav_bar_style = 'classic';
    $profile_opts = cleanProfileOpts([]);
    $category_heading_bg = null;
    $category_pic_style = 'round';
    $all_category_icon = null;
    $site_name_override = null;
    $nav_icon_style = 'classic';
    $nav_labels_json = null;
    $favicon_url_override = null;
    $bestseller_style = getBestsellerStyle(null);
    if ($restaurant_id) {
        try {
            require_once __DIR__ . '/../config/schema_cache.php';
            rgEnsureOnce('ensureWebsiteThemeSchema', $conn);   // once per deploy, not on every page
            require_once __DIR__ . '/../config/website_indexes.php';
            rgEnsureOnce('ensureWebsiteIndexes', $conn);       // missing database indexes, added once
            $stmt = $conn->prepare('SELECT background_theme, logo_shape, logo_size, primary_red, dark_red, primary_yellow, font_family, card_style, checkout_color, layout_style, header_style, site_name, nav_icon_style, nav_labels, favicon_url, nav_icons_custom, bestseller_style, menu_nav_position, menu_card_style, home_card_style, profile_style, profile_opts, nav_bar_style, category_heading_bg, category_pic_style, all_category_icon FROM website_settings WHERE restaurant_id = :rid');
            $stmt->execute([':rid' => $restaurant_id]);
            $themeRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($themeRow) {
                if (!empty($themeRow['background_theme'])) {
                    // Only use DB value if it's a valid URL (starts with http), not an invalid placeholder
                    $dbBg = trim($themeRow['background_theme']);
                    if (strpos($dbBg, 'http://') === 0 || strpos($dbBg, 'https://') === 0) {
                        $background_theme = $dbBg;
                    } elseif (strpos($dbBg, 'data:') === 0) {
                        // a photo saved inline (data: URI, often 500 KB+) went into the page 3-4 times on every
                        // visit: serve it as a cached file instead, so the page stays small and the photo is cached
                        $background_theme = websiteInlineImageUrl($dbBg) ?: $dbBg;
                    }
                }
                $logo_shape = $themeRow['logo_shape'] ?? 'circle';
                $logo_size = (int)($themeRow['logo_size'] ?? 90);
                if (!empty($themeRow['primary_red'])) $primary_red = $themeRow['primary_red'];
                if (!empty($themeRow['dark_red'])) $dark_red = $themeRow['dark_red'];
                if (!empty($themeRow['primary_yellow'])) $primary_yellow = $themeRow['primary_yellow'];
                if (!empty($themeRow['font_family'])) $font_family = $themeRow['font_family'];
                if (in_array($themeRow['card_style'] ?? '', THEME_CARD_STYLES, true)) $card_style = $themeRow['card_style'];
                if (!empty($themeRow['checkout_color'])) $checkout_color_override = $themeRow['checkout_color'];
                if (in_array($themeRow['layout_style'] ?? '', THEME_LAYOUT_STYLES, true)) $layout_style = $themeRow['layout_style'];
                if (in_array($themeRow['header_style'] ?? '', THEME_HEADER_STYLES, true)) $header_style = $themeRow['header_style'];
                if (in_array($themeRow['menu_nav_position'] ?? '', THEME_MENU_NAV_POSITIONS, true)) $menu_nav_position = $themeRow['menu_nav_position'];
                if (in_array($themeRow['menu_card_style'] ?? '', THEME_MENU_CARD_STYLES, true)) $menu_card_style = $themeRow['menu_card_style'];
                if (in_array($themeRow['home_card_style'] ?? '', THEME_HOME_CARD_STYLES, true)) $home_card_style = $themeRow['home_card_style'];
                if (in_array($themeRow['profile_style'] ?? '', THEME_PROFILE_STYLES, true)) $profile_style = $themeRow['profile_style'];
                if (in_array($themeRow['nav_bar_style'] ?? '', THEME_NAV_BAR_STYLES, true)) $nav_bar_style = $themeRow['nav_bar_style'];
                $profile_opts = cleanProfileOpts(json_decode((string)($themeRow['profile_opts'] ?? ''), true));
                if (preg_match('/^#[0-9a-fA-F]{6}$/', (string)($themeRow['category_heading_bg'] ?? ''))) $category_heading_bg = $themeRow['category_heading_bg'];
                if (in_array($themeRow['category_pic_style'] ?? '', THEME_CATEGORY_PIC_STYLES, true)) $category_pic_style = $themeRow['category_pic_style'];
                if (preg_match('#^data:image/(webp|png|jpeg|gif);base64,[A-Za-z0-9+/=]+$#', (string)($themeRow['all_category_icon'] ?? ''))) $all_category_icon = $themeRow['all_category_icon'];
                if (!empty($themeRow['site_name'])) $site_name_override = $themeRow['site_name'];
                if (array_key_exists($themeRow['nav_icon_style'] ?? '', NAV_ICON_STYLES)) $nav_icon_style = $themeRow['nav_icon_style'];
                if (!empty($themeRow['nav_labels'])) $nav_labels_json = $themeRow['nav_labels'];
                $bestseller_style = getBestsellerStyle($themeRow['bestseller_style'] ?? null);
                if (!empty($themeRow['favicon_url'])) $favicon_url_override = sanitizeFaviconUrl($themeRow['favicon_url']);
                $navIconOverrides = getNavIconOverrides($themeRow['nav_icons_custom'] ?? null);
            }
        } catch (Exception $e) {
        }
    }
    // ── Dashboard "Current" preview: unsaved Website Appearance choices arrive as
    // ?preview_theme=<json>. Applied to this page view only (never saved), and
    // every value is checked against the same allow-lists the save uses.
    $preview_theme = null;
    // Live preview on a phone (Website Appearance > "On your phone"): the same unsaved
    // choices, from a private session (?live_preview=<token>, then a cookie while browsing)
    $rg_live_preview = null;
    $pvRaw = (!empty($_GET['preview_theme']) && is_string($_GET['preview_theme'])) ? $_GET['preview_theme'] : null;
    $lpToken = isset($_GET['live_preview']) ? (string)$_GET['live_preview'] : (string)($_COOKIE['rg_live_preview'] ?? '');
    if ($pvRaw === null && $lpToken !== '' && !empty($restaurant_id) && isset($conn)) {
        require_once __DIR__ . '/../config/theme_live_preview.php';
        $lpRow = themeLivePreviewGet($conn, $lpToken);
        if ($lpRow && $lpRow['restaurant_id'] === $restaurant_id) {
            $rg_live_preview = ['token' => $lpRow['token'], 'v' => (int)$lpRow['version']];
            $pvRaw = (string)$lpRow['theme'];
            if (isset($_GET['live_preview']) && !headers_sent()) {
                setcookie('rg_live_preview', $lpRow['token'], ['expires' => time() + 7200, 'path' => '/', 'samesite' => 'Lax']);
            }
            // The page reloads itself when the dashboard changes something
            register_shutdown_function(function () use ($rg_live_preview) {
                foreach (headers_list() as $h) {
                    if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) return;
                }
                echo str_replace(['__TOKEN__', '__VERSION__'], [json_encode($rg_live_preview['token']), (int)$rg_live_preview['v']], RG_LIVE_PREVIEW_SCRIPT);
            });
        }
    }
    if ($pvRaw !== null && $pvRaw !== '' && strlen($pvRaw) < 8000) {
        $pv = json_decode($pvRaw, true);
        if (is_array($pv)) {
            $hex = function ($v) { return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : null; };
            $preview_theme = [];
            if ($v = $hex($pv['primary_red'] ?? null)) $primary_red = $v;
            if ($v = $hex($pv['dark_red'] ?? null)) $dark_red = $v;
            if ($v = $hex($pv['primary_yellow'] ?? null)) $primary_yellow = $v;
            if ($v = $hex($pv['checkout_color'] ?? null)) $checkout_color_override = $v;
            if (in_array($pv['font_family'] ?? '', ['Poppins', 'Playfair Display', 'Roboto', 'Montserrat', 'Nunito', 'Lora'], true)) $font_family = $pv['font_family'];
            if (in_array($pv['card_style'] ?? '', THEME_CARD_STYLES, true)) $card_style = $pv['card_style'];
            if (in_array($pv['layout_style'] ?? '', THEME_LAYOUT_STYLES, true)) $layout_style = $pv['layout_style'];
            if (in_array($pv['header_style'] ?? '', THEME_HEADER_STYLES, true)) $header_style = $pv['header_style'];
            if (in_array($pv['menu_nav_position'] ?? '', THEME_MENU_NAV_POSITIONS, true)) $menu_nav_position = $pv['menu_nav_position'];
            if (in_array($pv['menu_card_style'] ?? '', THEME_MENU_CARD_STYLES, true)) $menu_card_style = $pv['menu_card_style'];
            if (in_array($pv['home_card_style'] ?? '', THEME_HOME_CARD_STYLES, true)) $home_card_style = $pv['home_card_style'];
            if (in_array($pv['profile_style'] ?? '', THEME_PROFILE_STYLES, true)) $profile_style = $pv['profile_style'];
            if (in_array($pv['nav_bar_style'] ?? '', THEME_NAV_BAR_STYLES, true)) $nav_bar_style = $pv['nav_bar_style'];
            if (array_key_exists($pv['nav_icon_style'] ?? '', NAV_ICON_STYLES)) $nav_icon_style = $pv['nav_icon_style'];
            if (isset($pv['site_name']) && is_string($pv['site_name'])) $site_name_override = trim(mb_substr(strip_tags($pv['site_name']), 0, 191)) ?: null;
            if (isset($pv['nav_labels']) && is_array($pv['nav_labels'])) {
                $pvLabels = [];
                foreach (DEFAULT_NAV_LABELS as $slot => $default) {
                    if (!empty($pv['nav_labels'][$slot]) && is_string($pv['nav_labels'][$slot])) $pvLabels[$slot] = mb_substr(strip_tags($pv['nav_labels'][$slot]), 0, 20);
                }
                $nav_labels_json = json_encode($pvLabels);
            }
            if (isset($pv['profile_opts']) && is_array($pv['profile_opts'])) $profile_opts = cleanProfileOpts($pv['profile_opts']);
            if (in_array($pv['category_pic_style'] ?? '', THEME_CATEGORY_PIC_STYLES, true)) $category_pic_style = $pv['category_pic_style'];
            if (array_key_exists('category_heading_bg', $pv)) $category_heading_bg = $hex($pv['category_heading_bg']);
            if (in_array($pv['logo_shape'] ?? '', ['circle', 'square', 'rounded'], true)) $logo_shape = $pv['logo_shape'];
            if (isset($pv['logo_size']) && (int)$pv['logo_size'] >= 50 && (int)$pv['logo_size'] <= 150) $logo_size = (int)$pv['logo_size'];
            if (isset($pv['bestseller_style']) && is_array($pv['bestseller_style'])) $bestseller_style = getBestsellerStyle($pv['bestseller_style']);
            // a picked background (a link or a file on this site; uploaded pictures are too big
            // for the address, the dashboard puts those in itself)
            if (isset($pv['background_theme']) && is_string($pv['background_theme']) && strlen($pv['background_theme']) < 1000
                && ($pv['background_theme'] === '' || (preg_match('#^(https?://|/|\.\./|assets/|uploads/)#i', $pv['background_theme'])
                    && preg_match('#^[A-Za-z0-9:/._~%?&=+@,;-]+$#', $pv['background_theme'])))) {
                $background_theme = $pv['background_theme'];
            }
            $cols = (int)($pv['layout_columns'] ?? 0);
            if ($cols >= 1 && $cols <= 3) $preview_theme['layout_columns'] = $cols;
        }
    }
    $cardStyleRadii = getCardStyleRadii($card_style);
    $card_radius_css = $cardStyleRadii['card_radius'];
    $btn_radius_css = $cardStyleRadii['btn_radius'];
    // Checkout Color defaults to the Primary Color (matching the Checkout
    // button's original look) unless the admin has picked a distinct one.
    $checkout_color = $checkout_color_override ?? $primary_red;
    $checkout_color_dark = shadeHexColor($checkout_color, -12);
    $allowedWebFonts = [
        'Poppins' => "'Poppins', sans-serif",
        'Playfair Display' => "'Playfair Display', serif",
        'Roboto' => "'Roboto', sans-serif",
        'Montserrat' => "'Montserrat', sans-serif",
        'Nunito' => "'Nunito', sans-serif",
        'Lora' => "'Lora', serif",
    ];
    if (!array_key_exists($font_family, $allowedWebFonts)) {
        $font_family = 'Poppins';
    }
    $font_family_css = $allowedWebFonts[$font_family];
    // Only request a second Google Font family when it differs from the Poppins link already in <head>
    $font_family_google_param = ($font_family !== 'Poppins') ? str_replace(' ', '+', $font_family) : '';

// Derive phone dial code / expected local number length from the restaurant's country
// (falls back to India's 10-digit format if the country isn't in the reference list)
$countryPhoneInfo = getCountryByName($country);
$phone_dial_code = $countryPhoneInfo['dial_code'] ?? '+91';
$phone_min_digits = $countryPhoneInfo['phone_min'] ?? 10;
$phone_max_digits = $countryPhoneInfo['phone_max'] ?? 10;

// Timezone offset (minutes) for JavaScript, and opening-hours check below — both derived
// from the restaurant's own configured timezone (applyRestaurantTimezone() ran above),
// falling back to Asia/Kolkata (IST, UTC+5:30) if no restaurant/timezone was resolved.
$restaurant_tz = new DateTimeZone(date_default_timezone_get());
$timezone_offset_minutes = intdiv($restaurant_tz->getOffset(new DateTime('now', $restaurant_tz)), 60);

// Determine if restaurant is currently open, using the restaurant's own local time
$restaurant_open = true;
if (!empty($opening_hours)) {
    $hours = json_decode($opening_hours, true);
    if ($hours) {
        // also counts yesterday's shift running past midnight (config/opening_hours.php)
        require_once __DIR__ . '/../config/opening_hours.php';
        $restaurant_open = rgIsOpenNow($hours, $restaurant_tz);
    }
}

// Table from a QR code scan (?table=T4), remembered for this restaurant only and for a few hours
// (QR_TABLE_KEEP_SECONDS). It used to be one session value for every restaurant, kept as long as
// the session (400 days): one scan put every later visit, anywhere, into dine-in at that table.
if (!defined('QR_TABLE_KEEP_SECONDS')) define('QR_TABLE_KEEP_SECONDS', 4 * 3600);
$qrTableParam = isset($_GET['table']) && is_string($_GET['table']) ? trim($_GET['table']) : '';
if ($qrTableParam !== '' && $restaurant_id) {
    $_SESSION['qr_table'] = ['rid' => (string)$restaurant_id, 'table' => $qrTableParam, 'at' => time()];
}
$qrSaved = $_SESSION['qr_table'] ?? null;
if (is_array($qrSaved) && $restaurant_id && ($qrSaved['rid'] ?? '') === (string)$restaurant_id
    && time() - (int)($qrSaved['at'] ?? 0) < QR_TABLE_KEEP_SECONDS && (string)($qrSaved['table'] ?? '') !== '') {
    $qr_table = (string)$qrSaved['table'];
} elseif ($qrTableParam !== '') {
    $qr_table = $qrTableParam;
} elseif ($qrSaved !== null && (!is_array($qrSaved) || time() - (int)($qrSaved['at'] ?? 0) >= QR_TABLE_KEEP_SECONDS)) {
    unset($_SESSION['qr_table']);   // expired, or the old unscoped form
}

// An outlet of a brand lives at the brand's address (brand.com/<outlet>/… or restrogrow.com/<brand>/<outlet>/…):
// any other address of it (restrogrow.com/<name>, main/website/menu.php?restaurant_id=) moves there, query kept
// (?table=T3, ?order_id= after a payment). website/brand_router.php, config/brand.php
require_once __DIR__ . '/brand_router.php';
$rg_brand_ctx = !empty($restaurant_id) ? brandOfRestaurant($conn ?? getConnection(), (string)$restaurant_id) : null;
$rg_page_base = '';
if ($rg_brand_ctx) {
    $rgPage = rgCurrentPageName();
    if ($rgPage !== null && !defined('RG_BRAND_ROUTED') && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        ob_end_clean();
        $rgAbs = $rg_brand_ctx['brand']['domain_live'] && !brandByHostIsThis($rg_brand_ctx['brand']);
        header('Location: ' . brandOutletUrl($rg_brand_ctx['brand'], $rg_brand_ctx['outlet'], $rgPage, $rgAbs) . brandKeepQuery(), true, 301);
        exit;
    }
    $rg_page_base = brandOutletUrl($rg_brand_ctx['brand'], $rg_brand_ctx['outlet']);
}

$host = $_SERVER['HTTP_HOST'] ?? '';
if (strpos($host, ':') !== false) $host = explode(':', $host)[0];
$is_custom_domain = $host !== 'restrogrow.com' && $host !== 'www.restrogrow.com' && $host !== 'localhost' && $host !== '127.0.0.1' && strpos($host, 'hstgr.io') === false && !preg_match('/\.ngrok-free\.(app|dev)$/', $host);

// Redirect to custom domain if configured and visitor is on the main platform (not already on custom domain)
if (!empty($custom_domain) && $embed_enabled && !$is_custom_domain) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $domain = preg_replace('#^https?://#', '', $custom_domain);
    
    // Preserve the requested page path when redirecting to the custom domain
    $redirect_path = '/';
    
    // Check if ?page= parameter was used (reliable slug-based URLs)
    if (isset($_GET['page']) && $_GET['page'] !== '') {
        $redirect_path = '/' . trim($_GET['page']);
    } else {
        $current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
        $basename = basename($current_path);
        // Check if we're on a restaurant-specific page (not just the homepage)
        if ($restaurant_slug && strpos($current_path, $restaurant_slug) !== false) {
            // slug/page pattern: extract the page part after the restaurant slug
            $slug_pos = strpos($current_path, $restaurant_slug);
            $after_slug = substr($current_path, $slug_pos + strlen($restaurant_slug));
            if ($after_slug && $after_slug !== '/') {
                $redirect_path = $after_slug;
            }
        } elseif ($basename && $basename !== 'index.php') {
            // Direct .php page access (e.g. cart.php?restaurant_id=X)
            $page_name = str_replace('.php', '', $basename);
            if (in_array($page_name, ['menu', 'cart', 'about', 'contact', 'profile', 'login', 'reset_password', 'track', 'track-order', 'privacy-policy', 'terms-of-service', 'refund-policy', 'shipping-policy', 'cookie-policy', 'plans', 'subscribe', 'my-subscription', 'catering', 'reservations'])) {
                $redirect_path = '/' . $page_name;
            }
        }
    }
    
    // Keep the query string (e.g. ?table=T3 from a printed QR code), minus the
    // parameters that only identify the restaurant on the main platform
    $keep_query = $_GET;
    unset($keep_query['restaurant_id'], $keep_query['restaurant'], $keep_query['page']);
    if ($keep_query) {
        $redirect_path .= '?' . http_build_query($keep_query);
    }

    // Clean output buffer before redirect
    ob_end_clean();
    header('Location: ' . $scheme . '://' . $domain . $redirect_path);
    exit;
}

if ($is_custom_domain && $restaurant_id && !$restaurant_slug && !empty($restaurant_name)) {
    $restaurant_slug = createRestaurantSlug($restaurant_name);
}

// Apply the Website Appearance display-name override (if any) for customer-facing
// display only — after slug resolution above, so URLs still route by the account's
// real restaurant_name and only what's shown to visitors changes.
if (!empty($site_name_override)) {
    $restaurant_name = $site_name_override;
}
// Bottom-nav icon set for the customer website (Home/Menu/Social/Plans/Cart/Profile)
$navIcons = NAV_ICON_STYLES[$nav_icon_style] ?? NAV_ICON_STYLES['classic'];
// Bottom-nav text labels — restaurant-specific overrides merged over the defaults
$navLabels = getNavLabels($nav_labels_json ?? null);

/**
 * Generate the URL for a restaurant page.
 * If the restaurant has a custom domain enabled, uses that domain.
 * Otherwise uses the slug-based URL on the main platform.
 */
function restaurantPageUrl($page = '') {
    global $restaurant_slug, $is_custom_domain, $restaurant_id, $custom_domain, $embed_enabled, $rg_brand_ctx;

    // an outlet of a brand: brand.com/<outlet>/<page> or restrogrow.com/<brand>/<outlet>/<page> (config/brand.php)
    if (!empty($rg_brand_ctx)) {
        return brandOutletUrl($rg_brand_ctx['brand'], $rg_brand_ctx['outlet'], (string)$page);
    }

    // If someone is already on their custom domain, use relative URLs
    if ($is_custom_domain) {
        return $page ? '/' . $page : '/';
    }
    
    // If the restaurant has a custom domain configured and enabled, use the full custom domain URL
    if (!empty($custom_domain) && $embed_enabled) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $domain = $custom_domain;
        // Remove protocol if accidentally stored
        $domain = preg_replace('#^https?://#', '', $domain);
        return $scheme . '://' . $domain . ($page ? '/' . $page : '/');
    }
    
    // Standard slug-based URL on main platform
    if ($restaurant_slug) {
        $root = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        return $root . '/' . $restaurant_slug . ($page ? '/' . $page : '');
    }
    
    // Fallback: restaurant_id-based URL
    if ($restaurant_id) {
        return $page ? $page . '.php?restaurant_id=' . urlencode($restaurant_id) : 'index.php?restaurant_id=' . urlencode($restaurant_id);
    }
    
    return $page ? $page . '.php' : 'index.php';
}

// Favicon — admin-set favicon_url override, else the uploaded restaurant logo,
// else a local SVG placeholder (avoids external 404s if neither is set).
$favicon_href = $favicon_url_override ?? $restaurant_logo ?? $local_favicon_svg;
// the browser fetches the tab icon on every page: a 128 px copy of an uploaded logo, not the full file
if (is_string($favicon_href) && strpos($favicon_href, 'image.php?type=logo&id=') === 0) $favicon_href .= '&w=128';

$website_base_href = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
$site_root = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))), '/') . '/';

// ── Page Visit Tracking ──
// Fire-and-forget: track each page load silently (no effect on user experience)
if ($restaurant_id) {
    $track_page = $_SERVER['REQUEST_URI'] ?? '/';
    // page name from the file that runs (a restaurant slug like "menu-house" must not make every page "Menu")
    $track_name = ['index' => 'Home', 'menu' => 'Menu', 'cart' => 'Cart', 'about' => 'About Us', 'contact' => 'Contact',
        'profile' => 'Profile', 'track' => 'Track Order', 'loyalty' => 'Rewards', 'reservations' => 'Reservations',
        'catering' => 'Catering', 'plans' => 'Plans', 'subscribe' => 'Subscribe', 'my-subscription' => 'My subscription',
        'login' => 'Login'][basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'), '.php')] ?? 'Home';
    
    $track_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $track_ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $track_ref = $_SERVER['HTTP_REFERER'] ?? '';
    // Truncate referrer to avoid URL length issues (max 500 chars)
    if (strlen($track_ref) > 500) $track_ref = substr($track_ref, 0, 500);
    
    // Fire tracking as output-buffered JavaScript (async, invisible)
    echo '<script>(function(){var x=new XMLHttpRequest();x.open("GET","' . $website_base_href . 'track_visit.php?rid=' . urlencode($restaurant_id) . '&page=' . urlencode(substr($track_page, 0, 255)) . '&name=' . urlencode($track_name) . '&ip=' . urlencode($track_ip) . '&ref=' . urlencode($track_ref) . '&ua=' . urlencode(substr($track_ua, 0, 200)) . '",true);x.timeout=3000;x.send();})();</script>' . "\n";
}

// ── Marketing links: ?src=<campaign> and ?coupon=<code> from a poster / caption link ──
// The campaign tag is kept for 7 days and sent with the order (cart.php -> campaign_src);
// the coupon is applied in the cart automatically (cart.php applySavedReward, rgPromoCode).
if ($restaurant_id && (isset($_GET['src']) || isset($_GET['coupon']))) {
    echo '<script>(function(){try{var p=new URLSearchParams(location.search),s=(p.get("src")||"").toLowerCase(),c=(p.get("coupon")||"").toUpperCase(),r=' . json_encode((string)$restaurant_id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'
       . 'if(/^[a-z0-9-]{1,60}$/.test(s))localStorage.setItem("rgCampaign",JSON.stringify({src:s,rid:r,at:Date.now()}));'
       . 'if(/^[A-Z0-9_-]{2,50}$/.test(c))localStorage.setItem("rgPromoCode",JSON.stringify({code:c,rid:r}));}catch(e){}})();</script>' . "\n";
}

// ── PWA "Install App" support (shared across every page) ──
// Registers the service worker + beforeinstallprompt/appinstalled listeners
// and exposes a global promptInstall() so the bottom-nav "Install App"
// button (bottom_nav.php / index.php, shown only when $show_install_app is
// true) works everywhere, including about/contact/loyalty pages that have
// no install JS of their own. Wrapped in an IIFE so its own `deferredPrompt`
// never collides with the richer per-page copy index.php/menu.php/cart.php/
// profile.php already declare later in their own <script> blocks — a plain
// `function promptInstall(){}` declared there simply overwrites this one
// (function redeclaration doesn't throw, unlike `let`/`const`), so this
// fallback only actually matters on pages without their own copy.
// the restaurant's dial code and phone length for the page scripts (wa.me links, phone checks)
if (!empty($restaurant_id) && isset($conn)) echo '<script>' . countryJsGlobals(restaurantCountryInfo($conn, (string)$restaurant_id)) . '</script>' . "
";
if ($show_install_app) {
?>
<script>
(function(){
  var deferredPrompt = null;
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
      try {
        navigator.serviceWorker.register('<?php echo $website_base_href; ?>sw.php', { scope: '<?php echo $site_root; ?>' }).catch(function(){});
      } catch (e) {}
    });
  }
  window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault();
    deferredPrompt = e;
  });
  window.addEventListener('appinstalled', function() {
    deferredPrompt = null;
    var el = document.getElementById('installNavBtn');
    if (el) el.style.display = 'none';
  });
  function showGuide() {
    if (document.getElementById('installGuideOverlay')) return;
    var o = document.createElement('div');
    o.id = 'installGuideOverlay';
    o.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px';
    o.onclick = function(e) { if (e.target === o) o.remove(); };
    o.innerHTML = '<div style="background:#fff;border-radius:20px;max-width:360px;width:100%;padding:32px 24px;text-align:center;">' +
      '<div style="font-size:48px;margin-bottom:12px">📱</div>' +
      '<h3 style="font-size:18px;font-weight:600;color:#1a1b1f;margin:0 0 6px">Install This App</h3>' +
      '<p style="font-size:13px;color:#666;margin:0 0 16px">Open your browser menu and choose &quot;Add to Home Screen&quot; or &quot;Install App&quot;.</p>' +
      '<button id="installGuideCloseBtn" style="padding:10px 24px;border:none;border-radius:10px;background:#1a3934;color:#fff;font-weight:600;cursor:pointer;">Got it</button></div>';
    document.body.appendChild(o);
    document.getElementById('installGuideCloseBtn').addEventListener('click', function() { o.remove(); });
  }
  window.promptInstall = function() {
    if (!deferredPrompt) { showGuide(); return; }
    deferredPrompt.prompt();
    deferredPrompt.userChoice.then(function() { deferredPrompt = null; });
  };
  // Already installed / running standalone — no point offering to install again.
  document.addEventListener('DOMContentLoaded', function() {
    try {
      var standalone = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches;
      if (standalone || window.navigator.standalone === true) {
        var el = document.getElementById('installNavBtn');
        if (el) el.style.display = 'none';
      }
    } catch (e) {}
  });
})();
</script>
<?php
}

// ── Customer account session ──
// Resolves a logged-in customer for the restaurant being browsed (customers
// are scoped per-restaurant, same as the `customers` CRM table), reviving
// the session from a "remember me" cookie if needed.
require_once __DIR__ . '/customer_session.php';
$logged_in_customer = null;
if ($restaurant_id && isset($conn) && $conn instanceof PDO) {
    $logged_in_customer = getLoggedInCustomer($conn, $restaurant_id);
}
if ($logged_in_customer) header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');

// The scripts written above (visit tracker, campaign tag, install prompt) go inside the page's <head>.
// They used to be printed before <!DOCTYPE html>, which put every page in old "quirks mode" (wrong sizes
// on phones) and broke JSON replies (manifest, payment status) that include this file.
$rgEarlyHtml = (string)ob_get_clean();
// Every website page (not JSON replies that include this file): the network safety net (rg_net.php: retries,
// "You're offline" bar) goes first in <head>, so each page and any new one has it without adding anything.
if (rgCurrentPageName() !== null) {
    require_once __DIR__ . '/rg_net.php';
    $rgEarlyHtml = rgNetScript() . "\n" . $rgEarlyHtml;
}
if (trim($rgEarlyHtml) !== '') {
    ob_start(function ($html) use ($rgEarlyHtml) {
        // A photo saved inline (data: URI) over ~15 KB, wherever a page prints it (in HTML or in JSON with \/):
        // a cached file instead (websiteInlineImageUrl), so pages stay small and the photo is cached
        if (strpos($html, 'base64,') !== false) {
            $html = preg_replace_callback('#data:image(\\\\?/)(png|jpe?g|webp|gif);base64,([A-Za-z0-9+=/\\\\]{20000,}+)#',
                function ($m) {
                    $esc = $m[1] !== '/';
                    $uri = 'data:image/' . $m[2] . ';base64,' . str_replace('\\/', '/', $m[3]);
                    $url = websiteInlineImageUrl($uri);
                    if ($url === '') return $m[0];
                    return $esc ? str_replace('/', '\\/', $url) : $url;
                }, $html) ?? $html;
        }
        $p = stripos($html, '<head');
        $e = $p === false ? false : strpos($html, '>', $p);
        return $e === false ? $html : substr($html, 0, $e + 1) . "
" . $rgEarlyHtml . substr($html, $e + 1);
    });
}
?>
