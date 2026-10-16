<?php
/**
 * Email book (Super Admin › Tools): every automatic email as it really arrives, grouped by who gets it,
 * with when and why it is sent, plus what is still needed to switch sending on. Made live from the same
 * code that sends the emails (config/mail_catalog.php, sample restaurant "Spice Garden"), so it is never
 * out of date. Test sends, the sent log and on/off switches are on the Email page (email.php).
 */
require_once __DIR__ . '/auth.php';
require_superadmin();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/mail_catalog.php';

$conn = getConnection();
$mails = array_map(fn($m) => array_intersect_key($m, array_flip(['key', 'type', 'name', 'variant', 'who', 'when', 'how', 'subject', 'from', 'html'])), mailPreviewAll($conn));
$kinds = count(array_unique(array_column($mails, 'type')));
$off = [];
$cron = [];
try {
    $off = json_decode((string)$conn->query("SELECT v FROM sa_settings WHERE k = 'email_off'")->fetchColumn(), true) ?: [];
    foreach (['cron_email_dispatch_at' => 30, 'cron_owner_brief_at' => 45] as $k => $min) {
        $s = $conn->prepare('SELECT v FROM sa_settings WHERE k = ?');
        $s->execute([$k]);
        $v = (string)$s->fetchColumn();
        $cron[$k] = $v !== '' && strtotime($v) > time() - $min * 60;
    }
} catch (Throwable $e) { /* sa_settings not made yet: everything shows as not done */ }
$sink = trim((string)env('MAIL_SINK_DIR', ''));
$sending = SMTP_ENABLED && $sink === '';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$steps = [
    [$sending, 'Sending switched on', $sending ? 'SMTP is on (' . SMTP_FROM_EMAIL . ').' : ($sink !== '' ? 'Test mode on this computer: emails are saved as files (MAIL_SINK_DIR).' : 'In main/.env set SMTP_ENABLED=true, your Workspace address and an App Password, then Email › Settings & jobs › Send test.')],
    [null, 'SPF, DKIM and DMARC for restrogrow.com', 'So emails land in the inbox, not spam. Steps are in My to-do.'],
    [!empty($cron['cron_email_dispatch_at']), 'Email cron (every 10 min)', '/usr/bin/php <HOME>/main/cron/email_dispatch.php: reminders, receipts, gift emails, retries.'],
    [!empty($cron['cron_owner_brief_at']), 'Morning report cron (every 15 min)', '/usr/bin/php <HOME>/main/cron/owner_brief.php: the AI manager report.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Email book · Super Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Figtree:wght@400;500;600;700&family=JetBrains+Mono:wght@500&display=swap">
<style>
:root {
  --bg: #eef0ee; --panel: #ffffff; --ink: #1c211f; --soft: #4a524e; --muted: #727b76; --line: #d9dedb;
  --chip: #e3e7e4; --accent: #c1440e; --accent-ink: #ffffff; --accent-soft: #fbe6da;
  --ok: #1f7a46; --ok-soft: #dcf0e3; --todo: #9a5b00; --todo-soft: #fbecd2;
  --display: 'Bricolage Grotesque', 'Segoe UI', system-ui, sans-serif;
  --body: 'Figtree', 'Segoe UI', system-ui, sans-serif;
  --mono: 'JetBrains Mono', ui-monospace, Consolas, monospace;
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--ink); font-family: var(--body); font-size: 15px; line-height: 1.5; }
.wrap { max-width: 1160px; margin: 0 auto; padding: 24px 16px 64px; display: grid; gap: 26px; }
.top { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; font-size: 14px; }
.top a { color: var(--muted); text-decoration: none; } .top a:hover { color: var(--ink); }
.top a.go { color: var(--accent); font-weight: 700; }
h1, h2, h3 { font-family: var(--display); text-wrap: balance; margin: 0; }
header { display: grid; gap: 10px; }
.eyebrow { font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--accent); }
h1 { font-size: clamp(30px, 5vw, 44px); font-weight: 800; letter-spacing: -.02em; line-height: 1.05; }
.lede { color: var(--soft); max-width: 68ch; margin: 0; font-size: 16px; }
.counts { display: flex; flex-wrap: wrap; gap: 8px 22px; font-size: 14px; color: var(--muted); }
.counts b { color: var(--ink); font-family: var(--display); font-size: 20px; margin-right: 4px; font-variant-numeric: tabular-nums; }
.status { background: var(--panel); border: 1px solid var(--line); border-radius: 14px; padding: 18px 20px; display: grid; gap: 12px; }
.status h2 { font-size: 18px; font-weight: 800; }
.steps { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px; }
.step { border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px; display: grid; gap: 4px; align-content: start; min-width: 0; }
.step b { font-size: 14.5px; }
.step p { margin: 0; color: var(--soft); font-size: 13px; overflow-wrap: anywhere; }
.tag { justify-self: start; font-size: 12px; font-weight: 700; padding: 2px 9px; border-radius: 999px; }
.tag.ok { background: var(--ok-soft); color: var(--ok); } .tag.todo { background: var(--todo-soft); color: var(--todo); }
.tools { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; position: sticky; top: 0; z-index: 3; background: var(--bg); padding-block: 10px; }
.chip { font: inherit; font-size: 14px; font-weight: 600; padding: 8px 14px; border-radius: 999px; border: 1px solid var(--line); background: var(--panel); color: var(--soft); cursor: pointer; }
.chip[aria-pressed="true"] { background: var(--ink); color: var(--bg); border-color: var(--ink); }
.chip:focus-visible, .open:focus-visible, input:focus-visible, .close:focus-visible, .thumb:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
#q { flex: 1 1 220px; min-width: 0; font: inherit; padding: 9px 14px; border-radius: 999px; border: 1px solid var(--line); background: var(--panel); color: var(--ink); }
.group { display: grid; gap: 14px; margin-bottom: 26px; }
.group-h { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; border-bottom: 2px solid var(--ink); padding-bottom: 8px; }
.group-h h2 { font-size: 24px; font-weight: 800; }
.group-h span { color: var(--muted); font-size: 14px; }
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 520px), 1fr)); gap: 14px; }
.card { background: var(--panel); border: 1px solid var(--line); border-radius: 14px; display: grid; grid-template-columns: 210px 1fr; overflow: hidden; min-width: 0; transition: border-color .15s; }
.card:hover { border-color: var(--accent); }
.card.isoff { opacity: .6; }
.thumb { position: relative; height: 300px; overflow: hidden; background: #f5efe2; border-right: 1px solid var(--line); cursor: zoom-in; }
.thumb iframe { position: absolute; top: 0; left: 0; width: 600px; height: 1000px; border: 0; transform: scale(.35); transform-origin: 0 0; pointer-events: none; }
.thumb::after { content: ""; position: absolute; inset: auto 0 0 0; height: 46px; background: linear-gradient(transparent, #f5efe2); }
.info { padding: 16px 18px; display: grid; gap: 9px; align-content: start; min-width: 0; }
.info h3 { font-size: 17px; font-weight: 800; line-height: 1.25; }
.variant, .offtag { display: inline-block; margin-left: 6px; font-family: var(--body); font-size: 12px; font-weight: 700; padding: 2px 8px; border-radius: 999px; vertical-align: 2px; }
.variant { color: var(--accent); background: var(--accent-soft); } .offtag { color: var(--muted); background: var(--chip); }
dl { margin: 0; display: grid; grid-template-columns: 52px 1fr; gap: 6px 10px; font-size: 13.5px; }
dt { color: var(--muted); font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; padding-top: 2px; }
dd { margin: 0; color: var(--soft); min-width: 0; overflow-wrap: anywhere; }
.subject { font-size: 13.5px; background: var(--chip); border-radius: 9px; padding: 8px 10px; overflow-wrap: anywhere; }
.subject small { display: block; color: var(--muted); font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
.open { justify-self: start; font: inherit; font-size: 13.5px; font-weight: 700; padding: 8px 14px; border-radius: 9px; border: 0; background: var(--accent); color: var(--accent-ink); cursor: pointer; }
.empty { color: var(--muted); padding: 30px 0; text-align: center; }
footer { color: var(--muted); font-size: 13px; max-width: 72ch; }
code { font-family: var(--mono); font-size: 12px; background: var(--chip); padding: 1px 6px; border-radius: 5px; }
dialog { width: min(660px, calc(100vw - 24px)); height: min(90vh, 960px); padding: 0; border: 0; border-radius: 16px; overflow: hidden; background: var(--panel); color: var(--ink); }
dialog::backdrop { background: rgba(10, 14, 12, .6); }
.dlg-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 14px; border-bottom: 1px solid var(--line); }
.dlg-top div { min-width: 0; } .dlg-top b { display: block; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dlg-top small { color: var(--muted); font-size: 12.5px; }
.close { font: inherit; font-weight: 700; font-size: 13.5px; padding: 7px 13px; border-radius: 9px; border: 1px solid var(--line); background: var(--panel); color: var(--ink); cursor: pointer; }
dialog iframe { width: 100%; height: calc(100% - 58px); border: 0; background: #f5efe2; display: block; }
@media (max-width: 600px) {
  .card { grid-template-columns: 1fr; }
  .thumb { height: 230px; border-right: 0; border-bottom: 1px solid var(--line); }
  .thumb iframe { width: 200%; height: 200%; transform: scale(.5); }
  .tools { position: static; }
}
</style>
</head>
<body>
<div class="wrap">
  <div class="top"><a href="dashboard.php">← Back to dashboard</a><a class="go" href="email.php">Test sends, sent log and on/off switches → Email</a></div>
  <header>
    <div class="eyebrow">RestroGrow · automatic emails</div>
    <h1>Email book: every email we send, exactly as it arrives</h1>
    <p class="lede">Made live from the code that sends them, filled with a sample restaurant (Spice Garden) and sample customers. Real emails carry the restaurant's own name, logo, phone and order. Tap an email to see it full size.</p>
    <div class="counts"><span><b><?php echo count($mails); ?></b>email previews</span><span><b><?php echo $kinds; ?></b>kinds of email</span><span><b>3</b>audiences</span><?php if ($off): ?><span><b><?php echo count($off); ?></b>switched off</span><?php endif; ?></div>
  </header>

  <section class="status" aria-label="Setup">
    <h2>To switch emails on for real</h2>
    <div class="steps">
      <?php foreach ($steps as [$done, $title, $text]): ?>
        <div class="step"><span class="tag <?php echo $done ? 'ok' : 'todo'; ?>"><?php echo $done ? 'Done' : ($done === null ? 'Check' : 'To do'); ?></span><b><?php echo $h($title); ?></b><p><?php echo $h($text); ?></p></div>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="tools" role="toolbar" aria-label="Filter emails">
    <button class="chip" type="button" data-who="" aria-pressed="true">All</button>
    <button class="chip" type="button" data-who="owner" aria-pressed="false">Restaurant owners</button>
    <button class="chip" type="button" data-who="team" aria-pressed="false">RestroGrow team</button>
    <button class="chip" type="button" data-who="customer" aria-pressed="false">Customers</button>
    <input id="q" type="search" placeholder="Search: trial, order, birthday…" aria-label="Search emails">
  </div>

  <div id="list"></div>

  <footer>Sent through Google Workspace SMTP (up to 2,000 emails a day). Owner emails come from "RestroGrow"; customer emails come from "<i>Restaurant name</i> via RestroGrow", and replies go to the restaurant. Password-reset links are never kept in the log. Code: <code>main/config/mail_engine.php</code>, previews: <code>main/config/mail_catalog.php</code>, plan: <code>docs/EMAIL_PLAN.md</code>.</footer>
</div>

<dialog id="dlg" aria-labelledby="dlgT">
  <div class="dlg-top"><div><b id="dlgT"></b><small id="dlgS"></small></div><button class="close" type="button" id="dlgClose">Close</button></div>
  <iframe id="dlgF" title="Email full size" sandbox></iframe>
</dialog>

<script>
var MAILS = <?php echo json_encode($mails, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
var OFF = <?php echo json_encode(array_values($off)); ?>;
var GROUPS = [
  ['owner', 'To restaurant owners', 'sent from RestroGrow'],
  ['team', 'To the RestroGrow team and website visitors', 'leads, signups, contact-form replies'],
  ['customer', "To the restaurant's customers", "sent in the restaurant's name"]
];
function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
var who = '', q = '';
function render() {
  var out = '', shown = 0;
  GROUPS.forEach(function (g) {
    var items = MAILS.filter(function (m) {
      if (m.who !== g[0] || (who && who !== m.who)) return false;
      return !q || (m.name + ' ' + m.variant + ' ' + m.subject + ' ' + m.when + ' ' + m.how).toLowerCase().indexOf(q) !== -1;
    });
    if (!items.length) return;
    shown += items.length;
    out += '<section class="group"><div class="group-h"><h2>' + esc(g[1]) + '</h2><span>' + items.length + ' · ' + esc(g[2]) + '</span></div><div class="cards">' +
      items.map(function (m) {
        var off = OFF.indexOf(m.type) !== -1;
        return '<article class="card' + (off ? ' isoff' : '') + '"><div class="thumb" data-key="' + esc(m.key) + '" role="button" tabindex="0" aria-label="Open ' + esc(m.name) + ' full size"><iframe sandbox tabindex="-1" loading="lazy" title="' + esc(m.name) + '" data-src="' + esc(m.key) + '"></iframe></div>' +
          '<div class="info"><h3>' + esc(m.name) + (m.variant ? '<span class="variant">' + esc(m.variant) + '</span>' : '') + (off ? '<span class="offtag">switched off</span>' : '') + '</h3>' +
          '<dl><dt>When</dt><dd>' + esc(m.when) + '</dd><dt>Why</dt><dd>' + esc(m.how) + '</dd><dt>From</dt><dd>' + esc(m.from) + '</dd></dl>' +
          '<div class="subject"><small>Subject</small>' + esc(m.subject) + '</div>' +
          '<button class="open" type="button" data-key="' + esc(m.key) + '">View full size</button></div></article>';
      }).join('') + '</div></section>';
  });
  var list = document.getElementById('list');
  list.innerHTML = shown ? out : '<p class="empty">No email matches "' + esc(q) + '".</p>';
  list.querySelectorAll('iframe[data-src]').forEach(function (f) {
    var m = MAILS.filter(function (x) { return x.key === f.getAttribute('data-src'); })[0];
    if (m) f.srcdoc = m.html;
  });
}
render();
document.querySelectorAll('.chip').forEach(function (c) {
  c.addEventListener('click', function () {
    who = c.getAttribute('data-who');
    document.querySelectorAll('.chip').forEach(function (x) { x.setAttribute('aria-pressed', x === c ? 'true' : 'false'); });
    render();
  });
});
document.getElementById('q').addEventListener('input', function (e) { q = e.target.value.trim().toLowerCase(); render(); });
var dlg = document.getElementById('dlg');
function openMail(key) {
  var m = MAILS.filter(function (x) { return x.key === key; })[0];
  if (!m) return;
  document.getElementById('dlgT').textContent = m.subject;
  document.getElementById('dlgS').textContent = m.name + (m.variant ? ' · ' + m.variant : '') + ' · from ' + m.from;
  document.getElementById('dlgF').srcdoc = m.html;
  dlg.showModal();
}
document.addEventListener('click', function (e) { var t = e.target.closest('[data-key]'); if (t) openMail(t.getAttribute('data-key')); });
document.addEventListener('keydown', function (e) {
  var t = e.target.closest && e.target.closest('.thumb');
  if (t && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openMail(t.getAttribute('data-key')); }
});
document.getElementById('dlgClose').addEventListener('click', function () { dlg.close(); });
dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
</script>
</body>
</html>
