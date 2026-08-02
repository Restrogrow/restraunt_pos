<?php
ob_start();

// No-cache headers to ensure real-time updates on the customer website
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../config/countries.php';
startSecureSession(true);

function createRestaurantSlug($name) {
    $slug = strtolower($name);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

function findRestaurantBySlug($conn, $slug) {
    // Prefer users with menu entries, then most recently created
    $stmt = $conn->prepare("
        SELECT u.restaurant_id, u.restaurant_name
        FROM users u
        LEFT JOIN (
            SELECT restaurant_id, COUNT(*) as cnt
            FROM menu
            WHERE is_active = 1
            GROUP BY restaurant_id
        ) m ON u.restaurant_id = m.restaurant_id
        WHERE u.restaurant_name IS NOT NULL AND u.restaurant_name != ''
        ORDER BY COALESCE(m.cnt, 0) DESC, u.id DESC
    ");
    $stmt->execute();
    $restaurants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($restaurants as $restaurant) {
        $restaurant_slug = createRestaurantSlug($restaurant['restaurant_name']);
        if ($restaurant_slug === $slug) {
            return [
                'restaurant_id' => $restaurant['restaurant_id'],
                'restaurant_name' => $restaurant['restaurant_name']
            ];
        }
    }
    return null;
}

$restaurant_id = null;
$restaurant_id_param = isset($_GET['restaurant_id']) ? trim($_GET['restaurant_id']) : '';
$restaurant_slug = isset($_GET['restaurant']) ? trim($_GET['restaurant']) : '';
$has_id_param = $restaurant_id_param !== '';
$has_slug_param = $restaurant_slug !== '';

if ($has_id_param) {
    $restaurant_id = $restaurant_id_param;
    $_SESSION['restaurant_id'] = $restaurant_id;
} elseif ($has_slug_param) {
} elseif (isset($_SESSION['restaurant_id']) && $_SESSION['restaurant_id'] !== '') {
    $restaurant_id = $_SESSION['restaurant_id'];
}

// Handle table parameter from QR code scanning
$qr_table = isset($_GET['table']) && trim($_GET['table']) !== '' ? trim($_GET['table']) : (isset($_SESSION['qr_table']) ? $_SESSION['qr_table'] : null);
if ($qr_table) {
    $_SESSION['qr_table'] = $qr_table;
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
$instagram_link = null;
$facebook_link = null;
$twitter_link = null;
$youtube_link = null;
$linkedin_link = null;
$enable_delivery = 1;
$enable_takeaway = 1;
$enable_dinein = 1;
$cod_enabled = 1;

if ($has_slug_param && !$has_id_param) {
    try {
        require_once __DIR__ . '/db_config.php';
        if (function_exists('getConnection')) {
            $conn = getConnection();
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
$stmt = $conn->prepare("SELECT id, restaurant_name, restaurant_logo, currency_symbol, country, tax_name, tax_percent, timezone, address, description, description_format, phone, email, opening_hours, minimum_order_value, packaging_charge, enable_gst, payment_gateway_type, show_install_app, google_maps_link, owner_name, instagram_link, facebook_link, twitter_link, youtube_link, linkedin_link, enable_delivery, enable_takeaway, enable_dinein, cod_enabled, custom_domain, embed_enabled, delivery_radius_km, restaurant_lat, restaurant_lng, subscription_status FROM users WHERE restaurant_id = ? LIMIT 1");
                $stmt->execute([$restaurant_id]);
            } catch (Exception $e2) {
                // Fallback: new columns (delivery_radius_km, etc.) may not exist - query without them
                $stmt = $conn->prepare("SELECT id, restaurant_name, restaurant_logo, currency_symbol, address, description, description_format, phone, email, opening_hours, minimum_order_value, packaging_charge, enable_gst, payment_gateway_type, show_install_app, google_maps_link, owner_name, instagram_link, facebook_link, twitter_link, youtube_link, linkedin_link, enable_delivery, enable_takeaway, enable_dinein, custom_domain, embed_enabled, subscription_status FROM users WHERE restaurant_id = ? LIMIT 1");
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
            }
        }
    } catch (Exception $e) {
    }        // Load background_theme from website_settings
    if ($restaurant_id) {
        try {
            $stmt = $conn->prepare('SELECT background_theme FROM website_settings WHERE restaurant_id = :rid');
            $stmt->execute([':rid' => $restaurant_id]);
            $themeRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($themeRow && !empty($themeRow['background_theme'])) {
                // Only use DB value if it's a valid URL (starts with http), not an invalid placeholder
                $dbBg = trim($themeRow['background_theme']);
                if (strpos($dbBg, 'http://') === 0 || strpos($dbBg, 'https://') === 0 || strpos($dbBg, 'data:') === 0) {
                    $background_theme = $dbBg;
                }
            }
        } catch (Exception $e) {
        }
    }
    
    // Load logo_shape and logo_size from website_settings
    $logo_shape = 'circle';
    $logo_size = 90;
    if ($restaurant_id) {
        try {
            $stmt = $conn->prepare('SELECT logo_shape, logo_size FROM website_settings WHERE restaurant_id = :rid');
            $stmt->execute([':rid' => $restaurant_id]);
            $logoRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($logoRow) {
                $logo_shape = $logoRow['logo_shape'] ?? 'circle';
                $logo_size = (int)($logoRow['logo_size'] ?? 90);
            }
        } catch (Exception $e) {
        }
    }
}

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
        $now = new DateTime('now', $restaurant_tz);
        $day = strtolower($now->format('l'));
        if (!isset($hours[$day]) || empty($hours[$day]['open'])) {
            $restaurant_open = false;
        } else {
            $opening = $hours[$day]['opening'] ?? '09:00 AM';
            $closing = $hours[$day]['closing'] ?? '10:00 PM';
            $openDT = new DateTime($now->format('Y-m-d') . ' ' . $opening, $restaurant_tz);
            $closeDT = new DateTime($now->format('Y-m-d') . ' ' . $closing, $restaurant_tz);
            if ($closeDT <= $openDT) {
                $closeDT->modify('+1 day');
            }
            $restaurant_open = ($now >= $openDT && $now < $closeDT);
        }
    }
}

