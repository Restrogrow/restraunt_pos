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
  :root { --bg:#f6f3ee; --card:#fff; --ink:#1f1a16; --soft:#5b524a; --muted:#8a8178; --line:#ece6dd; --brand:#c1440e; --brand-2:#f97316; --ok:#16a34a; --warn:#b45309; --blue:#2563eb; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--ink); font-family:Poppins,system-ui,sans-serif; -webkit-tap-highlight-color:transparent; }
  .ms { font-family:'Material Symbols Rounded'; font-size:20px; line-height:1; vertical-align:middle; }
  header { background:linear-gradient(135deg,var(--brand-2),var(--brand)); color:#fff; padding:calc(16px + env(safe-area-inset-top)) 16px 26px; border-radius:0 0 26px 26px; }
  .hrow { display:flex; align-items:center; justify-content:space-between; gap:10px; }
  header h1 { margin:0; font-size:20px; font-weight:700; } header p { margin:2px 0 0; opacity:.9; font-size:13px; }
  .out { color:#fff; text-decoration:none; background:rgba(255,255,255,.18); width:40px; height:40px; border-radius:12px; display:grid; place-items:center; flex:none; }
  .stats { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-top:16px; }
  .stat { background:rgba(255,255,255,.16); border-radius:14px; padding:10px; } .stat b { display:block; font-size:19px; } .stat small { font-size:11.5px; opacity:.9; }
  main { max-width:560px; margin:-12px auto 0; padding:0 14px calc(40px + env(safe-area-inset-bottom)); display:grid; gap:14px; }
  .loc { display:flex; align-items:center; gap:8px; background:var(--card); border:1px solid var(--line); border-radius:14px; padding:10px 12px; font-size:13px; color:var(--soft); }
  .dot { width:9px; height:9px; border-radius:50%; background:#d1d5db; flex:none; } .dot.on { background:var(--ok); box-shadow:0 0 0 4px rgba(22,163,74,.15); }
  h2 { margin:6px 2px 0; font-size:15px; } h2 span { color:var(--muted); font-weight:500; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:18px; padding:14px; display:grid; gap:10px; }
  .top { display:flex; justify-content:space-between; gap:8px; align-items:flex-start; }
  .top b { font-size:15px; } .top small { display:block; color:var(--muted); font-size:12px; margin-top:2px; }
  .pill { font-size:11.5px; font-weight:700; padding:3px 9px; border-radius:999px; color:#fff; white-space:nowrap; }
  .addr { font-size:14px; line-height:1.45; } .addr small { display:block; color:var(--muted); font-size:12.5px; }
  .items { font-size:13px; color:var(--soft); background:#faf7f2; border-radius:10px; padding:8px 10px; }
  .cash { display:flex; align-items:center; gap:8px; font-weight:700; color:var(--warn); background:#fff7ed; border-radius:10px; padding:9px 10px; font-size:14px; }
  .paid { color:var(--ok); background:#f0fdf4; }
  .acts { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
  .btn { display:flex; align-items:center; justify-content:center; gap:6px; border:0; border-radius:12px; padding:12px; font:600 14px Poppins,sans-serif; cursor:pointer; text-decoration:none; color:var(--ink); background:#f3efe8; }
  .btn.big { grid-column:1/-1; padding:15px; font-size:15.5px; color:#fff; background:var(--brand); }
  .btn.green { background:var(--ok); color:#fff; } .btn:disabled { opacity:.55; }
  .chk { display:flex; gap:10px; align-items:center; font-size:14px; font-weight:600; grid-column:1/-1; padding:4px 2px; } .chk input { width:22px; height:22px; accent-color:var(--ok); }
  .empty { text-align:center; color:var(--muted); font-size:14px; padding:24px 10px; background:var(--card); border:1px dashed var(--line); border-radius:18px; }
  .done { display:flex; justify-content:space-between; font-size:13.5px; padding:9px 2px; border-bottom:1px solid var(--line); } .done:last-child { border:0; }
  #toast { position:fixed; left:50%; bottom:calc(20px + env(safe-area-inset-bottom)); transform:translateX(-50%); background:var(--ink); color:#fff; padding:11px 16px; border-radius:12px; font-size:14px; display:none; max-width:92vw; z-index:5; }
</style>
</head>
<body>
<header>
  <div class="hrow"><div><h1>Hi <?php echo $h(explode(' ', $name)[0]); ?> 👋</h1><p><?php echo $h($restaurant); ?> · Delivery rider</p></div>
    <a class="out" href="../controllers/staff_logout.php" aria-label="Log out" title="Log out"><span class="ms">logout</span></a></div>
  <div class="stats"><div class="stat"><b id="sNow">–</b><small>to deliver</small></div><div class="stat"><b id="sDone">–</b><small>delivered today</small></div><div class="stat"><b id="sCash">–</b><small>cash with you</small></div></div>
</header>
<main>
  <div class="loc"><span class="dot" id="locDot"></span><span id="locText">Location sharing starts when you pick up an order.</span></div>
  <div id="list"></div>
</main>
<div id="toast" role="status"></div>
<script>
(function () {
  var API = '../api/rider_app.php', D = null, watch = null, lastSent = 0, cur = '₹';
  var STEP = { Assigned: ['Go to the restaurant', '#2563eb'], Picked_Up: ['Picked up', '#b45309'], In_Transit: ['On the way', '#c1440e'], Pending: ['Open', '#6b7280'], Preparing: ['Open', '#6b7280'] };
  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(n) { return cur + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }); }
  function toast(t) { var e = $('toast'); e.textContent = t; e.style.display = 'block'; clearTimeout(toast.t); toast.t = setTimeout(function () { e.style.display = 'none'; }, 3500); }
  function post(b) { return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) }).then(function (r) { return r.json(); }); }
  function tel(p) { return 'tel:' + String(p || '').replace(/[^\d+]/g, ''); }

  function card(d, open) {
    var st = STEP[d.delivery_status] || STEP.Assigned;
    var next = open ? null : ({ Assigned: ['Picked_Up', 'I picked up the order'], Pending: ['Picked_Up', 'I picked up the order'], Preparing: ['Picked_Up', 'I picked up the order'], Picked_Up: ['In_Transit', 'Start the ride'], In_Transit: ['Delivered', 'Delivered'] })[d.delivery_status];
    var cod = d.cod_due > 0;
    return '<div class="card"><div class="top"><div><b>' + esc(d.order_number) + '</b><small>' + esc(d.customer_name || 'Customer') + (d.order_status === 'Ready' ? ' · food is ready' : ' · kitchen: ' + esc(String(d.order_status).toLowerCase())) + '</small></div><span class="pill" style="background:' + st[1] + '">' + st[0] + '</span></div>' +
      '<div class="addr">' + esc(d.address || 'No address given') + (d.landmark ? '<small>Near ' + esc(d.landmark) + '</small>' : '') + '</div>' +
      (d.items ? '<div class="items">' + esc(d.items) + '</div>' : '') +
      '<div class="cash' + (cod ? '' : ' paid') + '"><span class="ms">' + (cod ? 'payments' : 'check_circle') + '</span>' + (cod ? 'Collect ' + money(d.cod_due) + ' in cash' : d.payment_status === 'Paid' ? 'Already paid, collect nothing' : 'Paid online, collect nothing') + '</div>' +
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
    var h = '<h2>Your deliveries <span>' + D.mine.length + '</span></h2>' + (D.mine.length ? D.mine.map(function (d) { return card(d, false); }).join('') : '<div class="empty">Nothing to deliver right now. New deliveries show up here by themselves.</div>');
    if (D.self_pick && D.open.length) h += '<h2>Waiting for a rider <span>' + D.open.length + '</span></h2>' + D.open.map(function (d) { return card(d, true); }).join('');
    if (D.done_today.length) h += '<h2>Delivered today <span>' + D.done_today.length + '</span></h2><div class="card">' + D.done_today.map(function (d) {
      return '<div class="done"><span>' + esc(d.order_number) + ' · ' + esc(d.customer_name || '') + '</span><b>' + (d.cod_amount ? money(d.cod_amount) + ' cash' : 'paid') + '</b></div>';
    }).join('') + '</div>';
    if (D.restaurant && D.restaurant.phone) h += '<a class="btn" href="' + tel(D.restaurant.phone) + '"><span class="ms">storefront</span>Call the restaurant</a>';
    $('list').innerHTML = h;
    track(D.mine.some(function (d) { return ['Assigned', 'Picked_Up', 'In_Transit'].indexOf(d.delivery_status) !== -1; }));
  }
  function load() {
    return fetch(API, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.success) { if (/log in/i.test(d.message || '')) location.href = '../admin/login.php'; else $('list').innerHTML = '<div class="empty">' + esc(d.message) + '</div>'; return; }
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
