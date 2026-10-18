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
require_once __DIR__ . '/../config/mail_letters.php';

$conn = getConnection();
mailEnsure($conn);
letterEnsure($conn);
letterSigEnsure($conn);
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
        $letterIn = function () use ($in): array {
            $t = (array)($in['letter'] ?? []);
            $s = fn($k, $n) => mb_substr(trim((string)($t[$k] ?? '')), 0, $n);
            return ['name' => $s('name', 120), 'category' => in_array($t['category'] ?? '', letterCategories(), true) ? $t['category'] : 'Other',
                'subject' => $s('subject', 200), 'title' => $s('title', 200), 'body' => mb_substr((string)($t['body'] ?? ''), 0, 60000),
                'button_text' => $s('button_text', 60), 'button_url' => $s('button_url', 300)];
        };
        $letterGet = function (int $id) use ($conn): array {
            $st = $conn->prepare('SELECT * FROM sa_letters WHERE id = ?');
            $st->execute([$id]);
            $t = $st->fetch(PDO::FETCH_ASSOC);
            if (!$t) throw new Exception('Template not found');
            return $t;
        };
        switch ($in['action'] ?? '') {
            // ── Proposals & letters ──
            case 'sigSave':
                $name = mb_substr(trim(strip_tags((string)($in['name'] ?? ''))), 0, 80);
                if ($name === '') $name = 'RestroGrow';
                $png = letterSigClean((string)($in['image'] ?? ''));
                if ($png === '' || $png[0] === '!') throw new Exception(ltrim($png, '!') ?: 'The signature image is missing');
                $file = 'sig_' . bin2hex(random_bytes(12)) . '.png';
                if (!is_dir(letterSigDir())) @mkdir(letterSigDir(), 0755, true);
                @file_put_contents(letterSigDir() . '/' . $file, $png);
                $first = !(int)$conn->query('SELECT COUNT(*) FROM sa_signatures WHERE deleted_at IS NULL')->fetchColumn();
                $def = $first || !empty($in['is_default']);
                if ($def) $conn->exec('UPDATE sa_signatures SET is_default = 0');
                $conn->prepare('INSERT INTO sa_signatures (name, designation, extra, image, file, is_default) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$name, mb_substr(trim(strip_tags((string)($in['designation'] ?? ''))), 0, 80) ?: null, mb_substr(trim(strip_tags((string)($in['extra'] ?? ''))), 0, 120) ?: null,
                        base64_encode($png), $file, $def ? 1 : 0]);
                $out += ['message' => 'Signature saved', 'sigs' => letterSigList($conn)];
                break;
            case 'sigDefault':
                $conn->exec('UPDATE sa_signatures SET is_default = 0');
                $conn->prepare('UPDATE sa_signatures SET is_default = 1 WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
                $out += ['message' => 'Used by default from now on', 'sigs' => letterSigList($conn)];
                break;
            case 'sigDelete':
                $conn->prepare('UPDATE sa_signatures SET deleted_at = NOW(), is_default = 0 WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
                $out += ['message' => 'Signature deleted', 'sigs' => letterSigList($conn)];
                break;
            case 'sigRestore':
                $conn->prepare('UPDATE sa_signatures SET deleted_at = NULL WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
                $out += ['message' => 'Brought back', 'sigs' => letterSigList($conn)];
                break;
            case 'letterPreview':
                $r = letterRender($conn, $letterIn(), (array)($in['vals'] ?? []), letterSigGet($conn, (int)($in['sig'] ?? 0)), false);
                $out += ['subject' => $r['subject'], 'html' => $r['html'], 'text' => $r['text'], 'missing' => $r['missing']];
                break;
            case 'letterSave':
                $t = $letterIn();
                if ($t['name'] === '' || $t['subject'] === '' || trim($t['body']) === '') throw new Exception('Give it a name, a subject and some text');
                $id = (int)($in['id'] ?? 0);
                if ($id) {
                    $conn->prepare('UPDATE sa_letters SET name = ?, category = ?, subject = ?, title = ?, body = ?, button_text = ?, button_url = ? WHERE id = ?')
                        ->execute([$t['name'], $t['category'], $t['subject'], $t['title'], $t['body'], $t['button_text'] ?: null, $t['button_url'] ?: null, $id]);
                } else {
                    $conn->prepare('INSERT INTO sa_letters (category, name, subject, title, body, button_text, button_url, sort) VALUES (?, ?, ?, ?, ?, ?, ?, 999)')
                        ->execute([$t['category'], $t['name'], $t['subject'], $t['title'], $t['body'], $t['button_text'] ?: null, $t['button_url'] ?: null]);
                    $id = (int)$conn->lastInsertId();
                }
                $out += ['id' => $id, 'message' => 'Saved'];
                break;
            case 'letterDelete':
                $conn->prepare('UPDATE sa_letters SET deleted_at = NOW() WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
                $out['message'] = 'Deleted';
                break;
            case 'letterRestore':
                $conn->prepare('UPDATE sa_letters SET deleted_at = NULL WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
                $out['message'] = 'Brought back';
                break;
            case 'letterReset':
                // a starter template back to how RestroGrow wrote it
                $t = $letterGet((int)($in['id'] ?? 0));
                $d = null;
                foreach (letterDefaults() as $x) if ($x['seed'] === $t['seed']) $d = $x;
                if (!$d) throw new Exception('Only the starter templates can be reset');
                $conn->prepare('UPDATE sa_letters SET name = ?, category = ?, subject = ?, title = ?, body = ?, button_text = ?, button_url = ? WHERE id = ?')
                    ->execute([$d['name'], $d['category'], $d['subject'], $d['title'], $d['body'], $d['button_text'] ?? null, $d['button_url'] ?? null, $t['id']]);
                $out['message'] = 'Back to the original';
                break;
            case 'letterSend':
                $t = $letterIn();
                $vals = (array)($in['vals'] ?? []);
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new Exception('Enter the email address to send to');
                $r = letterRender($conn, $t, $vals, letterSigGet($conn, (int)($in['sig'] ?? 0)), true);
                if ($r['missing']) throw new Exception('Fill in first: ' . implode(', ', array_map(fn($k) => ucfirst(str_replace('_', ' ', $k)), $r['missing'])));
                $rid = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($in['restaurant_id'] ?? '')) ?: null;
                $s = mailSendNow($conn, 'letter', $to, $r['subject'], $r['html'], ['brand' => mailPlatformBrand(), 'text' => $r['text'], 'restaurant_id' => $rid,
                    'to_name' => (string)($vals['owner'] ?? '')]);
                if (!$s['success']) throw new Exception('Not sent: ' . ($s['error'] ?: 'check the SMTP settings in .env'));
                if (!empty($in['copy'])) mailSendNow($conn, 'letter', mailTeamAddress(), '[Copy] ' . $r['subject'], $r['html'], ['brand' => mailPlatformBrand(), 'text' => $r['text'], 'restaurant_id' => $rid]);
                if ((int)($in['id'] ?? 0)) $conn->prepare('UPDATE sa_letters SET sent_count = sent_count + 1, last_sent_at = NOW() WHERE id = ?')->execute([(int)$in['id']]);
                $out['message'] = 'Sent to ' . $to . (!empty($in['copy']) ? ' (copy to ' . mailTeamAddress() . ')' : '');
                break;
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
    ['Cron test', 'cron/heartbeat.php', 'Every minute', $setting('cron_heartbeat_at'), 3,
        'Only checks that the server\'s cron can run PHP and reach the database' . ($setting('cron_heartbeat_info') !== '' ? ' (last run: ' . $setting('cron_heartbeat_info') . ')' : '') . '. If this says Running, the other jobs can run too.'],
    ['Emails: reminders and retries', 'cron/email_dispatch.php', 'Every 10 minutes', $setting('cron_email_dispatch_at'), 30,
        'Trial / renewal reminders, payment receipts, gift emails, booking reminders, and retrying failed emails. Without it they still go out, but only while someone has a dashboard open.'],
    ['AI manager morning report', 'cron/owner_brief.php', 'Every 15 minutes', $setting('cron_owner_brief_at'), 45,
        'Sends each owner\'s morning report (WhatsApp, and email if they turned it on) at the time they picked. Without it the report only goes when the owner opens the AI manager.'],
];
$who = ['owner' => 'Restaurant owners', 'team' => 'RestroGrow team & website visitors', 'customer' => 'Customers'];
$whoLong = ['owner' => 'To restaurant owners (from RestroGrow)', 'team' => 'To the RestroGrow team / website visitors', 'customer' => 'To customers (from the restaurant\'s name)'];
$tab = in_array($_GET['tab'] ?? '', ['letters', 'all', 'log', 'settings'], true) ? $_GET['tab'] : (($q !== '' || $fs !== '') ? 'log' : 'letters');
// Proposals & letters: the templates, the restaurants to fill them from, and the values filled in automatically
$letters = $conn->query('SELECT id, seed, category, name, subject, title, body, button_text, button_url, sent_count, last_sent_at FROM sa_letters WHERE deleted_at IS NULL ORDER BY sort, id')->fetchAll(PDO::FETCH_ASSOC);
try { $letterRests = $conn->query("SELECT restaurant_id, restaurant_name, owner_name, email, phone, address, city FROM users WHERE restaurant_id IS NOT NULL AND restaurant_id <> '' ORDER BY restaurant_name LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC); }
catch (PDOException $e) { $letterRests = $conn->query("SELECT restaurant_id, restaurant_name, owner_name, email, phone, address FROM users WHERE restaurant_id IS NOT NULL AND restaurant_id <> '' ORDER BY restaurant_name LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC); }
$letterAuto = letterAutoValues($conn);
$letterSigs = letterSigList($conn);
$problems = (!SMTP_ENABLED && $sink === '') || (int)($stats['failed'] ?? 0) > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Email · Super Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@600&family=Great+Vibes&family=Caveat:wght@600&display=swap" rel="stylesheet">
<style>
  :root { --ink:#111827; --soft:#374151; --muted:#6b7280; --line:#e5e7eb; --bg:#f3f4f6; --ok:#16a34a; --bad:#dc2626; --wait:#b45309; --accent:#c1440e; }
  * { box-sizing: border-box; } [hidden] { display: none !important; }
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
  /* proposals & letters */
  .lt { display: grid; grid-template-columns: 250px minmax(0, 1fr) minmax(0, 1fr); gap: 14px; align-items: start; }
  .lt-list { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 10px; position: sticky; top: 10px; max-height: calc(100vh - 20px); overflow: auto; }
  .lt-list h4 { margin: 12px 8px 4px; font-size: 11.5px; letter-spacing: .07em; text-transform: uppercase; color: var(--muted); }
  .lt-item { display: block; width: 100%; text-align: left; padding: 9px 10px; border: 0; border-radius: 10px; background: none; font: inherit; font-size: 13.5px; font-weight: 600; color: var(--soft); cursor: pointer; line-height: 1.35; }
  .lt-item small { display: block; font-weight: 400; color: var(--muted); font-size: 12px; margin-top: 2px; }
  .lt-item:hover { background: #f9fafb; } .lt-item.on { background: #fff1e8; color: var(--ink); }
  .lt-main { display: grid; gap: 14px; }
  .lt-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap; }
  .lt-head h3 { margin: 0; font-size: 18px; }
  .lt-fields { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 10px; }
  .lt-f label, .lt-e label { display: block; font-size: 12.5px; font-weight: 700; color: var(--soft); margin-bottom: 4px; }
  .lt-f input, .lt-e input, .lt-e select, .lt-e textarea, .lt-send input[type=email], .lt-send select { width: 100%; padding: 9px 11px; border: 1.5px solid var(--line); border-radius: 10px; font: inherit; font-size: 14px; background: #fff; }
  .lt-f.empty input { border-color: #f59e0b; background: #fffbeb; }
  .lt-f.auto label:after { content: ' (auto)'; font-weight: 400; color: var(--muted); }
  .lt-e { display: grid; gap: 10px; } .lt-e[hidden], .lt-miss[hidden] { display: none; }
  .lt-e textarea { min-height: 380px; font-family: ui-monospace, Consolas, monospace; font-size: 13px; line-height: 1.55; resize: vertical; }
  .lt-help { font-size: 12.5px; color: var(--muted); line-height: 1.6; background: #f9fafb; border-radius: 10px; padding: 10px 12px; }
  .lt-help code { background: #fff; border: 1px solid var(--line); border-radius: 5px; padding: 0 4px; }
  .lt-send { display: grid; gap: 10px; }
  .lt-prev { background: #fff; border: 1px solid var(--line); border-radius: 16px; overflow: hidden; position: sticky; top: 10px; }
  .lt-prev .s { padding: 10px 14px; border-bottom: 1px solid var(--line); font-size: 13.5px; } .lt-prev .s small { color: var(--muted); display: block; font-size: 11.5px; }
  .lt-prev iframe { width: 100%; height: calc(100vh - 90px); min-height: 520px; border: 0; background: #f5efe2; display: block; }
  .lt-miss { font-size: 13px; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 8px 12px; }
  .sigdlg { width: min(900px, 96vw); height: auto; max-height: 92vh; overflow: auto; }
  .sig-wrap { display: grid; grid-template-columns: 1fr 1.3fr; gap: 16px; padding: 16px; }
  .sig-card { border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px; display: grid; gap: 6px; margin-bottom: 10px; }
  .sig-card img { max-height: 56px; max-width: 100%; justify-self: start; background: #fff; }
  .sig-card small { color: var(--muted); }
  .sig-tabs { display: flex; gap: 6px; margin-bottom: 8px; }
  .sig-pad { width: 100%; aspect-ratio: 3 / 1; border: 1.5px dashed #cbd5e1; border-radius: 12px; background: #fff; touch-action: none; cursor: crosshair; display: block; }
  .sig-f { display: grid; gap: 8px; margin-top: 10px; }
  .sig-f input, .sig-f select { width: 100%; padding: 9px 11px; border: 1.5px solid var(--line); border-radius: 10px; font: inherit; font-size: 14px; }
  .sig-f label { font-size: 12.5px; font-weight: 700; color: var(--soft); }
  @media (max-width: 760px) { .sig-wrap { grid-template-columns: 1fr; } }
  #toast button { margin-left: 12px; background: #fff; color: var(--ink); border: 0; border-radius: 8px; padding: 4px 10px; font-weight: 700; cursor: pointer; }
  @media (max-width: 1100px) { .lt { grid-template-columns: 220px minmax(0, 1fr); } .lt-prev { grid-column: 1 / -1; position: static; } }
  @media (max-width: 760px) { .lt { grid-template-columns: 1fr; } .lt-list { position: static; max-height: none; } }
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
    <a href="?tab=letters" data-tab="letters"<?php echo $tab === 'letters' ? ' class="on"' : ''; ?>>Proposals &amp; letters<b><?php echo count($letters); ?></b></a>
    <a href="?tab=all" data-tab="all"<?php echo $tab === 'all' ? ' class="on"' : ''; ?>>All emails<b><?php echo count($all); ?></b></a>
    <a href="?tab=log" data-tab="log"<?php echo $tab === 'log' ? ' class="on"' : ''; ?>>Sent log<?php if ((int)($stats['failed'] ?? 0)): ?><span class="dot" title="Failed emails"></span><?php endif; ?></a>
    <a href="?tab=settings" data-tab="settings"<?php echo $tab === 'settings' ? ' class="on"' : ''; ?>>Settings &amp; jobs</a>
  </nav>

  <!-- ═══ Proposals & letters ═══ -->
  <section class="pane<?php echo $tab === 'letters' ? ' on' : ''; ?>" id="pane-letters">
    <div class="lt">
      <nav class="lt-list" id="ltList" aria-label="Templates"></nav>
      <div class="lt-main">
        <div class="card">
          <div class="lt-head">
            <div><h3 id="ltName"></h3><small id="ltMeta" style="color:var(--muted)"></small></div>
            <div class="row">
              <button class="btn light sm" type="button" id="ltEditBtn">Edit template</button>
              <button class="btn light sm" type="button" id="ltDup">Make a copy</button>
              <button class="btn light sm" type="button" id="ltReset" hidden>Reset</button>
              <button class="btn light sm" type="button" id="ltDel">Delete</button>
            </div>
          </div>
        </div>

        <div class="card lt-e" id="ltEdit" hidden>
          <h3 style="margin:0">Edit template</h3>
          <div class="row" style="align-items:flex-end">
            <div style="flex:2;min-width:200px"><label for="eName">Template name (only you see it)</label><input id="eName" maxlength="120"></div>
            <div style="flex:1;min-width:140px"><label for="eCat">Group</label><select id="eCat"><?php foreach (letterCategories() as $k): ?><option><?php echo $h($k); ?></option><?php endforeach; ?></select></div>
          </div>
          <div><label for="eSubject">Email subject</label><input id="eSubject" maxlength="200"></div>
          <div><label for="eTitle">Heading inside the email</label><input id="eTitle" maxlength="200"></div>
          <div><label for="eBody">Text</label><textarea id="eBody" spellcheck="true"></textarea></div>
          <div class="row"><div style="flex:1;min-width:160px"><label for="eBtnT">Button text (optional)</label><input id="eBtnT" maxlength="60" placeholder="e.g. See a live demo"></div>
            <div style="flex:2;min-width:200px"><label for="eBtnU">Button link</label><input id="eBtnU" maxlength="300" placeholder="https://…"></div></div>
          <div class="lt-help">
            <b>How to write:</b> a blank line starts a new paragraph · <code>## Heading</code> · <code>- point</code> for bullets · <code>1. step</code> for numbers · <code>**bold**</code> · <code>[text](https://link)</code> · <code>---</code> a line.<br>
            <b>Boxes to fill:</b> write any word in double braces, like <code>{{restaurant}}</code>, <code>{{owner}}</code> or <code>{{setup_fee}}</code>; it becomes a box above Send. Filled by itself: <code>{{date}}</code> <code>{{my_name}}</code> <code>{{my_phone}}</code> <code>{{my_email}}</code> <code>{{price}}</code> <code>{{price_yearly}}</code> <code>{{trial_days}}</code> <code>{{website}}</code>. <code>{{signature}}</code> puts your chosen signature there (without it, the signature replaces the last <code>{{my_name}}</code>). Picking a restaurant fills <code>{{restaurant}}</code> <code>{{owner}}</code> <code>{{restaurant_address}}</code> <code>{{city}}</code> <code>{{login_email}}</code>.
          </div>
          <div class="row"><button class="btn" type="button" id="ltSave">Save template</button><button class="btn light" type="button" id="ltCancel">Cancel</button><small style="color:var(--muted)">The preview on the right updates as you type.</small></div>
        </div>

        <div class="card lt-send">
          <h3 style="margin:0">Fill in and send</h3>
          <div><label for="ltRest" style="font-size:12.5px;font-weight:700;color:var(--soft)">Fill from a restaurant (optional)</label>
            <select id="ltRest"><option value="">Type the details yourself…</option><?php foreach ($letterRests as $r): ?><option value="<?php echo $h($r['restaurant_id']); ?>"><?php echo $h(($r['restaurant_name'] ?: $r['restaurant_id']) . ($r['owner_name'] ? ' · ' . $r['owner_name'] : '')); ?></option><?php endforeach; ?></select></div>
          <div class="lt-fields" id="ltFields"></div>
          <div class="lt-miss" id="ltMiss" hidden></div>
          <div><label for="ltSig" style="font-size:12.5px;font-weight:700;color:var(--soft)">Signature</label>
            <div class="row" style="flex-wrap:nowrap"><select id="ltSig" style="flex:1"></select><button class="btn light sm" type="button" id="ltSigBtn">Signatures…</button></div></div>
          <div><label for="ltTo" style="font-size:12.5px;font-weight:700;color:var(--soft)">Send to</label><input type="email" id="ltTo" placeholder="owner@restaurant.com"></div>
          <label style="display:flex;gap:8px;align-items:center;font-size:13.5px"><input type="checkbox" id="ltCopy" checked> Send a copy to me (<?php echo $h(mailTeamAddress()); ?>)</label>
          <div class="row">
            <button class="btn" type="button" id="ltSend">Send email</button>
            <button class="btn light" type="button" id="ltText" title="Copy as text for WhatsApp">Copy text</button>
            <button class="btn light" type="button" id="ltPrint" title="Print, or Save as PDF in the print window">Print / PDF</button>
          </div>
        </div>
      </div>
      <div class="lt-prev"><div class="s"><small>Subject</small><span id="ltSubj"></span></div><iframe id="ltFrame" sandbox title="Preview"></iframe></div>
    </div>
  </section>

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
<dialog id="sigDlg" class="sigdlg">
  <div class="top"><span>Signatures</span><button class="btn light sm" type="button" id="sigClose">Close</button></div>
  <div class="sig-wrap">
    <div><h3 style="margin:0 0 10px;font-size:15px">Saved</h3><div id="sigList"></div></div>
    <div>
      <h3 style="margin:0 0 10px;font-size:15px">New signature</h3>
      <div class="sig-tabs"><button class="chip on" type="button" data-sigmode="draw">Draw</button><button class="chip" type="button" data-sigmode="type">Type</button><button class="chip" type="button" data-sigmode="upload">Upload</button></div>
      <canvas id="sigPad" class="sig-pad" width="900" height="300"></canvas>
      <div class="row" style="margin-top:8px" id="sigDrawBar"><small style="color:var(--muted);flex:1">Sign with your mouse or finger.</small>
        <select id="sigInk" style="padding:6px 8px;border-radius:8px;border:1.5px solid var(--line)"><option value="#1e3a8a">Blue ink</option><option value="#111827">Black ink</option></select>
        <button class="btn light sm" type="button" id="sigUndo">Undo</button><button class="btn light sm" type="button" id="sigClear">Clear</button></div>
      <div class="sig-f" id="sigTypeBar" hidden><label for="sigTypeText">Type your signature</label><input id="sigTypeText" maxlength="40" placeholder="Your name">
        <select id="sigFont"><option value="Dancing Script">Style 1</option><option value="Great Vibes">Style 2</option><option value="Caveat">Style 3</option></select></div>
      <div class="sig-f" id="sigUpBar" hidden><label for="sigFile">Photo or scan of your signature (on white paper)</label><input type="file" id="sigFile" accept="image/png,image/jpeg,image/webp"></div>
      <div class="sig-f">
        <div><label for="sigName">Name shown under it (optional)</label><input id="sigName" maxlength="80" placeholder="e.g. Rahul Sharma"></div>
        <div><label for="sigDesig">Designation (optional)</label><input id="sigDesig" maxlength="80" placeholder="e.g. Founder, RestroGrow"></div>
        <div><label for="sigExtra">Extra line (optional)</label><input id="sigExtra" maxlength="120" placeholder="e.g. +91 63775 68749 · restrogrow.com"></div>
        <label style="display:flex;gap:8px;align-items:center;font-weight:400;font-size:13.5px"><input type="checkbox" id="sigDef" style="width:auto"> Use this one by default</label>
        <button class="btn" type="button" id="sigSave">Save signature</button>
        <div id="sigMsg" role="status" style="font-size:13.5px;font-weight:600;min-height:18px"></div>
      </div>
    </div>
  </div>
</dialog>
<dialog id="dlg"><div class="top"><span id="dlgT"></span><button class="btn light sm" type="button" onclick="dlg.close()">Close</button></div><iframe id="frame" sandbox title="Email preview"></iframe></dialog>
<div id="toast"></div>
<script>
var dlg = document.getElementById('dlg');
var $ = function (id) { return document.getElementById(id); };
function toast(t) { var e = $('toast'), host = document.querySelector('dialog[open]') || document.body; if (e.parentNode !== host) host.appendChild(e); e.textContent = t; e.style.display = 'block'; clearTimeout(toast.t); toast.t = setTimeout(function () { e.style.display = 'none'; }, 4500); }
function post(b, btn) {
  if (btn) btn.disabled = true;
  return fetch('email.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) }).then(function (r) {
      if (r.redirected && /login/.test(r.url)) return { ok: false, message: 'Your login expired. Log in again and try once more.' };
      return r.text().then(function (s) { try { return JSON.parse(s); } catch (e) { return { ok: false, message: 'The server had a problem (' + r.status + '). Try again in a minute.' }; } });
    })
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
/* ── Proposals & letters ── */
(function () {
  var L = <?php echo json_encode(array_map(fn($t) => ['id' => (int)$t['id'], 'seed' => $t['seed'], 'category' => $t['category'], 'name' => $t['name'], 'subject' => $t['subject'], 'title' => $t['title'],
    'body' => $t['body'], 'button_text' => (string)$t['button_text'], 'button_url' => (string)$t['button_url'], 'sent' => (int)$t['sent_count'], 'last' => $t['last_sent_at'] ? date('d M Y', strtotime($t['last_sent_at'])) : ''], $letters), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE); ?>;
  var RESTS = <?php echo json_encode($letterRests, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE); ?>;
  var AUTO = <?php echo json_encode($letterAuto, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE); ?>;
  var CATS = <?php echo json_encode(letterCategories()); ?>;
  var SIGS = <?php echo json_encode($letterSigs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE); ?>;
  var cur = null, editing = false, vals = {}, restId = '', lastText = '', lastHtml = '';
  try { vals = JSON.parse(localStorage.getItem('rgLetterVals') || '{}') || {}; } catch (e) {}
  function keep() { try { localStorage.setItem('rgLetterVals', JSON.stringify(vals)); } catch (e) {} }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]; }); }
  function label(k) { var t = k.replace(/_/g, ' '); return t.charAt(0).toUpperCase() + t.slice(1); }
  function draft() { return editing ? { name: $('eName').value, category: $('eCat').value, subject: $('eSubject').value, title: $('eTitle').value, body: $('eBody').value, button_text: $('eBtnT').value, button_url: $('eBtnU').value } : cur; }
  function fieldsOf(t) {
    var s = [t.subject, t.title, t.body, t.button_text, t.button_url].join('\n'), m, re = /\{\{\s*([a-z0-9_]{1,40})\s*\}\}/gi, out = [];
    while ((m = re.exec(s))) { var k = m[1].toLowerCase(); if (k !== 'signature' && out.indexOf(k) === -1) out.push(k); }
    return out;
  }
  function list() {
    var html = '<button class="btn sm" type="button" id="ltNew" style="width:100%;margin-bottom:4px">+ New template</button>';
    CATS.forEach(function (c) {
      var items = L.filter(function (t) { return t.category === c; });
      if (!items.length) return;
      html += '<h4>' + esc(c) + '</h4>' + items.map(function (t) {
        return '<button type="button" class="lt-item' + (cur && cur.id === t.id ? ' on' : '') + '" data-lt="' + t.id + '">' + esc(t.name) + '<small>' + (t.sent ? 'Sent ' + t.sent + '× · last ' + esc(t.last) : 'Not sent yet') + '</small></button>';
      }).join('');
    });
    html += '<h4>Signatures</h4><button type="button" class="lt-item" id="ltSigs">Your signatures<small>' + (SIGS.length ? SIGS.length + ' saved' : 'Draw, type or upload one') + '</small></button>';
    $('ltList').innerHTML = html;
  }
  function fields() {
    var t = draft(), box = $('ltFields');
    if (!t) { box.innerHTML = ''; return; }
    var keys = fieldsOf(t);
    var focused = document.activeElement && document.activeElement.dataset ? document.activeElement.dataset.k : null;
    box.innerHTML = keys.map(function (k) {
      var auto = !(k in vals) && (k in AUTO), v = k in vals ? vals[k] : (AUTO[k] || '');
      return '<div class="lt-f' + (auto ? ' auto' : '') + (String(v).trim() === '' ? ' empty' : '') + '"><label for="ltv_' + k + '">' + esc(label(k)) + '</label><input id="ltv_' + k + '" data-k="' + k + '" value="' + esc(v) + '"></div>';
    }).join('') || '<small style="color:var(--muted)">Nothing to fill in: this template has no {{boxes}}.</small>';
    if (focused && $('ltv_' + focused)) { var i = $('ltv_' + focused); i.focus(); i.setSelectionRange(i.value.length, i.value.length); }
  }
  var pt;
  function preview() {
    clearTimeout(pt);
    pt = setTimeout(function () {
      var t = draft();
      if (!t) return;
      fetch('email.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'letterPreview', letter: t, vals: vals, sig: +$('ltSig').value || 0 }) })
        .then(function (r) { return r.json(); }).then(function (d) {
          if (!d.ok) return;
          lastText = d.text; lastHtml = d.html;
          $('ltSubj').textContent = d.subject;
          $('ltFrame').srcdoc = d.html;
          var m = $('ltMiss');
          m.hidden = !d.missing.length;
          m.textContent = d.missing.length ? 'Still to fill in: ' + d.missing.map(label).join(', ') : '';
        });
    }, 250);
  }
  function open(id) {
    cur = L.filter(function (t) { return t.id === id; })[0] || L[0] || null;
    if (!cur) { startNew(); return; }
    editing = false;
    $('ltEdit').hidden = true;
    $('ltName').textContent = cur.name;
    $('ltMeta').textContent = cur.category + (cur.sent ? ' · sent ' + cur.sent + ' times, last on ' + cur.last : ' · not sent yet');
    $('ltReset').hidden = !cur.seed;
    $('ltDel').hidden = $('ltDup').hidden = $('ltEditBtn').hidden = false;
    list(); fields(); preview();
    try { localStorage.setItem('rgLetterOpen', id); } catch (e) {}
  }
  function edit(on) {
    editing = on;
    $('ltEdit').hidden = !on;
    if (on && cur) {
      $('eName').value = cur.name; $('eCat').value = cur.category; $('eSubject').value = cur.subject; $('eTitle').value = cur.title;
      $('eBody').value = cur.body; $('eBtnT').value = cur.button_text || ''; $('eBtnU').value = cur.button_url || '';
      $('eBody').focus();
    }
    fields(); preview();
  }
  function startNew(copyOf) {
    var base = copyOf ? Object.assign({}, copyOf, { name: copyOf.name + ' (copy)' }) : { name: 'New template', category: 'Proposal', subject: 'Hello {{owner}}', title: '', body: 'Hi {{owner}},\n\nWrite your message here.\n\nRegards,\n{{my_name}}', button_text: '', button_url: '' };
    cur = null;
    $('ltName').textContent = base.name;
    $('ltMeta').textContent = 'Not saved yet';
    $('ltReset').hidden = $('ltDel').hidden = $('ltDup').hidden = $('ltEditBtn').hidden = true;
    editing = true; $('ltEdit').hidden = false;
    $('eName').value = base.name; $('eCat').value = base.category; $('eSubject').value = base.subject; $('eTitle').value = base.title;
    $('eBody').value = base.body; $('eBtnT').value = base.button_text || ''; $('eBtnU').value = base.button_url || '';
    $('eName').focus(); $('eName').select();
    list(); fields(); preview();
  }
  function undoToast(msg, fn) {
    var e = $('toast');
    e.innerHTML = esc(msg) + '<button type="button">Undo</button>';
    e.style.display = 'block';
    e.querySelector('button').onclick = function () { e.style.display = 'none'; fn(); };
    clearTimeout(toast.t); toast.t = setTimeout(function () { e.style.display = 'none'; }, 8000);
  }
  $('ltList').addEventListener('click', function (e) {
    var b = e.target.closest('[data-lt]');
    if (b) open(+b.dataset.lt);
    else if (e.target.closest('#ltNew')) startNew();
    else if (e.target.closest('#ltSigs')) sigOpen();
  });
  $('ltEditBtn').onclick = function () { edit(!editing); };
  $('ltCancel').onclick = function () { open(cur ? cur.id : (L[0] ? L[0].id : 0)); };
  $('ltDup').onclick = function () { if (cur) startNew(cur); };
  $('ltSave').onclick = function () {
    var t = draft();
    post({ action: 'letterSave', id: cur ? cur.id : 0, letter: t }, this).then(function (d) {
      if (!d.ok) return;
      var row = Object.assign(cur || { sent: 0, last: '', seed: null }, t, { id: d.id });
      if (!cur) L.push(row);
      open(row.id);
    });
  };
  $('ltReset').onclick = function () {
    if (!cur) return;
    var id = cur.id;
    post({ action: 'letterReset', id: id }, this).then(function (d) { if (d.ok) location.href = 'email.php?tab=letters&open=' + id; });
  };
  $('ltDel').onclick = function () {
    if (!cur) return;
    var gone = cur, idx = L.indexOf(cur);
    post({ action: 'letterDelete', id: gone.id }).then(function (d) {
      if (!d.ok) return;
      L.splice(idx, 1);
      open(L[0] ? L[0].id : 0);
      undoToast('"' + gone.name + '" deleted', function () {
        post({ action: 'letterRestore', id: gone.id }).then(function (r) { if (r.ok) { L.splice(idx, 0, gone); open(gone.id); } });
      });
    });
  };
  ['eName', 'eCat', 'eSubject', 'eTitle', 'eBody', 'eBtnT', 'eBtnU'].forEach(function (id) {
    $(id).addEventListener('input', function () { if (id === 'eName') $('ltName').textContent = this.value; fields(); preview(); });
  });
  $('ltFields').addEventListener('input', function (e) {
    var k = e.target.dataset.k;
    if (!k) return;
    vals[k] = e.target.value;
    e.target.parentNode.classList.toggle('empty', e.target.value.trim() === '');
    e.target.parentNode.classList.remove('auto');
    keep(); preview();
  });
  $('ltRest').addEventListener('change', function () {
    restId = this.value;
    var r = RESTS.filter(function (x) { return x.restaurant_id === restId; })[0];
    if (!r) return;
    vals.restaurant = r.restaurant_name || '';
    vals.owner = (r.owner_name || '').trim().split(' ')[0] || '';
    vals.restaurant_address = [r.address, r.city].filter(Boolean).join(', ');
    vals.city = r.city || '';
    vals.login_email = r.email || '';
    $('ltTo').value = r.email || '';
    keep(); fields(); preview();
  });
  $('ltSend').onclick = function () {
    post({ action: 'letterSend', id: cur ? cur.id : 0, letter: draft(), vals: vals, to: $('ltTo').value.trim(), copy: $('ltCopy').checked, restaurant_id: restId, sig: +$('ltSig').value || 0 }, this)
      .then(function (d) { if (d.ok && cur) { cur.sent++; cur.last = new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }); list(); } });
  };
  $('ltText').onclick = function () {
    var s = $('ltSubj').textContent + '\n\n' + lastText;
    (navigator.clipboard ? navigator.clipboard.writeText(s) : Promise.reject()).then(function () { toast('Copied. Paste it in WhatsApp or anywhere.'); }, function () { toast('Could not copy'); });
  };
  $('ltPrint').onclick = function () {
    var w = window.open('', '_blank');
    if (!w) { toast('Allow pop-ups to print'); return; }
    w.document.write(lastHtml.replace('</body>', '<script>window.onload=function(){window.print()}<\/script></body>'));
    w.document.close();
  };
  /* signatures: pick one for the letter, manage them in the Signatures window */
  function sigOptions(keepId) {
    var sel = $('ltSig'), pick = keepId != null ? keepId : sel.value;
    var def = SIGS.filter(function (s) { return +s.is_default; })[0];
    sel.innerHTML = '<option value="">No signature (just the name)</option>' + SIGS.map(function (s) {
      return '<option value="' + s.id + '">' + esc(s.name + (s.designation ? ' · ' + s.designation : '')) + (+s.is_default ? ' (default)' : '') + '</option>';
    }).join('');
    sel.value = pick && SIGS.some(function (s) { return String(s.id) === String(pick); }) ? pick : (def ? def.id : '');
  }
  function sigList() {
    $('sigList').innerHTML = SIGS.length ? SIGS.map(function (s) {
      return '<div class="sig-card"><img src="../uploads/signatures/' + encodeURIComponent(s.file) + '" alt=""><b>' + esc(s.name) + '</b>' + (s.designation ? '<small>' + esc(s.designation) + '</small>' : '') + (s.extra ? '<small>' + esc(s.extra) + '</small>' : '')
        + '<div class="row">' + (+s.is_default ? '<span class="pill sent">Default</span>' : '<button class="btn light sm" type="button" data-sigdef="' + s.id + '">Make default</button>')
        + '<button class="btn light sm" type="button" data-sigdel="' + s.id + '">Delete</button></div></div>';
    }).join('') : '<p style="color:var(--muted);font-size:14px;margin:0">No signatures yet. Make one on the right: draw it, type it or upload a photo.</p>';
  }
  sigOptions();
  $('ltSig').addEventListener('change', preview);
  var sigDlg = $('sigDlg'), pad = $('sigPad'), ctx = pad.getContext('2d'), mode = 'draw', drawn = false;
  // drawing: every stroke is kept (so Undo and a resize can draw it again), smoothed through the midpoints,
  // a little thinner when the pen moves fast, like real ink; the canvas matches the screen's pixels so it stays sharp
  var strokes = [], stroke = null;
  function sigMsg(t, bad) { var m = $('sigMsg'); m.textContent = t || ''; m.style.color = bad ? 'var(--bad)' : 'var(--ok)'; }
  function padSize() {
    var r = pad.getBoundingClientRect();
    if (!r.width) return;
    var dpr = Math.min(window.devicePixelRatio || 1, 2), w = Math.min(1400, Math.round(r.width * dpr)), h = Math.round(w / 3);
    if (pad.width !== w) { pad.width = w; pad.height = h; if (mode === 'draw') redraw(); else if (mode === 'type') typeDraw(); }
  }
  function padClear() { ctx.clearRect(0, 0, pad.width, pad.height); drawn = false; }
  function padPos(e) { var r = pad.getBoundingClientRect(); return { x: (e.clientX - r.left) / r.width, y: (e.clientY - r.top) / r.height, t: e.timeStamp, p: e.pointerType === 'pen' && e.pressure ? e.pressure : 0.5 }; }
  function seg(s, i) {
    // the curve from the midpoint before point i to the midpoint after it, with point i as the control
    var W = pad.width, H = pad.height, a = s.pts[i - 1], b = s.pts[i], c = s.pts[i + 1] || b;
    var dist = Math.hypot((b.x - a.x) * W, (b.y - a.y) * H), dt = Math.max(1, b.t - a.t), speed = dist / dt;
    var base = W / 220, width = Math.max(base * 0.45, Math.min(base * 1.25, base * (1.25 - Math.min(speed, 3) * 0.22) * (0.6 + b.p * 0.8)));
    s.w = s.w == null ? width : s.w * 0.6 + width * 0.4;
    ctx.lineWidth = s.w; ctx.strokeStyle = s.ink; ctx.lineCap = ctx.lineJoin = 'round';
    ctx.beginPath();
    ctx.moveTo((a.x + b.x) / 2 * W, (a.y + b.y) / 2 * H);
    ctx.quadraticCurveTo(b.x * W, b.y * H, (b.x + c.x) / 2 * W, (b.y + c.y) / 2 * H);
    ctx.stroke();
  }
  function dot(s) { var a = s.pts[0]; ctx.fillStyle = s.ink; ctx.beginPath(); ctx.arc(a.x * pad.width, a.y * pad.height, pad.width / 300, 0, 7); ctx.fill(); }
  function redraw() {
    padClear();
    strokes.forEach(function (s) { s.w = null; if (s.pts.length < 2) dot(s); else for (var i = 1; i < s.pts.length; i++) seg(s, i); });
    drawn = strokes.length > 0;
  }
  pad.addEventListener('pointerdown', function (e) {
    if (mode !== 'draw') return;
    e.preventDefault();
    pad.setPointerCapture(e.pointerId);
    stroke = { ink: $('sigInk').value, pts: [padPos(e)], w: null };
    strokes.push(stroke);
    dot(stroke); drawn = true; sigMsg('');
  });
  pad.addEventListener('pointermove', function (e) {
    if (mode !== 'draw' || !stroke) return;
    var evs = e.getCoalescedEvents ? e.getCoalescedEvents() : [e];
    (evs.length ? evs : [e]).forEach(function (ev) {
      var p = padPos(ev), q = stroke.pts[stroke.pts.length - 1];
      if (Math.hypot((p.x - q.x) * pad.width, (p.y - q.y) * pad.height) < 1.5) return;
      stroke.pts.push(p);
      if (stroke.pts.length > 2) seg(stroke, stroke.pts.length - 2);
    });
  });
  ['pointerup', 'pointercancel'].forEach(function (ev) {
    pad.addEventListener(ev, function () {
      if (stroke && stroke.pts.length > 1) seg(stroke, stroke.pts.length - 1);   // the last bit up to the pen
      stroke = null;
    });
  });
  window.addEventListener('resize', function () { if (sigDlg.open) padSize(); });
  function typeDraw() {
    var txt = $('sigTypeText').value.trim(), f = $('sigFont').value;
    padClear();
    if (!txt) return;
    document.fonts.load('96px "' + f + '"').then(function () {
      padClear();
      var size = Math.round(pad.height * 0.42);
      ctx.font = size + 'px "' + f + '"';
      while (ctx.measureText(txt).width > pad.width * 0.92 && size > 20) { size -= 4; ctx.font = size + 'px "' + f + '"'; }
      ctx.fillStyle = $('sigInk').value; ctx.textBaseline = 'middle';
      ctx.fillText(txt, pad.width * 0.04, pad.height / 2);
      drawn = true;
    });
  }
  $('sigTypeText').addEventListener('input', function () { if (!$('sigName').dataset.touched) $('sigName').value = this.value; typeDraw(); });
  $('sigFont').addEventListener('change', typeDraw);
  $('sigInk').addEventListener('change', function () { if (mode === 'type') typeDraw(); });
  $('sigName').addEventListener('input', function () { this.dataset.touched = '1'; });
  $('sigFile').addEventListener('change', function () {
    var f = this.files && this.files[0];
    if (!f) return;
    if (f.size > 8 * 1024 * 1024) { toast('That photo is too big'); return; }
    var img = new Image();
    img.onload = function () {
      padClear();
      var s = Math.min(pad.width / img.width, pad.height / img.height) * 0.95, w = img.width * s, h = img.height * s;
      ctx.drawImage(img, (pad.width - w) / 2, (pad.height - h) / 2, w, h);
      // white paper becomes see-through, so it sits nicely on the email
      var d = ctx.getImageData(0, 0, pad.width, pad.height), a = d.data;
      for (var i = 0; i < a.length; i += 4) { var lum = (a[i] + a[i + 1] + a[i + 2]) / 3; if (lum > 200) a[i + 3] = 0; else if (lum > 150) a[i + 3] = Math.round(a[i + 3] * (200 - lum) / 50); }
      ctx.putImageData(d, 0, 0);
      drawn = true;
      URL.revokeObjectURL(img.src);
    };
    img.src = URL.createObjectURL(f);
  });
  document.querySelectorAll('[data-sigmode]').forEach(function (b) {
    b.addEventListener('click', function () {
      mode = b.dataset.sigmode;
      document.querySelectorAll('[data-sigmode]').forEach(function (x) { x.classList.toggle('on', x === b); });
      $('sigDrawBar').hidden = mode === 'upload'; $('sigTypeBar').hidden = mode !== 'type'; $('sigUpBar').hidden = mode !== 'upload';
      pad.style.cursor = mode === 'draw' ? 'crosshair' : 'default';
      strokes = []; padClear(); sigMsg('');
      if (mode === 'type') typeDraw();
    });
  });
  $('sigClear').onclick = function () { strokes = []; padClear(); if (mode === 'type') $('sigTypeText').value = ''; };
  $('sigUndo').onclick = function () { if (mode === 'draw') { strokes.pop(); redraw(); } };
  function trimmed() {
    // only the inked part, with a little room around it
    var d = ctx.getImageData(0, 0, pad.width, pad.height).data, x0 = pad.width, y0 = pad.height, x1 = -1, y1 = -1;
    for (var y = 0; y < pad.height; y++) for (var x = 0; x < pad.width; x++) if (d[(y * pad.width + x) * 4 + 3] > 20) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
    if (x1 < 0) return null;
    var m = 12; x0 = Math.max(0, x0 - m); y0 = Math.max(0, y0 - m); x1 = Math.min(pad.width - 1, x1 + m); y1 = Math.min(pad.height - 1, y1 + m);
    var c2 = document.createElement('canvas'); c2.width = x1 - x0 + 1; c2.height = y1 - y0 + 1;
    c2.getContext('2d').drawImage(pad, x0, y0, c2.width, c2.height, 0, 0, c2.width, c2.height);
    return c2.toDataURL('image/png');
  }
  $('sigSave').onclick = function () {
    var img = drawn ? trimmed() : null;
    if (!img) { sigMsg(mode === 'upload' ? 'Choose a photo of your signature first.' : mode === 'type' ? 'Type your signature first.' : 'Sign in the box first.', true); return; }
    sigMsg('Saving…');
    post({ action: 'sigSave', image: img, name: $('sigName').value, designation: $('sigDesig').value, extra: $('sigExtra').value, is_default: $('sigDef').checked }, this).then(function (d) {
      if (!d.ok) { sigMsg(d.message || 'Not saved', true); return; }
      sigMsg('Saved ✓ It is now in the list on the left and in the Signature box of every letter.');
      SIGS = d.sigs;
      var newest = SIGS.reduce(function (a, s) { return +s.id > +a ? +s.id : a; }, 0);
      sigList(); sigOptions(newest); preview();
      strokes = []; padClear(); $('sigTypeText').value = ''; $('sigName').value = ''; delete $('sigName').dataset.touched; $('sigDesig').value = ''; $('sigExtra').value = ''; $('sigDef').checked = false; $('sigFile').value = '';
    });
  };
  $('sigList').addEventListener('click', function (e) {
    var b = e.target.closest('[data-sigdef],[data-sigdel]');
    if (!b) return;
    if (b.dataset.sigdef) post({ action: 'sigDefault', id: +b.dataset.sigdef }, b).then(function (d) { if (d.ok) { SIGS = d.sigs; sigList(); sigOptions(); preview(); } });
    else {
      var id = +b.dataset.sigdel;
      post({ action: 'sigDelete', id: id }, b).then(function (d) {
        if (!d.ok) return;
        SIGS = d.sigs; sigList(); sigOptions(); preview();
        undoToast('Signature deleted', function () { post({ action: 'sigRestore', id: id }).then(function (r) { if (r.ok) { SIGS = r.sigs; sigList(); sigOptions(); preview(); } }); });
      });
    }
  });
  function sigOpen() { sigList(); sigMsg(''); sigDlg.showModal(); requestAnimationFrame(padSize); }
  $('ltSigBtn').onclick = sigOpen;
  $('sigClose').onclick = function () { sigDlg.close(); };

  var want = +(new URLSearchParams(location.search).get('open') || 0);
  try { want = want || +(localStorage.getItem('rgLetterOpen') || 0); } catch (e) {}
  open(L.some(function (t) { return t.id === want; }) ? want : (L[0] ? L[0].id : 0));
})();

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
