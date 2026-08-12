<?php
require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true);

$restaurant_id = null;
$restaurant_id_param = isset($_GET['restaurant_id']) ? trim($_GET['restaurant_id']) : '';
$restaurant_slug = isset($_GET['restaurant']) ? trim($_GET['restaurant']) : '';

if ($restaurant_id_param !== '') {
    $restaurant_id = $restaurant_id_param;
} elseif (isset($_SESSION['restaurant_id']) && $_SESSION['restaurant_id'] !== '') {
    $restaurant_id = $_SESSION['restaurant_id'];
}

$website_base_href = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';

$host = $_SERVER['HTTP_HOST'] ?? '';
if (strpos($host, ':') !== false) $host = explode(':', $host)[0];
$is_custom_domain = $host !== 'restrogrow.com' && $host !== 'www.restrogrow.com' && $host !== 'localhost' && $host !== '127.0.0.1' && strpos($host, 'hstgr.io') === false;

function restaurantPageUrl($page = '') {
    global $restaurant_slug, $is_custom_domain, $restaurant_id;
    if ($is_custom_domain) {
        return $page ? '/' . $page : '/';
    }
    if ($restaurant_slug) {
        $root = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        return $root . '/' . $restaurant_slug . ($page ? '/' . $page : '');
    }
    if ($restaurant_id) {
        return $page ? $page . '.php?restaurant_id=' . urlencode($restaurant_id) : 'index.php?restaurant_id=' . urlencode($restaurant_id);
    }
    return $page ? $page . '.php' : 'index.php';
}

$restaurant_name = 'Restaurant';
$currency_symbol = '\u20b9';
$primary_red = '#F70000';
$dark_red = '#DA020E';
$primary_yellow = '#FFD100';
$restaurant_email = '';
$restaurant_phone = '';
$restaurant_owner = '';
$custom_policy_content = null;

try {
    require_once __DIR__ . '/db_config.php';
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        global $pdo;
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
        }
    }

    if ($restaurant_id) {
        $stmt = $conn->prepare("SELECT restaurant_name, currency_symbol, email, phone, owner_name FROM users WHERE restaurant_id = ? LIMIT 1");
        $stmt->execute([$restaurant_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $restaurant_name = $user['restaurant_name'] ?? 'Restaurant';
            $currency_symbol = $user['currency_symbol'] ?? '\u20b9';
            $restaurant_email = $user['email'] ?? '';
            $restaurant_phone = $user['phone'] ?? '';
            $restaurant_owner = $user['owner_name'] ?? '';
        }

        $stmt = $conn->prepare("SELECT primary_red, dark_red, primary_yellow FROM website_settings WHERE restaurant_id = ? LIMIT 1");
        $stmt->execute([$restaurant_id]);
        $themeRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($themeRow) {
            if (!empty($themeRow['primary_red'])) $primary_red = htmlspecialchars($themeRow['primary_red'], ENT_QUOTES, 'UTF-8');
            if (!empty($themeRow['dark_red'])) $dark_red = htmlspecialchars($themeRow['dark_red'], ENT_QUOTES, 'UTF-8');
            if (!empty($themeRow['primary_yellow'])) $primary_yellow = htmlspecialchars($themeRow['primary_yellow'], ENT_QUOTES, 'UTF-8');
        }

        // Restaurant-customized policy content (Settings > Policy Pages). Falls back to
        // the hardcoded default text below when nothing has been saved for this restaurant
        // (or when the policy_pages table hasn't been created yet on this server).
        try {
            $stmt = $conn->prepare("SELECT content FROM policy_pages WHERE restaurant_id = ? AND policy_type = 'shipping' LIMIT 1");
            $stmt->execute([$restaurant_id]);
            $policyRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($policyRow && trim((string)($policyRow['content'] ?? '')) !== '') {
                $custom_policy_content = $policyRow['content'];
            }
        } catch (Exception $e) {
            // policy_pages table not created yet - use default text
        }
    }
} catch (Exception $e) {
    error_log("Error loading restaurant data: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <base href="<?php echo $website_base_href; ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Shipping Policy - <?php echo htmlspecialchars($restaurant_name); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="dns-prefetch" href="https://fonts.googleapis.com">
    <link rel="dns-prefetch" href="https://fonts.gstatic.com">
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__ . '/style.css') ?: time(); ?>">
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"></noscript>
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0"></noscript>
    <style>
      :root {
        --primary-red: <?php echo htmlspecialchars($primary_red, ENT_QUOTES, 'UTF-8'); ?>;
        --dark-red: <?php echo htmlspecialchars($dark_red, ENT_QUOTES, 'UTF-8'); ?>;
        --primary-yellow: <?php echo htmlspecialchars($primary_yellow, ENT_QUOTES, 'UTF-8'); ?>;
      }
      .policy-container {
        max-width: 900px;
        margin: 2rem auto;
        padding: 2rem;
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      }
      .policy-header {
        border-bottom: 2px solid var(--primary-red);
        padding-bottom: 1rem;
        margin-bottom: 2rem;
      }
      .policy-header h1 {
        color: var(--primary-red);
        font-size: 2rem;
        margin-bottom: 0.5rem;
      }
      .policy-header p {
        color: #666;
        font-size: 0.9rem;
      }
      .policy-content h2 {
        color: var(--text-dark);
        font-size: 1.5rem;
        margin-top: 2rem;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid #e5e7eb;
      }
      .policy-content h3 {
        color: var(--text-dark);
        font-size: 1.2rem;
        margin-top: 1.5rem;
        margin-bottom: 0.75rem;
      }
      .policy-content p {
        color: var(--text-light);
        line-height: 1.8;
        margin-bottom: 1rem;
      }
      .policy-content ul {
        margin-left: 2rem;
        margin-bottom: 1rem;
      }
      .policy-content li {
        color: var(--text-light);
        line-height: 1.8;
        margin-bottom: 0.5rem;
      }
      .back-link {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--primary-red);
        text-decoration: none;
        font-weight: 600;
        margin-bottom: 2rem;
        transition: color 0.2s;
      }
      .back-link:hover {
        color: var(--dark-red);
      }
    </style>
