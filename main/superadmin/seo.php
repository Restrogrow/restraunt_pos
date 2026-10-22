<?php
/**
 * SEO (Super Admin › Tools): how restrogrow.com's own pages do in Google.
 * Overview (clicks, impressions, CTR, position vs the period before; daily clicks; best keywords; easy wins),
 * Keywords (every search that showed our pages, with position and its change), Pages (our own views next to
 * Google clicks, position and index status), Issues (index problems, on-page problems, sitemap errors) and
 * Setup. Engine and caching: config/site_seo.php. Restaurant websites on restrogrow.com are left out.
 */
require_once __DIR__ . '/auth.php';
require_superadmin();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/site_seo.php';

$conn = getConnection();
seoEnsure($conn);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$sa = gaServiceAccount();

// background refresh, a few pages per call (the page calls this until nothing is left)
if (isset($_GET['step'])) {
    header('Content-Type: application/json');
    $out = ['audited' => 0, 'inspected' => 0, 'error' => ''];
    try {
        $out['audited'] = seoAuditStale($conn, 4);
        if ($sa) $out['inspected'] = seoInspectStale($conn, 3);
    } catch (Throwable $e) { $out['error'] = $e->getMessage(); }
    $urls = seoOwnUrls($conn);
    $fresh = fn(string $t) => (int)$conn->query("SELECT COUNT(*) FROM $t WHERE checked_at > DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
    $out['remaining'] = max(0, count($urls) - $fresh('seo_page_audit')) + ($sa ? max(0, count($urls) - $fresh('seo_url_status')) : 0);
    echo json_encode($out);
    exit;
}

$flash = ''; $flashErr = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) { http_response_code(403); exit('Not allowed'); }
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'refresh') {
            seoClearCache($conn);
            $flash = 'Fresh numbers loaded from Search Console.';
        } elseif ($act === 'recheck') {
            $conn->exec('DELETE FROM seo_url_status');
            $conn->exec('DELETE FROM seo_page_audit');
            $flash = 'Checking every page again. It runs in the background; keep this tab open for a minute.';
        } elseif ($act === 'site') {
            $site = trim((string)($_POST['gsc_site'] ?? ''));
            if (!preg_match('#^(sc-domain:[a-z0-9.-]+|https?://[^\s]+/)$#i', $site)) throw new RuntimeException('Use sc-domain:restrogrow.com for a domain property, or the full address ending in / for a URL-prefix property.');
            saSet($conn, 'gsc_site', $site);
            saSet($conn, 'gsc_token_cache', null);
            seoClearCache($conn);
            $conn->exec('DELETE FROM seo_url_status');
            $flash = 'Saved. Search Console property is now ' . $site . '.';
        }
    } catch (Throwable $e) { $flash = $e->getMessage(); $flashErr = true; }
    $_SESSION['sa_seo_flash'] = [$flash, $flashErr];
    header('Location: seo.php?' . http_build_query(array_filter(['d' => $_POST['d'] ?? null, 'tab' => $_POST['tab'] ?? null])));
    exit;
}
if (!empty($_SESSION['sa_seo_flash'])) { [$flash, $flashErr] = $_SESSION['sa_seo_flash']; unset($_SESSION['sa_seo_flash']); }

$days = in_array((int)($_GET['d'] ?? 28), [7, 28, 90], true) ? (int)($_GET['d'] ?? 28) : 28;
$tab = in_array($_GET['tab'] ?? 'overview', ['overview', 'keywords', 'pages', 'issues', 'setup'], true) ? (string)($_GET['tab'] ?? 'overview') : 'overview';
$qs = fn(array $o) => 'seo.php?' . http_build_query(array_merge(['d' => $days, 'tab' => $tab], $o));

