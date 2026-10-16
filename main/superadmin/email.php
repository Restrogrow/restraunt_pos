<?php
/**
 * Email (Super Admin › Tools): every automatic email RestroGrow sends (config/mail_engine.php).
 *  - All emails: each email as it looks (made from sample data), who gets it, when, what starts it,
 *    and "send me a test" for any of them.
 *  - Sent log: everything sent / failed / waiting, with preview and "send again".
 *  - Settings & jobs: the SMTP account, a test email, each email type on/off, and whether the
 *    scheduled jobs (cron) are running.
 */
require_once __DIR__ . '/auth.php';
require_superadmin();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/mail_catalog.php';

$conn = getConnection();
mailEnsure($conn);
$conn->exec("CREATE TABLE IF NOT EXISTS sa_settings (k VARCHAR(60) PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// show one email (a sent one by id, or a sample by key) inside a sandboxed frame
if (isset($_GET['view']) || isset($_GET['preview'])) {
    $html = '';
    if (isset($_GET['view'])) {
        $st = $conn->prepare('SELECT html FROM email_outbox WHERE id = ?');
        $st->execute([(int)$_GET['view']]);
        $html = (string)$st->fetchColumn();
    } else {
        foreach (mailPreviewAll($conn) as $m) if ($m['key'] === $_GET['preview']) { $html = $m['html']; break; }
    }
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; img-src * data:; style-src 'unsafe-inline'");
    echo $html;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '')) { http_response_code(403); echo '{"ok":false}'; exit; }
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $out = ['ok' => true];
    try {
        $to = trim((string)($in['to'] ?? ''));
        switch ($in['action'] ?? '') {
            case 'types':
                $off = array_values(array_intersect(array_keys(mailTypes()), (array)($in['off'] ?? [])));
                $conn->prepare("REPLACE INTO sa_settings (k, v) VALUES ('email_off', ?)")->execute([json_encode($off)]);
                $out['message'] = 'Saved';
                break;
            case 'test':
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new Exception('Enter a valid email address');
                $b = mailPlatformBrand();
                $r = mailSendNow($conn, 'test', $to, 'Test email from RestroGrow ✅', mailLayout($b, ['title' => 'Your emails work ✅',
                    'preheader' => 'Sent from Super Admin › Email.', 'intro' => '<p style="margin:0">This is a test from Super Admin › Email. If it reached your inbox (not spam), restaurant owners and customers will get theirs too.</p>',
                    'rows' => [['Sent at', date('d M Y, h:i A')], ['Sender', rgMailFromName() . ' <' . SMTP_FROM_EMAIL . '>']], 'button' => ['Open RestroGrow', mailAppUrl()]]), ['brand' => $b]);
                if (!$r['success']) throw new Exception('Not sent: ' . ($r['error'] ?: 'check the SMTP settings in .env'));
                $out['message'] = 'Sent to ' . $to . '. Check the inbox (and spam).';
                break;
            case 'testOne':
                // one sample email from "All emails", to your own address
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new Exception('Type your email address at the top first');
                $pick = null;
                foreach (mailPreviewAll($conn) as $m) if ($m['key'] === ($in['key'] ?? '')) { $pick = $m; break; }
                if (!$pick) throw new Exception('Email not found');
                $r = mailSendNow($conn, 'test', $to, '[Test] ' . $pick['subject'], $pick['html'], ['brand' => ['from_name' => $pick['from']]]);
                if (!$r['success']) throw new Exception('Not sent: ' . ($r['error'] ?: 'check the SMTP settings in .env'));
                $out['message'] = 'Sent "' . $pick['name'] . '" to ' . $to;
                break;
            case 'resend':
                $st = $conn->prepare('SELECT * FROM email_outbox WHERE id = ?');
                $st->execute([(int)($in['id'] ?? 0)]);
                $m = $st->fetch(PDO::FETCH_ASSOC);
                if (!$m) throw new Exception('Email not found');
                if ($m['type'] === 'password_reset') throw new Exception('Reset links are not kept, ask the person to request a new one');
                $conn->prepare("UPDATE email_outbox SET status = 'queued', attempts = 0, last_error = NULL, send_after = NOW() WHERE id = ?")->execute([$m['id']]);
                [$sent] = mailDispatch($conn, 5);
                $out['message'] = $sent ? 'Sent again' : 'Queued again (sending failed just now, it will retry)';
                break;
            case 'sweep':
                $done = mailSweep($conn, true);
                [$sent, $failed] = mailDispatch($conn, 50);
                $out['message'] = 'Reminders made: ' . array_sum($done) . ' · sent ' . $sent . ($failed ? ' · ' . $failed . ' failed' : '');
                break;
            default:
                throw new Exception('Unknown action');
        }
    } catch (Throwable $e) {
        $out = ['ok' => false, 'message' => $e->getMessage()];
    }
    echo json_encode($out);
    exit;
}

