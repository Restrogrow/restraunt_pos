<?php
// Image serving script - supports both file-based and database-stored images
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Ensure no output before headers
if (ob_get_level()) {
    ob_clean();
}

// Helper: return a placeholder SVG so the browser doesn't show a broken image
function sendPlaceholderSvg($text = 'Image', $width = 200, $height = 200) {
    ob_end_clean();
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">
        <rect width="100%" height="100%" fill="#f0f0f0"/>
        <text x="50%" y="50%" font-family="Arial,sans-serif" font-size="14" fill="#999" text-anchor="middle" dy=".3em">' . htmlspecialchars($text) . '</text>
    </svg>';
    header('Content-Type: image/svg+xml');
    header('Content-Length: ' . strlen($svg));
    header('Cache-Control: public, max-age=3600');
    echo $svg;
    exit();
}

// Include database connection
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    header('Content-Type: text/plain');
    exit('Database connection not available');
}

// Get connection using getConnection() for lazy connection support
if (function_exists('getConnection')) {
    $conn = getConnection();
} else {
    // Fallback to $pdo if getConnection() doesn't exist (backward compatibility)
    global $pdo;
    $conn = $pdo ?? null;
    if (!$conn) {
        http_response_code(500);
        header('Content-Type: text/plain');
        exit('Database connection not available');
    }
}

$imagePath = $_GET['path'] ?? '';
$imageType = $_GET['type'] ?? ''; // 'logo', 'item', or 'banner'
$imageId = $_GET['id'] ?? '';

