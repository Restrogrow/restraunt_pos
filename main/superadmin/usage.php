<?php
/**
 * Super Admin › Usage: what every restaurant really uses.
 *   usage.php              all restaurants: plan, last login, orders, adoption score, never-touched count
 *   usage.php?rid=RES…     one restaurant: every feature (using / used / opened / never / off) with counts,
 *                          logins (owner / staff, web / app), pages opened, and what to talk to them about
 *   usage.php?tab=funnel   sign-up funnel: opened → code → verified → details → account → menu → first order
 * Data: config/usage.php (their own data + dashboard page opens), config/onboarding.php (funnel, guide).
 */
require_once __DIR__ . '/auth.php';
require_superadmin();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/usage.php';
require_once __DIR__ . '/../config/onboarding.php';
require_once __DIR__ . '/../config/access_control.php';

$conn = getConnection();
usEnsure($conn);
obEnsure($conn);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ago = function ($dt) {
    if (!$dt) return 'never';
    $s = time() - strtotime((string)$dt);
    if ($s < 0) return 'upcoming';
    if ($s < 3600) return max(1, (int)floor($s / 60)) . ' min ago';
    if ($s < 86400) return (int)floor($s / 3600) . ' h ago';
    $d = (int)floor($s / 86400);
    return $d === 1 ? 'yesterday' : ($d < 60 ? $d . ' days ago' : date('j M Y', strtotime((string)$dt)));
};
$num = fn($n) => number_format((int)$n);

// plan state for a users row
$plan = function (array $u) {
    $st = (string)($u['subscription_status'] ?? '');
    $end = $u['renewal_date'] ?: ($u['trial_end_date'] ?? null);
    $left = $end ? (int)floor((strtotime($end . ' 23:59:59') - time()) / 86400) : null;
    if ($st === 'disabled' || !(int)($u['is_active'] ?? 1)) return ['Disabled', 'off', $left];
    if ($left !== null && $left < 0) return ['Expired', 'bad', $left];
    if ($st === 'trial') return [$left !== null ? 'Trial · ' . $left . ' d left' : 'Trial', 'trial', $left];
    return ['Paying' . ($left !== null ? ' · ' . $left . ' d left' : ''), 'ok', $left];
};

