<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';

// Check if user is logged in (admin has user_id, staff has staff_id) and session is valid
// Redirect to login page if not logged in (for HTML pages, we redirect instead of returning JSON)
if (!isSessionValid() || (!isset($_SESSION['user_id']) && !isset($_SESSION['staff_id']) && !isset($_SESSION['branch_admin_id'])) || !isset($_SESSION['username']) || !isset($_SESSION['restaurant_id'])) {
    header('Location: ../admin/login.php');
    exit();
}

// Verify user has permission to view dashboard
// If not, redirect to login (they shouldn't be here)
try {
    requireLogin(false);
    requirePermission(PERMISSION_VIEW_DASHBOARD, false);
} catch (Exception $e) {
    // If permission denied, redirect to login
    header('Location: ../admin/login.php');
    exit();
}

// If staff member, redirect to their role-specific dashboard
if (isset($_SESSION['staff_id']) && isset($_SESSION['role'])) {
    $role = $_SESSION['role'];
    switch ($role) {
        case 'Waiter':
            header('Location: waiter_dashboard.php');
            exit();
        case 'Chef':
            header('Location: chef_dashboard.php');
            exit();
        case 'Manager':
            header('Location: manager_dashboard.php');
            exit();
        // Admin staff can access main dashboard, so no redirect needed
    }
}

// Load restaurant info from database to prevent flash of default content
$restaurant_name = $_SESSION['restaurant_name'] ?? 'Restaurant Name';
$restaurant_id = $_SESSION['restaurant_id'] ?? 'RES001';
$restaurant_logo = '../assets/images/logo-transparent.png'; // Default fallback
// Try to get currency from session first (if saved), otherwise default
$currency_symbol = $_SESSION['currency_symbol'] ?? '₹'; // Default currency
$country = $_SESSION['country'] ?? 'India';
$tax_name = $_SESSION['tax_name'] ?? 'GST';
$tax_percent = $_SESSION['tax_percent'] ?? 5.00;
$timezone = 'Asia/Kolkata'; // Default timezone
$language = $_SESSION['language'] ?? 'en';
$user_email = '';
$user_phone = '';
$user_address = '';
$user_role = 'Administrator';
$payment_gateway_type_db = 'cash_only';
$enable_language = 1;
$enable_gst = 1;
$enable_delivery = 1;
$enable_takeaway = 1;
$enable_dinein = 1;
$cod_enabled = 1;
$photo_gallery_enabled = 0;
$restaurant_custom_domain = '';
$restaurant_embed_enabled = false;
 
// Dynamically detect base URL path (works on localhost subdirectory and production root)
$scriptPath = $_SERVER['SCRIPT_NAME'];
$basePath = rtrim(dirname(dirname(dirname($scriptPath))), '/');

// Dynamically detect base URL path (works on localhost subdirectories like /menuwebsite/ and production root)
$scriptPath = $_SERVER['SCRIPT_NAME'];
$basePath = rtrim(dirname(dirname(dirname($scriptPath))), '/');

require_once __DIR__ . '/../config/countries.php';

try {
    // Include database connection
    if (file_exists(__DIR__ . '/../db_connection.php')) {
        require_once __DIR__ . '/../db_connection.php';
    } else {
        // Fallback: try root directory
        $rootDir = dirname(__DIR__);
        if (file_exists($rootDir . '/db_connection.php')) {
            require_once $rootDir . '/db_connection.php';
        }
    }
    
    // Get connection from db_connection.php (use getConnection() for lazy connection support)
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        // Fallback to $pdo if getConnection() doesn't exist (backward compatibility)
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
        }
    }
    
    // Try to get all user settings from database to prevent FOUC
    // Load exactly like restaurant logo - server-side before HTML renders
    try {
        // Default gateway credential values (overridden by DB if available)
        // Cashfree removed
        $phonepe_merchant_id = '';
        $phonepe_salt_key = '';
        $phonepe_environment = 'SANDBOX';
        
        require_once __DIR__ . '/../config/translate_utils.php';
        ensureLanguageColumns($conn, $restaurant_id);
        $stmt = $conn->prepare("SELECT id, restaurant_logo, currency_symbol, country, tax_name, tax_percent, timezone, language, email, phone, address, role, payment_gateway_type, phonepe_merchant_id, phonepe_salt_key, phonepe_environment, enable_gst, enable_delivery, enable_takeaway, enable_dinein, cod_enabled, photo_gallery_enabled, enable_language, payment_gateway_mode, custom_domain, embed_enabled FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($userRow) {
            // Payment gateway credentials for pre-filling forms
            // Cashfree removed
            $phonepe_merchant_id = $userRow['phonepe_merchant_id'] ?? '';
            $phonepe_salt_key = $userRow['phonepe_salt_key'] ?? '';
            $phonepe_environment = $userRow['phonepe_environment'] ?? 'SANDBOX';

            // Restaurant logo - load exactly like this
            if (!empty($userRow['restaurant_logo'])) {
                // Check if logo is stored in database (starts with 'db:')
                if (strpos($userRow['restaurant_logo'], 'db:') === 0) {
                    // Use API endpoint for database-stored image
                    $restaurant_logo = '../api/image.php?type=logo&id=' . ($userRow['id'] ?? $_SESSION['user_id']);
                } elseif (strpos($userRow['restaurant_logo'], 'http') === 0) {
                    // External URL
                    $restaurant_logo = $userRow['restaurant_logo'];
                } else {
                    // File-based image (backward compatibility)
                    $restaurant_logo = $userRow['restaurant_logo'];
                    if (strpos($restaurant_logo, 'uploads/') === 0) {
                        $restaurant_logo = '../' . $restaurant_logo;
                    } elseif (strpos($restaurant_logo, '../') !== 0) {
                        $restaurant_logo = '../uploads/' . $restaurant_logo;
                    }
                }
            }
            // Currency symbol - load exactly like restaurant logo (server-side, no JavaScript needed)
            // This MUST be loaded before HTML renders to prevent any flash
            // IMPORTANT: Use array_key_exists to check if column exists, then check value
            if (array_key_exists('currency_symbol', $userRow) && $userRow['currency_symbol'] !== null && $userRow['currency_symbol'] !== '') {
                // Use centralized Unicode fix function
                require_once __DIR__ . '/../config/unicode_utils.php';
                $db_currency = fixCurrencySymbol($userRow['currency_symbol']);
                    $currency_symbol = htmlspecialchars($db_currency, ENT_QUOTES, 'UTF-8');
                    // Save to session for faster loading next time
                    $_SESSION['currency_symbol'] = $currency_symbol;
            }
            // Country
            if (!empty($userRow['country'])) {
                $country = htmlspecialchars($userRow['country']);
                $_SESSION['country'] = $country;
            }
            // Tax name/percent (replaces the old fixed-5%-GST assumption)
            if (!empty($userRow['tax_name'])) {
                $tax_name = htmlspecialchars($userRow['tax_name']);
                $_SESSION['tax_name'] = $tax_name;
            }
            if (isset($userRow['tax_percent']) && $userRow['tax_percent'] !== null) {
                $tax_percent = (float)$userRow['tax_percent'];
                $_SESSION['tax_percent'] = $tax_percent;
            }
            // Timezone
            if (!empty($userRow['timezone'])) {
                $timezone = htmlspecialchars($userRow['timezone']);
            }
            require_once __DIR__ . '/../config/timezone_utils.php';
            applyRestaurantTimezone($timezone, $conn);
            // Language
            if (!empty($userRow['language'])) {
                $language = htmlspecialchars($userRow['language']);
            }
            // Payment gateway type
            $payment_gateway_type_db = $userRow['payment_gateway_type'] ?? 'cash_only';
            $payment_gateway_mode = $userRow['payment_gateway_mode'] ?? 'own';
            $enable_gst = isset($userRow['enable_gst']) ? (int)$userRow['enable_gst'] : 1;
            $enable_delivery = isset($userRow['enable_delivery']) ? (int)$userRow['enable_delivery'] : 1;
            $enable_takeaway = isset($userRow['enable_takeaway']) ? (int)$userRow['enable_takeaway'] : 1;
            $enable_dinein = isset($userRow['enable_dinein']) ? (int)$userRow['enable_dinein'] : 1;
            $cod_enabled = isset($userRow['cod_enabled']) ? (int)$userRow['cod_enabled'] : 1;
            $photo_gallery_enabled = isset($userRow['photo_gallery_enabled']) ? (int)$userRow['photo_gallery_enabled'] : 0;
            $enable_language = isset($userRow['enable_language']) ? (int)$userRow['enable_language'] : 1;
            // Force English when language support is disabled
            if (!$enable_language) {
                $language = 'en';
                $_SESSION['language'] = 'en';
            }

            // Custom domain
            $restaurant_custom_domain = $userRow['custom_domain'] ?? '';
            $restaurant_embed_enabled = !empty($userRow['embed_enabled']);
            
            // User details
            $user_email = htmlspecialchars($userRow['email'] ?? '');
            $user_phone = htmlspecialchars($userRow['phone'] ?? '');
            $user_address = htmlspecialchars($userRow['address'] ?? '');
            $user_role = htmlspecialchars($userRow['role'] ?? 'Administrator');
        }
    } catch (PDOException $e) {
        // If columns don't exist, try without them
        try {
            $stmt = $conn->prepare("SELECT id, restaurant_logo, currency_symbol FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$_SESSION['user_id']]);
            $logoRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($logoRow) {
                if (!empty($logoRow['restaurant_logo'])) {
                    // Check if logo is stored in database (starts with 'db:')
                    if (strpos($logoRow['restaurant_logo'], 'db:') === 0) {
                        // Use API endpoint for database-stored image
                        $restaurant_logo = '../api/image.php?type=logo&id=' . ($logoRow['id'] ?? $_SESSION['user_id']);
                    } elseif (strpos($logoRow['restaurant_logo'], 'http') === 0) {
                        // External URL
                        $restaurant_logo = $logoRow['restaurant_logo'];
                    } else {
                        // File-based image (backward compatibility)
                        $restaurant_logo = $logoRow['restaurant_logo'];
                        if (strpos($restaurant_logo, 'uploads/') === 0) {
                            $restaurant_logo = '../' . $restaurant_logo;
                        } elseif (strpos($restaurant_logo, '../') !== 0) {
                            $restaurant_logo = '../uploads/' . $restaurant_logo;
                        }
                    }
                }
                // Also try to get currency symbol
                if (array_key_exists('currency_symbol', $logoRow) && $logoRow['currency_symbol'] !== null && $logoRow['currency_symbol'] !== '') {
                    // Use centralized Unicode fix function
                    require_once __DIR__ . '/../config/unicode_utils.php';
                    $db_currency = fixCurrencySymbol($logoRow['currency_symbol']);
                        $currency_symbol = htmlspecialchars($db_currency, ENT_QUOTES, 'UTF-8');
                        // Save to session for faster loading next time
                        $_SESSION['currency_symbol'] = $currency_symbol;
                }
            }
        } catch (PDOException $e2) {
            // Use defaults - currency_symbol already has default '₹' set above
            $restaurant_logo = '../assets/images/logo-transparent.png';
        }
    }
} catch (Exception $e) {
    // If database query fails, use defaults
            $restaurant_logo = '../assets/images/logo-transparent.png';
}
?>
<?php $googleMapsApiKey = function_exists('env') ? env('GOOGLE_MAPS_API_KEY', '') : ''; ?>
<!DOCTYPE html>
<!-- Coding By CodingNepal - youtube.com/@codingnepal -->
<html lang="en">
<head>
  <meta charset="UTF-8">
  <?php if ($googleMapsApiKey): ?>
  <!-- Google Maps Places Autocomplete (Restaurant address setup) -->
  <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo urlencode($googleMapsApiKey); ?>&libraries=places&loading=async" async defer></script>
  <script>window.googleMapsApiKey = <?php echo json_encode($googleMapsApiKey); ?>;</script>
  <?php endif; ?>
  <script>
