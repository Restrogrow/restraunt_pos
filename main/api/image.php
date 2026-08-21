<?php
// Image serving script - supports both file-based and database-stored images
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Ensure no output before headers
while (ob_get_level()) {
    ob_end_clean();
}
ob_start(); // Start fresh buffer

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

// Some restaurants have BLOB image columns holding corrupted data from a
// historical incident predating this DB (spot-checked: logo_data,
// menu_items.image_data, website_banners.banner_data, and
// business_qr_code_data all have rows whose bytes were mangled — e.g. valid
// PNG/JPEG signature bytes replaced by the UTF-8 replacement character,
// EF BF BD). That data can't be recovered here (the original bytes are
// gone); serving it as-is just returns a 200 with a Content-Type that
// claims "image" over bytes no decoder can read, which is exactly the
// "isn't a valid image" console error this guards against. getimagesizefromstring()
// is a real decode attempt (not just a magic-byte peek), so anything that
// fails it is safe to treat as unusable and fall back to the placeholder.
function isValidImageBytes($data) {
    if (empty($data)) return false;
    return @getimagesizefromstring($data) !== false;
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
$imageType = $_GET['type'] ?? ''; // 'logo', 'item', 'banner', or 'business_qr'
$imageId = $_GET['id'] ?? '';

// Local image library reference (images/<Folder>/<file>, e.g. from AI menu
// auto-matching): serve straight from disk, no DB lookup needed.
$localRef = '';
if (strpos($imagePath, 'local:') === 0) $localRef = $imagePath;
elseif (strpos($imageId, 'local:') === 0) $localRef = $imageId;
if ($localRef !== '') {
    require_once __DIR__ . '/../includes/menu_image_matcher.php';
    $libraryBase = menu_image_library_root();
    $fullPath = false;
    if ($libraryBase) {
        $rel = ltrim(str_replace('\\', '/', substr($localRef, strlen('local:'))), '/');
        $real = realpath($libraryBase . '/' . $rel);
        if ($real !== false && ($real === $libraryBase || strpos($real, $libraryBase . DIRECTORY_SEPARATOR) === 0)) {
            $fullPath = $real;
        }
    }
    if ($fullPath !== false && is_file($fullPath)) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $fullPath);
        finfo_close($finfo);
        ob_end_clean();
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($fullPath));
        header('Cache-Control: public, max-age=31536000');
        readfile($fullPath);
        exit();
    }
    sendPlaceholderSvg('Image');
}

// Determine cache duration: 1 day for menu images (they change when admin uploads new ones)
// 1 year for everything else (logos, banners, QR codes, etc.)
$isMenuImage = ($imageType === 'menu') || (!empty($imagePath) && strpos($imagePath, 'db:') === 0);
$cacheMaxAge = $isMenuImage ? 86400 : 31536000; // 86400 = 1 day, 31536000 = 1 year

