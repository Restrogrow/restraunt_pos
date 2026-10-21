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
    ['Other countries', 1, 'Fix the settings of restaurants outside India (Royal Sekuwa & Khaja Ghar first)', "They signed up before country defaults existed, so they still run on India's clock and \"GST 5%\". For each (Super Admin › Restaurants, country not India): log in as them › Settings › Region and language › Timezone = their country's (Nepal: Asia/Kathmandu; the page now shows an orange warning) › Save. Then Tax and bill: Nepal uses VAT 13%, and Nepal law wants menu prices to already include VAT and service charge, so most Nepal restaurants should keep tax OFF and price their menu VAT-inclusive (ask them). Online payment (PhonePe) is now hidden for them; if they ask for online payments or renewal, handle it by WhatsApp."],
    ['Other countries', 0, 'Decide when to build the other-country fixes', "docs/IMPORTANT_TODO.md #6 lists what's left for restaurants outside India (Nepal first): their own time zone everywhere in reports and timers, Nepal VAT bills (VAT-inclusive prices, service charge, PAN, bill numbers per fiscal year, Nepali dates), WhatsApp links with the right country code, one customer record per person, Google showing NPR, Nepal festivals, Nepali language, a way for them to pay you, eSewa/Khalti. Tell Claude 'build #6' (or one row of it) when you're ready."],
    ['Blog', 0, 'Plan keywords and write the first blog posts', "Super Admin › Tools › Blog › Keywords: add the searches you want to show up for (or Suggest with AI), then Write post on the top ones. AI drafts are a start: add your own examples and check every fact before publishing. Aim for one good post a week."],
    ['Blog', 1, 'Add the 10 starter blog posts and publish 2 a week', "Super Admin › Tools › Blog › Add 10 starter posts (they come in as drafts, written from docs/BLOG_KEYWORD_PLAN.md). For each: read it, check the prices and fees are still right, add your own photo or a RestroGrow screenshot as the cover (at least 1200px wide, with alt text), then Publish. Order: KOT full form, KOT printer price, KOT printer not working, Swiggy charges, Zomato commission, WhatsApp orders, cheapest billing software, free Android billing, dhaba profit, kitchen display price. After each one goes live, ask Claude to request indexing in Search Console. Update the Zomato and Swiggy posts every 3 months."],
    ['Blog', 0, 'Load the keyword plan into the blog', "Open docs/BLOG_KEYWORD_PLAN.md, copy the block at the bottom (lines like 'kot full form | info | 1 | 6 | ...'), then Super Admin › Tools › Blog › Keywords › Add many at once › paste › Add all. The 20 keywords come in with priority and notes; the ones with a starter post move to Planned / Written as you publish."],
    ['Blog', 0, 'Submit the blog sitemap to Google Search Console', "Search Console › Sitemaps › add https://restrogrow.com/sitemap-blog.xml (it is also listed in robots.txt). Then use URL inspection › Request indexing on each new post."],
    ['Publish', 1, 'Install the new apps on your phone and test them', "Desktop: RestroGrow-Partner-1.1.9.apk, RestroGrow-Staff-1.0.2.apk, RestroGrow-SuperAdmin-1.1.1.apk. Uninstall the old app first, then install. They fix the logo / black screen on start. Test: Partner app login + one POS bill; Staff app as waiter, chef, cashier and rider (demo logins: <name>@spicegardenindiranagar.com (DEMO_STAFF) / demo). If anything goes black, plug the phone in with USB debugging and ask Claude to read the error."],
    ['Publish', 1, 'Install apps Partner 1.2.0, Staff 1.0.3, Super Admin 1.1.2', "Desktop: RestroGrow-Partner-1.2.0.apk, RestroGrow-Staff-1.0.3.apk, RestroGrow-SuperAdmin-1.1.2.apk (install over the old ones). They hide the sections you switch off in Super Admin › Restaurants › Access, and the Super Admin app can switch them too (restaurant › Dashboard sections). Test: switch Loyalty off for the demo restaurant, wait 20 seconds, it leaves the Partner app's More menu; switch it back on."],
    ['WhatsApp', 0, 'Approve 2 WhatsApp templates for new restaurants', "In RepeatGrow, on RestroGrow's own number, create and get approved: (1) a welcome template with 4 variables: first name, restaurant, dashboard link, website link; (2) a setup reminder with 4 variables: first name, restaurant, next step, dashboard link. Then add REPEATGROW_WELCOME_TEMPLATE=<name> and REPEATGROW_SETUP_TEMPLATE=<name> to the live main/.env. Until then new restaurants get the email only."],
    ['Check', 0, 'Look at Usage & sign-ups once a week', "Super Admin › Tools › Usage & sign-ups: call restaurants with low use or no login in 7 days (the 'talk to them about' list says what to show them), and the unfinished sign-ups in Leads."],
    ['Demo', 0, 'Switch the demo restaurant back to every section', "The demo (Spice Garden Indiranagar) was set to POS only while you tried Access. Super Admin › Restaurants › Access on its row › Everything › Save access, so visitors see the whole product."],
    ['Listings', 0, 'Check the Trustpilot page opens', "Open https://www.trustpilot.com/review/restrogrow.com yourself (Trustpilot blocked the automated check). If it works, ask a few happy restaurants for a review. Update its status in the handbook (section 11)."],
    ['Listings', 0, 'SoftwareSuggest and AlternativeTo approvals', "SoftwareSuggest: vendor portal › Software Profile should change from Unapproved within 1–2 days; then the listing is probably https://www.softwaresuggest.com/restrogrow. AlternativeTo: profile menu › My submissions. When live, put the links in the handbook (section 11) and tell Claude to add them to the website."],
    ['Decide', 0, 'Pick the Undo design', "Super Admin › Tools › Undo design: press Try it on each of the four, then Use this on the one you like. Until then restaurants see Dark pill. It shows after deleting a menu item, staff member, customer, booking, table, coupon, combo, stock item or payment method."],
    ['Delivery', 0, 'Turn on own riders where a restaurant has its own delivery boys', "Settings › Delivery › \"We deliver with our own riders\" (off by default). Then Staff › add member › role Delivery rider. Riders use the Staff app; the owner assigns deliveries in Order details or Delivery › Riders and settles their cash there."],
    ['Delivery', 0, 'Test one real delivery with a rider on the live site', "On a test restaurant (or the demo): turn on own riders, add a rider, place a cash delivery order on the website, accept it, assign the rider, then in the Staff app as the rider: Picked up → Start the ride → collect cash → Delivered. Check the customer's tracking page shows the rider on the map, and Settle cash in Delivery › Riders."],
    ['Demo', 0, 'Reset the demo restaurant on the live site', "Super Admin › Tools › Demo restaurant › Reset demo, so the live demo gets the new riders (arjun@spicegardenindiranagar.com), the cashier (kavita@spicegardenindiranagar.com) and own riders switched on."],
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
    ['Analytics', 0, 'Connect Google Analytics numbers to Super Admin', "So Super Admin › Tools › Website analytics › Google Analytics shows GA's real numbers (our own tracking already works without this). Same Google account as Analytics:
1. console.cloud.google.com/projectcreate › name it restrogrow-analytics › Create.
2. APIs › Library › \"Google Analytics Data API\" › Enable.
3. IAM & Admin › Service accounts › Create › name ga-reader (no roles) › open it › Keys › Add key › Create new key › JSON (a .json file downloads).
4. analytics.google.com › Admin › Property access management › + › Add users › paste the service account email (ends with iam.gserviceaccount.com) › role Viewer › untick Notify › Add.
5. Super Admin › Tools › Website analytics › Settings › choose the .json file › Upload key. The Google Analytics tab then shows users, pages, channels and countries."],
    ['WhatsApp', 0, 'New RepeatGrow key with webhooks:manage (WhatsApp Delivered / Read)', "So campaign cards show Delivered and Read for messages from RestroGrow's shared number. 1. RepeatGrow (RestroGrow's own account) › Settings › API keys › New key › tick messages:send AND webhooks:manage (\"Register and manage outbound event webhooks\") › create › copy the wacrm_live_… key. 2. Hostinger › File Manager › main/.env › replace the value after REPEATGROW_API_KEY= with the new key › Save. Nothing else: RestroGrow registers the webhook by itself the next time a WhatsApp page opens. (Skip if the current key already has webhooks:manage.)"],
    ['Payments', 1, 'Create the PhonePe webhook (so payments never get stuck)', "1. Make a username and a strong password (letters + digits, 8+ characters).
2. Hostinger › File Manager › main/.env › add (or fill):
PHONEPE_WEBHOOK_USERNAME=<your username>
PHONEPE_WEBHOOK_PASSWORD=<your password>
› Save.
3. business.phonepe.com (RestroGrow's PhonePe account) › Developer settings › Webhooks › Create webhook:
• Webhook URL: https://restrogrow.com/main/api/phonepe_webhook.php
• Username / Password: the same two as in .env
• Description: RestroGrow payments
• Events: tick checkout.order.completed and checkout.order.failed (nothing else needed)
› Create.
Only ONE webhook: it handles orders, plan renewals, payment links, wallet top-ups, VIP and meal plans. Restaurants on their own PhonePe account see their own webhook details in Payments › Payment Gateway."],
    ['Payments', 1, 'Add the payment safety-net cron job', "hPanel › Advanced › Cron Jobs › Custom › every 5 minutes:
/usr/bin/php <HOME>/main/cron/payments_reconcile.php
(<HOME> = the folder shown in File Manager.) It asks PhonePe about every payment still waiting and cancels website orders whose online payment never completed after 30 minutes (coupon, points, wallet money and stock go back). It also runs while a dashboard is open, but the cron makes it run at night too."],
    ['Payments', 1, 'Set the payment encryption key in .env', "Restaurants' own PhonePe secrets are now saved encrypted. Hostinger › File Manager › main/.env: if WA_KEY_SECRET= already has a value, nothing to do. Otherwise add PAYMENT_KEY_SECRET=<a long random text> (make one: any password generator, 40+ characters) › Save. Never change it afterwards (saved secrets could not be read any more). Also check PHONEPE_CLIENT_ID and PHONEPE_CLIENT_SECRET are RestroGrow's LIVE keys."],
    ['AI apps', 1, 'Try RestroGrow inside ChatGPT and Claude', "The steps are also in your dashboard: Growth › ChatGPT & Claude. Needs a paid ChatGPT plan (Developer mode is not on Free). ChatGPT: Settings › Apps & Connectors › Advanced settings › turn on Developer mode › back in Apps & Connectors › Create: Name RestroGrow, MCP server URL https://restrogrow.com/mcp, Authentication OAuth › tick I trust this application › Create. ChatGPT opens the RestroGrow login: log in as the demo owner (demo / demo) › Allow. In a new chat pick RestroGrow (+ › More, or + › Developer mode) and ask: \"sales this week\", \"top dishes this month\", \"switch off Paneer Tikka\", \"add Mango Lassi 129 to Beverages\", \"accept the newest pending order\". Claude: Settings › Connectors › Add custom connector › same URL › Connect. If anything fails, tell Claude what it said. Details: docs/CHATGPT_APP.md."],
    ['AI apps', 0, 'Submit RestroGrow to the ChatGPT app directory', "Only after the test above works. platform.openai.com › your organisation › verify it (ID check) › Apps › Submit app: MCP URL https://restrogrow.com/mcp, OAuth, test account demo / demo (make sure it logs in on the live site without a WhatsApp code), privacy policy https://restrogrow.com/privacy-policy/, terms https://restrogrow.com/terms-of-service/, support email, logo, screenshots (706 px wide, no chat text in them), a short demo video, and the test prompts from docs/CHATGPT_APP.md. If they ask for a domain check file, give the text to Claude to put on restrogrow.com. OpenAI decides when (and if) it is listed; until then owners can still connect with Developer mode."],
    ['Decide', 0, 'Pick the loading screens', "Super Admin › Tools › Loading screens: try the 12 loaders (Try full screen), choose one for Dashboard opening, Page change and Section & button, then Save. Then tell Claude to switch them on in the restaurant dashboard."],
];
// jobs the owner has already done outside the list
$doneOutside = ['Give the AI manager a natural, fast voice (Google Cloud Text-to-Speech)', 'Reset the demo restaurant on the live site', 'Load the keyword plan into the blog'];
$conn->prepare('DELETE FROM sa_todos WHERE title IN (?, ?, ?)')->execute($doneOutside);
$have = $conn->prepare('SELECT COUNT(*) FROM sa_todos WHERE title = ?');
$addLater = $conn->prepare('INSERT INTO sa_todos (category, urgent, title, details, sort) VALUES (?, ?, ?, ?, ?)');
foreach ($later as $i => $t) { if (in_array($t[2], $doneOutside, true)) continue; $have->execute([$t[2]]); if (!(int)$have->fetchColumn()) $addLater->execute([$t[0], $t[1], $t[2], $t[3], 100 + $i]); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    // same-site only (the page's own fetch); stops another site posting with the admin's cookie
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '')) { http_response_code(403); echo '{"ok":false}'; exit; }
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($in['id'] ?? 0);
    switch ($in['action'] ?? '') {
        case 'toggle':
            $conn->prepare('UPDATE sa_todos SET done_at = IF(done = 0, NOW(), NULL), done = 1 - done WHERE id = ?')->execute([$id]);
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
