<?php require_once __DIR__ . '/header.php'; ?>
<?php
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/order_abuse_guard.php';
require_once __DIR__ . '/../config/order_reasons.php';   // why an order was rejected / cancelled, in customer words

$orderNumber = $_POST['order_number'] ?? $_GET['order_number'] ?? '';
$customerPhone = $_POST['customer_phone'] ?? $_GET['customer_phone'] ?? '';
// Orders are stored with a normalized 10-digit phone (see
// order_abuse_guard.php / process_website_order.php) — normalize the lookup
// the same way so "+91 98XXX" vs "98XXX" both find the order.
$customerPhone = orderAbuseNormalizePhone($customerPhone) ?? $customerPhone;
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
        $stmt->execute([$orderNumber, $customerPhone]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

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
<?php if (!empty($font_family_google_param)): ?><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=<?php echo $font_family_google_param; ?>:wght@300;400;500;600;700&display=swap" rel="stylesheet"><?php endif; ?>
<style>
:root {
  --primary-red: <?php echo htmlspecialchars($primary_red, ENT_QUOTES, 'UTF-8'); ?>;
  --dark-red: <?php echo htmlspecialchars($dark_red, ENT_QUOTES, 'UTF-8'); ?>;
  --primary-yellow: <?php echo htmlspecialchars($primary_yellow, ENT_QUOTES, 'UTF-8'); ?>;
  --site-font: <?php echo $font_family_css; ?>;
  /* Progress + main button use the restaurant's checkout colour */
  --tk: <?php echo htmlspecialchars($checkout_color ?? $primary_red, ENT_QUOTES, 'UTF-8'); ?>;
  --tk-dark: <?php echo htmlspecialchars($checkout_color_dark ?? $dark_red, ENT_QUOTES, 'UTF-8'); ?>;
  --tk-soft: color-mix(in srgb, var(--tk) 12%, #fff);
  --ink: #16181d; --muted: #8a8f98; --line: #eceef1; --page: #f2f3f5;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--site-font), 'Inter', sans-serif; background: #e3e6ea; color: var(--ink); min-height: 100vh; overflow-x: hidden; }
.phone-frame { max-width: 425px; margin: 0 auto; min-height: 100vh; position: relative; background: var(--page); box-shadow: 0 0 40px rgba(0,0,0,0.08); }
@media (min-width: 768px) { .phone-frame { margin: 20px auto; min-height: calc(100vh - 40px); border-radius: 32px; overflow: hidden; } }

/* Top bar: round buttons, centred title */
.tk-top { display: flex; align-items: center; gap: 10px; padding: 16px 16px 8px; }
.tk-round { width: 44px; height: 44px; flex-shrink: 0; display: grid; place-items: center; border: 0; border-radius: 50%; background: #fff; color: var(--ink); cursor: pointer; box-shadow: 0 2px 10px rgba(0,0,0,.06); text-decoration: none; transition: transform .15s; }
.tk-round:active { transform: scale(.94); }
.tk-round .material-symbols-rounded { font-size: 22px; }
.tk-title { flex: 1; min-width: 0; text-align: center; }
.tk-title b { display: block; font-size: 17px; font-weight: 700; }
.tk-title small { display: block; font-size: 11.5px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tk-actions { display: flex; gap: 8px; }

.content { padding: 8px 14px 30px; }
.card { background: #fff; border-radius: 26px; padding: 20px 18px; margin-bottom: 14px; box-shadow: 0 2px 14px rgba(17,24,39,.05); }

/* Headline card */
.tk-head { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 16px; }
.tk-head h1 { flex: 1; font-size: 23px; font-weight: 700; line-height: 1.2; letter-spacing: -.01em; }
.tk-head .sub { display: block; margin-top: 4px; font-size: 12.5px; font-weight: 500; color: var(--muted); }
.status-badge { flex-shrink: 0; display: inline-flex; align-items: center; gap: 5px; padding: 6px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; background: var(--tk-soft); color: var(--tk-dark); white-space: nowrap; }
.status-badge::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
.status-rejected, .status-cancelled { background: #fee2e2; color: #b91c1c; }
.status-completed, .status-served { background: #dcfce7; color: #15803d; }
.status-badge.status-flash { animation: statusFlash 1.2s ease-out 2; }
@keyframes statusFlash { 0% { transform: scale(1); } 30% { transform: scale(1.1); } 100% { transform: scale(1); } }

/* Speech bubble pointing at the current step */
.tk-bubble { position: relative; margin: 0 2px 16px; padding: 14px 14px 12px; border-radius: 20px; background: #fff; box-shadow: 0 6px 22px rgba(17,24,39,.09); transition: transform .3s; }
.tk-bubble::after { content: ""; position: absolute; bottom: -8px; left: var(--arrow, 12.5%); width: 18px; height: 18px; margin-left: -9px; background: #fff; transform: rotate(45deg); border-radius: 0 0 4px 0; box-shadow: 4px 4px 8px rgba(17,24,39,.05); transition: left .7s cubic-bezier(.22,1,.36,1); }
.tk-bubble b { display: block; font-size: 15px; font-weight: 700; margin-bottom: 10px; }
.tk-bubble-row { display: flex; align-items: center; gap: 8px; }
.tk-chip { display: inline-flex; align-items: center; gap: 8px; flex: 1; min-width: 0; padding: 6px 14px 6px 6px; border-radius: 999px; background: #f4f5f7; color: var(--muted); font-size: 13px; font-weight: 500; }
.tk-chip i { width: 30px; height: 30px; flex-shrink: 0; display: grid; place-items: center; border-radius: 50%; background: var(--ink); color: #fff; font-style: normal; }
.tk-chip i .material-symbols-rounded { font-size: 17px; }
.tk-chip span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tk-next { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--muted); white-space: nowrap; }
.tk-next i { width: 22px; height: 22px; display: grid; place-items: center; border-radius: 50%; background: #f4f5f7; font-style: normal; font-size: 13px; }
.tk-bubble.pop { animation: bubblePop .5s cubic-bezier(.2,1.4,.4,1); }
@keyframes bubblePop { from { transform: translateY(6px) scale(.97); opacity: .6; } to { transform: none; opacity: 1; } }

/* The pill track */
.tk-track { position: relative; height: 72px; border-radius: 999px; background: #eef0f3; box-shadow: inset 0 0 0 2px #e4e7eb; overflow: hidden; }
.tk-fill { position: absolute; left: 0; top: 0; bottom: 0; width: 0; border-radius: 999px; background: linear-gradient(90deg, var(--tk-dark), var(--tk) 70%, color-mix(in srgb, var(--tk) 35%, #fff)); transition: width .9s cubic-bezier(.22,1,.36,1); }
.tk-fill::after { content: ""; position: absolute; inset: 0; background: linear-gradient(100deg, transparent 30%, rgba(255,255,255,.28) 50%, transparent 70%); background-size: 220% 100%; animation: tkShine 2.6s linear infinite; border-radius: inherit; }
.tk-nodes { position: absolute; inset: 0; display: flex; }
.tk-node { flex: 1; display: grid; place-items: center; }
.tk-node i { width: 52px; height: 52px; display: grid; place-items: center; border-radius: 50%; background: #fff; color: #9aa0a8; font-style: normal; box-shadow: 0 2px 8px rgba(0,0,0,.08); transition: color .3s, transform .3s; }
.tk-node i .material-symbols-rounded { font-size: 24px; }
.tk-node.done i, .tk-node.on i { color: var(--tk-dark); }
.tk-node.on i { animation: nodePulse 1.8s ease-out infinite; }
.tk-node.on.final i { animation: none; }
@keyframes nodePulse { 0% { box-shadow: 0 0 0 0 rgba(255,255,255,.75), 0 2px 8px rgba(0,0,0,.08); } 100% { box-shadow: 0 0 0 12px rgba(255,255,255,0), 0 2px 8px rgba(0,0,0,.08); } }
@keyframes tkShine { from { background-position: 120% 0; } to { background-position: -120% 0; } }
.tk-labels { display: flex; margin-top: 8px; }
.tk-labels div { flex: 1; text-align: center; font-size: 13px; font-weight: 600; color: #9aa0a8; transition: color .3s; }
.tk-labels div em { display: grid; place-items: center; width: 18px; height: 18px; margin: 0 auto 3px; border-radius: 50%; font-style: normal; font-size: 11px; font-weight: 700; color: #9aa0a8; }
.tk-labels div.done, .tk-labels div.on { color: var(--ink); }
.tk-labels div.on em { background: var(--ink); color: #fff; }
.tk-labels div.done em { color: var(--ink); }

/* Status card with a big icon */
.tk-status { display: flex; align-items: center; gap: 14px; margin-top: 18px; padding: 16px; border-radius: 20px; background: #f4f5f7; }
.tk-status .ic { width: 64px; height: 64px; flex-shrink: 0; display: grid; place-items: center; border-radius: 18px; background: #fff; color: var(--ink); box-shadow: 0 2px 8px rgba(0,0,0,.05); }
.tk-status .ic .material-symbols-rounded { font-size: 36px; animation: icBob 2.4s ease-in-out infinite; }
@keyframes icBob { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-3px); } }
.tk-status b { display: block; font-size: 15.5px; font-weight: 700; margin-bottom: 3px; }
.tk-status p { font-size: 13px; color: var(--muted); line-height: 1.45; }
.tk-stopped .tk-status { background: #fef2f2; }
.tk-stopped .tk-status .ic { color: #b91c1c; }

#trackMap { height: 200px; border-radius: 18px; margin-top: 14px; display: none; overflow: hidden; }
#riderLocationNote { display: none; font-size: 11px; color: var(--muted); margin-top: 8px; text-align: center; }

.loyalty-note { display: none; margin-top: 14px; padding: 10px 14px; border-radius: 14px; background: color-mix(in srgb, var(--primary-yellow) 22%, #fff); font-size: 13px; font-weight: 600; color: #7c4a03; }
.loyalty-note .material-symbols-rounded { font-size: 17px; vertical-align: -3px; }

/* Cards: details and summary */
h2 { font-size: 16px; font-weight: 700; margin-bottom: 14px; }
.info-row { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; font-size: 14px; }
.label { color: var(--muted); }
.value { font-weight: 600; color: var(--ink); text-align: right; }
.tk-item { display: flex; align-items: center; gap: 12px; padding: 8px 0; }
.tk-item + .tk-item { border-top: 1px dashed var(--line); }
.tk-thumb { width: 58px; height: 58px; flex-shrink: 0; border-radius: 50%; overflow: hidden; background: #f4f5f7; display: grid; place-items: center; color: #c4c8ce; box-shadow: 0 4px 10px rgba(0,0,0,.08); }
.tk-thumb img { width: 100%; height: 100%; object-fit: cover; }
.tk-item-info { flex: 1; min-width: 0; }
.tk-item-info b { display: block; font-size: 14.5px; font-weight: 600; line-height: 1.3; }
.tk-item-info span { font-size: 12.5px; color: var(--muted); }
.tk-item-price { font-size: 15px; font-weight: 700; white-space: nowrap; }
.tk-totals { margin-top: 10px; padding-top: 8px; border-top: 1px solid var(--line); }
.tk-totals .info-row:first-child .label { color: var(--ink); font-weight: 600; }
.tk-grand { margin-top: 6px; padding-top: 10px; border-top: 2px solid #d7dade; font-size: 15px; }
.tk-grand .label { color: var(--ink); font-weight: 700; }
.tk-grand .label small { font-size: 11px; font-weight: 500; color: var(--muted); margin-left: 4px; }
.tk-grand .value { font-size: 16px; font-weight: 800; }

/* Big button */
.tk-cta { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 17px; border: 0; border-radius: 999px; background: linear-gradient(135deg, var(--tk), var(--tk-dark)); color: #fff; font: 600 16px var(--site-font), 'Inter', sans-serif; cursor: pointer; box-shadow: 0 10px 24px color-mix(in srgb, var(--tk) 35%, transparent); text-decoration: none; transition: transform .15s; }
.tk-cta:active { transform: scale(.98); }
.tk-another { display: flex; align-items: center; justify-content: center; gap: 4px; margin: 14px auto 0; font-size: 13.5px; font-weight: 600; color: var(--muted); text-decoration: none; }

/* Live-update indicator */
.live-note { display: flex; align-items: center; justify-content: center; gap: 6px; font-size: 11px; color: var(--muted); margin: 6px 0 14px; }
.live-dot { width: 8px; height: 8px; border-radius: 50%; background: #16a34a; }
.live-note.on .live-dot { animation: livePulse 1.6s ease-out infinite; }
.live-note.offline .live-dot { background: #f59e0b; }
.live-note.done .live-dot { background: #9ca3af; }
@keyframes livePulse { 0% { box-shadow: 0 0 0 0 rgba(22,163,74,.55); } 100% { box-shadow: 0 0 0 7px rgba(22,163,74,0); } }

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

/* ── Progress section: one dark "journey" card (scene, status, steps) ── */
.tk-journey { position: relative; margin-top: 16px; padding: 4px 12px 18px; border-radius: 26px; color: #fff; overflow: hidden;
  background: radial-gradient(120% 70% at 50% 0%, color-mix(in srgb, var(--tk) 42%, transparent), transparent 70%), linear-gradient(180deg, #1c1e25, #111217);
  box-shadow: 0 16px 34px rgba(17,24,39,.28); }
.tk-journey svg { display: block; }
.tk-journey .tk-scene { margin: 0 -12px; height: 150px; background: transparent; border-radius: 0; }
.tk-journey .tk-scene .hero { width: 84px; height: 84px; margin: -48px 0 0 -42px; border-radius: 50%; background: #fff; color: var(--tk);
  box-shadow: 0 0 0 8px rgba(255,255,255,.07), 0 0 46px color-mix(in srgb, var(--tk) 65%, transparent); }
.tk-journey .tk-scene .hero svg { width: 40px; height: 40px; }
.tk-journey .sc-onway .hero { width: 74px; height: 74px; margin: -34px 0 0 -37px; }
.tk-journey .sc-done .hero, .tk-journey .sc-delivered .hero { color: #16a34a; box-shadow: 0 0 0 8px rgba(255,255,255,.07), 0 0 46px rgba(22,163,74,.55); }
.tk-journey .sc-stop { background: transparent; }
.tk-journey .sc-stop .hero { color: #ef4444; box-shadow: 0 0 0 8px rgba(255,255,255,.07), 0 0 46px rgba(239,68,68,.5); }
.tk-journey .sc-placed .fx i.slot { background: rgba(255,255,255,.35); }
.tk-journey .sc-accepted .fx i.ring { border-color: rgba(255,255,255,.55); border-radius: 50%; }
.tk-journey .sc-preparing .fx i.steam { background: linear-gradient(transparent, rgba(255,255,255,.75)); }
.tk-journey .sc-onway .fx i.road { background: #2a2d36; }
.tk-journey .sc-onway .fx i.town { filter: invert(1); opacity: .28; }
.tk-journey .sc-onway .fx i.cloud { background: rgba(255,255,255,.14); }
.tk-journey .fx i.spark { background: #fde047; }
.tk-journey .tk-status-text { margin-top: 2px; }
.tk-journey .tk-status-text b { color: #fff; font-size: 18px; }
.tk-journey .tk-status-text p { color: #a3a8b3; }
.tk-journey .tk-tip { background: rgba(255,255,255,.08); color: #f3f4f6; }
.tk-journey .tk-tip svg { width: 15px; height: 15px; color: #fde047; }

/* Steps: rail with a glowing fill, a spinning ring on the current step */
.tk-steps { position: relative; display: flex; margin-top: 22px; }
.tk-steps .rail { position: absolute; left: 12.5%; right: 12.5%; top: 21px; height: 4px; border-radius: 4px; background: rgba(255,255,255,.1); }
.tk-steps .fill { position: absolute; left: 0; top: 0; bottom: 0; width: 0; border-radius: 4px; background: linear-gradient(90deg, var(--tk-dark), var(--tk), color-mix(in srgb, var(--tk) 45%, #fff)); box-shadow: 0 0 12px color-mix(in srgb, var(--tk) 70%, transparent); transition: width 1s cubic-bezier(.22,1,.36,1); }
.tk-steps .fill::after { content: ""; position: absolute; right: -6px; top: 50%; width: 12px; height: 12px; margin-top: -6px; border-radius: 50%; background: #fff; box-shadow: 0 0 0 4px color-mix(in srgb, var(--tk) 45%, transparent), 0 0 14px #fff; animation: tkGlow 1.4s ease-in-out infinite; }
.tk-steps.final .fill::after, .tk-steps.start .fill::after { display: none; }
.tk-step { position: relative; z-index: 1; flex: 1; display: flex; flex-direction: column; align-items: center; gap: 9px; min-width: 0; }
.tk-step i { position: relative; width: 46px; height: 46px; display: grid; place-items: center; border-radius: 50%; background: #272a33; color: #7d828d; font-style: normal; transition: background .4s, color .4s, transform .4s; }
.tk-step i svg { width: 21px; height: 21px; }
.tk-step.done i { background: var(--tk); color: #fff; }
.tk-step.on i { background: #fff; color: var(--tk); transform: scale(1.12); }
.tk-step.on i::before { content: ""; position: absolute; inset: -6px; border-radius: 50%; background: conic-gradient(var(--tk) 0 28%, transparent 28% 100%);
  -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 4px), #000 calc(100% - 3px)); mask: radial-gradient(farthest-side, transparent calc(100% - 4px), #000 calc(100% - 3px)); animation: tkSpin 1.3s linear infinite; }
.tk-step.on.final i { background: #16a34a; color: #fff; }
.tk-step.on.final i::before { display: none; }
.tk-step span { font-size: 12px; font-weight: 600; color: #7d828d; text-align: center; line-height: 1.2; }
.tk-step.done span, .tk-step.on span { color: #fff; }
.tk-step.on span { font-weight: 800; }
@keyframes tkGlow { 50% { transform: scale(1.3); } }
@keyframes tkSpin { to { transform: rotate(360deg); } }
.tk-chip i svg { width: 16px; height: 16px; }
@media (prefers-reduced-motion: reduce) { .tk-steps .fill::after, .tk-step.on i::before { animation: none; } .tk-steps .fill { transition: none; } }

/* Search form (no order yet) */
.tk-search-ic { width: 72px; height: 72px; margin: 4px auto 12px; display: grid; place-items: center; border-radius: 22px; background: var(--tk-soft); color: var(--tk-dark); }
.tk-search-ic .material-symbols-rounded { font-size: 38px; }
.tk-search h1 { font-size: 22px; font-weight: 700; text-align: center; }
.tk-search .subtitle { font-size: 13px; color: var(--muted); text-align: center; margin: 4px 0 18px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
.form-group input { width: 100%; padding: 14px 16px; border: 1.5px solid var(--line); border-radius: 16px; background: #f8f9fa; font-size: 15px; font-family: inherit; outline: none; transition: border-color .2s, background .2s; }
.form-group input:focus { border-color: var(--tk); background: #fff; }
.error-box { margin-top: 14px; padding: 14px; border-radius: 16px; background: #fef2f2; color: #991b1b; font-size: 14px; text-align: center; }

@media (prefers-reduced-motion: reduce) {
  .live-note.on .live-dot, .status-badge.status-flash, .tk-fill::after, .tk-node.on i, .tk-status .ic .material-symbols-rounded, .tk-bubble.pop { animation: none; }
  .tk-fill, .tk-bubble::after { transition: none; }
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
    <div class="tk-title"><b>Track Order</b><small><?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></small></div>
    <div class="tk-actions">
      <button class="tk-round" aria-label="Menu" onclick="window.location.href='<?php echo restaurantPageUrl('menu'); ?>'"><span class="material-symbols-rounded">restaurant_menu</span></button>
      <button class="tk-round" aria-label="Home" onclick="window.location.href='<?php echo restaurantPageUrl(); ?>'"><span class="material-symbols-rounded">home</span></button>
    </div>
  </div>

  <div class="content">
  <?php if (!$order): ?>
    <div class="card tk-search">
      <div class="tk-search-ic"><span class="material-symbols-rounded">local_shipping</span></div>
      <h1>Track Your Order</h1>
      <p class="subtitle">Enter your order number and phone to see live status</p>
      <form method="POST">
        <div class="form-group">
          <label for="tkOrderNo">Order Number</label>
          <input type="text" id="tkOrderNo" name="order_number" placeholder="e.g. ORD-20261012-1234" required>
        </div>
        <div class="form-group">
          <label for="tkPhone">Phone Number</label>
          <input type="tel" id="tkPhone" name="customer_phone" placeholder="Phone used at checkout" required>
        </div>
        <button type="submit" class="tk-cta">Track Order</button>
      </form>
      <?php if ($error): ?><div class="error-box"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
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
        'cancel' => '<circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/>',
        'sparkle' => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/>',
    ];
    $tkIcon = function ($k) use ($tkIconPaths) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($tkIconPaths[$k] ?? $tkIconPaths['check']) . '</svg>';
    };
  ?><?php
    // Steps shown in the pill (labels + icons) for this kind of order
    if ($tkType === 'Delivery') {
        $tkSteps = [['Placed', 'receipt_long'], ['Preparing', 'skillet'], ['On the way', 'two_wheeler'], ['Delivered', 'where_to_vote']];
    } elseif ($tkType === 'Takeaway') {
        $tkSteps = [['Placed', 'receipt_long'], ['Preparing', 'skillet'], ['Pickup', 'shopping_bag'], ['Collected', 'front_hand']];
    } else {
        $tkSteps = [['Placed', 'receipt_long'], ['Preparing', 'skillet'], ['Ready', 'room_service'], ['Served', 'restaurant']];
    }
  ?>
    <div class="card" id="tkMain">
      <div class="tk-head">
        <h1><span id="tkHeadline">Order placed!</span><span class="sub">Order #<?php echo htmlspecialchars($order['order_number']); ?> · <?php echo htmlspecialchars($tkType); ?></span></h1>
        <div class="status-badge status-<?php echo strtolower($order['order_status']); ?>" id="orderStatusBadge"><span id="orderStatusText"><?php echo htmlspecialchars($statusLabels[$order['order_status']] ?? $order['order_status']); ?></span></div>
      </div>

      <div class="tk-clock"><span class="material-symbols-rounded">schedule</span><span>Placed at <?php echo htmlspecialchars(date('g:i A', strtotime($order['created_at']))); ?></span><span id="tkAgo"></span></div>

      <div id="tkProgress">
        <div class="tk-bubble" id="tkBubble">
          <b id="tkBubbleTitle">Order placed!</b>
          <div class="tk-bubble-row">
            <span class="tk-chip"><i id="tkChipIcon"><?php echo $tkIcon('receipt_long'); ?></i><span id="tkChipText">Waiting for the restaurant</span></span>
            <span class="tk-next" id="tkNext"><i>&rsaquo;</i><span id="tkNextText">Preparing</span></span>
          </div>
        </div>
      </div>

      <div class="tk-journey" id="tkJourney">
        <div class="tk-scene sc-placed" id="tkScene" aria-hidden="true">
          <div class="fx" id="tkSceneFx"></div>
          <div class="hero" id="tkStatusIcon"><?php echo $tkIcon('receipt_long'); ?></div>
        </div>
        <div class="tk-status-text">
          <b id="tkStatusTitle">We've got your order</b>
          <p id="tkStatusText">The restaurant will confirm it shortly.</p>
          <span class="tk-tip" id="tkTip"><?php echo $tkIcon('sparkle'); ?><span id="tkTipText">Sending your order to the kitchen</span></span>
        </div>
        <div class="tk-steps" role="progressbar" aria-valuemin="1" aria-valuemax="4" id="tkTrack">
          <div class="rail"><div class="fill" id="tkFill"></div></div>
          <?php foreach ($tkSteps as $i => $st): ?><div class="tk-step" data-icon="<?php echo $st[1]; ?>"><i><?php echo $tkIcon($st[1]); ?></i><span><?php echo htmlspecialchars($st[0]); ?></span></div><?php endforeach; ?>
        </div>
      </div>

      <?php if ($tkType === 'Delivery'): ?>
      <div class="tk-rider<?php echo ($tracking && !empty($tracking['rider_name'])) ? ' on' : ''; ?>" id="tkRider">
        <div class="av" id="tkRiderInitial"><?php echo htmlspecialchars(mb_strtoupper(mb_substr((string)($tracking['rider_name'] ?? 'R'), 0, 1))); ?></div>
        <div class="who"><b id="tkRiderName"><?php echo htmlspecialchars((string)($tracking['rider_name'] ?? '')); ?></b><span>Your delivery partner</span></div>
        <a class="tk-call" id="tkRiderCall" href="<?php echo !empty($tracking['rider_phone']) ? 'tel:' . htmlspecialchars(preg_replace('/[^\d+]/', '', $tracking['rider_phone'])) : '#'; ?>" style="<?php echo !empty($tracking['rider_phone']) ? '' : 'display:none;'; ?>" aria-label="Call rider"><span class="material-symbols-rounded">call</span></a>
      </div>
      <?php endif; ?>

      <?php if ($tkType === 'Delivery'): ?>
      <div id="trackMap"></div>
      <p id="riderLocationNote">Rider's live location</p>
      <?php endif; ?>

      <div id="loyaltyEarnedNotice" class="loyalty-note" style="display:<?php echo (($order['loyalty_points_earned'] ?? 0) > 0) ? 'block' : 'none'; ?>">
        <span class="material-symbols-rounded">redeem</span>
        You earned <span id="loyaltyEarnedPoints"><?php echo (int)($order['loyalty_points_earned'] ?? 0); ?></span> loyalty points on this order!
      </div>
    </div>

    <div id="tkScratch" data-order="<?php echo htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8'); ?>" data-phone="<?php echo htmlspecialchars($customerPhone, ENT_QUOTES, 'UTF-8'); ?>" data-rid="<?php echo htmlspecialchars($order['restaurant_id'], ENT_QUOTES, 'UTF-8'); ?>" data-cur="<?php echo htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?>"></div>
    <script src="rg-scratch.js?v=<?php echo @filemtime(__DIR__ . '/rg-scratch.js'); ?>" defer></script>

    <?php if (!empty($restaurant_phone)): ?>
    <div class="tk-help">
      <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^\d+]/', '', $restaurant_phone)); ?>"><span class="material-symbols-rounded">call</span>Call restaurant</a>
    </div>
    <?php endif; ?>

    <p id="liveNote" class="live-note on"><span class="live-dot"></span><span class="live-text">Live · updates automatically</span></p>

    <?php if ($tkType === 'Delivery'): ?>
    <div class="card">
      <h2>Delivery Details</h2>
      <div class="info-row"><span class="label">Address</span><span class="value" style="max-width:220px;"><?php echo htmlspecialchars($order['customer_address'] ?? 'N/A'); ?></span></div>
      <div class="info-row" id="deliveryChargeRow" style="<?php echo ((float)$order['delivery_charge'] > 0) ? '' : 'display:none;'; ?>"><span class="label">Delivery Charge</span><span class="value" id="deliveryChargeValue"><?php echo $currency . number_format((float)$order['delivery_charge'], 2); ?></span></div>
      <div class="info-row" id="riderRow" style="<?php echo ($tracking && $tracking['rider_name']) ? '' : 'display:none;'; ?>"><span class="label">Rider</span><span class="value" id="riderName"><?php echo $tracking ? htmlspecialchars($tracking['rider_name'] ?? '') : ''; ?></span></div>
    </div>
    <?php endif; ?>

    <div class="card">
      <h2>Order Summary</h2>
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

    <a class="tk-cta" href="<?php echo restaurantPageUrl('menu'); ?>"><span class="material-symbols-rounded">restaurant_menu</span>Back to Menu</a>
    <a href="track.php" class="tk-another"><span class="material-symbols-rounded" style="font-size:17px;">search</span>Track another order</a>
  <?php endif; ?>
  </div>
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
            if (delivery === 'Picked_Up' || delivery === 'In_Transit') return { i: 2, k: 'onway' };
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