(function(){
  var msg=function(a){
    if(typeof a==='string')return a;
    if(a&&a.message)return a.message;
    try{return String(a)}catch(e){return''}
  };
  window.addEventListener('unhandledrejection',function(e){
    var m=msg(e.reason);
    if(m.indexOf('Could not establish connection')!==-1||m.indexOf('runtime.lastError')!==-1){e.preventDefault()}
  });
  window.addEventListener('error',function(e){
    if(e.message&&e.message.indexOf('Could not establish connection')!==-1){e.preventDefault();return true}
  });
  ['error','warn'].forEach(function(m){
    var orig=console[m];
    console[m]=function(){
      for(var i=0;i<arguments.length;i++){
        var a=arguments[i];
        var s=msg(a);
        if(s.indexOf('runtime.lastError')!==-1||s.indexOf('Could not establish connection')!==-1)return;
      }
      return orig.apply(console,arguments);
    };
  });
})();
</script>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">
  <title>Restaurant Management System - Restro Grow</title>
  
  <!-- Favicon -->
  <link rel="icon" type="image/png" href="../assets/images/logo-transparent.png">
  <link rel="apple-touch-icon" href="../assets/images/logo-transparent.png">
  <meta name="theme-color" content="#1a3934">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Admin Panel">
  <link rel="manifest" href="admin_manifest.php<?php echo $restaurant_id ? '?restaurant_id=' . urlencode($restaurant_id) : ''; ?>">
  
  <!-- Resource Hints for Performance -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="dns-prefetch" href="https://fonts.googleapis.com">
  <link rel="dns-prefetch" href="https://fonts.gstatic.com">
  
  <!-- Critical CSS -->
  <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo time(); ?>">
  
  <!-- Optimized Font Loading -->
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0"></noscript>
  
  <!-- Cropper.js for image cropping (local files to avoid tracking prevention blocking) -->
  <link rel="stylesheet" href="../assets/libs/cropperjs/cropper.min.css">
  
  <!-- Scripts - Defer non-critical -->
  <script src="../assets/js/sweetalert2.all.min.js" defer></script>
  <script src="../assets/libs/cropperjs/cropper.min.js" defer></script>
  <script>
    // Currency symbol loaded from server-side PHP (exactly like restaurant logo/name)
    // NO JavaScript updates needed - value is already correct in HTML from PHP
    window.globalCurrencySymbol = <?php echo json_encode($currency_symbol, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    window.restaurantCustomDomain = <?php echo json_encode($restaurant_custom_domain, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    window.restaurantEmbedEnabled = <?php echo json_encode((bool)$restaurant_embed_enabled, JSON_HEX_TAG); ?>;
    <?php
    // Pretty-URL slug for the customer website, derived from restaurant_name
    // exactly like restaurantPageUrl() in main/website/header.php.
    $restaurant_website_slug = strtolower($restaurant_name);
    $restaurant_website_slug = preg_replace('/[^a-z0-9]+/', '-', $restaurant_website_slug);
    $restaurant_website_slug = trim($restaurant_website_slug, '-');
    ?>
    window.restaurantWebsiteSlug = <?php echo json_encode($restaurant_website_slug, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    window.enableGst = <?php echo json_encode((bool)$enable_gst, JSON_HEX_TAG); ?>;
    window.taxName = <?php echo json_encode($tax_name, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE); ?>;
    window.taxPercent = <?php echo json_encode((float)$tax_percent, JSON_HEX_TAG); ?>;
    <?php $dashCountryInfo = getCountryByName($country); ?>
    window.restaurantDialCode = <?php echo json_encode($dashCountryInfo['dial_code'] ?? '+91', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    window.restaurantPhoneMin = <?php echo json_encode((int)($dashCountryInfo['phone_min'] ?? 10), JSON_HEX_TAG); ?>;
    window.restaurantPhoneMax = <?php echo json_encode((int)($dashCountryInfo['phone_max'] ?? 10), JSON_HEX_TAG); ?>;
    window.enableLanguage = <?php echo json_encode((bool)$enable_language, JSON_HEX_TAG); ?>;
    window.enableDelivery = <?php echo json_encode((int)$enable_delivery, JSON_HEX_TAG); ?>;
    window.enableTakeaway = <?php echo json_encode((int)$enable_takeaway, JSON_HEX_TAG); ?>;
    window.enableDinein = <?php echo json_encode((int)$enable_dinein, JSON_HEX_TAG); ?>;
    window.codEnabled = <?php echo json_encode((int)$cod_enabled, JSON_HEX_TAG); ?>;
    localStorage.setItem('system_currency', window.globalCurrencySymbol);
    window.userTimezone = <?php echo json_encode($timezone, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.userLanguage = <?php echo json_encode($language, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    // Forward declaration for Install App button (defined later in PWA section)
    function promptInstall() {
      if (window._promptInstallFn) {
        window._promptInstallFn();
      } else {
        console.log('%c⏳ PWA install handler still loading...', 'font-size:12px;color:#f59e0b;');
        setTimeout(function() {
          if (window._promptInstallFn) window._promptInstallFn();
        }, 2000);
      }
    }

    // Load Add-ons management page via iframe
    function loadAdminAddons() {
      var container = document.getElementById('addonsList');
      if (!container) return;
      if (!container.querySelector('iframe')) {
        container.innerHTML = '<iframe src="../admin/addons.php" style="width:100%;height:700px;border:none;border-radius:8px;overflow:auto;"></iframe>';
      }
    }

    // Live dashboard clock - updates every second
    document.addEventListener('DOMContentLoaded', function() {
      var clockEl = document.getElementById('clockDisplay');
      if (!clockEl) return;
      var tz = window.userTimezone || Intl.DateTimeFormat().resolvedOptions().timeZone;
      function updateClock() {
        try {
          var now = new Date();
          clockEl.textContent = now.toLocaleTimeString([], { timeZone: tz, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
        } catch(e) {
          clockEl.textContent = new Date().toLocaleTimeString();
        }
      }
      updateClock();
      setInterval(updateClock, 1000);
    });  </script>
  <style>

        /* Settings form spacing - give all fields breathing room from edges */
    .settings-container .form-row {
      padding-left: 20px;
      padding-right: 20px;
    }
    /* Standalone form-groups (not inside form-row) get padding */
    .settings-container .form-group {
      padding-left: 20px;
      padding-right: 20px;
    }
    /* Reset for form-groups inside form-rows (they inherit from parent row) */
    .settings-container .form-row .form-group {
      padding-left: 0;
      padding-right: 0;
    }
    .nav-icon-profile {
      display: inline-flex;
      animation: profileGlow 3s ease-in-out infinite;
    }
    @keyframes profileGlow {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.85; transform: scale(1.08); }
    }
    .loading-spinner {
      display: inline-block;
      width: 1rem;
      height: 1rem;
      border: 2px solid rgba(255,255,255,0.3);
      border-top-color: currentColor;
      border-radius: 50%;
      animation: loading-spin 0.6s linear infinite;
      vertical-align: middle;
    }
    @keyframes loading-spin {
      to { transform: rotate(360deg); }
    }
    .nav-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 18px;
      height: 18px;
      padding: 0 5px;
      border-radius: 9px;
      background: #ef4444;
      color: #fff;
      font-size: 0.7rem;
      font-weight: 700;
      line-height: 1;
      margin-left: auto;
      position: relative;
      top: 1px;
    }
    .nav-badge.pulse {
      animation: badge-pulse 0.5s ease-in-out 3;
    }
    @keyframes badge-pulse {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.3); }
    }

    /* Sort mode styles */
    .sort-bar {
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      background: #fff;
      border-top: 2px solid #2c3e50;
      padding: 12px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      z-index: 1000;
      box-shadow: 0 -4px 12px rgba(0,0,0,0.15);
    }
    .sort-bar-info {
      display: flex;
      align-items: center;
      gap: 8px;
      color: #666;
      font-size: 14px;
    }
    .sort-bar-info .material-symbols-rounded {
      font-size: 20px;
    }
    .sort-bar-actions {
      display: flex;
      gap: 10px;
    }
    .sort-bar-actions .btn {
      padding: 8px 20px;
    }
    .btn-secondary {
      background: #f8f9fa;
      color: #333;
      border: 1px solid #ddd;
      padding: 10px 20px;
      border-radius: 6px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 14px;
      transition: all 0.2s;
    }
    .btn-secondary:hover {
      background: #e9ecef;
    }
    .sort-mode .menu-card,
    .sort-mode .menu-item-card {
      position: relative;
      padding-left: 50px;
      cursor: default;
    }
    .sort-mode .menu-card .sort-handle,
    .sort-mode .menu-item-card .sort-handle {
      position: absolute;
      left: 8px;
      top: 50%;
      transform: translateY(-50%);
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .sort-handle button {
      width: 32px;
      height: 28px;
      border: 1px solid #ddd;
      background: #fff;
      border-radius: 4px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.15s;
      font-size: 16px;
      line-height: 1;
      padding: 0;
      color: #555;
    }
    .sort-handle button:hover:not(:disabled) {
      background: #e9ecef;
      border-color: #adb5bd;
      color: #000;
    }
    .sort-handle button:disabled {
      opacity: 0.3;
      cursor: not-allowed;
    }
    .sort-handle .material-symbols-rounded {
      font-size: 18px;
    }
    .sort-mode .btn-edit,
    .sort-mode .btn-delete {
      display: none !important;
    }
    .desc-format-btn {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 10px;
      border: 1px solid #d1d5db;
      border-radius: 20px;
      background: #f9fafb;
      color: #6b7280;
      font-size: 12px;
      cursor: pointer;
      transition: all 0.2s;
      white-space: nowrap;
    }
    .desc-format-btn:hover {
      border-color: #9ca3af;
      background: #f3f4f6;
    }
    .desc-format-btn.active {
      background: #f70000;
      color: #fff;
      border-color: #f70000;
    }
 

    /* New Order Notification Overlay */
    #newOrderOverlay {
      display: none;
      position: fixed;
      inset: 0;
      z-index: 99999;
      background: rgba(0, 0, 0, 0.6);
      backdrop-filter: blur(4px);
      -webkit-backdrop-filter: blur(4px);
      justify-content: center;
      align-items: center;
      animation: overlayFadeIn 0.3s ease;
    }
    /* SweetAlert2 defaults to z-index:1060, which renders behind #newOrderOverlay
       (99999) - so confirm dialogs triggered from inside that popup (e.g.
       "Confirm Payment") were visible but unclickable. Force it above every
       overlay in the app. */
    .swal2-container {
      z-index: 100000 !important;
    }
    #newOrderOverlay.show {
      display: flex;
    }


    @keyframes overlayFadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    @keyframes overlaySlideUp {
      from { transform: translateY(40px) scale(0.95); opacity: 0; }
      to { transform: translateY(0) scale(1); opacity: 1; }
    }
    @keyframes orderPulse {
      0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
      50% { box-shadow: 0 0 0 12px rgba(239, 68, 68, 0); }
    }
    .new-order-card {
      background: #fff;
      border-radius: 20px;
      width: 90%;
      max-width: 520px;
      max-height: 85vh;
      overflow: hidden;
      box-shadow: 0 25px 60px rgba(0, 0, 0, 0.3);
      animation: overlaySlideUp 0.35s ease;
      display: flex;
      flex-direction: column;
    }
    .new-order-header {
      background: linear-gradient(135deg, #dc2626, #b91c1c);
      color: #fff;
      padding: 20px 24px;
      display: flex;
      align-items: center;
      gap: 14px;
      position: relative;
    }
    .new-order-header .bell-icon {
      width: 44px;
      height: 44px;
      background: rgba(255,255,255,0.2);
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      animation: orderPulse 2s infinite;
    }
    .new-order-header .bell-icon .material-symbols-rounded {
      font-size: 24px;
    }
    .new-order-header h2 {
      margin: 0;
      font-size: 1.15rem;
      font-weight: 700;
      line-height: 1.3;
    }
    .new-order-header .sub-text {
      font-size: 0.8rem;
      opacity: 0.85;
      margin-top: 2px;
    }
    .new-order-header .close-overlay {
      position: absolute;
      top: 12px;
      right: 12px;
      background: rgba(255,255,255,0.15);
      border: none;
      color: #fff;
      width: 32px;
      height: 32px;
      border-radius: 8px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      transition: background 0.2s;
    }
    .new-order-header .close-overlay:hover {
      background: rgba(255,255,255,0.3);
    }
    .new-order-body {
      padding: 20px 24px;
      overflow-y: auto;
      flex: 1;
    }
    .order-detail-row {
      display: flex;
      justify-content: space-between;
      padding: 8px 0;
      border-bottom: 1px solid #f3f4f6;
      font-size: 0.9rem;
    }
    .order-detail-row:last-child {
      border-bottom: none;
    }
    .order-detail-row .label {
      color: #6b7280;
      font-weight: 500;
    }
    .order-detail-row .value {
      color: #111827;
      font-weight: 600;
      text-align: right;
    }
    .order-detail-row .value.highlight {
      color: #dc2626;
      font-size: 1.1rem;
    }
    .order-items-list {
      margin: 12px 0 8px;
      background: #f9fafb;
      border-radius: 12px;
      padding: 12px 16px;
    }
    .order-items-list .items-title {
      font-size: 0.8rem;
      font-weight: 700;
      color: #6b7280;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 8px;
    }
    .order-item-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 6px 0;
      font-size: 0.88rem;
    }
    .order-item-row .item-name {
      color: #374151;
      font-weight: 500;
    }
    .order-item-row .item-qty {
      color: #6b7280;
      font-size: 0.82rem;
    }
    .order-item-row .item-price {
      color: #111827;
      font-weight: 600;
    }
    .new-order-footer {
      padding: 16px 24px 20px;
      border-top: 1px solid #e5e7eb;
      display: flex;
      gap: 12px;
      background: #fafafa;
    }
    .new-order-footer button {
      flex: 1;
      padding: 12px 16px;
      border: none;
      border-radius: 12px;
      font-weight: 700;
      font-size: 0.95rem;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: all 0.2s;
      font-family: inherit;
    }
    .btn-accept-order {
      background: #10b981;
      color: #fff;
    }
    .btn-accept-order:hover {
      background: #059669;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }
    .btn-accept-order:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }
    .btn-reject-order {
      background: #ef4444;
      color: #fff;
    }
    .btn-reject-order:hover {
      background: #dc2626;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    }
    .btn-reject-order:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }
    .new-order-time {
      font-size: 0.78rem;
      color: #9ca3af;
      margin-top: 8px;
      text-align: center;
    }
    @media (max-width: 480px) {
      .new-order-card {
        width: 95%;
        max-height: 90vh;
        border-radius: 16px;
      }
      .new-order-header {
        padding: 16px 18px;
      }
      .new-order-body {
        padding: 14px 18px;
      }
      .new-order-footer {
        padding: 12px 18px 16px;
        flex-direction: column;
      }
      .new-order-footer button {
        padding: 14px;
      }
    }
    </style>
  <!-- Leaflet.js for delivery map -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</head>
<body>
  <aside class="sidebar">
    <!-- Sidebar header -->
    <header class="sidebar-header">
      <a href="#" class="header-logo">
        <img id="dashboardRestaurantLogo" src="<?php echo htmlspecialchars($restaurant_logo) . (strpos($restaurant_logo, '?') !== false ? '&' : '?') . 't=' . (time() . '_' . mt_rand(1000, 9999)); ?>" alt="Restro Grow Logo" onerror="this.src='../assets/images/logo-transparent.png'; this.style.borderRadius='50%'; this.style.objectFit='cover';" style="border-radius: 50%; object-fit: cover; width: 46px; height: 46px;">
        <div class="restaurant-info">
          <div class="restaurant-name" id="restaurantName"><?php echo htmlspecialchars($restaurant_name); ?></div>
          <div class="restaurant-id" id="restaurantId"><?php echo htmlspecialchars($restaurant_id); ?></div>
          <div id="restaurantSwitcherWrapper" style="display:none;margin-top:6px;">
            <select id="restaurantSwitcher" style="width:100%;padding:4px 6px;font-size:12px;border-radius:6px;border:1px solid rgba(255,255,255,.3);background:rgba(255,255,255,.15);color:#fff;outline:none;cursor:pointer;">
              <option value="" disabled selected style="color:#333;background:#fff;">Switch restaurant...</option>
            </select>
          </div>
        </div>
      </a>
      <button class="toggler sidebar-toggler">
        <span class="material-symbols-rounded">chevron_left</span>
      </button>
      <button class="toggler menu-toggler">
        <span class="material-symbols-rounded">menu</span>
      </button>
    </header>

    <nav class="sidebar-nav">
      <!-- Primary top nav -->
      <ul class="nav-list primary-nav">
        <li class="nav-item">
          <a href="#" class="nav-link" data-page="dashboardPage">
            <span class="nav-icon material-symbols-rounded">dashboard</span>
            <span class="nav-label">Dashboard</span>
          </a>
          <span class="nav-tooltip">Dashboard</span>
        </li>
        <li class="nav-item has-submenu">
          <a href="#" class="nav-link submenu-toggle">
            <span class="nav-icon material-symbols-rounded">menu</span>
            <span class="nav-label">Menu</span>
            <span class="submenu-arrow material-symbols-rounded">chevron_right</span>
          </a>
          <span class="nav-tooltip">Menu</span>
          <ul class="submenu">
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="menuPage">
                <span class="nav-icon material-symbols-rounded">menu</span>
                <span class="nav-label">Category</span>
              </a>
              <span class="nav-tooltip">Category</span>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="menuItemsPage">
                <span class="nav-icon material-symbols-rounded">list</span>
                <span class="nav-label">Menu Items</span>
              </a>
              <span class="nav-tooltip">Menu Items</span>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="addonsPage" onclick="setTimeout(loadAdminAddons, 50)">
                <span class="nav-icon material-symbols-rounded">playlist_add</span>
                <span class="nav-label">Add-ons</span>
              </a>
              <span class="nav-tooltip">Add-ons</span>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="inventoryPage" onclick="setTimeout(loadInventory, 50)">
                <span class="nav-icon material-symbols-rounded">inventory_2</span>
                <span class="nav-label">Inventory</span>
              </a>
              <span class="nav-tooltip">Inventory</span>
            </li>
            <?php if ($photo_gallery_enabled): ?>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="galleryPage">
                <span class="nav-icon material-symbols-rounded">photo_library</span>
                <span class="nav-label">Photo Gallery</span>
              </a>
              <span class="nav-tooltip">Photo Gallery</span>
            </li>
            <?php endif; ?>
          </ul>
        </li>
        <!-- Tables Menu with Submenus -->
        <li class="nav-item has-submenu">
          <a href="#" class="nav-link submenu-toggle">
            <span class="nav-icon material-symbols-rounded">table_chart</span>
            <span class="nav-label">Tables</span>
            <span class="submenu-arrow material-symbols-rounded">chevron_right</span>
          </a>
          <span class="nav-tooltip">Tables</span>
          <ul class="submenu">
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="areaPage">
                <span class="nav-icon material-symbols-rounded">area_chart</span>
                <span class="nav-label">Area</span>
              </a>
              <span class="nav-tooltip">Area</span>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="tablesPage">
                <span class="nav-icon material-symbols-rounded">table_rows</span>
                <span class="nav-label">Tables</span>
              </a>
              <span class="nav-tooltip">Tables</span>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="qrCodesPage">
                <span class="nav-icon material-symbols-rounded">qr_code</span>
                <span class="nav-label">QR Code</span>
              </a>
              <span class="nav-tooltip">QR Code</span>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="tableMapPage">
                <span class="nav-icon material-symbols-rounded">map</span>
                <span class="nav-label">Table Map</span>
              </a>
              <span class="nav-tooltip">Table Map</span>
            </li>
          </ul>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link" data-page="reservationsPage">
            <span class="nav-icon material-symbols-rounded">event</span>
            <span class="nav-label">Reservations</span>
          </a>
          <span class="nav-tooltip">Reservations</span>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link" data-page="posPage">
            <span class="nav-icon material-symbols-rounded">point_of_sale</span>
            <span class="nav-label">POS</span>
          </a>
          <span class="nav-tooltip">Point of Sale</span>
        </li>
        <li class="nav-item has-submenu">
          <a href="#" class="nav-link submenu-toggle">
            <span class="nav-icon material-symbols-rounded">receipt_long</span>
            <span class="nav-label">Orders</span>
            <span class="nav-badge" id="ordersBadge" style="display:none">0</span>
            <span class="submenu-arrow material-symbols-rounded">chevron_right</span>
          </a>
          <span class="nav-tooltip">Orders</span>
          <ul class="submenu">
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="kotPage">
                <span class="nav-icon material-symbols-rounded">restaurant_menu</span>
                <span class="nav-label">KOT</span>
              </a>
              <span class="nav-tooltip">Kitchen Order Ticket</span>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="onlineOrdersPage">
                <span class="nav-icon material-symbols-rounded">language</span>
                <span class="nav-label">Online Orders</span>
                <span class="nav-badge" id="onlineOrdersBadge" style="display:none">0</span>
              </a>
              <span class="nav-tooltip">Orders from website</span>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="ordersPage">
                <span class="nav-icon material-symbols-rounded">receipt</span>
                <span class="nav-label">Orders</span>
              </a>
              <span class="nav-tooltip">Orders</span>
            </li>
          </ul>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link" data-page="customersPage">
            <span class="nav-icon material-symbols-rounded">people</span>
            <span class="nav-label">Customers</span>
          </a>
          <span class="nav-tooltip">Customers</span>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link" data-page="staffPage">
            <span class="nav-icon material-symbols-rounded">person</span>
            <span class="nav-label">Staff</span>
          </a>
          <span class="nav-tooltip">Staff</span>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link" data-page="waiterRequestsPage">
            <span class="nav-icon material-symbols-rounded">notifications_active</span>
            <span class="nav-label">Waiter Requests</span>
          </a>
          <span class="nav-tooltip">Waiter Requests</span>
        </li>
        <li class="nav-item has-submenu">
          <a href="#" class="nav-link submenu-toggle">
            <span class="nav-icon material-symbols-rounded">payments</span>
            <span class="nav-label">Payments</span>
            <span class="submenu-arrow material-symbols-rounded">chevron_right</span>
          </a>
          <span class="nav-tooltip">Payments</span>
          <ul class="submenu">
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="paymentsPage">
                <span class="nav-icon material-symbols-rounded">receipt_long</span>
                <span class="nav-label">Payments</span>
              </a>
              <span class="nav-tooltip">Payments</span>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="gatewayPage">
                <span class="nav-icon material-symbols-rounded">credit_card</span>
                <span class="nav-label">Gateway</span>
              </a>
              <span class="nav-tooltip">Gateway</span>
            </li>
          </ul>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link" data-page="reportsPage">
            <span class="nav-icon material-symbols-rounded">assessment</span>
            <span class="nav-label">Reports</span>
          </a>
          <span class="nav-tooltip">Reports</span>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link" data-page="analyticsPage">
            <span class="nav-icon material-symbols-rounded">analytics</span>
            <span class="nav-label">Analytics <span class="badge-new">New</span></span>
          </a>
          <span class="nav-tooltip">Analytics</span>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link" data-page="marketingPage">
            <span class="nav-icon material-symbols-rounded">campaign</span>
            <span class="nav-label">Marketing</span>
          </a>
          <span class="nav-tooltip">Marketing</span>
        </li>
        <li class="nav-item has-submenu">
          <a href="#" class="nav-link submenu-toggle">
            <span class="nav-icon material-symbols-rounded">local_offer</span>
            <span class="nav-label">Offers</span>
            <span class="submenu-arrow material-symbols-rounded">chevron_right</span>
          </a>
          <span class="nav-tooltip">Offers</span>
          <ul class="submenu">
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="couponsPage" onclick="setTimeout(loadAdminCoupons, 50)">
                <span class="material-symbols-rounded">confirmation_number</span>
                <span class="nav-label">Coupons</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="dealsPage" onclick="setTimeout(function(){loadDealMenus();loadDeals();},50)">
                <span class="material-symbols-rounded">local_offer</span>
                <span class="nav-label">Deals</span>
              </a>
            </li>
          </ul>
        </li>
        <li class="nav-item has-submenu">
          <a href="#" class="nav-link submenu-toggle">
            <span class="nav-icon material-symbols-rounded">local_shipping</span>
            <span class="nav-label">Delivery</span>
            <span class="submenu-arrow material-symbols-rounded">chevron_right</span>
          </a>
          <span class="nav-tooltip">Delivery</span>
          <ul class="submenu">
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="deliveryPage" onclick="setTimeout(loadDeliveryZones, 150)">
                <span class="material-symbols-rounded">pin_drop</span>
                <span class="nav-label">Zones</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="deliveryMapPage" onclick="setTimeout(initDeliveryMap, 50)">
                <span class="material-symbols-rounded">map</span>
                <span class="nav-label">Map View</span>
              </a>
            </li>
          </ul>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link" data-page="feedbackPage" onclick="setTimeout(loadFeedback, 50)">
            <span class="nav-icon material-symbols-rounded">star</span>
            <span class="nav-label">Feedback</span>
          </a>
          <span class="nav-tooltip">Feedback</span>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link" data-page="settingsPage">
            <span class="nav-icon material-symbols-rounded">settings</span>
            <span class="nav-label">Settings</span>
          </a>
          <span class="nav-tooltip">Settings</span>
        </li>
        <li class="nav-item">
          <a href="<?php 
            if (!empty($restaurant_custom_domain) && $restaurant_embed_enabled) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $domain = preg_replace('#^https?://#', '', $restaurant_custom_domain);
                echo $scheme . '://' . $domain . '/';
            } else {
                $slug = strtolower($restaurant_name);
                $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
                $slug = trim($slug, '-');
                echo $basePath . '/' . urlencode($slug);
            }
          ?>" class="nav-link" target="_blank">
            <span class="nav-icon material-symbols-rounded">language</span>
            <span class="nav-label">Customer Website</span>
          </a>
          <span class="nav-tooltip">Customer Website</span>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link" data-page="websiteThemePage">
            <span class="nav-icon material-symbols-rounded">palette</span>
            <span class="nav-label">Website Appearance</span>
          </a>
          <span class="nav-tooltip">Website Appearance</span>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link" data-page="embedPage" onclick="setTimeout(loadEmbedSettings, 50)">
            <span class="nav-icon material-symbols-rounded">language</span>
            <span class="nav-label">Custom Domain</span>
          </a>
          <span class="nav-tooltip">Custom Domain</span>
        </li>

      </ul>

      <!-- Secondary bottom nav -->
      <ul class="nav-list secondary-nav">
        <li class="nav-item">
          <a href="#" class="nav-link" data-page="profilePage">
            <span class="nav-icon material-symbols-rounded nav-icon-profile">account_circle</span>
            <span class="nav-label">Profile</span>
          </a>
          <span class="nav-tooltip">Profile</span>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link" onclick="logout()">
            <span class="nav-icon material-symbols-rounded">logout</span>
            <span class="nav-label">Logout</span>
          </a>
          <span class="nav-tooltip">Logout</span>
        </li>
      </ul>
    </nav>
  </aside>

  <!-- Main Content Area -->
  <main class="main-content">
    <!-- Dashboard Page (Default) -->
    <div id="dashboardPage" class="page active">
      <div class="page-header">
        <div class="dashboard-header-row">
          <div>
            <h1>Dashboard Overview</h1>
            <p id="dashboardTime">Welcome back! Here's what's happening today.</p>
            <div id="trialInfo" style="margin-top:.25rem;color:#92400e;font-weight:700;display:none;"></div>
          </div>
          <div style="display:flex;align-items:center;gap:12px;">
            <div id="liveClock" style="font-size:.85rem;color:#6b7280;font-weight:600;white-space:nowrap;font-variant-numeric:tabular-nums;background:#f3f4f6;padding:6px 12px;border-radius:8px;border:1px solid #e5e7eb;display:flex;align-items:center;gap:6px;">
              <span class="material-symbols-rounded" style="font-size:16px;color:#9ca3af;">schedule</span>
              <span id="clockDisplay">--:--:--</span>
            </div>
            <button class="btn-refresh-dashboard" onclick="loadDashboardStats()" title="Refresh">
              <span class="material-symbols-rounded">refresh</span>
            </button>
          </div>
        </div>
      </div>
      <div class="page-content">
        <!-- Main Stats Row -->
        <div class="main-stats">
          <div class="main-stat-card revenue">
            <div class="stat-main">
              <span class="material-symbols-rounded">payments</span>
              <div class="stat-main-info">
                <div class="stat-label">Today's Revenue</div>
                <div class="stat-value-large" id="todayRevenue"><?php echo htmlspecialchars($currency_symbol); ?>0.00</div>
              </div>
            </div>
            <div class="stat-footer">
              <span class="material-symbols-rounded">trending_up</span>
              <span>View Reports</span>
            </div>
          </div>
          
          <div class="main-stat-card orders">
            <div class="stat-main">
              <span class="material-symbols-rounded">receipt_long</span>
              <div class="stat-main-info">
                <div class="stat-label">Today's Orders</div>
                <div class="stat-value-large" id="todayOrders">0</div>
              </div>
            </div>
            <div class="stat-footer">
              <span class="material-symbols-rounded">schedule</span>
              <span>Last 24 hours</span>
            </div>
          </div>
          
          <div class="main-stat-card kot">
            <div class="stat-main">
              <span class="material-symbols-rounded">restaurant_menu</span>
              <div class="stat-main-info">
                <div class="stat-label">Active KOT</div>
                <div class="stat-value-large" id="activeKOT">0</div>
              </div>
            </div>
            <div class="stat-footer">
              <span class="material-symbols-rounded">kitchen</span>
              <span id="kotStatus">In Progress</span>
            </div>
          </div>
        </div>
        
        <!-- Secondary Stats -->
        <div class="secondary-stats">
          <div class="secondary-stat-card">
            <div class="stat-icon-circle customers">
              <span class="material-symbols-rounded">people</span>
            </div>
            <div class="stat-info">
              <div class="stat-label">Customers</div>
              <div class="stat-value" id="totalCustomers">0</div>
            </div>
          </div>
          
          <div class="secondary-stat-card">
            <div class="stat-icon-circle tables">
              <span class="material-symbols-rounded">table_restaurant</span>
            </div>
            <div class="stat-info">
              <div class="stat-label">Tables</div>
              <div class="stat-value" id="tableInfo">0/0</div>
            </div>
          </div>
          
          <div class="secondary-stat-card">
            <div class="stat-icon-circle items">
              <span class="material-symbols-rounded">restaurant</span>
            </div>
            <div class="stat-info">
              <div class="stat-label">Menu Items</div>
              <div class="stat-value" id="totalItems">0</div>
            </div>
          </div>
          
          <div class="secondary-stat-card">
            <div class="stat-icon-circle pending">
              <span class="material-symbols-rounded">pending_actions</span>
            </div>
            <div class="stat-info">
              <div class="stat-label">Pending</div>
              <div class="stat-value" id="pendingOrders">0</div>
            </div>
          </div>
        </div>
        
        <!-- Content Grid -->
        <div class="dashboard-content-grid">
          <!-- Recent Orders Card -->
          <div class="dashboard-card-modern">
            <div class="card-header-modern">
              <h3>
                <span class="material-symbols-rounded">schedule</span>
                Recent Orders
              </h3>
              <button class="btn-view-all" onclick="showPage('ordersPage')">View All</button>
            </div>
            <div class="card-body-modern" id="recentOrders">
              <div class="loading">Loading...</div>
            </div>
          </div>
          
          <!-- Popular Items Card -->
          <div class="dashboard-card-modern">
            <div class="card-header-modern">
              <h3>
                <span class="material-symbols-rounded">local_fire_department</span>
                Popular Today
              </h3>
              <span class="badge-today">Today</span>
            </div>
            <div class="card-body-modern" id="popularItems">
              <div class="loading">Loading...</div>
            </div>
          </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="dashboard-card-modern">
          <div class="card-header-modern">
            <h3>
              <span class="material-symbols-rounded">rocket_launch</span>
              Quick Actions
            </h3>
          </div>
          <div class="quick-actions-grid">
            <button class="quick-action-btn" onclick="showPage('posPage')">
              <span class="material-symbols-rounded">point_of_sale</span>
              <span class="action-label">New Order</span>
            </button>
            <button class="quick-action-btn" onclick="showPage('kotPage')">
              <span class="material-symbols-rounded">restaurant_menu</span>
              <span class="action-label">KOT</span>
            </button>
            <button class="quick-action-btn" onclick="showPage('ordersPage')">
              <span class="material-symbols-rounded">receipt_long</span>
              <span class="action-label">Orders</span>
            </button>
            <button class="quick-action-btn" onclick="showPage('menuItemsPage')">
              <span class="material-symbols-rounded">menu_book</span>
              <span class="action-label">Menu</span>
            </button>
            <button class="quick-action-btn" onclick="showPage('tablesPage')">
              <span class="material-symbols-rounded">table_chart</span>
              <span class="action-label">Tables</span>
            </button>
            <button class="quick-action-btn" onclick="showPage('customersPage')">
              <span class="material-symbols-rounded">people</span>
              <span class="action-label">Customers</span>
            </button>
            <button class="quick-action-btn" onclick="showPage('staffPage')">
              <span class="material-symbols-rounded">groups</span>
              <span class="action-label">Staff</span>
            </button>
            <button class="quick-action-btn" onclick="showPage('reservationsPage')">
              <span class="material-symbols-rounded">event</span>
              <span class="action-label">Reservations</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Website Theme Page -->
    <div id="websiteThemePage" class="page">
      <div class="page-header">
        <h1>Website Appearance</h1>
        <p>Change the colors and banner image used in the customer website.</p>
      </div>
      <div class="page-content">
        <div class="settings-grid">
          <div class="settings-section">
            <h2 class="settings-section-title">
              <span class="material-symbols-rounded">palette</span>
              Colors
            </h2>
            
            <!-- Visual Preview -->
            <div id="colorPreviewContainer" style="background: white; border-radius: 8px; padding: 1rem; border: 2px solid #e5e7eb; margin-bottom: 2rem;">
              <div style="font-weight: 700; color: #111827; margin-bottom: 0.75rem;">Preview:</div>
              <div id="heroPreview" style="background: linear-gradient(135deg, #F70000 0%, #DA020E 100%); border-radius: 8px; padding: 1.5rem; color: white; margin-bottom: 1rem;">
                <div style="font-size: 1.5rem; font-weight: 800; margin-bottom: 0.5rem;">Restaurant Name</div>
                <div style="font-size: 0.9rem; opacity: 0.9;">Hero Section Background (Gradient)</div>
              </div>
              <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <div id="categoryButtonPreview" style="border: 2px solid #F70000; color: #F70000; padding: 0.5rem 1rem; border-radius: 20px; font-weight: 600; font-size: 0.875rem;">Category Button</div>
                <div id="addToCartPreview" style="background: #FFD100; color: #333; padding: 0.5rem 1rem; border-radius: 20px; font-weight: 600; font-size: 0.875rem;">Add to Cart</div>
                <div id="checkoutPreview" style="background: #F70000; color: white; padding: 0.5rem 1rem; border-radius: 20px; font-weight: 600; font-size: 0.875rem;">Checkout</div>
              </div>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
              <div style="background: linear-gradient(135deg, #f9fafb 0%, #ffffff 100%); border-radius: 16px; padding: 1.5rem; border: 2px solid #e5e7eb; box-shadow: 0 2px 8px rgba(0,0,0,0.04); transition: all 0.3s ease;" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.04)'">
                <div style="text-align: center; margin-bottom: 1rem;">
                  <div style="font-weight: 700; color: #111827; font-size: 1rem; margin-bottom: 0.5rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <span class="material-symbols-rounded" style="font-size: 1.2rem; color: #dc2626;">palette</span>
                    Main Color
                  </div>
                  <div style="font-size: 0.8rem; color: #6b7280; margin-bottom: 0.75rem;">Primary brand color</div>
                </div>
                <input type="color" id="primaryRed" value="#F70000" style="width: 100%; height: 70px; border-radius: 12px; border: 3px solid #e5e7eb; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onmouseover="this.style.borderColor='#d1d5db'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.15)'" onmouseout="this.style.borderColor='#e5e7eb'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'">
                <div id="primaryRedDisplay" style="margin-top: 0.75rem; font-family: 'Courier New', monospace; font-size: 0.9rem; color: #374151; font-weight: 600; text-align: center; background: #f3f4f6; padding: 0.5rem; border-radius: 8px;">#F70000</div>
              </div>
              
              <div style="background: linear-gradient(135deg, #f9fafb 0%, #ffffff 100%); border-radius: 16px; padding: 1.5rem; border: 2px solid #e5e7eb; box-shadow: 0 2px 8px rgba(0,0,0,0.04); transition: all 0.3s ease;" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.04)'">
                <div style="text-align: center; margin-bottom: 1rem;">
                  <div style="font-weight: 700; color: #111827; font-size: 1rem; margin-bottom: 0.5rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <span class="material-symbols-rounded" style="font-size: 1.2rem; color: #991b1b;">gradient</span>
                    Accent Color
                  </div>
                  <div style="font-size: 0.8rem; color: #6b7280; margin-bottom: 0.75rem;">Darker shade for gradients</div>
                </div>
                <input type="color" id="darkRed" value="#DA020E" style="width: 100%; height: 70px; border-radius: 12px; border: 3px solid #e5e7eb; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onmouseover="this.style.borderColor='#d1d5db'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.15)'" onmouseout="this.style.borderColor='#e5e7eb'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'">
                <div id="darkRedDisplay" style="margin-top: 0.75rem; font-family: 'Courier New', monospace; font-size: 0.9rem; color: #374151; font-weight: 600; text-align: center; background: #f3f4f6; padding: 0.5rem; border-radius: 8px;">#DA020E</div>
              </div>
              
              <div style="background: linear-gradient(135deg, #f9fafb 0%, #ffffff 100%); border-radius: 16px; padding: 1.5rem; border: 2px solid #e5e7eb; box-shadow: 0 2px 8px rgba(0,0,0,0.04); transition: all 0.3s ease;" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.04)'">
                <div style="text-align: center; margin-bottom: 1rem;">
                  <div style="font-weight: 700; color: #111827; font-size: 1rem; margin-bottom: 0.5rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <span class="material-symbols-rounded" style="font-size: 1.2rem; color: #fbbf24;">star</span>
                    Highlight Color
                  </div>
                  <div style="font-size: 0.8rem; color: #6b7280; margin-bottom: 0.75rem;">Call-to-action buttons</div>
                </div>
                <input type="color" id="primaryYellow" value="#FFD100" style="width: 100%; height: 70px; border-radius: 12px; border: 3px solid #e5e7eb; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onmouseover="this.style.borderColor='#d1d5db'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.15)'" onmouseout="this.style.borderColor='#e5e7eb'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'">
                <div id="primaryYellowDisplay" style="margin-top: 0.75rem; font-family: 'Courier New', monospace; font-size: 0.9rem; color: #374151; font-weight: 600; text-align: center; background: #f3f4f6; padding: 0.5rem; border-radius: 8px;">#FFD100</div>
              </div>
            </div>
                        <!-- Background Theme Selector -->
            <div style="margin-bottom: 1.5rem; padding: 1.25rem; background: #f9fafb; border-radius: 12px; border: 2px solid #e5e7eb;">
              <div style="font-weight: 700; color: #111827; font-size: 1rem; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                <span class="material-symbols-rounded" style="font-size: 1.2rem; color: #8b5cf6;">wallpaper</span>
                Background Theme
              </div>
              <p style="font-size: 0.85rem; color: #6b7280; margin-bottom: 0.75rem;">Pick a background for your homepage. <strong>Click any preset</strong> to select it, then <strong>click Save Theme</strong> below. Or upload your own image — it will be saved automatically.</p>
              <div id="backgroundThemeGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; margin-bottom: 12px;"></div>
              <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <input type="file" id="backgroundImageUpload" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" style="flex: 1; min-width: 200px; padding: 0.4rem; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.85rem; background: #fff;">
                <button type="button" id="uploadBackgroundImageBtn" class="btn btn-primary" style="padding: 0.6rem 1rem; font-size: 0.85rem; white-space: nowrap;">
                  <span class="material-symbols-rounded" style="font-size: 1rem; vertical-align: middle;">upload</span>
                  Upload & Save
                </button>
              </div>
              <div id="bgUploadStatus" style="margin-top: 6px; font-size: 0.8rem; color: #888;"></div>
              <input type="hidden" id="backgroundThemeInput" value="">
            </div>
            <!-- Desktop Layout Toggle -->

            <div style="margin-bottom: 1.5rem; padding: 1rem; background: #f9fafb; border-radius: 12px; border: 2px solid #e5e7eb;">
              <div style="font-weight: 700; color: #111827; font-size: 1rem; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                <span class="material-symbols-rounded" style="font-size: 1.2rem; color: #dc2626;">grid_view</span>
                Mobile Layout
              </div>
              <p style="font-size: 0.85rem; color: #6b7280; margin-bottom: 0.75rem;">Choose how many product columns to show on phone screens. Desktop/laptop always shows 2 columns.</p>
              <div style="display: flex; gap: 1rem;">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; padding: 0.5rem 1rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; transition: all 0.2s;" onmouseover="this.style.borderColor='#d1d5db'" onmouseout="this.style.borderColor='#e5e7eb'">
                  <input type="radio" name="layoutColumns" value="1" style="accent-color: #dc2626;">
                  <span style="font-weight: 500; font-size: 0.9rem;">1 Column</span>
                </label>
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; padding: 0.5rem 1rem; border: 2px solid #dc2626; border-radius: 8px; background: #fff; transition: all 0.2s;" onmouseover="this.style.borderColor='#d1d5db'" onmouseout="this.style.borderColor='#e5e7eb'">
                  <input type="radio" name="layoutColumns" value="2" checked style="accent-color: #dc2626;">
                  <span style="font-weight: 500; font-size: 0.9rem;">2 Columns</span>
                </label>
              </div>
            </div>
            <!-- Logo Shape & Size Settings -->
            <div style="margin-bottom: 1.5rem; padding: 1.25rem; background: #f9fafb; border-radius: 12px; border: 2px solid #e5e7eb;">
              <div style="font-weight: 700; color: #111827; font-size: 1rem; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                <span class="material-symbols-rounded" style="font-size: 1.2rem; color: #dc2626;">crop</span>
                Logo Style
              </div>
              <p style="font-size: 0.85rem; color: #6b7280; margin-bottom: 0.75rem;">Customize how your restaurant logo appears on the website.</p>
              
              <!-- Shape Toggle -->
              <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: #374151;">Logo Shape</label>
                <div style="display: flex; gap: 1rem;">
                  <label class="logo-shape-btn" data-shape="circle" style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; padding: 0.6rem 1.2rem; border: 2px solid #dc2626; border-radius: 8px; background: #dc2626; color: #fff; transition: all 0.2s;" onmouseover="this.style.borderColor='#b91c1c'" onmouseout="this.style.borderColor='#dc2626'" onclick="document.querySelectorAll('.logo-shape-btn').forEach(function(el){el.style.borderColor='#e5e7eb';el.style.background='#fff';el.style.color='#374151'});this.style.borderColor='#dc2626';this.style.background='#dc2626';this.style.color='#fff'">
                    <input type="radio" name="logoShape" value="circle" checked style="accent-color: #fff; display: none;">
                    <span style="display: inline-block; width: 20px; height: 20px; border-radius: 50%; background: #fff; flex-shrink: 0;"></span>
                    <span style="font-weight: 500; font-size: 0.9rem;">Circle</span>
                  </label>
                  <label class="logo-shape-btn" data-shape="square" style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; padding: 0.6rem 1.2rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; color: #374151; transition: all 0.2s;" onmouseover="this.style.borderColor='#d1d5db'" onmouseout="this.style.borderColor='#e5e7eb'" onclick="document.querySelectorAll('.logo-shape-btn').forEach(function(el){el.style.borderColor='#e5e7eb';el.style.background='#fff';el.style.color='#374151'});this.style.borderColor='#dc2626';this.style.background='#dc2626';this.style.color='#fff'">
                    <input type="radio" name="logoShape" value="square" style="accent-color: #dc2626; display: none;">
                    <span style="display: inline-block; width: 20px; height: 20px; border-radius: 4px; background: #6b7280; flex-shrink: 0;"></span>
                    <span style="font-weight: 500; font-size: 0.9rem;">Square (Rounded)</span>
                  </label>
                </div>
              </div>
              
              <!-- Size Slider -->
              <div>
                <label for="logoSizeSlider" style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: #374151;">
                  Logo Size: <span id="logoSizeDisplay" style="color: #dc2626;">90</span>px
                </label>
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                  <span style="font-size: 0.8rem; color: #9ca3af; font-weight: 500;">Small</span>
                  <input type="range" id="logoSizeSlider" min="50" max="150" value="90" style="flex: 1; height: 6px; -webkit-appearance: none; appearance: none; background: linear-gradient(to right, #dc2626 0%, #dc2626 40%, #e5e7eb 40%, #e5e7eb 100%); border-radius: 3px; outline: none; cursor: pointer;" oninput="document.getElementById('logoSizeDisplay').textContent = this.value; this.style.background = 'linear-gradient(to right, #dc2626 0%, #dc2626 ' + ((this.value - 50) / 100 * 100) + '%, #e5e7eb ' + ((this.value - 50) / 100 * 100) + '%, #e5e7eb 100%)'">
                  <span style="font-size: 0.8rem; color: #9ca3af; font-weight: 500;">Large</span>
                </div>
              </div>
            </div>
            <div class="form-actions">
              <button type="button" class="btn btn-save" id="saveWebsiteThemeBtn">Save Theme</button>
              <a href="<?php echo $basePath; ?>/<?php 
                $restaurant_slug = strtolower($restaurant_name);
                $restaurant_slug = preg_replace('/[^a-z0-9]+/', '-', $restaurant_slug);
                $restaurant_slug = trim($restaurant_slug, '-');
                echo urlencode($restaurant_slug);
              ?>" class="btn btn-primary" target="_blank">Open Website</a>
            </div>
            <p style="margin-top:10px;color:#666;">Saved locally on this server (no link parameters required). The website reads saved colors automatically from the same origin.</p>
            
            <!-- Restaurant Website Link -->
            <div style="margin-top: 2rem; padding: 1.5rem; background: #f8f9fa; border-radius: 12px; border: 2px solid #e5e7eb;">
              <h3 style="margin: 0 0 1rem 0; font-size: 1.1rem; color: #111827; display: flex; align-items: center; gap: 0.5rem;">
                <span class="material-symbols-rounded" style="font-size: 1.3rem; color: var(--primary-red);">link</span>
                Your Restaurant Website Link
              </h3>
              <p style="margin: 0 0 1rem 0; color: #6b7280; font-size: 0.9rem;">Share this unique link with your customers. Each restaurant has its own unique URL.</p>
              <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                <input type="text" id="restaurantWebsiteLink" readonly value="<?php 
                  $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                  $restaurant_slug = strtolower($restaurant_name);
                  $restaurant_slug = preg_replace('/[^a-z0-9]+/', '-', $restaurant_slug);
                  $restaurant_slug = trim($restaurant_slug, '-');
                  echo htmlspecialchars($base_url . $basePath . '/' . urlencode($restaurant_slug));
                ?>" style="flex: 1; min-width: 300px; padding: 0.75rem; border: 2px solid #d1d5db; border-radius: 8px; font-size: 0.9rem; background: white; color: #111827;">
                <button type="button" class="btn btn-primary" onclick="copyRestaurantLink()" style="white-space: nowrap;">
                  <span class="material-symbols-rounded">content_copy</span>
                  Copy Link
                </button>
              </div>
              <p style="margin: 0.75rem 0 0 0; color: #6b7280; font-size: 0.85rem;">
                <strong>Short URL format:</strong> <code style="background: #e5e7eb; padding: 0.2rem 0.4rem; border-radius: 4px; font-size: 0.85rem;"><?php 
                  $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                  echo htmlspecialchars($base_url . $basePath . '/' . urlencode($restaurant_slug));
                ?></code>
              </p>
            </div>
          </div>
          
          <div class="settings-section">
            <h2 class="settings-section-title">
              <span class="material-symbols-rounded">preview</span>
              Live Website Preview
            </h2>
            <p style="color:#666;font-size:0.9rem;margin-bottom:16px;line-height:1.6;">
              See how your restaurant looks to customers with your current banners, colors, and theme settings.
              Save theme changes first, then click <strong>Refresh Preview</strong>.
            </p>

            <!-- Device Toggle -->
            <div style="display:flex;gap:8px;margin-bottom:16px;">
              <button type="button" class="device-btn active" data-device="desktop" onclick="setPreviewDevice('desktop')" style="display:flex;align-items:center;gap:6px;padding:8px 16px;border:2px solid #dc2626;border-radius:8px;background:#dc2626;color:#fff;cursor:pointer;font-weight:600;font-size:.85rem;transition:all .2s;">
                <span class="material-symbols-rounded" style="font-size:18px;">desktop_windows</span>
                Desktop
              </button>
              <button type="button" class="device-btn" data-device="mobile" onclick="setPreviewDevice('mobile')" style="display:flex;align-items:center;gap:6px;padding:8px 16px;border:2px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-weight:600;font-size:.85rem;transition:all .2s;">
                <span class="material-symbols-rounded" style="font-size:18px;">phone_iphone</span>
                Mobile
              </button>
            </div>

            <!-- Live Preview Frame -->
            <div style="border:2px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;margin-bottom:16px;">
              <div id="previewFrameWrapper" style="transition:all .3s ease;margin:0 auto;">
                <iframe id="livePreview" src="<?php
                  $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                  $restaurant_slug = strtolower($restaurant_name);
                  $restaurant_slug = preg_replace('/[^a-z0-9]+/', '-', $restaurant_slug);
                  $restaurant_slug = trim($restaurant_slug, '-');
                  echo htmlspecialchars($base_url . $basePath . '/' . urlencode($restaurant_slug));
                ?>" style="width:100%;height:500px;border:none;display:block;" loading="lazy"></iframe>
              </div>
            </div>

            <!-- Preview Actions -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;">
              <button type="button" class="btn btn-outline" onclick="refreshPreview()" style="display:flex;align-items:center;gap:6px;">
                <span class="material-symbols-rounded" style="font-size:18px;">refresh</span>
                Refresh Preview
              </button>
              <a href="<?php
                $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                $restaurant_slug = strtolower($restaurant_name);
                $restaurant_slug = preg_replace('/[^a-z0-9]+/', '-', $restaurant_slug);
                $restaurant_slug = trim($restaurant_slug, '-');
                echo htmlspecialchars($base_url . $basePath . '/' . urlencode($restaurant_slug));
              ?>" class="btn btn-primary" target="_blank" style="display:flex;align-items:center;gap:6px;">
                <span class="material-symbols-rounded" style="font-size:18px;">open_in_new</span>
                Open in New Tab
              </a>
            </div>

            <!-- Banner Upload Section -->
            <div style="border-top:2px solid #e5e7eb;padding-top:20px;">
              <h3 style="font-size:1rem;font-weight:700;color:#111827;margin-bottom:12px;display:flex;align-items:center;gap:8px;">
                <span class="material-symbols-rounded" style="font-size:20px;">wallpaper</span>
                Banner Images (Slideshow)
              </h3>
              <div class="form-group">
                <label for="bannerUpload">Upload Banner Images</label>
                <p style="color:#666;font-size:0.9rem;margin-bottom:10px;">Upload multiple banner images to display as a slideshow on your website. Each image will display for 3 seconds. Recommended size: 1200x300px or similar. Max size: 5MB per image</p>
                <input type="file" id="bannerUpload" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" multiple style="margin-bottom:10px;">
                <button type="button" class="btn btn-primary" id="uploadBannerBtn" style="margin-bottom:10px;">
                  <span class="material-symbols-rounded">upload</span>
                  Upload Banners
                </button>
              </div>
              <div id="bannersPreview" style="margin-top:20px;display:block;">
                <label style="display:block;margin-bottom:10px;font-weight:600;">Banner Previews:</label>
                <div id="bannersGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-top:10px;min-height:50px;">
                  <p style="color:#666;grid-column:1/-1;text-align:center;padding:20px;">Loading banners...</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- Custom Domain / Embed Page -->
    <div id="embedPage" class="page">
      <div class="page-header">
        <h1>Custom Domain / Embed</h1>
        <p>Embed your restaurant menu on any website with a single code snippet.</p>
      </div>
      <div class="page-content">
        <div class="settings-grid">
          <div class="settings-section">
            <h2 class="settings-section-title">
              <span class="material-symbols-rounded">language</span>
              Embed Your Restaurant
            </h2>

            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding:16px;background:#f8f9fa;border:2px solid #e5e7eb;border-radius:12px">
              <div>
                <div style="font-weight:600;font-size:15px;color:#1a1b1f">Enable Custom Domain / Embed</div>
                <div style="font-size:12px;color:#888">Turn on to activate and configure your embed settings</div>
              </div>
              <label class="day-toggle-label">
                <input type="checkbox" id="embedToggle" onchange="toggleEmbedFeature(this.checked)">
                <span id="embedToggleLabel">Disabled</span>
              </label>
            </div>

            <div id="embedSettingsContent" style="display:none">
              <p style="color:#666;margin-bottom:20px;line-height:1.6">
                Paste this code on any website to display your full restaurant menu, cart, and ordering system.
                Everything loads from our server — updates to your menu, prices, or features reflect automatically.
              </p>

              <div style="background:#f8f9fa;border:2px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:20px">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
                  <div style="width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#e17055,#d63031);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:20px">&lt;/&gt;</div>
                  <div>
                    <div style="font-weight:600;font-size:15px;color:#1a1b1f">Your Embed Code</div>
                    <div style="font-size:12px;color:#888">Copy and paste this into your website HTML</div>
                  </div>
                </div>
                <div style="position:relative">
                  <textarea id="embedCodeDisplay" readonly style="width:100%;min-height:70px;padding:14px;border:1px solid #d1d5db;border-radius:10px;font-family:'Courier New',monospace;font-size:13px;background:#fff;color:#1a1b1f;resize:none;outline:none" onclick="this.select()"></textarea>
                  <button onclick="copyEmbedCode()" style="position:absolute;top:8px;right:8px;padding:6px 14px;border:none;border-radius:8px;background:#e17055;color:#fff;font-size:12px;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif">Copy</button>
                </div>
              </div>

              <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">
                <div style="background:#fff;border:2px solid #e5e7eb;border-radius:12px;padding:16px">
                  <div style="font-size:13px;color:#888;margin-bottom:4px">Restaurant ID</div>
                  <div style="font-weight:600;font-size:16px" id="embedRestaurantId">-</div>
                </div>
                <div style="background:#fff;border:2px solid #e5e7eb;border-radius:12px;padding:16px">
                  <div style="font-size:13px;color:#888;margin-bottom:4px">Status</div>
                  <div style="font-weight:600;font-size:16px;color:#27ae60" id="embedStatus">Active</div>
                </div>
              </div>

              <div style="background:#fff;border:2px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:20px">
                <div style="font-weight:600;font-size:15px;color:#1a1b1f;margin-bottom:8px">Custom Domain</div>
                <div style="font-size:12px;color:#888;margin-bottom:12px">Enter your own domain to host the menu (e.g. menu.yourdomain.com)</div>
                <div style="display:flex;gap:10px">
                  <input type="text" id="customDomainInput" placeholder="menu.yourdomain.com" style="flex:1;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;outline:none;font-family:'Poppins',sans-serif">
                  <button onclick="saveCustomDomain()" style="padding:10px 24px;border:none;border-radius:8px;background:#1a1b1f;color:#fff;font-size:13px;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;white-space:nowrap">Save</button>
                </div>
                <div id="customDomainUrlDisplay" style="display:none;margin-top:12px;padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:13px;color:#166534">
                  <strong>Your live URL:</strong> <a id="customDomainUrlLink" href="" target="_blank" style="color:#166534;text-decoration:underline"></a>
                </div>
                <div id="customDomainSetupInstructions" style="display:none;margin-top:12px;padding:12px;background:#f8f9fa;border:1px solid #e5e7eb;border-radius:8px;font-size:12px;color:#555;line-height:1.6">
                  <strong style="color:#1a1b1f">DNS Setup Instructions:</strong><br>
                  1. The restaurant owner goes to their domain registrar (GoDaddy, Namecheap, etc.)<br>
                  2. Adds an <strong>A record</strong> pointing to server IP: <code id="serverIpDisplay" style="background:#e5e7eb;padding:1px 5px;border-radius:3px;font-weight:600"></code><br>
                  3. Or adds a <strong>CNAME record</strong> pointing to: <code id="mainDomainDisplay" style="background:#e5e7eb;padding:1px 5px;border-radius:3px;font-weight:600"></code><br>
                  4. Once DNS propagates (up to 48 hours), the restaurant's website is live on their own domain<br>
                  5. All pages work automatically: <code style="background:#e5e7eb;padding:1px 4px;border-radius:3px">theirdomain.com/menu</code>, <code style="background:#e5e7eb;padding:1px 4px;border-radius:3px">theirdomain.com/cart</code>
                </div>
              </div>

              <div style="background:linear-gradient(135deg,#fef3ef,#fff);border:2px solid #f5d5cc;border-radius:12px;padding:16px">
                <div style="display:flex;align-items:flex-start;gap:10px">
                  <span class="material-symbols-rounded" style="color:#e17055;font-size:20px">info</span>
                  <div style="font-size:13px;color:#666;line-height:1.5">
                    <strong style="color:#1a1b1f">How it works:</strong><br>
                    1. The restaurant owner points their domain to our server (DNS setup above)<br>
                    2. Their full website loads directly on their domain — no iframe, no embed code needed<br>
                    3. All pages work naturally: <code style="background:#f1f1f1;padding:1px 4px;border-radius:3px">theirdomain.com/menu</code>, <code style="background:#f1f1f1;padding:1px 4px;border-radius:3px">theirdomain.com/cart</code>, etc.<br>
                    4. Menu updates, prices, and features reflect automatically
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="settings-section" id="embedPreviewSection" style="display:none">
            <h2 class="settings-section-title">
              <span class="material-symbols-rounded">preview</span>
              Preview
            </h2>
            <p style="color:#666;margin-bottom:12px;line-height:1.5">
              This is how your menu will appear on your website.
            </p>
            <div style="border:2px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff">
              <iframe id="embedPreview" src="" style="width:100%;height:500px;border:none" loading="lazy"></iframe>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- Category Management Page -->
    <div id="menuPage" class="page">
      <div class="page-header">
        <h1>Category Management</h1>
        <p>Create, edit, and manage your menus</p>
      </div>
      <div class="page-content">
        <div class="menu-actions">
          <button class="btn btn-primary" id="addMenuBtn">
            <span class="material-symbols-rounded">add</span>
            Add New Category
          </button>
          <button class="btn btn-secondary" id="sortMenusBtn">
            <span class="material-symbols-rounded">swap_vert</span>
            Sort
          </button>
        </div>
        
        <div class="menu-list" id="menuList">
          <!-- Menus will be loaded here dynamically -->
          <div class="loading">Loading menus...</div>
        </div>

        <!-- Sort mode bottom bar for categories -->
        <div id="menuSortBar" class="sort-bar" style="display:none;">
          <span class="sort-bar-info"><span class="material-symbols-rounded">info</span> Drag or use arrows to reorder, then save</span>
          <div class="sort-bar-actions">
            <button class="btn btn-secondary" id="cancelMenuSortBtn">Cancel</button>
            <button class="btn btn-primary" id="saveMenuSortBtn">
              <span class="material-symbols-rounded">save</span>
              Save Order
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Menu Items Page -->
    <div id="menuItemsPage" class="page">
      <div class="page-header">
        <h1>Menu Items Management</h1>
        <p>Create, edit, and manage your menu items</p>
      </div>
      <div class="page-content">
        <div class="menu-items-actions">
          <button class="btn btn-primary" id="addMenuItemBtn">
            <span class="material-symbols-rounded">add</span>
            Add New Menu Item
          </button>
          <button class="btn btn-secondary" id="sortMenuItemsBtn">
            <span class="material-symbols-rounded">swap_vert</span>
            Sort
          </button>
          
          <div class="filters">
            <select id="menuFilter" class="filter-select">
              <option value="">All Menus</option>
            </select>
            <select id="categoryFilter" class="filter-select">
              <option value="">All Categories</option>
            </select>
            <select id="subcategoryFilter" class="filter-select" style="display: none;">
              <option value="">All Subcategories</option>
            </select>
            <select id="typeFilter" class="filter-select">
              <option value="">All Types</option>
              <option value="Veg">Veg</option>
              <option value="Non Veg">Non Veg</option>
              <option value="Egg">Egg</option>
              <option value="Drink">Drink</option>
              <option value="Dessert">Dessert</option>
              <option value="Other">Other</option>
            </select>
          </div>
        </div>
        
        <div class="menu-items-list" id="menuItemsList">
          <!-- Menu items will be loaded here dynamically -->
          <div class="loading">Loading menu items...</div>
        </div>

        <!-- Sort mode bottom bar for menu items -->
        <div id="menuItemSortBar" class="sort-bar" style="display:none;">
          <span class="sort-bar-info"><span class="material-symbols-rounded">info</span> Use arrows to reorder, then save</span>
          <div class="sort-bar-actions">
            <button class="btn btn-secondary" id="cancelMenuItemSortBtn">Cancel</button>
            <button class="btn btn-primary" id="saveMenuItemSortBtn">
              <span class="material-symbols-rounded">save</span>
              Save Order
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Add-ons Page -->
    <div id="addonsPage" class="page">
      <div class="page-header">
        <h1>Add-ons Management</h1>
        <p>Manage your add-ons like extra cheese, ketchup, mayonnaise etc.</p>
      </div>
      <div class="page-content">
        <div id="addonsList" style="min-height:400px;">
          <div class="loading">Loading add-ons management...</div>
        </div>
      </div>
    </div>

    <!-- Inventory Page -->
    <div id="inventoryPage" class="page">
      <div class="page-header">
        <h1>Inventory</h1>
        <p>Track stock levels and record purchases/wastage</p>
      </div>
      <div class="page-content">
        <!-- Summary Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
          <div style="background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 1rem;">
              <div style="width: 50px; height: 50px; background: #e5f3ff; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <span class="material-symbols-rounded" style="color: #0066cc;">inventory_2</span>
              </div>
              <div>
                <div style="color: #666; font-size: 0.9rem; margin-bottom: 0.25rem;">Total Items</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #0066cc;" id="invTotalItems">0</div>
              </div>
            </div>
          </div>
          <div style="background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 1rem;">
              <div style="width: 50px; height: 50px; background: #fdecea; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <span class="material-symbols-rounded" style="color: #e74c3c;">warning</span>
              </div>
              <div>
                <div style="color: #666; font-size: 0.9rem; margin-bottom: 0.25rem;">Low Stock Items</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #e74c3c;" id="invLowStockCount">0</div>
              </div>
            </div>
          </div>
          <div style="background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 1rem;">
              <div style="width: 50px; height: 50px; background: #e5f7e5; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <span class="material-symbols-rounded" style="color: #28a745;">payments</span>
              </div>
              <div>
                <div style="color: #666; font-size: 0.9rem; margin-bottom: 0.25rem;">Total Stock Value</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #28a745;" id="invTotalValue"><?php echo htmlspecialchars($currency_symbol); ?>0.00</div>
              </div>
            </div>
          </div>
        </div>

        <div class="section-card" style="margin-bottom:20px;">
          <div class="section-header">
            <div class="section-title">Stock Items</div>
            <button class="btn btn-primary" id="btnNewInventoryItem" onclick="openInventoryModal()">+ New Item</button>
          </div>
          <div class="section-body" style="overflow-x:auto;">
            <table class="data-table" id="inventoryTable">
              <thead>
                <tr>
                  <th>Item</th><th>Category</th><th>Unit</th><th>In Stock</th><th>Low Stock At</th><th>Cost/Unit</th><th>Stock Value</th><th>Status</th><th>Actions</th>
                </tr>
              </thead>
              <tbody id="inventoryTbody">
                <tr><td colspan="9" style="text-align:center;padding:30px;color:#999;">Loading...</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="section-card">
          <div class="section-header">
            <div class="section-title">Recent Stock Purchases &amp; Adjustments</div>
          </div>
          <div class="section-body" style="overflow-x:auto;">
            <table class="data-table" id="inventoryHistoryTable">
              <thead>
                <tr>
                  <th>Date</th><th>Item</th><th>Type</th><th>Quantity</th><th>Cost/Unit</th><th>Total Cost</th><th>Notes</th>
                </tr>
              </thead>
              <tbody id="inventoryHistoryTbody">
                <tr><td colspan="7" style="text-align:center;padding:30px;color:#999;">Loading...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Inventory Item Modal -->
    <div id="inventoryModal" class="modal">
      <div class="modal-content" style="max-width:520px;">
        <div class="modal-header">
          <h2 id="inventoryModalTitle">New Inventory Item</h2>
          <button class="modal-close" onclick="closeModal('inventoryModal')">&times;</button>
        </div>
        <div class="modal-body">
          <form id="inventoryForm" onsubmit="return saveInventoryItem(event)">
            <input type="hidden" id="invId" value="">
            <div class="form-group">
              <label>Item Name *</label>
              <input type="text" id="invName" class="form-control" placeholder="e.g. Chicken Breast" maxlength="255" required>
            </div>
            <div class="row" style="display:flex;gap:12px;">
              <div class="form-group" style="flex:1;">
                <label>Unit</label>
                <select id="invUnit" class="form-control">
                  <option value="kg">Kilogram (kg)</option>
                  <option value="g">Gram (g)</option>
                  <option value="L">Litre (L)</option>
                  <option value="ml">Millilitre (ml)</option>
                  <option value="pcs">Pieces (pcs)</option>
                  <option value="box">Box</option>
                  <option value="packet">Packet</option>
                  <option value="unit">Unit</option>
                </select>
              </div>
              <div class="form-group" style="flex:1;">
                <label>Category</label>
                <input type="text" id="invCategory" class="form-control" placeholder="e.g. Produce, Meat, Packaging">
              </div>
            </div>
            <div class="row" style="display:flex;gap:12px;">
              <div class="form-group" style="flex:1;">
                <label>Low Stock Alert At</label>
                <input type="number" id="invThreshold" class="form-control" step="0.01" min="0" value="0">
              </div>
              <div class="form-group" style="flex:1;">
                <label>Cost per Unit (<?php echo htmlspecialchars($currency_symbol); ?>)</label>
                <input type="number" id="invCost" class="form-control" step="0.01" min="0" value="0">
              </div>
            </div>
            <div class="form-group">
              <label>Notes</label>
              <input type="text" id="invNotes" class="form-control" placeholder="Optional notes">
            </div>
            <div style="background:#f0f7ff;border-radius:8px;padding:10px 12px;font-size:12.5px;color:#4b5563;margin-bottom:14px;" id="invNewItemHint">
              New items start at 0 in stock — use "Restock" on the item after saving to add stock (this also records the purchase cost as an expense).
            </div>
            <button type="submit" class="btn btn-primary" id="invSaveBtn" style="width:100%;">Save Item</button>
          </form>
        </div>
      </div>
    </div>

    <!-- Restock Modal -->
    <div id="restockModal" class="modal">
      <div class="modal-content" style="max-width:460px;">
        <div class="modal-header">
          <h2>Restock Item</h2>
          <button class="modal-close" onclick="closeModal('restockModal')">&times;</button>
        </div>
        <div class="modal-body">
          <form id="restockForm" onsubmit="return saveRestock(event)">
            <input type="hidden" id="rsItemId" value="">
            <div class="form-group">
              <label id="rsItemLabel" style="font-weight:700;color:#1a1b1f;"></label>
            </div>
            <div class="row" style="display:flex;gap:12px;">
              <div class="form-group" style="flex:1;">
                <label>Quantity to Add *</label>
                <input type="number" id="rsQuantity" class="form-control" step="0.01" min="0.01" required>
              </div>
              <div class="form-group" style="flex:1;">
                <label>Cost per Unit (<?php echo htmlspecialchars($currency_symbol); ?>)</label>
                <input type="number" id="rsCost" class="form-control" step="0.01" min="0">
              </div>
            </div>
            <div class="form-group">
              <label>Purchase Date</label>
              <input type="date" id="rsDate" class="form-control">
            </div>
            <div class="form-group">
              <label>Notes</label>
              <input type="text" id="rsNotes" class="form-control" placeholder="e.g. Supplier name / invoice #">
            </div>
            <div style="background:#f0fdf4;border-radius:8px;padding:10px 12px;font-size:12.5px;color:#166534;margin-bottom:14px;">
              This restock will be recorded as an "Inventory Purchase" expense and reflected in Reports.
            </div>
            <button type="submit" class="btn btn-primary" id="rsSaveBtn" style="width:100%;">Add Stock</button>
          </form>
        </div>
      </div>
    </div>

    <!-- Adjust / Wastage Modal -->
    <div id="adjustModal" class="modal">
      <div class="modal-content" style="max-width:460px;">
        <div class="modal-header">
          <h2>Adjust Stock</h2>
          <button class="modal-close" onclick="closeModal('adjustModal')">&times;</button>
        </div>
        <div class="modal-body">
          <form id="adjustForm" onsubmit="return saveAdjust(event)">
            <input type="hidden" id="adjItemId" value="">
            <div class="form-group">
              <label id="adjItemLabel" style="font-weight:700;color:#1a1b1f;"></label>
            </div>
            <div class="form-group">
              <label>Reason</label>
              <select id="adjType" class="form-control">
                <option value="wastage">Wastage / Spoilage (reduces stock, logs a loss expense)</option>
                <option value="adjustment">Stock Correction (fix a miscount, no expense)</option>
              </select>
            </div>
            <div class="row" style="display:flex;gap:12px;">
              <div class="form-group" style="flex:1;" id="adjQtyWrap">
                <label id="adjQtyLabel">Quantity Lost *</label>
                <input type="number" id="adjQuantity" class="form-control" step="0.01" required>
              </div>
            </div>
            <div class="form-group">
              <label>Notes</label>
              <input type="text" id="adjNotes" class="form-control" placeholder="e.g. Spilled, expired, miscount">
            </div>
            <button type="submit" class="btn btn-primary" id="adjSaveBtn" style="width:100%;">Save</button>
          </form>
        </div>
      </div>
    </div>

    <!-- Payments Page -->
    <div id="paymentsPage" class="page">
      <div class="page-header">
        <h1>Payment Transactions</h1>
        <p>View and manage all payment transactions</p>
      </div>
      <div class="page-content">
        <!-- Filters -->
        <div style="display: flex; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap;">
          <input type="text" id="paymentSearch" placeholder="Search by order, amount..." style="flex: 1; min-width: 250px; padding: 0.75rem; border: 2px solid #e0e0e0; border-radius: 8px;" />
          <select id="paymentMethodFilter" style="padding: 0.75rem; border: 2px solid #e0e0e0; border-radius: 8px;">
            <option value="">All Methods</option>
            <option value="Cash">Cash</option>
            <option value="Card">Card</option>
            <option value="UPI">UPI</option>
            <option value="Online">Online</option>
            <option value="Wallet">Wallet</option>
          </select>
          <select id="paymentStatusFilter" style="padding: 0.75rem; border: 2px solid #e0e0e0; border-radius: 8px;">
            <option value="">All Status</option>
            <option value="Success">Success</option>
            <option value="Failed">Failed</option>
            <option value="Pending">Pending</option>
            <option value="Refunded">Refunded</option>
          </select>
          <button onclick="exportPaymentsToCSV()" style="padding: 0.75rem 1.5rem; background: #28a745; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem;">
            <span class="material-symbols-rounded" style="font-size: 1rem;">download</span>
            Export CSV
          </button>
        </div>

        <!-- Payments Table -->
        <div class="card">
          <table class="data-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Amount</th>
                <th>Payment Method</th>
                <th>Transaction ID</th>
                <th>Order</th>
                <th>Status</th>
                <th>Date & Time</th>
              </tr>
            </thead>
            <tbody id="paymentsTableBody">
              <tr>
                <td colspan="7" style="text-align: center; padding: 2rem;">
                  <div class="loading">Loading payments...</div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Gateway Page -->
    <div id="gatewayPage" class="page">
      <div class="page-header">
        <h1>Payment Gateway Settings</h1>
        <p>Configure your payment gateways for online UPI / NetBanking payments</p>
      </div>
      <div class="page-content">
        <?php if (($payment_gateway_type_db ?? 'cash_only') !== 'cash_only'): ?>
        <form id="paymentGatewayForm">
          <?php
          $gwMode = $payment_gateway_mode ?? 'own';
          ?>
          <!-- Gateway Mode Selector -->
          <div class="profile-card-modern" style="margin-bottom:1.5rem;">
            <div class="profile-card-header">
              <h3>
                <span class="material-symbols-rounded">settings</span>
                Gateway Mode
              </h3>
              <p class="card-description">Choose how you want to handle online payments</p>
            </div>
            <div class="profile-card-body">
              <div style="display:flex;flex-direction:column;gap:1rem;">
                <label style="display:flex;align-items:flex-start;gap:12px;padding:1rem;border:2px solid <?php echo $gwMode === 'own' ? '#dc2626' : '#e5e7eb'; ?>;border-radius:12px;cursor:pointer;background:<?php echo $gwMode === 'own' ? '#fff5f5' : 'white'; ?>;transition:all 0.2s;" onclick="document.getElementById('gwModeOwn').checked=true;toggleGatewayMode();">
                  <input type="radio" name="payment_gateway_mode" id="gwModeOwn" value="own" <?php echo $gwMode === 'own' ? 'checked' : ''; ?> style="margin-top:3px;accent-color:#dc2626;" onchange="toggleGatewayMode()">
                  <div>
                    <div style="font-weight:600;color:#111827;">Use my own credentials</div>
                    <div style="font-size:0.85rem;color:#6b7280;margin-top:4px;">Enter your own PhonePe API keys. Payments go directly to your account.</div>
                  </div>
                </label>
                <label style="display:flex;align-items:flex-start;gap:12px;padding:1rem;border:2px solid <?php echo $gwMode === 'platform' ? '#dc2626' : '#e5e7eb'; ?>;border-radius:12px;cursor:pointer;background:<?php echo $gwMode === 'platform' ? '#fff5f5' : 'white'; ?>;transition:all 0.2s;" onclick="document.getElementById('gwModePlatform').checked=true;toggleGatewayMode();">
                  <input type="radio" name="payment_gateway_mode" id="gwModePlatform" value="platform" <?php echo $gwMode === 'platform' ? 'checked' : ''; ?> style="margin-top:3px;accent-color:#dc2626;" onchange="toggleGatewayMode()">
                  <div>
                    <div style="font-weight:600;color:#111827;">Use Platform API</div>
                    <div style="font-size:0.85rem;color:#6b7280;margin-top:4px;">Use our platform's payment gateway. No configuration needed. Settlement in 2 working days.</div>
                  </div>
                </label>
              </div>
            </div>
          </div>

          <!-- Own Credentials Section -->
          <div id="ownCredentialsSection" style="display: <?php echo $gwMode === 'own' ? 'block' : 'none'; ?>;">
          <div class="profile-card-modern">
            <div class="profile-card-header">
              <h3>
                <span class="material-symbols-rounded">phone_android</span>
                PhonePe
              </h3>
            </div>
            <div class="profile-card-body">
              <div class="form-row">
                <div class="form-group">
                  <label for="phonepeMerchantId">
                    <span class="material-symbols-rounded">key</span>
                    Merchant ID
                  </label>
                  <input type="text" id="phonepeMerchantId" name="phonepe_merchant_id" placeholder="Enter PhonePe Merchant ID" value="<?php echo htmlspecialchars($phonepe_merchant_id ?? ''); ?>">
                </div>
                <div class="form-group">
                  <label for="phonepeSaltKey">
                    <span class="material-symbols-rounded">lock</span>
                    Salt Key
                  </label>
                  <input type="password" id="phonepeSaltKey" name="phonepe_salt_key" placeholder="Enter PhonePe Salt Key" value="<?php echo htmlspecialchars($phonepe_salt_key ?? ''); ?>">
                </div>
              </div>
              <div class="form-group">
                <label for="phonepeEnvironment">
                  <span class="material-symbols-rounded">cloud</span>
                  Environment
                </label>
                <select id="phonepeEnvironment" name="phonepe_environment">
                  <option value="SANDBOX" <?php echo ($phonepe_environment ?? 'SANDBOX') === 'SANDBOX' ? 'selected' : ''; ?>>Sandbox (Test)</option>
                  <option value="PRODUCTION" <?php echo ($phonepe_environment ?? '') === 'PRODUCTION' ? 'selected' : ''; ?>>Production (Live)</option>
                </select>
              </div>
              <div class="form-actions" style="margin-top:1.5rem;">
                <button type="submit" class="btn btn-save">
                  <span class="material-symbols-rounded">save</span>
                  Save Payment Gateways
                </button>
              </div>
            </div>
          </div>
          </div><!-- end ownCredentialsSection -->

          <!-- Always-visible Save button for gateway mode -->
          <div style="margin-top:1rem;text-align:center;">
            <button type="submit" class="btn btn-save">
              <span class="material-symbols-rounded">save</span>
              Save Settings
            </button>
          </div>
        </form>
