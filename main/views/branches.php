<?php
// Multi-branch overview — landing page for branch-admin (multi-outlet) logins.
// Shows every linked branch with today's numbers and lets the owner jump into
// any branch's full dashboard (via api/switch_restaurant.php).
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

if (!isSessionValid() || !isset($_SESSION['branch_admin_id']) || empty($_SESSION['linked_restaurants'])) {
    header('Location: ../admin/login.php');
    exit();
}

require_once __DIR__ . '/../db_connection.php';
date_default_timezone_set('Asia/Kolkata');

$branches = [];
$totals = ['revenue' => 0, 'orders' => 0, 'pending' => 0, 'kot' => 0, 'week_revenue' => 0];
$currency = '₹';

try {
    $pdo = getConnection();

    // Refresh the linked list from the DB so newly linked branches show up
    // without a re-login, and keep the session copy in sync for switching.
    $linkStmt = $pdo->prepare("
        SELECT brl.restaurant_id, brl.label, u.restaurant_name, u.address, u.phone,
               u.currency_symbol, u.is_active
        FROM branch_restaurant_links brl
        JOIN users u ON u.restaurant_id = brl.restaurant_id
        WHERE brl.branch_admin_id = ?
        ORDER BY brl.id
    ");
    $linkStmt->execute([$_SESSION['branch_admin_id']]);
    $linked = $linkStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($linked) {
        $_SESSION['linked_restaurants'] = array_map(function ($r) {
            return ['restaurant_id' => $r['restaurant_id'], 'label' => $r['label'], 'restaurant_name' => $r['restaurant_name']];
        }, $linked);
    }

    $statStmt = $pdo->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() AND payment_status = 'Paid' THEN total END), 0) AS today_revenue,
          COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() AND order_status NOT IN ('Cancelled','Rejected') THEN 1 END), 0) AS today_orders,
          COALESCE(SUM(CASE WHEN order_status IN ('Pending','Accepted','Preparing','Ready') THEN 1 END), 0) AS open_orders,
          COALESCE(SUM(CASE WHEN created_at >= CURDATE() - INTERVAL 6 DAY AND payment_status = 'Paid' THEN total END), 0) AS week_revenue
        FROM orders
        WHERE restaurant_id = ? AND deleted_at IS NULL
    ");
    $kotStmt = $pdo->prepare("SELECT COUNT(*) FROM kot WHERE restaurant_id = ? AND kot_status IN ('Pending','Preparing')");
    $menuStmt = $pdo->prepare("SELECT COUNT(*) FROM menu_items WHERE restaurant_id = ?");
    $tableStmt = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(is_available = 0), 0) AS occupied FROM tables WHERE restaurant_id = ?");
    $staffStmt = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE restaurant_id = ? AND is_active = 1");

    foreach ($linked as $b) {
        $rid = $b['restaurant_id'];
        $statStmt->execute([$rid]);
        $s = $statStmt->fetch(PDO::FETCH_ASSOC);
        $kotStmt->execute([$rid]);
        $menuStmt->execute([$rid]);
        $tableStmt->execute([$rid]);
        $t = $tableStmt->fetch(PDO::FETCH_ASSOC);
        $staffStmt->execute([$rid]);

        $row = $b + [
            'today_revenue' => (float)$s['today_revenue'],
            'today_orders' => (int)$s['today_orders'],
            'open_orders' => (int)$s['open_orders'],
            'week_revenue' => (float)$s['week_revenue'],
            'active_kot' => (int)$kotStmt->fetchColumn(),
            'menu_items' => (int)$menuStmt->fetchColumn(),
            'tables_total' => (int)$t['total'],
            'tables_occupied' => (int)$t['occupied'],
            'staff' => (int)$staffStmt->fetchColumn(),
        ];
        $branches[] = $row;
        $totals['revenue'] += $row['today_revenue'];
        $totals['orders'] += $row['today_orders'];
        $totals['pending'] += $row['open_orders'];
        $totals['kot'] += $row['active_kot'];
        $totals['week_revenue'] += $row['week_revenue'];
        if (!empty($b['currency_symbol'])) $currency = $b['currency_symbol'];
    }
} catch (Exception $e) {
    error_log('branches.php: ' . $e->getMessage());
    $loadError = 'Could not load branch data. Please refresh.';
}

