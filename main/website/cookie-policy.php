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
$custom_policy_content = null;

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

        // Restaurant-customized policy content (Settings > Policy Pages). Falls back to
        // the hardcoded default text below when nothing has been saved for this restaurant
        // (or when the policy_pages table hasn't been created yet on this server).
        try {
            $stmt = $conn->prepare("SELECT content FROM policy_pages WHERE restaurant_id = ? AND policy_type = 'cookie' LIMIT 1");
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
    <title>Cookie Policy - <?php echo htmlspecialchars($restaurant_name); ?></title>
    
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
      .cookie-table {
        width: 100%;
        border-collapse: collapse;
        margin: 1rem 0;
      }
      .cookie-table th,
      .cookie-table td {
        padding: 0.75rem;
        text-align: left;
        border-bottom: 1px solid #e5e7eb;
      }
      .cookie-table th {
        background: #f9fafb;
        font-weight: 600;
        color: var(--text-dark);
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
            <h1>Cookie Policy</h1>
            <p>Last updated: <?php echo date('F j, Y'); ?></p>
        </div>
        
        <div class="policy-content">
            <?php if ($custom_policy_content !== null): ?>
            <?php echo $custom_policy_content; ?>
            <?php else: ?>
            <p>This Cookie Policy explains how <?php echo htmlspecialchars($restaurant_name); ?> uses cookies and similar tracking technologies when you visit our website and use our platform. It explains what these technologies are and why we use them, as well as your rights to control our use of them.</p>

            <h2>1. What Are Cookies?</h2>
            <p>Cookies are small text files that are placed on your device (computer, tablet, or mobile) when you visit a website. They are widely used to make websites work more efficiently and provide information to the website owners.</p>
            
            <h2>2. How We Use Cookies</h2>
            <p>We use cookies for several purposes:</p>
            <ul>
                <li><strong>Essential Cookies:</strong> These cookies are necessary for the website to function properly. They enable core functionality such as security, network management, and accessibility.</li>
                <li><strong>Performance Cookies:</strong> These cookies help us understand how visitors interact with our website by collecting and reporting information anonymously.</li>
                <li><strong>Functionality Cookies:</strong> These cookies allow the website to remember choices you make (such as your username, language, or region) and provide enhanced, personalized features.</li>
                <li><strong>Targeting/Advertising Cookies:</strong> These cookies may be set through our site by our advertising partners to build a profile of your interests and show you relevant content on other sites.</li>
            </ul>
            
            <h2>3. Types of Cookies We Use</h2>
            <table class="cookie-table">
                <thead>
                    <tr>
                        <th>Cookie Name</th>
                        <th>Purpose</th>
                        <th>Duration</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>session_id</td>
                        <td>Maintains your session while using the platform</td>
                        <td>Session</td>
                    </tr>
                    <tr>
                        <td>cart_data</td>
                        <td>Stores your shopping cart items</td>
                        <td>30 days</td>
                    </tr>
                    <tr>
                        <td>user_preferences</td>
                        <td>Remembers your preferences (language, currency, etc.)</td>
                        <td>1 year</td>
                    </tr>
                    <tr>
                        <td>cookie_consent</td>
                        <td>Stores your cookie consent preferences</td>
                        <td>1 year</td>
                    </tr>
                    <tr>
                        <td>analytics_id</td>
                        <td>Helps us analyze website usage and improve our services</td>
                        <td>2 years</td>
                    </tr>
                </tbody>
            </table>
            
            <h2>4. Third-Party Cookies</h2>
            <p>In addition to our own cookies, we may also use various third-party cookies to report usage statistics of the service, deliver advertisements, and so on. These third-party cookies include:</p>
            <ul>
                <li><strong>Google Analytics:</strong> Helps us understand how visitors use our website</li>
                <li><strong>Payment Processors:</strong> Cookies used by payment providers to process transactions securely</li>
                <li><strong>Social Media Platforms:</strong> Cookies from social media platforms if you interact with social features</li>
            </ul>
            
            <h2>5. Managing Cookies</h2>
            <p>You have the right to decide whether to accept or reject cookies. You can exercise your cookie rights by setting your preferences in our cookie consent banner or by configuring your browser settings.</p>
            
            <h3>5.1 Browser Settings</h3>
            <p>Most web browsers allow you to control cookies through their settings preferences. However, limiting cookies may impact your ability to use our website. Here are links to cookie settings for popular browsers:</p>
            <ul>
                <li><a href="https://support.google.com/chrome/answer/95647" target="_blank" style="color: var(--primary-red);">Google Chrome</a></li>
                <li><a href="https://support.mozilla.org/en-US/kb/enable-and-disable-cookies-website-preferences" target="_blank" style="color: var(--primary-red);">Mozilla Firefox</a></li>
                <li><a href="https://support.apple.com/guide/safari/manage-cookies-and-website-data-sfri11471/mac" target="_blank" style="color: var(--primary-red);">Safari</a></li>
                <li><a href="https://support.microsoft.com/en-us/microsoft-edge/delete-cookies-in-microsoft-edge-63947406-40ac-c3b8-57b9-2a946a29ae09" target="_blank" style="color: var(--primary-red);">Microsoft Edge</a></li>
            </ul>
            
            <h3>5.2 Cookie Consent Banner</h3>
            <p>When you first visit our website, you will see a cookie consent banner. You can accept all cookies, reject non-essential cookies, or customize your preferences. You can change your preferences at any time by clicking the cookie settings link in the footer.</p>
            
            <h2>6. Do Not Track Signals</h2>
            <p>Some browsers incorporate a "Do Not Track" (DNT) feature that signals to websites you visit that you do not want to have your online activity tracked. Currently, there is no standard for how DNT signals should be interpreted. As a result, our website does not currently respond to DNT signals.</p>
            
            <h2>7. Updates to This Cookie Policy</h2>
            <p>We may update this Cookie Policy from time to time to reflect changes in the cookies we use or for other operational, legal, or regulatory reasons. Please revisit this Cookie Policy regularly to stay informed about our use of cookies.</p>
            
            <h2>8. Contact Us</h2>
            <p>If you have any questions about our use of cookies or this Cookie Policy, please contact us at:</p>
            <p>
            <strong>Owner:</strong> <?php echo htmlspecialchars($restaurant_owner ?: 'Not specified'); ?><br>
            <strong>Email:</strong> <?php echo htmlspecialchars($restaurant_email ?: 'restrogrow@gmail.com'); ?><br>
            <strong>Phone:</strong> <?php echo htmlspecialchars($restaurant_phone ?: '+91 6377568749'); ?><br>
            <strong>Address:</strong> <?php echo htmlspecialchars($restaurant_name); ?>, Privacy Team
            </p>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="script.js?v=<?php echo filemtime(__DIR__ . '/script.js') ?: time(); ?>" defer></script>
</body>
</html>

