<?php
/**
 * Website analytics (Super Admin › Tools): visits to restrogrow.com's own pages.
 * Our own tracking (site_visits, with location), Visitors & leads (which sources bring leads and restaurants,
 * warm visitors, recent visitors: config/site_visitors.php), a Link builder for tagged links, and Google
 * Analytics 4 (Data API, with a service-account key uploaded here). Settings: GA IDs and key, the location
 * database (config/geo_ip.php) and "Don't count this browser". Engine: config/site_analytics.php.
 */
require_once __DIR__ . '/auth.php';
require_superadmin();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/site_visitors.php';

$conn = getConnection();
siteVisitorsEnsure($conn);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$flash = ''; $flashErr = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) { http_response_code(403); exit('Not allowed'); }
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'settings') {
            $mid = strtoupper(trim((string)($_POST['ga_measurement_id'] ?? '')));
            $pid = preg_replace('/\D/', '', (string)($_POST['ga_property_id'] ?? ''));
            if ($mid !== '' && !preg_match('/^G-[A-Z0-9]{4,15}$/', $mid)) throw new RuntimeException('The Measurement ID looks like G-ABC123XYZ. Copy it from GA › Admin › Data streams.');
            if ($pid === '') throw new RuntimeException('Enter the property ID (only numbers, from GA › Admin › Property details).');
            saSet($conn, 'ga_measurement_id', $mid);
            saSet($conn, 'ga_property_id', $pid);
            $flash = $mid === '' ? 'Saved. Google Analytics is now off on the website; our own tracking keeps running.' : 'Saved. The website uses ' . $mid . ' within 5 minutes.';
        } elseif ($act === 'key') {
            $f = $_FILES['key'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 20000) throw new RuntimeException('Choose the JSON key file you downloaded from Google Cloud.');
            $j = json_decode((string)file_get_contents($f['tmp_name']), true);
            if (!is_array($j) || ($j['type'] ?? '') !== 'service_account' || empty($j['client_email']) || empty($j['private_key'])) throw new RuntimeException('That file is not a service-account key. In Google Cloud, open the service account › Keys › Add key › JSON.');
            if (!openssl_pkey_get_private($j['private_key'])) throw new RuntimeException('The key inside the file could not be read. Download a new JSON key and try again.');
            if (file_put_contents(gaKeyFile(), json_encode($j)) === false) throw new RuntimeException('The server could not save the key. Check that main/config is writable.');
            @chmod(gaKeyFile(), 0600);
            saSet($conn, 'ga_token_cache', null);
            $flash = 'Key saved. Now give ' . $j['client_email'] . ' Viewer access in Google Analytics (step 4 below).';
        } elseif ($act === 'remove_key') {
            if (is_file(gaKeyFile())) @unlink(gaKeyFile());
            saSet($conn, 'ga_token_cache', null);
            $flash = 'Key removed. Google Analytics numbers will not show here until you upload one again.';
        } elseif ($act === 'refresh') {
            $conn->exec("DELETE FROM sa_settings WHERE k LIKE 'ga\\_cache\\_%'");
            $flash = 'Fresh numbers loaded from Google Analytics.';
        } elseif ($act === 'geo_update') {
            [$ok, $msg] = geoUpdate();
            if (!$ok) throw new RuntimeException($msg);
            $flash = $msg;
        } elseif ($act === 'ignore_me') {
            // this browser's own visits stop counting (api/site_hit.php checks the cookie)
            $on = !empty($_POST['on']);
            setcookie('rg_ignore', $on ? '1' : '', ['expires' => $on ? time() + 5 * 365 * 86400 : time() - 3600, 'path' => '/', 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
            $flash = $on ? 'This browser is no longer counted. Do the same on your phone and other computers.' : 'This browser is counted again.';
        }
    } catch (Throwable $e) { $flash = $e->getMessage(); $flashErr = true; }
    $_SESSION['sa_analytics_flash'] = [$flash, $flashErr];
    header('Location: site_analytics.php?' . http_build_query(array_filter(['d' => $_POST['d'] ?? null, 'host' => $_POST['host'] ?? null, 'tab' => $_POST['tab'] ?? null])));
    exit;
}
if (!empty($_SESSION['sa_analytics_flash'])) { [$flash, $flashErr] = $_SESSION['sa_analytics_flash']; unset($_SESSION['sa_analytics_flash']); }

$days = in_array((int)($_GET['d'] ?? 7), [1, 7, 30, 90], true) ? (int)($_GET['d'] ?? 7) : 7;
$host = in_array($_GET['host'] ?? 'restrogrow.com', ['restrogrow.com', 'demo.restrogrow.com', ''], true) ? (string)($_GET['host'] ?? 'restrogrow.com') : 'restrogrow.com';
$tab = in_array($_GET['tab'] ?? 'own', ['own', 'people', 'links', 'ga', 'settings'], true) ? $_GET['tab'] ?? 'own' : 'own';

