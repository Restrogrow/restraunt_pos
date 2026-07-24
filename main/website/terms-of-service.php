<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true); // Skip timeout validation for public customer website

// Get restaurant identifier
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
$is_custom_domain = $host !== 'restrogrow.com' && $host !== 'localhost' && $host !== '127.0.0.1' && strpos($host, 'hstgr.io') === false;

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
    <title>Terms of Service - <?php echo htmlspecialchars($restaurant_name); ?></title>
    
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
            <h1>Terms of Service</h1>
            <p>Last updated: <?php echo date('F j, Y'); ?></p>
        </div>
        
        <div class="policy-content">
            <p>Welcome to <?php echo htmlspecialchars($restaurant_name); ?><?php if ($restaurant_owner): ?> (Owned by <?php echo htmlspecialchars($restaurant_owner); ?>)<?php endif; ?>. These Terms of Service ("Terms") govern your access to and use of our restaurant management and ordering platform. By accessing or using our services, you agree to be bound by these Terms.</p>
            
            <h2>1. Acceptance of Terms</h2>
            <p>By accessing or using <?php echo htmlspecialchars($restaurant_name); ?>, you acknowledge that you have read, understood, and agree to be bound by these Terms and our Privacy Policy. If you do not agree to these Terms, you may not use our services.</p>
            
            <h2>2. Description of Service</h2>
            <p><?php echo htmlspecialchars($restaurant_name); ?> provides a platform for restaurants to manage their operations and for customers to place orders, make reservations, and interact with restaurants. We reserve the right to modify, suspend, or discontinue any aspect of the service at any time.</p>
            
            <h2>3. User Accounts</h2>
            <h3>3.1 Account Creation</h3>
            <p>To use certain features of our platform, you may be required to create an account. You agree to:</p>
            <ul>
                <li>Provide accurate, current, and complete information</li>
                <li>Maintain and update your account information</li>
                <li>Maintain the security of your account credentials</li>
                <li>Accept responsibility for all activities under your account</li>
            </ul>
            
            <h3>3.2 Account Termination</h3>
            <p>We reserve the right to suspend or terminate your account if you violate these Terms or engage in fraudulent, abusive, or illegal activity.</p>
            
            <h2>4. Orders and Payments</h2>
            <h3>4.1 Order Placement</h3>
            <p>When you place an order through our platform:</p>
            <ul>
                <li>You agree to pay the prices displayed for the items you order</li>
                <li>All prices are subject to change without notice</li>
                <li>Orders are subject to availability</li>
                <li>The restaurant reserves the right to refuse or cancel any order</li>
            </ul>
            
            <h3>4.2 Payment</h3>
            <p>Payment must be made at the time of order placement or as otherwise specified. We accept various payment methods as displayed on the platform. All payments are processed securely through third-party payment processors.</p>
            
            <h3>4.3 Refunds and Cancellations</h3>
            <p>Refund and cancellation policies are determined by the individual restaurant. Please contact the restaurant directly for refund or cancellation requests.</p>
            
            <h2>5. User Conduct</h2>
            <p>You agree not to:</p>
            <ul>
                <li>Use the service for any illegal purpose</li>
                <li>Violate any applicable laws or regulations</li>
                <li>Infringe upon the rights of others</li>
                <li>Transmit any harmful, offensive, or inappropriate content</li>
                <li>Interfere with or disrupt the service</li>
                <li>Attempt to gain unauthorized access to any part of the service</li>
                <li>Use automated systems to access the service without permission</li>
            </ul>
            
            <h2>6. Intellectual Property</h2>
            <p>All content, features, and functionality of <?php echo htmlspecialchars($restaurant_name); ?>, including but not limited to text, graphics, logos, images, and software, are owned by <?php echo htmlspecialchars($restaurant_name); ?> or its licensors and are protected by copyright, trademark, and other intellectual property laws.</p>
            
            <h2>7. Disclaimers</h2>
            <p>THE SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE" WITHOUT WARRANTIES OF ANY KIND, EITHER EXPRESS OR IMPLIED. WE DO NOT WARRANT THAT THE SERVICE WILL BE UNINTERRUPTED, ERROR-FREE, OR SECURE.</p>
            
            <h2>8. Limitation of Liability</h2>
            <p>TO THE MAXIMUM EXTENT PERMITTED BY LAW, <?php echo htmlspecialchars($restaurant_name); ?> SHALL NOT BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, OR ANY LOSS OF PROFITS OR REVENUES, WHETHER INCURRED DIRECTLY OR INDIRECTLY.</p>
            
            <h2>9. Indemnification</h2>
            <p>You agree to indemnify and hold harmless <?php echo htmlspecialchars($restaurant_name); ?>, its affiliates, and their respective officers, directors, employees, and agents from any claims, damages, losses, liabilities, and expenses arising out of your use of the service or violation of these Terms.</p>
            
            <h2>10. Modifications to Terms</h2>
            <p>We reserve the right to modify these Terms at any time. We will notify users of any material changes by posting the updated Terms on this page and updating the "Last updated" date. Your continued use of the service after such modifications constitutes acceptance of the updated Terms.</p>
            
            <h2>11. Governing Law</h2>
            <p>These Terms shall be governed by and construed in accordance with the laws of the jurisdiction in which <?php echo htmlspecialchars($restaurant_name); ?> operates, without regard to its conflict of law provisions.</p>
            
            <h2>12. Contact Information</h2>
            <p>If you have any questions about these Terms of Service, please contact us at:</p>
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