</head>
<body>
    <div class="policy-container">
        <a href="<?php echo restaurantPageUrl(); ?>" class="back-link">
            <span class="material-symbols-rounded">arrow_back</span>
            Back to Home
        </a>
        
        <div class="policy-header">
            <h1>Shipping & Delivery Policy</h1>
            <p>Last updated: <?php echo date('F j, Y'); ?></p>
        </div>
        
        <div class="policy-content">
            <?php if ($custom_policy_content !== null): ?>
            <?php echo $custom_policy_content; ?>
            <?php else: ?>
            <p>At <?php echo htmlspecialchars($restaurant_name); ?><?php if ($restaurant_owner): ?> (Owned by <?php echo htmlspecialchars($restaurant_owner); ?>)<?php endif; ?>, we are committed to delivering your orders promptly and efficiently. This Shipping & Delivery Policy explains our delivery practices and what you can expect when you order from us.</p>

            <h2>1. Delivery Areas</h2>
            <p>We currently deliver to select areas based on pincode availability. Please enter your pincode during checkout to check if we deliver to your location. We continuously expand our delivery zones to serve more customers.</p>

            <h2>2. Delivery Timeframes</h2>
            <h3>2.1 Estimated Delivery Time</h3>
            <p>Our estimated delivery time is displayed at checkout and depends on your location, order size, and current demand. Typical delivery times range from 30-60 minutes. Please note that these are estimates and actual delivery times may vary.</p>

            <h3>2.2 Peak Hours</h3>
            <p>During peak hours (typically lunch 12:00 PM - 2:00 PM and dinner 7:00 PM - 9:00 PM), delivery times may be longer than usual. We appreciate your patience during these busy periods.</p>

            <h2>3. Delivery Charges</h2>
            <p>Delivery charges are calculated based on your delivery location and displayed at checkout before you place your order. Some orders may qualify for free delivery based on promotional offers or order value thresholds.</p>

            <h2>4. Order Tracking</h2>
            <p>You can track your order status through your profile page on our website. We provide real-time updates on order preparation, dispatch, and estimated arrival time.</p>

            <h2>5. Delivery Attempts</h2>
            <p>Our delivery partners will make reasonable attempts to deliver your order to the address provided. If delivery is not possible due to incorrect address or unavailability, we will contact you using the phone number provided with your order.</p>

            <h2>6. Self-Pickup / Takeaway</h2>
            <p>If you prefer, you can choose the Takeaway option at checkout and pick up your order directly from <?php echo htmlspecialchars($restaurant_name); ?>. Please arrive within the specified pickup time to ensure your food is fresh.</p>

            <h2>7. Delivery Restrictions</h2>
            <p>We reserve the right to refuse or cancel delivery in certain circumstances, including but not limited to: incorrect or incomplete addresses,恶劣 weather conditions, or areas we cannot safely access with our delivery partners.</p>

            <h2>8. Contact Us</h2>
            <p>If you have any questions about our Shipping & Delivery Policy, please contact us at:</p>
            <p>
                <strong>Owner:</strong> <?php echo htmlspecialchars($restaurant_owner ?: 'Not specified'); ?><br>
                <strong>Email:</strong> <?php echo htmlspecialchars($restaurant_email ?: 'restrogrow@gmail.com'); ?><br>
                <strong>Phone:</strong> <?php echo htmlspecialchars($restaurant_phone ?: '+91 6377568749'); ?><br>
                <strong>Address:</strong> <?php echo htmlspecialchars($restaurant_name); ?>, Delivery Support
            </p>
            <?php endif; ?>
        </div>
    </div>

    <script src="script.js?v=<?php echo filemtime(__DIR__ . '/script.js') ?: time(); ?>" defer></script>
</body>
</html>
