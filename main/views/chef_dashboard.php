<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../config/env_loader.php';

// Require login and permission to view dashboard (Chef role)
requireLogin();
requirePermission(PERMISSION_VIEW_DASHBOARD);

// Verify user is a Chef, or a Bartender (the same screen with only the bar's tickets, BOT)
if (!isset($_SESSION['staff_id']) || !isset($_SESSION['restaurant_id']) || !in_array($_SESSION['role'], [ROLE_CHEF, ROLE_BARTENDER], true)) {
    header('Location: ../admin/login.php');
    exit();
}

$restaurant_id = $_SESSION['restaurant_id'];
$isBar = $_SESSION['role'] === ROLE_BARTENDER;

// Ticket timers run on the restaurant's clock, so the page tells the browser what time it is there.
$timezone = $_SESSION['timezone'] ?? null;
if ($timezone === null) {
    try {
        if (file_exists(__DIR__ . '/../db_connection.php')) {
            require_once __DIR__ . '/../db_connection.php';
            $conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
            if ($conn) {
                $tzStmt = $conn->prepare("SELECT timezone FROM users WHERE restaurant_id = ? LIMIT 1");
                $tzStmt->execute([$restaurant_id]);
                $timezone = $tzStmt->fetchColumn() ?: null;
            }
        }
    } catch (Exception $e) {
        // fall back below
    }
    if ($timezone) {
        $_SESSION['timezone'] = $timezone;
    }
}
require_once __DIR__ . '/../config/timezone_utils.php';
applyRestaurantTimezone($timezone ?: 'Asia/Kolkata');

$restaurant_name = $_SESSION['restaurant_name'] ?? 'Restaurant';
$staff_name = $_SESSION['username'] ?? 'Chef';
$name_parts = preg_split('/\s+/', trim($staff_name));
$initials = strtoupper(mb_substr($name_parts[0] ?? 'C', 0, 1) . (count($name_parts) > 1 ? mb_substr(end($name_parts), 0, 1) : ''));
$v = function ($f) { return @filemtime(__DIR__ . '/../assets/' . $f); };
?>
<!DOCTYPE html>
<html lang="en" class="st">
<head>
    <meta charset="UTF-8">
  <script src="../assets/js/quiet-console.js?v=<?php echo $v('js/quiet-console.js'); ?>"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <title><?php echo $isBar ? 'Bar' : 'Kitchen'; ?> – <?php echo htmlspecialchars($restaurant_name); ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">
    <link rel="preload" href="<?php require_once __DIR__ . '/../config/icon_font.php'; echo htmlspecialchars(rgIconFontUrl(), ENT_QUOTES); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo htmlspecialchars(rgIconFontUrl(), ENT_QUOTES); ?>"></noscript>
    <link rel="stylesheet" href="../assets/css/staff-ui.css?v=<?php echo $v('css/staff-ui.css'); ?>">

    <script>
        window.STAFF = {
            role: <?php echo json_encode($isBar ? 'Bartender' : 'Chef'); ?>,
            restaurantId: <?php echo json_encode($restaurant_id); ?>,
            serverNow: <?php echo json_encode(date('Y-m-d H:i:s')); ?>
        };
    </script>
    <script src="../website/rg-notify.js?v=<?php echo @filemtime(__DIR__ . '/../website/rg-notify.js'); ?>"></script>
    <script>RGNotify.setup({ toast: 'card' });</script>