<script>
function toggleGatewayMode() {
  var mode = document.querySelector('input[name="payment_gateway_mode"]:checked');
  if (!mode) return;
  var section = document.getElementById('ownCredentialsSection');
  if (section) {
    section.style.display = mode.value === 'own' ? 'block' : 'none';
  }
  // Update card highlight
  document.querySelectorAll('label[onclick*="gwMode"]').forEach(function(l) {
    var radio = l.querySelector('input[type="radio"]');
    if (radio) {
      l.style.borderColor = radio.checked ? '#dc2626' : '#e5e7eb';
      l.style.background = radio.checked ? '#fff5f5' : 'white';
    }
  });
}
(function(){
  var f = document.getElementById('paymentGatewayForm');
  if (f && !f.dataset.gwHandler) {
    f.dataset.gwHandler = '1';
    f.addEventListener('submit', async function(e) {
      e.preventDefault();
      var btn = e.target.tagName === 'BUTTON' ? e.target : f.querySelector('button[type="submit"]');
      var orig = btn ? btn.innerHTML : '';
      if (btn) { btn.disabled = true; btn.innerHTML = '<span class="material-symbols-rounded">hourglass_empty</span> Saving...'; }
      try {
        var gwMode = document.querySelector('input[name="payment_gateway_mode"]:checked');
        var r = await fetch('../admin/auth.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'action=updatePaymentGateway&payment_gateway_mode=' + encodeURIComponent(gwMode ? gwMode.value : 'own') + '&phonepe_merchant_id=' + encodeURIComponent(document.getElementById('phonepeMerchantId')?.value || '') + '&phonepe_salt_key=' + encodeURIComponent(document.getElementById('phonepeSaltKey')?.value || '') + '&phonepe_environment=' + encodeURIComponent(document.getElementById('phonepeEnvironment')?.value || 'SANDBOX')
        });
        var d = await r.json();
        var notify = window.showNotification || function(m){alert(m)};
        if (d.success) { notify('Payment gateway settings saved', 'success'); location.reload(); }
        else { notify(d.message || 'Save failed', 'error'); }
      } catch(e) {
        (window.showNotification || alert)('Error saving payment gateway settings', 'error');
      } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = orig; }
      }
    });
  }
})();
</script>
        <?php else: ?>
        <div class="card" style="padding:2rem;text-align:center;color:#6b7280;">
          <span class="material-symbols-rounded" style="font-size:3rem;color:#d1d5db;display:block;margin-bottom:1rem;">payments</span>
          <h3 style="margin:0 0 0.5rem;">Payment Gateway Disabled</h3>
          <p>Enable Payment Gateway from Superadmin to configure PhonePe.</p>
        </div>
        <?php endif; ?>

        <div class="card" style="margin-top:2rem;">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem;">
            <div>
              <h2 style="margin:0;">Payment Methods</h2>
              <p style="margin:4px 0 0;color:#6b7280;font-size:0.9rem;">Cash, Card and UPI are built in. Add extra methods your restaurant accepts at the counter (Wallet, Bank Transfer, etc.) — they'll show up at POS and in your reports automatically.</p>
            </div>
            <button type="button" class="btn btn-primary" onclick="openPaymentMethodModal()" style="white-space:nowrap;">
              <span class="material-symbols-rounded">add</span> Add Method
            </button>
          </div>
          <div id="paymentMethodsList" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;">
            <div style="text-align:center;padding:2rem;color:#6b7280;grid-column:1/-1;">Loading payment methods...</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Reports Page -->
    <div id="reportsPage" class="page">
      <div class="page-header">
        <h1>Sales Reports</h1>
        <p>View detailed sales reports and analytics</p>
      </div>
      <div class="page-content">
        <!-- Report Filter Controls -->
        <div class="report-filters" style="display: flex; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap; background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
          <div style="flex: 1; min-width: 200px;">
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Period</label>
            <select id="reportPeriod" style="width: 100%; padding: 0.75rem; border: 2px solid var(--light-gray); border-radius: 8px; font-size: 1rem;">
              <option value="today">Today</option>
              <option value="week">This Week</option>
              <option value="month">This Month</option>
              <option value="year">This Year</option>
              <option value="custom">Custom Date Range</option>
            </select>
          </div>
          <div id="customDateRange" style="display: none; flex: 1; min-width: 200px;">
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Start Date</label>
            <input type="date" id="reportStartDate" style="width: 100%; padding: 0.75rem; border: 2px solid var(--light-gray); border-radius: 8px; font-size: 1rem;">
          </div>
          <div id="customDateRangeEnd" style="display: none; flex: 1; min-width: 200px;">
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">End Date</label>
            <input type="date" id="reportEndDate" style="width: 100%; padding: 0.75rem; border: 2px solid var(--light-gray); border-radius: 8px; font-size: 1rem;">
          </div>
          <div style="flex: 1; min-width: 200px;">
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Report Type</label>
            <select id="reportType" style="width: 100%; padding: 0.75rem; border: 2px solid var(--light-gray); border-radius: 8px; font-size: 1rem;">
              <option value="sales">Sales Report</option>
              <option value="customers">Customer Report</option>
              <option value="items">Top Items Report</option>
              <option value="payment">Payment Methods Report</option>
              <option value="hourly">Hourly Sales Report</option>
              <option value="staff">Staff Performance Report</option>
            </select>
          </div>
          <div id="paymentMethodFilterWrapper" style="flex: 1; min-width: 200px; display: none;">
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Payment Method</label>
            <select id="filterPaymentMethod" style="width: 100%; padding: 0.75rem; border: 2px solid var(--light-gray); border-radius: 8px; font-size: 1rem;">
              <option value="all">All Methods</option>
              <option value="Cash">Cash</option>
              <option value="Card">Card</option>
              <option value="UPI">UPI</option>
            </select>
          </div>
          <div style="display: flex; align-items: flex-end; gap: 0.5rem;">
            <button onclick="loadReports()" style="padding: 0.75rem 2rem; background: var(--primary-red); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem;">
              <span class="material-symbols-rounded">refresh</span> Refresh
            </button>
            <button onclick="exportReportsToCSV()" style="padding: 0.75rem 2rem; background: #10b981; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem;">
              <span class="material-symbols-rounded">download</span> Export CSV
            </button>
          </div>
        </div>

        <!-- Sales Summary Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
          <div style="background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 1rem;">
              <div style="width: 50px; height: 50px; background: #ffe5e5; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <span class="material-symbols-rounded" style="color: var(--primary-red);">payments</span>
              </div>
              <div>
                <div style="color: #666; font-size: 0.9rem; margin-bottom: 0.25rem;">Total Sales</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-red);" id="reportTotalSales"><?php echo htmlspecialchars($currency_symbol); ?>0.00</div>
              </div>
            </div>
          </div>
          
          <div style="background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 1rem;">
              <div style="width: 50px; height: 50px; background: #e5f3ff; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <span class="material-symbols-rounded" style="color: #0066cc;">receipt_long</span>
              </div>
              <div>
                <div style="color: #666; font-size: 0.9rem; margin-bottom: 0.25rem;">Total Orders</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #0066cc;" id="reportTotalOrders">0</div>
              </div>
            </div>
          </div>
          
          <div style="background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 1rem;">
              <div style="width: 50px; height: 50px; background: #e5f7e5; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <span class="material-symbols-rounded" style="color: #28a745;">shopping_bag</span>
              </div>
              <div>
                <div style="color: #666; font-size: 0.9rem; margin-bottom: 0.25rem;">Items Sold</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #28a745;" id="reportTotalItems">0</div>
              </div>
            </div>
          </div>
          
          <div style="background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 1rem;">
              <div style="width: 50px; height: 50px; background: #fff3cd; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <span class="material-symbols-rounded" style="color: #ffc107;">people</span>
              </div>
              <div>
                <div style="color: #666; font-size: 0.9rem; margin-bottom: 0.25rem;">Customers</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #ffc107;" id="reportTotalCustomers">0</div>
              </div>
            </div>
          </div>

          <div style="background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 1rem;">
              <div style="width: 50px; height: 50px; background: #fdecea; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <span class="material-symbols-rounded" style="color: #e74c3c;">trending_down</span>
              </div>
              <div>
                <div style="color: #666; font-size: 0.9rem; margin-bottom: 0.25rem;">Total Expenses</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #e74c3c;" id="reportTotalExpenses"><?php echo htmlspecialchars($currency_symbol); ?>0.00</div>
              </div>
            </div>
          </div>

          <div style="background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 1rem;">
              <div style="width: 50px; height: 50px; background: #e5f7e5; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <span class="material-symbols-rounded" style="color: #28a745;">trending_up</span>
              </div>
              <div>
                <div style="color: #666; font-size: 0.9rem; margin-bottom: 0.25rem;">Net Profit (Sales − Expenses)</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #28a745;" id="reportNetProfit"><?php echo htmlspecialchars($currency_symbol); ?>0.00</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Sales Data Table -->
        <div style="background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
          <div style="padding: 1.5rem; border-bottom: 2px solid var(--light-gray);">
            <h2 style="margin: 0; font-size: 1.3rem; color: var(--primary-red);">Sales Details</h2>
          </div>
          <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
              <thead>
                <tr style="background: var(--light-gray);">
                  <th style="padding: 1rem; text-align: left; font-weight: 600;">Date</th>
                  <th style="padding: 1rem; text-align: left; font-weight: 600;">Order #</th>
                  <th style="padding: 1rem; text-align: left; font-weight: 600;">Customer</th>
                  <th style="padding: 1rem; text-align: left; font-weight: 600;">Items</th>
                  <th style="padding: 1rem; text-align: left; font-weight: 600;">Payment</th>
                  <th style="padding: 1rem; text-align: right; font-weight: 600;">Amount</th>
                </tr>
              </thead>
              <tbody id="reportSalesTable">
                <tr>
                  <td colspan="6" style="padding: 2rem; text-align: center; color: #666;">Loading sales data...</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Bottom Charts -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 2rem;">
          <div style="background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <div style="padding: 1.5rem; border-bottom: 2px solid var(--light-gray);">
              <h2 style="margin: 0; font-size: 1.3rem; color: var(--primary-red);">Top Selling Items</h2>
            </div>
            <div id="reportTopItems" style="padding: 1rem;">
              <div style="text-align: center; padding: 2rem; color: #666;">Loading...</div>
            </div>
          </div>
          
          <div style="background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <div style="padding: 1.5rem; border-bottom: 2px solid var(--light-gray);">
              <h2 style="margin: 0; font-size: 1.3rem; color: var(--primary-red);">Payment Methods</h2>
            </div>
            <div id="reportPaymentMethods" style="padding: 1rem;">
              <div style="text-align: center; padding: 2rem; color: #666;">Loading...</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Analytics Page -->
    <div id="analyticsPage" class="page">
      <div class="page-header">
        <div class="dashboard-header-row">
          <div>
            <h1>Analytics</h1>
            <p>Track your restaurant's performance, traffic, and insights</p>
          </div>
          <div style="display:flex;align-items:center;gap:12px;">
            <select id="analyticsPeriod" onchange="loadAnalytics()" style="padding:8px 14px;border-radius:8px;border:1.5px solid #d1d5db;font-size:13px;font-weight:500;background:#fff;cursor:pointer;outline:none;font-family:inherit;">
              <option value="7">Last 7 Days</option>
              <option value="30" selected>Last 30 Days</option>
              <option value="90">Last 90 Days</option>
            </select>
            <button class="btn-refresh-dashboard" onclick="loadAnalytics()" title="Refresh">
              <span class="material-symbols-rounded">refresh</span>
            </button>
          </div>
        </div>
      </div>
      <div class="page-content" id="analyticsContent">
        <div style="text-align:center;padding:80px 20px;">
          <span class="material-symbols-rounded" style="font-size:64px;color:#ccc;margin-bottom:16px;display:block;">analytics</span>
          <h3 style="margin:0 0 8px;color:#1f2937;font-size:1.3rem;">Loading Analytics...</h3>
          <p style="color:#9ca3af;margin:0;font-size:0.95rem;">Fetching your data.</p>
        </div>
      </div>
    </div>

    <!-- Marketing Page -->
    <div id="marketingPage" class="page">
      <div class="page-header">
        <div>
          <h1>Marketing</h1>
          <p>Promote your restaurant and reach more customers</p>
        </div>
      </div>
      <div class="page-content">
        <div style="text-align:center;padding:80px 20px;">
          <span class="material-symbols-rounded" style="font-size:64px;color:#ccc;margin-bottom:16px;display:block;">campaign</span>
          <h3 style="margin:0 0 8px;color:#1f2937;font-size:1.3rem;">Coming Soon</h3>
          <p style="color:#9ca3af;margin:0;font-size:0.95rem;">Marketing tools are on the way. Stay tuned!</p>
        </div>
      </div>
    </div>

    <style>
      #analyticsContent .section-card { background:#fff; border-radius:12px; padding:20px; margin-bottom:16px; box-shadow:0 1px 3px rgba(0,0,0,0.06); border:1px solid #e5e7eb; }
      #analyticsContent .section-card h3 { margin:0 0 12px; font-size:15px; font-weight:700; color:#111827; display:flex; align-items:center; gap:8px; }
      #analyticsContent .section-card h3 .material-symbols-rounded { font-size:20px; color:#6b7280; }
      .analytics-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:16px; }
      .analytics-stat-card { background:#fff; border-radius:12px; padding:18px 20px; border:1px solid #e5e7eb; box-shadow:0 1px 3px rgba(0,0,0,0.06); display:flex; align-items:center; gap:14px; transition:all 0.2s; }
      .analytics-stat-card:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,0.1); }
      .analytics-stat-card .stat-icon { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
      .analytics-stat-card .stat-icon .material-symbols-rounded { font-size:22px; color:#fff; }
      .analytics-stat-card .stat-info { min-width:0; }
      .analytics-stat-card .stat-label { font-size:12px; color:#6b7280; font-weight:500; margin-bottom:2px; }
      .analytics-stat-card .stat-value { font-size:20px; font-weight:800; color:#111827; line-height:1.2; }
      .analytics-stat-card .stat-sub { font-size:11px; color:#9ca3af; margin-top:2px; }
      .analytics-bar { display:flex; align-items:center; gap:8px; margin:4px 0; }
      .analytics-bar .bar-label { width:80px; font-size:12px; color:#374151; font-weight:500; flex-shrink:0; text-align:right; }
      .analytics-bar .bar-track { flex:1; height:22px; background:#f3f4f6; border-radius:6px; overflow:hidden; position:relative; }
      .analytics-bar .bar-fill { height:100%; border-radius:6px; background:linear-gradient(90deg,#3b82f6,#2563eb); transition:width 0.6s ease; min-width:4px; display:flex; align-items:center; justify-content:flex-end; padding-right:6px; }
      .analytics-bar .bar-fill .bar-count { font-size:10px; color:#fff; font-weight:700; }
      .analytics-bar .bar-pct { width:36px; font-size:11px; color:#6b7280; font-weight:600; flex-shrink:0; }
      .analytics-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
      @media(max-width:768px){ .analytics-grid-2 { grid-template-columns:1fr; } }
      .visit-row { display:flex; align-items:center; padding:8px 0; border-bottom:1px solid #f3f4f6; font-size:12px; gap:8px; }
      .visit-row:last-child { border-bottom:none; }
      .visit-row .visit-page { flex:1; font-weight:600; color:#111827; }
      .visit-row .visit-ip { color:#6b7280; width:100px; }
      .visit-row .visit-time { color:#9ca3af; width:130px; text-align:right; }
      .hour-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(28px,1fr)); gap:4px; }
      .hour-block { text-align:center; padding:6px 2px; border-radius:6px; font-size:10px; font-weight:600; transition:transform 0.2s; }
      .hour-block:hover { transform:scale(1.15); }
      .hour-block .hour-label { color:#6b7280; margin-bottom:2px; }
      .hour-block .hour-count { font-size:11px; }
      .status-chip { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; }
      .analytics-table { width:100%; border-collapse:collapse; font-size:13px; }
      .analytics-table th { text-align:left; padding:8px 12px; border-bottom:2px solid #e5e7eb; color:#6b7280; font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; }
      .analytics-table td { padding:10px 12px; border-bottom:1px solid #f3f4f6; color:#374151; }
      .analytics-table tr:hover td { background:#f9fafb; }
    </style>

    <!-- Coupons Page -->
    <div id="couponsPage" class="page">
      <div class="page-header">
        <h1>Coupons</h1>
        <p>Manage discount coupons for your customers</p>
      </div>
      <div class="page-content">
        <div class="section-card">
          <div class="section-header">
            <div class="section-title">All Coupons</div>
            <button class="btn btn-primary" id="btnNewCoupon" onclick="openCouponModal()">+ New Coupon</button>
          </div>
          <div class="section-body" style="overflow-x:auto;">
            <table class="data-table" id="couponsTable">
              <thead>
                <tr>
                  <th>Code</th><th>Type</th><th>Value</th><th>Min Order</th><th>Uses</th><th>Valid</th><th>Created</th><th>Status</th><th>Actions</th>
                </tr>
              </thead>
              <tbody id="couponsTbody">
                <tr><td colspan="9" style="text-align:center;padding:30px;color:#999;">Loading...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Deals Page -->
    <div id="dealsPage" class="page">
      <div class="page-header">
        <h1>Deals</h1>
        <p>Manage combo and special deals</p>
      </div>
      <div class="page-content">
        <div class="section-card" style="margin-bottom:20px;">
          <div class="section-header">
            <div class="section-title">Create New Deal</div>
          </div>
          <div class="section-body">
            <form id="dealForm" onsubmit="return saveDeal(event)" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
              <input type="hidden" id="dealId" value="">
              <div style="flex:1;min-width:160px;">
                <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:4px;">Deal Type</label>
                <select id="dealType" class="form-control" required>
                  <option value="">Select type...</option>
                  <option value="combo">Combo Deal</option>
                  <option value="new">New Deal</option>
                </select>
              </div>
              <div style="flex:2;min-width:200px;">
                <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:4px;">Select Menu</label>
                <select id="dealMenu" class="form-control" required>
                  <option value="">Select menu...</option>
                </select>
              </div>
              <button type="submit" class="btn btn-primary" id="dealSaveBtn" style="height:42px;">+ Create Deal</button>
            </form>
          </div>
        </div>
        <div class="section-card">
          <div class="section-header">
            <div class="section-title">Active Deals</div>
          </div>
          <div class="section-body" style="overflow-x:auto;">
            <table class="data-table" id="dealsTable">
              <thead>
                <tr>
                  <th>Type</th><th>Menu</th><th>Created</th><th>Actions</th>
                </tr>
              </thead>
              <tbody id="dealsTbody">
                <tr><td colspan="4" style="text-align:center;padding:30px;color:#999;">Loading deals...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Coupon Modal -->
    <div id="couponModal" class="modal">
      <div class="modal-content" style="max-width:520px;">
        <div class="modal-header">
          <h2 id="couponModalTitle">New Coupon</h2>
          <button class="modal-close" onclick="closeModal('couponModal')">&times;</button>
        </div>
        <div class="modal-body">
          <form id="couponForm" onsubmit="return saveCoupon(event)">
            <input type="hidden" id="couponId" value="">
            <div class="form-group">
              <label>Coupon Code *</label>
              <input type="text" id="cpnCode" class="form-control" placeholder="e.g. SAVE20" maxlength="50" required>
            </div>
            <div class="row" style="display:flex;gap:12px;">
              <div class="form-group" style="flex:1;">
                <label>Discount Type</label>
                <select id="cpnType" class="form-control">
                  <option value="percent">Percentage (%)</option>
                  <option value="flat">Flat Amount (<?php echo htmlspecialchars($currency_symbol); ?>)</option>
                </select>
              </div>
              <div class="form-group" style="flex:1;">
                <label>Discount Value *</label>
                <input type="number" id="cpnValue" class="form-control" step="0.01" min="1" placeholder="10" required>
              </div>
            </div>
            <div class="form-group">
              <label>Description</label>
              <input type="text" id="cpnDesc" class="form-control" placeholder="e.g. 10% off on first order">
            </div>
            <div class="row" style="display:flex;gap:12px;">
              <div class="form-group" style="flex:1;">
                <label>Min. Order Amount (<?php echo htmlspecialchars($currency_symbol); ?>)</label>
                <input type="number" id="cpnMinOrder" class="form-control" step="1" min="0" value="0">
              </div>
              <div class="form-group" style="flex:1;">
                <label>Max Uses (0 = unlimited)</label>
                <input type="number" id="cpnMaxUses" class="form-control" min="0" value="0">
              </div>
            </div>
            <div class="row" style="display:flex;gap:12px;">
              <div class="form-group" style="flex:1;">
                <label>Valid From</label>
                <input type="date" id="cpnValidFrom" class="form-control">
              </div>
              <div class="form-group" style="flex:1;">
                <label>Valid Until</label>
                <input type="date" id="cpnValidUntil" class="form-control">
              </div>
            </div>
            <div class="form-actions" style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end;">
              <button type="button" class="btn btn-secondary" onclick="closeModal('couponModal')">Cancel</button>
              <button type="submit" class="btn btn-primary" id="cpnSaveBtn">Save Coupon</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Delivery Page -->
    <div id="deliveryPage" class="page">
      <div class="page-header">
        <h1>Delivery Zones</h1>
        <p>Manage pincode-based delivery zones, charges, and availability</p>
      </div>
      <div class="page-content">
        <div class="menu-actions">
          <button class="btn btn-primary" onclick="openZoneModal()">
            <span class="material-symbols-rounded">add</span>
            Add Zone
          </button>
        </div>
        <div class="section-card">
          <div class="section-body" style="overflow-x:auto;">
            <table class="data-table" id="deliveryZonesTable">
              <thead>
                <tr>
                  <th>Pincode</th>
                  <th>Zone Name</th>
                  <th>Delivery Charge</th>
                  <th>ETA (min)</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="deliveryZonesTbody">
                <tr><td colspan="6" style="text-align:center;padding:30px;color:#999;">Loading...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Zone Modal -->
    <div id="zoneModal" class="modal">
      <div class="modal-content" style="max-width:480px;">
        <div class="modal-header">
          <h2 id="zoneModalTitle">Add Delivery Zone</h2>
          <button class="modal-close" onclick="closeModal('zoneModal')">&times;</button>
        </div>
        <div class="modal-body">
          <form id="zoneForm" onsubmit="return saveZone(event)">
            <input type="hidden" id="zoneId" value="">
            <div class="form-group">
              <label>Pincode *</label>
              <input type="text" id="zonePincode" class="form-control" placeholder="e.g. 201301" maxlength="10" required>
            </div>
            <div class="form-group">
              <label>Zone Name</label>
              <input type="text" id="zoneName" class="form-control" placeholder="e.g. Sector 62">
            </div>
            <div class="row" style="display:flex;gap:12px;">
              <div class="form-group" style="flex:1;">
                <label>Delivery Charge (<?php echo htmlspecialchars($currency_symbol); ?>) *</label>
                <input type="number" id="zoneCharge" class="form-control" placeholder="0" min="0" step="0.01" required>
              </div>
            </div>
            <div class="form-group">
              <label>Estimated Time (minutes)</label>
              <input type="number" id="zoneEta" class="form-control" placeholder="30" min="1" value="30">
            </div>
            <div class="form-group">
              <label class="checkbox-label" style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" id="zoneActive" checked>
                Active
              </label>
            </div>
            <div class="form-actions" style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end;">
              <button type="button" class="btn btn-secondary" onclick="closeModal('zoneModal')">Cancel</button>
              <button type="submit" class="btn btn-primary" id="zoneSaveBtn">Save Zone</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Delivery Map Page -->
    <div id="deliveryMapPage" class="page">
      <div class="page-header">
        <h1>Delivery Map</h1>
        <p>Track active deliveries in real-time</p>
      </div>
      <div class="page-content">
        <div style="margin-bottom: 10px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
          <span style="font-size: 13px; color: #6b7280;">Auto-refreshes every 30s</span>
          <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px;"><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#f59e0b;"></span> Preparing</span>
          <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px;"><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#3b82f6;"></span> Assigned</span>
          <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px;"><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#8b5cf6;"></span> Picked Up</span>
          <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px;"><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#059669;"></span> In Transit</span>
        </div>
        <div class="section-card" style="padding: 0;">
          <div id="deliveryMap" style="height: 600px; border-radius: 8px;"></div>
        </div>
        <div style="margin-top: 12px; text-align: center; font-size: 12px; color: #9ca3af;">
          <span id="deliveryMapStatus">Loading...</span>
        </div>
      </div>
    </div>

    <!-- Feedback Page -->
    <div id="feedbackPage" class="page">
      <div class="page-header">
        <h1>Customer Feedback</h1>
        <p>Reviews and ratings from your customers</p>
      </div>
      <div class="page-content">
        <div style="display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap;">
          <div style="flex:1;min-width:160px;background:#f0fdf4;border-radius:12px;padding:16px;text-align:center;border:1px solid #bbf7d0;">
            <div style="font-size:28px;font-weight:700;color:#16a34a;" id="fbTotal">0</div>
            <div style="font-size:12px;color:#6b7280;margin-top:4px;">Total Reviews</div>
          </div>
          <div style="flex:1;min-width:160px;background:#fffbeb;border-radius:12px;padding:16px;text-align:center;border:1px solid #fde68a;">
            <div style="font-size:28px;font-weight:700;color:#d97706;" id="fbAvg">0.0</div>
            <div style="font-size:12px;color:#6b7280;margin-top:4px;">Average Rating</div>
          </div>
          <div style="flex:1;min-width:160px;background:#f0fdf4;border-radius:12px;padding:16px;text-align:center;border:1px solid #bbf7d0;">
            <div style="font-size:28px;font-weight:700;color:#16a34a;" id="fbPositive">0</div>
            <div style="font-size:12px;color:#6b7280;margin-top:4px;">Positive (4-5★)</div>
          </div>
          <div style="flex:1;min-width:160px;background:#fef2f2;border-radius:12px;padding:16px;text-align:center;border:1px solid #fecaca;">
            <div style="font-size:28px;font-weight:700;color:#dc2626;" id="fbNegative">0</div>
            <div style="font-size:12px;color:#6b7280;margin-top:4px;">Negative (1-2★)</div>
          </div>
        </div>
        <div class="section-card">
          <div>
            <select id="fbFilter" onchange="renderFeedback()" style="border:1px solid #d1d5db;border-radius:8px;padding:8px 12px;font-size:13px;margin-bottom:12px;">
              <option value="all">All Ratings</option>
              <option value="5">5 ★</option>
              <option value="4">4 ★</option>
              <option value="3">3 ★</option>
              <option value="2">2 ★</option>
              <option value="1">1 ★</option>
            </select>
          </div>
          <div class="section-body" style="overflow-x:auto;">
            <table class="data-table" id="feedbackTable">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Order</th>
                  <th>Customer</th>
                  <th>Rating</th>
                  <th>Review</th>
                </tr>
              </thead>
              <tbody id="feedbackTbody">
                <tr><td colspan="5" style="text-align:center;padding:30px;color:#999;">Loading...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Settings Page -->
    <div id="settingsPage" class="page">
      <div class="page-header">
        <h1>Settings</h1>
        <p>Manage your restaurant settings and preferences</p>
      </div>
      <div class="page-content">
        <div class="settings-container">
          <!-- Restaurant Information Card -->
          <div class="profile-card-modern">
            <div class="profile-card-header">
              <h3>
                <span class="material-symbols-rounded">restaurant</span>
                Restaurant Information
              </h3>
              <p class="card-description">Update your restaurant details and contact information</p>
            </div>
            <div class="profile-card-body">
              <form id="restaurantSettingsForm">
                <div class="form-group">
                  <label for="restaurantNameSetting">
                    <span class="material-symbols-rounded">store</span>
                    Restaurant Name
                  </label>
                  <input type="text" id="restaurantNameSetting" placeholder="Enter restaurant name" required>
                </div>

                <div class="form-group">
                  <label for="ownerName">
                    <span class="material-symbols-rounded">person</span>
                    Owner Name
                  </label>
                  <input type="text" id="ownerName" placeholder="Enter owner name (shown on policy pages)">
                </div>
                
                
                <div class="form-group">
                  <label for="restaurantIdSetting">
                    <span class="material-symbols-rounded">badge</span>
                    Restaurant ID
                  </label>
                  <input type="text" id="restaurantIdSetting" placeholder="Restaurant ID" readonly style="background: #f3f4f6; cursor: not-allowed;">
                </div>
                
                <div class="form-group">
                  <label for="restaurantAddress">
                    <span class="material-symbols-rounded">location_on</span>
                    Address
                  </label>
                  <input type="text" id="restaurantAddress" autocomplete="off" placeholder="Search your restaurant's address...">
                  <button type="button" id="restaurantMapPickerBtn" style="margin-top:8px;width:100%;display:flex;align-items:center;justify-content:center;gap:6px;padding:11px 14px;border:2px solid #dc2626;border-radius:10px;background:#fff;color:#dc2626;font-size:13px;font-weight:600;cursor:pointer;">📍 Select Exact Location on Map</button>
                  <div id="restaurantMapPreview" style="display:none;margin-top:10px;width:100%;height:180px;border-radius:10px;overflow:hidden;border:2px solid #e0e0e0;"></div>
                  <input type="hidden" id="restaurantAddressLat" value="">
                  <input type="hidden" id="restaurantAddressLng" value="">
                  <p style="color:#6b7280;font-size:0.8rem;margin-top:6px;">Search your address, or use the map to drop a pin at your exact location — this improves delivery-radius accuracy.</p>
                </div>

                <div class="form-group">
                  <label for="restaurantDescription">
                    <span class="material-symbols-rounded">description</span>
                    Tagline / Description
                  </label>
                  <div style="display:flex;gap:8px;align-items:flex-start;margin-bottom:6px;">
                    <textarea id="restaurantDescription" rows="2" placeholder="e.g. Serving handcrafted pizzas, delicious pastas..." style="resize:vertical;flex:1;"></textarea>
                    <button type="button" id="descFormatToggleSettings" class="desc-format-btn" title="Toggle description format">
                      <span class="material-symbols-rounded" style="font-size:16px;vertical-align:middle;">format_align_left</span>
                      <span id="descFormatLabelSettings">Paragraph</span>
                    </button>
                  </div>
                  <input type="hidden" id="descriptionFormatSettings" name="description_format" value="paragraph">
                  <p style="color:#6b7280;font-size:0.85rem;margin-top:0.25rem;">Choose how the tagline appears: <strong>Paragraph</strong> (single block) or <strong>Line Break</strong> (preserves new lines).</p>
                </div>

                <div class="form-group">
                  <label for="restaurantGoogleMapsLink">
                    <span class="material-symbols-rounded">map</span>
                    Google Maps Link (Optional)
                  </label>
                  <input type="url" id="restaurantGoogleMapsLink" placeholder="e.g. https://share.google/..., https://maps.app.goo.gl/..., or https://maps.google.com/?q=...">
                  <p style="color:#6b7280;font-size:0.85rem;margin-top:0.25rem;">Paste your Google Maps location link here — a full link, a short share link (share.google, maps.app.goo.gl, goo.gl/maps), or an embed URL all work. If set, the "Open in Google Maps" button on the customer website will use this link. Leave empty to hide the map section if no address is set.</p>
                </div>

                <!-- Order Type Toggles -->
                <div class="form-group" style="padding-top: 24px;">
                  <label style="font-weight: 700; font-size: 1.1rem; color: #151A2D; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                    <span class="material-symbols-rounded" style="color: #2563eb;">switch_access_shortcut</span>
                    Order Type Settings
                  </label>
                  <p style="color: #6b7280; font-size: 0.85rem; margin-bottom: 0.75rem;">Toggle which order types are available on your customer website. Unchecked types will be hidden.</p>

                  <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 10px 16px; background: #f9fafb; border: 2px solid #e5e7eb; border-radius: 10px; transition: all 0.2s;" onmouseover="this.style.borderColor='#d1d5db'" onmouseout="this.style.borderColor='#e5e7eb'">
                      <input type="checkbox" id="enableDeliveryToggle" <?php echo $enable_delivery ? 'checked' : ''; ?> style="width:18px;height:18px;accent-color:#dc2626;cursor:pointer;">
                      <span style="font-weight: 600; font-size: 0.95rem;">🚚 Delivery</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 10px 16px; background: #f9fafb; border: 2px solid #e5e7eb; border-radius: 10px; transition: all 0.2s;" onmouseover="this.style.borderColor='#d1d5db'" onmouseout="this.style.borderColor='#e5e7eb'">
                      <input type="checkbox" id="enableTakeawayToggle" <?php echo $enable_takeaway ? 'checked' : ''; ?> style="width:18px;height:18px;accent-color:#dc2626;cursor:pointer;">
                      <span style="font-weight: 600; font-size: 0.95rem;">🥡 Take Away</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 10px 16px; background: #f9fafb; border: 2px solid #e5e7eb; border-radius: 10px; transition: all 0.2s;" onmouseover="this.style.borderColor='#d1d5db'" onmouseout="this.style.borderColor='#e5e7eb'">
                      <input type="checkbox" id="enableDineinToggle" <?php echo $enable_dinein ? 'checked' : ''; ?> style="width:18px;height:18px;accent-color:#dc2626;cursor:pointer;">
                      <span style="font-weight: 600; font-size: 0.95rem;">🍽️ Dine In</span>
                    </label>
                  </div>
                  <p style="color: #6b7280; font-size: 0.8rem; margin-top: 0.5rem;">All are enabled by default. At least one must remain enabled for customers to place orders.</p>
                </div>

                <div class="form-group">
                  <label style="display:flex;align-items:center;gap:8px;">
                    <span class="material-symbols-rounded">payments</span>
                    Cash on Delivery / Pay at Counter
                    <label class="switch" style="display:inline-flex;align-items:center;gap:6px;margin-left:auto;cursor:pointer;">
                      <input type="checkbox" id="enableCodToggle" <?php echo $cod_enabled ? "checked" : ""; ?> style="width:18px;height:18px;accent-color:#dc2626;cursor:pointer;">
                      <span style="font-size:13px;font-weight:500;color:#374151;" id="enableCodLabel"><?php echo $cod_enabled ? 'Enabled' : 'Disabled'; ?></span>
                    </label>
                  </label>
                  <p style="font-size:12px;color:#6b7280;margin-top:4px;">Turn off to remove Cash as a payment option on the customer website — only online payment methods (UPI/QR) will be offered at checkout. If no online payment method is configured, Cash stays available automatically so customers can still order.</p>
                </div>

                <!-- Social Media Links Section -->
                <div class="form-group" style="padding-top: 24px;">
                  <label style="font-weight: 700; font-size: 1.1rem; color: #151A2D; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                    <span class="material-symbols-rounded" style="color: #2563eb;">share</span>
                    Social Media Links (Optional)
                  </label>
                  <p style="color: #6b7280; font-size: 0.85rem; margin-bottom: 0.75rem;">Add your social media profile links. They will appear on your restaurant website. Leave empty to hide.</p>

                  <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                      <label for="instagramLink" style="font-size: 0.85rem; font-weight: 600; color: #374151; display: flex; align-items: center; gap: 4px;">
                        <span style="color: #E4405F;">📷</span> Instagram
                      </label>
                      <input type="url" id="instagramLink" placeholder="https://instagram.com/yourpage" style="padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.85rem; width: 100%; box-sizing: border-box;">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                      <label for="facebookLink" style="font-size: 0.85rem; font-weight: 600; color: #374151; display: flex; align-items: center; gap: 4px;">
                        <span style="color: #1877F2;">👍</span> Facebook
                      </label>
                      <input type="url" id="facebookLink" placeholder="https://facebook.com/yourpage" style="padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.85rem; width: 100%; box-sizing: border-box;">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                      <label for="twitterLink" style="font-size: 0.85rem; font-weight: 600; color: #374151; display: flex; align-items: center; gap: 4px;">
                        <span style="color: #000;">𝕏</span> Twitter / X
                      </label>
                      <input type="url" id="twitterLink" placeholder="https://x.com/yourpage" style="padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.85rem; width: 100%; box-sizing: border-box;">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                      <label for="youtubeLink" style="font-size: 0.85rem; font-weight: 600; color: #374151; display: flex; align-items: center; gap: 4px;">
                        <span style="color: #FF0000;">▶️</span> YouTube
                      </label>
                      <input type="url" id="youtubeLink" placeholder="https://youtube.com/@yourchannel" style="padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.85rem; width: 100%; box-sizing: border-box;">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                      <label for="linkedinLink" style="font-size: 0.85rem; font-weight: 600; color: #374151; display: flex; align-items: center; gap: 4px;">
                        <span style="color: #0A66C2;">💼</span> LinkedIn
                      </label>
                      <input type="url" id="linkedinLink" placeholder="https://linkedin.com/company/yourpage" style="padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.85rem; width: 100%; box-sizing: border-box;">
                    </div>
                  </div>
                  <p style="color: #6b7280; font-size: 0.8rem; margin-top: 0.5rem;">WhatsApp is already configured separately via your phone number.</p>
                </div>

                <div class="form-group opening-hours-group">
                  <label style="font-weight: 700; font-size: 1.1rem; color: #151A2D; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                    <span class="material-symbols-rounded">schedule</span>
                    Opening Hours
                    <button type="button" class="btn-normal-hours" onclick="fillNormalHours()" title="Set all days to 9:00 AM - 10:00 PM">
                      <span class="material-symbols-rounded" style="font-size: 1rem;">auto_schedule</span>
                      Set Normal Hours
                    </button>
                  </label>
                  <?php
                  $days = ['monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday', 'thursday' => 'Thursday', 'friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday'];
                  $hours = ['01','02','03','04','05','06','07','08','09','10','11','12'];
                  $minutes = ['00','05','10','15','20','25','30','35','40','45','50','55'];
                  foreach ($days as $key => $label):
                  ?>
                  <div class="day-hours-row" data-day="<?= $key ?>">
                    <label class="day-toggle-label">
                      <input type="checkbox" class="day-toggle" id="day_<?= $key ?>_open" data-day="<?= $key ?>">
                      <span><?= $label ?></span>
                    </label>
                    <div class="day-time-picker">
                      <select class="hour-select" id="opening_hour_<?= $key ?>" data-day="<?= $key ?>" data-type="opening_hour">
                        <?php foreach ($hours as $h): ?><option value="<?= $h ?>"><?= $h ?></option><?php endforeach; ?>
                      </select>
                      <span class="time-sep">:</span>
                      <select class="min-select" id="opening_min_<?= $key ?>" data-day="<?= $key ?>" data-type="opening_min">
                        <?php foreach ($minutes as $m): ?><option value="<?= $m ?>"><?= $m ?></option><?php endforeach; ?>
                      </select>
                      <select class="ampm-select" id="opening_ampm_<?= $key ?>" data-day="<?= $key ?>" data-type="opening_ampm">
                        <option value="AM">AM</option>
                        <option value="PM">PM</option>
                      </select>
                      <span class="to-sep">to</span>
                      <select class="hour-select" id="closing_hour_<?= $key ?>" data-day="<?= $key ?>" data-type="closing_hour">
                        <?php foreach ($hours as $h): ?><option value="<?= $h ?>"><?= $h ?></option><?php endforeach; ?>
                      </select>
                      <span class="time-sep">:</span>
                      <select class="min-select" id="closing_min_<?= $key ?>" data-day="<?= $key ?>" data-type="closing_min">
                        <?php foreach ($minutes as $m): ?><option value="<?= $m ?>"><?= $m ?></option><?php endforeach; ?>
                      </select>
                      <select class="ampm-select" id="closing_ampm_<?= $key ?>" data-day="<?= $key ?>" data-type="closing_ampm">
                        <option value="AM">AM</option>
                        <option value="PM">PM</option>
                      </select>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>

                <div class="form-group">
                  <label style="display:flex;align-items:center;gap:8px;">
                    <span class="material-symbols-rounded">language</span>
                    Language Support
                    <label class="switch" style="display:inline-flex;align-items:center;gap:6px;margin-left:auto;cursor:pointer;">
                      <input type="checkbox" id="enableLanguageToggle" <?php echo $enable_language ? "checked" : ""; ?> style="width:18px;height:18px;accent-color:#dc2626;cursor:pointer;">
                      <span style="font-size:13px;font-weight:500;color:#374151;" id="enableLanguageLabel"><?php echo $enable_language ? 'Enabled' : 'Disabled'; ?></span>
                    </label>
                  </label>
                  <p style="font-size:12px;color:#6b7280;margin-top:4px;">Turn on to allow language selection in system settings and auto-translate menu items.</p>
                </div>

                <div class="form-group">
                  <label style="display:flex;align-items:center;gap:8px;">
                    <span class="material-symbols-rounded">receipt_long</span>
                    Enable Tax
                    <label class="switch" style="display:inline-flex;align-items:center;gap:6px;margin-left:auto;cursor:pointer;">
                      <input type="checkbox" id="enableGstToggle" <?php echo $enable_gst ? "checked" : ""; ?> style="width:18px;height:18px;accent-color:#dc2626;cursor:pointer;">
                      <span style="font-size:13px;font-weight:500;color:#374151;" id="enableGstLabel">Enabled</span>
                    </label>
                  </label>
                  <p style="font-size:12px;color:#6b7280;margin-top:4px;">Turn off to remove tax calculation on orders and the customer website.</p>
                  <div class="row" style="display:flex;gap:12px;margin-top:10px;">
                    <div class="form-group" style="flex:1;margin-bottom:0;">
                      <label for="taxName" style="font-size:12px;color:#6b7280;">Tax Name</label>
                      <input type="text" id="taxName" class="form-control" maxlength="50" placeholder="e.g. GST, VAT, Sales Tax" value="<?php echo htmlspecialchars($tax_name); ?>">
                    </div>
                    <div class="form-group" style="flex:1;margin-bottom:0;">
                      <label for="taxPercentInput" style="font-size:12px;color:#6b7280;">Tax Percent (%)</label>
                      <input type="number" id="taxPercentInput" class="form-control" step="0.01" min="0" max="100" placeholder="e.g. 5" value="<?php echo htmlspecialchars(rtrim(rtrim(number_format((float)$tax_percent, 2), '0'), '.')); ?>">
                    </div>
                  </div>
                </div>

                <div class="form-group" >
                  <label for="minimumOrderValue">
                    <span class="material-symbols-rounded">shopping_cart</span>
                    Minimum Order Value
                  </label>
                  <input type="number" id="minimumOrderValue" min="0" step="0.01" placeholder="e.g. 350">
                </div>
            </div>
            
            <!-- Packaging Charge -->
            <div class="form-group" >
              <label for="packagingCharge">
                <span class="material-symbols-rounded">inventory_2</span>
                Packaging Charge (<?php echo htmlspecialchars($currency_symbol); ?>)
              </label>
              <p style="color:#666;font-size:0.85rem;margin-bottom:6px;">
                A flat packaging fee applied to all delivery orders. Set to 0 to disable.
              </p>
              <input type="number" id="packagingCharge" class="form-control" step="0.50" min="0" placeholder="e.g. 10.00" style="max-width:200px">
            </div>

            <!-- Delivery Radius -->
            <div class="form-group" >
              <label for="deliveryRadius">
                <span class="material-symbols-rounded">my_location</span>
                Delivery Radius (km)
              </label>
              <p style="color:#666;font-size:0.85rem;margin-bottom:6px;">
                Maximum distance in km for delivery. Set to 0 to disable radius check. Uses your restaurant's map location above to calculate distance.
              </p>
              <input type="number" id="deliveryRadius" class="form-control" step="0.5" min="0" placeholder="e.g. 10" style="max-width:200px">
            </div>

                <div class="form-row" >
                  <div class="form-group">
                    <label for="restaurantPhone">
                      <span class="material-symbols-rounded">phone</span>
                      Phone Number
                    </label>
                    <input type="tel" id="restaurantPhone" placeholder="Enter phone number">
                  </div>
                  <div class="form-group">
                    <label for="restaurantEmail">
                      <span class="material-symbols-rounded">email</span>
                      Email Address
                    </label>
                    <input type="email" id="restaurantEmail" placeholder="Enter email address">
                  </div>
                </div>

                <div class="form-actions">
                  <button type="submit" class="btn btn-save">
                    <span class="material-symbols-rounded">save</span>
                    Save Changes
                  </button>
                </div>
              </form>
            </div>
          </div>
          
          <!-- Profile Settings Card -->
          <div class="profile-card-modern">
            <div class="profile-card-header">
              <h3>
                <span class="material-symbols-rounded">account_circle</span>
                Profile Settings
              </h3>
              <p class="card-description">Manage your account username and email</p>
            </div>
            <div class="profile-card-body">
              <form id="profileSettingsForm">
                <div class="form-group">
                  <label for="usernameSetting">
                    <span class="material-symbols-rounded">badge</span>
                    Username
                  </label>
                  <input type="text" id="usernameSetting" placeholder="Enter username" required>
                </div>
                
                <div class="form-group">
                  <label for="profileEmailSetting">
                    <span class="material-symbols-rounded">email</span>
                    Email Address
                  </label>
                  <input type="email" id="profileEmailSetting" placeholder="Enter email address" required>
                </div>
                
                <div class="form-group">
                  <label class="checkbox-label">
                    <input type="checkbox" id="emailNotifications">
                    <span class="checkmark"></span>
                    <span>Enable Email Notifications</span>
                  </label>
                </div>
                
                <div class="form-group install-app-group" style="border-top:1px solid #e5e7eb;padding-top:1rem;margin-top:0.5rem;">
                  <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;font-weight:500;">
                    <span class="material-symbols-rounded" style="color:#e17055;">download</span>
                    Install App (PWA)
                  </label>
                  <p style="font-size:0.85rem;color:#6b7280;margin:0.25rem 0 0.75rem 0;">Install this admin panel as an app on your device for quick access.</p>
                  <button type="button" class="btn btn-save" onclick="promptInstall()" style="background:#e17055;border-color:#e17055;">
                    <span class="material-symbols-rounded">install_mobile</span>
                    Install App
                  </button>
                </div>

                <div class="form-actions">
                  <button type="submit" class="btn btn-save">
                    <span class="material-symbols-rounded">save</span>
                    Save Changes
                  </button>
                </div>
              </form>
            </div>
          </div>
          
          <!-- System Settings Card -->
          <div class="profile-card-modern">
            <div class="profile-card-header">
              <h3>
                <span class="material-symbols-rounded">tune</span>
                System Settings
              </h3>
              <p class="card-description">Configure system preferences and defaults</p>
            </div>
            <div class="profile-card-body">
              <form id="systemSettingsForm">
                <div class="form-group">
                  <label for="countrySelect">
                    <span class="material-symbols-rounded">public</span>
                    Country
                  </label>
                  <p style="color:#666;font-size:0.85rem;margin-bottom:0.75rem;">Sets the default currency below. Change the currency separately if needed.</p>
                  <select id="countrySelect">
                    <?php foreach (getCountryData() as $iso2 => $c): ?>
                    <option value="<?php echo htmlspecialchars($c['name']); ?>" data-currency="<?php echo htmlspecialchars($c['currency_symbol']); ?>" <?php echo $country === $c['name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="form-group">
                  <label for="currencySymbolSelect">
                    <span class="material-symbols-rounded">currency_exchange</span>
                    Currency Symbol
                  </label>
                  <select id="currencySymbolSelect">
                    <?php
                    $majorCurrencies = [
                      '₹' => '₹ Indian Rupee (INR)',
                      '$' => '$ US Dollar (USD)',
                      '€' => '€ Euro (EUR)',
                      '£' => '£ British Pound (GBP)',
                      '¥' => '¥ Japanese Yen (JPY)',
                      'Rs.' => 'Rs. Nepali Rupee (NPR)',
                      'A$' => 'A$ Australian Dollar (AUD)',
                      'C$' => 'C$ Canadian Dollar (CAD)',
                      'CHF' => 'CHF Swiss Franc',
                      'CN¥' => 'CN¥ Chinese Yuan (CNY)',
                      'HK$' => 'HK$ Hong Kong Dollar (HKD)',
                      'NZ$' => 'NZ$ New Zealand Dollar (NZD)',
                      'S$' => 'S$ Singapore Dollar (SGD)',
                      '₽' => '₽ Russian Ruble (RUB)',
                      '₩' => '₩ South Korean Won (KRW)',
                      'R' => 'R South African Rand (ZAR)',
                      '₦' => '₦ Nigerian Naira (NGN)',
                      '₨' => '₨ Pakistani Rupee (PKR)',
                      '৳' => '৳ Bangladeshi Taka (BDT)',
                      'Rs' => 'Rs Sri Lankan Rupee (LKR)',
                      'Custom' => 'Custom...'
                    ];
                    $isCustom = true;
                    foreach ($majorCurrencies as $symbol => $label) {
                      if ($symbol === 'Custom') continue;
                      if ($currency_symbol === $symbol) {
                        $isCustom = false;
                        echo '<option value="' . htmlspecialchars($symbol) . '" selected>' . htmlspecialchars($label) . '</option>';
                      } else {
                        echo '<option value="' . htmlspecialchars($symbol) . '">' . htmlspecialchars($label) . '</option>';
                      }
                    }
                    echo '<option value="Custom"' . ($isCustom ? ' selected' : '') . '>Custom...</option>';
                    ?>
                  </select>
                  <input type="text" id="currencySymbol" value="<?php echo $isCustom ? htmlspecialchars($currency_symbol) : ''; ?>" maxlength="10" placeholder="Enter custom currency symbol" class="currency-custom-input" style="<?php echo $isCustom ? '' : 'display: none;'; ?>">
                </div>
                
                <div class="form-group">
                  <label for="businessQRUpload">
                    <span class="material-symbols-rounded">qr_code</span>
                    Business Payment QR Code
                  </label>
                  <p style="color:#666;font-size:0.85rem;margin-bottom:0.75rem;">Upload your business payment QR code (UPI, Paytm, etc.) to display on the website. Max size: 5MB</p>
                  <div id="businessQRPreview" style="margin-bottom:0.75rem;display:none;">
                    <img id="businessQRPreviewImg" src="" alt="QR Code Preview" style="max-width:200px;max-height:200px;border-radius:8px;border:2px solid #e5e7eb;padding:0.5rem;background:#f9fafb;">
                    <button type="button" onclick="removeBusinessQR()" style="margin-top:0.5rem;padding:0.5rem 1rem;background:#fee2e2;color:#b91c1c;border:none;border-radius:6px;cursor:pointer;font-weight:600;font-size:0.85rem;">
                      <span class="material-symbols-rounded" style="font-size:1rem;vertical-align:middle;">delete</span>
                      Remove QR Code
                    </button>
                  </div>
                  <input type="file" id="businessQRUpload" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" style="margin-bottom:0.5rem;">
                  <button type="button" class="btn btn-primary" id="uploadBusinessQRBtn" style="margin-top:0.5rem;">
                    <span class="material-symbols-rounded">upload</span>
                    Upload QR Code
                  </button>
                </div>
                
                <div class="form-group">
                  <label for="timezone">
                    <span class="material-symbols-rounded">schedule</span>
                    Timezone
                  </label>
                  <select id="timezone">
                    <?php
                    $timezoneOptions = [
                      'UTC' => 'UTC',
                      'Asia/Kolkata' => 'Asia/Kolkata (India, IST)',
                      'Asia/Kathmandu' => 'Asia/Kathmandu (Nepal, NPT)',
                      'Asia/Dhaka' => 'Asia/Dhaka (Bangladesh)',
                      'Asia/Colombo' => 'Asia/Colombo (Sri Lanka)',
                      'Asia/Karachi' => 'Asia/Karachi (Pakistan)',
                      'Asia/Dubai' => 'Asia/Dubai (UAE)',
                      'Asia/Riyadh' => 'Asia/Riyadh (Saudi Arabia)',
                      'Asia/Qatar' => 'Asia/Qatar',
                      'Asia/Kuwait' => 'Asia/Kuwait',
                      'Asia/Bahrain' => 'Asia/Bahrain',
                      'Asia/Muscat' => 'Asia/Muscat (Oman)',
                      'Asia/Yangon' => 'Asia/Yangon (Myanmar)',
                      'Asia/Bangkok' => 'Asia/Bangkok (Thailand)',
                      'Asia/Jakarta' => 'Asia/Jakarta (Indonesia)',
                      'Asia/Singapore' => 'Asia/Singapore',
                      'Asia/Kuala_Lumpur' => 'Asia/Kuala_Lumpur (Malaysia)',
                      'Asia/Manila' => 'Asia/Manila (Philippines)',
                      'Asia/Ho_Chi_Minh' => 'Asia/Ho_Chi_Minh (Vietnam)',
                      'Asia/Hong_Kong' => 'Asia/Hong_Kong',
                      'Asia/Shanghai' => 'Asia/Shanghai (China)',
                      'Asia/Taipei' => 'Asia/Taipei (Taiwan)',
                      'Asia/Tokyo' => 'Asia/Tokyo (Japan)',
                      'Asia/Seoul' => 'Asia/Seoul (South Korea)',
                      'Asia/Jerusalem' => 'Asia/Jerusalem (Israel)',
                      'Asia/Istanbul' => 'Asia/Istanbul (Turkey)',
                      'Europe/London' => 'Europe/London (UK, GMT)',
                      'Europe/Dublin' => 'Europe/Dublin (Ireland)',
                      'Europe/Paris' => 'Europe/Paris (France)',
                      'Europe/Berlin' => 'Europe/Berlin (Germany)',
                      'Europe/Madrid' => 'Europe/Madrid (Spain)',
                      'Europe/Rome' => 'Europe/Rome (Italy)',
                      'Europe/Amsterdam' => 'Europe/Amsterdam (Netherlands)',
                      'Europe/Lisbon' => 'Europe/Lisbon (Portugal)',
                      'Europe/Moscow' => 'Europe/Moscow (Russia)',
                      'Africa/Cairo' => 'Africa/Cairo (Egypt)',
                      'Africa/Lagos' => 'Africa/Lagos (Nigeria)',
                      'Africa/Nairobi' => 'Africa/Nairobi (Kenya)',
                      'Africa/Johannesburg' => 'Africa/Johannesburg (South Africa)',
                      'Africa/Casablanca' => 'Africa/Casablanca (Morocco)',
                      'America/New_York' => 'America/New_York (US Eastern)',
                      'America/Chicago' => 'America/Chicago (US Central)',
                      'America/Denver' => 'America/Denver (US Mountain)',
                      'America/Los_Angeles' => 'America/Los_Angeles (US Pacific)',
                      'America/Toronto' => 'America/Toronto (Canada)',
                      'America/Mexico_City' => 'America/Mexico_City (Mexico)',
                      'America/Bogota' => 'America/Bogota (Colombia)',
                      'America/Sao_Paulo' => 'America/Sao_Paulo (Brazil)',
                      'America/Argentina/Buenos_Aires' => 'America/Buenos_Aires (Argentina)',
                      'America/Santiago' => 'America/Santiago (Chile)',
                      'America/Lima' => 'America/Lima (Peru)',
                      'Australia/Sydney' => 'Australia/Sydney',
                      'Australia/Perth' => 'Australia/Perth',
                      'Pacific/Auckland' => 'Pacific/Auckland (New Zealand)',
                      'Pacific/Fiji' => 'Pacific/Fiji',
                    ];
                    // If the restaurant's saved timezone isn't in the curated list above
                    // (still a valid IANA zone), include it so the dropdown doesn't silently
                    // fall back to something else on save.
                    if (!empty($timezone) && !isset($timezoneOptions[$timezone])) {
                      $timezoneOptions[$timezone] = $timezone;
                    }
                    foreach ($timezoneOptions as $tzValue => $tzLabel):
                    ?>
                    <option value="<?php echo htmlspecialchars($tzValue); ?>" <?php echo $timezone === $tzValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($tzLabel); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <?php if ($enable_language): ?>
                <div class="form-group">
                  <label for="language">
                    <span class="material-symbols-rounded">language</span>
                    Language
                  </label>
                  <select id="language">
                    <?php
                    $langs = [
                      'en' => 'English',
                      'hi' => 'हिन्दी (Hindi)',
                      'bn' => 'বাংলা (Bengali)',
                      'te' => 'తెలుగు (Telugu)',
                      'mr' => 'मराठी (Marathi)',
                      'ta' => 'தமிழ் (Tamil)',
                      'ur' => 'اردو (Urdu)',
                      'gu' => 'ગુજરાતી (Gujarati)',
                      'kn' => 'ಕನ್ನಡ (Kannada)',
                      'ml' => 'മലയാളം (Malayalam)',
                      'pa' => 'ਪੰਜਾਬੀ (Punjabi)',
                      'es' => 'Español (Spanish)',
                      'fr' => 'Français (French)',
                      'de' => 'Deutsch (German)',
                      'zh' => '中文 (Chinese)',
                      'ja' => '日本語 (Japanese)',
                      'ar' => 'العربية (Arabic)',
                      'pt' => 'Português (Portuguese)',
                      'ru' => 'Русский (Russian)',
                    ];
                    foreach ($langs as $code => $name) {
                      $selected = $language === $code ? 'selected' : '';
                      echo '<option value="' . htmlspecialchars($code) . '" ' . $selected . '>' . htmlspecialchars($name) . '</option>';
                    }
                    ?>
                  </select>
                  <p style="color:#666;font-size:0.85rem;margin-top:0.5rem;">When changed to a non-English language, menu items will be auto-translated.</p>
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                  <label class="checkbox-label">
                    <input type="checkbox" id="autoSync">
                    <span class="checkmark"></span>
                    <span>Enable Auto Sync</span>
                  </label>
                </div>

                <div class="form-group">
                  <label class="checkbox-label">
                    <input type="checkbox" id="notifications">
                    <span class="checkmark"></span>
                    <span>Enable Push Notifications</span>
                  </label>
                </div>
                
                <div class="form-actions">
                  <button type="submit" class="btn btn-save">
                    <span class="material-symbols-rounded">save</span>
                    Save Changes
                  </button>
                </div>
              </form>
            </div>
          </div>

          <!-- Printer Settings Card -->
          <div class="profile-card-modern">
            <div class="profile-card-header">
              <h3>
                <span class="material-symbols-rounded">print</span>
                Printer Settings
              </h3>
              <p class="card-description">Configure your thermal receipt printer for KOTs, bills and invoices</p>
            </div>
            <div class="profile-card-body">
              <form id="printerSettingsForm" onsubmit="return false;">
                <div class="form-group">
                  <label for="printerWidthSelect">
                    <span class="material-symbols-rounded">receipt_long</span>
                    Paper Width
                  </label>
                  <select id="printerWidthSelect">
                    <option value="58">58mm (small thermal printer)</option>
                    <option value="80">80mm (standard thermal printer)</option>
                  </select>
                  <p style="color:#666;font-size:0.85rem;margin-top:0.5rem;">Check the paper roll width printed on the box, or measure the roll - this must match your printer exactly or receipts will print cut off or with wasted margin.</p>
                </div>

                <div class="form-group">
                  <label for="printerModeSelect">
                    <span class="material-symbols-rounded">settings_ethernet</span>
                    Connection Mode
                  </label>
                  <select id="printerModeSelect">
                    <option value="browser">Browser / System Printer (recommended)</option>
                    <option value="network">Network Printer (ESC/POS, direct - no print dialog)</option>
                  </select>
                  <p style="color:#666;font-size:0.85rem;margin-top:0.5rem;">
                    <strong>Browser/System:</strong> works with any thermal printer that has a Windows/Android driver installed (USB, Bluetooth or network). Opens a print preview and uses your OS print dialog.<br>
                    <strong>Network Printer:</strong> sends raw print commands straight to a LAN thermal printer's IP address - no driver needed, no dialog, prints instantly like a real POS terminal. Requires a network (Ethernet/Wi-Fi) thermal printer.
                  </p>
                </div>

                <div id="printerNetworkFields" style="display:none;">
                  <div class="form-group">
                    <label for="printerNetworkIp">
                      <span class="material-symbols-rounded">lan</span>
                      Printer IP Address
                    </label>
                    <input type="text" id="printerNetworkIp" placeholder="e.g. 192.168.1.50">
                  </div>
                  <div class="form-group">
                    <label for="printerNetworkPort">
                      <span class="material-symbols-rounded">settings_input_component</span>
                      Port
                    </label>
                    <input type="number" id="printerNetworkPort" value="9100" min="1" max="65535">
                    <p style="color:#666;font-size:0.85rem;margin-top:0.5rem;">9100 is the standard raw ESC/POS port used by almost all network thermal printers.</p>
                  </div>
                </div>

                <div class="form-group" style="border-top:1px solid #e5e7eb;padding-top:1rem;margin-top:0.5rem;">
                  <label style="display:flex;align-items:center;gap:0.5rem;font-weight:500;">
                    <span class="material-symbols-rounded" style="color:#0066cc;">science</span>
                    Don't have a printer yet?
                  </label>
                  <p style="font-size:0.85rem;color:#6b7280;margin:0.25rem 0 0.75rem 0;">Run the bundled virtual printer to test the full print flow (including Network mode) with no hardware: open a terminal in <code>main/tools</code> and run <code>php virtual_printer_server.php</code>, then set Connection Mode to Network Printer with address <code>127.0.0.1</code> and port <code>9100</code>. Every print job will show up in that terminal exactly as a real printer would receive it.</p>
                </div>

                <div class="form-actions">
                  <button type="button" class="btn btn-save" onclick="savePrinterSettingsForm()">
                    <span class="material-symbols-rounded">save</span>
                    Save Printer Settings
                  </button>
                  <button type="button" class="btn btn-secondary" onclick="testPrintReceipt()" style="margin-left:0.5rem;">
                    <span class="material-symbols-rounded">print</span>
                    Test Print
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Profile Page -->
    <div id="profilePage" class="page">
      <div class="page-header">
        <h1>My Profile</h1>
        <p>Manage your account information and preferences</p>
      </div>
      <div class="page-content">
        <div class="profile-container">
          <!-- Profile Header Section -->
          <div class="profile-header-card">
            <div class="profile-avatar-section">
              <div class="profile-avatar-large" id="profileAvatarContainer">
                <img id="profileRestaurantLogo" src="" alt="Restaurant Logo" style="display: none; width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                <span id="profileInitials">JD</span>
              </div>
              <button class="btn-edit-avatar" onclick="openLogoUploadModal()" title="Change Restaurant Logo">
                <span class="material-symbols-rounded">photo_camera</span>
              </button>
            </div>
            <div class="profile-info-section">
              <div class="profile-title-row">
                <h2 id="profileName">Loading...</h2>
                <span class="profile-status-pill" id="profileSubscriptionStatusBadge">Loading...</span>
              </div>
              <p id="profileRole" class="profile-role-badge">Administrator</p>
              <p class="profile-email" id="profileEmail">Loading...</p>
              <div class="profile-details-list">
                <div class="profile-detail-item">
                  <span class="material-symbols-rounded">restaurant</span>
                  <span>Restaurant ID: <strong id="profileRestaurantName">Loading...</strong></span>
                </div>
                <div class="profile-detail-item">
                  <span class="material-symbols-rounded">call</span>
                  <span>Phone: <strong id="profilePhoneValueInline">Not added</strong></span>
                </div>
                <div class="profile-detail-item">
                  <span class="material-symbols-rounded">schedule</span>
                  <span>Timezone: <strong id="profileTimezoneTextInline">--</strong></span>
                </div>
                <div class="profile-detail-item">
                  <span class="material-symbols-rounded">calendar_today</span>
                  <span>Member Since: <strong id="profileMemberSinceDate">Loading...</strong></span>
                </div>
              </div>
            </div>
            <div class="profile-actions-section">
              <button class="btn btn-primary" id="editProfileBtn" onclick="showPage('settingsPage')">
                <span class="material-symbols-rounded">edit</span>
                Edit Profile
              </button>
              <button class="btn btn-secondary" type="button" onclick="showPage('settingsPage')">
                <span class="material-symbols-rounded">workspace_premium</span>
                Manage Subscription
              </button>
            </div>
          </div>

          <div class="profile-highlight-grid">
            <div class="profile-highlight-card">
              <div class="highlight-icon success">
                <span class="material-symbols-rounded">verified</span>
              </div>
              <div>
                <p class="highlight-label">Subscription</p>
                <h3 id="profileSubscriptionStatusText">Loading...</h3>
                <p class="highlight-subtext">Renews on <strong id="profileRenewalDateText">--</strong></p>
              </div>
            </div>
            <div class="profile-highlight-card">
              <div class="highlight-icon warning">
                <span class="material-symbols-rounded">calendar_month</span>
              </div>
              <div>
                <p class="highlight-label">Trial Ends</p>
                <h3 id="profileTrialEndText">--</h3>
                <p class="highlight-subtext">Timezone <strong id="profileTimezoneText">--</strong></p>
              </div>
            </div>
            <div class="profile-highlight-card">
              <div class="highlight-icon info">
                <span class="material-symbols-rounded">event_available</span>
              </div>
              <div>
                <p class="highlight-label">Member Since</p>
                <h3 id="profileMemberSinceHighlight">--</h3>
                <p class="highlight-subtext">Restaurant ID <strong id="profileRestaurantIdHighlight">--</strong></p>
              </div>
            </div>
          </div>

          <!-- Profile Content Grid -->
          <div class="profile-content-grid">
            <!-- Contact Card -->
            <div class="profile-card-modern profile-contact-card">
              <div class="profile-card-header">
                <h3>
                  <span class="material-symbols-rounded">contact_page</span>
                  Contact Details
                </h3>
                <p class="card-description">Keep your contact information up to date</p>
              </div>
              <div class="profile-card-body">
                <div class="profile-contact-row">
                  <div class="contact-icon accent">
                    <span class="material-symbols-rounded">call</span>
                  </div>
                  <div>
                    <p class="contact-label">Phone Number</p>
                    <strong id="profilePhoneValue">Not added</strong>
                  </div>
                </div>
                <div class="profile-contact-row">
                  <div class="contact-icon info">
                    <span class="material-symbols-rounded">email</span>
                  </div>
                  <div>
                    <p class="contact-label">Email Address</p>
                    <strong id="profileEmailValue">Not added</strong>
                  </div>
                </div>
                <div class="profile-contact-row">
                  <div class="contact-icon muted">
                    <span class="material-symbols-rounded">location_on</span>
                  </div>
                  <div>
                    <p class="contact-label">Address</p>
                    <strong id="profileAddressValue">Add your restaurant address</strong>
                  </div>
                </div>
              </div>
            </div>
            <!-- Edit Profile Form (Hidden by default) -->
            <div class="profile-card-modern" id="editProfileCard" style="display: none;">
              <div class="profile-card-header">
                <h3>
                  <span class="material-symbols-rounded">edit</span>
                  Edit Profile Information
                </h3>
              </div>
              <div class="profile-card-body">
                <form id="editProfileForm">
                  <div class="form-group">
                    <label for="editUsername">
                      <span class="material-symbols-rounded">badge</span>
                      Username
                    </label>
                    <input type="text" id="editUsername" placeholder="Enter username" required>
                  </div>
                  <div class="form-group">
                    <label for="editEmail">
                      <span class="material-symbols-rounded">email</span>
                      Email Address
                    </label>
                    <input type="email" id="editEmail" placeholder="Enter email address" required>
                  </div>
                  <div class="form-actions">
                    <button type="button" class="btn btn-cancel" onclick="cancelProfileEdit()">
                      <span class="material-symbols-rounded">close</span>
                      Cancel
                    </button>
                    <button type="submit" class="btn btn-save">
                      <span class="material-symbols-rounded">save</span>
                      Save Changes
                    </button>
                  </div>
                </form>
              </div>
            </div>

            <!-- Change Password Card -->
            <div class="profile-card-modern">
              <div class="profile-card-header">
                <h3>
                  <span class="material-symbols-rounded">lock</span>
                  Change Password
                </h3>
              </div>
              <div class="profile-card-body">
                <form id="changePasswordForm">
                  <div class="form-group">
                    <label for="currentPassword">
                      <span class="material-symbols-rounded">lock</span>
                      Current Password
                    </label>
                    <input type="password" id="currentPassword" placeholder="Enter current password" required>
                    <small class="form-error" id="currentPasswordError" style="display: none; color: #ef4444; margin-top: 0.5rem; font-size: 0.875rem;"></small>
                  </div>
                  <div class="form-group">
                    <label for="newPassword">
                      <span class="material-symbols-rounded">lock_reset</span>
                      New Password
                    </label>
                    <input type="password" id="newPassword" placeholder="Enter new password" required minlength="6">
                    <div class="password-criteria" id="passwordCriteria">
                      <small class="form-hint" style="display: block; margin-top: 0.5rem; color: #6b7280; font-size: 0.875rem;">Password must meet the following criteria:</small>
                      <ul class="criteria-list" style="margin: 0.5rem 0 0 1.25rem; padding: 0; list-style: none;">
                        <li class="criteria-item" data-criteria="length">
                          <span class="material-symbols-rounded criteria-icon" style="font-size: 1rem; vertical-align: middle; margin-right: 0.25rem;">close</span>
                          <span>At least 6 characters long</span>
                        </li>
                      </ul>
                    </div>
                    <small class="form-error" id="newPasswordError" style="display: none; color: #ef4444; margin-top: 0.5rem; font-size: 0.875rem;"></small>
                  </div>
                  <div class="form-group">
                    <label for="confirmPassword">
                      <span class="material-symbols-rounded">verified</span>
                      Confirm New Password
                    </label>
                    <input type="password" id="confirmPassword" placeholder="Confirm new password" required minlength="6">
                    <small class="form-hint" id="passwordMatchStatus" style="display: none; margin-top: 0.5rem; font-size: 0.875rem;"></small>
                    <small class="form-error" id="confirmPasswordError" style="display: none; color: #ef4444; margin-top: 0.5rem; font-size: 0.875rem;"></small>
                  </div>
                  <div class="form-actions">
                    <button type="submit" class="btn btn-save" id="changePasswordBtn">
                      <span class="material-symbols-rounded">lock</span>
                      Change Password
                    </button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Area Management Page -->
    <div id="areaPage" class="page">
      <div class="page-header">
        <h1>Area Management</h1>
        <p>Create, edit, and manage your restaurant areas</p>
      </div>
      <div class="page-content">
        <div class="menu-actions">
          <button class="btn btn-primary" id="addAreaBtn">
            <span class="material-symbols-rounded">add</span>
            Add New Area
          </button>
        </div>
        
        <div class="menu-list" id="areaList">
          <!-- Areas will be loaded here dynamically -->
          <div class="loading">Loading areas...</div>
        </div>
      </div>
    </div>

    <!-- Tables Management Page -->
    <div id="tablesPage" class="page">
      <div class="page-header">
        <h1>Tables Management</h1>
        <p>Create, edit, and manage your restaurant tables</p>
      </div>
      <div class="page-content">
        <div class="menu-actions">
          <button class="btn btn-primary" id="addTableBtn">
            <span class="material-symbols-rounded">add</span>
            Add New Table
          </button>
        </div>
        
        <div class="menu-list" id="tableList">
          <!-- Tables will be loaded here dynamically -->
          <div class="loading">Loading tables...</div>
        </div>
      </div>
    </div>

    <!-- QR Codes Page -->
    <div id="qrCodesPage" class="page">
      <div class="page-header">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
          <div>
            <h1 style="margin: 0; font-size: 1.5rem;">QR Codes for Tables</h1>
            <p style="margin: 0.25rem 0 0 0; color: #6b7280;">Generate and download QR codes for customers to access your menu</p>
          </div>
          <button class="btn btn-primary" onclick="generateAllQRCodes()" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem; font-size: 0.9rem; white-space: nowrap;">
            <span class="material-symbols-rounded" style="font-size: 1.2rem;">download</span>
            Download All
          </button>
        </div>
      </div>
      <div class="page-content">
        <div id="qrCodesGrid" class="qr-codes-grid">
          <div class="loading">Loading QR codes...</div>
        </div>
      </div>    </div>

    <?php if ($photo_gallery_enabled): ?>
    <!-- Photo Gallery Page -->
    <div id="galleryPage" class="page">
      <div class="page-header">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
          <div>
            <h1 id="galleryPageTitle" style="margin: 0; font-size: 1.5rem;">Photo Gallery</h1>
            <p id="galleryPageSubtitle" style="margin: 0.25rem 0 0 0; color: #6b7280;">Browse high-quality stock photos, organized by category</p>
          </div>
          <button class="btn btn-secondary" id="galleryBackBtn" onclick="showGalleryCategories()" style="display:none; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem; font-size: 0.9rem; white-space: nowrap;">
            <span class="material-symbols-rounded" style="font-size: 1.2rem;">arrow_back</span>
            Back to Categories
          </button>
        </div>
      </div>
      <div class="page-content">
        <div id="galleryCategoryGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px;">
          <div class="loading">Loading photo gallery...</div>
        </div>
        <div id="galleryPhotoGrid" style="display:none; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px;"></div>
      </div>
    </div>

    <!-- Photo Gallery Lightbox -->
    <div id="galleryLightboxModal" class="modal">
      <div class="modal-content" style="max-width:700px;">
        <div class="modal-header">
          <h2 id="galleryLightboxTitle" style="margin:0;font-size:1rem;font-weight:600;"></h2>
          <button class="modal-close" onclick="closeModal('galleryLightboxModal')">&times;</button>
        </div>
        <div class="modal-body" style="text-align:center;">
          <img id="galleryLightboxImg" src="" alt="" style="max-width:100%;max-height:70vh;border-radius:8px;object-fit:contain;">
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Table Map Page -->
    <div id="tableMapPage" class="page">
      <div class="page-header">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
          <div>
            <h1 style="display:flex;align-items:center;gap:8px;">
              <span class="material-symbols-rounded" style="font-size:28px;color:#3b82f6;">map</span>
              Table Map
            </h1>
            <p>Visual floor plan of all tables with live status.</p>
          </div>
          <div style="display:flex;gap:12px;align-items:center;">
            <div style="display:flex;gap:10px;font-size:13px;">
              <span style="display:flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:50%;background:#22c55e;display:inline-block;"></span> Free</span>
              <span style="display:flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:50%;background:#ef4444;display:inline-block;"></span> Occupied</span>
              <span style="display:flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:50%;background:#eab308;display:inline-block;"></span> Reserved</span>
            </div>
            <button class="btn btn-secondary" onclick="renderTableMap()" style="padding:8px 16px;border-radius:8px;border:1px solid #d1d5db;background:#fff;cursor:pointer;font-weight:600;display:flex;align-items:center;gap:6px;">
              <span class="material-symbols-rounded" style="font-size:16px;">refresh</span> Refresh
            </button>
          </div>
        </div>
      </div>
      <div class="page-content">
        <div id="tableMapContainer" style="background:#0f172a;border-radius:16px;border:1px solid #334155;padding:24px;min-height:500px;">
          <div class="loading" style="color:#94a3b8;text-align:center;padding:60px 20px;">
            <div style="font-size:40px;margin-bottom:16px;">🗺️</div>
            <div style="font-size:18px;font-weight:600;margin-bottom:8px;">Loading Table Map...</div>
            <div style="font-size:13px;color:#64748b;">Fetching table data and live status</div>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Reservations Management Page -->
    <div id="reservationsPage" class="page">
      <div class="page-header">
        <h1>Reservations</h1>
        <p>View and manage customer reservations</p>
      </div>
      <div class="page-content">
        <div class="page-toolbar reservation-toolbar" style="display: flex; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap; align-items: center; background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-left: 0; margin-right: 0;">
          <div class="toolbar-left" style="display: flex; gap: 1rem; flex: 1; flex-wrap: wrap; align-items: center; min-width: 0;">
            <div class="search-wrapper" style="flex: 1; min-width: 250px; max-width: 100%; position: relative; border: 2px solid #e5e7eb; border-radius: 10px; padding: 0 0.75rem 0 2.75rem; background: white;">
              <span class="material-symbols-rounded" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 1.2rem; pointer-events: none; z-index: 1;">search</span>
              <input type="text" id="reservationSearch" placeholder="Search by name, phone, email..." style="width: 100%; padding: 0.875rem 0; border: none; border-radius: 0; font-size: 0.95rem; transition: all 0.2s; outline: none; background: transparent;" onfocus="this.parentElement.style.borderColor='#f70000'; this.parentElement.style.boxShadow='0 0 0 3px rgba(247,0,0,0.1)'" onblur="this.parentElement.style.borderColor='#e5e7eb'; this.parentElement.style.boxShadow='none';">
            </div>
            <div class="date-range-wrapper" style="display: flex; gap: 0.75rem; align-items: center; background: #f9fafb; padding: 0.5rem; border-radius: 10px; border: 1px solid #e5e7eb; flex-wrap: wrap; flex: 1; min-width: 0;">
              <div style="position: relative; flex: 1; min-width: 140px;">
                <input type="date" id="reservationDateFrom" style="width: 100%; padding: 0.875rem 2.5rem 0.875rem 1rem; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.95rem; background: white; cursor: pointer; transition: all 0.2s; outline: none; box-sizing: border-box;" onfocus="this.style.borderColor='#f70000'; this.style.boxShadow='0 0 0 3px rgba(247,0,0,0.1)'" onblur="this.style.borderColor='#e5e7eb'; this.style.boxShadow='none'; this.style.outline='none';" onchange="this.blur();">
                <span class="material-symbols-rounded" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #374151; font-size: 1.2rem; cursor: pointer; z-index: 1; pointer-events: auto; transition: color 0.2s;" onclick="const input = document.getElementById('reservationDateFrom'); input.showPicker(); setTimeout(() => input.blur(), 100);" onmouseover="this.style.color='#111827'" onmouseout="this.style.color='#374151'">calendar_today</span>
              </div>
              <span style="color: #6b7280; font-weight: 600; font-size: 0.9rem; padding: 0 0.25rem; white-space: nowrap;">to</span>
              <div style="position: relative; flex: 1; min-width: 140px;">
                <input type="date" id="reservationDateTo" style="width: 100%; padding: 0.875rem 2.5rem 0.875rem 1rem; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.95rem; background: white; cursor: pointer; transition: all 0.2s; outline: none; box-sizing: border-box;" onfocus="this.style.borderColor='#f70000'; this.style.boxShadow='0 0 0 3px rgba(247,0,0,0.1)'" onblur="this.style.borderColor='#e5e7eb'; this.style.boxShadow='none'; this.style.outline='none';" onchange="this.blur();">
                <span class="material-symbols-rounded" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #374151; font-size: 1.2rem; cursor: pointer; z-index: 1; pointer-events: auto; transition: color 0.2s;" onclick="const input = document.getElementById('reservationDateTo'); input.showPicker(); setTimeout(() => input.blur(), 100);" onmouseover="this.style.color='#111827'" onmouseout="this.style.color='#374151'">calendar_today</span>
              </div>
              <button onclick="clearDateRange()" style="padding: 0.875rem; background: #ffffff; border: 2px solid #d1d5db; border-radius: 8px; cursor: pointer; color: #374151; display: flex; align-items: center; justify-content: center; transition: all 0.2s; min-width: 42px; height: 42px; outline: none; box-shadow: 0 1px 2px rgba(0,0,0,0.05); flex-shrink: 0;" onmouseover="this.style.background='#f3f4f6'; this.style.borderColor='#9ca3af'; this.style.color='#111827'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'" onmouseout="this.style.background='#ffffff'; this.style.borderColor='#d1d5db'; this.style.color='#374151'; this.style.boxShadow='0 1px 2px rgba(0,0,0,0.05)'" onfocus="this.style.outline='none';" onblur="this.style.outline='none';" title="Clear date range">
                <span class="material-symbols-rounded" style="font-size: 1.3rem; font-weight: 500;">close</span>
              </button>
            </div>
          </div>
          <div class="toolbar-right" style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; min-width: 0; width: 100%;">
            <select id="reservationStatusFilter" class="filter-select" style="flex: 1; min-width: 160px; max-width: 100%; padding: 0.875rem 2.5rem 0.875rem 1rem; border: 2px solid #e5e7eb; border-radius: 10px; font-size: 0.95rem; background: white; cursor: pointer; appearance: none; background-image: url('data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'24\' height=\'24\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%236b7280\' stroke-width=\'2\'><polyline points=\'6 9 12 15 18 9\'></polyline></svg>'); background-repeat: no-repeat; background-position: right 0.75rem center; transition: all 0.2s; outline: none; box-sizing: border-box;" onfocus="this.style.borderColor='#f70000'; this.style.boxShadow='0 0 0 3px rgba(247,0,0,0.1)'" onblur="this.style.borderColor='#e5e7eb'; this.style.boxShadow='none'; this.style.outline='none';" onchange="this.blur();">
              <option value="">All Status</option>
              <option value="Pending">Pending</option>
              <option value="Confirmed">Confirmed</option>
              <option value="Checked In">Checked In</option>
              <option value="Completed">Completed</option>
              <option value="Cancelled">Cancelled</option>
              <option value="No Show">No Show</option>
            </select>
            <button class="btn btn-primary" id="addReservationBtn" style="display: flex; align-items: center; gap: 0.5rem; padding: 0.875rem 1rem; background: #f70000 !important; color: #ffffff !important; border: 2px solid #f70000 !important; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 1rem; transition: all 0.2s; box-shadow: 0 2px 4px rgba(247,0,0,0.3); letter-spacing: 0.3px; white-space: nowrap; flex-shrink: 0; width: auto; justify-content: center;" onmouseover="this.style.background='#d60000'; this.style.borderColor='#d60000'; this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 8px rgba(247,0,0,0.4)';" onmouseout="this.style.background='#f70000'; this.style.borderColor='#f70000'; this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(247,0,0,0.3)';">
              <span class="material-symbols-rounded" style="font-size: 1.3rem; font-weight: 600; color: #ffffff !important;">add_circle</span>
              <span style="color: #ffffff !important; font-weight: 700;">Add Reservation</span>
            </button>
          </div>
        </div>
        
        <div class="menu-list" id="reservationList" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 350px), 1fr)); gap: 1.5rem; max-width: 100%;">
          <!-- Reservations will be loaded here dynamically -->
          <div class="loading">Loading reservations...</div>
        </div>
      </div>
    </div>

    <!-- POS Page -->
    <div id="posPage" class="page">
      <div class="page-header">
        <h1>Point of Sale</h1>
        <p>Process orders and manage transactions</p>
      </div>
      <div class="page-content pos-content">
        <div class="pos-container">
          <!-- Left Side - Menu Items -->
          <div class="pos-menu-section">
            <div class="pos-filters">
              <select id="posMenuFilter" class="filter-select">
                <option value="">All Menus</option>
              </select>
              <select id="posCategoryFilter" class="filter-select">
                <option value="">All Categories</option>
              </select>
              <select id="posTypeFilter" class="filter-select">
                <option value="">All Types</option>
                <option value="Veg">Veg</option>
                <option value="Non Veg">Non Veg</option>
                <option value="Egg">Egg</option>
                <option value="Drink">Drink</option>
                <option value="Dessert">Dessert</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div class="pos-menu-items" id="posMenuItems">
              <!-- Menu items will be loaded here -->
              <div class="loading">Loading menu items...</div>
            </div>
          </div>
          
          <!-- Mobile Sticky Add Item Button -->
          <button id="mobileAddItemBtn" class="mobile-add-item-btn" onclick="openMobileAddItemModal()" style="display: none;">
            <span class="material-symbols-rounded">add</span>
            <span>Add Item</span>
          </button>

          <!-- Right Side - Cart -->
          <div class="pos-cart-section">
            <div class="pos-cart-header">
              <h3>Order Cart</h3>
              <button class="btn-clear-cart" id="clearCartBtn">
                <span class="material-symbols-rounded">delete</span>
                Clear Cart
              </button>
            </div>
            
            <div class="pos-table-select">
              <label for="selectPosTable">Select Table:</label>
              <select id="selectPosTable" class="filter-select">
                <option value="">Walk-in</option>
              </select>
            </div>

            <div class="pos-cart-items" id="posCartItems">
              <div class="empty-cart">
                <span class="material-symbols-rounded">shopping_cart</span>
                <p>Cart is empty</p>
                <p class="empty-subtext">Add items from the menu</p>
              </div>
            </div>

            <div class="pos-cart-summary">
              <div class="cart-summary-row">
                <span>Subtotal:</span>
                <span id="cartSubtotal"><?php echo htmlspecialchars($currency_symbol); ?>0.00</span>
              </div>
              <?php if ($enable_gst): ?>
              <div class="cart-summary-row">
                <span><?php echo htmlspecialchars($tax_name); ?> (<?php echo rtrim(rtrim(number_format((float)$tax_percent, 2), '0'), '.'); ?>%):</span>
                <span id="cartTax"><?php echo htmlspecialchars($currency_symbol); ?>0.00</span>
              </div>
              <?php endif; ?>
              <div class="cart-summary-row total">
                <span>Total:</span>
                <span id="cartTotal"><?php echo htmlspecialchars($currency_symbol); ?>0.00</span>
              </div>
            </div>

            <div class="pos-cart-actions pos-cart-actions-3">
              <button class="btn btn-primary" id="processPaymentBtn">
                <span class="material-symbols-rounded">payment</span>
                Pay
              </button>
              <button class="btn btn-secondary" id="holdOrderBtn">
                <span class="material-symbols-rounded">pause</span>
                Hold
              </button>
              <button class="btn btn-kot" id="sendKotBtn">
                <span class="material-symbols-rounded">soup_kitchen</span>
                KOT
              </button>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Mobile Bill Summary (Above Buttons) -->
      <div id="mobilePosBillSummary" class="mobile-pos-bill-summary" style="display: none;">
        <div class="mobile-bill-summary-card">
          <div class="mobile-bill-summary-header" onclick="toggleMobileBillDetails()">
            <div>
              <div style="font-size:0.75rem;color:#6b7280;margin-bottom:0.125rem;">Bill Summary</div>
              <div style="font-size:1.1rem;font-weight:700;color:#111827;">
                Total: <span id="mobilePosBillTotal"><?php echo htmlspecialchars($currency_symbol); ?>0.00</span>
              </div>
            </div>
            <span class="material-symbols-rounded" id="mobilePosBillSummaryArrow" style="font-size:1.25rem;color:#6b7280;transition:transform 0.3s;">chevron_right</span>
          </div>
          <div id="mobilePosBillDetails" style="display:none;margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid #e5e7eb;">
            <div style="display:flex;justify-content:space-between;margin-bottom:0.5rem;font-size:0.85rem;">
              <span style="color:#6b7280;">Subtotal:</span>
              <span style="font-weight:600;color:#111827;" id="mobilePosBillSubtotal"><?php echo htmlspecialchars($currency_symbol); ?>0.00</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:0.85rem;">
              <span style="color:#6b7280;"><?php echo htmlspecialchars($tax_name); ?> (<?php echo rtrim(rtrim(number_format((float)$tax_percent, 2), '0'), '.'); ?>%):</span>
              <span style="font-weight:600;color:#111827;" id="mobilePosBillTax"><?php echo htmlspecialchars($currency_symbol); ?>0.00</span>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Mobile Sticky Bottom Buttons -->
      <div id="mobilePosBottomActions" class="mobile-pos-bottom-actions mobile-pos-bottom-actions-3" style="display: none;">
        <button class="mobile-pos-btn mobile-pos-btn-bill" id="mobileProcessPaymentBtn">
          <span class="material-symbols-rounded">payment</span>
          <span>Pay</span>
        </button>
        <button class="mobile-pos-btn mobile-pos-btn-hold" id="mobileHoldOrderBtn">
          <span class="material-symbols-rounded">pause</span>
          <span>Hold</span>
        </button>
        <button class="mobile-pos-btn mobile-pos-btn-kot" id="mobileSendKotBtn">
          <span class="material-symbols-rounded">soup_kitchen</span>
          <span>KOT</span>
        </button>
      </div>
    </div>

    <!-- KOT Page -->
    <div id="kotPage" class="page">
      <div class="page-header">
        <div>
          <h1>Kitchen Order Ticket (KOT)</h1>
          <p>View and manage kitchen orders</p>
        </div>
        <div style="display: flex; align-items: center; gap: 12px;">
          <span id="kotLastRefresh" style="color: #6b7280; font-size: 0.875rem;">Auto-refreshing every 5 seconds...</span>
          <button class="btn" onclick="window.kotOrdersSoundEnabled = !window.kotOrdersSoundEnabled; this.style.background = window.kotOrdersSoundEnabled ? '#10b981' : '#6b7280'" style="background: #10b981; color: white; border: none; display: flex; align-items: center; gap: 4px;" title="Toggle KOT sound">
            <span class="material-symbols-rounded" style="font-size: 1.1rem;">notifications</span>
          </button>
          <button class="btn btn-primary" onclick="loadKOTOrders()">
            <span class="material-symbols-rounded" style="vertical-align: middle;">refresh</span> Refresh
          </button>
        </div>
      </div>
      <div class="page-content">
        <div class="kot-filters">
          <select id="kotStatusFilter" class="filter-select" onchange="loadKOTOrders()">
            <option value="">All Status</option>
            <option value="Pending">Pending</option>
            <option value="Preparing">Preparing</option>
            <option value="Ready">Ready</option>
          </select>
          <select id="kotTableFilter" class="filter-select" onchange="loadKOTOrders()">
            <option value="">All Tables</option>
          </select>
        </div>
        <div class="kot-list" id="kotList">
          <!-- KOT orders will be loaded here -->
          <div class="loading">Loading KOT orders...</div>
        </div>
      </div>
    </div>

    <!-- Online Orders Page -->
    <div id="onlineOrdersPage" class="page">
      <div class="page-header">
        <div>
          <h1>Online Orders</h1>
          <p>Orders placed from the customer website</p>
        </div>
        <div style="display: flex; align-items: center; gap: 12px;">
          <span id="onlineOrdersLastRefresh" style="color: #6b7280; font-size: 0.875rem;">Auto-refreshing every 15 seconds...</span>
          <button class="btn" onclick="window.onlineOrdersSoundEnabled = !window.onlineOrdersSoundEnabled; this.style.background = window.onlineOrdersSoundEnabled ? '#10b981' : '#6b7280'" style="background: #10b981; color: white; border: none; display: flex; align-items: center; gap: 4px;" title="Toggle new-order sound">
            <span class="material-symbols-rounded" style="font-size: 1.1rem;">notifications</span>
          </button>
          <button class="btn btn-primary" onclick="loadOnlineOrders()">
            <span class="material-symbols-rounded" style="vertical-align: middle;">refresh</span> Refresh
          </button>
        </div>
      </div>
      <div class="page-content">
        <div class="orders-filters" style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
          <div style="position: relative; flex: 1; min-width: 250px;">
            <span class="material-symbols-rounded" style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: #666; font-size: 1.2rem; pointer-events: none;">search</span>
            <input type="text" id="onlineOrdersSearch" placeholder="Search by order number or customer name..." style="width: 100%; padding: 0.75rem 0.75rem 0.75rem 2.5rem; border: 1px solid #ddd; border-radius: 8px; font-size: 0.9rem;">
          </div>
            <select id="onlineOrdersStatusFilter" class="filter-select" onchange="loadOnlineOrders()">
            <option value="">All Status</option>
            <option value="Pending">Pending</option>
            <option value="Accepted">Accepted</option>
            <option value="Rejected">Rejected</option>
            <option value="Preparing">Preparing</option>
            <option value="Ready">Ready</option>
            <option value="Served">Served</option>
            <option value="Completed">Completed</option>
            <option value="Cancelled">Cancelled</option>
          </select>
        </div>
        <div class="orders-list" id="onlineOrdersList">
          <div class="loading">Loading online orders...</div>
        </div>
      </div>
    </div>

    <!-- Orders Page -->
    <div id="ordersPage" class="page">
      <div class="page-header">
        <h1>Orders Management</h1>
        <p>View and manage all orders</p>
      </div>
      <div class="page-content">
        <div class="orders-filters" style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
          <div style="position: relative; flex: 1; min-width: 250px;">
            <span class="material-symbols-rounded" style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: #666; font-size: 1.2rem; pointer-events: none;">search</span>
            <input type="text" id="ordersSearch" placeholder="Search by order number, customer name, or table..." style="width: 100%; padding: 0.75rem 0.75rem 0.75rem 2.5rem; border: 1px solid #ddd; border-radius: 8px; font-size: 0.9rem;">
          </div>
          <select id="ordersStatusFilter" class="filter-select">
            <option value="">All Status</option>
            <option value="Pending">Pending</option>
            <option value="Preparing">Preparing</option>
            <option value="Ready">Ready</option>
            <option value="Served">Served</option>
            <option value="Completed">Completed</option>
            <option value="Cancelled">Cancelled</option>
          </select>
          <select id="ordersPaymentFilter" class="filter-select">
            <option value="">All Payment Status</option>
            <option value="Pending">Pending</option>
            <option value="Paid">Paid</option>
            <option value="Partially Paid">Partially Paid</option>
            <option value="Refunded">Refunded</option>
          </select>
          <select id="ordersTypeFilter" class="filter-select">
            <option value="">All Order Types</option>
            <option value="Dine-in">Dine-in</option>
            <option value="Takeaway">Takeaway</option>
            <option value="Delivery">Delivery</option>
          </select>
          <input type="date" id="ordersDateFilter" class="filter-select" style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 8px; font-size: 0.9rem;">
          <button onclick="exportOrdersToCSV()" style="padding: 0.75rem 1.5rem; background: #28a745; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem;">
            <span class="material-symbols-rounded" style="font-size: 1rem;">download</span>
            Export CSV
          </button>
        </div>
        <div class="orders-list" id="ordersList">
          <!-- Orders will be loaded here -->
          <div class="loading">Loading orders...</div>
        </div>
      </div>
    </div>

    <!-- Customers Page -->
    <div id="customersPage" class="page">
      <div class="page-header">
        <h1>Customers</h1>
        <p>View and manage your customers</p>
      </div>
      <div class="page-content">
        <div class="page-toolbar">
          <div class="toolbar-left">
            <div class="search-wrapper">
              <span class="material-symbols-rounded">search</span>
              <input type="text" id="customerSearch" placeholder="Search customers...">
            </div>
          </div>
          <div class="toolbar-right">
            <button onclick="exportCustomersToCSV()" style="padding: 0.75rem 1.5rem; background: #28a745; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem;">
              <span class="material-symbols-rounded">download</span>
              Export CSV
            </button>
          </div>
            
            <select id="customerSortBy" class="filter-select">
              <option value="name">Sort by Name</option>
              <option value="visits">Most Visits</option>
              <option value="recent">Most Recent</option>
            </select>
          </div>
          
          <button class="btn btn-primary" id="addCustomerBtn">
            <span class="material-symbols-rounded">add</span>
            Add Customer
          </button>
        </div>
        
        <div class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>Avatar</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Email</th>
                <th>Address</th>
                <th>Total Visits</th>
                <th>Total Spent</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="customerList">
              <tr>
                <td colspan="8" class="loading">Loading customers...</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Staff Page -->
    <div id="staffPage" class="page">
      <div class="page-header">
        <h1>Staff Management</h1>
        <p>Manage your restaurant staff</p>
      </div>
      <div class="page-content">
        <div class="page-toolbar">
          <div class="toolbar-left">
            <div class="search-wrapper">
              <span class="material-symbols-rounded">search</span>
              <input type="text" id="staffSearch" placeholder="Search staff...">
            </div>
          </div>
          <div class="toolbar-right">
            <button onclick="exportStaffToCSV()" style="padding: 0.75rem 1.5rem; background: #28a745; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem;">
              <span class="material-symbols-rounded">download</span>
              Export CSV
            </button>
          </div>
            
            <select id="staffSortBy" class="filter-select">
              <option value="name">Sort by Name</option>
              <option value="role">Sort by Role</option>
            </select>
          </div>
          
          <button class="btn btn-primary" id="addStaffBtn">
            <span class="material-symbols-rounded">add</span>
            Add Staff
          </button>
        </div>
        
        <div class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>Avatar</th>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Role</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="staffList">
              <tr>
                <td colspan="6" class="loading">Loading staff...</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Waiter Requests Page -->
    <div id="waiterRequestsPage" class="page">
      <div class="page-header">
        <h1>Waiter Requests</h1>
        <p>Manage service requests from tables</p>
      </div>
      <div class="page-content">
        <div id="waiterRequestsList">
          <!-- Waiter requests will be loaded here dynamically grouped by area -->
          <div class="loading">Loading waiter requests...</div>
        </div>
      </div>
    </div>



  </main>

  <!-- Menu Modal (Add/Edit) -->
  <div id="menuModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 id="modalTitle">Add New Category</h2>
        <span class="close">&times;</span>
      </div>
      <div class="modal-body">
        <form id="menuForm" enctype="multipart/form-data">
          <input type="hidden" id="menuId" name="menuId" value="">
          <div class="form-group">
            <label for="menuName">Category Name:</label>
            <input type="text" id="menuName" name="menuName" required placeholder="Enter category name (e.g., Breakfast, Lunch, Dinner)">
          </div>
          <div class="form-group">
            <label for="menuImage">Category Image:</label>
            <div class="file-upload">
              <input type="file" id="menuImage" name="menuImage" accept="image/*">
              <label for="menuImage" class="file-upload-btn">
                <span class="material-symbols-rounded">upload</span>
                Choose File
              </label>
              <span class="file-name" id="menuImageFileName">No file chosen</span>
            </div>
            
            <!-- Image Cropper Section for Category -->
            <div id="menuImageCropperSection" style="display: none; margin-top: 1rem;">
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1rem;">
                <!-- Cropper Container -->
                <div>
                  <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #374151;">Crop Image:</label>
                  <div style="max-width: 100%; height: 300px; background: #f3f4f6; border-radius: 8px; overflow: hidden;">
                    <img id="menuImageToCrop" src="" alt="Image to crop" style="max-width: 100%; max-height: 100%; display: block;">
                  </div>
                  <div style="margin-top: 1rem; display: flex; gap: 0.5rem;">
                    <button type="button" id="cropMenuImageBtn" class="btn btn-primary" style="flex: 1;">
                      <span class="material-symbols-rounded" style="font-size: 1.2rem; vertical-align: middle;">crop</span>
                      Apply Crop
                    </button>
                    <button type="button" id="resetMenuCropBtn" class="btn btn-secondary" style="flex: 1;">
                      <span class="material-symbols-rounded" style="font-size: 1.2rem; vertical-align: middle;">refresh</span>
                      Reset
                    </button>
                  </div>
                </div>
                
                <!-- Category Preview -->
                <div>
                  <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #374151;">Preview:</label>
                  <div style="border: 2px solid #e5e7eb; border-radius: 12px; overflow: hidden; background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <div id="menuCategoryPreviewImage" style="width: 100%; height: 200px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden;">
                      <img id="croppedMenuPreviewImg" src="" alt="Preview" style="width: 100%; height: 100%; object-fit: cover; display: none;">
                      <span style="color: white; font-size: 3rem; opacity: 0.5;">📁</span>
                    </div>
                    <div style="padding: 1rem; background: #f9fafb;">
                      <div style="font-weight: 600; color: #111827; margin-bottom: 0.25rem;" id="previewCategoryName">Category Name</div>
                      <div style="font-size: 0.875rem; color: #6b7280;">Category preview</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- Old Preview (hidden, kept for backward compatibility) -->
            <div id="menuImagePreview" style="margin-top: 10px; display: none;">
              <img id="menuImagePreviewImg" src="" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px; border: 2px solid #e5e7eb;">
            </div>
            <input type="hidden" id="menuImageBase64" name="menuImageBase64" value="">
          </div>
          
          <!-- Subcategories Section -->
          <div class="form-group subcategories-section" id="subcategoriesSection" style="display: none; margin-top: 1rem; padding-top: 1rem; border-top: 2px solid #e5e7eb;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
              <label style="font-weight: 700; font-size: 1rem; color: #151A2D; display: flex; align-items: center; gap: 0.5rem;">
                <span class="material-symbols-rounded" style="font-size: 1.2rem;">folder</span>
                Subcategories
                <span style="font-size: 0.75rem; color: #6b7280; font-weight: 400; background: #f3f4f6; padding: 2px 8px; border-radius: 4px;">Optional</span>
              </label>
              <button type="button" class="btn btn-primary" id="addSubcategoryBtn" style="padding: 6px 14px; font-size: 0.85rem;">
                <span class="material-symbols-rounded" style="font-size: 1rem;">add</span>
                Add Subcategory
              </button>
            </div>
            <p style="font-size: 0.85rem; color: #6b7280; margin-bottom: 0.75rem;">Create subcategories to organize items within this category (e.g., "Hot Drinks", "Cold Drinks" under "Beverages").</p>
            <div id="subcategoriesList" style="display: flex; flex-direction: column; gap: 0.5rem;">
              <div id="noSubcategoriesMsg" style="text-align: center; padding: 1.5rem; color: #9ca3af; background: #f9fafb; border-radius: 8px; font-size: 0.9rem;">
                <span class="material-symbols-rounded" style="font-size: 1.5rem; display: block; margin-bottom: 0.25rem;">folder_off</span>
                No subcategories yet. Click "Add Subcategory" to create one.
              </div>
            </div>
          </div>
          
          <div class="form-actions">
            <button type="button" class="btn btn-cancel" id="cancelBtn">Cancel</button>
            <button type="submit" class="btn btn-save" id="saveBtn">Save Category</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  
  <!-- Subcategory Modal (Add/Edit) -->
  <div id="subcategoryModal" class="modal">
    <div class="modal-content" style="max-width: 450px;">
      <div class="modal-header">
        <h2 id="subcategoryModalTitle">Add Subcategory</h2>
        <span class="close" onclick="closeSubcategoryModal()">&times;</span>
      </div>
      <div class="modal-body">
        <form id="subcategoryForm" onsubmit="return saveSubcategory(event)">
          <input type="hidden" id="subcategoryId" name="subcategoryId" value="">
          <input type="hidden" id="subcategoryMenuId" name="subcategoryMenuId" value="">
          <div class="form-group">
            <label for="subcategoryName">Subcategory Name:</label>
            <input type="text" id="subcategoryName" name="subcategoryName" required placeholder="e.g., Hot Drinks, Cold Drinks, Starters" maxlength="100">
          </div>
          <div class="form-actions" style="margin-top: 1rem;">
            <button type="button" class="btn btn-cancel" onclick="closeSubcategoryModal()">Cancel</button>
            <button type="submit" class="btn btn-save" id="subcategorySaveBtn">Save Subcategory</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Menu Item Modal (Add/Edit) -->
  <div id="menuItemModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 id="menuItemModalTitle">Add New Menu Item</h2>
        <span class="close">&times;</span>
      </div>
      <div class="modal-body">
        <form id="menuItemForm" enctype="multipart/form-data">
          <input type="hidden" id="menuItemId" name="menuItemId" value="">
          <input type="hidden" id="itemImageBase64" name="itemImageBase64" value="">
          
          <div class="form-group">
            <label for="itemNameEn">Item Name (English):</label>
            <input type="text" id="itemNameEn" name="itemNameEn" required placeholder="e.g., Margherita Pizza">
          </div>
          
          <div class="form-group">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
              <label for="itemDescriptionEn" style="margin:0;">Item Description (English):</label>
              <button type="button" id="descFormatToggle" class="desc-format-btn" data-format="paragraph" title="Toggle between paragraph and line-break mode">
                <span class="material-symbols-rounded" style="font-size:16px;">format_align_left</span>
                <span id="descFormatLabel">Paragraph</span>
              </button>
            </div>
            <textarea id="itemDescriptionEn" name="itemDescriptionEn" rows="3" placeholder="e.g., A classic Italian pizza with fresh tomatoes and basil."></textarea>
            <input type="hidden" id="descriptionFormat" name="description_format" value="paragraph">
          </div>
          
          <div class="form-row">
            <div class="form-group">
              <label for="chooseMenu">Choose Menu:</label>
              <select id="chooseMenu" name="chooseMenu" required>
                <option value="">--</option>
              </select>
            </div>
            
            <div class="form-group" id="subcategoryFormGroup">
              <label for="subcategoryDropdown">Subcategory:</label>
              <select id="subcategoryDropdown" name="subcategoryId">
                <option value="">None</option>
              </select>
            </div>
          </div>
          
          <div class="form-group">
            <label>Item Type:</label>
            <div class="type-buttons">
              <button type="button" class="type-btn" data-type="Veg">
                <span class="material-symbols-rounded">eco</span>
                Veg
              </button>
              <button type="button" class="type-btn" data-type="Non Veg">
                <span class="material-symbols-rounded">restaurant</span>
                Non Veg
              </button>
              <button type="button" class="type-btn" data-type="Egg">
                <span class="material-symbols-rounded">egg</span>
                Egg
              </button>
              <button type="button" class="type-btn" data-type="Drink">
                <span class="material-symbols-rounded">local_bar</span>
                Drink
              </button>
              <button type="button" class="type-btn" data-type="Dessert">
                <span class="material-symbols-rounded">cake</span>
                Dessert
              </button>
              <button type="button" class="type-btn" data-type="Other">
                <span class="material-symbols-rounded">close</span>
                None
              </button>
            </div>
            <input type="hidden" id="itemType" name="itemType" value="Veg">
          </div>
          
          <div class="form-row">
            <div class="form-group">
              <label for="preparationTime">Preparation Time:</label>
              <input type="number" id="preparationTime" name="preparationTime" min="0" value="0" placeholder="0">
              <span class="input-suffix">Minutes</span>
            </div>
            
            <div class="form-group">
              <label for="calories">Calories:</label>
              <input type="number" id="calories" name="calories" min="0" value="0" placeholder="0">
              <span class="input-suffix">kcal</span>
            </div>
            
            <div class="form-group">
              <label for="isAvailable">Stock Status:</label>
              <select id="isAvailable" name="isAvailable">
                <option value="1">In Stock</option>
                <option value="0">Out of Stock</option>
              </select>
            </div>
          </div>
          
          <div class="form-group">
            <label for="itemImage">Item Image:</label>
            <div class="file-upload">
              <input type="file" id="itemImage" name="itemImage" accept="image/*">
              <label for="itemImage" class="file-upload-btn">
                <span class="material-symbols-rounded">upload</span>
                Choose File
              </label>
              <span class="file-name">No file chosen</span>
            </div>
            <div style="margin-top:8px;display:flex;align-items:center;gap:8px;">
              <span style="color:var(--muted);font-size:0.85rem;">Or paste image URL:</span>
              <input type="text" id="itemImageUrl" name="itemImageUrl" placeholder="https://example.com/image.jpg" style="flex:1;">
            </div>
            
            <!-- Image Cropper Section -->
            <div id="imageCropperSection" style="display: none; margin-top: 1rem;">
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1rem;">
                <!-- Cropper Container -->
                <div>
                  <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #374151;">Crop Image:</label>
                  <div style="max-width: 100%; height: 300px; background: #f3f4f6; border-radius: 8px; overflow: hidden;">
                    <img id="imageToCrop" src="" alt="Image to crop" style="max-width: 100%; max-height: 100%; display: block;">
                  </div>
                  <div style="margin-top: 1rem; display: flex; gap: 0.5rem;">
                    <button type="button" id="cropImageBtn" class="btn btn-primary" style="flex: 1;">
                      <span class="material-symbols-rounded" style="font-size: 1.2rem; vertical-align: middle;">crop</span>
                      Apply Crop
                    </button>
                    <button type="button" id="resetCropBtn" class="btn btn-secondary" style="flex: 1;">
                      <span class="material-symbols-rounded" style="font-size: 1.2rem; vertical-align: middle;">refresh</span>
                      Reset
                    </button>
                  </div>
                </div>
                
                <!-- Website Preview -->
                <div>
                  <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #374151;">Preview on Website:</label>
                  <div style="border: 2px solid #e5e7eb; border-radius: 12px; overflow: hidden; background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <div id="websitePreviewImage" style="width: 100%; height: 200px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden;">
                      <img id="croppedPreviewImg" src="" alt="Preview" style="width: 100%; height: 100%; object-fit: cover; display: none;">
                      <span style="color: white; font-size: 3rem; opacity: 0.5;">🍽️</span>
                    </div>
                    <div style="padding: 1rem; background: #f9fafb;">
                      <div style="font-weight: 600; color: #111827; margin-bottom: 0.25rem;" id="previewItemName">Item Name</div>
                      <div style="font-size: 0.875rem; color: #6b7280; margin-bottom: 0.5rem;">Item Description</div>
                      <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-weight: 700; color: #f70000; font-size: 1.125rem;"><?php echo htmlspecialchars($currency_symbol); ?>0.00</span>
                        <button style="background: #f70000; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; font-weight: 600; cursor: pointer;">
                          <span class="material-symbols-rounded" style="font-size: 1rem; vertical-align: middle;">add_shopping_cart</span>
                          Add
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- Old Preview (hidden, kept for backward compatibility) -->
            <div id="imagePreview" class="image-preview" style="display: none;">
              <img id="previewImg" src="" alt="Preview">
            </div>
          </div>
          
          <div class="form-row">
            <div class="form-group">
              <label for="basePrice">Price:</label>
              <div class="price-input">
                <span class="currency-symbol" id="currencySymbolDisplay"><?php echo htmlspecialchars($currency_symbol); ?></span>
                <input type="number" id="basePrice" name="basePrice" min="0" step="0.01" value="0.00" placeholder="0.00">
              </div>
            </div>
            
            <div class="form-group checkbox-group">
              <label class="checkbox-label">
                <input type="checkbox" id="hasVariations" name="hasVariations" onchange="toggleVariationsSection()">
                <span class="checkmark"></span>
                Has Variations (e.g., Small, Medium, Large)
              </label>
            </div>
          </div>
          
          <!-- Variations Section -->
          <div id="variationsSection" style="display: none; margin-top: 1.5rem; padding: 1.5rem; background: #f9fafb; border-radius: 8px; border: 2px solid #e5e7eb;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
              <h3 style="margin: 0; font-size: 1.1rem; color: #111827; display: flex; align-items: center; gap: 0.5rem;">
                <span class="material-symbols-rounded" style="font-size: 1.2rem;">tune</span>
                Item Variations
              </h3>
              <button type="button" onclick="addVariationRow()" style="padding: 0.5rem 1rem; background: #f70000; color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem;">
                <span class="material-symbols-rounded" style="font-size: 1rem;">add</span>
                Add Variation
              </button>
            </div>
            <p style="margin: 0 0 1rem 0; color: #6b7280; font-size: 0.875rem;">Add different sizes or options with their prices (e.g., Small: <?php echo htmlspecialchars($currency_symbol); ?>100, Medium: <?php echo htmlspecialchars($currency_symbol); ?>150, Large: <?php echo htmlspecialchars($currency_symbol); ?>200)</p>
            <div id="variationsList" style="display: flex; flex-direction: column; gap: 0.75rem;">
              <!-- Variations will be added here dynamically -->
            </div>
            <div id="noVariationsMessage" style="text-align: center; padding: 2rem; color: #9ca3af; font-size: 0.9rem;">
              No variations added yet. Click "Add Variation" to add size options.
            </div>
          </div>
          
          <div class="form-actions">
            <button type="button" class="btn btn-cancel" id="menuItemCancelBtn">Cancel</button>
            <button type="submit" class="btn btn-save" id="menuItemSaveBtn">Save Menu Item</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Delete Confirmation Modal -->
  <div id="deleteModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Confirm Delete</h2>
        <span class="close">&times;</span>
      </div>
      <div class="modal-body">
        <p id="deleteMessage">Are you sure you want to delete this item? This action cannot be undone.</p>
        <div class="form-actions">
          <button type="button" class="btn btn-cancel" id="deleteCancelBtn">Cancel</button>
          <button type="button" class="btn btn-delete" id="deleteConfirmBtn">Delete</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Area Modal (Add/Edit) -->
  <div id="areaModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 id="areaModalTitle">Add New Area</h2>
        <span class="close">&times;</span>
      </div>
      <div class="modal-body">
        <form id="areaForm">
          <input type="hidden" id="areaId" name="areaId" value="">
          <div class="form-group">
            <label for="areaName">Area Name:</label>
            <input type="text" id="areaName" name="areaName" required placeholder="Enter area name (e.g., Indoor, Outdoor, Smoking)">
          </div>
          <div class="form-actions">
            <button type="button" class="btn btn-cancel" id="areaCancelBtn">Cancel</button>
            <button type="submit" class="btn btn-save" id="areaSaveBtn">Save Area</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Table Modal (Add/Edit) -->
  <div id="tableModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 id="tableModalTitle">Add New Table</h2>
        <span class="close">&times;</span>
      </div>
      <div class="modal-body">
        <form id="tableForm">
          <input type="hidden" id="tableId" name="tableId" value="">
          <div class="form-group">
            <label for="tableNumber">Table Number:</label>
            <input type="text" id="tableNumber" name="tableNumber" required placeholder="Enter table number">
          </div>
          <div class="form-group">
            <label for="capacity">Capacity:</label>
            <input type="number" id="capacity" name="capacity" min="1" value="4" required placeholder="Number of seats">
          </div>
          <div class="form-group">
            <label for="chooseArea">Area:</label>
            <select id="chooseArea" name="chooseArea" required>
              <option value="">--</option>
            </select>
          </div>
          <div class="form-actions">
            <button type="button" class="btn btn-cancel" id="tableCancelBtn">Cancel</button>
            <button type="submit" class="btn btn-save" id="tableSaveBtn">Save Table</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Reservation Modal (Add/Edit) -->
  <div id="reservationModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 id="reservationModalTitle">New Reservation</h2>
        <span class="close">&times;</span>
      </div>
      <div class="modal-body">
        <div id="reservationFormErrors" style="display: none; background: #fee; border: 2px solid #fcc; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; color: #c33;">
          <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
            <span class="material-symbols-rounded" style="color: #c33;">error</span>
            <strong>Please fix the following errors:</strong>
          </div>
          <ul id="reservationErrorList" style="margin: 0; padding-left: 1.5rem; color: #c33;"></ul>
        </div>
        <form id="reservationForm">
          <input type="hidden" id="reservationId" name="reservationId" value="">
          <div class="form-row">
            <div class="form-group">
              <label for="reservationDate">Date: <span style="color: red;">*</span></label>
              <input type="date" id="reservationDate" name="reservationDate" required>
              <span class="field-error" id="reservationDateError" style="display: none; color: #c33; font-size: 0.875rem; margin-top: 0.25rem;"></span>
            </div>
            <div class="form-group">
              <label for="noOfGuests">Guests: <span style="color: red;">*</span></label>
              <input type="number" id="noOfGuests" name="noOfGuests" min="1" value="1" required placeholder="Number of guests">
              <span class="field-error" id="noOfGuestsError" style="display: none; color: #c33; font-size: 0.875rem; margin-top: 0.25rem;"></span>
            </div>
          </div>
          <div class="form-group">
            <label for="mealType">Meal Type: <span style="color: red;">*</span></label>
            <select id="mealType" name="mealType" required>
              <option value="Breakfast">Breakfast</option>
              <option value="Lunch" selected>Lunch</option>
              <option value="Dinner">Dinner</option>
              <option value="Snacks">Snacks</option>
            </select>
            <span class="field-error" id="mealTypeError" style="display: none; color: #c33; font-size: 0.875rem; margin-top: 0.25rem;"></span>
          </div>
          <div class="form-group">
            <label for="timeSlot">Select Time Slot: <span style="color: red;">*</span></label>
            <div id="timeSlots" class="time-slots">
              <!-- Time slots will be added dynamically -->
            </div>
            <div id="customTimeSlotContainer" style="display: none; margin-top: 1rem;">
              <label for="customTimeSlot" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #374151;">Enter Custom Time:</label>
              <input type="time" id="customTimeSlot" style="width: 100%; padding: 0.75rem; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 1rem; transition: all 0.2s;" onfocus="this.style.borderColor='#f70000'; this.style.boxShadow='0 0 0 3px rgba(247,0,0,0.1)'" onblur="this.style.borderColor='#e5e7eb'; this.style.boxShadow='none';">
              <span style="display: block; margin-top: 0.5rem; font-size: 0.875rem; color: #6b7280;">Or enter time in 12-hour format (e.g., 2:30 PM)</span>
            </div>
            <span class="field-error" id="timeSlotError" style="display: none; color: #c33; font-size: 0.875rem; margin-top: 0.25rem;"></span>
          </div>
          <div class="form-group">
            <label for="specialRequest">Any special request?</label>
            <textarea id="specialRequest" name="specialRequest" rows="3" placeholder="Enter any special requests..."></textarea>
            <span class="field-error" id="specialRequestError" style="display: none; color: #c33; font-size: 0.875rem; margin-top: 0.25rem;"></span>
          </div>
          <div class="form-group">
            <label for="customerName">Customer Name: <span style="color: red;">*</span></label>
            <input type="text" id="customerName" name="customerName" required placeholder="Enter customer name" autocomplete="off">
            <span class="field-error" id="customerNameError" style="display: none; color: #c33; font-size: 0.875rem; margin-top: 0.25rem;"></span>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label for="phone">Phone:</label>
              <input type="tel" id="phone" name="phone" placeholder="Enter phone number (optional)" autocomplete="off">
              <span class="field-error" id="phoneError" style="display: none; color: #c33; font-size: 0.875rem; margin-top: 0.25rem;"></span>
            </div>
            <div class="form-group">
              <label for="email">Email Address:</label>
              <input type="email" id="email" name="email" placeholder="Enter email address (optional)">
              <span class="field-error" id="emailError" style="display: none; color: #c33; font-size: 0.875rem; margin-top: 0.25rem;"></span>
            </div>
          </div>
          <div class="form-group">
            <label for="selectTable">Assign Table:</label>
            <select id="selectTable" name="selectTable">
              <option value="">-- Select Table --</option>
            </select>
            <span class="field-error" id="selectTableError" style="display: none; color: #c33; font-size: 0.875rem; margin-top: 0.25rem;"></span>
          </div>
          <div class="form-actions">
            <button type="button" class="btn btn-cancel" id="reservationCancelBtn">Cancel</button>
            <button type="submit" class="btn btn-save" id="reservationSaveBtn">Reserve Now</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Customer Modal (Add/Edit) -->
  <div id="customerModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 id="customerModalTitle">Add New Customer</h2>
        <span class="close">&times;</span>
      </div>
      <div class="modal-body">
        <form id="customerForm">
          <input type="hidden" id="customerId" name="customerId" value="">
          <div class="form-group">
            <label for="customerNameInput">Customer Name:</label>
            <input type="text" id="customerNameInput" name="customerName" required placeholder="Enter customer name" autocomplete="off">
          </div>
          <div class="form-group">
            <label for="customerPhoneInput">Phone:</label>
            <input type="tel" id="customerPhoneInput" name="phone" placeholder="Enter phone number (optional)" autocomplete="off">
          </div>
          <div class="form-group">
            <label for="customerEmailInput">Email Address:</label>
            <input type="email" id="customerEmailInput" name="email" placeholder="Enter email address">
          </div>
          <div class="form-actions">
            <button type="button" class="btn btn-cancel" id="customerCancelBtn">Cancel</button>
            <button type="submit" class="btn btn-save" id="customerSaveBtn">Save Customer</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Staff Modal (Add Member) -->
  <div id="staffModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Add Member</h2>
        <span class="close">&times;</span>
      </div>
      <div class="modal-body">
        <form id="staffForm">
          <input type="hidden" id="staffId" name="staffId" value="">
          <div class="form-group">
            <label for="memberName">Member Name:</label>
            <input type="text" id="memberName" name="memberName" required placeholder="admin@example.com">
          </div>
          <div class="form-group">
            <label for="memberEmail">Email Address:</label>
            <input type="email" id="memberEmail" name="memberEmail" required placeholder="Enter email address">
          </div>
          <div class="form-group">
            <label for="staffPhone">Restaurant Phone Number:</label>
            <div class="form-row">
              <select id="countryCode" name="countryCode" style="width: 30%;">
                <?php
                $staffDialDefault = $dashCountryInfo['dial_code'] ?? '+91';
                foreach (getCountryData() as $iso2 => $c):
                ?>
                <option value="<?php echo htmlspecialchars($c['dial_code']); ?>" <?php echo $c['dial_code'] === $staffDialDefault ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['dial_code'] . ' (' . $c['name'] . ')'); ?></option>
                <?php endforeach; ?>
              </select>
              <input type="tel" id="staffPhone" name="restaurantPhone" required placeholder="1234567890" style="width: 68%;">
            </div>
          </div>
          <div class="form-group">
            <label for="memberPassword">Password:</label>
            <input type="password" id="memberPassword" name="memberPassword" required placeholder="Enter password">
          </div>
          <div class="form-group">
            <label for="memberRole">Role:</label>
            <select id="memberRole" name="memberRole" required>
              <option value="Admin" selected>Admin</option>
              <option value="Manager">Manager</option>
              <option value="Waiter">Waiter</option>
              <option value="Chef">Chef</option>
            </select>
          </div>
          <div class="form-actions">
            <button type="button" class="btn btn-cancel" id="staffCancelBtn">Cancel</button>
            <button type="submit" class="btn btn-save" id="staffSaveBtn">Save</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Subscription Payment Modal (Replaces old renewal modal) -->
  <div id="renewalModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this) closeSubscriptionModal();">
    <div style="background:#fff;border-radius:20px;padding:0;max-width:460px;width:92%;overflow:hidden;box-shadow:0 25px 80px rgba(0,0,0,.35);margin:1rem;animation:overlaySlideUp 0.3s ease;">
      <div style="background:linear-gradient(135deg,#1e293b,#0f172a);padding:2rem 2rem 1.5rem;text-align:center;color:#fff;">
        <div style="font-size:3rem;margin-bottom:0.75rem;">🔒</div>
        <h2 style="margin:0;color:#fff;font-size:1.4rem;font-weight:700;">Subscription Required</h2>
        <p style="margin:0.5rem 0 0;opacity:0.8;font-size:0.9rem;" id="subscriptionModalMessage">Your subscription has expired. Please renew to continue using the service.</p>
      </div>
      <div style="padding:1.5rem 2rem 2rem;">
        <!-- Plan Selection -->
        <div style="display:flex;gap:12px;margin-bottom:1.25rem;">
          <div id="planMonthly" class="plan-option active" onclick="selectPlan('monthly', 999)" style="flex:1;border:2px solid #e17055;border-radius:12px;padding:1rem;text-align:center;cursor:pointer;background:#fff5f0;transition:all 0.2s;">
            <div style="font-size:1.5rem;font-weight:800;color:#1e293b;">₹999</div>
            <div style="font-size:0.8rem;color:#6b7280;margin-top:4px;">per month</div>
            <div style="font-size:0.7rem;color:#e17055;margin-top:6px;font-weight:600;">✓ Most Popular</div>
          </div>
          <div id="planYearly" class="plan-option" onclick="selectPlan('yearly', 9990)" style="flex:1;border:2px solid #e5e7eb;border-radius:12px;padding:1rem;text-align:center;cursor:pointer;background:#fff;transition:all 0.2s;">
            <div style="font-size:1.5rem;font-weight:800;color:#1e293b;">₹9,990</div>
            <div style="font-size:0.8rem;color:#6b7280;margin-top:4px;">per year</div>
            <div style="font-size:0.7rem;color:#10b981;margin-top:6px;font-weight:600;">✓ Save 17%</div>
          </div>
        </div>

        <!-- Payment Method -->
        <div style="margin-bottom:1rem;">
          <label style="font-size:0.85rem;font-weight:600;color:#374151;display:block;margin-bottom:8px;">Pay with</label>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <div class="payment-method-option active" data-method="phonepe" onclick="selectPaymentMethod('phonepe')" style="flex:1;border:2px solid #e17055;border-radius:10px;padding:10px;text-align:center;cursor:pointer;background:#fff5f0;display:flex;align-items:center;justify-content:center;gap:8px;min-width:120px;transition:all 0.2s;">
              <span style="font-weight:700;color:#6b21a8;">PhonePe</span>
              <span style="font-size:0.75rem;color:#8b5cf6;">UPI</span>
            </div>
          </div>
        </div>

        <!-- Auto-pay toggle -->
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:1.25rem;padding:10px 14px;background:#f9fafb;border-radius:10px;border:1px solid #e5e7eb;">
          <input type="checkbox" id="autoPayToggle" style="width:18px;height:18px;accent-color:#e17055;cursor:pointer;">
          <div style="flex:1;">
            <label for="autoPayToggle" style="font-weight:600;font-size:0.85rem;color:#374151;cursor:pointer;">Enable Auto-Pay</label>
            <p style="margin:2px 0 0;font-size:0.75rem;color:#9ca3af;">Automatically renew each month via UPI</p>
          </div>
        </div>

        <!-- Pay Button -->
        <button id="payNowBtn" onclick="initiateSubscriptionPayment()" style="width:100%;padding:14px;background:linear-gradient(135deg,#e17055,#d63031);color:#fff;border:none;border-radius:12px;font-weight:700;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all 0.2s;font-family:inherit;" onmouseover="this.style.transform='translateY(-1px)';this.style.boxShadow='0 4px 15px rgba(225,112,85,0.4)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
          <span class="material-symbols-rounded" style="font-size:20px;">lock</span>
          Pay ₹<span id="payNowAmount">999</span>
        </button>

        <!-- Payment status area -->
        <div id="subscriptionPaymentStatus" style="display:none;margin-top:1rem;padding:1rem;border-radius:10px;text-align:center;"></div>

        <p style="color:#9ca3af;font-size:0.75rem;text-align:center;margin-top:1rem;margin-bottom:0;">
          Secure payment via PhonePe. Your data is encrypted.
          <br><a href="#" onclick="event.preventDefault();closeSubscriptionModal();" style="color:#e17055;text-decoration:underline;">Maybe later</a>
        </p>
      </div>
    </div>
  </div>

  <style>
    .plan-option:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
    .payment-method-option:hover { transform: translateY(-1px); }
    #renewalModal .plan-option { transition: all 0.2s; }
    #renewalModal .payment-method-option { transition: all 0.2s; }
    @keyframes overlaySlideUp {
      from { transform: translateY(30px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }
    .badge-new{display:inline-block;font-size:9px;font-weight:700;padding:2px 6px;border-radius:10px;background:#10b981;color:#fff;text-transform:uppercase;letter-spacing:.3px;vertical-align:middle;margin-left:4px;}
  </style>

  <!-- POS Clear Cart Confirmation Modal -->
  <div id="posClearCartModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width:450px;">
      <div class="modal-header">
        <h2>
          <span class="material-symbols-rounded" style="vertical-align:middle;margin-right:0.5rem;">delete_outline</span>
          Clear Cart
        </h2>
        <span class="close" onclick="closePOSClearCartModal()">&times;</span>
      </div>
      <div class="modal-body">
        <div style="text-align:center;padding:1rem 0;">
          <span class="material-symbols-rounded" style="font-size:4rem;color:#f59e0b;display:block;margin-bottom:1rem;">shopping_cart_off</span>
          <p style="font-size:1.1rem;color:#1f2937;margin-bottom:0.5rem;font-weight:600;">Are you sure you want to clear the cart?</p>
          <p style="color:#6b7280;font-size:0.9rem;">This will remove all items from your cart. This action cannot be undone.</p>
        </div>
        <div class="form-actions" style="justify-content:center;margin-top:1.5rem;">
          <button type="button" class="btn btn-cancel" onclick="closePOSClearCartModal()">
            <span class="material-symbols-rounded">close</span>
            Cancel
          </button>
          <button type="button" class="btn btn-delete" id="posClearCartConfirmBtn">
            <span class="material-symbols-rounded">delete</span>
            Clear Cart
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Mobile Add Item Modal -->
  <div id="mobileAddItemModal" class="modal" style="display:none;z-index:10000;" onclick="if(event.target===this) closeMobileAddItemModal();">
    <div class="modal-content" style="max-width:100%;height:85vh;margin:7.5vh auto;display:flex;flex-direction:column;background:white;border-radius:16px 16px 0 0;" onclick="event.stopPropagation();">
      <div class="modal-header" style="flex-shrink:0;border-bottom:2px solid #f3f4f6;padding:0.75rem 1rem;">
        <h2 style="font-size:1.1rem;margin:0;">
          <span class="material-symbols-rounded" style="vertical-align:middle;margin-right:0.5rem;font-size:1.2rem;">add_circle</span>
          Add Item
        </h2>
        <span class="close" onclick="closeMobileAddItemModal()" style="font-size:1.4rem;">&times;</span>
      </div>
      <div class="modal-body" style="flex:1;overflow-y:auto;padding:0.75rem;max-height:calc(85vh - 80px);">
        <div style="margin-bottom:0.75rem;position:relative;">
          <input type="text" id="mobileItemSearch" placeholder="🔍 Search items..." style="width:100%;padding:0.6rem 2.5rem 0.6rem 0.6rem;border:2px solid #e5e7eb;border-radius:8px;font-size:0.85rem;box-sizing:border-box;" oninput="filterMobileItems()">
          <button type="button" onclick="filterMobileItems()" style="position:absolute;right:0.4rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:0.4rem;display:flex;align-items:center;justify-content:center;color:#6b7280;transition:color 0.2s;" onmouseover="this.style.color='#f70000'" onmouseout="this.style.color='#6b7280'">
            <span class="material-symbols-rounded" style="font-size:1.3rem;">search</span>
          </button>
        </div>
        <div id="mobileItemsList" style="display:grid;grid-template-columns:repeat(2,1fr);gap:0.5rem;">
          <!-- Items will be loaded here -->
        </div>
      </div>
    </div>
  </div>

  <!-- POS Variation Selection Modal -->
  <div id="posVariationModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width:500px;">
      <div class="modal-header">
        <h2>
          <span class="material-symbols-rounded" style="vertical-align:middle;margin-right:0.5rem;">tune</span>
          Select Variation
        </h2>
        <span class="close" onclick="closePOSVariationModal()">&times;</span>
      </div>
      <div class="modal-body">
        <p style="color:#6b7280;margin-bottom:1.5rem;text-align:center;" id="posVariationItemName">Choose a size or option:</p>
        <div id="posVariationOptions" style="display:flex;flex-direction:column;gap:0.75rem;margin-bottom:1.5rem;">
          <!-- Variations will be added here -->
        </div>
        <div class="form-actions" style="justify-content:center;">
          <button type="button" class="btn btn-cancel" onclick="closePOSVariationModal()">
            <span class="material-symbols-rounded">close</span>
            Cancel
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- POS Payment Method Selection Modal -->
  <div id="posPaymentMethodModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width:500px;">
      <div class="modal-header">
        <h2>
          <span class="material-symbols-rounded" style="vertical-align:middle;margin-right:0.5rem;">payments</span>
          Select Payment Method
        </h2>
        <span class="close" onclick="closePOSPaymentMethodModal()">&times;</span>
      </div>
      <div class="modal-body">
        <p style="color:#6b7280;margin-bottom:1.5rem;text-align:center;">Choose the payment method for this order:</p>
        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:1rem;margin-bottom:1.5rem;">
          <button class="payment-method-btn" data-method="Cash" onclick="selectPaymentMethod('Cash')" style="padding:1.5rem;border:2px solid #e5e7eb;border-radius:12px;background:white;cursor:pointer;transition:all 0.2s;text-align:center;">
            <span class="material-symbols-rounded" style="font-size:2.5rem;color:#10b981;display:block;margin-bottom:0.5rem;">money</span>
            <div style="font-weight:600;color:#1f2937;">Cash</div>
          </button>
          <button class="payment-method-btn" data-method="Card" onclick="selectPaymentMethod('Card')" style="padding:1.5rem;border:2px solid #e5e7eb;border-radius:12px;background:white;cursor:pointer;transition:all 0.2s;text-align:center;">
            <span class="material-symbols-rounded" style="font-size:2.5rem;color:#3b82f6;display:block;margin-bottom:0.5rem;">credit_card</span>
            <div style="font-weight:600;color:#1f2937;">Card</div>
          </button>
          <button class="payment-method-btn" data-method="UPI" onclick="selectPaymentMethod('UPI')" style="padding:1.5rem;border:2px solid #e5e7eb;border-radius:12px;background:white;cursor:pointer;transition:all 0.2s;text-align:center;">
            <span class="material-symbols-rounded" style="font-size:2.5rem;color:#8b5cf6;display:block;margin-bottom:0.5rem;">qr_code</span>
            <div style="font-weight:600;color:#1f2937;">UPI</div>
          </button>
          <button class="payment-method-btn" data-method="Online" onclick="selectPaymentMethod('Online')" style="padding:1.5rem;border:2px solid #e5e7eb;border-radius:12px;background:white;cursor:pointer;transition:all 0.2s;text-align:center;">
            <span class="material-symbols-rounded" style="font-size:2.5rem;color:#f59e0b;display:block;margin-bottom:0.5rem;">language</span>
            <div style="font-weight:600;color:#1f2937;">Online</div>
          </button>
        </div>
        <div class="form-actions" style="justify-content:center;">
          <button type="button" class="btn btn-cancel" onclick="closePOSPaymentMethodModal()">
            <span class="material-symbols-rounded">close</span>
            Cancel
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Payment Method Modal (Add/Edit) -->
  <div id="paymentMethodModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width: 500px;">
      <div class="modal-header">
        <h2 id="paymentMethodModalTitle">Add Payment Method</h2>
        <span class="close" onclick="closePaymentMethodModal()">&times;</span>
      </div>
      <div class="modal-body">
        <form id="paymentMethodForm">
          <input type="hidden" id="paymentMethodId" />
          <div class="form-group">
            <label for="paymentMethodName">Method Name *</label>
            <input type="text" id="paymentMethodName" placeholder="e.g., PayPal, Apple Pay" required />
          </div>
          <div class="form-group">
            <label for="paymentMethodEmoji">Emoji (Optional)</label>
            <div style="display: flex; gap: 0.5rem; align-items: center;">
              <input type="text" id="paymentMethodEmoji" placeholder="💳 or click below" maxlength="10" style="flex: 1;" />
              <div style="display: flex; gap: 0.25rem; flex-wrap: wrap; max-width: 200px;">
                <button type="button" class="emoji-btn" onclick="selectEmoji('💵')" title="Cash">💵</button>
                <button type="button" class="emoji-btn" onclick="selectEmoji('💳')" title="Card">💳</button>
                <button type="button" class="emoji-btn" onclick="selectEmoji('📱')" title="UPI">📱</button>
                <button type="button" class="emoji-btn" onclick="selectEmoji('🌐')" title="Online">🌐</button>
                <button type="button" class="emoji-btn" onclick="selectEmoji('👛')" title="Wallet">👛</button>
                <button type="button" class="emoji-btn" onclick="selectEmoji('🏦')" title="Bank">🏦</button>
                <button type="button" class="emoji-btn" onclick="selectEmoji('📝')" title="Cheque">📝</button>
                <button type="button" class="emoji-btn" onclick="selectEmoji('₿')" title="Crypto">₿</button>
                <button type="button" class="emoji-btn" onclick="selectEmoji('💎')" title="Diamond">💎</button>
                <button type="button" class="emoji-btn" onclick="selectEmoji('🎁')" title="Gift">🎁</button>
                <button type="button" class="emoji-btn" onclick="selectEmoji('💰')" title="Money">💰</button>
                <button type="button" class="emoji-btn" onclick="selectEmoji('💸')" title="Money Wings">💸</button>
              </div>
            </div>
          </div>
          <div class="form-group">
            <label>
              <input type="checkbox" id="paymentMethodActive" checked />
              Active (visible in payment options)
            </label>
          </div>
          <div class="form-actions">
            <button type="button" class="btn btn-cancel" onclick="closePaymentMethodModal()">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Method</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Restaurant Logo Upload Modal -->
  <div id="logoUploadModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width:500px;">
      <div class="modal-header">
        <h2>
          <span class="material-symbols-rounded" style="vertical-align:middle;margin-right:0.5rem;">image</span>
          Change Restaurant Logo
        </h2>
        <span class="close" onclick="closeLogoUploadModal()">&times;</span>
      </div>
      <div class="modal-body">
        <div style="text-align:center;padding:1rem 0;">
          <div id="logoPreviewContainer" style="margin-bottom:1.5rem;">
            <div id="logoPreview" style="width:150px;height:150px;border-radius:50%;background:#f3f4f6;margin:0 auto;display:flex;align-items:center;justify-content:center;border:3px dashed #d1d5db;overflow:hidden;">
              <span class="material-symbols-rounded" style="font-size:3rem;color:#9ca3af;">image</span>
            </div>
          </div>
          <input type="file" id="logoFileInput" accept="image/*" style="display:none;" onchange="handleLogoFileSelect(event)">
          <button type="button" class="btn btn-primary" onclick="document.getElementById('logoFileInput').click()" style="margin-bottom:1rem;">
            <span class="material-symbols-rounded">upload</span>
            Choose Image
          </button>
          <p style="color:#6b7280;font-size:0.875rem;margin:0.5rem 0;">Recommended: Square image, max 2MB (JPG, PNG, WebP)</p>
        </div>
        <div class="form-actions" style="justify-content:center;margin-top:1.5rem;">
          <button type="button" class="btn btn-cancel" onclick="closeLogoUploadModal()">
            <span class="material-symbols-rounded">close</span>
            Cancel
          </button>
          <button type="button" class="btn btn-save" id="saveLogoBtn" onclick="uploadRestaurantLogo()" disabled>
            <span class="material-symbols-rounded">save</span>
            Save Logo
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Script -->
  <!-- Currency utils (extracted from script.js) -->
  <script src="../assets/js/utils/currency.js?v=<?php echo time(); ?>"></script>
  <script src="../assets/js/escpos.js?v=<?php echo time(); ?>"></script>
  <script src="../assets/js/script.js?v=<?php echo time(); ?>" defer></script>
  

  
  <script>
    // Check payment status on page load (for redirect from PhonePe or Demo)
    window.addEventListener('load', function() {
      const urlParams = new URLSearchParams(window.location.search);
      const demoSuccess = urlParams.get('demo_payment_success');
      const demoCancelled = urlParams.get('demo_payment_cancelled');
      
      // Handle demo payment success
      if (demoSuccess === 'true') {
        const transactionId = urlParams.get('transaction_id');
        if (transactionId) {
          showNotification('Demo payment successful! Your subscription has been activated.', 'success');
          if (typeof loadRestaurantInfo === 'function') {
            setTimeout(() => loadRestaurantInfo(), 1000);
          }
          // Clear URL parameters
          window.history.replaceState({}, document.title, window.location.pathname);
          return;
        }
      }
      
      // Handle demo payment cancelled
      if (demoCancelled === 'true') {
        showNotification('Payment cancelled.', 'error');
        window.history.replaceState({}, document.title, window.location.pathname);
        return;
      }
      
      // Check if we just returned from real PhonePe payment
      const justReturned = sessionStorage.getItem('payment_processing');
      if (justReturned === 'true') {
        sessionStorage.removeItem('payment_processing');
        
        // Check payment status from server
        fetch('../api/check_payment_status.php')
          .then(response => response.json())
          .then(result => {
            if (result.success) {
              if (result.payment.payment_status === 'success') {
                showNotification('Payment successful! Your subscription has been activated.', 'success');
                // Reload subscription info
                if (typeof loadRestaurantInfo === 'function') {
                  setTimeout(() => loadRestaurantInfo(), 1000);
                }
              } else if (result.payment.payment_status === 'failed') {
                showNotification('Payment failed. Please try again.', 'error');
              } else {
                // Payment still pending, check again after 2 seconds
                setTimeout(() => {
                  fetch('../api/check_payment_status.php')
                    .then(r => r.json())
                    .then(r => {
                      if (r.success && r.payment.payment_status === 'success') {
                        showNotification('Payment successful! Your subscription has been activated.', 'success');
                        if (typeof loadRestaurantInfo === 'function') {
                          loadRestaurantInfo();
                        }
                      }
                    });
                }, 2000);
              }
            }
          })
          .catch(error => {
            console.error('Error checking payment status:', error);
          });
      }
    });
    
    // Restaurant switcher for branch admins
    function initRestaurantSwitcher() {
      fetch('../admin/get_session.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.success && data.data.linked_restaurants && data.data.user_type === 'branch_admin') {
            var wrapper = document.getElementById('restaurantSwitcherWrapper');
            var select = document.getElementById('restaurantSwitcher');
            if (!wrapper || !select) return;
            wrapper.style.display = 'block';
            var current = data.data.restaurant_id;
            data.data.linked_restaurants.forEach(function(lr) {
              var opt = document.createElement('option');
              opt.value = lr.restaurant_id;
              opt.textContent = lr.label || lr.restaurant_name;
              opt.style.color = '#333';
              opt.style.background = '#fff';
              if (lr.restaurant_id === current) opt.selected = true;
              select.appendChild(opt);
            });
            select.addEventListener('change', function() {
              var loading = document.createElement('div');
              loading.id = 'switchLoader';
              loading.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:99999;';
              loading.innerHTML = '<div style="background:#fff;padding:30px 40px;border-radius:12px;text-align:center"><div style="font-size:32px;margin-bottom:10px">&#8987;</div><p>Switching restaurant...</p></div>';
              document.body.appendChild(loading);
              fetch('../api/switch_restaurant.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ restaurant_id: this.value })
              })
              .then(function(r) { return r.json(); })
              .then(function(d) {
                if (d.success) {
                  window.location.reload();
                } else {
                  document.getElementById('switchLoader')?.remove();
                  showNotification('Failed to switch: ' + d.message, 'error');
                }
              })
              .catch(function() {
                document.getElementById('switchLoader')?.remove();
                showNotification('Network error switching restaurant', 'error');
              });
            });
          }
        })
        .catch(function() {});
    }

    // Initialize restaurant switcher
    window.addEventListener('load', function() {
      initRestaurantSwitcher();
    });

    // Ensure mobile scrolling works on all devices
    (function() {
      function enableScrolling() {
        if (window.innerWidth <= 768) {
          // Ensure html allows body to grow
          document.documentElement.style.overflowY = 'auto';
          document.documentElement.style.height = 'auto';
          document.documentElement.style.minHeight = '100%';
          document.documentElement.style.maxHeight = 'none';
          
          // Ensure body can scroll and grow
          document.body.style.overflowY = 'auto';
          document.body.style.height = 'auto';
          document.body.style.minHeight = '100vh';
          document.body.style.maxHeight = 'none';
          document.body.style.webkitOverflowScrolling = 'touch';
          document.body.style.touchAction = 'pan-y';
          document.body.style.position = 'relative';
          
          // Ensure main-content doesn't constrain body
          const mainContent = document.querySelector('.main-content');
          if (mainContent) {
            mainContent.style.height = 'auto';
            mainContent.style.minHeight = 'auto';
            mainContent.style.maxHeight = 'none';
            mainContent.style.overflow = 'visible';
            mainContent.style.position = 'relative';
          }
          
          // Remove height constraints from all page containers
          const containers = document.querySelectorAll('.page, .page-content, .page-header');
          containers.forEach(function(el) {
            el.style.height = 'auto';
            el.style.minHeight = 'auto';
            el.style.maxHeight = 'none';
            el.style.overflow = 'visible';
          });
        }
      }
      
      // Run on load and resize
      enableScrolling();
      window.addEventListener('resize', enableScrolling);
      window.addEventListener('orientationchange', function() {
        setTimeout(enableScrolling, 100);
      });
      
      // Also run after a short delay to ensure DOM is fully loaded
      setTimeout(enableScrolling, 100);
    })();
  </script>
<script>
</script>
<script>
var existingDealTypes = {};

function loadDeals() {
  var rid = document.querySelector("meta[name=restaurant-id]")?.content || window.websiteRestaurantId || document.getElementById("restaurantId")?.textContent || "";
  fetch("../api/get_deals.php?restaurant_id=" + encodeURIComponent(rid))
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (!res.success) return;
      var tbody = document.getElementById("dealsTbody");
      if (!tbody) return;
      existingDealTypes = {};
      var html = "";
      if (!res.data || res.data.length === 0) {
        html = "<tr><td colspan=\"4\" style=\"text-align:center;padding:30px;color:#999;\">No deals created yet. Create one above!</td></tr>";
      } else {
        for (var i = 0; i < res.data.length; i++) {
          var d = res.data[i];
          existingDealTypes[d.deal_type] = d.menu_name;
          var typeLabel = d.deal_type === "combo" ? "Combo" : "New";
          var typeIcon = d.deal_type === "combo" ? "&#x1f371;" : "&#x2728;";
          var created = d.created_at ? d.created_at.split(" ")[0] : "-";
          html += "<tr>" +
            "<td><span style=\"display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;background:" + (d.deal_type === "combo" ? "#e8f5e9" : "#fff3e0") + ";font-size:12px;font-weight:600;color:" + (d.deal_type === "combo" ? "#2e7d32" : "#e65100") + "\">" + typeIcon + " " + typeLabel + "</span></td>" +
            "<td><strong>" + (d.menu_name || "-") + "</strong></td>" +
            "<td>" + created + "</td>" +
            "<td><button class=\"btn btn-danger\" style=\"padding:6px 12px;font-size:12px;\" onclick=\"deleteDeal(" + d.id + ")\">Delete</button></td>" +
          "</tr>";
        }
      }
      tbody.innerHTML = html;
      updateDealTypeOptions();
    })
    .catch(function() {});
}

function updateDealTypeOptions() {
  var sel = document.getElementById("dealType");
  if (!sel) return;
  var options = sel.options;
  for (var i = 0; i < options.length; i++) {
    var val = options[i].value;
    if (val === "combo" && existingDealTypes["combo"]) {
      options[i].disabled = true;
      options[i].text = "Combo Deal (already set on " + existingDealTypes["combo"] + ")";
    } else if (val === "combo") {
      options[i].disabled = false;
      options[i].text = "Combo Deal";
    } else if (val === "new" && existingDealTypes["new"]) {
      options[i].disabled = true;
      options[i].text = "New Deal (already set on " + existingDealTypes["new"] + ")";
    } else if (val === "new") {
      options[i].disabled = false;
      options[i].text = "New Deal";
    }
  }
}

function loadDealMenus() {
  var rid = document.querySelector("meta[name=restaurant-id]")?.content || window.websiteRestaurantId || document.getElementById("restaurantId")?.textContent || "";
  fetch("../api/get_menus.php?restaurant_id=" + encodeURIComponent(rid))
    .then(function(r) { return r.json(); })
    .then(function(res) {
      var sel = document.getElementById("dealMenu");
      if (!sel) return;
      var menus = res.data || [];
      sel.innerHTML = "<option value=\"\">Select menu...</option>";
      for (var i = 0; i < menus.length; i++) {
        sel.innerHTML += "<option value=\"" + menus[i].id + "\">" + (menus[i].menu_name || "Menu " + (i+1)) + "</option>";
      }
    })
    .catch(function() {});
}

function saveDeal(e) {
  e.preventDefault();
  var dealType = document.getElementById("dealType").value;
  var menuId = document.getElementById("dealMenu").value;
  var btn = document.getElementById("dealSaveBtn");
  
  if (!dealType || !menuId) {
    showSweetAlert("Pick a deal type and a menu to get started!", "info");
    return false;
  }
  
  btn.disabled = true;
  btn.textContent = "Saving...";
  
  var rid = document.getElementById("restaurantId")?.textContent || window.websiteRestaurantId || "";
  var formData = new FormData();
  formData.append("restaurant_id", rid);
  formData.append("deal_type", dealType);
  formData.append("menu_id", menuId);
  
  fetch("../api/save_deal.php", { method: "POST", body: formData })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      btn.disabled = false;
      btn.innerHTML = "+ Create Deal";
      if (res.success) {
        showNotification("success", "Deal created successfully!");
        document.getElementById("dealType").value = "";
        document.getElementById("dealMenu").value = "";
        loadDeals();
      } else {
        showNotification("error", res.message || "Couldn't create the deal — no worries, try again!");
      }
    })
    .catch(function() {
      btn.disabled = false;
      btn.innerHTML = "+ Create Deal";
      showFriendlyNetworkError();
    });
  return false;
}

function deleteDeal(id) {
  if (!confirm("Are you sure you want to delete this deal?")) return;
  
  var rid = document.getElementById("restaurantId")?.textContent || "";
  var formData = new FormData();
  formData.append("restaurant_id", rid);
  formData.append("deal_id", id);
  formData.append("action", "delete");
  
  fetch("../api/save_deal.php", { method: "POST", body: formData })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.success) {
        showNotification("success", "Deal deleted");
        loadDeals();
      } else {
        showNotification("error", res.message || "Couldn't delete just yet — try again!");
      }
    })
    .catch(function() {
      showFriendlyNetworkError();
    });
}

// ===== Delivery Zone Functions =====
function loadDeliveryZones() {
  fetch("../api/get_delivery_zones.php")
    .then(function(r) { return r.json(); })
    .then(function(res) {
      var tbody = document.getElementById("deliveryZonesTbody");
      if (!res.success || !res.zones || res.zones.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:30px;color:#999;">No zones added yet. Click "Add Zone" to create one.</td></tr>';
        return;
      }
      var html = "";
      res.zones.forEach(function(z) {
        var activeClass = z.is_active == 1 ? "status-active" : "status-inactive";
        var activeText = z.is_active == 1 ? "Active" : "Inactive";
        html += "<tr>" +
          "<td>" + z.pincode + "</td>" +
          "<td>" + (z.zone_name || "-") + "</td>" +
          "<td>" + (window.globalCurrencySymbol || '₹') + parseFloat(z.delivery_charge).toFixed(2) + "</td>" +
          "<td>" + z.estimated_time + " min</td>" +
          '<td><span class="status-badge ' + activeClass + '">' + activeText + "</span></td>" +
          '<td class="action-btns">' +
            '<button class="btn-icon" onclick="editZone(' + z.id + ')" title="Edit"><span class="material-symbols-rounded">edit</span></button> ' +
            '<button class="btn-icon" onclick="toggleZoneStatus(' + z.id + ', ' + z.is_active + ')" title="Toggle">' +
              '<span class="material-symbols-rounded">' + (z.is_active == 1 ? "toggle_on" : "toggle_off") + "</span>" +
            '</button> ' +
            '<button class="btn-icon btn-icon-danger" onclick="deleteZone(' + z.id + ')" title="Delete"><span class="material-symbols-rounded">delete</span></button>' +
          "</td>" +
        "</tr>";
      });
      tbody.innerHTML = html;
    })
    .catch(function() {
      document.getElementById("deliveryZonesTbody").innerHTML = '<tr><td colspan="6" style="text-align:center;padding:30px;color:#999;">Couldn\'t load zones — check your connection and try again</td></tr>';
    });
}

function openZoneModal(zoneData) {
  document.getElementById("zoneId").value = zoneData ? (zoneData.id || "") : "";
  document.getElementById("zonePincode").value = zoneData ? (zoneData.pincode || "") : "";
  document.getElementById("zoneName").value = zoneData ? (zoneData.zone_name || "") : "";
  document.getElementById("zoneCharge").value = zoneData ? (zoneData.delivery_charge || 0) : "";
  document.getElementById("zoneEta").value = zoneData ? (zoneData.estimated_time || 30) : 30;
  document.getElementById("zoneActive").checked = zoneData ? (zoneData.is_active == 1) : true;
  document.getElementById("zoneModalTitle").textContent = zoneData ? "Edit Zone" : "Add Delivery Zone";
  document.getElementById("zoneSaveBtn").textContent = zoneData ? "Update Zone" : "Save Zone";
  document.getElementById("zoneModal").style.display = "flex";
}

function editZone(id) {
  var zones = document.getElementById("deliveryZonesTbody").querySelectorAll("tr");
  fetch("../api/get_delivery_zones.php")
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.success && res.zones) {
        var zone = res.zones.find(function(z) { return z.id == id; });
        if (zone) openZoneModal(zone);
      }
    });
}

function saveZone(event) {
  event.preventDefault();
  var formData = new FormData();
  var zoneId = document.getElementById("zoneId").value;
  formData.append("action", zoneId ? "update" : "create");
  if (zoneId) formData.append("id", zoneId);
  formData.append("pincode", document.getElementById("zonePincode").value);
  formData.append("zone_name", document.getElementById("zoneName").value);
  formData.append("delivery_charge", document.getElementById("zoneCharge").value);
  formData.append("estimated_time", document.getElementById("zoneEta").value);
  formData.append("is_active", document.getElementById("zoneActive").checked ? 1 : 0);

  var btn = document.getElementById("zoneSaveBtn");
  btn.disabled = true;
  btn.textContent = "Saving...";

  fetch("../api/save_delivery_zone.php", { method: "POST", body: formData })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      btn.disabled = false;
      btn.textContent = zoneId ? "Update Zone" : "Save Zone";
      if (res.success) {
        showNotification("success", res.message);
        closeModal("zoneModal");
        loadDeliveryZones();
      } else {
        showNotification("error", res.message || "Couldn't save the zone — try once more!");
      }
    })
    .catch(function() {
      btn.disabled = false;
      btn.textContent = zoneId ? "Update Zone" : "Save Zone";
      showFriendlyNetworkError();
    });
  return false;
}

function deleteZone(id) {
  if (!confirm("Are you sure you want to delete this zone?")) return;
  var formData = new FormData();
  formData.append("action", "delete");
  formData.append("id", id);
  fetch("../api/save_delivery_zone.php", { method: "POST", body: formData })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.success) {
        showNotification("success", "Zone deleted");
        loadDeliveryZones();
      } else {
        showNotification("error", res.message || "Couldn't delete — give it another go!");
      }
    })
    .catch(function() {
      showFriendlyNetworkError();
    });
}

