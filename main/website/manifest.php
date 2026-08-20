<?php
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/db_config.php';

// Local placeholder SVG (defined here since manifest.php doesn't include header.php)
$local_placeholder_svg = 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="192" height="192" viewBox="0 0 192 192"><rect width="100%" height="100%" fill="#f0f0f0"/><text x="50%" y="50%" font-family="Arial,sans-serif" font-size="16" fill="#999" text-anchor="middle" dy=".3em">Logo</text></svg>');

$restaurantId = $_GET['restaurant_id'] ?? '';
$slug = '';
$name = 'Restaurant';
$description = 'Order your favorite food online';
$iconUrl = $local_placeholder_svg;
$iconType = 'image/svg+xml';

// Script is at /<project>/main/website/manifest.php
// Site root = /<project>/
$siteRoot = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));
$siteRoot = rtrim($siteRoot, '/') . '/';

if ($restaurantId && function_exists('getConnection')) {
    try {
        $conn = getConnection();
        // logo_data/logo_mime_type may not exist on a DB that hasn't run
        // that migration yet (image.php has the same fallback) — try the
        // full query first, then degrade to the columns guaranteed to
        // exist so a missing column never breaks the whole manifest.
        try {
            $stmt = $conn->prepare("SELECT restaurant_name, id, logo_mime_type, logo_data, restaurant_logo FROM users WHERE restaurant_id = ? LIMIT 1");
            $stmt->execute([$restaurantId]);
        } catch (PDOException $e) {
            $stmt = $conn->prepare("SELECT restaurant_name, id, restaurant_logo FROM users WHERE restaurant_id = ? LIMIT 1");
            $stmt->execute([$restaurantId]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $name = $row['restaurant_name'] ?? $name;
            $slug = strtolower($name);
            $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
            $slug = trim($slug, '-');

            // The manifest's declared icon 'type' must match what
            // main/api/image.php actually serves for this restaurant, or
            // Chrome rejects the icon outright ("Download error or resource
            // isn't a valid image"). image.php falls back to an SVG
            // placeholder whenever there's no real (decodable) logo, so this
            // has to make the same decodability check — some restaurants
            // have a logo_data row that exists but is corrupted historical
            // data (bytes that don't decode as any image), which would
            // otherwise still get declared here as a working image/png icon.
            if (!empty($row['logo_data']) && @getimagesizefromstring($row['logo_data']) !== false) {
                $iconUrl = $siteRoot . 'main/api/image.php?type=logo&id=' . $row['id'];
                $iconType = !empty($row['logo_mime_type']) ? $row['logo_mime_type'] : 'image/jpeg';
            } elseif (!empty($row['restaurant_logo']) && strpos($row['restaurant_logo'], 'db:') !== 0) {
                $iconUrl = $siteRoot . 'main/api/image.php?type=logo&id=' . $row['id'];
                $ext = strtolower(pathinfo(parse_url($row['restaurant_logo'], PHP_URL_PATH) ?: $row['restaurant_logo'], PATHINFO_EXTENSION));
                $extToMime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml'];
                $iconType = $extToMime[$ext] ?? 'image/jpeg';
            }
            // Otherwise: no logo at all — keep the local placeholder SVG
            // (already set above) instead of pointing at image.php, since
            // that endpoint would also just return a placeholder SVG but
            // under a URL the manifest has no way to know the real type of.
        }
    } catch (Exception $e) {
    }
}

$startUrl = $slug ? $siteRoot . $slug : $siteRoot . 'main/website/index.php' . ($restaurantId ? '?restaurant_id=' . urlencode($restaurantId) : '');

header('Content-Type: application/json');

echo json_encode([
    'name' => $name,
    'short_name' => mb_substr($name, 0, 12),
    'description' => $description,
    'start_url' => $startUrl,
    'scope' => $siteRoot,
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => '#ffffff',
    'theme_color' => '#1a3934',
    'prefer_related_applications' => false,
    'icons' => [
        [
            'src' => $iconUrl,
            'sizes' => '192x192',
            'type' => $iconType,
            'purpose' => 'any maskable'
        ],
        [
            'src' => $iconUrl,
            'sizes' => '512x512',
            'type' => $iconType,
            'purpose' => 'any maskable'
        ]
    ]
]);
