<?php
/**
 * Restaurant Router
 * Handles restaurant-specific URLs like website/restaurant-name
 * Usage: website/router.php?restaurant=restaurant-name
 * Or: website/restaurant-name (via .htaccess rewrite)
 */

// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
// Skip timeout validation for public customer website - sessions are just for restaurant context
startSecureSession(true);

// Get restaurant identifier from URL
$restaurant_slug = isset($_GET['restaurant']) ? trim($_GET['restaurant']) : '';
$restaurant_id = null;
$restaurant_name = null;

if (empty($restaurant_slug)) {
    // Try to get from URL path (if using .htaccess rewrite)
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
    
    // Extract restaurant name from URL path
    // Example: /test/restraunt_pos/website/restaurant-name
    $path = parse_url($request_uri, PHP_URL_PATH);
    $path_parts = explode('/', trim($path, '/'));
    
    // Find 'website' in path and get next part
    $website_index = array_search('website', $path_parts);
    if ($website_index !== false && isset($path_parts[$website_index + 1])) {
        $restaurant_slug = $path_parts[$website_index + 1];
    }
}

// If still no slug, check if it's index.php with restaurant parameter
if (empty($restaurant_slug) && isset($_GET['restaurant'])) {
    $restaurant_slug = trim($_GET['restaurant']);
}

// Include database connection
require_once __DIR__ . '/db_config.php';

// Get connection using getConnection() for lazy connection support
if (function_exists('getConnection')) {
    $conn = getConnection();
} else {
    // Fallback to $pdo if getConnection() doesn't exist (backward compatibility)
    global $pdo;
    $conn = $pdo ?? null;
    if (!$conn) {
        http_response_code(500);
        die('Database connection not available');
    }
}

// Function to create URL-friendly slug from restaurant name
function createRestaurantSlug($name) {
    // Convert to lowercase
    $slug = strtolower($name);
    // Replace spaces and special characters with hyphens
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    // Remove leading/trailing hyphens
    $slug = trim($slug, '-');
    return $slug;
}

// Function to find restaurant by slug
function findRestaurantBySlug($conn, $slug) {
    // Prefer users with menu entries, then most recently created
    $stmt = $conn->prepare("
        SELECT u.restaurant_id, u.restaurant_name, u.custom_domain, u.embed_enabled
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
                'restaurant_name' => $restaurant['restaurant_name'],
                'custom_domain' => $restaurant['custom_domain'] ?? '',
                'embed_enabled' => !empty($restaurant['embed_enabled'])
            ];
        }
    }
    
    return null;
}

// If we have a restaurant slug, find the restaurant
if (!empty($restaurant_slug)) {
    $restaurant_info = findRestaurantBySlug($conn, $restaurant_slug);
    if ($restaurant_info) {
        $restaurant_id = $restaurant_info['restaurant_id'];
        $restaurant_name = $restaurant_info['restaurant_name'];
    }
}

// If restaurant found, check if it has a custom domain and redirect there
if ($restaurant_id) {
    // Use custom_domain info from findRestaurantBySlug() result (no extra DB query needed)
    $has_custom_domain = !empty($restaurant_info['custom_domain']) && !empty($restaurant_info['embed_enabled']);
    $custom_domain_url = '';
    if ($has_custom_domain) {
        $domain = preg_replace('#^https?://#', '', trim($restaurant_info['custom_domain']));
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $custom_domain_url = $scheme . '://' . $domain . '/';
    }
    
    // Also check if currently on a custom domain
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (strpos($host, ':') !== false) $host = explode(':', $host)[0];
    $is_custom_request = $host !== 'restrogrow.com' && $host !== 'localhost' && $host !== '127.0.0.1' && strpos($host, 'hstgr.io') === false;
    
    if ($has_custom_domain && !$is_custom_request) {
        // Restaurant has a custom domain and visitor is NOT already on it - redirect to custom domain
        header('Location: ' . $custom_domain_url);
    } elseif ($is_custom_request) {
        // Already on the custom domain - just show the homepage
        header('Location: /');
    } elseif ($restaurant_slug) {
        $root = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        header('Location: ' . $root . '/' . urlencode($restaurant_slug));
    } else {
        header('Location: index.php?restaurant_id=' . urlencode($restaurant_id));
    }
    exit();
} else {
    // Restaurant not found, show error or redirect to default
    http_response_code(404);
    die('Restaurant not found. Please check the URL.');
}
?>

