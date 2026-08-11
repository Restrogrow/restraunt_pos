<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$domain = $_SERVER['HTTP_HOST'];
if (strpos($domain, ':') !== false) $domain = explode(':', $domain)[0];

require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../db_connection.php';

if (!function_exists('getConnection')) exit('Server configuration error');

$conn = getConnection();
$stmt = $conn->prepare("SELECT restaurant_id FROM users WHERE custom_domain = ? AND embed_enabled = 1 LIMIT 1");
$stmt->execute([$domain]);
$customDomainRow = $stmt->fetch(PDO::FETCH_ASSOC);

if ($customDomainRow) {
    // Custom domain request
    $restaurant_id = $customDomainRow['restaurant_id'];
    $_GET['restaurant_id'] = $restaurant_id;

    $parsedUrl = parse_url($_SERVER['REQUEST_URI']);
    $path = isset($parsedUrl['path']) ? trim($parsedUrl['path'], '/') : '';

    $staticExts = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'ico', 'svg', 'woff', 'woff2', 'ttf', 'eot', 'webp', 'pdf', 'zip', 'mp4', 'webm', 'json'];
    if (in_array(pathinfo($path, PATHINFO_EXTENSION), $staticExts)) return false;

    $pageMap = [
        '' => 'index.php',
        'menu' => 'menu.php',
        'cart' => 'cart.php',
        'about' => 'about.php',
        'contact' => 'contact.php',
        'profile' => 'profile.php',
        'login' => 'login.php',
        'reset-password' => 'reset_password.php',
        'track' => 'track.php',
        'track-order' => 'track.php',
        'privacy-policy' => 'privacy-policy.php',
        'terms-of-service' => 'terms-of-service.php',
        'refund-policy' => 'refund-policy.php',
        'shipping-policy' => 'shipping-policy.php',
        'cookie-policy' => 'cookie-policy.php',
        'plans' => 'plans.php',
        'subscribe' => 'subscribe.php',
        'my-subscription' => 'my-subscription.php',
        'catering' => 'catering.php',
    ];

    $page = null;
    if (isset($pageMap[$path])) {
        $page = $pageMap[$path];
    } elseif (in_array($path, ['index.php', 'menu.php', 'cart.php', 'about.php', 'contact.php', 'profile.php', 'login.php', 'reset_password.php', 'track.php', 'privacy-policy.php', 'terms-of-service.php', 'refund-policy.php', 'shipping-policy.php', 'cookie-policy.php'])) {
        $page = $path;
    } elseif (preg_match('/^(.+)\.php$/', $path, $m) && isset($pageMap[$m[1]])) {
        $page = $pageMap[$m[1]];
    }

    if (!$page) {
        http_response_code(404);
        include __DIR__ . '/404.php';
        exit;
    }

    include __DIR__ . '/' . $page;
    exit;
}

// Not a custom domain — this is the main domain, handle normally
$parsedUrl = parse_url($_SERVER['REQUEST_URI']);
$path = isset($parsedUrl['path']) ? trim($parsedUrl['path'], '/') : '';

$pageMap = [
    '' => 'index.php',
    'menu' => 'menu.php',
    'cart' => 'cart.php',
    'about' => 'about.php',
    'contact' => 'contact.php',
    'profile' => 'profile.php',
    'login' => 'login.php',
    'reset-password' => 'reset_password.php',
    'track' => 'track.php',
    'track-order' => 'track.php',
    'privacy-policy' => 'privacy-policy.php',
    'terms-of-service' => 'terms-of-service.php',
    'refund-policy' => 'refund-policy.php',
    'shipping-policy' => 'shipping-policy.php',
    'cookie-policy' => 'cookie-policy.php',
    'plans' => 'plans.php',
    'subscribe' => 'subscribe.php',
    'my-subscription' => 'my-subscription.php',
    'catering' => 'catering.php',
];

