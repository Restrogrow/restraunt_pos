/*
 * Offers > Scratch card (admin)
 *
 * After checkout the customer scratches a card on the tracking page: a real
 * prize for the next order (₹ off / % off) or "better luck next time", drawn
 * by the chances set here, within a monthly budget. Saved through
 * ../api/scratch_admin.php.
 */
(function () {
  'use strict';

  var API = '../api/scratch_admin.php';
  var DEFAULT_PRIZES = [{ type: 'flat', value: 50, weight: 30 }, { type: 'percent', value: 10, weight: 15 }, { type: 'flat', value: 100, weight: 5 }, { type: 'none', value: 0, weight: 50 }];
  var st = null;

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function notify(msg, type) { if (typeof window.showNotification === 'function') window.showNotification(msg, type); }
  function cur() { return (st && st.currency) || '₹'; }
  function money(n) { return cur() + Math.round(n).toLocaleString(); }
  function label(p) { return p.type === 'flat' ? money(p.value) + ' off' : p.type === 'percent' ? (+p.value) + '% off' : 'Better luck next time'; }

  function render() {
    var box = $('scBody');
    if (!box || !st) return;
    var s = st.settings, ps = st.prizes, k = st.stats;
    var sum = ps.reduce(function (t, p) { return t + (+p.weight || 0); }, 0) || 1;
    var avgOrder = Math.max(+s.min_order || 0, 500);
    var perCard = ps.reduce(function (t, p) {
      var c = p.type === 'flat' ? +p.value : p.type === 'percent' ? avgOrder * p.value / 100 : 0;
      return t + c * (+p.weight || 0) / sum;
    }, 0);
    var winPct = ps.reduce(function (t, p) { return t + (p.type !== 'none' ? (+p.weight || 0) : 0); }, 0) / sum * 100;

    box.innerHTML =
      '<div class="scx-stats">' +
        '<div><b>' + k.given + '</b><span>Cards given</span></div>' +
        '<div><b>' + k.scratched + '</b><span>Scratched</span></div>' +
        '<div><b>' + k.won + '</b><span>Prizes won</span></div>' +
        '<div><b>' + k.used + '</b><span>Codes used</span></div>' +
        '<div class="hi"><b>' + money(k.return_sales) + '</b><span>' + k.return_orders + ' orders came back</span></div>' +
        '<small>Last 30 days</small>' +
      '</div>' +

      '<div class="scx-cols">' +
      '<div>' +
        '<div class="up-card">' +
          '<label class="up-switch"><input type="checkbox" id="scEnabled"' + (s.enabled ? ' checked' : '') + '><span></span> Give a scratch card after checkout</label>' +
          '<div class="scx-grid">' +
            '<label>Heading<input id="scTitle" maxlength="60" placeholder="Your scratch card" value="' + esc(s.title) + '"></label>' +
            '<label>Orders above (' + cur() + ')<input id="scMin" type="number" min="0" value="' + (+s.min_order || '') + '" placeholder="Any order"></label>' +
            '<label>Code valid for<select id="scDays">' + [3, 7, 15, 30, 60].map(function (d) { return '<option value="' + d + '"' + (+s.valid_days === d ? ' selected' : '') + '>' + d + ' days</option>'; }).join('') + '</select></label>' +
            '<label>Next order must be above (' + cur() + '), unless a prize sets its own<input id="scNextMin" type="number" min="0" value="' + (+s.next_min_order || '') + '" placeholder="Any amount"></label>' +
            '<label>Monthly budget (' + cur() + ')<input id="scBudget" type="number" min="0" value="' + (+s.monthly_budget || '') + '" placeholder="No limit"></label>' +
          '</div>' +
          (+s.monthly_budget > 0 ? '<div class="scx-budget"><div><i style="width:' + Math.min(100, k.spent_month / s.monthly_budget * 100) + '%"></i></div><span>' + money(k.spent_month) + ' of ' + money(s.monthly_budget) + ' given this month. When it runs out, no more cards until next month.</span></div>' : '') +
        '</div>' +

        '<div class="up-card">' +
          '<h3><span class="material-symbols-rounded">redeem</span> Prizes and chances</h3>' +
          '<div class="scx-prize-head"><span>Prize</span><span>Amount</span><span>Min. next order <em>optional</em></span><span>Chance</span><span></span></div>' +
          ps.map(function (p, i) {
            return '<div class="scx-prize" data-i="' + i + '">' +
              '<select class="scx-type">' + [['flat', cur() + ' off next order'], ['percent', '% off next order'], ['none', 'Better luck next time']].map(function (o) {
                return '<option value="' + o[0] + '"' + (p.type === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>' +
              '<input class="scx-value" type="number" min="1"' + (p.type === 'none' ? ' disabled value=""' : ' value="' + (+p.value) + '"') + ' placeholder="' + (p.type === 'percent' ? '%' : cur()) + '">' +
              '<input class="scx-pmin" type="number" min="0"' + (p.type === 'none' ? ' disabled value=""' : ' value="' + (+p.min_order || '') + '"') + ' placeholder="' + (+s.next_min_order > 0 ? cur() + (+s.next_min_order) : 'Any') + '" title="Leave empty to use the general minimum above">' +
              '<div class="scx-weight"><input class="scx-w" type="number" min="1" max="1000" value="' + (+p.weight) + '"><b>' + Math.round(p.weight / sum * 1000) / 10 + '%</b></div>' +
              '<button type="button" class="up-pair-x scx-del" title="Remove">&times;</button>' +
            '</div>';
          }).join('') +
          (ps.length < 8 ? '<button type="button" class="ts-add" id="scAddPrize"><span class="material-symbols-rounded">add</span> Add a prize</button>' : '') +
          '<div class="scx-math"><span class="material-symbols-rounded">calculate</span><div>About <b>' + Math.round(winPct) + '%</b> of cards win. Each card costs you about <b>' + money(perCard) + '</b> on a ' + money(avgOrder) + ' order, only when the code is used on a new order.</div></div>' +
          '<p class="scx-note">Customers can see these chances on the card. A won prize becomes a one-time code for that customer’s phone only. Cards unlock after you accept the order, and a code from a cancelled order stops working.</p>' +
        '</div>' +
      '</div>' +

      '<div>' +
        '<div class="up-card scx-preview">' +
          '<h3><span class="material-symbols-rounded">visibility</span> What customers see</h3>' +
          '<div class="scx-phone"><div class="scx-mock-head"><i></i><div><b>' + esc(s.title || 'Your scratch card') + '</b><small>Scratch to reveal your reward</small></div></div>' +
          '<div class="scx-foil"><span>★ SCRATCH ★</span><em></em></div>' +
          '<div class="scx-mock-odds">See the chances</div></div>' +
        '</div>' +
        '<div class="up-card">' +
          '<h3><span class="material-symbols-rounded">history</span> Latest cards</h3>' +
          (st.recent.length ? '<div class="scx-recent">' + st.recent.map(function (r) {
            return '<div><span class="ph">' + esc(r.phone) + '<small>' + esc(r.order) + '</small></span>' +
              '<span class="pz' + (r.type === 'none' ? ' no' : '') + '">' + esc(r.status === 'scratched' ? r.prize : 'Not scratched yet') + '</span>' +
              (r.code ? '<span class="tag' + (r.used ? ' used' : '') + '">' + (r.used ? 'Used' : esc(r.code)) + '</span>' : '<span></span>') + '</div>';
          }).join('') + '</div>' : '<div class="up-empty">No cards yet. They appear here as customers get them.</div>') +
        '</div>' +
      '</div>' +
      '</div>';
  }

  function load() {
    var box = $('scBody');
    if (box && !st) box.innerHTML = '<div class="up-empty">Loading…</div>';
    fetch(API, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) { if (box) box.innerHTML = '<div class="up-empty">' + esc(d.message || 'Could not load') + '</div>'; return; }
        if (!d.prizes.length) d.prizes = DEFAULT_PRIZES.map(function (p) { return { type: p.type, value: p.value, weight: p.weight }; });
        st = d;
        render();
      })
      .catch(function () { if (box) box.innerHTML = '<div class="up-empty">Could not load. Please try again.</div>'; });
  }

  function save() {
    var btn = $('scSave');
    btn.disabled = true;
    fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ settings: st.settings, prizes: st.prizes }) })
      .then(function (r) { return r.json(); })
      .then(function (d) { btn.disabled = false; notify(d.success ? 'Scratch card saved' : (d.message || 'Could not save'), d.success ? 'success' : 'error'); })
      .catch(function () { btn.disabled = false; notify('Network error. Please try again.', 'error'); });
  }

  document.addEventListener('click', function (e) {
    if (!st || !e.target.closest || !e.target.closest('#scratchPage')) return;
    var t;
    if ((t = e.target.closest('#scSave'))) { save(); return; }
    if ((t = e.target.closest('#scAddPrize'))) { st.prizes.push({ type: 'flat', value: 25, weight: 10 }); render(); return; }
    if ((t = e.target.closest('.scx-del'))) { st.prizes.splice(+t.closest('.scx-prize').getAttribute('data-i'), 1); render(); }
  });
  function onEdit(e, redraw) {
    if (!st || !e.target.closest || !e.target.closest('#scratchPage')) return;
    var t = e.target, s = st.settings, row = t.closest('.scx-prize'), p = row ? st.prizes[+row.getAttribute('data-i')] : null;
    if (t.id === 'scEnabled') s.enabled = t.checked ? 1 : 0;
    else if (t.id === 'scTitle') s.title = t.value;
    else if (t.id === 'scMin') s.min_order = +t.value || 0;
    else if (t.id === 'scNextMin') s.next_min_order = +t.value || 0;
    else if (t.id === 'scBudget') s.monthly_budget = +t.value || 0;
    else if (t.id === 'scDays') s.valid_days = +t.value;
    else if (p && t.classList.contains('scx-type')) { p.type = t.value; if (p.type === 'percent' && p.value > 50) p.value = 10; }
    else if (p && t.classList.contains('scx-value')) p.value = +t.value || 0;
    else if (p && t.classList.contains('scx-w')) p.weight = Math.max(1, +t.value || 1);
    else if (p && t.classList.contains('scx-pmin')) p.min_order = +t.value > 0 ? +t.value : null;
    else return;
    if (redraw) render();
  }
  document.addEventListener('change', function (e) { onEdit(e, true); });
  document.addEventListener('input', function (e) { onEdit(e, false); });

  window.loadScratchAdmin = load;
})();