// FIRST: Check if image data exists in database as BLOB (for menu items)
// This handles cases where item_image has any value (file path or db: prefix) but image_data BLOB exists
// BLOB data always takes priority over file-based or db: reference
if (!empty($imagePath) && strpos($imagePath, 'http') !== 0 && empty($imageType)) {
    try {
        // Strategy 1: Exact path match (works for both db: and file paths)
        $stmt = $conn->prepare("SELECT id, image_data, image_mime_type FROM menu_items WHERE item_image = ? AND image_data IS NOT NULL LIMIT 1");
        $stmt->execute([$imagePath]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($item && !empty($item['image_data']) && isValidImageBytes($item['image_data'])) {
            ob_end_clean();
            header('Content-Type: ' . ($item['image_mime_type'] ?? 'image/jpeg'));
            header('Content-Length: ' . strlen($item['image_data']));
            header('Cache-Control: public, max-age=' . $cacheMaxAge);
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $cacheMaxAge) . ' GMT');
            echo $item['image_data'];
            exit();
        }
        
        // Strategy 2: For db: paths, extract identifier and try direct match
        if (strpos($imagePath, 'db:') === 0) {
            $dbId = substr($imagePath, 3); // Remove 'db:' prefix
            $stmt2 = $conn->prepare("SELECT id, image_data, image_mime_type FROM menu_items WHERE item_image = ? AND image_data IS NOT NULL LIMIT 1");
            $stmt2->execute([$imagePath]); // Try full path again
            $item = $stmt2->fetch(PDO::FETCH_ASSOC);
            
            if ($item && !empty($item['image_data']) && isValidImageBytes($item['image_data'])) {
                ob_end_clean();
                header('Content-Type: ' . ($item['image_mime_type'] ?? 'image/jpeg'));
                header('Content-Length: ' . strlen($item['image_data']));
                header('Cache-Control: public, max-age=' . $cacheMaxAge);
                header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $cacheMaxAge) . ' GMT');
                echo $item['image_data'];
                exit();
            }
        }
        
        // Strategy 3: Filename match (if exact match failed) - for file-based paths
        if (!$item || empty($item['image_data'])) {
            $filename = basename($imagePath);
            // Remove query strings or fragments from filename
            $filename = preg_replace('/[?#].*$/', '', $filename);
            $stmt3 = $conn->prepare("SELECT id, image_data, image_mime_type FROM menu_items WHERE (item_image LIKE ? OR item_image LIKE ? OR item_image = ?) AND image_data IS NOT NULL LIMIT 1");
            $stmt3->execute(['%' . $filename, '%/' . $filename . '%', $imagePath]);
            $item = $stmt3->fetch(PDO::FETCH_ASSOC);
        }
        
        // Strategy 4: Check if path contains a unique identifier (like item ID or hash)
        // Extract any alphanumeric identifier from the path
        if (!$item || empty($item['image_data'])) {
            preg_match('/([a-f0-9]{10,})/i', $imagePath, $matches);
            if (!empty($matches[1])) {
                $identifier = $matches[1];
                $stmt4 = $conn->prepare("SELECT id, image_data, image_mime_type FROM menu_items WHERE (item_image LIKE ? OR item_image LIKE ?) AND image_data IS NOT NULL LIMIT 1");
                $stmt4->execute(['%' . $identifier . '%', 'db:' . $identifier]);
                $item = $stmt4->fetch(PDO::FETCH_ASSOC);
            }
        }
        
        if ($item && !empty($item['image_data']) && isValidImageBytes($item['image_data'])) {
            ob_end_clean();
            header('Content-Type: ' . ($item['image_mime_type'] ?? 'image/jpeg'));
            header('Content-Length: ' . strlen($item['image_data']));
            header('Cache-Control: public, max-age=31536000');
            echo $item['image_data'];
            exit();
        }
    } catch (PDOException $e) {
        // If query fails, continue to other checks
        error_log("Database BLOB check failed for path '$imagePath': " . $e->getMessage());
    } catch (Exception $e) {
        error_log("Unexpected error in BLOB check for path '$imagePath': " . $e->getMessage());
    }
}

