<?php
/**
 * Undo design (Super Admin › Tools): pick how the Undo box looks when a restaurant deletes something
 * important (menu items, categories, staff, customers, bookings, tables, coupons, combos, stock items,
 * payment methods). Each design can be tried live here. Saved as sa_settings 'undo_design' and used by
 * every restaurant dashboard (assets/js/rg-undo.js, views/dashboard.php).
 */
require_once __DIR__ . '/auth.php';
require_superadmin();
require_once __DIR__ . '/../db_connection.php';

$conn = getConnection();
$conn->exec("CREATE TABLE IF NOT EXISTS sa_settings (k VARCHAR(60) PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$designs = [
    'pill'  => ['Dark pill', 'A dark rounded bar at the bottom centre with an orange Undo and a thin line running out. Calm, works on every page.'],
    'ring'  => ['Countdown ring', 'A white card at the bottom left with a ring counting down the seconds. Shows exactly how long is left.'],
    'snack' => ['Snackbar', 'The classic wide bar along the bottom with UNDO in capitals, like Gmail and Google apps. Familiar to everyone.'],
    'glass' => ['Floating glass', 'A frosted card that drops in at the top with a red bin and a big orange Undo button. The most eye-catching.'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    // same-site only (the page's own fetch)
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '')) { http_response_code(403); echo '{"success":false}'; exit; }
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $d = (string)($in['design'] ?? '');
    if (!isset($designs[$d])) { echo json_encode(['success' => false, 'message' => 'Unknown design']); exit; }
    $conn->prepare("REPLACE INTO sa_settings (k, v) VALUES ('undo_design', ?)")->execute([$d]);
    echo json_encode(['success' => true, 'design' => $d]);
    exit;
}

