<?php
/**
 * Loading screens (Super Admin › Tools): 12 animated loaders made from the RestroGrow logo.
 * Each can be tried live here (light/dark, speed, full screen). Pick one loader for each place:
 * dashboard opening, page change, and section/button loading. Saved as sa_settings 'loader_settings'
 * (JSON: {dash, page, section, speed, enabled}). Styles: assets/loaders/rg-loaders.css.
 * The restaurant and Super Admin dashboards show the Bouncing G full screen on load / refresh / page change
 * (includes/rg_boot_loader.php), switched off here with "Show loading screens".
 */
require_once __DIR__ . '/auth.php';
require_superadmin();
require_once __DIR__ . '/../db_connection.php';

$conn = getConnection();
$conn->exec("CREATE TABLE IF NOT EXISTS sa_settings (k VARCHAR(60) PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$ids = ['fill', 'shimmer', 'gspin', 'bounce', 'reveal', 'ring', 'steam', 'orbit', 'skeleton', 'topbar', 'inline', 'pulse'];
$defaults = ['dash' => 'bounce', 'page' => 'topbar', 'section' => 'inline', 'speed' => 1, 'enabled' => true];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) { http_response_code(403); echo '{"success":false}'; exit; }
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $out = [];
    foreach (['dash', 'page', 'section'] as $k) {
        $v = (string)($in[$k] ?? '');
        if (!in_array($v, $ids, true)) { echo json_encode(['success' => false, 'message' => 'Pick a loader for every place.']); exit; }
        $out[$k] = $v;
    }
    $out['speed'] = max(0.5, min(2, round((float)($in['speed'] ?? 1) * 4) / 4));
    $out['enabled'] = !empty($in['enabled']);
    $conn->prepare("REPLACE INTO sa_settings (k, v) VALUES ('loader_settings', ?)")->execute([json_encode($out)]);
    echo json_encode(['success' => true, 'settings' => $out]);
    exit;
}