$rep = null; $repErr = '';
if ($sa && $tab !== 'setup') { try { $rep = seoReport($conn, $days); } catch (Throwable $e) { $repErr = $e->getMessage(); } }
$sitemaps = []; $smErr = '';
if ($sa && in_array($tab, ['issues', 'setup'], true)) { try { $sitemaps = seoSitemaps($conn); } catch (Throwable $e) { $smErr = $e->getMessage(); } }
$views = seoViews($conn, $days);
$urls = seoOwnUrls($conn);
$status = $conn->query('SELECT * FROM seo_url_status')->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);
$audit = $conn->query('SELECT * FROM seo_page_audit')->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);

$key = fn(string $url) => rtrim(seoPathOf($url), '/') === '' ? '/' : rtrim(seoPathOf($url), '/');
$gscPages = [];
foreach (($rep['pages'] ?? []) as $path => $p) $gscPages[$key('https://x' . $path)] = $p;

$n = fn($v) => number_format((float)$v);
$pct = fn($v) => number_format($v * 100, 1) . '%';
$pos = fn($v) => $v ? number_format($v, 1) : '–';
$delta = function ($now, $prev, bool $lowerIsBetter = false) {
    if (!$prev) return $now ? '<span class="up">new</span>' : '';
    $d = $lowerIsBetter ? $prev - $now : $now - $prev;
    if ($lowerIsBetter) return abs($d) < 0.05 ? '' : '<span class="' . ($d > 0 ? 'up' : 'down') . '">' . ($d > 0 ? '↑' : '↓') . number_format(abs($d), 1) . '</span>';
    $p = round(($now - $prev) / $prev * 100);
    return '<span class="' . ($p >= 0 ? 'up' : 'down') . '">' . ($p >= 0 ? '↑' : '↓') . abs($p) . '%</span>';
};
$posChange = function ($now, $prev) {
    if ($prev === null) return '<span class="muted">new</span>';
    $d = $prev - $now;
    if (abs($d) < 0.5) return '<span class="muted">–</span>';
    return '<span class="' . ($d > 0 ? 'up' : 'down') . '">' . ($d > 0 ? '↑' : '↓') . number_format(abs($d), 1) . '</span>';
};
$idx = function (?array $s) use ($h) {
    if (!$s) return '<span class="pill wait">Not checked yet</span>';
    if (!empty($s['error'])) return '<span class="pill wait" title="' . $h($s['error']) . '">Could not check</span>';
    if (($s['verdict'] ?? '') === 'PASS') return '<span class="pill ok">Indexed</span>';
    return '<span class="pill no" title="' . $h($s['coverage']) . '">' . $h($s['coverage'] ?: 'Not indexed') . '</span>';
};
$label = fn(string $url) => ($p = seoPathOf($url)) === '/' ? 'Home' : $p;

// issues: index problems, on-page problems, sitemap problems
$issues = [];
foreach ($urls as $u) {
    $s = $status[$u] ?? null;
    if ($s && empty($s['error']) && ($s['verdict'] ?? '') !== 'PASS') $issues[] = ['high', $u, 'Not in Google: ' . ($s['coverage'] ?: 'not indexed')];
    if ($s && !empty($s['google_canonical']) && rtrim($s['google_canonical'], '/') !== rtrim($u, '/')) $issues[] = ['medium', $u, 'Google picked a different main address: ' . $s['google_canonical']];
    foreach (json_decode((string)($audit[$u]['issues'] ?? '[]'), true) ?: [] as [$lvl, $msg]) $issues[] = [$lvl, $u, $msg];
}
foreach ($sitemaps as $sm) {
    if ((int)($sm['errors'] ?? 0) > 0) $issues[] = ['high', $sm['path'], $sm['errors'] . ' errors in this sitemap'];
    if ((int)($sm['warnings'] ?? 0) > 0) $issues[] = ['low', $sm['path'], $sm['warnings'] . ' warnings in this sitemap'];
}
$rank = ['high' => 0, 'medium' => 1, 'low' => 2];
usort($issues, fn($a, $b) => $rank[$a[0]] <=> $rank[$b[0]]);
$issueCount = fn(string $u) => count(array_filter($issues, fn($i) => $i[1] === $u));