if (isset($pageMap[$path]) || in_array($path, ['index.php', 'menu.php', 'cart.php', 'about.php', 'contact.php', 'profile.php', 'login.php', 'reset_password.php', 'track.php', 'privacy-policy.php', 'terms-of-service.php', 'refund-policy.php', 'shipping-policy.php', 'cookie-policy.php']) || preg_match('/^(.+)\.php$/', $path, $m) && isset($pageMap[$m[1]])) {
    // If a restaurant_id is in query params, serve the correct page instead of always index.php
    if (isset($_GET['restaurant_id']) && $_GET['restaurant_id'] !== '' && $path !== 'index.php') {
        // Determine the correct page file
        $direct_page = $path;
        if (isset($pageMap[$path])) {
            $direct_page = $pageMap[$path];
        } elseif (preg_match('/^(.+)\.php$/', $path, $m) && isset($pageMap[$m[1]])) {
            $direct_page = $pageMap[$m[1]];
        }
        // Only serve known PHP files to prevent path traversal
        $allowed_php = ['index.php', 'menu.php', 'cart.php', 'about.php', 'contact.php', 'profile.php', 'login.php', 'reset_password.php', 'track.php', 'privacy-policy.php', 'terms-of-service.php', 'refund-policy.php', 'shipping-policy.php', 'cookie-policy.php', 'plans.php', 'subscribe.php', 'my-subscription.php', 'catering.php'];
        if (in_array($direct_page, $allowed_php) && file_exists(__DIR__ . '/' . $direct_page)) {
            include __DIR__ . '/' . $direct_page;
            exit;
        }
    }
    include __DIR__ . '/index.php';
    exit;
}

// Handle restaurant-slug/page paths (e.g. restaurant-name/menu)
$requested_page = '';
if (strpos($path, '/') !== false) {
    $parts = explode('/', $path, 2);
    $path = $parts[0];          // restaurant slug
    // Validate the page is one we know how to serve
    $allowed_pages = ['menu', 'cart', 'about', 'contact', 'profile', 'login', 'reset-password', 'track', 'track-order',
                      'privacy-policy', 'terms-of-service', 'refund-policy', 'shipping-policy', 'cookie-policy',
                      'plans', 'subscribe', 'my-subscription', 'catering'];
    if (in_array($parts[1], $allowed_pages)) {
        $requested_page = $parts[1];
    }
}

// Try looking up the restaurant by slug to serve the page directly
if ($requested_page !== '') {
    try {
        // Reuse createRestaurantSlug logic — find restaurant by name slug
        $stmt = $conn->prepare("SELECT restaurant_id, restaurant_name, custom_domain, embed_enabled FROM users WHERE restaurant_name IS NOT NULL AND restaurant_name != '' ORDER BY id DESC");
        $stmt->execute();
        $all_restaurants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Build slug function inline (avoids requiring header.php early)
        $createSlug = function($name) {
            $slug = strtolower($name);
            $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
            return trim($slug, '-');
        };
        
        foreach ($all_restaurants as $r) {
            if ($createSlug($r['restaurant_name']) === $path) {
                $_GET['restaurant_id'] = $r['restaurant_id'];
                
                // Map page name to PHP file
                $pageFile = $pageMap[$requested_page] ?? '';
                if ($pageFile) {
                    // If restaurant has a custom domain and visitor is NOT on it, redirect there
                    $has_custom_domain = !empty($r['custom_domain']) && !empty($r['embed_enabled']);
                    $host = $_SERVER['HTTP_HOST'] ?? '';
                    if (strpos($host, ':') !== false) $host = explode(':', $host)[0];
                    $is_custom_request = $host !== 'restrogrow.com' && $host !== 'www.restrogrow.com' && $host !== 'localhost' && $host !== '127.0.0.1' && strpos($host, 'hstgr.io') === false;
                    
                    if ($has_custom_domain && !$is_custom_request) {
                        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $domain = preg_replace('#^https?://#', '', trim($r['custom_domain']));
                        header('Location: ' . $scheme . '://' . $domain . '/' . $requested_page);
                        exit;
                    }
                    
                    // Serve the page directly (avoids redirect loop)
                    include __DIR__ . '/' . $pageFile;
                    exit;
                }
                break;
            }
        }
    } catch (Exception $e) {
        // Ignore errors, fall through to router.php below
    }
}

$_GET['restaurant'] = $path;
include __DIR__ . '/router.php';
