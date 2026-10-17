<?php
/**
 * My to-do (Super Admin › Tools): the jobs only the owner can do (keys, approvals, cron, testing on a phone),
 * kept where they can't be forgotten. Tick to mark done, add your own, delete. Saved in sa_todos.
 * The first visit fills it with the open jobs from the work done so far (docs/IMPORTANT_TODO.md, the handbook).
 */
require_once __DIR__ . '/auth.php';
require_superadmin();
require_once __DIR__ . '/../db_connection.php';

$conn = getConnection();
$conn->exec("CREATE TABLE IF NOT EXISTS sa_todos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(40) NOT NULL DEFAULT 'Other',
    title VARCHAR(255) NOT NULL,
    details TEXT NULL,
    urgent TINYINT NOT NULL DEFAULT 0,
    done TINYINT NOT NULL DEFAULT 0,
    done_at DATETIME NULL,
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// first visit: the open jobs so far
if ((int)$conn->query('SELECT COUNT(*) FROM sa_todos')->fetchColumn() === 0) {
    $seed = [
        ['Security', 1, 'Change the live database password', "It was pasted in a chat. Hostinger › Databases › change the password of u509616587_restrogrow, then put the new one in DB_PASS in the live main/.env (File Manager). Check the site still loads."],
        ['Publish', 1, 'Say "push" when ready', "Local commits are waiting (WhatsApp ordering, AI manager, feature list, this to-do). Nothing goes live until you say push."],
        ['Test', 0, 'Taste of बनारस website order', "Ask them to retry an order on tasteofbanaras.in. If it fails, note the Ref number on the error (it is saved in Super Admin › Error monitor) and send it."],
        ['Settings', 0, 'Fill business details for invoices', "Super Admin › Settings: business name, address, GSTIN, UPI ID and bank details. Without GSTIN invoices say \"Invoice\" not \"Tax Invoice\"; without UPI there is no pay-by-QR."],
        ['WhatsApp ordering', 0, 'RepeatGrow: allow two more permissions for RestroGrow', "In RepeatGrow, let the RestroGrow Connect client use webhooks:manage and contacts:read. Then add REPEATGROW_CONNECT_ORDERING=1 to the live main/.env. Each restaurant reconnects once (Growth › WhatsApp)."],
        ['WhatsApp ordering', 0, 'Check GEMINI_API_KEY in the live .env', "Used by WhatsApp ordering and the AI manager (and the website chatbot). If the chatbot works live, it's already there."],
        ['WhatsApp ordering', 0, 'Optional: shared RestroGrow ordering number', "For restaurants without their own WhatsApp number: set WA_ORDER_SHARED_NUMBER and REPEATGROW_WEBHOOK_SECRET in .env, and register https://restrogrow.com/main/api/wa_webhook.php?rid=platform as a webhook (message.received) on that RepeatGrow account."],
        ['AI manager', 0, 'Get a "daily report" WhatsApp template approved', "So the morning report arrives even when the owner hasn't messaged in the last 24 hours. Until then it is sent as normal text."],
        ['Cron jobs', 0, 'Add the cron jobs on Hostinger', "hPanel › Advanced › Cron Jobs:\n• every 15 min: /usr/bin/php /home/<user>/public_html/main/cron/owner_brief.php (AI manager)\n• every 5 min: /usr/bin/php /home/<user>/public_html/main/cron/whatsapp_dispatch.php (WhatsApp sending)\n• every 5 min: /usr/bin/php /home/<user>/public_html/main/cron/activate_scheduled_orders.php (scheduled orders)\n• once a day: /usr/bin/php /home/<user>/public_html/main/cron/loyalty_autopilot.php (birthday / we-miss-you gifts)\n• once a day: /usr/bin/php /home/<user>/public_html/main/cron/generate_subscription_deliveries.php (meal plans)\n(reset_demo_restaurant: you said no cron, reset it from Super Admin.)"],
        ['Keys & approvals', 0, 'Google Business keys after Google approves', "Add GOOGLE_GBP_CLIENT_ID and GOOGLE_GBP_CLIENT_SECRET to the live .env once the Business Profile API is approved."],
        ['Keys & approvals', 0, 'PhonePe v2 keys', "Online payments need the PhonePe v2 credentials in .env (the local .env only has the old v1 names)."],
        ['Keys & approvals', 0, 'Phone wallet card credentials', "Apple / Google Wallet loyalty card needs its credentials before restaurants can switch it on."],
        ['Later', 0, 'Contact UrbanPiper (or a similar partner) for Swiggy / Zomato', "Feature 4 is for later, but approvals take weeks. See docs/IMPORTANT_TODO.md #4."],
        ['Decide', 0, 'Decide on the fixes the feature check found', "Delete main/api/update_menu_items.php (unused, can wipe a menu), Reports payment filter, Auto-Pay switch, Super Admin CSV export, payment links not extending plans. Ask Claude to fix them. Full list: Super Admin › Features › Problems found."],
    ];
    $ins = $conn->prepare('INSERT INTO sa_todos (category, urgent, title, details, sort) VALUES (?, ?, ?, ?, ?)');
    foreach ($seed as $i => $t) $ins->execute([$t[0], $t[1], $t[2], $t[3], $i]);
}

// jobs added after the first visit (each added once, by its title)
$later = [
    ['Blog', 0, 'Plan keywords and write the first blog posts', "Super Admin › Tools › Blog › Keywords: add the searches you want to show up for (or Suggest with AI), then Write post on the top ones. AI drafts are a start: add your own examples and check every fact before publishing. Aim for one good post a week."],
    ['Blog', 0, 'Submit the blog sitemap to Google Search Console', "Search Console › Sitemaps › add https://restrogrow.com/sitemap-blog.xml (it is also listed in robots.txt). Then use URL inspection › Request indexing on each new post."],
    ['Publish', 1, 'Install the new apps on your phone and test them', "Desktop: RestroGrow-Partner-1.1.9.apk, RestroGrow-Staff-1.0.2.apk, RestroGrow-SuperAdmin-1.1.1.apk. Uninstall the old app first, then install. They fix the logo / black screen on start. Test: Partner app login + one POS bill; Staff app as waiter, chef, cashier and rider (demo logins: <role>.demo@spicegarden.test / demo). If anything goes black, plug the phone in with USB debugging and ask Claude to read the error."],
    ['Delivery', 0, 'Turn on own riders where a restaurant has its own delivery boys', "Settings › Delivery › \"We deliver with our own riders\" (off by default). Then Staff › add member › role Delivery rider. Riders use the Staff app; the owner assigns deliveries in Order details or Delivery › Riders and settles their cash there."],
    ['Delivery', 0, 'Test one real delivery with a rider on the live site', "On a test restaurant (or the demo): turn on own riders, add a rider, place a cash delivery order on the website, accept it, assign the rider, then in the Staff app as the rider: Picked up → Start the ride → collect cash → Delivered. Check the customer's tracking page shows the rider on the map, and Settle cash in Delivery › Riders."],
    ['Demo', 0, 'Reset the demo restaurant on the live site', "Super Admin › Tools › Demo restaurant › Reset demo, so the live demo gets the new riders (rider.demo@spicegarden.test), the cashier (cashier.demo@spicegarden.test) and own riders switched on."],
    ['Staff', 0, 'Add cashiers where the counter person needs billing only', "Staff › add member › role Cashier: orders (incl. online), POS, payments, customers, tables, riders' cash; no reports, menu, staff or settings."],
    ['Email', 1, 'Turn on emails in the live .env', "Live main/.env: SMTP_ENABLED=true, SMTP_HOST=smtp.gmail.com, SMTP_PORT=587, SMTP_SECURE=tls, SMTP_USERNAME=<your Workspace address>, SMTP_PASSWORD=<an App Password: Google account › Security › 2-Step Verification › App passwords>, SMTP_FROM_EMAIL=<same address>, SMTP_FROM_NAME=RestroGrow. Optional: MAIL_TEAM=<where new leads and signups go>. Then Super Admin › Email › Send test."],
    ['Email', 0, 'SPF, DKIM and DMARC for restrogrow.com', "So emails don't land in spam. In your domain's DNS (Hostinger › Domains › DNS):
• SPF (TXT on @): v=spf1 include:_spf.google.com ~all  (if an SPF record exists, add include:_spf.google.com to it, don't make a second one)
• DKIM: Google Admin › Apps › Google Workspace › Gmail › Authenticate email › Generate new record, add the TXT it shows (google._domainkey), then click Start authentication
• DMARC (TXT on _dmarc): v=DMARC1; p=none; rua=mailto:<your address>
Check after a day with Super Admin › Email › Send test to a Gmail address › Show original: SPF, DKIM, DMARC should say PASS."],
    ['Email', 0, 'Optional: separate sender addresses', "In Google Admin add aliases (billing@, orders@, support@ …) to your Workspace user, then in the live .env: MAIL_FROM_BILLING, MAIL_FROM_ORDERS, MAIL_FROM_ALERTS, MAIL_FROM_ACCOUNTS, MAIL_FROM_SUPPORT, MAIL_FROM_SALES. Without them everything comes from SMTP_FROM_EMAIL, which is fine."],
    ['Cron jobs', 1, 'Add the 2 cron jobs for emails and the morning report', "hPanel › Advanced › Cron Jobs › Custom (<HOME> = the folder shown in File Manager):
• every 10 minutes: /usr/bin/php <HOME>/main/cron/email_dispatch.php  (trial / renewal reminders, payment receipts, gift emails, booking reminders, retrying failed emails)
• every 15 minutes: /usr/bin/php <HOME>/main/cron/owner_brief.php  (AI manager morning report on WhatsApp / email)
Then open Super Admin › Email › Settings & jobs: both should say Running within 15 minutes. Without them, reminders only go out while someone has a dashboard open."],
];
$have = $conn->prepare('SELECT COUNT(*) FROM sa_todos WHERE title = ?');
$addLater = $conn->prepare('INSERT INTO sa_todos (category, urgent, title, details, sort) VALUES (?, ?, ?, ?, ?)');
foreach ($later as $i => $t) { $have->execute([$t[2]]); if (!(int)$have->fetchColumn()) $addLater->execute([$t[0], $t[1], $t[2], $t[3], 100 + $i]); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    // same-site only (the page's own fetch); stops another site posting with the admin's cookie
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '')) { http_response_code(403); echo '{"ok":false}'; exit; }
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($in['id'] ?? 0);
    switch ($in['action'] ?? '') {
        case 'toggle':
            $conn->prepare('UPDATE sa_todos SET done = 1 - done, done_at = IF(done = 0, NOW(), NULL) WHERE id = ?')->execute([$id]);
            break;
        case 'add':
            $title = trim(mb_substr((string)($in['title'] ?? ''), 0, 255));
            if ($title === '') { echo '{"ok":false}'; exit; }
            $conn->prepare('INSERT INTO sa_todos (category, title, details, urgent, sort) VALUES (?, ?, ?, ?, 999)')
                ->execute([trim(mb_substr((string)($in['category'] ?? ''), 0, 40)) ?: 'Other', $title, trim(mb_substr((string)($in['details'] ?? ''), 0, 4000)) ?: null, empty($in['urgent']) ? 0 : 1]);
            break;
        case 'delete':
            $conn->prepare('DELETE FROM sa_todos WHERE id = ?')->execute([$id]);
            break;
    }
    echo '{"ok":true}';
    exit;
}

$rows = $conn->query('SELECT * FROM sa_todos ORDER BY done, urgent DESC, sort, id')->fetchAll(PDO::FETCH_ASSOC);
$open = count(array_filter($rows, fn($r) => !$r['done']));
$cats = array_values(array_unique(array_column($rows, 'category')));
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>My to-do · Super Admin</title>
<style>
  :root { --ink:#111827; --soft:#374151; --muted:#6b7280; --line:#e5e7eb; --bg:#f3f4f6; --accent:#c2410c; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: var(--bg); color: var(--ink); }
  main { max-width: 860px; margin: 0 auto; padding: 24px 16px 60px; }
  .back { color: var(--muted); text-decoration: none; font-size: 14px; }
  h1 { margin: 12px 0 4px; font-size: 28px; }
  .sub { color: var(--muted); margin: 0 0 18px; font-size: 15px; }
  .bar { height: 10px; border-radius: 999px; background: #e5e7eb; overflow: hidden; margin-bottom: 20px; }
  .bar i { display: block; height: 100%; background: linear-gradient(90deg, #f97316, #16a34a); }
  .item { display: flex; gap: 12px; align-items: flex-start; padding: 14px 16px; margin-bottom: 10px; background: #fff; border-radius: 14px; border: 1px solid var(--line); box-shadow: 0 1px 2px rgba(0,0,0,.03); }
  .item.urgent { border-color: #fdba74; background: #fffbf5; }
  .item.done { opacity: .55; }
  .item.done .t { text-decoration: line-through; }
  .item input[type=checkbox] { width: 22px; height: 22px; margin-top: 2px; accent-color: #16a34a; flex: none; cursor: pointer; }
  .body { flex: 1; min-width: 0; }
  .t { font-weight: 700; font-size: 15.5px; line-height: 1.35; }
  .meta { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
  .tag { font-size: 11.5px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: #eef2ff; color: #3730a3; }
  .tag.u { background: #ffedd5; color: #9a3412; }
  .d { margin-top: 8px; color: var(--soft); font-size: 14px; line-height: 1.55; white-space: pre-wrap; word-break: break-word; }
  .x { border: 0; background: none; color: #9ca3af; font-size: 20px; cursor: pointer; padding: 0 4px; }
  .x:hover { color: #dc2626; }
  details.add { margin: 22px 0 12px; background: #fff; border-radius: 14px; border: 1px dashed #cbd5e1; padding: 12px 16px; }
  details.add summary { cursor: pointer; font-weight: 700; }
  .add form { display: grid; gap: 10px; margin-top: 12px; }
  .add input, .add textarea, .add select { width: 100%; padding: 10px 12px; border: 1.5px solid var(--line); border-radius: 10px; font: inherit; }
  .add button { justify-self: start; padding: 10px 18px; border: 0; border-radius: 10px; background: var(--ink); color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
  h2 { font-size: 13px; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); margin: 26px 0 10px; }
</style>
</head>
<body>
<main>
  <a class="back" href="dashboard.php">← Back to dashboard</a>
  <h1>My to-do</h1>
  <p class="sub"><?php echo $open; ?> left · things only you can do (keys, approvals, testing). Tick when done.</p>
  <div class="bar"><i style="width:<?php echo $rows ? round((count($rows) - $open) / count($rows) * 100) : 0; ?>%"></i></div>

  <?php $doneHeader = false; foreach ($rows as $r): if ($r['done'] && !$doneHeader): $doneHeader = true; ?><h2>Done</h2><?php endif; ?>
    <div class="item<?php echo $r['done'] ? ' done' : ''; echo $r['urgent'] && !$r['done'] ? ' urgent' : ''; ?>">
      <input type="checkbox" data-toggle="<?php echo (int)$r['id']; ?>"<?php echo $r['done'] ? ' checked' : ''; ?> aria-label="Done">
      <div class="body">
        <div class="t"><?php echo $h($r['title']); ?></div>
        <div class="meta"><span class="tag"><?php echo $h($r['category']); ?></span><?php if ($r['urgent'] && !$r['done']): ?><span class="tag u">Do first</span><?php endif; ?><?php if ($r['done_at']): ?><span class="tag" style="background:#dcfce7;color:#166534">Done <?php echo $h(date('j M', strtotime($r['done_at']))); ?></span><?php endif; ?></div>
        <?php if ($r['details']): ?><div class="d"><?php echo $h($r['details']); ?></div><?php endif; ?>
      </div>
      <button class="x" type="button" data-del="<?php echo (int)$r['id']; ?>" title="Delete" aria-label="Delete">×</button>
    </div>
  <?php endforeach; ?>

  <details class="add"><summary>+ Add something to remember</summary>
    <form id="addForm">
      <input name="title" maxlength="255" placeholder="What do you need to do?" required>
      <textarea name="details" rows="3" placeholder="Details (optional)"></textarea>
      <select name="category"><?php foreach (array_unique(array_merge($cats, ['Other'])) as $c): ?><option><?php echo $h($c); ?></option><?php endforeach; ?></select>
      <label style="display:flex;gap:8px;align-items:center;font-size:14px"><input type="checkbox" name="urgent" style="width:18px"> Do first</label>
      <button type="submit">Add</button>
    </form>
  </details>
</main>
<script>
function send(b) { return fetch('todo.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) }).then(function () { location.reload(); }); }
document.addEventListener('change', function (e) { var id = e.target.getAttribute('data-toggle'); if (id) send({ action: 'toggle', id: +id }); });
document.addEventListener('click', function (e) { var id = e.target.getAttribute('data-del'); if (id && confirm('Delete this item?')) send({ action: 'delete', id: +id }); });
document.getElementById('addForm').addEventListener('submit', function (e) {
  e.preventDefault(); var f = e.target;
  send({ action: 'add', title: f.title.value, details: f.details.value, category: f.category.value, urgent: f.urgent.checked ? 1 : 0 });
});
</script>
</body>
</html>
