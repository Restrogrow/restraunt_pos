<?php
/**
 * /demo — every RestroGrow demo in one place, with direct links to each app's main screens.
 * The demo restaurant is Spice Garden Indiranagar (login demo / demo). Not linked from any public
 * page; shared from the admin handbook and Super Admin › Tools › All demos.
 *
 *  - Web dashboard: ?go=admin|manager|waiter|chef logs straight in; &page=ordersPage opens that page.
 *  - Partner app (browser demo, sample data): …/app/?screen=pos (app/demo/DemoDeepLink.js)
 *  - Staff app (browser build on the real demo restaurant): …/app/?as=waiter&screen=tables (staff-app/demo/DemoDeepLink.js)
 *  - Customer website pages, the customer app template.
 *  - Signed in to Super Admin? A "Team only" part adds the Super Admin app, the panel and the Email book.
 * The app builds have a fixed base path, so this PC uses the -local copies (gitignored) and the live site the others.
 */
require_once __DIR__ . '/../main/config/demo_restaurant.php';
require_once __DIR__ . '/../main/config/session_config.php';
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
startSecureSession();
$isTeam = !empty($_SESSION['superadmin_id']);
session_write_close();

$logins = [
    'admin'   => [DEMO_USERNAME, DEMO_PASSWORD, 'Owner dashboard'],
    'manager' => [demoStaffEmail('manager'), DEMO_STAFF_PASSWORD, 'Manager'],
    'waiter'  => [demoStaffEmail('waiter'), DEMO_STAFF_PASSWORD, 'Waiter'],
    'chef'    => [demoStaffEmail('chef'), DEMO_STAFF_PASSWORD, 'Kitchen (chef)'],
    'bartender' => [demoStaffEmail('bartender'), DEMO_STAFF_PASSWORD, 'Bar (bartender)'],
    'rider'   => [demoStaffEmail('rider'), DEMO_STAFF_PASSWORD, 'Delivery rider'],
    'cashier' => [demoStaffEmail('cashier'), DEMO_STAFF_PASSWORD, 'Cashier'],
];
$go = isset($_GET['go'], $logins[$_GET['go']]) ? $_GET['go'] : '';
$goPage = preg_match('/^[a-zA-Z]{3,40}Page$/', (string)($_GET['page'] ?? '')) ? $_GET['page'] : '';
$slug = demoRestaurantSlug();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = $host === '127.0.0.1' || str_starts_with($host, 'localhost') || str_starts_with($host, '127.0.0.1:');
$build = fn(string $live, string $local, string $sub = '') => ($isLocal && is_dir(__DIR__ . "/../$local")) ? "../$local/$sub" : "../$live/$sub";
$partnerFrame = $build('app-demo', 'app-demo-local');
$partnerApp = $build('app-demo', 'app-demo-local', 'app/');
$customerApp = $build('customer-app-demo', 'customer-app-demo-local');
$staffApp = $build('staff-app-demo', 'staff-app-demo-local', 'app/');
$superApp = $build('superadmin-app-web', 'superadmin-app-web-local', 'app/');
$site = '../' . $slug;

