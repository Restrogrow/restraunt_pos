<?php
/**
 * A thin bar at the top of the menu and cart while the guest came from a table's QR code ($qr_table from
 * header.php): "Table T4", and once anyone at the table has ordered "· Aman, Bina ordered · ₹757 · See".
 * Tapping it opens the table's live order (api/table_session.php, config/table_session.php): who ordered
 * what, its status, the table total. Updates every 10 s while the page is open. Nothing for a hotel room.
 */
if (empty($qr_table) || !empty($qr_room) || empty($restaurant_id)) return;
$tsbH = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<button type="button" class="rg-tablebar" id="rgTableBar" aria-haspopup="dialog" aria-controls="rgTableSheet">
  <i class="fa-solid fa-utensils" aria-hidden="true"></i>
  <span id="rgTableBarText"><b>Table <?php echo $tsbH($qr_table); ?></b> · your order comes to your table</span>
  <span class="rg-tb-see" id="rgTableBarSee" hidden>See</span>
</button>
<div class="rg-tb-back" id="rgTableBack"></div>
<div class="rg-tb-sheet" id="rgTableSheet" role="dialog" aria-modal="true" aria-labelledby="rgTableSheetTitle" aria-hidden="true">
  <div class="rg-tb-grab"></div>
  <div class="rg-tb-head">
    <div><h3 id="rgTableSheetTitle">Table <?php echo $tsbH($qr_table); ?> · one bill</h3><p id="rgTableSheetSub">Everyone at the table orders from their own phone</p></div>
    <button type="button" class="rg-tb-x" data-rg-tb-close aria-label="Close">×</button>
  </div>
  <div id="rgTableSheetList"></div>
  <div class="rg-tb-sum" id="rgTableSheetSum"></div>
</div>
<style>
.rg-tablebar { display: flex; align-items: center; gap: 8px; width: 100%; justify-content: center; padding: 8px 12px; border: 0; font-size: 12.5px; cursor: pointer;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031)); color: #fff; font-family: 'Poppins', sans-serif; text-align: left; }
.rg-tablebar b { font-weight: 700; }
.rg-tablebar #rgTableBarText { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rg-tb-see { flex: none; padding: 2px 10px; border-radius: 999px; background: rgba(255,255,255,.22); font-weight: 700; }
.rg-tb-back { position: fixed; inset: 0; z-index: 1300; background: rgba(17,24,39,.45); opacity: 0; pointer-events: none; transition: opacity .2s; }
.rg-tb-back.open { opacity: 1; pointer-events: auto; }
.rg-tb-sheet { position: fixed; left: 50%; bottom: 0; z-index: 1301; width: 100%; max-width: 480px; max-height: 82vh; overflow-y: auto; transform: translate(-50%, 105%);
  background: #fff; color: #111827; border-radius: 22px 22px 0 0; padding: 10px 18px calc(18px + env(safe-area-inset-bottom, 0px)); font-family: 'Poppins', system-ui, sans-serif;
  box-shadow: 0 -20px 50px -20px rgba(0,0,0,.35); transition: transform .28s cubic-bezier(.2,.9,.3,1); }
