/*
 * POS manual discount (owner/manager dashboard and waiter dashboard): the cashier's
 * own discount on a bill — a percentage or a flat amount — separate from the
 * customer's reward codes and points (assets/js/pos-customer.js). It comes off the
 * subtotal BEFORE tax, sits after any reward/points already taken, and is capped so
 * the bill can never go below zero.
 *
 * script.js works out all the bill money in window.posComputeTotals(), which asks
 * this module for the effective amount through pricing(). The amount is sent with
 * the bill (manualDiscount / manualDiscountType / manualDiscountValue) and
 * controllers/pos_operations.php caps it again, records it in the KOT notes and in
 * the owner's activity log, and saves it to orders.discount_amount — so the Orders
 * page, the reports and the printed bill all show it.
 *
 * No CSS or modal-library dependency: the row rides on .cart-summary-row (style.css)
 * and the editor is a small self-contained overlay, so it looks the same on both
 * dashboards. Icons: material-symbols-rounded.
 */
(function () {
  'use strict';

  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var sym = function () { return window.globalCurrencySymbol || '₹'; };
  var money = function (v) { return typeof window.formatCurrency === 'function' ? window.formatCurrency(v) : sym() + (+v || 0).toFixed(2); };

  var disc = null;                 // { type: 'percent' | 'amount', value: Number }  — null = no discount
  var lastSub = 0, lastTaken = 0;  // the last subtotal / already-taken discount (coupon + points)

  // The amount this discount actually takes off: applied to the subtotal, then
  // trimmed so it never dips into what the coupon + points already took.
  function effective(sub, taken) {
    if (!disc || !(disc.value > 0) || !(sub > 0)) return 0;
    var amt = disc.type === 'percent' ? (sub * disc.value) / 100 : disc.value;
    amt = Math.round(amt * 100) / 100;
    return Math.max(0, Math.min(amt, sub - (taken || 0)));
  }
  // The label the printed bill shows for this discount
  function label() {
    if (!disc || !(disc.value > 0)) return 'Discount';
    return disc.type === 'percent' ? 'Discount ' + (+disc.value) + '%' : 'Discount';
  }
  function refresh() {
    var fn = window.updatePOSCartSummaryHook || window.updatePOSCartSummary;
    if (typeof fn === 'function') fn(); else paint(lastSub, lastTaken);
  }

  /* ── the row under the cart's subtotal ─────────────────────────── */
  var BTN = 'display:inline-flex;align-items:center;gap:3px;border:1px solid #e5e7eb;background:#fff;color:#374151;border-radius:7px;padding:3px 9px;font:600 11px/1.5 inherit;cursor:pointer;';
  var BTN_ADD = BTN + 'border-style:dashed;color:#e11d48;';
  var BTN_CLR = 'display:inline-flex;align-items:center;border:1px solid #e5e7eb;background:#fff;color:#9ca3af;border-radius:7px;padding:3px 4px;cursor:pointer;';

  function paint(sub, taken) {
    lastSub = +sub || 0;
    lastTaken = +taken || 0;
    var sum = document.querySelector('.pos-cart-summary');
    if (!sum) return;
    var el = document.getElementById('posManualDiscRow');
    if (!el) {
      el = document.createElement('div');
      el.id = 'posManualDiscRow';
      el.className = 'cart-summary-row pc-manual';
      sum.appendChild(el);
    }
    // always park directly under the subtotal (the customer's rows may move around)
    var first = sum.querySelector('.cart-summary-row');
    if (first && first !== el && el !== first.nextSibling) sum.insertBefore(el, first.nextSibling);

    var amt = effective(lastSub, lastTaken);
    var val = amt > 0
      ? '<span style="color:#15803d;font-weight:700;">-' + esc(money(amt)) + '</span>'
        + (disc.type === 'percent' ? '<span style="color:#9ca3af;font-size:11px;">(' + (+disc.value) + '%)</span>' : '')
      : '';
    el.innerHTML =
      '<span>Discount</span>' +
      '<span style="display:flex;align-items:center;gap:6px;">' + val +
        '<button type="button" style="' + (amt > 0 ? BTN : BTN_ADD) + '" data-pd="edit">' +
          ic(amt > 0 ? 'edit' : 'add') + (amt > 0 ? 'Change' : 'Add discount') + '</button>' +
        (amt > 0 ? '<button type="button" style="' + BTN_CLR + '" data-pd="clear" title="Remove discount" aria-label="Remove discount">' + ic('close') + '</button>' : '') +
      '</span>';
  }

  /* ── the editor overlay ────────────────────────────────────────── */
  function ensureStyle() {
    if (document.getElementById('pd-style')) return;
    var s = document.createElement('style');
    s.id = 'pd-style';
    s.textContent =
      '.pd-ov{position:fixed;inset:0;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;z-index:10000;padding:16px;}'
      + '.pd-card{background:#fff;border-radius:14px;width:100%;max-width:370px;box-shadow:0 20px 50px rgba(0,0,0,.3);overflow:hidden;font-family:inherit;}'
      + '.pd-hd{display:flex;align-items:center;gap:8px;padding:14px 16px;border-bottom:1px solid #eef2f7;}'
      + '.pd-hd b{font-size:15px;color:#111827;}'
      + '.pd-x{margin-left:auto;border:0;background:none;cursor:pointer;color:#6b7280;display:flex;padding:2px;}'
      + '.pd-bd{padding:16px;}'
      + '.pd-types{display:flex;gap:8px;margin-bottom:12px;}'
      + '.pd-t{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:5px;border:1px solid #e5e7eb;background:#fff;color:#374151;border-radius:10px;padding:9px;font:600 13px/1 inherit;cursor:pointer;}'
      + '.pd-t.on{border-color:#e11d48;background:#fff1f3;color:#e11d48;}'
      + '.pd-in{display:flex;align-items:center;gap:8px;border:1px solid #d1d5db;border-radius:10px;padding:0 12px;}'
      + '.pd-in:focus-within{border-color:#e11d48;}'
      + '.pd-in>span{color:#6b7280;font-weight:700;}'
      + '.pd-in input{flex:1;border:0;outline:0;padding:12px 0;font-size:18px;font-weight:700;color:#111827;background:transparent;min-width:0;}'
      + '.pd-hint{margin:10px 2px 0;font-size:12px;color:#6b7280;min-height:16px;}'
      + '.pd-hint.bad{color:#dc2626;}'
      + '.pd-ft{display:flex;gap:8px;padding:0 16px 16px;}'
      + '.pd-ft button{flex:1;border-radius:10px;padding:11px;font:700 13px/1 inherit;cursor:pointer;}'
      + '.pd-cancel{border:1px solid #e5e7eb;background:#fff;color:#374151;}'
      + '.pd-apply{border:0;background:#e11d48;color:#fff;}'
      + '.pd-apply:disabled{opacity:.5;cursor:not-allowed;}';
    document.head.appendChild(s);
  }

  function openEditor() {
    ensureStyle();
    var sub = lastSub, taken = lastTaken;
    var type = disc ? disc.type : 'percent';
    var val = disc ? disc.value : '';
    var ov = document.createElement('div');
    ov.className = 'pd-ov';
    ov.innerHTML =
      '<div class="pd-card" role="dialog" aria-label="Discount on this bill">' +
        '<div class="pd-hd">' + ic('percent') + '<b>Discount on this bill</b><button type="button" class="pd-x" data-pd-x aria-label="Close">' + ic('close') + '</button></div>' +
        '<div class="pd-bd">' +
          '<div class="pd-types">' +
            '<button type="button" class="pd-t" data-t="percent">' + ic('percent') + 'Percent</button>' +
            '<button type="button" class="pd-t" data-t="amount">' + ic('payments') + esc(sym()) + ' Amount</button>' +
          '</div>' +
          '<div class="pd-in"><span data-prefix></span><input type="number" inputmode="decimal" min="0" step="0.01" data-in value="' + (val === '' ? '' : esc(val)) + '" placeholder="0"><span data-suffix></span></div>' +
          '<p class="pd-hint" data-hint></p>' +
        '</div>' +
        '<div class="pd-ft"><button type="button" class="pd-cancel" data-pd-x>Cancel</button><button type="button" class="pd-apply" data-apply>Apply discount</button></div>' +
      '</div>';
    document.body.appendChild(ov);

    var input = ov.querySelector('[data-in]');
    var prefix = ov.querySelector('[data-prefix]');
    var suffix = ov.querySelector('[data-suffix]');
    var hint = ov.querySelector('[data-hint]');
    var apply = ov.querySelector('[data-apply]');
    var tabs = ov.querySelectorAll('.pd-t');

    function preview() {
      var num = parseFloat(input.value) || 0;
      var raw = type === 'percent' ? (sub * num) / 100 : num;
      var amt = Math.max(0, Math.min(Math.round(raw * 100) / 100, sub - taken));
      var tooBig = type === 'percent' && num > 100;
      apply.disabled = !(num > 0) || tooBig || sub <= 0;
      if (tooBig) { hint.className = 'pd-hint bad'; hint.textContent = 'A percentage can be at most 100%.'; }
      else if (sub <= 0) { hint.className = 'pd-hint bad'; hint.textContent = 'Add dishes to the cart first.'; }
      else if (num > 0) { hint.className = 'pd-hint'; hint.textContent = 'Takes ' + money(amt) + ' off' + (raw > sub - taken ? ', capped at what is left after the coupon and points.' : ', before tax.'); }
      else { hint.className = 'pd-hint'; hint.textContent = ''; }
    }
    function setTab(t) {
      type = t;
      for (var i = 0; i < tabs.length; i++) tabs[i].classList.toggle('on', tabs[i].getAttribute('data-t') === t);
      prefix.textContent = t === 'amount' ? sym() : '';
      suffix.textContent = t === 'percent' ? '%' : '';
      preview();
    }
    function teardown() {
      if (ov.parentNode) ov.parentNode.removeChild(ov);
      document.removeEventListener('keydown', onKey);
    }
    function onKey(e) { if (e.key === 'Escape') teardown(); }
    function commit() {
      var num = parseFloat(input.value) || 0;
      if (!(num > 0) || sub <= 0) return;
      if (type === 'percent' && num > 100) return;
      disc = { type: type, value: Math.round(num * 100) / 100 };
      teardown();
      refresh();
    }

    for (var i = 0; i < tabs.length; i++) tabs[i].addEventListener('click', function () { setTab(this.getAttribute('data-t')); });
    input.addEventListener('input', preview);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); commit(); } });
    ov.addEventListener('click', function (e) {
      if (e.target === ov || e.target.closest('[data-pd-x]')) { teardown(); return; }
      if (e.target.closest('[data-apply]')) { commit(); }
    });
    document.addEventListener('keydown', onKey);
    setTab(type);
    if (val !== '' && val != null) input.value = val;
    setTimeout(function () { try { input.focus(); input.select(); } catch (e) {} }, 30);
  }

  /* ── wiring ────────────────────────────────────────────────────── */
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-pd]') : null;
    if (!b) return;
    var a = b.getAttribute('data-pd');
    if (a === 'edit') { e.preventDefault(); openEditor(); }
    else if (a === 'clear') { e.preventDefault(); disc = null; refresh(); }
  });

  window.rgPosDiscount = {
    // the effective amount to take off (after coupon + points), or null when there is none
    pricing: function (sub, taken) {
      var amt = effective(sub, taken);
      return amt > 0 ? { amount: amt, type: disc.type, value: disc.value, label: label() } : null;
    },
    // paint the summary row (called by script.js and the waiter dashboard)
    paintSummary: function (sub, taken) { paint(sub, taken); },
    reset: function () { disc = null; },
    get: function () { return disc; },
    set: function (type, value) { disc = (value > 0) ? { type: type === 'percent' ? 'percent' : 'amount', value: +value } : null; }
  };

  // show the "Add discount" row on its own, even before the first cart change
  function init() { paint(0, 0); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