// logins per restaurant: last, owner / staff in 30 days, app vs web
$logins = [];
try {
    foreach ($conn->query("SELECT restaurant_id, MAX(created_at) last,
            SUM(user_type = 'admin' AND created_at >= NOW() - INTERVAL 30 DAY) owner30,
            SUM(user_type = 'staff' AND created_at >= NOW() - INTERVAL 30 DAY) staff30,
            SUM((user_agent LIKE '%okhttp%' OR user_agent LIKE '%Expo%' OR user_agent LIKE '%ReactNative%' OR user_agent LIKE '%Dalvik%') AND created_at >= NOW() - INTERVAL 30 DAY) app30
        FROM login_logs WHERE outcome = 'success' GROUP BY restaurant_id")->fetchAll(PDO::FETCH_ASSOC) as $r) $logins[(string)$r['restaurant_id']] = $r;
} catch (Throwable $e) {}

$tab = ($_GET['tab'] ?? '') === 'funnel' ? 'funnel' : 'restaurants';
$rid = isset($_GET['rid']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string)$_GET['rid']) : '';
$STATE = ['using' => ['Using', 'Data in the last 30 days'], 'used' => ['Used before', 'Has data, but nothing in 30 days'], 'opened' => ['Only looked', 'Opened the page, never used it'],
          'never' => ['Never touched', 'Not opened, no data'], 'off' => ['Switched off', 'Off in Restaurants › Access']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Usage · Super Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?php require_once __DIR__ . '/../config/icon_font.php'; echo $h(rgIconFontUrl()); ?>">
<style>
  :root { --ink: #0f172a; --soft: #475569; --muted: #94a3b8; --line: #e2e8f0; --bg: #f5f7fb; --brand: #ea580c;
          --using: #16a34a; --using-bg: #dcfce7; --used: #2563eb; --used-bg: #dbeafe; --opened: #b45309; --opened-bg: #fef3c7; --never: #94a3b8; --never-bg: #f1f5f9; --off: #64748b; --off-bg: #e2e8f0; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Inter, system-ui, -apple-system, 'Segoe UI', sans-serif; background: var(--bg); color: var(--ink); }
  main { max-width: 1240px; margin: 0 auto; padding: 22px 16px 80px; }
  a { color: inherit; }
  .back { color: var(--soft); text-decoration: none; font-size: 14px; font-weight: 600; }
  .back:hover { color: var(--brand); }
  h1 { margin: 12px 0 4px; font-size: 28px; letter-spacing: -.01em; }
  .sub { margin: 0 0 18px; color: var(--soft); font-size: 14.5px; }
  .tabs { display: flex; gap: 6px; margin-bottom: 18px; flex-wrap: wrap; }
  .tabs a { text-decoration: none; padding: 9px 16px; border-radius: 999px; background: #fff; border: 1px solid var(--line); font-weight: 700; font-size: 14px; color: var(--soft); }
  .tabs a.on { background: var(--ink); color: #fff; border-color: var(--ink); }
  .ms { font-family: 'Material Symbols Rounded'; font-weight: normal; font-style: normal; font-size: 20px; line-height: 1; display: inline-block; -webkit-font-feature-settings: 'liga'; font-feature-settings: 'liga'; vertical-align: middle; }
  .tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; margin-bottom: 18px; }
  .tile { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 14px 16px; }
  .tile b { display: block; font-size: 26px; font-weight: 800; letter-spacing: -.01em; }
  .tile span { color: var(--soft); font-size: 13px; }
  .card { background: #fff; border: 1px solid var(--line); border-radius: 18px; overflow: hidden; }
  .bar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; padding: 14px; border-bottom: 1px solid var(--line); }
  .bar input { flex: 1; min-width: 200px; height: 40px; border: 1.5px solid var(--line); border-radius: 11px; padding: 0 12px; font: inherit; font-size: 14px; }
  .chip { border: 1.5px solid var(--line); background: #fff; border-radius: 999px; padding: 7px 12px; font: inherit; font-size: 13px; font-weight: 700; color: var(--soft); cursor: pointer; }
  .chip.on { border-color: var(--ink); background: var(--ink); color: #fff; }
  .tw { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); font-weight: 700; padding: 10px 14px; background: #f8fafc; white-space: nowrap; }
  td { padding: 12px 14px; border-top: 1px solid #f1f5f9; vertical-align: middle; }
  tr.r { cursor: pointer; }
  tr.r:hover td { background: #fff7ed; }
  .nm b { display: block; }
  .nm small { color: var(--muted); font-size: 12px; }
  .pill { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 12px; font-weight: 700; white-space: nowrap; }
  .pill.ok { background: var(--using-bg); color: #15803d; } .pill.trial { background: #ffedd5; color: #9a3412; } .pill.bad { background: #fee2e2; color: #b91c1c; } .pill.off { background: var(--off-bg); color: var(--off); }
  .score { display: flex; align-items: center; gap: 8px; min-width: 140px; }
  .score i { flex: 1; height: 8px; border-radius: 999px; background: #f1f5f9; overflow: hidden; display: block; }
  .score i u { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #f97316, #16a34a); text-decoration: none; }
  .score b { font-size: 13px; width: 38px; text-align: right; }
  .muted { color: var(--muted); }
  .nv { font-weight: 700; color: var(--opened); }
  /* detail */
  .dh { display: flex; gap: 16px; align-items: flex-start; flex-wrap: wrap; margin-bottom: 16px; }
  .dh h1 { margin: 6px 0 4px; }
  .facts { display: flex; gap: 8px; flex-wrap: wrap; }
  .facts span { background: #fff; border: 1px solid var(--line); border-radius: 999px; padding: 5px 10px; font-size: 12.5px; color: var(--soft); font-weight: 600; }
  .legend { display: flex; gap: 12px; flex-wrap: wrap; margin: 6px 0 16px; font-size: 12.5px; color: var(--soft); }
  .legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 5px; vertical-align: -1px; }
  .groups { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 370px), 1fr)); gap: 14px; }
  .grp h3 { margin: 0; padding: 12px 14px; font-size: 14px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; }
  .grp h3 small { color: var(--muted); font-weight: 600; }
  .ft { display: flex; gap: 10px; align-items: center; padding: 10px 14px; border-top: 1px solid #f1f5f9; }
  .ft:first-of-type { border-top: 0; }
  .ft .ic { width: 34px; height: 34px; flex: none; border-radius: 10px; display: grid; place-items: center; }
  .ft .ic .ms { font-size: 19px; }
  .ft .m { flex: 1; min-width: 0; }
  .ft .m b { display: block; font-size: 13.5px; }
  .ft .m small { display: block; color: var(--muted); font-size: 12px; margin-top: 1px; }
  .ft .st { font-size: 11.5px; font-weight: 800; padding: 3px 8px; border-radius: 999px; white-space: nowrap; }
  .s-using .ic, .s-using .st { background: var(--using-bg); color: var(--using); }
  .s-used .ic, .s-used .st { background: var(--used-bg); color: var(--used); }
  .s-opened .ic, .s-opened .st { background: var(--opened-bg); color: var(--opened); }
  .s-never .ic, .s-never .st { background: var(--never-bg); color: var(--never); }
  .s-off .ic, .s-off .st { background: var(--off-bg); color: var(--off); }
  .s-never .m b, .s-off .m b { color: var(--soft); }
  .two { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 370px), 1fr)); gap: 14px; margin-top: 14px; }
  .list { padding: 6px 0; }
  .list div { display: flex; justify-content: space-between; gap: 10px; padding: 8px 14px; font-size: 13.5px; border-top: 1px solid #f1f5f9; }
  .list div:first-child { border-top: 0; }
  .talk li { margin: 6px 0; font-size: 14px; line-height: 1.5; }
  /* funnel */
  .fn { padding: 16px; display: grid; gap: 10px; }
  .fr { display: grid; grid-template-columns: 210px 1fr 120px; gap: 12px; align-items: center; }
  .fr .lbl b { display: block; font-size: 14px; } .fr .lbl small { color: var(--muted); font-size: 12px; }
  .fr .bx { height: 30px; border-radius: 9px; background: #f1f5f9; overflow: hidden; }
  .fr .bx u { display: flex; align-items: center; height: 100%; padding: 0 10px; color: #fff; font-weight: 800; font-size: 13px; text-decoration: none; border-radius: 9px; background: linear-gradient(90deg, #fb923c, #ea580c); min-width: 34px; }
  .fr .bx u.after { background: linear-gradient(90deg, #4ade80, #16a34a); }
  .fr .pc { font-size: 13px; color: var(--soft); text-align: right; }
  .fr .pc em { display: block; font-style: normal; color: #b91c1c; font-size: 12px; font-weight: 700; }
  .sel { display: flex; gap: 6px; }
  @media (max-width: 700px) { .fr { grid-template-columns: 1fr; gap: 4px; } .fr .pc { text-align: left; } }
</style>
</head>
<body>
<main>
<?php if ($rid !== ''):
    // ───────── one restaurant ─────────
    $u = $conn->prepare('SELECT * FROM users WHERE restaurant_id = ? LIMIT 1');
    $u->execute([$rid]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if (!$user) { echo '<a class="back" href="usage.php">← Usage</a><h1>Restaurant not found</h1></main></body></html>'; exit; }
    $data = usData($conn, $rid)[$rid] ?? [];
    $opens = usOpens($conn, $rid)[$rid] ?? [];
    $status = usStatus($data, $opens, accessOffPages($conn, $rid));
    $score = usScore($status);
    [$planTxt, $planCls] = $plan($user);
    $lg = $logins[$rid] ?? ['last' => null, 'owner30' => 0, 'staff30' => 0, 'app30' => 0];
    $guide = obGuide($conn, $rid);
    $cnt = array_count_values(array_column($status, 'state'));
    $orders30 = ($data['pos'][1] ?? 0) + ($data['website_ord'][1] ?? 0);
    $recent = $conn->prepare("SELECT created_at, user_type, actor_name, user_agent, ip_address FROM login_logs WHERE restaurant_id = ? AND outcome = 'success' ORDER BY id DESC LIMIT 10");
    try { $recent->execute([$rid]); $recentLogins = $recent->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $recentLogins = []; }
    arsort($opens);
    $topPages = array_slice(array_map(fn($p, $o) => [$p, $o], array_keys($opens), $opens), 0, 12);
    usort($topPages, fn($a, $b) => $b[1][0] <=> $a[1][0]);
    $talk = array_filter($status, fn($s) => in_array($s['state'], ['never', 'opened'], true));
?>
  <a class="back" href="usage.php">← All restaurants</a>
  <div class="dh">
    <div style="flex:1;min-width:260px">
      <h1><?php echo $h($user['restaurant_name']); ?></h1>
      <div class="facts">
        <span><?php echo $h($rid); ?></span>
        <span class="pill <?php echo $planCls; ?>"><?php echo $h($planTxt); ?></span>
        <span>Signed up <?php echo $h($ago($user['created_at'])); ?><?php echo !empty($user['signup_source']) ? ' · ' . $h($user['signup_source']) : ''; ?></span>
        <?php if (!empty($user['business_type'])): ?><span><?php echo $h(OB_TYPES[$user['business_type']] ?? $user['business_type']); ?></span><?php endif; ?>
        <?php if (!empty($user['city'])): ?><span><?php echo $h($user['city']); ?></span><?php endif; ?>
        <?php if (!empty($user['phone'])): ?><span><?php echo $h($user['phone']); ?></span><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="tiles">
    <div class="tile"><b><?php echo $score; ?>%</b><span>of available features used</span></div>
    <div class="tile"><b><?php echo $num($orders30); ?></b><span>orders in 30 days (POS + website)</span></div>
    <div class="tile"><b><?php echo $h($ago($lg['last'])); ?></b><span>last login</span></div>
    <div class="tile"><b><?php echo $num($lg['owner30']); ?> / <?php echo $num($lg['staff30']); ?></b><span>owner / staff logins, 30 days (<?php echo $num($lg['app30']); ?> from the apps)</span></div>
    <div class="tile"><b><?php echo $guide['done']; ?>/<?php echo $guide['total']; ?></b><span>setup guide steps done</span></div>
    <div class="tile"><b><?php echo (int)($cnt['never'] ?? 0); ?></b><span>features never touched</span></div>
  </div>

  <div class="legend"><?php foreach ($STATE as $k => $s): ?><span title="<?php echo $h($s[1]); ?>"><i style="background:var(--<?php echo $k; ?>)"></i><?php echo $h($s[0]); ?> <b><?php echo (int)($cnt[$k] ?? 0); ?></b></span><?php endforeach; ?></div>

  <div class="groups">
  <?php $groups = []; foreach ($status as $s) $groups[$s['group']][] = $s;
  foreach ($groups as $g => $rows): $u2 = count(array_filter($rows, fn($s) => in_array($s['state'], ['using', 'used'], true))); ?>
    <section class="card grp"><h3><?php echo $h($g); ?> <small><?php echo $u2; ?> of <?php echo count($rows); ?> used</small></h3>
    <?php foreach ($rows as $s):
        $parts = [];
        if ($s['n']) $parts[] = $num($s['n']) . ' ' . $s['unit'] . ($s['n30'] ? ' (' . $num($s['n30']) . ' in 30 d)' : '');
        if ($s['last']) $parts[] = 'last ' . $ago($s['last']);
        if ($s['opens']) $parts[] = 'opened ' . $num($s['opens']) . '×' . ($s['staff_opens'] ? ' (' . $num($s['staff_opens']) . ' by staff)' : '') . ($s['opened_last'] && !$s['last'] ? ', last ' . $ago($s['opened_last']) : '');
    ?>
      <div class="ft s-<?php echo $s['state']; ?>"><span class="ic"><span class="ms"><?php echo $h($s['icon']); ?></span></span>
        <span class="m"><b><?php echo $h($s['label']); ?></b><small><?php echo $parts ? $h(implode(' · ', $parts)) : $h($STATE[$s['state']][1]); ?></small></span>
        <span class="st"><?php echo $h($STATE[$s['state']][0]); ?></span></div>
    <?php endforeach; ?>
    </section>
  <?php endforeach; ?>
  </div>

  <div class="two">
    <section class="card"><div class="grp"><h3>Talk to them about <small>on, but not used</small></h3></div>
      <?php if ($talk): ?><ul class="talk" style="padding:4px 18px 12px 34px;margin:0">
        <?php foreach (array_slice($talk, 0, 10) as $s): ?><li><b><?php echo $h($s['label']); ?></b> · <?php echo $s['state'] === 'opened' ? 'they looked at it ' . $num($s['opens']) . '× but never used it: show them how' : 'never opened'; ?></li><?php endforeach; ?>
      </ul><?php else: ?><p style="padding:0 16px 8px" class="muted">They use everything that's switched on. 🎉</p><?php endif; ?>
    </section>
    <section class="card"><div class="grp"><h3>Pages they open most <small>dashboard, all time</small></h3></div>
      <div class="list"><?php if (!$topPages): ?><div><span class="muted">No page opens recorded yet (counting started 18 Oct 2026).</span></div><?php endif; ?>
      <?php foreach ($topPages as [$pg, $o]): ?><div><span><?php echo $h(preg_replace('/Page$/', '', $pg)); ?></span><span class="muted"><?php echo $num($o[0]); ?>× · <?php echo $h($ago($o[2])); ?></span></div><?php endforeach; ?></div>
    </section>
    <section class="card"><div class="grp"><h3>Last logins <small>owner and staff</small></h3></div>
      <div class="list"><?php if (!$recentLogins): ?><div><span class="muted">No logins recorded.</span></div><?php endif; ?>
      <?php foreach ($recentLogins as $l): $app = preg_match('/okhttp|Expo|ReactNative|Dalvik/i', (string)$l['user_agent']); ?>
        <div><span><?php echo $h($l['actor_name'] ?: $l['user_type']); ?> <span class="muted">· <?php echo $l['user_type'] === 'admin' ? 'owner' : $h($l['user_type']); ?> · <?php echo $app ? 'app' : 'web'; ?></span></span><span class="muted"><?php echo $h($ago($l['created_at'])); ?></span></div>
      <?php endforeach; ?></div>
    </section>
  </div>

<?php elseif ($tab === 'funnel'):
    // ───────── sign-up funnel ─────────
    $days = (int)($_GET['days'] ?? 30);
    if (!in_array($days, [7, 30, 90], true)) $days = 30;
    $c = function (string $step) use ($conn, $days) {
        $st = $conn->prepare("SELECT COUNT(DISTINCT token) FROM signup_funnel WHERE step = ? AND created_at >= NOW() - INTERVAL $days DAY");
        $st->execute([$step]);
        return (int)$st->fetchColumn();
    };
    $steps = [['opened', 'Opened sign-up', 'saw the sign-up form'], ['code_sent', 'Asked for the code', 'entered a phone number'], ['verified', 'Verified the phone', 'WhatsApp code or Google'],
              ['details', 'Named the restaurant', 'step 2 done'], ['created', 'Account made', 'step 3 done']];
    $vals = [];
    foreach ($steps as $s) $vals[$s[0]] = $c($s[0]);
    // after sign-up: restaurants made in the period that added a menu / took an order
    $new = $conn->query("SELECT restaurant_id, restaurant_name, created_at, signup_source, business_type, city FROM users WHERE created_at >= NOW() - INTERVAL $days DAY ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $withMenu = 0; $withOrder = 0; $rowsNew = [];
    foreach ($new as $n) {
        $g = obGuide($conn, (string)$n['restaurant_id']);
        $hasMenu = (bool)array_values(array_filter($g['steps'], fn($s) => $s['key'] === 'menu' && $s['done']));
        $hasOrder = (bool)array_values(array_filter($g['steps'], fn($s) => $s['key'] === 'order' && $s['done']));
        $withMenu += $hasMenu ? 1 : 0; $withOrder += $hasOrder ? 1 : 0;
        $rowsNew[] = $n + ['g' => $g, 'menu' => $hasMenu, 'order' => $hasOrder];
    }
    $top = max(1, $vals['opened'], $vals['created'], count($new));
    $leads = (int)$conn->query("SELECT COUNT(*) FROM signup_funnel WHERE step = 'lead' AND created_at >= NOW() - INTERVAL $days DAY")->fetchColumn();
?>
  <a class="back" href="dashboard.php">← Super Admin</a>
  <h1>Usage</h1>
  <p class="sub">Where new restaurants come from, where they drop off, and whether they start using RestroGrow.</p>
  <div class="tabs"><a href="usage.php">Restaurants</a><a class="on" href="usage.php?tab=funnel">Sign-up funnel</a></div>
  <div class="sel" style="margin-bottom:12px"><?php foreach ([7, 30, 90] as $d): ?><a class="chip<?php echo $d === $days ? ' on' : ''; ?>" style="text-decoration:none" href="usage.php?tab=funnel&days=<?php echo $d; ?>">Last <?php echo $d; ?> days</a><?php endforeach; ?></div>
  <section class="card"><div class="fn">
    <?php $prev = null; foreach ($steps as [$k, $lbl, $sub]): $v = $vals[$k]; $drop = $prev !== null && $prev > 0 ? round((1 - $v / $prev) * 100) : null; ?>
      <div class="fr"><div class="lbl"><b><?php echo $h($lbl); ?></b><small><?php echo $h($sub); ?></small></div>
        <div class="bx"><u style="width:<?php echo max(4, round($v / $top * 100)); ?>%"><?php echo $num($v); ?></u></div>
        <div class="pc"><?php echo $vals['opened'] ? round($v / $vals['opened'] * 100) . '% of visitors' : '–'; ?><?php if ($drop !== null && $drop > 0): ?><em>−<?php echo $drop; ?>% here</em><?php endif; ?></div></div>
    <?php $prev = $v; endforeach; ?>
    <div class="fr"><div class="lbl"><b>Added a menu</b><small>3+ dishes</small></div><div class="bx"><u class="after" style="width:<?php echo max(4, round($withMenu / $top * 100)); ?>%"><?php echo $withMenu; ?></u></div><div class="pc"><?php echo count($new) ? round($withMenu / count($new) * 100) . '% of new restaurants' : '–'; ?></div></div>
    <div class="fr"><div class="lbl"><b>Took a first order</b><small>live</small></div><div class="bx"><u class="after" style="width:<?php echo max(4, round($withOrder / $top * 100)); ?>%"><?php echo $withOrder; ?></u></div><div class="pc"><?php echo count($new) ? round($withOrder / count($new) * 100) . '% of new restaurants' : '–'; ?></div></div>
  </div></section>
  <p class="muted" style="font-size:13px;margin:10px 2px 18px">Counting started 18 Oct 2026. <?php echo $leads; ?> unfinished sign-up<?php echo $leads === 1 ? '' : 's'; ?> in this period became leads (Super Admin › Leads), so you can call them.</p>

  <section class="card"><div class="grp"><h3>New restaurants <small><?php echo count($new); ?> in <?php echo $days; ?> days</small></h3></div>
    <div class="tw"><table><thead><tr><th>Restaurant</th><th>Signed up</th><th>How</th><th>Type · city</th><th>Setup guide</th><th>Menu</th><th>First order</th></tr></thead><tbody>
    <?php if (!$rowsNew): ?><tr><td colspan="7" class="muted">No sign-ups in this period.</td></tr><?php endif; ?>
    <?php foreach ($rowsNew as $n): ?>
      <tr class="r" onclick="location.href='usage.php?rid=<?php echo $h($n['restaurant_id']); ?>'"><td class="nm"><b><?php echo $h($n['restaurant_name']); ?></b><small><?php echo $h($n['restaurant_id']); ?></small></td>
        <td><?php echo $h($ago($n['created_at'])); ?></td><td><?php echo $h($n['signup_source'] ?: 'Super Admin / old form'); ?></td>
        <td><?php echo $h(trim((OB_TYPES[$n['business_type'] ?? ''] ?? '') . ' · ' . ($n['city'] ?? ''), ' ·')) ?: '<span class="muted">–</span>'; ?></td>
        <td><?php echo $n['g']['done']; ?>/<?php echo $n['g']['total']; ?></td><td><?php echo $n['menu'] ? '✓' : '<span class="muted">–</span>'; ?></td><td><?php echo $n['order'] ? '✓' : '<span class="muted">–</span>'; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  </section>

<?php else:
    // ───────── all restaurants ─────────
    $users = $conn->query('SELECT restaurant_id, restaurant_name, username, phone, subscription_status, trial_end_date, renewal_date, is_active, created_at FROM users ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    $data = usData($conn);
    $opens = usOpens($conn);
    $offAll = [];
    try { foreach ($conn->query('SELECT restaurant_id, off_pages FROM restaurant_access')->fetchAll(PDO::FETCH_ASSOC) as $r) $offAll[$r['restaurant_id']] = json_decode((string)$r['off_pages'], true) ?: []; } catch (Throwable $e) {}
    $rows = [];
    foreach ($users as $u) {
        $id = (string)$u['restaurant_id'];
        $st = usStatus($data[$id] ?? [], $opens[$id] ?? [], $offAll[$id] ?? []);
        $cnt = array_count_values(array_column($st, 'state'));
        $lastOrder = max((string)($data[$id]['pos'][2] ?? ''), (string)($data[$id]['website_ord'][2] ?? ''));
        $rows[] = ['u' => $u, 'score' => usScore($st), 'never' => (int)($cnt['never'] ?? 0), 'using' => (int)($cnt['using'] ?? 0),
                   'orders30' => ($data[$id]['pos'][1] ?? 0) + ($data[$id]['website_ord'][1] ?? 0), 'last_login' => $logins[$id]['last'] ?? null, 'last_order' => $lastOrder ?: null,
                   'plan' => $plan($u)];
    }
    $active7 = count(array_filter($rows, fn($r) => $r['last_login'] && strtotime($r['last_login']) > time() - 7 * 86400));
    $avg = $rows ? round(array_sum(array_column($rows, 'score')) / count($rows)) : 0;
    $noOrders = count(array_filter($rows, fn($r) => !$r['orders30']));
?>
  <a class="back" href="dashboard.php">← Super Admin</a>
  <h1>Usage</h1>
  <p class="sub">What every restaurant really uses, what they only looked at, and what they never touched. Click a restaurant for the full picture.</p>
  <div class="tabs"><a class="on" href="usage.php">Restaurants</a><a href="usage.php?tab=funnel">Sign-up funnel</a></div>
  <div class="tiles">
    <div class="tile"><b><?php echo count($rows); ?></b><span>restaurants</span></div>
    <div class="tile"><b><?php echo $active7; ?></b><span>logged in during the last 7 days</span></div>
    <div class="tile"><b><?php echo $avg; ?>%</b><span>average share of features used</span></div>
    <div class="tile"><b><?php echo $noOrders; ?></b><span>with no orders in 30 days</span></div>
  </div>
  <section class="card">
    <div class="bar">
      <input type="search" id="q" placeholder="Search restaurant, ID or phone" aria-label="Search">
      <?php foreach (['all' => 'All', 'trial' => 'On trial', 'ok' => 'Paying', 'low' => 'Low use (under 30%)', 'quiet' => 'No login in 7 days', 'noorders' => 'No orders in 30 days'] as $k => $l): ?>
        <button type="button" class="chip<?php echo $k === 'all' ? ' on' : ''; ?>" data-f="<?php echo $k; ?>"><?php echo $h($l); ?></button>
      <?php endforeach; ?>
    </div>
    <div class="tw"><table><thead><tr><th>Restaurant</th><th>Plan</th><th>Last login</th><th>Last order</th><th>Orders · 30 d</th><th>Features used</th><th>Never touched</th></tr></thead><tbody id="rows">
    <?php foreach ($rows as $r): $u = $r['u']; [$pt, $pc] = $r['plan'];
        $quiet = !$r['last_login'] || strtotime($r['last_login']) < time() - 7 * 86400; ?>
      <tr class="r" data-q="<?php echo $h(strtolower($u['restaurant_name'] . ' ' . $u['restaurant_id'] . ' ' . $u['phone'] . ' ' . $u['username'])); ?>"
          data-plan="<?php echo $pc; ?>" data-score="<?php echo $r['score']; ?>" data-quiet="<?php echo $quiet ? 1 : 0; ?>" data-orders="<?php echo (int)$r['orders30']; ?>"
          onclick="location.href='usage.php?rid=<?php echo $h($u['restaurant_id']); ?>'">
        <td class="nm"><b><?php echo $h($u['restaurant_name']); ?></b><small><?php echo $h($u['restaurant_id']); ?> · since <?php echo $h(date('j M Y', strtotime($u['created_at']))); ?></small></td>
        <td><span class="pill <?php echo $pc; ?>"><?php echo $h($pt); ?></span></td>
        <td class="<?php echo $quiet ? 'nv' : ''; ?>"><?php echo $h($ago($r['last_login'])); ?></td>
        <td><?php echo $h($ago($r['last_order'])); ?></td>
        <td><?php echo $num($r['orders30']); ?></td>
        <td><div class="score"><i><u style="width:<?php echo $r['score']; ?>%"></u></i><b><?php echo $r['score']; ?>%</b></div></td>
        <td><?php echo $r['never']; ?></td>
      </tr>
    <?php endforeach; ?></tbody></table></div>
  </section>
  <script>
  (function () {
    var f = 'all', q = document.getElementById('q');
    function apply() {
      var t = q.value.trim().toLowerCase();
      document.querySelectorAll('#rows tr').forEach(function (r) {
        var d = r.dataset, ok = (!t || d.q.indexOf(t) > -1) &&
          (f === 'all' || (f === 'trial' && d.plan === 'trial') || (f === 'ok' && d.plan === 'ok') || (f === 'low' && +d.score < 30) || (f === 'quiet' && d.quiet === '1') || (f === 'noorders' && d.orders === '0'));
        r.style.display = ok ? '' : 'none';
      });
    }
    q.addEventListener('input', apply);
    document.querySelectorAll('[data-f]').forEach(function (b) { b.addEventListener('click', function () { f = b.dataset.f; document.querySelectorAll('[data-f]').forEach(function (x) { x.classList.toggle('on', x === b); }); apply(); }); });
  })();
  </script>
<?php endif; ?>
</main>
</body>
</html>