.rg-tb-sheet.open { transform: translate(-50%, 0); }
.rg-tb-grab { width: 42px; height: 5px; border-radius: 3px; background: #e5e7eb; margin: 2px auto 12px; }
.rg-tb-head { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 6px; }
.rg-tb-head h3 { margin: 0; font-size: 17px; font-weight: 700; }
.rg-tb-head p { margin: 2px 0 0; font-size: 12.5px; color: #6b7280; }
.rg-tb-x { margin-left: auto; width: 34px; height: 34px; border: 0; border-radius: 50%; background: #f3f4f6; color: #374151; font-size: 20px; line-height: 1; cursor: pointer; flex: none; }
.rg-tb-o { padding: 10px 0; border-bottom: 1px dashed #e5e7eb; }
.rg-tb-o .h { display: flex; align-items: baseline; gap: 8px; }
.rg-tb-o .h b { font-size: 14px; }
.rg-tb-o .h small { font-size: 11.5px; color: #6b7280; }
.rg-tb-o .h span { margin-left: auto; font-weight: 700; font-size: 13.5px; }
.rg-tb-o .it { margin-top: 3px; font-size: 12.5px; color: #6b7280; line-height: 1.5; }
.rg-tb-sum { padding-top: 10px; }
.rg-tb-sum div { display: flex; justify-content: space-between; padding: 3px 0; font-size: 14px; }
.rg-tb-sum b { font-size: 16px; }
.rg-tb-empty { padding: 18px 0; text-align: center; color: #6b7280; font-size: 13.5px; }
@media (prefers-reduced-motion: reduce) { .rg-tb-sheet, .rg-tb-back { transition: none; } }
</style>
<script>
(function () {
  var url = '../api/table_session.php?restaurant_id=' + encodeURIComponent(<?php echo json_encode((string)$restaurant_id); ?>) + '&table=' + encodeURIComponent(<?php echo json_encode((string)$qr_table); ?>);
  var TABLE = <?php echo json_encode((string)$qr_table); ?>;
  var bar = document.getElementById('rgTableBar'), sheet = document.getElementById('rgTableSheet'), back = document.getElementById('rgTableBack');
  var esc = function (t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var stat = { Pending: 'Placed', Accepted: 'Confirmed', Preparing: 'Preparing', Ready: 'Ready', Served: 'Served', Completed: 'Served', Scheduled: 'Scheduled' };
  var D = null, timer = 0;
  function money(n) { return ((D && D.currency) || '') + (Math.round(n * 100) / 100).toFixed(2); }
  function draw() {
    var has = D && D.open && D.orders && D.orders.length;
    document.getElementById('rgTableBarText').innerHTML = '<b>Table ' + esc(TABLE) + '</b> · ' + (has
      ? esc(D.guests.join(', ')) + ' ordered · ' + esc(money(D.sum.total))
      : 'your order comes to your table');
    document.getElementById('rgTableBarSee').hidden = !has;
    document.getElementById('rgTableSheetList').innerHTML = has ? D.orders.map(function (o) {
      return '<div class="rg-tb-o"><div class="h"><b>' + esc(o.name) + '</b><small>' + esc(stat[o.status] || o.status) + (o.paid ? ' · Paid' : '') + '</small><span>' + esc(money(o.total)) + '</span></div>'
        + '<div class="it">' + o.items.map(function (it) { return esc(it.qty + ' × ' + it.name); }).join('<br>') + '</div></div>';
    }).join('') : '<div class="rg-tb-empty">Nothing ordered at this table yet. Your order will show here, with everyone else’s at the table.</div>';
    document.getElementById('rgTableSheetSum').innerHTML = has ? '<div><span>Table total</span><b>' + esc(money(D.sum.total)) + '</b></div>'
      + (D.sum.paid > 0 ? '<div><span>Still to pay</span><b>' + esc(money(D.sum.due)) + '</b></div>' : '') : '';
  }
  function load() {
    clearTimeout(timer);
    fetch(url, { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) { if (d && d.success) { D = d; draw(); } })
      .catch(function () {}).then(function () { timer = setTimeout(load, document.hidden ? 30000 : 10000); });
  }
  function open() { load(); sheet.classList.add('open'); back.classList.add('open'); sheet.setAttribute('aria-hidden', 'false'); }
  function close() { sheet.classList.remove('open'); back.classList.remove('open'); sheet.setAttribute('aria-hidden', 'true'); }
  bar.addEventListener('click', open);
  back.addEventListener('click', close);
  sheet.addEventListener('click', function (e) { if (e.target.closest('[data-rg-tb-close]')) close(); });
  document.addEventListener('visibilitychange', function () { if (!document.hidden) load(); });
  load();
})();
</script>
