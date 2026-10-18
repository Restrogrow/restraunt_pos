<?php
/**
 * Shared layout for the restaurant's public policy pages
 * (privacy-policy.php, terms-of-service.php, refund-policy.php, shipping-policy.php,
 * cookie-policy.php). Each of those files only calls renderPolicyPage('<type>').
 *
 * Wording comes from Settings > Policy Pages (policy_pages table) when the owner saved
 * their own, otherwise from the defaults in policy_defaults.php. The page adds the
 * restaurant's logo and colours, tabs to the other policies, an "On this page" list
 * built from the headings, and a contact card.
 */
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/policy_defaults.php';
// db_config.php keeps its settings in globals, so it has to load at file level
try { require_once __DIR__ . '/db_config.php'; } catch (Throwable $e) { error_log('policy page db_config: ' . $e->getMessage()); }

function policyPageTypes() {
    return [
        'privacy'  => ['page' => 'privacy-policy',   'title' => 'Privacy Policy',             'tab' => 'Privacy',  'icon' => 'shield_person',     'lead' => 'What details we collect when you order from us, why we need them, and how we keep them safe.'],
        'terms'    => ['page' => 'terms-of-service', 'title' => 'Terms of Service',           'tab' => 'Terms',    'icon' => 'gavel',             'lead' => 'The rules for ordering, paying and using our website.'],
        'refund'   => ['page' => 'refund-policy',    'title' => 'Refund Policy',              'tab' => 'Refunds',  'icon' => 'currency_exchange', 'lead' => 'When you can get your money back, how to ask for it, and how long it takes.'],
        'shipping' => ['page' => 'shipping-policy',  'title' => 'Shipping & Delivery Policy', 'tab' => 'Delivery', 'icon' => 'local_shipping',    'lead' => 'Where we deliver, how long it takes and what it costs.'],
        'cookie'   => ['page' => 'cookie-policy',    'title' => 'Cookie Policy',              'tab' => 'Cookies',  'icon' => 'cookie',            'lead' => 'The small files our website saves in your browser, and what they are for.'],
    ];
}