function toggleZoneStatus(id, current) {
  var formData = new FormData();
  formData.append("action", "toggle");
  formData.append("id", id);
  fetch("../api/save_delivery_zone.php", { method: "POST", body: formData })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.success) {
        showNotification("success", res.message);
        loadDeliveryZones();
      } else {
        showNotification("error", res.message || "Couldn't update the status — give it another shot!");
      }
    });
}

// ===== Delivery Map Functions =====
var deliveryMap = null;
var deliveryMarkers = [];
var mapRefreshTimer = null;

function initDeliveryMap() {
  var container = document.getElementById("deliveryMap");
  if (!container) return;
  if (deliveryMap) {
    deliveryMap.invalidateSize();
    loadActiveDeliveries();
  } else {
    deliveryMap = L.map("deliveryMap").setView([20.5937, 78.9629], 5);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
      maxZoom: 19
    }).addTo(deliveryMap);
    loadActiveDeliveries();
  }
  startDeliveryMapRefresh();
}

// Auto-refresh every 30s, but only while the map page is actually showing and
// the tab is visible — previously this timer kept running forever in the
// background once the map had been opened once, even after navigating away
// or backgrounding the tab.
function startDeliveryMapRefresh() {
  stopDeliveryMapRefresh();
  mapRefreshTimer = setInterval(function() {
    if (document.hidden) return;
    loadActiveDeliveries();
  }, 30000);
}

