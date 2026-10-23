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

// Delivery riders have their own screen (and no dashboard permissions)
if (isDeliverySession()) {
    header('Location: rider_dashboard.php');
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
        case 'Cashier':
            header('Location: manager_dashboard.php');
            exit();
        // Admin staff can access main dashboard, so no redirect needed
    }
}

// "We deliver with our own riders" (Settings › Delivery): Riders, the rider staff role and Assign rider only show when on
$ownRiders = false;
try {
    require_once __DIR__ . '/../db_connection.php';
    require_once __DIR__ . '/../config/rider_engine.php';
    $ownRiders = riderOwnRidersOn(getConnection(), (string)($_SESSION['restaurant_id'] ?? ''));
} catch (Throwable $e) { $ownRiders = false; }

// Load restaurant info from database to prevent flash of default content
$restaurant_name = $_SESSION['restaurant_name'] ?? 'Restaurant Name';
$restaurant_id = $_SESSION['restaurant_id'] ?? 'RES001';
$restaurant_logo = '../assets/images/logo-192.png'; // Default fallback
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
$meal_subscriptions_enabled = 0;
$show_business_id = 0;
$business_id_label = '';
$business_id_no = '';
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
        require_once __DIR__ . '/../config/pan_helpers.php';
        ensurePanColumns($conn);
        require_once __DIR__ . '/../config/gstin_helpers.php';
        ensureGstinColumns($conn);
        require_once __DIR__ . '/../config/business_id_helpers.php';
        ensureBusinessIdColumns($conn);
        $stmt = $conn->prepare("SELECT id, restaurant_logo, currency_symbol, country, tax_name, tax_percent, timezone, language, email, phone, address, role, payment_gateway_type, phonepe_merchant_id, phonepe_salt_key, phonepe_environment, enable_gst, enable_delivery, enable_takeaway, enable_dinein, cod_enabled, photo_gallery_enabled, enable_language, payment_gateway_mode, custom_domain, embed_enabled, show_business_id, business_id_label, business_id_no FROM users WHERE " . (isset($_SESSION['user_id']) ? "id = ?" : "restaurant_id = ?") . " LIMIT 1");
        // Branch admins / staff have no user_id — load the current restaurant's row instead
        $stmt->execute([$_SESSION['user_id'] ?? $restaurant_id]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($userRow) {
            // Payment gateway credentials for pre-filling forms
            // Cashfree removed
            $phonepe_merchant_id = $userRow['phonepe_merchant_id'] ?? '';
            // the secret itself never reaches the page: only whether one is saved and its last 4 characters
            require_once __DIR__ . '/../config/phonepe_verify.php';
            $phonepe_salt_key = ppMask(ppSecretOpen($userRow['phonepe_salt_key'] ?? ''));
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
            $show_business_id = isset($userRow['show_business_id']) ? (int)$userRow['show_business_id'] : 0;
            $business_id_label = (!empty($userRow['business_id_label'])) ? $userRow['business_id_label'] : defaultBusinessIdLabel($userRow['country'] ?? $country);
            $business_id_no = $userRow['business_id_no'] ?? '';
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
            $stmt = $conn->prepare("SELECT id, restaurant_logo, currency_symbol FROM users WHERE " . (isset($_SESSION['user_id']) ? "id = ?" : "restaurant_id = ?") . " LIMIT 1");
            $stmt->execute([$_SESSION['user_id'] ?? $restaurant_id]);
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
            $restaurant_logo = '../assets/images/logo-192.png';
        }
    }
} catch (Exception $e) {
    // If database query fails, use defaults
            $restaurant_logo = '../assets/images/logo-192.png';
}

// Independent of everything above (which is all keyed off id = $_SESSION
// ['user_id'] and silently no-ops for staff/branch-admin logins, since
// only the owner login sets that session var - see the three-way login
// check at the top of this file). restaurant_id is the one session var
// guaranteed set for all three login types, and is exactly what the
// superadmin toggle is scoped to, so this is checked on its own rather
// than nested inside the user_id-dependent block above.
try {
    $msStmt = $conn->prepare("SELECT meal_subscriptions_enabled FROM users WHERE restaurant_id = ? LIMIT 1");
    $msStmt->execute([$_SESSION['restaurant_id']]);
    $meal_subscriptions_enabled = (int)($msStmt->fetchColumn() ?: 0);
} catch (Exception $e) {
    $meal_subscriptions_enabled = 0;
}

// Sections Super Admin switched off for this restaurant (Restaurants › Access): hidden below and blocked in showPage()
require_once __DIR__ . '/../config/access_control.php';
try { $accessOff = accessOffPages(getConnection(), (string)$_SESSION['restaurant_id']); } catch (Throwable $e) { $accessOff = []; }
?>
<?php $googleMapsApiKey = function_exists('googleMapsApiKey') ? googleMapsApiKey() : ''; ?>
<!DOCTYPE html>
<!-- Coding By CodingNepal - youtube.com/@codingnepal -->
<html lang="en">
<head>
  <meta charset="UTF-8">
