<?php
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/website_theme_helpers.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? 'get';
// When opened from admin, restaurant_id comes from session; when opened directly, query param can be used
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true); // Skip timeout validation for public customer website
$restaurant_id = $_GET['restaurant_id'] ?? ($_SESSION['restaurant_id'] ?? 'RES001');

// Get connection using getConnection() for lazy connection support
if (function_exists('getConnection')) {
    try {
        $conn = getConnection();
    } catch (Exception $e) {
        error_log("Error getting connection in theme_api.php: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }
} else {
    // Fallback to $pdo if getConnection() doesn't exist (backward compatibility)
    global $pdo;
    $conn = $pdo ?? null;
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection not available']);
        exit;
    }
}

try {
  ensureWebsiteThemeSchema($conn);

  if ($action === 'get') {
    $stmt = $conn->prepare('SELECT primary_red, dark_red, primary_yellow, banner_image, layout_columns, background_theme, logo_shape, logo_size, font_family, theme_preset, card_style, checkout_color, layout_style, header_style FROM website_settings WHERE restaurant_id = :rid');
    $stmt->execute([':rid' => $restaurant_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      $row = ['primary_red'=>'#F70000','dark_red'=>'#DA020E','primary_yellow'=>'#FFD100','banner_image'=>null,'layout_columns'=>2,'background_theme'=>null,'logo_shape'=>'circle','logo_size'=>90,'font_family'=>'Poppins','theme_preset'=>null,'card_style'=>'rounded','checkout_color'=>null,'layout_style'=>'grid','header_style'=>'hero'];
    } else {
      if (empty($row['font_family'])) { $row['font_family'] = 'Poppins'; }
      if (empty($row['card_style'])) { $row['card_style'] = 'rounded'; }
      if (empty($row['layout_style'])) { $row['layout_style'] = 'grid'; }
      if (empty($row['header_style'])) { $row['header_style'] = 'hero'; }
      // Ensure banner_image is null if empty string
      if (empty($row['banner_image']) || trim($row['banner_image']) === '') {
        $row['banner_image'] = null;
      }
      // Ensure background_theme is null if empty string
      if (empty($row['background_theme']) || trim($row['background_theme']) === '') {
        $row['background_theme'] = null;
      }
    }

    // Get all banners from website_banners table
    $bannersStmt = $conn->prepare('SELECT id, banner_path, display_order FROM website_banners WHERE restaurant_id = :rid ORDER BY display_order ASC, id ASC');
    $bannersStmt->execute([':rid' => $restaurant_id]);
    $banners = $bannersStmt->fetchAll(PDO::FETCH_ASSOC);
    $row['banners'] = $banners ?: [];

    echo json_encode(['success'=>true,'settings'=>$row,'presets'=>THEME_PRESETS]);
    exit;
  }
  
  if ($action === 'get_banners' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get banners (exclude binary data from JSON)
    $bannersStmt = $conn->prepare('SELECT id, banner_path, display_order FROM website_banners WHERE restaurant_id = :rid ORDER BY display_order ASC, id ASC');
    $bannersStmt->execute([':rid' => $restaurant_id]);
    $banners = $bannersStmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'banners' => $banners ?: []]);
    exit;
  }

  /**
   * Sanitize image URL fields to prevent stored XSS.
   * Only allows: http://, https://, data:image/* URLs, or null/empty.
   * Blocks javascript:, vbscript:, data:text/html, and other non-image protocols.
   */
  function sanitizeImageUrl($url) {
    if ($url === null || $url === '' || trim($url) === '') {
      return null;
    }
    $url = trim($url);
    // Only allow http://, https://, and data:image/* URLs
    if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
      // Also sanitize the URL itself - remove null bytes, control chars
      $url = str_replace(["\0", "\n", "\r", "\t"], '', $url);
      return $url;
    }
    if (strpos($url, 'data:') === 0) {
      // Only allow data:image/* URLs (not data:text/html which could contain scripts)
      if (strpos($url, 'data:image/') === 0) {
        return $url;
      }
    }
    // Block all other protocols (javascript:, vbscript:, data:text/html, etc.)
    return null;
  }

  if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $pr = $data['primary_red'] ?? '#F70000';
    $dr = $data['dark_red'] ?? '#DA020E';
    $py = $data['primary_yellow'] ?? '#FFD100';
    $bi = sanitizeImageUrl($data['banner_image'] ?? null);
    $lc = isset($data['layout_columns']) ? (int)$data['layout_columns'] : 2;
    $bt = sanitizeImageUrl($data['background_theme'] ?? null);
    $allowedFonts = ['Poppins', 'Playfair Display', 'Roboto', 'Montserrat', 'Nunito', 'Lora'];
    $ff = in_array($data['font_family'] ?? '', $allowedFonts, true) ? $data['font_family'] : 'Poppins';
    $cs = in_array($data['card_style'] ?? '', THEME_CARD_STYLES, true) ? $data['card_style'] : 'rounded';
    $tp = array_key_exists($data['theme_preset'] ?? '', THEME_PRESETS) ? $data['theme_preset'] : null;
    // checkout_color: null/empty means "follow Primary Color" (no override)
    $ccRaw = trim($data['checkout_color'] ?? '');
    $cc = preg_match('/^#[0-9a-fA-F]{6}$/', $ccRaw) ? $ccRaw : null;
    $lyt = in_array($data['layout_style'] ?? '', THEME_LAYOUT_STYLES, true) ? $data['layout_style'] : 'grid';
    $hdr = in_array($data['header_style'] ?? '', THEME_HEADER_STYLES, true) ? $data['header_style'] : 'hero';
    $stmt = $conn->prepare('INSERT INTO website_settings (restaurant_id, primary_red, dark_red, primary_yellow, banner_image, layout_columns, background_theme, logo_shape, logo_size, font_family, card_style, theme_preset, checkout_color, layout_style, header_style) VALUES (:rid,:pr,:dr,:py,:bi,:lc,:bt,:ls,:lz,:ff,:cs,:tp,:cc,:lyt,:hdr)
      ON DUPLICATE KEY UPDATE primary_red=VALUES(primary_red), dark_red=VALUES(dark_red), primary_yellow=VALUES(primary_yellow), banner_image=VALUES(banner_image), layout_columns=VALUES(layout_columns), background_theme=VALUES(background_theme), logo_shape=VALUES(logo_shape), logo_size=VALUES(logo_size), font_family=VALUES(font_family), card_style=VALUES(card_style), theme_preset=VALUES(theme_preset), checkout_color=VALUES(checkout_color), layout_style=VALUES(layout_style), header_style=VALUES(header_style)');
    // Save logo settings
    $ls = $data['logo_shape'] ?? 'circle';
    $lz = isset($data['logo_size']) ? (int)$data['logo_size'] : 90;
    $stmt->execute([':rid'=>$restaurant_id, ':pr'=>$pr, ':dr'=>$dr, ':py'=>$py, ':bi'=>$bi, ':lc'=>$lc, ':bt'=>$bt, ':ls'=>$ls, ':lz'=>$lz, ':ff'=>$ff, ':cs'=>$cs, ':tp'=>$tp, ':cc'=>$cc, ':lyt'=>$lyt, ':hdr'=>$hdr]);
    echo json_encode(['success'=>true]);
    exit;
  }

  if ($action === 'upload_background' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Upload a background theme image directly and save to DB
    if (!isset($_FILES['background_image']) || $_FILES['background_image']['error'] !== UPLOAD_ERR_OK) {
      $errorCode = $_FILES['background_image']['error'] ?? -1;
      $errorMsg = 'Upload failed';
      if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) $errorMsg = 'File too large (max 5MB)';
      elseif ($errorCode === UPLOAD_ERR_NO_FILE) $errorMsg = 'No file selected';
      elseif ($errorCode === UPLOAD_ERR_PARTIAL) $errorMsg = 'File partially uploaded';
      throw new Exception($errorMsg);
    }
    
    $file = $_FILES['background_image'];
    
    // Validate MIME type
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    if (!function_exists('finfo_open')) {
      $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
      $extMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];
      $mime = $extMap[$ext] ?? 'application/octet-stream';
    } else {
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      $mime = finfo_file($finfo, $file['tmp_name']);
      finfo_close($finfo);
    }
    if (!in_array($mime, $allowedTypes)) {
      throw new Exception('Invalid file type. Allowed: JPEG, PNG, GIF, WebP');
    }
    // Secondary check: validate file extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowedExts)) {
      throw new Exception('Invalid file extension. Allowed: jpg, jpeg, png, gif, webp');
    }
    
    // Validate file size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
      throw new Exception('File too large (max 5MB)');
    }
    
    // Read file binary and convert to base64 data URL for DB storage
    $imageData = file_get_contents($file['tmp_name']);
    if ($imageData === false) {
      throw new Exception('Failed to read uploaded file');
    }
    $base64Data = base64_encode($imageData);
    $dataUrl = 'data:' . $mime . ';base64,' . $base64Data;
    
    // Save base64 data URL directly to DB
    $stmt = $conn->prepare('INSERT INTO website_settings (restaurant_id, background_theme) VALUES (:rid, :bt) ON DUPLICATE KEY UPDATE background_theme = VALUES(background_theme)');
    $stmt->execute([':rid' => $restaurant_id, ':bt' => $dataUrl]);
    
    echo json_encode(['success' => true, 'background_url' => $dataUrl, 'message' => 'Background image saved as base64!']);
    exit;
  }

  if ($action === 'upload_banner' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if table exists, if not return error asking to run migration
    $tableCheck = $conn->query("SHOW TABLES LIKE 'website_banners'")->fetch();
    if (!$tableCheck) {
      throw new Exception('Website banners table not found. Please run the migration first.');
    }
    
    $uploadedBanners = [];
    $uploadDir = __DIR__ . '/../uploads/banners/';
    if (!file_exists($uploadDir)) {
      mkdir($uploadDir, 0755, true);
    }
    
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    
    // Handle multiple file uploads
    $files = [];
    
    // Check for banners[] (multiple files) - when using FormData with banners[], PHP creates array structure
    if (isset($_FILES['banners'])) {
      // Multiple files - check if it's an array
      if (isset($_FILES['banners']['name']) && is_array($_FILES['banners']['name'])) {
        $fileCount = count($_FILES['banners']['name']);
        for ($i = 0; $i < $fileCount; $i++) {
          if (isset($_FILES['banners']['error'][$i]) && $_FILES['banners']['error'][$i] === UPLOAD_ERR_OK) {
            $files[] = [
              'name' => $_FILES['banners']['name'][$i],
              'type' => $_FILES['banners']['type'][$i] ?? 'image/jpeg',
              'tmp_name' => $_FILES['banners']['tmp_name'][$i],
              'size' => $_FILES['banners']['size'][$i] ?? 0,
              'error' => $_FILES['banners']['error'][$i]
            ];
          } elseif (isset($_FILES['banners']['error'][$i]) && $_FILES['banners']['error'][$i] !== UPLOAD_ERR_OK) {
            // Log upload errors for debugging
            $errorMsg = 'Upload error for file ' . ($i + 1) . ': ';
            switch ($_FILES['banners']['error'][$i]) {
              case UPLOAD_ERR_INI_SIZE:
              case UPLOAD_ERR_FORM_SIZE:
                $errorMsg .= 'File too large';
                break;
              case UPLOAD_ERR_PARTIAL:
                $errorMsg .= 'File partially uploaded';
                break;
              case UPLOAD_ERR_NO_FILE:
                $errorMsg .= 'No file uploaded';
                break;
              case UPLOAD_ERR_NO_TMP_DIR:
                $errorMsg .= 'Missing temporary folder';
                break;
              case UPLOAD_ERR_CANT_WRITE:
                $errorMsg .= 'Failed to write file to disk';
                break;
              case UPLOAD_ERR_EXTENSION:
                $errorMsg .= 'File upload stopped by extension';
                break;
              default:
                $errorMsg .= 'Unknown error (' . $_FILES['banners']['error'][$i] . ')';
            }
            error_log($errorMsg);
          }
        }
      } elseif (isset($_FILES['banners']['error']) && $_FILES['banners']['error'] === UPLOAD_ERR_OK) {
        // Single file uploaded as banners[]
        $files[] = [
          'name' => $_FILES['banners']['name'],
          'type' => $_FILES['banners']['type'] ?? 'image/jpeg',
          'tmp_name' => $_FILES['banners']['tmp_name'],
          'size' => $_FILES['banners']['size'] ?? 0,
          'error' => $_FILES['banners']['error']
        ];
      } elseif (isset($_FILES['banners']['error']) && $_FILES['banners']['error'] !== UPLOAD_ERR_OK) {
        // Single file upload error
        $errorMsg = 'Upload error: ';
        switch ($_FILES['banners']['error']) {
          case UPLOAD_ERR_INI_SIZE:
          case UPLOAD_ERR_FORM_SIZE:
            $errorMsg .= 'File too large (max 5MB)';
            break;
          case UPLOAD_ERR_PARTIAL:
            $errorMsg .= 'File partially uploaded';
            break;
          case UPLOAD_ERR_NO_FILE:
            $errorMsg .= 'No file selected';
            break;
          case UPLOAD_ERR_NO_TMP_DIR:
            $errorMsg .= 'Server configuration error';
            break;
          case UPLOAD_ERR_CANT_WRITE:
            $errorMsg .= 'Failed to save file';
            break;
          case UPLOAD_ERR_EXTENSION:
            $errorMsg .= 'File type not allowed';
            break;
          default:
            $errorMsg .= 'Unknown error (' . $_FILES['banners']['error'] . ')';
        }
        throw new Exception($errorMsg);
      }
    }
    
    // Check for banner (single file - backward compatibility)
    if (empty($files) && isset($_FILES['banner']) && $_FILES['banner']['error'] === UPLOAD_ERR_OK) {
      $files[] = $_FILES['banner'];
    } elseif (empty($files) && isset($_FILES['banner']) && $_FILES['banner']['error'] !== UPLOAD_ERR_OK) {
      $errorMsg = 'Upload error: ';
      switch ($_FILES['banner']['error']) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
          $errorMsg .= 'File too large (max 5MB)';
          break;
        case UPLOAD_ERR_NO_FILE:
          $errorMsg .= 'No file selected';
          break;
        default:
          $errorMsg .= 'Upload failed';
      }
      throw new Exception($errorMsg);
    }
    
    if (empty($files)) {
      throw new Exception('No files uploaded. Please select image files (JPEG, PNG, GIF, or WebP) and try again.');
    }
    
    // Get current max display_order
    $orderStmt = $conn->prepare('SELECT MAX(display_order) as max_order FROM website_banners WHERE restaurant_id = :rid');
    $orderStmt->execute([':rid' => $restaurant_id]);
    $orderResult = $orderStmt->fetch();
    $nextOrder = ($orderResult['max_order'] ?? 0) + 1;
    
    $errorMessages = [];
    foreach ($files as $index => $file) {
      // Check if temp file exists
      if (!isset($file['tmp_name']) || !file_exists($file['tmp_name'])) {
        $errorMessages[] = 'File ' . ($index + 1) . ' (' . ($file['name'] ?? 'unknown') . '): Temporary file not found';
        continue;
      }
      
      // Verify MIME type from file content
      if (!function_exists('finfo_open')) {
        // Fallback to file extension if finfo is not available
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $extensionMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        $actualMimeType = $extensionMap[$extension] ?? 'application/octet-stream';
      } else {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $actualMimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
      }
      
      if (!in_array($actualMimeType, $allowedTypes)) {
        $errorMessages[] = 'File ' . ($index + 1) . ' (' . $file['name'] . '): Invalid file type. Allowed: JPEG, PNG, GIF, WebP';
        continue; // Skip invalid files
      }
      
      if ($file['size'] > 5 * 1024 * 1024) {
        $errorMessages[] = 'File ' . ($index + 1) . ' (' . $file['name'] . '): File too large (max 5MB)';
        continue; // Skip files larger than 5MB
      }
      
      // Read image data for database storage
      $bannerData = file_get_contents($file['tmp_name']);
      if ($bannerData === false) {
        $errorMessages[] = 'File ' . ($index + 1) . ' (' . $file['name'] . '): Failed to read file';
        continue; // Skip if failed to read
      }
      
      $bannerPath = 'db:' . uniqid(); // Reference ID for database storage
      
      try {
        $stmt = $conn->prepare('INSERT INTO website_banners (restaurant_id, banner_path, banner_data, banner_mime_type, display_order) VALUES (:rid, :path, :data, :mime, :order)');
        $stmt->execute([
          ':rid' => $restaurant_id,
          ':path' => $bannerPath,
          ':data' => $bannerData,
          ':mime' => $actualMimeType,
          ':order' => $nextOrder++
        ]);
        $uploadedBanners[] = [
          'id' => $conn->lastInsertId(),
          'banner_path' => $bannerPath,
          'banner_url' => 'image.php?type=banner&id=' . $conn->lastInsertId()
        ];
      } catch (PDOException $e) {
        // If columns don't exist, fall back to file-based storage
        if (strpos($e->getMessage(), 'banner_data') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
          $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
          $filename = 'banner_' . $restaurant_id . '_' . time() . '_' . uniqid() . '.' . $extension;
          $filepath = $uploadDir . $filename;
          
          if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $bannerPath = 'uploads/banners/' . $filename;
            $stmt = $conn->prepare('INSERT INTO website_banners (restaurant_id, banner_path, display_order) VALUES (:rid, :path, :order)');
            $stmt->execute([
              ':rid' => $restaurant_id,
              ':path' => $bannerPath,
              ':order' => $nextOrder++
            ]);
            $uploadedBanners[] = [
              'id' => $conn->lastInsertId(),
              'banner_path' => $bannerPath
            ];
          } else {
            $errorMessages[] = 'File ' . ($index + 1) . ' (' . $file['name'] . '): Failed to save file';
          }
        } else {
          error_log('Banner upload database error: ' . $e->getMessage());
          $errorMessages[] = 'File ' . ($index + 1) . ' (' . $file['name'] . '): Database error';
        }
      }
    }
    
    if (empty($uploadedBanners)) {
      $errorMsg = 'No valid files were uploaded.';
      if (!empty($errorMessages)) {
        $errorMsg .= ' Errors: ' . implode('; ', $errorMessages);
      } else {
        $errorMsg .= ' Please check file types (JPEG, PNG, GIF, WebP) and sizes (max 5MB).';
      }
      throw new Exception($errorMsg);
    }
    
    $response = ['success' => true, 'banners' => $uploadedBanners];
    if (!empty($errorMessages)) {
      $response['warnings'] = $errorMessages;
    }
    echo json_encode($response);
    exit;
  }

  if ($action === 'delete_banner' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $bannerId = $_POST['banner_id'] ?? $_GET['banner_id'] ?? null;
    
    if ($bannerId) {
      // Delete from website_banners table
      $stmt = $conn->prepare('SELECT banner_path FROM website_banners WHERE id = :id AND restaurant_id = :rid');
      $stmt->execute([':id' => $bannerId, ':rid' => $restaurant_id]);
      $banner = $stmt->fetch();
      
      // Delete old banner file if exists (for backward compatibility - only if not in database)
      if ($banner && strpos($banner['banner_path'], 'db:') !== 0 && file_exists(__DIR__ . '/../' . $banner['banner_path'])) {
        @unlink(__DIR__ . '/../' . $banner['banner_path']);
      }
      // Banner data in database will be automatically deleted when row is deleted
      
      $deleteStmt = $conn->prepare('DELETE FROM website_banners WHERE id = :id AND restaurant_id = :rid');
      $deleteStmt->execute([':id' => $bannerId, ':rid' => $restaurant_id]);
      
      echo json_encode(['success' => true]);
      exit;
    } else {
      // Backward compatibility: delete from website_settings
      $oldStmt = $conn->prepare('SELECT banner_image FROM website_settings WHERE restaurant_id = :rid');
      $oldStmt->execute([':rid' => $restaurant_id]);
      $old = $oldStmt->fetch();
      if ($old && $old['banner_image'] && strpos($old['banner_image'], 'db:') !== 0 && file_exists(__DIR__ . '/../' . $old['banner_image'])) {
        @unlink(__DIR__ . '/../' . $old['banner_image']);
      }
      
      $stmt = $conn->prepare('UPDATE website_settings SET banner_image = NULL, banner_data = NULL, banner_mime_type = NULL WHERE restaurant_id = :rid');
      $stmt->execute([':rid' => $restaurant_id]);
      
      echo json_encode(['success' => true]);
      exit;
    }
  }
  
  if ($action === 'reorder_banners' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $bannerIds = $data['banner_ids'] ?? [];
    
    if (empty($bannerIds) || !is_array($bannerIds)) {
      throw new Exception('Invalid banner IDs');
    }
    
    $conn->beginTransaction();
    try {
      foreach ($bannerIds as $order => $bannerId) {
        $stmt = $conn->prepare('UPDATE website_banners SET display_order = :order WHERE id = :id AND restaurant_id = :rid');
        $stmt->execute([':order' => $order, ':id' => $bannerId, ':rid' => $restaurant_id]);
      }
      $conn->commit();
      echo json_encode(['success' => true]);
      exit;
    } catch (Exception $e) {
      $conn->rollBack();
      throw $e;
    }
  }

  echo json_encode(['success'=>false,'message'=>'Invalid action']);
} catch (Exception $e) {
  http_response_code(500);
  error_log("Error in theme_api.php: " . $e->getMessage());
  $friendlyMessage = 'An error occurred. Please try again.';
  if (strpos($e->getMessage(), 'max_allowed_packet') !== false) {
    $friendlyMessage = 'Image too large to save. Please use a smaller background image.';
  }
  echo json_encode(['success'=>false,'message'=>$friendlyMessage]);
}
?>