$kws = $rep['keywords'] ?? [];
$wins = array_values(array_filter($kws, fn($k) => $k['position'] >= 4 && $k['position'] <= 20 && $k['impressions'] >= 10));
usort($wins, fn($a, $b) => $b['impressions'] <=> $a['impressions']);
$checkedAudit = count(array_intersect($urls, array_keys($audit)));
$checkedIdx = count(array_intersect($urls, array_keys($status)));
$indexed = count(array_filter($urls, fn($u) => ($status[$u]['verdict'] ?? '') === 'PASS'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>SEO · Super Admin</title>
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
  .sub { margin: 0; color: var(--soft); font-size: 15px; line-height: 1.55; max-width: 78ch; }
  .bar-row { display: flex; gap: 10px 16px; flex-wrap: wrap; align-items: center; justify-content: space-between; }
  .tabs, .seg { display: inline-flex; gap: 4px; background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 4px; flex-wrap: wrap; }
  .tabs a, .seg a { text-decoration: none; color: var(--ink); font-weight: 600; font-size: 13.5px; padding: 7px 12px; border-radius: 9px; }
  .tabs a[aria-current="page"], .seg a[aria-current="true"] { background: var(--brand); color: #fff; }
  .tabs a .cnt { display: inline-block; margin-left: 4px; font-size: 11px; background: #fee2e2; color: var(--bad); border-radius: 999px; padding: 0 6px; }
  .flash { padding: 12px 14px; border-radius: 12px; background: #ecfdf5; color: var(--good); font-weight: 600; font-size: 14px; border: 1px solid #bbf7d0; }
  .flash.err { background: #fef2f2; color: var(--bad); border-color: #fecaca; }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; }
  .kpi { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 14px 16px; display: grid; gap: 4px; }
  .kpi small { color: var(--soft); font-weight: 600; font-size: 12.5px; }
  .kpi b { font-size: 26px; font-variant-numeric: tabular-nums; letter-spacing: -.01em; }
  .up { color: var(--good); font-size: 12.5px; font-weight: 700; } .down { color: var(--bad); font-size: 12.5px; font-weight: 700; } .muted { color: var(--muted); font-size: 12.5px; }
  .panel { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 16px; display: grid; gap: 12px; min-width: 0; }
  .panel h2 { margin: 0; font-size: 16px; }
  .panel .hint { margin: 0; color: var(--soft); font-size: 13px; line-height: 1.5; }
  .cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 420px), 1fr)); gap: 14px; }
  .bars { display: flex; align-items: flex-end; gap: 3px; height: 120px; padding-top: 6px; border-bottom: 1px solid var(--line); }
  .bars span { flex: 1; background: var(--brand); border-radius: 4px 4px 0 0; min-width: 2px; opacity: .85; }
  .axis { display: flex; justify-content: space-between; color: var(--muted); font-size: 11.5px; }
  .tbl-wrap { overflow-x: auto; }
  table.tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
  .tbl th { text-align: left; color: var(--soft); font-weight: 700; font-size: 12px; padding: 8px 10px; border-bottom: 1px solid var(--line); white-space: nowrap; }
  .tbl td { padding: 9px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
  .tbl td.num, .tbl th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .tbl small { display: block; color: var(--muted); font-size: 11.5px; margin-top: 2px; word-break: break-all; }
  .tbl a { color: var(--ink); }
  .pill { display: inline-block; font-size: 11.5px; font-weight: 700; padding: 3px 9px; border-radius: 999px; white-space: nowrap; }
  .pill.ok { background: #dcfce7; color: var(--good); } .pill.no { background: #fee2e2; color: var(--bad); } .pill.wait { background: #f1f5f9; color: var(--soft); }
  .lvl { display: inline-block; font-size: 11px; font-weight: 800; text-transform: uppercase; padding: 2px 8px; border-radius: 6px; }
  .lvl.high { background: #fee2e2; color: #b91c1c; } .lvl.medium { background: #ffedd5; color: #c2410c; } .lvl.low { background: #f1f5f9; color: var(--soft); }
  .empty { margin: 0; color: var(--muted); font-size: 13.5px; }
  form.inline { display: inline; }
  .btn { border: 1px solid var(--line); background: #fff; color: var(--ink); font: 700 13.5px Inter, sans-serif; padding: 9px 14px; border-radius: 11px; cursor: pointer; text-decoration: none; display: inline-block; }
  .btn.primary { background: var(--brand); border-color: var(--brand); color: #fff; }
  .steps { margin: 0; padding-left: 20px; display: grid; gap: 10px; font-size: 14px; line-height: 1.55; }
  .mono { font-family: ui-monospace, Consolas, monospace; font-size: 12.5px; background: var(--bg); border: 1px solid var(--line); border-radius: 6px; padding: 1px 6px; word-break: break-all; }
  .field input[type=text] { font: 500 14px Inter, sans-serif; padding: 9px 11px; border: 1px solid var(--line); border-radius: 10px; width: 100%; max-width: 360px; }
  #prog { font-size: 13px; color: var(--soft); }
  a:focus-visible, button:focus-visible, input:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
</style>
</head>
<body>
<main>
  <div>
    <a class="back" href="dashboard.php">← Super Admin</a>
    <h1>SEO</h1>
    <p class="sub">How restrogrow.com's own pages do in Google: keywords, positions, clicks, which pages are indexed, and what to fix. Restaurant websites are left out. Google numbers are about 2 days behind and refresh every 6 hours.</p>
  </div>
  <?php if ($flash): ?><div class="flash<?php echo $flashErr ? ' err' : ''; ?>" role="status"><?php echo $h($flash); ?></div><?php endif; ?>
  <?php if (!$sa): ?><div class="flash err">Search Console is not connected yet, so only our own views and the page checks show. See <a href="<?php echo $h($qs(['tab' => 'setup'])); ?>">Setup</a> (5 minutes).</div>
  <?php elseif ($repErr && $tab !== 'setup'): ?><div class="flash err"><?php echo $h($repErr); ?></div><?php endif; ?>

  <div class="bar-row">
    <nav class="tabs" aria-label="SEO sections">
      <?php foreach (['overview' => 'Overview', 'keywords' => 'Keywords', 'pages' => 'Pages', 'issues' => 'Issues', 'setup' => 'Setup'] as $k => $l): ?>
        <a href="<?php echo $h($qs(['tab' => $k])); ?>"<?php echo $tab === $k ? ' aria-current="page"' : ''; ?>><?php echo $l; ?><?php if ($k === 'issues' && $issues): ?><span class="cnt"><?php echo count($issues); ?></span><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <nav class="seg" aria-label="Period">
        <?php foreach ([7 => '7 days', 28 => '28 days', 90 => '3 months'] as $d => $l): ?><a href="<?php echo $h($qs(['d' => $d])); ?>"<?php echo $days === $d ? ' aria-current="true"' : ''; ?>><?php echo $l; ?></a><?php endforeach; ?>
      </nav>
      <?php if ($sa): ?><form method="post" class="inline"><input type="hidden" name="action" value="refresh"><input type="hidden" name="d" value="<?php echo $days; ?>"><input type="hidden" name="tab" value="<?php echo $h($tab); ?>"><button class="btn">Refresh</button></form><?php endif; ?>
    </div>
  </div>
  <p id="prog" aria-live="polite"></p>

<?php if ($tab === 'overview'): ?>
  <?php $o = $rep['own'] ?? null; $op = $rep['own_prev'] ?? null; ?>
  <div class="kpis">
    <div class="kpi"><small>Clicks from Google</small><b><?php echo $o ? $n($o['clicks']) : '–'; ?></b><?php echo $o ? $delta($o['clicks'], $op['clicks']) : ''; ?></div>
    <div class="kpi"><small>Times shown in Google</small><b><?php echo $o ? $n($o['impressions']) : '–'; ?></b><?php echo $o ? $delta($o['impressions'], $op['impressions']) : ''; ?></div>
    <div class="kpi"><small>Click rate</small><b><?php echo $o ? $pct($o['ctr']) : '–'; ?></b></div>
    <div class="kpi"><small>Average position</small><b><?php echo $o ? $pos($o['position']) : '–'; ?></b><?php echo $o ? $delta($o['position'], $op['position'], true) : ''; ?></div>
    <div class="kpi"><small>Pages in Google</small><b><?php echo $indexed; ?> / <?php echo count($urls); ?></b><span class="muted"><?php echo $checkedIdx < count($urls) ? $checkedIdx . ' checked so far' : 'all checked'; ?></span></div>
    <div class="kpi"><small>Our page views (<?php echo $days; ?> days)</small><b><?php echo $n(array_sum(array_column($views, 'views'))); ?></b><span class="muted"><?php echo $n(array_sum(array_column($views, 'visitors'))); ?> visitors</span></div>
  </div>
  <?php if ($rep): ?><p class="hint" style="margin:0;color:var(--soft);font-size:13px">Our pages only, <?php echo $h($rep['period'][0]); ?> to <?php echo $h($rep['period'][1]); ?>, compared with the <?php echo $days; ?> days before. Whole domain including restaurant websites: <?php echo $n($rep['site']['clicks']); ?> clicks, <?php echo $n($rep['site']['impressions']); ?> impressions.</p><?php endif; ?>
  <div class="cols">
    <section class="panel">
      <h2>Clicks from Google, day by day</h2>
      <?php $dl = $rep['daily'] ?? []; if ($dl): $mx = max(1, max(array_column($dl, 'clicks'))); ?>
        <div class="bars" role="img" aria-label="Daily clicks"><?php foreach ($dl as $r): ?><span title="<?php echo $h($r['date'] . ': ' . $r['clicks'] . ' clicks, ' . $r['impressions'] . ' impressions'); ?>" style="height:<?php echo max(2, round($r['clicks'] / $mx * 100)); ?>%"></span><?php endforeach; ?></div>
        <div class="axis"><span><?php echo $h($dl[0]['date']); ?></span><span><?php echo $h(end($dl)['date']); ?></span></div>
      <?php else: ?><p class="empty">No Search Console data yet.</p><?php endif; ?>
    </section>
    <section class="panel">
      <h2>Easy wins</h2>
      <p class="hint">Searches where we show on page 1–2 (position 4–20) but get few clicks. Improve that page's title and first paragraph, or write a post for it.</p>
      <?php if ($wins): ?><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Search</th><th class="num">Shown</th><th class="num">Position</th></tr></thead><tbody>
        <?php foreach (array_slice($wins, 0, 8) as $k): ?><tr><td><?php echo $h($k['query']); ?><small><?php echo $h($k['page']); ?></small></td><td class="num"><?php echo $n($k['impressions']); ?></td><td class="num"><?php echo $pos($k['position']); ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php else: ?><p class="empty">None right now.</p><?php endif; ?>
    </section>
  </div>
  <section class="panel">
    <h2>Top keywords</h2>
    <?php if ($kws): ?><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Search</th><th class="num">Clicks</th><th class="num">Shown</th><th class="num">Position</th><th class="num">Change</th></tr></thead><tbody>
      <?php foreach (array_slice($kws, 0, 12) as $k): ?><tr><td><?php echo $h($k['query']); ?><small><?php echo $h($k['page']); ?></small></td><td class="num"><?php echo $n($k['clicks']); ?></td><td class="num"><?php echo $n($k['impressions']); ?></td><td class="num"><?php echo $pos($k['position']); ?></td><td class="num"><?php echo $posChange($k['position'], $k['prev_position']); ?></td></tr><?php endforeach; ?>
    </tbody></table></div><p class="hint"><a href="<?php echo $h($qs(['tab' => 'keywords'])); ?>">All <?php echo count($kws); ?> keywords →</a></p>
    <?php else: ?><p class="empty">No keywords yet.</p><?php endif; ?>
  </section>

<?php elseif ($tab === 'keywords'): ?>
  <section class="panel">
    <h2>Keywords (<?php echo count($kws); ?>)</h2>
    <p class="hint">Every search where Google showed one of our pages. Position 1–10 is page 1. Change compares with the <?php echo $days; ?> days before (↑ = moved up).</p>
    <?php if ($kws): ?><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Search</th><th>Our page</th><th class="num">Clicks</th><th class="num">Shown</th><th class="num">Click rate</th><th class="num">Position</th><th class="num">Change</th></tr></thead><tbody>
      <?php foreach ($kws as $k): ?><tr><td><?php echo $h($k['query']); ?></td><td><?php echo $h($k['page']); ?></td><td class="num"><?php echo $n($k['clicks']); ?></td><td class="num"><?php echo $n($k['impressions']); ?></td><td class="num"><?php echo $pct($k['ctr']); ?></td><td class="num"><?php echo $pos($k['position']); ?></td><td class="num"><?php echo $posChange($k['position'], $k['prev_position']); ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php else: ?><p class="empty"><?php echo $sa ? 'No keywords for this period.' : 'Connect Search Console in Setup to see keywords.'; ?></p><?php endif; ?>
  </section>

<?php elseif ($tab === 'pages'): ?>
  <section class="panel">
    <h2>Pages (<?php echo count($urls); ?>)</h2>
    <p class="hint">Our own views come from the website's tracking (Website analytics). Clicks, shown and position come from Google. Index status is checked once a day.</p>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Page</th><th class="num">Our views</th><th class="num">Google clicks</th><th class="num">Shown</th><th class="num">Position</th><th>In Google</th><th class="num">Issues</th></tr></thead><tbody>
      <?php foreach ($urls as $u): $k = $key($u); $g = $gscPages[$k] ?? null; $v = $views[$k] ?? null; $a = $audit[$u] ?? null; ?>
        <tr>
          <td><a href="<?php echo $h($u); ?>" target="_blank" rel="noopener"><?php echo $h($label($u)); ?></a><?php if ($a): ?><small><?php echo $h($a['title']); ?></small><?php endif; ?></td>
          <td class="num"><?php echo $v ? $n($v['views']) : '0'; ?></td>
          <td class="num"><?php echo $g ? $n($g['clicks']) : '–'; ?></td>
          <td class="num"><?php echo $g ? $n($g['impressions']) : '–'; ?></td>
          <td class="num"><?php echo $g ? $pos($g['position']) : '–'; ?></td>
          <td><?php echo $idx($status[$u] ?? null); ?><?php if (!empty($status[$u]['last_crawl'])): ?><small>Crawled <?php echo $h(date('j M', strtotime($status[$u]['last_crawl']))); ?></small><?php endif; ?></td>
          <td class="num"><?php $ic = $issueCount($u); echo $ic ? '<a href="' . $h($qs(['tab' => 'issues'])) . '">' . $ic . '</a>' : ($a ? '0' : '–'); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>

<?php elseif ($tab === 'issues'): ?>
  <section class="panel">
    <h2>Issues (<?php echo count($issues); ?>)</h2>
    <p class="hint">High = Google can't show the page or it is broken. Medium = hurts ranking. Low = worth fixing when you edit the page. Pages checked: <?php echo $checkedAudit; ?> of <?php echo count($urls); ?>.</p>
    <?php if ($smErr): ?><p class="flash err"><?php echo $h($smErr); ?></p><?php endif; ?>
    <?php if ($issues): ?><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Level</th><th>Page</th><th>Problem</th></tr></thead><tbody>
      <?php foreach ($issues as [$lvl, $u, $msg]): ?><tr><td><span class="lvl <?php echo $h($lvl); ?>"><?php echo $h($lvl); ?></span></td><td><?php echo preg_match('#^https?://#', $u) ? '<a href="' . $h($u) . '" target="_blank" rel="noopener">' . $h($label($u)) . '</a>' : $h($u); ?></td><td><?php echo $h($msg); ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php else: ?><p class="empty">No issues found<?php echo $checkedAudit < count($urls) ? ' so far' : ''; ?>.</p><?php endif; ?>
    <form method="post" class="inline"><input type="hidden" name="action" value="recheck"><input type="hidden" name="tab" value="issues"><button class="btn">Check every page again</button></form>
  </section>
  <?php if ($sitemaps): ?><section class="panel"><h2>Sitemaps</h2><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Sitemap</th><th>Last read by Google</th><th class="num">Errors</th><th class="num">Warnings</th></tr></thead><tbody>
    <?php foreach ($sitemaps as $sm): ?><tr><td><?php echo $h($sm['path']); ?></td><td><?php echo !empty($sm['lastDownloaded']) ? $h(date('j M Y', strtotime($sm['lastDownloaded']))) : '–'; ?></td><td class="num"><?php echo (int)($sm['errors'] ?? 0); ?></td><td class="num"><?php echo (int)($sm['warnings'] ?? 0); ?></td></tr><?php endforeach; ?>
  </tbody></table></div></section><?php endif; ?>

<?php else: ?>
  <section class="panel">
    <h2>Connect Google Search Console</h2>
    <?php if ($sa): ?><p class="hint"><span class="pill ok">Google key found</span> &nbsp;Service account: <span class="mono"><?php echo $h($sa['client_email']); ?></span></p><?php endif; ?>
    <ol class="steps">
      <li><?php if ($sa): ?>The Google key is already uploaded (Website analytics uses the same one).<?php else: ?>Upload a Google service-account JSON key in <a href="site_analytics.php?tab=settings">Website analytics › Settings</a> (the same key powers Google Analytics there).<?php endif; ?></li>
      <li>In Google Cloud, open the same project and turn on <b>Google Search Console API</b> (APIs &amp; Services › Library).</li>
      <li>Open <a href="https://search.google.com/search-console/users" target="_blank" rel="noopener">Search Console › Settings › Users and permissions</a> for restrogrow.com, click <b>Add user</b>, paste <span class="mono"><?php echo $h($sa['client_email'] ?? 'the service account email'); ?></span> and choose <b>Restricted</b>.</li>
      <li>Come back here and press Refresh. Keywords show at once; index status fills in over a few minutes.</li>
    </ol>
  </section>
  <section class="panel">
    <h2>Search Console property</h2>
    <form method="post" class="field" style="display:grid;gap:8px">
      <input type="hidden" name="action" value="site"><input type="hidden" name="tab" value="setup">
      <label for="gsc_site"><b>Property</b></label>
      <input type="text" id="gsc_site" name="gsc_site" value="<?php echo $h(seoSite($conn)); ?>">
      <small class="muted">sc-domain:restrogrow.com for a domain property (the usual), or https://restrogrow.com/ for a URL-prefix one.</small>
      <div><button class="btn primary">Save</button></div>
    </form>
  </section>
<?php endif; ?>
</main>
<script>
// fill in index status and page checks a few pages at a time, then reload once
(function () {
  var prog = document.getElementById('prog'), changed = 0, rounds = 0;
  function step() {
    fetch('seo.php?step=1', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      changed += (d.audited || 0) + (d.inspected || 0);
      if (d.error) { prog.textContent = d.error; return; }
      if (d.remaining > 0 && (d.audited || d.inspected) && ++rounds < 40) { prog.textContent = 'Checking pages… ' + d.remaining + ' left'; step(); }
      else if (changed) { prog.textContent = 'Checks done. Updating…'; location.reload(); }
      else prog.textContent = '';
    }).catch(function () { prog.textContent = ''; });
  }
  step();
})();
</script>
</body>
</html>
