<?php
/**
 * The delivery rider's screen in the browser (the same as the Staff app's rider layout), for riders who
 * don't install the app. Data: api/rider_app.php. While a delivery is under way and the page is open, the
 * phone's location goes to the customer's tracking page.
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
if (!isSessionValid() || !isDeliverySession() || empty($_SESSION['restaurant_id'])) {
    header('Location: ../admin/login.php');
    exit();
}
$name = (string)($_SESSION['username'] ?? 'Rider');
$restaurant = (string)($_SESSION['restaurant_name'] ?? 'Restaurant');
session_write_close();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#c1440e">
<meta name="robots" content="noindex">
<title>Deliveries · <?php echo $h($restaurant); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL@20..48,500,0..1&display=block" rel="stylesheet">
<style>
  :root { --bg:#f6f3ee; --card:#fff; --ink:#1f1a16; --soft:#5b524a; --muted:#8a8178; --line:#ece6dd; --chip:#f3efe8;
          --brand:#c1440e; --brand-2:#f97316; --ok:#16a34a; --ok-bg:#f0fdf4; --warn:#b45309; --warn-bg:#fff7ed; --blue:#2563eb; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--ink); font-family:Poppins,system-ui,sans-serif; -webkit-tap-highlight-color:transparent; }
  .ms { font-family:'Material Symbols Rounded'; font-size:20px; line-height:1; vertical-align:middle; font-weight:normal; }
  .wrap { max-width:1080px; margin:0 auto; padding:0 16px; }

  header { background:linear-gradient(135deg,var(--brand-2),var(--brand)); color:#fff; padding:calc(18px + env(safe-area-inset-top)) 0 22px; }
  .hrow { display:flex; align-items:center; justify-content:space-between; gap:12px; }
  .who { display:flex; align-items:center; gap:12px; min-width:0; }
  .avatar { width:44px; height:44px; border-radius:14px; background:rgba(255,255,255,.2); display:grid; place-items:center; flex:none; }
  .avatar .ms { font-size:26px; }
  header h1 { margin:0; font-size:20px; font-weight:700; line-height:1.2; }
  header p { margin:2px 0 0; opacity:.9; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .out { color:#fff; text-decoration:none; background:rgba(255,255,255,.18); height:40px; padding:0 12px; border-radius:12px; display:flex; align-items:center; gap:6px; font-size:13px; font-weight:600; flex:none; }
  .out:hover { background:rgba(255,255,255,.28); }
  .stats { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-top:18px; }
  .stat { background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.18); border-radius:16px; padding:12px 14px; display:flex; align-items:center; gap:12px; min-width:0; }
  .stat .ms { font-size:24px; background:rgba(255,255,255,.2); width:42px; height:42px; border-radius:12px; display:grid; place-items:center; flex:none; }
  .stat b { display:block; font-size:21px; line-height:1.15; white-space:nowrap; }
  .stat small { font-size:12px; opacity:.92; }

  main.wrap { padding-top:18px; padding-bottom:calc(40px + env(safe-area-inset-bottom)); display:grid; grid-template-columns:minmax(0,1fr) 340px; gap:22px; align-items:start; }
  .col { display:grid; gap:12px; min-width:0; }
  aside.col { position:sticky; top:16px; }
  h2 { margin:6px 2px 0; font-size:15px; display:flex; align-items:center; gap:8px; }
  h2 .n { background:var(--chip); color:var(--soft); font-size:12px; font-weight:600; padding:1px 9px; border-radius:999px; }

  .loc { display:flex; align-items:center; gap:10px; background:var(--card); border:1px solid var(--line); border-radius:14px; padding:12px 14px; font-size:13px; color:var(--soft); }
  .dot { width:10px; height:10px; border-radius:50%; background:#d1d5db; flex:none; } .dot.on { background:var(--ok); box-shadow:0 0 0 4px rgba(22,163,74,.15); }

  .card { background:var(--card); border:1px solid var(--line); border-radius:18px; padding:16px; display:grid; gap:12px; box-shadow:0 1px 2px rgba(31,26,22,.04); }
  .top { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; }
  .top b { font-size:16px; display:block; }
  .top small { display:block; color:var(--muted); font-size:12px; margin-top:2px; }
  .pill { font-size:12px; font-weight:600; padding:4px 10px; border-radius:999px; color:#fff; white-space:nowrap; display:inline-flex; align-items:center; gap:4px; }
  .pill .ms { font-size:15px; }

  .steps { display:grid; grid-template-columns:repeat(4,1fr); gap:6px; }
  .steps div { font-size:11px; color:var(--muted); text-align:center; }
  .steps div::before { content:''; display:block; height:5px; border-radius:99px; background:var(--line); margin-bottom:5px; }
  .steps .on { color:var(--ink); font-weight:600; } .steps .on::before { background:var(--brand-2); }
  .steps .now::before { background:var(--brand); }

  .row { display:flex; gap:10px; align-items:flex-start; font-size:14px; line-height:1.45; }
  .row > .ms { color:var(--muted); margin-top:1px; flex:none; }
  .row small { display:block; color:var(--muted); font-size:12.5px; }
  .items { color:var(--soft); font-size:13.5px; }
  .cash { display:flex; align-items:center; gap:8px; font-weight:600; color:var(--warn); background:var(--warn-bg); border-radius:12px; padding:10px 12px; font-size:14px; }
  .cash.paid { color:var(--ok); background:var(--ok-bg); }
  .acts { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
  .btn { display:flex; align-items:center; justify-content:center; gap:7px; border:0; border-radius:12px; padding:12px; font:600 14px Poppins,sans-serif; cursor:pointer; text-decoration:none; color:var(--ink); background:var(--chip); transition:filter .15s; }
  .btn:hover { filter:brightness(.97); }
  aside .acts .btn:not(.big) { font-size:13px; padding:11px 6px; white-space:nowrap; }
  .btn.big { grid-column:1/-1; padding:15px; font-size:15.5px; color:#fff; background:var(--brand); }
  .btn.green { background:var(--ok); color:#fff; } .btn:disabled { opacity:.55; cursor:default; }
  .btn.wide { width:100%; background:var(--card); border:1px solid var(--line); }
  .chk { display:flex; gap:10px; align-items:center; font-size:14px; font-weight:600; grid-column:1/-1; padding:10px 12px; border-radius:12px; border:1.5px dashed #f3c99b; background:#fffaf3; cursor:pointer; }
  .chk input { width:22px; height:22px; accent-color:var(--ok); flex:none; margin:0; }

  .empty { text-align:center; color:var(--muted); font-size:14px; padding:30px 16px; background:var(--card); border:1px dashed var(--line); border-radius:18px; }
  .empty .ms { font-size:36px; display:block; margin:0 auto 8px; color:#c9bfb3; }
  .open .top b { font-size:15px; }
  .done-list { padding:4px 14px; gap:0; }
  .done { display:flex; align-items:center; gap:10px; font-size:13.5px; padding:11px 0; border-bottom:1px solid var(--line); }
  .done:last-child { border:0; }
  .done .ms { color:var(--ok); font-size:20px; flex:none; }
  .done span { flex:1; min-width:0; } .done span small { display:block; color:var(--muted); font-size:12px; }
  .done b { font-size:13.5px; white-space:nowrap; text-align:right; } .done b small { display:block; font-weight:500; color:var(--muted); font-size:11.5px; }

  .skel { height:180px; border-radius:18px; background:linear-gradient(90deg,#efe9e0 25%,#f7f3ed 50%,#efe9e0 75%); background-size:200% 100%; animation:sk 1.2s infinite; }
  @keyframes sk { to { background-position:-200% 0; } }
  #toast { position:fixed; left:50%; bottom:calc(20px + env(safe-area-inset-bottom)); transform:translateX(-50%); background:var(--ink); color:#fff; padding:11px 16px; border-radius:12px; font-size:14px; display:none; max-width:92vw; z-index:5; box-shadow:0 8px 24px rgba(0,0,0,.18); }

  @media (max-width: 860px) {
    main.wrap { grid-template-columns:1fr; gap:14px; padding-top:14px; }
    aside.col { position:static; }
  }
  @media (max-width: 520px) {
    .wrap { padding:0 14px; }
    .stats { gap:8px; margin-top:16px; }
    .stat { flex-direction:column; align-items:flex-start; gap:2px; padding:10px 12px; }
    .stat .ms { display:none; }
    .stat b { font-size:19px; }
    .out span:last-child { display:none; } .out { width:40px; padding:0; justify-content:center; }
  }
</style>
</head>
<body>
<header>
  <div class="wrap">
    <div class="hrow">
      <div class="who"><div class="avatar"><span class="ms">two_wheeler</span></div>
        <div style="min-width:0"><h1>Hi <?php echo $h(explode(' ', $name)[0]); ?> 👋</h1><p><?php echo $h($restaurant); ?> · Delivery rider</p></div></div>
      <a class="out" href="../controllers/staff_logout.php" aria-label="Log out" title="Log out"><span class="ms">logout</span><span>Log out</span></a>
    </div>
    <div class="stats">
      <div class="stat"><span class="ms">package_2</span><div><b id="sNow">–</b><small>to deliver</small></div></div>
      <div class="stat"><span class="ms">task_alt</span><div><b id="sDone">–</b><small>delivered today</small></div></div>
      <div class="stat"><span class="ms">payments</span><div><b id="sCash">–</b><small>cash with you</small></div></div>
    </div>
  </div>
</header>
<main class="wrap">
  <section class="col">
    <div class="loc"><span class="dot" id="locDot"></span><span id="locText">Location sharing starts when you pick up an order.</span></div>
    <div class="col" id="mine"><div class="skel"></div><div class="skel"></div></div>
  </section>
  <aside class="col" id="side"></aside>
</main>
<div id="toast" role="status"></div>
<script>
(function () {
  var API = '../api/rider_app.php', D = null, watch = null, lastSent = 0, cur = '₹';
  var STEP = { Assigned: ['Go to the restaurant', '#2563eb', 'storefront'], Picked_Up: ['Picked up', '#b45309', 'shopping_bag'], In_Transit: ['On the way', '#c1440e', 'two_wheeler'], Pending: ['Waiting', '#6b7280', 'schedule'], Preparing: ['Waiting', '#6b7280', 'schedule'] };
  var ORDER = ['Assigned', 'Picked_Up', 'In_Transit', 'Delivered'];
  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(n) { return cur + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }); }
  function toast(t) { var e = $('toast'); e.textContent = t; e.style.display = 'block'; clearTimeout(toast.t); toast.t = setTimeout(function () { e.style.display = 'none'; }, 3500); }
  function post(b) { return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) }).then(function (r) { return r.json(); }); }
  function tel(p) { return 'tel:' + String(p || '').replace(/[^\d+]/g, ''); }
  function when(s) { return s ? new Date(String(s).replace(' ', 'T')) : null; }
  function ago(s) {
    var d = when(s); if (!d || isNaN(d)) return '';
    var m = Math.max(0, Math.round((Date.now() - d.getTime()) / 60000));
    return m < 1 ? 'just now' : m < 60 ? m + ' min ago' : Math.floor(m / 60) + ' h ' + (m % 60) + ' min ago';
  }
  function clock(s) { var d = when(s); return d && !isNaN(d) ? d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : ''; }

  function steps(status) {
    var at = ORDER.indexOf(status); if (at < 0) at = 0;
    return '<div class="steps">' + ['Assigned', 'Picked up', 'On the way', 'Delivered'].map(function (l, i) {
      return '<div class="' + (i <= at ? 'on' : '') + (i === at ? ' now' : '') + '">' + l + '</div>';
    }).join('') + '</div>';
  }

  function card(d, open) {
    var st = STEP[d.delivery_status] || STEP.Assigned;
    var next = open ? null : ({ Assigned: ['Picked_Up', 'I picked up the order'], Pending: ['Picked_Up', 'I picked up the order'], Preparing: ['Picked_Up', 'I picked up the order'], Picked_Up: ['In_Transit', 'Start the ride'], In_Transit: ['Delivered', 'Delivered'] })[d.delivery_status];
    var cod = d.cod_due > 0;
    var kitchen = d.order_status === 'Ready' ? 'Food is ready' : 'Kitchen: ' + esc(String(d.order_status).toLowerCase());
    return '<div class="card' + (open ? ' open' : '') + '">' +
      '<div class="top"><div><b>' + esc(d.customer_name || 'Customer') + '</b><small>' + esc(d.order_number) + ' · ' + ago(d.created_at) + '</small></div>' +
        '<span class="pill" style="background:' + st[1] + '"><span class="ms">' + st[2] + '</span>' + (open ? 'Open' : st[0]) + '</span></div>' +
      (open ? '' : steps(d.delivery_status)) +
      '<div class="row"><span class="ms">location_on</span><div>' + esc(d.address || 'No address given') + (d.landmark ? '<small>Near ' + esc(d.landmark) + '</small>' : '') + '</div></div>' +
      (d.items ? '<div class="row items"><span class="ms">restaurant</span><div>' + esc(d.items) + '<small>' + kitchen + '</small></div></div>' : '') +
      '<div class="cash' + (cod ? '' : ' paid') + '"><span class="ms">' + (cod ? 'payments' : 'check_circle') + '</span>' + (cod ? 'Collect ' + money(d.cod_due) + ' in cash' : 'Already paid, collect nothing') + '</div>' +
      '<div class="acts">' + (d.maps_url ? '<a class="btn" href="' + esc(d.maps_url) + '" target="_blank" rel="noopener"><span class="ms">navigation</span>Directions</a>' : '<span></span>') +
      (d.customer_phone ? '<a class="btn" href="' + tel(d.customer_phone) + '"><span class="ms">call</span>Call customer</a>' : '<span></span>') +
      (open ? '<button class="btn big" data-claim="' + d.id + '"><span class="ms">add_task</span>Take this delivery</button>' :
        (next ? (next[0] === 'Delivered' && cod ? '<label class="chk"><input type="checkbox" data-cod="' + d.id + '">I collected ' + money(d.cod_due) + ' in cash</label>' : '') +
          '<button class="btn big' + (next[0] === 'Delivered' ? ' green' : '') + '" data-step="' + next[0] + '" data-id="' + d.id + '"><span class="ms">' + (next[0] === 'Delivered' ? 'task_alt' : next[0] === 'In_Transit' ? 'two_wheeler' : 'shopping_bag') + '</span>' + next[1] + '</button>' : '')) +
      '</div></div>';
  }

  function draw() {
    cur = (D.restaurant && D.restaurant.currency) || '₹';
    $('sNow').textContent = D.mine.length; $('sDone').textContent = D.delivered_today; $('sCash').textContent = money(D.cash_in_hand);
    $('mine').innerHTML = '<h2>Your deliveries <span class="n">' + D.mine.length + '</span></h2>' + (D.mine.length ? D.mine.map(function (d) { return card(d, false); }).join('') :
      '<div class="empty"><span class="ms">inventory_2</span>Nothing to deliver right now.<br>New deliveries show up here by themselves.</div>');
    var h = '';
    if (D.self_pick && D.open.length) h += '<h2>Waiting for a rider <span class="n">' + D.open.length + '</span></h2>' + D.open.map(function (d) { return card(d, true); }).join('');
    if (D.done_today.length) h += '<h2>Delivered today <span class="n">' + D.done_today.length + '</span></h2><div class="card done-list">' + D.done_today.map(function (d) {
      return '<div class="done"><span class="ms">check_circle</span><span>' + esc(d.customer_name || 'Customer') + '<small>' + esc(d.order_number) + (d.delivered_at ? ' · ' + clock(d.delivered_at) : '') + '</small></span>' +
        '<b>' + money(d.total) + '<small>' + (d.cod_amount ? 'cash ' + money(d.cod_amount) : 'paid online') + '</small></b></div>';
    }).join('') + '</div>';
    if (D.restaurant && D.restaurant.phone) h += '<a class="btn wide" href="' + tel(D.restaurant.phone) + '"><span class="ms">storefront</span>Call the restaurant</a>';
    $('side').innerHTML = h;
    track(D.mine.some(function (d) { return ['Assigned', 'Picked_Up', 'In_Transit'].indexOf(d.delivery_status) !== -1; }));
  }
  function load() {
    return fetch(API, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.success) { if (/log in/i.test(d.message || '')) location.href = '../admin/login.php'; else $('mine').innerHTML = '<div class="empty">' + esc(d.message) + '</div>'; return; }
      D = d; draw();
    }).catch(function () { $('locText').textContent = 'No internet. Trying again…'; });
  }

  // live location while a delivery is under way
  function track(on) {
    if (!navigator.geolocation) { $('locText').textContent = 'This browser can\'t share location. Use the RestroGrow Staff app.'; return; }
    if (on && watch === null) {
      watch = navigator.geolocation.watchPosition(function (p) {
        $('locDot').classList.add('on'); $('locText').textContent = 'Sharing your location with the customer.';
        if (Date.now() - lastSent < 15000) return;
        lastSent = Date.now();
        post({ action: 'location', lat: p.coords.latitude, lng: p.coords.longitude });
      }, function () { $('locDot').classList.remove('on'); $('locText').textContent = 'Allow location so the customer can follow you.'; }, { enableHighAccuracy: true, maximumAge: 10000, timeout: 20000 });
    } else if (!on && watch !== null) {
      navigator.geolocation.clearWatch(watch); watch = null;
      $('locDot').classList.remove('on'); $('locText').textContent = 'Location sharing starts when you pick up an order.';
    }
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-step],[data-claim]');
    if (!b) return;
    var body;
    if (b.dataset.claim) body = { action: 'claim', order_id: +b.dataset.claim };
    else {
      var c = document.querySelector('[data-cod="' + b.dataset.id + '"]');
      if (c && !c.checked) { toast('Tick that you collected the cash first'); return; }
      body = { action: 'step', order_id: +b.dataset.id, status: b.dataset.step, cod_collected: c && c.checked ? 1 : 0 };
    }
    b.disabled = true;
    post(body).then(function (d) { toast(d.message || (d.success ? 'Done' : 'Something went wrong')); return load(); }).finally(function () { b.disabled = false; });
  });
  load();
  setInterval(function () { if (!document.hidden) load(); }, 20000);
})();
</script>
</body>
</html>