$own = siteOwnReport($conn, $days);
$vr = in_array($tab, ['own', 'people'], true) ? siteVisitorReport($conn, $days) : null;
$geo = geoStatus();
$ignored = !empty($_COOKIE['rg_ignore']);
$eventNames = ['pricing_view' => 'Saw the pricing', 'trial_click' => 'Clicked "Start free trial"', 'whatsapp_click' => 'Clicked WhatsApp', 'call_click' => 'Clicked to call',
    'chat_open' => 'Opened the chat', 'login_click' => 'Clicked Login', 'demo_click' => 'Opened the app demo'];
$flag = fn($cc) => preg_match('/^[A-Z]{2}$/', (string)$cc) ? mb_chr(127397 + ord($cc[0])) . mb_chr(127397 + ord($cc[1])) . ' ' : '';
$place = fn(array $v) => implode(', ', array_values(array_unique(array_filter([$v['city'] ?? '', $v['region'] ?? '', $v['country'] ?? '']))));
$sa = gaServiceAccount();
$ga = null; $gaErr = '';
if ($sa && $tab === 'ga') { try { $ga = gaReport($conn, $days, $host); } catch (Throwable $e) { $gaErr = $e->getMessage(); } }
$mid = (string)saGet($conn, 'ga_measurement_id', SA_GA_DEFAULT_ID);
$pid = (string)saGet($conn, 'ga_property_id', SA_GA_DEFAULT_PROPERTY);

