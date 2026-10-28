<?php
// Multi-branch overview — landing page for branch-admin (multi-outlet) logins.
// Today's numbers for all branches against yesterday, a 7-day chart by branch, every branch's
// card (open its dashboard or website), best sellers across branches and old orders still left
// open. When Super Admin made the outlets a brand, a second tab holds the brand website settings
// (assets/js/brand-studio.js). Jumping into a branch goes through api/switch_restaurant.php.
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

if (!isSessionValid() || !isset($_SESSION['branch_admin_id']) || empty($_SESSION['linked_restaurants'])) {
    header('Location: ../admin/login.php');
    exit();
}

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/brand.php';
date_default_timezone_set('Asia/Kolkata');

$branches = [];
$off = [];
$days = [];
for ($i = 6; $i >= 0; $i--) $days[] = date('Y-m-d', strtotime("-$i day"));
$totals = ['revenue' => 0, 'orders' => 0, 'paid_orders' => 0, 'open' => 0, 'stale' => 0, 'kot' => 0, 'week_revenue' => 0, 'y_revenue' => 0, 'y_orders' => 0];
$currency = '₹';
$brand = null;
$ownerName = '';
$topDishes = [];

try {
    $pdo = getConnection();
    $adminId = (int)$_SESSION['branch_admin_id'];

    // Refresh the linked list from the DB so newly linked branches show up
    // without a re-login, and keep the session copy in sync for switching.
    $linkStmt = $pdo->prepare("
        SELECT brl.restaurant_id, brl.label, u.restaurant_name, u.address, u.phone,
               u.currency_symbol, u.is_active, u.subscription_status
        FROM branch_restaurant_links brl
        JOIN users u ON u.restaurant_id = brl.restaurant_id
        WHERE brl.branch_admin_id = ?
        ORDER BY brl.id
    ");
    $linkStmt->execute([$adminId]);
    $linked = $linkStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($linked) {
        $_SESSION['linked_restaurants'] = array_map(function ($r) {
            return ['restaurant_id' => $r['restaurant_id'], 'label' => $r['label'], 'restaurant_name' => $r['restaurant_name']];
        }, $linked);
    }

    $st = $pdo->prepare('SELECT display_name FROM branch_admins WHERE id = ?');
    $st->execute([$adminId]);
    $ownerName = (string)$st->fetchColumn();

    // a brand's outlets in the brand's order (the picker order); others after them, as linked
    $brand = brandForOwner($pdo, $adminId);
    if ($brand) {
        $order = array_flip(array_column(brandOutlets($pdo, (int)$brand['id']), 'restaurant_id'));
        usort($linked, fn($a, $b) => ($order[$a['restaurant_id']] ?? 99) <=> ($order[$b['restaurant_id']] ?? 99));
    }

    // each figure for every branch at once, grouped by restaurant
    $rids = array_column($linked, 'restaurant_id');
    $in = implode(',', array_fill(0, max(1, count($rids)), '?'));
    $per = function (string $sql, array $extra = []) use ($pdo, $rids, $in): array {
        if (!$rids) return [];
        $st = $pdo->prepare(str_replace('%IN%', $in, $sql));
        $st->execute(array_merge($rids, $extra));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $keyed = function (array $rows): array {
        $out = [];
        foreach ($rows as $r) $out[strtoupper((string)$r['rid'])] = $r;
        return $out;
    };
    // yesterday = yesterday up to this time of day, so the morning isn't compared with a whole day;
    // open orders: only the last 24 hours count as "open now"; older ones are orders nobody closed
    $stats = $keyed($per("
        SELECT restaurant_id AS rid,
          COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() AND payment_status = 'Paid' THEN total END), 0) AS today_revenue,
          COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() AND payment_status = 'Paid' THEN 1 END), 0) AS today_paid,
          COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() AND order_status NOT IN ('Cancelled','Rejected') THEN 1 END), 0) AS today_orders,
          COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() - INTERVAL 1 DAY AND created_at <= NOW() - INTERVAL 1 DAY AND payment_status = 'Paid' THEN total END), 0) AS y_revenue,
          COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() - INTERVAL 1 DAY AND created_at <= NOW() - INTERVAL 1 DAY AND order_status NOT IN ('Cancelled','Rejected') THEN 1 END), 0) AS y_orders,
          COALESCE(SUM(CASE WHEN order_status IN ('Pending','Accepted','Preparing','Ready') AND created_at >= NOW() - INTERVAL 1 DAY THEN 1 END), 0) AS open_orders,
          COALESCE(SUM(CASE WHEN order_status IN ('Pending','Accepted','Preparing','Ready') AND created_at < NOW() - INTERVAL 1 DAY THEN 1 END), 0) AS stale_orders,
          COALESCE(SUM(CASE WHEN created_at >= CURDATE() - INTERVAL 6 DAY AND payment_status = 'Paid' THEN total END), 0) AS week_revenue
        FROM orders
        WHERE restaurant_id IN (%IN%) AND deleted_at IS NULL GROUP BY restaurant_id
    "));
    $daily = [];
    foreach ($per("SELECT restaurant_id AS rid, DATE(created_at) AS d, SUM(total) AS rev FROM orders
        WHERE restaurant_id IN (%IN%) AND deleted_at IS NULL AND payment_status = 'Paid' AND created_at >= CURDATE() - INTERVAL 6 DAY
        GROUP BY restaurant_id, DATE(created_at)") as $r) {
        $daily[strtoupper((string)$r['rid'])][$r['d']] = (float)$r['rev'];
    }
    $kots = $keyed($per("SELECT restaurant_id AS rid, COUNT(*) n FROM kot WHERE restaurant_id IN (%IN%) AND kot_status IN ('Pending','Preparing') AND created_at >= NOW() - INTERVAL 1 DAY GROUP BY restaurant_id"));
    $menus = $keyed($per("SELECT restaurant_id AS rid, COUNT(*) n FROM menu_items WHERE restaurant_id IN (%IN%) GROUP BY restaurant_id"));
    $tables = $keyed($per("SELECT restaurant_id AS rid, COUNT(*) AS total, COALESCE(SUM(is_available = 0), 0) AS occupied FROM tables WHERE restaurant_id IN (%IN%) GROUP BY restaurant_id"));
    $staffs = $keyed($per("SELECT restaurant_id AS rid, COUNT(*) n FROM staff WHERE restaurant_id IN (%IN%) AND is_active = 1 GROUP BY restaurant_id"));
    // best sellers across branches, last 7 days (cancelled orders left out)
    $dishRows = $per("SELECT o.restaurant_id AS rid, oi.item_name AS name, SUM(oi.quantity) AS qty, SUM(oi.total_price) AS amount
        FROM order_items oi JOIN orders o ON o.id = oi.order_id
        WHERE o.restaurant_id IN (%IN%) AND o.deleted_at IS NULL AND o.order_status NOT IN ('Cancelled','Rejected') AND o.created_at >= CURDATE() - INTERVAL 6 DAY
        GROUP BY o.restaurant_id, oi.item_name");

    foreach ($linked as $b) {
        $k = strtoupper((string)$b['restaurant_id']);
        $s = $stats[$k] ?? [];
        $t = $tables[$k] ?? [];
        $row = $b + [
            'name' => $b['label'] ?: $b['restaurant_name'],
            'today_revenue' => (float)($s['today_revenue'] ?? 0),
            'today_paid' => (int)($s['today_paid'] ?? 0),
            'today_orders' => (int)($s['today_orders'] ?? 0),
            'y_revenue' => (float)($s['y_revenue'] ?? 0),
            'y_orders' => (int)($s['y_orders'] ?? 0),
            'open_orders' => (int)($s['open_orders'] ?? 0),
            'stale_orders' => (int)($s['stale_orders'] ?? 0),
            'week_revenue' => (float)($s['week_revenue'] ?? 0),
            'days' => array_map(fn($d) => $daily[$k][$d] ?? 0, $days),
            'active_kot' => (int)($kots[$k]['n'] ?? 0),
            'menu_items' => (int)($menus[$k]['n'] ?? 0),
            'tables_total' => (int)($t['total'] ?? 0),
            'tables_occupied' => (int)($t['occupied'] ?? 0),
            'staff' => (int)($staffs[$k]['n'] ?? 0),
        ];
        if ((int)$b['is_active'] !== 1) { $off[] = $row; continue; }
        $branches[] = $row;
        $totals['revenue'] += $row['today_revenue'];
        $totals['paid_orders'] += $row['today_paid'];
        $totals['orders'] += $row['today_orders'];
        $totals['y_revenue'] += $row['y_revenue'];
        $totals['y_orders'] += $row['y_orders'];
        $totals['open'] += $row['open_orders'];
        $totals['stale'] += $row['stale_orders'];
        $totals['kot'] += $row['active_kot'];
        $totals['week_revenue'] += $row['week_revenue'];
        if (!empty($b['currency_symbol'])) $currency = $b['currency_symbol'];
    }

    $names = array_column($branches, 'name', 'restaurant_id');
    $dishes = [];
    foreach ($dishRows as $r) {
        if (!isset($names[$r['rid']])) continue;
        $n = trim((string)$r['name']);
        $d = &$dishes[mb_strtolower($n)];
        $d['name'] = $d['name'] ?? $n;
        $d['qty'] = ($d['qty'] ?? 0) + (int)$r['qty'];
        $d['amount'] = ($d['amount'] ?? 0) + (float)$r['amount'];
        $d['by'][$names[$r['rid']]] = ($d['by'][$names[$r['rid']]] ?? 0) + (int)$r['qty'];
        unset($d);
    }
    usort($dishes, fn($a, $b) => $b['qty'] <=> $a['qty']);
    $topDishes = array_slice($dishes, 0, 6);
} catch (Exception $e) {
    error_log('branches.php: ' . $e->getMessage());
    $loadError = 'Could not load branch data. Please refresh.';
}

$currentRid = $_SESSION['restaurant_id'] ?? '';
$username = $_SESSION['username'] ?? '';
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$title = $brand ? $brand['name'] : 'All branches';
$aov = $totals['paid_orders'] > 0 ? $totals['revenue'] / $totals['paid_orders'] : 0;
// the chart shows up to 3 branches by colour; more are folded into "Other branches"
$series = [];
foreach ($branches as $i => $b) {
    if ($i < 3 || count($branches) === 4) $series[] = ['name' => $b['name'], 'days' => $b['days']];
    else {
        if (!isset($series[3])) $series[3] = ['name' => 'Other branches', 'days' => array_fill(0, 7, 0)];
        foreach ($b['days'] as $j => $v) $series[3]['days'][$j] += $v;
    }
}
$series = array_slice($series, 0, 4);
$dayTotals = array_map(fn($j) => array_sum(array_map(fn($s) => $s['days'][$j], $series)), range(0, 6));
$chartMax = max(1, ...$dayTotals);
$colorOf = [];
foreach ($branches as $i => $b) $colorOf[$b['restaurant_id']] = min($i, 3) + 1;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n, $c) { return $c . number_format($n, $n == floor($n) ? 0 : 2); }
function shortMoney($n, $c) {
    if ($n >= 10000000) return $c . round($n / 10000000, 1) . 'Cr';
    if ($n >= 100000) return $c . round($n / 100000, 1) . 'L';
    if ($n >= 1000) return $c . round($n / 1000, 1) . 'k';
    return $c . round($n);
}
/** "▲ 12% vs ₹900 by this time yesterday" (or a plain note when there is nothing to compare). */
function vsYesterday($now, $then, $c = null) {
    if ($then <= 0 && $now <= 0) return '<span class="delta flat">Same as yesterday by now</span>';
    if ($then <= 0) return '<span class="delta up">▲ New <span>· none by this time yesterday</span></span>';
    $p = round(($now - $then) / $then * 100);
    $y = $c === null ? (int)$then : money($then, $c);
    if ($p === 0.0 || $p == 0) return '<span class="delta flat">Same as yesterday (' . h($y) . ')</span>';
    return '<span class="delta ' . ($p > 0 ? 'up' : 'down') . '">' . ($p > 0 ? '▲ ' : '▼ ') . abs($p) . '% <span>vs ' . h($y) . ' by now yesterday</span></span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php /* network safety net (retries data requests, offline bar) + loading skeletons and Try again: assets/js/rg-load.js */
require_once __DIR__ . '/../website/rg_net.php'; echo rgNetScript(false); ?>
<script src="../assets/js/rg-load.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/rg-load.js'); ?>"></script>
<meta charset="UTF-8">
  <script src="../assets/js/quiet-console.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/quiet-console.js'); ?>"></script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?php echo h($title); ?> · Branches — RestroGrow</title>
<link rel="icon" type="image/png" href="../assets/images/logo-192.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?php require_once __DIR__ . '/../config/icon_font.php'; echo htmlspecialchars(rgIconFontUrl(), ENT_QUOTES); ?>">
<style>
  :root {
    --brand: #ff5a1f; --brand-soft: #fff1ea; --ink: #1f2937; --muted: #6b7280;
    --line: #e5e7eb; --bg: #f6f7fb; --card: #ffffff; --ok: #16a34a; --warn: #d97706; --bad: #dc2626;
    --s1: #2a78d6; --s2: #eb6834; --s3: #1baf7a; --s4: #9ca3af;
  }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'Poppins', system-ui, sans-serif; background: var(--bg); color: var(--ink); }
  .topbar { background: var(--card); border-bottom: 1px solid var(--line); position: sticky; top: 0; z-index: 10; }
  .topbar-inner { max-width: 1200px; margin: 0 auto; padding: 12px 16px; display: flex; align-items: center; gap: 16px; }
  .topbar img { height: 26px; width: auto; display: block; }
  .topbar .tag { font-size: 12px; font-weight: 600; color: var(--brand); background: var(--brand-soft); padding: 3px 10px; border-radius: 999px; }
  .topbar .spacer { flex: 1; }
  .topbar .user { font-size: 14px; color: var(--muted); }
  .btn { display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--line); background: var(--card); color: var(--ink);
         padding: 8px 14px; border-radius: 10px; font: 500 14px 'Poppins', sans-serif; cursor: pointer; text-decoration: none; }
  .btn:hover { border-color: #cbd5e1; }
  .btn-primary { background: var(--brand); border-color: var(--brand); color: #fff; }
  .btn-primary:hover { background: #e94e14; border-color: #e94e14; }
  .btn .material-symbols-rounded { font-size: 18px; }
  main { max-width: 1200px; margin: 0 auto; padding: 24px 16px 48px; }
  .head { display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
  .head .logo { width: 56px; height: 56px; border-radius: 14px; object-fit: cover; background: #fff; border: 1px solid var(--line); }
  .head .who { flex: 1; min-width: 220px; }
  h1 { font-size: 24px; margin: 0 0 2px; }
  .sub { color: var(--muted); margin: 0; font-size: 14px; }
  .sub .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--ok); margin-right: 4px; vertical-align: 1px; }
  .head-actions { display: flex; gap: 8px; flex-wrap: wrap; }
  .tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--line); margin-bottom: 20px; }
  .tab { background: none; border: 0; border-bottom: 2px solid transparent; padding: 10px 14px; font: 500 14px 'Poppins', sans-serif; color: var(--muted); cursor: pointer; display: inline-flex; gap: 6px; align-items: center; }
  .tab .material-symbols-rounded { font-size: 18px; }
  .tab.on { color: var(--ink); border-bottom-color: var(--brand); }
  .panel[hidden] { display: none; }
  .summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 20px; }
  .stat { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 16px; }
  .stat .label { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
  .stat .value { font-size: 26px; font-weight: 700; margin: 4px 0 2px; font-variant-numeric: tabular-nums; }
  .stat .value.warn { color: var(--warn); }
  .delta { font-size: 12px; font-weight: 600; }
  .delta span { font-weight: 400; color: var(--muted); }
  .delta.up { color: var(--ok); } .delta.down { color: var(--bad); } .delta.flat { color: var(--muted); font-weight: 400; }
  .hint { font-size: 12px; color: var(--muted); }
  .alert { display: flex; gap: 10px; align-items: flex-start; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 12px; padding: 12px 14px; font-size: 13px; margin-bottom: 20px; }
  .alert .material-symbols-rounded { font-size: 20px; }
  .alert button { background: none; border: 0; padding: 0; color: inherit; font: inherit; font-weight: 600; text-decoration: underline; cursor: pointer; }
  .row2 { display: grid; grid-template-columns: 1.6fr 1fr; gap: 16px; margin-bottom: 24px; }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 18px; min-width: 0; }
  .card h2 { font-size: 16px; margin: 0 0 2px; }
  .card .hint { margin: 0 0 14px; }
  .legend { display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: 12px; color: var(--muted); margin-bottom: 10px; }
  .legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 6px; vertical-align: -1px; }
  .chart { position: relative; }
  .chart { padding-left: 46px; }
  .chart .plot { position: relative; }
  .chart svg { display: block; width: 100%; height: 196px; overflow: visible; }
  .chart .grid line { stroke: var(--line); stroke-width: 1; }
  .yaxis { position: absolute; left: 0; top: 0; width: 40px; height: 196px; }
  .yaxis span { position: absolute; right: 0; transform: translateY(-50%); font-size: 11px; color: var(--muted); font-variant-numeric: tabular-nums; }
  .xaxis { display: grid; grid-template-columns: repeat(7, 1fr); text-align: center; font-size: 11px; color: var(--muted); margin-top: 6px; }
  .xaxis .today { color: var(--ink); font-weight: 600; }
  .chart .col { cursor: default; }
  .chart .col:hover .hit { fill: rgba(17, 24, 39, .04); }
  .tip { position: absolute; pointer-events: none; background: #111827; color: #fff; border-radius: 10px; padding: 8px 10px; font-size: 12px; line-height: 1.5; white-space: nowrap; transform: translate(-50%, -100%); opacity: 0; transition: opacity .1s; z-index: 5; }
  .tip.show { opacity: 1; }
  .tip i { display: inline-block; width: 8px; height: 8px; border-radius: 2px; margin-right: 6px; }
  .tip b { font-weight: 600; }
  .dishes { list-style: none; margin: 0; padding: 0; }
  .dishes li { display: grid; grid-template-columns: 22px 1fr auto; gap: 8px; align-items: start; padding: 8px 0; border-top: 1px solid var(--line); font-size: 13px; }
  .dishes li:first-child { border-top: 0; }
  .dishes .n { color: var(--muted); font-weight: 600; }
  .dishes .q { font-weight: 600; font-variant-numeric: tabular-nums; text-align: right; }
  .dishes small { display: block; color: var(--muted); font-size: 11px; }
  .section-title { display: flex; align-items: baseline; justify-content: space-between; margin: 0 0 12px; gap: 12px; }
  .section-title h2 { font-size: 17px; margin: 0; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 16px; }
  .branch { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 18px; display: flex; flex-direction: column; gap: 14px; }
  .branch.current { border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-soft); }
  .branch-head { display: flex; align-items: flex-start; gap: 12px; }
  .avatar { width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; font-weight: 700; flex: none; color: #fff; }
  .avatar.c1 { background: var(--s1); } .avatar.c2 { background: var(--s2); } .avatar.c3 { background: var(--s3); } .avatar.c4 { background: var(--s4); }
  .branch-name { font-weight: 600; font-size: 16px; line-height: 1.3; }
  .branch-meta { font-size: 12px; color: var(--muted); margin-top: 2px; line-height: 1.4; }
  .pill { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 999px; margin-left: auto; white-space: nowrap; }
  .pill.on { background: #dcfce7; color: var(--ok); }
  .pill.off { background: #f3f4f6; color: var(--muted); }
  .pill.here { background: var(--brand-soft); color: var(--brand); }
  .pill.top { background: #fef3c7; color: #92400e; }
  .kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
  .kpi { background: var(--bg); border-radius: 10px; padding: 10px; }
  .kpi .k { font-size: 11px; color: var(--muted); }
  .kpi .v { font-size: 17px; font-weight: 600; font-variant-numeric: tabular-nums; }
  .kpi .v.warn { color: var(--warn); }
  .kpi .delta { font-size: 11px; display: block; }
  .kpi .delta span { display: none; }
  .spark { display: flex; align-items: center; gap: 10px; font-size: 12px; color: var(--muted); }
  .spark svg { flex: 1; min-width: 0; height: 34px; }
  .spark > span { white-space: nowrap; }
  .spark b { color: var(--ink); font-variant-numeric: tabular-nums; }
  .facts { display: flex; flex-wrap: wrap; gap: 6px 14px; font-size: 12px; color: var(--muted); }
  .facts span { display: inline-flex; align-items: center; gap: 4px; }
  .facts .material-symbols-rounded { font-size: 16px; }
  .facts .warn { color: var(--warn); font-weight: 500; }
  .actions { display: grid; grid-template-columns: 1fr auto; gap: 8px; }
  .actions .btn-primary { justify-content: center; }
  .offlist { margin-top: 20px; }
  .offlist summary { cursor: pointer; color: var(--muted); font-size: 13px; }
  .offlist ul { margin: 8px 0 0; padding: 0; list-style: none; display: flex; flex-wrap: wrap; gap: 8px; }
  .offlist li { background: var(--card); border: 1px dashed var(--line); border-radius: 10px; padding: 8px 12px; font-size: 13px; color: var(--muted); }
  .empty, .error { background: var(--card); border: 1px dashed var(--line); border-radius: 14px; padding: 32px; text-align: center; color: var(--muted); }
  .error { border-color: #fca5a5; color: #b91c1c; }
  .overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, .45); display: none; place-items: center; z-index: 50; }
  .overlay.show { display: grid; }
  .overlay div { background: #fff; padding: 22px 30px; border-radius: 14px; font-weight: 500; }
  .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
  @media (max-width: 900px) { .row2 { grid-template-columns: 1fr; } }
  @media (max-width: 640px) {
    .topbar .user, .topbar .tag { display: none; }
    .grid { grid-template-columns: 1fr; }
    .summary { grid-template-columns: repeat(2, 1fr); }
    .stat .value { font-size: 20px; }
    .stat .delta span { display: none; }
    .head-actions { width: 100%; }
  }
</style>
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <img src="../assets/images/logo-transparent.png" alt="RestroGrow">
    <span class="tag">Multi-branch</span>
    <span class="spacer"></span>
    <span class="user">Signed in as <strong><?php echo h($username); ?></strong></span>
    <button class="btn" type="button" id="logoutBtn"><span class="material-symbols-rounded">logout</span>Log out</button>
  </div>
</header>

<main>
  <div class="head">
    <?php if ($brand && !empty($branches)): ?>
      <img class="logo" src="../website/image.php?type=logo&id=<?php echo (int)(brandOutlets($pdo, (int)$brand['id'])[0]['user_id'] ?? 0); ?>" alt="" onerror="this.remove()">
    <?php endif; ?>
    <div class="who">
      <h1><?php echo h($title); ?></h1>
      <p class="sub"><?php echo h($greeting . ($ownerName !== '' ? ', ' . $ownerName : '')); ?> · <?php echo date('l, j M'); ?> · <?php echo count($branches); ?> branch<?php echo count($branches) === 1 ? '' : 'es'; ?>
        · <span class="dot" aria-hidden="true"></span><span id="updated">Updated <?php echo date('g:i A'); ?></span></p>
    </div>
    <div class="head-actions">
      <?php if ($brand): ?><a class="btn" href="<?php echo h(brandPickerUrl($brand, true)); ?>" target="_blank" rel="noopener"><span class="material-symbols-rounded">open_in_new</span>Website</a><?php endif; ?>
      <button class="btn" type="button" id="refreshBtn"><span class="material-symbols-rounded">refresh</span>Refresh</button>
    </div>
  </div>

  <?php if ($brand): ?>
  <nav class="tabs" role="tablist">
    <button class="tab on" type="button" role="tab" data-tab="overview" aria-selected="true"><span class="material-symbols-rounded">monitoring</span>Overview</button>
    <button class="tab" type="button" role="tab" data-tab="brand" aria-selected="false"><span class="material-symbols-rounded">language</span>Brand website</button>
  </nav>
  <?php endif; ?>

  <div class="panel" id="panel-overview">
  <?php if (!empty($loadError)): ?>
    <div class="error"><?php echo h($loadError); ?></div>
  <?php else: ?>
  <section class="summary" aria-label="Totals across all branches">
    <div class="stat"><div class="label">Revenue today</div><div class="value"><?php echo h(money($totals['revenue'], $currency)); ?></div><?php echo vsYesterday($totals['revenue'], $totals['y_revenue'], $currency); ?></div>
    <div class="stat"><div class="label">Orders today</div><div class="value"><?php echo (int)$totals['orders']; ?></div><?php echo vsYesterday($totals['orders'], $totals['y_orders']); ?></div>
    <div class="stat"><div class="label">Average bill</div><div class="value"><?php echo h(money(round($aov), $currency)); ?></div><span class="hint">Paid orders today</span></div>
    <div class="stat"><div class="label">Open now</div><div class="value<?php echo $totals['open'] ? ' warn' : ''; ?>"><?php echo (int)$totals['open']; ?></div><span class="hint"><?php echo (int)$totals['kot']; ?> KOT<?php echo $totals['kot'] == 1 ? '' : 's'; ?> in the kitchen</span></div>
    <div class="stat"><div class="label">Last 7 days</div><div class="value"><?php echo h(money($totals['week_revenue'], $currency)); ?></div><span class="hint">Paid, all branches</span></div>
  </section>

  <?php if ($totals['stale'] > 0):
    $staleList = array_filter($branches, fn($b) => $b['stale_orders'] > 0); ?>
    <div class="alert" role="status"><span class="material-symbols-rounded">schedule</span>
      <div><b><?php echo (int)$totals['stale']; ?> older order<?php echo $totals['stale'] == 1 ? ' is' : 's are'; ?> still marked open</b> (placed more than a day ago):
        <?php echo implode(', ', array_map(fn($b) => '<button type="button" data-rid="' . h($b['restaurant_id']) . '" data-page="ordersPage">' . h($b['name']) . ' (' . $b['stale_orders'] . ')</button>', $staleList)); ?>.
        Mark them completed or cancelled so “Open now” stays right.</div>
    </div>
  <?php endif; ?>

  <?php if ($branches): ?>
  <div class="row2">
    <section class="card" aria-labelledby="chartTitle">
      <h2 id="chartTitle">Revenue, last 7 days</h2>
      <p class="hint">Paid orders by day<?php echo count($branches) > 1 ? ', split by branch' : ''; ?></p>
      <?php if (count($series) > 1): ?>
      <div class="legend"><?php foreach ($series as $i => $s): ?><span><i style="background:var(--s<?php echo $i + 1; ?>)"></i><?php echo h($s['name']); ?></span><?php endforeach; ?></div>
      <?php endif; ?>
      <div class="chart" id="chart">
        <?php
          $W = 700; $H = 196; $padT = 6; $plotH = $H - $padT; $colW = $W / 7; $barW = min(44, $colW * 0.56);
          $step = $chartMax / 4;
        ?>
        <div class="yaxis" aria-hidden="true"><?php for ($g = 4; $g >= 0; $g--): ?><span style="top:<?php echo ($padT + $plotH - $plotH * $g / 4) / $H * 100; ?>%"><?php echo h(shortMoney($step * $g, $currency)); ?></span><?php endfor; ?></div>
        <div class="plot">
        <svg viewBox="0 0 <?php echo $W; ?> <?php echo $H; ?>" preserveAspectRatio="none" role="img" aria-label="Revenue by day for the last 7 days">
          <g class="grid">
            <?php for ($g = 0; $g <= 4; $g++): $y = $padT + $plotH - $plotH * $g / 4; ?>
              <line x1="0" x2="<?php echo $W; ?>" y1="<?php echo $y; ?>" y2="<?php echo $y; ?>" vector-effect="non-scaling-stroke"/>
            <?php endfor; ?>
          </g>
          <?php foreach ($days as $j => $d):
            $x = $colW * $j + ($colW - $barW) / 2; $y = $padT + $plotH;
            $tip = '<b>' . ($j === 6 ? 'Today, ' . date('j M', strtotime($d)) : date('D, j M', strtotime($d))) . '</b>';
            foreach ($series as $i => $s) $tip .= '<br><i style="background:var(--s' . ($i + 1) . ')"></i>' . h($s['name']) . ': ' . h(money($s['days'][$j], $currency));
            if (count($series) > 1) $tip .= '<br>Total: <b>' . h(money($dayTotals[$j], $currency)) . '</b>';
            $segs = array_values(array_filter(array_map(fn($i) => ['i' => $i, 'v' => $series[$i]['days'][$j]], array_keys($series)), fn($s) => $s['v'] > 0));
          ?>
          <g class="col" data-tip="<?php echo h($tip); ?>" data-x="<?php echo ($colW * $j + $colW / 2) / $W * 100; ?>" data-y="<?php echo ($padT + $plotH - $plotH * $dayTotals[$j] / $chartMax) / $H * 100; ?>">
            <rect class="hit" x="<?php echo $colW * $j; ?>" y="0" width="<?php echo $colW; ?>" height="<?php echo $H; ?>" fill="transparent"/>
            <?php foreach ($segs as $si => $s):
              $hgt = $plotH * $s['v'] / $chartMax; $y -= $hgt;
              $gap = $si > 0 ? 2 : 0; $isTop = $si === count($segs) - 1; ?>
              <?php if ($isTop && $hgt > 4): ?>
                <path d="M<?php echo $x; ?>,<?php echo $y + $hgt - $gap; ?> V<?php echo $y + 4; ?> Q<?php echo $x; ?>,<?php echo $y; ?> <?php echo $x + 4; ?>,<?php echo $y; ?> H<?php echo $x + $barW - 4; ?> Q<?php echo $x + $barW; ?>,<?php echo $y; ?> <?php echo $x + $barW; ?>,<?php echo $y + 4; ?> V<?php echo $y + $hgt - $gap; ?> Z" fill="var(--s<?php echo $s['i'] + 1; ?>)"/>
              <?php else: ?>
                <rect x="<?php echo $x; ?>" y="<?php echo $y; ?>" width="<?php echo $barW; ?>" height="<?php echo max(0, $hgt - $gap); ?>" fill="var(--s<?php echo $s['i'] + 1; ?>)"/>
              <?php endif; ?>
            <?php endforeach; ?>
          </g>
          <?php endforeach; ?>
        </svg>
        <div class="xaxis" aria-hidden="true"><?php foreach ($days as $j => $d): ?><span<?php echo $j === 6 ? ' class="today"' : ''; ?>><?php echo $j === 6 ? 'Today' : date('D', strtotime($d)); ?></span><?php endforeach; ?></div>
        <div class="tip" id="tip"></div>
        </div>
        <table class="sr"><caption>Paid revenue by day</caption>
          <tr><th>Day</th><?php foreach ($series as $s): ?><th><?php echo h($s['name']); ?></th><?php endforeach; ?></tr>
          <?php foreach ($days as $j => $d): ?><tr><td><?php echo h($d); ?></td><?php foreach ($series as $s): ?><td><?php echo h(money($s['days'][$j], $currency)); ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        </table>
      </div>
    </section>

    <section class="card" aria-labelledby="dishTitle">
      <h2 id="dishTitle">Best sellers, last 7 days</h2>
      <p class="hint">Across all branches, by plates sold</p>
      <?php if (!$topDishes): ?>
        <p class="hint">No orders in the last 7 days yet.</p>
      <?php else: ?>
      <ol class="dishes">
        <?php foreach ($topDishes as $i => $d): arsort($d['by']); ?>
          <li><span class="n"><?php echo $i + 1; ?></span>
            <span><?php echo h($d['name']); ?><?php if (count($branches) > 1): ?><small><?php echo h(implode(' · ', array_map(fn($n, $q) => "$n $q", array_keys($d['by']), $d['by']))); ?></small><?php endif; ?></span>
            <span class="q"><?php echo (int)$d['qty']; ?><small><?php echo h(shortMoney($d['amount'], $currency)); ?></small></span></li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
    </section>
  </div>
  <?php endif; ?>

  <div class="section-title"><h2>Branches</h2><span class="hint">Tap a branch to open its full dashboard</span></div>
  <?php if (!$branches): ?>
    <div class="empty">No active branches are linked to this account yet.</div>
  <?php else:
    $best = count($branches) > 1 ? max(array_column($branches, 'today_revenue')) : 0; ?>
  <section class="grid">
    <?php foreach ($branches as $b):
      $initials = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/[\s\-–]+/', trim($b['name'])), 0, 2))));
      $isCurrent = $b['restaurant_id'] === $currentRid;
      $c = $colorOf[$b['restaurant_id']] ?? 4;
      $smax = max(1, ...$b['days']);
      $pts = implode(' ', array_map(fn($v, $j) => round($j * 100 / 6, 1) . ',' . round(30 - $v / $smax * 26, 1), $b['days'], array_keys($b['days'])));
      $planOff = in_array((string)$b['subscription_status'], ['expired', 'disabled'], true);
    ?>
    <article class="branch<?php echo $isCurrent ? ' current' : ''; ?>">
      <div class="branch-head">
        <div class="avatar c<?php echo $c; ?>"><?php echo h($initials); ?></div>
        <div>
          <div class="branch-name"><?php echo h($b['name']); ?></div>
          <div class="branch-meta"><?php echo h($b['address'] ?: $b['restaurant_id']); ?></div>
        </div>
        <?php if ($planOff): ?><span class="pill off">Plan expired</span>
        <?php elseif ($best > 0 && $b['today_revenue'] == $best): ?><span class="pill top">Top today</span>
        <?php elseif ($isCurrent): ?><span class="pill here">Last opened</span>
        <?php else: ?><span class="pill on">Active</span><?php endif; ?>
      </div>

      <div class="kpis">
        <div class="kpi"><div class="k">Revenue today</div><div class="v"><?php echo h(money($b['today_revenue'], $currency)); ?></div><?php echo vsYesterday($b['today_revenue'], $b['y_revenue'], $currency); ?></div>
        <div class="kpi"><div class="k">Orders</div><div class="v"><?php echo $b['today_orders']; ?></div><?php echo vsYesterday($b['today_orders'], $b['y_orders']); ?></div>
        <div class="kpi"><div class="k">Open now</div><div class="v<?php echo $b['open_orders'] ? ' warn' : ''; ?>"><?php echo $b['open_orders']; ?></div><span class="hint"><?php echo $b['active_kot']; ?> KOT<?php echo $b['active_kot'] == 1 ? '' : 's'; ?></span></div>
      </div>

      <div class="spark" title="Paid revenue, last 7 days">
        <svg viewBox="0 0 100 32" preserveAspectRatio="none" aria-hidden="true"><polyline points="<?php echo $pts; ?>" fill="none" stroke="var(--s<?php echo $c; ?>)" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"/></svg>
        <span>7 days <b><?php echo h(money($b['week_revenue'], $currency)); ?></b></span>
      </div>

      <div class="facts">
        <span><span class="material-symbols-rounded">table_restaurant</span><?php echo $b['tables_occupied']; ?>/<?php echo $b['tables_total']; ?> tables busy</span>
        <span><span class="material-symbols-rounded">restaurant_menu</span><?php echo $b['menu_items']; ?> dishes</span>
        <span><span class="material-symbols-rounded">group</span><?php echo $b['staff']; ?> staff</span>
        <?php if ($b['stale_orders']): ?><span class="warn"><span class="material-symbols-rounded">schedule</span><?php echo $b['stale_orders']; ?> old open</span><?php endif; ?>
      </div>

      <div class="actions">
        <button class="btn btn-primary" type="button" data-rid="<?php echo h($b['restaurant_id']); ?>"><span class="material-symbols-rounded">dashboard</span>Open dashboard</button>
        <a class="btn" href="../website/menu.php?restaurant_id=<?php echo urlencode($b['restaurant_id']); ?>" target="_blank" rel="noopener" title="Open this branch's website menu"><span class="material-symbols-rounded">storefront</span>Menu</a>
      </div>
    </article>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <?php if ($off): ?>
    <details class="offlist">
      <summary><?php echo count($off); ?> switched-off branch<?php echo count($off) === 1 ? '' : 'es'; ?> (hidden from the totals)</summary>
      <ul><?php foreach ($off as $b): ?><li><?php echo h($b['name']); ?> · <?php echo h($b['restaurant_id']); ?></li><?php endforeach; ?></ul>
    </details>
  <?php endif; ?>
  <?php endif; ?>
  </div>

  <!-- Brand website: one address and payment gateway for all outlets (assets/js/brand-studio.js, api/brand_admin.php) -->
  <div class="panel" id="panel-brand"<?php echo $brand ? ' hidden' : ''; ?>>
    <section id="brandBox" aria-label="Brand website"></section>
  </div>
</main>

<div class="overlay" id="switching"><div>Opening branch…</div></div>

<script>
  document.getElementById('logoutBtn').addEventListener('click', function () {
    fetch('../admin/auth.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=logout'
    }).finally(function () { window.location.href = '../admin/login.php'; });
  });
  document.getElementById('refreshBtn').addEventListener('click', function () { location.reload(); });

  // tabs (the brand tab is remembered in the address: #brand)
  var tabs = document.querySelectorAll('.tab');
  function showTab(name) {
    tabs.forEach(function (t) { var on = t.getAttribute('data-tab') === name; t.classList.toggle('on', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
    document.getElementById('panel-overview').hidden = name !== 'overview';
    document.getElementById('panel-brand').hidden = name !== 'brand';
    history.replaceState(null, '', name === 'brand' ? '#brand' : location.pathname);
  }
  tabs.forEach(function (t) { t.addEventListener('click', function () { showTab(t.getAttribute('data-tab')); }); });
  if (tabs.length && location.hash === '#brand') showTab('brand');

  // chart tooltip
  var chart = document.getElementById('chart'), tip = document.getElementById('tip');
  if (chart && tip) {
    chart.querySelectorAll('.col').forEach(function (g) {
      g.addEventListener('mouseenter', function () {
        tip.innerHTML = g.getAttribute('data-tip');
        tip.style.left = Math.min(88, Math.max(12, parseFloat(g.getAttribute('data-x')))) + '%';
        tip.style.top = 'calc(' + parseFloat(g.getAttribute('data-y')) + '% - 8px)';
        tip.classList.add('show');
      });
      g.addEventListener('mouseleave', function () { tip.classList.remove('show'); });
    });
  }

  // numbers refresh every 2 minutes while the overview is on screen (not while editing brand settings)
  setInterval(function () {
    if (document.visibilityState === 'visible' && !document.getElementById('panel-overview').hidden) location.reload();
  }, 120000);

  document.querySelectorAll('[data-rid]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var overlay = document.getElementById('switching');
      overlay.classList.add('show');
      fetch('../api/switch_restaurant.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ restaurant_id: btn.getAttribute('data-rid') })
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.success) {
            try { localStorage.removeItem('admin_active_page'); } catch (e) {}
            var page = btn.getAttribute('data-page');
            window.location.href = 'dashboard.php' + (page ? '?page=' + page : '');
          } else {
            overlay.classList.remove('show');
            alert('Could not open branch: ' + (d.message || 'unknown error'));
          }
        })
        .catch(function () {
          overlay.classList.remove('show');
          alert('Network error — please try again.');
        });
    });
  });
</script>
<script src="../assets/js/brand-studio.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/brand-studio.js'); ?>"></script>
</body>
</html>
