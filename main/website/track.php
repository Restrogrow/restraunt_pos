<?php require_once __DIR__ . '/header.php'; ?>
<?php
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/order_abuse_guard.php';
require_once __DIR__ . '/../config/order_reasons.php';   // why an order was rejected / cancelled, in customer words
require_once __DIR__ . '/../config/order_eta.php';       // "Ready around" / "Arrives around" from the times the restaurant chose

$orderNumber = $_POST['order_number'] ?? $_GET['order_number'] ?? '';
$customerPhone = $_POST['customer_phone'] ?? $_GET['customer_phone'] ?? '';
// Orders are stored with a normalized 10-digit phone (see
// order_abuse_guard.php / process_website_order.php) — normalize the lookup
// the same way so "+91 98XXX" vs "98XXX" both find the order.
$customerPhone = orderAbuseNormalizePhone($customerPhone) ?? $customerPhone;
// links we send carry a code instead of the phone (config/order_track_link.php)
require_once __DIR__ . '/../config/order_track_link.php';
$trackCode = (string)($_GET['k'] ?? '');
if ($orderNumber && !$customerPhone && $trackCode !== '') {
    try { $customerPhone = orderTrackPhoneFromCode(getConnection(), (string)$orderNumber, $trackCode) ?? ''; } catch (Throwable $e) { $customerPhone = ''; }
}
$error = '';
$order = null;
$tracking = null;
$items = [];

