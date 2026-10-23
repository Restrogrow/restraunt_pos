<?php
/**
 * "Call waiter" on the customer website: a floating button and a small sheet (what do you need,
 * which table), sent to api/create_waiter_request.php. Calls show in Waiter Requests, the waiter
 * dashboard and the Partner app.
 *
 * Available only when the owner has it on (Waiter Requests › switch), dine-in is on and the restaurant
 * has tables. Even then the button shows only to guests eating in: they came from a table QR, or
 * picked Dine In (home page order tabs, or the order type on the cart page). A table QR code (?table=T4, remembered as $qr_table by header.php) fills the table in;
 * otherwise the guest picks it. Included near the end of each customer page (after header.php).
 */
if (empty($restaurant_id)) return;
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/waiter_call.php';
try {
    $wcConn = getConnection();
    if (!waiterCallAvailable($wcConn, (string)$restaurant_id)) return;
    $wcTables = [];
    $wcTable = null;
    $st = $wcConn->prepare('SELECT t.table_number, a.area_name FROM tables t LEFT JOIN areas a ON a.id = t.area_id WHERE t.restaurant_id = ? ORDER BY a.sort_order, a.area_name, t.sort_order, t.table_number');
    $st->execute([(string)$restaurant_id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $wcTables[] = $t;
        if (!empty($qr_table) && strcasecmp((string)$t['table_number'], (string)$qr_table) === 0) $wcTable = $t;
    }
} catch (Throwable $e) {
    error_log('call_waiter.php: ' . $e->getMessage());
    return;
}
$wcH = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<style>
  .rgcw-fab { position: fixed; right: 14px; bottom: calc(var(--rgcw-bottom, 92px) + env(safe-area-inset-bottom, 0px)); z-index: 990; display: inline-flex; align-items: center; gap: 8px; height: 50px; padding: 0 18px 0 14px; border: 0; border-radius: 999px; background: #111827; color: #fff; font: 600 14.5px/1 'Poppins', system-ui, sans-serif; box-shadow: 0 14px 30px -10px rgba(17,24,39,.55); cursor: pointer; transition: transform .18s ease, bottom .2s ease, background .2s; -webkit-tap-highlight-color: transparent; }
  .rgcw-fab[hidden] { display: none; }
  .rgcw-fab:active { transform: scale(.95); }
  .rgcw-fab .rgcw-ic { width: 30px; height: 30px; display: grid; place-items: center; border-radius: 50%; background: var(--primary-red, #e11d48); flex: none; }
  .rgcw-fab svg { width: 18px; height: 18px; }
  .rgcw-fab small { font-weight: 500; opacity: .7; margin-left: 2px; }
  .rgcw-fab.called { background: #15803d; }
  .rgcw-fab.called .rgcw-ic { background: rgba(255,255,255,.2); }
  .rgcw-fab.called .rgcw-ring { display: none; }
  .rgcw-fab .rgcw-ring { position: absolute; inset: -4px; border-radius: 999px; border: 2px solid var(--primary-red, #e11d48); opacity: 0; animation: rgcwRing 2.6s ease-out 1.2s 2; pointer-events: none; }
  @keyframes rgcwRing { 0% { opacity: .7; transform: scale(.96); } 100% { opacity: 0; transform: scale(1.12); } }

  .rgcw-back { position: fixed; inset: 0; z-index: 1300; background: rgba(17,24,39,.45); opacity: 0; pointer-events: none; transition: opacity .2s; }
  .rgcw-back.open { opacity: 1; pointer-events: auto; }
  .rgcw-sheet { position: fixed; left: 50%; bottom: 0; z-index: 1301; width: 100%; max-width: 480px; max-height: 88vh; overflow-y: auto; transform: translate(-50%, 105%); background: #fff; color: #111827; border-radius: 24px 24px 0 0; padding: 10px 18px calc(20px + env(safe-area-inset-bottom, 0px)); font-family: 'Poppins', system-ui, sans-serif; box-shadow: 0 -20px 50px -20px rgba(0,0,0,.35); transition: transform .28s cubic-bezier(.2,.9,.3,1); }
  .rgcw-sheet.open { transform: translate(-50%, 0); }
  .rgcw-grab { width: 42px; height: 5px; border-radius: 3px; background: #e5e7eb; margin: 2px auto 12px; }
  .rgcw-head { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
  .rgcw-head .rgcw-ic { width: 44px; height: 44px; border-radius: 14px; display: grid; place-items: center; background: color-mix(in srgb, var(--primary-red, #e11d48) 12%, #fff); color: var(--primary-red, #e11d48); flex: none; }
  .rgcw-head .rgcw-ic svg { width: 24px; height: 24px; }
  .rgcw-head h3 { margin: 0; font-size: 18px; font-weight: 700; }
  .rgcw-head p { margin: 2px 0 0; font-size: 13px; color: #6b7280; }
  .rgcw-x { margin-left: auto; width: 36px; height: 36px; border: 0; border-radius: 50%; background: #f3f4f6; color: #374151; font-size: 20px; line-height: 1; cursor: pointer; }
  .rgcw-label { display: block; margin: 0 0 8px; font-size: 12.5px; font-weight: 600; color: #6b7280; }
  .rgcw-table { width: 100%; height: 48px; margin-bottom: 14px; padding: 0 12px; border: 1.5px solid #e5e7eb; border-radius: 12px; background: #fff; font: 500 15px 'Poppins', system-ui, sans-serif; color: #111827; }
  .rgcw-reasons { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-bottom: 12px; }
  .rgcw-reasons button { display: flex; align-items: center; gap: 8px; min-height: 52px; padding: 8px 12px; border: 1.5px solid #e5e7eb; border-radius: 14px; background: #fff; color: #111827; font: 600 13.5px 'Poppins', system-ui, sans-serif; text-align: left; cursor: pointer; transition: border-color .15s, background .15s; }
  .rgcw-reasons button span { font-size: 20px; }
  .rgcw-reasons button.on { border-color: var(--primary-red, #e11d48); background: color-mix(in srgb, var(--primary-red, #e11d48) 8%, #fff); }
  .rgcw-reasons button:last-child:nth-child(odd) { grid-column: 1 / -1; }
  .rgcw-note { width: 100%; padding: 12px; margin-bottom: 14px; border: 1.5px solid #e5e7eb; border-radius: 12px; font: 400 15px 'Poppins', system-ui, sans-serif; resize: none; }
  .rgcw-go { width: 100%; height: 54px; border: 0; border-radius: 14px; background: var(--primary-red, #e11d48); color: #fff; font: 700 16px 'Poppins', system-ui, sans-serif; cursor: pointer; }
  .rgcw-go:disabled { opacity: .6; }
  .rgcw-err { min-height: 18px; margin: -6px 0 10px; color: #b91c1c; font-size: 13px; }
  .rgcw-done { display: none; text-align: center; padding: 10px 0 6px; }
  .rgcw-done .rgcw-check { width: 72px; height: 72px; margin: 6px auto 12px; border-radius: 50%; display: grid; place-items: center; background: #dcfce7; color: #15803d; animation: rgcwPop .4s cubic-bezier(.2,1.4,.4,1); }
  .rgcw-done .rgcw-check svg { width: 36px; height: 36px; }
  @keyframes rgcwPop { from { transform: scale(.6); opacity: 0; } to { transform: none; opacity: 1; } }
  .rgcw-done h3 { margin: 0 0 6px; font-size: 19px; }
  .rgcw-done p { margin: 0 0 16px; color: #6b7280; font-size: 14px; }
  .rgcw-sheet.sent .rgcw-form { display: none; } .rgcw-sheet.sent .rgcw-done { display: block; }
  @media (prefers-reduced-motion: reduce) { .rgcw-fab, .rgcw-sheet, .rgcw-back { transition: none; } .rgcw-fab .rgcw-ring, .rgcw-check { animation: none; } }
</style>

<button type="button" class="rgcw-fab" id="rgcwFab" aria-haspopup="dialog" aria-controls="rgcwSheet"<?php if (!$wcTable && empty($qr_table)) echo ' hidden'; ?>>
  <span class="rgcw-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg></span>
  <span id="rgcwFabText">Call waiter<?php if ($wcTable): ?> <small>· <?php echo $wcH($wcTable['table_number']); ?></small><?php endif; ?></span>
  <i class="rgcw-ring" aria-hidden="true"></i>
</button>
<div class="rgcw-back" id="rgcwBack"></div>
<div class="rgcw-sheet" id="rgcwSheet" role="dialog" aria-modal="true" aria-labelledby="rgcwTitle" aria-hidden="true">
  <div class="rgcw-grab"></div>
  <div class="rgcw-form">
    <div class="rgcw-head">
      <span class="rgcw-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg></span>
      <div><h3 id="rgcwTitle">Need anything?</h3><p><?php echo $wcTable ? 'Table ' . $wcH($wcTable['table_number']) . ($wcTable['area_name'] ? ' · ' . $wcH($wcTable['area_name']) : '') : 'We’ll send someone to your table'; ?></p></div>
      <button type="button" class="rgcw-x" data-rgcw-close aria-label="Close">×</button>
    </div>
    <?php if (!$wcTable): ?>
      <label class="rgcw-label" for="rgcwTable">Your table</label>
      <select class="rgcw-table" id="rgcwTable">
        <option value="">Choose your table</option>
        <?php $wcArea = null; foreach ($wcTables as $t): $a = (string)($t['area_name'] ?: 'Tables'); if ($a !== $wcArea): if ($wcArea !== null) echo '</optgroup>'; $wcArea = $a; echo '<optgroup label="' . $wcH($a) . '">'; endif; ?>
          <option value="<?php echo $wcH($t['table_number']); ?>"><?php echo $wcH($t['table_number']); ?></option>
        <?php endforeach; if ($wcArea !== null) echo '</optgroup>'; ?>
      </select>
    <?php endif; ?>
    <span class="rgcw-label">What do you need?</span>
    <div class="rgcw-reasons" role="radiogroup" aria-label="What do you need?">
      <button type="button" class="on" data-reason="waiter" role="radio" aria-checked="true"><span>🙋</span>Call a waiter</button>
      <button type="button" data-reason="bill" role="radio" aria-checked="false"><span>🧾</span>Bring the bill</button>
      <button type="button" data-reason="water" role="radio" aria-checked="false"><span>💧</span>Water</button>
      <button type="button" data-reason="extras" role="radio" aria-checked="false"><span>🍴</span>Cutlery / napkins</button>
      <button type="button" data-reason="order" role="radio" aria-checked="false"><span>📝</span>Ready to order</button>
    </div>
    <textarea class="rgcw-note" id="rgcwNote" rows="2" maxlength="200" placeholder="Anything else? (optional)"></textarea>
    <div class="rgcw-err" id="rgcwErr" role="alert"></div>
    <button type="button" class="rgcw-go" id="rgcwGo">Call waiter</button>
  </div>
  <div class="rgcw-done">
    <div class="rgcw-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></div>
    <h3 id="rgcwDoneTitle">A waiter is on the way</h3>
    <p id="rgcwDoneText">They’ve been notified and will be with you shortly.</p>
    <button type="button" class="rgcw-go" data-rgcw-close>Done</button>
  </div>
</div>
<script>
(function () {
  'use strict';
  var RID = <?php echo json_encode((string)$restaurant_id); ?>;
  var TABLE = <?php echo json_encode($wcTable ? (string)$wcTable['table_number'] : ''); ?>;
  var fab = document.getElementById('rgcwFab'), sheet = document.getElementById('rgcwSheet'), back = document.getElementById('rgcwBack');
  var go = document.getElementById('rgcwGo'), err = document.getElementById('rgcwErr'), reason = 'waiter';
  var KEY = 'rgcw:' + RID;
  function store(v) { try { if (v === undefined) return JSON.parse(sessionStorage.getItem(KEY) || 'null'); sessionStorage.setItem(KEY, JSON.stringify(v)); } catch (e) { return null; } }

  // keep the button above whatever sits at the bottom of the screen (bottom menu, cart bar).
  // A full scan finds the fixed/sticky bars now and then; in between only those are re-measured.
  var bars = [];
  function scan() {
    bars = [];
    document.querySelectorAll('body *').forEach(function (el) {
      if (el === fab || fab.contains(el) || el === back || el === sheet || sheet.contains(el)) return;
      var p = getComputedStyle(el).position;
      if (p === 'fixed' || p === 'sticky') bars.push(el);
    });
  }
  // Only guests eating in see the button: a table QR, or Dine In picked on the cart page (its
  // order type radios) or on the home page (saved as dvaniOrderType).
  function dineIn() {
    if (TABLE) return true;
    try { if (sessionStorage.getItem('qrTable')) return true; } catch (e) {}
    var r = document.querySelector('input[name="orderType"]:checked');
    if (r) return r.value === 'Dine-in';
    try { return localStorage.getItem('dvaniOrderType') === 'dinein'; } catch (e) { return false; }
  }
  function place() {
    var show = dineIn();
    fab.hidden = !show;
    if (!show) return;
    var h = window.innerHeight, top = h;
    bars.forEach(function (el) {
      if (!el.isConnected) return;
      var cs = getComputedStyle(el);
      if (cs.display === 'none' || cs.visibility === 'hidden') return;
      var r = el.getBoundingClientRect();
      if (r.height > 0 && r.height < h * 0.4 && r.width > window.innerWidth * 0.5 && r.bottom >= h - 30 && r.top < top) top = r.top;
    });
    fab.style.setProperty('--rgcw-bottom', Math.max(16, h - top + 12) + 'px');
  }
  scan(); place();
  window.addEventListener('resize', place);
  document.addEventListener('click', function () { setTimeout(place, 50); }, true);
  setInterval(place, 1200);
  setInterval(function () { scan(); place(); }, 8000);
  window.addEventListener('load', function () { scan(); place(); });

  function calledState() {
    var s = store();
    var recent = s && Date.now() - s.at < 120000;
    fab.classList.toggle('called', !!recent);
    document.getElementById('rgcwFabText').innerHTML = recent ? 'Waiter called ✓' : 'Call waiter' + (TABLE ? ' <small>· ' + TABLE.replace(/[<>&]/g, '') + '</small>' : '');
  }
  calledState(); setInterval(calledState, 10000);

  function open() {
    sheet.classList.remove('sent'); err.textContent = '';
    sheet.classList.add('open'); back.classList.add('open'); sheet.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }
  function close() {
    sheet.classList.remove('open'); back.classList.remove('open'); sheet.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }
  fab.addEventListener('click', open);
  back.addEventListener('click', close);
  sheet.addEventListener('click', function (e) {
    if (e.target.closest('[data-rgcw-close]')) return close();
    var b = e.target.closest('[data-reason]');
    if (!b) return;
    reason = b.getAttribute('data-reason');
    sheet.querySelectorAll('[data-reason]').forEach(function (x) { x.classList.toggle('on', x === b); x.setAttribute('aria-checked', x === b ? 'true' : 'false'); });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sheet.classList.contains('open')) close(); });

  go.addEventListener('click', function () {
    var sel = document.getElementById('rgcwTable');
    var table = TABLE || (sel ? sel.value : '');
    if (!table) { err.textContent = 'Please choose your table.'; if (sel) sel.focus(); return; }
    err.textContent = ''; go.disabled = true; go.textContent = 'Calling…';
    fetch(new URL('../api/create_waiter_request.php', document.baseURI).href, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
      body: JSON.stringify({ restaurant_id: RID, table_number: table, reason: reason, note: (document.getElementById('rgcwNote').value || '').trim() })
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.success) throw new Error(d.message || 'Could not call a waiter');
      document.getElementById('rgcwDoneTitle').textContent = d.already ? 'Already on the way' : 'A waiter is on the way';
      document.getElementById('rgcwDoneText').textContent = d.already ? 'We’ve updated your request for table ' + (d.table || table) + '.' : 'The staff have been told. Someone will be at table ' + (d.table || table) + ' shortly.';
      sheet.classList.add('sent');
      document.getElementById('rgcwNote').value = '';
      store({ at: Date.now(), table: table }); calledState();
      if (navigator.vibrate) navigator.vibrate(40);
    }).catch(function (e) {
      err.textContent = e.message || 'No connection. Please try again or ask the staff.';
    }).then(function () { go.disabled = false; go.textContent = 'Call waiter'; });
  });
})();
</script>