$saved = json_decode((string)$conn->query("SELECT v FROM sa_settings WHERE k = 'loader_settings'")->fetchColumn(), true);
$cur = is_array($saved) ? array_merge($defaults, $saved) : $defaults;
foreach (['dash', 'page', 'section'] as $k) if (!in_array($cur[$k], $ids, true)) $cur[$k] = $defaults[$k];
$cssV = @filemtime(__DIR__ . '/../assets/loaders/rg-loaders.css') ?: 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Loading screens · Super Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Montserrat:wght@700&family=Poppins:wght@500;600&display=swap">
<link rel="stylesheet" href="../assets/loaders/rg-loaders.css?v=<?php echo (int)$cssV; ?>">
<style>
  :root { --ink: #0f172a; --soft: #475569; --muted: #94a3b8; --line: #e2e8f0; --bg: #f5f7fb; --brand: #ea580c; --soft-brand: #ffedd5; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Inter, system-ui, -apple-system, 'Segoe UI', sans-serif; background: var(--bg); color: var(--ink); }
  main { max-width: 1180px; margin: 0 auto; padding: 24px 16px 120px; display: grid; gap: 20px; }
  .back { color: var(--soft); text-decoration: none; font-size: 14px; font-weight: 600; }
  .back:hover { color: var(--brand); }
  h1 { margin: 10px 0 4px; font-size: 28px; letter-spacing: -.01em; }
  .sub { margin: 0; color: var(--soft); font-size: 15px; line-height: 1.55; max-width: 74ch; }
  button:focus-visible, input:focus-visible, select:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }

  /* the three places + save */
  .picks { background: #fff; border: 1.5px solid var(--line); border-radius: 18px; padding: 16px; display: grid; gap: 14px; }
  .picks-h { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; justify-content: space-between; }
  .picks-h h2 { margin: 0; font-size: 17px; }
  .slots { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
  .slot { border: 1.5px dashed var(--line); border-radius: 14px; padding: 12px; display: grid; gap: 6px; }
  .slot.active { border-style: solid; border-color: var(--brand); background: #fffaf6; }
  .slot b { font-size: 14px; }
  .slot small { color: var(--soft); font-size: 12.5px; line-height: 1.4; }
  .slot select { font: 600 14px Inter, sans-serif; padding: 7px 9px; border-radius: 10px; border: 1px solid var(--line); background: #fff; color: var(--ink); }
  .row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
  .row label { font-size: 13.5px; color: var(--soft); font-weight: 600; display: inline-flex; align-items: center; gap: 8px; }
  input[type=range] { accent-color: var(--brand); width: 140px; }
  input[type=checkbox] { accent-color: var(--brand); width: 17px; height: 17px; }
  .val { font-variant-numeric: tabular-nums; font-weight: 700; color: var(--ink); min-width: 3ch; }
  .btn { border: 1px solid var(--line); background: #fff; color: var(--ink); font: 700 13.5px Inter, sans-serif; padding: 9px 14px; border-radius: 11px; cursor: pointer; }
  .btn.primary { background: var(--brand); border-color: var(--brand); color: #fff; }
  .btn:disabled { opacity: .55; cursor: default; }
  .msg { font-size: 13.5px; font-weight: 600; color: #15803d; }
  .msg.err { color: #dc2626; }

  .toolbar { display: flex; flex-wrap: wrap; gap: 10px 18px; align-items: center; }
  .seg { display: inline-flex; border: 1px solid var(--line); border-radius: 999px; padding: 3px; gap: 2px; background: #fff; }
  .seg button, .chip { border: 0; background: transparent; color: var(--ink); font: 600 13px Inter, sans-serif; padding: 6px 12px; border-radius: 999px; cursor: pointer; }
  .seg button[aria-pressed="true"] { background: var(--brand); color: #fff; }
  .chip { border: 1px solid var(--line); background: #fff; }
  .chip[aria-pressed="true"] { border-color: var(--brand); background: var(--soft-brand); }

  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 330px), 1fr)); gap: 16px; }
  .card { background: #fff; border: 1.5px solid var(--line); border-radius: 18px; overflow: hidden; display: grid; grid-template-rows: auto 1fr; }
  .card.is-used { border-color: var(--brand); box-shadow: 0 0 0 4px rgba(234, 88, 12, .10); }
  .card .stage { position: relative; aspect-ratio: 16 / 10; max-width: 100%; background: var(--stage-bg); display: grid; place-items: center; overflow: hidden; border-bottom: 1px solid var(--line); color: var(--stage-ink); }
  .meta { padding: 14px 16px 16px; display: grid; gap: 8px; align-content: start; }
  .meta .top { display: flex; align-items: baseline; gap: 10px; justify-content: space-between; }
  .meta h3 { margin: 0; font-size: 16px; }
  .meta code { font-size: 12px; color: var(--soft); background: var(--bg); border: 1px solid var(--line); border-radius: 6px; padding: 1px 6px; }
  .meta p { margin: 0; color: var(--soft); font-size: 13.5px; line-height: 1.5; }
  .tags { display: flex; gap: 6px; flex-wrap: wrap; }
  .tag { font-size: 11.5px; font-weight: 700; padding: 3px 9px; border-radius: 999px; background: var(--soft-brand); color: #9a3412; }
  .tag.on { background: var(--brand); color: #fff; }
  .acts { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px; }
  .acts .btn { padding: 7px 10px; font-size: 12.5px; }

  .fs { position: fixed; inset: 0; z-index: 50; display: grid; place-items: center; background: var(--stage-bg); color: var(--stage-ink); }
  .fs .stage { width: min(820px, 100%); aspect-ratio: 16 / 10; display: grid; place-items: center; position: relative; overflow: hidden; background: var(--stage-bg); }
  .fs .close { position: absolute; top: 14px; right: 14px; }
  .fs .done { position: absolute; inset: 0; display: grid; place-items: center; background: var(--stage-bg); animation: fadeIn .4s ease both; text-align: center; }
  .fs .done .in { display: grid; gap: 8px; justify-items: center; }
  .fs .done b { font: 700 22px Montserrat, Inter, sans-serif; }
  .fs .done span { opacity: .6; font-size: 14px; }
  @keyframes fadeIn { from { opacity: 0; } }
  .card[hidden] { display: none !important; }
  /* these two fill the whole stage instead of sitting in the middle */
  .card .stage.l-skel, .card .stage.l-top, .fs .stage.l-skel, .fs .stage.l-top { place-items: stretch; }
</style>
</head>
<body>
<main>
  <div>
    <a class="back" href="dashboard.php">← Super Admin</a>
    <h1>Loading screens</h1>
    <p class="sub">12 loaders made from the RestroGrow logo. Try each one below, then choose which one shows in each place and press <b>Save</b>. Your choice is saved now; restaurant dashboards start using it once the loaders are switched on there.</p>
  </div>

  <section class="picks" aria-labelledby="picksH">
    <div class="picks-h">
      <h2 id="picksH">Where each loader shows</h2>
      <div class="row">
        <label for="spd">Speed <input type="range" id="spd" min="0.5" max="2" step="0.25" value="<?php echo htmlspecialchars((string)$cur['speed']); ?>"><span class="val" id="spdVal"></span></label>
        <label for="enabled"><input type="checkbox" id="enabled" <?php echo $cur['enabled'] ? 'checked' : ''; ?>> Loaders on</label>
        <button type="button" class="btn primary" id="save">Save</button>
        <span class="msg" id="msg" role="status"></span>
      </div>
    </div>
    <div class="slots">
      <div class="slot" data-slot="dash"><b>Dashboard opening</b><small>After login, while the restaurant dashboard loads.</small><select id="sel-dash" aria-label="Loader for dashboard opening"></select></div>
      <div class="slot" data-slot="page"><b>Page change</b><small>Moving between Menu, Orders, Reports and other pages.</small><select id="sel-page" aria-label="Loader for page change"></select></div>
      <div class="slot" data-slot="section"><b>Section &amp; button</b><small>A card or table still loading, and buttons like Save or Print bill.</small><select id="sel-section" aria-label="Loader for sections and buttons"></select></div>
    </div>
  </section>

  <div class="toolbar" role="group" aria-label="Preview settings">
    <div class="seg"><button type="button" id="stLight" aria-pressed="true">Light stage</button><button type="button" id="stDark" aria-pressed="false">Dark stage</button></div>
    <div class="seg" id="filters" style="gap:4px;padding:0;border:0;background:transparent">
      <button type="button" class="chip" data-f="all" aria-pressed="true">All</button>
      <button type="button" class="chip" data-f="dash" aria-pressed="false">Dashboard opening</button>
      <button type="button" class="chip" data-f="page" aria-pressed="false">Page change</button>
      <button type="button" class="chip" data-f="section" aria-pressed="false">Section / button</button>
    </div>
  </div>

  <div class="grid" id="grid"></div>
</main>

<script>
const RG_LOADERS = [
  { id: 'fill', name: 'Liquid fill', cls: 'l-fill', uses: ['dash', 'page'], desc: 'Orange fills up through the logo like a glass of mango lassi, then loops.',
    html: '<div class="lg"><i class="mask-all base"></i><i class="liquid"></i></div>' },
  { id: 'shimmer', name: 'Shimmer sweep', cls: 'l-shimmer', uses: ['page', 'dash'], desc: 'A soft light sweeps across the logo, with a thin bar sliding underneath.',
    html: '<div class="col"><div class="lg"><i class="mask-ink"></i><i class="mask-g"></i><i class="mask-all shine"></i></div><div class="bar"></div></div>' },
  { id: 'gspin', name: 'G flip spinner', cls: 'l-gspin', uses: ['section', 'page'], desc: 'The orange G flips in 3D inside a spinning ring. Works small and large.',
    html: '<div class="col"><div class="spin"><i class="arc"></i><i class="gm gmark"></i></div><span class="cap">Loading your restaurant…</span></div>' },
  { id: 'bounce', name: 'Bouncing G', cls: 'l-bounce', uses: ['dash', 'page'], desc: 'The G hops with a little squash and a shadow while the rest of the name waits.',
    html: '<div class="lg"><i class="mask-ink"></i><i class="gpos gmark"></i><i class="shadow"></i></div>' },
  { id: 'reveal', name: 'Write-on reveal', cls: 'l-reveal', uses: ['dash'], desc: 'The logo writes itself left to right in step with a progress bar. Good first impression after login.',
    html: '<div class="col"><div class="lg"><i class="mask-ink ghost"></i><i class="mask-g ghost"></i><i class="mask-ink wipe"></i><i class="mask-g wipe"></i></div><div class="track"><i></i></div></div>' },
  { id: 'ring', name: 'Progress ring + steps', cls: 'l-ring', uses: ['dash'], desc: 'Percent around the G with what is loading right now: menu, orders, customers, reports.',
    html: '<div class="col"><div class="ringbox"><svg viewBox="0 0 100 100" aria-hidden="true"><circle class="bg" cx="50" cy="50" r="46"/><circle class="fg" cx="50" cy="50" r="46"/></svg><i class="gm gmark"></i></div><span class="pct">0%</span><span class="cap step">Loading menu…</span></div>' },
  { id: 'steam', name: 'Hot plate', cls: 'l-steam', uses: ['page', 'section'], desc: 'The G sits on a plate with steam rising, like food fresh from the kitchen.',
    html: '<div class="col"><div class="plate"><svg viewBox="0 0 70 56" aria-hidden="true"><path d="M18 52c-6-8 6-12 0-22s6-14 2-24"/><path d="M35 52c-6-8 6-12 0-22s6-14 2-24"/><path d="M52 52c-6-8 6-12 0-22s6-14 2-24"/></svg><i class="gm gmark"></i><i class="dish"></i></div><span class="cap">Cooking up your dashboard…</span></div>' },
  { id: 'orbit', name: 'Orbit', cls: 'l-orbit', uses: ['section', 'page'], desc: 'Three dots circle the G at different speeds, like orders coming in.',
    html: '<div class="orb"><i class="ring"></i><i class="o" style="--t:1.6s;--s:12px;--c:var(--orange)"></i><i class="o" style="--t:2.4s;--s:9px;--c:var(--stage-ink);--y:14px"></i><i class="o" style="--t:3.4s;--s:7px;--c:var(--orange);--y:26px;opacity:.5"></i><i class="gm gmark"></i></div>' },
  { id: 'skeleton', name: 'Dashboard skeleton', cls: 'l-skel', uses: ['dash', 'section'], desc: 'The dashboard layout appears at once with shimmering blocks, so it feels fast even on slow internet.',
    html: '<div class="app"><div class="side"><div class="lg"><i class="mask-ink"></i><i class="mask-g"></i></div><i class="sk line"></i><i class="sk line"></i><i class="sk line"></i><i class="sk line"></i><i class="sk line"></i></div><div class="main"><i class="sk line" style="width:40%;height:12px"></i><div class="tiles"><i class="sk tile"></i><i class="sk tile"></i><i class="sk tile"></i></div><i class="sk big"></i></div></div>' },
  { id: 'topbar', name: 'Top bar', cls: 'l-top', uses: ['page'], desc: 'A thin orange bar runs across the top while the next page loads; the small G spins in the header.',
    html: '<div class="page"><div class="bar"><i></i></div><div><div class="nav"><div class="lg"><i class="mask-ink"></i><i class="mask-g"></i></div><i class="gm gmark"></i></div><div class="body"><span style="width:60%"></span><span></span><span style="width:80%"></span><span style="width:45%"></span></div></div></div>' },
  { id: 'inline', name: 'Inline + button', cls: 'l-inline', uses: ['section'], desc: 'For a card or table that is still loading, and for buttons like Save or Print bill.',
    html: '<div class="demo"><span class="tiny"><i class="gm gmark"></i>Loading orders <span class="dots"><i></i><i></i><i></i></span></span><span class="fakebtn"><i class="sp"></i>Saving…</span></div>' },
  { id: 'pulse', name: 'Heartbeat', cls: 'l-pulse', uses: ['dash', 'page'], desc: 'The full logo beats twice with a warm orange glow behind it. Simple splash screen.',
    html: '<i class="glow"></i><div class="lg"><i class="mask-ink"></i><i class="mask-g"></i></div>' },
];
const PLACE = { dash: 'Dashboard opening', page: 'Page change', section: 'Section / button' };
const CUR = <?php echo json_encode(['dash' => $cur['dash'], 'page' => $cur['page'], 'section' => $cur['section']]); ?>;
const root = document.documentElement;
const grid = document.getElementById('grid');

grid.innerHTML = RG_LOADERS.map((l, i) => `
  <article class="card" data-uses="${l.uses.join(' ')}" id="card-${l.id}">
    <div class="stage ${l.cls}">${l.html}</div>
    <div class="meta">
      <div class="top"><h3>${i + 1}. ${l.name}</h3><code>${l.id}</code></div>
      <p>${l.desc}</p>
      <div class="tags" data-tags="${l.id}"></div>
      <div class="acts">
        <button type="button" class="btn" data-fs="${l.id}">Try full screen</button>
        ${Object.keys(PLACE).map(p => `<button type="button" class="btn" data-use="${l.id}" data-place="${p}">Use for ${PLACE[p].toLowerCase()}</button>`).join('')}
      </div>
    </div>
  </article>`).join('');

/* selects + "in use" marks */
['dash', 'page', 'section'].forEach(p => {
  const s = document.getElementById('sel-' + p);
  s.innerHTML = RG_LOADERS.map(l => `<option value="${l.id}">${l.name}</option>`).join('');
  s.value = CUR[p];
  s.onchange = () => { CUR[p] = s.value; marks(); dirty(); };
});
function marks() {
  RG_LOADERS.forEach(l => {
    const on = Object.keys(PLACE).filter(p => CUR[p] === l.id);
    document.getElementById('card-' + l.id).classList.toggle('is-used', on.length > 0);
    document.querySelector(`[data-tags="${l.id}"]`).innerHTML =
      l.uses.map(u => `<span class="tag${CUR[u] === l.id ? ' on' : ''}">${PLACE[u]}${CUR[u] === l.id ? ' ✓' : ''}</span>`).join('') +
      on.filter(p => !l.uses.includes(p)).map(p => `<span class="tag on">${PLACE[p]} ✓</span>`).join('');
  });
}
marks();
grid.addEventListener('click', e => {
  const u = e.target.closest('[data-use]');
  if (u) { CUR[u.dataset.place] = u.dataset.use; document.getElementById('sel-' + u.dataset.place).value = u.dataset.use; marks(); dirty(); flashSlot(u.dataset.place); return; }
  const f = e.target.closest('[data-fs]');
  if (f) openFs(f.dataset.fs);
});
function flashSlot(p) { const s = document.querySelector(`[data-slot="${p}"]`); s.classList.add('active'); setTimeout(() => s.classList.remove('active'), 900); }

/* progress ring */
const STEPS = ['Loading menu…', 'Fetching today’s orders…', 'Syncing customers…', 'Preparing reports…', 'Almost ready…'];
function runRings(scope) {
  (scope || document).querySelectorAll('.l-ring').forEach(st => {
    if (st._t) return;
    let p = 0;
    const fg = st.querySelector('.fg'), pct = st.querySelector('.pct'), step = st.querySelector('.step');
    st._t = setInterval(() => {
      const sp = parseFloat(getComputedStyle(root).getPropertyValue('--spd')) || 1;
      p += (p < 70 ? 2.2 : p < 92 ? 1 : 2.6) * sp;
      if (p > 112) p = 0;
      const v = Math.min(100, p);
      fg.style.strokeDashoffset = 289 - 289 * v / 100;
      pct.textContent = Math.round(v) + '%';
      step.textContent = v >= 100 ? 'Ready' : STEPS[Math.min(STEPS.length - 1, Math.floor(v / 20))];
    }, 50);
  });
}
runRings();

/* speed / stage / filter */
const spd = document.getElementById('spd'), spdVal = document.getElementById('spdVal');
function applySpd() { root.style.setProperty('--spd', spd.value); spdVal.textContent = spd.value + '×'; }
spd.oninput = () => { applySpd(); dirty(); };
applySpd();
document.getElementById('enabled').onchange = dirty;
let stageDark = false;
const stL = document.getElementById('stLight'), stD = document.getElementById('stDark');
function setStage(d) { stageDark = d; document.body.classList.toggle('stages-dark', d); stL.setAttribute('aria-pressed', !d); stD.setAttribute('aria-pressed', d); }
stL.onclick = () => setStage(false); stD.onclick = () => setStage(true);
document.getElementById('filters').onclick = e => {
  const b = e.target.closest('.chip'); if (!b) return;
  document.querySelectorAll('#filters .chip').forEach(c => c.setAttribute('aria-pressed', c === b));
  document.querySelectorAll('.card').forEach(c => { c.hidden = b.dataset.f !== 'all' && !c.dataset.uses.split(' ').includes(b.dataset.f); });
};

/* full screen try-out */
let fsEl = null, fsT = null;
function closeFs() { if (!fsEl) return; clearTimeout(fsT); fsEl.querySelectorAll('.l-ring').forEach(r => clearInterval(r._t)); fsEl.remove(); fsEl = null; }
function openFs(id) {
  const l = RG_LOADERS.find(x => x.id === id);
  closeFs();
  fsEl = document.createElement('div');
  fsEl.className = 'fs rgl-root' + (stageDark ? ' dark' : '');
  fsEl.setAttribute('role', 'dialog'); fsEl.setAttribute('aria-label', l.name + ' preview');
  fsEl.innerHTML = `<div class="stage ${l.cls}${stageDark ? ' dark' : ''}">${l.html}</div><button type="button" class="btn close">Close</button>`;
  document.body.appendChild(fsEl);
  fsEl.querySelector('.close').onclick = closeFs;
  fsEl.querySelector('.close').focus();
  runRings(fsEl);
  fsT = setTimeout(() => {
    if (!fsEl) return;
    const d = document.createElement('div'); d.className = 'done';
    d.innerHTML = '<div class="in"><div class="lg" style="width:220px"><i class="mask-ink"></i><i class="mask-g"></i></div><b>Dashboard ready</b><span>Here the real screen opens. Press Close or Esc.</span></div>';
    fsEl.querySelector('.stage').appendChild(d);
  }, 3600 / (parseFloat(spd.value) || 1));
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeFs(); });

/* save */
const saveBtn = document.getElementById('save'), msg = document.getElementById('msg');
function dirty() { msg.className = 'msg'; msg.textContent = 'Not saved yet'; msg.style.color = 'var(--soft)'; }
saveBtn.onclick = async () => {
  saveBtn.disabled = true; msg.style.color = ''; msg.textContent = 'Saving…';
  try {
    const r = await fetch('loading_screens.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ dash: CUR.dash, page: CUR.page, section: CUR.section, speed: parseFloat(spd.value), enabled: document.getElementById('enabled').checked }) });
    const j = await r.json();
    if (!j.success) throw new Error(j.message || 'Could not save');
    msg.className = 'msg'; msg.textContent = 'Saved';
  } catch (err) {
    msg.className = 'msg err'; msg.textContent = (err.message || 'Could not save') + '. Check your connection and try again.';
  } finally { saveBtn.disabled = false; }
};
</script>
</body>
</html>