function renderPolicyPage($type) {
    global $restaurant_id, $restaurant_slug, $is_custom_domain;
    $types = policyPageTypes();
    $meta = $types[$type];

    startSecureSession(true); // public page: no timeout check

    // Restaurant: restaurant_id in the URL, else the visitor's session
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
    $is_custom_domain = $host !== 'restrogrow.com' && $host !== 'www.restrogrow.com' && $host !== 'localhost' && $host !== '127.0.0.1' && strpos($host, 'hstgr.io') === false && !preg_match('/\.ngrok-free\.(app|dev)$/', $host);

    $restaurant_name = 'Restaurant';
    $restaurant_email = '';
    $restaurant_phone = '';
    $restaurant_owner = '';
    $restaurant_address = '';
    $restaurant_logo = null;
    $accent = '#F70000';
    $custom = null;
    $updated = null;

    try {
        if (function_exists('getConnection')) {
            $conn = getConnection();
        } else {
            global $pdo;
            $conn = $pdo ?? null;
            if (!$conn) throw new Exception('Database connection not available');
        }
        // /<restaurant-slug>/privacy-policy: the restaurant in the link wins over whatever is in the session
        // (this page used to show "Restaurant" with no name or contact, or another restaurant's policy)
        if ($restaurant_id_param === '' && $restaurant_slug !== '' && preg_match('/^[a-z0-9-]{1,120}$/', $restaurant_slug)) {
            $toSlug = function ($n) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$n)), '-'); };
            $tokens = array_values(array_filter(explode('-', $restaurant_slug), 'strlen'));
            $st = $conn->prepare("SELECT restaurant_id, restaurant_name FROM users WHERE restaurant_name LIKE ? ORDER BY id DESC LIMIT 50");
            $st->execute(['%' . implode('%', $tokens) . '%']);
            $restaurant_id = null;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ($toSlug($r['restaurant_name']) === $restaurant_slug) { $restaurant_id = $r['restaurant_id']; break; }
            }
        }
        if ($restaurant_id) {
            $stmt = $conn->prepare("SELECT id, restaurant_name, restaurant_logo, email, phone, owner_name, address FROM users WHERE restaurant_id = ? LIMIT 1");
            $stmt->execute([$restaurant_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $restaurant_name = $user['restaurant_name'] ?: 'Restaurant';
                $restaurant_email = $user['email'] ?? '';
                $restaurant_phone = $user['phone'] ?? '';
                $restaurant_owner = $user['owner_name'] ?? '';
                $restaurant_address = $user['address'] ?? '';
                // same logo rules as header.php
                $logo = $user['restaurant_logo'] ?? '';
                if ($logo && strpos($logo, 'http') === 0) $restaurant_logo = $logo;
                elseif ($logo && strpos($logo, 'db:') === 0) $restaurant_logo = 'image.php?type=logo&id=' . urlencode($user['id']);
                elseif ($logo) $restaurant_logo = 'image.php?type=file&path=' . urlencode('../' . $logo);
                else $restaurant_logo = 'image.php?type=logo&id=' . urlencode($user['id']);
            }
            $stmt = $conn->prepare("SELECT primary_red FROM website_settings WHERE restaurant_id = ? LIMIT 1");
            $stmt->execute([$restaurant_id]);
            $theme = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($theme && preg_match('/^#[0-9a-fA-F]{3,8}$/', trim((string)$theme['primary_red']))) $accent = trim($theme['primary_red']);
            try {
                $stmt = $conn->prepare("SELECT content, updated_at FROM policy_pages WHERE restaurant_id = ? AND policy_type = ? LIMIT 1");
                $stmt->execute([$restaurant_id, $type]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && trim((string)($row['content'] ?? '')) !== '') {
                    $custom = $row['content'];
                    $updated = $row['updated_at'] ? strtotime($row['updated_at']) : null;
                }
            } catch (Exception $e) {
                // policy_pages table not created yet: default wording
            }
        }
    } catch (Exception $e) {
        error_log("Error loading restaurant data: " . $e->getMessage());
    }

    if ($custom !== null) {
        $body = formatPolicyContent($custom);
    } else {
        $fn = ['privacy' => 'getDefaultPrivacyPolicyHtml', 'terms' => 'getDefaultTermsOfServiceHtml', 'refund' => 'getDefaultRefundPolicyHtml', 'shipping' => 'getDefaultShippingPolicyHtml', 'cookie' => 'getDefaultCookiePolicyHtml'][$type];
        $body = $type === 'privacy'
            ? $fn($restaurant_name, $restaurant_owner, $restaurant_email, $restaurant_phone, restaurantPageUrl('cookie-policy'))
            : $fn($restaurant_name, $restaurant_owner, $restaurant_email, $restaurant_phone);
        $updated = @filemtime(__DIR__ . '/policy_defaults.php') ?: null; // when the standard wording last changed
    }
    $minutes = max(1, (int)round(str_word_count(strip_tags($body)) / 200));
    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $initials = '';
    foreach (preg_split('/\s+/', trim($restaurant_name)) as $w) { if ($w !== '' && strlen($initials) < 2) $initials .= mb_strtoupper(mb_substr($w, 0, 1)); }
    $tel = preg_replace('/[^0-9+]/', '', $restaurant_phone);
    $cssV = @filemtime(__DIR__ . '/policy_page.css') ?: 1;
    $jsV = @filemtime(__DIR__ . '/policy_page.js') ?: 1;
    require_once __DIR__ . '/font_links.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <base href="<?php echo $e($website_base_href); ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $e($meta['title'] . ' - ' . $restaurant_name); ?></title>
    <meta name="description" content="<?php echo $e($meta['lead']); ?>">
    <meta name="theme-color" content="<?php echo $e($accent); ?>">
    <?php echo websiteFontLinks(['poppins', 'inter', 'material-symbols']); ?>
    <link rel="stylesheet" href="policy_page.css?v=<?php echo $cssV; ?>">
    <style>:root { --pp-accent: <?php echo $e($accent); ?>; }</style>
