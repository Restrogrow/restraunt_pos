<?php
/**
 * Analytics (restaurant dashboard): GET ?days=7|30|90|365
 * Every number is for the chosen period and, where it helps, the same-length period before it.
 * Orders that count: not cancelled / rejected / deleted and not a held POS cart.
 * Rendered by assets/js/analytics-studio.js.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
header('Content-Type: application/json');
header('Cache-Control: no-store');
$rid = $_SESSION['restaurant_id'] ?? null;
if (!$rid || (!isset($_SESSION['user_id']) && !isset($_SESSION['staff_id']) && !isset($_SESSION['branch_admin_id']))) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.']);
    exit;
}
session_write_close(); // read-only from here: don't hold the session lock
require_once __DIR__ . '/../db_connection.php';
$conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
if (!$conn instanceof PDO) { http_response_code(500); echo json_encode(['success' => false, 'message' => 'Database unavailable']); exit; }

$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90, 365], true)) $days = 30;

const LIVE = "o.deleted_at IS NULL AND o.order_status NOT IN ('Cancelled','Rejected') AND o.payment_status <> 'Refunded' AND NOT (COALESCE(o.notes,'') = 'Order on hold' AND o.order_status = 'Pending' AND o.payment_status = 'Pending')";

function q(PDO $c, string $sql, array $p = []) { $s = $c->prepare($sql); $s->execute($p); return $s; }
function pct($cur, $prev) { $cur = (float)$cur; $prev = (float)$prev; if ($prev == 0) return $cur > 0 ? null : 0; return round(($cur - $prev) * 100 / $prev, 1); }
function hasTable(PDO $c, string $t): bool { return (bool)$c->query("SHOW TABLES LIKE " . $c->quote($t))->fetch(); }

try {
    $now = (string)$conn->query("SELECT NOW()")->fetchColumn();
    $start = date('Y-m-d H:i:s', strtotime($now) - $days * 86400);
    $prevStart = date('Y-m-d H:i:s', strtotime($now) - 2 * $days * 86400);
    $P = [$rid, $start];          // current period
    $PP = [$rid, $prevStart, $start]; // previous period

    /* ── headline numbers ── */
    $kpi = function (string $from, ?string $to) use ($conn, $rid) {
        $w = "o.restaurant_id = ? AND o.created_at >= ?" . ($to ? " AND o.created_at < ?" : '');
        $p = $to ? [$rid, $from, $to] : [$rid, $from];
        $a = q($conn, "SELECT COUNT(*) orders, COALESCE(SUM(o.total),0) revenue, COALESCE(SUM(o.discount_amount),0) discount,
                COUNT(DISTINCT NULLIF(o.customer_phone,'')) customers
            FROM orders o WHERE $w AND " . LIVE, $p)->fetch(PDO::FETCH_ASSOC);
        $all = q($conn, "SELECT COUNT(*) n, SUM(o.order_status IN ('Cancelled','Rejected')) stopped FROM orders o WHERE $w AND o.deleted_at IS NULL", $p)->fetch(PDO::FETCH_ASSOC);
        $items = q($conn, "SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE $w AND " . LIVE, $p)->fetchColumn();
        // per customer in this period: all their orders up to the end of it, and when they first ordered
        $end = $to ?: date('Y-m-d H:i:s', time() + 86400);
        $cust = q($conn, "SELECT p.customer_phone, COUNT(*) n, MIN(p.created_at) first_at FROM orders p
            WHERE p.restaurant_id = ? AND p.customer_phone <> '' AND p.created_at < ? AND p.deleted_at IS NULL AND p.order_status NOT IN ('Cancelled','Rejected')
              AND p.customer_phone IN (SELECT o.customer_phone FROM orders o WHERE $w AND " . LIVE . ")
            GROUP BY p.customer_phone", array_merge([$rid, $end], $p))->fetchAll(PDO::FETCH_ASSOC);
        $ret = 0; $newC = 0;
        foreach ($cust as $c) { if ((int)$c['n'] >= 2) $ret++; if ($c['first_at'] >= $from) $newC++; }
        $a['orders'] = (int)$a['orders']; $a['revenue'] = round((float)$a['revenue'], 2); $a['discount'] = round((float)$a['discount'], 2);
        $a['customers'] = (int)$a['customers'];
        $a['aov'] = $a['orders'] ? round($a['revenue'] / $a['orders'], 2) : 0;
        $a['items_per_order'] = $a['orders'] ? round($items / $a['orders'], 2) : 0;
        $a['returning'] = $ret;              // ordered 2+ times (so far)
        $a['new_customers'] = $newC;          // first ever order in this period
        $a['repeat_rate'] = $a['customers'] ? round($a['returning'] * 100 / $a['customers'], 1) : 0;
        $a['cancel_rate'] = (int)$all['n'] ? round((int)$all['stopped'] * 100 / (int)$all['n'], 1) : 0;
        $a['cancelled'] = (int)$all['stopped'];
        return $a;
    };
    $cur = $kpi($start, null);
    $prev = $kpi($prevStart, $start);
    $change = [];
    foreach (['revenue', 'orders', 'aov', 'customers', 'repeat_rate', 'items_per_order', 'discount', 'cancel_rate'] as $k) $change[$k] = pct($cur[$k], $prev[$k]);

    /* ── daily trend: this period vs the one before, lined up day by day ── */
    $dailyCur = q($conn, "SELECT DATE(o.created_at) d, COUNT(*) n, SUM(o.total) s FROM orders o WHERE o.restaurant_id = ? AND o.created_at >= ? AND " . LIVE . " GROUP BY DATE(o.created_at)", $P)->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $dailyPrev = q($conn, "SELECT DATE(o.created_at) d, COUNT(*) n, SUM(o.total) s FROM orders o WHERE o.restaurant_id = ? AND o.created_at >= ? AND o.created_at < ? AND " . LIVE . " GROUP BY DATE(o.created_at)", $PP)->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $trend = [];
    $bucket = $days > 90 ? 7 : 1;   // a year is shown by week
    for ($i = $days - 1; $i >= 0; $i -= $bucket) {
        $row = ['label' => '', 'orders' => 0, 'revenue' => 0, 'prev_orders' => 0, 'prev_revenue' => 0];
        for ($j = 0; $j < $bucket && $i - $j >= 0; $j++) {
            $d = date('Y-m-d', strtotime($now) - ($i - $j) * 86400);
            $pd = date('Y-m-d', strtotime($now) - ($i - $j + $days) * 86400);
            if ($j === 0) $row['label'] = $d;
            $row['orders'] += (int)($dailyCur[$d]['n'] ?? 0); $row['revenue'] += (float)($dailyCur[$d]['s'] ?? 0);
            $row['prev_orders'] += (int)($dailyPrev[$pd]['n'] ?? 0); $row['prev_revenue'] += (float)($dailyPrev[$pd]['s'] ?? 0);
        }
        $trend[] = $row;
    }

    /* ── when: weekday x hour ── */
    $heat = array_fill(0, 7, array_fill(0, 24, 0));
    $heatRev = array_fill(0, 7, 0);
    foreach (q($conn, "SELECT WEEKDAY(o.created_at) wd, HOUR(o.created_at) h, COUNT(*) n, SUM(o.total) s FROM orders o WHERE o.restaurant_id = ? AND o.created_at >= ? AND " . LIVE . " GROUP BY wd, h", $P) as $r) {
        $heat[(int)$r['wd']][(int)$r['h']] = (int)$r['n'];
        $heatRev[(int)$r['wd']] += (float)$r['s'];
    }

    /* ── channels ── */
    $channels = [
        'source' => q($conn, "SELECT COALESCE(NULLIF(o.source,''),'pos') k, COUNT(*) n, SUM(o.total) s FROM orders o WHERE o.restaurant_id = ? AND o.created_at >= ? AND " . LIVE . " GROUP BY k ORDER BY s DESC", $P)->fetchAll(PDO::FETCH_ASSOC),
        'type' => q($conn, "SELECT COALESCE(NULLIF(o.order_type,''),'Other') k, COUNT(*) n, SUM(o.total) s FROM orders o WHERE o.restaurant_id = ? AND o.created_at >= ? AND " . LIVE . " GROUP BY k ORDER BY s DESC", $P)->fetchAll(PDO::FETCH_ASSOC),
        'payment' => q($conn, "SELECT COALESCE(NULLIF(o.payment_method,''),'Not set') k, COUNT(*) n, SUM(o.total) s FROM orders o WHERE o.restaurant_id = ? AND o.created_at >= ? AND " . LIVE . " GROUP BY k ORDER BY s DESC", $P)->fetchAll(PDO::FETCH_ASSOC),
    ];

    /* ── menu engineering ── */
    $items = q($conn, "SELECT COALESCE(mi.id, 0) id, COALESCE(mi.item_name_en, oi.item_name) name, COALESCE(mi.item_category, '') category,
            SUM(oi.quantity) qty, SUM(oi.total_price) revenue, COUNT(DISTINCT o.id) orders
        FROM order_items oi JOIN orders o ON o.id = oi.order_id
        LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id AND mi.restaurant_id = o.restaurant_id
        WHERE o.restaurant_id = ? AND o.created_at >= ? AND " . LIVE . "
        GROUP BY COALESCE(mi.id, 0), COALESCE(mi.item_name_en, oi.item_name), COALESCE(mi.item_category, '')
        HAVING qty > 0 ORDER BY revenue DESC", $P)->fetchAll(PDO::FETCH_ASSOC);
    // cost per plate from inventory recipes, where the restaurant has set them up
    $cost = [];
    if (hasTable($conn, 'inventory_recipes') && hasTable($conn, 'inventory_items')) {
        require_once __DIR__ . '/../config/inventory_engine.php';
        foreach (q($conn, "SELECT r.menu_item_id, r.quantity, r.unit, i.unit iunit, i.cost_per_unit FROM inventory_recipes r JOIN inventory_items i ON i.id = r.inventory_item_id
                WHERE r.restaurant_id = ? AND i.restaurant_id = ?", [$rid, $rid]) as $r) {
            $q = function_exists('invConvert') ? invConvert((float)$r['quantity'], (string)$r['unit'], (string)$r['iunit']) : (float)$r['quantity'];
            $cost[(int)$r['menu_item_id']] = ($cost[(int)$r['menu_item_id']] ?? 0) + $q * (float)$r['cost_per_unit'];
        }
    }
    $totalQty = array_sum(array_column($items, 'qty')) ?: 1;
    $totalRev = array_sum(array_column($items, 'revenue')) ?: 1;
    $n = count($items) ?: 1;
    $withCost = 0;
    foreach ($items as &$it) {
        $it['qty'] = (int)$it['qty']; $it['revenue'] = round((float)$it['revenue'], 2); $it['orders'] = (int)$it['orders'];
        $it['avg_price'] = $it['qty'] ? round($it['revenue'] / $it['qty'], 2) : 0;
        $it['plate_cost'] = isset($cost[(int)$it['id']]) ? round($cost[(int)$it['id']], 2) : null;
        if ($it['plate_cost'] !== null) $withCost++;
        // contribution margin per plate: price minus food cost (price alone when no recipe)
        $it['margin'] = $it['plate_cost'] !== null ? round($it['avg_price'] - $it['plate_cost'], 2) : $it['avg_price'];
        $it['food_cost_pct'] = $it['plate_cost'] !== null && $it['avg_price'] > 0 ? round($it['plate_cost'] * 100 / $it['avg_price'], 1) : null;
        $it['qty_share'] = round($it['qty'] * 100 / $totalQty, 2);
        $it['rev_share'] = round($it['revenue'] * 100 / $totalRev, 2);
    }
    unset($it);
    // Kasavana-Smith: popular when share >= 70% of an even share; profitable when margin >= the sales-weighted average margin
    $avgMargin = $totalQty ? array_sum(array_map(function ($i) { return $i['margin'] * $i['qty']; }, $items)) / $totalQty : 0;
    $popLine = 0.7 * 100 / $n;
    foreach ($items as &$it) {
        $pop = $it['qty_share'] >= $popLine; $prof = $it['margin'] >= $avgMargin;
        $it['class'] = $pop && $prof ? 'star' : ($pop ? 'workhorse' : ($prof ? 'puzzle' : 'dog'));
    }
    unset($it);
    // how many dishes make 80% of sales
    $acc = 0; $pareto = 0;
    foreach ($items as $it) { $acc += $it['revenue']; $pareto++; if ($acc >= 0.8 * $totalRev) break; }
    $menuTotal = (int)q($conn, "SELECT COUNT(*) FROM menu_items WHERE restaurant_id = ? AND COALESCE(is_hidden,0) = 0", [$rid])->fetchColumn();
    $unsold = q($conn, "SELECT mi.id, mi.item_name_en name, mi.item_category category, mi.base_price price FROM menu_items mi
        WHERE mi.restaurant_id = ? AND COALESCE(mi.is_hidden,0) = 0 AND mi.is_available = 1
          AND NOT EXISTS (SELECT 1 FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.menu_item_id = mi.id AND o.restaurant_id = mi.restaurant_id AND o.created_at >= ? AND " . LIVE . ")
        ORDER BY mi.item_category, mi.item_name_en LIMIT 60", $P)->fetchAll(PDO::FETCH_ASSOC);
    $categories = [];
    foreach ($items as $it) { $c = $it['category'] !== '' ? $it['category'] : 'Other'; $categories[$c] = ($categories[$c] ?? 0) + $it['revenue']; }
    arsort($categories);
    // what sells together (top pairs) — for combos
    $pairs = q($conn, "SELECT a.item_name x, b.item_name y, COUNT(DISTINCT a.order_id) n FROM order_items a JOIN order_items b ON b.order_id = a.order_id AND a.item_name < b.item_name
        JOIN orders o ON o.id = a.order_id WHERE o.restaurant_id = ? AND o.created_at >= ? AND " . LIVE . " GROUP BY x, y HAVING n >= 2 ORDER BY n DESC LIMIT 6", $P)->fetchAll(PDO::FETCH_ASSOC);
    $single = (int)q($conn, "SELECT COUNT(*) FROM (SELECT o.id FROM orders o JOIN order_items oi ON oi.order_id = o.id WHERE o.restaurant_id = ? AND o.created_at >= ? AND " . LIVE . " GROUP BY o.id HAVING SUM(oi.quantity) = 1) t", $P)->fetchColumn();

    /* ── customers ── */
    $top = q($conn, "SELECT MAX(o.customer_name) name, o.customer_phone phone, COUNT(*) n, SUM(o.total) s, MAX(o.created_at) last_at FROM orders o
        WHERE o.restaurant_id = ? AND o.created_at >= ? AND " . LIVE . " AND o.customer_phone <> '' GROUP BY o.customer_phone ORDER BY s DESC LIMIT 8", $P)->fetchAll(PDO::FETCH_ASSOC);
    $lapsed = q($conn, "SELECT MAX(o.customer_name) name, o.customer_phone phone, COUNT(*) n, SUM(o.total) s, MAX(o.created_at) last_at,
            DATEDIFF(NOW(), MAX(o.created_at)) days_away
        FROM orders o WHERE o.restaurant_id = ? AND " . LIVE . " AND o.customer_phone <> ''
        GROUP BY o.customer_phone HAVING n >= 3 AND days_away BETWEEN 21 AND 180 ORDER BY s DESC LIMIT 10", [$rid])->fetchAll(PDO::FETCH_ASSOC);
    $lapsedCount = (int)q($conn, "SELECT COUNT(*) FROM (SELECT o.customer_phone FROM orders o WHERE o.restaurant_id = ? AND " . LIVE . " AND o.customer_phone <> ''
        GROUP BY o.customer_phone HAVING COUNT(*) >= 3 AND DATEDIFF(NOW(), MAX(o.created_at)) BETWEEN 21 AND 180) t", [$rid])->fetchColumn();
    $life = q($conn, "SELECT COUNT(*) c, AVG(n) avg_orders, AVG(s) avg_spend FROM (SELECT COUNT(*) n, SUM(o.total) s FROM orders o WHERE o.restaurant_id = ? AND " . LIVE . " AND o.customer_phone <> '' GROUP BY o.customer_phone) t", [$rid])->fetch(PDO::FETCH_ASSOC);
    $mask = function ($p) { $d = preg_replace('/\D/', '', (string)$p); return strlen($d) >= 6 ? substr($d, 0, 2) . str_repeat('•', max(0, strlen($d) - 5)) . substr($d, -3) : $p; };
    foreach ($top as &$t) { $t['phone_masked'] = $mask($t['phone']); unset($t['phone']); }
    unset($t);
    foreach ($lapsed as &$t) { $t['phone_masked'] = $mask($t['phone']); unset($t['phone']); }
    unset($t);

    /* ── website: visits to orders ── */
    $web = ['visitors' => 0, 'visits' => 0, 'orders' => 0, 'conversion' => 0, 'prev_conversion' => 0, 'pages' => []];
    if (hasTable($conn, 'page_visits')) {
        $v = q($conn, "SELECT COUNT(*) visits, COUNT(DISTINCT visitor_ip) visitors FROM page_visits WHERE restaurant_id = ? AND visited_at >= ?", $P)->fetch(PDO::FETCH_ASSOC);
        $vp = q($conn, "SELECT COUNT(DISTINCT visitor_ip) FROM page_visits WHERE restaurant_id = ? AND visited_at >= ? AND visited_at < ?", $PP)->fetchColumn();
        $wo = q($conn, "SELECT COUNT(*) FROM orders o WHERE o.restaurant_id = ? AND o.created_at >= ? AND o.source = 'website' AND " . LIVE, $P)->fetchColumn();
        $wop = q($conn, "SELECT COUNT(*) FROM orders o WHERE o.restaurant_id = ? AND o.created_at >= ? AND o.created_at < ? AND o.source = 'website' AND " . LIVE, $PP)->fetchColumn();
        $web['visits'] = (int)$v['visits']; $web['visitors'] = (int)$v['visitors']; $web['orders'] = (int)$wo;
        $web['conversion'] = $web['visitors'] ? round($wo * 100 / $web['visitors'], 1) : 0;
        $web['prev_conversion'] = $vp ? round($wop * 100 / $vp, 1) : 0;
        $web['pages'] = q($conn, "SELECT COALESCE(NULLIF(page_name,''), page_url) page, COUNT(*) n FROM page_visits WHERE restaurant_id = ? AND visited_at >= ? GROUP BY page ORDER BY n DESC LIMIT 6", $P)->fetchAll(PDO::FETCH_ASSOC);
        $web['sources'] = q($conn, "SELECT CASE WHEN COALESCE(referrer_url,'') = '' THEN 'Direct / QR' WHEN referrer_url LIKE '%google%' THEN 'Google' WHEN referrer_url LIKE '%instagram%' THEN 'Instagram'
                WHEN referrer_url LIKE '%facebook%' OR referrer_url LIKE '%fb.%' THEN 'Facebook' WHEN referrer_url LIKE '%whatsapp%' OR referrer_url LIKE '%wa.me%' THEN 'WhatsApp' ELSE 'Other sites' END src, COUNT(DISTINCT visitor_ip) n
            FROM page_visits WHERE restaurant_id = ? AND visited_at >= ? GROUP BY src ORDER BY n DESC", $P)->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ── reviews ── */
    $reviews = ['avg' => null, 'count' => 0, 'dist' => [], 'low' => []];
    if (hasTable($conn, 'order_feedback')) {
        $r = q($conn, "SELECT AVG(rating) a, COUNT(*) c FROM order_feedback WHERE restaurant_id = ? AND created_at >= ?", $P)->fetch(PDO::FETCH_ASSOC);
        $rp = q($conn, "SELECT AVG(rating) FROM order_feedback WHERE restaurant_id = ? AND created_at >= ? AND created_at < ?", $PP)->fetchColumn();
        $reviews['avg'] = $r['c'] ? round((float)$r['a'], 2) : null; $reviews['count'] = (int)$r['c']; $reviews['prev_avg'] = $rp !== null ? round((float)$rp, 2) : null;
        $reviews['dist'] = q($conn, "SELECT rating, COUNT(*) n FROM order_feedback WHERE restaurant_id = ? AND created_at >= ? GROUP BY rating", $P)->fetchAll(PDO::FETCH_KEY_PAIR);
        $reviews['low'] = q($conn, "SELECT rating, review, customer_name, order_number, created_at FROM order_feedback WHERE restaurant_id = ? AND created_at >= ? AND rating <= 3 AND COALESCE(review,'') <> '' ORDER BY id DESC LIMIT 5", $P)->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ── why orders were stopped (reason codes from the reject / cancel popup) ── */
    $stopReasons = [];
    foreach (q($conn, "SELECT o.order_status, o.notes FROM orders o WHERE o.restaurant_id = ? AND o.created_at >= ? AND o.deleted_at IS NULL AND o.order_status IN ('Cancelled','Rejected')", $P) as $r) {
        $code = preg_match('/\[(?:Rejected|Cancelled) \(([a-z_]+)\)/', (string)$r['notes'], $m) ? $m[1] : 'not_given';
        $stopReasons[$code] = ($stopReasons[$code] ?? 0) + 1;
    }
    arsort($stopReasons);

    /* ── next 7 days: average of the same weekday over the last 4 weeks ── */
    $wk = q($conn, "SELECT DATE(o.created_at) d, COUNT(*) n, SUM(o.total) s FROM orders o WHERE o.restaurant_id = ? AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 28 DAY) AND o.created_at < CURDATE() AND " . LIVE . " GROUP BY DATE(o.created_at)", [$rid])->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $forecast = [];
    for ($i = 0; $i < 7; $i++) {
        $d = strtotime(date('Y-m-d', strtotime($now))) + $i * 86400;
        $n = 0; $s = 0;
        for ($w = 1; $w <= 4; $w++) { $past = date('Y-m-d', $d - $w * 7 * 86400); $n += (int)($wk[$past]['n'] ?? 0); $s += (float)($wk[$past]['s'] ?? 0); }
        $forecast[] = ['date' => date('Y-m-d', $d), 'day' => date('D', $d), 'orders' => round($n / 4, 1), 'revenue' => round($s / 4)];
    }

    /* ── what to do: plain-language tips worked out from the numbers above ── */
    $tips = [];
    $cs = $_SESSION['currency_symbol'] ?? '₹';
    $money = function ($v) use ($cs) { return $cs . number_format((float)$v, 0); };
    $wdNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $tip = function ($kind, $icon, $title, $text, $action = null) use (&$tips) { $tips[] = compact('kind', 'icon', 'title', 'text', 'action'); };
    if ($cur['orders'] >= 5) {
        if ($change['revenue'] !== null && $change['revenue'] <= -10) $tip('bad', 'trending_down', 'Sales are down ' . abs($change['revenue']) . '%', 'Compared with the ' . $days . ' days before. Check the slow days below and run an offer on them; message your regulars on WhatsApp.', ['page' => 'whatsappPage', 'label' => 'Message customers']);
        elseif ($change['revenue'] !== null && $change['revenue'] >= 10) $tip('good', 'trending_up', 'Sales are up ' . $change['revenue'] . '%', 'Keep what is working: the top dishes and busy hours below. Make sure the kitchen is staffed for the peak.');
        // weekday spread
        $wdSales = $heatRev; $nonzero = array_filter($wdSales);
        if (count($nonzero) >= 4) {
            $slow = array_keys($wdSales, min($nonzero))[0]; $best = array_keys($wdSales, max($wdSales))[0];
            if ($wdSales[$best] > 0 && $wdSales[$slow] < 0.6 * $wdSales[$best]) $tip('idea', 'event_busy', $wdNames[$slow] . ' is your slowest day', $wdNames[$slow] . ' brings ' . round($wdSales[$slow] * 100 / $wdSales[$best]) . '% of ' . $wdNames[$best] . '\'s sales. Try a ' . $wdNames[$slow] . '-only deal (combo, happy hour or free dessert) to fill it.', ['page' => 'dealsPage', 'label' => 'Create a deal']);
        }
        // peak hour
        $hourTot = array_fill(0, 24, 0); foreach ($heat as $row) foreach ($row as $h => $v) $hourTot[$h] += $v;
        $peakH = array_keys($hourTot, max($hourTot))[0];
        if (max($hourTot) > 0) $tip('info', 'schedule', 'Busiest time: ' . date('g A', mktime($peakH, 0)) . ' to ' . date('g A', mktime(($peakH + 1) % 24, 0)), round(max($hourTot) * 100 / max(1, array_sum($hourTot))) . '% of orders come in this hour. Prep popular dishes before it and keep enough staff on.');
        // single-item orders: upsell
        if ($cur['orders'] && $single / $cur['orders'] >= 0.35) $tip('idea', 'add_shopping_cart', round($single * 100 / $cur['orders']) . '% of orders have just one item', 'Add a drink or dessert suggestion at checkout, or a combo, to raise the average bill (now ' . $money($cur['aov']) . ').', ['page' => 'upsellPage', 'label' => 'Set up upsell']);
        if ($pairs) $tip('idea', 'join_inner', 'Make a combo: ' . $pairs[0]['x'] . ' + ' . $pairs[0]['y'], 'Ordered together ' . $pairs[0]['n'] . ' times. A small combo discount makes more people add both.', ['page' => 'dealsPage', 'label' => 'Create a combo']);
        if ($cur['customers'] >= 10 && $cur['repeat_rate'] < 25) $tip('bad', 'person_off', 'Only ' . $cur['repeat_rate'] . '% of customers ordered again', 'Most customers order once. A loyalty reward or a "come back" WhatsApp message with a small offer brings them back.', ['page' => 'growthSettingsPage', 'label' => 'Loyalty settings']);
        if ($lapsedCount > 0) $tip('idea', 'person_off', $lapsedCount . ' regular' . ($lapsedCount > 1 ? 's have' : ' has') . ' stopped ordering', 'They ordered 3+ times but not in the last 3 weeks. Send them a personal offer before they forget you. List below.', ['page' => 'whatsappPage', 'label' => 'Win them back']);
        if ($cur['cancel_rate'] >= 8) { $topR = $stopReasons ? array_key_first($stopReasons) : ''; $tip('bad', 'cancel', $cur['cancel_rate'] . '% of orders were rejected or cancelled', 'Main reason: ' . str_replace('_', ' ', $topR) . '. Fixing this is the fastest way to stop losing sales (mark sold-out dishes, keep timings updated).'); }
        if ($cur['revenue'] > 0 && $cur['discount'] / max(1, $cur['revenue'] + $cur['discount']) >= 0.12) $tip('info', 'sell', 'Discounts are ' . round($cur['discount'] * 100 / ($cur['revenue'] + $cur['discount'])) . '% of sales', 'That is ' . $money($cur['discount']) . ' given away. Check which coupons bring new customers and stop the ones that only discount regulars.');
    }
    $dogs = array_values(array_filter($items, function ($i) { return $i['class'] === 'dog'; }));
    $puzzles = array_values(array_filter($items, function ($i) { return $i['class'] === 'puzzle'; }));
    $horses = array_values(array_filter($items, function ($i) { return $i['class'] === 'workhorse'; }));
    if (count($items) >= 6) {
        if ($puzzles) $tip('idea', 'visibility', 'Push ' . $puzzles[0]['name'], 'It earns well per plate but few people order it. Put it in Today\'s Special, add a better photo, or have staff recommend it.', ['page' => 'todaysSpecialPage', 'label' => 'Today\'s special']);
        if ($horses && $withCost) $tip('idea', 'paid', $horses[0]['name'] . ' sells a lot but earns little', 'Food cost ' . ($horses[0]['food_cost_pct'] ?? '?') . '%. A small price rise or a smaller portion barely changes demand for a favourite.');
        if (count($dogs) >= 3) $tip('info', 'delete', count($dogs) . ' dishes sell little and earn little', 'For example ' . implode(', ', array_slice(array_column($dogs, 'name'), 0, 3)) . '. A shorter menu is faster to cook and easier to choose from.');
        if ($pareto && $pareto <= max(3, (int)round(count($items) * 0.25))) $tip('info', 'star', $pareto . ' dish' . ($pareto > 1 ? 'es make' : ' makes') . ' 80% of your sales', 'Never run out of these. Keep them first on the menu and in stock (Inventory › reorder list).', ['page' => 'inventoryPage', 'label' => 'Check stock']);
    }
    if (count($unsold) >= 5 && $menuTotal) $tip('info', 'block', count($unsold) . ($menuTotal ? ' of ' . $menuTotal : '') . ' dishes were not ordered at all', 'In the last ' . $days . ' days. Hide or rework them, or feature one as a special.', ['page' => 'menuPage', 'label' => 'Open menu']);
    if ($web['visitors'] >= 30 && $web['conversion'] < 3) $tip('bad', 'shopping_cart_off', 'Only ' . $web['conversion'] . '% of website visitors order', 'Add photos to dishes, show offers at the top, and keep the menu short and clear. Faster ordering = more orders.', ['page' => 'settingsPage', 'label' => 'Website settings']);
    if ($reviews['avg'] !== null && $reviews['count'] >= 3 && $reviews['avg'] < 4) $tip('bad', 'sentiment_dissatisfied', 'Average rating ' . $reviews['avg'] . ' / 5', 'Read the low reviews below and reply; fixing the most common complaint lifts repeat orders.', ['page' => 'feedbackPage', 'label' => 'See reviews']);
    if (!$withCost && count($items) >= 3) $tip('info', 'calculate', 'Add recipes to see real profit per dish', 'Menu analysis below uses price only. In Inventory, add a recipe to your top dishes to see food cost and true margin.', ['page' => 'inventoryPage', 'label' => 'Add recipes']);
    $order = ['bad' => 0, 'idea' => 1, 'good' => 2, 'info' => 3];
    usort($tips, function ($a, $b) use ($order) { return $order[$a['kind']] <=> $order[$b['kind']]; });

    echo json_encode([
        'success' => true, 'days' => $days, 'now' => $now,
        'kpi' => $cur, 'prev' => $prev, 'change' => $change,
        'trend' => $trend, 'bucket' => $bucket, 'heat' => $heat, 'weekday_sales' => $heatRev,
        'channels' => $channels,
        'menu' => ['items' => array_slice($items, 0, 60), 'item_count' => count($items), 'menu_total' => $menuTotal, 'with_cost' => $withCost, 'avg_margin' => round($avgMargin, 2),
            'pareto' => $pareto, 'unsold' => $unsold, 'categories' => $categories, 'pairs' => $pairs, 'single_item_orders' => $single],
        'customers' => ['top' => $top, 'lapsed' => $lapsed, 'lapsed_count' => $lapsedCount,
            'lifetime' => ['customers' => (int)$life['c'], 'avg_orders' => round((float)$life['avg_orders'], 1), 'avg_spend' => round((float)$life['avg_spend'])]],
        'web' => $web, 'reviews' => $reviews, 'stop_reasons' => $stopReasons, 'forecast' => $forecast, 'tips' => $tips,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('[analytics] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load analytics. Please try again.']);
}