<?php require_once __DIR__ . '/../config/sentry_browser.php'; echo rgSentryBrowserTag('dashboard'); ?>
  <script src="../assets/js/quiet-console.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/quiet-console.js'); ?>"></script>
  <script>
  // Many parts of the dashboard ask get_session.php for the restaurant details as the page opens. While one
  // such request is still on its way, the others share its answer instead of sending their own.
  (function () {
    var realFetch = window.fetch, inflight = null;
    window.fetch = function (url, opts) {
      var m = opts && opts.method ? String(opts.method).toUpperCase() : 'GET';
      if (typeof url === 'string' && m === 'GET' && /(^|\/)admin\/get_session\.php(\?|$)/.test(url)) {
        if (!inflight) {
          inflight = realFetch.apply(window, arguments);
          inflight.then(function () { inflight = null; }, function () { inflight = null; });
        }
        return inflight.then(function (r) { return r.clone(); });
      }
      return realFetch.apply(window, arguments);
    };
  })();
  </script>
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
  <link rel="icon" type="image/png" href="../assets/images/logo-192.png">
  <link rel="apple-touch-icon" href="../assets/images/logo-192.png">
  <meta name="theme-color" content="#1a3934">
  <meta name="mobile-web-app-capable" content="yes">
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
  <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
  <link rel="stylesheet" href="../assets/css/studio-ui.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/studio-ui.css'); ?>">
  <!-- Navigation style (Settings › Navigation style): sidebar, bottom dock or round menu — assets/js/nav-style.js -->
  <link rel="stylesheet" href="../assets/css/nav-style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/nav-style.css'); ?>">
  <script>try { var rgNs = localStorage.getItem('rgNavStyle'); if (rgNs === 'dock' || rgNs === 'radial') document.documentElement.classList.add(rgNs === 'dock' ? 'rg-nav-dock' : 'rg-nav-radial'); } catch (e) {}</script>
  <link rel="stylesheet" href="../assets/css/rg-stats.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/rg-stats.css'); ?>">
  <!-- phones and small tablets: loaded last so it only corrects narrow screens; tables become cards -->
  <link rel="stylesheet" href="../assets/css/mobile.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/mobile.css'); ?>">
  <script src="../assets/js/mobile-tables.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/mobile-tables.js'); ?>" defer></script>
  <!-- Font Awesome: the bottom-bar icon style previews in Website Appearance (loaded without blocking the page) -->
  <link rel="stylesheet" href="../assets/fonts/fontawesome/css/all.min.css" media="print" onload="this.media='all'">
  
  <!-- Optimized Font Loading -->
  <link rel="preload" href="<?php require_once __DIR__ . '/../config/icon_font.php'; echo htmlspecialchars(rgIconFontUrl(), ENT_QUOTES); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="<?php require_once __DIR__ . '/../config/icon_font.php'; echo htmlspecialchars(rgIconFontUrl(), ENT_QUOTES); ?>"></noscript>

  <!-- Poster generator fonts (Marketing page) -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&family=Playfair+Display:wght@700;800&family=Bebas+Neue&display=swap">
  
  <!-- Cropper.js for image cropping (local files to avoid tracking prevention blocking) -->
  <link rel="stylesheet" href="../assets/libs/cropperjs/cropper.min.css">
  
  <!-- Scripts - Defer non-critical -->
  <script src="../assets/js/sweetalert2.all.min.js" defer></script>
  <!-- toasts, "Saving…" → ✓, popups: website/rg-notify.js (shared with the waiter screen and the customer website) -->
  <script src="../website/rg-notify.js?v=<?php echo @filemtime(__DIR__ . '/../website/rg-notify.js'); ?>"></script>
  <script>RGNotify.setup({ toast: 'card', watchSaves: true });</script>
  <script src="../assets/libs/cropperjs/cropper.min.js" defer></script>
  <!-- jsPDF for client-side PDF report export (local files, same reasoning as Cropper.js above) -->
  <script src="../assets/libs/jspdf/jspdf.umd.min.js" defer></script>
  <script src="../assets/libs/jspdf/jspdf.plugin.autotable.min.js" defer></script>
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
    window.restaurantName = <?php echo json_encode($restaurant_name, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    window.restaurantLogoUrl = <?php echo json_encode($restaurant_logo, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    window.restaurantPhone = <?php echo json_encode($user_phone, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
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
    window.showBusinessId = <?php echo json_encode((bool)$show_business_id, JSON_HEX_TAG); ?>;
    window.businessIdLabel = <?php echo json_encode($business_id_label, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.businessIdNo = <?php echo json_encode($business_id_no, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
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
        container.innerHTML = '<iframe src="../admin/addons.php?embed=1" style="width:100%;height:700px;border:none;border-radius:8px;overflow:auto;"></iframe>';
      }
    }

    // Load Blocked Customers (order abuse blocklist) page via iframe.
    // Re-set src on every visit so unblocks/blocks made elsewhere show fresh.
    function loadAdminBlocklist() {
      var container = document.getElementById('blocklistContainer');
      if (!container) return;
      var frame = container.querySelector('iframe');
      if (frame) { frame.src = '../admin/blocklist.php'; return; }
      container.innerHTML = '<iframe src="../admin/blocklist.php" style="width:100%;height:700px;border:none;border-radius:8px;overflow:auto;"></iframe>';
    }

    // Load Meal Plans (tiffin subscription) management page via iframe
    function loadAdminMealPlans() {
      var container = document.getElementById('mealPlansList');
      if (!container) return;
      if (!container.querySelector('iframe')) {
        container.innerHTML = '<iframe src="../admin/meal_plans.php" style="width:100%;height:700px;border:none;border-radius:8px;overflow:auto;"></iframe>';
      }
    }

    // Load Meal Subscriptions (customer subscribers) management page via iframe
    function loadAdminMealSubscriptions() {
      var container = document.getElementById('mealSubscriptionsList');
      if (!container) return;
      if (!container.querySelector('iframe')) {
        container.innerHTML = '<iframe src="../admin/meal_subscriptions.php" style="width:100%;height:700px;border:none;border-radius:8px;overflow:auto;"></iframe>';
      }
    }

    // Load Catering (bulk/event orders) management page via iframe
    function loadAdminCatering() {
      var container = document.getElementById('cateringList');
      if (!container) return;
      if (!container.querySelector('iframe')) {
        container.innerHTML = '<iframe src="../admin/catering.php?embed=1" title="Catering" style="width:100%;height:700px;border:none;overflow:auto;background:transparent;"></iframe>';
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
  <?php require_once __DIR__ . '/../config/nav_animated_icons.php'; ?>
  <style><?php echo navAnimatedSvgCss(); ?>
  .nis-tile .nav-svg { width: 17px; height: 17px; display: inline-block; flex-shrink: 0; }
  .nis-tile .nis-ic { gap: 3px !important; flex-wrap: nowrap; }
  .nis-badge { display: inline-block; margin-left: 4px; padding: 1px 5px; border-radius: 999px; background: #ede9fe; color: #6d28d9; font-size: 9px; font-weight: 800; vertical-align: 1px; }</style>
  <script>window.NAV_ANIM_SVGS = <?php require_once __DIR__ . '/../config/nav_icon_packs.php'; $navAnimAll = ['line' => navAnimatedSvgAll('line'), 'flat' => navAnimatedSvgAll('flat')]; foreach (navIconPackNames() as $pk) $navAnimAll[$pk] = navAnimatedSvgAll($pk); echo json_encode($navAnimAll, JSON_HEX_TAG | JSON_HEX_AMP); ?>;</script>
</head>
<body>
<?php require __DIR__ . '/../includes/rg_boot_loader.php'; // Bouncing G loading screen ?>
  <aside class="sidebar">
    <!-- Sidebar header -->
    <header class="sidebar-header">
      <a href="#" class="header-logo">
        <img id="dashboardRestaurantLogo" src="<?php echo htmlspecialchars($restaurant_logo) . (strpos($restaurant_logo, '?') !== false ? '&' : '?') . 't=' . (time() . '_' . mt_rand(1000, 9999)); ?>" alt="Restro Grow Logo" onerror="this.src='../assets/images/logo-192.png'; this.style.borderRadius='50%'; this.style.objectFit='cover';" style="border-radius: 50%; object-fit: cover; width: 46px; height: 46px;">
        <div class="restaurant-info">
          <div class="restaurant-name" id="restaurantName"><?php echo htmlspecialchars($restaurant_name); ?></div>
          <div class="restaurant-id" id="restaurantId"><?php echo htmlspecialchars($restaurant_id); ?></div>
        </div>
      </a>
      <button class="toggler sidebar-toggler">
        <span class="material-symbols-rounded">chevron_left</span>
      </button>
      <button class="toggler menu-toggler">
        <span class="material-symbols-rounded">menu</span>
      </button>
    </header>

    <!-- Branch switcher (multi-branch logins only — shown by initRestaurantSwitcher) -->
    <div id="restaurantSwitcherWrapper" class="branch-switcher" style="display:none;">
      <label class="branch-switcher-label" for="restaurantSwitcher">Current branch</label>
      <div class="branch-switcher-select">
        <span class="material-symbols-rounded branch-switcher-icon">storefront</span>
        <select id="restaurantSwitcher">
          <option value="" disabled>Switch branch…</option>
        </select>
        <span class="material-symbols-rounded branch-switcher-chevron">expand_more</span>
      </div>
      <a href="branches.php" class="branch-switcher-all">
        <span class="material-symbols-rounded">grid_view</span>All branches
      </a>
    </div>

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
              <a href="#" class="nav-link submenu-link" data-page="addonsPage">
                <span class="nav-icon material-symbols-rounded">playlist_add</span>
                <span class="nav-label">Add-ons</span>
              </a>
              <span class="nav-tooltip">Add-ons</span>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="inventoryPage">
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
        <!-- Subscriptions Menu with Submenus - only for restaurants a
             superadmin has turned this feature on for (users.meal_subscriptions_enabled) -->
        <?php if ($meal_subscriptions_enabled): ?>
        <li class="nav-item has-submenu">
          <a href="#" class="nav-link submenu-toggle">
            <span class="nav-icon material-symbols-rounded">event_repeat</span>
            <span class="nav-label">Subscriptions</span>
            <span class="submenu-arrow material-symbols-rounded">chevron_right</span>
          </a>
          <span class="nav-tooltip">Subscriptions</span>
          <ul class="submenu">
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="mealPlansPage">
                <span class="nav-icon material-symbols-rounded">event_repeat</span>
                <span class="nav-label">Meal Plans</span>
              </a>
              <span class="nav-tooltip">Meal Plans</span>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="mealSubscriptionsPage">
                <span class="nav-icon material-symbols-rounded">group</span>
                <span class="nav-label">Subscribers</span>
              </a>
              <span class="nav-tooltip">Subscribers</span>
            </li>
          </ul>
        </li>
        <?php endif; ?>
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
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="blocklistPage">
                <span class="nav-icon material-symbols-rounded">block</span>
                <span class="nav-label">Blocked Customers</span>
              </a>
              <span class="nav-tooltip">Block fake-order numbers/IPs</span>
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
          <a href="#" class="nav-link" data-page="googleBusinessPage">
            <span class="nav-icon material-symbols-rounded">storefront</span>
            <span class="nav-label">Google Business <span class="badge-soon">Soon</span></span>
          </a>
          <span class="nav-tooltip">Google Business</span>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link" data-page="seoPage">
            <span class="nav-icon material-symbols-rounded">search</span>
            <span class="nav-label">SEO <span class="badge-new">New</span></span>
          </a>
          <span class="nav-tooltip">SEO</span>
        </li>
        <li class="nav-item has-submenu">
          <a href="#" class="nav-link submenu-toggle">
            <span class="nav-icon material-symbols-rounded">campaign</span>
            <span class="nav-label">Marketing</span>
            <span class="submenu-arrow material-symbols-rounded">chevron_right</span>
          </a>
          <span class="nav-tooltip">Marketing</span>
          <ul class="submenu">
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="marketingPostersPage">
                <span class="nav-icon material-symbols-rounded">imagesmode</span>
                <span class="nav-label">Posters</span>
              </a>
              <span class="nav-tooltip">Posters</span>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="marketingVideosPage">
                <span class="nav-icon material-symbols-rounded">movie</span>
                <span class="nav-label">Videos</span>
              </a>
              <span class="nav-tooltip">Videos</span>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="marketingCaptionsPage">
                <span class="nav-icon material-symbols-rounded">chat_bubble</span>
                <span class="nav-label">Social Captions</span>
              </a>
              <span class="nav-tooltip">Social Captions</span>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="marketingCalendarPage">
                <span class="nav-icon material-symbols-rounded">event</span>
                <span class="nav-label">Campaign Calendar</span>
              </a>
              <span class="nav-tooltip">Campaign Calendar</span>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="marketingDesignsPage">
                <span class="nav-icon material-symbols-rounded">folder</span>
                <span class="nav-label">My campaigns</span>
              </a>
              <span class="nav-tooltip">My campaigns</span>
            </li>
          </ul>
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
              <a href="#" class="nav-link submenu-link" data-page="offersHomePage">
                <span class="material-symbols-rounded">dashboard</span>
                <span class="nav-label">All offers</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="couponsPage">
                <span class="material-symbols-rounded">confirmation_number</span>
                <span class="nav-label">Coupons</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="offerFiltersPage">
                <span class="material-symbols-rounded">filter_alt</span>
                <span class="nav-label">Offer filters</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="dealsPage">
                <span class="material-symbols-rounded">sell</span>
                <span class="nav-label">Combos &amp; Deals</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="bestsellersPage">
                <span class="material-symbols-rounded">star</span>
                <span class="nav-label">Bestsellers</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="todaysSpecialPage">
                <span class="material-symbols-rounded">local_fire_department</span>
                <span class="nav-label">Today's Special</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="upsellPage">
                <span class="material-symbols-rounded">add_shopping_cart</span>
                <span class="nav-label">Complete your meal</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="scratchPage">
                <span class="material-symbols-rounded">redeem</span>
                <span class="nav-label">Scratch card</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="walletPage">
                <span class="material-symbols-rounded">account_balance_wallet</span>
                <span class="nav-label">Wallet</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="cateringPage">
                <span class="material-symbols-rounded">celebration</span>
                <span class="nav-label">Catering</span>
              </a>
            </li>
          </ul>
        </li>
        <li class="nav-item has-submenu">
          <a href="#" class="nav-link submenu-toggle">
            <span class="nav-icon material-symbols-rounded">redeem</span>
            <span class="nav-label">Growth</span>
            <span class="submenu-arrow material-symbols-rounded">chevron_right</span>
          </a>
          <span class="nav-tooltip">Growth</span>
          <ul class="submenu">
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="growthSettingsPage">
                <span class="material-symbols-rounded">loyalty</span>
                <span class="nav-label">Loyalty Studio</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="whatsappPage">
                <span class="material-symbols-rounded">chat</span>
                <span class="nav-label">WhatsApp</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="waOrderPage">
                <span class="material-symbols-rounded">shopping_bag</span>
                <span class="nav-label">WhatsApp ordering</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="ownerManagerPage">
                <span class="material-symbols-rounded">auto_awesome</span>
                <span class="nav-label">AI manager</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="aiAppsPage">
                <span class="material-symbols-rounded">add_link</span>
                <span class="nav-label">ChatGPT &amp; Claude</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="customerSegmentsPage">
                <span class="material-symbols-rounded">groups</span>
                <span class="nav-label">Customer Segments</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="referralsPage">
                <span class="material-symbols-rounded">group_add</span>
                <span class="nav-label">Referrals</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="growthReportsPage">
                <span class="material-symbols-rounded">insights</span>
                <span class="nav-label">Reports</span>
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
              <a href="#" class="nav-link submenu-link" data-page="deliveryPage">
                <span class="material-symbols-rounded">pin_drop</span>
                <span class="nav-label">Zones</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="#" class="nav-link submenu-link" data-page="deliveryMapPage">
                <span class="material-symbols-rounded">map</span>
                <span class="nav-label">Map View</span>
              </a>
            </li>
            <li class="nav-item" id="ridersNavItem"<?php echo $ownRiders ? '' : ' style="display:none"'; ?>>
              <a href="#" class="nav-link submenu-link" data-page="ridersPage">
                <span class="material-symbols-rounded">two_wheeler</span>
                <span class="nav-label">Riders</span>
              </a>
            </li>
          </ul>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link" data-page="feedbackPage">
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
          <a href="#" class="nav-link" data-page="policyPagesPage">
            <span class="nav-icon material-symbols-rounded">gavel</span>
            <span class="nav-label">Policy Pages</span>
          </a>
          <span class="nav-tooltip">Policy Pages</span>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link" data-page="embedPage">
            <span class="nav-icon material-symbols-rounded">language</span>
            <span class="nav-label">Custom Domain</span>
          </a>
          <span class="nav-tooltip">Custom Domain</span>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link" data-page="howToPage" onclick="openHowTo(document.querySelector('.page.active'))">
            <span class="nav-icon material-symbols-rounded">help</span>
            <span class="nav-label">How to use</span>
          </a>
          <span class="nav-tooltip">How to use</span>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link" id="pnSupportLink" onclick="return rgOpenSupport(event)">
            <span class="nav-icon material-symbols-rounded">support_agent</span>
            <span class="nav-label">Help &amp; support</span>
          </a>
          <span class="nav-tooltip">Help &amp; support</span>
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
  <?php if ($accessOff): ?>
  <style><?php foreach ($accessOff as $p) echo '[data-dh-go="' . htmlspecialchars($p, ENT_QUOTES) . '"],'; ?>.rg-access-none{display:none!important}</style>
  <script>
    // switched off by Super Admin: drop those links, then any menu group left empty
    window.RG_ACCESS_OFF = <?php echo json_encode($accessOff); ?>;
    (function () {
      RG_ACCESS_OFF.forEach(function (p) {
        document.querySelectorAll('.sidebar [data-page="' + p + '"]').forEach(function (a) { var li = a.closest('li'); (li || a).remove(); });
      });
      document.querySelectorAll('.sidebar li.has-submenu').forEach(function (g) { if (!g.querySelector('.submenu [data-page]')) g.remove(); });
    })();
  </script>
  <?php endif; ?>

  <!-- Main Content Area -->
  <main class="main-content">
    <!-- Dashboard Page (Default) -->
    <!-- Dashboard home (assets/js/dashboard-home.js, data: api/dashboard_home.php, styles dh-* in studio-ui.css) -->
    <div id="dashboardPage" class="page active sx dh">
      <div class="dh-head">
        <div class="dh-head-l">
          <span class="dh-hello" id="dhHello">Welcome back</span>
          <h1 id="dhName"><?php echo htmlspecialchars($restaurant_name, ENT_QUOTES, 'UTF-8'); ?></h1>
          <div class="dh-meta">
            <span id="dhDate"></span>
            <span class="dh-store" id="dhStore" hidden></span>
            <span class="dh-plan" id="trialInfo" style="display:none;"></span>
          </div>
        </div>
        <div class="dh-head-r">
          <span class="dh-updated" id="dashboardTime"></span>
          <button type="button" class="btn btn-secondary dh-icon" onclick="loadDashboardStats(true)" title="Refresh" aria-label="Refresh"><span class="material-symbols-rounded">refresh</span></button>
          <a class="btn btn-secondary" href="<?php
            if (!empty($restaurant_custom_domain) && $restaurant_embed_enabled) {
                echo htmlspecialchars('https://' . preg_replace('#^https?://#', '', $restaurant_custom_domain) . '/', ENT_QUOTES, 'UTF-8');
            } else {
                echo htmlspecialchars($basePath . '/' . urlencode(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($restaurant_name)), '-')), ENT_QUOTES, 'UTF-8');
            }
          ?>" target="_blank" rel="noopener"><span class="material-symbols-rounded">storefront</span><span class="dh-hide-s">Your website</span></a>
          <button type="button" class="btn btn-primary" data-dh-go="posPage"><span class="material-symbols-rounded">add</span>New order</button>
        </div>
      </div>
      <div class="page-content dh-body">
        <div id="dhRoot"><div class="sx-loading"><span></span><span></span><span></span></div></div>
      </div>
    </div>

    <!-- Website Theme Page -->
    <div id="websiteThemePage" class="page sx">
      <div class="page-header">
        <h1>Website Appearance</h1>
        <p>How your website looks: colours, fonts, layouts and banners. The preview shows every change before you save.</p>
      </div>
      <div class="page-content">
        <div class="settings-grid">
          <div class="settings-section">
            <nav class="wt-jump" aria-label="Jump to">
              <button type="button" data-wt-jump="wtg-brand"><span class="material-symbols-rounded">storefront</span>Brand</button>
              <button type="button" data-wt-jump="wtg-colours"><span class="material-symbols-rounded">palette</span>Colours and font</button>
              <button type="button" data-wt-jump="wtg-home"><span class="material-symbols-rounded">home</span>Home page</button>
              <button type="button" data-wt-jump="wtg-menu"><span class="material-symbols-rounded">restaurant_menu</span>Menu page</button>
              <button type="button" data-wt-jump="wtg-nav"><span class="material-symbols-rounded">apps</span>Navigation and profile</button>
              <button type="button" data-wt-jump="wtg-banners"><span class="material-symbols-rounded">campaign</span>Banners and sharing</button>
            </nav>

            <section class="wt-group" id="wtg-brand">
              <div class="wt-group-h"><span class="material-symbols-rounded">storefront</span><div><h3>Brand</h3><p>Your name, logo, browser icon and the top of your home page.</p></div></div>
<!-- Website Name -->
            <div class="wt-card">
              <div class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#dc2626">badge</span>
                Website Display Name
              </div>
              <p class="wt-desc">The name shown to customers on your website. Leave blank to keep using your account name.</p>
              <input type="text" id="siteNameInput" maxlength="191" placeholder="<?php echo htmlspecialchars($restaurant_name, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; padding: 0.65rem 0.85rem; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.95rem; background: #fff;">
            </div>
<!-- Logo Shape & Size Settings -->
            <div class="wt-card">
              <div class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#dc2626">crop</span>
                Logo Style
              </div>
              <p class="wt-desc">Customize how your restaurant logo appears on the website.</p>
              
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
<!-- Favicon -->
            <div class="wt-card">
              <div class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#dc2626">tab</span>
                Browser Tab Icon (Favicon)
              </div>
              <p class="wt-desc">Paste a direct image URL to use as your website's browser-tab icon. Leave blank to use your uploaded logo.</p>
              <div style="display:flex; align-items:center; gap:10px;">
                <img id="faviconPreviewImg" src="" alt="" style="width:36px;height:36px;border-radius:6px;border:2px solid #e5e7eb;object-fit:cover;display:none;background:#fff;">
                <input type="text" id="faviconUrlInput" maxlength="500" placeholder="https://example.com/favicon.png" style="flex:1; padding: 0.65rem 0.85rem; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.95rem; background: #fff;">
              </div>
            </div>
<!-- Header Style: hero banner vs a compact minimal bar -->
            <div class="wt-card">
              <div class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#7c3aed">web_asset</span>
                Header Style
              </div>
              <p class="wt-desc">The top of your home page: your photo, logo, name, today's hours and rating. Photo styles use your Background Image; the rating shows once you have 3 or more customer reviews.</p>
              <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 8px;">
                <label class="header-style-opt" data-value="hero" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #7c3aed;border-radius:10px;background:#fff;">
                  <input type="radio" name="headerStyle" value="hero" checked style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:44px;border-radius:6px;overflow:hidden;background:linear-gradient(135deg,#94a3b8,#475569);"><i style="position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.6);left:28px;top:8px;"></i><i style="position:absolute;height:3px;border-radius:2px;left:16px;right:16px;top:28px;background:#fff;"></i><i style="position:absolute;height:3px;border-radius:2px;left:22px;right:22px;top:35px;background:rgba(255,255,255,.6);"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Photo banner</span>
                </label>
                <label class="header-style-opt" data-value="minimal" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="headerStyle" value="minimal" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:44px;border-radius:6px;overflow:hidden;background:#fff;"><i style="position:absolute;left:0;right:0;top:0;height:18px;background:#dc2626;"></i><i style="position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.6);left:4px;top:2px;"></i><i style="position:absolute;height:3px;border-radius:2px;left:22px;width:26px;top:8px;background:#fff;"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Minimal bar</span>
                </label>
                <label class="header-style-opt" data-value="glass" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="headerStyle" value="glass" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:44px;border-radius:6px;overflow:hidden;background:linear-gradient(135deg,#94a3b8,#475569);"><i style="position:absolute;left:6px;right:6px;bottom:4px;height:24px;border-radius:6px;background:rgba(15,15,20,.55);border:1px solid rgba(255,255,255,.3);"></i><i style="position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.6);left:28px;top:12px;border-radius:4px;"></i><i style="position:absolute;height:3px;border-radius:2px;left:18px;right:18px;bottom:16px;background:#fff;"></i><i style="position:absolute;height:3px;border-radius:2px;left:24px;width:10px;bottom:9px;background:#22c55e;"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Glass card</span>
                </label>
                <label class="header-style-opt" data-value="cover" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="headerStyle" value="cover" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:44px;border-radius:6px;overflow:hidden;background:linear-gradient(180deg,#94a3b8,#1f2937);"><i style="position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.6);left:5px;bottom:14px;"></i><i style="position:absolute;height:3px;border-radius:2px;left:22px;width:30px;bottom:19px;background:#fff;"></i><i style="position:absolute;height:3px;border-radius:2px;left:5px;width:40px;bottom:7px;background:rgba(255,255,255,.6);"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Full cover</span>
                </label>
                <label class="header-style-opt" data-value="card" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="headerStyle" value="card" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:44px;border-radius:6px;overflow:hidden;background:linear-gradient(180deg,#94a3b8 0 40%,#f3f4f6 40%);"><i style="position:absolute;left:6px;right:6px;bottom:3px;top:16px;border-radius:6px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.15);"></i><i style="position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.6);left:28px;top:9px;box-shadow:0 0 0 2px #fff,0 1px 3px rgba(0,0,0,.2);background:#16a34a;"></i><i style="position:absolute;height:3px;border-radius:2px;left:16px;right:16px;top:30px;background:#111827;"></i><i style="position:absolute;height:3px;border-radius:2px;left:22px;right:22px;top:37px;background:#d1d5db;"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Photo + card</span>
                </label>
                <label class="header-style-opt" data-value="brand" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="headerStyle" value="brand" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:44px;border-radius:6px;overflow:hidden;background:radial-gradient(circle,rgba(255,255,255,.25) 1px,transparent 1.5px) 0 0/6px 6px,linear-gradient(140deg,#ef4444,#991b1b);"><i style="position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.6);left:27px;top:6px;border-radius:4px;width:16px;height:16px;"></i><i style="position:absolute;height:3px;border-radius:2px;left:14px;right:14px;top:28px;background:#fff;"></i><i style="position:absolute;height:3px;border-radius:2px;left:20px;right:20px;top:35px;background:rgba(255,255,255,.6);"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Brand colour</span>
                </label>
                <label class="header-style-opt" data-value="poster" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="headerStyle" value="poster" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:44px;border-radius:6px;overflow:hidden;background:linear-gradient(180deg,#94a3b8,#111827);"><i style="position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.6);left:4px;top:4px;width:9px;height:9px;"></i><i style="position:absolute;left:5px;width:42px;bottom:13px;height:7px;border-radius:2px;background:#fff;"></i><i style="position:absolute;height:3px;border-radius:2px;left:5px;width:12px;bottom:7px;background:#fbbf24;"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Poster</span>
                </label>
                <label class="header-style-opt" data-value="split" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="headerStyle" value="split" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:44px;border-radius:6px;overflow:hidden;background:#fff;"><i style="position:absolute;left:0;top:0;bottom:0;width:40%;border-radius:0 8px 8px 0;background:linear-gradient(135deg,#94a3b8,#475569);"></i><i style="position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.6);left:46%;top:8px;background:#16a34a;"></i><i style="position:absolute;height:3px;border-radius:2px;left:46%;right:6px;top:26px;background:#111827;"></i><i style="position:absolute;height:3px;border-radius:2px;left:46%;right:14px;top:33px;background:#d1d5db;"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Split</span>
                </label>
                <label class="header-style-opt" data-value="arch" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="headerStyle" value="arch" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:44px;border-radius:6px;overflow:hidden;background:#fbf6ee;"><i style="position:absolute;left:22px;right:22px;top:4px;height:24px;border-radius:12px 12px 3px 3px;background:linear-gradient(135deg,#94a3b8,#475569);"></i><i style="position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.6);left:28px;top:22px;background:#16a34a;box-shadow:0 0 0 2px #fbf6ee;"></i><i style="position:absolute;height:3px;border-radius:2px;left:18px;right:18px;bottom:4px;background:#111827;"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Arch</span>
                </label>
                <label class="header-style-opt" data-value="story" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="headerStyle" value="story" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:44px;border-radius:6px;overflow:hidden;background:linear-gradient(180deg,#94a3b8,#111827);"><i style="position:absolute;height:3px;border-radius:2px;left:4px;right:20px;top:3px;height:2px;background:linear-gradient(90deg,#fff 0 33%,transparent 33% 36%,#fff 36% 55%,rgba(255,255,255,.4) 55%);"></i><i style="position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.6);left:5px;bottom:12px;box-shadow:0 0 0 2px #f59e0b;"></i><i style="position:absolute;height:3px;border-radius:2px;left:23px;width:28px;bottom:17px;background:#fff;"></i><i style="position:absolute;height:3px;border-radius:2px;left:5px;width:40px;bottom:5px;background:rgba(255,255,255,.6);"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Story</span>
                </label>
                <label class="header-style-opt" data-value="neon" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="headerStyle" value="neon" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:44px;border-radius:6px;overflow:hidden;background:#0b0b14;"><i style="position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.6);left:28px;top:6px;box-shadow:0 0 0 2px #ef4444,0 0 8px #ef4444;"></i><i style="position:absolute;height:3px;border-radius:2px;left:14px;right:14px;top:27px;background:#fff;box-shadow:0 0 6px #ef4444;"></i><i style="position:absolute;height:3px;border-radius:2px;left:24px;right:24px;top:35px;background:rgba(255,255,255,.4);"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Neon</span>
                </label>
                <label class="header-style-opt" data-value="paper" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="headerStyle" value="paper" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:44px;border-radius:6px;overflow:hidden;background:#fbf7ef;box-shadow:inset 0 0 0 3px #fbf7ef,inset 0 0 0 4px rgba(140,100,60,.45);"><i style="position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.6);left:28px;top:6px;box-shadow:0 0 0 1px rgba(140,100,60,.6);"></i><i style="position:absolute;height:3px;border-radius:2px;left:16px;right:16px;top:25px;background:#3b2a1a;"></i><i style="position:absolute;height:3px;border-radius:2px;left:26px;right:26px;top:33px;height:1px;background:#a47148;"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Paper menu</span>
                </label>
                <label class="header-style-opt" data-value="profile" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="headerStyle" value="profile" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:44px;border-radius:6px;overflow:hidden;background:linear-gradient(180deg,#94a3b8 0 50%,#fff 50%);"><i style="position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.6);left:5px;top:15px;background:#16a34a;box-shadow:0 0 0 2px #fff;"></i><i style="position:absolute;height:3px;border-radius:2px;left:5px;width:38px;top:32px;background:#111827;"></i><i style="position:absolute;height:3px;border-radius:2px;left:5px;width:28px;top:38px;background:#d1d5db;"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Profile</span>
                </label>
                <label class="header-style-opt" data-value="aurora" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="headerStyle" value="aurora" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:44px;border-radius:6px;overflow:hidden;background:linear-gradient(120deg,#ef4444,#fbbf24,#991b1b);"><i style="position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.6);left:28px;top:6px;"></i><i style="position:absolute;height:3px;border-radius:2px;left:14px;right:14px;top:27px;background:#fff;"></i><i style="position:absolute;height:3px;border-radius:2px;left:22px;right:22px;top:34px;background:rgba(255,255,255,.6);"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Aurora</span>
                </label>
                <label class="header-style-opt" data-value="stack" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="headerStyle" value="stack" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:44px;border-radius:6px;overflow:hidden;background:#f6f7fb;"><i style="position:absolute;left:18px;right:16px;top:5px;height:22px;border-radius:5px;background:#fff;box-shadow:2px 2px 0 #d1d5db;transform:rotate(3deg);"></i><i style="position:absolute;left:16px;right:20px;top:4px;height:22px;border-radius:5px;background:linear-gradient(135deg,#94a3b8,#475569);transform:rotate(-3deg);"></i><i style="position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.6);left:28px;top:22px;background:#16a34a;box-shadow:0 0 0 2px #f6f7fb;"></i><i style="position:absolute;height:3px;border-radius:2px;left:18px;right:18px;bottom:3px;background:#111827;"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Stack</span>
                </label>
              </div>
              <!-- Story photos: only while the Story header is selected -->
              <div id="storyPhotosPanel" style="display:none;margin-top:12px;padding:12px;border-radius:12px;border:2px dashed #ddd6fe;background:#faf8ff;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
                  <span class="material-symbols-rounded" style="font-size:20px;color:#7c3aed;">auto_stories</span>
                  <b style="flex:1;min-width:140px;color:#111827;">Story photos <span id="storyPhotosCount" style="font-weight:600;color:#6b7280;"></span></b>
                  <label class="btn btn-primary" style="margin:0;cursor:pointer;display:inline-flex;align-items:center;gap:6px;padding:7px 12px;font-size:0.85rem;">
                    <span class="material-symbols-rounded" style="font-size:18px;">add_photo_alternate</span> Add photos
                    <input type="file" id="storyPhotosInput" accept="image/jpeg,image/png,image/webp,image/gif" multiple style="display:none;">
                  </label>
                </div>
                <p style="margin:0 0 10px;font-size:0.8rem;color:#6b7280;">Up to 10 photos play one after another at the top of your home page, like Instagram stories. Customers tap the right side for the next one. With no photos here, Story uses your Background Image.</p>
                <div id="storyPhotosGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(72px,1fr));gap:8px;"></div>
              </div>
            </div>
            </section>

            <section class="wt-group" id="wtg-colours">
              <div class="wt-group-h"><span class="material-symbols-rounded">palette</span><div><h3>Colours and font</h3><p>Start from a ready theme, then fine-tune colours, font and background.</p></div></div>
<!-- Theme Presets -->
            <div class="wt-card">
              <div class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#7c3aed">auto_awesome</span>
                Quick Themes
              </div>
              <p class="wt-desc">Pick a theme for a distinct color, font &amp; shape combo so your site doesn't look identical to every other restaurant on the platform. You can still fine-tune colors below afterward.</p>
              <div id="themePresetGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 10px;"></div>
              <input type="hidden" id="cardStyleInput" value="rounded">
              <input type="hidden" id="themePresetInput" value="">
            </div>
<!-- Colours (the real preview on the right shows them: Current mode) -->
            <div class="wt-card">
              <div class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#dc2626">palette</span>Colours</div>
              <p class="wt-desc">Tap a swatch to pick, or type a hex code. The preview changes as you go.</p>
            <div id="colorPreviewContainer" style="display:none;" aria-hidden="true">
              <div id="heroPreview"><div id="heroPreviewName"></div></div><div id="categoryButtonPreview"></div><div id="addToCartPreview"></div><div id="checkoutPreview"></div>
            </div>
            <div class="wt-swatches">
              <div style="display:flex;align-items:center;gap:10px;padding:10px;border-radius:12px;border:1.5px solid #e5e7eb;background:#fff;">
                <input type="color" id="primaryRed" value="#F70000" title="Primary" style="width:46px;height:46px;flex-shrink:0;padding:0;border:0;border-radius:12px;background:none;cursor:pointer;">
                <div style="flex:1;min-width:0;">
                  <div style="display:flex;align-items:center;gap:5px;font-weight:700;color:#111827;font-size:0.88rem;"><span class="material-symbols-rounded" style="font-size:1rem;color:#dc2626;">palette</span>Primary</div>
                  <div style="font-size:0.72rem;color:#6b7280;margin:1px 0 4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Buttons, links &amp; header</div>
                  <input type="text" id="primaryRedHex" value="#F70000" maxlength="7" placeholder="#F70000" style="width:100%;font-family:'Courier New',monospace;font-size:0.8rem;color:#374151;font-weight:700;background:#f3f4f6;padding:0.3rem 0.45rem;border-radius:6px;border:1.5px solid #e5e7eb;text-transform:uppercase;">
                </div>
              </div>
              <div style="display:flex;align-items:center;gap:10px;padding:10px;border-radius:12px;border:1.5px solid #e5e7eb;background:#fff;">
                <input type="color" id="primaryYellow" value="#FFD100" title="Add to Cart" style="width:46px;height:46px;flex-shrink:0;padding:0;border:0;border-radius:12px;background:none;cursor:pointer;">
                <div style="flex:1;min-width:0;">
                  <div style="display:flex;align-items:center;gap:5px;font-weight:700;color:#111827;font-size:0.88rem;"><span class="material-symbols-rounded" style="font-size:1rem;color:#f59e0b;">star</span>Add to Cart</div>
                  <div style="font-size:0.72rem;color:#6b7280;margin:1px 0 4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">"Add to Cart" buttons &amp; highlights</div>
                  <input type="text" id="primaryYellowHex" value="#FFD100" maxlength="7" placeholder="#FFD100" style="width:100%;font-family:'Courier New',monospace;font-size:0.8rem;color:#374151;font-weight:700;background:#f3f4f6;padding:0.3rem 0.45rem;border-radius:6px;border:1.5px solid #e5e7eb;text-transform:uppercase;">
                </div>
              </div>
              <div style="display:flex;align-items:center;gap:10px;padding:10px;border-radius:12px;border:1.5px solid #e5e7eb;background:#fff;">
                <input type="color" id="checkoutColor" value="#F70000" title="Checkout" style="width:46px;height:46px;flex-shrink:0;padding:0;border:0;border-radius:12px;background:none;cursor:pointer;">
                <div style="flex:1;min-width:0;">
                  <div style="display:flex;align-items:center;gap:5px;font-weight:700;color:#111827;font-size:0.88rem;"><span class="material-symbols-rounded" style="font-size:1rem;color:#dc2626;">shopping_cart_checkout</span>Checkout</div>
                  <div style="font-size:0.72rem;color:#6b7280;margin:1px 0 4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Checkout bar &amp; button</div>
                  <input type="text" id="checkoutColorHex" value="#F70000" maxlength="7" placeholder="#F70000" style="width:100%;font-family:'Courier New',monospace;font-size:0.8rem;color:#374151;font-weight:700;background:#f3f4f6;padding:0.3rem 0.45rem;border-radius:6px;border:1.5px solid #e5e7eb;text-transform:uppercase;">
                </div>
              </div>
            </div>
            <!-- Hidden field: gradient shade auto-derived from Primary Color (no manual picker needed) -->
            <input type="hidden" id="darkRed" value="#DA020E">
            <p class="wt-desc" style="margin:0">The gradient shade used in headers is generated automatically from your Primary Color.</p>
            </div>
<!-- Font Picker -->
            <div class="wt-card">
              <div class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#2563eb">font_download</span>
                Website Font
              </div>
              <p class="wt-desc">Choose the font used across your customer-facing website.</p>
              <select id="siteFontSelect" style="width: 100%; padding: 0.75rem; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 1rem; background: #fff; cursor: pointer;">
                <option value="Poppins" style="font-family: 'Poppins', sans-serif;">Poppins (Default)</option>
                <option value="Playfair Display" style="font-family: 'Playfair Display', serif;">Playfair Display (Elegant)</option>
                <option value="Roboto" style="font-family: 'Roboto', sans-serif;">Roboto (Clean)</option>
                <option value="Montserrat" style="font-family: 'Montserrat', sans-serif;">Montserrat (Modern)</option>
                <option value="Nunito" style="font-family: 'Nunito', sans-serif;">Nunito (Friendly)</option>
                <option value="Lora" style="font-family: 'Lora', serif;">Lora (Classic)</option>
              </select>
            </div>
<!-- Background Theme Selector -->
            <div class="wt-card">
              <div class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#8b5cf6">wallpaper</span>
                Background Theme
              </div>
              <p class="wt-desc">Pick a background for your homepage. <strong>Click any preset</strong> to select it, then <strong>click Save Theme</strong> below. Or upload your own image — it will be saved automatically.</p>
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
<!-- Category heading colour (Menu page + home menu list) -->
            <div class="wt-card">
              <div class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#7c3aed">format_color_fill</span>
                Category Heading Colour
              </div>
              <p class="wt-desc">Background of the category headings (e.g. <b>Starters (6)</b>). <b>Automatic</b> uses a light shade of your ADD button colour; untick it to pick your own &mdash; light colours read best.</p>
              <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; font-weight: 500; cursor: pointer;"><input type="checkbox" id="categoryHeadingAuto" checked> Automatic</label>
                <input type="color" id="categoryHeadingBg" value="#fdeee4" style="width: 52px; height: 36px; border: 1.5px solid #e5e7eb; border-radius: 8px; padding: 2px; background: #fff; cursor: pointer;" disabled>
                <span id="categoryHeadingPreview" style="padding: 6px 14px; border-radius: 10px; font-weight: 700; color: #1a1b1f; background: #fdeee4;">Starters (6)</span>
              </div>
            </div>
            </section>

            <section class="wt-group" id="wtg-home">
              <div class="wt-group-h"><span class="material-symbols-rounded">home</span><div><h3>Home page</h3><p>What customers see first.</p></div></div>
<!-- Home page dishes: how the menu list on the home page looks -->
            <div class="wt-card">
              <div class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#7c3aed">home</span>
                Home Page Dishes
              </div>
              <p class="wt-desc">How dishes look in the menu on your Home page. <b>Classic</b>: rows with a photo (default). <b>Grid</b>: 2 cards per row. <b>Big photo</b>: one large photo per dish. <b>Compact</b>: short rows for long menus. <b>Carousel</b>: each category scrolls sideways. <b>Magazine</b>: zig-zag cards. <b>Printed menu</b>: no photos, name ····· price. <b>Tiles</b>: 3 per row with the name on the photo. <b>Delivery app</b>: photo on the right with ADD on it. <b>Dark</b>: the dish list in dark mode.</p>
              <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <label class="home-card-style-opt" data-value="classic" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #7c3aed; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="homeCardStyle" value="classic" checked style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #7c3aed;">view_list</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Classic</span>
                </label>
                <label class="home-card-style-opt" data-value="grid" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="homeCardStyle" value="grid" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">grid_view</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Grid</span>
                </label>
                <label class="home-card-style-opt" data-value="big" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="homeCardStyle" value="big" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">image</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Big photo</span>
                </label>
                <label class="home-card-style-opt" data-value="compact" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="homeCardStyle" value="compact" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">density_small</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Compact</span>
                </label>
                <label class="home-card-style-opt" data-value="carousel" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="homeCardStyle" value="carousel" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">view_carousel</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Carousel</span>
                </label>
                <label class="home-card-style-opt" data-value="magazine" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="homeCardStyle" value="magazine" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">auto_stories</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Magazine</span>
                </label>
                <label class="home-card-style-opt" data-value="printed" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="homeCardStyle" value="printed" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">menu_book</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Printed menu</span>
                </label>
                <label class="home-card-style-opt" data-value="tiles" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="homeCardStyle" value="tiles" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">apps</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Tiles</span>
                </label>
                <label class="home-card-style-opt" data-value="delivery" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="homeCardStyle" value="delivery" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">delivery_dining</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Delivery app</span>
                </label>
                <label class="home-card-style-opt" data-value="dark" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="homeCardStyle" value="dark" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">dark_mode</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Dark</span>
                </label>
              </div>
            </div>
<!-- Bestsellers section look (saved with the theme as bestseller_style) -->
            <div class="bs-style-card">
              <div class="bs-style-head">
                <span class="material-symbols-rounded" style="font-size: 1.2rem; color: #f59e0b;">star</span>
                Bestsellers Section
              </div>
              <p class="bs-style-sub">Customise the “Bestsellers” row on your homepage. Pick which dishes appear under <strong>Offers → Bestsellers</strong>.</p>

              <label class="bs-style-check"><input type="checkbox" id="bsShowSection" checked> Show the Bestsellers section</label>

              <div class="bs-style-grid">
                <label class="bs-style-field">Heading
                  <input type="text" id="bsTitle" maxlength="40" placeholder="Bestsellers">
                </label>
                <label class="bs-style-field">Small label above
                  <input type="text" id="bsEyebrow" maxlength="40" placeholder="Customer favourites">
                </label>
              </div>

              <div class="bs-style-grid bs-style-colors">
                <label class="bs-style-field">Heading colour
                  <input type="color" id="bsTitleColor" value="#1f2a44">
                </label>
                <label class="bs-style-field">Offer &amp; rating badges
                  <input type="color" id="bsBadgeColor" value="#1f7a3a">
                </label>
                <label class="bs-style-field">ADD button colour
                  <input type="color" id="bsAddColor" value="#e53935">
                </label>
              </div>

              <div class="bs-style-grid">
                <label class="bs-style-field">ADD button animation
                  <select id="bsAddAnimation">
                    <option value="glow">Glow border ★</option>
                    <option value="bounce">Bounce</option>
                    <option value="ripple">Ripple</option>
                    <option value="none">No animation</option>
                  </select>
                </label>
                <div class="bs-style-field">Show on cards
                  <label class="bs-style-check"><input type="checkbox" id="bsShowRating" checked> Customer rating</label>
                  <label class="bs-style-check"><input type="checkbox" id="bsShowOffer" checked> “₹X OFF” offer badge</label>
                </div>
              </div>

              <div class="bs-style-grid">
                <label class="bs-style-field">ADD button style
                  <select id="bsAddStyle">
                    <option value="outline">Outline</option>
                    <option value="filled">Filled</option>
                    <option value="soft">Soft tint</option>
                  </select>
                </label>
                <label class="bs-style-field">ADD button shape
                  <select id="bsAddShape">
                    <option value="rounded">Rounded</option>
                    <option value="pill">Pill</option>
                    <option value="square">Square</option>
                  </select>
                </label>
                <label class="bs-style-field">ADD button text
                  <input type="text" id="bsAddLabel" maxlength="12" placeholder="ADD">
                </label>
                <div class="bs-style-field">&nbsp;
                  <label class="bs-style-check"><input type="checkbox" id="bsAddPlus"> Show “+” icon after the text</label>
                </div>
              </div>

              <div class="bs-style-grid">
                <label class="bs-style-field">Price box colour (cards &amp; menu)
                  <input type="color" id="bsPriceColor" value="#ea580c">
                </label>
                <label class="bs-style-field">Price box style
                  <select id="bsPriceStyle">
                    <option value="outline">Outline</option>
                    <option value="filled" selected>Filled</option>
                    <option value="soft">Soft tint</option>
                  </select>
                </label>
              </div>

              <div class="bs-style-preview" id="bsPreview" aria-label="Preview">
                <span class="bs-style-preview-label">Preview</span>
                <div class="bs-preview-card">
                  <div class="bs-preview-img">
                    <img src="../../assets/images/default-menu-item.jpg" alt="" loading="lazy">
                    <span class="bs-preview-off" id="bsPreviewOff">₹35 OFF</span>
                    <span class="bs-preview-rate" id="bsPreviewRate">4.6 ★</span>
                  </div>
                  <div class="bs-preview-name">Steamed Momos</div>
                  <div class="bs-preview-price"><s>₹149</s><span class="bs-preview-price-now">₹114</span></div>
                  <div class="bs-preview-qty"><button type="button" class="bs-preview-add" id="bsPreviewAdd" tabindex="-1"><span id="bsPreviewAddText">ADD</span><span class="material-symbols-rounded bs-preview-plus" id="bsPreviewPlus" aria-hidden="true">add</span></button></div>
                </div>
              </div>
            </div>
            </section>

            <section class="wt-group" id="wtg-menu">
              <div class="wt-group-h"><span class="material-symbols-rounded">restaurant_menu</span><div><h3>Menu page</h3><p>How dishes and categories are laid out.</p></div></div>
<!-- Desktop Layout Toggle -->

            <div class="wt-card">
              <div class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#dc2626">grid_view</span>
                Mobile Layout
              </div>
              <p class="wt-desc">Choose how many product columns to show on phone screens. Desktop/laptop always shows 2 columns.</p>
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
<!-- Card Layout: real structural variants, not just recoloring -->
            <div class="wt-card">
              <div class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#7c3aed">view_agenda</span>
                Card Layout
              </div>
              <p class="wt-desc">How menu items are arranged on your Menu page &mdash; a real layout change, not just a color swap.</p>
              <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <label class="layout-style-opt" data-value="grid" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #7c3aed; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="layoutStyle" value="grid" checked style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #7c3aed;">grid_view</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Grid</span>
                </label>
                <label class="layout-style-opt" data-value="list" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="layoutStyle" value="list" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">view_list</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">List</span>
                </label>
                <label class="layout-style-opt" data-value="magazine" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="layoutStyle" value="magazine" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">view_agenda</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Magazine</span>
                </label>
              </div>
            </div>
<!-- Menu Card Design: original cards or the modern card -->
            <div class="wt-card">
              <div class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#7c3aed">dashboard</span>
                Menu Card Design
              </div>
              <p class="wt-desc">How dishes look on your Menu page. <b>Modern</b>: wide photo with a quantity picker. <b>Minimal</b>: clean photo with a round + button. <b>Photo</b>: big photo with the name over it. <b>List</b>: one dish per row with its description (like delivery apps). <b>Elegant</b>: round plate photo on a cream card. <b>Compact</b>: 3 small dishes per row for big menus. <b>Midnight</b>: dark cards with a glowing price. <b>Showcase</b>: one big photo per row. The new designs look best with Menu Categories set to <b>Top</b>.</p>
              <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <label class="menu-card-style-opt" data-value="classic" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #7c3aed; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="menuCardStyle" value="classic" checked style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #7c3aed;">grid_view</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Classic</span>
                </label>
                <label class="menu-card-style-opt" data-value="modern" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="menuCardStyle" value="modern" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">dashboard</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Modern</span>
                </label>
                <label class="menu-card-style-opt" data-value="minimal" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="menuCardStyle" value="minimal" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">crop_square</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Minimal</span>
                </label>
                <label class="menu-card-style-opt" data-value="photo" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="menuCardStyle" value="photo" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">photo</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Photo</span>
                </label>
                <label class="menu-card-style-opt" data-value="list" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="menuCardStyle" value="list" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">view_agenda</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">List</span>
                </label>
                <label class="menu-card-style-opt" data-value="elegant" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="menuCardStyle" value="elegant" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">restaurant</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Elegant</span>
                </label>
                <label class="menu-card-style-opt" data-value="compact" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="menuCardStyle" value="compact" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">apps</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Compact</span>
                </label>
                <label class="menu-card-style-opt" data-value="midnight" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="menuCardStyle" value="midnight" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">dark_mode</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Midnight</span>
                </label>
                <label class="menu-card-style-opt" data-value="showcase" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="menuCardStyle" value="showcase" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">panorama</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Showcase</span>
                </label>
              </div>
            </div>
<!-- Menu Categories: left sidebar or a scrolling bar across the top -->
            <div class="wt-card">
              <div class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#7c3aed">view_sidebar</span>
                Menu Categories
              </div>
              <p class="wt-desc">Where the category buttons sit on your Menu page &mdash; down the left side, or in a scrolling bar across the top.</p>
              <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <label class="menu-nav-pos-opt" data-value="left" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #7c3aed; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="menuNavPosition" value="left" checked style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #7c3aed;">view_sidebar</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Left</span>
                </label>
                <label class="menu-nav-pos-opt" data-value="top" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="menuNavPosition" value="top" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">view_day</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Top</span>
                </label>
              </div>
            </div>
<!-- Category Pictures: round photos or cut-outs -->
            <div class="wt-card">
              <div class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#7c3aed">lunch_dining</span>
                Category Pictures
              </div>
              <p class="wt-desc">How the category buttons on your Menu page show their pictures. <b>Cut-out</b> shows the whole picture with no tile &mdash; best with transparent PNG images of the dish (upload them on each category; transparency is kept).</p>
              <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <label class="cat-pic-style-opt" data-value="round" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #7c3aed; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="categoryPicStyle" value="round" checked style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #7c3aed;">account_circle</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Round</span>
                </label>
                <label class="cat-pic-style-opt" data-value="cutout" style="display: flex; flex-direction: column; align-items: center; gap: 0.4rem; cursor: pointer; padding: 0.6rem; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; min-width: 90px;">
                  <input type="radio" name="categoryPicStyle" value="cutout" style="display:none;">
                  <span class="material-symbols-rounded" style="font-size: 1.5rem; color: #6b7280;">lunch_dining</span>
                  <span style="font-weight: 500; font-size: 0.8rem;">Cut-out</span>
                </label>
              </div>
              <!-- Custom picture for the Menu page "All" button -->
              <div style="margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid #e5e7eb; display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                <span style="font-weight: 600; font-size: 0.85rem; color: #111827;">&ldquo;All&rdquo; button picture</span>
                <img id="allCategoryIconPreview" src="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 48 48%22%3E%3Crect width=%2248%22 height=%2248%22 rx=%2212%22 fill=%22%23fff1ec%22/%3E%3Cg fill=%22%23ea580c%22%3E%3Crect x=%2215%22 y=%2215%22 width=%228%22 height=%228%22 rx=%222%22/%3E%3Crect x=%2225%22 y=%2215%22 width=%228%22 height=%228%22 rx=%222%22/%3E%3Crect x=%2215%22 y=%2225%22 width=%228%22 height=%228%22 rx=%222%22/%3E%3Crect x=%2225%22 y=%2225%22 width=%228%22 height=%228%22 rx=%222%22/%3E%3C/g%3E%3C/svg%3E" data-default="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 48 48%22%3E%3Crect width=%2248%22 height=%2248%22 rx=%2212%22 fill=%22%23fff1ec%22/%3E%3Cg fill=%22%23ea580c%22%3E%3Crect x=%2215%22 y=%2215%22 width=%228%22 height=%228%22 rx=%222%22/%3E%3Crect x=%2225%22 y=%2215%22 width=%228%22 height=%228%22 rx=%222%22/%3E%3Crect x=%2215%22 y=%2225%22 width=%228%22 height=%228%22 rx=%222%22/%3E%3Crect x=%2225%22 y=%2225%22 width=%228%22 height=%228%22 rx=%222%22/%3E%3C/g%3E%3C/svg%3E" alt="All button picture" style="width: 48px; height: 48px; border-radius: 50%; object-fit: contain; background: #fff; border: 1px solid #e5e7eb;">
                <label class="btn btn-outline" style="cursor: pointer; margin: 0;">Upload<input type="file" id="allCategoryIconInput" accept="image/jpeg,image/png,image/webp,image/gif" style="display: none;"></label>
                <button type="button" class="btn btn-outline" id="allCategoryIconRemove">Use default</button>
                <span id="allCategoryIconStatus" style="font-size: 0.8rem; color: #6b7280;">JPG, PNG or WebP, max 2MB &mdash; saved straight away.</span>
              </div>
            </div>
            </section>

            <section class="wt-group" id="wtg-nav">
              <div class="wt-group-h"><span class="material-symbols-rounded">apps</span><div><h3>Navigation and profile</h3><p>The bottom bar and the customer’s profile page.</p></div></div>
<!-- Bottom Navigation: icon style + each item's label and own icon, in one place -->
            <div class="wt-card">
              <div class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#dc2626">apps</span>
                Bottom Navigation
              </div>
              <p class="wt-desc">Pick how the bar looks and an icon style, then rename any item (blank = default) or upload your own icon for it (PNG/JPG/SVG/WebP/GIF, max 1MB).</p>
              <div style="font-size: 0.75rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 6px;">Bar style</div>
              <style>
                .nbs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); gap: 8px; margin-bottom: 14px; }
                .nav-bar-style-opt { display: flex; flex-direction: column; gap: 6px; padding: 8px; border: 2px solid #e5e7eb; border-radius: 12px; background: #fff; cursor: pointer; transition: border-color .15s; }
                .nav-bar-style-opt input { display: none; }
                .nav-bar-style-opt b { font-size: 0.8rem; color: #111827; }
                .nav-bar-style-opt small { font-size: 0.7rem; color: #6b7280; line-height: 1.3; }
                .nbs-m { position: relative; height: 54px; border-radius: 8px; overflow: hidden; background: linear-gradient(160deg, #fde68a, #fca5a5 60%, #c4b5fd); }
                .nbs-bar { position: absolute; left: 0; right: 0; bottom: 0; height: 22px; display: flex; align-items: center; justify-content: space-around; background: #fff; border-top: 1px solid #e5e7eb; }
                .nbs-bar i { width: 8px; height: 8px; border-radius: 3px; background: #9ca3af; position: relative; }
                .nbs-bar i.on { background: #e11d48; }
                .nbs-floating .nbs-bar { left: 6px; right: 6px; bottom: 5px; border-radius: 10px; border: 0; box-shadow: 0 4px 10px rgba(0,0,0,.18); }
                .nbs-glass .nbs-bar { background: rgba(255,255,255,.55); border-top-color: rgba(255,255,255,.8); -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px); }
                .nbs-dark .nbs-bar { background: #111827; border: 0; }
                .nbs-dark .nbs-bar i { background: #6b7280; } .nbs-dark .nbs-bar i.on { background: #fff; }
                .nbs-pill .nbs-bar i.on { width: 26px; border-radius: 999px; background: #fecdd3; box-shadow: inset 0 0 0 3px #fecdd3, inset 8px 0 0 0 #e11d48; }
                .nbs-bubble .nbs-bar i.on { width: 16px; height: 16px; border-radius: 50%; margin-top: -14px; border: 2px solid #fff; }
                .nbs-line .nbs-bar i.on::before { content: ""; position: absolute; top: -7px; left: -3px; width: 14px; height: 2px; border-radius: 2px; background: #e11d48; }
                .nbs-fab .nbs-bar i.mid { width: 18px; height: 18px; border-radius: 50%; margin-top: -16px; background: #e11d48; border: 2px solid #fff; }
                .nbs-minimal .nbs-bar i.on::after { content: ""; position: absolute; bottom: -5px; left: 2px; width: 4px; height: 4px; border-radius: 50%; background: #e11d48; }
              </style>
              <div class="nbs-grid">
                <?php foreach ([
                  'classic'  => ['Classic', 'Icons with labels'],
                  'floating' => ['Floating', 'Rounded bar above the edge'],
                  'glass'    => ['Glass', 'Frosted, see-through'],
                  'dark'     => ['Dark', 'Dark bar, light icons'],
                  'pill'     => ['Pill', 'Current tab in a pill'],
                  'bubble'   => ['Bubble', 'Current tab pops up'],
                  'line'     => ['Top line', 'Accent line on top'],
                  'fab'      => ['Raised cart', 'Big round cart button'],
                  'minimal'  => ['Icons only', 'No labels, a dot'],
                ] as $nbsId => $nbs): ?>
                <label class="nav-bar-style-opt" data-value="<?php echo $nbsId; ?>">
                  <input type="radio" name="navBarStyle" value="<?php echo $nbsId; ?>"<?php echo $nbsId === 'classic' ? ' checked' : ''; ?>>
                  <span class="nbs-m nbs-<?php echo $nbsId; ?>"><span class="nbs-bar"><i class="on"></i><i></i><i class="mid"></i><i></i><i></i></span></span>
                  <b><?php echo $nbs[0]; ?></b><small><?php echo $nbs[1]; ?></small>
                </label>
                <?php endforeach; ?>
              </div>
              <div style="font-size: 0.75rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 6px;">Icon style</div>
              <div id="navIconStyleGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(92px, 1fr)); gap: 8px;"></div>
              <input type="hidden" id="navIconStyleInput" value="classic">
              <div style="font-size: 0.75rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; margin: 14px 0 6px;">Items — label &amp; own icon</div>
              <div id="navIconUploadGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 8px;">
                <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <span data-nav-icon-thumb="home" style="display:flex;flex-shrink:0;"></span>
                  <input type="text" id="navLabelHomeInput" maxlength="20" placeholder="Home" aria-label="Home label" style="flex:1;min-width:0;padding:0.45rem 0.6rem;border:1.5px solid #e5e7eb;border-radius:8px;font-size:0.88rem;background:#fff;">
                  <span data-nav-icon-actions="home" style="display:flex;gap:4px;flex-shrink:0;"></span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <span data-nav-icon-thumb="menu" style="display:flex;flex-shrink:0;"></span>
                  <input type="text" id="navLabelMenuInput" maxlength="20" placeholder="Menu" aria-label="Menu label" style="flex:1;min-width:0;padding:0.45rem 0.6rem;border:1.5px solid #e5e7eb;border-radius:8px;font-size:0.88rem;background:#fff;">
                  <span data-nav-icon-actions="menu" style="display:flex;gap:4px;flex-shrink:0;"></span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <span data-nav-icon-thumb="social" style="display:flex;flex-shrink:0;"></span>
                  <input type="text" id="navLabelSocialInput" maxlength="20" placeholder="Social" aria-label="Social label" style="flex:1;min-width:0;padding:0.45rem 0.6rem;border:1.5px solid #e5e7eb;border-radius:8px;font-size:0.88rem;background:#fff;">
                  <span data-nav-icon-actions="social" style="display:flex;gap:4px;flex-shrink:0;"></span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <span data-nav-icon-thumb="plans" style="display:flex;flex-shrink:0;"></span>
                  <input type="text" id="navLabelPlansInput" maxlength="20" placeholder="Plans" aria-label="Plans label" style="flex:1;min-width:0;padding:0.45rem 0.6rem;border:1.5px solid #e5e7eb;border-radius:8px;font-size:0.88rem;background:#fff;">
                  <span data-nav-icon-actions="plans" style="display:flex;gap:4px;flex-shrink:0;"></span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <span data-nav-icon-thumb="reservations" style="display:flex;flex-shrink:0;"></span>
                  <input type="text" id="navLabelReservationsInput" maxlength="20" placeholder="Reserve" aria-label="Reserve label" style="flex:1;min-width:0;padding:0.45rem 0.6rem;border:1.5px solid #e5e7eb;border-radius:8px;font-size:0.88rem;background:#fff;">
                  <span data-nav-icon-actions="reservations" style="display:flex;gap:4px;flex-shrink:0;"></span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <span data-nav-icon-thumb="cart" style="display:flex;flex-shrink:0;"></span>
                  <input type="text" id="navLabelCartInput" maxlength="20" placeholder="Cart" aria-label="Cart label" style="flex:1;min-width:0;padding:0.45rem 0.6rem;border:1.5px solid #e5e7eb;border-radius:8px;font-size:0.88rem;background:#fff;">
                  <span data-nav-icon-actions="cart" style="display:flex;gap:4px;flex-shrink:0;"></span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <span data-nav-icon-thumb="profile" style="display:flex;flex-shrink:0;"></span>
                  <input type="text" id="navLabelProfileInput" maxlength="20" placeholder="Profile" aria-label="Profile label" style="flex:1;min-width:0;padding:0.45rem 0.6rem;border:1.5px solid #e5e7eb;border-radius:8px;font-size:0.88rem;background:#fff;">
                  <span data-nav-icon-actions="profile" style="display:flex;gap:4px;flex-shrink:0;"></span>
                </div>
              </div>
            </div>
<!-- Profile page: how the customer's profile page looks -->
            <div id="profileDesignBox" class="wt-card">
              <div class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#7c3aed">account_circle</span>
                Profile Page
              </div>
              <p class="wt-desc">How your customers' profile page looks: pick a top card, then how the sections below look. <b>Cover</b> uses your Background Image.</p>
              <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 8px;">
                <label class="profile-style-opt" data-value="midnight" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #7c3aed;border-radius:10px;background:#fff;">
                  <input type="radio" name="profileStyle" value="midnight" checked style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:56px;border-radius:7px;overflow:hidden;background:#f2f3f5;"><i style="position:absolute;left:5px;right:5px;top:5px;height:28px;border-radius:6px;background:linear-gradient(180deg,#3b1d24,#15161b);"></i><i style="position:absolute;width:14px;height:14px;border-radius:50%;left:9px;top:12px;background:#ef4444;"></i><i style="position:absolute;height:3px;border-radius:2px;left:27px;width:24px;top:15px;background:#fff;"></i><i style="position:absolute;left:5px;right:5px;bottom:4px;height:14px;border-radius:4px;background:#fff;"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Midnight</span>
                </label>
                <label class="profile-style-opt" data-value="brand" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="profileStyle" value="brand" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:56px;border-radius:7px;overflow:hidden;background:#f2f3f5;"><i style="position:absolute;left:5px;right:5px;top:5px;height:28px;border-radius:6px;background:linear-gradient(140deg,#ef4444,#991b1b);"></i><i style="position:absolute;width:14px;height:14px;border-radius:50%;left:9px;top:12px;background:#fff;"></i><i style="position:absolute;height:3px;border-radius:2px;left:27px;width:24px;top:15px;background:#fff;"></i><i style="position:absolute;left:5px;right:5px;bottom:4px;height:14px;border-radius:4px;background:#fff;"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Brand</span>
                </label>
                <label class="profile-style-opt" data-value="soft" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="profileStyle" value="soft" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:56px;border-radius:7px;overflow:hidden;background:#f2f3f5;"><i style="position:absolute;left:5px;right:5px;top:5px;height:28px;border-radius:6px;background:linear-gradient(160deg,#fde2e2,#fff);border:1px solid #fbd5d5;"></i><i style="position:absolute;width:14px;height:14px;border-radius:50%;left:9px;top:12px;background:#ef4444;"></i><i style="position:absolute;height:3px;border-radius:2px;left:27px;width:24px;top:15px;background:#374151;"></i><i style="position:absolute;left:5px;right:5px;bottom:4px;height:14px;border-radius:4px;background:#fff;"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Soft</span>
                </label>
                <label class="profile-style-opt" data-value="centered" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="profileStyle" value="centered" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:56px;border-radius:7px;overflow:hidden;background:#f2f3f5;"><i style="position:absolute;left:5px;right:5px;top:5px;height:12px;border-radius:6px 6px 0 0;background:#ef4444;"></i><i style="position:absolute;width:14px;height:14px;border-radius:50%;left:50%;margin-left:-7px;top:10px;background:#16a34a;box-shadow:0 0 0 2px #fff;"></i><i style="position:absolute;height:3px;border-radius:2px;left:22px;right:22px;top:28px;background:#374151;"></i><i style="position:absolute;left:5px;right:5px;bottom:4px;height:14px;border-radius:4px;background:#fff;"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Centered</span>
                </label>
                <label class="profile-style-opt" data-value="cover" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="profileStyle" value="cover" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:56px;border-radius:7px;overflow:hidden;background:#f2f3f5;"><i style="position:absolute;left:5px;right:5px;top:5px;height:28px;border-radius:6px;background:linear-gradient(180deg,#94a3b8,#334155);"></i><i style="position:absolute;left:9px;right:9px;top:16px;height:13px;border-radius:4px;background:rgba(255,255,255,.35);"></i><i style="position:absolute;width:14px;height:14px;border-radius:50%;left:12px;top:15px;width:10px;height:10px;background:#fff;"></i><i style="position:absolute;height:3px;border-radius:2px;left:26px;width:22px;top:20px;background:#fff;"></i><i style="position:absolute;left:5px;right:5px;bottom:4px;height:14px;border-radius:4px;background:#fff;"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Cover</span>
                </label>
                <label class="profile-style-opt" data-value="glass" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="profileStyle" value="glass" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:56px;border-radius:7px;overflow:hidden;background:#f2f3f5;"><i style="position:absolute;left:5px;right:5px;top:5px;height:28px;border-radius:6px;background:radial-gradient(circle at 20% 20%,#ef4444,transparent 50%),radial-gradient(circle at 85% 85%,#f59e0b,transparent 50%),#1b1d24;"></i><i style="position:absolute;left:9px;right:9px;top:10px;height:18px;border-radius:5px;background:rgba(255,255,255,.18);"></i><i style="position:absolute;width:14px;height:14px;border-radius:50%;left:12px;top:12px;background:#fff;"></i><i style="position:absolute;height:3px;border-radius:2px;left:30px;width:20px;top:17px;background:#fff;"></i><i style="position:absolute;left:5px;right:5px;bottom:4px;height:14px;border-radius:4px;background:#fff;"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Glass</span>
                </label>
                <label class="profile-style-opt" data-value="aurora" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="profileStyle" value="aurora" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:56px;border-radius:7px;overflow:hidden;background:#f2f3f5;"><i style="position:absolute;left:5px;right:5px;top:5px;height:28px;border-radius:6px;background:linear-gradient(120deg,#ef4444,#7c3aed,#991b1b);"></i><i style="position:absolute;width:14px;height:14px;border-radius:50%;left:9px;top:12px;background:#fff;"></i><i style="position:absolute;height:3px;border-radius:2px;left:27px;width:24px;top:15px;background:#fff;"></i><i style="position:absolute;left:5px;right:5px;bottom:4px;height:14px;border-radius:4px;background:#fff;"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Aurora</span>
                </label>
                <label class="profile-style-opt" data-value="minimal" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="profileStyle" value="minimal" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:56px;border-radius:7px;overflow:hidden;background:#f2f3f5;"><i style="position:absolute;width:14px;height:14px;border-radius:50%;left:8px;top:8px;width:12px;height:12px;background:#ef4444;"></i><i style="position:absolute;height:3px;border-radius:2px;left:24px;width:26px;top:12px;background:#374151;"></i><i style="position:absolute;left:6px;right:6px;top:26px;height:1px;background:#d1d5db;"></i><i style="position:absolute;height:3px;border-radius:2px;left:8px;width:10px;top:30px;background:#9ca3af;"></i><i style="position:absolute;height:3px;border-radius:2px;left:30px;width:10px;top:30px;background:#9ca3af;"></i><i style="position:absolute;left:5px;right:5px;bottom:4px;height:14px;border-radius:4px;background:#fff;"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Minimal</span>
                </label>
                <label class="profile-style-opt" data-value="banner" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="profileStyle" value="banner" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:56px;border-radius:7px;overflow:hidden;background:#f2f3f5;"><i style="position:absolute;left:0;right:0;top:0;height:34px;border-radius:0 0 50% 50% / 0 0 10px 10px;background:linear-gradient(160deg,#ef4444,#991b1b);"></i><i style="position:absolute;width:14px;height:14px;border-radius:50%;left:9px;top:10px;background:#fff;"></i><i style="position:absolute;height:3px;border-radius:2px;left:27px;width:24px;top:14px;background:#fff;"></i><i style="position:absolute;left:5px;right:5px;bottom:4px;height:14px;border-radius:4px;background:#fff;"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Banner</span>
                </label>
                <label class="profile-style-opt" data-value="neon" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;cursor:pointer;padding:6px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;">
                  <input type="radio" name="profileStyle" value="neon" style="display:none;">
                  <span aria-hidden="true" style="position:relative;display:block;height:56px;border-radius:7px;overflow:hidden;background:#f2f3f5;"><i style="position:absolute;left:5px;right:5px;top:5px;height:28px;border-radius:6px;background:#0a0a10;box-shadow:inset 0 0 0 1px #ef4444;"></i><i style="position:absolute;width:14px;height:14px;border-radius:50%;left:9px;top:12px;background:#0a0a10;box-shadow:0 0 0 1.5px #ef4444,0 0 6px #ef4444;"></i><i style="position:absolute;height:3px;border-radius:2px;left:27px;width:24px;top:15px;background:#fff;box-shadow:0 0 4px #ef4444;"></i><i style="position:absolute;left:5px;right:5px;bottom:4px;height:14px;border-radius:4px;background:#fff;"></i></span>
                  <span style="font-weight:600;font-size:0.78rem;text-align:center;color:#374151;">Neon</span>
                </label>
              </div>
              <div style="margin-top:14px;font-size:0.8rem;font-weight:700;color:#374151;">Sections below &amp; options</div>
              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px 14px;margin-top:8px;">
                <div><span style="display:block;font-size:0.75rem;font-weight:700;color:#6b7280;margin:0 0 4px;text-transform:uppercase;letter-spacing:.04em;">Personal details</span>
                <div style="display:flex;padding:3px;border-radius:10px;background:#eef0f4;gap:3px;">
                  <label class="pf-opt" data-group="details" data-value="rows" style="flex:1;text-align:center;padding:6px 4px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;background:#fff;color:#7c3aed;box-shadow:0 1px 3px rgba(0,0,0,.12);"><input type="radio" name="pfOpt_details" value="rows" checked style="display:none;">Rows</label>
                  <label class="pf-opt" data-group="details" data-value="tiles" style="flex:1;text-align:center;padding:6px 4px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;color:#4b5563;"><input type="radio" name="pfOpt_details" value="tiles" style="display:none;">Tiles</label>
                  <label class="pf-opt" data-group="details" data-value="compact" style="flex:1;text-align:center;padding:6px 4px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;color:#4b5563;"><input type="radio" name="pfOpt_details" value="compact" style="display:none;">Compact</label>
                </div></div>
                <div><span style="display:block;font-size:0.75rem;font-weight:700;color:#6b7280;margin:0 0 4px;text-transform:uppercase;letter-spacing:.04em;">Quick links</span>
                <div style="display:flex;padding:3px;border-radius:10px;background:#eef0f4;gap:3px;">
                  <label class="pf-opt" data-group="links" data-value="tiles" style="flex:1;text-align:center;padding:6px 4px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;background:#fff;color:#7c3aed;box-shadow:0 1px 3px rgba(0,0,0,.12);"><input type="radio" name="pfOpt_links" value="tiles" checked style="display:none;">Tiles</label>
                  <label class="pf-opt" data-group="links" data-value="list" style="flex:1;text-align:center;padding:6px 4px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;color:#4b5563;"><input type="radio" name="pfOpt_links" value="list" style="display:none;">List</label>
                  <label class="pf-opt" data-group="links" data-value="icons" style="flex:1;text-align:center;padding:6px 4px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;color:#4b5563;"><input type="radio" name="pfOpt_links" value="icons" style="display:none;">Icons</label>
                  <label class="pf-opt" data-group="links" data-value="pills" style="flex:1;text-align:center;padding:6px 4px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;color:#4b5563;"><input type="radio" name="pfOpt_links" value="pills" style="display:none;">Pills</label>
                </div></div>
                <div><span style="display:block;font-size:0.75rem;font-weight:700;color:#6b7280;margin:0 0 4px;text-transform:uppercase;letter-spacing:.04em;">Order history</span>
                <div style="display:flex;padding:3px;border-radius:10px;background:#eef0f4;gap:3px;">
                  <label class="pf-opt" data-group="orders" data-value="cards" style="flex:1;text-align:center;padding:6px 4px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;background:#fff;color:#7c3aed;box-shadow:0 1px 3px rgba(0,0,0,.12);"><input type="radio" name="pfOpt_orders" value="cards" checked style="display:none;">Cards</label>
                  <label class="pf-opt" data-group="orders" data-value="timeline" style="flex:1;text-align:center;padding:6px 4px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;color:#4b5563;"><input type="radio" name="pfOpt_orders" value="timeline" style="display:none;">Timeline</label>
                  <label class="pf-opt" data-group="orders" data-value="compact" style="flex:1;text-align:center;padding:6px 4px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;color:#4b5563;"><input type="radio" name="pfOpt_orders" value="compact" style="display:none;">Compact</label>
                </div></div>
                <div><span style="display:block;font-size:0.75rem;font-weight:700;color:#6b7280;margin:0 0 4px;text-transform:uppercase;letter-spacing:.04em;">Avatar shape</span>
                <div style="display:flex;padding:3px;border-radius:10px;background:#eef0f4;gap:3px;">
                  <label class="pf-opt" data-group="avatar" data-value="circle" style="flex:1;text-align:center;padding:6px 4px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;background:#fff;color:#7c3aed;box-shadow:0 1px 3px rgba(0,0,0,.12);"><input type="radio" name="pfOpt_avatar" value="circle" checked style="display:none;">Round</label>
                  <label class="pf-opt" data-group="avatar" data-value="rounded" style="flex:1;text-align:center;padding:6px 4px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;color:#4b5563;"><input type="radio" name="pfOpt_avatar" value="rounded" style="display:none;">Rounded</label>
                  <label class="pf-opt" data-group="avatar" data-value="square" style="flex:1;text-align:center;padding:6px 4px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;color:#4b5563;"><input type="radio" name="pfOpt_avatar" value="square" style="display:none;">Square</label>
                </div></div>
                <div><span style="display:block;font-size:0.75rem;font-weight:700;color:#6b7280;margin:0 0 4px;text-transform:uppercase;letter-spacing:.04em;">Corners</span>
                <div style="display:flex;padding:3px;border-radius:10px;background:#eef0f4;gap:3px;">
                  <label class="pf-opt" data-group="corners" data-value="round" style="flex:1;text-align:center;padding:6px 4px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;background:#fff;color:#7c3aed;box-shadow:0 1px 3px rgba(0,0,0,.12);"><input type="radio" name="pfOpt_corners" value="round" checked style="display:none;">Round</label>
                  <label class="pf-opt" data-group="corners" data-value="soft" style="flex:1;text-align:center;padding:6px 4px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;color:#4b5563;"><input type="radio" name="pfOpt_corners" value="soft" style="display:none;">Soft</label>
                  <label class="pf-opt" data-group="corners" data-value="sharp" style="flex:1;text-align:center;padding:6px 4px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;color:#4b5563;"><input type="radio" name="pfOpt_corners" value="sharp" style="display:none;">Sharp</label>
                </div></div>
                <div><span style="display:block;font-size:0.75rem;font-weight:700;color:#6b7280;margin:0 0 4px;text-transform:uppercase;letter-spacing:.04em;">Stats (orders, spent, member since)</span>
                <div style="display:flex;padding:3px;border-radius:10px;background:#eef0f4;gap:3px;">
                  <label class="pf-opt" data-group="stats" data-value="show" style="flex:1;text-align:center;padding:6px 4px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;background:#fff;color:#7c3aed;box-shadow:0 1px 3px rgba(0,0,0,.12);"><input type="radio" name="pfOpt_stats" value="show" checked style="display:none;">Show</label>
                  <label class="pf-opt" data-group="stats" data-value="hide" style="flex:1;text-align:center;padding:6px 4px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;color:#4b5563;"><input type="radio" name="pfOpt_stats" value="hide" style="display:none;">Hide</label>
                </div></div>
                <div><span style="display:block;font-size:0.75rem;font-weight:700;color:#6b7280;margin:0 0 4px;text-transform:uppercase;letter-spacing:.04em;">Colour</span>
                <label style="display:flex;align-items:center;gap:8px;font-size:0.85rem;cursor:pointer;"><input type="checkbox" id="pfAccentOn"> Own colour for this page <input type="color" id="pfAccent" value="#7c3aed" style="width:34px !important;height:28px !important;min-height:0 !important;padding:0 !important;border:0;border-radius:6px;background:none;cursor:pointer;flex:none;"></label>
                <span style="font-size:0.75rem;color:#9ca3af;">Unticked: your brand colour.</span></div>
              </div>
            </div>
            </section>

            <section class="wt-group" id="wtg-banners">
              <div class="wt-group-h"><span class="material-symbols-rounded">campaign</span><div><h3>Banners and sharing</h3><p>Photo banners, animated offer banners and your website link. Banners save as soon as you upload or edit them; everything else saves with Save Theme.</p></div></div>
<!-- Banner Upload Section -->
            <div class="wt-card">
              <h3 class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#7c3aed">wallpaper</span>
                Banner Images (Slideshow)
              </h3>
              <div class="form-group">
                <label for="bannerUpload">Upload Banner Images</label>
                <p style="color:#666;font-size:0.9rem;margin-bottom:10px;">Upload multiple banner images to display as a slideshow on your website. Each image will display for 3 seconds. Recommended size: 1200x300px or similar. Max size: 5MB per image</p>
                <input type="file" id="bannerUpload" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" multiple style="margin-bottom:10px;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
                  <label for="bannerPlacementNew" style="margin:0;font-weight:600;font-size:0.9rem;">Show new banners on:</label>
                  <select id="bannerPlacementNew" style="padding:0.45rem 0.6rem;border:2px solid #e5e7eb;border-radius:8px;">
                    <option value="home_top">Home page — top</option>
                    <option value="home_menu">Home page — above the menu</option>
                    <option value="menu_top">Menu page — above the dishes</option>
                    <option value="cart">Cart page — top</option>
                  </select>
                  <span style="font-size:0.8rem;color:#6b7280;">You can change it per banner below.</span>
                </div>
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
<!-- Offer Banners: animated, made in HTML (no photo needed) -->
            <div class="wt-card">
              <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
                <div style="flex:1;min-width:220px;">
                  <h3 class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#7c3aed">auto_awesome</span>
                    Offer Banners (animated)
                  </h3>
                  <p class="wt-desc" style="margin:0">No photo design needed: pick a style, type your offer and code, choose a colour. They move, and they show next to your photo banners. Customers tap the code to copy it.</p>
                </div>
                <button type="button" class="btn btn-primary" id="offerBannerNew"><span class="material-symbols-rounded">add</span>New offer banner</button>
              </div>
              <div id="offerBannerEditor" style="display:none;padding:14px;margin-bottom:14px;border:2px solid #ede9fe;border-radius:14px;background:#faf8ff;"></div>
              <div id="offerBannersGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;"></div>
            </div>
<!-- Restaurant Website Link -->
            <div class="wt-card">
              <h3 class="wt-card-h"><span class="material-symbols-rounded wt-ic" style="--c:#2563eb">link</span>
                Your Restaurant Website Link
              </h3>
              <p class="wt-desc">Share this unique link with your customers. Each restaurant has its own unique URL.</p>
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
            </section>

            <div class="form-actions wt-savebar">
              <button type="button" class="btn btn-primary btn-save" id="saveWebsiteThemeBtn"><span class="material-symbols-rounded">save</span>Save Theme</button>
              <button type="button" class="btn btn-secondary" id="resetWebsiteThemeBtn">
                <span class="material-symbols-rounded">restart_alt</span>
                Reset<span class="wt-long">to Default</span>
              </button>
              <a href="<?php echo $basePath; ?>/<?php
                $restaurant_slug = strtolower($restaurant_name);
                $restaurant_slug = preg_replace('/[^a-z0-9]+/', '-', $restaurant_slug);
                $restaurant_slug = trim($restaurant_slug, '-');
                echo urlencode($restaurant_slug);
              ?>" class="btn btn-secondary" target="_blank"><span class="material-symbols-rounded">open_in_new</span>Open<span class="wt-long">website</span></a>
            </div>
          </div>
          
          <div class="settings-section" id="livePreviewSection">
            <h2 class="settings-section-title">
              <span class="material-symbols-rounded">preview</span>
              Live Website Preview
            </h2>
            <p style="color:#6b7280;font-size:0.8rem;margin:0 0 10px;line-height:1.45;"><strong>Current</strong> shows your changes before you save. <strong>Live</strong> shows the website your customers see now.</p>

            <!-- Current (unsaved) vs Live (saved) -->
            <div id="previewModeToggle" style="position:relative;display:flex;padding:4px;margin-bottom:10px;background:#f3f4f6;border-radius:12px;">
              <span id="previewModeInd" style="position:absolute;top:4px;bottom:4px;left:4px;width:calc(50% - 4px);border-radius:9px;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,0.10);transition:transform .3s cubic-bezier(.22,1,.36,1);"></span>
              <button type="button" class="pv-mode-btn" data-pv="current" onclick="setPreviewMode('current')" style="position:relative;flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:8px 6px;border:0;background:none;border-radius:9px;cursor:pointer;font-family:inherit;font-size:13px;font-weight:700;color:#7c3aed;">
                <span class="material-symbols-rounded" style="font-size:17px;">edit</span>Current <span id="pvUnsavedDot" style="display:none;width:7px;height:7px;border-radius:50%;background:#f59e0b;" title="Unsaved changes"></span>
              </button>
              <button type="button" class="pv-mode-btn" data-pv="live" onclick="setPreviewMode('live')" style="position:relative;flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:8px 6px;border:0;background:none;border-radius:9px;cursor:pointer;font-family:inherit;font-size:13px;font-weight:700;color:#6b7280;">
                <span style="width:8px;height:8px;border-radius:50%;background:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,0.18);"></span>Live
              </button>
            </div>

            <!-- Toolbar: device + refresh + open (one row, so the preview gets the height) -->
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;align-items:center;">
              <button type="button" class="device-btn" data-device="desktop" onclick="setPreviewDevice('desktop')" style="display:flex;align-items:center;gap:5px;padding:6px 12px;border:2px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-weight:600;font-size:.8rem;transition:all .2s;">
                <span class="material-symbols-rounded" style="font-size:17px;">desktop_windows</span>
                Desktop
              </button>
              <button type="button" class="device-btn active" data-device="mobile" onclick="setPreviewDevice('mobile')" style="display:flex;align-items:center;gap:5px;padding:6px 12px;border:2px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-weight:600;font-size:.8rem;transition:all .2s;">
                <span class="material-symbols-rounded" style="font-size:17px;">phone_iphone</span>
                Mobile
              </button>
              <span style="flex:1"></span>
              <button type="button" id="lpBtn" onclick="toggleLivePhone()" title="Open this preview on your phone; it updates live as you change things here" style="display:flex;align-items:center;gap:5px;height:34px;padding:0 10px;border:2px solid #c4b5fd;border-radius:8px;background:#faf5ff;color:#6d28d9;font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap;"><span class="material-symbols-rounded" style="font-size:18px;">qr_code_2</span>Phone</button>
              <a href="layout-gallery.html?site=<?php echo urlencode($basePath . '/' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($restaurant_name)), '-')); ?>" target="_blank" title="See every home & menu layout side by side (nothing is saved)" style="display:flex;align-items:center;gap:5px;height:34px;padding:0 10px;border:2px solid #e5e7eb;border-radius:8px;background:#fff;color:#374151;font-size:12.5px;font-weight:700;text-decoration:none;white-space:nowrap;"><span class="material-symbols-rounded" style="font-size:18px;">grid_view</span>All layouts</a>
              <button type="button" onclick="refreshPreview()" title="Refresh preview" aria-label="Refresh preview" style="display:flex;align-items:center;justify-content:center;width:34px;height:34px;border:2px solid #e5e7eb;border-radius:8px;background:#fff;color:#374151;cursor:pointer;text-decoration:none;"><span class="material-symbols-rounded" style="font-size:19px;">refresh</span></button>
              <a href="<?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $basePath . '/' . urlencode(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($restaurant_name)), '-'))); ?>" target="_blank" title="Open in new tab" aria-label="Open in new tab" style="display:flex;align-items:center;justify-content:center;width:34px;height:34px;border:2px solid #e5e7eb;border-radius:8px;background:#fff;color:#374151;cursor:pointer;text-decoration:none;"><span class="material-symbols-rounded" style="font-size:19px;">open_in_new</span></a>
            </div>

            <!-- Live preview on a phone: QR + link; changes here show up on the phone by themselves -->
            <div id="lpPanel" style="display:none;margin-bottom:10px;padding:14px;border:2px solid #c4b5fd;border-radius:14px;background:linear-gradient(135deg,#faf5ff,#fff);">
              <div style="display:flex;gap:14px;align-items:flex-start;">
                <img id="lpQr" alt="QR code for the phone preview" width="132" height="132" style="flex-shrink:0;border-radius:10px;background:#fff;border:1px solid #ede9fe;padding:6px;">
                <div style="flex:1;min-width:0;">
                  <div style="display:flex;align-items:center;gap:8px;font-weight:800;color:#111827;font-size:.95rem;"><span id="lpDot" style="width:9px;height:9px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.25);"></span><span id="lpStatus">Live on your phone</span></div>
                  <p style="margin:6px 0 10px;font-size:.8rem;color:#4b5563;line-height:1.5;">Scan with your phone camera. Every change you make here shows up on the phone within a second, no refresh needed. Nothing is saved until you click Save.</p>
                  <div style="display:flex;gap:6px;">
                    <input id="lpLink" readonly style="flex:1;min-width:0;padding:7px 9px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:.75rem;background:#fff;color:#374151;">
                    <button type="button" onclick="copyLivePhoneLink()" style="padding:0 10px;border:0;border-radius:8px;background:#111827;color:#fff;font-size:.75rem;font-weight:700;cursor:pointer;">Copy</button>
                    <button type="button" onclick="stopLivePhone()" style="padding:0 10px;border:1.5px solid #fecaca;border-radius:8px;background:#fff;color:#dc2626;font-size:.75rem;font-weight:700;cursor:pointer;">Stop</button>
                  </div>
                  <div id="lpLocalHint" style="display:none;margin-top:8px;font-size:.75rem;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:6px 8px;">This link uses <b>localhost</b>, which a phone can&rsquo;t open. On your live website it just works; on this computer, open the dashboard using your computer&rsquo;s network address (e.g. http://192.168.x.x/…) and the link follows.</div>
                </div>
              </div>
            </div>

            <!-- Live Preview Frame -->
            <div id="previewFrameBox" style="border:2px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;">
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

          </div>
        </div>
      </div>
    </div>
    <!-- Policy Pages -->
    <div id="policyPagesPage" class="page sx lg">
      <div class="page-header">
        <h1>Policy Pages</h1>
        <p>Customize the legal text shown on your public website. Leave a page blank to keep showing the default text.</p>
      </div>
      <div class="page-content">
        <!-- a list of the pages on the left, the editor on the right (pol-* in studio-ui.css) -->
        <div class="pol-layout">
          <aside class="pol-side">
            <div class="pol-side-h"><span class="material-symbols-rounded">gavel</span><div><b>Your legal pages</b><small>Linked in your website’s footer</small></div></div>
            <div id="policyTabs" class="pol-tabs"></div>
            <p class="pol-hint"><span class="material-symbols-rounded">info</span><span>Every page starts with ready-made text that already has your restaurant’s name and contact details. Change only what you need.</span></p>
          </aside>
          <section class="pol-main">
            <div class="pol-head">
              <div><h2 id="policyTitle">Privacy Policy</h2><span id="policyStatusLabel" class="pol-status"></span></div>
              <a href="#" id="policyViewLiveLink" target="_blank" rel="noopener" class="btn btn-secondary btn-sm"><span class="material-symbols-rounded">open_in_new</span>View on website</a>
            </div>
            <div id="policyEditorWrapper" class="pol-editor">
              <div id="policyContentEditor" style="min-height:420px;"></div>
            </div>
            <div class="pol-foot">
              <small><span class="material-symbols-rounded">edit_note</span>Type like a normal document: headings, bold, lists and links from the toolbar.</small>
              <button type="button" class="btn btn-secondary" id="resetPolicyBtn"><span class="material-symbols-rounded">restart_alt</span>Use default text</button>
              <button type="button" class="btn btn-primary" id="savePolicyBtn"><span class="material-symbols-rounded">check</span>Save page</button>
            </div>
          </section>
        </div>
      </div>
    </div>
    <script>
      const POLICY_PAGE_BASE_URL = "<?php
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $policy_restaurant_slug = strtolower($restaurant_name);
        $policy_restaurant_slug = preg_replace('/[^a-z0-9]+/', '-', $policy_restaurant_slug);
        $policy_restaurant_slug = trim($policy_restaurant_slug, '-');
        echo htmlspecialchars($base_url . $basePath . '/' . urlencode($policy_restaurant_slug), ENT_QUOTES, 'UTF-8');
      ?>";

      const POLICY_TYPES = [
        { key: 'privacy', label: 'Privacy Policy', file: 'privacy-policy' },
        { key: 'terms', label: 'Terms of Service', file: 'terms-of-service' },
        { key: 'refund', label: 'Refund Policy', file: 'refund-policy' },
        { key: 'shipping', label: 'Shipping Policy', file: 'shipping-policy' },
        { key: 'cookie', label: 'Cookie Policy', file: 'cookie-policy' }
      ];
      let policyActiveTab = 'privacy';
      let policyStatuses = {};
      let policyEditorInitialized = false;
      let policyQuill = null;
      let policyQuillLoadPromise = null;

      function getPolicyByKey(key) {
        for (var i = 0; i < POLICY_TYPES.length; i++) { if (POLICY_TYPES[i].key === key) return POLICY_TYPES[i]; }
        return POLICY_TYPES[0];
      }

      // Quill is loaded on demand (only when this page is opened) so restaurant
      // owners who never touch Policy Pages don't pay for it on every dashboard load.
      function ensurePolicyEditorLibLoaded() {
        if (window.Quill) return Promise.resolve();
        if (policyQuillLoadPromise) return policyQuillLoadPromise;
        policyQuillLoadPromise = new Promise(function(resolve, reject) {
          var link = document.createElement('link');
          link.rel = 'stylesheet';
          link.href = 'https://cdn.quilljs.com/1.3.6/quill.snow.css';
          document.head.appendChild(link);

          var script = document.createElement('script');
          script.src = 'https://cdn.quilljs.com/1.3.6/quill.min.js';
          script.onload = function() { resolve(); };
          script.onerror = function() { reject(new Error('Failed to load editor library')); };
          document.head.appendChild(script);
        });
        return policyQuillLoadPromise;
      }

      function initPolicyPagesEditor() {
        var tabsContainer = document.getElementById('policyTabs');
        var editorContainer = document.getElementById('policyContentEditor');
        if (!tabsContainer || !editorContainer) return;

        ensurePolicyEditorLibLoaded().then(function() {
          if (!policyEditorInitialized) {
            policyEditorInitialized = true;
            var POLICY_LOOK = { privacy: ['shield', 'What you collect and why'], terms: ['description', 'Rules for ordering'], refund: ['currency_rupee', 'Cancellations and refunds'], shipping: ['local_shipping', 'Delivery areas and times'], cookie: ['cookie', 'Cookies on your website'] };
            tabsContainer.innerHTML = POLICY_TYPES.map(function(p) {
              var lk = POLICY_LOOK[p.key] || ['description', ''];
              return '<button type="button" class="policy-tab-btn" data-policy="' + p.key + '"><span class="pol-ic material-symbols-rounded">' + lk[0] + '</span>' +
                '<span class="pol-tx"><b>' + p.label + '</b><small>' + lk[1] + '</small></span><span class="policy-tab-dot" data-dot="' + p.key + '">Default</span></button>';
            }).join('');

            tabsContainer.querySelectorAll('.policy-tab-btn').forEach(function(btn) {
              btn.addEventListener('click', function() {
                selectPolicyTab(btn.getAttribute('data-policy'));
              });
            });

            policyQuill = new Quill('#policyContentEditor', {
              theme: 'snow',
              placeholder: 'Start typing here — use the toolbar above to add headings, bold text, or lists...',
              modules: {
                toolbar: [
                  [{ header: [2, 3, false] }],
                  ['bold', 'italic', 'underline'],
                  [{ list: 'ordered' }, { list: 'bullet' }],
                  ['link'],
                  ['clean']
                ]
              }
            });

            var saveBtn = document.getElementById('savePolicyBtn');
            if (saveBtn) saveBtn.addEventListener('click', savePolicyContent);
            var resetBtn = document.getElementById('resetPolicyBtn');
            if (resetBtn) resetBtn.addEventListener('click', resetPolicyContent);
          }

          loadPolicyStatuses(function() {
            selectPolicyTab(policyActiveTab);
          });
        }).catch(function() {
          showToastFallback('Could not load the editor. Please refresh and try again.', 'error');
        });
      }

      function loadPolicyStatuses(callback) {
        fetch('../website/policy_pages_api.php?action=get')
          .then(function(r) { return r.json(); })
          .then(function(res) {
            if (res.success && res.policies) {
              policyStatuses = {};
              POLICY_TYPES.forEach(function(p) {
                var entry = res.policies[p.key];
                policyStatuses[p.key] = !!(entry && entry.content && entry.content.trim() !== '');
              });
              updatePolicyTabDots();
            }
            if (callback) callback();
          })
          .catch(function() { if (callback) callback(); });
      }

      function updatePolicyTabDots() {
        POLICY_TYPES.forEach(function(p) {
          var dot = document.querySelector('.policy-tab-dot[data-dot="' + p.key + '"]');
          if (dot) { dot.classList.toggle('custom', !!policyStatuses[p.key]); dot.textContent = policyStatuses[p.key] ? 'Custom' : 'Default'; }
        });
      }

      function updatePolicyTabActiveState(key) {
        document.querySelectorAll('.policy-tab-btn').forEach(function(btn) {
          btn.classList.toggle('on', btn.getAttribute('data-policy') === key);
        });
        var title = document.getElementById('policyTitle');
        if (title) title.textContent = getPolicyByKey(key).label;
      }

      function selectPolicyTab(key) {
        policyActiveTab = key;
        updatePolicyTabActiveState(key);

        var info = getPolicyByKey(key);
        var viewLink = document.getElementById('policyViewLiveLink');
        if (viewLink) viewLink.href = POLICY_PAGE_BASE_URL + '/' + info.file;

        var statusLabel = document.getElementById('policyStatusLabel');
        if (policyQuill) { policyQuill.setText('Loading...'); policyQuill.disable(); }

        fetch('../website/policy_pages_api.php?action=get&policy_type=' + encodeURIComponent(key))
          .then(function(r) { return r.json(); })
          .then(function(res) {
            var hasCustom = !!(res.success && res.content && res.content.trim() !== '');
            if (policyQuill) {
              policyQuill.enable();
              // No custom override yet: pre-fill with the real default wording (with this
              // restaurant's own name/contact details already filled in) so the owner can
              // tweak just the parts they want instead of writing a page from scratch.
              // Use the clipboard converter (not root.innerHTML directly) so lists/headers/etc.
              // are parsed into Quill's own format instead of being silently dropped.
              var htmlToLoad = hasCustom ? res.content : (res.success ? (res.default_html || '') : '');
              policyQuill.clipboard.dangerouslyPasteHTML(htmlToLoad);
            }
            policyStatuses[key] = hasCustom;
            updatePolicyTabDots();
            if (statusLabel) {
              statusLabel.className = 'pol-status ' + (hasCustom ? 'custom' : 'default');
              statusLabel.innerHTML = hasCustom
                ? '<span class="material-symbols-rounded">check_circle</span>Your own text is live on the website'
                : '<span class="material-symbols-rounded">info</span>Showing the default text: edit any part and save to make it yours';
            }
          })
          .catch(function() {
            if (policyQuill) { policyQuill.enable(); policyQuill.setText(''); }
            showToastFallback('Could not load policy content. Please try again.', 'error');
          });
      }

      function savePolicyContent() {
        var btn = document.getElementById('savePolicyBtn');
        if (!btn || !policyQuill) return;
        // Quill's "empty" state is technically "<p><br></p>", not an empty string,
        // so check the plain text length rather than the raw HTML to decide whether
        // to save an override or fall back to the default (deletes the row server-side).
        var isEmpty = policyQuill.getText().trim() === '';
        var htmlContent = isEmpty ? '' : policyQuill.root.innerHTML;
        var origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="loading-spinner"></span> Saving...';

        fetch('../website/policy_pages_api.php?action=save', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ policy_type: policyActiveTab, content: htmlContent })
        })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
            if (res.success) {
              showToastFallback('Policy page saved.', 'success');
              selectPolicyTab(policyActiveTab);
            } else {
              showToastFallback(res.message || 'Could not save. Please try again.', 'error');
            }
          })
          .catch(function() {
            btn.disabled = false;
            btn.innerHTML = origHtml;
            showToastFallback('Could not save. Please check your connection.', 'error');
          });
      }

      function resetPolicyContent() {
        var doReset = function() {
          var btn = document.getElementById('resetPolicyBtn');
          var origHtml = btn.innerHTML;
          btn.disabled = true;
          btn.innerHTML = '<span class="loading-spinner"></span> Resetting...';

          fetch('../website/policy_pages_api.php?action=reset', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ policy_type: policyActiveTab })
          })
            .then(function(r) { return r.json(); })
            .then(function(res) {
              btn.disabled = false;
              btn.innerHTML = origHtml;
              if (res.success) {
                showToastFallback('Restored default text.', 'success');
                selectPolicyTab(policyActiveTab);
              } else {
                showToastFallback(res.message || 'Could not reset. Please try again.', 'error');
              }
            })
            .catch(function() {
              btn.disabled = false;
              btn.innerHTML = origHtml;
              showToastFallback('Could not reset. Please check your connection.', 'error');
            });
        };

        if (window.Swal) {
          Swal.fire({
            title: 'Reset to default text?',
            text: 'This will discard your custom content for this page and go back to the built-in wording.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Reset',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc2626'
          }).then(function(result) {
            if (result.isConfirmed) doReset();
          });
        } else if (confirm('Reset to default text? This will discard your custom content for this page.')) {
          doReset();
        }
      }
    </script>
    <!-- How to use: the user guide, opened at the screen the owner came from -->
    <div id="howToPage" class="page sx">
      <style>
        .howto-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
        .howto-bar p { margin: 0; color: #6b7280; font-size: .88rem; }
        .howto-bar p b { color: #111827; }
        .howto-acts { display: flex; flex-wrap: wrap; gap: 8px; }
        .howto-acts a { text-decoration: none; }
        .howto-frame { width: 100%; height: calc(100vh - 210px); min-height: 520px; border: 1px solid #e5e7eb; border-radius: 16px; background: #fff; box-shadow: 0 10px 30px -20px rgba(15,23,42,.35); }
      </style>
      <div class="page-header">
        <h1>How to use</h1>
        <p>Every screen explained step by step, with pictures. Search for any feature.</p>
      </div>
      <div class="page-content">
        <div class="howto-bar">
          <p id="howToFrom">Start with the <b>Quick start checklist</b> to go live in ten steps.</p>
          <div class="howto-acts">
            <button type="button" class="btn btn-secondary" onclick="window.rgStartTour && rgStartTour()"><span class="material-symbols-rounded">rocket_launch</span> Take the tour</button>
            <button type="button" class="btn btn-secondary" onclick="window.rgShowSetupGuide && rgShowSetupGuide()"><span class="material-symbols-rounded">task_alt</span> Setup guide</button>
            <button type="button" class="btn btn-secondary" onclick="openHowTo(null, 'quick-start')"><span class="material-symbols-rounded">checklist</span> Quick start</button>
            <button type="button" class="btn btn-secondary" onclick="rgOpenSupport(event)"><span class="material-symbols-rounded">support_agent</span> Contact support</button>
            <a class="btn btn-primary" id="howToNewTab" href="user-guide.html" target="_blank" rel="noopener"><span class="material-symbols-rounded">open_in_new</span> Open in a new tab</a>
          </div>
        </div>
        <iframe id="howToFrame" class="howto-frame" title="RestroGrow guide" loading="lazy"></iframe>
      </div>
    </div>
    <script>
      // "How to use": open the guide at the screen (and Studio / WhatsApp tab) the owner was just on
      function openHowTo(fromPage, anchor) {
        var labels = { growthSettingsPage: 'Loyalty Studio', whatsappPage: 'WhatsApp' };
        var hash = '', where = '';
        if (anchor) hash = anchor;
        else if (fromPage && fromPage.id && fromPage.id !== 'howToPage') {
          var tab = fromPage.querySelector('.ls-tab.on');
          if (fromPage.id === 'growthSettingsPage' && tab) {
            hash = { program: 'loyalty', card: 'phonecard' }[tab.getAttribute('data-ls-tab')] || tab.getAttribute('data-ls-tab');
          } else if (fromPage.id === 'whatsappPage' && tab && tab.getAttribute('data-wa-tab') === 'camp') {
            hash = 'wacampaigns';
          } else {
            hash = 'from-' + fromPage.id;
          }
          var h1 = fromPage.querySelector('.page-header h1');
          where = labels[fromPage.id] || (h1 ? h1.textContent.trim() : '');
        }
        var frame = document.getElementById('howToFrame'), note = document.getElementById('howToFrom'), nt = document.getElementById('howToNewTab');
        var url = 'user-guide.html' + (hash ? '#' + hash : '');
        if (note) note.innerHTML = where ? 'Showing how to use <b>' + where.replace(/[<>&]/g, '') + '</b>. Scroll or search for anything else.' : 'Start with the <b>Quick start checklist</b> to go live in ten steps.';
        if (nt) nt.href = url;
        if (!frame) return;
        frame.src = 'user-guide.html?v=' + Date.now() + (hash ? '&go=' + encodeURIComponent(hash) : '');   // fresh load; ?go= not #, so the dashboard itself doesn't scroll
      }
    </script>

    <!-- Customer Website > Custom Domain: own domain + embed code (assets/js/custom-domain-studio.js) -->
    <div id="embedPage" class="page sx">
      <div class="page-header">
        <h1>Custom Domain</h1>
        <p>Put your ordering website on your own web address, or add your menu to a website you already have.</p>
      </div>
      <div class="page-content">
        <div id="cdRoot"><div class="loading" data-loading-fallback>Loading...</div></div>
      </div>
    </div>
    <!-- Category Management Page -->
    <div id="menuPage" class="page sx lg">
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
          <div class="loading" data-loading-fallback>Loading menus...</div>
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
    <div id="menuItemsPage" class="page sx lg">
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
          <div class="loading" data-loading-fallback>Loading menu items...</div>
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
    <div id="addonsPage" class="page sx lg">
      <div class="page-header">
        <h1>Add-ons Management</h1>
        <p>Manage your add-ons like extra cheese, ketchup, mayonnaise etc.</p>
      </div>
      <div class="page-content">
        <div id="addonsList" style="min-height:400px;">
          <div class="loading" data-loading-fallback>Loading add-ons management...</div>
        </div>
      </div>
    </div>

    <!-- Blocked Customers (order abuse blocklist) Page -->
    <div id="blocklistPage" class="page sx lg">
      <div class="page-header">
        <h1>Blocked Customers</h1>
        <p>Phones/IPs blocked from placing website orders — auto-blocked after 3 fake-order strikes, or blocked manually</p>
      </div>
      <div class="page-content">
        <div id="blocklistContainer" style="min-height:400px;">
          <div class="loading" data-loading-fallback>Loading blocked customers...</div>
        </div>
      </div>
    </div>

    <?php if ($meal_subscriptions_enabled): ?>
    <!-- Meal Plans Page -->
    <div id="mealPlansPage" class="page sx lg">
      <div class="page-header">
        <h1>Meal Plans</h1>
        <p>Define tiffin/subscription bundles and their weekly menu</p>
      </div>
      <div class="page-content">
        <div id="mealPlansList" style="min-height:400px;">
          <div class="loading" data-loading-fallback>Loading meal plans management...</div>
        </div>
      </div>
    </div>

    <!-- Meal Subscriptions (Subscribers) Page -->
    <div id="mealSubscriptionsPage" class="page sx lg">
      <div class="page-header">
        <h1>Subscribers</h1>
        <p>View and manage customer meal plan subscriptions</p>
      </div>
      <div class="page-content">
        <div id="mealSubscriptionsList" style="min-height:400px;">
          <div class="loading" data-loading-fallback>Loading subscribers...</div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Catering Page -->
    <div id="cateringPage" class="page sx">
      <div class="page-header">
        <h1>Catering</h1>
        <p>Price per guest for parties and events, and the catering requests customers send you.</p>
      </div>
      <div class="page-content">
        <div id="cateringList" style="min-height:400px;">
          <div class="loading" data-loading-fallback>Loading catering management...</div>
        </div>
      </div>
    </div>

    <!-- Inventory Page -->
    <!-- Menu › Inventory: stock, recipes, purchase orders, suppliers, stock count, activity, reports
         (assets/js/inventory-studio.js, data api/inventory_studio.php, styles iv-* in studio-ui.css) -->
    <div id="inventoryPage" class="page sx lg">
      <div class="page-header">
        <h1>Inventory</h1>
        <p>Stock, recipes and purchases. Orders take stock out by themselves.</p>
      </div>
      <div class="page-content">
        <div id="invRoot" class="iv"></div>
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
    <div id="paymentsPage" class="page sx lg">
      <div class="page-header">
        <h1>Payment Transactions</h1>
        <p>View and manage all payment transactions</p>
      </div>
      <div class="page-content">
        <div class="sx-toolbar lg-toolbar">
          <div class="sx-search"><span class="material-symbols-rounded">search</span><input type="text" id="paymentSearch" placeholder="Search by order or amount"></div>
          <select id="paymentMethodFilter" class="filter-select">
            <option value="">All Methods</option>
            <option value="Cash">Cash</option>
            <option value="Card">Card</option>
            <option value="UPI">UPI</option>
            <option value="Online">Online</option>
            <option value="Wallet">Wallet</option>
          </select>
          <select id="paymentStatusFilter" class="filter-select">
            <option value="">All Status</option>
            <option value="Success">Success</option>
            <option value="Failed">Failed</option>
            <option value="Pending">Pending</option>
            <option value="Refunded">Refunded</option>
          </select>
          <div class="sx-toolbar-r"><button type="button" class="btn btn-secondary" onclick="exportPaymentsToCSV()"><span class="material-symbols-rounded">download</span>Export CSV</button></div>
        </div>

        <div class="card lg-card lg-scroll">
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
    <!-- Payments › Payment Gateway: online payments (PhonePe) and the payment methods the POS offers.
         Save: admin/auth.php action=updatePaymentGateway (unchanged). Methods: script.js loadPaymentMethods / renderPaymentMethods. Styles gw-* in studio-ui.css. -->
    <div id="gatewayPage" class="page sx lg">
      <div class="page-header">
        <h1>Payment Gateway</h1>
        <p>Online payments on your website, and the payment methods your POS offers</p>
      </div>
      <div class="page-content">
        <?php
        // PhonePe takes Indian rupees only: outside India there is no online payment here, just support
        $gwIndia = restaurantIsIndia(html_entity_decode((string)($country ?? 'India')));
        $gwOn = $gwIndia && ($payment_gateway_type_db ?? 'cash_only') !== 'cash_only';
        $gwMode = $payment_gateway_mode ?? 'own';
        $gwLive = ($phonepe_environment ?? 'SANDBOX') === 'PRODUCTION';
        $gwKeys = trim($phonepe_merchant_id ?? '') !== '' && trim($phonepe_salt_key ?? '') !== '';
        if (!$gwIndia) { $gwState = ['off', 'Online payments: contact support', 'Online payment through PhonePe works for restaurants in India only. Customers can pay cash, or by your payment QR at the counter. To take online payments in ' . html_entity_decode((string)$country) . ', contact RestroGrow support on WhatsApp +91 63775 68749 or support@restrogrow.com.']; }
        elseif (!$gwOn) { $gwState = ['off', 'Online payments are off', 'Customers pay cash, or by UPI QR at the counter. Online payment (UPI, cards, net banking through PhonePe) is switched on for your restaurant by RestroGrow support.']; }
        elseif ($gwMode === 'platform') { $gwState = ['ok', 'Online payments are on', 'Through the RestroGrow platform account. Nothing to set up; money reaches your bank in 2 working days.']; }
        elseif (!$gwKeys) { $gwState = ['warn', 'Add your PhonePe keys', 'Online payments are allowed, but your own PhonePe Client ID and Secret aren’t saved yet, so customers can’t pay online.']; }
        elseif (!$gwLive) { $gwState = ['warn', 'Test mode', 'Your PhonePe keys are saved in Sandbox. Payments are only tests; switch to Live when PhonePe approves your account.']; }
        else { $gwState = ['ok', 'Online payments are live', 'Payments go straight to your own PhonePe account.']; }
        ?>
        <div class="gw-hero <?php echo $gwState[0]; ?>">
          <span class="sx-ic material-symbols-rounded"><?php echo $gwState[0] === 'ok' ? 'verified' : ($gwState[0] === 'off' ? 'payments' : 'error'); ?></span>
          <div>
            <b><?php echo htmlspecialchars($gwState[1]); ?></b>
            <span><?php echo htmlspecialchars($gwState[2]); ?></span>
          </div>
          <?php if ($gwOn): ?>
          <div class="gw-tags">
            <span class="sx-pill <?php echo $gwMode === 'platform' ? 'ok' : 'muted'; ?>"><?php echo $gwMode === 'platform' ? 'Platform account' : 'Your PhonePe'; ?></span>
            <?php if ($gwMode === 'own'): ?><span class="sx-pill <?php echo $gwLive ? 'ok' : 'warn'; ?>"><?php echo $gwLive ? 'Live' : 'Test (Sandbox)'; ?></span><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($gwOn): ?>
        <form id="paymentGatewayForm" class="gw-form">
          <section class="lg-card gw-card">
            <div class="lg-card-h"><h2>How customers pay online</h2></div>
            <div class="gw-modes">
              <label class="gw-mode<?php echo $gwMode === 'platform' ? ' on' : ''; ?>">
                <input type="radio" name="payment_gateway_mode" id="gwModePlatform" value="platform" <?php echo $gwMode === 'platform' ? 'checked' : ''; ?> onchange="toggleGatewayMode()">
                <span class="sx-ic material-symbols-rounded" style="--c:#16a34a">bolt</span>
                <span class="gw-mode-t"><b>RestroGrow platform account</b><small>No setup. We collect the payment and settle it to your bank in 2 working days.</small></span>
                <span class="gw-radio"></span>
              </label>
              <label class="gw-mode<?php echo $gwMode === 'own' ? ' on' : ''; ?>">
                <input type="radio" name="payment_gateway_mode" id="gwModeOwn" value="own" <?php echo $gwMode === 'own' ? 'checked' : ''; ?> onchange="toggleGatewayMode()">
                <span class="sx-ic material-symbols-rounded" style="--c:#7c3aed">key</span>
                <span class="gw-mode-t"><b>My own PhonePe account</b><small>Use your PhonePe business keys. Money goes straight to your account.</small></span>
                <span class="gw-radio"></span>
              </label>
            </div>

            <div id="ownCredentialsSection" class="gw-keys" style="display: <?php echo $gwMode === 'own' ? 'block' : 'none'; ?>;">
              <div class="gw-keys-h"><span class="material-symbols-rounded">phone_android</span><b>PhonePe keys</b><small>From the PhonePe Business dashboard › Developer settings</small></div>
              <div class="gw-grid">
                <label class="gw-field" for="phonepeMerchantId"><span>Client ID</span>
                  <input type="text" id="phonepeMerchantId" name="phonepe_merchant_id" placeholder="e.g. M22ABCXYZ" autocomplete="off" value="<?php echo htmlspecialchars($phonepe_merchant_id ?? ''); ?>">
                </label>
                <label class="gw-field" for="phonepeSaltKey"><span>Client Secret</span>
                  <span class="gw-secret">
                    <input type="password" id="phonepeSaltKey" name="phonepe_salt_key" placeholder="<?php echo ($phonepe_salt_key ?? '') !== '' ? 'Saved (' . htmlspecialchars($phonepe_salt_key) . '). Leave blank to keep it' : 'Your PhonePe Client Secret'; ?>" autocomplete="new-password" value="">
                    <button type="button" class="sx-icon-btn" onclick="var i=document.getElementById('phonepeSaltKey');i.type=i.type==='password'?'text':'password';this.querySelector('span').textContent=i.type==='password'?'visibility':'visibility_off'" title="Show or hide"><span class="material-symbols-rounded">visibility</span></button>
                  </span>
                </label>
              </div>
              <div class="gw-env">
                <span>Mode</span>
                <select id="phonepeEnvironment" name="phonepe_environment">
                  <option value="SANDBOX" <?php echo ($phonepe_environment ?? 'SANDBOX') === 'SANDBOX' ? 'selected' : ''; ?>>Sandbox (test payments)</option>
                  <option value="PRODUCTION" <?php echo ($phonepe_environment ?? '') === 'PRODUCTION' ? 'selected' : ''; ?>>Production (live payments)</option>
                </select>
                <small>Use Sandbox until PhonePe has approved your account, then switch to Production.</small>
              </div>
              <?php require_once __DIR__ . '/../config/phonepe_verify.php'; $gwHook = ppWebhookCreds((string)$restaurant_id); if ($gwHook): ?>
              <div class="gw-hook" style="margin-top:16px;padding:14px;border:1px dashed var(--border,#e5e7eb);border-radius:12px;">
                <div class="gw-keys-h"><span class="material-symbols-rounded">webhook</span><b>Webhook (so payments never get stuck)</b><small>PhonePe Business dashboard › Developer settings › Webhooks › Create webhook</small></div>
                <div class="gw-grid">
                  <label class="gw-field"><span>Webhook URL</span><input type="text" readonly value="<?php echo htmlspecialchars($gwHook['url']); ?>" onclick="this.select()"></label>
                  <label class="gw-field"><span>Username</span><input type="text" readonly value="<?php echo htmlspecialchars($gwHook['username']); ?>" onclick="this.select()"></label>
                  <label class="gw-field"><span>Password</span><input type="text" readonly value="<?php echo htmlspecialchars($gwHook['password']); ?>" onclick="this.select()"></label>
                  <label class="gw-field"><span>Events to tick</span><input type="text" readonly value="checkout.order.completed, checkout.order.failed"></label>
                </div>
              </div>
              <?php endif; ?>
            </div>

            <div class="gw-save">
              <span class="sx-muted">Saved changes apply to your website straight away.</span>
              <button type="submit" class="btn btn-primary"><span class="material-symbols-rounded">save</span>Save</button>
            </div>
          </section>
        </form>
<script>
function toggleGatewayMode() {
  var mode = document.querySelector('input[name="payment_gateway_mode"]:checked');
  if (!mode) return;
  var section = document.getElementById('ownCredentialsSection');
  if (section) section.style.display = mode.value === 'own' ? 'block' : 'none';
  document.querySelectorAll('#gatewayPage .gw-mode').forEach(function (l) { var r = l.querySelector('input[type="radio"]'); l.classList.toggle('on', !!(r && r.checked)); });
}
(function(){
  var f = document.getElementById('paymentGatewayForm');
  if (f && !f.dataset.gwHandler) {
    f.dataset.gwHandler = '1';
    f.addEventListener('submit', async function(e) {
      e.preventDefault();
      var btn = f.querySelector('button[type="submit"]');
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
        <?php endif; ?>

        <section class="lg-card gw-card">
          <div class="lg-card-h gw-methods-h">
            <div><h2>Payment methods</h2><p>Cash, Card and UPI are built in. Add others you accept at the counter (wallet, bank transfer…); they show up at the POS and in your reports. Upload a QR to show it when a customer pays by that method.</p></div>
            <button type="button" class="btn btn-primary" onclick="openPaymentMethodModal()"><span class="material-symbols-rounded">add</span>Add method</button>
          </div>
          <div id="paymentMethodsList" class="gw-methods">
            <div class="sx-loading"><span></span><span></span><span></span></div>
          </div>
        </section>
      </div>
    </div>

    <!-- Reports Page -->
    <div id="reportsPage" class="page sx lg">
      <div class="page-header">
        <h1>Sales Reports</h1>
        <p>View detailed sales reports and analytics</p>
      </div>
      <div class="page-content">
        <!-- Report Filter Controls -->
        <div class="report-filters lg-filters">
          <div class="lg-field">
            <label>Period</label>
            <select id="reportPeriod">
              <option value="today">Today</option>
              <option value="week">This Week</option>
              <option value="month">This Month</option>
              <option value="year">This Year</option>
              <option value="custom">Custom Date Range</option>
            </select>
          </div>
          <div id="customDateRange" class="lg-field" style="display: none;">
            <label>Start Date</label>
            <input type="date" id="reportStartDate">
          </div>
          <div id="customDateRangeEnd" class="lg-field" style="display: none;">
            <label>End Date</label>
            <input type="date" id="reportEndDate">
          </div>
          <div class="lg-field">
            <label>Report Type</label>
            <select id="reportType">
              <option value="sales">Sales Report</option>
              <option value="customers">Customer Report</option>
              <option value="items">Top Items Report</option>
              <option value="payment">Payment Methods Report</option>
              <option value="hourly">Hourly Sales Report</option>
              <option value="staff">Staff Performance Report</option>
            </select>
          </div>
          <div id="paymentMethodFilterWrapper" class="lg-field" style="display: none;">
            <label>Payment Method</label>
            <select id="filterPaymentMethod">
              <option value="all">All Methods</option>
              <option value="Cash">Cash</option>
              <option value="Card">Card</option>
              <option value="UPI">UPI</option>
            </select>
          </div>
          <div class="lg-filter-acts">
            <button type="button" class="btn btn-secondary" onclick="loadReports()" title="Refresh"><span class="material-symbols-rounded">refresh</span>Refresh</button>
            <button type="button" class="btn btn-secondary" onclick="exportReportsToCSV()"><span class="material-symbols-rounded">download</span>CSV</button>
            <button type="button" class="btn btn-secondary" onclick="exportReportsToPDF()"><span class="material-symbols-rounded">picture_as_pdf</span>PDF</button>
          </div>
        </div>

        <div class="lg-stats">
          <div class="lg-stat" style="--c:#2563eb"><span class="sx-ic material-symbols-rounded">payments</span><div><small>Total sales</small><b id="reportTotalSales"><?php echo htmlspecialchars($currency_symbol); ?>0.00</b></div></div>
          <div class="lg-stat" style="--c:#7c3aed"><span class="sx-ic material-symbols-rounded">receipt_long</span><div><small>Orders</small><b id="reportTotalOrders">0</b></div></div>
          <div class="lg-stat" style="--c:#0891b2"><span class="sx-ic material-symbols-rounded">shopping_bag</span><div><small>Items sold</small><b id="reportTotalItems">0</b></div></div>
          <div class="lg-stat" style="--c:#d97706"><span class="sx-ic material-symbols-rounded">group</span><div><small>Customers</small><b id="reportTotalCustomers">0</b></div></div>
          <div class="lg-stat" style="--c:#dc2626"><span class="sx-ic material-symbols-rounded">trending_down</span><div><small>Expenses</small><b id="reportTotalExpenses"><?php echo htmlspecialchars($currency_symbol); ?>0.00</b></div></div>
          <div class="lg-stat" style="--c:#16a34a"><span class="sx-ic material-symbols-rounded">trending_up</span><div><small>Net profit (sales − expenses)</small><b id="reportNetProfit"><?php echo htmlspecialchars($currency_symbol); ?>0.00</b></div></div>
        </div>

        <!-- Sales Data Table -->
        <div class="lg-card">
          <div class="lg-card-h"><h2>Sales details</h2></div>
          <div class="lg-scroll">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Order #</th>
                  <th>Customer</th>
                  <th>Items</th>
                  <th>Payment</th>
                  <th style="text-align: right;">Amount</th>
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
        <div class="lg-grid2">
          <div class="lg-card">
            <div class="lg-card-h"><h2>Top selling items</h2></div>
            <div id="reportTopItems" style="padding: 1rem;">
              <div style="text-align: center; padding: 2rem; color: #666;">Loading...</div>
            </div>
          </div>
          
          <div class="lg-card">
            <div class="lg-card-h"><h2>Payment methods</h2></div>
            <div id="reportPaymentMethods" style="padding: 1rem;">
              <div style="text-align: center; padding: 2rem; color: #666;">Loading...</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Analytics Page -->
    <div id="analyticsPage" class="page sx lg">
      <div class="page-header">
        <div class="dashboard-header-row">
          <div>
            <h1>Analytics</h1>
            <p>Track your restaurant's performance, traffic, and insights</p>
          </div>
          <div style="display:flex;align-items:center;gap:12px;">
            <select id="analyticsPeriod" onchange="window.loadAnalytics()">
              <option value="7">Last 7 Days</option>
              <option value="30" selected>Last 30 Days</option>
              <option value="90">Last 90 Days</option>
            </select>
            <button class="btn-refresh-dashboard" onclick="window.loadAnalytics()" title="Refresh">
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

    <!-- Marketing > Posters Page -->
    <div id="marketingPostersPage" class="page sx mk">
      <div class="page-header">
        <div>
          <h1>Posters</h1>
          <p>Pick a design and a dish, attach an offer, then download in every size with a caption ready to post.</p>
        </div>
      </div>
      <div class="page-content">
        <div class="poster-studio">
          <div class="poster-controls-panel">
            <div class="poster-panel-title">1. Pick a design</div>
            <div id="posterTemplateGrid" class="poster-template-grid"></div>
            <div id="posterFieldsContainer" class="poster-fields"></div>
          </div>
          <div class="poster-preview-panel">
            <div class="poster-canvas-wrap">
              <canvas id="posterCanvas" width="1080" height="1080"></canvas>
            </div>
            <button type="button" class="btn btn-primary mk-main" onclick="downloadPoster()">
              <span class="material-symbols-rounded" style="font-size:18px;">download</span> Download poster
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Marketing > Videos Page -->
    <div id="marketingVideosPage" class="page sx mk">
      <div class="page-header">
        <div>
          <h1>Videos</h1>
          <p>Short animated promos for WhatsApp Status, Instagram Reels and Facebook, square or upright.</p>
        </div>
      </div>
      <div class="page-content">
        <div class="poster-studio">
          <div class="poster-controls-panel">
            <div class="poster-panel-title">1. Pick an animation</div>
            <div id="videoTemplateGrid" class="poster-template-grid"></div>
            <div id="videoFieldsContainer" class="poster-fields"></div>
          </div>
          <div class="poster-preview-panel">
            <div class="poster-canvas-wrap">
              <canvas id="videoCanvas" width="1080" height="1080"></canvas>
            </div>
            <button type="button" class="btn btn-primary mk-main" id="recordVideoBtn">
              <span class="material-symbols-rounded" style="font-size:18px;">videocam</span>
              <span class="video-record-label">Record &amp; Download Video</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Marketing > Social Captions Page -->
    <div id="marketingCaptionsPage" class="page sx mk">
      <div class="page-header">
        <div>
          <h1>Social Captions</h1>
          <p>Captions in English, Hinglish or Hindi with your tracked link and hashtags. Edit, copy and post.</p>
        </div>
      </div>
      <div class="page-content">
        <div class="caption-studio">
          <div class="caption-controls-panel">
            <div class="poster-panel-title">1. What are you promoting?</div>
            <div class="poster-fields">
              <div class="poster-field">
                <label for="captionPostType">Post Type</label>
                <select id="captionPostType">
                  <option value="new_item">New Dish / Menu Item</option>
                  <option value="discount">Discount / Offer</option>
                  <option value="combo">Combo Deal</option>
                  <option value="happy_hour">Happy Hour</option>
                  <option value="weekend">Weekend Special</option>
                  <option value="festive">Festive / Event Special</option>
                  <option value="now_open">Grand Opening / Now Open</option>
                  <option value="we_deliver">Delivery Available</option>
                  <option value="testimonial">Customer Review / Testimonial</option>
                  <option value="general">General Promotion</option>
                </select>
              </div>
              <div class="poster-field">
                <label for="captionSubject">Dish / Offer Name</label>
                <input type="text" id="captionSubject" maxlength="60" placeholder="e.g. Signature Butter Chicken">
              </div>
              <div class="poster-field">
                <label for="captionDetail">Details (price, timing, etc.) - optional</label>
                <input type="text" id="captionDetail" maxlength="80" placeholder="e.g. Starting at 249, this weekend only">
              </div>
              <div class="poster-field">
                <label for="captionTone">Tone</label>
                <select id="captionTone">
                  <option value="fun">Fun & Casual</option>
                  <option value="elegant">Elegant</option>
                  <option value="urgent">Urgent / FOMO</option>
                </select>
              </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:16px;">
              <button type="button" class="mk-btn big" style="flex:1;" onclick="generateCaptions(true)">
                <span class="material-symbols-rounded" style="font-size:18px;">auto_awesome</span> Generate Captions
              </button>
              <button type="button" class="mk-btn ghost big" title="Get different wording for the same inputs" aria-label="Different wording" onclick="generateCaptions(true)">
                <span class="material-symbols-rounded" style="font-size:18px;">shuffle</span>
              </button>
            </div>
          </div>
          <div class="caption-results-panel">
            <div class="poster-panel-title">2. Pick a caption, edit it, post it</div>
            <div id="captionResultsContainer" class="caption-results"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Marketing > My campaigns: saved designs and their results (assets/js/marketing-studio.js) -->
    <div id="marketingDesignsPage" class="page sx mk">
      <div class="page-header">
        <div>
          <h1>My campaigns</h1>
          <p>Every poster, video and caption you saved, with how many people opened it and the orders it brought in.</p>
        </div>
      </div>
      <div class="page-content">
        <div id="mkDesignsRoot"></div>
      </div>
    </div>

    <!-- Marketing > Campaign Calendar Page -->
    <div id="marketingCalendarPage" class="page sx mk">
      <div class="page-header">
        <div>
          <h1>Campaign Calendar</h1>
          <p>Festivals, national days and food days with an offer idea for each. Start a poster, caption or offer, already filled in.</p>
        </div>
      </div>
      <div class="page-content">
        <div id="campaignCalendarGrid" class="campaign-calendar-grid"></div>
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
    <!-- Offers home: every offer tool, where customers see it, on or off (assets/js/offers-studio.js) -->
    <div id="offersHomePage" class="page sx">
      <div class="page-header">
        <h1>Offers</h1>
        <p>Every way to bring customers in and raise the bill, in one place. Pick one to set it up.</p>
      </div>
      <div class="page-content">
        <div id="offersHomeRoot"></div>
      </div>
    </div>

    <!-- Offers › Coupons (assets/js/offers-studio.js; the add / edit form is #couponModal) -->
    <div id="couponsPage" class="page sx">
      <div class="page-header">
        <h1>Coupons</h1>
        <p>Codes customers type at checkout for money off. See which ones bring orders, pause or edit them anytime.</p>
      </div>
      <div class="page-content">
        <div id="couponsRoot"><div class="loading" data-loading-fallback>Loading coupons...</div></div>
      </div>
    </div>
    <!-- ═══════ Growth Module: Loyalty Settings ═══════ -->
    <div id="growthSettingsPage" class="page sx">
      <style>
        .ls-tabs { display: flex; gap: 6px; overflow-x: auto; margin-bottom: 16px; padding: 5px; border-radius: 14px; background: #f3f4f6; scrollbar-width: none; }
        .ls-tab { flex: 0 0 auto; display: flex; align-items: center; gap: 6px; padding: 9px 14px; border: 0; border-radius: 10px; background: none; color: #4b5563; font: inherit; font-size: .88rem; font-weight: 700; cursor: pointer; }
        .ls-tab .material-symbols-rounded { font-size: 19px; }
        .ls-tab.on { background: #fff; color: #6d28d9; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .ls-hero { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 14px; align-items: center; padding: 18px; border-radius: 18px; color: #fff; background: radial-gradient(90% 140% at 100% 0%, #6d28d9, transparent 60%), #1c1230; margin-bottom: 18px; }
        .ls-hero p { margin: 6px 0 0; font-size: .84rem; color: rgba(255,255,255,.75); }
        .ls-hero .up-switch b { color: #fff; }
        .ls-kpis { display: flex; gap: 10px; }
        .ls-kpis div { min-width: 92px; padding: 10px 12px; border-radius: 12px; background: rgba(255,255,255,.1); text-align: center; }
        .ls-kpis b { display: block; font-size: 1.3rem; }
        .ls-kpis span { font-size: .72rem; color: rgba(255,255,255,.7); }
        .ls-h { margin: 0 0 10px; font-size: 1rem; color: #111827; }
        .ls-types { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 14px; }
        .ls-type { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; padding: 16px; border: 2px solid #e5e7eb; border-radius: 16px; background: #fff; text-align: left; font: inherit; cursor: pointer; transition: border-color .15s, box-shadow .15s; }
        .ls-type .material-symbols-rounded { font-size: 28px; color: #9ca3af; margin-bottom: 4px; }
        .ls-type b { font-size: 1rem; color: #111827; }
        .ls-type b em { margin-left: 4px; padding: 1px 7px; border-radius: 999px; background: #ede9fe; color: #6d28d9; font-size: .65rem; font-style: normal; vertical-align: 2px; }
        .ls-type small { font-size: .8rem; color: #374151; }
        .ls-type i { font-size: .74rem; color: #6b7280; font-style: normal; }
        .ls-type.on { border-color: #7c3aed; background: #faf5ff; box-shadow: 0 8px 20px -12px rgba(109,40,217,.5); }
        .ls-type.on .material-symbols-rounded { color: #7c3aed; }
        .ls-box { padding: 16px; border: 1px solid #e5e7eb; border-radius: 16px; background: #fff; }
        .ls-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
        .ls-grid label { display: flex; flex-direction: column; gap: 5px; font-size: .78rem; font-weight: 700; color: #6b7280; }
        #growthSettingsPage .ls-grid input, #growthSettingsPage .ls-grid select { padding: 9px 11px; border: 1.5px solid #e5e7eb; border-radius: 10px; font: inherit; font-size: .9rem; color: #111827; background: #fff; }
        .ls-example { display: flex; gap: 10px; align-items: flex-start; margin-top: 12px; padding: 12px 14px; border-radius: 12px; background: #f5f3ff; color: #3b0764; font-size: .86rem; line-height: 1.5; }
        .ls-example .material-symbols-rounded { color: #7c3aed; }
        .ls-stamp-preview { margin-bottom: 14px; padding: 14px; border-radius: 14px; background: #1c1230; }
        .ls-stamp-preview small { display: block; margin-top: 8px; color: rgba(255,255,255,.6); font-size: .72rem; }
        .ls-stamps { display: flex; flex-wrap: wrap; gap: 8px; }
        .ls-st { width: 38px; height: 38px; display: grid; place-items: center; border-radius: 50%; border: 2px dashed rgba(255,255,255,.35); color: rgba(255,255,255,.6); font-weight: 800; font-size: .8rem; }
        .ls-st.on { border: 0; background: linear-gradient(135deg, #ffe08a, #d99a1e); color: #3b2604; }
        .ls-st.gift { border-style: solid; border-color: #f5c451; color: #f5c451; }
        .ls-st .material-symbols-rounded { font-size: 20px; }
        .lsa-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); grid-auto-flow: row dense; gap: 12px; align-items: start; margin-bottom: 22px; }
        .lsa-card { padding: 14px; border: 1px solid #e5e7eb; border-radius: 16px; background: #fff; transition: border-color .15s, box-shadow .15s; }
        .lsa-card.on { border-color: #ddd6fe; box-shadow: 0 10px 24px -18px rgba(109,40,217,.55); }
        .lsa-card.open { grid-column: 1 / -1; border-color: #a78bfa; }
        .lsa-top { display: flex; align-items: center; gap: 12px; }
        .lsa-ic { width: 42px; height: 42px; flex-shrink: 0; display: grid; place-items: center; border-radius: 12px; background: #f3f4f6; color: #9ca3af; font-size: 23px; }
        .lsa-card.on .lsa-ic { background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: #fff; }
        .lsa-t { flex: 1; min-width: 0; }
        .lsa-t b { display: block; font-size: .95rem; color: #111827; }
        .lsa-t small { display: block; margin-top: 2px; font-size: .78rem; color: #6b7280; }
        .lsa-t em { font-style: normal; font-weight: 700; color: #6d28d9; }
        .lsa-card:not(.on) .lsa-t em { color: #6b7280; }
        .lsa-sw { margin: 0; }
        .lsa-nums { display: flex; align-items: center; gap: 6px; margin-top: 12px; padding-top: 12px; border-top: 1px dashed #e5e7eb; }
        .lsa-nums div { flex: 1; min-width: 0; }
        .lsa-nums b { display: block; font-size: .98rem; color: #111827; }
        .lsa-nums span { font-size: .7rem; color: #9ca3af; font-weight: 600; }
        .lsa-edit { display: flex; align-items: center; gap: 4px; padding: 7px 11px; border: 1.5px solid #e5e7eb; border-radius: 10px; background: #fff; color: #374151; font: inherit; font-size: .8rem; font-weight: 700; cursor: pointer; }
        .lsa-edit:hover { border-color: #a78bfa; color: #6d28d9; }
        .lsa-edit .material-symbols-rounded { font-size: 17px; }
        .lsa-note { margin: 10px 0 0; padding: 8px 10px; border-radius: 10px; background: #f9fafb; color: #6b7280; font-size: .76rem; line-height: 1.45; }
        .lsa-ed { margin-top: 14px; padding-top: 14px; border-top: 1px solid #ede9fe; }
        .lsa-msg { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 12px; align-items: start; }
        .lsa-msg label { display: flex; flex-direction: column; gap: 5px; font-size: .78rem; font-weight: 700; color: #6b7280; }
        #growthSettingsPage .lsa-msg textarea { padding: 10px 11px; border: 1.5px solid #e5e7eb; border-radius: 10px; font: inherit; font-size: .88rem; color: #111827; resize: vertical; min-height: 84px; }
        .lsa-vars { display: flex; flex-wrap: wrap; gap: 6px; grid-column: 1; }
        .lsa-vars button { padding: 4px 9px; border: 1px solid #ddd6fe; border-radius: 999px; background: #faf5ff; color: #6d28d9; font: inherit; font-size: .74rem; font-weight: 700; cursor: pointer; }
        .lsa-bubble { grid-column: 2; grid-row: 1 / span 2; padding: 12px; border-radius: 14px; background: #e7f7ee; }
        .lsa-bubble-h { display: block; margin-bottom: 6px; font-size: .68rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: #15803d; }
        .lsa-bubble p { margin: 0; padding: 10px 12px; border-radius: 4px 12px 12px 12px; background: #fff; color: #111827; font-size: .86rem; line-height: 1.5; box-shadow: 0 1px 1px rgba(0,0,0,.06); }
        .lsa-foot { display: flex; align-items: center; gap: 10px; margin-top: 14px; padding: 10px 12px; border-radius: 12px; background: #f5f3ff; color: #4c1d95; font-size: .8rem; line-height: 1.45; }
        .lsa-foot > .material-symbols-rounded { color: #7c3aed; font-size: 20px; }
        .lsa-foot > span:nth-child(2) { flex: 1; }
        .lsa-foot .btn { flex-shrink: 0; }
        .lsa-side-h { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 10px; }
        .lsa-recent { border: 1px solid #e5e7eb; border-radius: 16px; background: #fff; overflow: hidden; }
        .lsa-row { display: flex; align-items: center; gap: 12px; padding: 11px 14px; border-top: 1px solid #f3f4f6; }
        .lsa-row:first-child { border-top: 0; }
        .lsa-row > div { flex: 1; min-width: 0; }
        .lsa-row b { display: block; font-size: .88rem; color: #111827; }
        .lsa-row small { font-size: .76rem; color: #6b7280; }
        .lsa-ric { width: 34px; height: 34px; display: grid; place-items: center; border-radius: 10px; background: #f5f3ff; color: #7c3aed; font-size: 19px; }
        .lsa-row code { padding: 3px 8px; border-radius: 8px; background: #f3f4f6; color: #374151; font-size: .78rem; font-weight: 700; letter-spacing: .03em; }
        .lsa-pill { padding: 3px 9px; border-radius: 999px; font-size: .7rem; font-weight: 800; }
        .lsa-pill.ready { background: #ecfdf5; color: #047857; }
        .lsa-pill.used { background: #ede9fe; color: #6d28d9; }
        .lsa-empty { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 26px; border: 1px dashed #d1d5db; border-radius: 16px; color: #6b7280; font-size: .84rem; text-align: center; }
        .lsa-empty .material-symbols-rounded { font-size: 30px; color: #c4b5fd; }
        .lsa-empty b { color: #111827; }
        .lsg-sec { margin-bottom: 24px; }
        .lsg-sec-h { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 10px; margin-bottom: 12px; }
        .lsg-sec-h p { margin: 4px 0 0; font-size: .82rem; color: #6b7280; max-width: 620px; }
        .lsg-styles { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .lsg-style { display: flex; align-items: center; gap: 16px; padding: 16px; border: 2px solid #e5e7eb; border-radius: 16px; background: #fff; text-align: left; font: inherit; cursor: pointer; transition: border-color .15s, box-shadow .15s; }
        .lsg-style b { display: block; font-size: 1rem; color: #111827; }
        .lsg-style b em { margin-left: 4px; padding: 1px 7px; border-radius: 999px; background: #ede9fe; color: #6d28d9; font-size: .65rem; font-style: normal; vertical-align: 2px; }
        .lsg-style small { display: block; margin-top: 3px; font-size: .8rem; color: #6b7280; line-height: 1.45; }
        .lsg-style.on { border-color: #7c3aed; background: #faf5ff; box-shadow: 0 8px 20px -12px rgba(109,40,217,.5); }
        .lsg-wheel { position: relative; width: 84px; height: 84px; flex-shrink: 0; }
        .lsg-wheel i { position: absolute; inset: 0; border-radius: 50%; border: 4px solid #1c1230; box-shadow: 0 6px 14px -6px rgba(28,18,48,.6); }
        .lsg-wheel b { position: absolute; left: 50%; top: 50%; width: 18px; height: 18px; margin: -9px 0 0 -9px; border-radius: 50%; background: #1c1230; border: 3px solid #fde68a; }
        .lsg-wheel em { position: absolute; left: 50%; top: -6px; margin-left: -7px; border: 7px solid transparent; border-top: 12px solid #ef4444; }
        .lsg-style.on .lsg-wheel i { animation: lsgSpin 6s linear infinite; }
        @keyframes lsgSpin { to { transform: rotate(360deg); } }
        .lsg-scratch { position: relative; width: 120px; height: 76px; flex-shrink: 0; display: grid; place-items: center; border-radius: 12px; background: linear-gradient(135deg, #fef3c7, #fde68a); overflow: hidden; }
        .lsg-scratch span { font-weight: 800; color: #92400e; font-size: .95rem; }
        .lsg-scratch i { position: absolute; inset: 0; background: linear-gradient(135deg, #c0c4cc, #eef0f3 45%, #d5d8de 55%, #a9aeb8); clip-path: polygon(0 0, 100% 0, 100% 30%, 70% 48%, 40% 35%, 20% 62%, 0 55%); }
        .lsg-status { display: flex; align-items: center; gap: 8px; margin-top: 10px; padding: 10px 12px; border-radius: 12px; background: #ecfdf5; color: #065f46; font-size: .82rem; }
        .lsg-status .material-symbols-rounded { font-size: 19px; }
        .lsg-status.off { background: #f9fafb; color: #6b7280; }
        .lsg-tpls { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .lsg-tpls > span { font-size: .78rem; font-weight: 700; color: #9ca3af; margin-right: 2px; }
        .lsg-tpls button { display: flex; align-items: center; gap: 6px; padding: 9px 14px; border: 1.5px dashed #c4b5fd; border-radius: 12px; background: #faf5ff; color: #5b21b6; font: inherit; font-size: .85rem; font-weight: 700; cursor: pointer; }
        .lsg-tpls button:hover { border-style: solid; background: #f3e8ff; }
        .lsg-tpls .material-symbols-rounded { font-size: 18px; }
        .lsg-ch { padding: 14px; border: 1px solid #e5e7eb; border-radius: 16px; background: #fff; }
        .lsg-ch.on { border-color: #ddd6fe; box-shadow: 0 10px 24px -18px rgba(109,40,217,.55); }
        .lsg-ch.on .lsa-ic { background: linear-gradient(135deg, #f59e0b, #ea580c); color: #fff; }
        .lsg-ch:not(.on) .lsa-t em { color: #6b7280; }
        .lsg-del { padding: 7px 8px; }
        .lsg-del.sure { border-color: #fecaca; background: #fef2f2; color: #b91c1c; padding: 7px 11px; }
        .lsg-ed { padding: 16px; border: 1.5px solid #a78bfa; border-radius: 16px; background: #fff; }
        .lsg-ed-h { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .lsg-ed-h b { font-size: 1rem; color: #111827; }
        .lsg-kinds { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 14px; }
        .lsg-kind { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; padding: 10px 12px; border: 1.5px solid #e5e7eb; border-radius: 12px; background: #fff; text-align: left; font: inherit; cursor: pointer; }
        .lsg-kind .material-symbols-rounded { font-size: 22px; color: #9ca3af; }
        .lsg-kind b { font-size: .86rem; color: #111827; }
        .lsg-kind small { font-size: .72rem; color: #6b7280; }
        .lsg-kind.on { border-color: #7c3aed; background: #faf5ff; }
        .lsg-kind.on .material-symbols-rounded { color: #7c3aed; }
        .lsg-ed-body { display: grid; grid-template-columns: 1fr 300px; gap: 16px; align-items: start; }
        .lsg-title { display: flex; flex-direction: column; gap: 5px; margin-top: 12px; font-size: .78rem; font-weight: 700; color: #6b7280; }
        .lsg-title i { font-style: normal; font-weight: 500; color: #9ca3af; }
        #growthSettingsPage .lsg-title input { padding: 9px 11px; border: 1.5px solid #e5e7eb; border-radius: 10px; font: inherit; font-size: .9rem; color: #111827; }
        .lsg-prev { padding: 12px; border-radius: 14px; background: #f5f3ff; }
        .lsg-prev-card { display: flex; gap: 10px; padding: 12px; border-radius: 12px; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
        .lsg-prev-ic { width: 36px; height: 36px; flex-shrink: 0; display: grid; place-items: center; border-radius: 10px; background: linear-gradient(135deg, #f59e0b, #ea580c); color: #fff; font-size: 20px; }
        .lsg-prev-card > div { flex: 1; min-width: 0; }
        .lsg-prev-card b { display: block; font-size: .88rem; color: #111827; }
        .lsg-prev-card small { display: block; font-size: .74rem; color: #6b7280; margin-top: 2px; }
        .lsg-bar { height: 7px; margin: 8px 0 2px; border-radius: 99px; background: #f3f4f6; overflow: hidden; }
        .lsg-bar i { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #f59e0b, #ea580c); }
        @media (max-width: 860px) { .lsg-styles, .lsg-ed-body { grid-template-columns: 1fr; } .lsg-kinds { grid-template-columns: 1fr 1fr; } }
        .lsv-hero { background: radial-gradient(90% 140% at 100% 0%, #b45309, transparent 55%), radial-gradient(60% 120% at 0% 100%, #6d28d9, transparent 60%), #17111f; }
        .lsv-plans { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 14px; margin-top: 14px; align-items: start; }
        .lsv-plan { position: relative; padding: 18px 16px 16px; border-radius: 18px; color: #f8f5ff; background: radial-gradient(120% 90% at 100% 0%, rgba(245,196,81,.22), transparent 55%), linear-gradient(160deg, #241736, #120c1b); box-shadow: 0 14px 30px -18px rgba(18,12,27,.8); }
        .lsv-plan.hl { box-shadow: 0 0 0 2px #f5c451, 0 18px 34px -16px rgba(180,83,9,.6); }
        .lsv-plan.off { opacity: .55; }
        .lsv-badge { position: absolute; top: -10px; right: 14px; padding: 3px 10px; border-radius: 999px; background: linear-gradient(135deg, #ffe08a, #d99a1e); color: #3b2604; font-size: .68rem; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
        .lsv-plan-h { display: flex; align-items: center; gap: 8px; }
        .lsv-plan-h .material-symbols-rounded { font-size: 20px; color: #f5c451; }
        .lsv-plan-h b { font-size: 1rem; letter-spacing: .02em; }
        .lsv-price { margin-top: 10px; }
        .lsv-price b { font-size: 1.9rem; font-weight: 800; color: #ffe08a; }
        .lsv-price span { margin-left: 4px; font-size: .85rem; color: rgba(255,255,255,.65); }
        .lsv-pm { display: block; font-size: .74rem; color: rgba(255,255,255,.55); }
        .lsv-plan ul { list-style: none; margin: 12px 0 0; padding: 12px 0 0; border-top: 1px solid rgba(255,255,255,.1); display: grid; gap: 8px; }
        .lsv-plan li { display: flex; gap: 8px; align-items: flex-start; font-size: .84rem; line-height: 1.35; }
        .lsv-plan li .material-symbols-rounded { font-size: 17px; color: #f5c451; }
        .lsv-cta { display: block; margin-top: 14px; padding: 10px; border-radius: 12px; background: linear-gradient(135deg, #ffe08a, #d99a1e); color: #3b2604; text-align: center; font-weight: 800; font-size: .88rem; }
        .lsv-stats { display: flex; gap: 6px; margin-top: 14px; padding: 10px; border-radius: 12px; background: rgba(255,255,255,.06); }
        .lsv-stats div { flex: 1; min-width: 0; }
        .lsv-stats b { display: block; font-size: .95rem; }
        .lsv-stats span { font-size: .68rem; color: rgba(255,255,255,.55); }
        .lsv-acts { display: flex; align-items: center; gap: 6px; margin-top: 12px; }
        .lsv-acts .up-switch { flex: 1; margin: 0; color: rgba(255,255,255,.8); font-size: .8rem; font-weight: 700; }
        .lsv-acts .lsa-edit { background: rgba(255,255,255,.08); border-color: rgba(255,255,255,.15); color: #fff; }
        .lsv-acts .lsg-del.sure { background: #fef2f2; color: #b91c1c; }
        .lsv-h4 { margin: 16px 0 8px; font-size: .78rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: #9ca3af; }
        .lsv-check { justify-content: flex-end; }
        .lsv-check span { display: flex; align-items: center; gap: 8px; padding: 9px 11px; border: 1.5px solid #e5e7eb; border-radius: 10px; color: #111827; font-size: .88rem; font-weight: 700; cursor: pointer; }
        .lsv-check input { width: 17px; height: 17px; accent-color: #7c3aed; }
        .lsv-prev { background: #f5f3ff; }
        .lsv-prev .lsv-plan { margin-top: 8px; }
        .lsp-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 12px; }
        .lsp-range { display: flex; gap: 4px; padding: 4px; border-radius: 12px; background: #f3f4f6; }
        .lsp-range button { padding: 7px 12px; border: 0; border-radius: 9px; background: none; color: #4b5563; font: inherit; font-size: .82rem; font-weight: 700; cursor: pointer; }
        .lsp-range button.on { background: #fff; color: #6d28d9; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .lsp-hero small { font-size: .78rem; color: rgba(255,255,255,.7); font-weight: 600; }
        .lsp-big { display: block; margin-top: 2px; font-size: 2.1rem; line-height: 1.1; }
        .lsp-hero em { margin-left: 6px; padding: 2px 8px; border-radius: 999px; font-style: normal; font-size: .72rem; font-weight: 800; }
        .lsp-hero em.up { background: rgba(34,197,94,.2); color: #86efac; }
        .lsp-hero em.down { background: rgba(239,68,68,.2); color: #fca5a5; }
        .lsp-grid { display: grid; grid-template-columns: 1.4fr 1fr; gap: 14px; }
        .lsp-chart { display: flex; align-items: flex-end; gap: 6px; height: 170px; padding-top: 6px; }
        .lsp-col { flex: 1; min-width: 0; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; gap: 6px; }
        .lsp-bar { position: relative; width: 100%; max-width: 38px; border-radius: 8px 8px 3px 3px; background: #e9e5f5; overflow: hidden; display: flex; align-items: flex-end; }
        .lsp-bar i { display: block; width: 100%; background: linear-gradient(180deg, #8b5cf6, #6d28d9); }
        .lsp-col small { font-size: .64rem; color: #9ca3af; white-space: nowrap; }
        .lsp-legend { display: flex; gap: 14px; margin-top: 10px; font-size: .76rem; color: #6b7280; }
        .lsp-legend i { display: inline-block; width: 10px; height: 10px; margin-right: 5px; border-radius: 3px; vertical-align: -1px; }
        .lsp-legend i.all { background: #e9e5f5; } .lsp-legend i.loyal { background: #7c3aed; }
        .lsp-tips { display: grid; gap: 10px; }
        .lsp-tips > div { display: flex; gap: 10px; align-items: flex-start; padding: 12px; border-radius: 12px; background: #faf5ff; }
        .lsp-tips > div > .material-symbols-rounded { color: #7c3aed; font-size: 21px; }
        .lsp-tips p { flex: 1; margin: 0; font-size: .82rem; color: #374151; line-height: 1.45; }
        .lsp-tips .lsa-edit { flex-shrink: 0; }
        .lsp-table { display: grid; }
        .lsp-tr { display: grid; grid-template-columns: 2fr .7fr .9fr 1.2fr 1fr .8fr; gap: 8px; align-items: center; padding: 10px 4px; border-top: 1px solid #f3f4f6; font-size: .86rem; color: #374151; }
        .lsp-th { border-top: 0; font-size: .7rem; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; color: #9ca3af; }
        .lsp-src { display: flex; align-items: center; gap: 8px; font-weight: 700; color: #111827; }
        .lsp-src .material-symbols-rounded { width: 30px; height: 30px; display: grid; place-items: center; border-radius: 9px; background: #f5f3ff; color: #7c3aed; font-size: 17px; }
        .lsp-tr small { color: #9ca3af; font-weight: 600; }
        .lsp-ret { padding: 2px 8px; border-radius: 999px; background: #ecfdf5; color: #047857; }
        .lsp-na { color: #d1d5db; font-style: normal; }
        .lsp-segs { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 12px; }
        .lsp-seg { padding: 16px; border: 1px solid #e5e7eb; border-radius: 16px; background: #fff; }
        .lsp-seg-h { display: flex; align-items: center; gap: 8px; }
        .lsp-seg-h .material-symbols-rounded { color: #7c3aed; }
        .lsp-seg-h b { flex: 1; color: #111827; }
        .lsp-seg-h small { color: #9ca3af; font-size: .74rem; }
        .lsp-seg.vip { background: linear-gradient(160deg, #241736, #120c1b); border-color: transparent; color: #fff; }
        .lsp-seg.vip .lsp-seg-h b, .lsp-seg.vip .lsp-meter b, .lsp-seg.vip .lsp-seg-f b { color: #ffe08a; }
        .lsp-seg.vip .material-symbols-rounded { color: #f5c451; }
        .lsp-meter { margin-top: 14px; }
        .lsp-meter span { font-size: .74rem; color: #9ca3af; }
        .lsp-meter b { display: block; font-size: 1.4rem; color: #111827; }
        .lsp-meter div { height: 7px; margin-top: 6px; border-radius: 99px; background: #f3f4f6; overflow: hidden; }
        .lsp-meter div i { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #a78bfa, #6d28d9); }
        .lsp-seg.vip .lsp-meter div { background: rgba(255,255,255,.12); }
        .lsp-seg.vip .lsp-meter div i { background: linear-gradient(90deg, #ffe08a, #d99a1e); }
        .lsp-seg-f { display: flex; gap: 10px; margin-top: 14px; padding-top: 12px; border-top: 1px dashed #e5e7eb; }
        .lsp-seg.vip .lsp-seg-f { border-color: rgba(255,255,255,.15); }
        .lsp-seg-f div { flex: 1; }
        .lsp-seg-f b { display: block; color: #111827; }
        .lsp-seg-f span { font-size: .72rem; color: #9ca3af; }
        .lsp-owed { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; }
        .lsp-owed > div { display: flex; flex-direction: column; gap: 2px; padding: 14px; border-radius: 14px; background: #fffbeb; border: 1px solid #fde68a; }
        .lsp-owed .material-symbols-rounded { color: #b45309; margin-bottom: 4px; }
        .lsp-owed small { font-size: .74rem; font-weight: 700; color: #92400e; }
        .lsp-owed b { font-size: 1.15rem; color: #111827; }
        .lsp-owed em { font-style: normal; font-size: .74rem; color: #6b7280; }
        @media (max-width: 960px) { .lsp-grid { grid-template-columns: 1fr; } .lsp-tr { grid-template-columns: 1.6fr .6fr .8fr 1fr; } .lsp-tr > :nth-child(5), .lsp-tr > :nth-child(6) { display: none; } }
        .lsc-hero { background: radial-gradient(90% 140% at 100% 0%, #0ea5e9, transparent 55%), linear-gradient(135deg, #0f172a, #1c1230); }
        .lsc-wrap { display: grid; grid-template-columns: 340px 1fr; gap: 18px; align-items: start; }
        .lsc-sw { display: flex; flex-wrap: wrap; gap: 8px; }
        .lsc-sw button { width: 44px; height: 44px; border-radius: 12px; border: 2px solid #e5e7eb; font: inherit; font-weight: 800; cursor: pointer; transition: transform .15s; }
        .lsc-sw button:hover { transform: translateY(-2px); }
        .lsc-sw button.on { border-color: #7c3aed; box-shadow: 0 0 0 3px #ede9fe; }
        .lsc-custom { display: flex; flex-direction: column; align-items: center; gap: 2px; font-size: .66rem; font-weight: 700; color: #6b7280; cursor: pointer; }
        .lsc-custom input { width: 44px; height: 30px; padding: 0; border: 0; background: none; cursor: pointer; }
        .lsc-status { display: grid; gap: 8px; margin: 14px 0 6px; }
        .lsc-status > div { display: flex; gap: 10px; align-items: center; padding: 10px 12px; border-radius: 12px; background: #f9fafb; }
        .lsc-status > div .material-symbols-rounded { color: #9ca3af; }
        .lsc-status > div.ok { background: #ecfdf5; } .lsc-status > div.ok .material-symbols-rounded { color: #059669; }
        .lsc-status b { display: block; font-size: .86rem; color: #111827; } .lsc-status small { font-size: .74rem; color: #6b7280; }
        .lsc-prev { display: flex; flex-wrap: wrap; gap: 22px; justify-content: center; padding: 22px; border-radius: 20px; background: radial-gradient(circle at 30% 20%, #f5f3ff, #eef2ff 60%, #f8fafc); }
        .lsc-device { width: 280px; animation: lscIn .5s cubic-bezier(.2,.9,.3,1.2) both; }
        .lsc-device + .lsc-device { animation-delay: .08s; }
        @keyframes lscIn { from { opacity: 0; transform: translateY(14px) rotate(-1deg); } }
        .lsc-cap { margin-bottom: 8px; text-align: center; font-size: .72rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: #6b7280; }
        .lsc-logo { width: 30px; height: 30px; flex-shrink: 0; display: grid; place-items: center; overflow: hidden; border-radius: 8px; background: rgba(255,255,255,.18); font-weight: 800; }
        .lsc-logo.round { border-radius: 50%; } .lsc-logo img { width: 100%; height: 100%; object-fit: cover; }
        .lsc-apple, .lsc-google { position: relative; overflow: hidden; border-radius: 16px; box-shadow: 0 22px 40px -22px rgba(15,23,42,.8); }
        .lsc-apple::after, .lsc-google::after { content: ''; position: absolute; inset: 0; background: linear-gradient(115deg, transparent 30%, rgba(255,255,255,.14) 45%, transparent 60%); transform: translateX(-100%); animation: lscShine 3.8s ease-in-out infinite; pointer-events: none; }
        @keyframes lscShine { 60%, 100% { transform: translateX(100%); } }
        .lsc-apple { padding: 14px; }
        .lsc-a-top { display: flex; align-items: center; gap: 8px; } .lsc-a-top > b { flex: 1; font-size: .9rem; }
        .lsc-a-bal { text-align: right; } .lsc-a-bal small, .lsc-a-mem small, .lsc-a-row small { display: block; font-size: .58rem; font-weight: 700; letter-spacing: .06em; color: #f5c451; } .lsc-a-bal b { font-size: 1.1rem; }
        .lsc-a-mem { margin-top: 22px; } .lsc-a-mem b { font-size: 1.35rem; }
        .lsc-a-row { display: flex; gap: 22px; margin-top: 14px; } .lsc-a-row b { font-size: .9rem; }
        .lsc-a-code, .lsc-g-code { display: flex; flex-direction: column; align-items: center; gap: 6px; margin-top: 18px; }
        .lsc-a-code small, .lsc-g-code small { font-size: .66rem; opacity: .8; }
        .lsc-qr { display: grid; grid-template-columns: repeat(11, 1fr); gap: 0; width: 92px; height: 92px; padding: 7px; border-radius: 8px; background: #fff; }
        .lsc-qr i { background: transparent; } .lsc-qr i.on { background: #111; }
        .lsc-google { padding: 16px; }
        .lsc-g-top { display: flex; align-items: center; gap: 10px; font-size: .86rem; }
        .lsc-g-prog { margin-top: 14px; font-size: 1.2rem; font-weight: 700; }
        .lsc-g-row { display: flex; gap: 26px; margin-top: 14px; } .lsc-g-row small { display: block; font-size: .7rem; opacity: .75; } .lsc-g-row b { font-size: 1.05rem; }
        .lsc-g-name { margin-top: 10px; font-size: .84rem; opacity: .85; }
        .lsc-g-code { padding-top: 14px; border-top: 1px solid rgba(255,255,255,.18); }
        @media (max-width: 980px) { .lsc-wrap { grid-template-columns: 1fr; } }
        .lsa-fine { margin: 12px 2px 0; font-size: .76rem; color: #9ca3af; line-height: 1.5; }
        @media (max-width: 760px) { .lsa-grid { grid-template-columns: 1fr; } .lsa-msg { grid-template-columns: 1fr; } .lsa-bubble { grid-column: 1; grid-row: auto; } .lsa-foot { flex-wrap: wrap; } .lsa-row code { display: none; } }
        @media (max-width: 860px) { .ls-types { grid-template-columns: 1fr; } }
      </style>      <div class="page-header">
        <h1>Loyalty Studio</h1>
        <p>Choose how customers earn, put rewards on autopilot, run games and a VIP membership, and see what it brings in.</p>
      </div>
      <div class="page-content">
        <div id="lsTabs" class="ls-tabs"></div>
        <div id="lsPanel"></div>
        <div id="lsLegacy">
        <div class="analytics-stats" id="growthOverviewCards"></div>
        <div class="section-card">
          <div class="section-header">
            <div class="section-title">Loyalty Points</div>
            <button class="btn btn-primary" id="btnSaveGrowthSettings" onclick="saveGrowthSettings()">Save Settings</button>
          </div>
          <div class="section-body">
            <div class="form-group">
              <label><input type="checkbox" id="gsLoyaltyEnabled"> Enable loyalty points program</label>
            </div>
            <div class="row" style="display:flex;gap:12px;">
              <div class="form-group" style="flex:1;">
                <label>Spend threshold (<?php echo htmlspecialchars($currency_symbol); ?>)</label>
                <input type="number" id="gsEarnAmountThreshold" class="form-control" min="0" step="1">
              </div>
              <div class="form-group" style="flex:1;">
                <label>Points earned per threshold</label>
                <input type="number" id="gsEarnPointsPerAmount" class="form-control" min="0" step="1">
              </div>
            </div>
            <div class="row" style="display:flex;gap:12px;">
              <div class="form-group" style="flex:1;">
                <label>Redemption value per point (<?php echo htmlspecialchars($currency_symbol); ?>)</label>
                <input type="number" id="gsRedeemValuePerPoint" class="form-control" min="0" step="0.01">
              </div>
              <div class="form-group" style="flex:1;">
                <label>Minimum points to redeem</label>
                <input type="number" id="gsMinRedeemPoints" class="form-control" min="0" step="1">
              </div>
            </div>
            <div class="row" style="display:flex;gap:12px;">
              <div class="form-group" style="flex:1;">
                <label>Points expire after (days of inactivity)</label>
                <input type="number" id="gsPointsExpiryDays" class="form-control" min="0" step="1" placeholder="0 = never expire">
              </div>
            </div>
          </div>
        </div>

        <div class="section-card">
          <div class="section-header">
            <div class="section-title">Verify Redemption</div>
          </div>
          <div class="section-body">
            <p style="color:#666;font-size:0.85rem;margin:0 0 12px;">When a customer redeems points on their Loyalty page for an in-person order, they're given a 5-character code. Enter it here to confirm the discount and mark it used, so it can't be reused.</p>
            <div class="row" style="display:flex;gap:12px;align-items:flex-end;">
              <div class="form-group" style="flex:1;max-width:220px;">
                <label>Redemption Code</label>
                <input type="text" id="redemptionCodeInput" class="form-control" maxlength="10" style="text-transform:uppercase;" placeholder="e.g. A1B2C" onkeydown="if(event.key==='Enter')lookupRedemption()">
              </div>
              <button class="btn btn-primary" onclick="lookupRedemption()">Look Up</button>
            </div>
            <div id="redemptionResult" style="margin-top:14px;"></div>
          </div>
        </div>

        <div class="section-card">
          <div class="section-header">
            <div class="section-title">Referral Program</div>
          </div>
          <div class="section-body">
            <div class="form-group">
              <label><input type="checkbox" id="gsReferralEnabled"> Enable referral program</label>
            </div>
            <div class="row" style="display:flex;gap:12px;">
              <div class="form-group" style="flex:1;">
                <label>Referrer bonus points (friend completes first order)</label>
                <input type="number" id="gsReferrerRewardPoints" class="form-control" min="0" step="1">
              </div>
              <div class="form-group" style="flex:1;">
                <label>New customer welcome bonus points</label>
                <input type="number" id="gsReferredRewardPoints" class="form-control" min="0" step="1">
              </div>
            </div>
          </div>
        </div>

        <div class="section-card">
          <div class="section-header">
            <div class="section-title">Customer Segmentation</div>
          </div>
          <div class="section-body">
            <div class="row" style="display:flex;gap:12px;">
              <div class="form-group" style="flex:1;">
                <label>Days without a visit to be "lapsed"</label>
                <input type="number" id="gsLapsedDaysThreshold" class="form-control" min="1" step="1">
              </div>
              <div class="form-group" style="flex:1;">
                <label>Total spend to be "high spender" (<?php echo htmlspecialchars($currency_symbol); ?>)</label>
                <input type="number" id="gsHighSpenderThreshold" class="form-control" min="0" step="1">
              </div>
            </div>
          </div>
        </div>

        <div class="section-card">
          <div class="section-header">
            <div class="section-title">Loyalty Tiers</div>
            <button class="btn btn-primary" onclick="openTierModal()">+ New Tier</button>
          </div>
          <div class="section-body" style="overflow-x:auto;">
            <table class="data-table" id="tiersTable">
              <thead>
                <tr><th>Tier</th><th>Min. Total Spent</th><th>Icon</th><th>Order</th><th>Points Multiplier</th><th>Actions</th></tr>
              </thead>
              <tbody id="tiersTbody">
                <tr><td colspan="6" style="text-align:center;padding:30px;color:#999;">Loading...</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="section-card">
          <div class="section-header">
            <div class="section-title">Free Item Rewards</div>
            <button class="btn btn-primary" onclick="openRewardModal()">+ New Reward</button>
          </div>
          <div class="section-body" style="overflow-x:auto;">
            <p style="color:#666;font-size:0.85rem;margin:0 0 12px;">Let customers redeem points for a specific free menu item, in addition to the cash discount above.</p>
            <table class="data-table" id="rewardsTable">
              <thead>
                <tr><th>Item</th><th>Points Cost</th><th>Status</th><th>Actions</th></tr>
              </thead>
              <tbody id="rewardsTbody">
                <tr><td colspan="4" style="text-align:center;padding:30px;color:#999;">Loading...</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="section-card">
          <div class="section-header">
            <div class="section-title">Loyalty Card QR (for counter display)</div>
          </div>
          <div class="section-body" style="text-align:center;">
            <img id="loyaltyQrImage" src="" alt="Loyalty page QR code" width="180" height="180" style="border:1px solid #eee;border-radius:8px;">
            <p style="margin-top:10px;color:#666;font-size:13px;">Customers scan this to check their points balance and referral code.</p>
          </div>
        </div>
        </div>
      </div>
    </div>

    <!-- Tier Add/Edit Modal -->
    <div id="tierModal" class="modal">
      <div class="modal-content">
        <div class="modal-header">
          <h2 id="tierModalTitle">New Tier</h2>
          <button class="modal-close" onclick="closeModal('tierModal')">&times;</button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="tierId">
          <div class="form-group">
            <label>Tier Name</label>
            <input type="text" id="tierName" class="form-control" placeholder="e.g. Gold">
          </div>
          <div class="form-group">
            <label>Minimum Total Spent</label>
            <input type="number" id="tierMinSpent" class="form-control" min="0" step="1">
          </div>
          <div class="form-group">
            <label>Icon (Material Symbol name)</label>
            <select id="tierIcon" class="form-control">
              <option value="star">Star</option>
              <option value="medal">Medal</option>
              <option value="crown">Crown</option>
              <option value="gem">Gem</option>
              <option value="trophy">Trophy</option>
              <option value="award">Award</option>
              <option value="fire">Fire</option>
              <option value="heart">Heart</option>
            </select>
          </div>
          <div class="form-group">
            <label>Sort Order</label>
            <input type="number" id="tierSortOrder" class="form-control" min="0" step="1" value="0">
          </div>
          <div class="form-group">
            <label>Points Multiplier (e.g. 1.5 = 1.5x points earned)</label>
            <input type="number" id="tierPointsMultiplier" class="form-control" min="0.1" max="9.99" step="0.05" value="1">
          </div>
          <button class="btn btn-primary" onclick="saveTier()">Save Tier</button>
        </div>
      </div>
    </div>

    <!-- Free Item Reward Add Modal -->
    <div id="rewardModal" class="modal">
      <div class="modal-content">
        <div class="modal-header">
          <h2>New Free Item Reward</h2>
          <button class="modal-close" onclick="closeModal('rewardModal')">&times;</button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>Menu Item</label>
            <select id="rewardMenuItemId" class="form-control">
              <option value="">Loading menu items...</option>
            </select>
          </div>
          <div class="form-group">
            <label>Points Cost</label>
            <input type="number" id="rewardPointsCost" class="form-control" min="1" step="1">
          </div>
          <button class="btn btn-primary" onclick="saveReward()">Add Reward</button>
        </div>
      </div>
    </div>

    <!-- ═══════ Growth Module: Customer Segments ═══════ -->
    <div id="customerSegmentsPage" class="page sx lg">
      <div class="page-header">
        <h1>Customer Segments</h1>
        <p>See who's new, repeat, high-spending, or lapsed</p>
      </div>
      <div class="page-content">
        <div class="analytics-stats" id="segmentSummaryCards"></div>
        <div class="section-card">
          <div class="section-header">
            <div class="section-title">Customers</div>
            <div id="segmentFilterTabs" style="display:flex;gap:6px;flex-wrap:wrap;"></div>
          </div>
          <div class="section-body" style="overflow-x:auto;">
            <table class="data-table" id="segmentsTable">
              <thead>
                <tr><th>Name</th><th>Phone</th><th>Segment</th><th>Visits</th><th>Total Spent</th><th>Last Visit</th><th>Points</th><th>Actions</th></tr>
              </thead>
              <tbody id="segmentsTbody">
                <tr><td colspan="8" style="text-align:center;padding:30px;color:#999;">Loading...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Manual Points Adjustment Modal -->
    <div id="adjustPointsModal" class="modal">
      <div class="modal-content">
        <div class="modal-header">
          <h2>Adjust Points</h2>
          <button class="modal-close" onclick="closeModal('adjustPointsModal')">&times;</button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="adjustCustomerId">
          <p id="adjustCustomerName" style="margin-bottom:12px;color:#666;"></p>
          <div class="form-group">
            <label>Points Adjustment (use a negative number to deduct)</label>
            <input type="number" id="adjustPointsDelta" class="form-control" step="1">
          </div>
          <div class="form-group">
            <label>Note</label>
            <input type="text" id="adjustPointsNote" class="form-control" placeholder="Reason for adjustment">
          </div>
          <button class="btn btn-primary" onclick="submitAdjustPoints()">Apply Adjustment</button>
        </div>
      </div>
    </div>

    <!-- ═══════ Growth Module: Referrals ═══════ -->
    <!-- Growth › WhatsApp ordering (assets/js/wa-order-studio.js) -->
    <div id="waOrderPage" class="page sx lg">
      <div class="page-header">
        <h1>WhatsApp ordering</h1>
        <p>Customers order and track their food in a WhatsApp chat</p>
      </div>
      <div id="woRoot"></div>
    </div>

    <!-- Delivery › Riders (assets/js/riders-board.js) -->
    <div id="ridersPage" class="page sx lg">
      <div class="page-header">
        <h1>Riders</h1>
        <p>Your delivery riders: who is carrying what, deliveries waiting for a rider, and cash to collect</p>
      </div>
      <div id="rbRoot"></div>
    </div>

    <!-- Growth › AI manager (assets/js/owner-manager-studio.js) -->
    <div id="ownerManagerPage" class="page sx lg">
      <div class="page-header">
        <h1>AI manager</h1>
        <p>A morning report on WhatsApp, one-tap fixes, and answers to your questions</p>
      </div>
      <div id="omRoot"></div>
    </div>

    <!-- Growth › ChatGPT & Claude (assets/js/ai-apps-studio.js; the app itself: main/mcp) -->
    <div id="aiAppsPage" class="page sx">
      <div class="page-header">
        <h1>ChatGPT &amp; Claude</h1>
        <p>Run your restaurant by chatting: sales, orders and menu, right inside ChatGPT or Claude</p>
      </div>
      <div id="aiaRoot"></div>
    </div>

    <div id="whatsappPage" class="page sx">
      <style>
        #whatsappPage .ls-grid input, #whatsappPage .ls-grid select, #whatsappPage textarea { padding: 9px 11px; border: 1.5px solid #e5e7eb; border-radius: 10px; font: inherit; font-size: .9rem; color: #111827; background: #fff; }
        #whatsappPage textarea { width: 100%; resize: vertical; min-height: 92px; box-sizing: border-box; }
        .wa-dry { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; padding: 10px 14px; border-radius: 12px; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; font-size: .84rem; font-weight: 600; }
        .wa-hero { display: flex; flex-wrap: wrap; align-items: center; gap: 18px; padding: 18px; margin-bottom: 16px; border-radius: 18px; color: #fff; background: radial-gradient(90% 140% at 100% 0%, #25d366, transparent 55%), linear-gradient(135deg, #075e54, #0b3d36); box-shadow: 0 16px 34px -20px rgba(7,94,84,.8); }
        .wa-hero-l { flex: 1; min-width: 220px; }
        .wa-hero .up-switch b { color: #fff; }
        .wa-hero p { display: flex; align-items: center; gap: 6px; margin: 8px 0 0; font-size: .84rem; color: rgba(255,255,255,.8); }
        .wa-hero p .material-symbols-rounded { font-size: 17px; }
        .wa-hero .ls-kpis div { background: rgba(255,255,255,.12); }
        .wa-ring { width: 78px; height: 78px; flex-shrink: 0; display: grid; place-items: center; border-radius: 50%; background: conic-gradient(#b9f6ca calc(var(--p) * 1%), rgba(255,255,255,.15) 0); animation: waRing 1s ease-out; }
        .wa-ring > div { width: 62px; height: 62px; display: grid; place-items: center; align-content: center; border-radius: 50%; background: #0b3d36; text-align: center; }
        .wa-ring b { font-size: 1.05rem; } .wa-ring span { font-size: .6rem; color: rgba(255,255,255,.7); }
        @keyframes waRing { from { transform: rotate(-90deg) scale(.8); opacity: 0; } }
        .wa-badge { margin-left: 4px; padding: 0 6px; border-radius: 999px; background: #ef4444; color: #fff; font-style: normal; font-size: .7rem; }
        .wa-intro { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 14px; padding: 12px 14px; border-radius: 14px; background: #ecfdf5; color: #065f46; font-size: .85rem; line-height: 1.5; }
        .wa-intro .material-symbols-rounded { color: #059669; }
        .wa-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); grid-auto-flow: row dense; gap: 12px; align-items: start; }
        .wa-card { padding: 14px; border: 1px solid #e5e7eb; border-radius: 16px; background: #fff; transition: box-shadow .2s, border-color .2s; animation: waIn .35s ease both; }
        .wa-card:hover { box-shadow: 0 10px 24px -18px rgba(7,94,84,.6); }
        .wa-card.on { border-color: #bbf7d0; }
        .wa-card.open { grid-column: 1 / -1; border-color: #25d366; }
        @keyframes waIn { from { opacity: 0; transform: translateY(6px); } }
        .wa-card-top { display: flex; align-items: center; gap: 12px; }
        .wa-ic { width: 42px; height: 42px; flex-shrink: 0; display: grid; place-items: center; border-radius: 12px; background: #f3f4f6; color: #9ca3af; font-size: 22px; }
        .wa-card.on .wa-ic { background: linear-gradient(135deg, #25d366, #128c7e); color: #fff; }
        .wa-card-t { flex: 1; min-width: 0; }
        .wa-card-t b { display: block; color: #111827; }
        .wa-card-t small { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
        .wa-chip { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; border-radius: 999px; font-size: .7rem; font-weight: 700; }
        .wa-chip .material-symbols-rounded { font-size: 14px; }
        .wa-chip.wait { background: #eff6ff; color: #1d4ed8; } .wa-chip.bad { background: #fef2f2; color: #b91c1c; }
        .wa-spin { animation: waSpin 1.1s linear infinite; display: inline-block; }
        @keyframes waSpin { to { transform: rotate(360deg); } }
        .wa-linked { display: flex; gap: 10px; align-items: flex-start; margin-top: 12px; padding: 12px 14px; border-radius: 14px; background: #f0fdf4; border: 1px solid #bbf7d0; }
        .wa-linked > .material-symbols-rounded { color: #059669; }
        .wa-linked.pending { background: #eff6ff; border-color: #bfdbfe; } .wa-linked.pending > .material-symbols-rounded { color: #2563eb; }
        .wa-linked.rejected { background: #fef2f2; border-color: #fecaca; } .wa-linked.rejected > .material-symbols-rounded { color: #dc2626; }
        .wa-linked.none { background: #faf5ff; border-color: #e9d5ff; } .wa-linked.none > .material-symbols-rounded { color: #7c3aed; }
        .wa-linked b { display: block; font-size: .88rem; color: #111827; } .wa-linked small { font-size: .8rem; color: #4b5563; line-height: 1.45; }
        .wa-tplbar, .wa-connect, .wa-connected { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 14px; padding: 14px 16px; border-radius: 16px; }
        .wa-tplbar { background: linear-gradient(135deg, #faf5ff, #fff); border: 1.5px solid #e9d5ff; }
        .wa-tplbar > .material-symbols-rounded { color: #7c3aed; font-size: 26px; }
        .wa-tplbar > div, .wa-connect > div:nth-child(2), .wa-connected > div { flex: 1; min-width: 200px; }
        .wa-tplbar b, .wa-connect b, .wa-connected b { display: block; color: #111827; } .wa-tplbar small, .wa-connect small, .wa-connected small { font-size: .82rem; color: #6b7280; }
        .wa-connect { background: radial-gradient(120% 140% at 100% 0%, #dcfce7, transparent 60%), #fff; border: 1.5px solid #86efac; box-shadow: 0 12px 28px -22px rgba(7,94,84,.8); animation: waIn .4s ease both; }
        .wa-connect.wa-connect-lost { background: radial-gradient(120% 140% at 100% 0%, #ffedd5, transparent 60%), #fff; border-color: #fdba74; }
        .wa-connect-lost .wa-connect-logo { background: #f97316; }
        .wa-connect-logo { width: 46px; height: 46px; display: grid; place-items: center; border-radius: 14px; background: linear-gradient(135deg, #25d366, #075e54); color: #fff; }
        .wa-connect-btn { text-decoration: none; background: #128c7e !important; border-color: #128c7e !important; }
        .wa-connected { background: #f0fdf4; border: 1px solid #bbf7d0; margin-top: 12px; }
        .wa-connected > .material-symbols-rounded { color: #059669; font-size: 26px; }
        .wa-manual { margin-top: 10px; } .wa-manual summary { cursor: pointer; font-size: .84rem; font-weight: 700; color: #6b7280; }
        .wa-chip.ok { background: #ecfdf5; color: #047857; } .wa-chip.warn { background: #fffbeb; color: #b45309; } .wa-chip.muted { background: #f3f4f6; color: #6b7280; }
        .wa-card-msg { margin: 10px 0 0; padding: 10px 12px; border-radius: 4px 12px 12px 12px; background: #f0fdf4; color: #374151; font-size: .82rem; line-height: 1.45; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
        .wa-card.open .wa-card-msg { display: none; }
        .wa-nums { display: flex; align-items: center; gap: 6px; margin-top: 12px; padding-top: 12px; border-top: 1px dashed #e5e7eb; }
        .wa-nums div { flex: 1; min-width: 0; } .wa-nums b { display: block; font-size: .95rem; color: #111827; } .wa-nums span { font-size: .68rem; color: #9ca3af; font-weight: 600; }
        .wa-ed { display: grid; grid-template-columns: 1fr 300px; gap: 18px; margin-top: 14px; padding-top: 14px; border-top: 1px solid #dcfce7; align-items: start; }
        .wa-compose .wa-ed { border-top: 0; padding-top: 0; }
        .wa-lbl { display: flex; flex-direction: column; gap: 5px; font-size: .78rem; font-weight: 700; color: #6b7280; }
        .wa-varbar { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .wa-varbar button { padding: 4px 9px; border: 1px solid #bbf7d0; border-radius: 999px; background: #f0fdf4; color: #047857; font: inherit; font-size: .74rem; font-weight: 700; cursor: pointer; }
        .wa-varbar button:hover { background: #dcfce7; }
        .wa-tpl { margin-top: 14px; padding: 12px; border-radius: 14px; background: #f9fafb; border: 1px dashed #d1d5db; }
        .wa-tpl-h { display: flex; align-items: center; gap: 8px; } .wa-tpl-h b { flex: 1; font-size: .86rem; color: #111827; } .wa-tpl-h > .material-symbols-rounded { color: #6b7280; }
        .wa-tpl-grid { display: grid; grid-template-columns: auto 1fr; gap: 4px 8px; align-items: center; margin: 10px 0; font-size: .74rem; color: #6b7280; }
        .wa-tpl code { justify-self: start; padding: 2px 6px; border-radius: 6px; background: #fff; border: 1px solid #e5e7eb; color: #111827; font-size: .76rem; word-break: break-all; }
        .wa-tpl pre { margin: 0; padding: 10px; border-radius: 10px; background: #fff; border: 1px solid #e5e7eb; white-space: pre-wrap; font: inherit; font-size: .84rem; color: #111827; }
        .wa-tpl-vars { display: grid; gap: 4px; margin-top: 8px; font-size: .76rem; color: #374151; }
        .wa-tpl-vars i { color: #9ca3af; font-style: normal; }
        .wa-tpl small { display: block; margin-top: 8px; font-size: .72rem; color: #9ca3af; }
        .wa-phone { border-radius: 26px; padding: 8px; background: #111; box-shadow: 0 20px 40px -24px rgba(0,0,0,.6); }
        .wa-phone-top { display: flex; align-items: center; gap: 8px; padding: 10px; border-radius: 18px 18px 0 0; background: #075e54; color: #fff; }
        .wa-phone-top b { display: block; font-size: .82rem; } .wa-phone-top small { font-size: .66rem; color: rgba(255,255,255,.7); }
        .wa-av { width: 30px; height: 30px; display: grid; place-items: center; border-radius: 50%; background: #25d366; font-weight: 800; }
        .wa-chat { min-height: 230px; padding: 14px 10px; border-radius: 0 0 18px 18px; background: #e5ddd5 radial-gradient(rgba(0,0,0,.035) 1px, transparent 1px) 0 0 / 12px 12px; }
        .wa-bubble { position: relative; max-width: 92%; padding: 8px 10px 18px; border-radius: 0 10px 10px 10px; background: #fff; color: #111; font-size: .8rem; line-height: 1.45; box-shadow: 0 1px 1px rgba(0,0,0,.12); animation: waPop .35s cubic-bezier(.2,.9,.3,1.3); word-wrap: break-word; }
        .wa-bubble b { color: #075e54; }
        @keyframes waPop { from { transform: scale(.85); opacity: 0; transform-origin: 0 0; } }
        .wa-time { position: absolute; right: 8px; bottom: 3px; font-size: .62rem; color: #9ca3af; }
        .wa-ed-acts { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; margin-top: 12px; }
        .wa-new { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; padding: 16px; border-radius: 16px; background: linear-gradient(135deg, #f0fdf4, #fff); border: 1px solid #bbf7d0; }
        .wa-new b { display: block; color: #111827; } .wa-new span { font-size: .84rem; color: #6b7280; }
        .wa-compose { padding: 16px; border: 1.5px solid #25d366; border-radius: 16px; background: #fff; }
        .wa-presets { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 14px; }
        .wa-presets span { font-size: .78rem; font-weight: 700; color: #9ca3af; }
        .wa-presets button { padding: 7px 12px; border: 1.5px dashed #86efac; border-radius: 10px; background: #f0fdf4; color: #047857; font: inherit; font-size: .82rem; font-weight: 700; cursor: pointer; }
        .wa-aud { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; margin-top: 6px; }
        .wa-aud button { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; padding: 10px 12px; border: 1.5px solid #e5e7eb; border-radius: 12px; background: #fff; font: inherit; text-align: left; cursor: pointer; }
        .wa-aud button b { font-size: 1.1rem; color: #111827; } .wa-aud button span { font-size: .74rem; color: #6b7280; }
        .wa-aud button.on { border-color: #25d366; background: #f0fdf4; box-shadow: 0 6px 14px -10px rgba(7,94,84,.7); }
        .wa-reach { margin-top: 12px; padding: 10px 12px; border-radius: 12px; background: #f0fdf4; color: #065f46; font-size: .8rem; line-height: 1.45; }
        .wa-reach b { font-size: 1.05rem; }
        .wa-camps { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; margin-top: 14px; }
        .wa-camp { padding: 14px; border: 1px solid #e5e7eb; border-radius: 16px; background: #fff; }
        .wa-camp-h { display: flex; justify-content: space-between; align-items: center; gap: 8px; } .wa-camp-h b { color: #111827; }
        .wa-camp > small { display: block; margin-top: 2px; font-size: .76rem; color: #6b7280; }
        .wa-pill { padding: 3px 9px; border-radius: 999px; font-size: .7rem; font-weight: 800; white-space: nowrap; }
        .wa-pill.ok { background: #ecfdf5; color: #047857; } .wa-pill.wait { background: #eff6ff; color: #1d4ed8; } .wa-pill.bad { background: #fef2f2; color: #b91c1c; } .wa-pill.muted { background: #f3f4f6; color: #6b7280; }
        .wa-lock { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 34px 20px; border-radius: 16px; background: #f9fafb; border: 1px dashed #d1d5db; text-align: center; }
        .wa-lock .material-symbols-rounded { font-size: 34px; color: #128c7e; } .wa-lock b { color: #111827; } .wa-lock p { margin: 0 0 8px; max-width: 460px; font-size: .86rem; color: #6b7280; }
        .wa-logbar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 12px; }
        .wa-log { border: 1px solid #e5e7eb; border-radius: 16px; background: #fff; overflow: hidden; }
        .wa-log-row { display: grid; grid-template-columns: 180px 1fr 110px; gap: 12px; align-items: center; padding: 11px 14px; border-top: 1px solid #f3f4f6; }
        .wa-log-row:first-child { border-top: 0; }
        .wa-log-who b { display: block; font-size: .86rem; color: #111827; } .wa-log-who small { font-size: .72rem; color: #9ca3af; }
        .wa-log-row p { margin: 0; font-size: .8rem; color: #374151; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .wa-log-row p em { display: block; color: #b91c1c; font-style: normal; font-size: .74rem; }
        .wa-log-st { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; } .wa-log-st small { font-size: .7rem; color: #9ca3af; }
        .wa-modes { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .wa-mode { position: relative; display: flex; flex-direction: column; align-items: flex-start; gap: 4px; padding: 16px; border: 2px solid #e5e7eb; border-radius: 16px; background: #fff; font: inherit; text-align: left; cursor: pointer; }
        .wa-mode .material-symbols-rounded { font-size: 26px; color: #9ca3af; } .wa-mode b { color: #111827; } .wa-mode small { font-size: .8rem; color: #6b7280; line-height: 1.45; }
        .wa-mode i { position: absolute; top: 12px; right: 12px; padding: 2px 8px; border-radius: 999px; background: #f3f4f6; color: #6b7280; font-style: normal; font-size: .66rem; font-weight: 800; }
        .wa-mode i.ok { background: #ecfdf5; color: #047857; }
        .wa-mode.on { border-color: #25d366; background: #f0fdf4; } .wa-mode.on .material-symbols-rounded { color: #128c7e; }
        @media (max-width: 900px) { .wa-ed { grid-template-columns: 1fr; } .wa-modes { grid-template-columns: 1fr; } .wa-log-row { grid-template-columns: 1fr auto; } .wa-log-row p { grid-column: 1 / -1; grid-row: 2; } }
        /* Missed calls tab */
        .wa-mc-setup { margin-top: 14px; }
        .wa-mc-h { font-size: .95rem; color: #111827; }
        .wa-mc-steps { margin: 10px 0 0; padding-left: 20px; display: grid; gap: 12px; font-size: .86rem; line-height: 1.55; color: #374151; }
        .wa-mc-steps b { color: #111827; }
        .wa-mc-steps small { display: block; margin-top: 4px; color: #6b7280; }
        .wa-mc-url { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 8px; }
        .wa-mc-url code { flex: 1 1 320px; min-width: 0; padding: 9px 11px; border-radius: 10px; background: #f3f4f6; border: 1px solid #e5e7eb; font-size: .76rem; color: #111827; word-break: break-all; }
        .wa-mc-kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-top: 14px; }
        .wa-mc-kpis div { padding: 12px 14px; border-radius: 12px; background: #f9fafb; border: 1px solid #eef0f3; }
        .wa-mc-kpis b { display: block; font-size: 1.3rem; color: #111827; }
        .wa-mc-kpis span { color: #6b7280 !important; font-size: .78rem; }
      </style>
      <div class="page-header">
        <h1>WhatsApp</h1>
        <p>Every gift reaches your customers on WhatsApp by itself, and you can send campaigns to the people you choose.</p>
      </div>
      <div class="page-content"><div id="waRoot"></div></div>
    </div>

    <div id="referralsPage" class="page sx lg">
      <div class="page-header">
        <h1>Referrals</h1>
        <p>Track referral signups and conversions</p>
      </div>
      <div class="page-content">
        <div class="analytics-stats" id="referralSummaryCards"></div>
        <div class="section-card">
          <div class="section-header">
            <div class="section-title">Top Referrers</div>
          </div>
          <div class="section-body" style="overflow-x:auto;">
            <table class="data-table" id="referralLeaderboardTable">
              <thead>
                <tr><th>Customer</th><th>Phone</th><th>Total Referrals</th><th>Completed</th></tr>
              </thead>
              <tbody id="referralLeaderboardTbody">
                <tr><td colspan="4" style="text-align:center;padding:30px;color:#999;">Loading...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="section-card">
          <div class="section-header">
            <div class="section-title">All Referrals</div>
          </div>
          <div class="section-body" style="overflow-x:auto;">
            <table class="data-table" id="referralsTable">
              <thead>
                <tr><th>Referrer</th><th>Referred</th><th>Code</th><th>Status</th><th>Date</th></tr>
              </thead>
              <tbody id="referralsTbody">
                <tr><td colspan="5" style="text-align:center;padding:30px;color:#999;">Loading...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ Growth Module: Reports ═══════ -->
    <div id="growthReportsPage" class="page sx lg">
      <div class="page-header">
        <h1>Growth Reports</h1>
        <p>Revenue attributable to loyalty and referrals</p>
      </div>
      <div class="page-content">
        <div class="section-card">
          <div class="section-header">
            <div class="section-title">Customer Lifetime Value &amp; Churn Risk</div>
            <div style="display:flex;gap:8px;">
              <button class="btn" style="padding:6px 14px;font-size:0.8rem;" onclick="exportClvCsv()">Export CSV</button>
              <button class="btn" style="padding:6px 14px;font-size:0.8rem;" onclick="exportClvPdf()">Export PDF</button>
            </div>
          </div>
          <div class="section-body">
            <div class="analytics-stats" id="clvSummaryCards"></div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin:16px 0;" id="clvFilterTabs"></div>
            <div style="overflow-x:auto;">
              <table class="data-table" id="clvTable">
                <thead>
                  <tr><th>Customer</th><th>Phone</th><th>Lifetime Value</th><th>Avg Order Value</th><th>Predicted CLV (2yr)</th><th>Days Since Last Visit</th><th>Churn Risk</th></tr>
                </thead>
                <tbody id="clvTbody">
                  <tr><td colspan="7" style="text-align:center;padding:30px;color:#999;">Loading...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="section-card">
          <div class="section-header">
            <div class="section-title">Last 30 Days vs Previous 30 Days</div>
          </div>
          <div class="section-body" style="overflow-x:auto;">
            <table class="data-table" id="growthTrendTable">
              <thead>
                <tr><th>Metric</th><th>Last 30 Days</th><th>Previous 30 Days</th><th>Change</th></tr>
              </thead>
              <tbody id="growthTrendTbody">
                <tr><td colspan="4" style="text-align:center;padding:30px;color:#999;">Loading...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="section-card">
          <div class="section-header">
            <div class="section-title">Loyalty Program ROI</div>
          </div>
          <div class="analytics-stats" id="loyaltyRoiCards">
          </div>
        </div>
        <div class="section-card">
          <div class="section-header">
            <div class="section-title">Referral Revenue</div>
          </div>
          <div class="analytics-stats" id="referralRevenueCards">
          </div>
        </div>
        <div class="section-card">
          <div class="section-header">
            <div class="section-title">Revenue by Segment</div>
          </div>
          <div class="section-body" style="overflow-x:auto;">
            <table class="data-table" id="segmentRevenueTable">
              <thead>
                <tr><th>Segment</th><th>Customers</th><th>Total Revenue</th></tr>
              </thead>
              <tbody id="segmentRevenueTbody">
                <tr><td colspan="3" style="text-align:center;padding:30px;color:#999;">Loading...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Google Business: rating, reviews and replies, posts, profile, insights, review links
         (assets/js/gbp-studio.js, api/gbp.php, config/gbp_helpers.php) -->
    <div id="googleBusinessPage" class="page sx">
      <div class="page-header">
        <h1>Google Business</h1>
        <p>Your Google rating and reviews, replies, posts, hours and how people find you on Google Search and Maps.</p>
      </div>
      <div class="page-content">
        <div id="gbpRoot"><div class="loading" data-loading-fallback>Loading Google Business...</div></div>
      </div>
    </div>

    <!-- SEO: titles, descriptions, share picture, tracking IDs and health score for the customer website
         (assets/js/seo-studio.js, api/seo.php, config/seo_helpers.php, website/sitemap.php) -->
    <div id="seoPage" class="page sx">
      <div class="page-header">
        <h1>SEO</h1>
        <p>Get found on Google: how your website shows in search results and when it’s shared.</p>
      </div>
      <div class="page-content">
        <div id="seoRoot"><div class="loading" data-loading-fallback>Loading SEO...</div></div>
      </div>
    </div>

    <!-- Deals Page -->
    <!-- Offers › Combos & Deals: real combos at one price (assets/js/combos-studio.js, api/combos_admin.php);
         the Menu page's old "Combos" / "New" category filters stay below (offers-studio.js) -->
    <div id="dealsPage" class="page sx">
      <div class="page-header">
        <h1>Combos &amp; Deals</h1>
        <p>Bundle dishes at one price. They show at the top of your website, customers pick their choices, and the price is checked at checkout.</p>
      </div>
      <div class="page-content">
        <div id="combosRoot"></div>
        <details class="sx-card cbx-filters">
          <summary class="cbx-sec-h"><b><span class="material-symbols-rounded">tune</span>Menu filter buttons</b><small>Optional: a “Combos” and a “New” button on your Menu page that jump to a category.</small></summary>
          <div id="dealsRoot"></div>
        </details>
      </div>
    </div>
    <!-- Bestsellers Page (Offers > Bestsellers) -->
    <div id="bestsellersPage" class="page">
      <div class="page-header">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
          <div>
            <h1 style="margin:0;font-size:1.5rem;">Bestsellers</h1>
            <p style="margin:0.25rem 0 0;color:#6b7280;">Pick the dishes to feature in the “Bestsellers” row on your website, in the order you want. Add an offer price to show a discount.</p>
          </div>
          <button type="button" class="btn btn-primary" id="bsSaveBtn" style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.75rem 1.5rem;" disabled>
            <span class="material-symbols-rounded" style="font-size:1.2rem;">save</span>Save changes
          </button>
        </div>
      </div>
      <div class="page-content">
        <div class="bs-admin-grid">
          <div class="section-card">
            <div class="section-header">
              <div class="section-title">Featured on your website <span class="bs-count" id="bsCount"></span></div>
            </div>
            <div class="section-body">
              <ol class="bs-selected" id="bsSelected">
                <li class="bs-empty" data-loading-fallback>Loading…</li>
              </ol>
              <p class="bs-note">Offer prices apply to the website menu, cart and checkout. For dishes with sizes, choose one discount for every size or a separate offer price for each size.</p>
            </div>
          </div>
          <div class="section-card">
            <div class="section-header">
              <div class="section-title">Add dishes</div>
            </div>
            <div class="section-body">
              <div class="bs-search">
                <span class="material-symbols-rounded">search</span>
                <input type="search" id="bsSearch" placeholder="Search your menu…" autocomplete="off">
              </div>
              <div class="bs-picker" id="bsPicker"></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Offer Filters Page (Offers > Offer Filters) -->
    <div id="todaysSpecialPage" class="page">
      <style>
        .ts-wrap { max-width: 980px; }
        .ts-now { display: flex; align-items: center; gap: 8px; padding: 12px 14px; margin-bottom: 14px; border-radius: 12px; background: #fff7ed; color: #9a3412; font-size: 0.9rem; }
        .ts-now .material-symbols-rounded { font-size: 20px; }
        .ts-row { display: grid; grid-template-columns: 170px minmax(0, 1fr); gap: 12px; align-items: start; padding: 12px; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; margin-bottom: 8px; }
        .ts-head { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1.6fr) 100px 34px; gap: 8px; margin-left: 182px; padding: 0 12px 6px; font-size: 0.75rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
        .ts-entries { display: flex; flex-direction: column; gap: 8px; }
        .ts-entry { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1.6fr) 100px 34px; gap: 8px; align-items: center; }
        .ts-del { width: 34px; height: 34px; border: 1px solid #fecaca; border-radius: 9px; background: #fef2f2; color: #dc2626; font-size: 18px; cursor: pointer; }
        .ts-add { align-self: flex-start; display: inline-flex; align-items: center; gap: 4px; padding: 7px 12px; border: 1.5px dashed #d1d5db; border-radius: 9px; background: #fff; color: #374151; font: inherit; font-size: 0.85rem; font-weight: 600; cursor: pointer; }
        .ts-add .material-symbols-rounded { font-size: 18px; }
        .ts-add:disabled { opacity: .5; cursor: default; }
        .ts-count { display: block; margin-top: 4px; font-size: 0.72rem; color: #9ca3af; }
        .ts-row.every { background: #faf5ff; border-color: #e9d5ff; margin-bottom: 14px; }
        .ts-day b { display: block; font-size: 0.92rem; color: #111827; }
        .ts-day small { display: block; font-size: 0.72rem; color: #6b7280; }
        .ts-today { margin-left: 4px; padding: 2px 7px; border-radius: 999px; background: #16a34a; color: #fff; font-size: 0.65rem; font-weight: 700; vertical-align: middle; }
        .ts-entry select, .ts-entry input { width: 100%; padding: 9px 10px; border: 1.5px solid #e5e7eb; border-radius: 9px; font: inherit; font-size: 0.88rem; background: #fff; }
        .ts-empty { padding: 26px; text-align: center; color: #6b7280; border: 1px dashed #e5e7eb; border-radius: 12px; }
        @media (max-width: 860px) { .ts-head { display: none; } .ts-row { grid-template-columns: 1fr; } .ts-entry { grid-template-columns: 1fr 90px 34px; } .ts-entry .ts-note { grid-column: 1 / -1; order: 5; } }
      </style>
      <div class="ts-wrap">
        <div class="page-header" style="margin-bottom:14px;">
          <h1 style="display:flex;align-items:center;gap:8px;"><span class="material-symbols-rounded">local_fire_department</span> Today's Special</h1>
          <p style="color:#6b7280;margin:4px 0 0;">Dishes of the day for each weekday (up to 6 a day), shown on your home page above Bestsellers with your note, a countdown to the end of the day and how many plates are left.</p>
        </div>
        <div class="ts-now" id="tsTodayNow"></div>
        <div class="ts-head"><span>Dish</span><span>Chef's note (optional)</span><span>Plates / day</span><span></span></div>
        <div id="tsSlots"></div>
        <div style="display:flex;justify-content:flex-end;margin-top:6px;">
          <button type="button" class="btn btn-primary" id="tsSave"><span class="material-symbols-rounded">save</span> Save specials</button>
        </div>
      </div>
    </div>

    <div id="upsellPage" class="page">
      <style>
        .up-wrap { max-width: 980px; }
        .up-stats { position: relative; display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 14px; }
        .up-stats > div { padding: 14px; border: 1px solid #e5e7eb; border-radius: 14px; background: #fff; }
        .up-stats > div.hi { background: linear-gradient(135deg, #ecfdf5, #d1fae5); border-color: #a7f3d0; }
        .up-stats b { display: block; font-size: 1.4rem; color: #111827; }
        .up-stats span { font-size: 0.78rem; color: #6b7280; }
        .up-stats small { position: absolute; right: 4px; top: -18px; font-size: 0.7rem; color: #9ca3af; }
        .up-card { padding: 16px; border: 1px solid #e5e7eb; border-radius: 14px; background: #fff; margin-bottom: 12px; transition: opacity .2s; }
        .up-card.off { opacity: .45; pointer-events: none; }
        .up-card h3 { display: flex; align-items: center; gap: 6px; margin: 0 0 10px; font-size: 1rem; color: #111827; }
        .up-card h3 .material-symbols-rounded { font-size: 20px; color: #7c3aed; }
        .up-switch { display: flex; align-items: center; gap: 10px; font-weight: 600; color: #111827; cursor: pointer; margin-bottom: 12px; }
        .up-switch input { display: none; }
        .up-switch span { width: 40px; height: 22px; border-radius: 999px; background: #d1d5db; position: relative; transition: background .2s; flex-shrink: 0; }
        .up-switch span::after { content: ''; position: absolute; left: 3px; top: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: transform .2s; }
        .up-switch input:checked + span { background: #16a34a; }
        .up-switch input:checked + span::after { transform: translateX(18px); }
        .up-modes { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 14px; }
        .up-mode { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; padding: 12px; border: 2px solid #e5e7eb; border-radius: 12px; background: #fff; text-align: left; font: inherit; cursor: pointer; }
        .up-mode .material-symbols-rounded { font-size: 22px; color: #9ca3af; }
        .up-mode b { font-size: 0.92rem; color: #111827; }
        .up-mode b em { margin-left: 4px; padding: 1px 6px; border-radius: 999px; background: #ede9fe; color: #6d28d9; font-size: 0.65rem; font-style: normal; }
        .up-mode small { font-size: 0.76rem; color: #6b7280; line-height: 1.35; }
        .up-mode.on { border-color: #7c3aed; background: #faf5ff; }
        .up-mode.on .material-symbols-rounded { color: #7c3aed; }
        .up-grid { display: grid; grid-template-columns: 1.4fr 1fr 1fr; gap: 10px; margin-bottom: 10px; }
        .up-grid label, .up-roles label { display: flex; flex-direction: column; gap: 4px; font-size: 0.78rem; font-weight: 600; color: #6b7280; }
        #upsellPage select, #upsellPage input:not([type=checkbox]) { padding: 8px 10px; border: 1.5px solid #e5e7eb; border-radius: 9px; font: inherit; font-size: 0.86rem; background: #fff; color: #111827; }
        .up-check { display: flex; align-items: center; gap: 8px; margin-top: 6px; font-size: 0.86rem; color: #374151; cursor: pointer; }
        .up-sub { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; font-size: 0.86rem; font-weight: 700; color: #111827; }
        .up-sub small { font-weight: 400; color: #6b7280; }
        .up-chips { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
        .up-chips select { max-width: 220px; }
        .up-chip { display: inline-flex; align-items: center; gap: 4px; padding: 5px 6px 5px 11px; border-radius: 999px; background: #f3f4f6; font-size: 0.82rem; font-weight: 600; color: #111827; }
        .up-chip-x, .up-pair-x { width: 22px; height: 22px; border: 0; border-radius: 50%; background: #fff; color: #6b7280; font-size: 15px; line-height: 1; cursor: pointer; }
        .up-pair { display: grid; grid-template-columns: 170px 22px 1fr 26px; align-items: center; gap: 8px; padding: 8px 10px; margin-bottom: 6px; border: 1px solid #f3f4f6; border-radius: 10px; background: #fafafa; }
        .up-pair b { font-size: 0.86rem; }
        .up-pair > .material-symbols-rounded { font-size: 18px; color: #9ca3af; }
        .up-pair-x { background: #fef2f2; color: #dc2626; }
        .up-roles { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 10px; }
        .up-link { margin-left: auto; display: inline-flex; align-items: center; gap: 3px; border: 0; background: none; color: #7c3aed; font: inherit; font-size: 0.8rem; font-weight: 600; cursor: pointer; }
        .up-link .material-symbols-rounded { font-size: 16px; }
        .up-learned { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 6px; }
        .up-learned div { display: flex; align-items: center; gap: 6px; padding: 8px 10px; border-radius: 10px; background: #f5f3ff; font-size: 0.82rem; font-weight: 600; color: #111827; }
        .up-learned .material-symbols-rounded { font-size: 15px; color: #7c3aed; }
        .up-learned em { margin-left: auto; font-style: normal; font-weight: 500; font-size: 0.74rem; color: #6d28d9; white-space: nowrap; }
        .up-empty { padding: 16px; text-align: center; color: #6b7280; font-size: 0.86rem; border: 1px dashed #e5e7eb; border-radius: 10px; }
        .up-preview { background: #fcfcfd; }
        #upPreview { margin-top: 12px; }
        .up-pv-head { font-weight: 800; font-size: 0.95rem; margin-bottom: 8px; color: #111827; }
        .up-pv-head small { font-weight: 500; color: #9ca3af; margin-left: 6px; }
        .up-pv { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 6px; }
        .up-pv-card { flex: 0 0 150px; display: flex; flex-direction: column; gap: 3px; padding: 8px; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; }
        .up-pv-card img, .up-pv-card .ph { width: 100%; height: 90px; object-fit: cover; border-radius: 8px; background: #f3f4f6; }
        .up-pv-card b { font-size: 0.84rem; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .up-pv-card .why { font-size: 0.7rem; color: #7c3aed; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .up-pv-card .pr { font-size: 0.84rem; font-weight: 700; color: #111827; }
        @media (max-width: 760px) { .up-stats, .up-modes, .up-grid { grid-template-columns: 1fr 1fr; } .up-pair { grid-template-columns: 1fr 26px; } .up-pair > .material-symbols-rounded { display: none; } .up-pair .up-chips { grid-column: 1 / -1; } }
      </style>
      <div class="up-wrap">
        <div class="page-header" style="margin-bottom:22px;">
          <h1 style="display:flex;align-items:center;gap:8px;"><span class="material-symbols-rounded">add_shopping_cart</span> Complete your meal</h1>
          <p style="color:#6b7280;margin:4px 0 0;">Suggestions on the cart page that raise the order value: a drink with the biryani, a dessert to finish. Let it pick from your real orders, choose the dishes yourself, or both.</p>
        </div>
        <div id="upBody"></div>
        <div style="display:flex;justify-content:flex-end;margin-top:6px;">
          <button type="button" class="btn btn-primary" id="upSave"><span class="material-symbols-rounded">save</span> Save</button>
        </div>
      </div>
    </div>

    <div id="scratchPage" class="page">
      <style>
        .scx-wrap { max-width: 1100px; }
        .scx-stats { position: relative; display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-bottom: 14px; }
        .scx-stats > div { padding: 14px; border: 1px solid #e5e7eb; border-radius: 14px; background: #fff; }
        .scx-stats > div.hi { background: linear-gradient(135deg, #fff1f2, #ffedd5); border-color: #fecdd3; }
        .scx-stats b { display: block; font-size: 1.4rem; color: #111827; }
        .scx-stats span { font-size: 0.78rem; color: #6b7280; }
        .scx-stats small { position: absolute; right: 4px; top: -18px; font-size: 0.7rem; color: #9ca3af; }
        .scx-cols { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); gap: 12px; align-items: start; }
        .scx-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .scx-grid label { display: flex; flex-direction: column; gap: 4px; font-size: 0.78rem; font-weight: 600; color: #6b7280; }
        .scx-grid label:first-child { grid-column: 1 / -1; }
        #scratchPage select, #scratchPage input:not([type=checkbox]) { padding: 8px 10px; border: 1.5px solid #e5e7eb; border-radius: 9px; font: inherit; font-size: 0.86rem; background: #fff; color: #111827; min-width: 0; }
        #scratchPage input:disabled { background: #f9fafb; }
        .scx-budget { margin-top: 12px; }
        .scx-budget > div { height: 8px; border-radius: 999px; background: #f3f4f6; overflow: hidden; }
        .scx-budget i { display: block; height: 100%; background: linear-gradient(90deg, #f59e0b, #f43f5e); border-radius: 999px; }
        .scx-budget span { display: block; margin-top: 6px; font-size: 0.78rem; color: #6b7280; }
        .scx-prize-head, .scx-prize { display: grid; grid-template-columns: minmax(0, 1.4fr) 90px minmax(0, 1fr) 124px 26px; gap: 8px; align-items: center; }
        .scx-prize-head em { font-style: normal; font-weight: 500; text-transform: none; letter-spacing: 0; color: #9ca3af; }
        .scx-prize-head { padding: 0 2px 6px; font-size: 0.72rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
        .scx-prize { margin-bottom: 8px; }
        .scx-weight { display: flex; align-items: center; gap: 6px; }
        .scx-weight input { width: 64px; }
        .scx-weight b { font-size: 0.85rem; color: #be123c; min-width: 46px; }
        .scx-math { display: flex; gap: 10px; align-items: flex-start; margin-top: 12px; padding: 12px; border-radius: 12px; background: #f0fdf4; color: #14532d; font-size: 0.86rem; }
        .scx-math .material-symbols-rounded { color: #16a34a; }
        .scx-note { margin: 10px 0 0; font-size: 0.78rem; color: #6b7280; line-height: 1.5; }
        .scx-phone { max-width: 300px; margin: 0 auto; padding: 14px; border-radius: 20px; background: #fff; box-shadow: 0 10px 30px rgba(17,24,39,.1); border: 1px solid #f3f4f6; }
        .scx-mock-head { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
        .scx-mock-head i { width: 32px; height: 32px; border-radius: 10px; background: linear-gradient(135deg, #f59e0b, #f43f5e); }
        .scx-mock-head b { display: block; font-size: 0.88rem; color: #111827; }
        .scx-mock-head small { font-size: 0.72rem; color: #6b7280; }
        .scx-foil { position: relative; height: 110px; border-radius: 14px; overflow: hidden; display: grid; place-items: center; background: linear-gradient(135deg, #c0c4cc, #eef0f3 45%, #d5d8de 55%, #a9aeb8); }
        .scx-foil span { font-weight: 800; color: #e11d48; letter-spacing: .1em; font-size: 0.85rem; }
        .scx-foil em { position: absolute; inset: 0; background: linear-gradient(110deg, transparent 30%, rgba(255,255,255,.7) 50%, transparent 70%); background-size: 250% 100%; animation: scxShine 2.4s linear infinite; }
        @keyframes scxShine { from { background-position: 150% 0; } to { background-position: -100% 0; } }
        .scx-mock-odds { margin-top: 8px; font-size: 0.72rem; color: #6b7280; font-weight: 600; }
        .scx-recent > div { display: grid; grid-template-columns: 1fr 1.2fr auto; gap: 8px; align-items: center; padding: 8px 0; border-bottom: 1px solid #f3f4f6; font-size: 0.82rem; }
        .scx-recent .ph { font-weight: 600; color: #111827; }
        .scx-recent .ph small { display: block; font-weight: 400; color: #9ca3af; font-size: 0.7rem; }
        .scx-recent .pz { color: #be123c; font-weight: 600; }
        .scx-recent .pz.no { color: #6b7280; font-weight: 500; }
        .scx-recent .tag { padding: 3px 8px; border-radius: 999px; background: #fff1f2; color: #9f1239; font-size: 0.7rem; font-weight: 700; letter-spacing: .04em; }
        .scx-recent .tag.used { background: #dcfce7; color: #166534; }
        @media (max-width: 960px) { .scx-cols { grid-template-columns: 1fr; } .scx-stats { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 600px) { .scx-grid { grid-template-columns: 1fr; } .scx-prize-head { display: none; } .scx-prize { grid-template-columns: 1fr 26px; } .scx-prize .scx-value, .scx-prize .scx-pmin, .scx-prize .scx-weight { grid-column: 1; } }
      </style>
      <div class="scx-wrap">
        <div class="page-header" style="margin-bottom:22px;">
          <h1 style="display:flex;align-items:center;gap:8px;"><span class="material-symbols-rounded">confirmation_number</span> Scratch card</h1>
          <p style="color:#6b7280;margin:4px 0 0;">After checkout, customers scratch a card on the order tracking page and can win a reward for their next order. You choose the prizes, the chances and a monthly budget.</p>
        </div>
        <div id="scBody"></div>
        <div style="display:flex;justify-content:flex-end;margin-top:6px;">
          <button type="button" class="btn btn-primary" id="scSave"><span class="material-symbols-rounded">save</span> Save</button>
        </div>
      </div>
    </div>

    <div id="walletPage" class="page">
      <style>
        .wl-warn { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; margin-bottom: 12px; border-radius: 12px; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; font-size: 0.86rem; }
        .wl-tier { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 0.86rem; color: #374151; font-weight: 600; }
        .wl-tier input { width: 110px; }
        .wl-adj { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .wl-adj input:nth-child(3) { grid-column: 1 / -1; }
        .wl-adj .btn { grid-column: 1 / -1; justify-content: center; }
        #walletPage select, #walletPage input:not([type=checkbox]) { padding: 8px 10px; border: 1.5px solid #e5e7eb; border-radius: 9px; font: inherit; font-size: 0.86rem; background: #fff; color: #111827; min-width: 0; }
        #walletPage .scx-stats { grid-template-columns: repeat(4, 1fr); }
        @media (max-width: 960px) { #walletPage .scx-stats { grid-template-columns: repeat(2, 1fr); } }
      </style>
      <div class="scx-wrap">
        <div class="page-header" style="margin-bottom:22px;">
          <h1 style="display:flex;align-items:center;gap:8px;"><span class="material-symbols-rounded">account_balance_wallet</span> Wallet</h1>
          <p style="color:#6b7280;margin:4px 0 0;">Customers add money with PhonePe and pay for orders from it on your website. Give extra money on bigger top-ups and cashback on orders to bring them back. The money can only be used at your restaurant.</p>
        </div>
        <div id="wlBody"></div>
        <div style="display:flex;justify-content:flex-end;margin-top:6px;">
          <button type="button" class="btn btn-primary" id="wlSave"><span class="material-symbols-rounded">save</span> Save</button>
        </div>
      </div>
    </div>

    <div id="offerFiltersPage" class="page">
      <style>
        .of-grid { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr); gap: 20px; align-items: start; }
        @media (max-width: 1000px) { .of-grid { grid-template-columns: 1fr; } }
        .of-presets { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
        .of-preset { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border: 1px dashed #d1d5db; border-radius: 999px; background: #fff; color: #374151; font: inherit; font-size: 0.85rem; cursor: pointer; }
        .of-preset:hover { border-color: #e53935; color: #e53935; }
        .of-list { display: flex; flex-direction: column; gap: 10px; }
        .of-empty { padding: 26px 12px; text-align: center; color: #6b7280; font-size: 0.9rem; border: 1px dashed #e5e7eb; border-radius: 12px; }
        .of-card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px; background: #fff; }
        .of-card.off { opacity: 0.6; }
        .of-top { display: flex; align-items: center; gap: 8px; }
        .of-icon { width: 54px; padding: 6px 4px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 1.1rem; background: #fff; }
        .of-title { flex: 1; min-width: 0; padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 8px; font: inherit; font-weight: 600; }
        .of-actions { display: flex; gap: 2px; }
        .of-actions button { width: 30px; height: 30px; border: 0; border-radius: 8px; background: none; color: #6b7280; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
        .of-actions button:hover { background: #f3f4f6; color: #111827; }
        .of-actions .material-symbols-rounded { font-size: 1.15rem; }
        .of-rule { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 10px; font-size: 0.88rem; color: #4b5563; }
        .of-rule select, .of-rule input[type=number] { width: auto; padding: 7px 10px; border: 1px solid #e5e7eb; border-radius: 8px; font: inherit; background: #fff; }
        .of-rule input[type=number] { width: 96px; }
        .of-switch { margin-left: auto; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
        .of-pick { margin-top: 10px; border-top: 1px solid #f3f4f6; padding-top: 10px; }
        .of-pick input[type=search] { width: 100%; padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 8px; font: inherit; margin-bottom: 8px; }
        .of-pick-list { max-height: 220px; overflow: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 4px 12px; }
        .of-pick-list label { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; padding: 4px 2px; cursor: pointer; }
        .of-pick-list small { color: #9ca3af; }
        .of-phone { max-width: 320px; margin: 0 auto; border: 1px solid #e5e7eb; border-radius: 22px; overflow: hidden; background: #f3f4f6; }
        .of-phone-sheet { background: #fff; border-radius: 18px 18px 0 0; margin-top: 60px; padding: 16px; }
        .of-phone-sheet h4 { margin: 0 0 12px; font-size: 1rem; }
        .of-chips { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .of-chip { display: flex; align-items: center; gap: 8px; padding: 10px; border-radius: 12px; background: #fff7ed; border: 1px solid #fed7aa; font-size: 0.82rem; font-weight: 700; color: #9a3412; }
        .of-chip span:first-child { font-size: 1.15rem; }
        .of-bubble-demo { display: flex; justify-content: flex-end; padding: 0 16px 16px; background: #fff; }
        .of-bubble-demo span { width: 46px; height: 46px; border-radius: 50%; background: #e53935; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; box-shadow: 0 6px 16px rgba(229,57,53,0.35); }
      </style>
      <div class="page-header">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
          <div>
            <h1 style="margin:0;font-size:1.5rem;">Offer Filters</h1>
            <p style="margin:0.25rem 0 0;color:#6b7280;">Quick filters for your website menu. Customers tap the floating offers bubble on the Menu page and pick one, like “Under ₹99” or “50% OFF &amp; more”. The bubble only shows when at least one filter is turned on.</p>
          </div>
          <button type="button" class="btn btn-primary" id="ofSaveBtn" style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.75rem 1.5rem;" disabled>
            <span class="material-symbols-rounded" style="font-size:1.2rem;">save</span>Save changes
          </button>
        </div>
      </div>
      <div class="page-content">
        <div class="of-grid">
          <div class="section-card">
            <div class="section-header">
              <div class="section-title">Your filters <span class="bs-count" id="ofCount"></span></div>
            </div>
            <div class="section-body">
              <div class="of-presets" id="ofPresets"></div>
              <div class="of-list" id="ofList"><div class="of-empty">Loading…</div></div>
              <p class="bs-note">Filters only use your real menu: prices and discounts come from the dishes themselves (offer prices are set in Offers → Bestsellers). A filter with no matching dishes is hidden from customers.</p>
            </div>
          </div>
          <div class="section-card">
            <div class="section-header">
              <div class="section-title">How customers see it</div>
            </div>
            <div class="section-body">
              <div class="of-phone">
                <div class="of-phone-sheet">
                  <h4>Offers for you</h4>
                  <div class="of-chips" id="ofPreview"></div>
                </div>
                <div class="of-bubble-demo"><span>%</span></div>
              </div>
            </div>
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
            <label class="form-group" for="cpnMilestone" style="display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid #fde68a;border-radius:10px;background:#fffbeb;cursor:pointer;">
              <input type="checkbox" id="cpnMilestone" style="margin-top:3px;width:16px;height:16px;flex-shrink:0;">
              <span><strong>Milestone offer</strong><br><span style="font-size:0.85rem;color:#6b7280;">Show it on the cart's progress bar (“Add ₹40 more to get this offer”) and apply it automatically once the order reaches the minimum amount. Make a few, e.g. ₹100, ₹200 and ₹300.</span></span>
            </label>
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
    <div id="deliveryPage" class="page sx lg">
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
    <div id="deliveryMapPage" class="page sx lg">
      <div class="page-header">
        <h1>Delivery Map</h1>
        <p>Track active deliveries in real-time</p>
      </div>
      <div class="page-content">
        <div class="lg-legend">
          <span class="lg-live">Updates every 30 seconds</span>
          <span><i style="background:#f59e0b"></i>Preparing</span>
          <span><i style="background:#3b82f6"></i>Assigned</span>
          <span><i style="background:#8b5cf6"></i>Picked up</span>
          <span><i style="background:#059669"></i>On the way</span>
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
    <div id="feedbackPage" class="page sx lg">
      <div class="page-header">
        <h1>Customer Feedback</h1>
        <p>Reviews and ratings from your customers</p>
      </div>
      <div class="page-content">
        <div class="lg-stats">
          <div class="lg-stat" style="--c:#2563eb"><span class="sx-ic material-symbols-rounded">reviews</span><div><small>Reviews</small><b id="fbTotal">0</b></div></div>
          <div class="lg-stat" style="--c:#d97706"><span class="sx-ic material-symbols-rounded">star</span><div><small>Average rating</small><b id="fbAvg">0.0</b></div></div>
          <div class="lg-stat" style="--c:#16a34a"><span class="sx-ic material-symbols-rounded">sentiment_satisfied</span><div><small>Positive (4–5 stars)</small><b id="fbPositive">0</b></div></div>
          <div class="lg-stat" style="--c:#dc2626"><span class="sx-ic material-symbols-rounded">sentiment_dissatisfied</span><div><small>Negative (1–2 stars)</small><b id="fbNegative">0</b></div></div>
        </div>
        <div id="fbInsights"></div>
        <div class="section-card">
          <div class="section-header">
            <div class="section-title">All reviews</div>
            <select id="fbFilter" class="filter-select" onchange="renderFeedback()">
              <option value="all">All Ratings</option>
              <option value="5">5 stars</option>
              <option value="4">4 stars</option>
              <option value="3">3 stars</option>
              <option value="2">2 stars</option>
              <option value="1">1 star</option>
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
    <!-- Settings: sections listed on the left, one open at a time, one save bar for all of them.
         Fields keep their ids: the save handlers (script.js, systemSettingsForm below, printer)
         read them by id. data-st-form on each card says which save it belongs to.
         Layout + save bar: assets/js/settings-studio.js, styles st-* in assets/css/studio-ui.css. -->
    <div id="settingsPage" class="page sx">
      <div class="page-header">
        <h1>Settings</h1>
        <p>Your restaurant details, ordering rules, billing and devices.</p>
      </div>
      <div class="page-content st-wrap">
        <!-- the four saves, kept as forms so their existing handlers run unchanged -->
        <form id="restaurantSettingsForm" class="st-form-proxy"><button type="submit" class="btn-save" tabindex="-1">Save</button></form>
        <form id="profileSettingsForm" class="st-form-proxy"><button type="submit" class="btn-save" tabindex="-1">Save</button></form>
        <form id="systemSettingsForm" class="st-form-proxy"><button type="submit" class="btn-save" tabindex="-1">Save</button></form>
        <form id="printerSettingsForm" class="st-form-proxy" onsubmit="return false;"></form>

        <nav class="st-nav" aria-label="Settings sections">
          <div class="st-nav-group"><small>Business</small>
            <button type="button" data-st-go="profile" class="on"><span class="material-symbols-rounded">storefront</span><span><b>Restaurant profile</b><em>Name, tagline, contact</em></span></button>
            <button type="button" data-st-go="location"><span class="material-symbols-rounded">location_on</span><span><b>Location</b><em>Address and map pin</em></span></button>
            <button type="button" data-st-go="hours"><span class="material-symbols-rounded">schedule</span><span><b>Business hours</b><em>When you take orders</em></span></button>
          </div>
          <div class="st-nav-group"><small>Orders</small>
            <button type="button" data-st-go="ordering"><span class="material-symbols-rounded">shopping_bag</span><span><b>Ordering</b><em>Order types, reservations</em></span></button>
            <button type="button" data-st-go="delivery"><span class="material-symbols-rounded">local_shipping</span><span><b>Delivery</b><em>Radius and charges</em></span></button>
            <button type="button" data-st-go="payments"><span class="material-symbols-rounded">payments</span><span><b>Payments</b><em>Cash and payment QR</em></span></button>
            <button type="button" data-st-go="tax"><span class="material-symbols-rounded">receipt_long</span><span><b>Tax and bill</b><em>GST / VAT, business ID</em></span></button>
          </div>
          <div class="st-nav-group"><small>Website</small>
            <button type="button" data-st-go="social"><span class="material-symbols-rounded">share</span><span><b>Social links</b><em>Instagram, Facebook…</em></span></button>
            <button type="button" data-st-go="region"><span class="material-symbols-rounded">public</span><span><b>Region and language</b><em>Country, currency, time</em></span></button>
          </div>
          <div class="st-nav-group"><small>Devices and account</small>
            <button type="button" data-st-go="printer"><span class="material-symbols-rounded">print</span><span><b>Printer</b><em>Receipts and KOTs</em></span></button>
            <button type="button" data-st-go="account"><span class="material-symbols-rounded">account_circle</span><span><b>Account</b><em>Login, notifications, app</em></span></button>
            <button type="button" data-st-go="navstyle"><span class="material-symbols-rounded">space_dashboard</span><span><b>Navigation style</b><em>Sidebar, dock or round menu</em></span></button>
          </div>
        </nav>

        <div class="st-panes">

          <!-- ── Restaurant profile ── -->
          <section class="st-pane on" data-st-pane="profile">
            <header class="st-pane-h"><span class="material-symbols-rounded">storefront</span><div><h2>Restaurant profile</h2><p>How your restaurant appears on your website, bills and policy pages.</p></div></header>
            <div class="st-card" data-st-form="restaurant">
              <div class="st-grid2">
                <div class="st-field">
                  <label for="restaurantNameSetting">Restaurant name</label>
                  <input type="text" id="restaurantNameSetting" placeholder="Enter restaurant name" required>
                </div>
                <div class="st-field">
                  <label for="ownerName">Owner name</label>
                  <input type="text" id="ownerName" placeholder="Shown on your policy pages">
                </div>
              </div>
              <div class="st-field">
                <label for="restaurantDescription">Tagline / description</label>
                <textarea id="restaurantDescription" rows="3" placeholder="e.g. Serving handcrafted pizzas, delicious pastas..."></textarea>
                <div class="st-inline">
                  <span class="st-help">Shown under your name on the website.</span>
                  <button type="button" id="descFormatToggleSettings" class="desc-format-btn st-chipbtn" title="How new lines show on the website">
                    <span class="material-symbols-rounded">format_align_left</span>
                    <span id="descFormatLabelSettings">Paragraph</span>
                  </button>
                </div>
                <input type="hidden" id="descriptionFormatSettings" name="description_format" value="paragraph">
                <p class="st-help">Paragraph shows it as one block. Line Break keeps your new lines.</p>
              </div>
            </div>
            <div class="st-card" data-st-form="restaurant">
              <div class="st-card-h"><b>Contact</b><small>Customers see these on your website and bills.</small></div>
              <div class="st-grid2">
                <div class="st-field">
                  <label for="restaurantPhone">Phone number</label>
                  <input type="tel" id="restaurantPhone" placeholder="Enter phone number">
                </div>
                <div class="st-field">
                  <label for="restaurantEmail">Email address</label>
                  <input type="email" id="restaurantEmail" placeholder="Enter email address">
                </div>
              </div>
            </div>
            <div class="st-card st-card-muted">
              <div class="st-field st-id">
                <label for="restaurantIdSetting">Restaurant ID</label>
                <div class="st-copy"><input type="text" id="restaurantIdSetting" placeholder="Restaurant ID" readonly><button type="button" class="sx-icon-btn" data-st-copy="restaurantIdSetting" title="Copy"><span class="material-symbols-rounded">content_copy</span></button></div>
                <p class="st-help">Your account number. Support may ask for it.</p>
              </div>
            </div>
          </section>

          <!-- ── Location ── -->
          <section class="st-pane" data-st-pane="location">
            <header class="st-pane-h"><span class="material-symbols-rounded">location_on</span><div><h2>Location</h2><p>Used for directions on your website and to measure delivery distance.</p></div></header>
            <div class="st-card" data-st-form="restaurant">
              <div class="st-field">
                <label for="restaurantAddress">Address</label>
                <input type="text" id="restaurantAddress" autocomplete="off" placeholder="Search your restaurant's address...">
                <button type="button" id="restaurantMapPickerBtn" class="btn btn-secondary st-mapbtn"><span class="material-symbols-rounded">pin_drop</span>Select exact location on map</button>
                <div id="restaurantMapPreview" class="st-map" style="display:none;"></div>
                <input type="hidden" id="restaurantAddressLat" value="">
                <input type="hidden" id="restaurantAddressLng" value="">
                <p class="st-help">Search your address, or drop a pin on the map at your exact spot. A pin makes the delivery radius accurate.</p>
              </div>
            </div>
            <div class="st-card" data-st-form="restaurant">
              <div class="st-field">
                <label for="restaurantGoogleMapsLink">Google Maps link <em>Optional</em></label>
                <input type="url" id="restaurantGoogleMapsLink" placeholder="e.g. https://maps.app.goo.gl/...">
                <p class="st-help">Paste your Google Maps link (a full link, a share link like share.google or maps.app.goo.gl, or an embed link). The "Open in Google Maps" button on your website then uses it. Leave empty to use your address.</p>
              </div>
            </div>
          </section>

          <!-- ── Business hours ── -->
          <section class="st-pane" data-st-pane="hours">
            <header class="st-pane-h"><span class="material-symbols-rounded">schedule</span><div><h2>Business hours</h2><p>Customers can order only while you are open.</p></div>
              <button type="button" class="btn btn-secondary btn-sm btn-normal-hours" onclick="fillNormalHours(); window.stMarkDirty && stMarkDirty('restaurant')" title="Set all days to 9:00 AM - 10:00 PM"><span class="material-symbols-rounded">auto_schedule</span>9 AM – 10 PM every day</button>
            </header>
            <div class="st-card st-hours" data-st-form="restaurant">
              <?php
              $days = ['monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday', 'thursday' => 'Thursday', 'friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday'];
              $hours = ['01','02','03','04','05','06','07','08','09','10','11','12'];
              $minutes = ['00','05','10','15','20','25','30','35','40','45','50','55','59']; // 59: "open till 11:59 PM"
              foreach ($days as $key => $label):
              ?>
              <div class="st-day" data-day="<?= $key ?>">
                <label class="st-day-name">
                  <span class="st-switch"><input type="checkbox" class="day-toggle" id="day_<?= $key ?>_open" data-day="<?= $key ?>"><i></i></span>
                  <b><?= $label ?></b>
                </label>
                <div class="day-time-picker st-times">
                  <span class="st-time">
                    <select class="hour-select" id="opening_hour_<?= $key ?>" data-day="<?= $key ?>" data-type="opening_hour" aria-label="<?= $label ?> opening hour">
                      <?php foreach ($hours as $h): ?><option value="<?= $h ?>"><?= $h ?></option><?php endforeach; ?>
                    </select><span class="time-sep">:</span><select class="min-select" id="opening_min_<?= $key ?>" data-day="<?= $key ?>" data-type="opening_min" aria-label="<?= $label ?> opening minute">
                      <?php foreach ($minutes as $m): ?><option value="<?= $m ?>"><?= $m ?></option><?php endforeach; ?>
                    </select><select class="ampm-select" id="opening_ampm_<?= $key ?>" data-day="<?= $key ?>" data-type="opening_ampm" aria-label="<?= $label ?> opening AM or PM">
                      <option value="AM">AM</option>
                      <option value="PM">PM</option>
                    </select>
                  </span>
                  <span class="to-sep">to</span>
                  <span class="st-time">
                    <select class="hour-select" id="closing_hour_<?= $key ?>" data-day="<?= $key ?>" data-type="closing_hour" aria-label="<?= $label ?> closing hour">
                      <?php foreach ($hours as $h): ?><option value="<?= $h ?>"><?= $h ?></option><?php endforeach; ?>
                    </select><span class="time-sep">:</span><select class="min-select" id="closing_min_<?= $key ?>" data-day="<?= $key ?>" data-type="closing_min" aria-label="<?= $label ?> closing minute">
                      <?php foreach ($minutes as $m): ?><option value="<?= $m ?>"><?= $m ?></option><?php endforeach; ?>
                    </select><select class="ampm-select" id="closing_ampm_<?= $key ?>" data-day="<?= $key ?>" data-type="closing_ampm" aria-label="<?= $label ?> closing AM or PM">
                      <option value="AM">AM</option>
                      <option value="PM">PM</option>
                    </select>
                  </span>
                </div>
                <span class="st-closed">Closed</span>
              </div>
              <?php endforeach; ?>
            </div>
          </section>

          <!-- ── Ordering ── -->
          <section class="st-pane" data-st-pane="ordering">
            <header class="st-pane-h"><span class="material-symbols-rounded">shopping_bag</span><div><h2>Ordering</h2><p>What customers can do on your website.</p></div></header>
            <div class="st-card" data-st-form="restaurant">
              <div class="st-card-h"><b>Order types</b><small>Switched-off types are hidden on your website. Keep at least one on so customers can order.</small></div>
              <div class="st-types">
                <label class="st-type"><input type="checkbox" id="enableDeliveryToggle" <?php echo $enable_delivery ? 'checked' : ''; ?>><span class="material-symbols-rounded">local_shipping</span><b>Delivery</b><small>To the customer's door</small><i class="st-tick material-symbols-rounded">check_circle</i></label>
                <label class="st-type"><input type="checkbox" id="enableTakeawayToggle" <?php echo $enable_takeaway ? 'checked' : ''; ?>><span class="material-symbols-rounded">takeout_dining</span><b>Takeaway</b><small>Customer picks it up</small><i class="st-tick material-symbols-rounded">check_circle</i></label>
                <label class="st-type"><input type="checkbox" id="enableDineinToggle" <?php echo $enable_dinein ? 'checked' : ''; ?>><span class="material-symbols-rounded">restaurant</span><b>Dine in</b><small>Ordered at the table</small><i class="st-tick material-symbols-rounded">check_circle</i></label>
              </div>
            </div>
            <div class="st-card" data-st-form="restaurant">
              <label class="st-toggle">
                <span><b>Table reservations</b><small>Shows a "Reserve" section on your website where visitors book a table for a date and time. Bookings land on the Reservations page for you to confirm.</small></span>
                <span class="st-switch"><input type="checkbox" id="enableReservationsToggle"><i></i></span>
              </label>
            </div>
            <div class="st-card" data-st-form="restaurant">
              <div class="st-field st-narrow">
                <label for="minimumOrderValue">Minimum order value (<?php echo htmlspecialchars($currency_symbol); ?>)</label>
                <input type="number" id="minimumOrderValue" min="0" step="0.01" placeholder="e.g. 350">
                <p class="st-help">Orders below this amount can't be placed.</p>
              </div>
            </div>
          </section>

          <!-- ── Delivery ── -->
          <section class="st-pane" data-st-pane="delivery">
            <header class="st-pane-h"><span class="material-symbols-rounded">local_shipping</span><div><h2>Delivery</h2><p>How far you deliver and what it costs.</p></div></header>
            <div class="st-card" data-st-form="restaurant">
              <div class="st-grid2">
                <div class="st-field">
                  <label for="deliveryRadius">Delivery radius (km)</label>
                  <input type="number" id="deliveryRadius" class="form-control" step="0.5" min="0" placeholder="e.g. 10">
                  <p class="st-help">Furthest distance you deliver to, measured from your map pin. 0 turns the check off.</p>
                </div>
                <div class="st-field">
                  <label for="packagingCharge">Packaging charge (<?php echo htmlspecialchars($currency_symbol); ?>)</label>
                  <input type="number" id="packagingCharge" class="form-control" step="0.50" min="0" placeholder="e.g. 10.00">
                  <p class="st-help">A flat fee added to every delivery order. 0 turns it off.</p>
                </div>
              </div>
            </div>
            <div class="st-card">
              <label class="st-toggle">
                <span><b>We deliver with our own riders</b><small>Turn on if your own staff deliver the orders. You get Delivery › Riders, the staff role "Delivery rider", and "Assign rider" on delivery orders; riders use the RestroGrow Staff app and the customer can follow them live. Leave off if you use Swiggy, Zomato, Dunzo or other outside riders (the QR link for outside riders always works). Saved straight away.</small></span>
                <span class="st-switch"><input type="checkbox" id="ownRidersToggle"<?php echo $ownRiders ? ' checked' : ''; ?>><i></i></span>
              </label>
            </div>
            <div class="st-card" data-st-form="restaurant">
              <label class="st-toggle">
                <span><b>Charge delivery by distance</b><small>Delivery charge = distance (km) × your rate, instead of a flat charge. Only for customers inside your radius. You still confirm the amount when you accept each order.</small></span>
                <span class="st-switch"><input type="checkbox" id="enableKmDeliveryToggle"><i></i></span>
              </label>
              <span class="st-sr" id="enableKmDeliveryLabel">Disabled</span>
              <div id="deliveryRatePerKmGroup" class="st-field st-narrow st-sub" style="display:none;">
                <label for="deliveryRatePerKm">Rate per km (<?php echo htmlspecialchars($currency_symbol); ?>)</label>
                <input type="number" id="deliveryRatePerKm" class="form-control" step="0.5" min="0" placeholder="e.g. 5">
              </div>
            </div>
          </section>

          <!-- ── Payments ── -->
          <section class="st-pane" data-st-pane="payments">
            <header class="st-pane-h"><span class="material-symbols-rounded">payments</span><div><h2>Payments</h2><p>How customers can pay on your website.</p></div></header>
            <div class="st-card" data-st-form="restaurant">
              <label class="st-toggle">
                <span><b>Cash on delivery / pay at counter</b><small>Turn off to offer only online payment (UPI / QR) at checkout. If no online payment is set up, cash stays on so customers can still order.</small></span>
                <span class="st-switch"><input type="checkbox" id="enableCodToggle" <?php echo $cod_enabled ? "checked" : ""; ?>><i></i></span>
              </label>
              <span class="st-sr" id="enableCodLabel"><?php echo $cod_enabled ? 'Enabled' : 'Disabled'; ?></span>
            </div>
            <div class="st-card">
              <div class="st-card-h"><b>Business payment QR code</b><small>Your UPI / Paytm QR, shown on your website. JPG, PNG, GIF or WEBP, up to 5 MB. Uploads straight away.</small></div>
              <div class="st-qr">
                <div id="businessQRPreview" class="st-qr-preview" style="display:none;">
                  <img id="businessQRPreviewImg" src="" alt="Your payment QR code">
                  <button type="button" class="btn btn-secondary btn-sm sx-danger-ghost" onclick="removeBusinessQR()"><span class="material-symbols-rounded">delete</span>Remove QR code</button>
                </div>
                <div class="st-qr-up">
                  <label class="st-file"><span class="material-symbols-rounded">qr_code_2</span><span>Choose an image</span><input type="file" id="businessQRUpload" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp"></label>
                  <button type="button" class="btn btn-primary" id="uploadBusinessQRBtn"><span class="material-symbols-rounded">upload</span>Upload QR Code</button>
                </div>
              </div>
            </div>
          </section>

          <!-- ── Tax and bill ── -->
          <section class="st-pane" data-st-pane="tax">
            <header class="st-pane-h"><span class="material-symbols-rounded">receipt_long</span><div><h2>Tax and bill</h2><p>Tax on orders and what prints on the customer bill.</p></div></header>
            <div class="st-card" data-st-form="restaurant">
              <label class="st-toggle">
                <span><b>Charge tax</b><small>Turn off to remove tax from orders and your website.</small></span>
                <span class="st-switch"><input type="checkbox" id="enableGstToggle" <?php echo $enable_gst ? "checked" : ""; ?>><i></i></span>
              </label>
              <span class="st-sr" id="enableGstLabel">Enabled</span>
              <div class="st-grid2 st-sub" data-st-when="enableGstToggle">
                <div class="st-field">
                  <label for="taxName">Tax name</label>
                  <input type="text" id="taxName" class="form-control" maxlength="50" placeholder="e.g. GST, VAT, Sales Tax" value="<?php echo htmlspecialchars($tax_name); ?>">
                </div>
                <div class="st-field">
                  <label for="taxPercentInput">Tax percent (%)</label>
                  <input type="number" id="taxPercentInput" class="form-control" step="0.01" min="0" max="100" placeholder="e.g. 5" value="<?php echo htmlspecialchars(rtrim(rtrim(number_format((float)$tax_percent, 2), '0'), '.')); ?>">
                </div>
              </div>
            </div>
            <div class="st-card" data-st-form="restaurant">
              <label class="st-toggle">
                <span><b>Show business ID on the bill</b><small>Prints your registration number on the customer bill, e.g. GSTIN in India or PAN No in Nepal. Name the label the way your country does.</small></span>
                <span class="st-switch"><input type="checkbox" id="showBusinessIdToggle" <?php echo $show_business_id ? "checked" : ""; ?>><i></i></span>
              </label>
              <span class="st-sr" id="showBusinessIdLabel"><?php echo $show_business_id ? 'Enabled' : 'Disabled'; ?></span>
              <div class="st-grid2 st-sub" data-st-when="showBusinessIdToggle">
                <div class="st-field">
                  <label for="businessIdLabelInput">Label</label>
                  <input type="text" id="businessIdLabelInput" class="form-control" maxlength="30" placeholder="e.g. GSTIN, PAN No, VAT Number" value="<?php echo htmlspecialchars($business_id_label); ?>">
                </div>
                <div class="st-field">
                  <label for="businessIdNoInput">Number</label>
                  <input type="text" id="businessIdNoInput" class="form-control" maxlength="20" placeholder="e.g. 22AAAAA0000A1Z5" value="<?php echo htmlspecialchars($business_id_no); ?>">
                </div>
              </div>
            </div>
          </section>

          <!-- ── Social links ── -->
          <section class="st-pane" data-st-pane="social">
            <header class="st-pane-h"><span class="material-symbols-rounded">share</span><div><h2>Social links</h2><p>Shown on your website. Leave a box empty to hide that one. WhatsApp uses your phone number.</p></div></header>
            <div class="st-card st-socials" data-st-form="restaurant">
              <div class="st-social"><span class="st-brand" style="--b:#E4405F">IG</span><div class="st-field"><label for="instagramLink">Instagram</label><input type="url" id="instagramLink" placeholder="https://instagram.com/yourpage"></div></div>
              <div class="st-social"><span class="st-brand" style="--b:#1877F2">f</span><div class="st-field"><label for="facebookLink">Facebook</label><input type="url" id="facebookLink" placeholder="https://facebook.com/yourpage"></div></div>
              <div class="st-social"><span class="st-brand" style="--b:#111827">X</span><div class="st-field"><label for="twitterLink">X (Twitter)</label><input type="url" id="twitterLink" placeholder="https://x.com/yourpage"></div></div>
              <div class="st-social"><span class="st-brand" style="--b:#FF0000"><span class="material-symbols-rounded">play_arrow</span></span><div class="st-field"><label for="youtubeLink">YouTube</label><input type="url" id="youtubeLink" placeholder="https://youtube.com/@yourchannel"></div></div>
              <div class="st-social"><span class="st-brand" style="--b:#0A66C2">in</span><div class="st-field"><label for="linkedinLink">LinkedIn</label><input type="url" id="linkedinLink" placeholder="https://linkedin.com/company/yourpage"></div></div>
            </div>
          </section>

          <!-- ── Region and language ── -->
          <section class="st-pane" data-st-pane="region">
            <header class="st-pane-h"><span class="material-symbols-rounded">public</span><div><h2>Region and language</h2><p>Money, time and language across your dashboard and website.</p></div></header>
            <div class="st-card" data-st-form="system">
              <div class="st-grid2">
                <div class="st-field">
                  <label for="countrySelect">Country</label>
                  <select id="countrySelect">
                    <?php foreach (getCountryData() as $iso2 => $c): ?>
                    <?php $cLocal = getCountryLocalDefaults($iso2); ?>
                    <option value="<?php echo htmlspecialchars($c['name']); ?>" data-currency="<?php echo htmlspecialchars($c['currency_symbol']); ?>" data-timezone="<?php echo htmlspecialchars($cLocal['timezone'] ?? ''); ?>" <?php echo $country === $c['name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <p class="st-help">Picks the usual currency and time zone for you. You can still change them.</p>
                </div>
                <div class="st-field">
                  <label for="currencySymbolSelect">Currency</label>
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
              </div>
              <div class="st-field">
                <label for="timezone">Timezone</label>
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
                <p class="st-help">Order times, reports and opening hours follow this clock.</p>
                <?php
                // restaurants that signed up before country defaults existed still sit on India's clock
                $cInfo = getCountryByName((string)$country);
                $cTz = $cInfo ? (getCountryLocalDefaults($cInfo['iso2'])['timezone'] ?? '') : '';
                if ($cTz && $cTz !== 'Asia/Kolkata' && ($timezone ?: 'Asia/Kolkata') === 'Asia/Kolkata'):
                ?>
                <p class="st-help" style="color:#b45309;font-weight:600">Your country is <?php echo htmlspecialchars($cInfo['name']); ?> but this clock is India's. Pick <?php echo htmlspecialchars($cTz); ?> and save, so order times and reports match your local time.</p>
                <?php endif; ?>
              </div>
            </div>
            <div class="st-card" data-st-form="restaurant">
              <label class="st-toggle">
                <span><b>Language support</b><small>Lets you pick another language below and auto-translates your menu items.</small></span>
                <span class="st-switch"><input type="checkbox" id="enableLanguageToggle" <?php echo $enable_language ? "checked" : ""; ?>><i></i></span>
              </label>
              <span class="st-sr" id="enableLanguageLabel"><?php echo $enable_language ? 'Enabled' : 'Disabled'; ?></span>
            </div>
            <?php if ($enable_language): ?>
            <div class="st-card" data-st-form="system">
              <div class="st-field st-narrow">
                <label for="language">Language</label>
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
                <p class="st-help">Picking a language other than English auto-translates your menu items.</p>
              </div>
            </div>
            <?php endif; ?>
          </section>

          <!-- ── Printer ── -->
          <section class="st-pane" data-st-pane="printer">
            <header class="st-pane-h"><span class="material-symbols-rounded">print</span><div><h2>Printer</h2><p>Your thermal printer for KOTs, bills and invoices. Saved on this device.</p></div>
              <button type="button" class="btn btn-secondary btn-sm" onclick="testPrintReceipt()"><span class="material-symbols-rounded">print</span>Test print</button>
            </header>
            <div class="st-card" data-st-form="printer">
              <div class="st-field">
                <label>Paper width</label>
                <select id="printerWidthSelect" class="st-sr-select" aria-label="Paper width">
                  <option value="58">58mm (small thermal printer)</option>
                  <option value="80">80mm (standard thermal printer)</option>
                </select>
                <div class="st-choice" data-st-mirror="printerWidthSelect">
                  <button type="button" data-v="58"><b>58 mm</b><small>Small thermal printer</small></button>
                  <button type="button" data-v="80"><b>80 mm</b><small>Standard thermal printer</small></button>
                </div>
                <p class="st-help">Check the roll width on the box, or measure the roll. It must match your printer, or receipts print cut off.</p>
              </div>
              <div class="st-field">
                <label>Connection</label>
                <select id="printerModeSelect" class="st-sr-select" aria-label="Connection mode">
                  <option value="browser">Browser / System Printer (recommended)</option>
                  <option value="network">Network Printer (ESC/POS, direct - no print dialog)</option>
                  <option value="bluetooth">Bluetooth Printer (direct - no print dialog)</option>
                  <option value="serial">USB Printer (direct - no print dialog)</option>
                </select>
                <div class="st-choice" data-st-mirror="printerModeSelect">
                  <button type="button" data-v="browser"><b>Browser / system printer</b><small>Any printer with a driver (USB, Bluetooth or network). Uses the print dialog.</small></button>
                  <button type="button" data-v="bluetooth"><b>Bluetooth printer</b><small>Connect once from here; bills and KOTs then print straight away. Chrome / Edge on Android, Windows, Mac.</small></button>
                  <button type="button" data-v="serial"><b>USB printer</b><small>Plugged in by USB, or a Bluetooth printer paired in Windows (COM port). Chrome / Edge on a computer.</small></button>
                  <button type="button" data-v="network"><b>Network printer</b><small>Prints straight to a Wi-Fi / LAN thermal printer by IP. No driver, no dialog.</small></button>
                </div>
              </div>
              <div id="printerDeviceFields" class="st-sub" style="display:none;">
                <div class="st-printer-dev">
                  <span class="st-printer-ic"><span class="material-symbols-rounded" id="printerDevIcon">bluetooth</span></span>
                  <div class="st-printer-txt"><b id="printerDevName">No printer connected yet</b><small id="printerDevState"></small></div>
                  <div class="st-printer-acts">
                    <button type="button" class="btn btn-primary btn-sm" id="printerConnectBtn" onclick="connectPrinterFromSettings()"><span class="material-symbols-rounded">link</span>Connect printer</button>
                    <button type="button" class="btn btn-secondary btn-sm" id="printerNextWayBtn" onclick="tryNextPrinterWay()" style="display:none;" title="The test went out but nothing printed? Send it another way."><span class="material-symbols-rounded">sync</span>Try another way</button>
                    <button type="button" class="btn btn-secondary btn-sm" id="printerForgetBtn" onclick="forgetPrinterFromSettings()" style="display:none;">Forget</button>
                  </div>
                </div>
                <div class="st-field" id="printerBaudField" style="margin-top:14px;">
                  <label for="printerSerialBaud">Speed (baud rate)</label>
                  <select id="printerSerialBaud">
                    <option value="9600">9600 (most printers)</option>
                    <option value="19200">19200</option>
                    <option value="38400">38400</option>
                    <option value="115200">115200</option>
                  </select>
                  <p class="st-help">Only matters for USB. If the receipt comes out as random symbols, try 115200. Bluetooth printers paired in Windows ignore this.</p>
                </div>
                <p class="st-help">The printer is remembered on this device and reconnects by itself when you open the dashboard. The POS shows a printer button beside "Point of Sale": green means ready, tap it if it's off.</p>
                <details class="st-details st-printer-log" id="printerLogBox">
                  <summary><span class="material-symbols-rounded">description</span>Printer log<span class="material-symbols-rounded st-chev">expand_more</span></summary>
                  <pre id="printerLog">Nothing yet. Connect the printer and tap Test print.</pre>
                  <button type="button" class="btn btn-secondary btn-sm" onclick="copyPrinterLog(this)"><span class="material-symbols-rounded">content_copy</span>Copy log</button>
                </details>
              </div>
              <div id="printerNetworkFields" style="display:none;">
                <div class="st-grid2 st-sub">
                  <div class="st-field">
                    <label for="printerNetworkIp">Printer IP address</label>
                    <input type="text" id="printerNetworkIp" placeholder="e.g. 192.168.1.50">
                  </div>
                  <div class="st-field">
                    <label for="printerNetworkPort">Port</label>
                    <input type="number" id="printerNetworkPort" value="9100" min="1" max="65535">
                    <p class="st-help">9100 is the standard port for almost all network thermal printers.</p>
                  </div>
                </div>
              </div>
            </div>
            <details class="st-card st-details">
              <summary><span class="material-symbols-rounded">science</span>Don't have a printer yet?<span class="material-symbols-rounded st-chev">expand_more</span></summary>
              <p class="st-help">Run the bundled virtual printer to test the full print flow (including Network mode) with no hardware: open a terminal in <code>main/tools</code> and run <code>php virtual_printer_server.php</code>, then choose Network printer with address <code>127.0.0.1</code> and port <code>9100</code>. Every print job shows up in that terminal exactly as a real printer would receive it.</p>
            </details>
          </section>

          <!-- ── Account ── -->
          <section class="st-pane" data-st-pane="account">
            <header class="st-pane-h"><span class="material-symbols-rounded">account_circle</span><div><h2>Account</h2><p>Your login details and this device.</p></div></header>
            <div class="st-card" data-st-form="profile">
              <div class="st-card-h"><b>Login details</b></div>
              <div class="st-grid2">
                <div class="st-field">
                  <label for="usernameSetting">Username</label>
                  <input type="text" id="usernameSetting" placeholder="Enter username" required>
                </div>
                <div class="st-field">
                  <label for="profileEmailSetting">Email address</label>
                  <input type="email" id="profileEmailSetting" placeholder="Enter email address" required>
                </div>
              </div>
              <!-- "Email notifications" and "Auto sync" only saved a browser flag nothing reads: hidden, kept for the save code -->
              <label class="st-toggle st-toggle-flat" style="display:none">
                <span><b>Email notifications</b></span>
                <span class="st-switch"><input type="checkbox" id="emailNotifications"><i></i></span>
              </label>
            </div>
            <div class="st-card" data-st-form="system">
              <div class="st-card-h"><b>This device</b></div>
              <label class="st-toggle st-toggle-flat" style="display:none">
                <span><b>Auto sync</b></span>
                <span class="st-switch"><input type="checkbox" id="autoSync"><i></i></span>
              </label>
              <label class="st-toggle st-toggle-flat">
                <span><b>Push notifications</b></span>
                <span class="st-switch"><input type="checkbox" id="notifications"><i></i></span>
              </label>
            </div>
            <div class="st-card st-install install-app-group">
              <span class="st-install-ic material-symbols-rounded">install_mobile</span>
              <div><b>Install the app</b><small>Add this dashboard to your phone or computer for one-tap access.</small></div>
              <button type="button" class="btn btn-secondary" onclick="promptInstall()"><span class="material-symbols-rounded">download</span>Install App</button>
            </div>
          </section>
          <section class="st-pane" data-st-pane="navstyle">
            <header class="st-pane-h"><span class="material-symbols-rounded">space_dashboard</span><div><h2>Navigation style</h2><p>How you move between pages on this device.</p></div></header>
            <div class="st-card">
              <div class="st-card-h"><b>Pick a style</b></div>
              <div class="rgnav-pick" role="radiogroup" aria-label="Navigation style">
                <label><input type="radio" name="rgNavStyle" value="sidebar"><span class="pv"><span class="pv-side"></span><span class="pv-lines"></span></span><b>Sidebar</b><em>The usual menu on the left. Default.</em></label>
                <label><input type="radio" name="rgNavStyle" value="dock"><span class="pv"><span class="pv-lines full"></span><span class="pv-dock"><i style="background:#2563eb"></i><i style="background:#0ea5e9"></i><i style="background:#f97316"></i><i style="background:#ef4444"></i><i style="background:#10b981"></i><i style="background:#8b5cf6"></i><i style="background:#16a34a"></i></span></span><b>Bottom dock</b><em>Colourful icons at the bottom, like a Mac. Sections open as folders.</em></label>
                <label><input type="radio" name="rgNavStyle" value="radial"><span class="pv"><span class="pv-lines full"></span><span class="pv-fab"></span><i style="right:36px;bottom:10px;background:#2563eb"></i><i style="right:32px;bottom:26px;background:#f97316"></i><i style="right:22px;bottom:38px;background:#10b981"></i><i style="right:8px;bottom:40px;background:#8b5cf6"></i></span><b>Round menu</b><em>One round button in the corner. Tap it and the sections open around it.</em></label>
              </div>
              <p class="rgnav-note">Changes straight away and is remembered on this device. Every page works the same in all three.</p>
            </div>
          </section>

          <!-- one save bar for every section -->
          <div class="st-savebar" id="stSavebar" hidden>
            <span class="st-savebar-msg"><span class="material-symbols-rounded">edit_note</span><span id="stSaveMsg">You have unsaved changes</span></span>
            <button type="button" class="btn btn-secondary" id="stDiscard">Discard</button>
            <button type="button" class="btn btn-primary" id="stSave"><span class="material-symbols-rounded">save</span>Save changes</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Profile Page -->
    <div id="profilePage" class="page sx lg">
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
              <button class="btn btn-primary" id="editProfileBtn" onclick="showPage('settingsPage'); window.openSettingsSection && openSettingsSection('account')">
                <span class="material-symbols-rounded">edit</span>
                Edit Profile
              </button>
              <button class="btn btn-secondary" type="button" onclick="showPage('settingsPage'); window.openSettingsSection && openSettingsSection('account')">
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
    <div id="areaPage" class="page sx">
      <style>
        #areaPage .ar-hero { display: flex; flex-wrap: wrap; align-items: center; gap: 18px; padding: 20px; margin-bottom: 16px; border-radius: 20px; color: #fff; background: radial-gradient(90% 140% at 100% 0%, #7c3aed, transparent 55%), radial-gradient(60% 120% at 0% 100%, #0ea5e9, transparent 60%), #111827; box-shadow: 0 18px 40px -26px rgba(17,24,39,.9); }
        .ar-hero-l { flex: 1; min-width: 220px; }
        .ar-hero h2 { margin: 6px 0 4px; font-size: 1.5rem; }
        .ar-hero p { margin: 0; color: rgba(255,255,255,.75); font-size: .9rem; }
        .ar-live { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; background: rgba(34,197,94,.18); color: #86efac; font-size: .72rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
        .ar-live i { width: 7px; height: 7px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 0 rgba(34,197,94,.7); animation: arPulse 1.8s infinite; }
        @keyframes arPulse { 70% { box-shadow: 0 0 0 8px rgba(34,197,94,0); } 100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); } }
        .ar-ring { width: 82px; height: 82px; flex-shrink: 0; display: grid; place-items: center; border-radius: 50%; background: conic-gradient(#f472b6 calc(var(--p) * 1%), rgba(255,255,255,.14) 0); animation: arRing .9s ease-out; }
        .ar-ring > div { width: 66px; height: 66px; display: grid; place-items: center; align-content: center; border-radius: 50%; background: #111827; text-align: center; }
        .ar-ring b { font-size: 1.1rem; } .ar-ring span { font-size: .62rem; color: rgba(255,255,255,.65); }
        @keyframes arRing { from { transform: rotate(-90deg) scale(.85); opacity: 0; } }
        .ar-kpis { display: flex; flex-wrap: wrap; gap: 10px; }
        .ar-kpis div { min-width: 92px; padding: 10px 12px; border-radius: 14px; background: rgba(255,255,255,.09); text-align: center; }
        .ar-kpis b { display: block; font-size: 1.25rem; } .ar-kpis span { font-size: .7rem; color: rgba(255,255,255,.65); }
        .ar-warn { display: flex; gap: 8px; align-items: center; margin-bottom: 14px; padding: 10px 14px; border-radius: 12px; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; font-size: .85rem; }
        .ar-bar-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 12px; }
        .ar-legend { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; font-size: .8rem; font-weight: 700; color: #374151; }
        .ar-legend span::before { content: ''; display: inline-block; width: 10px; height: 10px; margin-right: 6px; border-radius: 3px; vertical-align: -1px; }
        .ar-legend .f::before { background: #22c55e; } .ar-legend .b::before { background: #ef4444; } .ar-legend .r::before { background: #f59e0b; }
        .ar-legend small { font-weight: 500; color: #9ca3af; }
        .ar-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 14px; align-items: start; }
        .ar-card { position: relative; overflow: hidden; border: 1px solid #e5e7eb; border-radius: 18px; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.04); transition: box-shadow .2s, transform .2s, border-color .2s; animation: arIn .45s cubic-bezier(.2,.9,.3,1.1) both; }
        .ar-card::before { content: ''; position: absolute; inset: 0 0 auto; height: 4px; background: var(--c); }
        .ar-card:hover { box-shadow: 0 16px 30px -22px rgba(17,24,39,.55); transform: translateY(-2px); }
        .ar-card.drag { opacity: .45; } .ar-card.over { border-color: var(--c); box-shadow: 0 0 0 3px color-mix(in srgb, var(--c) 25%, transparent); }
        @keyframes arIn { from { opacity: 0; transform: translateY(10px); } }
        .ar-top { display: flex; align-items: center; gap: 10px; padding: 16px 14px 10px; }
        .ar-grip { color: #d1d5db; cursor: grab; font-size: 20px; margin-left: -4px; }
        .ar-ic { width: 44px; height: 44px; flex-shrink: 0; display: grid; place-items: center; border-radius: 13px; background: color-mix(in srgb, var(--c) 14%, #fff); color: var(--c); font-size: 24px; }
        .ar-name { flex: 1; min-width: 0; } .ar-name b { display: block; font-size: 1.02rem; color: #111827; } .ar-name small { font-size: .78rem; color: #6b7280; }
        .ar-icon-btn { width: 34px; height: 34px; display: grid; place-items: center; border: 0; border-radius: 10px; background: transparent; color: #9ca3af; cursor: pointer; transition: background .15s, color .15s; }
        .ar-icon-btn:hover { background: #f3f4f6; color: #111827; } .ar-icon-btn[data-ar-del]:hover { background: #fef2f2; color: #dc2626; }
        .ar-icon-btn .material-symbols-rounded { font-size: 19px; }
        .ar-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; padding: 0 14px; }
        .ar-stats div { padding: 8px 6px; border-radius: 10px; background: #f9fafb; text-align: center; }
        .ar-stats b { display: block; font-size: .98rem; color: #111827; } .ar-stats span { font-size: .66rem; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: .03em; }
        .ar-bar { display: flex; height: 6px; margin: 12px 14px 0; border-radius: 99px; background: #f3f4f6; overflow: hidden; }
        .ar-bar i { display: block; height: 100%; transition: width .8s cubic-bezier(.2,.8,.2,1); }
        .ar-bar .b { background: #ef4444; } .ar-bar .r { background: #f59e0b; } .ar-bar .f { background: #22c55e; }
        .ar-tables { display: grid; grid-template-columns: repeat(auto-fill, minmax(62px, 1fr)); gap: 8px; padding: 14px; }
        .ar-t { position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; height: 58px; border-radius: 12px; border: 1.5px solid #bbf7d0; background: #f0fdf4; color: #065f46; cursor: default; transition: transform .15s; }
        .ar-t:hover { transform: scale(1.05); }
        .ar-t b { font-size: .86rem; } .ar-t small { display: flex; align-items: center; gap: 2px; font-size: .68rem; opacity: .8; } .ar-t small .material-symbols-rounded { font-size: 13px; }
        .ar-t.busy { border-color: #fecaca; background: #fef2f2; color: #b91c1c; }
        .ar-t.busy::after { content: ''; position: absolute; top: 6px; right: 6px; width: 7px; height: 7px; border-radius: 50%; background: #ef4444; animation: arPulseR 1.6s infinite; }
        @keyframes arPulseR { 0% { box-shadow: 0 0 0 0 rgba(239,68,68,.6); } 70% { box-shadow: 0 0 0 6px rgba(239,68,68,0); } 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0); } }
        .ar-t.stale { border-color: #f59e0b; background: repeating-linear-gradient(135deg, #fef2f2 0 6px, #fff7ed 6px 12px); }
        .ar-stale { display: flex; gap: 8px; align-items: flex-start; margin: 12px 14px 0; padding: 9px 12px; border-radius: 12px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; font-size: .8rem; line-height: 1.4; }
        .ar-stale .material-symbols-rounded { font-size: 18px; }
        .ar-t.reserved { border-color: #fde68a; background: #fffbeb; color: #92400e; }
        .ar-t.off { border-color: #e5e7eb; background: #f9fafb; color: #9ca3af; }
        .ar-t.new { border-style: dashed; border-color: var(--c); background: color-mix(in srgb, var(--c) 7%, #fff); color: var(--c); animation: arIn .3s ease both; }
        .ar-none { display: flex; align-items: center; gap: 8px; margin: 14px; padding: 14px; border: 1.5px dashed #e5e7eb; border-radius: 12px; color: #9ca3af; font-size: .86rem; }
        .ar-foot { display: flex; flex-wrap: wrap; gap: 4px; padding: 10px 10px 12px; border-top: 1px solid #f3f4f6; }
        .ar-link { display: inline-flex; align-items: center; gap: 5px; padding: 7px 10px; border: 0; border-radius: 10px; background: transparent; color: #4b5563; font: inherit; font-size: .82rem; font-weight: 700; cursor: pointer; }
        .ar-link:hover { background: #f3f4f6; color: #111827; } .ar-link .material-symbols-rounded { font-size: 18px; } .ar-link[data-ar-add] { color: var(--c); }
        .ar-add { margin: 12px 14px 14px; padding: 14px; border-radius: 14px; background: color-mix(in srgb, var(--c) 6%, #fff); border: 1.5px solid color-mix(in srgb, var(--c) 30%, #fff); animation: arIn .3s ease both; }
        .ar-add-row { display: grid; grid-template-columns: 1.2fr 1fr 1fr; gap: 10px; }
        .ar-add label { display: flex; flex-direction: column; gap: 5px; font-size: .74rem; font-weight: 700; color: #6b7280; }
        #areaPage .ar-add input, #areaPage .ar-add select { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1.5px solid #e5e7eb; border-radius: 10px; font: inherit; font-size: .9rem; background: #fff; }
        .ar-step { display: flex; } .ar-step button { width: 34px; border: 1.5px solid #e5e7eb; background: #fff; font-size: 1.1rem; cursor: pointer; }
        .ar-step button:first-child { border-radius: 10px 0 0 10px; } .ar-step button:last-child { border-radius: 0 10px 10px 0; }
        #areaPage .ar-step input { border-radius: 0; border-left: 0; border-right: 0; text-align: center; -moz-appearance: textfield; }
        .ar-add-prev { margin-top: 10px; } .ar-add-prev > small { font-size: .76rem; color: #6b7280; } .ar-add-prev .ar-tables { padding: 8px 0 0; }
        .ar-add-acts, .ar-del-acts, .ar-sheet-acts { display: flex; justify-content: flex-end; gap: 8px; margin-top: 10px; }
        .ar-del { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-start; margin: 12px 14px 14px; padding: 14px; border-radius: 14px; background: #fef2f2; border: 1px solid #fecaca; animation: arIn .3s ease both; }
        .ar-del > .material-symbols-rounded { color: #dc2626; } .ar-del > div:nth-child(2) { flex: 1; min-width: 160px; }
        .ar-del b { display: block; color: #111827; } .ar-del small { display: block; margin: 4px 0; font-size: .8rem; color: #6b7280; }
        #areaPage .ar-del select { padding: 7px 10px; border: 1.5px solid #fecaca; border-radius: 10px; font: inherit; background: #fff; }
        .ar-del-acts { width: 100%; margin-top: 4px; }
        .ar-danger { background: #dc2626; color: #fff; border: 0; }
        .ar-empty { padding: 34px 20px; border-radius: 20px; border: 1.5px dashed #d1d5db; background: #fff; text-align: center; }
        .ar-empty-art { display: flex; justify-content: center; gap: 10px; margin-bottom: 12px; }
        .ar-empty-art span { width: 52px; height: 52px; display: grid; place-items: center; border-radius: 16px; font-size: 28px; color: #fff; animation: arIn .5s ease both; }
        .ar-empty-art span:nth-child(1) { background: #7c3aed; } .ar-empty-art span:nth-child(2) { background: #10b981; animation-delay: .08s; } .ar-empty-art span:nth-child(3) { background: #f97316; animation-delay: .16s; }
        .ar-empty h3 { margin: 0; font-size: 1.25rem; color: #111827; } .ar-empty p { max-width: 460px; margin: 6px auto 16px; color: #6b7280; font-size: .9rem; }
        .ar-ideas { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; }
        .ar-ideas button { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; border: 1.5px solid #e5e7eb; border-radius: 12px; background: #fff; font: inherit; font-size: .86rem; font-weight: 700; color: #374151; cursor: pointer; transition: all .15s; }
        .ar-ideas button .material-symbols-rounded { font-size: 19px; color: var(--c); }
        .ar-ideas button.on { border-color: var(--c); background: color-mix(in srgb, var(--c) 10%, #fff); color: #111827; }
        .ar-empty-acts { display: flex; justify-content: center; gap: 8px; margin-top: 16px; }
        .ar-modal { position: fixed; inset: 0; z-index: 1200; display: grid; place-items: center; padding: 16px; background: rgba(17,24,39,.55); backdrop-filter: blur(3px); animation: arFade .2s ease; }
        @keyframes arFade { from { opacity: 0; } }
        .ar-sheet { width: min(520px, 100%); max-height: calc(100vh - 32px); overflow: auto; border-radius: 22px; background: #fff; box-shadow: 0 30px 60px -20px rgba(0,0,0,.45); animation: arPop .28s cubic-bezier(.2,.9,.3,1.2); }
        @keyframes arPop { from { opacity: 0; transform: translateY(14px) scale(.97); } }
        .ar-prev { display: flex; align-items: center; gap: 12px; padding: 20px; color: #fff; background: radial-gradient(120% 140% at 100% 0%, rgba(255,255,255,.25), transparent 55%), var(--c); transition: background .3s; }
        .ar-prev .ar-ic { background: rgba(255,255,255,.2); color: #fff; } .ar-prev b { display: block; font-size: 1.15rem; } .ar-prev small { color: rgba(255,255,255,.85); font-size: .8rem; }
        .ar-form { padding: 16px 20px 4px; }
        .ar-lbl { display: flex; flex-direction: column; gap: 6px; margin: 12px 0 6px; font-size: .78rem; font-weight: 800; color: #6b7280; } .ar-lbl i { font-style: normal; font-weight: 500; color: #9ca3af; }
        #areaPage .ar-form input { padding: 10px 12px; border: 1.5px solid #e5e7eb; border-radius: 12px; font: inherit; font-size: .95rem; color: #111827; }
        #areaPage .ar-form input:focus { outline: 0; border-color: #7c3aed; box-shadow: 0 0 0 3px #ede9fe; }
        .ar-sugg { display: flex; flex-wrap: wrap; gap: 6px; }
        .ar-sugg button { padding: 5px 10px; border: 1px solid #e5e7eb; border-radius: 999px; background: #f9fafb; font: inherit; font-size: .78rem; font-weight: 700; color: #4b5563; cursor: pointer; }
        .ar-sugg button:hover { border-color: #c4b5fd; background: #faf5ff; color: #6d28d9; }
        .ar-icons { display: grid; grid-template-columns: repeat(8, 1fr); gap: 6px; }
        .ar-icons button { aspect-ratio: 1; display: grid; place-items: center; border: 1.5px solid #e5e7eb; border-radius: 12px; background: #fff; color: #6b7280; cursor: pointer; transition: all .15s; }
        .ar-icons button:hover { border-color: #c4b5fd; color: #6d28d9; } .ar-icons button.on { border-color: #7c3aed; background: #faf5ff; color: #6d28d9; box-shadow: 0 0 0 3px #ede9fe; }
        .ar-colors { display: flex; flex-wrap: wrap; gap: 8px; }
        .ar-colors button { width: 32px; height: 32px; border: 3px solid #fff; border-radius: 50%; box-shadow: 0 0 0 1.5px #e5e7eb; cursor: pointer; transition: transform .15s; }
        .ar-colors button:hover { transform: scale(1.1); } .ar-colors button.on { box-shadow: 0 0 0 2.5px #111827; }
        .ar-sheet-acts { padding: 6px 20px 20px; }
        .ar-loading { display: flex; justify-content: center; gap: 8px; padding: 50px; } .ar-loading span { width: 10px; height: 10px; border-radius: 50%; background: #c4b5fd; animation: arDot 1s infinite alternate; }
        .ar-loading span:nth-child(2) { animation-delay: .2s; } .ar-loading span:nth-child(3) { animation-delay: .4s; }
        @keyframes arDot { to { transform: translateY(-8px); background: #7c3aed; } }
        @media (max-width: 700px) { .ar-grid { grid-template-columns: 1fr; } .ar-add-row { grid-template-columns: 1fr 1fr; } .ar-icons { grid-template-columns: repeat(6, 1fr); } .ar-kpis div { min-width: 70px; } }
        @media (prefers-reduced-motion: reduce) { #areaPage * { animation: none !important; transition: none !important; } }
      </style>
      <div class="page-header">
        <h1>Areas</h1>
        <p>Your floor at a glance: every area, its tables live, and today’s sales.</p>
      </div>
      <div class="page-content"><div id="areasRoot"></div></div>
    </div>

    <!-- Tables Management Page -->
    <div id="tablesPage" class="page sx">
      <div class="page-header">
        <h1>Tables</h1>
        <p>Every table with its live status. Edit, switch off, print QR codes, or change many at once.</p>
      </div>
      <div class="page-content"><div id="tablesRoot"></div></div>
    </div>

    <!-- QR Codes Page -->
    <div id="qrCodesPage" class="page sx lg">
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
    <div id="galleryPage" class="page sx lg">
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
        <div class="search-wrapper" style="margin-top: 1rem; max-width: 400px; position: relative; border: 2px solid #e5e7eb; border-radius: 10px; padding: 0 0.75rem 0 2.75rem; background: white;">
          <span class="material-symbols-rounded" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 1.2rem; pointer-events: none; z-index: 1;">search</span>
          <input type="text" id="gallerySearchInput" placeholder="Search photos or categories..." oninput="filterGalleryItems(this.value)" style="width: 100%; padding: 0.875rem 0; border: none; border-radius: 0; font-size: 0.95rem; transition: all 0.2s; outline: none; background: transparent;" onfocus="this.parentElement.style.borderColor='#f70000'; this.parentElement.style.boxShadow='0 0 0 3px rgba(247,0,0,0.1)'" onblur="this.parentElement.style.borderColor='#e5e7eb'; this.parentElement.style.boxShadow='none';">
        </div>
      </div>
      <div class="page-content">
        <div id="galleryCategoryGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px;">
          <div class="loading">Loading photo gallery...</div>
        </div>
        <div id="galleryPhotoGrid" style="display:none; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px;"></div>
        <div id="galleryNoResults" class="empty-state" style="display:none;">
          <span class="material-symbols-rounded">search_off</span>
          <h3>No Matches</h3>
          <p>Nothing matches your search.</p>
        </div>
      </div>
    </div>

    <!-- Photo Gallery Lightbox -->
    <div id="galleryLightboxModal" class="modal">
      <div class="modal-content" style="max-width:700px;">
        <div class="modal-header">
          <h2 id="galleryLightboxTitle" style="margin:0;font-size:1rem;font-weight:600;"></h2>
          <button class="modal-close" onclick="closeModal('galleryLightboxModal')">&times;</button>
        </div>
        <div class="modal-body gallery-lightbox-body">
          <button type="button" class="gallery-lightbox-nav gallery-lightbox-prev" onclick="navigateGalleryLightbox(-1)" aria-label="Previous photo">
            <span class="material-symbols-rounded">chevron_left</span>
          </button>
          <img id="galleryLightboxImg" src="" alt="" class="gallery-lightbox-img">
          <button type="button" class="gallery-lightbox-nav gallery-lightbox-next" onclick="navigateGalleryLightbox(1)" aria-label="Next photo">
            <span class="material-symbols-rounded">chevron_right</span>
          </button>
        </div>
        <div class="gallery-lightbox-actions">
          <button type="button" class="btn-secondary" onclick="downloadGalleryImage()">
            <span class="material-symbols-rounded" style="font-size:18px;">download</span> Download
          </button>
          <button type="button" class="btn-secondary" onclick="openGalleryImageFullSize()">
            <span class="material-symbols-rounded" style="font-size:18px;">open_in_new</span> Open Full Size
          </button>
          <button type="button" class="btn-secondary" onclick="copyGalleryImage()">
            <span class="material-symbols-rounded" style="font-size:18px;">content_copy</span> Copy Image
          </button>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Table Map Page -->
    <div id="tableMapPage" class="page sx">
      <div class="page-header">
        <h1>Table map</h1>
        <p>Your floor live: who is seated, for how long, and what is reserved. Tap a table for details.</p>
      </div>
      <div class="page-content">
        <div class="tm-head"><div class="tm-legend"><span class="f">Free</span><span class="o">Busy</span><span class="r">Reserved</span><span class="x">Switched off</span></div>
          <div style="display:flex;align-items:center;gap:10px"><span class="tm-updated" id="tmUpdated"></span><button type="button" class="btn btn-secondary btn-sm" onclick="renderTableMap(true)"><span class="material-symbols-rounded">refresh</span> Refresh</button></div></div>
        <div id="tableMapContainer" class="tm-wrap" style="margin-top:14px"></div>
      </div>
    </div>

    <!-- Reservations Management Page -->
    <div id="reservationsPage" class="page sx lg">
      <!-- Reservations: day strip, timeline, one-tap actions (assets/js/reservations-studio.js, styles rv-*) -->
      <div class="page-header">
        <div>
          <h1>Reservations</h1>
          <p>Bookings by day: confirm, seat and finish them in one tap</p>
        </div>
        <div class="lg-head-acts"><button type="button" class="btn btn-primary" id="addReservationBtn"><span class="material-symbols-rounded">add</span>Add reservation</button></div>
      </div>
      <div class="page-content">
        <div class="menu-list lg-cards" id="reservationList">
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
              <input type="text" id="posSearchInput" class="filter-select" placeholder="Search items...">
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
              <div style="display:flex;gap:6px;align-items:center;">
                <button class="btn-clear-cart" id="heldOrdersBtn" type="button" onclick="openHeldOrders()" title="Orders put on hold — resume or discard">
                  <span class="material-symbols-rounded">pause_circle</span>
                  Held <span id="heldOrdersCount" style="display:none;min-width:18px;padding:0 5px;border-radius:999px;background:#f59e0b;color:#fff;font-size:11px;font-weight:700;line-height:18px;text-align:center;"></span>
                </button>
                <button class="btn-clear-cart" id="clearCartBtn">
                  <span class="material-symbols-rounded">delete</span>
                  Clear Cart
                </button>
              </div>
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
    <div id="kotPage" class="page sx lg">
      <div class="page-header">
        <div>
          <h1>Kitchen Order Ticket (KOT)</h1>
          <p>View and manage kitchen orders</p>
        </div>
        <div class="lg-head-acts">
          <span id="kotLastRefresh" class="lg-live">Updates every 5 seconds</span>
          <button type="button" class="btn btn-secondary lg-sound on" onclick="window.kotOrdersSoundEnabled = !window.kotOrdersSoundEnabled; this.classList.toggle('on', !!window.kotOrdersSoundEnabled); this.querySelector('.material-symbols-rounded').textContent = window.kotOrdersSoundEnabled ? 'notifications_active' : 'notifications_off'" title="Turn the KOT sound on or off"><span class="material-symbols-rounded">notifications_active</span></button>
          <button type="button" class="btn btn-secondary" onclick="loadKOTOrders()"><span class="material-symbols-rounded">refresh</span>Refresh</button>
        </div>
      </div>
      <div class="page-content">
        <div class="kot-filters sx-toolbar lg-toolbar">
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
    <div id="onlineOrdersPage" class="page sx lg">
      <div class="page-header">
        <div>
          <h1>Online Orders</h1>
          <p>Orders placed from the customer website</p>
        </div>
        <div class="lg-head-acts">
          <span id="onlineOrdersLastRefresh" class="lg-live">Updates every 15 seconds</span>
          <button type="button" class="btn btn-secondary lg-sound on" onclick="window.onlineOrdersSoundEnabled = !window.onlineOrdersSoundEnabled; this.classList.toggle('on', !!window.onlineOrdersSoundEnabled); this.querySelector('.material-symbols-rounded').textContent = window.onlineOrdersSoundEnabled ? 'notifications_active' : 'notifications_off'" title="Turn the new-order sound on or off"><span class="material-symbols-rounded">notifications_active</span></button>
          <button type="button" class="btn btn-secondary" onclick="loadOnlineOrders()"><span class="material-symbols-rounded">refresh</span>Refresh</button>
        </div>
      </div>
      <div class="page-content">
        <div class="orders-filters sx-toolbar lg-toolbar">
          <div class="sx-search"><span class="material-symbols-rounded">search</span><input type="text" id="onlineOrdersSearch" placeholder="Search by order number or customer"></div>
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
    <div id="ordersPage" class="page sx lg">
      <div class="page-header">
        <h1>Orders Management</h1>
        <p>View and manage all orders</p>
      </div>
      <div class="page-content">
        <div class="orders-filters sx-toolbar lg-toolbar">
          <div class="sx-search"><span class="material-symbols-rounded">search</span><input type="text" id="ordersSearch" placeholder="Search by order number, customer or table"></div>
          <select id="ordersStatusFilter" class="filter-select">
            <option value="">All Status</option>
            <option value="Scheduled">Scheduled</option>
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
          <input type="date" id="ordersDateFilter" class="filter-select" title="Order date">
          <div class="sx-toolbar-r"><button type="button" class="btn btn-secondary" onclick="exportOrdersToCSV()"><span class="material-symbols-rounded">download</span>Export CSV</button></div>
        </div>
        <div class="orders-list" id="ordersList">
          <!-- Orders will be loaded here -->
          <div class="loading">Loading orders...</div>
        </div>
      </div>
    </div>

    <!-- Customers Page -->
    <div id="customersPage" class="page sx lg">
      <div class="page-header">
        <h1>Customers</h1>
        <p>View and manage your customers</p>
      </div>
      <div class="page-content">
        <div class="sx-toolbar lg-toolbar">
          <div class="sx-search"><span class="material-symbols-rounded">search</span><input type="text" id="customerSearch" placeholder="Search by name, phone or email"></div>
          <select id="customerSortBy" class="filter-select">
              <option value="name">Sort by Name</option>
              <option value="visits">Most Visits</option>
              <option value="recent">Most Recent</option>
          </select>
          <div class="sx-toolbar-r">
            <button type="button" class="btn btn-secondary" onclick="exportCustomersToCSV()"><span class="material-symbols-rounded">download</span>Export CSV</button>
            <button type="button" class="btn btn-primary" id="addCustomerBtn"><span class="material-symbols-rounded">add</span>Add Customer</button>
          </div>
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
                <th>Points</th>
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
    <div id="staffPage" class="page sx lg">
      <div class="page-header">
        <h1>Staff Management</h1>
        <p>Manage your restaurant staff</p>
      </div>
      <div class="page-content">
        <div class="sx-toolbar lg-toolbar">
          <div class="sx-search"><span class="material-symbols-rounded">search</span><input type="text" id="staffSearch" placeholder="Search staff by name, email or phone"></div>
          <select id="staffSortBy" class="filter-select">
              <option value="name">Sort by Name</option>
              <option value="role">Sort by Role</option>
          </select>
          <div class="sx-toolbar-r">
            <button type="button" class="btn btn-secondary" onclick="showPage('activityPage')" title="Who did what: cancellations, discounts, price changes, logins"><span class="material-symbols-rounded">history</span>Activity log</button>
            <button type="button" class="btn btn-secondary" onclick="exportStaffToCSV()"><span class="material-symbols-rounded">download</span>Export CSV</button>
            <button type="button" class="btn btn-primary" id="addStaffBtn"><span class="material-symbols-rounded">add</span>Add Staff</button>
          </div>
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

    <!-- Activity log for the owner: who did what (assets/js/activity-log.js, api/activity_log.php, styles al-* in studio-ui.css) -->
    <div id="activityPage" class="page sx lg">
      <div class="page-header">
        <h1>Activity log</h1>
        <p>Who cancelled, deleted, discounted or changed what, and when</p>
      </div>
      <div class="page-content"><div id="alRoot"></div></div>
    </div>

    <!-- Waiter Requests Page -->
    <div id="waiterRequestsPage" class="page sx lg">
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

            <div class="form-group" style="grid-column:1/-1;">
              <label>Nutrition per serving <span style="font-weight:400;color:#6b7280;">(optional, grams)</span>:</label>
              <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;">
                <input type="number" id="protein_g" name="protein_g" min="0" step="0.1" placeholder="Protein" aria-label="Protein (g)">
                <input type="number" id="fat_g" name="fat_g" min="0" step="0.1" placeholder="Fat" aria-label="Fat (g)">
                <input type="number" id="carbs_g" name="carbs_g" min="0" step="0.1" placeholder="Carbs" aria-label="Carbs (g)">
                <input type="number" id="fibre_g" name="fibre_g" min="0" step="0.1" placeholder="Fibre" aria-label="Fibre (g)">
              </div>
              <input type="hidden" name="health_tags_sent" value="1">
              <details id="healthTagsDropdown" style="margin-top:10px;border:1.5px solid #e5e7eb;border-radius:10px;background:#fff;">
                <summary style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;cursor:pointer;font-size:0.9rem;font-weight:600;list-style:none;"><span>Health tags: <span id="healthTagsSummary" style="font-weight:500;color:#6b7280;">none</span></span><span class="material-symbols-rounded" style="font-size:20px;color:#6b7280;">expand_more</span></summary>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:2px 8px;padding:4px 8px 10px;border-top:1px solid #f1f2f4;">
                    <label style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:8px;cursor:pointer;font-size:0.88rem;"><input type="checkbox" name="health_tags[]" value="high_protein" class="health-tag-cb"> High protein</label>
                    <label style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:8px;cursor:pointer;font-size:0.88rem;"><input type="checkbox" name="health_tags[]" value="low_carb" class="health-tag-cb"> Low carb</label>
                    <label style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:8px;cursor:pointer;font-size:0.88rem;"><input type="checkbox" name="health_tags[]" value="low_fat" class="health-tag-cb"> Low fat</label>
                    <label style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:8px;cursor:pointer;font-size:0.88rem;"><input type="checkbox" name="health_tags[]" value="high_fibre" class="health-tag-cb"> High fibre</label>
                    <label style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:8px;cursor:pointer;font-size:0.88rem;"><input type="checkbox" name="health_tags[]" value="low_calorie" class="health-tag-cb"> Low calorie</label>
                    <label style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:8px;cursor:pointer;font-size:0.88rem;"><input type="checkbox" name="health_tags[]" value="low_sugar" class="health-tag-cb"> Low sugar</label>
                    <label style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:8px;cursor:pointer;font-size:0.88rem;"><input type="checkbox" name="health_tags[]" value="keto" class="health-tag-cb"> Keto friendly</label>
                    <label style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:8px;cursor:pointer;font-size:0.88rem;"><input type="checkbox" name="health_tags[]" value="vegan" class="health-tag-cb"> Vegan</label>
                    <label style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:8px;cursor:pointer;font-size:0.88rem;"><input type="checkbox" name="health_tags[]" value="gluten_free" class="health-tag-cb"> Gluten free</label>
                    <label style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:8px;cursor:pointer;font-size:0.88rem;"><input type="checkbox" name="health_tags[]" value="dairy_free" class="health-tag-cb"> Dairy free</label>
                    <label style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:8px;cursor:pointer;font-size:0.88rem;"><input type="checkbox" name="health_tags[]" value="jain" class="health-tag-cb"> Jain</label>
                </div>
              </details>
              <small style="display:block;margin-top:4px;color:#6b7280;font-size:0.8rem;">Shown with the calories as "Nutritional info" in the dish details. Leave blank to hide. Tick health tags to show them as labels (only the ones you tick).</small>
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
            <label for="ingredients">Ingredients <span style="font-weight:400;color:#6b7280;">(optional)</span>:</label>
            <textarea id="ingredients" name="ingredients" rows="4" maxlength="4000" placeholder="One per line, with an optional note after a dash:&#10;Paneer - excellent source of protein and calcium&#10;Spinach - rich in iron and fibre&#10;Ginger-garlic paste"></textarea>
            <small style="display:block;margin-top:4px;color:#6b7280;font-size:0.8rem;">One per line; add a short note after a dash and customers see it under <b>What's inside</b> in the dish details (a comma list still works).</small>
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
                  <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #374151;">Crop Image <span style="font-weight:400;color:#6b7280;">(same shape customers see on the menu)</span>:</label>
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
                    <div id="websitePreviewImage" style="width: 100%; aspect-ratio: 5 / 4; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden;">
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
              <option value="Cashier">Cashier</option>
              <option value="Delivery" id="memberRoleDelivery"<?php echo $ownRiders ? '' : ' hidden disabled'; ?>>Delivery rider</option>
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
        <?php
        // the prices come from Super Admin › Settings (the server charges these, whatever the page sends);
        // PhonePe works only for Indian restaurants, everyone else renews through support
        $rgPlanM = 999.0; $rgPlanY = 9990.0;
        try {
            require_once __DIR__ . '/../config/platform_engine.php';
            if (isset($conn) && $conn instanceof PDO) { $rgPs = pfSettings($conn); $rgPlanM = (float)$rgPs['plan_price']; $rgPlanY = (float)$rgPs['plan_price_yearly']; }
        } catch (Throwable $e) { error_log('renewal modal prices: ' . $e->getMessage()); }
        $rgSave = $rgPlanM > 0 ? (int)round((1 - $rgPlanY / ($rgPlanM * 12)) * 100) : 0;
        $rgIndia = restaurantIsIndia(html_entity_decode((string)($country ?? 'India')));
        $rgMoney = fn($v) => '₹' . number_format($v, floor($v) == $v ? 0 : 2);
        ?>
        <?php if (!$rgIndia): ?>
        <div style="text-align:center;padding:.5rem 0 1rem;">
          <p style="margin:0 0 1rem;color:#374151;font-size:.95rem;line-height:1.6;">To renew for a restaurant outside India, contact our support team. We'll share the price and how to pay in your country.</p>
          <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center;">
            <a href="https://wa.me/916377568749?text=<?php echo rawurlencode('Hi, I want to renew RestroGrow for ' . ($restaurant_name ?? 'my restaurant') . ' (' . ($restaurant_id ?? '') . ').'); ?>" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;padding:12px 18px;border-radius:12px;background:#16a34a;color:#fff;font-weight:700;text-decoration:none;"><span class="material-symbols-rounded" style="font-size:20px;">chat</span>WhatsApp support</a>
            <a href="mailto:support@restrogrow.com?subject=<?php echo rawurlencode('Renew RestroGrow – ' . ($restaurant_id ?? '')); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:12px 18px;border-radius:12px;background:#1e293b;color:#fff;font-weight:700;text-decoration:none;"><span class="material-symbols-rounded" style="font-size:20px;">mail</span>Email support</a>
          </div>
          <p style="margin:1rem 0 0;"><a href="#" onclick="event.preventDefault();closeSubscriptionModal();" style="color:#e17055;text-decoration:underline;font-size:.8rem;">Maybe later</a></p>
        </div>
        <?php else: ?>
        <!-- Plan Selection -->
        <div style="display:flex;gap:12px;margin-bottom:1.25rem;">
          <div id="planMonthly" class="plan-option active" onclick="selectPlan('monthly', <?php echo json_encode($rgPlanM); ?>)" style="flex:1;border:2px solid #e17055;border-radius:12px;padding:1rem;text-align:center;cursor:pointer;background:#fff5f0;transition:all 0.2s;">
            <div style="font-size:1.5rem;font-weight:800;color:#1e293b;"><?php echo $rgMoney($rgPlanM); ?></div>
            <div style="font-size:0.8rem;color:#6b7280;margin-top:4px;">per month</div>
            <div style="font-size:0.7rem;color:#e17055;margin-top:6px;font-weight:600;">✓ Most Popular</div>
          </div>
          <div id="planYearly" class="plan-option" onclick="selectPlan('yearly', <?php echo json_encode($rgPlanY); ?>)" style="flex:1;border:2px solid #e5e7eb;border-radius:12px;padding:1rem;text-align:center;cursor:pointer;background:#fff;transition:all 0.2s;">
            <div style="font-size:1.5rem;font-weight:800;color:#1e293b;"><?php echo $rgMoney($rgPlanY); ?></div>
            <div style="font-size:0.8rem;color:#6b7280;margin-top:4px;">per year</div>
            <div style="font-size:0.7rem;color:#10b981;margin-top:6px;font-weight:600;"><?php echo $rgSave > 0 ? '✓ Save ' . $rgSave . '%' : '✓ Pay once a year'; ?></div>
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

        <!-- Auto-pay toggle: hidden until recurring PhonePe payments are built (it only saved a flag nothing reads) -->
        <div style="display:none;align-items:center;gap:10px;margin-bottom:1.25rem;padding:10px 14px;background:#f9fafb;border-radius:10px;border:1px solid #e5e7eb;">
          <input type="checkbox" id="autoPayToggle" style="width:18px;height:18px;accent-color:#e17055;cursor:pointer;">
          <div style="flex:1;">
            <label for="autoPayToggle" style="font-weight:600;font-size:0.85rem;color:#374151;cursor:pointer;">Enable Auto-Pay</label>
            <p style="margin:2px 0 0;font-size:0.75rem;color:#9ca3af;">Automatically renew each month via UPI</p>
          </div>
        </div>

        <!-- Pay Button -->
        <button id="payNowBtn" onclick="initiateSubscriptionPayment()" style="width:100%;padding:14px;background:linear-gradient(135deg,#e17055,#d63031);color:#fff;border:none;border-radius:12px;font-weight:700;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all 0.2s;font-family:inherit;" onmouseover="this.style.transform='translateY(-1px)';this.style.boxShadow='0 4px 15px rgba(225,112,85,0.4)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
          <span class="material-symbols-rounded" style="font-size:20px;">lock</span>
          Pay ₹<span id="payNowAmount"><?php echo number_format($rgPlanM, floor($rgPlanM) == $rgPlanM ? 0 : 2); ?></span>
        </button>

        <!-- Payment status area -->
        <div id="subscriptionPaymentStatus" style="display:none;margin-top:1rem;padding:1rem;border-radius:10px;text-align:center;"></div>

        <p style="color:#9ca3af;font-size:0.75rem;text-align:center;margin-top:1rem;margin-bottom:0;">
          Secure payment via PhonePe. Your data is encrypted.
          <br><a href="#" onclick="event.preventDefault();closeSubscriptionModal();" style="color:#e17055;text-decoration:underline;">Maybe later</a>
        </p>
        <?php endif; ?>
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
    .badge-old{display:inline-block;font-size:9px;font-weight:700;padding:2px 6px;border-radius:10px;background:#f59e0b;color:#fff;text-transform:uppercase;letter-spacing:.3px;vertical-align:middle;margin-left:4px;}   /* pages still in the old look */
    .badge-soon{display:inline-block;font-size:9px;font-weight:700;padding:2px 6px;border-radius:10px;background:#7c3aed;color:#fff;text-transform:uppercase;letter-spacing:.3px;vertical-align:middle;margin-left:4px;}   /* built, switching on soon */
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
  <script src="../assets/js/utils/currency.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/utils/currency.js'); ?>"></script>
  <script src="../assets/js/escpos.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/escpos.js'); ?>"></script>
  <script src="../assets/js/direct-printer.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/direct-printer.js'); ?>" defer></script>
  <script src="../assets/libs/qrcode/qrcode.min.js" defer></script>
  <script src="../assets/js/poster-generator.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/poster-generator.js'); ?>" defer></script>
  <script src="../assets/js/video-generator.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/video-generator.js'); ?>" defer></script>
  <script src="../assets/js/caption-generator.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/caption-generator.js'); ?>" defer></script>
  <script src="../assets/js/campaign-calendar.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/campaign-calendar.js'); ?>" defer></script>
  <script src="../assets/js/growth.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/growth.js'); ?>" defer></script>
  <?php
  // Undo box for important deletes; its look is picked in Super Admin › Tools › Undo design
  $rgUndoDesign = 'pill';
  try { $v = getConnection()->query("SELECT v FROM sa_settings WHERE k = 'undo_design'")->fetchColumn(); if (in_array($v, ['pill', 'ring', 'snack', 'glass'], true)) $rgUndoDesign = $v; } catch (Throwable $e) {}
  ?>
  <script>window.RG_UNDO_DESIGN = <?php echo json_encode($rgUndoDesign); ?>;</script>
  <script src="../assets/js/rg-undo.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/rg-undo.js'); ?>"></script>
  <script src="../assets/js/onboarding.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/onboarding.js'); ?>" defer></script>
  <script src="../assets/js/script.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/script.js'); ?>" defer></script>
  <script src="../assets/js/qr-standee.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/qr-standee.js'); ?>" defer></script>
  <script src="../assets/js/bestsellers-admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/bestsellers-admin.js'); ?>" defer></script>
  <script src="../assets/js/offer-filters-admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/offer-filters-admin.js'); ?>" defer></script>
  <script src="../assets/js/todays-special-admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/todays-special-admin.js'); ?>" defer></script>
  <script src="../website/rg-upsell.js?v=<?php echo @filemtime(__DIR__ . '/../website/rg-upsell.js'); ?>" defer></script>
  <script src="../assets/js/upsell-admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/upsell-admin.js'); ?>" defer></script>
  <script src="../assets/js/scratch-admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/scratch-admin.js'); ?>" defer></script>
  <script src="../assets/js/wallet-admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/wallet-admin.js'); ?>" defer></script>
  <script src="../assets/js/loyalty-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/loyalty-studio.js'); ?>" defer></script>
  <script src="../assets/js/loyalty-studio-autopilot.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/loyalty-studio-autopilot.js'); ?>" defer></script>
  <script src="../assets/js/loyalty-studio-games.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/loyalty-studio-games.js'); ?>" defer></script>
  <script src="../assets/js/loyalty-studio-vip.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/loyalty-studio-vip.js'); ?>" defer></script>
  <script src="../assets/js/loyalty-studio-card.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/loyalty-studio-card.js'); ?>" defer></script>
  <script src="../assets/js/loyalty-studio-performance.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/loyalty-studio-performance.js'); ?>" defer></script>
  <script src="../assets/js/rg-stats.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/rg-stats.js'); ?>" defer></script>
  <script src="../assets/js/whatsapp-admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/whatsapp-admin.js'); ?>" defer></script>
  <script src="../assets/js/wa-order-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/wa-order-studio.js'); ?>" defer></script>
  <script src="../assets/js/owner-manager-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/owner-manager-studio.js'); ?>" defer></script>
  <script src="../assets/js/ai-apps-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/ai-apps-studio.js'); ?>" defer></script>
  <script src="../assets/js/riders-board.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/riders-board.js'); ?>" defer></script>
  <script src="../assets/js/studio-ui.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/studio-ui.js'); ?>" defer></script>
  <script src="../assets/js/areas-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/areas-studio.js'); ?>" defer></script>
  <script src="../assets/js/tables-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/tables-studio.js'); ?>" defer></script>
  <script src="../assets/js/custom-domain-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/custom-domain-studio.js'); ?>" defer></script>
  <script src="../assets/js/settings-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/settings-studio.js'); ?>" defer></script>
  <script src="../assets/js/offers-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/offers-studio.js'); ?>" defer></script>
  <script src="../assets/js/combos-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/combos-studio.js'); ?>" defer></script>
  <script src="../assets/js/gbp-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/gbp-studio.js'); ?>" defer></script>
  <script src="../assets/js/seo-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/seo-studio.js'); ?>" defer></script>
  <script src="../assets/js/staff-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/staff-studio.js'); ?>" defer></script>
  <script src="../assets/js/payments-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/payments-studio.js'); ?>" defer></script>
  <script src="../assets/js/waiter-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/waiter-studio.js'); ?>" defer></script>
  <script src="../assets/js/profile-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/profile-studio.js'); ?>" defer></script>
  <script src="../assets/js/inventory-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/inventory-studio.js'); ?>" defer></script>
  <script src="../assets/js/menu-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/menu-studio.js'); ?>" defer></script>
  <script src="../assets/js/new-order-popup.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/new-order-popup.js'); ?>" defer></script>
  <script src="../assets/js/reservations-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/reservations-studio.js'); ?>" defer></script>
  <script src="../assets/js/pos-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/pos-studio.js'); ?>" defer></script>
  <script src="../assets/js/pos-customer.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/pos-customer.js'); ?>" defer></script>
  <script src="../assets/js/pos-discount.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/pos-discount.js'); ?>" defer></script>
  <script src="../assets/js/pos-addons.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/pos-addons.js'); ?>" defer></script>
  <script src="../assets/js/pos-combos.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/pos-combos.js'); ?>" defer></script>
  <script src="../assets/js/pos-offline.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/pos-offline.js'); ?>" defer></script>
  <script src="../assets/js/menu-hours.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/menu-hours.js'); ?>" defer></script>
  <script src="../assets/js/activity-log.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/activity-log.js'); ?>" defer></script>
  <script src="../assets/js/order-alerts.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/order-alerts.js'); ?>" defer></script>
  <script src="../assets/js/rg-modal.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/rg-modal.js'); ?>" defer></script>
  <script src="../assets/js/kot-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/kot-studio.js'); ?>" defer></script>
  <script src="../assets/js/order-card.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/order-card.js'); ?>" defer></script>
  <script src="../assets/js/order-tabs.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/order-tabs.js'); ?>" defer></script>
  <script src="../assets/js/analytics-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/analytics-studio.js'); ?>" defer></script>
  <script src="../assets/js/platform-notices.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/platform-notices.js'); ?>" defer></script>
  <script src="../assets/js/dashboard-home.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/dashboard-home.js'); ?>" defer></script>
  <script src="../assets/js/nav-style.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/nav-style.js'); ?>" defer></script>
  <script src="../assets/js/delivery-partners.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/delivery-partners.js'); ?>" defer></script>
  <script src="../assets/js/marketing-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/marketing-studio.js'); ?>" defer></script>
  <script src="../website/rg-banners.js?v=<?php echo @filemtime(__DIR__ . '/../website/rg-banners.js'); ?>" defer></script>
  <script src="../assets/js/offer-banners-admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/offer-banners-admin.js'); ?>" defer></script>
  <script src="../assets/js/story-photos-admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/story-photos-admin.js'); ?>" defer></script>
  

  
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
      renderFeedbackInsights(res.insights);
      renderFeedback();
    })
    .catch(function() {
      document.getElementById("feedbackTbody").innerHTML = '<tr><td colspan="5" style="text-align:center;padding:30px;color:#999;">Couldn\'t load feedback — try refreshing the page!</td></tr>';
    });
}

// last 30 days at a glance: how many orders get rated, rating vs the month before, 6-month trend, who to call back
function renderFeedbackInsights(x) {
  var el = document.getElementById("fbInsights");
  if (!el || !x || !window.RGStats) return;
  var max = Math.max.apply(null, x.trend.map(function(m) { return m.n; }).concat([1]));
  var d = x.avg30 != null && x.avgprev != null ? Math.round((x.avg30 - x.avgprev) * 10) / 10 : null;
  var waNum = function(ph) { var s = String(ph || "").replace(/\D/g, ""); return s.length === 10 ? "91" + s : s; };
  el.innerHTML = '<div class="section-card" style="margin-bottom:16px"><div class="section-header"><div class="section-title">Last 30 days</div></div><div class="section-body">' +
    RGStats.funnel([
      { label: "Completed orders", value: x.orders30, icon: "receipt_long", hint: "Orders completed in the last 30 days" },
      { label: "Left a rating", value: x.n30, icon: "reviews", hint: "Share of orders that got a rating" },
      { label: "Happy (4–5★)", value: x.pos30, icon: "sentiment_satisfied", strong: true },
      { label: "Unhappy (1–2★)", value: x.neg30, icon: "sentiment_dissatisfied", noPct: true }
    ], { extra: [
      x.avg30 != null ? { label: "Average " + x.avg30.toFixed(1) + " ★", icon: "star", tone: x.avg30 >= 4 ? "good" : x.avg30 < 3.5 ? "bad" : "" } : null,
      d ? { label: (d > 0 ? "+" : "") + d.toFixed(1) + "★ vs the month before", tone: d > 0 ? "good" : "bad", icon: d > 0 ? "trending_up" : "trending_down" } : null,
      x.nprev ? { label: x.nprev + " ratings the month before" } : null ] }) +
    '<div class="gbx-trend">' + x.trend.map(function(m) {
      var lbl = new Date(m.month + "-01T00:00:00").toLocaleDateString(undefined, { month: "short" });
      return '<div title="' + m.n + ' ratings' + (m.avg ? ', average ' + m.avg.toFixed(1) : '') + '"><i style="height:' + Math.max(4, Math.round(m.n * 100 / max)) + '%"></i><b>' + m.n + '</b><small>' + lbl + (m.avg ? ' · ' + m.avg.toFixed(1) + '★' : '') + '</small></div>';
    }).join("") + '</div>' +
    (x.unhappy && x.unhappy.length ? '<div class="fb-unhappy"><b><span class="material-symbols-rounded">support_agent</span>Call these customers back</b><small>A quick sorry and a small gift often turns a 1-star into a regular.</small>' +
      x.unhappy.map(function(u) {
        return '<div class="fb-u"><span>' + "★".repeat(u.rating) + '</span><div><b>' + escHtml(u.customer_name || "Customer") + '</b> · #' + escHtml(u.order_number) + '<small>' + escHtml(u.review || "No comment") + '</small></div>' +
          (u.customer_phone ? '<a class="btn btn-secondary btn-sm" href="https://wa.me/' + waNum(u.customer_phone) + '" target="_blank" rel="noopener">WhatsApp</a>' : '') + '</div>';
      }).join("") + '</div>' : '') +
    '</div></div>';
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

// Custom Domain page: see assets/js/custom-domain-studio.js (window.loadEmbedSettings)

// Country -> currency and time zone auto-fill (both still manually overridable below)
document.getElementById('countrySelect').addEventListener('change', function() {
  var opt = this.options[this.selectedIndex];
  var tz = opt.getAttribute('data-timezone'), tzSel = document.getElementById('timezone');
  if (tz && tzSel) {
    if (![].some.call(tzSel.options, function (o) { return o.value === tz; })) tzSel.add(new Option(tz, tz));
    tzSel.value = tz;
  }
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
      showNotification(data.message || 'Settings saved', 'success');
    } else {
      showNotification(data.message || 'Failed to save settings', 'error');
    }
  } catch(err) {
    showNotification('Network error', 'error');
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

// Capture the beforeinstallprompt event when the browser fires it.
// Don't call preventDefault() here — Chrome warns if preventDefault is called
// but prompt() never fires. We save the event and call prompt() only when the
// user clicks "Install App".
var _promptTriggered = false;
window.addEventListener('beforeinstallprompt', function(e) {
  if (deferredPrompt) return; // already captured one
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
let selectedAmount = <?php echo json_encode($rgPlanM ?? 999); ?>;

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

// Self-driving: no "still pending" dead end that waits on the user to click
// something. Fast checks for ~2.5 min, then automatically drops to a slower
// background check for another ~10 min (same two-phase idea cart.php uses
// for order payments) — it keeps resolving itself for as long as PhonePe
// could plausibly still be confirming a UPI payment. Success/failure end
// the poll immediately either way.
function pollPaymentStatus(transactionId, attempts, intervalMs) {
  attempts = attempts || 0;
  intervalMs = intervalMs || 5000;
  const fastPhaseLimit = 30; // ~2.5 min at 5s
  const slowPhaseLimit = 70; // + ~10 min at 15s

  const runCheck = async () => {
    attempts++;
    try {
      const resp = await fetch('../api/subscription_payment.php?action=checkPaymentStatus&transaction_id=' + encodeURIComponent(transactionId));
      const data = await resp.json();
      if (data.success && data.payment_status === 'success') {
        showPaymentStatus(
          '<div style="text-align:center;">'
          + '<div style="font-size:3rem;margin-bottom:0.5rem;">✅</div>'
          + '<h3 style="margin:0 0 0.5rem;color:#1e293b;">Payment Successful!</h3>'
          + '<p style="color:#6b7280;font-size:0.9rem;">Your subscription has been activated. Refreshing...</p>'
          + '</div>',
          false
        );
        setTimeout(() => location.reload(), 2000);
        return;
      }
      if (data.success && data.payment_status === 'failed') {
        showPaymentStatus(
          '<div style="text-align:center;">'
          + '<div style="font-size:3rem;margin-bottom:0.5rem;">❌</div>'
          + '<h3 style="margin:0 0 0.5rem;color:#1e293b;">Payment Failed</h3>'
          + '<p style="color:#6b7280;font-size:0.9rem;">Please try again.</p>'
          + '</div>',
          true
        );
        const btn = document.getElementById('payNowBtn');
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = '<span class="material-symbols-rounded" style="font-size:20px;">lock</span> Pay ₹<span id="payNowAmount">' + selectedAmount.toLocaleString() + '</span>';
        }
        return;
      }
    } catch (e) {}

    if (attempts >= slowPhaseLimit) {
      // Both phases exhausted (~12.5 min) with no confirmation either way.
      // Rather than freezing or requiring a click, reload automatically —
      // checkSubscriptionOnLoad() re-evaluates the true state on the
      // refreshed page, and if PhonePe confirms later the next load picks
      // it up with zero action needed from the user.
      showPaymentStatus(
        '<div style="text-align:center;">'
        + '<div style="font-size:3rem;margin-bottom:0.5rem;">⏳</div>'
        + '<h3 style="margin:0 0 0.5rem;color:#1e293b;">Still confirming your payment</h3>'
        + '<p style="color:#6b7280;font-size:0.9rem;">This is taking longer than usual. Refreshing to check the latest status...</p>'
        + '</div>',
        false
      );
      setTimeout(() => location.reload(), 3000);
      return;
    }

    if (attempts === fastPhaseLimit) {
      const msgEl = document.getElementById('subscriptionPaymentStatus');
      if (msgEl) {
        const p = msgEl.querySelector('p');
        if (p) p.textContent = "This is taking longer than usual, but we're still checking automatically.";
      }
      pollPaymentStatus(transactionId, attempts, 15000);
      return;
    }

    pollPaymentStatus(transactionId, attempts, intervalMs);
  };

  // The very first check runs immediately instead of waiting out a full
  // interval first — the payment may well have already completed (webhook
  // included) by the time the user is even looking at this tab again, so
  // there's no reason to make them stare at "Redirecting to PhonePe..." for
  // a needless 5 seconds before the first real check happens.
  if (attempts === 0) {
    runCheck();
  } else {
    setTimeout(runCheck, intervalMs);
  }
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
        trialInfo.dataset.state = 'warn';
        trialInfo.innerHTML = 'Free trial ends in <strong>' + data.days_left + ' day' + (data.days_left == 1 ? '' : 's') + '</strong> · <a href="#" onclick="showSubscriptionModal();return false;" style="color:#dc2626;text-decoration:underline;">Renew now</a>';
        trialInfo.style.display = 'block';
      } else if (data.status === 'expired' || data.status === 'disabled') {
        trialInfo.dataset.state = 'bad';
        trialInfo.innerHTML = 'Subscription expired · <a href="#" onclick="showSubscriptionModal();return false;" style="color:#dc2626;text-decoration:underline;">Renew now</a>';
        trialInfo.style.display = 'block';
        // Show renewal modal automatically after short delay
        setTimeout(() => showSubscriptionModal(), 1000);
      } else if (data.days_left >= 0) {
        trialInfo.dataset.state = 'ok';
        trialInfo.innerHTML = 'Plan active · <strong>' + data.days_left + ' day' + (data.days_left == 1 ? '' : 's') + '</strong> left';
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
    deferredPrompt.preventDefault();
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
      var SW_VERSION = 18;   // 18: printed bills/receipts show only the combo's name (not its picks or 0-price ingredient lines); 17: POS manual discount (cashier's own % or flat, before tax, on the bill + orders); 16: bill prints Total Items/Quantity aligned under the QTY column and prints combo "×"/"·" (was "?"); 15: Google Business page loads on every open (refresh included) like other sections; 14: the bill/receipt preview starts at the left (no centred blank space); 13: SEO loads on every page open (refresh included) like other sections; 12: Search Console site-picking fix in SEO; 11: KOT preview leads on to the bill again; 10: force cache refresh after POS print preview fix; 9: the dashboard opens offline (POS offline billing)
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
      var vapidKey = ((await (await fetch('../api/get_vapid_key.php', { cache: 'no-store' })).json()).publicKey || '');
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

<!-- New Order popup: filled by assets/js/new-order-popup.js (RGNewOrder.show), styles no-* in studio-ui.css -->
<div id="newOrderOverlay" role="dialog" aria-modal="true" aria-label="New order">
  <div class="no-card" id="noCard"></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     ERROR MONITOR — JavaScript Functions
     ═══════════════════════════════════════════════════════════════ -->
<script>
</html>
