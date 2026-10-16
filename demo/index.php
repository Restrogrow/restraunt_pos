<?php
/**
 * /demo — one-tap entry to the demo restaurant (Spice Garden Indiranagar, login demo / demo).
 * Not linked from any public page; it's shared from the admin handbook. Opens the owner
 * dashboard, the staff screens, the customer website and the app demo.
 * ?go=admin|manager|waiter|chef logs straight in, so a link can skip this page.
 */
require_once __DIR__ . '/../main/config/demo_restaurant.php';
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$logins = [
    'admin'   => [DEMO_USERNAME, DEMO_PASSWORD, 'Owner dashboard'],
    'manager' => ['manager.demo@spicegarden.test', DEMO_STAFF_PASSWORD, 'Manager'],
    'waiter'  => ['waiter.demo@spicegarden.test', DEMO_STAFF_PASSWORD, 'Waiter'],
    'chef'    => ['chef.demo@spicegarden.test', DEMO_STAFF_PASSWORD, 'Kitchen (chef)'],
];
$go = isset($_GET['go'], $logins[$_GET['go']]) ? $_GET['go'] : '';
$slug = demoRestaurantSlug();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
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
  :root { --paper:#f5efe2; --raised:#fffcf5; --deep:#ece2cd; --ink:#241d16; --soft:#55493c; --faint:#726553; --accent:#c1440e; --accent-dark:#8f3308; --ok:#3f7d4a; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--paper); color:var(--ink); font-family:'Public Sans','Segoe UI',sans-serif; }
  .wrap { max-width:980px; margin:0 auto; padding:28px 16px 56px; }
  .brand { display:flex; align-items:center; gap:10px; font-family:Archivo,sans-serif; font-weight:800; font-size:20px; text-decoration:none; color:inherit; }
  .brand img { width:32px; height:32px; }
  h1 { font-family:Archivo,sans-serif; font-weight:900; font-size:clamp(30px,4vw,44px); line-height:1.05; margin:26px 0 10px; letter-spacing:-.02em; }
  .lead { font-size:17px; line-height:1.6; color:var(--soft); margin:0 0 24px; max-width:640px; }
  .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:14px; }
  .card { background:var(--raised); border:1px solid var(--deep); border-radius:16px; padding:20px; display:flex; flex-direction:column; gap:10px; }
  .card h2 { font-size:17px; margin:0; }
  .card p { margin:0; color:var(--faint); font-size:14px; line-height:1.55; }
  .btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; border:0; border-radius:999px; padding:12px 18px; font:700 14.5px 'Public Sans',sans-serif; cursor:pointer; text-decoration:none; }
  .primary { background:var(--accent); color:#fff; } .primary:hover { background:var(--accent-dark); }
  .ghost { background:transparent; color:var(--ink); border:2px solid var(--ink); }
  .row { display:flex; gap:8px; flex-wrap:wrap; margin-top:auto; }
  .cred { font-size:13.5px; color:var(--soft); }
  code { background:var(--deep); border-radius:6px; padding:2px 6px; font-size:13px; }
  .note { margin-top:22px; font-size:13.5px; color:var(--faint); line-height:1.6; }
  .status { min-height:20px; font-size:14px; color:var(--accent-dark); margin-top:14px; }
  @media (max-width:600px) { .btn { flex:1; } }
</style>
</head>
<body>
<main class="wrap">
  <a class="brand" href="../"><img src="../assets/images/logo-transparent.png" alt="">Restro Grow</a>
  <h1>Try RestroGrow with a real-looking restaurant</h1>
  <p class="lead"><b><?php echo $h(DEMO_RESTAURANT_NAME); ?></b> has a full menu with photos, tables, staff, six weeks of sales
     and orders coming in right now. Tap around freely: we reset the demo from time to time.</p>

  <div class="grid">
    <section class="card">
      <h2>Owner dashboard</h2>
      <p>Orders, POS billing, kitchen, tables, menu, reports, coupons, loyalty and settings.</p>
      <p class="cred">Username <code><?php echo $h(DEMO_USERNAME); ?></code> · Password <code><?php echo $h(DEMO_PASSWORD); ?></code></p>
      <div class="row"><button class="btn primary" data-go="admin" type="button">Open dashboard</button></div>
    </section>
    <section class="card">
      <h2>Customer website</h2>
      <p>The restaurant's own ordering site: browse the menu, apply <code>WELCOME50</code>, order delivery, takeaway or dine-in.</p>
      <div class="row"><a class="btn primary" href="../<?php echo $h($slug); ?>" target="_blank" rel="noopener">Open website</a></div>
    </section>
    <section class="card">
      <h2>Staff screens</h2>
      <p>What the manager, waiters and kitchen see on their phones and tablets.</p>
      <p class="cred">Password for all staff: <code><?php echo $h(DEMO_STAFF_PASSWORD); ?></code></p>
      <div class="row">
        <button class="btn ghost" data-go="manager" type="button">Manager</button>
        <button class="btn ghost" data-go="waiter" type="button">Waiter</button>
        <button class="btn ghost" data-go="chef" type="button">Kitchen</button>
      </div>
    </section>
    <section class="card">
      <h2>Partner app</h2>
      <p>The Android app for owners, running in the browser with sample data.</p>
      <div class="row"><a class="btn primary" href="../app-demo/" target="_blank" rel="noopener">Open app demo</a></div>
    </section>
  </div>
  <p class="status" id="status" role="status"></p>
  <p class="note">Orders placed on the demo website are test orders: nothing is cooked or delivered. Messages, payment keys,
     passwords and file uploads are switched off for the demo account.</p>
</main>
<script>
  var LOGINS = <?php echo json_encode(array_map(fn($l) => ['u' => $l[0], 'p' => $l[1], 'label' => $l[2]], $logins)); ?>;
  var statusEl = document.getElementById('status');
  function go(key) {
    var l = LOGINS[key];
    if (!l) return;
    statusEl.textContent = 'Opening ' + l.label + '…';
    var body = new URLSearchParams({ action: 'login', username: l.u, password: l.p });
    fetch('../main/admin/auth.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) throw new Error(d.message || 'Login failed');
        // auth.php answers with a path relative to main/admin/
        location.href = '../main/admin/' + (d.redirect || '../views/dashboard.php');
      })
      .catch(function (e) { statusEl.textContent = 'Could not open the demo: ' + e.message; });
  }
  document.querySelectorAll('[data-go]').forEach(function (b) { b.addEventListener('click', function () { go(b.getAttribute('data-go')); }); });
  <?php if ($go): ?>go(<?php echo json_encode($go); ?>);<?php endif; ?>
</script>
</body>
</html>