$current = (string)$conn->query("SELECT v FROM sa_settings WHERE k = 'undo_design'")->fetchColumn();
if (!isset($designs[$current])) $current = 'pill';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Undo design · Super Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<style>
  :root { --ink: #0f172a; --soft: #475569; --muted: #94a3b8; --line: #e2e8f0; --bg: #f5f7fb; --brand: #ea580c; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Inter, system-ui, -apple-system, 'Segoe UI', sans-serif; background: var(--bg); color: var(--ink); }
  main { max-width: 1100px; margin: 0 auto; padding: 24px 16px 120px; }
  .back { color: var(--soft); text-decoration: none; font-size: 14px; font-weight: 600; }
  .back:hover { color: var(--brand); }
  h1 { margin: 14px 0 6px; font-size: 28px; letter-spacing: -.01em; }
  .sub { margin: 0 0 22px; color: var(--soft); font-size: 15px; line-height: 1.55; max-width: 70ch; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 480px), 1fr)); gap: 16px; }
  .d { position: relative; background: #fff; border: 1.5px solid var(--line); border-radius: 18px; padding: 16px; display: flex; flex-direction: column; gap: 12px; transition: border-color .15s, box-shadow .15s; }
  .d.cur { border-color: var(--brand); box-shadow: 0 0 0 4px rgba(234, 88, 12, .10); }
  .d-h { display: flex; align-items: center; gap: 10px; }
  .d-h h2 { margin: 0; font-size: 17px; flex: 1; }
  .tag { font-size: 11.5px; font-weight: 800; padding: 4px 10px; border-radius: 999px; background: #ffedd5; color: #9a3412; display: none; }
  .d.cur .tag { display: inline-block; }
  .d p { margin: 0; color: var(--soft); font-size: 14px; line-height: 1.5; }
  /* a mini dashboard that the demo box appears over */
  .stage { position: relative; height: 230px; border-radius: 14px; overflow: hidden; background: linear-gradient(180deg, #f8fafc, #eef2f7); border: 1px solid var(--line); transform: translateZ(0); }
  .stage .rows { padding: 14px; display: grid; gap: 8px; }
  .stage .row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 12px; background: #fff; border: 1px solid #e8edf3; font-size: 13.5px; font-weight: 600; }
  .stage .row i { width: 30px; height: 30px; border-radius: 9px; flex: none; }
  .stage .row span { flex: 1; }
  .stage .row em { font-style: normal; color: var(--muted); font-weight: 600; }
  .stage .row button { border: 0; background: #fef2f2; color: #dc2626; width: 30px; height: 30px; border-radius: 9px; cursor: pointer; display: grid; place-items: center; }
  .stage .row button svg { width: 16px; height: 16px; }
  /* the demo box lives inside the stage, not the page */
  .stage .rgu-host { position: absolute !important; }
  .stage .rgu-host.d-pill { bottom: 14px; }
  .stage .rgu-host.d-ring { left: 12px; bottom: 12px; }
  .stage .rgu-host.d-snack { width: 100%; padding: 0 10px 10px; }
  .stage .rgu-host.d-glass { top: 10px; }
  .stage .rgu-host .rgu { max-width: calc(100% - 20px) !important; }
  .stage .rgu-host.d-snack .rgu, .stage .rgu-host.d-ring .rgu { max-width: 100% !important; }
  .stage .rgu-host.d-pill .rgu { min-width: 0; width: auto; }
  .stage .rgu-host.d-ring { right: 12px; }
  .acts { display: flex; gap: 8px; flex-wrap: wrap; }
  .btn { display: inline-flex; align-items: center; gap: 8px; height: 42px; padding: 0 16px; border-radius: 11px; border: 1.5px solid var(--line); background: #fff; color: var(--ink); font: inherit; font-size: 14px; font-weight: 700; cursor: pointer; }
  .btn:hover { border-color: #cbd5e1; }
  .btn.pri { background: var(--ink); border-color: var(--ink); color: #fff; }
  .btn.pri:hover { background: #1e293b; }
  .btn:disabled { opacity: .55; cursor: default; }
  .btn svg { width: 17px; height: 17px; }
  .d.cur .btn.pri { background: #16a34a; border-color: #16a34a; }
  .info { margin-top: 26px; padding: 16px 18px; border-radius: 14px; background: #fff; border: 1px solid var(--line); color: var(--soft); font-size: 14px; line-height: 1.6; }
  .info b { color: var(--ink); }
  .toast { position: fixed; left: 50%; top: 18px; transform: translateX(-50%); padding: 10px 16px; border-radius: 10px; background: #16a34a; color: #fff; font-weight: 700; font-size: 14px; box-shadow: 0 10px 24px -10px rgba(0,0,0,.4); opacity: 0; transition: opacity .2s; pointer-events: none; z-index: 10; }
  .toast.on { opacity: 1; }
</style>
</head>
<body>
<main>
  <a class="back" href="dashboard.php">← Super Admin</a>
  <h1>Undo design</h1>
  <p class="sub">When a restaurant deletes something important, it disappears at once and a box offers <b>Undo</b> for 6 seconds. Nothing is deleted on the server until the time is up. Press <b>Try it</b> to see each design, then <b>Use this</b> for every restaurant dashboard.</p>

  <div class="grid">
    <?php foreach ($designs as $key => $d): ?>
    <section class="d<?php echo $key === $current ? ' cur' : ''; ?>" data-design="<?php echo $h($key); ?>">
      <div class="d-h"><h2><?php echo $h($d[0]); ?></h2><span class="tag">In use</span></div>
      <p><?php echo $h($d[1]); ?></p>
      <div class="stage" aria-label="Preview of <?php echo $h($d[0]); ?>">
        <div class="rows">
          <?php foreach ([['Paneer Tikka', '₹280', '#fde68a'], ['Veg Biryani', '₹240', '#bbf7d0'], ['Masala Dosa', '₹120', '#bfdbfe']] as $i => $r): ?>
          <div class="row" data-i="<?php echo $i; ?>"><i style="background:<?php echo $r[2]; ?>"></i><span><?php echo $h($r[0]); ?></span><em><?php echo $h($r[1]); ?></em>
            <button type="button" data-del aria-label="Delete <?php echo $h($r[0]); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg></button></div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="acts">
        <button type="button" class="btn" data-try><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4l14 8-14 8z"/></svg>Try it</button>
        <button type="button" class="btn pri" data-use><?php echo $key === $current ? 'In use' : 'Use this'; ?></button>
      </div>
    </section>
    <?php endforeach; ?>
  </div>

  <div class="info">
    <b>Where Undo appears:</b> deleting a menu item, menu or subcategory, a staff member, a customer, a booking, a table (one or many), a coupon, a combo, a stock item or a payment method. Restaurants can also press <b>Ctrl+Z</b>. If they close the page during the countdown, the delete still goes through. If the server refuses, the item comes back with the reason.
    The new design shows the next time a restaurant opens or refreshes its dashboard.
  </div>
</main>
<div class="toast" id="toast" role="status"></div>

<script>window.RG_UNDO_DESIGN = <?php echo json_encode($current); ?>;</script>
<script src="../assets/js/rg-undo.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/rg-undo.js'); ?>"></script>
<script>
(function () {
  // each stage gets its own box host, so four designs can show side by side
  function demo(card, row) {
    var stage = card.querySelector('.stage'), design = card.dataset.design;
    var old = document.getElementById('rguHost');
    if (old) old.id = 'rguHost-parked';
    var host = stage.querySelector('.rgu-host');
    if (host) host.id = 'rguHost';
    var r = row || stage.querySelector('.row:not(.rgu-gone)') || stage.querySelector('.row');
    var name = r.querySelector('span').textContent;
    rgUndo.preview(design, { html: 'Deleted <b>“' + name + '”</b>', hide: r });
    host = document.getElementById('rguHost');
    if (host.parentNode !== stage) stage.appendChild(host);
    host.id = 'rguHost-' + design;
    if (old) old.id = 'rguHost';
    // a preview "commits" after 6 s: bring the row back so it can be tried again
    setTimeout(function () { r.style.display = ''; r.classList.remove('rgu-gone'); }, 6400);
  }
  document.addEventListener('click', function (e) {
    var card = e.target.closest('.d');
    if (!card) return;
    if (e.target.closest('[data-try]')) demo(card);
    else if (e.target.closest('[data-del]')) demo(card, e.target.closest('.row'));
    else if (e.target.closest('[data-use]')) {
      var b = e.target.closest('[data-use]');
      if (card.classList.contains('cur')) return;
      b.disabled = true;
      fetch('undo_designs.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ design: card.dataset.design }) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          b.disabled = false;
          if (!d.success) return;
          document.querySelectorAll('.d').forEach(function (c) { c.classList.toggle('cur', c === card); c.querySelector('[data-use]').textContent = c === card ? 'In use' : 'Use this'; });
          var t = document.getElementById('toast');
          t.textContent = card.querySelector('h2').textContent + ' is now used on every dashboard';
          t.classList.add('on'); setTimeout(function () { t.classList.remove('on'); }, 2600);
        })
        .catch(function () { b.disabled = false; });
    }
  });
})();
</script>
</body>
</html>
