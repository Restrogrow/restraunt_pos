<?php
/**
 * Loyalty Studio admin API (Growth > Loyalty & Rewards).
 *
 * GET  ?section=program   — program type + its settings, dishes (for a free-dish reward), wallet status
 * GET  ?section=autopilot — automations, their numbers, who is coming up, recent gifts
 * POST JSON { section: 'program', ... }   — save the program
 * POST JSON { section: 'autopilot', type, enabled, reward_kind, ... } — save one automation
 * POST JSON { section: 'autopilot', action: 'run' } — run the date checks now
 * GET  ?section=games     — the after-checkout game (style, prizes) and the challenges with their numbers
 * POST JSON { section: 'games', action: 'style' | 'save' | 'toggle' | 'delete', ... }
 * GET  ?section=vip       — the club, plans with their members and revenue, latest members
 * POST JSON { section: 'vip', action: 'settings' | 'save' | 'toggle' | 'delete', ... }
 * GET  ?section=card      — the phone wallet card: switch, colours, which wallets are ready, cards added
 * POST JSON { section: 'card', enabled, color, text_color }
 * GET  ?section=performance&days=30|90|365 — what loyalty brings in: revenue from gift codes by
 *                          source, members vs guests, repeat rate, weekly trend, what is owed
 * Always scoped to the session's restaurant.
 */
require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin(); // the companion app's dev web preview (logged-in requests)
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../config/loyalty_autopilot.php';
require_once __DIR__ . '/../config/vip_membership.php';
require_once __DIR__ . '/../config/wallet_helpers.php';
require_once __DIR__ . '/../config/wallet_card.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}
// same gate as controllers/growth_operations.php: owner / manager, not every staff login
if (!hasPermission(PERMISSION_MANAGE_LOYALTY)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have access to loyalty settings.']);
    exit;
}
$rid = $_SESSION['restaurant_id'];
session_write_close();