// [label, page] for the owner dashboard
$dashPages = [
    ['Home', 'dashboardPage'], ['Orders', 'ordersPage'], ['Online orders', 'onlineOrdersPage'], ['POS billing', 'posPage'], ['Kitchen (KOT)', 'kotPage'],
    ['Tables', 'tablesPage'], ['Table map', 'tableMapPage'], ['Waiter calls', 'waiterRequestsPage'], ['Bookings', 'reservationsPage'],
    ['Menu', 'menuPage'], ['Dishes', 'menuItemsPage'], ['Add-ons', 'addonsPage'], ["Today's special", 'todaysSpecialPage'], ['Inventory', 'inventoryPage'],
    ['Customers', 'customersPage'], ['Coupons', 'couponsPage'], ['Offers', 'offersHomePage'], ['Wallet', 'walletPage'], ['Referrals', 'referralsPage'],
    ['Reports', 'reportsPage'], ['Analytics', 'analyticsPage'], ['Payments', 'paymentsPage'], ['Feedback', 'feedbackPage'],
    ['WhatsApp ordering', 'waOrderPage'], ['AI manager', 'ownerManagerPage'], ['WhatsApp', 'whatsappPage'], ['Posters', 'marketingPostersPage'],
    ['Videos', 'marketingVideosPage'], ['Campaign calendar', 'marketingCalendarPage'], ['Google Business', 'googleBusinessPage'], ['SEO', 'seoPage'],
    ['Website look', 'websiteThemePage'], ['QR codes', 'qrCodesPage'], ['Delivery zones', 'deliveryPage'], ['Riders', 'ridersPage'], ['Meal plans', 'mealPlansPage'],
    ['Staff', 'staffPage'], ['Settings', 'settingsPage'],
];
// [label, path] on the customer website
$sitePages = [['Home', ''], ['Menu', '/menu'], ['Cart', '/cart'], ['Track order', '/track'], ['Log in', '/login'], ['My account', '/profile'],
    ['Loyalty', '/loyalty'], ['Book a table', '/reservations'], ['Meal plans', '/plans'], ['Catering', '/catering'], ['About', '/about'], ['Contact', '/contact']];
// [label, ?screen=] in the Partner app demo
$partnerScreens = [['Home', 'home'], ['Orders', 'orders'], ['POS', 'pos'], ['Menu', 'menu'], ['Kitchen', 'kitchen'], ['Tables', 'tables'],
    ['Waiter calls', 'waiter-calls'], ['Bookings', 'bookings'], ['Customers', 'customers'], ['Reports', 'reports'], ['Loyalty', 'loyalty'],
    ['Riders', 'riders'], ['Activity log', 'logs'], ['Settings', 'settings']];
