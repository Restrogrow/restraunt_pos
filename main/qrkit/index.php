<?php
/**
 * QR kit orders dashboard: only the Smart QR Menu packages bought on restrogrow.com/qr-menu-ordering/
 * (config/qr_kit.php). Its own logins (login.php, made in Super Admin › QR kit orders), so a sales or delivery
 * person sees this and nothing else.
 *
 *   numbers for the chosen period (7 / 30 / 90 days / all): paid orders, money received online, balance still to
 *   collect, orders to start, people who started but didn't pay, how many of those who started paid
 *   a bar chart of paid orders by day, then the orders: To do, Delivered, Cancelled, Not paid, All, with search;
 *   each order: WhatsApp / call, address, status, balance collected, a note. CSV of what is shown.
 * Changes are POST + CSRF token, then back to the same view (config/prg.php).
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/qr_kit.php';
require_once __DIR__ . '/../config/prg.php';
header('X-Robots-Tag: noindex');
header('X-Frame-Options: DENY');

$conn = getConnection();
$me = qkSessionUser($conn);
if (!$me) { header('Location: login.php'); exit; }
if (empty($_SESSION['qrkit_csrf'])) $_SESSION['qrkit_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['qrkit_csrf'];

$tabs = ['todo' => 'To do', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled', 'unpaid' => 'Not paid', 'all' => 'All paid'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'todo';
$ranges = ['7' => '7 days', '30' => '30 days', '90' => '90 days', 'all' => 'All time'];
$range = isset($ranges[$_GET['range'] ?? '']) ? $_GET['range'] : '30';
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$here = '?' . http_build_query(array_filter(['tab' => $tab, 'range' => $range, 'q' => $q]));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = ''; $error = '';
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new InvalidArgumentException('The page was open too long. Please try again.');
        if (!qkUpdateOrder($conn, (int)($_POST['id'] ?? 0), (string)($_POST['stage'] ?? ''), !empty($_POST['balance_paid']), (string)($_POST['team_note'] ?? ''))) {
            throw new InvalidArgumentException('Order not found.');
        }
        $message = 'Saved.';
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('qrkit dashboard: ' . $e->getMessage());
        $error = 'Could not save. Please try again.';
    }
    prgRedirect('qrkit', ['message' => $message, 'error' => $error], 'index.php' . $here);
}
['message' => $message, 'error' => $error] = prgTake('qrkit', ['message' => '', 'error' => '']);

// ── numbers for the period ──
$since = $range === 'all' ? '2000-01-01 00:00:00' : date('Y-m-d 00:00:00', strtotime('-' . ((int)$range - 1) . ' days'));
$st = $conn->prepare("SELECT
    SUM(payment_status = 'paid') paid, COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN pay_now END), 0) received,
    COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total END), 0) sold, COUNT(*) started,
    SUM(payment_status <> 'paid') unpaid
    FROM qr_kit_orders WHERE created_at >= ?");
$st->execute([$since]);
$k = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$open = $conn->query("SELECT SUM(stage = 'paid') todo, COALESCE(SUM(CASE WHEN balance_paid = 0 AND stage <> 'cancelled' THEN pay_on_delivery END), 0) due
    FROM qr_kit_orders WHERE payment_status = 'paid'")->fetch(PDO::FETCH_ASSOC) ?: [];
$conversion = (int)$k['started'] ? round((int)$k['paid'] * 100 / (int)$k['started']) : 0;

// paid orders by day (a 7/30/90-day window; "all time" shows the last 90 days)
$days = $range === 'all' ? 90 : (int)$range;
$byDay = [];
for ($i = $days - 1; $i >= 0; $i--) $byDay[date('Y-m-d', strtotime("-$i days"))] = [0, 0.0];
$ds = $conn->prepare("SELECT DATE(paid_at) d, COUNT(*) n, SUM(pay_now) m FROM qr_kit_orders WHERE payment_status = 'paid' AND paid_at >= ? GROUP BY DATE(paid_at)");
$ds->execute([date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'))]);
foreach ($ds->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($byDay[$r['d']])) $byDay[$r['d']] = [(int)$r['n'], (float)$r['m']];
$maxDay = max(1, max(array_map(fn($v) => $v[0], $byDay)));

// ── the list ──
$where = ['created_at >= ?']; $args = [$since];
if ($tab === 'todo') $where[] = "payment_status = 'paid' AND stage IN ('paid', 'building', 'shipped')";
elseif ($tab === 'delivered') $where[] = "payment_status = 'paid' AND stage = 'delivered'";
elseif ($tab === 'cancelled') $where[] = "payment_status = 'paid' AND stage = 'cancelled'";
elseif ($tab === 'unpaid') $where[] = "payment_status <> 'paid'";
else $where[] = "payment_status = 'paid'";
if ($tab === 'todo') { $where[0] = '1 = 1'; $args = []; }   // work to do is shown whenever it was bought
if ($q !== '') {
    $where[] = '(name LIKE ? OR phone LIKE ? OR restaurant LIKE ? OR city LIKE ? OR transaction_id LIKE ?)';
    array_push($args, ...array_fill(0, 5, '%' . $q . '%'));
}
$ls = $conn->prepare('SELECT * FROM qr_kit_orders WHERE ' . implode(' AND ', $where) . " ORDER BY FIELD(stage, 'paid', 'building', 'shipped', 'delivered', 'cancelled'), COALESCE(paid_at, created_at) DESC LIMIT 500");
$ls->execute($args);
$orders = $ls->fetchAll(PDO::FETCH_ASSOC);
$counts = [];
foreach ($tabs as $t => $_) {
    $sql = ['todo' => "payment_status = 'paid' AND stage IN ('paid', 'building', 'shipped')", 'delivered' => "payment_status = 'paid' AND stage = 'delivered' AND created_at >= ?",
        'cancelled' => "payment_status = 'paid' AND stage = 'cancelled' AND created_at >= ?", 'unpaid' => "payment_status <> 'paid' AND created_at >= ?", 'all' => "payment_status = 'paid' AND created_at >= ?"][$t];
    $c = $conn->prepare('SELECT COUNT(*) FROM qr_kit_orders WHERE ' . $sql);
    $c->execute($t === 'todo' ? [] : [$since]);
    $counts[$t] = (int)$c->fetchColumn();
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$inr = fn($n) => '₹' . number_format((float)$n, 0, '.', ',');
$wa = fn($phone, $text) => 'https://wa.me/91' . preg_replace('/\D/', '', $phone) . '?text=' . rawurlencode($text);
$link = fn(array $set) => 'index.php?' . http_build_query(array_filter(array_merge(['tab' => $tab, 'range' => $range, 'q' => $q], $set)));

// CSV of what is shown
if (($_GET['csv'] ?? '') === '1') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="qr-kit-orders-' . $tab . '-' . date('Y-m-d') . '.csv"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['Booked', 'Paid', 'Payment', 'Status', 'Restaurant', 'Name', 'Phone', 'Package', 'Design', 'Standees', 'Takeaway & delivery', 'Online payments',
        'Total', 'Paid online', 'On delivery', 'Balance collected', 'Address', 'City', 'State', 'Pincode', 'Note', 'Booking id']);
    foreach ($orders as $o) {
        fputcsv($f, [$o['created_at'], $o['paid_at'], $o['payment_status'], QK_STAGES[$o['stage']] ?? $o['stage'], $o['restaurant'], $o['name'], $o['phone'],
            QK_PACKAGES[$o['package']]['name'] ?? $o['package'], $o['design'], $o['standees'], $o['add_delivery'] ? 'Yes' : 'No', $o['add_payments'] ? 'Yes' : 'No',
            $o['total'], $o['pay_now'], $o['pay_on_delivery'], $o['balance_paid'] ? 'Yes' : 'No', $o['address'], $o['city'], $o['state'], $o['pincode'], $o['team_note'], $o['transaction_id']]);
    }
    exit;
}
$stageTone = ['paid' => 'new', 'building' => 'work', 'shipped' => 'work', 'delivered' => 'done', 'cancelled' => 'off'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>QR kit orders</title>
<style>
  :root { --ink:#24130d; --ink2:#55433a; --mut:#8f7f73; --line:#eadfd2; --soft:#fbf5ee; --card:#fff; --brand:#ff5a1f; --bar:#ff5a1f; --barh:#e0460d; }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--soft); color: var(--ink); font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 14px; }
  a { color: inherit; }
  .top { position: sticky; top: 0; z-index: 5; background: var(--ink); color: #fff; }
  .top .in { max-width: 1180px; margin: 0 auto; padding: 12px 16px; display: flex; align-items: center; gap: 12px; }
  .top b { font-size: 16px; } .top .who { margin-left: auto; font-size: 13px; opacity: .85; } .top a { color: #fff; font-size: 13px; }
  .wrap { max-width: 1180px; margin: 0 auto; padding: 18px 16px 40px; }
  .msg { padding: 11px 13px; border-radius: 10px; margin-bottom: 14px; } .ok { background: #e7f5ec; color: #14532d; } .err { background: #fdecea; color: #a1271b; }
  .bar2 { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
  .chip { border: 1px solid var(--line); background: var(--card); border-radius: 999px; padding: 7px 13px; font-weight: 600; font-size: 13px; text-decoration: none; color: var(--ink2); white-space: nowrap; }
  .chip.on { background: var(--ink); color: #fff; border-color: var(--ink); }
  .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 14px; }
  .tile { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 12px 14px; }
  .tile small { display: block; color: var(--mut); font-size: 12px; } .tile b { font-size: 22px; display: block; margin-top: 2px; } .tile em { font-style: normal; color: var(--mut); font-size: 12px; }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 14px; margin-bottom: 14px; }
  .card h2 { font-size: 14px; margin: 0 0 10px; }
  .chart { display: flex; align-items: flex-end; gap: 2px; height: 120px; border-bottom: 1px solid var(--line); }
  .chart div { flex: 1; min-width: 2px; background: var(--bar); border-radius: 4px 4px 0 0; position: relative; }
  .chart div:hover { background: var(--barh); }
  .chart div:hover::after { content: attr(data-tip); position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); margin-bottom: 6px; background: var(--ink); color: #fff; font-size: 11px; padding: 4px 7px; border-radius: 6px; white-space: nowrap; z-index: 2; }
  .axis { display: flex; justify-content: space-between; color: var(--mut); font-size: 11px; margin-top: 4px; }
  form.search { display: flex; gap: 6px; margin-left: auto; }
  form.search input { padding: 8px 11px; border: 1px solid var(--line); border-radius: 10px; font-size: 13px; min-width: 200px; font-family: inherit; }
  .btn { border: 0; border-radius: 10px; padding: 8px 13px; font-size: 13px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; font-family: inherit; }
  .dark { background: var(--ink); color: #fff; } .light { background: #f3ebe1; color: var(--ink); } .wa { background: #e7f5ec; color: #14833b; }
  .list { display: grid; gap: 10px; }
  .o { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 14px; display: grid; grid-template-columns: 1.3fr 1fr 1fr 250px; gap: 14px; }
  .o h3 { margin: 0; font-size: 15px; } .o small { color: var(--mut); display: block; line-height: 1.5; }
  .o .acts { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
  .pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 12px; font-weight: 700; }
  .t-new { background: #ffefe6; color: #b6400e; } .t-work { background: #e8eefc; color: #2442a1; } .t-done { background: #e7f5ec; color: #14532d; } .t-off { background: #f3f4f6; color: #6b7280; } .t-bad { background: #fdecea; color: #a1271b; }
  .o form { display: grid; gap: 6px; }
  .o select, .o input[type=text] { padding: 8px 9px; border: 1px solid var(--line); border-radius: 9px; font-size: 13px; font-family: inherit; width: 100%; }
  .o label { font-size: 13px; display: flex; gap: 6px; align-items: center; }
  .empty { background: var(--card); border: 1px dashed var(--line); border-radius: 14px; padding: 30px; text-align: center; color: var(--mut); }
  @media (max-width: 860px) { .o { grid-template-columns: 1fr; } form.search { margin-left: 0; width: 100%; } form.search input { flex: 1; min-width: 0; } }
</style>
</head>
<body>
<div class="top"><div class="in"><b>QR kit orders</b><span style="opacity:.6;font-size:13px">RestroGrow</span>
  <span class="who"><?php echo $h($me['name']); ?></span><a href="logout.php">Sign out</a></div></div>
<div class="wrap">
  <?php if ($message): ?><div class="msg ok"><?php echo $h($message); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg err" role="alert"><?php echo $h($error); ?></div><?php endif; ?>

  <div class="bar2"><?php foreach ($ranges as $r => $l): ?><a class="chip<?php echo $range === (string)$r ? ' on' : ''; ?>" href="<?php echo $h($link(['range' => $r])); ?>"><?php echo $h($l); ?></a><?php endforeach; ?></div>

  <div class="tiles">
    <div class="tile"><small>Paid orders</small><b><?php echo (int)$k['paid']; ?></b><em><?php echo $h($ranges[$range]); ?></em></div>
    <div class="tile"><small>Received online</small><b><?php echo $inr($k['received']); ?></b><em>of <?php echo $inr($k['sold']); ?> sold</em></div>
    <div class="tile"><small>Balance to collect</small><b><?php echo $inr($open['due']); ?></b><em>on delivery, all open orders</em></div>
    <div class="tile"><small>New, not started</small><b><?php echo (int)$open['todo']; ?></b><em>WhatsApp them for the menu</em></div>
    <div class="tile"><small>Started, didn't pay</small><b><?php echo (int)$k['unpaid']; ?></b><em>worth a call</em></div>
    <div class="tile"><small>Paid out of started</small><b><?php echo $conversion; ?>%</b><em><?php echo (int)$k['paid']; ?> of <?php echo (int)$k['started']; ?> checkouts</em></div>
  </div>

  <div class="card"><h2>Paid orders by day</h2>
    <div class="chart" role="img" aria-label="Paid orders for each of the last <?php echo $days; ?> days">
      <?php foreach ($byDay as $d => [$n, $m]): ?><div style="height:<?php echo $n ? max(3, round($n * 100 / $maxDay)) : 0; ?>%" data-tip="<?php echo $h(date('j M', strtotime($d)) . ': ' . $n . ' order' . ($n === 1 ? '' : 's') . ($n ? ', ' . $inr($m) : '')); ?>"></div><?php endforeach; ?>
    </div>
    <div class="axis"><span><?php echo $h(date('j M', strtotime(array_key_first($byDay)))); ?></span><span>Highest day: <?php echo $maxDay; ?> order<?php echo $maxDay === 1 ? '' : 's'; ?></span><span>Today</span></div>
  </div>

  <div class="bar2">
    <?php foreach ($tabs as $t => $l): ?><a class="chip<?php echo $tab === $t ? ' on' : ''; ?>" href="<?php echo $h($link(['tab' => $t])); ?>"><?php echo $h($l); ?><?php echo $counts[$t] ? ' · ' . $counts[$t] : ''; ?></a><?php endforeach; ?>
    <form class="search" method="get"><input type="hidden" name="tab" value="<?php echo $h($tab); ?>"><input type="hidden" name="range" value="<?php echo $h($range); ?>">
      <input type="search" name="q" value="<?php echo $h($q); ?>" placeholder="Name, phone, restaurant, city"><button class="btn dark" type="submit">Search</button></form>
    <a class="btn light" href="<?php echo $h($link(['csv' => '1'])); ?>">CSV</a>
  </div>
  <?php if ($tab === 'todo'): ?><p style="color:var(--mut);margin:-4px 0 12px;font-size:13px">Every open order, whenever it was bought.</p><?php endif; ?>

  <?php if (!$orders): ?><div class="empty"><?php echo $q !== '' ? 'Nothing matches "' . $h($q) . '".' : 'No orders here.'; ?></div><?php else: ?>
  <div class="list">
    <?php foreach ($orders as $o): $paid = $o['payment_status'] === 'paid'; $first = trim(explode(' ', $o['name'])[0]); ?>
    <div class="o">
      <div>
        <h3><?php echo $h($o['restaurant']); ?></h3>
        <small><?php echo $h($o['name']); ?> · <?php echo $h($o['phone']); ?></small>
        <small><?php echo $h($o['address'] . ', ' . $o['city'] . ', ' . $o['state'] . ' - ' . $o['pincode']); ?></small>
        <div class="acts">
          <a class="btn wa" target="_blank" rel="noopener" href="<?php echo $h($paid
            ? $wa($o['phone'], 'Hi ' . $first . ', this is RestroGrow. Thank you for booking the Smart QR Menu for ' . $o['restaurant'] . '! Please send photos of your menu and your logo here, and we will set it up within 24 hours.')
            : $wa($o['phone'], 'Hi ' . $first . ', this is RestroGrow. We saw you were booking the Smart QR Menu for ' . $o['restaurant'] . '. Can we help you finish it?')); ?>">WhatsApp</a>
          <a class="btn light" href="tel:<?php echo $h($o['phone']); ?>">Call</a>
        </div>
      </div>
      <div>
        <b><?php echo $h(QK_PACKAGES[$o['package']]['name'] ?? $o['package']); ?></b>
        <small><?php echo (int)$o['standees']; ?> standees · <?php echo $h($o['design']); ?></small>
        <?php if ($o['add_delivery'] || $o['add_payments']): ?><small>+ <?php echo $h(implode(', ', array_filter([$o['add_delivery'] ? 'takeaway & delivery' : '', $o['add_payments'] ? 'online payments' : '']))); ?></small><?php endif; ?>
        <small><?php echo $paid ? 'Paid ' . $h(date('j M, g:i A', strtotime((string)$o['paid_at']))) : 'Started ' . $h(date('j M, g:i A', strtotime((string)$o['created_at']))); ?></small>
      </div>
      <div>
        <b><?php echo $inr($o['total']); ?></b>
        <?php if ($paid): ?>
          <small>Paid online <?php echo $inr($o['pay_now']); ?></small>
          <?php if ($o['pay_on_delivery'] > 0): ?><small><?php echo $o['balance_paid'] ? 'Balance ' . $inr($o['pay_on_delivery']) . ' collected' : '<b style="color:var(--ink)">' . $inr($o['pay_on_delivery']) . ' to collect</b>'; ?></small><?php else: ?><small>All paid online</small><?php endif; ?>
        <?php else: ?>
          <small>Would have paid <?php echo $inr($o['pay_now']); ?> now</small>
        <?php endif; ?>
      </div>
      <div>
        <?php if ($paid): ?>
        <form method="post" action="<?php echo $h('index.php' . $here); ?>">
          <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
          <span><span class="pill t-<?php echo $stageTone[$o['stage']] ?? 'off'; ?>"><?php echo $h(QK_STAGES[$o['stage']] ?? $o['stage']); ?></span></span>
          <select name="stage" aria-label="Status"><?php foreach (QK_STAGES as $sk => $sl): ?><option value="<?php echo $sk; ?>"<?php echo $o['stage'] === $sk ? ' selected' : ''; ?>><?php echo $h($sl); ?></option><?php endforeach; ?></select>
          <?php if ($o['pay_on_delivery'] > 0): ?><label><input type="checkbox" name="balance_paid" value="1"<?php echo $o['balance_paid'] ? ' checked' : ''; ?>> Balance collected</label><?php endif; ?>
          <input type="text" name="team_note" maxlength="500" placeholder="Note" value="<?php echo $h($o['team_note']); ?>" aria-label="Note">
          <button class="btn dark" type="submit">Save</button>
        </form>
        <?php else: ?>
          <span class="pill <?php echo $o['payment_status'] === 'failed' ? 't-bad' : 't-off'; ?>"><?php echo $o['payment_status'] === 'failed' ? 'Payment failed' : 'Not finished'; ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
