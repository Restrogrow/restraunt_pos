/**
 * POS › combos (Offers › Combos & Deals): a strip of the combos on sale right now above the dishes.
 * Tap a combo: one with choices opens a picker; one without goes straight into the cart.
 * Data: api/combos_admin.php?view=pos. Cart: window.addComboToPOSCart (script.js). The server prices
 * the combo again when the bill is saved (controllers/pos_operations.php). Styles: pcx-* in studio-ui.css.
 */
(function () {
  'use strict';
  var combos = [];
  var pick = null;   // { c, picks, qty }
  var esc = function (s) { return window.SX ? SX.esc(s) : String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var cur = function () { return window.globalCurrencySymbol || '₹'; };
  var money = function (n) { n = Math.round((+n || 0) * 100) / 100; return cur() + (n % 1 ? n.toFixed(2) : n.toLocaleString('en-IN')); };
  var img = function (p) { return p ? '../api/image.php?path=' + encodeURIComponent(p) : ''; };

  function load() {
    if (typeof window.addComboToPOSCart !== 'function' || !document.getElementById('posMenuItems')) return;
    fetch('../api/combos_admin.php?view=pos', { cache: 'no-store' }).then(function (r) { return r.json(); })
      .then(function (d) { combos = (d && d.success && d.combos) || []; draw(); })
      .catch(function () {});
  }

  function draw() {
    var grid = document.getElementById('posMenuItems');
    if (!grid) return;
    var strip = document.getElementById('pcxStrip');
    if (!combos.length) { if (strip) strip.remove(); return; }
    if (!strip) { strip = document.createElement('div'); strip.id = 'pcxStrip'; strip.className = 'pcx-strip'; grid.parentNode.insertBefore(strip, grid); }
    strip.innerHTML = '<span class="pcx-h">' + ic('fastfood') + 'Combos</span>' + combos.map(function (c) {
      var multi = c.slots.some(function (s) { return s.choices.length > 1; });
      var ch = c.slots[0] && c.slots[0].choices[0];
      return '<button type="button" class="pcx-card" data-pcx="' + c.id + '" title="' + esc(c.slots.map(function (s) { return (s.qty > 1 ? s.qty + ' × ' : '') + (s.choices.length > 1 ? s.title : s.choices[0].name); }).join(' + ')) + '">' +
        (c.image || (ch && ch.image) ? '<img src="' + img(c.image || ch.image) + '" alt="">' : '<span class="pcx-ph">' + ic('fastfood') + '</span>') +
        '<span class="pcx-t"><b>' + esc(c.name) + '</b><small>' + money(c.price) + (c.save > 0 ? ' <s>' + money(c.worth) + '</s>' : '') + (c.ends_at ? ' · ' + ic('schedule') + new Date(c.ends_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '') + '</small></span>' +
        '<span class="pcx-go">' + ic(multi ? 'tune' : 'add') + '</span></button>';
    }).join('');
  }

  function chosen(s) { return s.choices.filter(function (o) { return o.id === pick.picks[s.id]; })[0] || s.choices[0]; }
  function unit() { return pick.c.slots.reduce(function (t, s) { return t + (+chosen(s).extra || 0); }, +pick.c.price); }
  function summary() { return pick.c.slots.map(function (s) { var o = chosen(s); return (s.qty > 1 ? s.qty + ' × ' : '') + o.name + (o.variation ? ' (' + o.variation + ')' : ''); }).join(', '); }

  function addToCart() {
    var c = pick.c, ids = c.slots.map(function (s) { return chosen(s).id; }), picks = {};
    c.slots.forEach(function (s) { picks[s.id] = chosen(s).id; });
    window.addComboToPOSCart({ comboId: c.id, cartKey: 'combo_' + c.id + '_' + ids.join('-'), name: c.name, price: unit(), quantity: pick.qty, picks: picks, summary: summary() });
    if (window.SX) SX.notify(c.name + ' added', 'success');
  }

  function open(id) {
    var c = combos.filter(function (x) { return x.id === id; })[0];
    if (!c) return;
    var picks = {};
    c.slots.forEach(function (s) { picks[s.id] = s.choices[0].id; });
    pick = { c: c, picks: picks, qty: 1 };
    if (!c.slots.some(function (s) { return s.choices.length > 1; })) { addToCart(); pick = null; return; }   // nothing to choose
    var ov = document.createElement('div');
    ov.className = 'pcx-ov'; ov.id = 'pcxPick';
    ov.innerHTML = '<div class="pcx-sheet" role="dialog" aria-modal="true" aria-label="' + esc(c.name) + '"><div class="pcx-sh-h"><div><b>' + esc(c.name) + '</b><small>' + money(c.price) + (c.save > 0 ? ' · saves ' + money(c.save) : '') + '</small></div>' +
      '<button type="button" class="sx-icon-btn" data-pcx-close aria-label="Close">' + ic('close') + '</button></div><div class="pcx-body"></div>' +
      '<div class="pcx-f"><div class="pcx-qty"><button type="button" data-pcx-qty="-1" aria-label="One less">' + ic('remove') + '</button><b>1</b><button type="button" data-pcx-qty="1" aria-label="One more">' + ic('add') + '</button></div>' +
      '<button type="button" class="btn btn-primary pcx-add" data-pcx-add>' + ic('add_shopping_cart') + '<span>Add</span><b></b></button></div></div>';
    document.body.appendChild(ov);
    drawPick();
    requestAnimationFrame(function () { ov.classList.add('in'); });
  }
  function close() {
    var ov = document.getElementById('pcxPick');
    pick = null;
    if (!ov) return;
    ov.classList.remove('in');
    setTimeout(function () { ov.remove(); }, 200);
  }
  function drawPick() {
    var body = document.querySelector('#pcxPick .pcx-body');
    if (!body || !pick) return;
    body.innerHTML = pick.c.slots.map(function (s) {
      if (s.choices.length < 2) return '<div class="pcx-fixed">' + ic('check_circle') + '<span>' + esc((s.qty > 1 ? s.qty + ' × ' : '') + s.choices[0].name) + '</span><small>Included</small></div>';
      return '<div class="pcx-step"><div class="pcx-step-h"><b>' + esc((s.qty > 1 ? s.qty + ' × ' : '') + s.title) + '</b><small>Pick 1</small></div><div class="pcx-opts">' + s.choices.map(function (o) {
        var on = pick.picks[s.id] === o.id;
        return '<button type="button" class="pcx-opt' + (on ? ' on' : '') + '" data-pcx-pick="' + s.id + ':' + o.id + '">' + (on ? ic('check_circle') : '') + '<b>' + esc(o.name) + '</b>' +
          (o.variation ? '<small>' + esc(o.variation) + '</small>' : '') + '<em>' + (o.extra > 0 ? '+' + money(o.extra) : 'Included') + '</em></button>';
      }).join('') + '</div></div>';
    }).join('');
    var sh = document.getElementById('pcxPick');
    sh.querySelector('.pcx-qty b').textContent = pick.qty;
    sh.querySelector('.pcx-add b').textContent = money(unit() * pick.qty);
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-pcx],[data-pcx-close],[data-pcx-pick],[data-pcx-qty],[data-pcx-add]');
    if (!t) { if (e.target.id === 'pcxPick') close(); return; }
    if (t.hasAttribute('data-pcx')) { open(+t.getAttribute('data-pcx')); return; }
    if (t.hasAttribute('data-pcx-close')) { close(); return; }
    if (!pick) return;
    if (t.hasAttribute('data-pcx-pick')) { var p = t.getAttribute('data-pcx-pick').split(':'); pick.picks[+p[0]] = +p[1]; drawPick(); return; }
    if (t.hasAttribute('data-pcx-qty')) { pick.qty = Math.max(1, Math.min(50, pick.qty + (+t.getAttribute('data-pcx-qty')))); drawPick(); return; }
    if (t.hasAttribute('data-pcx-add')) { addToCart(); close(); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && pick) close(); });

  // load when the POS opens, and every 2 minutes while it's open (a lunch deal starts or ends)
  document.addEventListener('click', function (e) { if (e.target.closest('.nav-link[data-page="posPage"]')) setTimeout(load, 300); });
  setInterval(function () { if (window.SX && SX.isActive('posPage')) load(); }, 120000);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(load, 800); }); else setTimeout(load, 800);
})();