function stopDeliveryMapRefresh() {
  if (mapRefreshTimer) {
    clearInterval(mapRefreshTimer);
    mapRefreshTimer = null;
  }
}
window.stopDeliveryMapRefresh = stopDeliveryMapRefresh;

function loadActiveDeliveries() {
  var statusEl = document.getElementById("deliveryMapStatus");
  if (statusEl) statusEl.textContent = "Refreshing...";
  fetch("../api/get_active_deliveries.php")
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (!res.success) {
        if (statusEl) statusEl.textContent = "Couldn't fetch deliveries — might be a temporary hiccup!";
        return;
      }
      // Clear existing markers
      deliveryMarkers.forEach(function(m) { deliveryMap.removeLayer(m); });
      deliveryMarkers = [];
      if (!res.deliveries || res.deliveries.length === 0) {
        if (statusEl) statusEl.textContent = "No active deliveries right now.";
        return;
      }
      var bounds = [];
      res.deliveries.forEach(function(d) {
        var lat = parseFloat(d.current_lat);
        var lng = parseFloat(d.current_lng);
        if (!d.current_lat || !d.current_lng || isNaN(lat) || isNaN(lng)) return;
        var color = "#3b82f6";
        if (d.delivery_status === "Preparing") color = "#f59e0b";
        else if (d.delivery_status === "Assigned") color = "#3b82f6";
        else if (d.delivery_status === "Picked_Up") color = "#8b5cf6";
        else if (d.delivery_status === "In_Transit") color = "#059669";
        var icon = L.divIcon({
          className: "custom-marker",
          html: '<div style="background:' + color + ';color:#fff;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.3);"><span class="material-symbols-rounded" style="font-size:18px;">local_shipping</span></div>',
          iconSize: [32, 32],
          iconAnchor: [16, 32],
          popupAnchor: [0, -36]
        });
        var marker = L.marker([lat, lng], { icon: icon }).addTo(deliveryMap);
        var timeAgo = "";
        if (d.location_updated_at) {
          var updated = new Date(d.location_updated_at.replace(" ", "T") + "+05:30");
          var now = new Date();
          var diff = Math.floor((now - updated) / 60000);
          timeAgo = diff < 1 ? "Just now" : diff + " min ago";
        }
        var statusLabel = d.delivery_status.replace(/_/g, " ");
        marker.bindPopup(
          '<div style="min-width:200px;font-size:13px;">' +
            '<div style="font-weight:600;margin-bottom:4px;">' + escHtml(d.rider_name || "Unassigned") + " - " + escHtml(d.order_number) + '</div>' +
            '<div style="color:#6b7280;margin-bottom:2px;">Status: <span style="color:' + color + ';font-weight:500;">' + statusLabel + '</span></div>' +
            (d.customer_name ? '<div style="color:#6b7280;margin-bottom:2px;">Customer: ' + escHtml(d.customer_name) + '</div>' : "") +
            (d.delivery_address ? '<div style="color:#6b7280;margin-bottom:2px;">Address: ' + escHtml(d.delivery_address) + '</div>' : "") +
            (timeAgo ? '<div style="color:#9ca3af;font-size:11px;margin-top:4px;">Location: ' + timeAgo + '</div>' : "") +
          "</div>"
        );
        deliveryMarkers.push(marker);
        bounds.push([lat, lng]);
      });
      if (bounds.length > 0) {
        deliveryMap.fitBounds(bounds, { padding: [50, 50], maxZoom: 15 });
      }
      if (statusEl) statusEl.textContent = res.deliveries.length + " active delivery" + (res.deliveries.length > 1 ? "ies" : "y") + " showing";
    })
    .catch(function() {
      if (statusEl) statusEl.textContent = "Delivery data took a break — check back in a moment!";
    });
}