$maxRevenue = max(1, ...array_map(fn($b) => $b['today_revenue'], $branches ?: [['today_revenue' => 0]]));
$currentRid = $_SESSION['restaurant_id'] ?? '';
$username = $_SESSION['username'] ?? '';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n, $c) { return $c . number_format($n, $n == floor($n) ? 0 : 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>All Branches — RestroGrow</title>
<link rel="icon" type="image/png" href="../assets/images/logo-192.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0">
<style>
  :root {
    --brand: #ff5a1f; --brand-soft: #fff1ea; --ink: #1f2937; --muted: #6b7280;
    --line: #e5e7eb; --bg: #f6f7fb; --card: #ffffff; --ok: #16a34a; --warn: #d97706;
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
  h1 { font-size: 24px; margin: 0 0 4px; }
  .sub { color: var(--muted); margin: 0 0 20px; font-size: 14px; }
  .summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 28px; }
  .stat { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 16px; }
  .stat .label { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
  .stat .value { font-size: 24px; font-weight: 700; margin-top: 4px; font-variant-numeric: tabular-nums; }
  .section-title { display: flex; align-items: baseline; justify-content: space-between; margin: 0 0 12px; }
  .section-title h2 { font-size: 17px; margin: 0; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; }
  .branch { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 18px; display: flex; flex-direction: column; gap: 14px; }
  .branch.current { border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-soft); }
  .branch-head { display: flex; align-items: flex-start; gap: 12px; }
  .avatar { width: 42px; height: 42px; border-radius: 12px; background: var(--brand-soft); color: var(--brand); display: grid; place-items: center; font-weight: 700; flex: none; }
  .branch-name { font-weight: 600; font-size: 16px; line-height: 1.3; }
  .branch-meta { font-size: 12px; color: var(--muted); margin-top: 2px; }
  .pill { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 999px; margin-left: auto; white-space: nowrap; }
  .pill.on { background: #dcfce7; color: var(--ok); }
  .pill.off { background: #f3f4f6; color: var(--muted); }
  .pill.here { background: var(--brand-soft); color: var(--brand); }
  .kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
  .kpi { background: var(--bg); border-radius: 10px; padding: 10px; }
  .kpi .k { font-size: 11px; color: var(--muted); }
  .kpi .v { font-size: 17px; font-weight: 600; font-variant-numeric: tabular-nums; }
  .kpi .v.warn { color: var(--warn); }
  .bar { height: 6px; background: var(--bg); border-radius: 999px; overflow: hidden; }
  .bar span { display: block; height: 100%; background: var(--brand); border-radius: 999px; }
  .bar-label { display: flex; justify-content: space-between; font-size: 12px; color: var(--muted); margin-bottom: 6px; }
  .facts { display: flex; flex-wrap: wrap; gap: 6px 14px; font-size: 12px; color: var(--muted); }
  .facts span { display: inline-flex; align-items: center; gap: 4px; }
  .facts .material-symbols-rounded { font-size: 16px; }
  .branch .btn-primary { justify-content: center; width: 100%; }
  .empty, .error { background: var(--card); border: 1px dashed var(--line); border-radius: 14px; padding: 32px; text-align: center; color: var(--muted); }
  .error { border-color: #fca5a5; color: #b91c1c; }
  .overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, .45); display: none; place-items: center; z-index: 50; }
  .overlay.show { display: grid; }
  .overlay div { background: #fff; padding: 22px 30px; border-radius: 14px; font-weight: 500; }
  @media (max-width: 640px) {
    .topbar .user, .topbar .tag { display: none; }
    .grid { grid-template-columns: 1fr; }
    .summary { grid-template-columns: repeat(2, 1fr); }
    .stat .value { font-size: 20px; }
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
  <h1>All branches</h1>
  <p class="sub">Today, <?php echo date('l, j M Y'); ?> · <?php echo count($branches); ?> branch<?php echo count($branches) === 1 ? '' : 'es'; ?></p>

  <?php if (!empty($loadError)): ?>
    <div class="error"><?php echo h($loadError); ?></div>
  <?php else: ?>
  <section class="summary" aria-label="Totals across all branches">
    <div class="stat"><div class="label">Revenue today</div><div class="value"><?php echo h(money($totals['revenue'], $currency)); ?></div></div>
    <div class="stat"><div class="label">Orders today</div><div class="value"><?php echo (int)$totals['orders']; ?></div></div>
    <div class="stat"><div class="label">Open orders</div><div class="value"><?php echo (int)$totals['pending']; ?></div></div>
    <div class="stat"><div class="label">Active KOTs</div><div class="value"><?php echo (int)$totals['kot']; ?></div></div>
    <div class="stat"><div class="label">Last 7 days</div><div class="value"><?php echo h(money($totals['week_revenue'], $currency)); ?></div></div>
  </section>

  <div class="section-title"><h2>Branches</h2></div>
  <?php if (!$branches): ?>
    <div class="empty">No branches are linked to this account yet.</div>
  <?php else: ?>
  <section class="grid">
    <?php foreach ($branches as $b):
      $name = $b['label'] ?: $b['restaurant_name'];
      $initials = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/[\s\-–]+/', trim($name)), 0, 2))));
      $isCurrent = $b['restaurant_id'] === $currentRid;
      $share = round($b['today_revenue'] / $maxRevenue * 100);
    ?>
    <article class="branch<?php echo $isCurrent ? ' current' : ''; ?>">
      <div class="branch-head">
        <div class="avatar"><?php echo h($initials); ?></div>
        <div>
          <div class="branch-name"><?php echo h($name); ?></div>
          <div class="branch-meta"><?php echo h($b['restaurant_id']); ?><?php echo $b['address'] ? ' · ' . h($b['address']) : ''; ?></div>
        </div>
        <?php if ($isCurrent): ?><span class="pill here">Last opened</span>
        <?php elseif ((int)$b['is_active'] === 1): ?><span class="pill on">Active</span>
        <?php else: ?><span class="pill off">Disabled</span><?php endif; ?>
      </div>

      <div class="kpis">
        <div class="kpi"><div class="k">Revenue</div><div class="v"><?php echo h(money($b['today_revenue'], $currency)); ?></div></div>
        <div class="kpi"><div class="k">Orders</div><div class="v"><?php echo $b['today_orders']; ?></div></div>
        <div class="kpi"><div class="k">Open</div><div class="v<?php echo $b['open_orders'] ? ' warn' : ''; ?>"><?php echo $b['open_orders']; ?></div></div>
      </div>

      <div>
        <div class="bar-label"><span>Share of today's revenue</span><span><?php echo $totals['revenue'] > 0 ? round($b['today_revenue'] / $totals['revenue'] * 100) : 0; ?>%</span></div>
        <div class="bar"><span style="width: <?php echo $share; ?>%"></span></div>
      </div>

      <div class="facts">
        <span><span class="material-symbols-rounded">table_restaurant</span><?php echo $b['tables_occupied']; ?>/<?php echo $b['tables_total']; ?> tables busy</span>
        <span><span class="material-symbols-rounded">soup_kitchen</span><?php echo $b['active_kot']; ?> KOTs</span>
        <span><span class="material-symbols-rounded">restaurant_menu</span><?php echo $b['menu_items']; ?> items</span>
        <span><span class="material-symbols-rounded">group</span><?php echo $b['staff']; ?> staff</span>
        <span><span class="material-symbols-rounded">calendar_month</span><?php echo h(money($b['week_revenue'], $currency)); ?> / 7d</span>
      </div>

      <button class="btn btn-primary" type="button" data-rid="<?php echo h($b['restaurant_id']); ?>">
        <span class="material-symbols-rounded">dashboard</span>Open dashboard
      </button>
    </article>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
  <?php endif; ?>
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
            window.location.href = 'dashboard.php';
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
</body>
</html>