// FIRST: Check if image data exists in database as BLOB (for menu items)
// This handles cases where item_image has any value (file path or db: prefix) but image_data BLOB exists
// BLOB data always takes priority over file-based or db: reference
if (!empty($imagePath) && strpos($imagePath, 'http') !== 0 && empty($imageType)) {
    try {
        // First try: Check for BLOB data by matching the item_image value exactly
        $stmt = $conn->prepare("SELECT id, image_data, image_mime_type FROM menu_items WHERE item_image = ? AND image_data IS NOT NULL LIMIT 1");
        $stmt->execute([$imagePath]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If not found by path, try to find by filename match
        if (!$item || empty($item['image_data'])) {
            $filename = basename($imagePath);
            $stmt2 = $conn->prepare("SELECT id, image_data, image_mime_type FROM menu_items WHERE (item_image LIKE ? OR item_image = ?) AND image_data IS NOT NULL LIMIT 1");
            $stmt2->execute(['%' . $filename . '%', $imagePath]);
            $item = $stmt2->fetch(PDO::FETCH_ASSOC);
        }
        
        if ($item && !empty($item['image_data'])) {
            ob_end_clean();
            header('Content-Type: ' . ($item['image_mime_type'] ?? 'image/jpeg'));
            header('Content-Length: ' . strlen($item['image_data']));
            header('Cache-Control: public, max-age=31536000');
            echo $item['image_data'];
            exit();
        }
    } catch (PDOException $e) {
        // If query fails, continue to other checks
        error_log("Database BLOB check failed: " . $e->getMessage());
    }
}

// Handle bare id parameter without type (from getImageUrl() in index.php/menu.php)
if (empty($imagePath) && empty($imageType) && !empty($imageId)) {
    try {
        $stmt = $conn->prepare("SELECT image_data, image_mime_type FROM menu_items WHERE item_image = ? AND image_data IS NOT NULL LIMIT 1");
        $stmt->execute(['db:' . $imageId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($item && !empty($item['image_data'])) {
            ob_end_clean();
            header('Content-Type: ' . ($item['image_mime_type'] ?? 'image/jpeg'));
            header('Content-Length: ' . strlen($item['image_data']));
            header('Cache-Control: public, max-age=31536000');
            echo $item['image_data'];
            exit();
        }
    } catch (PDOException $e) {
        error_log("Image ID lookup failed: " . $e->getMessage());
    }
}

// Check if this is a database-stored image (starts with 'db:') or type-specific request
if (strpos($imagePath, 'db:') === 0 || !empty($imageType)) {
    // Retrieve image from database
    try {
        if ($imageType === 'logo' && !empty($imageId)) {
            // Get restaurant logo
            try {
                $stmt = $conn->prepare("SELECT logo_data, logo_mime_type, restaurant_logo FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$imageId]);
                $logo = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($logo && !empty($logo['logo_data'])) {
                    ob_end_clean();
                    header('Content-Type: ' . ($logo['logo_mime_type'] ?? 'image/jpeg'));
                    header('Content-Length: ' . strlen($logo['logo_data']));
                    header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
                    echo $logo['logo_data'];
                    exit();
                } elseif ($logo && !empty($logo['restaurant_logo']) && strpos($logo['restaurant_logo'], 'db:') !== 0) {
                    // Logo is file-based, use the path for file-based handling
                    $imagePath = $logo['restaurant_logo'];
                    // Fall through to file-based handling below
                } else {
                    sendPlaceholderSvg('Logo');
                }
            } catch (PDOException $e) {
                // If logo_data column doesn't exist, try to get file-based logo
                if (strpos($e->getMessage(), 'logo_data') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
                    error_log("Logo data column not found, trying file-based: " . $e->getMessage());
                    try {
                        $stmt = $conn->prepare("SELECT restaurant_logo FROM users WHERE id = ? LIMIT 1");
                        $stmt->execute([$imageId]);
                        $logoRow = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($logoRow && !empty($logoRow['restaurant_logo']) && strpos($logoRow['restaurant_logo'], 'db:') !== 0) {
                            $imagePath = $logoRow['restaurant_logo'];
                            // Fall through to file-based handling below
                        } else {
                            sendPlaceholderSvg('Logo');
                        }
                    } catch (PDOException $e2) {
                        sendPlaceholderSvg('Logo');
                    }
                } else {
                    throw $e;
                }
            }
        } elseif ($imageType === 'banner' && !empty($imageId)) {
            // Get website banner by ID
            $stmt = $conn->prepare("SELECT banner_data, banner_mime_type FROM website_banners WHERE id = ? LIMIT 1");
            $stmt->execute([$imageId]);
            $banner = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($banner && !empty($banner['banner_data'])) {
                header('Content-Type: ' . ($banner['banner_mime_type'] ?? 'image/jpeg'));
                header('Content-Length: ' . strlen($banner['banner_data']));
                header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
                echo $banner['banner_data'];
                exit();
            }
        } elseif (!empty($imagePath) && strpos($imagePath, 'db:') === 0) {
            // Try to find menu item by item_image reference
            $stmt = $conn->prepare("SELECT image_data, image_mime_type FROM menu_items WHERE item_image = ? LIMIT 1");
            $stmt->execute([$imagePath]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($item && !empty($item['image_data'])) {
                header('Content-Type: ' . ($item['image_mime_type'] ?? 'image/jpeg'));
                header('Content-Length: ' . strlen($item['image_data']));
                header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
                echo $item['image_data'];
                exit();
            }
            
            // Try to find banner by banner_path reference
            $stmt = $conn->prepare("SELECT banner_data, banner_mime_type FROM website_banners WHERE banner_path = ? LIMIT 1");
            $stmt->execute([$imagePath]);
            $banner = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($banner && !empty($banner['banner_data'])) {
                header('Content-Type: ' . ($banner['banner_mime_type'] ?? 'image/jpeg'));
                header('Content-Length: ' . strlen($banner['banner_data']));
                header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
                echo $banner['banner_data'];
                exit();
            }
        }
        
        // If not found in database and no file path provided, return placeholder
        if (empty($imagePath)) {
            sendPlaceholderSvg('Image');
        }
        // Otherwise, fall through to file-based handling
        
    } catch (PDOException $e) {
        error_log("Error retrieving image from database: " . $e->getMessage());
        // If columns don't exist, try to fall back to file-based
        if (strpos($e->getMessage(), 'logo_data') !== false || strpos($e->getMessage(), 'image_data') !== false || strpos($e->getMessage(), 'banner_data') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
            // Columns don't exist yet, fall through to file-based handling
        } else {
            http_response_code(500);
            header('Content-Type: text/plain');
            exit('Error loading image');
        }
    }
}

// Fallback: File-based image (backward compatibility)
// Security check - only allow images from uploads directory
if (empty($imagePath) || strpos($imagePath, 'uploads/') !== 0) {
    sendPlaceholderSvg('Image');
}

// Check if file exists
if (!file_exists($imagePath)) {
    sendPlaceholderSvg('Image');
}

// Get file info
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $imagePath);
finfo_close($finfo);

// Set appropriate headers
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($imagePath));
header('Cache-Control: public, max-age=31536000'); // Cache for 1 year

// Output the image
readfile($imagePath);
?>

