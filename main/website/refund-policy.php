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
$currency_symbol = '₹';
$primary_red = '#F70000';
$dark_red = '#DA020E';
$primary_yellow = '#FFD100';
$restaurant_email = '';
$restaurant_phone = '';
$restaurant_owner = '';
$custom_policy_content = null;

require_once __DIR__ . '/policy_defaults.php';

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
            $currency_symbol = $user['currency_symbol'] ?? '₹';
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
            $stmt = $conn->prepare("SELECT content FROM policy_pages WHERE restaurant_id = ? AND policy_type = 'refund' LIMIT 1");
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
    <title>Refund Policy - <?php echo htmlspecialchars($restaurant_name, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', sans-serif;
            background: #e8ecf2;
            color: #1a1b1f;
            min-height: 100vh;
            overflow-x: hidden;
        }
        .phone-frame {
            max-width: 425px;
            margin: 0 auto;
            min-height: 100vh;
            background: #fff;
            position: relative;
            box-shadow: 0 0 40px rgba(0,0,0,0.08);
        }
        @media (min-width: 768px) {
            .phone-frame { margin: 20px auto; min-height: calc(100vh - 40px); border-radius: 28px; overflow: hidden; }
<?php if ($host === 'triposhsymmetry.in'): ?>
            /* TEMPORARY (PhonePe approval, added 2026-08-18 — remove in ~2 days) */
            .phone-frame { max-width: 100%; margin: 0; border-radius: 0; }
<?php endif; ?>
        }
        .policy-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 12px 12px;
            border-bottom: 1.5px solid #ccc;
        }
        .back-btn {
            display: flex; align-items: center; justify-content: center;
            width: 40px; height: 40px; border-radius: 8px;
            background: linear-gradient(to right, #846241, #1a3934);
            color: #fff; border: none; cursor: pointer; font-size: 22px;
            flex-shrink: 0; text-decoration: none;
        }
        .policy-title {
            flex: 1;
            font-size: 16px;
            font-weight: 700;
            color: #1a1b1f;
        }
        .policy-container {
            padding: 20px 16px 40px;
        }
        .policy-container h1 {
            font-size: 22px;
            font-weight: 700;
            color: #1a3934;
            margin-bottom: 4px;
        }
        .policy-container .last-updated {
            font-size: 12px;
            color: #888;
            margin-bottom: 20px;
        }
        .policy-container h2 {
            font-size: 16px;
            font-weight: 600;
            color: #1a3934;
            margin-top: 24px;
            margin-bottom: 10px;
        }
        .policy-container h3 {
            font-size: 14px;
            font-weight: 600;
            color: #1a1b1f;
            margin-top: 16px;
            margin-bottom: 8px;
        }
        .policy-container p {
            font-size: 13px;
            color: #444;
            line-height: 1.7;
            margin-bottom: 12px;
        }
        .policy-container ul {
            margin-left: 20px;
            margin-bottom: 12px;
        }
        .policy-container li {
            font-size: 13px;
            color: #444;
            line-height: 1.7;
            margin-bottom: 6px;
        }
        .policy-container strong {
            color: #1a1b1f;
        }
        .back-home {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #846241;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 16px;
        }
        .back-home:hover { opacity: 0.8; }
    </style>
</head>
<body>
<div class="phone-frame">
    <div class="policy-header">
        <a class="back-btn" href="<?php echo restaurantPageUrl('cart'); ?>"><i class="fa fa-arrow-left"></i></a>
        <span class="policy-title"><?php echo htmlspecialchars($restaurant_name, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <div class="policy-container">
        <a class="back-home" href="<?php echo restaurantPageUrl(); ?>"><i class="fa fa-chevron-left"></i> Back to Home</a>
        <h1>Refund Policy</h1>
        <p class="last-updated">Last updated: <?php echo date('F j, Y'); ?></p>

        <?php if ($custom_policy_content !== null): ?>
        <?php echo formatPolicyContent($custom_policy_content); ?>
        <?php else: ?>
        <?php echo getDefaultRefundPolicyHtml($restaurant_name, $restaurant_owner, $restaurant_email, $restaurant_phone); ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
