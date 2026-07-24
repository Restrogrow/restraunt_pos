<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true); // Skip timeout validation for public customer website

// Get restaurant identifier - priority: restaurant_id > restaurant slug > session
$restaurant_id = null;
$restaurant_id_param = isset($_GET['restaurant_id']) ? trim($_GET['restaurant_id']) : '';
$restaurant_slug = isset($_GET['restaurant']) ? trim($_GET['restaurant']) : '';
$has_id_param = $restaurant_id_param !== '';
$has_slug_param = $restaurant_slug !== '';

if ($has_id_param) {
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

// Default values
$restaurant_name = 'Restaurant';
$currency_symbol = '₹';
$primary_red = '#F70000';
$dark_red = '#DA020E';
$primary_yellow = '#FFD100';
$restaurant_email = '';
$restaurant_phone = '';
$restaurant_owner = '';

try {
    require_once __DIR__ . '/db_config.php';
    // Get connection using getConnection() for lazy connection support
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        // Fallback to $pdo if getConnection() doesn't exist (backward compatibility)
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
            $currency_symbol = $user['currency_symbol'] ?? '₹';
            $restaurant_email = $user['email'] ?? '';
            $restaurant_phone = $user['phone'] ?? '';
            $restaurant_owner = $user['owner_name'] ?? '';
        }
        
        // Get theme colors
        $stmt = $conn->prepare("SELECT primary_red, dark_red, primary_yellow FROM website_settings WHERE restaurant_id = ? LIMIT 1");
        $stmt->execute([$restaurant_id]);
        $themeRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($themeRow) {
            if (!empty($themeRow['primary_red'])) $primary_red = htmlspecialchars($themeRow['primary_red'], ENT_QUOTES, 'UTF-8');
            if (!empty($themeRow['dark_red'])) $dark_red = htmlspecialchars($themeRow['dark_red'], ENT_QUOTES, 'UTF-8');
            if (!empty($themeRow['primary_yellow'])) $primary_yellow = htmlspecialchars($themeRow['primary_yellow'], ENT_QUOTES, 'UTF-8');
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
    <title>Privacy Policy - <?php echo htmlspecialchars($restaurant_name); ?></title>
    
    <!-- Resource Hints for Performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="dns-prefetch" href="https://fonts.googleapis.com">
    <link rel="dns-prefetch" href="https://fonts.gstatic.com">
    
    <!-- Critical CSS -->
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__ . '/style.css') ?: time(); ?>">
    
    <!-- Optimized Font Loading -->
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
            <h1>Privacy Policy</h1>
            <p>Last updated: <?php echo date('F j, Y'); ?></p>
        </div>
        
        <div class="policy-content">
            <p>At <?php echo htmlspecialchars($restaurant_name); ?>, we are committed to protecting your privacy. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our restaurant management and ordering platform.</p>
            
            <h2>1. Information We Collect</h2>
            <h3>1.1 Personal Information</h3>
            <p>We may collect personal information that you provide to us, including:</p>
            <ul>
                <li>Name and contact information (email address, phone number)</li>
                <li>Delivery address and location data</li>
                <li>Payment information (processed securely through third-party payment processors)</li>
                <li>Order history and preferences</li>
                <li>Account credentials (username, password)</li>
            </ul>
            
            <h3>1.2 Automatically Collected Information</h3>
            <p>When you use our platform, we may automatically collect:</p>
            <ul>
                <li>Device information (IP address, browser type, operating system)</li>
                <li>Usage data (pages visited, time spent, features used)</li>
                <li>Cookies and similar tracking technologies</li>
                <li>Location data (if you enable location services)</li>
            </ul>
            
            <h2>2. How We Use Your Information</h2>
            <p>We use the collected information for the following purposes:</p>
            <ul>
                <li>To process and fulfill your orders</li>
                <li>To communicate with you about your orders and account</li>
                <li>To improve our services and user experience</li>
                <li>To send promotional offers and updates (with your consent)</li>
                <li>To detect and prevent fraud or abuse</li>
                <li>To comply with legal obligations</li>
            </ul>
            
            <h2>3. Information Sharing and Disclosure</h2>
            <p>We do not sell your personal information. We may share your information in the following circumstances:</p>
            <ul>
                <li><strong>Service Providers:</strong> With third-party service providers who assist in operating our platform (payment processors, delivery services, analytics providers)</li>
                <li><strong>Legal Requirements:</strong> When required by law or to protect our rights and safety</li>
                <li><strong>Business Transfers:</strong> In connection with a merger, acquisition, or sale of assets</li>
                <li><strong>With Your Consent:</strong> When you explicitly authorize us to share your information</li>
            </ul>
            
            <h2>4. Data Security</h2>
            <p>We implement appropriate technical and organizational measures to protect your personal information against unauthorized access, alteration, disclosure, or destruction. However, no method of transmission over the internet is 100% secure.</p>
            
            <h2>5. Your Rights</h2>
            <p>Depending on your location, you may have the following rights regarding your personal information:</p>
            <ul>
                <li>Right to access your personal data</li>
                <li>Right to rectify inaccurate data</li>
                <li>Right to erasure ("right to be forgotten")</li>
                <li>Right to restrict processing</li>
                <li>Right to data portability</li>
                <li>Right to object to processing</li>
                <li>Right to withdraw consent</li>
            </ul>
            
            <h2>6. Cookies and Tracking Technologies</h2>
            <p>We use cookies and similar technologies to enhance your experience, analyze usage, and assist with marketing efforts. For more information, please see our         <a href="<?php echo restaurantPageUrl('cookie-policy'); ?>" style="color: var(--primary-red);">Cookie Policy</a>.</p>
            
            <h2>7. Data Retention</h2>
            <p>We retain your personal information for as long as necessary to fulfill the purposes outlined in this Privacy Policy, unless a longer retention period is required or permitted by law.</p>
            
            <h2>8. Children's Privacy</h2>
            <p>Our platform is not intended for children under the age of 13. We do not knowingly collect personal information from children under 13. If you believe we have collected information from a child under 13, please contact us immediately.</p>
            
            <h2>9. Changes to This Privacy Policy</h2>
            <p>We may update this Privacy Policy from time to time. We will notify you of any changes by posting the new Privacy Policy on this page and updating the "Last updated" date.</p>
            
            <h2>10. Contact Us</h2>
            <p>If you have any questions about this Privacy Policy or our data practices, please contact us at:</p>
            <p>
                <strong>Owner:</strong> <?php echo htmlspecialchars($restaurant_owner ?: 'Not specified'); ?><br>
                <strong>Email:</strong> <?php echo htmlspecialchars($restaurant_email ?: 'restrogrow@gmail.com'); ?><br>
                <strong>Phone:</strong> <?php echo htmlspecialchars($restaurant_phone ?: '+91 6377568749'); ?><br>
                <strong>Address:</strong> <?php echo htmlspecialchars($restaurant_name); ?>, Customer Support
            </p>
        </div>
    </div>
    
    <script src="script.js?v=<?php echo filemtime(__DIR__ . '/script.js') ?: time(); ?>" defer></script>
</body>
</html>