function escHtml(str) {
  if (!str) return "";
  return String(str).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
}

// ===== Feedback Functions =====
var allFeedback = [];

function loadFeedback() {
  fetch("../api/get_feedback.php")
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (!res.success) return;
      allFeedback = res.feedback || [];
      if (res.stats) {
        document.getElementById("fbTotal").textContent = res.stats.total || 0;
        document.getElementById("fbAvg").textContent = (parseFloat(res.stats.avg_rating) || 0).toFixed(1);
        document.getElementById("fbPositive").textContent = res.stats.positive || 0;
        document.getElementById("fbNegative").textContent = res.stats.negative || 0;
      }
      renderFeedback();
    })
    .catch(function() {
      document.getElementById("feedbackTbody").innerHTML = '<tr><td colspan="5" style="text-align:center;padding:30px;color:#999;">Couldn\'t load feedback — try refreshing the page!</td></tr>';
    });
}

function renderFeedback() {
  var filter = document.getElementById("fbFilter").value;
  var tbody = document.getElementById("feedbackTbody");
  var items = filter === "all" ? allFeedback : allFeedback.filter(function(f) { return f.rating == filter; });
  if (items.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:30px;color:#999;">No feedback yet.</td></tr>';
    return;
  }
  var html = "";
  items.forEach(function(f) {
    var stars = "";
    for (var i = 1; i <= 5; i++) stars += i <= f.rating ? '<span style="color:#f59e0b;">★</span>' : '<span style="color:#d1d5db;">★</span>';
    var date = f.created_at ? f.created_at.split(" ")[0] : "";
    html += "<tr>" +
      "<td style='white-space:nowrap;'>" + date + "</td>" +
      "<td>#" + escHtml(f.order_number) + "</td>" +
      "<td>" + escHtml(f.customer_name || "Anonymous") + "</td>" +
      "<td>" + stars + "</td>" +
      "<td style='max-width:300px;word-break:break-word;'>" + escHtml(f.review || "-") + "</td>" +
    "</tr>";
  });
  tbody.innerHTML = html;
}

