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

// Script is at /<project>/main/website/manifest.php
// Site root = /<project>/
$siteRoot = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));
$siteRoot = rtrim($siteRoot, '/') . '/';

if ($restaurantId && function_exists('getConnection')) {
    try {
        $conn = getConnection();
        $stmt = $conn->prepare("SELECT restaurant_name, id FROM users WHERE restaurant_id = ? LIMIT 1");
        $stmt->execute([$restaurantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $name = $row['restaurant_name'] ?? $name;
            $slug = strtolower($name);
            $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
            $slug = trim($slug, '-');
            $iconUrl = $siteRoot . 'main/api/image.php?type=logo&id=' . $row['id'];
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
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ],
        [
            'src' => $iconUrl,
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ]
    ]
]);
