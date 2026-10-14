/**
 * Kitchen Order Tickets (KOT page): ticket-style cards and stage chips with counts.
 * script.js displayKOTOrders() hands the loaded tickets to window.rgKotRender(kots).
 * Buttons call the same functions as before: printKOT, updateKOTStatus, completeKOT, cancelKOT.
 */
(function () {
  'use strict';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var STAGE = { Pending: ['New', 'amber', 'fiber_new'], Preparing: ['In kitchen', 'orange', 'skillet'], Ready: ['Ready', 'green', 'check_circle'], Completed: ['Completed', 'done', 'task_alt'], Cancelled: ['Cancelled', 'red', 'block'] };
  var state = { stage: '', kots: [] };
  try { state.stage = localStorage.getItem('rg_kot_stage') || ''; } catch (e) {}

  function statusOf(k) { return k.kot_status || k.status || 'Pending'; }
  function parseTime(s) { var t = new Date(String(s || '').replace(' ', 'T')); return isNaN(t) ? null : t; }
  function age(t) {
    if (!t) return '';
    var m = Math.max(0, Math.round((Date.now() - t) / 60000));
    if (m < 60) return m + ' min';
    if (m < 1440) return Math.floor(m / 60) + ' h ' + (m % 60) + ' min';
    return Math.floor(m / 1440) + ' d ' + Math.floor((m % 1440) / 60) + ' h';
  }
  function ageClass(t, st) {
    if (!t || (st !== 'Pending' && st !== 'Preparing')) return 'calm';
    var m = (Date.now() - t) / 60000;
    return m >= 20 ? 'late' : m >= 10 ? 'warn' : 'ok';
  }
  // add-ons for the whole order (not on a dish) travel in the ticket note: [Add-ons: Raita x2, ...]
  function orderAddons(k) { var m = (k.notes || '').match(/\[Add-ons:\s*([^\]]+)\]/); return m ? m[1].trim() : ''; }
  function pay(k) { var m = (k.notes || '').match(/\[Payment:\s*([^\]]+)\]/); return m ? m[1].trim() : 'Cash'; }

  function card(k, n) {
    var st = statusOf(k), S = STAGE[st] || [st, 'done', 'receipt_long'];
    var t = parseTime(k.created_at), id = parseInt(k.id, 10);
    var table = k.table_number ? 'Table ' + esc(k.table_number) + (k.area_name ? ' · ' + esc(k.area_name) : '') : 'Takeaway';
    var items = Array.isArray(k.items) ? k.items : [];
    var qty = items.reduce(function (a, it) { return a + (+it.quantity || 0); }, 0);
    var who = k.customer_name && k.customer_name !== 'Table Customer' && k.customer_name !== 'Takeaway' ? k.customer_name : '';
    var main = st === 'Pending' ? '<button type="button" class="kt-main" onclick="updateKOTStatus(' + id + ', \'Preparing\')">' + ic('skillet') + 'Start cooking</button>'
      : st === 'Preparing' ? '<button type="button" class="kt-main go" onclick="updateKOTStatus(' + id + ', \'Ready\')">' + ic('check_circle') + 'Mark as ready</button>'
      : st === 'Ready' ? '<button type="button" class="kt-main done" onclick="completeKOT(' + id + ')">' + ic('done_all') + 'Complete order</button>'
      : '<span class="kt-main flat">' + ic(S[2]) + esc(S[0]) + '</span>';
    return '<div class="kot-order-card kt kt-' + S[1] + '" data-status="' + esc(st) + '">' +
      '<div class="kt-top"><span class="kt-n">' + n + '</span><div class="kt-id"><small>KOT</small><b>#' + esc(k.kot_number || k.id) + '</b></div>' +
      '<span class="kt-age ' + ageClass(t, st) + '" data-t="' + (t ? t.getTime() : '') + '" data-st="' + esc(st) + '" title="' + (t ? esc(t.toLocaleString()) : '') + '">' + ic('timer') + '<span>' + age(t) + '</span></span></div>' +
      '<div class="kt-meta"><span class="kt-st">' + esc(S[0]) + '</span><span class="kt-chip">' + ic(k.table_number ? 'table_restaurant' : 'shopping_bag') + table + '</span><span class="kt-chip pay">' + ic(pay(k) === 'Cash' ? 'payments' : 'qr_code_2') + esc(pay(k)) + '</span></div>' +
      (who ? '<div class="kt-who">' + ic('person') + '<span>' + esc(who) + (k.customer_phone ? ' · <a href="tel:' + esc(String(k.customer_phone).replace(/[^0-9+]/g, '')) + '">' + esc(k.customer_phone) + '</a>' : '') + '</span></div>' : '') +
      '<div class="kt-perf" aria-hidden="true"></div>' +
      '<div class="kt-items"><div class="kt-items-h">' + items.length + ' dish' + (items.length === 1 ? '' : 'es') + ' · ' + qty + ' item' + (qty === 1 ? '' : 's') + '</div>' +
      items.map(function (it) {
        var note = it.notes || it.special_instructions;
        var adds = Array.isArray(it.addons) ? it.addons : [];
        return '<div class="kt-item"><span class="kt-q">' + (+it.quantity || 1) + '</span><div><b>' + esc(it.item_name || it.name) + '</b>' +
          (adds.length ? '<em class="kt-add">+ ' + adds.map(function (a) { return esc(a.name); }).join(', ') + '</em>' : '') +
          (note ? '<small>' + ic('edit_note') + esc(note) + '</small>' : '') + '</div></div>';
      }).join('') + (orderAddons(k) ? '<div class="kt-item kt-extra"><span class="kt-q">' + ic('add_circle') + '</span><div><b>Add-ons</b><em class="kt-add">' + esc(orderAddons(k)) + '</em></div></div>' : '') + '</div>' +
      '<div class="kt-foot">' + main + '<button type="button" class="kt-rb" title="Print KOT" aria-label="Print KOT" onclick="printKOT(' + id + ')">' + ic('print') + '</button>' +
      (st !== 'Completed' && st !== 'Cancelled' ? '<button type="button" class="kt-rb red" title="Cancel KOT" aria-label="Cancel KOT" onclick="cancelKOT(' + id + ')">' + ic('block') + '</button>' : '') + '</div></div>';
  }

  function chips() {
    var bar = document.getElementById('rgKotStages');
    var list = document.getElementById('kotList');
    if (!list) return;
    if (!bar) { bar = document.createElement('div'); bar.id = 'rgKotStages'; bar.className = 'kt-stages'; list.parentNode.insertBefore(bar, list); }
    var c = function (v) { return state.kots.filter(function (k) { return !v || statusOf(k) === v; }).length; };
    bar.innerHTML = [['', 'All tickets', 'receipt_long'], ['Pending', 'New', 'fiber_new'], ['Preparing', 'In kitchen', 'skillet'], ['Ready', 'Ready', 'check_circle']].map(function (x) {
      return '<button type="button" class="kt-stage' + (state.stage === x[0] ? ' on' : '') + (x[0] ? ' s-' + x[0].toLowerCase() : '') + '" data-kt-stage="' + x[0] + '">' + ic(x[2]) + x[1] + '<em>' + c(x[0]) + '</em></button>';
    }).join('');
  }

  window.rgKotRender = function (kots) {
    state.kots = Array.isArray(kots) ? kots : [];
    var list = document.getElementById('kotList');
    if (!list) return;
    chips();
    var shown = state.kots.filter(function (k) { return !state.stage || statusOf(k) === state.stage; });
    if (!state.kots.length) { list.innerHTML = '<div class="kt-empty">' + ic('soup_kitchen') + '<b>No kitchen tickets right now</b><span>New orders sent to the kitchen show up here.</span></div>'; return; }
    if (!shown.length) { list.innerHTML = '<div class="kt-empty">' + ic('filter_alt') + '<b>No ' + esc((STAGE[state.stage] || [state.stage])[0]).toLowerCase() + ' tickets</b><button type="button" data-kt-stage="">Show all ' + state.kots.length + ' tickets</button></div>'; return; }
    list.innerHTML = shown.map(function (k, i) { return card(k, i + 1); }).join('');
  };

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-kt-stage]');
    if (!b) return;
    state.stage = b.dataset.ktStage;
    try { localStorage.setItem('rg_kot_stage', state.stage); } catch (er) {}
    window.rgKotRender(state.kots);
  });
  // keep the waiting time fresh without reloading
  setInterval(function () {
    document.querySelectorAll('.kt-age[data-t]').forEach(function (el) {
      var t = +el.dataset.t; if (!t) return;
      el.querySelector('span').textContent = age(new Date(t));
      el.className = 'kt-age ' + ageClass(new Date(t), el.dataset.st);
    });
  }, 30000);
})();