// ===== Embed / Custom Domain Functions =====
var embedSettings = null;

function loadEmbedSettings() {
  fetch("../api/get_embed_settings.php")
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (!res.success) {
        // Previously silent — left embedSettings as null with no visible
        // sign anything was wrong, so the toggle switch still looked
        // interactive. Flipping it while embedSettings was null used to
        // wipe out the saved custom domain (see toggleEmbedFeature/
        // saveCustomDomain — now fixed to never do that regardless), but
        // this is still worth surfacing so a failed load isn't invisible.
        showNotification('error', res.message || 'Could not load custom domain settings. Please refresh and try again.');
        return;
      }
      embedSettings = res.data;
      var enabled = res.data.embed_enabled;
      document.getElementById('embedToggle').checked = enabled;
      document.getElementById('embedToggleLabel').textContent = enabled ? 'Enabled' : 'Disabled';
      document.getElementById('embedSettingsContent').style.display = enabled ? 'block' : 'none';
      document.getElementById('embedPreviewSection').style.display = enabled ? 'block' : 'none';
      var code = res.data.embed_code || '';
      document.getElementById('embedCodeDisplay').value = code;
      document.getElementById('embedRestaurantId').textContent = res.data.restaurant_id || '-';
      document.getElementById('embedStatus').textContent = enabled ? 'Active' : 'Available';
      document.getElementById('embedStatus').style.color = enabled ? '#27ae60' : '#f39c12';
      document.getElementById('customDomainInput').value = res.data.custom_domain || '';
      var customDomain = res.data.custom_domain || '';
      var urlDisplay = document.getElementById('customDomainUrlDisplay');
      var urlLink = document.getElementById('customDomainUrlLink');
      var setupInstructions = document.getElementById('customDomainSetupInstructions');
      if (customDomain) {
        var protocol = window.location.protocol + '//';
        urlLink.href = protocol + customDomain;
        urlLink.textContent = protocol + customDomain;
        urlDisplay.style.display = 'block';
        setupInstructions.style.display = 'block';
      } else {
        urlDisplay.style.display = 'none';
        setupInstructions.style.display = 'none';
      }
      document.getElementById('serverIpDisplay').textContent = res.data.server_ip || window.location.hostname;
      document.getElementById('mainDomainDisplay').textContent = res.data.main_domain || window.location.hostname;
      // Load preview
      var preview = document.getElementById('embedPreview');
      if (preview && res.data.restaurant_id) {
        preview.src = '../embed/embed.php?restaurant_id=' + encodeURIComponent(res.data.restaurant_id);
      }
    })
    .catch(function() {
      showNotification('error', 'Could not load custom domain settings. Please check your connection and refresh.');
    });
}

function toggleEmbedFeature(enabled) {
  document.getElementById('embedToggleLabel').textContent = enabled ? 'Enabled' : 'Disabled';
  document.getElementById('embedSettingsContent').style.display = enabled ? 'block' : 'none';
  document.getElementById('embedPreviewSection').style.display = enabled ? 'block' : 'none';
  // Only send embed_enabled — never re-send custom_domain here. This used
  // to resend embedSettings.custom_domain "for safety", but if that cached
  // value was ever null/stale (e.g. the initial GET failed), it silently
  // blanked out an already-saved custom domain. The API now updates each
  // field independently, so this only ever touches embed_enabled.
  fetch("../api/save_embed_settings.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ embed_enabled: enabled })
  })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.success) {
        if (embedSettings) embedSettings.embed_enabled = enabled;
        showNotification('success', enabled ? 'Custom domain / embed enabled' : 'Custom domain / embed disabled');
      } else {
        showNotification('error', res.message || 'Failed to save');
      }
    })
    .catch(function() {
      showNotification('error', 'Network error');
    });
}

function saveCustomDomain() {
  var domain = document.getElementById('customDomainInput').value.trim();
  // Only send custom_domain — never re-send embed_enabled here, for the
  // same reason as toggleEmbedFeature() above.
  fetch("../api/save_embed_settings.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ custom_domain: domain })
  })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.success) {
        if (embedSettings) embedSettings.custom_domain = domain;
        showNotification('success', 'Custom domain saved');
        loadEmbedSettings();
      } else {
        showNotification('error', res.message || 'Failed to save');
      }
    })
    .catch(function() {
      showNotification('error', 'Network error');
    });
}

function copyEmbedCode() {
  var el = document.getElementById('embedCodeDisplay');
  if (!el || !el.value) return;
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(el.value).then(function() {
      var btn = el.nextElementSibling;
      if (btn) { btn.textContent = 'Copied!'; setTimeout(function() { btn.textContent = 'Copy'; }, 2000); }
    });
  } else {
    el.select();
    document.execCommand('copy');
    var btn = el.nextElementSibling;
    if (btn) { btn.textContent = 'Copied!'; setTimeout(function() { btn.textContent = 'Copy'; }, 2000); }
  }
}

// Country -> currency auto-fill (still manually overridable via the Currency Symbol field below)
document.getElementById('countrySelect').addEventListener('change', function() {
  var opt = this.options[this.selectedIndex];
  var currency = opt.getAttribute('data-currency');
  if (!currency) return;
  var currencySelect = document.getElementById('currencySymbolSelect');
  var customInput = document.getElementById('currencySymbol');
  var matched = false;
  for (var i = 0; i < currencySelect.options.length; i++) {
    if (currencySelect.options[i].value === currency) {
      currencySelect.selectedIndex = i;
      matched = true;
      break;
    }
  }
  if (matched) {
    customInput.style.display = 'none';
    customInput.value = '';
  } else {
    for (var j = 0; j < currencySelect.options.length; j++) {
      if (currencySelect.options[j].value === 'Custom') { currencySelect.selectedIndex = j; break; }
    }
    customInput.style.display = '';
    customInput.value = currency;
  }
});

// System settings form submit handler
document.getElementById('systemSettingsForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  var btn = this.querySelector('.btn-save');
  btn.disabled = true;
  btn.innerHTML = '<span class="material-symbols-rounded">hourglass_top</span> Saving...';

  var formData = new FormData();
  formData.append('action', 'updateSystemSettings');
  formData.append('country', document.getElementById('countrySelect').value);
  formData.append('currency_symbol', document.getElementById('currencySymbol').value || document.getElementById('currencySymbolSelect').value);
  formData.append('timezone', document.getElementById('timezone').value);
  formData.append('language', document.getElementById('language') ? document.getElementById('language').value : 'en');
  formData.append('auto_sync', document.getElementById('autoSync').checked ? 1 : 0);
  formData.append('notifications', document.getElementById('notifications').checked ? 1 : 0);

  try {
    var res = await fetch('../admin/auth.php', { method: 'POST', body: formData });
    var data = await res.json();
    if (data.success) {
      showNotification('success', data.message);
    } else {
      showNotification('error', data.message || 'Failed to save settings');
    }
  } catch(err) {
    showNotification('error', 'Network error');
  }
  btn.disabled = false;
  btn.innerHTML = '<span class="material-symbols-rounded">save</span> Save Changes';
});

