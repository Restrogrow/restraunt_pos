<?php
/**
 * Offers (dashboard): one place to see every offer tool and manage coupons and menu tags.
 *
 *   GET ?view=overview   each offer tool: on or off, a one-line summary, and what needs attention
 *   GET ?view=coupons    the owner's coupons with how they did (orders and sales in 30 days)
 *   GET ?view=tags       menu categories and which one the "Combos" / "New" filters show
 *   POST {action:'tag', type:'combo'|'new', menu_id}   which category the Menu page's
 *        "Combos" / "New" filter shows (0 = none). Same rules as save_deal.php: one each.
 *
 * Coupon create / edit / pause / delete still go through controllers/coupon_operations.php.
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}
$rid = $_SESSION['restaurant_id'];
$canCoupons = hasPermission(PERMISSION_MANAGE_COUPONS);   // checked before the session is released
session_write_close();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/link_tracking.php';

function osOut(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
/** Run a query that may hit a table this server hasn't created yet: null instead of an error. */
function osTry(callable $f) { try { return $f(); } catch (Throwable $e) { return null; } }

try {
    $conn = getConnection();
    $q = function (string $sql, array $args = []) use ($conn) { $st = $conn->prepare($sql); $st->execute($args); return $st; };
    $cur = (string)($q('SELECT currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1', [$rid])->fetchColumn() ?: '₹');
    $money = function ($n) use ($cur) { return $cur . number_format((float)$n, 0); };
    // a coupon counts as live when it's on, inside its dates and not used up
    $live = "is_active = 1 AND owner_phone IS NULL AND (valid_from IS NULL OR valid_from <= CURDATE()) AND (valid_until IS NULL OR valid_until >= CURDATE()) AND (max_uses = 0 OR current_uses < max_uses)";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        // a short link that opens the menu with this coupon already applied; its taps show on the coupon card
        if (($in['action'] ?? '') === 'coupon_link') {
            if (!$canCoupons) osOut(['success' => false, 'message' => 'You don’t have access to coupons.'], 403);
            $code = strtoupper(trim((string)($in['code'] ?? '')));
            $ok = $code !== '' ? $q('SELECT 1 FROM coupons WHERE restaurant_id = ? AND UPPER(coupon_code) = ? AND owner_phone IS NULL', [$rid, $code])->fetchColumn() : false;
            if (!$ok) osOut(['success' => false, 'message' => 'That coupon was not found'], 404);
            $l = tlLink($conn, $rid, 'coupon', mb_substr($code, 0, 40), ['label' => 'Coupon ' . $code, 'target' => 'menu', 'src' => 'coupon-' . $code, 'coupon' => $code]);
            osOut(['success' => true, 'short' => $l['short']]);
        }
        if (($in['action'] ?? '') === 'tag') {
            $type = in_array($in['type'] ?? '', ['combo', 'new'], true) ? $in['type'] : null;
            $menuId = (int)($in['menu_id'] ?? 0);
            if (!$type) osOut(['success' => false, 'message' => 'Unknown tag'], 400);
            if ($menuId) {
                $ok = $q('SELECT id FROM menu WHERE id = ? AND restaurant_id = ?', [$menuId, $rid])->fetchColumn();
                if (!$ok) osOut(['success' => false, 'message' => 'That category was not found'], 400);
            }
            $conn->beginTransaction();
            $q('DELETE FROM deals WHERE restaurant_id = ? AND deal_type = ?', [$rid, $type]);
            if ($menuId) $q('INSERT INTO deals (restaurant_id, deal_type, menu_id) VALUES (?, ?, ?)', [$rid, $type, $menuId]);
            $conn->commit();
            osOut(['success' => true]);
        }
        osOut(['success' => false, 'message' => 'Unknown action'], 400);
    }

    $view = $_GET['view'] ?? 'overview';

    if ($view === 'coupons') {
        if (!$canCoupons) osOut(['success' => false, 'message' => 'You don’t have access to coupons.'], 403);
        require_once __DIR__ . '/../config/coupon_helpers.php';
        ensureCouponMilestoneColumn($conn);
        ensureCouponOwnerColumn($conn);
        $rows = $q('SELECT * FROM coupons WHERE restaurant_id = ? AND owner_phone IS NULL ORDER BY created_at DESC', [$rid])->fetchAll(PDO::FETCH_ASSOC);
        $stats = [];
        foreach ($q("SELECT UPPER(coupon_code) code, COUNT(*) orders, COALESCE(SUM(total), 0) sales, COALESCE(SUM(discount_amount), 0) given, MAX(created_at) last_used
                FROM orders WHERE restaurant_id = ? AND coupon_code IS NOT NULL AND coupon_code <> '' AND deleted_at IS NULL
                AND order_status NOT IN ('Cancelled', 'Rejected') AND created_at >= NOW() - INTERVAL 30 DAY GROUP BY UPPER(coupon_code)", [$rid])->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $stats[$s['code']] = $s;
        }
        $today = date('Y-m-d');
        // share links already made for coupons, with their taps
        tlEnsure($conn);
        $links = [];
        foreach ($q("SELECT * FROM tracked_links WHERE restaurant_id = ? AND kind = 'coupon'", [$rid])->fetchAll(PDO::FETCH_ASSOC) as $l) $links[strtoupper($l['ref'])] = $l;
        $lstats = tlStats($conn, $rid, array_values($links));
        $out = array_map(function ($c) use ($stats, $today, $links, $lstats, $conn, $rid) {
            $st = $stats[strtoupper($c['coupon_code'])] ?? null;
            $state = !$c['is_active'] ? 'paused'
                : (($c['valid_until'] && $c['valid_until'] < $today) ? 'expired'
                : (($c['valid_from'] && $c['valid_from'] > $today) ? 'scheduled'
                : (((int)$c['max_uses'] > 0 && (int)$c['current_uses'] >= (int)$c['max_uses']) ? 'used_up' : 'active')));
            return ['id' => (int)$c['id'], 'code' => $c['coupon_code'], 'type' => $c['discount_type'], 'value' => (float)$c['discount_value'],
                    'min_order' => (float)$c['minimum_order_amount'], 'max_uses' => (int)$c['max_uses'], 'uses' => (int)$c['current_uses'],
                    'valid_from' => $c['valid_from'], 'valid_until' => $c['valid_until'], 'is_active' => (int)$c['is_active'],
                    'description' => (string)$c['description'], 'milestone' => (int)($c['is_milestone'] ?? 0), 'created_at' => $c['created_at'], 'state' => $state,
                    'orders_30d' => $st ? (int)$st['orders'] : 0, 'sales_30d' => $st ? (float)$st['sales'] : 0, 'given_30d' => $st ? (float)$st['given'] : 0,
                    'last_used' => $st['last_used'] ?? null,
                    'link' => ($l = $links[strtoupper($c['coupon_code'])] ?? null) ? ['short' => tlShortUrl($conn, $rid, $l['code']), 'people' => $lstats[(int)$l['id']]['people'] ?? 0, 'clicks' => $lstats[(int)$l['id']]['clicks'] ?? 0] : null,
                    // the raw row, for the existing edit form
                    'raw' => $c];
        }, $rows);
        osOut(['success' => true, 'currency' => $cur, 'coupons' => $out]);
    }

    if ($view === 'tags') {
        $cats = $q('SELECT m.id, m.menu_name name, (SELECT COUNT(*) FROM menu_items mi WHERE mi.menu_id = m.id) items FROM menu m WHERE m.restaurant_id = ? ORDER BY m.sort_order, m.menu_name', [$rid])->fetchAll(PDO::FETCH_ASSOC);
        $tags = osTry(function () use ($q, $rid) { return $q('SELECT deal_type, menu_id FROM deals WHERE restaurant_id = ? AND is_active = 1', [$rid])->fetchAll(PDO::FETCH_KEY_PAIR); }) ?: [];
        osOut(['success' => true, 'categories' => array_map(function ($c) { return ['id' => (int)$c['id'], 'name' => $c['name'], 'items' => (int)$c['items']]; }, $cats),
               'combo' => isset($tags['combo']) ? (int)$tags['combo'] : 0, 'new' => isset($tags['new']) ? (int)$tags['new'] : 0]);
    }

    // ── overview ──
    $tools = [];
    // Coupons
    $c = osTry(function () use ($q, $rid, $live) { return $q("SELECT COUNT(*) total, SUM($live) live FROM coupons WHERE restaurant_id = ? AND owner_phone IS NULL", [$rid])->fetch(PDO::FETCH_ASSOC); }) ?: ['total' => 0, 'live' => 0];
    $cu = osTry(function () use ($q, $rid) { return $q("SELECT COUNT(*) n, COALESCE(SUM(o.total), 0) s FROM orders o JOIN coupons c ON c.restaurant_id = o.restaurant_id AND UPPER(c.coupon_code) = UPPER(o.coupon_code) AND c.owner_phone IS NULL
            WHERE o.restaurant_id = ? AND o.deleted_at IS NULL AND o.order_status NOT IN ('Cancelled', 'Rejected') AND o.created_at >= NOW() - INTERVAL 30 DAY", [$rid])->fetch(PDO::FETCH_ASSOC); }) ?: ['n' => 0, 's' => 0];
    $tools['coupons'] = ['on' => (int)$c['live'] > 0, 'summary' => (int)$c['live'] . ' live of ' . (int)$c['total'] . ' · ' . (int)$cu['n'] . ' orders in 30 days',
                         'attention' => (int)$c['total'] > 0 && !(int)$c['live'] ? 'None of your coupons can be used right now (paused, expired or used up).' : null];
    // Combos & Deals (config/combo_helpers.php)
    $cb = osTry(function () use ($conn, $rid) { require_once __DIR__ . '/../config/combo_helpers.php'; return getCombos($conn, $rid, false); }) ?: [];
    $cbLive = count(array_filter($cb, function ($c) { return $c['live']; }));
    $cbSold = (int)(osTry(function () use ($q, $rid) { return $q('SELECT COALESCE(SUM(qty), 0) FROM combo_sales WHERE restaurant_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)', [$rid])->fetchColumn(); }) ?: 0);
    $tools['tags'] = ['on' => $cbLive > 0, 'summary' => $cb ? $cbLive . ' live of ' . count($cb) . ' · ' . $cbSold . ' sold in 30 days' : 'No combos yet',
                      'attention' => $cb && !$cbLive ? 'None of your combos is on sale right now (off, or outside its days and hours).' : null];
    // Offer filters
    $of = (int)(osTry(function () use ($q, $rid) { return $q('SELECT COUNT(*) FROM offer_filters WHERE restaurant_id = ? AND is_active = 1', [$rid])->fetchColumn(); }) ?? 0);
    $tools['filters'] = ['on' => $of > 0, 'summary' => $of ? $of . ' filter' . ($of > 1 ? 's' : '') . ' in the offers bubble' : 'Off: the offers bubble is hidden'];
    // Bestsellers
    $bs = (int)(osTry(function () use ($q, $rid) { return $q('SELECT COUNT(*) FROM bestsellers WHERE restaurant_id = ?', [$rid])->fetchColumn(); }) ?? 0);
    $bsOffer = (int)(osTry(function () use ($q, $rid) { return $q('SELECT COUNT(*) FROM bestsellers WHERE restaurant_id = ? AND offer_price > 0', [$rid])->fetchColumn(); }) ?? 0);
    $tools['bestsellers'] = ['on' => $bs > 0, 'summary' => $bs ? $bs . ' dish' . ($bs > 1 ? 'es' : '') . ' featured' . ($bsOffer ? ', ' . $bsOffer . ' with an offer price' : '') : 'No dishes picked'];
    // Today's special
    // weekday 0 = Sunday … 6 = Saturday; 7 = every day, used when a day has none of its own
    $wd = (int)date('w');
    $ts = osTry(function () use ($q, $rid, $wd) { return $q("SELECT SUM(weekday = ?) today, SUM(weekday = 7) every, COUNT(DISTINCT weekday) days FROM todays_specials WHERE restaurant_id = ? AND menu_item_id IS NOT NULL", [$wd, $rid])->fetch(PDO::FETCH_ASSOC); }) ?: ['today' => 0, 'every' => 0, 'days' => 0];
    $tsToday = (int)$ts['today'] ?: (int)$ts['every'];
    $tools['special'] = ['on' => (int)$ts['days'] > 0, 'summary' => (int)$ts['days'] ? ($tsToday ? $tsToday . ' dish' . ($tsToday > 1 ? 'es' : '') . ' on today' : 'Nothing set for today') : 'No specials set'];
    // Complete your meal
    $up = osTry(function () use ($q, $rid) { return $q('SELECT enabled, mode FROM upsell_settings WHERE restaurant_id = ?', [$rid])->fetch(PDO::FETCH_ASSOC); });
    $upOn = $up ? (int)$up['enabled'] === 1 : true;   // on by default
    $upPairs = (int)(osTry(function () use ($q, $rid) { return $q('SELECT COUNT(*) FROM upsell_pairs WHERE restaurant_id = ?', [$rid])->fetchColumn(); }) ?? 0);
    $tools['upsell'] = ['on' => $upOn, 'summary' => $upOn ? ['auto' => 'Smart picks', 'manual' => 'Your picks', 'both' => 'Smart picks + your picks'][$up['mode'] ?? 'both'] . ($upPairs ? ' · ' . $upPairs . ' pairing' . ($upPairs > 1 ? 's' : '') : '') : 'Off'];
    // Scratch card
    $sc = osTry(function () use ($q, $rid) { return $q('SELECT enabled, monthly_budget FROM scratch_settings WHERE restaurant_id = ?', [$rid])->fetch(PDO::FETCH_ASSOC); });
    $scOn = $sc && (int)$sc['enabled'] === 1;
    $tools['scratch'] = ['on' => $scOn, 'summary' => $scOn ? 'On · ' . $money($sc['monthly_budget']) . ' a month budget' : 'Off'];
    // Wallet
    $wl = osTry(function () use ($q, $rid) { return $q('SELECT enabled FROM wallet_settings WHERE restaurant_id = ?', [$rid])->fetchColumn(); });
    $wlHolders = (int)(osTry(function () use ($q, $rid) { return $q('SELECT COUNT(*) FROM wallets WHERE restaurant_id = ? AND balance > 0', [$rid])->fetchColumn(); }) ?? 0);
    $wlOn = (int)$wl === 1;
    $hasPhonePe = (bool)osTry(function () use ($q, $rid) { return $q("SELECT phonepe_merchant_id IS NOT NULL AND phonepe_merchant_id <> '' FROM users WHERE restaurant_id = ?", [$rid])->fetchColumn(); });
    $tools['wallet'] = ['on' => $wlOn, 'summary' => $wlOn ? 'On · ' . $wlHolders . ' customer' . ($wlHolders === 1 ? '' : 's') . ' hold money' : 'Off',
                        'attention' => $wlOn && !$hasPhonePe ? 'Customers can’t add money until PhonePe is set up in Payments › Gateway.' : null];
    // Catering
    $ct = osTry(function () use ($q, $rid) { return $q("SELECT (SELECT COUNT(*) FROM catering_price_tiers WHERE restaurant_id = ? AND is_active = 1) tiers, (SELECT COUNT(*) FROM catering_requests WHERE restaurant_id = ? AND status = 'new') fresh", [$rid, $rid])->fetch(PDO::FETCH_ASSOC); }) ?: ['tiers' => 0, 'fresh' => 0];
    $tools['catering'] = ['on' => (int)$ct['tiers'] > 0, 'summary' => (int)$ct['tiers'] ? (int)$ct['tiers'] . ' price tier' . ((int)$ct['tiers'] > 1 ? 's' : '') . ((int)$ct['fresh'] ? ' · ' . (int)$ct['fresh'] . ' new request' . ((int)$ct['fresh'] > 1 ? 's' : '') : '') : 'No price tiers yet',
                          'attention' => (int)$ct['fresh'] ? (int)$ct['fresh'] . ' catering request' . ((int)$ct['fresh'] > 1 ? 's' : '') . ' waiting for a reply.' : null];

    osOut(['success' => true, 'currency' => $cur, 'tools' => $tools,
           'coupon_orders_30d' => (int)$cu['n'], 'coupon_sales_30d' => (float)$cu['s']]);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('offers_studio.php: ' . $e->getMessage());
    osOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