$n = fn($v) => number_format((float)$v);
$delta = function ($now, $prev) { if ($prev <= 0) return $now > 0 ? '<span class="up">new</span>' : ''; $p = round(($now - $prev) / $prev * 100); return '<span class="' . ($p >= 0 ? 'up' : 'down') . '">' . ($p >= 0 ? '↑' : '↓') . abs($p) . '%</span>'; };
$periodLabel = [1 => 'Today', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'][$days];
$qs = fn(array $o) => 'site_analytics.php?' . http_build_query(array_merge(['d' => $days, 'host' => $host, 'tab' => $tab], $o));
$bars = function (array $vals, array $labels) {
    $max = max(1, max($vals ?: [0]));
    $out = '<div class="bars" role="img" aria-label="Daily chart">';
    foreach ($vals as $i => $v) $out .= '<span title="' . htmlspecialchars($labels[$i] . ': ' . $v) . '" style="height:' . max(2, round($v / $max * 100)) . '%"></span>';
    return $out . '</div>';
};
$list = function (array $rows, string $label, string $val, ?callable $fmt = null) use ($n) {
    if (!$rows) return '<p class="empty">No data yet for this period.</p>';
    $max = max(1, max(array_map(fn($r) => (float)$r[$val], $rows)));
    $o = '<ul class="list">';
    foreach ($rows as $r) $o .= '<li><span class="lb">' . ($fmt ? $fmt($r) : htmlspecialchars((string)$r[$label])) . '</span><b>' . $n($r[$val]) . '</b><i style="width:' . round($r[$val] / $max * 100) . '%"></i></li>';
    return $o . '</ul>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Website analytics · Super Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<style>
  :root { --ink: #0f172a; --soft: #475569; --muted: #94a3b8; --line: #e2e8f0; --bg: #f5f7fb; --brand: #ea580c; --soft-brand: #ffedd5; --good: #15803d; --bad: #dc2626; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Inter, system-ui, -apple-system, 'Segoe UI', sans-serif; background: var(--bg); color: var(--ink); }
  main { max-width: 1180px; margin: 0 auto; padding: 24px 16px 100px; display: grid; gap: 18px; }
  .back { color: var(--soft); text-decoration: none; font-size: 14px; font-weight: 600; }
  h1 { margin: 10px 0 4px; font-size: 28px; letter-spacing: -.01em; }
  .sub { margin: 0; color: var(--soft); font-size: 15px; line-height: 1.55; max-width: 74ch; }
  .bar-row { display: flex; gap: 10px 16px; flex-wrap: wrap; align-items: center; justify-content: space-between; }
  .tabs, .seg { display: inline-flex; gap: 4px; background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 4px; flex-wrap: wrap; }
  .tabs a, .seg a { text-decoration: none; color: var(--ink); font-weight: 600; font-size: 13.5px; padding: 7px 12px; border-radius: 9px; }
  .tabs a[aria-current="page"], .seg a[aria-current="true"] { background: var(--brand); color: #fff; }
  .flash { padding: 12px 14px; border-radius: 12px; background: #ecfdf5; color: var(--good); font-weight: 600; font-size: 14px; border: 1px solid #bbf7d0; }
  .flash.err { background: #fef2f2; color: var(--bad); border-color: #fecaca; }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; }
  .kpi { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 14px 16px; display: grid; gap: 4px; }
  .kpi small { color: var(--soft); font-weight: 600; font-size: 12.5px; }
  .kpi b { font-size: 26px; font-variant-numeric: tabular-nums; letter-spacing: -.01em; }
  .kpi .up { color: var(--good); font-size: 12.5px; font-weight: 700; } .kpi .down { color: var(--bad); font-size: 12.5px; font-weight: 700; }
  .panel { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 16px; display: grid; gap: 12px; min-width: 0; }
  .panel h2 { margin: 0; font-size: 16px; }
  .panel .hint { margin: 0; color: var(--soft); font-size: 13px; }
  .cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr)); gap: 14px; }
  .bars { display: flex; align-items: flex-end; gap: 3px; height: 120px; padding-top: 6px; border-bottom: 1px solid var(--line); }
  .bars span { flex: 1; background: var(--brand); border-radius: 4px 4px 0 0; min-width: 2px; opacity: .85; }
  .bars span:hover { opacity: 1; }
  .axis { display: flex; justify-content: space-between; color: var(--muted); font-size: 11.5px; font-variant-numeric: tabular-nums; }
  .list { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
  .list li { position: relative; display: flex; justify-content: space-between; gap: 10px; padding: 7px 10px; border-radius: 9px; font-size: 13.5px; overflow: hidden; }
  .list li i { position: absolute; left: 0; top: 0; bottom: 0; background: var(--soft-brand); z-index: 0; border-radius: 9px; }
  .list li .lb, .list li b { position: relative; z-index: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .list li b { font-variant-numeric: tabular-nums; }
  .empty { margin: 0; color: var(--muted); font-size: 13.5px; }
  .note { color: var(--soft); font-size: 13px; line-height: 1.55; }
  form.inline { display: inline; }
  .btn { border: 1px solid var(--line); background: #fff; color: var(--ink); font: 700 13.5px Inter, sans-serif; padding: 9px 14px; border-radius: 11px; cursor: pointer; text-decoration: none; display: inline-block; }
  .btn.primary { background: var(--brand); border-color: var(--brand); color: #fff; }
  .btn.danger { color: var(--bad); }
  .field { display: grid; gap: 6px; }
  .field label { font-weight: 700; font-size: 13.5px; }
  .field input[type=text] { font: 500 14px Inter, sans-serif; padding: 9px 11px; border: 1px solid var(--line); border-radius: 10px; max-width: 320px; }
  .field small { color: var(--soft); font-size: 12.5px; }
  .steps { margin: 0; padding-left: 20px; display: grid; gap: 10px; font-size: 14px; line-height: 1.55; }
  .steps code, .mono { font-family: ui-monospace, Consolas, monospace; font-size: 12.5px; background: var(--bg); border: 1px solid var(--line); border-radius: 6px; padding: 1px 6px; word-break: break-all; }
  .pill { display: inline-block; font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 999px; }
  .pill.ok { background: #dcfce7; color: var(--good); } .pill.no { background: #fee2e2; color: var(--bad); }
  a:focus-visible, button:focus-visible, input:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
  .live { display: inline-flex; align-items: center; gap: 6px; } .live::before { content: ''; width: 8px; height: 8px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 4px #dcfce7; }
  .tbl-wrap { overflow-x: auto; }
  table.tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
  .tbl th { text-align: left; color: var(--soft); font-weight: 700; font-size: 12px; padding: 8px 10px; border-bottom: 1px solid var(--line); white-space: nowrap; }
  .tbl td { padding: 9px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
  .tbl td.num, .tbl th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .tbl small { display: block; color: var(--muted); font-size: 11.5px; margin-top: 2px; }
  .score { display: inline-block; min-width: 34px; text-align: center; padding: 2px 8px; border-radius: 999px; font-weight: 800; font-size: 12px; background: #f1f5f9; color: var(--soft); }
  .score.warm { background: #ffedd5; color: #c2410c; } .score.hot { background: #fee2e2; color: #b91c1c; }
  .tag { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 700; background: #dcfce7; color: var(--good); }
  .tag.lead { background: #dbeafe; color: #1d4ed8; }
  .lb-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
  .lb-grid select, .lb-grid input { width: 100%; font: 500 14px Inter, sans-serif; padding: 9px 11px; border: 1px solid var(--line); border-radius: 10px; background: #fff; }
  .lb-out { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .lb-out input { flex: 1; min-width: 240px; font: 500 13px ui-monospace, Consolas, monospace; padding: 10px 12px; border: 1.5px dashed #cbd5e1; border-radius: 10px; background: var(--bg); }
</style>
</head>
<body>
<main>
  <div>
    <a class="back" href="dashboard.php">← Super Admin</a>
    <h1>Website analytics</h1>
    <p class="sub">Visits to restrogrow.com's own pages: home, about, FAQ, app demo, legal pages and the blog. <b>Our tracking</b> counts every visit in our own database. <b>Google Analytics</b> shows Google's numbers for the same pages (and the demo landing page).</p>
  </div>

  <?php if ($flash): ?><div class="flash<?php echo $flashErr ? ' err' : ''; ?>" role="status"><?php echo $h($flash); ?></div><?php endif; ?>

  <div class="bar-row">
    <nav class="tabs" aria-label="Sections">
      <a href="<?php echo $h($qs(['tab' => 'own'])); ?>" <?php echo $tab === 'own' ? 'aria-current="page"' : ''; ?>>Our tracking</a>
      <a href="<?php echo $h($qs(['tab' => 'people'])); ?>" <?php echo $tab === 'people' ? 'aria-current="page"' : ''; ?>>Visitors &amp; leads</a>
      <a href="<?php echo $h($qs(['tab' => 'links'])); ?>" <?php echo $tab === 'links' ? 'aria-current="page"' : ''; ?>>Link builder</a>
      <a href="<?php echo $h($qs(['tab' => 'ga'])); ?>" <?php echo $tab === 'ga' ? 'aria-current="page"' : ''; ?>>Google Analytics</a>
      <a href="<?php echo $h($qs(['tab' => 'settings'])); ?>" <?php echo $tab === 'settings' ? 'aria-current="page"' : ''; ?>>Settings</a>
    </nav>
    <?php if (!in_array($tab, ['settings', 'links'], true)): ?>
    <nav class="seg" aria-label="Period">
      <?php foreach ([1 => 'Today', 7 => '7 days', 30 => '30 days', 90 => '90 days'] as $d => $l): ?>
        <a href="<?php echo $h($qs(['d' => $d])); ?>" aria-current="<?php echo $d === $days ? 'true' : 'false'; ?>"><?php echo $l; ?></a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
  </div>

<?php if ($tab === 'own'): $s = $own['series']; ?>
  <div class="kpis">
    <div class="kpi"><small>Visitors · <?php echo $periodLabel; ?></small><b><?php echo $n($own['visitors']); ?></b><?php echo $delta($own['visitors'], $own['prev_visitors']); ?></div>
    <div class="kpi"><small>Page views</small><b><?php echo $n($own['views']); ?></b><?php echo $delta($own['views'], $own['prev_views']); ?></div>
    <div class="kpi"><small>Pages per visitor</small><b><?php echo $own['visitors'] ? number_format($own['views'] / $own['visitors'], 1) : '0'; ?></b></div>
    <div class="kpi"><small>Top source</small><b style="font-size:20px"><?php echo $h($own['sources'][0]['source'] ?? '—'); ?></b></div>
    <div class="kpi"><small class="live">On the site now</small><b><?php echo $n($vr['live']); ?></b><small>in the last 5 minutes</small></div>
  </div>
  <?php if ($days > 1): ?>
  <section class="panel">
    <h2>Visitors per day</h2>
    <?php echo $bars(array_column($s, 'visitors'), array_map(fn($x) => date('j M', strtotime($x['day'])), $s)); ?>
    <div class="axis"><span><?php echo date('j M', strtotime($s[0]['day'])); ?></span><span><?php echo date('j M', strtotime(end($s)['day'])); ?></span></div>
  </section>
  <?php endif; ?>
  <div class="cols">
    <section class="panel"><h2>Most visited pages</h2><?php echo $list($own['pages'], 'path', 'views', fn($r) => '<span title="' . htmlspecialchars($r['path']) . '">' . htmlspecialchars($r['path'] === '/' ? 'Home' : $r['path']) . '</span>'); ?></section>
    <section class="panel"><h2>Where visitors came from</h2><?php echo $list($own['sources'], 'source', 'visitors'); ?><p class="hint">Add <span class="mono">?utm_source=instagram&amp;utm_campaign=diwali</span> to links you share and they show up here by name.</p></section>
    <section class="panel"><h2>Devices</h2><?php echo $list($own['devices'], 'device', 'visitors', fn($r) => ucfirst($r['device'])); ?></section>
    <section class="panel"><h2>Campaigns</h2><?php echo $list($own['campaigns'], 'campaign', 'visitors'); ?></section>
    <section class="panel"><h2>Countries</h2><?php echo $list($vr['countries'], 'country', 'visitors', fn($r) => $flag($r['code']) . htmlspecialchars($r['country'])); ?></section>
    <section class="panel"><h2>Cities</h2><?php echo $list($vr['cities'], 'city', 'visitors', fn($r) => $flag($r['code']) . htmlspecialchars($r['city'] . ($r['region'] && $r['region'] !== $r['city'] ? ', ' . $r['region'] : ''))); ?>
      <?php if (!$geo['ready']): ?><p class="hint">Cities show once the location database is downloaded (<a href="<?php echo $h($qs(['tab' => 'settings'])); ?>">Settings</a>).</p><?php endif; ?></section>
    <section class="panel"><h2>Browsers &amp; apps</h2><?php echo $list($vr['browsers'], 'browser', 'visitors'); ?><p class="hint">"Instagram app" = opened from inside Instagram.</p></section>
  </div>
  <p class="note">One visitor = one browser (a random id in a cookie on restrogrow.com). Location comes from the internet address and is a close guess: mobile networks often show the nearest big city. Bots and search crawlers are left out. Restaurant pages (like restrogrow.com/arya-cafe) are not counted here. Location data: <a href="https://db-ip.com" target="_blank" rel="noopener">IP Geolocation by DB-IP</a>.</p>

<?php elseif ($tab === 'people'): ?>
  <div class="kpis">
    <div class="kpi"><small>New visitors · <?php echo $periodLabel; ?></small><b><?php echo $n($vr['new']); ?></b></div>
    <div class="kpi"><small>Came back</small><b><?php echo $n($vr['returning']); ?></b><small>visited more than once</small></div>
    <div class="kpi"><small>Leads</small><b><?php echo $n($vr['leads']); ?></b><small>contact form, chat, unfinished sign-up</small></div>
    <div class="kpi"><small>New restaurants</small><b><?php echo $n($vr['signups']); ?></b><small>signed up from the website</small></div>
    <div class="kpi"><small>Visitors who got in touch or signed up</small><b><?php echo $vr['new'] ? number_format(min($vr['converted'], $vr['new']) / $vr['new'] * 100, 1) : '0'; ?>%</b><small>of new visitors</small></div>
  </div>

  <section class="panel">
    <h2>Which sources bring business</h2>
    <p class="hint">Where people first came from, and how many of them got in touch or signed up. Put your effort where the right-hand columns are high, not just the visitors.</p>
    <?php if (!$vr['by_source']): ?><p class="empty">No data yet for this period.</p><?php else: ?>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Source</th><th class="num">Visitors</th><th class="num">Leads</th><th class="num">Restaurants</th><th class="num">Visitors who got in touch</th></tr></thead><tbody>
    <?php foreach ($vr['by_source'] as $r): ?>
      <tr><td><b><?php echo $h($r['source']); ?></b></td><td class="num"><?php echo $n($r['visitors']); ?></td><td class="num"><?php echo $n($r['leads']); ?></td><td class="num"><?php echo $n($r['signups']); ?></td>
        <td class="num"><?php echo $r['visitors'] ? number_format(min($r['converted'], $r['visitors']) / $r['visitors'] * 100, 1) . '%' : '—'; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>

  <div class="cols">
    <section class="panel"><h2>Campaigns</h2>
      <?php if (!$vr['campaigns']): ?><p class="empty">No tagged links used yet. Make one in the <a href="<?php echo $h($qs(['tab' => 'links'])); ?>">Link builder</a>.</p><?php else: ?>
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Campaign</th><th class="num">Visitors</th><th class="num">Leads</th><th class="num">Restaurants</th></tr></thead><tbody>
      <?php foreach ($vr['campaigns'] as $r): ?><tr><td><?php echo $h($r['campaign']); ?></td><td class="num"><?php echo $n($r['visitors']); ?></td><td class="num"><?php echo $n($r['leads']); ?></td><td class="num"><?php echo $n($r['signups']); ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?></section>
    <section class="panel"><h2>First page they saw</h2>
      <?php if (!$vr['landing']): ?><p class="empty">No data yet for this period.</p><?php else: ?>
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Page</th><th class="num">Visitors</th><th class="num">Leads</th></tr></thead><tbody>
      <?php foreach ($vr['landing'] as $r): ?><tr><td><?php echo $h($r['page'] === '/' ? 'Home' : $r['page']); ?></td><td class="num"><?php echo $n($r['visitors']); ?></td><td class="num"><?php echo $n($r['leads']); ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?></section>
    <section class="panel"><h2>What visitors did</h2>
      <?php echo $list($vr['events'], 'name', 'people', fn($r) => htmlspecialchars($eventNames[$r['name']] ?? $r['name'])); ?>
      <p class="hint">People who did each one (pricing seen = half the price section on screen).</p></section>
  </div>

  <section class="panel">
    <h2>🔥 Warm visitors who haven't got in touch</h2>
    <p class="hint">Came back, read a lot, saw pricing or clicked "Start free trial", but no lead or sign-up yet. You can't call them (no number), but this shows which sources and pages bring interested people. Score: 50+ warm, 70+ hot.</p>
    <?php if (!$vr['hot']): ?><p class="empty">Nobody warm right now.</p><?php else: ?>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Score</th><th>Where</th><th>Came from</th><th class="num">Visits · pages</th><th>Last seen</th></tr></thead><tbody>
    <?php foreach ($vr['hot'] as $v): ?>
      <tr><td><span class="score <?php echo $v['score'] >= 70 ? 'hot' : 'warm'; ?>"><?php echo (int)$v['score']; ?></span></td>
        <td><?php echo $flag($v['country_code']) . $h($place($v) ?: 'Unknown'); ?><small><?php echo $h(ucfirst((string)$v['device']) . ' · ' . $v['browser']); ?></small></td>
        <td><?php echo $h($v['first_source'] . ($v['first_campaign'] ? ' · ' . $v['first_campaign'] : '')); ?><small><?php echo $v['saw_pricing'] ? 'Saw pricing' : ''; ?><?php echo (int)$v['clicks'] ? ($v['saw_pricing'] ? ' · ' : '') . (int)$v['clicks'] . ' click' . ((int)$v['clicks'] > 1 ? 's' : '') : ''; ?></small></td>
        <td class="num"><?php echo (int)$v['sessions'] . ' · ' . (int)$v['pageviews']; ?></td><td><?php echo $h(date('j M, g:i A', strtotime($v['last_seen']))); ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>

  <section class="panel">
    <h2>Recent visitors</h2>
    <?php if (!$vr['recent']): ?><p class="empty">No visitors yet for this period.</p><?php else: ?>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Last seen</th><th>Where</th><th>First came from</th><th>Latest visit from</th><th class="num">Visits · pages</th><th>Score</th><th>Became</th></tr></thead><tbody>
    <?php foreach ($vr['recent'] as $v): ?>
      <tr><td><?php echo $h(date('j M, g:i A', strtotime($v['last_seen']))); ?><small>first <?php echo $h(date('j M', strtotime($v['first_seen']))); ?></small></td>
        <td><?php echo $flag($v['country_code']) . $h($place($v) ?: 'Unknown'); ?><small><?php echo $h(ucfirst((string)$v['device']) . ' · ' . $v['browser'] . ' · ' . $v['os']); ?></small></td>
        <td><?php echo $h($v['first_source'] ?: 'Direct'); ?><small><?php echo $h(trim(($v['first_campaign'] ?? '') . ($v['first_landing'] ? ' ↳ ' . ($v['first_landing'] === '/' ? 'Home' : $v['first_landing']) : ''))); ?></small></td>
        <td><?php echo $h($v['last_source'] ?: '—'); ?></td>
        <td class="num"><?php echo (int)$v['sessions'] . ' · ' . (int)$v['pageviews']; ?></td>
        <td><span class="score <?php echo $v['score'] >= 70 ? 'hot' : ($v['score'] >= 50 ? 'warm' : ''); ?>"><?php echo (int)$v['score']; ?></span></td>
        <td><?php echo $v['restaurant_id'] ? '<span class="tag">Restaurant ' . $h($v['restaurant_id']) . '</span>' : ($v['lead_kind'] ? '<span class="tag lead">' . $h(['contact' => 'Lead', 'chat' => 'Chat lead'][$v['lead_kind']] ?? 'Lead') . '</span>' : ''); ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
    <p class="note">Each lead in Super Admin › Leads and Chat leads now shows where it came from and where the person is.</p>
  </section>

<?php elseif ($tab === 'links'): ?>
  <section class="panel">
    <h2>Make a link that tells you where people came from</h2>
    <p class="hint">Use these instead of plain restrogrow.com everywhere you post. Every visit, lead and new restaurant from the link is counted under its source and campaign in <b>Visitors &amp; leads</b>.</p>
    <div class="lb-grid">
      <div class="field"><label for="lbWhere">Where you'll post it</label><select id="lbWhere">
        <option value="instagram|bio">Instagram bio</option><option value="instagram|story">Instagram story</option><option value="instagram|post">Instagram post / reel</option>
        <option value="instagram|paid">Instagram ad</option><option value="facebook|post">Facebook post</option><option value="facebook|paid">Facebook ad</option>
        <option value="whatsapp|status">WhatsApp status</option><option value="whatsapp|message">WhatsApp message / group</option><option value="youtube|video">YouTube video</option>
        <option value="google|cpc">Google Ads</option><option value="google|business">Google Business profile</option><option value="linkedin|post">LinkedIn</option>
        <option value="email|newsletter">Email</option><option value="qr|print">QR code / poster / visiting card</option><option value="partner|referral">Partner / reseller</option><option value="other|other">Other</option>
      </select></div>
      <div class="field"><label for="lbCamp">Campaign name</label><input type="text" id="lbCamp" placeholder="e.g. diwali-offer, oct-reel-2" maxlength="60"><small>Short, no spaces. Same name = counted together.</small></div>
      <div class="field"><label for="lbPage">Page</label><select id="lbPage"><option value="/">Home</option><option value="/#pricing">Pricing</option><option value="/main/admin/login.php?tab=signup">Sign-up (free trial)</option>
        <option value="/about/">About</option><option value="/faq/">FAQ</option><option value="/blog/">Blog</option></select></div>
    </div>
    <div class="lb-out"><input id="lbUrl" readonly aria-label="Your link"><button class="btn primary" type="button" id="lbCopy">Copy link</button></div>
    <p class="hint" id="lbQr"></p>
  </section>
  <section class="panel">
    <h2>Good habits</h2>
    <ol class="steps">
      <li><b>Instagram bio:</b> put the Instagram-bio link there, not plain restrogrow.com. Instagram hides where visits come from otherwise; many show up as "Direct".</li>
      <li><b>One campaign name per idea</b> (diwali-offer, free-trial-reel). Then Visitors &amp; leads shows which idea brought restaurants, not just clicks.</li>
      <li><b>Print a QR</b> with the QR-code link for posters, visiting cards and stalls, so offline visits are counted too.</li>
      <li><b>Ads:</b> Google and Meta add their own click ids; visits from ads are recognised even without these tags.</li>
    </ol>
  </section>
  <script>
  (function () {
    var base = <?php echo json_encode(rtrim((string)(function_exists('env') ? env('APP_URL', '') : ''), '/') ?: 'https://restrogrow.com'); ?>;
    var $ = function (id) { return document.getElementById(id); };
    function build() {
      var w = $('lbWhere').value.split('|'), camp = $('lbCamp').value.trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
      var page = $('lbPage').value, hash = '';
      if (page.indexOf('#') > -1) { hash = page.slice(page.indexOf('#')); page = page.slice(0, page.indexOf('#')); }
      var p = new URLSearchParams({ utm_source: w[0], utm_medium: w[1] });
      if (camp) p.set('utm_campaign', camp);
      var u = base + page + (page.indexOf('?') > -1 ? '&' : '?') + p.toString() + hash;
      $('lbUrl').value = u;
      $('lbQr').innerHTML = 'QR code for this link: <a target="_blank" rel="noopener" href="https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=' + encodeURIComponent(u) + '">open / download</a>';
    }
    ['lbWhere', 'lbCamp', 'lbPage'].forEach(function (id) { $(id).addEventListener('input', build); $(id).addEventListener('change', build); });
    $('lbCopy').addEventListener('click', function () { var i = $('lbUrl'); i.select(); (navigator.clipboard ? navigator.clipboard.writeText(i.value) : Promise.resolve(document.execCommand('copy'))).then(function () { $('lbCopy').textContent = 'Copied ✓'; setTimeout(function () { $('lbCopy').textContent = 'Copy link'; }, 1500); }); });
    build();
  })();
  </script>

<?php elseif ($tab === 'ga'): ?>
  <?php if (!$sa): ?>
    <section class="panel">
      <h2>Connect Google Analytics</h2>
      <p class="hint">To show Google's real numbers here, upload a Google service-account key once. Steps are in <a href="<?php echo $h($qs(['tab' => 'settings'])); ?>">Settings</a>.</p>
    </section>
  <?php else: ?>
    <div class="bar-row">
      <nav class="seg" aria-label="Website">
        <?php foreach (['restrogrow.com' => 'restrogrow.com', 'demo.restrogrow.com' => 'Demo landing', '' => 'Both'] as $k => $l): ?>
          <a href="<?php echo $h($qs(['host' => $k])); ?>" aria-current="<?php echo $k === $host ? 'true' : 'false'; ?>"><?php echo $h($l); ?></a>
        <?php endforeach; ?>
      </nav>
      <form method="post" class="inline"><input type="hidden" name="action" value="refresh"><input type="hidden" name="d" value="<?php echo $days; ?>"><input type="hidden" name="host" value="<?php echo $h($host); ?>"><input type="hidden" name="tab" value="ga"><button class="btn" type="submit">Refresh from Google</button></form>
    </div>
    <?php if ($gaErr): ?><div class="flash err"><?php echo $h($gaErr); ?></div>
    <?php else: $dl = array_map(fn($r) => (int)$r['activeUsers'], $ga['daily']); ?>
      <div class="kpis">
        <div class="kpi"><small>Users · <?php echo $periodLabel; ?></small><b><?php echo $n($ga['users']); ?></b></div>
        <div class="kpi"><small>Sessions</small><b><?php echo $n($ga['sessions']); ?></b></div>
        <div class="kpi"><small>Page views</small><b><?php echo $n($ga['views']); ?></b></div>
        <div class="kpi"><small>Avg. session</small><b><?php echo floor($ga['avg_duration'] / 60) . 'm ' . str_pad((string)round(fmod($ga['avg_duration'], 60)), 2, '0', STR_PAD_LEFT) . 's'; ?></b></div>
        <div class="kpi"><small>Engaged sessions</small><b><?php echo round($ga['engagement'] * 100); ?>%</b></div>
      </div>
      <?php if ($days > 1 && $dl): ?>
      <section class="panel"><h2>Users per day</h2><?php echo $bars($dl, array_map(fn($r) => date('j M', strtotime($r['date'])), $ga['daily'])); ?>
        <div class="axis"><span><?php echo date('j M', strtotime($ga['daily'][0]['date'])); ?></span><span><?php echo date('j M', strtotime(end($ga['daily'])['date'])); ?></span></div></section>
      <?php endif; ?>
      <div class="cols">
        <section class="panel"><h2>Top pages</h2><?php echo $list($ga['pages'], 'pagePath', 'screenPageViews', fn($r) => '<span title="' . htmlspecialchars($r['hostName'] . $r['pagePath']) . '">' . htmlspecialchars(($host === '' ? $r['hostName'] : '') . ($r['pagePath'] === '/' ? ($host === '' ? '/' : 'Home') : $r['pagePath'])) . '</span>'); ?></section>
        <section class="panel"><h2>Channels</h2><?php echo $list($ga['channels'], 'sessionDefaultChannelGroup', 'sessions'); ?></section>
        <section class="panel"><h2>Countries</h2><?php echo $list($ga['countries'], 'country', 'activeUsers'); ?></section>
        <section class="panel"><h2>Websites in this property</h2><?php echo $list($ga['hosts'], 'hostName', 'activeUsers'); ?></section>
      </div>
      <p class="note">From Google Analytics property <?php echo $h($ga['property']); ?>, updated <?php echo $h(date('g:i A', (int)$ga['cached_at'])); ?>. Google can take up to a day to finish counting the latest visits.</p>
    <?php endif; ?>
  <?php endif; ?>

<?php else: /* settings */ ?>
  <section class="panel">
    <div class="bar-row"><h2>Location database (country, state, city)</h2><?php echo $geo['ready'] ? '<span class="pill ok">Ready</span>' : '<span class="pill no">Not downloaded</span>'; ?></div>
    <p class="hint"><?php echo $geo['ready'] ? 'Updated ' . $h(date('j M Y', strtotime((string)$geo['updated']))) . ' (' . (int)$geo['mb'] . ' MB). A new copy comes every month by itself (with the email cron job).' : 'Download it once (about 60 MB, takes a minute). After that it updates itself every month.'; ?>
      Free <a href="https://db-ip.com/db/download/ip-to-city-lite" target="_blank" rel="noopener">DB-IP City Lite</a>, kept on this server: no outside service sees your visitors.</p>
    <form method="post" class="inline"><input type="hidden" name="action" value="geo_update"><input type="hidden" name="tab" value="settings"><button class="btn primary" type="submit"><?php echo $geo['ready'] ? 'Update now' : 'Download now'; ?></button></form>
    <p class="hint">Check what the server sees for you: <a href="../api/site_hit.php?whoami=1" target="_blank" rel="noopener">your address and place</a>.</p>
  </section>

  <section class="panel">
    <div class="bar-row"><h2>Don't count my own visits</h2><?php echo $ignored ? '<span class="pill ok">Not counted</span>' : '<span class="pill no">Counted</span>'; ?></div>
    <p class="hint">Turn this on in every browser you and your team use (phone too: open this page there), so your own checks don't look like customers.</p>
    <form method="post" class="inline"><input type="hidden" name="action" value="ignore_me"><input type="hidden" name="tab" value="settings"><input type="hidden" name="on" value="<?php echo $ignored ? '' : '1'; ?>">
      <button class="btn<?php echo $ignored ? '' : ' primary'; ?>" type="submit"><?php echo $ignored ? 'Count this browser again' : "Don't count this browser"; ?></button></form>
  </section>

  <section class="panel">
    <h2>Google Analytics on the website</h2>
    <form method="post" style="display:grid;gap:14px">
      <input type="hidden" name="action" value="settings"><input type="hidden" name="tab" value="settings">
      <div class="field"><label for="mid">Measurement ID</label><input type="text" id="mid" name="ga_measurement_id" value="<?php echo $h($mid); ?>" placeholder="G-XXXXXXXXXX" autocomplete="off">
        <small>Used by every restrogrow.com page. Leave empty to turn Google Analytics off (our tracking keeps running). Found in GA › Admin › Data streams.</small></div>
      <div class="field"><label for="pid">Property ID</label><input type="text" id="pid" name="ga_property_id" value="<?php echo $h($pid); ?>" inputmode="numeric" autocomplete="off">
        <small>Only numbers. GA › Admin › Property details. Used to read numbers for the Google Analytics tab.</small></div>
      <div><button class="btn primary" type="submit">Save</button></div>
    </form>
  </section>

  <section class="panel">
    <div class="bar-row"><h2>Google key for reading numbers</h2><?php echo $sa ? '<span class="pill ok">Connected</span>' : '<span class="pill no">Not connected</span>'; ?></div>
    <?php if ($sa): ?>
      <p class="hint">Service account: <span class="mono"><?php echo $h($sa['client_email']); ?></span>. It needs <b>Viewer</b> access in Google Analytics (step 4).</p>
      <form method="post" class="inline"><input type="hidden" name="action" value="remove_key"><input type="hidden" name="tab" value="settings"><button class="btn danger" type="submit">Remove key</button></form>
    <?php endif; ?>
    <ol class="steps">
      <li>Open <a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">Google Cloud › New project</a> (same Google account as Analytics). Name it <span class="mono">restrogrow-analytics</span> and press Create.</li>
      <li>Turn on the API: <a href="https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com" target="_blank" rel="noopener">Google Analytics Data API</a> › <b>Enable</b>.</li>
      <li><a href="https://console.cloud.google.com/iam-admin/serviceaccounts/create" target="_blank" rel="noopener">Create a service account</a> named <span class="mono">ga-reader</span> (no roles needed). Open it › <b>Keys</b> › <b>Add key</b> › <b>Create new key</b> › <b>JSON</b>. A .json file downloads.</li>
      <li>In <a href="https://analytics.google.com/analytics/web/#/a412266518p559286209/admin/suiteusermanagement/property" target="_blank" rel="noopener">GA › Admin › Property access management</a>, press <b>+</b> › Add users, paste the service account email (ends with <span class="mono">iam.gserviceaccount.com</span>), role <b>Viewer</b>, untick "Notify", Add.</li>
      <li>Upload the .json file here:
        <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:8px">
          <input type="hidden" name="action" value="key"><input type="hidden" name="tab" value="settings">
          <input type="file" name="key" id="keyfile" accept="application/json,.json" required aria-label="Service account JSON key">
          <button class="btn primary" type="submit">Upload key</button>
        </form>
      </li>
    </ol>
    <p class="note">The key is saved on the server as a hidden file that the website never serves, and it only has permission to read Analytics. You can remove it any time.</p>
  </section>
<?php endif; ?>
</main>
</body>
</html>
