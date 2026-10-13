/**
 * RestroGrow modals for the dashboard (instead of the browser's alert / confirm boxes):
 *   rgModal({ title, text, html, icon, tone, confirmText, cancelText, onConfirm }) -> Promise<{ ok, value }>
 *   rgConfirm(message, { title, confirmText, danger })                              -> Promise<boolean>
 *   rgAlert(message, type)                                                          -> Promise
 *   rgUpdatePayment(orderId)  Update payment window for an order card (method + status)
 * window.alert, showSweetConfirm and showSweetAlert are routed here so every message looks the same.
 */
(function () {
  'use strict';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var TONES = { default: ['help', 'orange'], danger: ['warning', 'red'], success: ['check_circle', 'green'], info: ['info', 'blue'], error: ['error', 'red'], warning: ['warning', 'amber'], question: ['help', 'orange'] };
  var open = [];

  window.rgModal = function (o) {
    o = o || {};
    return new Promise(function (resolve) {
      var tone = TONES[o.tone] ? o.tone : 'default';
      var ov = document.createElement('div');
      ov.className = 'rgm-ov';
      ov.innerHTML = '<div class="rgm" role="dialog" aria-modal="true" aria-labelledby="rgmT">' +
        '<button type="button" class="rgm-x" aria-label="Close">' + ic('close') + '</button>' +
        (o.icon !== false ? '<span class="rgm-ic t-' + TONES[tone][1] + '">' + ic(o.icon || TONES[tone][0]) + '</span>' : '') +
        '<h3 id="rgmT">' + esc(o.title || 'Are you sure?') + '</h3>' +
        (o.text ? '<p class="rgm-text">' + esc(o.text) + '</p>' : '') + (o.html || '') +
        '<div class="rgm-err" hidden></div>' +
        '<div class="rgm-foot">' + (o.cancelText === null ? '' : '<button type="button" class="rgm-btn rgm-cancel">' + esc(o.cancelText || 'Cancel') + '</button>') +
        '<button type="button" class="rgm-btn rgm-ok t-' + (tone === 'danger' || tone === 'error' ? 'red' : 'orange') + '">' + esc(o.confirmText || 'OK') + '</button></div></div>';
      document.body.appendChild(ov);
      open.push(ov);
      var card = ov.querySelector('.rgm'), ok = ov.querySelector('.rgm-ok'), err = ov.querySelector('.rgm-err');
      requestAnimationFrame(function () { ov.classList.add('show'); });
      var done = false;
      var close = function (res) {
        if (done) return; done = true;
        ov.classList.remove('show');
        open.splice(open.indexOf(ov), 1);
        setTimeout(function () { ov.remove(); }, 180);
        document.removeEventListener('keydown', key, true);
        resolve(res);
      };
      var key = function (e) {
        if (open[open.length - 1] !== ov) return;
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close({ ok: false }); }
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && e.target.tagName !== 'BUTTON') { e.preventDefault(); ok.click(); }
      };
      document.addEventListener('keydown', key, true);
      ov.addEventListener('click', function (e) { if (e.target === ov || e.target.closest('.rgm-x') || e.target.closest('.rgm-cancel')) close({ ok: false }); });
      ok.addEventListener('click', function () {
        if (!o.onConfirm) { close({ ok: true }); return; }
        ok.disabled = true; err.hidden = true;
        var label = ok.textContent; ok.innerHTML = '<span class="loading-spinner"></span>' + esc(label);
        Promise.resolve().then(function () { return o.onConfirm(card); }).then(function (v) {
          if (v === false) { ok.disabled = false; ok.textContent = label; return; }
          close({ ok: true, value: v });
        }).catch(function (e2) { ok.disabled = false; ok.textContent = label; err.textContent = (e2 && e2.message) || 'Something went wrong'; err.hidden = false; });
      });
      if (typeof o.onOpen === 'function') o.onOpen(card);
      setTimeout(function () { (card.querySelector('[autofocus]') || ok).focus(); }, 60);
    });
  };
  window.rgConfirm = function (message, opts) {
    opts = opts || {};
    var danger = opts.danger != null ? opts.danger : /cancel|delete|discard|remove|reject|refund/i.test((opts.title || '') + ' ' + message);
    return window.rgModal({ title: opts.title || 'Are you sure?', text: message, tone: danger ? 'danger' : 'default', icon: opts.icon, confirmText: opts.confirmText || (danger ? 'Yes, continue' : 'Yes'), cancelText: opts.cancelText || 'No, go back' })
      .then(function (r) { return !!r.ok; });
  };
  window.rgAlert = function (message, type) {
    var t = /^(success|error|warning|info)$/.test(type) ? type : (/error|fail|could not|cannot|invalid|not found/i.test(String(message)) ? 'error' : /saved|success|updated|done|created|added|sent/i.test(String(message)) ? 'success' : 'info');
    var titles = { success: 'Done', error: 'Something went wrong', warning: 'Please check', info: 'Note' };
    return window.rgModal({ title: titles[t], text: String(message == null ? '' : message), tone: t, confirmText: 'OK', cancelText: null });
  };
  // the browser's alert() becomes the same modal (it never returned anything, so nothing changes for callers)
  window.alert = function (m) { window.rgAlert(m); };
  // the app's SweetAlert helpers show the same modal too (same return values)
  function routeHelpers() {
    window.showSweetConfirm = function (message, title) { return window.rgConfirm(message, { title: title }); };
    window.showSweetAlert = function (message, type) { return window.rgAlert(message, type); };
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', routeHelpers); else routeHelpers();

  /* ── Update payment (order cards): how it was paid + paid / part paid / unpaid / refunded ── */
  var NEXT_PAY = { Pending: ['Paid', 'Partially Paid', 'Refunded'], Paid: ['Refunded', 'Partially Paid'], 'Partially Paid': ['Paid', 'Refunded'], Refunded: [] };
  var PAY_LABEL = { Paid: 'Paid', 'Partially Paid': 'Part paid', Pending: 'Not paid yet', Refunded: 'Refunded' };
  var METHODS = [['Cash', 'payments'], ['UPI', 'qr_code_2'], ['Card', 'credit_card'], ['Online', 'language'], ['Wallet', 'account_balance_wallet']];
  window.rgUpdatePayment = function (orderId) {
    var o = (window._rgOrders || {})[orderId];
    if (!o) return;
    var cur = o.payment_status || 'Pending', method = o.payment_method || 'Cash';
    var money = typeof window.formatCurrency === 'function' ? window.formatCurrency(o.total) : '₹' + (+o.total || 0).toFixed(2);
    var statuses = [cur].concat(NEXT_PAY[cur] || []);
    var mList = METHODS.slice(); if (!mList.some(function (m) { return m[0] === method; })) mList.push([method, 'payments']);
    var html = '<div class="rgp">' +
      '<div class="rgp-sum"><div><small>Order</small><b>#' + esc(o.order_number || o.id) + '</b></div><div class="r"><small>Total</small><b>' + esc(money) + '</b></div></div>' +
      '<div class="rgp-l">Paid by</div><div class="rgp-opts" data-g="m">' + mList.map(function (m) { return '<button type="button" data-v="' + esc(m[0]) + '"' + (m[0] === method ? ' class="on"' : '') + '>' + ic(m[1]) + esc(m[0]) + '</button>'; }).join('') + '</div>' +
      '<div class="rgp-l">Payment</div><div class="rgp-opts" data-g="s">' + ['Paid', 'Partially Paid', 'Pending', 'Refunded'].map(function (s) {
        var allowed = statuses.indexOf(s) >= 0;
        return '<button type="button" data-v="' + s + '"' + (s === cur ? ' class="on"' : '') + (allowed ? '' : ' disabled title="Not possible from ' + esc(PAY_LABEL[cur]) + '"') + '>' + ic({ Paid: 'check_circle', 'Partially Paid': 'pending', Pending: 'schedule', Refunded: 'undo' }[s]) + PAY_LABEL[s] + '</button>';
      }).join('') + '</div>' +
      '<p class="rgp-note">Now: <b>' + esc(PAY_LABEL[cur] || cur) + ' · ' + esc(method) + '</b>. A paid order can be refunded but not set back to unpaid.</p></div>';
    window.rgModal({
      title: 'Update payment', icon: 'payments', html: html, confirmText: 'Save', cancelText: 'Cancel',
      onOpen: function (card) {
        card.addEventListener('click', function (e) {
          var b = e.target.closest('.rgp-opts button');
          if (!b || b.disabled) return;
          b.parentNode.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
        });
      },
      onConfirm: function (card) {
        var m = (card.querySelector('[data-g=m] .on') || {}).dataset, s = (card.querySelector('[data-g=s] .on') || {}).dataset;
        var nm = m ? m.v : method, ns = s ? s.v : cur;
        if (nm === method && ns === cur) return true;
        var body = new URLSearchParams({ orderId: String(orderId), status: ns });
        if (nm !== method) body.append('payment_method', nm);
        return fetch('../api/update_payment_status.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d.success) throw new Error(d.message || 'Could not update the payment');
            if (typeof window.showNotification === 'function') window.showNotification('Payment updated: ' + (PAY_LABEL[ns] || ns) + ' · ' + nm, 'success');
            if (document.getElementById('onlineOrdersList') && typeof window.loadOnlineOrders === 'function') window.loadOnlineOrders();
            if (document.getElementById('ordersList') && typeof window.loadOrders === 'function') window.loadOrders();
            return true;
          });
      }
    });
  };
})();