</head>
<body class="pp">
    <div class="pp-progress" aria-hidden="true"><span></span></div>

    <header class="pp-top">
        <a class="pp-brand" href="<?php echo $e(restaurantPageUrl()); ?>">
            <span class="pp-logo"><?php if ($restaurant_logo): ?><img src="<?php echo $e($restaurant_logo); ?>" alt="" onerror="this.remove()"><?php endif; ?><b><?php echo $e($initials ?: 'R'); ?></b></span>
            <span class="pp-brand-name"><?php echo $e($restaurant_name); ?></span>
        </a>
        <a class="pp-btn pp-btn-ghost" href="<?php echo $e(restaurantPageUrl('menu')); ?>">
            <span class="material-symbols-rounded">restaurant_menu</span><span class="pp-hide-s">View menu</span>
        </a>
    </header>

    <section class="pp-hero">
        <div class="pp-wrap">
            <a class="pp-back" href="<?php echo $e(restaurantPageUrl()); ?>"><span class="material-symbols-rounded">arrow_back</span>Back to home</a>
            <div class="pp-hero-row">
                <span class="pp-hero-ic material-symbols-rounded"><?php echo $e($meta['icon']); ?></span>
                <div>
                    <h1><?php echo $e($meta['title']); ?></h1>
                    <p class="pp-lead"><?php echo $e($meta['lead']); ?></p>
                </div>
            </div>
            <div class="pp-meta">
                <?php if ($updated): ?><span><span class="material-symbols-rounded">update</span>Updated <?php echo $e(date('j F Y', $updated)); ?></span><?php endif; ?>
                <span><span class="material-symbols-rounded">schedule</span><?php echo $minutes; ?> min read</span>
                <button type="button" class="pp-print" onclick="window.print()"><span class="material-symbols-rounded">print</span>Print</button>
            </div>
            <nav class="pp-tabs" aria-label="Policies">
                <?php foreach ($types as $k => $t): ?>
                <a href="<?php echo $e(restaurantPageUrl($t['page'])); ?>"<?php echo $k === $type ? ' class="on" aria-current="page"' : ''; ?>><span class="material-symbols-rounded"><?php echo $e($t['icon']); ?></span><?php echo $e($t['tab']); ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
    </section>

    <main class="pp-wrap pp-main">
        <details class="pp-toc" id="ppToc" open hidden>
            <summary><span class="material-symbols-rounded">toc</span>On this page<span class="material-symbols-rounded pp-chev">expand_more</span></summary>
            <ol></ol>
        </details>

        <div class="pp-col">
            <article class="pp-card pp-body" id="ppBody">
                <?php echo $body; ?>
            </article>

            <?php if ($restaurant_email || $tel): ?>
            <section class="pp-card pp-help">
                <span class="pp-help-ic material-symbols-rounded">support_agent</span>
                <div class="pp-help-text">
                    <b>Questions about this policy?</b>
                    <span>Get in touch with <?php echo $e($restaurant_name); ?> and we will help you out.</span>
                </div>
                <div class="pp-help-actions">
                    <?php if ($tel): ?><a class="pp-btn" href="tel:<?php echo $e($tel); ?>"><span class="material-symbols-rounded">call</span>Call us</a><?php endif; ?>
                    <?php if ($restaurant_email): ?><a class="pp-btn pp-btn-ghost" href="mailto:<?php echo $e($restaurant_email); ?>"><span class="material-symbols-rounded">mail</span>Email us</a><?php endif; ?>
                </div>
            </section>
            <?php endif; ?>
        </div>
    </main>

    <footer class="pp-foot">
        <div class="pp-wrap">
            <nav aria-label="All policies">
                <?php foreach ($types as $k => $t): ?><a href="<?php echo $e(restaurantPageUrl($t['page'])); ?>"><?php echo $e($t['title']); ?></a><?php endforeach; ?>
            </nav>
            <p>&copy; <?php echo date('Y') . ' ' . $e($restaurant_name); ?><?php echo $restaurant_address ? ' &middot; ' . $e($restaurant_address) : ''; ?></p>
        </div>
    </footer>

    <button type="button" class="pp-up" id="ppUp" aria-label="Back to top"><span class="material-symbols-rounded">arrow_upward</span></button>
    <script src="policy_page.js?v=<?php echo $jsV; ?>" defer></script>
</body>
</html>
<?php
}

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