// Handle bare id parameter without type/path (used by admin addons page, etc.)
// format: image.php?id=692188df76bd5
if (empty($imagePath) && empty($imageType) && !empty($imageId)) {
    try {
        $stmt = $conn->prepare("SELECT image_data, image_mime_type FROM meal_addons WHERE addon_image = ? AND image_data IS NOT NULL LIMIT 1");
        $stmt->execute(['db:' . $imageId]);
        $addon = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($addon && !empty($addon['image_data'])) {
            ob_end_clean();
            header('Content-Type: ' . ($addon['image_mime_type'] ?? 'image/jpeg'));
            header('Content-Length: ' . strlen($addon['image_data']));
            header('Cache-Control: public, max-age=86400');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
            echo $addon['image_data'];
            exit();
        }
    } catch (PDOException $e) {
        error_log("Addon image lookup failed (bare id): " . $e->getMessage());
    }

    // Fallback: menu_items by item_image = 'db:' . id (mirrors website/image.php convenience lookup)
    try {
        $stmt = $conn->prepare("SELECT image_data, image_mime_type FROM menu_items WHERE item_image = ? AND image_data IS NOT NULL LIMIT 1");
        $stmt->execute(['db:' . $imageId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($item && !empty($item['image_data']) && isValidImageBytes($item['image_data'])) {
            ob_end_clean();
            header('Content-Type: ' . ($item['image_mime_type'] ?? 'image/jpeg'));
            header('Content-Length: ' . strlen($item['image_data']));
            header('Cache-Control: public, max-age=86400');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
            echo $item['image_data'];
            exit();
        }
    } catch (PDOException $e) {
        error_log("Menu item image lookup failed (bare id): " . $e->getMessage());
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

                if ($logo && !empty($logo['logo_data']) && isValidImageBytes($logo['logo_data'])) {
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
        } elseif ($imageType === 'menu' && !empty($imageId)) {
            // Get menu image
            try {
                // First try to get BLOB image data
                // Use PDO::FETCH_ASSOC and ensure BLOB is read correctly
                $stmt = $conn->prepare("SELECT menu_image_data, menu_image_mime_type, menu_image FROM menu WHERE id = ? LIMIT 1");
                $stmt->execute([$imageId]);
                $menu = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($menu) {
                    // Priority 1: BLOB data (menu_image_data)
                    // Check if BLOB data exists and is not empty
                    if (isset($menu['menu_image_data']) && $menu['menu_image_data'] !== null && $menu['menu_image_data'] !== '') {
                        // Ensure we have valid binary data
                        $imageData = $menu['menu_image_data'];
                        $dataLength = strlen($imageData);

                        // Only proceed if we have actual, decodable image data
                        if ($dataLength > 0 && isValidImageBytes($imageData)) {
                            ob_end_clean();
                            $mimeType = !empty($menu['menu_image_mime_type']) ? $menu['menu_image_mime_type'] : 'image/jpeg';
                            header('Content-Type: ' . $mimeType);
                            header('Content-Length: ' . $dataLength);
                            header('Cache-Control: public, max-age=86400'); // 1 day - menu images can change
                            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
                            echo $imageData;
                            exit();
                        }
                    }
                    
                    // Priority 2: File path (menu_image)
                    if (!empty($menu['menu_image'])) {
                        $menuImagePath = $menu['menu_image'];
                        
                        // Handle db: prefix
                        if (strpos($menuImagePath, 'db:') === 0) {
                            // Already tried BLOB, so skip
                        } elseif (strpos($menuImagePath, 'http') === 0) {
                            // External URL - redirect
                            ob_end_clean();
                            header('Location: ' . $menuImagePath);
                            exit();
                        } else {
                        // File path - try to serve the file
                        $filePath = __DIR__ . '/../' . ltrim($menuImagePath, '/');
                        if (file_exists($filePath) && is_file($filePath)) {
                            ob_end_clean();
                            $mimeType = mime_content_type($filePath);
                            header('Content-Type: ' . ($mimeType ?: 'image/jpeg'));
                            header('Content-Length: ' . filesize($filePath));
                            header('Cache-Control: public, max-age=86400');
                            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
                            readfile($filePath);
                            exit();
                        }
                        }
                    }
                }
                
                sendPlaceholderSvg('Menu');
            } catch (PDOException $e) {
                // Log the error for debugging
                error_log("PDO Error fetching menu image (ID: $imageId): " . $e->getMessage());
                
                // If menu_image_data column doesn't exist, try file path
                if (strpos($e->getMessage(), 'menu_image_data') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
                    try {
                        $stmt = $conn->prepare("SELECT menu_image FROM menu WHERE id = ? LIMIT 1");
                        $stmt->execute([$imageId]);
                        $menu = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($menu && !empty($menu['menu_image'])) {
                            $menuImagePath = $menu['menu_image'];
                            if (strpos($menuImagePath, 'http') === 0) {
                                ob_end_clean();
                                header('Location: ' . $menuImagePath);
                                exit();
                            } else {
                                $filePath = __DIR__ . '/../' . ltrim($menuImagePath, '/');
                                if (file_exists($filePath) && is_file($filePath)) {
                                    ob_end_clean();
                                    $mimeType = mime_content_type($filePath);
                                    header('Content-Type: ' . ($mimeType ?: 'image/jpeg'));
                                    header('Content-Length: ' . filesize($filePath));
                                    header('Cache-Control: public, max-age=31536000');
                                    readfile($filePath);
                                    exit();
                                }
                            }
                        }
                    } catch (PDOException $e2) {
                        // Ignore and return 404
                    }
                }
                
                sendPlaceholderSvg('Menu');
            }
        } elseif ($imageType === 'banner' && !empty($imageId)) {
            // Get website banner
            try {
                $stmt = $conn->prepare("SELECT banner_data, banner_mime_type FROM website_banners WHERE id = ? LIMIT 1");
                $stmt->execute([$imageId]);
                $banner = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($banner && !empty($banner['banner_data']) && isValidImageBytes($banner['banner_data'])) {
                    header('Content-Type: ' . ($banner['banner_mime_type'] ?? 'image/jpeg'));
                    header('Content-Length: ' . strlen($banner['banner_data']));
                    header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
                    echo $banner['banner_data'];
                    exit();
                } else {
                    // Banner not found in database, try file-based fallback
                    $stmt = $conn->prepare("SELECT banner_path FROM website_banners WHERE id = ? LIMIT 1");
                    $stmt->execute([$imageId]);
                    $bannerPath = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($bannerPath && !empty($bannerPath['banner_path']) && strpos($bannerPath['banner_path'], 'db:') !== 0) {
                        // Use file-based path
                        $imagePath = $bannerPath['banner_path'];
                        // Fall through to file-based handling below
                    } else {
                        sendPlaceholderSvg('Banner');
                    }
                }
            } catch (PDOException $e) {
                // If banner_data column doesn't exist, try file-based fallback
                if (strpos($e->getMessage(), 'banner_data') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
                    error_log("Banner data column not found, trying file-based: " . $e->getMessage());
                    try {
                        $stmt = $conn->prepare("SELECT banner_path FROM website_banners WHERE id = ? LIMIT 1");
                        $stmt->execute([$imageId]);
                        $bannerPath = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($bannerPath && !empty($bannerPath['banner_path']) && strpos($bannerPath['banner_path'], 'db:') !== 0) {
                            $imagePath = $bannerPath['banner_path'];
                            // Fall through to file-based handling below
                        } else {
                            sendPlaceholderSvg('Banner');
                        }
                    } catch (PDOException $e2) {
                        sendPlaceholderSvg('Banner');
                    }
                } else {
                    throw $e;
                }
            }
        } elseif ($imageType === 'business_qr' && !empty($imageId)) {
            // Get business QR code from users table
            try {
                $stmt = $conn->prepare("SELECT business_qr_code_data, business_qr_code_mime_type FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$imageId]);
                $qr = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($qr && !empty($qr['business_qr_code_data']) && isValidImageBytes($qr['business_qr_code_data'])) {
                    header('Content-Type: ' . ($qr['business_qr_code_mime_type'] ?? 'image/jpeg'));
                    header('Content-Length: ' . strlen($qr['business_qr_code_data']));
                    header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
                    echo $qr['business_qr_code_data'];
                    exit();
                } else {
                    sendPlaceholderSvg('QR Code');
                }
            } catch (PDOException $e) {
                // If business_qr_code_data column doesn't exist
                if (strpos($e->getMessage(), 'business_qr_code_data') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
                    sendPlaceholderSvg('QR Code');
                } else {
                    throw $e;
                }
            }
        } elseif ($imageType === 'meal_plan' && !empty($imageId)) {
            // Meal-plan photo shown on the public subscribe page - intentionally
            // public, no auth, same as business_qr above.
            try {
                $stmt = $conn->prepare("SELECT image_data, image_mime_type FROM meal_plans WHERE id = ? LIMIT 1");
                $stmt->execute([$imageId]);
                $img = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($img && isValidImageBytes($img['image_data'] ?? null)) {
                    header('Content-Type: ' . ($img['image_mime_type'] ?? 'image/jpeg'));
                    header('Content-Length: ' . strlen($img['image_data']));
                    header('Cache-Control: public, max-age=31536000');
                    echo $img['image_data'];
                    exit();
                }
                sendPlaceholderSvg('Plan Photo');
            } catch (PDOException $e) {
                sendPlaceholderSvg('Plan Photo');
            }
        } elseif ($imageType === 'weekly_menu_item' && !empty($imageId)) {
            // Weekly-menu item photo - also public, shown on the same page.
            try {
                $stmt = $conn->prepare("SELECT image_data, image_mime_type FROM meal_plan_weekly_menu_items WHERE id = ? LIMIT 1");
                $stmt->execute([$imageId]);
                $img = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($img && isValidImageBytes($img['image_data'] ?? null)) {
                    header('Content-Type: ' . ($img['image_mime_type'] ?? 'image/jpeg'));
                    header('Content-Length: ' . strlen($img['image_data']));
                    header('Cache-Control: public, max-age=31536000');
                    echo $img['image_data'];
                    exit();
                }
                sendPlaceholderSvg('Dish Photo');
            } catch (PDOException $e) {
                sendPlaceholderSvg('Dish Photo');
            }
        } elseif ($imageType === 'payment_proof' && !empty($imageId)) {
            // Customer payment screenshots are private — require an authenticated
            // admin/staff session for the restaurant that owns the order, unlike
            // the other image types on this page which are intentionally public.
            require_once __DIR__ . '/../config/session_config.php';
            startSecureSession();
            require_once __DIR__ . '/../config/authorization_config.php';
            if (empty($_SESSION['restaurant_id']) || !hasPermission(PERMISSION_MANAGE_ORDERS)) {
                http_response_code(403);
                sendPlaceholderSvg('Forbidden');
            }
            try {
                $stmt = $conn->prepare("SELECT proof_data, proof_mime_type FROM payment_proofs WHERE order_id = ? AND restaurant_id = ? LIMIT 1");
                $stmt->execute([$imageId, $_SESSION['restaurant_id']]);
                $proof = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($proof && !empty($proof['proof_data'])) {
                    // Discard anything already sitting in the output buffer
                    // (e.g. stray whitespace leaked by an included config
                    // file) - otherwise it gets prepended to the image bytes
                    // and corrupts the file signature, same as every other
                    // branch on this page already does before echoing binary data.
                    ob_end_clean();
                    header('Content-Type: ' . ($proof['proof_mime_type'] ?? 'image/jpeg'));
                    header('Content-Length: ' . strlen($proof['proof_data']));
                    header('Cache-Control: private, max-age=3600');
                    echo $proof['proof_data'];
                    exit();
                }
                sendPlaceholderSvg('No Proof');
            } catch (PDOException $e) {
                sendPlaceholderSvg('No Proof');
            }
        } elseif (!empty($imagePath) && strpos($imagePath, 'db:') === 0) {
            // Extract ID from path (format: db:uniqueid)
            $dbId = str_replace('db:', '', $imagePath);
            
            // Try to find menu item by item_image reference
            $stmt = $conn->prepare("SELECT image_data, image_mime_type FROM menu_items WHERE item_image = ? LIMIT 1");
            $stmt->execute([$imagePath]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($item && !empty($item['image_data']) && isValidImageBytes($item['image_data'])) {
                ob_end_clean();
                header('Content-Type: ' . ($item['image_mime_type'] ?? 'image/jpeg'));
                header('Content-Length: ' . strlen($item['image_data']));
                header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
                echo $item['image_data'];
                exit();
            }
        } elseif (!empty($imagePath) && strpos($imagePath, 'db:') !== 0) {
            // Old file-based image path - check if it exists in database as image_data first
            // This handles cases where old images might have been migrated to database
            try {
                $stmt = $conn->prepare("SELECT image_data, image_mime_type FROM menu_items WHERE item_image = ? AND image_data IS NOT NULL LIMIT 1");
                $stmt->execute([$imagePath]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($item && !empty($item['image_data']) && isValidImageBytes($item['image_data'])) {
                    ob_end_clean();
                    header('Content-Type: ' . ($item['image_mime_type'] ?? 'image/jpeg'));
                    header('Content-Length: ' . strlen($item['image_data']));
                    header('Cache-Control: public, max-age=31536000');
                    echo $item['image_data'];
                    exit();
                }
            } catch (PDOException $e) {
                // If query fails, fall through to file-based handling
                error_log("Database check for old image failed: " . $e->getMessage());
            }
        }
        
        // If not found in database and no file path provided, return 404
        if (empty($imagePath)) {
            http_response_code(404);
            header('Content-Type: text/plain');
            exit('Image not found');
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

// Fallback: File-based image (backward compatibility for old images)
// Security check - only allow images from uploads directory
if (empty($imagePath)) {
    sendPlaceholderSvg('Image');
}

// Redirect external URLs directly
if (strpos($imagePath, 'http') === 0) {
    ob_end_clean();
    header('Location: ' . $imagePath);
    exit();
}

// Skip file-based handling if it's a database reference
if (strpos($imagePath, 'db:') === 0) {
    sendPlaceholderSvg('Image');
}

// Normalize path - handle various path formats
$normalizedPath = $imagePath;

// Check if path already contains uploads
$hasUploadsPath = (
    stripos($imagePath, 'uploads/') !== false || 
    stripos($imagePath, 'uploads\\') !== false
);

// If path doesn't contain uploads, prepend it
if (!$hasUploadsPath) {
    $normalizedPath = 'uploads/' . ltrim($imagePath, '/\\');
}

// Normalize path separators and remove unsafe patterns
$normalizedPath = str_replace('\\', '/', $normalizedPath);
$normalizedPath = preg_replace('#\.\./#', '', $normalizedPath); // Remove ../
$normalizedPath = ltrim($normalizedPath, '/');

// Ensure it starts with uploads/
if (strpos($normalizedPath, 'uploads/') !== 0) {
    $normalizedPath = 'uploads/' . $normalizedPath;
}

// Build full path from root - try multiple possible locations (Hostinger-friendly)
$rootDir = dirname(__DIR__);
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
$scriptDir = __DIR__;

// Detect if running on Hostinger (check for common Hostinger paths)
$isHostinger = (
    strpos($docRoot, 'public_html') !== false ||
    strpos($docRoot, 'domains') !== false ||
    strpos($_SERVER['SERVER_NAME'] ?? '', 'hstgr.io') !== false ||
    strpos($_SERVER['HTTP_HOST'] ?? '', 'restrogrow.com') !== false
);

$possiblePaths = [];
if ($isHostinger) {
    // Hostinger-specific paths (usually public_html is document root)
    // Based on diagnostic: uploads is at /home/u509616587/domains/restrogrow.com/public_html/uploads
    $possiblePaths = [
        $docRoot . '/' . $normalizedPath,  // From document root (most common on Hostinger) - /public_html/uploads/...
        $rootDir . '/public_html/' . $normalizedPath,  // From project root + public_html
        $rootDir . '/' . $normalizedPath,  // From project root (if uploads is at root level)
        $scriptDir . '/../' . $normalizedPath, // Relative from api/ (goes to root, then uploads)
        dirname($docRoot) . '/' . $normalizedPath, // One level up from doc root
        '/home/' . get_current_user() . '/public_html/' . $normalizedPath, // Absolute Hostinger path
        $docRoot . '/../' . $normalizedPath, // One level up from public_html
    ];
} else {
    // Local development paths
    $possiblePaths = [
        $rootDir . '/' . $normalizedPath,  // Standard path
        $scriptDir . '/../' . $normalizedPath, // Relative from api/
        $docRoot . '/' . $normalizedPath, // From document root
        $normalizedPath, // Absolute path (if already full)
    ];
}

$fullPath = null;
foreach ($possiblePaths as $path) {
    // Normalize path separators
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $path = realpath($path); // Resolve any .. or . in path
    
    if ($path && file_exists($path) && is_readable($path)) {
        // Security: Ensure path is within allowed directory
        $allowedDir = realpath($rootDir . '/uploads') ?: realpath($docRoot . '/uploads');
        if ($allowedDir && strpos($path, $allowedDir) === 0) {
            $fullPath = $path;
            break;
        }
    }
}

// Check if file exists
if (!$fullPath || !file_exists($fullPath)) {
    // Before returning 404, try comprehensive database check for BLOB data
    // This handles cases where file doesn't exist but BLOB might
    if (!empty($imagePath) && strpos($imagePath, 'http') !== 0 && empty($imageType)) {
        try {
            $filename = basename($imagePath);
            $filename = preg_replace('/[?#].*$/', '', $filename); // Remove query strings
            
            // Comprehensive BLOB search - try multiple strategies
            $finalStmt = $conn->prepare("
                SELECT image_data, image_mime_type 
                FROM menu_items 
                WHERE image_data IS NOT NULL 
                AND (
                    item_image = ? 
                    OR item_image LIKE ? 
                    OR item_image LIKE ?
                    OR item_image LIKE ?
                )
                LIMIT 1
            ");
            $finalStmt->execute([
                $imagePath,
                '%' . $filename,
                '%/' . $filename . '%',
                'uploads/%' . $filename . '%'
            ]);
            $finalItem = $finalStmt->fetch(PDO::FETCH_ASSOC);
            
            // If still not found, try identifier matching
            if (!$finalItem || empty($finalItem['image_data'])) {
                preg_match('/([a-f0-9]{10,})/i', $imagePath, $matches);
                if (!empty($matches[1])) {
                    $identifier = $matches[1];
                    $finalStmt2 = $conn->prepare("SELECT image_data, image_mime_type FROM menu_items WHERE (item_image LIKE ? OR item_image LIKE ?) AND image_data IS NOT NULL LIMIT 1");
                    $finalStmt2->execute(['%' . $identifier . '%', 'db:' . $identifier]);
                    $finalItem = $finalStmt2->fetch(PDO::FETCH_ASSOC);
                }
            }
            
            if ($finalItem && !empty($finalItem['image_data']) && isValidImageBytes($finalItem['image_data'])) {
                ob_end_clean();
                header('Content-Type: ' . ($finalItem['image_mime_type'] ?? 'image/jpeg'));
                header('Content-Length: ' . strlen($finalItem['image_data']));
                header('Cache-Control: public, max-age=31536000');
                echo $finalItem['image_data'];
                exit();
            }
        } catch (PDOException $e) {
            // Ignore and continue to 404
            error_log("Final BLOB check failed: " . $e->getMessage());
        }
    }
    
    http_response_code(404);
    header('Content-Type: text/plain');
    
    // Enhanced logging for Hostinger debugging
    $debugInfo = [
        'environment' => $isHostinger ? 'Hostinger' : 'Local',
        'requested_path' => $imagePath,
        'normalized_path' => $normalizedPath,
        'root_dir' => $rootDir,
        'document_root' => $docRoot,
        'script_dir' => $scriptDir,
        'tried_paths' => $possiblePaths,
        'current_user' => get_current_user(),
        'server_name' => $_SERVER['SERVER_NAME'] ?? 'unknown',
        'http_host' => $_SERVER['HTTP_HOST'] ?? 'unknown'
    ];
    
    error_log("Image not found: " . json_encode($debugInfo));
    sendPlaceholderSvg('Image');
}

// Get file info
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $fullPath);
finfo_close($finfo);

// Validate MIME type is an image
if (strpos($mimeType, 'image/') !== 0) {
    error_log("File is not an image. MIME type: " . $mimeType . ", Path: " . $fullPath);
    sendPlaceholderSvg('Image');
}

// Clear any output buffer before sending image
ob_end_clean();

// Set appropriate headers
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');

// Output the image
readfile($fullPath);
exit();
?>
