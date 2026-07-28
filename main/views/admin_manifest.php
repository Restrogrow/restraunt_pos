<?php
header('Content-Type: application/json; charset=UTF-8');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')));
if ($basePath === '/' || $basePath === '\\') $basePath = '';
$siteUrl = $scheme . '://' . $host . $basePath;

$restaurantId = $_GET['restaurant_id'] ?? '';
$startUrl = $siteUrl . '/main/views/dashboard.php' . ($restaurantId ? '?restaurant_id=' . urlencode($restaurantId) : '');

echo json_encode([
    'name' => 'Restro Grow - Admin',
    'short_name' => 'Admin Panel',
    'description' => 'Restaurant Management Admin Panel',
    'start_url' => $startUrl,
    'scope' => $siteUrl . '/',
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => '#ffffff',
    'theme_color' => '#1a3934',
    'prefer_related_applications' => false,
    'icons' => [
        [
            'src' => $siteUrl . '/main/assets/images/logo-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any maskable',
        ],
        [
            'src' => $siteUrl . '/main/assets/images/logo-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable',
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
