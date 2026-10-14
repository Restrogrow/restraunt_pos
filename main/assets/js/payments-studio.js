/**
 * Payments page (dashboard › Payments › Payments): what came in for a date range, compared with the
 * period before, split by method, still to collect, per day, and the transactions.
 * Data: api/payments_overview.php. The page's search / method / status filters and Export CSV keep
 * working (window.currentPaymentsData). Styles: pay-* in studio-ui.css.
 */
(function () {
  'use strict';
  var D = null;
  var range = 'today', from = '', to = '';
  var esc = function (s) { return window.SX ? SX.esc(s) : String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var money = function (n, dec) { var c = (D && D.currency) || '₹'; n = +n || 0; return c + (dec ? n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : Math.round(n).toLocaleString('en-IN')); };
  var METHOD = { Cash: ['#16a34a', 'payments'], UPI: ['#7c3aed', 'qr_code_2'], Card: ['#2563eb', 'credit_card'], Online: ['#0891b2', 'language'], Wallet: ['#ea580c', 'account_balance_wallet'] };
  var mlook = function (m) { return METHOD[m] || ['#64748b', 'receipt_long']; };
  var STATUS = { Success: 'ok', Pending: 'wait', Failed: 'bad', Refunded: 'muted' };

  function params() {
    var p = new URLSearchParams({ range: range });
    if (range === 'custom') { p.set('from', from); p.set('to', to); }
    var s = (document.getElementById('paymentSearch') || {}).value, m = (document.getElementById('paymentMethodFilter') || {}).value, st = (document.getElementById('paymentStatusFilter') || {}).value;
    if (s) p.set('search', s); if (m) p.set('method', m); if (st) p.set('status', st);
    return p.toString();
  }

  function load() {
    var page = document.getElementById('paymentsPage');
    if (!page) return;
    var box = document.getElementById('payRoot');
    if (!box) {
      box = document.createElement('div'); box.id = 'payRoot';
      var tb = page.querySelector('.sx-toolbar');
      if (tb) tb.parentNode.insertBefore(box, tb); else page.querySelector('.page-content').prepend(box);
      var old = page.querySelector('.lg-card'); if (old) old.style.display = 'none';
      var list = document.createElement('div'); list.id = 'payList';
      (old || tb).parentNode.insertBefore(list, old ? old : tb.nextSibling);
    }
    if (!D) box.innerHTML = '<div class="cbx-loading"><span></span><span></span><span></span></div>';
    fetch('../api/payments_overview.php?' + params(), { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.success) { box.innerHTML = '<div class="sx-note warn">' + ic('warning') + '<span>' + esc(d.message || 'Could not load payments') + '</span></div>'; return; }
      D = d;
      window.currentPaymentsData = d.payments;   // Export CSV exports what's shown
      draw();
    }).catch(function () { box.innerHTML = '<div class="sx-note warn">' + ic('warning') + '<span>No connection. Please try again.</span></div>'; });
  }

  function change(now, before) {
    if (!before) return now ? '<em class="up">New</em>' : '';
    var p = Math.round((now - before) / before * 100);
    return '<em class="' + (p >= 0 ? 'up' : 'down') + '">' + ic(p >= 0 ? 'trending_up' : 'trending_down') + Math.abs(p) + '%</em>';
  }

  function draw() {
    var box = document.getElementById('payRoot');
    if (!box || !D) return;
    var t = D.totals, pv = D.previous, total = D.methods.reduce(function (s, m) { return s + m.amount; }, 0) || 1;
    var keys = Object.keys(D.series), max = Math.max.apply(null, keys.map(function (k) { return D.series[k]; }).concat([1]));
    var label = range === 'today' ? 'today' : range === 'custom' ? D.range.from + ' – ' + D.range.to : 'last ' + range + ' days';
    box.innerHTML =
      '<div class="pay-range"><div class="cbx-chips">' + [['today', 'Today'], ['7', '7 days'], ['30', '30 days'], ['90', '90 days']].map(function (r) {
        return '<button type="button" class="cbx-chip' + (range === r[0] ? ' on' : '') + '" data-pay-range="' + r[0] + '">' + r[1] + '</button>'; }).join('') +
        '<button type="button" class="cbx-chip' + (range === 'custom' ? ' on' : '') + '" data-pay-range="custom">' + ic('calendar_month') + 'Dates</button></div>' +
        (range === 'custom' ? '<div class="pay-dates"><input type="date" id="payFrom" value="' + esc(D.range.from) + '"><span>to</span><input type="date" id="payTo" value="' + esc(D.range.to) + '"><button type="button" class="btn btn-primary btn-sm" data-pay-apply>Show</button></div>' : '') + '</div>' +
      '<div class="pay-tiles">' +
        '<div class="pay-tile main">' + ic('account_balance_wallet') + '<small>Collected ' + esc(label) + '</small><b>' + money(t.collected) + '</b>' + change(t.collected, pv.collected) + '</div>' +
        '<div class="pay-tile">' + ic('receipt_long') + '<small>Payments</small><b>' + t.count + '</b>' + change(t.count, pv.count) + '</div>' +
        '<div class="pay-tile">' + ic('calculate') + '<small>Average payment</small><b>' + money(t.average) + '</b></div>' +
        '<div class="pay-tile' + (D.due.amount > 0 ? ' warn' : '') + '">' + ic('hourglass_top') + '<small>Still to collect</small><b>' + money(D.due.amount) + '</b><span>' + D.due.count + ' open bill' + (D.due.count === 1 ? '' : 's') + '</span></div>' +
      '</div>' +
      '<div class="pay-grid">' +
        '<section class="gbx-card"><h4>' + ic('percent') + 'By method</h4>' + (D.methods.length ? '<div class="pay-stack">' + D.methods.map(function (m) {
            return '<i style="--w:' + (m.amount / total * 100).toFixed(2) + '%;--c:' + mlook(m.method)[0] + '" title="' + esc(m.method) + ': ' + money(m.amount) + '"></i>'; }).join('') + '</div>' +
          '<div class="pay-methods">' + D.methods.map(function (m) {
            var l = mlook(m.method);
            return '<div style="--c:' + l[0] + '"><span class="pay-mi">' + ic(l[1]) + '</span><span><b>' + esc(m.method) + '</b><small>' + m.count + ' payment' + (m.count === 1 ? '' : 's') + '</small></span><span class="pay-amt"><b>' + money(m.amount) + '</b><small>' + Math.round(m.amount / total * 100) + '%</small></span></div>';
          }).join('') + '</div>' : '<p class="sx-muted">No payments in this range yet.</p>') +
          (t.pending > 0 || t.refunded > 0 ? '<div class="pay-extra">' + (t.pending > 0 ? '<span class="sx-pill wait">' + money(t.pending) + ' pending</span>' : '') + (t.refunded > 0 ? '<span class="sx-pill muted">' + money(t.refunded) + ' refunded</span>' : '') + '</div>' : '') + '</section>' +
        '<section class="gbx-card"><h4>' + ic('insights') + 'Collected per day</h4>' + (keys.length > 1 ? '<div class="gbx-chart pay-chart">' + keys.map(function (k, i) {
            var v = D.series[k];
            return '<i style="--h:' + (v / max * 100).toFixed(1) + '%;--d:' + Math.min(i, 60) * 10 + 'ms" title="' + k + ': ' + money(v) + '"></i>'; }).join('') + '</div><div class="gbx-axis"><span>' + keys[0] + '</span><span>' + keys[keys.length - 1] + '</span></div>'
          : '<div class="pay-today"><b>' + money(t.collected) + '</b><small>collected today' + (pv.collected ? ', ' + money(pv.collected) + ' yesterday' : '') + '</small></div>') + '</section>' +
      '</div>';
    drawList();
  }

  function drawList() {
    var el = document.getElementById('payList');
    if (!el || !D) return;
    var rows = D.payments;
    el.innerHTML = '<div class="pay-list-h"><b>Transactions</b><small>' + rows.length + (rows.length === 500 ? '+ (newest 500)' : '') + '</small></div>' +
      (rows.length ? '<div class="pay-list">' + rows.map(function (p, i) {
        var l = mlook(p.payment_method), when = new Date(String(p.created_at).replace(' ', 'T'));
        return '<div class="pay-row" style="--c:' + l[0] + ';--d:' + Math.min(i, 20) * 20 + 'ms"><span class="pay-mi">' + ic(l[1]) + '</span>' +
          '<span class="pay-main"><b>' + money(p.amount, true) + '</b><small>' + esc(p.payment_method || '') + (p.transaction_id ? ' · ' + esc(p.transaction_id) : '') + '</small></span>' +
          '<span class="pay-order">' + (p.order_id ? '<a href="#" data-pay-order="' + p.order_id + '">' + esc(p.bill_no ? 'Bill ' + p.bill_no : (p.order_number || '#' + p.order_id)) + '</a>' : '—') + '<small>' + esc([p.customer_name, p.order_type].filter(Boolean).join(' · ')) + '</small></span>' +
          '<span class="sx-pill ' + (STATUS[p.payment_status] || 'muted') + '">' + esc(p.payment_status) + '</span>' +
          '<span class="pay-when"><b>' + when.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) + '</b><small>' + when.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) + '</small></span></div>';
      }).join('') + '</div>' : '<div class="cbx-empty"><div class="cbx-empty-art">' + ic('receipt_long') + '</div><h3>No payments here</h3><p>Try another date range, or clear the search and filters.</p></div>');
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-pay-range],[data-pay-apply],[data-pay-order]');
    if (!t) return;
    if (t.hasAttribute('data-pay-order')) { e.preventDefault(); if (window.showFullOrderDetails) window.showFullOrderDetails(+t.getAttribute('data-pay-order')); return; }
    if (t.hasAttribute('data-pay-range')) {
      range = t.getAttribute('data-pay-range');
      if (range === 'custom') { if (!from) { from = D ? D.range.from : ''; to = D ? D.range.to : ''; } draw(); return; }
      load(); return;
    }
    if (t.hasAttribute('data-pay-apply')) { from = (document.getElementById('payFrom') || {}).value || ''; to = (document.getElementById('payTo') || {}).value || ''; if (from && to) load(); }
  });
  var deb = null;
  document.addEventListener('input', function (e) { if (e.target.id === 'paymentSearch') { clearTimeout(deb); deb = setTimeout(load, 300); } });
  document.addEventListener('change', function (e) { if (e.target.id === 'paymentMethodFilter' || e.target.id === 'paymentStatusFilter') load(); });

  // script.js fills the old (hidden) table on its own: keep Export showing this page's list
  function watch() {
    var tb = document.getElementById('paymentsTableBody');
    if (!tb || tb._payWatched) return;
    tb._payWatched = true;
    new MutationObserver(function () { if (D) window.currentPaymentsData = D.payments; }).observe(tb, { childList: true });
  }
  document.addEventListener('click', function (e) { if (e.target.closest('.nav-link[data-page="paymentsPage"]')) setTimeout(function () { watch(); load(); }, 50); });
  document.addEventListener('DOMContentLoaded', function () { watch(); if (window.SX && SX.isActive('paymentsPage')) load(); });
})();