function laOut(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function laItems(PDO $conn, string $rid): array {
    $st = $conn->prepare('SELECT mi.id, mi.item_name_en name, mi.base_price price, m.menu_name category FROM menu_items mi JOIN menu m ON m.id = mi.menu_id WHERE mi.restaurant_id = ? ORDER BY m.sort_order, m.menu_name, mi.item_name_en');
    $st->execute([$rid]);
    return array_map(function ($r) { return ['id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['price'], 'category' => $r['category']]; }, $st->fetchAll(PDO::FETCH_ASSOC));
}

try {
    $conn = getConnection();
    ensureLoyaltyAutopilotSchema($conn);
    ensureVipSchema($conn);
    ensureWalletTables($conn);   // orders.wallet_paid, used by Performance
    ensureWalletCardSchema($conn);
    $cur = (string)($conn->query('SELECT currency_symbol FROM users WHERE restaurant_id = ' . $conn->quote($rid) . ' LIMIT 1')->fetchColumn() ?: '₹');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $section = $_GET['section'] ?? 'program';
        if ($section === 'program') {
            $s = getGrowthSettings($conn, $rid);
            $items = laItems($conn, $rid);
            $members = $conn->prepare('SELECT COUNT(*) total, SUM(loyalty_points_balance > 0) with_points, SUM(stamps > 0) with_stamps, SUM(birthday IS NOT NULL) with_birthday, SUM(whatsapp_optin = 1) opted_in FROM customers WHERE restaurant_id = ?');
            $members->execute([$rid]);
            $u = $conn->prepare('SELECT phonepe_merchant_id, payment_gateway_mode FROM users WHERE restaurant_id = ? LIMIT 1');
            $u->execute([$rid]);
            $uu = $u->fetch(PDO::FETCH_ASSOC) ?: [];
            laOut(['success' => true, 'currency' => $cur,
                'program' => [
                    'loyalty_enabled' => (int)$s['loyalty_enabled'], 'program_type' => loyaltyProgramType($s),
                    'cashback_pct' => (float)$s['cashback_pct'], 'cashback_max' => (float)$s['cashback_max'],
                    'stamp_goal' => (int)$s['stamp_goal'], 'stamp_min_order' => (float)$s['stamp_min_order'],
                    'stamp_reward_kind' => $s['stamp_reward_kind'], 'stamp_reward_item_id' => $s['stamp_reward_item_id'] ? (int)$s['stamp_reward_item_id'] : null,
                    'stamp_reward_value' => (float)$s['stamp_reward_value'], 'reward_valid_days' => (int)$s['reward_valid_days'],
                    'earn_points_per_amount' => (int)$s['earn_points_per_amount'], 'earn_amount_threshold' => (float)$s['earn_amount_threshold'],
                    'redeem_value_per_point' => (float)$s['redeem_value_per_point'],
                ],
                'items' => $items,
                'members' => array_map('intval', $members->fetch(PDO::FETCH_ASSOC) ?: []),
                'phonepe_ready' => !empty($uu['phonepe_merchant_id']) || ($uu['payment_gateway_mode'] ?? 'own') !== 'own',
            ]);
        }
        if ($section === 'autopilot') {
            loyaltyRunSweep($conn, $rid);
            $s = getGrowthSettings($conn, $rid);
            $q = function ($sql) use ($conn, $rid) { $st = $conn->prepare($sql); $st->execute([$rid]); return (int)$st->fetchColumn(); };
            $A = loyaltyAutomations($conn, $rid);
            $soon = function ($col, $days) { return "SELECT COUNT(*) FROM customers WHERE restaurant_id = ? AND `$col` IS NOT NULL AND (
                DATEDIFF(STR_TO_DATE(CONCAT(YEAR(CURDATE()), DATE_FORMAT(`$col`, '-%m-%d')), '%Y-%m-%d'), CURDATE()) BETWEEN 0 AND $days
             OR DATEDIFF(STR_TO_DATE(CONCAT(YEAR(CURDATE()) + 1, DATE_FORMAT(`$col`, '-%m-%d')), '%Y-%m-%d'), CURDATE()) BETWEEN 0 AND $days)"; };
            $wb = max(7, (int)$A['winback']['param']);
            $recent = $conn->prepare('SELECT r.source, r.label, r.coupon_code, r.created_at, r.expires_on, r.notify_status, c.customer_name, c.phone, COALESCE(cp.current_uses, 0) > 0 used
                FROM loyalty_rewards_issued r LEFT JOIN customers c ON c.id = r.customer_id LEFT JOIN coupons cp ON cp.restaurant_id = r.restaurant_id AND cp.coupon_code = r.coupon_code
                WHERE r.restaurant_id = ? ORDER BY r.id DESC LIMIT 12');
            $recent->execute([$rid]);
            laOut(['success' => true, 'currency' => $cur,
                'loyalty_enabled' => (int)$s['loyalty_enabled'], 'program_type' => loyaltyProgramType($s), 'referral_enabled' => (int)($s['referral_enabled'] ?? 0),
                'last_run' => $s['autopilot_last_run'] ?? null,
                'automations' => array_values($A),
                'stats' => (object)loyaltyAutomationStats($conn, $rid),
                'upcoming' => [
                    'birthday' => $q($soon('birthday', 30)), 'anniversary' => $q($soon('anniversary', 30)),
                    'winback' => $q("SELECT COUNT(*) FROM (SELECT c.id FROM customers c JOIN orders o ON o.restaurant_id = c.restaurant_id AND o.customer_phone = c.phone AND o.order_status = 'Completed' AND o.deleted_at IS NULL WHERE c.restaurant_id = ? GROUP BY c.id HAVING MAX(o.created_at) < NOW() - INTERVAL $wb DAY) x"),
                    'birthdays_known' => $q('SELECT COUNT(*) FROM customers WHERE restaurant_id = ? AND birthday IS NOT NULL'),
                    'opted_in' => $q('SELECT COUNT(*) FROM customers WHERE restaurant_id = ? AND whatsapp_optin = 1'),
                ],
                'recent' => array_map(function ($r) { return ['source' => $r['source'], 'label' => $r['label'], 'code' => $r['coupon_code'], 'at' => $r['created_at'], 'expires_on' => $r['expires_on'],
                    'name' => $r['customer_name'] ?: 'Guest', 'phone' => $r['phone'] ? substr($r['phone'], 0, 2) . '******' . substr($r['phone'], -2) : '', 'used' => (int)$r['used'], 'notify' => $r['notify_status']]; }, $recent->fetchAll(PDO::FETCH_ASSOC)),
                'items' => laItems($conn, $rid),
            ]);
        }
        if ($section === 'games') {
            require_once __DIR__ . '/../config/scratch_helpers.php';
            $s = getGrowthSettings($conn, $rid);
            $g = $conn->prepare('SELECT enabled, title, monthly_budget, game_style FROM scratch_settings WHERE restaurant_id = ?');
            $g->execute([$rid]);
            $game = $g->fetch(PDO::FETCH_ASSOC) ?: ['enabled' => 0, 'title' => null, 'monthly_budget' => 0, 'game_style' => 'scratch'];
            $prizes = getScratchPrizes($conn, $rid);
            $plays = $conn->prepare("SELECT COUNT(*) played, SUM(prize_type <> 'none') won FROM scratch_cards WHERE restaurant_id = ? AND status = 'scratched' AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
            $plays->execute([$rid]);
            $pl = $plays->fetch(PDO::FETCH_ASSOC) ?: [];
            laOut(['success' => true, 'currency' => $cur, 'loyalty_enabled' => (int)$s['loyalty_enabled'],
                'game' => ['enabled' => (int)$game['enabled'], 'style' => $game['game_style'] === 'wheel' ? 'wheel' : 'scratch', 'title' => $game['title'],
                           'budget' => (float)$game['monthly_budget'], 'spent' => scratchSpentThisMonth($conn, $rid), 'played' => (int)($pl['played'] ?? 0), 'won' => (int)($pl['won'] ?? 0),
                           'prizes' => array_map(function ($o) { return $o; }, scratchOdds($prizes, $cur, 0))],
                'challenges' => array_map(function ($c) use ($conn, $rid, $cur) {
                    return $c + ['display_title' => loyaltyChallengeTitle($c, $cur), 'reward' => loyaltyChallengeRewardLabel($conn, $rid, $c, $cur), 'stats' => loyaltyChallengeStats($conn, $rid, $c)];
                }, loyaltyChallenges($conn, $rid)),
                'items' => laItems($conn, $rid),
            ]);
        }
        if ($section === 'card') {
            $cs = walletCardSettings($conn, $rid);
            $n = $conn->prepare('SELECT platform, COUNT(*) n FROM wallet_cards WHERE restaurant_id = ? GROUP BY platform');
            $n->execute([$rid]);
            $counts = ['apple' => 0, 'google' => 0];
            foreach ($n->fetchAll(PDO::FETCH_ASSOC) as $x) $counts[$x['platform']] = (int)$x['n'];
            // a sample member for the preview: the top customer, else a made-up one
            $top = $conn->prepare('SELECT * FROM customers WHERE restaurant_id = ? ORDER BY total_spent DESC LIMIT 1');
            $top->execute([$rid]);
            $sample = $top->fetch(PDO::FETCH_ASSOC) ?: ['id' => 0, 'customer_name' => 'Priya Sharma', 'phone' => '9876543210', 'total_spent' => 0, 'stamps' => 3, 'loyalty_points_balance' => 240];
            $c = walletCardContent($conn, $rid, $sample);
            $c['name'] = 'Priya Sharma'; $c['barcode_alt'] = 'Member 98••••3210';
            laOut(['success' => true, 'currency' => $cur, 'enabled' => $cs['enabled'], 'color' => $cs['color'], 'text_color' => $cs['text_color'],
                'loyalty_enabled' => $cs['loyalty_enabled'], 'platforms' => walletCardPlatforms(), 'counts' => $counts, 'preview' => $c]);
        }
        if ($section === 'performance') {
            $days = (int)($_GET['days'] ?? 30);
            if (!in_array($days, [30, 90, 365], true)) $days = 30;
            $from = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
            $prevFrom = date('Y-m-d H:i:s', strtotime('-' . (2 * $days) . ' days'));
            $one = function ($sql, $args) use ($conn) { $st = $conn->prepare($sql); $st->execute($args); return $st->fetch(PDO::FETCH_ASSOC) ?: []; };
            $all = function ($sql, $args) use ($conn) { $st = $conn->prepare($sql); $st->execute($args); return $st->fetchAll(PDO::FETCH_ASSOC); };
            $live = "o.restaurant_id = ? AND o.deleted_at IS NULL AND o.order_status NOT IN ('Cancelled', 'Rejected')";
            // An order loyalty helped bring in: a personal gift code, points, the member discount or the wallet
            $codes = "SELECT coupon_code FROM loyalty_rewards_issued WHERE restaurant_id = ? UNION SELECT coupon_code FROM scratch_cards WHERE restaurant_id = ? AND coupon_code IS NOT NULL";
            $loyal = "(o.coupon_code IN ($codes) OR o.loyalty_points_redeemed > 0 OR o.vip_discount > 0 OR o.wallet_paid > 0)";
            $period = function ($a, $b) use ($one, $live, $loyal, $rid) {
                return $one("SELECT COUNT(*) orders, COALESCE(SUM(o.total), 0) revenue, COUNT(DISTINCT NULLIF(o.customer_phone, '')) customers,
                    COALESCE(SUM(CASE WHEN $loyal THEN o.total END), 0) loyal_revenue, SUM($loyal) loyal_orders,
                    COALESCE(SUM(CASE WHEN $loyal THEN o.discount_amount + o.loyalty_discount_amount + o.vip_discount END), 0) given
                    FROM orders o WHERE $live AND o.created_at >= ? AND o.created_at < ?", [$rid, $rid, $rid, $rid, $rid, $rid, $rid, $a, $b]);
            };
            $now = $period($from, date('Y-m-d H:i:s', time() + 60));
            $prev = $period($prevFrom, $from);

            // Revenue from each kind of gift code
            $bySource = $all("SELECT r.source, COUNT(DISTINCT r.id) issued, COUNT(DISTINCT o.id) used, COALESCE(SUM(o.total), 0) revenue, COALESCE(SUM(o.discount_amount), 0) cost
                FROM loyalty_rewards_issued r LEFT JOIN orders o ON o.restaurant_id = r.restaurant_id AND o.coupon_code = r.coupon_code AND o.deleted_at IS NULL
                    AND o.order_status NOT IN ('Cancelled', 'Rejected')
                WHERE r.restaurant_id = ? AND r.created_at >= ? GROUP BY r.source", [$rid, $from]);
            $sc = $one("SELECT COUNT(DISTINCT s.id) issued, COUNT(DISTINCT o.id) used, COALESCE(SUM(o.total), 0) revenue, COALESCE(SUM(o.discount_amount), 0) cost
                FROM scratch_cards s LEFT JOIN orders o ON o.restaurant_id = s.restaurant_id AND o.coupon_code = s.coupon_code AND o.deleted_at IS NULL AND o.order_status NOT IN ('Cancelled', 'Rejected')
                WHERE s.restaurant_id = ? AND s.prize_type <> 'none' AND s.status = 'scratched' AND s.created_at >= ?", [$rid, $from]);
            if ((int)($sc['issued'] ?? 0) > 0) $bySource[] = ['source' => 'scratch'] + $sc;
            $pts = $one("SELECT COUNT(*) used, COALESCE(SUM(o.total), 0) revenue, COALESCE(SUM(o.loyalty_discount_amount), 0) cost FROM orders o WHERE $live AND o.created_at >= ? AND o.loyalty_points_redeemed > 0", [$rid, $from]);
            if ((int)($pts['used'] ?? 0) > 0) $bySource[] = ['source' => 'points', 'issued' => null] + $pts;
            $vipO = $one("SELECT COUNT(*) used, COALESCE(SUM(o.total), 0) revenue, COALESCE(SUM(o.vip_discount), 0) cost FROM orders o WHERE $live AND o.created_at >= ? AND o.vip_discount > 0", [$rid, $from]);
            if ((int)($vipO['used'] ?? 0) > 0) $bySource[] = ['source' => 'vip_orders', 'issued' => null] + $vipO;
            $bySource = array_map(function ($r) { return ['source' => $r['source'], 'issued' => $r['issued'] === null ? null : (int)$r['issued'], 'used' => (int)$r['used'],
                'revenue' => (float)$r['revenue'], 'cost' => (float)$r['cost']]; }, $bySource);
            usort($bySource, function ($a, $b) { return $b['revenue'] <=> $a['revenue']; });

            // Members (signed up) vs guests, and VIP members
            $seg = $all("SELECT CASE WHEN c.password_hash IS NOT NULL THEN 'members' ELSE 'guests' END seg, COUNT(*) orders, COALESCE(SUM(o.total), 0) revenue,
                    COUNT(DISTINCT o.customer_phone) customers
                FROM orders o LEFT JOIN customers c ON c.restaurant_id = o.restaurant_id AND c.phone = o.customer_phone
                WHERE $live AND o.created_at >= ? AND o.customer_phone IS NOT NULL AND o.customer_phone <> '' GROUP BY seg", [$rid, $from]);
            $segOut = ['members' => ['orders' => 0, 'revenue' => 0, 'customers' => 0], 'guests' => ['orders' => 0, 'revenue' => 0, 'customers' => 0]];
            foreach ($seg as $r) $segOut[$r['seg']] = ['orders' => (int)$r['orders'], 'revenue' => (float)$r['revenue'], 'customers' => (int)$r['customers']];
            $vipSeg = $one("SELECT COUNT(*) orders, COALESCE(SUM(o.total), 0) revenue, COUNT(DISTINCT o.customer_phone) customers FROM orders o WHERE $live AND o.created_at >= ?
                AND EXISTS (SELECT 1 FROM vip_members m WHERE m.restaurant_id = o.restaurant_id AND m.phone = o.customer_phone AND m.status = 'Active' AND o.created_at BETWEEN m.starts_at AND m.ends_at)", [$rid, $from]);
            $segOut['vip'] = ['orders' => (int)($vipSeg['orders'] ?? 0), 'revenue' => (float)($vipSeg['revenue'] ?? 0), 'customers' => (int)($vipSeg['customers'] ?? 0)];
            $rep = $one("SELECT COUNT(*) customers, SUM(n >= 2) repeaters FROM (SELECT COUNT(*) n FROM orders o WHERE $live AND o.created_at >= ? AND o.customer_phone IS NOT NULL AND o.customer_phone <> '' GROUP BY o.customer_phone) x", [$rid, $from]);

            // Weekly trend (last 12 weeks): all revenue and the loyalty-helped part
            $weeks = $all("SELECT YEARWEEK(o.created_at, 3) w, MIN(DATE(o.created_at)) d, COALESCE(SUM(o.total), 0) revenue, COALESCE(SUM(CASE WHEN $loyal THEN o.total END), 0) loyal
                FROM orders o WHERE $live AND o.created_at >= NOW() - INTERVAL 12 WEEK GROUP BY w ORDER BY w", [$rid, $rid, $rid]);

            // What is owed: points, wallet money, unused gift codes, stamp cards in progress
            $s = getGrowthSettings($conn, $rid);
            $owed = $one("SELECT COALESCE(SUM(loyalty_points_balance), 0) points, SUM(stamps > 0) stamp_cards, COALESCE(SUM(stamps), 0) stamps FROM customers WHERE restaurant_id = ?", [$rid]);
            $wallet = 0.0;
            try { $wallet = (float)($one('SELECT COALESCE(SUM(balance), 0) b FROM wallets WHERE restaurant_id = ?', [$rid])['b'] ?? 0); } catch (PDOException $e) {}
            $gifts = $one("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN discount_type = 'flat' THEN discount_value END), 0) flat, SUM(discount_type = 'percent') pct
                FROM coupons WHERE restaurant_id = ? AND owner_phone IS NOT NULL AND is_active = 1 AND current_uses < max_uses AND (valid_until IS NULL OR valid_until >= CURDATE())", [$rid]);
            $vipMembers = (int)($one("SELECT COUNT(DISTINCT phone) n FROM vip_members WHERE restaurant_id = ? AND status = 'Active' AND starts_at <= NOW() AND ends_at > NOW()", [$rid])['n'] ?? 0);

            $f = function ($r) { return ['orders' => (int)($r['orders'] ?? 0), 'revenue' => (float)($r['revenue'] ?? 0), 'customers' => (int)($r['customers'] ?? 0),
                'loyal_revenue' => (float)($r['loyal_revenue'] ?? 0), 'loyal_orders' => (int)($r['loyal_orders'] ?? 0), 'given' => (float)($r['given'] ?? 0)]; };
            laOut(['success' => true, 'currency' => $cur, 'days' => $days, 'program_type' => loyaltyProgramType($s), 'loyalty_enabled' => (int)$s['loyalty_enabled'],
                'now' => $f($now), 'prev' => $f($prev), 'sources' => $bySource, 'segments' => $segOut,
                'repeat' => ['customers' => (int)($rep['customers'] ?? 0), 'repeaters' => (int)($rep['repeaters'] ?? 0)],
                'weeks' => array_map(function ($w) { return ['from' => $w['d'], 'revenue' => (float)$w['revenue'], 'loyal' => (float)$w['loyal']]; }, $weeks),
                'owed' => ['points' => (int)$owed['points'], 'points_value' => round((int)$owed['points'] * (float)$s['redeem_value_per_point'], 2),
                           'wallet' => $wallet, 'gift_codes' => (int)($gifts['n'] ?? 0), 'gift_flat' => (float)($gifts['flat'] ?? 0), 'gift_pct' => (int)($gifts['pct'] ?? 0),
                           'stamp_cards' => (int)($owed['stamp_cards'] ?? 0), 'vip_members' => $vipMembers],
            ]);
        }
        if ($section === 'vip') {
            $vs = vipSettings($conn, $rid);
            $q = function ($sql, $args = null) use ($conn, $rid) { $st = $conn->prepare($sql); $st->execute($args ?: [$rid]); return $st->fetchColumn(); };
            $plans = array_map(function ($p) use ($conn, $rid, $cur, $q) {
                return $p + ['gift' => vipGiftLabel($conn, $rid, $p, $cur),
                    'members' => (int)$q("SELECT COUNT(DISTINCT phone) FROM vip_members WHERE restaurant_id = ? AND plan_id = ? AND status = 'Active' AND ends_at > NOW()", [$rid, $p['id']]),
                    'sold' => (int)$q("SELECT COUNT(*) FROM vip_members WHERE restaurant_id = ? AND plan_id = ? AND status = 'Active'", [$rid, $p['id']]),
                    'revenue' => (float)$q("SELECT COALESCE(SUM(amount), 0) FROM vip_members WHERE restaurant_id = ? AND plan_id = ? AND status = 'Active'", [$rid, $p['id']])];
            }, vipPlans($conn, $rid));
            $recent = $conn->prepare("SELECT m.phone, m.plan_name, m.amount, m.paid_via, m.starts_at, m.ends_at, m.created_at, c.customer_name FROM vip_members m
                LEFT JOIN customers c ON c.restaurant_id = m.restaurant_id AND c.phone = m.phone WHERE m.restaurant_id = ? AND m.status = 'Active' ORDER BY m.id DESC LIMIT 10");
            $recent->execute([$rid]);
            $u = $conn->prepare('SELECT phonepe_merchant_id, payment_gateway_mode FROM users WHERE restaurant_id = ? LIMIT 1');
            $u->execute([$rid]);
            $uu = $u->fetch(PDO::FETCH_ASSOC) ?: [];
            laOut(['success' => true, 'currency' => $cur, 'settings' => $vs, 'plans' => $plans, 'items' => laItems($conn, $rid),
                'phonepe_ready' => !empty($uu['phonepe_merchant_id']) || ($uu['payment_gateway_mode'] ?? 'own') !== 'own',
                'wallet_enabled' => (int)$q('SELECT enabled FROM wallet_settings WHERE restaurant_id = ?'),
                'kpis' => [
                    'members' => (int)$q("SELECT COUNT(DISTINCT phone) FROM vip_members WHERE restaurant_id = ? AND status = 'Active' AND starts_at <= NOW() AND ends_at > NOW()"),
                    'revenue_month' => (float)$q("SELECT COALESCE(SUM(amount), 0) FROM vip_members WHERE restaurant_id = ? AND status = 'Active' AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
                    'revenue_total' => (float)$q("SELECT COALESCE(SUM(amount), 0) FROM vip_members WHERE restaurant_id = ? AND status = 'Active'"),
                    'given' => (float)$q("SELECT COALESCE(SUM(vip_discount), 0) FROM orders WHERE restaurant_id = ? AND deleted_at IS NULL AND order_status NOT IN ('Cancelled', 'Rejected')"),
                    'renewing_soon' => (int)$q("SELECT COUNT(DISTINCT phone) FROM vip_members m WHERE restaurant_id = ? AND status = 'Active' AND ends_at > NOW() AND ends_at <= NOW() + INTERVAL 7 DAY
                        AND NOT EXISTS (SELECT 1 FROM vip_members n WHERE n.restaurant_id = m.restaurant_id AND n.phone = m.phone AND n.status = 'Active' AND n.ends_at > m.ends_at)"),
                ],
                'recent' => array_map(function ($r) { return ['name' => $r['customer_name'] ?: 'Customer', 'phone' => substr($r['phone'], 0, 2) . '******' . substr($r['phone'], -2),
                    'plan' => $r['plan_name'], 'amount' => (float)$r['amount'], 'via' => $r['paid_via'], 'at' => $r['created_at'], 'ends_at' => $r['ends_at']]; }, $recent->fetchAll(PDO::FETCH_ASSOC)),
            ]);
        }
        laOut(['success' => false, 'message' => 'Unknown section'], 400);
    }

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $section = $in['section'] ?? '';

    if ($section === 'program') {
        $num = function ($v, $min, $max) { return max($min, min($max, round((float)$v, 2))); };
        $type = in_array($in['program_type'] ?? '', LOYALTY_PROGRAM_TYPES, true) ? $in['program_type'] : 'points';
        $kind = in_array($in['stamp_reward_kind'] ?? '', ['item', 'flat', 'percent'], true) ? $in['stamp_reward_kind'] : 'item';
        $itemId = (int)($in['stamp_reward_item_id'] ?? 0);
        if ($itemId) {
            $own = $conn->prepare('SELECT 1 FROM menu_items WHERE id = ? AND restaurant_id = ?');
            $own->execute([$itemId, $rid]);
            if (!$own->fetchColumn()) $itemId = 0;
        }
        if ($type === 'stamps' && $kind === 'item' && !$itemId) laOut(['success' => false, 'message' => 'Pick the free dish for a full stamp card'], 400);
        if ($type === 'stamps' && $kind !== 'item' && $num($in['stamp_reward_value'] ?? 0, 0, 100000) <= 0) laOut(['success' => false, 'message' => 'Set the reward for a full stamp card'], 400);
        $conn->prepare('UPDATE growth_settings SET loyalty_enabled = ?, program_type = ?, cashback_pct = ?, cashback_max = ?, stamp_goal = ?, stamp_min_order = ?,
                stamp_reward_kind = ?, stamp_reward_item_id = ?, stamp_reward_value = ?, reward_valid_days = ? WHERE restaurant_id = ?')
             ->execute([empty($in['loyalty_enabled']) ? 0 : 1, $type, $num($in['cashback_pct'] ?? 5, 0.5, 50), $num($in['cashback_max'] ?? 0, 0, 100000),
                        (int)$num($in['stamp_goal'] ?? 6, 2, 20), $num($in['stamp_min_order'] ?? 0, 0, 100000), $kind, $itemId ?: null,
                        $num($in['stamp_reward_value'] ?? 0, 0, 100000), (int)$num($in['reward_valid_days'] ?? 30, 1, 365), $rid]);
        loyaltySyncCashbackToWallet($conn, $rid, getGrowthSettings($conn, $rid));
        laOut(['success' => true]);
    }

    if ($section === 'card') {
        $hex = function ($v, $d) { return preg_match('/^#[0-9a-fA-F]{6}$/', (string)$v) ? strtolower($v) : $d; };
        $conn->prepare('UPDATE growth_settings SET card_enabled = ?, card_color = ?, card_text_color = ? WHERE restaurant_id = ?')
             ->execute([empty($in['enabled']) ? 0 : 1, $hex($in['color'] ?? '', '#1c1230'), $hex($in['text_color'] ?? '', '#ffffff'), $rid]);
        laOut(['success' => true]);
    }

    if ($section === 'vip') {
        $action = (string)($in['action'] ?? '');
        if ($action === 'settings') {
            $conn->prepare('UPDATE growth_settings SET vip_enabled = ?, vip_name = ?, vip_tagline = ? WHERE restaurant_id = ?')
                 ->execute([empty($in['enabled']) ? 0 : 1, trim(mb_substr((string)($in['name'] ?? ''), 0, 40)) ?: null, trim(mb_substr((string)($in['tagline'] ?? ''), 0, 120)) ?: null, $rid]);
            laOut(['success' => true]);
        }
        $id = (int)($in['id'] ?? 0);
        if ($action === 'toggle') {
            $conn->prepare('UPDATE vip_plans SET active = ? WHERE id = ? AND restaurant_id = ?')->execute([empty($in['active']) ? 0 : 1, $id, $rid]);
            laOut(['success' => true]);
        }
        if ($action === 'delete') {
            $used = $conn->prepare('SELECT COUNT(*) FROM vip_members WHERE plan_id = ? AND restaurant_id = ?');
            $used->execute([$id, $rid]);
            if ((int)$used->fetchColumn() > 0) {
                // members keep their perks: hide the plan instead of removing it
                $conn->prepare('UPDATE vip_plans SET active = 0 WHERE id = ? AND restaurant_id = ?')->execute([$id, $rid]);
                laOut(['success' => true, 'hidden' => true]);
            }
            $conn->prepare('DELETE FROM vip_plans WHERE id = ? AND restaurant_id = ?')->execute([$id, $rid]);
            laOut(['success' => true]);
        }
        if ($action === 'save') {
            $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 40));
            if ($name === '') laOut(['success' => false, 'message' => 'Give the plan a name'], 400);
            $price = round((float)($in['price'] ?? 0), 2);
            if ($price < 1 || $price > 100000) laOut(['success' => false, 'message' => 'Set a price for the plan'], 400);
            $days = (int)($in['duration_days'] ?? 30);
            if ($days < 7 || $days > 730) laOut(['success' => false, 'message' => 'A plan runs between 7 days and 2 years'], 400);
            $pct = max(0, min(50, round((float)($in['discount_pct'] ?? 0), 2)));
            $boost = max(1, min(5, round((float)($in['earn_boost'] ?? 1), 2)));
            $free = empty($in['free_delivery']) ? 0 : 1;
            $gk = in_array($in['gift_kind'] ?? '', ['flat', 'percent', 'item'], true) ? $in['gift_kind'] : null;
            $gv = max(0, min($gk === 'percent' ? 100 : 100000, round((float)($in['gift_value'] ?? 0), 2)));
            $gi = (int)($in['gift_item_id'] ?? 0);
            if ($gi) { $own = $conn->prepare('SELECT 1 FROM menu_items WHERE id = ? AND restaurant_id = ?'); $own->execute([$gi, $rid]); if (!$own->fetchColumn()) $gi = 0; }
            if ($gk === 'item' && !$gi) laOut(['success' => false, 'message' => 'Pick the welcome dish'], 400);
            if ($gk && $gk !== 'item' && $gv <= 0) $gk = null;
            if ($pct <= 0 && !$free && $boost <= 1 && !$gk) laOut(['success' => false, 'message' => 'Give members at least one perk'], 400);
            $vals = [$name, $price, $days, $pct, max(0, min(100000, round((float)($in['discount_max'] ?? 0), 2))), $free, max(0, min(100000, round((float)($in['free_delivery_min'] ?? 0), 2))),
                     $boost, $gk, $gk === 'item' ? 0 : $gv, $gk === 'item' ? $gi : null, empty($in['highlight']) ? 0 : 1];
            if (!empty($in['highlight'])) $conn->prepare('UPDATE vip_plans SET highlight = 0 WHERE restaurant_id = ?')->execute([$rid]);
            if ($id) {
                $conn->prepare('UPDATE vip_plans SET name = ?, price = ?, duration_days = ?, discount_pct = ?, discount_max = ?, free_delivery = ?, free_delivery_min = ?, earn_boost = ?, gift_kind = ?, gift_value = ?, gift_item_id = ?, highlight = ? WHERE id = ? AND restaurant_id = ?')
                     ->execute(array_merge($vals, [$id, $rid]));
            } else {
                $n = $conn->prepare('SELECT COUNT(*) FROM vip_plans WHERE restaurant_id = ?');
                $n->execute([$rid]);
                if ((int)$n->fetchColumn() >= 6) laOut(['success' => false, 'message' => 'Up to 6 plans. Hide or remove one first.'], 400);
                $conn->prepare('INSERT INTO vip_plans (name, price, duration_days, discount_pct, discount_max, free_delivery, free_delivery_min, earn_boost, gift_kind, gift_value, gift_item_id, highlight, restaurant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                     ->execute(array_merge($vals, [$rid]));
            }
            laOut(['success' => true]);
        }
        laOut(['success' => false, 'message' => 'Unknown action'], 400);
    }

    if ($section === 'games') {
        $action = (string)($in['action'] ?? '');
        $own = function ($itemId) use ($conn, $rid) {
            if (!$itemId) return 0;
            $st = $conn->prepare('SELECT 1 FROM menu_items WHERE id = ? AND restaurant_id = ?');
            $st->execute([(int)$itemId, $rid]);
            return $st->fetchColumn() ? (int)$itemId : 0;
        };
        if ($action === 'style') {
            $style = ($in['style'] ?? '') === 'wheel' ? 'wheel' : 'scratch';
            $conn->prepare('INSERT INTO scratch_settings (restaurant_id, game_style) VALUES (?, ?) ON DUPLICATE KEY UPDATE game_style = VALUES(game_style)')->execute([$rid, $style]);
            laOut(['success' => true]);
        }
        $id = (int)($in['id'] ?? 0);
        if ($action === 'toggle' || $action === 'delete') {
            $sql = $action === 'toggle' ? 'UPDATE loyalty_challenges SET active = ? WHERE id = ? AND restaurant_id = ?' : 'DELETE FROM loyalty_challenges WHERE id = ? AND restaurant_id = ?';
            $conn->prepare($sql)->execute($action === 'toggle' ? [empty($in['active']) ? 0 : 1, $id, $rid] : [$id, $rid]);
            laOut(['success' => true]);
        }
        if ($action === 'save') {
            $kind = in_array($in['kind'] ?? '', LOYALTY_CHALLENGE_KINDS, true) ? $in['kind'] : null;
            if (!$kind) laOut(['success' => false, 'message' => 'Pick a challenge type'], 400);
            $limits = ['visits' => [2, 31], 'spend' => [100, 1000000], 'dish' => [1, 31], 'streak' => [2, 12]];
            $target = round((float)($in['target'] ?? 0), 2);
            if ($target < $limits[$kind][0] || $target > $limits[$kind][1]) laOut(['success' => false, 'message' => 'Set a goal between ' . $limits[$kind][0] . ' and ' . $limits[$kind][1]], 400);
            if ($kind !== 'spend') $target = (int)$target;
            $itemId = $own($in['item_id'] ?? 0);
            if ($kind === 'dish' && !$itemId) laOut(['success' => false, 'message' => 'Pick the dish for this challenge'], 400);
            $rk = in_array($in['reward_kind'] ?? '', ['item', 'flat', 'percent'], true) ? $in['reward_kind'] : 'flat';
            $rv = max(0, min($rk === 'percent' ? 100 : 100000, round((float)($in['reward_value'] ?? 0), 2)));
            $rItem = $own($in['reward_item_id'] ?? 0);
            if ($rk === 'item' && !$rItem) laOut(['success' => false, 'message' => 'Pick the free dish'], 400);
            if ($rk !== 'item' && $rv <= 0) laOut(['success' => false, 'message' => 'Set the reward amount'], 400);
            $title = trim(mb_substr((string)($in['title'] ?? ''), 0, 80));
            $vals = [$kind, $title !== '' ? $title : null, $target, $kind === 'dish' ? $itemId : null, $rk, $rv, $rItem ?: null,
                     max(0, min(100000, round((float)($in['min_order'] ?? 0), 2))), max(1, min(365, (int)($in['valid_days'] ?? 30)))];
            if ($id) {
                $conn->prepare('UPDATE loyalty_challenges SET kind = ?, title = ?, target = ?, item_id = ?, reward_kind = ?, reward_value = ?, reward_item_id = ?, min_order = ?, valid_days = ? WHERE id = ? AND restaurant_id = ?')
                     ->execute(array_merge($vals, [$id, $rid]));
            } else {
                $n = $conn->prepare('SELECT COUNT(*) FROM loyalty_challenges WHERE restaurant_id = ?');
                $n->execute([$rid]);
                if ((int)$n->fetchColumn() >= 12) laOut(['success' => false, 'message' => 'You can have up to 12 challenges. Remove an old one first.'], 400);
                $conn->prepare('INSERT INTO loyalty_challenges (kind, title, target, item_id, reward_kind, reward_value, reward_item_id, min_order, valid_days, restaurant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                     ->execute(array_merge($vals, [$rid]));
            }
            laOut(['success' => true]);
        }
        laOut(['success' => false, 'message' => 'Unknown action'], 400);
    }

    if ($section === 'autopilot') {
        if (($in['action'] ?? '') === 'run') laOut(['success' => true, 'issued' => loyaltyRunSweep($conn, $rid, true)]);
        $defs = loyaltyAutopilotDefaults();
        $type = (string)($in['type'] ?? '');
        if (!isset($defs[$type])) laOut(['success' => false, 'message' => 'Unknown automation'], 400);
        $kind = in_array($in['reward_kind'] ?? '', ['item', 'flat', 'percent'], true) ? $in['reward_kind'] : 'flat';
        $value = max(0, min($kind === 'percent' ? 100 : 100000, round((float)($in['reward_value'] ?? 0), 2)));
        $itemId = (int)($in['reward_item_id'] ?? 0);
        if ($itemId) {
            $own = $conn->prepare('SELECT 1 FROM menu_items WHERE id = ? AND restaurant_id = ?');
            $own->execute([$itemId, $rid]);
            if (!$own->fetchColumn()) $itemId = 0;
        }
        $enabled = empty($in['enabled']) ? 0 : 1;
        if ($enabled && $kind === 'item' && !$itemId) laOut(['success' => false, 'message' => 'Pick the free dish'], 400);
        if ($enabled && $kind !== 'item' && $value <= 0) laOut(['success' => false, 'message' => 'Set the gift amount'], 400);
        $limits = ['birthday' => [0, 30], 'anniversary' => [0, 30], 'winback' => [7, 365], 'milestone' => [2, 100]];
        $param = (int)($in['param'] ?? $defs[$type][3]);
        if (isset($limits[$type])) $param = max($limits[$type][0], min($limits[$type][1], $param)); else $param = 0;
        $msg = trim(mb_substr((string)($in['message'] ?? ''), 0, 500)) ?: $defs[$type][6];
        $conn->prepare('INSERT INTO loyalty_automations (restaurant_id, type, enabled, reward_kind, reward_value, reward_item_id, param, min_order, valid_days, message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), reward_kind = VALUES(reward_kind), reward_value = VALUES(reward_value),
                reward_item_id = VALUES(reward_item_id), param = VALUES(param), min_order = VALUES(min_order), valid_days = VALUES(valid_days), message = VALUES(message)')
             ->execute([$rid, $type, $enabled, $kind, $value, $itemId ?: null, $param, max(0, min(100000, round((float)($in['min_order'] ?? 0), 2))),
                        max(1, min(365, (int)($in['valid_days'] ?? 30))), $msg]);
        laOut(['success' => true]);
    }

    laOut(['success' => false, 'message' => 'Unknown section'], 400);
} catch (Throwable $e) {
    error_log('loyalty_admin.php: ' . $e->getMessage());
    laOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