$types = mailTypes();
$all = mailPreviewAll($conn);
$off = json_decode((string)$conn->query("SELECT v FROM sa_settings WHERE k = 'email_off'")->fetchColumn(), true) ?: [];
$stats = $conn->query("SELECT status, COUNT(*) n FROM email_outbox WHERE created_at > NOW() - INTERVAL 7 DAY GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$sentByType = $conn->query("SELECT type, COUNT(*) n FROM email_outbox WHERE status = 'sent' AND created_at > NOW() - INTERVAL 30 DAY GROUP BY type")->fetchAll(PDO::FETCH_KEY_PAIR);
$q = trim((string)($_GET['q'] ?? ''));
$fs = in_array($_GET['s'] ?? '', ['sent', 'failed', 'queued'], true) ? $_GET['s'] : '';
$sql = 'SELECT o.id, o.type, o.restaurant_id, o.to_email, o.subject, o.status, o.attempts, o.last_error, o.created_at, o.sent_at, u.restaurant_name
    FROM email_outbox o LEFT JOIN users u ON u.restaurant_id = o.restaurant_id WHERE 1';
$p = [];
if ($q !== '') { $sql .= ' AND (o.to_email LIKE ? OR o.subject LIKE ? OR o.restaurant_id LIKE ?)'; $p = ["%$q%", "%$q%", "%$q%"]; }
if ($fs !== '') { $sql .= $fs === 'queued' ? " AND o.status IN ('queued', 'sending')" : ' AND o.status = ?'; if ($fs !== 'queued') $p[] = $fs; }
$st = $conn->prepare($sql . ' ORDER BY o.id DESC LIMIT 150');
$st->execute($p);
$log = $st->fetchAll(PDO::FETCH_ASSOC);
$sink = trim((string)env('MAIL_SINK_DIR', ''));
$setting = function (string $k) use ($conn) { $s = $conn->prepare('SELECT v FROM sa_settings WHERE k = ?'); $s->execute([$k]); return (string)$s->fetchColumn(); };
$lastSweep = $setting('mail_sweep_at');
// the scheduled jobs: when each one last ran from the server's cron
$jobs = [
    ['Emails: reminders and retries', 'cron/email_dispatch.php', 'Every 10 minutes', $setting('cron_email_dispatch_at'), 30,
        'Trial / renewal reminders, payment receipts, gift emails, booking reminders, and retrying failed emails. Without it they still go out, but only while someone has a dashboard open.'],
    ['AI manager morning report', 'cron/owner_brief.php', 'Every 15 minutes', $setting('cron_owner_brief_at'), 45,
        'Sends each owner\'s morning report (WhatsApp, and email if they turned it on) at the time they picked. Without it the report only goes when the owner opens the AI manager.'],
];
$who = ['owner' => 'Restaurant owners', 'team' => 'RestroGrow team & website visitors', 'customer' => 'Customers'];
$whoLong = ['owner' => 'To restaurant owners (from RestroGrow)', 'team' => 'To the RestroGrow team / website visitors', 'customer' => 'To customers (from the restaurant\'s name)'];
$tab = in_array($_GET['tab'] ?? '', ['all', 'log', 'settings'], true) ? $_GET['tab'] : (($q !== '' || $fs !== '') ? 'log' : 'all');
$problems = (!SMTP_ENABLED && $sink === '') || (int)($stats['failed'] ?? 0) > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Email · Super Admin</title>
<style>
  :root { --ink:#111827; --soft:#374151; --muted:#6b7280; --line:#e5e7eb; --bg:#f3f4f6; --ok:#16a34a; --bad:#dc2626; --wait:#b45309; --accent:#c1440e; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: var(--bg); color: var(--ink); }
  main { max-width: 1180px; margin: 0 auto; padding: 24px 16px 60px; }
  .back { color: var(--muted); text-decoration: none; font-size: 14px; }
  h1 { margin: 12px 0 4px; font-size: 28px; }
  .sub { color: var(--muted); margin: 0 0 16px; font-size: 15px; }
  .tabs { display: flex; gap: 6px; margin: 0 0 16px; border-bottom: 1px solid var(--line); overflow-x: auto; }
  .tabs a { padding: 10px 14px; text-decoration: none; color: var(--muted); font-weight: 700; font-size: 14.5px; border-bottom: 3px solid transparent; white-space: nowrap; }
  .tabs a.on { color: var(--ink); border-color: var(--accent); }
  .tabs a b { display: inline-block; min-width: 20px; padding: 1px 6px; margin-left: 4px; border-radius: 999px; background: #e5e7eb; color: var(--soft); font-size: 12px; text-align: center; }
  .tabs a .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--bad); margin-left: 6px; vertical-align: middle; }
  .pane { display: none; } .pane.on { display: block; }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 14px; margin-bottom: 14px; }
  .card { background: #fff; border-radius: 16px; border: 1px solid var(--line); padding: 18px; }
  .card h3 { margin: 0 0 12px; font-size: 16px; }
  .kv { display: grid; grid-template-columns: auto 1fr; gap: 6px 14px; font-size: 14px; }
  .kv span { color: var(--muted); } .kv b { word-break: break-all; }
  .stats { display: flex; gap: 10px; flex-wrap: wrap; }
  .stat { flex: 1; min-width: 90px; padding: 12px; border-radius: 12px; background: #f9fafb; text-align: center; }
  .stat b { display: block; font-size: 24px; } .stat small { color: var(--muted); font-size: 12.5px; }
  .pill { display: inline-block; font-size: 12px; font-weight: 700; padding: 2px 9px; border-radius: 999px; }
  .pill.sent { background: #dcfce7; color: #166534; } .pill.failed { background: #fee2e2; color: #991b1b; } .pill.queued, .pill.sending { background: #fef3c7; color: #92400e; }
  .pill.off { background: #f3f4f6; color: var(--muted); } .pill.var { background: #fff1e8; color: #9a3412; }
  .warn { padding: 12px 14px; border-radius: 12px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; font-size: 14px; line-height: 1.5; margin-bottom: 14px; }
  .row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  input[type=email], input[type=search], select { padding: 10px 12px; border: 1.5px solid var(--line); border-radius: 10px; font: inherit; font-size: 14px; min-width: 0; background: #fff; }
  .btn { padding: 10px 16px; border: 0; border-radius: 10px; background: var(--ink); color: #fff; font: inherit; font-weight: 700; font-size: 14px; cursor: pointer; }
  .btn.light { background: #fff; color: var(--ink); border: 1.5px solid var(--line); }
  .btn.sm { padding: 7px 11px; font-size: 13px; border-radius: 9px; }
  .btn:disabled { opacity: .5; cursor: wait; }
  /* all emails */
  .bar { position: sticky; top: 0; z-index: 2; background: var(--bg); padding: 8px 0 12px; }
  .chips { display: flex; gap: 6px; flex-wrap: wrap; }
  .chip { padding: 7px 12px; border-radius: 999px; border: 1.5px solid var(--line); background: #fff; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; color: var(--soft); }
  .chip.on { background: var(--ink); color: #fff; border-color: var(--ink); }
  .group-h { margin: 22px 0 10px; font-size: 13px; letter-spacing: .07em; text-transform: uppercase; color: var(--muted); }
  .mails { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 14px; }
  .mail { background: #fff; border: 1px solid var(--line); border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; }
  .mail.isoff { opacity: .6; }
  .thumb { position: relative; height: 250px; overflow: hidden; background: #f5efe2; border-bottom: 1px solid var(--line); cursor: zoom-in; }
  .thumb iframe { position: absolute; top: 0; left: 0; width: 200%; height: 200%; border: 0; transform: scale(.5); transform-origin: 0 0; pointer-events: none; }
  .thumb:after { content: ''; position: absolute; inset: auto 0 0 0; height: 40px; background: linear-gradient(transparent, #f5efe2); }
  .mail .info { padding: 14px 16px 16px; display: grid; gap: 7px; font-size: 13.5px; flex: 1; }
  .mail .t { font-weight: 800; font-size: 15px; line-height: 1.3; }
  .mail .l { display: grid; grid-template-columns: 54px 1fr; gap: 8px; line-height: 1.45; }
  .mail .l span { color: var(--muted); font-size: 12.5px; padding-top: 1px; }
  .mail .subj { padding: 8px 10px; border-radius: 10px; background: #f9fafb; font-size: 13px; }
  .mail .subj small { display: block; color: var(--muted); font-size: 11.5px; margin-bottom: 2px; }
  .mail .acts { display: flex; gap: 8px; margin-top: 4px; }
  /* jobs */
  .job { display: grid; grid-template-columns: 1fr auto; gap: 6px 14px; padding: 14px 0; border-bottom: 1px solid #f3f4f6; }
  .job:last-child { border-bottom: 0; }
  .job code { font-size: 12.5px; background: #f3f4f6; padding: 2px 6px; border-radius: 6px; word-break: break-all; }
  .job p { margin: 4px 0 0; color: var(--soft); font-size: 13.5px; line-height: 1.5; grid-column: 1 / -1; }
  .types h4 { margin: 14px 0 6px; font-size: 12.5px; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
  .types .cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 0 28px; }
  .types label { display: flex; gap: 10px; align-items: center; padding: 7px 0; font-size: 14px; border-bottom: 1px solid #f3f4f6; cursor: pointer; }
  .types input { width: 18px; height: 18px; accent-color: var(--ok); flex: none; }
  table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
  th { text-align: left; color: var(--muted); font-weight: 600; font-size: 12px; padding: 8px 6px; border-bottom: 1px solid var(--line); }
  td { padding: 9px 6px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
  td small { color: var(--muted); }
  .err { color: var(--bad); font-size: 12px; margin-top: 3px; }
  .lacts { display: flex; gap: 6px; }
  .lacts button { padding: 5px 9px; font-size: 12.5px; border-radius: 8px; border: 1.5px solid var(--line); background: #fff; cursor: pointer; font-weight: 600; }
  .scroll { overflow-x: auto; }
  dialog { width: min(680px, 96vw); height: min(88vh, 940px); padding: 0; border: 0; border-radius: 16px; overflow: hidden; }
  dialog::backdrop { background: rgba(17,24,39,.55); }
  dialog .top { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; border-bottom: 1px solid var(--line); font-weight: 700; font-size: 14px; gap: 10px; }
  dialog .top span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  dialog iframe { width: 100%; height: calc(100% - 50px); border: 0; background: #f5efe2; }
  #toast { position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%); background: var(--ink); color: #fff; padding: 12px 18px; border-radius: 12px; font-size: 14px; display: none; max-width: 92vw; z-index: 10; }
  @media (max-width: 640px) { .hide-sm { display: none; } .mails { grid-template-columns: 1fr; } .bar { position: static; } }
</style>
</head>
<body>
<main>
  <a class="back" href="dashboard.php">← Back to dashboard</a>
  <h1>Email</h1>
  <p class="sub">Every email RestroGrow sends: how it looks, who gets it and when, what was sent, and the jobs that send them. <a href="email_book.php" style="color:var(--accent);font-weight:700">Open the Email book →</a> (all emails full size, easy to read or show someone)</p>

  <?php if ($sink !== ''): ?>
    <div class="warn"><b>Test mode:</b> MAIL_SINK_DIR is set, so emails are saved as files in <?php echo $h($sink); ?> instead of being sent.</div>
  <?php elseif (!SMTP_ENABLED): ?>
    <div class="warn"><b>Emails are switched off:</b> set SMTP_ENABLED=true in main/.env (with the Google Workspace SMTP login) and they start going out. Until then nothing reaches anyone; see Super Admin › My to-do.</div>
  <?php endif; ?>

  <nav class="tabs">
    <a href="?tab=all" data-tab="all"<?php echo $tab === 'all' ? ' class="on"' : ''; ?>>All emails<b><?php echo count($all); ?></b></a>
    <a href="?tab=log" data-tab="log"<?php echo $tab === 'log' ? ' class="on"' : ''; ?>>Sent log<?php if ((int)($stats['failed'] ?? 0)): ?><span class="dot" title="Failed emails"></span><?php endif; ?></a>
    <a href="?tab=settings" data-tab="settings"<?php echo $tab === 'settings' ? ' class="on"' : ''; ?>>Settings &amp; jobs</a>
  </nav>

  <!-- ═══ All emails ═══ -->
  <section class="pane<?php echo $tab === 'all' ? ' on' : ''; ?>" id="pane-all">
    <div class="bar">
      <div class="row" style="margin-bottom:10px">
        <input type="search" id="mailQ" placeholder="Search: trial, order, birthday…" style="flex:1;min-width:200px">
        <input type="email" id="meTo" placeholder="Your email, for test sends" style="flex:1;min-width:200px">
      </div>
      <div class="chips">
        <button type="button" class="chip on" data-who="">All <?php echo count($all); ?></button>
        <?php foreach ($who as $w => $l): ?><button type="button" class="chip" data-who="<?php echo $w; ?>"><?php echo $h($l); ?> <?php echo count(array_filter($all, fn($m) => $m['who'] === $w)); ?></button><?php endforeach; ?>
      </div>
    </div>
    <?php foreach ($whoLong as $w => $label): ?>
      <h2 class="group-h" data-group="<?php echo $w; ?>"><?php echo $h($label); ?></h2>
      <div class="mails" data-group="<?php echo $w; ?>">
      <?php foreach ($all as $m): if ($m['who'] !== $w) continue; $isOff = in_array($m['type'], $off, true); ?>
        <article class="mail<?php echo $isOff ? ' isoff' : ''; ?>" data-who="<?php echo $w; ?>" data-search="<?php echo $h(mb_strtolower($m['name'] . ' ' . $m['variant'] . ' ' . $m['subject'] . ' ' . $m['when'] . ' ' . $m['type'])); ?>">
          <div class="thumb" data-open="<?php echo $h($m['key']); ?>" data-subj="<?php echo $h($m['subject']); ?>"><iframe loading="lazy" sandbox src="email.php?preview=<?php echo $h($m['key']); ?>" title="<?php echo $h($m['name']); ?>" tabindex="-1"></iframe></div>
          <div class="info">
            <div class="t"><?php echo $h($m['name']); ?> <?php if ($m['variant']): ?><span class="pill var"><?php echo $h($m['variant']); ?></span><?php endif; ?><?php if ($isOff): ?> <span class="pill off">switched off</span><?php endif; ?></div>
            <div class="l"><span>When</span><div><?php echo $h($m['when']); ?></div></div>
            <div class="l"><span>How</span><div><?php echo $h($m['how']); ?></div></div>
            <div class="l"><span>From</span><div><?php echo $h($m['from']); ?></div></div>
            <div class="subj"><small>Subject</small><?php echo $h($m['subject']); ?></div>
            <div class="l"><span>Sent</span><div><?php $n = (int)($sentByType[$m['type']] ?? 0); echo $n ? $n . ' in the last 30 days' : 'none in the last 30 days'; ?></div></div>
            <div class="acts"><button class="btn light sm" type="button" data-open="<?php echo $h($m['key']); ?>" data-subj="<?php echo $h($m['subject']); ?>">Open</button><button class="btn sm" type="button" data-testone="<?php echo $h($m['key']); ?>">Send me a test</button></div>
          </div>
        </article>
      <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <p style="color:var(--muted);font-size:13px;margin-top:18px">Previews use a sample restaurant (Spice Garden) and sample customers. Real emails carry the restaurant's own name, logo, phone and order.</p>
  </section>

  <!-- ═══ Sent log ═══ -->
  <section class="pane<?php echo $tab === 'log' ? ' on' : ''; ?>" id="pane-log">
    <div class="grid">
      <div class="card">
        <h3>Last 7 days</h3>
        <div class="stats">
          <div class="stat"><b style="color:var(--ok)"><?php echo (int)($stats['sent'] ?? 0); ?></b><small>sent</small></div>
          <div class="stat"><b style="color:var(--bad)"><?php echo (int)($stats['failed'] ?? 0); ?></b><small>failed</small></div>
          <div class="stat"><b style="color:var(--wait)"><?php echo (int)($stats['queued'] ?? 0) + (int)($stats['sending'] ?? 0); ?></b><small>waiting</small></div>
        </div>
      </div>
    </div>
    <div class="card">
      <form class="row" method="get" style="margin-bottom:10px">
        <input type="hidden" name="tab" value="log">
        <input type="search" name="q" value="<?php echo $h($q); ?>" placeholder="Email, subject or restaurant ID" style="flex:1">
        <select name="s"><option value="">All</option><?php foreach (['sent' => 'Sent', 'failed' => 'Failed', 'queued' => 'Waiting'] as $k => $l): ?><option value="<?php echo $k; ?>"<?php echo $fs === $k ? ' selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select>
        <button class="btn light" type="submit">Filter</button>
      </form>
      <div class="scroll">
      <?php if (!$log): ?><p style="color:var(--muted)">No emails yet.</p><?php else: ?>
        <table>
          <thead><tr><th>When</th><th>To</th><th>Email</th><th class="hide-sm">Type</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($log as $m): ?>
            <tr>
              <td><?php echo $h(date('d M, h:i A', strtotime($m['created_at']))); ?></td>
              <td><?php echo $h($m['to_email']); ?><?php if ($m['restaurant_name']): ?><br><small><?php echo $h($m['restaurant_name']); ?></small><?php endif; ?></td>
              <td><?php echo $h($m['subject']); ?></td>
              <td class="hide-sm"><small><?php echo $h($m['type'] === 'test' ? 'Test' : ($types[$m['type']][0] ?? $m['type'])); ?></small></td>
              <td><span class="pill <?php echo $h($m['status']); ?>"><?php echo $h(['queued' => 'waiting', 'sending' => 'sending'][$m['status']] ?? $m['status']); ?></span>
                <?php if ($m['last_error']): ?><div class="err"><?php echo $h(mb_substr($m['last_error'], 0, 140)); ?></div><?php endif; ?></td>
              <td><div class="lacts"><button type="button" data-view="<?php echo (int)$m['id']; ?>" data-subj="<?php echo $h($m['subject']); ?>">View</button><?php if ($m['type'] !== 'password_reset' && $m['status'] !== 'sending'): ?><button type="button" data-resend="<?php echo (int)$m['id']; ?>">Send again</button><?php endif; ?></div></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- ═══ Settings & jobs ═══ -->
  <section class="pane<?php echo $tab === 'settings' ? ' on' : ''; ?>" id="pane-settings">
    <div class="grid">
      <div class="card">
        <h3>Sending account</h3>
        <div class="kv">
          <span>Status</span><b style="color:<?php echo $sink !== '' ? 'var(--wait)' : (SMTP_ENABLED ? 'var(--ok)' : 'var(--bad)'); ?>"><?php echo $sink !== '' ? 'Test mode (saved to files)' : (SMTP_ENABLED ? 'On' : 'Off (SMTP_ENABLED=false)'); ?></b>
          <span>Server</span><b><?php echo $h(SMTP_HOST . ':' . SMTP_PORT . ' (' . SMTP_SECURE . ')'); ?></b>
          <span>From</span><b><?php echo $h(rgMailFromName() . ' <' . SMTP_FROM_EMAIL . '>'); ?></b>
          <span>Team alerts to</span><b><?php echo $h(mailTeamAddress()); ?></b>
          <span>Daily limit</span><b>2,000 / day (Google Workspace)</b>
        </div>
        <div class="row" style="margin-top:14px"><input type="email" id="testTo" placeholder="you@restrogrow.com" style="flex:1"><button class="btn" type="button" id="test">Send test</button></div>
      </div>
      <div class="card">
        <h3>Scheduled jobs (cron)</h3>
        <?php foreach ($jobs as [$name, $file, $every, $last, $staleMin, $what]):
          $ok = $last !== '' && strtotime($last) > time() - $staleMin * 60; ?>
          <div class="job">
            <div><b><?php echo $h($name); ?></b><br><small style="color:var(--muted)"><?php echo $h($every); ?> · <code><?php echo $h($file); ?></code></small></div>
            <div style="text-align:right"><span class="pill <?php echo $ok ? 'sent' : ($last === '' ? 'off' : 'failed'); ?>"><?php echo $ok ? 'Running' : ($last === '' ? 'Not set up' : 'Stopped'); ?></span><br><small style="color:var(--muted)"><?php echo $last ? 'Last run ' . $h(date('d M, h:i A', strtotime($last))) : 'Never ran'; ?></small></div>
            <p><?php echo $h($what); ?></p>
          </div>
        <?php endforeach; ?>
        <p style="font-size:13px;color:var(--muted);margin:10px 0 0">Reminders last checked: <?php echo $lastSweep ? $h(date('d M, h:i A', strtotime($lastSweep))) : 'never'; ?></p>
        <div class="row" style="margin-top:10px"><button class="btn light" type="button" id="sweep">Check reminders now</button></div>
      </div>
    </div>
    <div class="card types">
      <h3>Which emails go out</h3>
      <div class="cols">
        <?php foreach ($whoLong as $w => $label): ?>
          <div><h4><?php echo $h($label); ?></h4>
          <?php foreach ($types as $k => $t): if ($t[1] !== $w) continue; ?>
            <label><input type="checkbox" data-type="<?php echo $h($k); ?>"<?php echo in_array($k, $off, true) ? '' : ' checked'; ?><?php echo $k === 'password_reset' ? ' disabled' : ''; ?>><?php echo $h($t[0]); ?></label>
          <?php endforeach; ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</main>
<dialog id="dlg"><div class="top"><span id="dlgT"></span><button class="btn light sm" type="button" onclick="dlg.close()">Close</button></div><iframe id="frame" sandbox title="Email preview"></iframe></dialog>
<div id="toast"></div>
<script>
var dlg = document.getElementById('dlg');
var $ = function (id) { return document.getElementById(id); };
function toast(t) { var e = $('toast'); e.textContent = t; e.style.display = 'block'; clearTimeout(toast.t); toast.t = setTimeout(function () { e.style.display = 'none'; }, 4500); }
function post(b, btn) {
  if (btn) btn.disabled = true;
  return fetch('email.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) }).then(function (r) { return r.json(); })
    .then(function (d) { toast(d.message || (d.ok ? 'Done' : 'Something went wrong')); return d; })
    .catch(function () { toast('Network error'); return { ok: false }; })
    .finally(function () { if (btn) btn.disabled = false; });
}
function show(url, title) { $('dlgT').textContent = title; $('frame').src = url; dlg.showModal(); }
// tabs without reloading
document.querySelectorAll('.tabs a').forEach(function (a) {
  a.addEventListener('click', function (e) {
    e.preventDefault();
    document.querySelectorAll('.tabs a').forEach(function (x) { x.classList.toggle('on', x === a); });
    document.querySelectorAll('.pane').forEach(function (p) { p.classList.toggle('on', p.id === 'pane-' + a.dataset.tab); });
    history.replaceState(null, '', '?tab=' + a.dataset.tab);
  });
});
// your address for test sends, remembered on this computer
try { $('meTo').value = localStorage.getItem('rgMailTestTo') || ''; $('testTo').value = $('meTo').value; } catch (e) {}
$('meTo').addEventListener('change', function () { try { localStorage.setItem('rgMailTestTo', this.value); } catch (e) {} $('testTo').value = this.value; });
// search + who filter
var who = '';
function filter() {
  var q = $('mailQ').value.trim().toLowerCase();
  document.querySelectorAll('.mail').forEach(function (m) { m.style.display = (!who || m.dataset.who === who) && (!q || m.dataset.search.indexOf(q) !== -1) ? '' : 'none'; });
  document.querySelectorAll('[data-group]').forEach(function (g) {
    var any = [].some.call(document.querySelectorAll('.mails[data-group="' + g.dataset.group + '"] .mail'), function (m) { return m.style.display !== 'none'; });
    g.style.display = any ? '' : 'none';
  });
}
$('mailQ').addEventListener('input', filter);
document.querySelectorAll('.chip').forEach(function (c) {
  c.addEventListener('click', function () { who = c.dataset.who; document.querySelectorAll('.chip').forEach(function (x) { x.classList.toggle('on', x === c); }); filter(); });
});
document.addEventListener('change', function (e) {
  if (!e.target.dataset.type) return;
  var off = [].slice.call(document.querySelectorAll('[data-type]')).filter(function (c) { return !c.checked; }).map(function (c) { return c.dataset.type; });
  post({ action: 'types', off: off });
});
document.addEventListener('click', function (e) {
  var t = e.target.closest('[data-open],[data-view],[data-resend],[data-testone],#test,#sweep');
  if (!t) return;
  if (t.dataset.open) show('email.php?preview=' + encodeURIComponent(t.dataset.open), t.dataset.subj);
  else if (t.dataset.view) show('email.php?view=' + t.dataset.view, t.dataset.subj);
  else if (t.dataset.resend) post({ action: 'resend', id: +t.dataset.resend }, t).then(function (d) { if (d.ok) setTimeout(function () { location.reload(); }, 900); });
  else if (t.dataset.testone) { if (!$('meTo').value) { $('meTo').focus(); toast('Type your email address at the top first'); return; } post({ action: 'testOne', key: t.dataset.testone, to: $('meTo').value }, t); }
  else if (t.id === 'test') post({ action: 'test', to: $('testTo').value }, t);
  else if (t.id === 'sweep') post({ action: 'sweep' }, t);
});
</script>
</body>
</html>
