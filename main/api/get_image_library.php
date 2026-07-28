<?php
// Suppress error display, log errors instead
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';

// Ensure no output before headers
if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Require permission to manage menu (the gallery exists to pick menu item photos)
requirePermission(PERMISSION_MANAGE_MENU);

// This feature is opt-in per restaurant, enabled only by the superadmin -
// enforce it here too, not just by hiding the nav link.
require_once __DIR__ . '/../db_connection.php';
$restaurant_id = getRestaurantId();
$galleryEnabled = false;
try {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT photo_gallery_enabled FROM users WHERE restaurant_id = ? LIMIT 1");
    $stmt->execute([$restaurant_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $galleryEnabled = $row && (int)$row['photo_gallery_enabled'] === 1;
} catch (PDOException $e) {
    // photo_gallery_enabled column not present yet (migration not run) - deny by default
    $galleryEnabled = false;
}
if (!$galleryEnabled) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Photo Gallery is not enabled for this restaurant.',
        'categories' => [],
        'images' => [],
    ]);
    exit();
}

try {
    require_once __DIR__ . '/../includes/menu_image_matcher.php';
    $library = menu_image_library_scan(); // ['Folder' => [['file'=>...,'keywords'=>[...]], ...]]

    $categories = [];
    foreach ($library as $folder => $entries) {
        if (empty($entries)) continue;
        $categories[] = [
            'name' => $folder,
            'count' => count($entries),
            'sample_thumbnail' => 'local:' . $folder . '/' . $entries[0]['file'],
        ];
    }
    // Alphabetical order reads better than filesystem scan order
    usort($categories, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });

    $images = [];
    $category = $_GET['category'] ?? null;
    if ($category !== null && isset($library[$category])) {
        foreach ($library[$category] as $entry) {
            $images[] = [
                'file' => $entry['file'],
                'src' => 'local:' . $category . '/' . $entry['file'],
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'categories' => $categories,
        'images' => $images,
    ]);
} catch (Exception $e) {
    error_log("Error in get_image_library.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred loading the photo gallery.',
        'categories' => [],
        'images' => [],
    ]);
    exit();
}