if ($orderNumber && $customerPhone) {
    try {
        $conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
        if (!$conn) throw new Exception('No connection');

        $stmt = $conn->prepare("SELECT o.*, u.restaurant_name, u.currency_symbol, u.address as restaurant_address
            FROM orders o JOIN users u ON o.restaurant_id = u.restaurant_id
            WHERE o.order_number = ? AND o.customer_phone = ? AND o.source = 'website'
            LIMIT 1");
        require_once __DIR__ . '/../config/guess_guard.php';
        if (rgGuessBlocked()) {
            $order = false;
        } else {
            $stmt->execute([$orderNumber, $customerPhone]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) rgGuessMiss();
        }

        if (!$order) {
            $error = 'Order not found. Please check your order number and phone number.';
        } else {
            // Dish photo for the summary (dishes deleted since show without one)
            $itemsStmt = $conn->prepare("SELECT oi.item_name, oi.quantity, oi.unit_price, oi.total_price, mi.item_image FROM order_items oi LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id WHERE oi.order_id = ?");
            $itemsStmt->execute([$order['id']]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $trackStmt = $conn->prepare("SELECT * FROM delivery_tracking WHERE order_id = ? AND restaurant_id = ? LIMIT 1");
            $trackStmt->execute([$order['id'], $order['restaurant_id']]);
            $tracking = $trackStmt->fetch(PDO::FETCH_ASSOC);
            $etaText = orderEtaText(orderEta($conn, (int)$order['id']), (string)$order['order_status'], (string)$order['order_type'], $tracking['delivery_status'] ?? null);
        }
    } catch (Exception $e) {
        $error = 'Something went wrong. Please try again.';
        error_log("Track error: " . $e->getMessage());
    }
}

$currency = ($order['currency_symbol'] ?? '₹');
$statusLabels = [
    'Pending' => 'Order Placed', 'Accepted' => 'Accepted', 'Preparing' => 'Preparing',
    'Ready' => 'Ready', 'Served' => 'Served', 'Completed' => 'Completed',
    'Rejected' => 'Rejected', 'Cancelled' => 'Cancelled'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<base href="<?php echo $website_base_href; ?>">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="restaurant-id" content="<?php echo htmlspecialchars($restaurant_id ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<?php include __DIR__ . '/cart_scope.php'; ?>
<title>Track Order - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" href="<?php echo htmlspecialchars($favicon_href ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<?php require_once __DIR__ . '/font_links.php'; echo websiteFontLinks(['poppins', 'inter', 'material-symbols']); ?>
<?php if (!empty($font_family_google_param)) echo websiteThemeFontLink($font_family_google_param); ?>
<style>
/* ── Fun layer: animated scene per step, rotating lines, rider card, confetti ── */
.tk-scene { position: relative; height: 150px; margin-top: 18px; border-radius: 22px; overflow: hidden; background: linear-gradient(180deg, color-mix(in srgb, var(--tk) 10%, #fff), color-mix(in srgb, var(--tk) 22%, #fff)); }
.tk-scene .hero { position: absolute; left: 50%; top: 50%; width: 76px; height: 76px; margin: -46px 0 0 -38px; display: grid; place-items: center; border-radius: 24px; background: #fff; color: var(--tk-dark); box-shadow: 0 10px 24px color-mix(in srgb, var(--tk) 25%, transparent); z-index: 3; }
.tk-scene .hero .material-symbols-rounded { font-size: 42px; }
.tk-scene .fx { position: absolute; inset: 0; z-index: 2; pointer-events: none; }
.tk-scene .fx i { position: absolute; display: block; font-style: normal; }
/* placed: a receipt printing out of a slot */
.sc-placed .hero { animation: scPrint 2.4s ease-in-out infinite; }
.sc-placed .fx i.slot { left: 50%; top: 34px; width: 110px; height: 10px; margin-left: -55px; border-radius: 6px; background: var(--tk-dark); opacity: .85; }
@keyframes scPrint { 0% { transform: translateY(-18px) scale(.9); opacity: 0; } 25%, 80% { transform: none; opacity: 1; } 100% { transform: translateY(6px); opacity: 0; } }
/* accepted: thumbs up with rings */
.sc-accepted .hero { animation: scPop 1.8s ease-in-out infinite; }
.sc-accepted .fx i.ring { left: 50%; top: 50%; width: 76px; height: 76px; margin: -46px 0 0 -38px; border-radius: 24px; border: 3px solid var(--tk); animation: scRing 1.8s ease-out infinite; }
.sc-accepted .fx i.ring:nth-child(2) { animation-delay: .6s; }
@keyframes scPop { 0%, 100% { transform: scale(1); } 15% { transform: scale(1.12) rotate(-8deg); } 30% { transform: scale(1); } }
@keyframes scRing { from { transform: scale(1); opacity: .7; } to { transform: scale(1.9); opacity: 0; } }
/* preparing: flames under, steam rising */
.sc-preparing .hero { animation: scWobble 1.6s ease-in-out infinite; }
.sc-preparing .fx i.steam { top: 2px; width: 9px; height: 26px; border-radius: 10px; background: linear-gradient(transparent, color-mix(in srgb, var(--tk) 45%, #fff)); filter: blur(.6px); animation: scSteam 2.2s ease-in infinite; }
.sc-preparing .fx i.steam:nth-child(1) { left: calc(50% - 22px); }
.sc-preparing .fx i.steam:nth-child(2) { left: calc(50% - 5px); animation-delay: .7s; }
.sc-preparing .fx i.steam:nth-child(3) { left: calc(50% + 12px); animation-delay: 1.4s; }
.sc-preparing .fx i.flame { bottom: 16px; width: 14px; height: 22px; border-radius: 50% 50% 50% 50% / 60% 60% 40% 40%; background: radial-gradient(circle at 50% 70%, #fde047, #f97316 60%, #ef4444); animation: scFlame .5s ease-in-out infinite alternate; }
.sc-preparing .fx i.flame:nth-child(4) { left: calc(50% - 26px); }
.sc-preparing .fx i.flame:nth-child(5) { left: calc(50% - 7px); height: 28px; animation-delay: .15s; }
.sc-preparing .fx i.flame:nth-child(6) { left: calc(50% + 12px); animation-delay: .3s; }
@keyframes scWobble { 0%, 100% { transform: rotate(0); } 25% { transform: rotate(-5deg); } 75% { transform: rotate(5deg); } }
@keyframes scSteam { 0% { transform: translateY(16px) scaleY(.4); opacity: 0; } 35% { opacity: 1; } 100% { transform: translateY(-8px) scaleY(1.1) translateX(5px); opacity: 0; } }
@keyframes scFlame { from { transform: scaleY(.85) translateY(2px); } to { transform: scaleY(1.15); } }
/* packed / ready: bag bounces, sparkles */
.sc-packed .hero, .sc-ready .hero { animation: scBounce 1.2s cubic-bezier(.3,1.6,.5,1) infinite; }
.sc-packed .fx i.spark, .sc-ready .fx i.spark { width: 14px; height: 14px; background: var(--tk); clip-path: polygon(50% 0, 62% 38%, 100% 50%, 62% 62%, 50% 100%, 38% 62%, 0 50%, 38% 38%); animation: scTwinkle 1.6s ease-in-out infinite; }
.fx i.spark:nth-child(1) { left: 26%; top: 24%; } .fx i.spark:nth-child(2) { left: 70%; top: 20%; animation-delay: .4s; }
.fx i.spark:nth-child(3) { left: 22%; top: 66%; animation-delay: .8s; } .fx i.spark:nth-child(4) { left: 74%; top: 64%; animation-delay: 1.2s; }
@keyframes scBounce { 0%, 100% { transform: translateY(0); } 40% { transform: translateY(-14px); } }
@keyframes scTwinkle { 0%, 100% { transform: scale(.3) rotate(0); opacity: .2; } 50% { transform: scale(1.1) rotate(45deg); opacity: 1; } }
/* on the way: scooter on a moving road, town passing by */
.sc-onway .hero { width: 70px; height: 70px; margin: -30px 0 0 -35px; border-radius: 50%; animation: scRide .45s ease-in-out infinite alternate; }
.sc-onway .fx i.road { left: 0; right: 0; bottom: 0; height: 34px; background: #4b5563; }
.sc-onway .fx i.dash { left: 0; right: 0; bottom: 15px; height: 4px; background: repeating-linear-gradient(90deg, #fde047 0 26px, transparent 26px 52px); background-size: 52px 4px; animation: scRoad .6s linear infinite; }
.sc-onway .fx i.town { left: 0; right: 0; bottom: 34px; height: 46px; background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='46'%3E%3Cg fill='rgba(0,0,0,0.16)'%3E%3Crect x='8' y='14' width='24' height='32' rx='2'/%3E%3Crect x='38' y='2' width='18' height='44' rx='2'/%3E%3Crect x='62' y='22' width='30' height='24' rx='2'/%3E%3Crect x='98' y='8' width='16' height='38' rx='2'/%3E%3Crect x='120' y='18' width='28' height='28' rx='2'/%3E%3C/g%3E%3C/svg%3E") 0 0 / 160px 46px repeat-x; animation: scTown 6s linear infinite; }
.sc-onway .fx i.cloud { top: 16px; width: 54px; height: 18px; border-radius: 20px; background: #fff; opacity: .9; animation: scCloud 9s linear infinite; }
.sc-onway .fx i.cloud:nth-child(5) { top: 34px; width: 38px; animation-duration: 13s; animation-delay: -5s; }
@keyframes scRide { from { transform: translateY(0) rotate(-2deg); } to { transform: translateY(-3px) rotate(2deg); } }
@keyframes scRoad { to { background-position: -52px 0; } }
@keyframes scTown { from { background-position: 0 0; } to { background-position: -160px 0; } }
@keyframes scCloud { from { left: 105%; } to { left: -20%; } }
/* delivered / served: tick + falling confetti */
.sc-done .hero, .sc-delivered .hero { color: #16a34a; animation: scPop 2.4s ease-in-out infinite; }
.tk-scene .fx i.conf { top: -14px; width: 8px; height: 8px; border-radius: 2px; animation: scFall linear infinite; }
@keyframes scFall { to { transform: translateY(190px) rotate(540deg); } }
/* cancelled */
.sc-stop { background: #fef2f2; }
.sc-stop .hero { color: #b91c1c; animation: scShake 2.4s ease-in-out infinite; }
@keyframes scShake { 0%, 60%, 100% { transform: none; } 65%, 75% { transform: translateX(-5px); } 70%, 80% { transform: translateX(5px); } }

.tk-status-text { margin-top: 14px; text-align: center; }
.tk-status-text b { display: block; font-size: 17px; font-weight: 800; }
.tk-status-text p { margin-top: 4px; font-size: 13.5px; color: var(--muted); line-height: 1.45; }
.tk-tip { display: inline-flex; align-items: center; gap: 6px; margin-top: 10px; padding: 7px 14px; border-radius: 999px; background: #f4f5f7; font-size: 12.5px; font-weight: 600; color: var(--ink); transition: opacity .35s, transform .35s; }
.tk-tip .material-symbols-rounded { font-size: 16px; color: var(--tk); }
.tk-tip.out { opacity: 0; transform: translateY(4px); }
.tk-clock { display: flex; align-items: center; gap: 6px; margin: -8px 0 14px; font-size: 12px; color: var(--muted); }
.tk-clock .material-symbols-rounded { font-size: 15px; }

.tk-rider { display: none; align-items: center; gap: 12px; margin-top: 14px; padding: 12px; border-radius: 18px; background: #f4f5f7; }
.tk-rider.on { display: flex; animation: bubblePop .5s cubic-bezier(.2,1.4,.4,1); }
.tk-rider .av { width: 46px; height: 46px; flex-shrink: 0; display: grid; place-items: center; border-radius: 50%; background: linear-gradient(135deg, var(--tk), var(--tk-dark)); color: #fff; font-size: 18px; font-weight: 800; }
.tk-rider .who { flex: 1; min-width: 0; }
.tk-rider .who b { display: block; font-size: 14.5px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tk-rider .who span { font-size: 12px; color: var(--muted); }
.tk-call { width: 42px; height: 42px; flex-shrink: 0; display: grid; place-items: center; border-radius: 50%; background: #16a34a; color: #fff; text-decoration: none; box-shadow: 0 4px 12px rgba(22,163,74,.35); }
.tk-call .material-symbols-rounded { font-size: 20px; }
.tk-help { display: flex; gap: 10px; margin-bottom: 14px; }
.tk-help a { flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 12px; border-radius: 16px; background: #fff; color: var(--ink); font-size: 13.5px; font-weight: 600; text-decoration: none; box-shadow: 0 2px 10px rgba(17,24,39,.05); }
.tk-help a .material-symbols-rounded { font-size: 19px; color: var(--tk); }

/* Celebration burst when a step changes */
.tk-burst { position: fixed; inset: 0; pointer-events: none; z-index: 50; overflow: hidden; }
.tk-burst i { position: absolute; top: 40%; left: 50%; width: 9px; height: 9px; border-radius: 2px; animation: tkBurst 1.4s cubic-bezier(.15,.7,.3,1) forwards; }
@keyframes tkBurst { 0% { transform: translate(0, 0) rotate(0); opacity: 1; } 100% { transform: translate(var(--x), var(--y)) rotate(var(--r)); opacity: 0; } }

@media (prefers-reduced-motion: reduce) {
  .tk-scene *, .tk-scene .hero, .tk-burst i, .tk-rider.on { animation: none !important; }
}

:root {
  --primary-red: <?php echo htmlspecialchars($primary_red, ENT_QUOTES, 'UTF-8'); ?>;
  --dark-red: <?php echo htmlspecialchars($dark_red, ENT_QUOTES, 'UTF-8'); ?>;
  --primary-yellow: <?php echo htmlspecialchars($primary_yellow, ENT_QUOTES, 'UTF-8'); ?>;
  --site-font: <?php echo $font_family_css; ?>;
  /* Progress + main button use the restaurant's checkout colour */
  --tk: <?php echo htmlspecialchars($checkout_color ?? $primary_red, ENT_QUOTES, 'UTF-8'); ?>;
  --tk-dark: <?php echo htmlspecialchars($checkout_color_dark ?? $dark_red, ENT_QUOTES, 'UTF-8'); ?>;
  --tk-soft: color-mix(in srgb, var(--tk) 10%, #fff);
  --ink: #1b1d22; --muted: #7b808a; --line: #eef0f2; --page: #f4f5f7;
  /* one clean sans font for the whole tracking screen (amounts always have the currency sign) */
  --ui: 'Poppins', 'Inter', system-ui, sans-serif;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--ui); background: #e7e9ec; color: var(--ink); min-height: 100vh; overflow-x: hidden; -webkit-font-smoothing: antialiased; }
.phone-frame { max-width: 425px; margin: 0 auto; min-height: 100vh; position: relative; background: var(--page); box-shadow: 0 0 40px rgba(0,0,0,0.08); }
@media (min-width: 768px) { .phone-frame { margin: 20px auto; min-height: calc(100vh - 40px); border-radius: 32px; overflow: hidden; } }

/* Round buttons floating over the top area */
.tk-top { position: absolute; z-index: 5; top: 0; left: 0; right: 0; display: flex; align-items: center; justify-content: space-between; padding: 14px 14px 0; pointer-events: none; }
.tk-top > * { pointer-events: auto; }
.tk-round { width: 40px; height: 40px; flex-shrink: 0; display: grid; place-items: center; border: 0; border-radius: 50%; background: #fff; color: var(--ink); cursor: pointer; box-shadow: 0 2px 10px rgba(0,0,0,.1); text-decoration: none; transition: transform .15s; }
.tk-round:active { transform: scale(.94); }
.tk-round .material-symbols-rounded { font-size: 20px; }
.tk-actions { display: flex; gap: 8px; }

/* Top area: animated scene, or the rider's map once there is a location */
.tk-hero { position: relative; height: 250px; overflow: hidden; background: linear-gradient(180deg, color-mix(in srgb, var(--tk) 16%, #fff), color-mix(in srgb, var(--tk) 7%, #fff)); }
.tk-hero .tk-scene { position: absolute; inset: 0; height: auto; margin: 0; border-radius: 0; background: transparent; }
.tk-hero .tk-scene .hero { width: 96px; height: 96px; margin: -36px 0 0 -48px; border-radius: 50%; color: var(--tk); box-shadow: 0 0 0 12px color-mix(in srgb, var(--tk) 10%, transparent), 0 14px 30px color-mix(in srgb, var(--tk) 25%, transparent); }
.tk-hero .tk-scene .hero svg { width: 44px; height: 44px; }
.tk-hero .sc-onway .hero { width: 84px; height: 84px; margin: -20px 0 0 -42px; }
.tk-hero .sc-placed .fx i.slot { top: 64px; }
.tk-hero .sc-preparing .fx i.steam { top: 40px; }
.tk-hero .sc-preparing .fx i.flame { bottom: 54px; }
.tk-hero .sc-done .hero, .tk-hero .sc-delivered .hero { color: #16a34a; box-shadow: 0 0 0 12px rgba(22,163,74,.12), 0 14px 30px rgba(22,163,74,.25); animation: heroDone 2.4s ease-in-out infinite; }
.tk-hero .sc-stop .hero { color: #dc2626; box-shadow: 0 0 0 12px rgba(220,38,38,.1), 0 14px 30px rgba(220,38,38,.2); }
.tk-hero:has(.sc-stop) { background: linear-gradient(180deg, #fee2e2, #fff5f5); }
@keyframes heroDone { 0%, 100% { transform: scale(1); } 15% { transform: scale(1.1) rotate(-6deg); } 30% { transform: scale(1); } }
.tk-hero.has-map .tk-scene { display: none; }
#trackMap { position: absolute; inset: 0; display: none; }
.tk-hero.has-map #trackMap { display: block; }

/* White sheet over the top area */
.tk-sheet { position: relative; z-index: 2; margin-top: -26px; padding: 22px 18px 18px; border-radius: 28px 28px 0 0; background: #fff; }
.tk-sheet::before { content: ""; position: absolute; top: 8px; left: 50%; width: 38px; height: 4px; margin-left: -19px; border-radius: 4px; background: #e2e4e8; }
.tk-rest { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
.tk-rest img { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; border: 1px solid var(--line); }
.tk-rest .nm { flex: 1; min-width: 0; }
.tk-rest .nm b { display: block; font-size: 13.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tk-rest .nm small { display: block; font-size: 11.5px; color: var(--muted); }
.status-badge { flex-shrink: 0; display: inline-flex; align-items: center; gap: 6px; padding: 5px 11px; border-radius: 999px; font-size: 11.5px; font-weight: 600; background: var(--tk-soft); color: var(--tk-dark); white-space: nowrap; }
.status-badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.status-rejected, .status-cancelled { background: #fee2e2; color: #b91c1c; }
.status-completed, .status-served { background: #dcfce7; color: #15803d; }
.status-badge.status-flash { animation: statusFlash 1.2s ease-out 2; }
@keyframes statusFlash { 0% { transform: scale(1); } 30% { transform: scale(1.1); } 100% { transform: scale(1); } }

.tk-status-text { text-align: left; }
.tk-status-text b { display: block; font-size: 21px; font-weight: 700; line-height: 1.25; letter-spacing: -.01em; }
.tk-status-text p { margin-top: 4px; font-size: 13.5px; color: var(--muted); line-height: 1.45; }
.tk-eta { display: inline-flex; align-items: center; gap: 6px; margin-top: 10px; padding: 6px 12px; border-radius: 10px; background: var(--tk-soft); color: var(--tk-dark); font-size: 13px; font-weight: 600; }
.tk-eta svg { width: 16px; height: 16px; }

/* Progress bar + "next" line */
.tk-bar { margin-top: 16px; }
.tk-bar .rail { height: 6px; border-radius: 6px; background: #eceef1; overflow: hidden; }
.tk-bar .fill { position: relative; height: 100%; width: 0; min-width: 8%; border-radius: 6px; background: var(--tk); transition: width 1s cubic-bezier(.22,1,.36,1); overflow: hidden; }
.tk-bar .fill::after { content: ""; position: absolute; inset: 0; background: linear-gradient(100deg, transparent 20%, rgba(255,255,255,.55) 50%, transparent 80%); background-size: 200% 100%; animation: tkShine 1.8s linear infinite; }
@keyframes tkShine { from { background-position: 150% 0; } to { background-position: -50% 0; } }
.tk-bar-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 8px; font-size: 12px; color: var(--muted); }
.tk-next b { color: var(--ink); font-weight: 600; }
.tk-tip { display: inline-flex; align-items: center; gap: 5px; min-width: 0; transition: opacity .35s, transform .35s; }
.tk-tip svg { width: 14px; height: 14px; flex-shrink: 0; color: var(--tk); }
.tk-tip span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tk-tip.out { opacity: 0; transform: translateY(3px); }
.tk-bubble.pop { animation: bubblePop .5s cubic-bezier(.2,1.4,.4,1); }
@keyframes bubblePop { from { transform: translateY(4px); opacity: .6; } to { transform: none; opacity: 1; } }

/* Timeline */
.tk-steps { position: relative; margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--line); }
.tk-step { position: relative; display: flex; align-items: center; gap: 12px; padding: 7px 0; }
.tk-step:not(:last-child)::after { content: ""; position: absolute; left: 15px; top: 39px; bottom: -7px; width: 2px; margin-left: -1px; background: #e5e7eb; }
.tk-step.done:not(:last-child)::after { background: var(--tk); }
.tk-step i { position: relative; z-index: 1; width: 30px; height: 30px; flex-shrink: 0; display: grid; place-items: center; border-radius: 50%; background: #f1f2f4; color: #a3a8b0; font-style: normal; transition: background .3s, color .3s; }
.tk-step i svg { width: 16px; height: 16px; }
.tk-step span { font-size: 14px; font-weight: 500; color: #a3a8b0; }
.tk-step.done i { background: var(--tk); color: #fff; }
.tk-step.done span { color: var(--ink); }
.tk-step.on i { background: #fff; color: var(--tk); box-shadow: 0 0 0 2px var(--tk), 0 0 0 7px color-mix(in srgb, var(--tk) 14%, transparent); animation: stepPulse 1.8s ease-out infinite; }
.tk-step.on span { color: var(--ink); font-weight: 700; }
.tk-step.on.final i { background: #16a34a; color: #fff; box-shadow: none; animation: none; }
@keyframes stepPulse { 50% { box-shadow: 0 0 0 2px var(--tk), 0 0 0 10px color-mix(in srgb, var(--tk) 6%, transparent); } }
.tk-stopped .tk-status-text b { color: #b91c1c; }

.tk-clock { display: flex; align-items: center; gap: 5px; margin-top: 14px; font-size: 12px; color: var(--muted); }
.tk-clock .material-symbols-rounded { font-size: 15px; }

/* Rider + help */
.tk-rider { display: none; align-items: center; gap: 12px; margin-top: 16px; padding: 12px; border: 1px solid var(--line); border-radius: 16px; }
.tk-rider.on { display: flex; animation: bubblePop .5s cubic-bezier(.2,1.4,.4,1); }
.tk-rider .av { width: 42px; height: 42px; flex-shrink: 0; display: grid; place-items: center; border-radius: 50%; background: var(--tk-soft); color: var(--tk-dark); font-size: 16px; font-weight: 700; }
.tk-rider .who { flex: 1; min-width: 0; }
.tk-rider .who b { display: block; font-size: 14px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tk-rider .who span { font-size: 12px; color: var(--muted); }
.tk-call { width: 40px; height: 40px; flex-shrink: 0; display: grid; place-items: center; border-radius: 50%; background: var(--tk); color: #fff; text-decoration: none; }
.tk-call .material-symbols-rounded { font-size: 19px; }
#riderLocationNote { display: none; margin-top: 8px; font-size: 11.5px; color: var(--muted); }
.tk-help { display: flex; gap: 10px; margin-top: 16px; }
.tk-help a { flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 11px; border: 1px solid var(--line); border-radius: 14px; background: #fff; color: var(--ink); font-size: 13px; font-weight: 600; text-decoration: none; }
.tk-help a .material-symbols-rounded { font-size: 18px; color: var(--tk); }
.loyalty-note { display: none; margin-top: 14px; padding: 10px 14px; border-radius: 14px; background: color-mix(in srgb, var(--primary-yellow) 22%, #fff); font-size: 13px; font-weight: 600; color: #7c4a03; }
.loyalty-note .material-symbols-rounded { font-size: 17px; vertical-align: -3px; }

.live-note { display: flex; align-items: center; justify-content: center; gap: 6px; font-size: 11px; color: var(--muted); margin: 14px 0 0; }
.live-dot { width: 7px; height: 7px; border-radius: 50%; background: #16a34a; }
.live-note.on .live-dot { animation: livePulse 1.6s ease-out infinite; }
.live-note.offline .live-dot { background: #f59e0b; }
.live-note.done .live-dot { background: #9ca3af; }
@keyframes livePulse { 0% { box-shadow: 0 0 0 0 rgba(22,163,74,.55); } 100% { box-shadow: 0 0 0 7px rgba(22,163,74,0); } }

/* Details below the sheet */
.content { padding: 10px 14px 28px; }
.card { background: #fff; border-radius: 20px; padding: 16px; margin-bottom: 10px; }
.tk-fold summary { display: flex; align-items: center; gap: 12px; list-style: none; cursor: pointer; }
.tk-fold summary::-webkit-details-marker { display: none; }
.tk-fold .f-ic { width: 36px; height: 36px; flex-shrink: 0; display: grid; place-items: center; border-radius: 12px; background: var(--tk-soft); color: var(--tk-dark); }
.tk-fold .f-ic .material-symbols-rounded { font-size: 19px; }
.tk-fold .f-t { flex: 1; min-width: 0; }
.tk-fold .f-t b { display: block; font-size: 14.5px; font-weight: 600; }
.tk-fold .f-t small { display: block; font-size: 12px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tk-fold .chev { color: var(--muted); transition: transform .25s; }
.tk-fold[open] .chev { transform: rotate(180deg); }
.tk-fold .f-body { margin-top: 12px; padding-top: 6px; border-top: 1px solid var(--line); }
.info-row { display: flex; justify-content: space-between; gap: 12px; padding: 6px 0; font-size: 13.5px; }
.label { color: var(--muted); }
.value { font-weight: 600; color: var(--ink); text-align: right; font-variant-numeric: tabular-nums; }
.tk-item { display: flex; align-items: center; gap: 12px; padding: 9px 0; }
.tk-item + .tk-item { border-top: 1px dashed var(--line); }
.tk-thumb { width: 48px; height: 48px; flex-shrink: 0; border-radius: 12px; overflow: hidden; background: #f4f5f7; display: grid; place-items: center; color: #c4c8ce; }
.tk-thumb img { width: 100%; height: 100%; object-fit: cover; }
.tk-item-info { flex: 1; min-width: 0; }
.tk-item-info b { display: block; font-size: 14px; font-weight: 500; line-height: 1.3; }
.tk-item-info span { font-size: 12px; color: var(--muted); }
.tk-item-price { font-size: 14px; font-weight: 600; white-space: nowrap; font-variant-numeric: tabular-nums; }
.tk-totals { margin-top: 8px; padding-top: 6px; border-top: 1px solid var(--line); }
.tk-grand { margin-top: 4px; padding-top: 10px; border-top: 1px dashed #d7dade; }
.tk-grand .label { color: var(--ink); font-weight: 600; }
.tk-grand .label small { font-size: 11px; font-weight: 400; color: var(--muted); margin-left: 4px; }
.tk-grand .value { font-size: 16px; font-weight: 700; }

/* Buttons */
.tk-cta { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 15px; border: 0; border-radius: 14px; background: var(--tk); color: #fff; font: 600 15px var(--ui); cursor: pointer; text-decoration: none; transition: transform .15s, filter .15s; }
.tk-cta:hover { filter: brightness(1.06); }
.tk-cta:active { transform: scale(.98); }
.tk-cta .material-symbols-rounded { font-size: 20px; }
.tk-cta.ghost { margin-top: 6px; background: #fff; color: var(--tk-dark); border: 1.5px solid color-mix(in srgb, var(--tk) 30%, #fff); }
.tk-another { display: flex; align-items: center; justify-content: center; gap: 4px; margin: 14px auto 0; font-size: 13px; font-weight: 500; color: var(--muted); text-decoration: none; }

/* Search form (no order yet) */
.tk-find { padding: 70px 18px 24px; text-align: center; background: linear-gradient(180deg, color-mix(in srgb, var(--tk) 14%, #fff), var(--page)); }
.tk-find img { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; background: #fff; box-shadow: 0 8px 20px color-mix(in srgb, var(--tk) 20%, transparent); }
.tk-find .tk-find-ic { width: 64px; height: 64px; margin: 0 auto; display: grid; place-items: center; border-radius: 50%; background: #fff; color: var(--tk); box-shadow: 0 8px 20px color-mix(in srgb, var(--tk) 20%, transparent); }
.tk-find .tk-find-ic .material-symbols-rounded { font-size: 32px; }
.tk-find h1 { margin-top: 14px; font-size: 22px; font-weight: 700; }
.tk-find p { margin-top: 4px; font-size: 13px; color: var(--muted); }
.tk-search { padding: 20px 18px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; font-size: 12.5px; font-weight: 600; margin-bottom: 6px; color: var(--ink); }
.tk-field { position: relative; }
.tk-field .material-symbols-rounded { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); font-size: 20px; color: var(--muted); pointer-events: none; transition: color .2s; }
.form-group input { width: 100%; padding: 14px 16px 14px 44px; border: 1.5px solid var(--line); border-radius: 14px; background: #f8f9fa; font-size: 15px; font-family: var(--ui); outline: none; transition: border-color .2s, background .2s, box-shadow .2s; }
.form-group input:focus { border-color: var(--tk); background: #fff; box-shadow: 0 0 0 4px color-mix(in srgb, var(--tk) 12%, transparent); }
.tk-field:focus-within .material-symbols-rounded { color: var(--tk); }
.tk-hint { display: flex; gap: 8px; margin-top: 14px; font-size: 12px; color: var(--muted); line-height: 1.45; text-align: left; }
.tk-hint .material-symbols-rounded { font-size: 17px; flex-shrink: 0; }
.error-box { margin-top: 14px; padding: 12px; border-radius: 12px; background: #fef2f2; color: #991b1b; font-size: 13.5px; text-align: center; }

@media (prefers-reduced-motion: reduce) {
  .live-note.on .live-dot, .status-badge.status-flash, .tk-bar .fill::after, .tk-step.on i, .tk-bubble.pop { animation: none; }
  .tk-bar .fill { transition: none; }
}
/* Scratch card (Offers > Scratch card) */
.sc-card { padding: 16px; overflow: hidden; }
.sc-head { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.sc-ic { width: 38px; height: 38px; flex-shrink: 0; display: grid; place-items: center; border-radius: 12px; background: linear-gradient(135deg, #f59e0b, #f43f5e); color: #fff; box-shadow: 0 6px 14px rgba(244,63,94,.3); animation: scWiggle 2.8s ease-in-out infinite; }
.sc-ic svg { width: 20px; height: 20px; }
@keyframes scWiggle { 0%, 80%, 100% { transform: rotate(0); } 85% { transform: rotate(-10deg); } 90% { transform: rotate(10deg); } 95% { transform: rotate(-5deg); } }
.sc-head b { display: block; font-size: 15px; font-weight: 800; color: #111827; }
.sc-head small { display: block; font-size: 12px; color: #6b7280; }
.sc-area { position: relative; height: 150px; border-radius: 18px; overflow: hidden; background: radial-gradient(circle at 30% 20%, #fff7ed, #ffe4e6 60%, #fce7f3); touch-action: none; user-select: none; -webkit-user-select: none; }
.sc-area canvas { position: absolute; inset: 0; width: 100%; height: 100%; cursor: grab; transition: opacity .6s ease, transform .6s ease; }
.sc-area canvas.gone { opacity: 0; transform: scale(1.08); pointer-events: none; }
.sc-hint { position: absolute; left: 50%; bottom: 12px; transform: translateX(-50%); padding: 4px 10px; border-radius: 999px; background: rgba(17,24,39,.75); color: #fff; font-size: 11px; font-weight: 700; pointer-events: none; animation: scHint 1.6s ease-in-out infinite; }
.sc-area.scratching .sc-hint, .sc-area.open .sc-hint { display: none; }
@keyframes scHint { 50% { transform: translateX(-50%) translateY(-3px); } }
#scUnder, .sc-prize { position: absolute; inset: 0; }
.sc-prize { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; text-align: center; padding: 10px; }
.sc-prize small { font-size: 12px; font-weight: 700; color: #9f1239; text-transform: uppercase; letter-spacing: .08em; }
.sc-prize.win b { font-size: 38px; line-height: 1.05; font-weight: 900; background: linear-gradient(135deg, #f43f5e, #f59e0b); -webkit-background-clip: text; background-clip: text; color: transparent; }
.sc-area.open .sc-prize.win b { animation: scPop .6s cubic-bezier(.2,1.6,.4,1) both; }
@keyframes scPop { from { transform: scale(.5); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.sc-prize span { font-size: 13px; color: #4b5563; font-weight: 600; }
.sc-prize.lose b { font-size: 22px; font-weight: 800; color: #111827; }
.sc-prize.wait { background: rgba(255,255,255,.4); }
.sc-spin { width: 26px; height: 26px; border-radius: 50%; border: 3px solid #fecdd3; border-top-color: #f43f5e; animation: scSpin .8s linear infinite; }
@keyframes scSpin { to { transform: rotate(360deg); } }
.sc-area.locked { background: repeating-linear-gradient(135deg, #f3f4f6 0 14px, #e5e7eb 14px 28px); }
.sc-lockface { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; color: #374151; }
.sc-lockface i { width: 40px; height: 40px; display: grid; place-items: center; border-radius: 50%; background: #fff; box-shadow: 0 4px 12px rgba(0,0,0,.08); margin-bottom: 4px; animation: scBreath 2s ease-in-out infinite; }
.sc-lockface i svg { width: 20px; height: 20px; }
@keyframes scBreath { 50% { transform: scale(1.08); } }
.sc-lockface b { font-size: 15px; }
.sc-lockface span { font-size: 12px; color: #6b7280; }
.sc-bit { position: absolute; top: 50%; width: 8px; height: 12px; border-radius: 2px; pointer-events: none; animation: scBurst 1.4s cubic-bezier(.15,.7,.4,1) forwards; }
@keyframes scBurst { from { transform: translate(0, 0) rotate(0); opacity: 1; } to { transform: translate(var(--dx), calc(var(--dy) + 220px)) rotate(var(--r)); opacity: 0; } }
.sc-won { margin-top: 12px; }
.sc-code { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 10px 10px 14px; border: 2px dashed #fda4af; border-radius: 14px; background: #fff1f2; }
.sc-code span { font-size: 18px; font-weight: 900; letter-spacing: .12em; color: #9f1239; }
.sc-copy { padding: 7px 14px; border: 0; border-radius: 10px; background: #111827; color: #fff; font: inherit; font-size: 12px; font-weight: 800; cursor: pointer; }
.sc-won p { margin: 8px 2px 10px; font-size: 12px; color: #6b7280; line-height: 1.45; }
.sc-use { width: 100%; padding: 13px; border: 0; border-radius: 14px; background: linear-gradient(135deg, #f43f5e, #f59e0b); color: #fff; font: inherit; font-size: 14px; font-weight: 800; cursor: pointer; box-shadow: 0 8px 18px rgba(244,63,94,.28); }
.sc-use.saved { background: #16a34a; box-shadow: none; }
.sc-odds { margin-top: 10px; font-size: 12px; color: #6b7280; }
.sc-odds summary { cursor: pointer; font-weight: 600; }
.sc-odds ul { list-style: none; margin: 6px 0 0; padding: 0; }
.sc-odds li { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid #f3f4f6; }
.sc-odds li b { color: #111827; }
@media (prefers-reduced-motion: reduce) { .sc-ic, .sc-hint, .sc-lockface i, .sc-area.open .sc-prize.win b { animation: none; } }
</style>
</head>
<body>
<div class="phone-frame">
  <div class="tk-top">
    <button class="tk-round" aria-label="Back" onclick="window.location.href='<?php echo restaurantPageUrl(); ?>'"><span class="material-symbols-rounded">arrow_back_ios_new</span></button>
    <div class="tk-actions">
      <button class="tk-round" aria-label="Menu" onclick="window.location.href='<?php echo restaurantPageUrl('menu'); ?>'"><span class="material-symbols-rounded">restaurant_menu</span></button>
      <button class="tk-round" aria-label="Home" onclick="window.location.href='<?php echo restaurantPageUrl(); ?>'"><span class="material-symbols-rounded">home</span></button>
    </div>
  </div>

  <?php if (!$order): ?>
    <div class="tk-find">
      <?php if (!empty($restaurant_logo)): ?><img src="<?php echo htmlspecialchars($restaurant_logo_img, ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php else: ?><div class="tk-find-ic"><span class="material-symbols-rounded">local_shipping</span></div><?php endif; ?>
      <h1>Track your order</h1>
      <p>Live status from <?php echo htmlspecialchars($restaurant_name ?? 'the restaurant', ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <div class="content">
      <div class="card tk-search">
        <form method="get"><!-- GET: a refresh shows the order again instead of asking to resend the form -->
          <div class="form-group">
            <label for="tkOrderNo">Order number</label>
            <div class="tk-field"><span class="material-symbols-rounded">receipt_long</span><input type="text" id="tkOrderNo" name="order_number" placeholder="e.g. ORD-20261012-1234" value="<?php echo htmlspecialchars($orderNumber, ENT_QUOTES, 'UTF-8'); ?>" required></div>
          </div>
          <div class="form-group">
            <label for="tkPhone">Phone number</label>
            <div class="tk-field"><span class="material-symbols-rounded">call</span><input type="tel" id="tkPhone" name="customer_phone" placeholder="Phone used at checkout" value="<?php echo htmlspecialchars($customerPhone, ENT_QUOTES, 'UTF-8'); ?>" required></div>
          </div>
          <button type="submit" class="tk-cta">Track order</button>
        </form>
        <?php if ($error): ?><div class="error-box"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <div class="tk-hint"><span class="material-symbols-rounded">info</span><span>Your order number is on the confirmation screen and in the message we sent after you ordered.</span></div>
      </div>
    </div>
  <?php else:
    $tkType = $order['order_type'];
  ?><?php
    // Drawn icons for the progress section (no icon font needed); keys = the names used below
    $tkIconPaths = [
        'receipt_long' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        'thumb_up' => '<path d="M7 11v9H4v-9z"/><path d="M7 11l4-8c1.1 0 2 .9 2 2v4h5.2a2 2 0 0 1 2 2.4l-1.3 6.4A2 2 0 0 1 16.9 20H7"/>',
        'skillet' => '<path d="M3 11h13a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5z"/><path d="M16 11h5"/><path d="M7 3c0 1.5 1.2 1.5 1.2 3S7 7.5 7 9M11 3c0 1.5 1.2 1.5 1.2 3S11 7.5 11 9"/>',
        'inventory_2' => '<path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4M12 11v10"/>',
        'shopping_bag' => '<path d="M5 8h14l-1.2 12H6.2z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        'two_wheeler' => '<circle cx="5.5" cy="17" r="3"/><circle cx="18.5" cy="17" r="3"/><path d="M5.5 17h7l3-7h3M15 6h2.5l1 4M9 10h4"/>',
        'where_to_vote' => '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><path d="M9.5 10l2 2 3.5-3.5"/>',
        'front_hand' => '<path d="M7 13V6.5a1.5 1.5 0 0 1 3 0V11M10 11V5a1.5 1.5 0 0 1 3 0v6M13 11V6.5a1.5 1.5 0 0 1 3 0V13M16 13v-2.5a1.5 1.5 0 0 1 3 0V15a6 6 0 0 1-6 6h-1a6 6 0 0 1-5.2-3L5 14.5a1.5 1.5 0 0 1 2.4-1.8L9 14"/>',
        'room_service' => '<path d="M3 18h18M5 18a7 7 0 0 1 14 0M12 8V6M10 6h4"/>',
        'restaurant' => '<path d="M7 3v8M5 3v5a2 2 0 0 0 4 0V3M7 11v10M17 3c-2 0-3 2.5-3 6h3v12"/>',
        'check_circle' => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.7 2.7L16.5 9.5"/>',
        'check' => '<path d="M20 6L9 17l-5-5"/>',
        'schedule' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'cancel' => '<circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/>',
        'sparkle' => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/>',
    ];
    $tkIcon = function ($k) use ($tkIconPaths) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($tkIconPaths[$k] ?? $tkIconPaths['check']) . '</svg>';
    };
    // Timeline steps (labels + icons) for this kind of order
    if ($tkType === 'Delivery') {
        $tkSteps = [['Order placed', 'receipt_long'], ['Preparing', 'skillet'], ['On the way', 'two_wheeler'], ['Delivered', 'where_to_vote']];
    } elseif ($tkType === 'Takeaway') {
        $tkSteps = [['Order placed', 'receipt_long'], ['Preparing', 'skillet'], ['Ready for pickup', 'shopping_bag'], ['Collected', 'front_hand']];
    } else {
        $tkSteps = [['Order placed', 'receipt_long'], ['Preparing', 'skillet'], ['Ready', 'room_service'], ['Served', 'restaurant']];
    }
    $itemCount = 0;
    foreach ($items as $it) $itemCount += (int)$it['quantity'];
  ?>
    <div class="tk-hero" id="tkHero">
      <div class="tk-scene sc-placed" id="tkScene" aria-hidden="true">
        <div class="fx" id="tkSceneFx"></div>
        <div class="hero" id="tkStatusIcon"><?php echo $tkIcon('receipt_long'); ?></div>
      </div>
      <?php if ($tkType === 'Delivery'): ?><div id="trackMap"></div><?php endif; ?>
    </div>

    <div class="tk-sheet" id="tkMain">
      <div class="tk-rest">
        <?php if (!empty($restaurant_logo)): ?><img src="<?php echo htmlspecialchars($restaurant_logo_img, ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php endif; ?>
        <div class="nm"><b><?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></b><small>#<?php echo htmlspecialchars($order['order_number']); ?> · <?php echo htmlspecialchars($tkType); ?></small></div>
        <div class="status-badge status-<?php echo strtolower($order['order_status']); ?>" id="orderStatusBadge"><span id="orderStatusText"><?php echo htmlspecialchars($statusLabels[$order['order_status']] ?? $order['order_status']); ?></span></div>
      </div>

      <div class="tk-status-text">
        <b id="tkStatusTitle">We've got your order</b>
        <p id="tkStatusText">The restaurant will confirm it shortly.</p>
        <span class="tk-eta" id="tkEta" style="display:<?php echo !empty($etaText) ? 'inline-flex' : 'none'; ?>"><?php echo $tkIcon('schedule'); ?><span id="tkEtaText"><?php echo htmlspecialchars($etaText ?? '', ENT_QUOTES, 'UTF-8'); ?></span></span>
      </div>

      <div id="tkProgress">
        <div class="tk-bar tk-bubble" id="tkBubble">
          <div class="rail"><div class="fill" id="tkFill"></div></div>
          <div class="tk-bar-row">
            <span class="tk-tip" id="tkTip"><?php echo $tkIcon('sparkle'); ?><span id="tkTipText">Sending your order to the kitchen</span></span>
            <span class="tk-next" id="tkNext">Next: <b id="tkNextText">Preparing</b></span>
          </div>
        </div>
      </div>

      <div class="tk-steps" role="progressbar" aria-valuemin="1" aria-valuemax="4" id="tkTrack">
        <?php foreach ($tkSteps as $st): ?><div class="tk-step" data-icon="<?php echo $st[1]; ?>"><i><?php echo $tkIcon($st[1]); ?></i><span><?php echo htmlspecialchars($st[0]); ?></span></div><?php endforeach; ?>
      </div>

      <div class="tk-clock"><span class="material-symbols-rounded">schedule</span><span>Placed at <?php echo htmlspecialchars(date('g:i A', strtotime($order['created_at']))); ?></span><span id="tkAgo"></span></div>

      <?php if ($tkType === 'Delivery'): ?>
      <div class="tk-rider<?php echo ($tracking && !empty($tracking['rider_name'])) ? ' on' : ''; ?>" id="tkRider">
        <div class="av" id="tkRiderInitial"><?php echo htmlspecialchars(mb_strtoupper(mb_substr((string)($tracking['rider_name'] ?? 'R'), 0, 1))); ?></div>
<?php
        // booked with an outside app (Order details › Book a delivery partner, api/delivery_partner.php)
        $tkPartners = ['uber' => 'Uber', 'rapido' => 'Rapido', 'porter' => 'Porter', 'borzo' => 'Borzo', 'ola' => 'Ola', 'shiprocket' => 'Shiprocket Quick', 'other' => ''];
        $tkPartner = $tkPartners[(string)($tracking['partner'] ?? '')] ?? '';
        $tkPartnerUrl = (string)($tracking['partner_track_url'] ?? '');
        if (!preg_match('#^https?://#i', $tkPartnerUrl)) $tkPartnerUrl = '';
?>
        <div class="who"><b id="tkRiderName"><?php echo htmlspecialchars((string)($tracking['rider_name'] ?? '')); ?></b><span><?php echo $tkPartner !== '' ? 'Delivering with ' . htmlspecialchars($tkPartner) : 'Your delivery partner'; ?><?php if ($tkPartnerUrl !== ''): ?> · <a href="<?php echo htmlspecialchars($tkPartnerUrl); ?>" target="_blank" rel="noopener nofollow" style="color:inherit;font-weight:600;text-decoration:underline;">Live tracking</a><?php endif; ?></span></div>
        <a class="tk-call" id="tkRiderCall" href="<?php echo !empty($tracking['rider_phone']) ? 'tel:' . htmlspecialchars(preg_replace('/[^\d+]/', '', $tracking['rider_phone'])) : '#'; ?>" style="<?php echo !empty($tracking['rider_phone']) ? '' : 'display:none;'; ?>" aria-label="Call rider"><span class="material-symbols-rounded">call</span></a>
      </div>
      <p id="riderLocationNote">Rider's live location</p>
      <?php endif; ?>

      <div id="loyaltyEarnedNotice" class="loyalty-note" style="display:<?php echo (($order['loyalty_points_earned'] ?? 0) > 0) ? 'block' : 'none'; ?>">
        <span class="material-symbols-rounded">redeem</span>
        You earned <span id="loyaltyEarnedPoints"><?php echo (int)($order['loyalty_points_earned'] ?? 0); ?></span> loyalty points on this order!
      </div>

      <?php if (!empty($restaurant_phone)): ?>
      <div class="tk-help">
        <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^\d+]/', '', $restaurant_phone)); ?>"><span class="material-symbols-rounded">call</span>Call restaurant</a>
        <a href="<?php echo restaurantPageUrl('menu'); ?>"><span class="material-symbols-rounded">restaurant_menu</span>Order more</a>
      </div>
      <?php endif; ?>

      <?php if (!in_array($order['order_status'], ['Completed', 'Cancelled', 'Rejected'], true)): ?>
      <div id="tkPush" hidden data-rg-push data-order="<?php echo htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8'); ?>" data-phone="<?php echo htmlspecialchars($customerPhone, ENT_QUOTES, 'UTF-8'); ?>"
           data-sw="<?php echo htmlspecialchars($website_base_href . 'sw.php', ENT_QUOTES, 'UTF-8'); ?>" data-scope="<?php echo htmlspecialchars($site_root, ENT_QUOTES, 'UTF-8'); ?>"
           data-icon="<?php echo htmlspecialchars((string)($restaurant_logo ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
           data-page="<?php echo htmlspecialchars(restaurantPageUrl('track') . '?order_number=' . rawurlencode($order['order_number']) . '&k=' . orderTrackCode($order['order_number'], $order['customer_phone']), ENT_QUOTES, 'UTF-8'); ?>"></div>
      <script src="rg-order-push.js?v=<?php echo @filemtime(__DIR__ . '/rg-order-push.js'); ?>" defer></script>
      <?php endif; ?>
      <script>
      // the address bar (and so any shared link or screenshot) shows the code, never the phone
      try { var u = new URL(location.href); if (u.searchParams.has('customer_phone')) { u.searchParams.delete('customer_phone'); u.searchParams.set('k', <?php echo json_encode(orderTrackCode($order['order_number'], $order['customer_phone'])); ?>); history.replaceState(history.state, '', u.toString()); } } catch (e) {}
      </script>

      <p id="liveNote" class="live-note on"><span class="live-dot"></span><span class="live-text">Live · updates automatically</span></p>
    </div>

    <div class="content">
    <div id="tkScratch" data-order="<?php echo htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8'); ?>" data-phone="<?php echo htmlspecialchars($customerPhone, ENT_QUOTES, 'UTF-8'); ?>" data-rid="<?php echo htmlspecialchars($order['restaurant_id'], ENT_QUOTES, 'UTF-8'); ?>" data-cur="<?php echo htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?>"></div>
    <script src="rg-scratch.js?v=<?php echo @filemtime(__DIR__ . '/rg-scratch.js'); ?>" defer></script>

    <?php if ($tkType === 'Delivery'): ?>
    <details class="card tk-fold">
      <summary><span class="f-ic"><span class="material-symbols-rounded">location_on</span></span><span class="f-t"><b>Delivery details</b><small><?php echo htmlspecialchars($order['customer_address'] ?? 'N/A'); ?></small></span><span class="material-symbols-rounded chev">expand_more</span></summary>
      <div class="f-body">
        <div class="info-row"><span class="label">Address</span><span class="value" style="max-width:220px;"><?php echo htmlspecialchars($order['customer_address'] ?? 'N/A'); ?></span></div>
        <div class="info-row" id="deliveryChargeRow" style="<?php echo ((float)$order['delivery_charge'] > 0) ? '' : 'display:none;'; ?>"><span class="label">Delivery charge</span><span class="value" id="deliveryChargeValue"><?php echo $currency . number_format((float)$order['delivery_charge'], 2); ?></span></div>
        <div class="info-row" id="riderRow" style="<?php echo ($tracking && $tracking['rider_name']) ? '' : 'display:none;'; ?>"><span class="label">Rider</span><span class="value" id="riderName"><?php echo $tracking ? htmlspecialchars($tracking['rider_name'] ?? '') : ''; ?></span></div>
      </div>
    </details>
    <?php endif; ?>

    <details class="card tk-fold" open>
      <summary><span class="f-ic"><span class="material-symbols-rounded">receipt_long</span></span><span class="f-t"><b>Order summary</b><small><?php echo $itemCount; ?> item<?php echo $itemCount === 1 ? '' : 's'; ?> · <?php echo $currency . number_format((float)$order['total'], 2); ?></small></span><span class="material-symbols-rounded chev">expand_more</span></summary>
      <div class="f-body">
      <?php foreach ($items as $item):
        $img = (string)($item['item_image'] ?? '');
        $imgUrl = $img === '' ? '' : (preg_match('#^(https?:|data:)#', $img) ? $img : 'image.php?id=' . rawurlencode(preg_replace('/^db:/', '', $img)));
      ?>
      <div class="tk-item">
        <div class="tk-thumb"><?php if ($imgUrl): ?><img src="<?php echo htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy"><?php else: ?><span class="material-symbols-rounded">restaurant</span><?php endif; ?></div>
        <div class="tk-item-info"><b><?php echo htmlspecialchars($item['item_name']); ?></b><span><?php echo (int)$item['quantity']; ?> × <?php echo $currency . number_format((float)$item['unit_price'], 2); ?></span></div>
        <div class="tk-item-price"><?php echo $currency . number_format((float)$item['total_price'], 2); ?></div>
      </div>
      <?php endforeach; ?>
      <?php
      // Discounts and the packaging charge are part of `total` but have no line
      // of their own, so show the discounts and derive packaging (not stored
      // separately) as whatever is left over — the rows then add up to Total.
      $couponDiscount  = (float)($order['discount_amount'] ?? 0);
      $loyaltyDiscount = (float)($order['loyalty_discount_amount'] ?? 0);
      $vipDiscount = (float)($order['vip_discount'] ?? 0);
      $otherCharges = round((float)$order['total'] - (float)$order['subtotal'] - (float)$order['tax']
          - (float)$order['delivery_charge'] + $couponDiscount + $loyaltyDiscount + $vipDiscount, 2);
      ?>
      <div class="tk-totals">
        <div class="info-row"><span class="label">Subtotal</span><span class="value"><?php echo $currency . number_format((float)$order['subtotal'], 2); ?></span></div>
        <?php if ($couponDiscount > 0): ?>
        <div class="info-row"><span class="label">Discount<?php echo !empty($order['coupon_code']) ? ' (' . htmlspecialchars($order['coupon_code']) . ')' : ''; ?></span><span class="value" style="color:#16a34a;">−<?php echo $currency . number_format($couponDiscount, 2); ?></span></div>
        <?php endif; ?>
        <?php if ($loyaltyDiscount > 0): ?>
        <div class="info-row"><span class="label">Loyalty points</span><span class="value" style="color:#16a34a;">−<?php echo $currency . number_format($loyaltyDiscount, 2); ?></span></div>
        <?php endif; ?>
        <?php if ($vipDiscount > 0): ?>
        <div class="info-row"><span class="label">Member discount</span><span class="value" style="color:#16a34a;">−<?php echo $currency . number_format($vipDiscount, 2); ?></span></div>
        <?php endif; ?>
        <div class="info-row" id="summaryDeliveryRow" style="<?php echo ((float)$order['delivery_charge'] > 0) ? '' : 'display:none;'; ?>"><span class="label">Delivery</span><span class="value" id="summaryDeliveryValue"><?php echo $currency . number_format((float)$order['delivery_charge'], 2); ?></span></div>
        <?php if ($otherCharges >= 0.01): ?>
        <div class="info-row" id="otherChargesRow"><span class="label">Packaging</span><span class="value"><?php echo $currency . number_format($otherCharges, 2); ?></span></div>
        <?php endif; ?>
        <?php if ((float)$order['tax'] > 0): ?>
        <div class="info-row"><span class="label">Tax</span><span class="value"><?php echo $currency . number_format((float)$order['tax'], 2); ?></span></div>
        <?php endif; ?>
        <div class="info-row tk-grand"><span class="label">Total<small>(incl. fees and tax)</small></span><span class="value" id="summaryTotalValue"><?php echo $currency . number_format((float)$order['total'], 2); ?></span></div>
      </div>
      </div>
    </details>

    <a class="tk-cta ghost" href="<?php echo restaurantPageUrl('menu'); ?>"><span class="material-symbols-rounded">restaurant_menu</span>Back to menu</a>
    <a href="track.php" class="tk-another"><span class="material-symbols-rounded" style="font-size:17px;">search</span>Track another order</a>
    </div>
  <?php endif; ?>
</div>

<?php if ($order): ?>
<script>
(function() {
    var statusLabels = <?php echo json_encode($statusLabels); ?>;
    var orderType = <?php echo json_encode($order['order_type']); ?>;
    var map = null, riderMarker = null, leafletPromise = null, riderName = <?php echo json_encode($tracking['rider_name'] ?? ''); ?>;

    // ── What the page says at each point (headline, bubble, status card) ──
    // Step index in the pill: 0 placed, 1 preparing, 2 ready / on the way, 3 done
    function stageOf(status, delivery) {
        if (status === 'Cancelled' || status === 'Rejected') return { stop: true };
        if (orderType === 'Delivery') {
            if (delivery === 'Delivered' || status === 'Completed') return { i: 3, k: 'delivered' };
            if (delivery === 'Picked_Up' || delivery === 'In_Transit' || status === 'Served') return { i: 2, k: 'onway' };   // Served = sent out for delivery
            if (status === 'Ready' || delivery === 'Assigned') return { i: 1, k: 'packed' };
            if (status === 'Preparing') return { i: 1, k: 'preparing' };
            return { i: 0, k: status === 'Accepted' ? 'accepted' : 'placed' };
        }
        if (status === 'Served' || status === 'Completed') return { i: 3, k: 'done' };
        if (status === 'Ready') return { i: 2, k: 'ready' };
        if (status === 'Preparing') return { i: 1, k: 'preparing' };
        return { i: 0, k: status === 'Accepted' ? 'accepted' : 'placed' };
    }
    function words(st) {
        var rider = riderName ? 'Rider ' + riderName : 'Your rider';
        var take = orderType === 'Takeaway', dine = orderType !== 'Delivery' && !take;
        var W = {
            placed:    ['Order placed!', 'Order placed!', 'receipt_long', 'Waiting for the restaurant', 'receipt_long', "We've got your order", 'The restaurant will confirm it shortly.'],
            accepted:  ['Order confirmed!', 'Order accepted!', 'thumb_up', 'The kitchen starts soon', 'thumb_up', 'Your order is confirmed', 'The kitchen will start on it in a moment.'],
            preparing: ['Cooking it up', 'Being prepared', 'skillet', 'Fresh in the kitchen', 'skillet', 'Your food is being prepared', 'Made fresh, just for you.'],
            packed:    ["You're almost there!", 'Packed & ready', 'inventory_2', riderName ? rider + ' is picking it up' : 'Waiting for a rider', 'inventory_2', 'Your order is packed', riderName ? rider + ' is on the way to collect it.' : "We'll assign a rider shortly."],
            onway:     ["You're almost there!", 'On the way!', 'two_wheeler', rider + ' is heading to you', 'two_wheeler', 'Your order is on the way', rider + ' is bringing it to your door.'],
            delivered: ['Enjoy your meal!', 'Delivered', 'where_to_vote', 'Delivered to your door', 'check_circle', 'Delivered', 'Hope you enjoy it. You can rate it from your profile.'],
            ready:     ["You're almost there!", 'Order is ready!', take ? 'shopping_bag' : 'room_service', take ? 'Pick up your order' : 'Coming to your table', take ? 'shopping_bag' : 'room_service', 'Order is ready!', take ? 'Please pick up your order at the counter.' : 'It will be served at your table in a moment.'],
            done:      ['Enjoy your meal!', take ? 'Collected' : 'Served', 'check_circle', take ? 'Order collected' : 'Served at your table', 'check_circle', take ? 'Collected — enjoy!' : 'Served — enjoy!', 'Hope you enjoy it. You can rate it from your profile.']
        };
        return W[st.k];
    }
    // ── Fun layer: scene per step, rotating lines, celebration ──
    var TIPS = {
        placed: ['Sending your order to the kitchen', 'The restaurant is taking a look', 'Hang tight, it starts in a moment'],
        accepted: ['The chef said yes', 'Ingredients coming out of the fridge', 'Starting the stove'],
        preparing: ['Chef is firing up the stove', 'Adding the secret masala', 'Tasting for the perfect salt', 'Plating it with care'],
        packed: ['Sealed tight and still hot', 'Double-checking everything is in', 'Ready to roll'],
        onway: ['Dodging traffic like a pro', 'Your food is warm in the bag', 'Taking the quickest route', 'Almost at your door'],
        ready: ['Hot and ready', 'Packed with care', 'Waiting just for you'],
        delivered: ['Time to dig in', 'Hope it tastes amazing', 'Thanks for ordering'],
        done: ['Time to dig in', 'Hope it tastes amazing', 'Thanks for ordering']
    };
    var CONF = ['#fde047', '#22d3ee', '#a3e635', '#f472b6', '#fb923c', '#a78bfa'];
    var tipTimer = 0, tipIdx = 0, tipKey = '';
    function sceneFx(k) {
        var h = '', i;
        if (k === 'placed') h = '<i class="slot"></i>';
        else if (k === 'accepted') h = '<i class="ring"></i><i class="ring"></i>';
        else if (k === 'preparing') h = '<i class="steam"></i><i class="steam"></i><i class="steam"></i><i class="flame"></i><i class="flame"></i><i class="flame"></i>';
        else if (k === 'packed' || k === 'ready') h = '<i class="spark"></i><i class="spark"></i><i class="spark"></i><i class="spark"></i>';
        else if (k === 'onway') h = '<i class="town"></i><i class="road"></i><i class="dash"></i><i class="cloud"></i><i class="cloud"></i>';
        else if (k === 'delivered' || k === 'done') {
            for (i = 0; i < 18; i++) h += '<i class="conf" style="left:' + ((i * 37 + 4) % 96) + '%;background:' + CONF[i % CONF.length] + ';animation-duration:' + (2.4 + (i % 5) * 0.5) + 's;animation-delay:-' + ((i * 0.37) % 3).toFixed(2) + 's"></i>';
        }
        return h;
    }
    function setScene(k) {
        var sc = document.getElementById('tkScene');
        if (!sc || sc.getAttribute('data-k') === k) return;
        sc.setAttribute('data-k', k);
        sc.className = 'tk-scene sc-' + k;
        document.getElementById('tkSceneFx').innerHTML = sceneFx(k);
    }
    function setTips(k) {
        if (tipKey === k) return;
        tipKey = k; tipIdx = 0;
        var list = TIPS[k] || [];
        var tipEl = document.getElementById('tkTip'), txt = document.getElementById('tkTipText');
        if (!tipEl) return;
        tipEl.style.display = list.length ? '' : 'none';
        if (list.length) txt.textContent = list[0];
        clearInterval(tipTimer);
        if (list.length < 2) return;
        tipTimer = setInterval(function() {
            tipEl.classList.add('out');
            setTimeout(function() { tipIdx = (tipIdx + 1) % list.length; txt.textContent = list[tipIdx]; tipEl.classList.remove('out'); }, 350);
        }, 3800);
    }
    function celebrate(big) {
        try { if (navigator.vibrate) navigator.vibrate(big ? [60, 40, 90] : 50); } catch (e) {}
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        var wrap = document.createElement('div'), n = big ? 46 : 18, h = '';
        wrap.className = 'tk-burst';
        for (var i = 0; i < n; i++) {
            var a = (i / n) * Math.PI * 2, d = (big ? 160 : 90) + (i % 4) * 30;
            h += '<i style="background:' + CONF[i % CONF.length] + ';--x:' + Math.round(Math.cos(a) * d) + 'px;--y:' + Math.round(Math.sin(a) * d + (big ? 120 : 60)) + 'px;--r:' + (i * 47 % 360) + 'deg"></i>';
        }
        wrap.innerHTML = h;
        document.body.appendChild(wrap);
        setTimeout(function() { wrap.remove(); }, 1600);
    }
    // "· 12 min ago" next to the order time
    var placedAt = <?php echo (int)(strtotime($order['created_at']) * 1000); ?>;
    function tickAgo() {
        var el = document.getElementById('tkAgo');
        if (!el) return;
        var m = Math.max(0, Math.round((Date.now() - placedAt) / 60000));
        el.textContent = m < 1 ? '· just now' : m < 60 ? '· ' + m + ' min ago' : (m < 1440 ? '· ' + Math.floor(m / 60) + ' h ' + (m % 60) + ' min ago' : '');
    }
    tickAgo(); setInterval(tickAgo, 30000);
    function setRider(name, phone) {
        var card = document.getElementById('tkRider');
        if (!card || !name) return;
        document.getElementById('tkRiderName').textContent = name;
        document.getElementById('tkRiderInitial').textContent = name.trim().charAt(0).toUpperCase();
        var call = document.getElementById('tkRiderCall');
        if (phone) { call.href = 'tel:' + String(phone).replace(/[^\d+]/g, ''); call.style.display = ''; }
        if (!card.classList.contains('on')) card.classList.add('on');
    }

    var ICONS = <?php echo json_encode($tkIconPaths); ?>;
    function svgIcon(k) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[k] || ICONS.check) + '</svg>'; }
    function setIcon(id, k) { var el = document.getElementById(id); if (el && el.getAttribute('data-k') !== k) { el.setAttribute('data-k', k); el.innerHTML = svgIcon(k); } }
    var nextLabels = Array.prototype.map.call(document.querySelectorAll('#tkTrack .tk-step span'), function(d) { return d.textContent.trim(); });
    var lastStage = '';
    // why the restaurant rejected / cancelled it (preset reasons only; updated by the poll)
    var stopReason = <?php echo json_encode(orderStopMessage($order['notes'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;

    function renderStage(status, delivery) {
        var st = stageOf(status, delivery);
        var main = document.getElementById('tkMain');
        var set = function(id, v) { var el = document.getElementById(id); if (el && el.textContent !== v) el.textContent = v; };
        if (st.stop) {
            main.classList.add('tk-stopped');
            document.getElementById('tkProgress').style.display = 'none';
            set('tkHeadline', status === 'Rejected' ? 'Order not accepted' : 'Order cancelled');
            setIcon('tkStatusIcon', 'cancel');
            document.getElementById('tkTrack').style.display = 'none';
            set('tkStatusTitle', status === 'Rejected' ? 'The restaurant could not take this order' : 'This order was cancelled');
            set('tkStatusText', (stopReason ? stopReason + ' ' : '') + 'If you paid online, the refund goes back to your payment method.');
            setScene('stop'); setTips('');
            return;
        }
        var w = words(st);
        var final = st.i === 3;
        // Badge follows the same step (a delivery can be 'On the way' while the kitchen status still says Preparing)
        var take = orderType === 'Takeaway';
        var badge = { placed: 'Order Placed', accepted: 'Accepted', preparing: 'Preparing', packed: 'Ready', onway: 'On the way', delivered: 'Delivered', ready: 'Ready', done: take ? 'Collected' : 'Served' }[st.k];
        set('orderStatusText', badge);
        set('tkHeadline', w[0]); set('tkBubbleTitle', w[1]); setIcon('tkChipIcon', w[2]); set('tkChipText', w[3]);
        setIcon('tkStatusIcon', w[4]); set('tkStatusTitle', w[5]); set('tkStatusText', w[6]);
        var next = document.getElementById('tkNext');
        if (final) next.style.display = 'none'; else { next.style.display = ''; set('tkNextText', nextLabels[st.i + 1] || ''); }
        // Steps: rail filled up to the current step, ticks on finished ones
        var track = document.getElementById('tkTrack');
        document.getElementById('tkFill').style.width = (st.i / 3 * 100) + '%';
        track.classList.toggle('final', final);
        track.classList.toggle('start', st.i === 0);
        track.setAttribute('aria-valuenow', st.i + 1);
        track.querySelectorAll('.tk-step').forEach(function(n, k) {
            n.className = 'tk-step' + (k < st.i ? ' done' : k === st.i ? ' on' + (final ? ' final' : '') : '');
            var want = k < st.i || (final && k === st.i) ? 'check' : n.getAttribute('data-icon');
            var i = n.querySelector('i');
            if (i.getAttribute('data-k') !== want) { i.setAttribute('data-k', want); i.innerHTML = svgIcon(want); }
        });
        var bubble = document.getElementById('tkBubble');
        bubble.style.setProperty('--arrow', (st.i * 25 + 12.5) + '%');
        var key = st.i + st.k;
        setScene(st.k); setTips(st.k);
        if (lastStage && key !== lastStage) { bubble.classList.remove('pop'); void bubble.offsetWidth; bubble.classList.add('pop'); celebrate(final); }
        lastStage = key;
    }

    // ── Rider map (Leaflet loads only once there are coordinates) ──
    function loadLeaflet() {
        if (window.L) return Promise.resolve();
        if (leafletPromise) return leafletPromise;
        leafletPromise = new Promise(function(resolve) {
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
            document.head.appendChild(link);
            var script = document.createElement('script');
            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            script.onload = function() { resolve(); };
            document.head.appendChild(script);
        });
        return leafletPromise;
    }
    function showRiderOnMap(lat, lng) {
        loadLeaflet().then(function() {
            var mapEl = document.getElementById('trackMap');
            var noteEl = document.getElementById('riderLocationNote');
            if (mapEl) mapEl.style.display = 'block';
            var heroEl = document.getElementById('tkHero');
            if (heroEl) heroEl.classList.add('has-map');
            if (noteEl) noteEl.style.display = 'block';
            if (!map) {
                map = L.map('trackMap').setView([lat, lng], 15);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
                var tk = getComputedStyle(document.documentElement).getPropertyValue('--tk').trim() || '#1a3934';
                var riderIcon = L.divIcon({
                    className: '',
                    html: '<div style="background:' + tk + ';color:#fff;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.3);"><span class="material-symbols-rounded" style="font-size:20px;">two_wheeler</span></div>',
                    iconSize: [38, 38], iconAnchor: [19, 38], popupAnchor: [0, -40]
                });
                riderMarker = L.marker([lat, lng], { icon: riderIcon }).addTo(map).bindPopup('Rider is here');
                setTimeout(function() { map.invalidateSize(); }, 0);
            } else {
                riderMarker.setLatLng([lat, lng]);
                map.panTo([lat, lng], { animate: true });
            }
        });
    }
    <?php if ($tracking && $tracking['current_lat'] && $tracking['current_lng']): ?>
    showRiderOnMap(<?php echo $tracking['current_lat']; ?>, <?php echo $tracking['current_lng']; ?>);
    <?php endif; ?>

    var consecutivePollFailures = 0;
    function setLocationNote(text) {
        var noteEl = document.getElementById('riderLocationNote');
        if (noteEl) noteEl.textContent = text;
    }

    // Live updates: poll fast while the order is moving (every 4-5s, slowing
    // down only if nothing has changed for minutes), check again the instant
    // the customer comes back to the tab / unlocks the phone / reconnects,
    // pause while the tab is hidden and stop once the order is finished.
    // (Polling rather than a held-open SSE connection: on shared hosting each
    // open connection would pin a PHP worker per customer watching.)
    var pollUrl = '../api/get_tracking_status.php?order_number=<?php echo urlencode($order['order_number']); ?>&customer_phone=<?php echo urlencode($customerPhone); ?>';
    var lastStatus = <?php echo json_encode($order['order_status']); ?>;
    var lastDelivery = <?php echo json_encode($tracking['delivery_status'] ?? null); ?>;
    var lastChangeAt = Date.now(), pollTimer = null, inFlight = false;

    function isFinished(status, delivery) {
        if (status === 'Cancelled' || status === 'Rejected') return true;
        if (orderType === 'Delivery') return delivery === 'Delivered' || status === 'Completed';
        return status === 'Completed' || status === 'Served';
    }
    var finished = isFinished(lastStatus, lastDelivery);

    function nextDelay() {
        if (lastDelivery === 'Picked_Up' || lastDelivery === 'In_Transit') return 3000; // rider moving
        var idle = Date.now() - lastChangeAt;
        if (idle < 10 * 60000) return 4000;
        if (idle < 30 * 60000) return 10000;
        return 20000;
    }
    function schedule(ms) {
        clearTimeout(pollTimer);
        if (finished || document.hidden) return;
        pollTimer = setTimeout(poll, typeof ms === 'number' ? ms : nextDelay());
    }
    function setLive(state) {
        var el = document.getElementById('liveNote');
        if (!el) return;
        el.className = 'live-note ' + state;
        var txt = el.querySelector('.live-text');
        if (state === 'done') txt.textContent = 'Final status';
        else if (state === 'offline') txt.textContent = 'Reconnecting…';
        else txt.textContent = 'Live · updates automatically';
    }
    function flashStatus() {
        var badgeEl = document.getElementById('orderStatusBadge');
        if (!badgeEl) return;
        badgeEl.classList.remove('status-flash');
        void badgeEl.offsetWidth; // restart the animation
        badgeEl.classList.add('status-flash');
    }

    function poll() {
        if (inFlight || finished) return;
        inFlight = true;
        clearTimeout(pollTimer);
        fetch(pollUrl, { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d.success) return;
                if (typeof d.stop_reason === 'string') stopReason = d.stop_reason;
                if (typeof d.eta_text === 'string') { var etaEl = document.getElementById('tkEta'), etaT = document.getElementById('tkEtaText'); if (etaEl && etaT) { etaT.textContent = d.eta_text; etaEl.style.display = d.eta_text ? 'inline-flex' : 'none'; } }
                consecutivePollFailures = 0;
                var newDelivery = d.tracking ? (d.tracking.delivery_status || null) : null;
                if (d.tracking && d.tracking.rider_name) { riderName = d.tracking.rider_name; setRider(d.tracking.rider_name, d.tracking.rider_phone); }
                if (d.order_status !== lastStatus || newDelivery !== lastDelivery) {
                    lastChangeAt = Date.now();
                    if (d.order_status !== lastStatus) flashStatus();
                    lastStatus = d.order_status;
                    lastDelivery = newDelivery;
                }
                finished = isFinished(lastStatus, lastDelivery);
                setLive(finished ? 'done' : 'on');
                var statusEl = document.getElementById('orderStatusText');
                var badgeEl = document.getElementById('orderStatusBadge');
                if (statusEl && d.order_status) {
                    statusEl.textContent = statusLabels[d.order_status] || d.order_status;
                    badgeEl.className = 'status-badge status-' + d.order_status.toLowerCase();
                }
                renderStage(lastStatus, lastDelivery);
                if (typeof d.loyalty_points_earned !== 'undefined' && d.loyalty_points_earned > 0) {
                    var loyaltyNotice = document.getElementById('loyaltyEarnedNotice');
                    var loyaltyPointsEl = document.getElementById('loyaltyEarnedPoints');
                    if (loyaltyNotice && loyaltyPointsEl) {
                        loyaltyPointsEl.textContent = d.loyalty_points_earned;
                        loyaltyNotice.style.display = 'block';
                    }
                }
                // A delivery charge the restaurant sets when accepting the order
                // (KM-based pricing) isn't there at page load if the order was
                // still Pending, so patch it in live.
                if (typeof d.delivery_charge !== 'undefined' && d.delivery_charge !== null) {
                    var dc = parseFloat(d.delivery_charge) || 0;
                    if (dc > 0) {
                        var chargeText = <?php echo json_encode($currency); ?> + dc.toFixed(2);
                        ['deliveryChargeRow', 'summaryDeliveryRow'].forEach(function(id) { var el = document.getElementById(id); if (el) el.style.display = ''; });
                        ['deliveryChargeValue', 'summaryDeliveryValue'].forEach(function(id) { var el = document.getElementById(id); if (el) el.textContent = chargeText; });
                    }
                }
                if (typeof d.total !== 'undefined' && d.total !== null) {
                    var totalVal = document.getElementById('summaryTotalValue');
                    if (totalVal) totalVal.textContent = <?php echo json_encode($currency); ?> + parseFloat(d.total).toFixed(2);
                }
                if (d.tracking) {
                    if (d.tracking.rider_name) {
                        var rn = document.getElementById('riderName'), rr = document.getElementById('riderRow');
                        if (rn) rn.textContent = d.tracking.rider_name;
                        if (rr) rr.style.display = '';
                    }
                    if (d.tracking.current_lat && d.tracking.current_lng) showRiderOnMap(d.tracking.current_lat, d.tracking.current_lng);
                    // A frozen map looks like a live one: say so when the rider's
                    // location hasn't updated recently.
                    var ageSec = d.tracking.location_age_seconds;
                    if (typeof ageSec === 'number') {
                        setLocationNote(ageSec > 180 ? "Rider's location hasn't updated in a while — they may have a weak signal." : "Rider's live location");
                    }
                }
            })
            .catch(function() {
                consecutivePollFailures++;
                if (consecutivePollFailures >= 3) {
                    setLocationNote('Having trouble getting live updates. Checking your connection…');
                    setLive('offline');
                }
            })
            .then(function() {
                inFlight = false;
                schedule();
            });
    }

    document.addEventListener('visibilitychange', function() {
        if (document.hidden) clearTimeout(pollTimer);
        else poll(); // catch up immediately when the customer comes back
    });
    window.addEventListener('online', poll);
    window.addEventListener('focus', poll);
    window.addEventListener('pageshow', function(e) { if (e.persisted) poll(); });

    renderStage(lastStatus, lastDelivery);
    setLive(finished ? 'done' : 'on');
    schedule(2500);
})();
</script>
<?php endif; ?>
<script>
// Keep the embedding iframe sized to this page's content (same as cart/menu)
(function() {
  function sendHeight() {
    try { window.parent.postMessage({ type: 'rg-embed-resize', height: document.body.scrollHeight }, '*'); } catch(e) {}
  }
  sendHeight();
  setTimeout(sendHeight, 300);
  setTimeout(sendHeight, 1000);
  if (typeof ResizeObserver !== 'undefined') {
    try { new ResizeObserver(function(){ sendHeight(); }).observe(document.body); } catch(e){}
  }
})();
</script>
</body>
</html>