</head>
<body class="st-body">
    <header class="st-top">
        <div class="st-top-in">
            <div class="st-brand">
                <span class="st-logo chef" aria-hidden="true"><span class="material-symbols-rounded"><?php echo $isBar ? 'local_bar' : 'skillet'; ?></span></span>
                <div class="st-brand-t">
                    <b><?php echo htmlspecialchars($restaurant_name); ?></b>
                    <small><?php echo $isBar ? 'Bar display' : 'Kitchen display'; ?></small>
                </div>
            </div>
            <div class="st-top-r">
                <span class="st-live" id="stLive"><i></i><span>Connecting…</span></span>
                <button type="button" class="st-ibtn st-hide-sm" id="stFullBtn" aria-label="Full screen" title="Full screen"><span class="material-symbols-rounded">desktop_windows</span></button>
                <button type="button" class="st-ibtn on" id="stSoundBtn" aria-pressed="true" aria-label="Sound on"><span class="material-symbols-rounded">campaign</span></button>
                <button type="button" class="st-alerts" id="enableAlertsBtn"><span class="material-symbols-rounded">notifications</span><span class="st-alerts-l">Turn on alerts</span></button>
                <div class="st-user">
                    <span class="st-av" aria-hidden="true"><?php echo htmlspecialchars($initials); ?></span>
                    <div class="st-user-t"><b><?php echo htmlspecialchars($staff_name); ?></b><small><?php echo $isBar ? 'Bartender' : 'Chef'; ?></small></div>
                </div>
                <a class="st-ibtn" id="stLogout" href="../controllers/staff_logout.php" aria-label="Log out" title="Log out"><span class="material-symbols-rounded">logout</span></a>
            </div>
        </div>
    </header>

    <main class="st-wrap">
        <div class="st-head">
            <div class="st-head-l">
                <span class="st-hello">Hi <?php echo htmlspecialchars($name_parts[0] ?? 'Chef'); ?> 👋</span>
                <h1><?php echo $isBar ? 'Bar orders' : 'Kitchen orders'; ?></h1>
            </div>
            <div class="st-head-r kds-toolbar">
                <span class="st-updated" id="lastRefresh">Auto-refreshing…</span>
                <button type="button" class="st-btn ghost" id="kdsRefresh"><span class="material-symbols-rounded">refresh</span>Refresh</button>
            </div>
        </div>

        <div class="kds-stats" id="kdsStats" aria-live="polite">
            <div class="st-skel h-stat"></div><div class="st-skel h-stat"></div><div class="st-skel h-stat"></div><div class="st-skel h-stat"></div>
        </div>
        <nav class="kds-stages" id="kdsStages" aria-label="Show tickets"></nav>
        <div class="kds-board" id="kotOrdersList"></div>
    </main>

    <script src="../assets/js/staff-common.js?v=<?php echo $v('js/staff-common.js'); ?>"></script>
    <script src="../assets/js/staff-kds.js?v=<?php echo $v('js/staff-kds.js'); ?>"></script>
    <script>
        (function () {
            var kds = StaffKDS.create({
                board: document.getElementById('kotOrdersList'),
                stages: document.getElementById('kdsStages'),
                stats: document.getElementById('kdsStats'),
                updated: document.getElementById('lastRefresh'),
                bar: <?php echo $isBar ? 'true' : 'false'; ?>,   // a bartender: bar tickets only, no Kitchen / Bar switch
                onCounts: function (c) { StaffUI.titleCount(c.pending); }
            }).start();

            document.getElementById('kdsRefresh').addEventListener('click', function () {
                var b = this; b.disabled = true;
                kds.refresh().catch(function () { StaffUI.toast('Could not refresh – check the connection', 'error'); }).then(function () { b.disabled = false; });
            });

            // Kitchen tablets: one tap to go full screen
            var fb = document.getElementById('stFullBtn');
            if (!document.fullscreenEnabled) fb.style.display = 'none';
            fb.addEventListener('click', function () {
                if (document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen().catch(function () { });
            });
            document.addEventListener('fullscreenchange', function () {
                fb.querySelector('.material-symbols-rounded').textContent = document.fullscreenElement ? 'close' : 'desktop_windows';
            });

            // names the old page exposed
            window.loadKOTOrders = kds.refresh;
            window.filterKOTs = kds.filter;
            window.updateKOTStatus = function (id, status) { kds.act(id, status, document.querySelector('[data-kds-act="' + id + '"]')); };
            window.completeKOT = function (id) { kds.act(id, 'Complete', document.querySelector('[data-kds-act="' + id + '"]')); };
        })();
    </script>
</body>
</html>
