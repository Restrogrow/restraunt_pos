<?php
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$restaurant_id = $_SESSION['restaurant_id'] ?? $_GET['restaurant_id'] ?? '';
if (!$restaurant_id && isset($_SESSION['superadmin_id'])) {
    $restaurant_id = $_GET['restaurant_id'] ?? '';
}
if (!$restaurant_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No restaurant selected']);
    exit;
}

try {
    require_once __DIR__ . '/../db_connection.php';
    $conn = getConnection();

    $stmt = $conn->prepare("SELECT embed_enabled, custom_domain, restaurant_id FROM users WHERE restaurant_id = ? LIMIT 1");
    $stmt->execute([$restaurant_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'restrogrow.com';
    $basePath = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($basePath === '/' || $basePath === '\\') $basePath = '';
    $baseUrl = $scheme . '://' . $host . $basePath;

    $embed_code = '<div id="rg-restaurant-menu"></div>' . "\n";
    $embed_code .= '<script src="' . $baseUrl . '/main/embed/embed.js?restaurant=' . urlencode($restaurant_id) . '"></script>';

    $iframe_src = $baseUrl . '/main/website/index.php?restaurant_id=' . urlencode($restaurant_id);

    $custom_domain_embed_code = '<!DOCTYPE html>' . "\n";
    $custom_domain_embed_code .= '<html lang="en">' . "\n";
    $custom_domain_embed_code .= '<head>' . "\n";
    $custom_domain_embed_code .= '    <meta charset="UTF-8">' . "\n";
    $custom_domain_embed_code .= '    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">' . "\n";
    $custom_domain_embed_code .= '    <style>' . "\n";
    $custom_domain_embed_code .= '        body, html {' . "\n";
    $custom_domain_embed_code .= '            margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden; background-color: #f1f4f9;' . "\n";
    $custom_domain_embed_code .= '        }' . "\n";
    $custom_domain_embed_code .= '        .iframe-wrapper {' . "\n";
    $custom_domain_embed_code .= '            position: absolute; top: 0; left: 0; right: 0; bottom: 0;' . "\n";
    $custom_domain_embed_code .= '            width: 100%; height: 100dvh; -webkit-overflow-scrolling: touch; overflow-y: auto;' . "\n";
    $custom_domain_embed_code .= '        }' . "\n";
    $custom_domain_embed_code .= '        .iframe-wrapper iframe {' . "\n";
    $custom_domain_embed_code .= '            width: 100%; height: 100%; border: none; display: block;' . "\n";
    $custom_domain_embed_code .= '        }' . "\n";
    $custom_domain_embed_code .= '    </style>' . "\n";
    $custom_domain_embed_code .= '</head>' . "\n";
    $custom_domain_embed_code .= '<body>' . "\n";
    $custom_domain_embed_code .= '    <div class="iframe-wrapper">' . "\n";
    $custom_domain_embed_code .= '        <iframe src="' . htmlspecialchars($iframe_src, ENT_QUOTES, 'UTF-8') . '"></iframe>' . "\n";
    $custom_domain_embed_code .= '    </div>' . "\n";
    $custom_domain_embed_code .= '</body>' . "\n";
    $custom_domain_embed_code .= '</html>';

    echo json_encode([
        'success' => true,
        'data' => [
            'embed_enabled' => $row ? (bool)($row['embed_enabled'] ?? false) : false,
            'custom_domain' => $row ? ($row['custom_domain'] ?? '') : '',
            'restaurant_id' => $restaurant_id,
            'embed_code' => $embed_code,
            'custom_domain_embed_code' => $custom_domain_embed_code,
            'server_ip' => $_SERVER['SERVER_ADDR'] ?? '',
            'main_domain' => $host,
        ]
    ]);
} catch (Exception $e) {
    error_log("Error in get_embed_settings.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}