$host = $_SERVER['HTTP_HOST'] ?? '';
if (strpos($host, ':') !== false) $host = explode(':', $host)[0];
$is_custom_domain = $host !== 'restrogrow.com' && $host !== 'www.restrogrow.com' && $host !== 'localhost' && $host !== '127.0.0.1' && strpos($host, 'hstgr.io') === false;

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
            if (in_array($page_name, ['menu', 'cart', 'about', 'contact', 'profile', 'track', 'track-order', 'privacy-policy', 'terms-of-service', 'refund-policy', 'shipping-policy', 'cookie-policy'])) {
                $redirect_path = '/' . $page_name;
            }
        }
    }
    
    // Clean output buffer before redirect
    ob_end_clean();
    header('Location: ' . $scheme . '://' . $domain . $redirect_path);
    exit;
}

if ($is_custom_domain && $restaurant_id && !$restaurant_slug && !empty($restaurant_name)) {
    $restaurant_slug = createRestaurantSlug($restaurant_name);
}

/**
 * Generate the URL for a restaurant page.
 * If the restaurant has a custom domain enabled, uses that domain.
 * Otherwise uses the slug-based URL on the main platform.
 */
function restaurantPageUrl($page = '') {
    global $restaurant_slug, $is_custom_domain, $restaurant_id, $custom_domain, $embed_enabled;
    
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

// Favicon — use local SVG to avoid 404 errors
$favicon_href = $local_favicon_svg;

$website_base_href = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
$site_root = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))), '/') . '/';

// ── Page Visit Tracking ──
// Fire-and-forget: track each page load silently (no effect on user experience)
if ($restaurant_id) {
    $track_page = $_SERVER['REQUEST_URI'] ?? '/';
    $track_name = 'Home';
    // Determine page name from URL
    if (strpos($track_page, 'menu') !== false) $track_name = 'Menu';
    elseif (strpos($track_page, 'cart') !== false) $track_name = 'Cart';
    elseif (strpos($track_page, 'about') !== false) $track_name = 'About Us';
    elseif (strpos($track_page, 'contact') !== false) $track_name = 'Contact';
    elseif (strpos($track_page, 'profile') !== false) $track_name = 'Profile';
    elseif (strpos($track_page, 'track') !== false) $track_name = 'Track Order';
    elseif (strpos($track_page, 'privacy') !== false) $track_name = 'Privacy Policy';
    elseif (strpos($track_page, 'terms') !== false) $track_name = 'Terms of Service';
    elseif (strpos($track_page, 'refund') !== false) $track_name = 'Refund Policy';
    elseif (strpos($track_page, 'shipping') !== false) $track_name = 'Shipping Policy';
    elseif (strpos($track_page, 'cookie') !== false) $track_name = 'Cookie Policy';
    
    $track_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $track_ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $track_ref = $_SERVER['HTTP_REFERER'] ?? '';
    // Truncate referrer to avoid URL length issues (max 500 chars)
    if (strlen($track_ref) > 500) $track_ref = substr($track_ref, 0, 500);
    
    // Fire tracking as output-buffered JavaScript (async, invisible)
    echo '<script>(function(){var x=new XMLHttpRequest();x.open("GET","' . $website_base_href . 'track_visit.php?rid=' . urlencode($restaurant_id) . '&page=' . urlencode(substr($track_page, 0, 255)) . '&name=' . urlencode($track_name) . '&ip=' . urlencode($track_ip) . '&ref=' . urlencode($track_ref) . '&ua=' . urlencode(substr($track_ua, 0, 200)) . '",true);x.timeout=3000;x.send();})();</script>' . "\n";
}

ob_end_flush();
?>