// role => [[label, ?screen=]] in the Staff app (only the screens that role has)
$staffScreens = [
    'manager' => [['Home', 'home'], ['Orders', 'orders'], ['POS', 'pos'], ['Menu', 'menu'], ['Kitchen', 'kitchen'], ['Tables', 'tables'], ['Waiter calls', 'waiter-calls'], ['Bookings', 'bookings'], ['Customers', 'customers'], ['Riders', 'riders'], ['Reports', 'reports'], ['Activity log', 'logs']],
    'waiter'  => [['Home', 'home'], ['Tables', 'tables'], ['POS', 'pos'], ['Orders', 'orders'], ['Kitchen', 'kitchen'], ['Waiter calls', 'waiter-calls'], ['Bookings', 'bookings'], ['Customers', 'customers']],
    'chef'    => [['Home', 'home'], ['Kitchen', 'kitchen'], ['Orders', 'orders'], ['Tables', 'tables']],
    'bartender' => [['Home', 'home'], ['Bar', 'kitchen'], ['Orders', 'orders'], ['Tables', 'tables']],
    'cashier' => [['Home', 'home'], ['Orders', 'orders'], ['POS', 'pos'], ['Tables', 'tables'], ['Kitchen', 'kitchen'], ['Waiter calls', 'waiter-calls'], ['Bookings', 'bookings'], ['Customers', 'customers']],
    'rider'   => [['My deliveries', 'home']],
];
$roleNames = ['manager' => 'Manager', 'waiter' => 'Waiter', 'chef' => 'Chef', 'bartender' => 'Bartender', 'cashier' => 'Cashier', 'rider' => 'Rider'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>RestroGrow demo</title>
<link rel="icon" type="image/png" href="../favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root { --paper:#f5efe2; --raised:#fffcf5; --deep:#ece2cd; --line:#e3d6bd; --ink:#241d16; --soft:#55493c; --faint:#726553; --accent:#c1440e; --accent-dark:#8f3308; --team:#2d3a8c; --team-soft:#e6e9fb; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--paper); color:var(--ink); font-family:'Public Sans','Segoe UI',sans-serif; }
  .wrap { max-width:1080px; margin:0 auto; padding:28px 16px 56px; }
  .brand img { height:28px; width:auto; display:block; }
  h1 { font-family:Archivo,sans-serif; font-weight:900; font-size:clamp(30px,4vw,44px); line-height:1.05; margin:26px 0 10px; letter-spacing:-.02em; text-wrap:balance; }
  .lead { font-size:17px; line-height:1.6; color:var(--soft); margin:0 0 18px; max-width:680px; }
  .jump { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 26px; }
  .jump a { font-size:13.5px; font-weight:700; color:var(--ink); text-decoration:none; padding:7px 12px; border-radius:999px; background:var(--deep); }
  .jump a:hover { background:var(--line); }
  .app { background:var(--raised); border:1px solid var(--line); border-radius:18px; padding:22px; margin-bottom:16px; display:grid; gap:12px; scroll-margin-top:16px; }
  .app.team { border-color:#c9cff3; }
  .app-h { display:flex; flex-wrap:wrap; align-items:flex-start; justify-content:space-between; gap:12px; }
  .app-h > div { min-width:0; max-width:640px; }
  .app h2 { font-family:Archivo,sans-serif; font-weight:800; font-size:22px; margin:0 0 4px; }
  .kind { display:inline-block; font-size:11.5px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--accent-dark); margin-bottom:6px; }
  .team .kind { color:var(--team); }
  .app p { margin:0; color:var(--faint); font-size:14.5px; line-height:1.55; }
  .cred { font-size:13.5px; color:var(--soft); }
  code { background:var(--deep); border-radius:6px; padding:2px 6px; font-size:13px; }
  .btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; border:0; border-radius:999px; padding:12px 18px; font:700 14.5px 'Public Sans',sans-serif; cursor:pointer; text-decoration:none; white-space:nowrap; }
  .primary { background:var(--accent); color:#fff; } .primary:hover { background:var(--accent-dark); }
  .team .primary { background:var(--team); } .team .primary:hover { background:#1e2866; }
  .ghost { background:transparent; color:var(--ink); border:2px solid var(--ink); padding:10px 16px; }
  .btns { display:flex; gap:8px; flex-wrap:wrap; }
  .screens-h { font-size:12px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--faint); margin-top:4px; }
  .screens { display:flex; flex-wrap:wrap; gap:6px; }
  .screens a, .screens button { font:600 13.5px 'Public Sans',sans-serif; color:var(--ink); background:#fff; border:1px solid var(--line); border-radius:9px; padding:7px 11px; cursor:pointer; text-decoration:none; }
  .screens a:hover, .screens button:hover { border-color:var(--accent); color:var(--accent-dark); }
  .team .screens a:hover { border-color:var(--team); color:var(--team); }
  .roles { display:grid; gap:10px; }
  .role { display:grid; grid-template-columns:90px 1fr; gap:10px; align-items:start; }
  .role b { font-size:14px; padding-top:7px; }
  .team-h { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:30px 0 12px; font-family:Archivo,sans-serif; font-weight:800; font-size:20px; }
  .team-h span { font:700 12px 'Public Sans',sans-serif; color:var(--team); background:var(--team-soft); padding:3px 9px; border-radius:999px; }
  .note { margin-top:22px; font-size:13.5px; color:var(--faint); line-height:1.6; }
  .status { position:fixed; left:50%; bottom:20px; transform:translateX(-50%); background:var(--ink); color:#fff; padding:11px 16px; border-radius:12px; font-size:14px; max-width:92vw; margin:0; }
  .status:empty { display:none; }
  a:focus-visible, button:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
  @media (max-width:600px) { .role { grid-template-columns:1fr; gap:4px; } .role b { padding-top:0; } .app-h .btns { width:100%; } .app-h .btn { flex:1; } }
</style>
</head>
<body>
<main class="wrap">
  <a class="brand" href="../" aria-label="Restro Grow home"><img src="../assets/images/logo-transparent.png" alt="Restro Grow"></a>
  <h1>Every RestroGrow app, one tap away</h1>
  <p class="lead"><b><?php echo $h(DEMO_RESTAURANT_NAME); ?></b> has a full menu with photos, tables, staff, six weeks of sales and orders
     coming in right now. Open an app, or tap a screen to land straight on it. Tap around freely: the demo is reset from time to time.</p>
  <nav class="jump" aria-label="Apps on this page">
    <a href="#dashboard">Owner dashboard</a><a href="#staff-web">Staff screens</a><a href="#website">Customer website</a>
    <a href="#partner">Partner app</a><a href="#staff-app">Staff app</a><a href="#customer-app">Customer app</a>
    <?php if ($isTeam): ?><a href="#team">Team only</a><?php endif; ?>
  </nav>

  <section class="app" id="dashboard">
    <div class="app-h">
      <div><span class="kind">Web · restaurant owner</span><h2>Owner dashboard</h2>
        <p>Orders, POS billing, kitchen, tables, menu, reports, coupons, loyalty, marketing and settings.</p>
        <p class="cred">Username <code><?php echo $h(DEMO_USERNAME); ?></code> · Password <code><?php echo $h(DEMO_PASSWORD); ?></code></p></div>
      <div class="btns"><button class="btn primary" data-go="admin" type="button">Open dashboard</button></div>
    </div>
    <div class="screens-h">Go straight to</div>
    <div class="screens"><?php foreach ($dashPages as [$l, $p]): ?><button type="button" data-go="admin" data-page="<?php echo $h($p); ?>"><?php echo $h($l); ?></button><?php endforeach; ?></div>
  </section>

  <section class="app" id="staff-web">
    <div class="app-h">
      <div><span class="kind">Web · restaurant staff</span><h2>Staff screens</h2>
        <p>What the manager, waiters, kitchen, bar, cashier and delivery riders see in the browser on their phones and tablets.</p>
        <p class="cred">Password for all staff: <code><?php echo $h(DEMO_STAFF_PASSWORD); ?></code></p></div>
      <div class="btns">
        <button class="btn ghost" data-go="manager" type="button">Manager</button>
        <button class="btn ghost" data-go="waiter" type="button">Waiter</button>
        <button class="btn ghost" data-go="chef" type="button">Kitchen</button>
        <button class="btn ghost" data-go="bartender" type="button">Bar</button>
        <button class="btn ghost" data-go="cashier" type="button">Cashier</button>
        <button class="btn ghost" data-go="rider" type="button">Rider</button>
      </div>
    </div>
  </section>

  <section class="app" id="website">
    <div class="app-h">
      <div><span class="kind">Web · the restaurant's guests</span><h2>Customer website</h2>
        <p>The restaurant's own ordering site: browse the menu, apply <code>WELCOME50</code>, order delivery, takeaway or dine-in.</p></div>
      <div class="btns"><a class="btn primary" href="<?php echo $h($site); ?>" target="_blank" rel="noopener">Open website</a></div>
    </div>
    <div class="screens-h">Go straight to</div>
    <div class="screens"><?php foreach ($sitePages as [$l, $p]): ?><a href="<?php echo $h($site . $p); ?>" target="_blank" rel="noopener"><?php echo $h($l); ?></a><?php endforeach; ?></div>
  </section>

  <section class="app" id="partner">
    <div class="app-h">
      <div><span class="kind">Android app · restaurant owner</span><h2>Partner app</h2>
        <p>The owner's app, running in the browser with sample orders. A new order rings about 25 seconds after you open it. Nothing here touches a real restaurant.</p></div>
      <div class="btns"><a class="btn primary" href="<?php echo $h($partnerFrame); ?>" target="_blank" rel="noopener">Open in a phone frame</a></div>
    </div>
    <div class="screens-h">Go straight to (logs in by itself)</div>
    <div class="screens"><?php foreach ($partnerScreens as [$l, $s]): ?><a href="<?php echo $h($partnerApp . '?screen=' . $s); ?>" target="_blank" rel="noopener"><?php echo $h($l); ?></a><?php endforeach; ?></div>
  </section>

  <section class="app" id="staff-app">
    <div class="app-h">
      <div><span class="kind">Android app · restaurant staff</span><h2>Staff app</h2>
        <p>The app for managers, waiters, chefs, cashiers and delivery riders, each seeing only what their job needs. It runs on the demo restaurant, so what you do shows up in the dashboard too.</p></div>
      <div class="btns"><a class="btn primary" href="<?php echo $h($staffApp); ?>" target="_blank" rel="noopener">Open staff app</a></div>
    </div>
    <div class="roles">
      <?php foreach ($staffScreens as $role => $list): ?>
        <div class="role"><b><?php echo $h($roleNames[$role]); ?></b>
          <div class="screens"><?php foreach ($list as [$l, $s]): ?><a href="<?php echo $h($staffApp . '?as=' . $role . '&screen=' . $s); ?>" target="_blank" rel="noopener"><?php echo $h($l); ?></a><?php endforeach; ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="app" id="customer-app">
    <div class="app-h">
      <div><span class="kind">Android app · template for restaurants</span><h2>Customer app</h2>
        <p>How a restaurant's own ordering app would look on the Play Store: its name, colours and menu. Try other looks live. Not published.</p></div>
      <div class="btns"><a class="btn primary" href="<?php echo $h($customerApp); ?>" target="_blank" rel="noopener">Open customer app</a></div>
    </div>
  </section>

  <?php if ($isTeam): ?>
    <h2 class="team-h" id="team">Team only <span>you're signed in to Super Admin</span></h2>
    <section class="app team">
      <div class="app-h">
        <div><span class="kind">Android app · RestroGrow team</span><h2>Super Admin app</h2>
          <p>Every restaurant on the platform in your pocket. Sign in with your Super Admin username and password (there is no demo account).</p></div>
        <div class="btns"><a class="btn primary" href="<?php echo $h($superApp); ?>" target="_blank" rel="noopener">Open Super Admin app</a></div>
      </div>
    </section>
    <section class="app team">
      <div class="app-h">
        <div><span class="kind">Web · RestroGrow team</span><h2>Super Admin panel</h2>
          <p>Restaurants, renewals, payments, invoices, leads, support, emails and platform health.</p></div>
        <div class="btns"><a class="btn primary" href="../main/superadmin/dashboard.php" target="_blank" rel="noopener">Open panel</a></div>
      </div>
      <div class="screens-h">Go straight to</div>
      <div class="screens">
        <a href="../main/superadmin/email_book.php" target="_blank" rel="noopener">Email book</a>
        <a href="../main/superadmin/email.php" target="_blank" rel="noopener">Email</a>
        <a href="../main/superadmin/todo.php" target="_blank" rel="noopener">My to-do</a>
        <a href="../main/superadmin/features.php" target="_blank" rel="noopener">Features</a>
        <a href="../main/superadmin/handbook.php" target="_blank" rel="noopener">Admin handbook</a>
        <a href="../main/superadmin/demo_restaurant.php" target="_blank" rel="noopener">Reset demo</a>
      </div>
    </section>
  <?php endif; ?>

  <p class="note">Orders placed on the demo website and in the staff app are test orders: nothing is cooked or delivered. Messages, payment keys,
     passwords and file uploads are switched off for the demo account. Opening one login (for example Waiter) signs out the one before it in this browser.</p>
</main>
<p class="status" id="status" role="status"></p>
<script>
  var LOGINS = <?php echo json_encode(array_map(fn($l) => ['u' => $l[0], 'p' => $l[1], 'label' => $l[2]], $logins)); ?>;
  var statusEl = document.getElementById('status');
  function go(key, page) {
    var l = LOGINS[key];
    if (!l) return;
    statusEl.textContent = 'Opening ' + l.label + '…';
    var body = new URLSearchParams({ action: 'login', username: l.u, password: l.p });
    fetch('../main/admin/auth.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) throw new Error(d.message || 'Login failed');
        // auth.php answers with a path relative to main/admin/
        var to = '../main/admin/' + (d.redirect || '../views/dashboard.php');
        if (page && /dashboard\.php/.test(to)) to += (to.indexOf('?') === -1 ? '?' : '&') + 'page=' + encodeURIComponent(page);
        location.href = to;
      })
      .catch(function (e) { statusEl.textContent = 'Could not open the demo: ' + e.message; });
  }
  document.querySelectorAll('[data-go]').forEach(function (b) {
    b.addEventListener('click', function () { go(b.getAttribute('data-go'), b.getAttribute('data-page')); });
  });
  <?php if ($go): ?>go(<?php echo json_encode($go); ?>, <?php echo json_encode($goPage); ?>);<?php endif; ?>
</script>
</body>
</html>