// ===== PWA Install App Support =====
var deferredPrompt = null;

function showInstallMsg(msg, icon, title) {
  icon = icon || 'info';
  title = title || 'Install App';
  if (typeof Swal !== 'undefined') {
    Swal.fire({ icon: icon, title: title, text: msg, confirmButtonColor: '#e17055' });
  } else {
    alert(msg);
  }
}

// Capture the beforeinstallprompt event when the browser fires it
var _promptTriggered = false;
window.addEventListener('beforeinstallprompt', function(e) {
  e.preventDefault();
  deferredPrompt = e;
  _promptTriggered = false;
  pwaDiagnostics.deferredPromptReady = true;
  console.log('%c📲 PWA install prompt is ready! Click Install App to add to home screen.', 'background:#059669;color:#fff;padding:4px 8px;border-radius:4px;font-size:13px;');
});

window.addEventListener('appinstalled', function() {
  deferredPrompt = null;
  sessionStorage.removeItem('pwa_refreshed');
  var allBtns = document.querySelectorAll('[onclick*="promptInstall"]');
  for (var bi = 0; bi < allBtns.length; bi++) { allBtns[bi].innerHTML = '<span class="material-symbols-rounded">check_circle</span> Installed'; allBtns[bi].disabled = true; }
  console.log('%c✅ PWA App installed successfully!', 'background:#059669;color:#fff;padding:4px 8px;border-radius:4px;font-size:13px;');
});

// ===== Subscription Management =====
let selectedSubscriptionPlan = 'monthly';
let selectedPaymentMethod = 'phonepe';
let selectedAmount = 999;

function closeSubscriptionModal() {
  document.getElementById('renewalModal').style.display = 'none';
}

function selectPlan(type, amount) {
  selectedSubscriptionPlan = type;
  selectedAmount = amount;
  document.querySelectorAll('.plan-option').forEach(el => {
    el.style.borderColor = '#e5e7eb';
    el.style.background = '#fff';
    el.querySelector('div:last-child').style.color = type === 'monthly' ? '#e17055' : '#10b981';
  });
  const active = type === 'monthly' ? document.getElementById('planMonthly') : document.getElementById('planYearly');
  active.style.borderColor = '#e17055';
  active.style.background = '#fff5f0';
  document.getElementById('payNowAmount').textContent = amount.toLocaleString();
}

function selectPaymentMethod(method) {
  selectedPaymentMethod = method;
  document.querySelectorAll('.payment-method-option').forEach(el => {
    el.style.borderColor = '#e5e7eb';
    el.style.background = '#fff';
  });
  const active = document.querySelector(`.payment-method-option[data-method="${method}"]`);
  if (active) {
    active.style.borderColor = '#e17055';
    active.style.background = '#fff5f0';
  }
}

function showPaymentStatus(html, isError) {
  const el = document.getElementById('subscriptionPaymentStatus');
  el.style.display = 'block';
  el.style.background = isError ? '#fef2f2' : '#f0fdf4';
  el.style.color = isError ? '#dc2626' : '#16a34a';
  el.innerHTML = html;
}

async function initiateSubscriptionPayment() {
  const btn = document.getElementById('payNowBtn');
  const autoPay = document.getElementById('autoPayToggle')?.checked || false;
  btn.disabled = true;
  btn.innerHTML = '<span class="loading-spinner"></span> Processing...';
  showPaymentStatus('', false);

  try {
    // First check if subscription_payments table exists
    const checkResp = await fetch('../api/subscription_payment.php?action=getStatus');
    const checkData = await checkResp.json();

    if (!checkData.success) {
      showPaymentStatus('⚠️ ' + (checkData.message || 'Error checking subscription'), true);
      btn.disabled = false;
      btn.innerHTML = '<span class="material-symbols-rounded" style="font-size:20px;">lock</span> Pay ₹<span id="payNowAmount">' + selectedAmount.toLocaleString() + '</span>';
      return;
    }

    const formData = new FormData();
    formData.append('action', 'initiatePayment');
    formData.append('amount', selectedAmount);
    formData.append('subscription_type', selectedSubscriptionPlan);

    const resp = await fetch('../api/subscription_payment.php', {
      method: 'POST',
      body: new URLSearchParams({
        'action': 'initiatePayment',
        'amount': selectedAmount,
        'subscription_type': selectedSubscriptionPlan
      })
    });

    const data = await resp.json();

    if (!data.success) {
      showPaymentStatus('⚠️ ' + (data.message || 'Payment initiation failed'), true);
      btn.disabled = false;
      btn.innerHTML = '<span class="material-symbols-rounded" style="font-size:20px;">lock</span> Pay ₹<span id="payNowAmount">' + selectedAmount.toLocaleString() + '</span>';
      return;
    }

    // If auto-pay enabled, save preference
    if (autoPay) {
      await fetch('../api/subscription_payment.php', {
        method: 'POST',
        body: new URLSearchParams({
          'action': 'toggleAutoPay',
          'enable': '1',
          'method': selectedPaymentMethod
        })
      });
    }

    if (data.demo_mode) {
      // Demo mode - simulate success
      showPaymentStatus(
        '<div style="text-align:center;">'
        + '<div style="font-size:3rem;margin-bottom:0.5rem;">🧪</div>'
        + '<h3 style="margin:0 0 0.5rem;color:#1e293b;">Demo Payment</h3>'
        + '<p style="color:#6b7280;font-size:0.9rem;margin-bottom:1rem;">This is a demo. In production, you will be redirected to PhonePe.</p>'
        + '<button onclick="simulatePaymentSuccess(\'' + data.transaction_id + '\')" style="padding:10px 24px;background:#10b981;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">✓ Simulate Payment Success</button>'
        + '</div>',
        false
      );
      btn.disabled = false;
      btn.innerHTML = '<span class="material-symbols-rounded" style="font-size:20px;">lock</span> Pay ₹<span id="payNowAmount">' + selectedAmount.toLocaleString() + '</span>';
    } else if (data.payment_url) {
      // Real payment - open PhonePe checkout in new window
      showPaymentStatus(
        '<div style="text-align:center;">'
        + '<div style="font-size:3rem;margin-bottom:0.5rem;">🔄</div>'
        + '<h3 style="margin:0 0 0.5rem;color:#1e293b;">Redirecting to PhonePe...</h3>'
        + '<p style="color:#6b7280;font-size:0.9rem;">Please complete the payment in the new window.</p>'
        + '</div>',
        false
      );
      // Open PhonePe payment page
      window.open(data.payment_url, '_blank');
      // Poll for payment status
      pollPaymentStatus(data.transaction_id);
    } else {
      showPaymentStatus('⚠️ No payment URL received', true);
      btn.disabled = false;
      btn.innerHTML = '<span class="material-symbols-rounded" style="font-size:20px;">lock</span> Pay ₹<span id="payNowAmount">' + selectedAmount.toLocaleString() + '</span>';
    }

  } catch (err) {
    showPaymentStatus('⚠️ Connection error: ' + err.message, true);
    btn.disabled = false;
    btn.innerHTML = '<span class="material-symbols-rounded" style="font-size:20px;">lock</span> Pay ₹<span id="payNowAmount">' + selectedAmount.toLocaleString() + '</span>';
  }
}

function pollPaymentStatus(transactionId) {
  let attempts = 0;
  const maxAttempts = 30; // Poll for ~2.5 minutes
  const interval = setInterval(async () => {
    attempts++;
    try {
      const resp = await fetch('../api/subscription_payment.php?action=checkPaymentStatus&transaction_id=' + encodeURIComponent(transactionId));
      const data = await resp.json();
      if (data.success && data.payment_status === 'success') {
        clearInterval(interval);
        showPaymentStatus(
          '<div style="text-align:center;">'
          + '<div style="font-size:3rem;margin-bottom:0.5rem;">✅</div>'
          + '<h3 style="margin:0 0 0.5rem;color:#1e293b;">Payment Successful!</h3>'
          + '<p style="color:#6b7280;font-size:0.9rem;">Your subscription has been activated. Refreshing...</p>'
          + '</div>',
          false
        );
        setTimeout(() => location.reload(), 2000);
      } else if (data.success && data.payment_status === 'failed') {
        clearInterval(interval);
        showPaymentStatus(
          '<div style="text-align:center;">'
          + '<div style="font-size:3rem;margin-bottom:0.5rem;">❌</div>'
          + '<h3 style="margin:0 0 0.5rem;color:#1e293b;">Payment Failed</h3>'
          + '<p style="color:#6b7280;font-size:0.9rem;">Please try again.</p>'
          + '<button onclick="location.reload()" style="padding:10px 24px;background:#e17055;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;margin-top:8px;">Try Again</button>'
          + '</div>',
          true
        );
      }
    } catch (e) {}
    if (attempts >= maxAttempts) {
      clearInterval(interval);
    }
  }, 5000);
}

async function simulatePaymentSuccess(transactionId) {
  showPaymentStatus(
    '<div style="text-align:center;">'
    + '<div style="font-size:3rem;margin-bottom:0.5rem;">⏳</div>'
    + '<h3 style="margin:0 0 0.5rem;color:#1e293b;">Activating subscription...</h3>'
    + '</div>',
    false
  );
  // Reload — the middleware checkSubscriptionOnLoad will pick up the
  // updated status from the callback. A forced reload is simplest & safest.
  setTimeout(() => location.reload(), 1500);
}

// Check subscription status on page load
async function checkSubscriptionOnLoad() {
  try {
    const resp = await fetch('../api/subscription_payment.php?action=getStatus');
    const data = await resp.json();
    if (!data.success) return;

    // Update trial info banner
    const trialInfo = document.getElementById('trialInfo');
    if (trialInfo) {
      if (data.status === 'trial' && data.days_left > 0 && data.days_left <= 7) {
        trialInfo.innerHTML = '⚠️ Your free trial ends in <strong>' + data.days_left + ' day(s)</strong>. <a href="#" onclick="showSubscriptionModal();return false;" style="color:#dc2626;text-decoration:underline;">Renew now</a>';
        trialInfo.style.display = 'block';
      } else if (data.status === 'expired' || data.status === 'disabled') {
        trialInfo.innerHTML = '🔒 Your subscription has expired. <a href="#" onclick="showSubscriptionModal();return false;" style="color:#dc2626;text-decoration:underline;">Renew now</a>';
        trialInfo.style.display = 'block';
        // Show renewal modal automatically after short delay
        setTimeout(() => showSubscriptionModal(), 1000);
      } else if (data.days_left >= 0) {
        trialInfo.innerHTML = '✅ Your subscription is active. <strong>' + data.days_left + ' day(s)</strong> remaining.';
        trialInfo.style.display = 'block';
      }
    }

    // Update profile subscription info
    const subStatusEl = document.getElementById('profileSubscriptionStatusBadge');
    const subStatusText = document.getElementById('profileSubscriptionStatusText');
    const renewalText = document.getElementById('profileRenewalDateText');
    const trialEndText = document.getElementById('profileTrialEndText');

    if (subStatusEl) {
      const statusMap = {
        'active': { text: '✓ Active', color: '#065f46', bg: '#d1fae5' },
        'trial': { text: '⏳ Trial', color: '#92400e', bg: '#fef3c7' },
        'expired': { text: '✗ Expired', color: '#991b1b', bg: '#fee2e2' },
        'disabled': { text: '✗ Disabled', color: '#6b7280', bg: '#f3f4f6' }
      };
      const info = statusMap[data.status] || { text: data.status, color: '#6b7280', bg: '#f3f4f6' };
      subStatusEl.textContent = info.text;
      subStatusEl.style.backgroundColor = info.bg;
      subStatusEl.style.color = info.color;
    }

    if (subStatusText) {
      const labels = { 'active': 'Active', 'trial': 'Free Trial', 'expired': 'Expired', 'disabled': 'Disabled' };
      subStatusText.textContent = labels[data.status] || data.status;
    }

    if (renewalText) {
      renewalText.textContent = data.renewal_date || data.trial_end_date || '--';
    }

    if (trialEndText) {
      trialEndText.textContent = data.trial_end_date || '--';
    }
  } catch (e) {
    console.log('Subscription check:', e.message);
  }
}

function showSubscriptionModal() {
  document.getElementById('renewalModal').style.display = 'flex';
}

// Check subscription on page load after dashboard stats load
window.addEventListener('DOMContentLoaded', function() {
  setTimeout(checkSubscriptionOnLoad, 2000);
});

// ===== PWA Diagnostics =====
var pwaDiagnostics = {};

function runPWADiagnostics() {
  pwaDiagnostics = {
    swSupported: 'serviceWorker' in navigator,
    swRegistered: pwaDiagnostics.swRegistered || false,
    swActive: !!navigator.serviceWorker.controller,
    manifestFound: !!document.querySelector('link[rel="manifest"]'),
    browser: (function(){
      var ua = navigator.userAgent;
      if (ua.indexOf('Edg/') !== -1 || ua.indexOf('Edge/') !== -1) return 'Edge';
      if (ua.indexOf('Chrome/') !== -1 && ua.indexOf('OPR/') === -1) return 'Chrome';
      if (ua.indexOf('OPR/') !== -1 || ua.indexOf('Opera/') !== -1) return 'Opera';
      if (ua.indexOf('Firefox/') !== -1) return 'Firefox';
      if (ua.indexOf('Safari/') !== -1 && ua.indexOf('Chrome/') === -1) return 'Safari';
      return 'Other';
    })(),
    isLocalhost: window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1' || window.location.hostname === '[::1]',
    isHTTPS: window.location.protocol === 'https:',
    deferredPromptReady: !!deferredPrompt,
    installPromptSupported: typeof BeforeInstallPromptEvent !== 'undefined',
    standalone: window.matchMedia('(display-mode: standalone)').matches || !!window.navigator.standalone
  };
  
  console.log('%c📋 PWA Diagnostics:', 'font-weight:bold;font-size:13px;');
  console.table(pwaDiagnostics);
  
  if (!pwaDiagnostics.browser.match(/chrome|edge|opera|samsung/i)) {
    console.warn('%c⚠️ PWA install is only fully supported in Chrome, Edge, Opera, or Samsung Internet.', 'font-size:12px;');
  }
  if (!pwaDiagnostics.isLocalhost && !pwaDiagnostics.isHTTPS) {
    console.warn('%c❌ PWA requires HTTPS (or localhost). You are on HTTP which blocks install.', 'font-size:12px;');
  }
}

// Run diagnostics after SW registration
window._pageLoadTime = Date.now();
setTimeout(runPWADiagnostics, 3000);

function detectBrowser() {
  var ua = navigator.userAgent;
  if (ua.indexOf('Edg/') !== -1 || ua.indexOf('Edge/') !== -1) return 'edge';
  if (ua.indexOf('Chrome/') !== -1 && ua.indexOf('OPR/') === -1 && ua.indexOf('Brave') === -1) return 'chrome';
  if (ua.indexOf('OPR/') !== -1 || ua.indexOf('Opera/') !== -1) return 'opera';
  if (ua.indexOf('Firefox/') !== -1) return 'firefox';
  if (ua.indexOf('Safari/') !== -1 && ua.indexOf('Chrome/') === -1) return 'safari';
  if (ua.indexOf('SamsungBrowser/') !== -1) return 'samsung';
  if (typeof ua === 'string' && ua.indexOf('Brave') !== -1) return 'brave';
  return 'other';
}

function showInstallGuide() {
  var browser = detectBrowser();
  var steps = '';
  var guideTitle = '';
  
  // Build diagnostic info
  var diag = '';
  var diagColor = '#10b981';
  var pageLoadedSec = Math.round((Date.now() - window._pageLoadTime) / 1000);
  
  if (!pwaDiagnostics.swRegistered) {
    if (pageLoadedSec < 5) {
      diag = '⏳ Service Worker still registering... Try again in a moment.';
      diagColor = '#f59e0b';
    } else {
      diag = '❌ Service Worker registration failed (F12 → Console for details)';
      diagColor = '#ef4444';
    }
  } else if (navigator.serviceWorker.controller === null) {
    diag = '⏳ SW registered but not yet active. Try refreshing the page.';
    diagColor = '#f59e0b';
  } else if (!pwaDiagnostics.deferredPromptReady) {
    diag = '✅ App ready! Look for the install icon ' + (browser === 'edge' ? '⬇' : '➕') + ' at the right side of your address bar, then click Install.';
    diagColor = '#10b981';
  }
  if (!pwaDiagnostics.isLocalhost && !pwaDiagnostics.isHTTPS) {
    diag = '❌ This site needs HTTPS for PWA. Use localhost or enable HTTPS (F12 → Console).';
    diagColor = '#ef4444';
  }
  
  if (browser === 'chrome' || browser === 'edge' || browser === 'brave') {
    var name = browser === 'edge' ? 'Edge' : browser === 'brave' ? 'Brave' : 'Chrome';
    steps = '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px;margin-bottom:14px;text-align:center">' +
            '<span style="font-size:32px">📲</span><br>' +
            '<b style="font-size:15px">Look in your address bar</b><br>' +
            '<span style="font-size:13px;color:#4b5563">' + name + ' shows an install icon on the <b>right side</b> of the address bar</span></div>' +
            '<div style="font-size:0.9rem;line-height:1.7">' +
            '1️⃣ <b>Look at the address bar</b> at the top of this window<br>' +
            '2️⃣ Find the <b>install icon</b> (looks like a '+(browser==='edge'?'⬇ download arrow':'➕ with a monitor')+')<br>' +
            '3️⃣ Click it and select <b>"Install"</b></div>';
    guideTitle = name + ' — Install Icon in Address Bar';
  } else if (browser === 'opera') {
    steps = '1️⃣ Click the <b>menu icon (≡)</b> on the top-left<br><br>' +
            '2️⃣ Scroll down and click <b>"Install App..."</b><br><br>' +
            '3️⃣ Click <b>"Install"</b> in the popup';
    guideTitle = 'Opera Browser';
  } else if (browser === 'firefox') {
    steps = '1️⃣ Firefox supports PWAs on <b>mobile only</b><br><br>' +
            '2️⃣ On Android: Open menu (⋮) → <b>"Install"</b><br><br>' +
            '3️⃣ On desktop: Use <b>Chrome or Edge</b> for full PWA support';
    guideTitle = 'Firefox Browser';
  } else if (browser === 'safari') {
    steps = '📱 <b>iPhone/iPad</b>:<br>' +
            '1️⃣ Tap the <b>Share icon (📤)</b> at the bottom<br>' +
            '2️⃣ Scroll down and tap <b>"Add to Home Screen"</b><br>' +
            '3️⃣ Tap <b>"Add"</b> (top-right)<br><br>' +
            '💻 <b>Mac</b>: Use Chrome or Edge for PWA support';
    guideTitle = 'Safari Browser';
  } else if (browser === 'samsung') {
    steps = '1️⃣ Tap the <b>menu icon (≡)</b> at the bottom<br><br>' +
            '2️⃣ Tap <b>"Add to Home screen"</b><br><br>' +
            '3️⃣ Tap <b>"Add"</b> to confirm';
    guideTitle = 'Samsung Internet';
  } else {
    steps = '1️⃣ Open your <b>browser menu</b> (⋮ or ☰ or ⚙️)<br><br>' +
            '2️⃣ Look for <b>"Install App"</b> or <b>"Add to Home Screen"</b><br><br>' +
            '3️⃣ Follow the prompts to install';
    guideTitle = 'Your Browser';
  }
  
  if (typeof Swal !== 'undefined') {
    Swal.fire({
      icon: diag.indexOf('❌') !== -1 ? 'error' : diag.indexOf('⏳') !== -1 ? 'warning' : 'info',
      title: guideTitle,
      html: '<div style="text-align:left;font-size:0.95rem;line-height:1.6">' + steps + '</div>' +
            '<div style="margin-top:16px;padding:10px 14px;background:'+diagColor+'15;border-radius:8px;border-left:4px solid '+diagColor+';font-size:0.85rem;color:#374151">🔍 <b>Diagnostic:</b> ' + diag + '</div>' +
            '<p style="margin-top:12px;font-size:0.78rem;color:#9ca3af">Press F12 → Console tab to see detailed diagnostics</p>',
      confirmButtonColor: '#e17055',
      confirmButtonText: 'Got it'
    });
  } else {
    alert('To install this app:\n\n' + steps.replace(/<[^>]*>/g, '') + '\n\nDiagnostic: ' + diag.replace(/<[^>]*>/g, ''));
  }
}

window._promptInstallFn = function() {
  // Refresh diagnostics first
  runPWADiagnostics();
  
  if (deferredPrompt) {
    console.log('%c🎯 Triggering native install prompt...', 'background:#059669;color:#fff;padding:4px 8px;border-radius:4px;font-size:13px;');
    _promptTriggered = true;
    deferredPrompt.prompt();
    deferredPrompt.userChoice.then(function(result) {
      console.log('%c   User ' + (result.outcome === 'accepted' ? 'accepted' : 'dismissed') + ' install prompt', 'font-size:12px;color:' + (result.outcome === 'accepted' ? '#10b981' : '#f59e0b'));
      deferredPrompt = null;
      _promptTriggered = false;
    });
    return;
  }
  
  if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) {
    showInstallMsg('This app is already installed on your device!', 'success', 'Already Installed');
    return;
  }
  
  // Check if page is installable (on HTTPS/localhost with valid manifest)
  if (!pwaDiagnostics.isLocalhost && !pwaDiagnostics.isHTTPS) {
    showInstallMsg('PWA install requires HTTPS (or localhost). You are on HTTP.', 'error', 'HTTPS Required');
    return;
  }
  
  if (!pwaDiagnostics.manifestFound) {
    showInstallMsg('Manifest not found. PWA install requires a valid manifest.', 'error', 'Missing Manifest');
    return;
  }
  
  // If SW is active but beforeinstallprompt didn't fire (common on desktop Chrome),
  // show the address bar guide immediately instead of waiting 12s
  if (navigator.serviceWorker.controller && !deferredPrompt) {
    console.log('%c📲 SW is active. Use the address bar install icon.', 'font-size:12px;color:#10b981;');
    showInstallGuide();
    return;
  }
  
  // One-time listener for when Chrome fires the event later
  if (!window._installPending) {
    window._installPending = true;
    console.log('%c⏳ Waiting for install prompt from browser...', 'font-size:12px;color:#f59e0b;');
    window.addEventListener('beforeinstallprompt', function(e) {
      e.preventDefault();
      if (!deferredPrompt) {
        deferredPrompt = e;
        pwaDiagnostics.deferredPromptReady = true;
        console.log('%c📲 Install prompt received! Triggering now...', 'background:#059669;color:#fff;padding:4px 8px;border-radius:4px;font-size:13px;');
        if (!_promptTriggered) {
          _promptTriggered = true;
          deferredPrompt.prompt();
          deferredPrompt.userChoice.then(function() { deferredPrompt = null; window._installPending = false; _promptTriggered = false; });
        }
      }
    }, { once: true });
    
    // Check periodically if prompt was received
    var checkInterval = setInterval(function() {
      if (deferredPrompt && !_promptTriggered) {
        clearInterval(checkInterval);
        _promptTriggered = true;
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function() { deferredPrompt = null; window._installPending = false; _promptTriggered = false; });
      }
    }, 1000);
    
    setTimeout(function() { 
      window._installPending = false; 
      clearInterval(checkInterval);
      if (!deferredPrompt) {
        runPWADiagnostics();
        showInstallGuide();
      }
    }, 12000);
  } else {
    showInstallGuide();
  }
};

// Listen for service worker controller change (SW takes control)
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.addEventListener('controllerchange', function() {
    console.log('%c✅ Service Worker now controlling this page!', 'background:#059669;color:#fff;padding:4px 8px;border-radius:4px;font-size:13px;');
    pwaDiagnostics.swActive = true;
    if (deferredPrompt) {
      console.log('%c📲 Install prompt is ready!', 'font-size:12px;color:#10b981;');
    } else {
      // SW just took control - browser may fire beforeinstallprompt soon
      console.log('%c⏳ Waiting for install prompt from browser...', 'font-size:12px;color:#f59e0b;');
    }
  });
}

// Register service worker — use version param to bypass stale SW cache
if ('serviceWorker' in navigator) {
  (function(){
    try {
      // Cache-busting version: bump this when sw.js changes
      var SW_VERSION = 6;
      var swUrl = '../website/sw.php?v=' + SW_VERSION;
      
      // Calculate proper scope from the service worker URL path
      // This ensures the scope covers the admin dashboard regardless of deployment path
      var swFullUrl = new URL(swUrl, window.location.href).href;
      var swPath = new URL(swFullUrl).pathname;
      
      // Scope: go up 2 levels from the SW file directory to cover both /website/ and /views/
      // e.g., sw at /menuwebsite/main/website/sw.php -> scope /menuwebsite/
      // e.g., sw at /main/website/sw.php -> scope /
      var scopeParts = swPath.replace(/\/[^\/]+$/, '').split('/'); // remove sw.php filename
      scopeParts.pop(); // remove SW directory segment (e.g., 'website')
      var scope = scopeParts.join('/') + '/';
      
      console.log('%c⏳ Registering Service Worker (v' + SW_VERSION + ')...', 'font-size:12px;color:#2563eb;');
      console.log('%c   URL: ' + swFullUrl, 'font-size:11px;color:#6b7280;');
      console.log('%c   Scope: ' + scope, 'font-size:11px;color:#6b7280;');
      
      // Check if SW file is reachable
      fetch(swUrl, { method: 'HEAD', cache: 'no-store' }).then(function(resp) {
        console.log('%c   HTTP ' + resp.status + ' ' + resp.statusText, 'font-size:11px;color:' + (resp.ok ? '#10b981' : '#ef4444'));
      }).catch(function(fetchErr) {
        console.error('%c   FETCH FAILED:', 'font-size:11px;color:#ef4444;', fetchErr);
      });
      
      // Unregister any OLD SW first (ones without our version param)
      navigator.serviceWorker.getRegistrations().then(function(regs) {
        var unregisterPromises = [];
        for (var ri = 0; ri < regs.length; ri++) {
          var regUrl = regs[ri].active && regs[ri].active.scriptURL || regs[ri].installing && regs[ri].installing.scriptURL || '';
          // Only unregister SWs without our version param (old ones)
          if (regUrl.indexOf('?v=' + SW_VERSION) === -1) {
            console.log('%c   Unregistering old SW: ' + regUrl, 'font-size:11px;color:#f59e0b;');
            unregisterPromises.push(regs[ri].unregister());
          }
        }
        return Promise.all(unregisterPromises);
      }).then(function() {
        // Now register the NEW SW with cache-busting version
        return navigator.serviceWorker.register(swUrl, { scope: scope });
      }).then(function(reg) {
        pwaDiagnostics.swRegistered = true;
        console.log('%c✅ Service Worker v' + SW_VERSION + ' registered!', 'background:#059669;color:#fff;padding:3px 6px;border-radius:3px;font-size:12px;');
        console.log('%c   Scope: ' + reg.scope, 'font-size:11px;color:#6b7280;');
        
        // Check if update is available
        reg.addEventListener('updatefound', function() {
          console.log('%c🔄 SW update found, installing...', 'font-size:12px;color:#2563eb;');
        });
        
        // Wait for the SW to become active and control this page
        if (navigator.serviceWorker.controller) {
          // Already active
          pwaDiagnostics.swActive = true;
          console.log('%c📲 SW is active. Page should be installable.', 'font-size:12px;color:#10b981;');
        } else {
          console.log('%c⏳ Waiting for SW to activate and take control...', 'font-size:12px;color:#f59e0b;');
          return navigator.serviceWorker.ready.then(function() {
            pwaDiagnostics.swActive = true;
            console.log('%c✅ SW is now active and controlling this page!', 'background:#059669;color:#fff;padding:3px 6px;border-radius:3px;font-size:12px;');
            
            // Reload page once so Chrome sees the SW was active from the start
            // This allows beforeinstallprompt to fire properly
            // Use sessionStorage so the flag persists across the reload
            if (!sessionStorage.getItem('pwa_refreshed')) {
              sessionStorage.setItem('pwa_refreshed', '1');
              console.log('%c🔄 Refreshing page so SW controls from the start...', 'font-size:12px;color:#2563eb;');
              setTimeout(function() {
                window.location.reload();
              }, 800);
            }
          });
        }
      }).catch(function(err) {
        console.error('%c❌ Service Worker registration FAILED:', 'font-size:12px;color:#ef4444;font-weight:bold;');
        console.error('   URL: ' + swFullUrl);
        console.error('   Error name:', err ? err.name : 'unknown');
        console.error('   Error message:', err ? err.message : 'unknown');
        console.error('   Full error:', err);
        
        // Retry once after 3 seconds
        setTimeout(function() {
          console.log('%c🔄 Retrying SW registration...', 'font-size:12px;color:#f59e0b;');
          navigator.serviceWorker.register(swUrl, { scope: scope }).then(function(reg) {
            pwaDiagnostics.swRegistered = true;
            pwaDiagnostics.swActive = !!navigator.serviceWorker.controller;
            console.log('%c✅ Service Worker v' + SW_VERSION + ' registered on retry!', 'background:#059669;color:#fff;padding:3px 6px;border-radius:3px;font-size:12px;');
          }).catch(function(retryErr) {
            console.error('%c❌ SW registration retry also FAILED:', 'font-size:12px;color:#ef4444;', retryErr);
          });
        }, 3000);
      });
    } catch(e) {
      console.error('%c❌ Service Worker threw sync error:', 'font-size:12px;color:#ef4444;font-weight:bold;', e);
    }
  })();
} else {
  console.warn('%c❌ Service Worker not supported in this browser', 'font-size:12px;color:#ef4444;font-weight:bold;');
}

// ===== TEMPORARY: Test Notification button — remove before production =====
// Runs the whole push pipeline (permission -> subscribe -> save -> send) from
// one click and reports exactly which step failed, so it doubles as a
// diagnostic tool instead of failing silently like the rest of the flow did.
function _pushVapidKeyToUint8Array(base64String) {
  var padding = '='.repeat((4 - base64String.length % 4) % 4);
  var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  var rawData = window.atob(base64);
  var outputArray = new Uint8Array(rawData.length);
  for (var i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}

async function testPushNotification(btn) {
  var iconHtml = '<span class="material-symbols-rounded" style="font-size:18px;">notifications_active</span> ';
  var originalText = 'Test Notification';
  function setStatus(text, color) {
    btn.innerHTML = iconHtml + text;
    btn.style.color = color || '#374151';
  }
  function resetLater() {
    setTimeout(function() { setStatus(originalText); }, 5000);
  }

  if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
    alert('This browser does not support push notifications (Safari on iOS needs the site added to the Home Screen first).');
    return;
  }

  btn.disabled = true;
  try {
    setStatus('Checking permission…');
    if (Notification.permission === 'denied') {
      alert('Notifications are blocked for this site. Enable them in your browser/site settings, then try again.');
      setStatus('Blocked', '#dc2626');
      resetLater();
      return;
    }
    if (Notification.permission !== 'granted') {
      var perm = await Notification.requestPermission();
      if (perm !== 'granted') {
        alert('Notification permission was not granted.');
        setStatus('Permission denied', '#dc2626');
        resetLater();
        return;
      }
    }

    setStatus('Getting subscription…');
    var reg = await navigator.serviceWorker.ready;
    var sub = await reg.pushManager.getSubscription();
    if (!sub) {
      var vapidKey = <?php echo json_encode(env('VAPID_PUBLIC_KEY', '')); ?>;
      if (!vapidKey) {
        alert('VAPID_PUBLIC_KEY is not configured on the server (.env) — push notifications cannot be set up at all until that\'s set.');
        setStatus('Server not configured', '#dc2626');
        resetLater();
        return;
      }
      setStatus('Subscribing…');
      sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: _pushVapidKeyToUint8Array(vapidKey)
      });
    }

    setStatus('Saving subscription…');
    var saveRes = await fetch('../api/save_push_subscription.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(sub.toJSON ? sub.toJSON() : sub)
    });
    var saveText = await saveRes.text();
    var saveData;
    try { saveData = JSON.parse(saveText); } catch (e) {
      alert('save_push_subscription.php did not return JSON (HTTP ' + saveRes.status + '):\n\n' + saveText.substring(0, 500));
      setStatus('Save step broken', '#dc2626');
      resetLater();
      return;
    }
    if (!saveData.success) {
      alert('Saving the subscription failed: ' + (saveData.message || 'unknown error'));
      setStatus('Save failed', '#dc2626');
      resetLater();
      return;
    }

    setStatus('Sending test push…');
    var sendRes = await fetch('../api/send_push_notification.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        title: '🔔 Test Notification',
        body: 'If you can see this, push notifications are working end to end!',
        url: '../views/dashboard.php'
      })
    });
    var sendText = await sendRes.text();
    var sendData;
    try { sendData = JSON.parse(sendText); } catch (e) {
      alert('send_push_notification.php did not return JSON (HTTP ' + sendRes.status + '):\n\n' + sendText.substring(0, 500));
      setStatus('Send step broken', '#dc2626');
      resetLater();
      return;
    }

    if (sendData.success && sendData.sent > 0) {
      setStatus('Sent! Watch for it', '#059669');
    } else {
      var reason = (sendData.failure_reasons && sendData.failure_reasons.length)
        ? sendData.failure_reasons.join('; ')
        : (sendData.message || 'server reported 0 sent, 0 failed — check total_subscribers');
      alert('Push did not deliver.\nSent: ' + (sendData.sent || 0) + '  Failed: ' + (sendData.failed || 0) + '  Subscribers: ' + (sendData.total_subscribers ?? '?') + '\nReason: ' + reason);
      setStatus('Not delivered', '#dc2626');
    }
    resetLater();
  } catch (err) {
    console.error('Test notification error:', err);
    alert('Error testing notification: ' + err.message);
    setStatus('Error — see console', '#dc2626');
    resetLater();
  } finally {
    btn.disabled = false;
  }
}
window.testPushNotification = testPushNotification;
</script>

<!-- New Order Notification Overlay -->
<div id="newOrderOverlay">
  <div class="new-order-card">
    <div class="new-order-header">
      <div class="bell-icon">
        <span class="material-symbols-rounded">notifications_active</span>
      </div>
      <div>
        <h2 id="notifOrderTitle">New Order Received!</h2>
        <div class="sub-text" id="notifOrderNumber">Order #---</div>
      </div>
      <button class="close-overlay" onclick="closeNewOrderOverlay()" title="Dismiss">
        <span class="material-symbols-rounded" style="font-size:18px;">close</span>
      </button>
    </div>
    <div class="new-order-body">
      <div class="order-detail-row">
        <span class="label">Customer</span>
        <span class="value" id="notifCustomerName">---</span>
      </div>
      <div class="order-detail-row">
        <span class="label">Phone</span>
        <span class="value" id="notifCustomerPhone">---</span>
      </div>
      <div class="order-detail-row" id="notifAddressRow">
        <span class="label">Address</span>
        <span class="value" id="notifCustomerAddress">---</span>
      </div>
      <div class="order-detail-row" id="notifLandmarkRow" style="display:none;">
        <span class="label">Landmark</span>
        <span class="value" id="notifLandmark">---</span>
      </div>
      <div id="notifMapContainer"></div>
      <div class="order-detail-row">
        <span class="label">Order Type</span>
        <span class="value" id="notifOrderType">---</span>
      </div>
      <div class="order-detail-row">
        <span class="label">Payment</span>
        <span class="value" id="notifPaymentInfo">---</span>
      </div>
      <div id="notifPaymentProofContainer"></div>
      <div id="notifItemsContainer" class="order-items-list">
        <div class="items-title">Order Items</div>
        <div id="notifItemsList"></div>
      </div>
      <div class="order-detail-row">
        <span class="label">Total Amount</span>
        <span class="value highlight" id="notifTotalAmount">---</span>
      </div>
      <div class="new-order-time" id="notifOrderTime"></div>
    </div>
    <div class="new-order-footer">
      <button class="btn-accept-order" id="notifAcceptBtn" onclick="acceptNewOrder()">
        <span class="material-symbols-rounded" style="font-size:18px;">check_circle</span>
        Accept
      </button>
      <button class="btn-reject-order" id="notifRejectBtn" onclick="rejectNewOrder()">
        <span class="material-symbols-rounded" style="font-size:18px;">cancel</span>
        Reject
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     ERROR MONITOR — JavaScript Functions
     ═══════════════════════════════════════════════════════════════ -->
<script>
</html>
