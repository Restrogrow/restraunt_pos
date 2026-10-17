/*
 * POS customer (#posPage cart): pick an existing customer, add a new one, or carry on without.
 * With a customer the bill can use their points, their own reward codes and their wallet, and
 * the sale earns them points / stamps / cashback (server: controllers/pos_operations.php with
 * config/pos_loyalty.php; data: api/pos_customer.php). Customers without a website account get
 * an "Account QR": they scan it, add an email and password, and log in with everything saved.
 *
 * script.js asks window.rgPosCustomer for the discounts (pricing), the form fields to send
 * (fields) and, after a sale, hands back what was earned (afterSale).
 * Styles: pc-* in assets/css/studio-ui.css.
 */
(function () {
  'use strict';
  var API = '../api/pos_customer.php';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var cur = function () { return window.globalCurrencySymbol || '₹'; };
  var money = function (n) { n = Number(n || 0); return cur() + n.toLocaleString('en-IN', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 }); };
  var notify = function (m, t) { if (window.SX && SX.notify) SX.notify(m, t); else if (window.showNotification) showNotification(m, t); };
  var initials = function (n) { return String(n || '?').split(/\s+/).map(function (w) { return w.charAt(0); }).join('').slice(0, 2).toUpperCase(); };

  var cust = null;        // posCustomerSummary() from the server
  var ptsOn = false, pts = 0, walletOn = false, gift = null;
  var lastSubtotal = 0;

  function refresh() { if (typeof window.updatePOSCartSummaryHook === 'function') window.updatePOSCartSummaryHook(); paint(); }

  /* ── pricing ── */
  // no internet (pos-offline.js): points, wallet and rewards can't be checked, so none are taken off
  function offline() { return !!(window.rgPosOffline && window.rgPosOffline.isOffline()); }
  function giftDiscount(sub) {
    if (!gift || sub <= 0 || sub < gift.min_order) return 0;
    var d = gift.type === 'percent' ? Math.round(sub * gift.value) / 100 : gift.value;
    return Math.min(Math.round(d * 100) / 100, sub);
  }
  function pointsRule() {
    var L = cust && cust.loyalty;
    return L && L.enabled && L.type === 'points' && L.redeem_value_per_point > 0 ? L : null;
  }
  function maxPoints(sub) {
    var L = pointsRule(); if (!L) return 0;
    return Math.max(0, Math.min(L.points, Math.floor((sub - giftDiscount(sub)) / L.redeem_value_per_point + 1e-9)));
  }
  // what the bill takes off (before tax), from the cart's subtotal
  function pricing(sub) {
    lastSubtotal = sub;
    var out = { coupon: 0, couponCode: '', points: 0, pointsDiscount: 0 };
    if (!cust || offline()) return out;
    out.coupon = giftDiscount(sub);
    if (out.coupon > 0) out.couponCode = gift.code;
    var L = pointsRule();
    if (L && ptsOn) {
      var p = Math.min(pts, maxPoints(sub));
      if (p >= L.min_redeem && p > 0) { out.points = p; out.pointsDiscount = Math.round(p * L.redeem_value_per_point * 100) / 100; }
    }
    return out;
  }
  function walletFor(total) { return cust && !offline() && walletOn && cust.wallet.enabled ? Math.round(Math.min(cust.wallet.balance, total) * 100) / 100 : 0; }

  /* ── the box in the cart ── */
  function box() {
    var b = document.getElementById('posCustBox');
    if (b) return b;
    var after = document.querySelector('#posPage .pos-table-select');
    if (!after) return null;
    b = document.createElement('div');
    b.id = 'posCustBox';
    b.className = 'pc-box';
    after.after(b);
    return b;
  }
  function paint() {
    var b = box(); if (!b) return;
    if (!cust) {
      b.innerHTML = '<button type="button" class="pc-add" data-pc="open">' + ic('person_add') + '<span><b>Add customer</b><small>Points, rewards and bill on their name</small></span>' + ic('chevron_right') + '</button>';
      return;
    }
    var L = cust.loyalty, sub = lastSubtotal, html = '';
    if (offline()) {
      b.innerHTML = '<div class="pc-card"><div class="pc-top"><span class="pc-av">' + esc(initials(cust.name)) + '</span><span class="pc-who"><b>' + esc(cust.name) + '</b><small>' + esc(cust.phone) + '</small></span>' +
        '<button type="button" class="pc-ib" data-pc="clear" title="Remove customer">' + ic('close') + '</button></div><p class="pc-hint">' + ic('cloud_off') + ' Offline: points, wallet and rewards need the internet. The bill is still on their name and earns when it syncs.</p></div>';
      return;
    }
    html += '<div class="pc-card"><div class="pc-top"><span class="pc-av">' + esc(initials(cust.name)) + '</span><span class="pc-who"><b>' + esc(cust.name) + '</b><small>' + esc(cust.phone) + ' · ' + cust.visits + ' visit' + (cust.visits === 1 ? '' : 's') + (L.tier ? ' · ' + esc(L.tier) : '') + '</small></span>' +
      '<button type="button" class="pc-ib" data-pc="open" title="Change customer">' + ic('swap_horiz') + '</button><button type="button" class="pc-ib" data-pc="clear" title="Remove customer">' + ic('close') + '</button></div>';
    if (L.enabled && L.type === 'points') {
      var mx = maxPoints(sub), can = L.points >= L.min_redeem && mx >= L.min_redeem;
      html += '<div class="pc-row"><span class="pc-pill">' + ic('star') + '<b>' + L.points.toLocaleString('en-IN') + '</b> points</span><small>worth ' + money(L.points_value) + '</small></div>';
      if (can) {
        if (pts > mx || !pts) pts = mx;
        html += '<label class="pc-sw"><input type="checkbox" data-pc="pts"' + (ptsOn ? ' checked' : '') + '><i></i><span>Use points</span>' + (ptsOn ? '<em>−' + money(Math.round(pts * L.redeem_value_per_point * 100) / 100) + '</em>' : '') + '</label>';
        if (ptsOn) html += '<div class="pc-step"><button type="button" data-pc="ptsminus" aria-label="Fewer points">' + ic('remove') + '</button><input type="number" data-pc="ptsval" min="' + L.min_redeem + '" max="' + mx + '" value="' + pts + '"><button type="button" data-pc="ptsplus" aria-label="More points">' + ic('add') + '</button><button type="button" class="pc-max" data-pc="ptsmax">Max ' + mx + '</button></div>';
      } else {
        html += '<p class="pc-hint">' + (L.points < L.min_redeem ? 'Points can be used from ' + L.min_redeem + '.' : 'Add more to the bill to use points.') + ' This bill earns about ' + Math.floor(sub / (L.earn_per || 100)) * L.earn_points + ' points.</p>';
      }
    } else if (L.enabled && L.type === 'stamps') {
      var dots = ''; for (var i = 0; i < L.stamp_goal; i++) dots += '<i class="' + (i < L.stamps ? 'on' : '') + '"></i>';
      html += '<div class="pc-row"><span class="pc-stamps">' + dots + '</span><small>' + L.stamps + ' of ' + L.stamp_goal + (L.stamp_reward ? ' · then ' + esc(L.stamp_reward) : '') + '</small></div>';
    } else if (L.enabled && L.type === 'cashback') {
      html += '<p class="pc-hint">Earns ' + L.cashback_pct + '% cashback into their wallet when paid.</p>';
    } else if (!L.enabled) {
      html += '<p class="pc-hint">Loyalty is off. Turn it on in Growth › Loyalty to give points.</p>';
    }
    if (cust.wallet.enabled && cust.wallet.balance > 0) {
      html += '<label class="pc-sw"><input type="checkbox" data-pc="wallet"' + (walletOn ? ' checked' : '') + '><i></i><span>Pay from wallet</span><em class="soft">' + money(cust.wallet.balance) + ' balance</em></label>';
    }
    if (cust.gifts.length) {
      html += '<div class="pc-gifts">' + cust.gifts.map(function (g, i) {
        var lbl = g.label || (g.type === 'percent' ? g.value + '% off' : money(g.value) + ' off');
        var on = gift && gift.code === g.code, short = sub > 0 && sub < g.min_order;
        return '<button type="button" data-pc="gift" data-i="' + i + '" class="' + (on ? 'on' : '') + (short ? ' short' : '') + '" title="' + esc(g.code + (g.min_order ? ' · min ' + money(g.min_order) : '')) + '">' + ic(on ? 'check_circle' : 'redeem') + esc(lbl) + '</button>';
      }).join('') + '</div>';
    }
    if (!cust.has_account) html += '<button type="button" class="pc-acc" data-pc="qr">' + ic('qr_code_2') + '<span><b>Account QR</b><small>Not on the website yet: they scan it to log in with these points</small></span></button>';
    html += '</div>';
    b.innerHTML = html;
  }

  function attach(c) {
    cust = c; ptsOn = false; pts = 0; walletOn = false; gift = null;
    refresh();
  }
  function load(id) {
    return fetch(API + '?id=' + id, { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.success) { notify(d.message || 'Could not load the customer.', 'error'); return; }
      attach(d.customer);
    });
  }

  /* ── pick / add window ── */
  function sheet() {
    var ov = document.createElement('div');
    ov.className = 'pc-ov';
    ov.innerHTML = '<div class="pc-sheet" role="dialog" aria-modal="true" aria-label="Customer"><div class="pc-sh"><b>Customer for this bill</b><button type="button" class="pc-ib" data-x aria-label="Close">' + ic('close') + '</button></div>' +
      '<div class="pc-sb"><div class="pc-search">' + ic('search') + '<input type="search" placeholder="Search name or phone" autocomplete="off"></div><div class="pc-results"><p class="pc-hint">Type at least 2 letters or digits.</p></div>' +
      '<form class="pc-new" hidden><div class="pc-new-h">' + ic('person_add') + '<b>New customer</b></div>' +
      '<label>Name<input name="name" maxlength="100" required></label><label>Phone<input name="phone" type="tel" inputmode="tel" maxlength="15" required></label>' +
      '<div class="pc-2"><label>Email <small>optional</small><input name="email" type="email"></label><label>Birthday <small>optional</small><input name="birthday" type="date" max="' + new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10) + '"></label></div>' +
      '<button type="submit" class="pc-btn">' + ic('check') + 'Save and add to bill</button></form></div>' +
      '<div class="pc-sf"><button type="button" class="pc-btn ghost" data-new>' + ic('person_add') + 'New customer</button><button type="button" class="pc-btn ghost" data-skip>Continue without customer</button></div></div>';
    document.body.appendChild(ov);
    var q = ov.querySelector('.pc-search input'), res = ov.querySelector('.pc-results'), form = ov.querySelector('.pc-new'), t = 0, list = [];
    var fld = function (n) { return form.querySelector('[name=' + n + ']'); };
    function close() { ov.remove(); document.removeEventListener('keydown', key, true); }
    function key(e) { if (e.key === 'Escape') { e.stopPropagation(); close(); } }
    document.addEventListener('keydown', key, true);
    function showNew(prefill) {
      form.hidden = false;
      var digits = String(prefill || '').replace(/\D/g, '');
      if (digits.length >= 6) fld('phone').value = digits; else if (prefill && !digits) fld('name').value = prefill;
      (fld('name').value ? fld('phone') : fld('name')).focus();
    }
    function search() {
      var v = q.value.trim();
      if (v.length < 2) { res.innerHTML = '<p class="pc-hint">Type at least 2 letters or digits.</p>'; return; }
      fetch(API + '?q=' + encodeURIComponent(v), { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
        list = (d && d.customers) || [];
        res.innerHTML = list.length ? list.map(function (c, i) {
          return '<button type="button" class="pc-res" data-i="' + i + '"><span class="pc-av sm">' + esc(initials(c.name)) + '</span><span class="pc-who"><b>' + esc(c.name) + '</b><small>' + esc(c.phone || 'no phone') + ' · ' + c.visits + ' visits · ' + money(c.spent) + '</small></span>' +
            (c.points ? '<span class="pc-pill sm">' + ic('star') + c.points + '</span>' : '') + (c.has_account ? '<span class="pc-tag">Online</span>' : '') + '</button>';
        }).join('') : '<p class="pc-hint">No customer matches “' + esc(v) + '”.</p><button type="button" class="pc-btn" data-newfrom>' + ic('person_add') + 'Add “' + esc(v) + '” as a new customer</button>';
      });
    }
    q.addEventListener('input', function () { clearTimeout(t); t = setTimeout(search, 220); });
    ov.addEventListener('click', function (e) {
      if (e.target === ov || e.target.closest('[data-x]')) { close(); return; }
      if (e.target.closest('[data-skip]')) { cust = null; refresh(); close(); return; }
      if (e.target.closest('[data-new]')) { showNew(q.value.trim()); return; }
      if (e.target.closest('[data-newfrom]')) { showNew(q.value.trim()); return; }
      var r = e.target.closest('.pc-res');
      if (r) { var c = list[+r.dataset.i]; close(); load(c.id); }
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = form.querySelector('button[type=submit]'); btn.disabled = true;
      fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'create', name: fld('name').value, phone: fld('phone').value, email: fld('email').value, birthday: fld('birthday').value }) })
        .then(function (r) { return r.json(); }).then(function (d) {
          btn.disabled = false;
          if (!d.success) { notify(d.message || 'Could not save.', 'error'); return; }
          notify(d.existed ? 'This phone already belongs to ' + d.customer.name + ', added to the bill' : 'Customer added', 'success');
          close(); attach(d.customer);
        }).catch(function () { btn.disabled = false; notify('Could not save. Check your connection.', 'error'); });
    });
    setTimeout(function () { q.focus(); }, 30);
  }

  /* ── account QR (also used by the Customers page) ── */
  function siteLoginUrl(code) {
    var menu = window.getMarketingMenuUrl ? window.getMarketingMenuUrl() : '';
    var url = /\/menu$/.test(menu) ? menu.replace(/\/menu$/, '/login') : menu.replace('website/menu.php', 'website/login.php');
    return url + (url.indexOf('?') > -1 ? '&' : '?') + 'claim=' + code;
  }
  window.rgCustomerAccountQR = function (id, name) {
    return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'claim', id: id }) })
      .then(function (r) { return r.json(); }).then(function (d) {
        if (!d.success) { notify(d.message || 'Could not make the QR code.', 'error'); return; }
        var link = siteLoginUrl(d.code), qr = window.MK && MK.qr ? MK.qr(link) : null;
        var html = '<div class="pc-qr">' + (qr ? '<img alt="QR code" src="' + qr.toDataURL('image/png') + '">' : '') +
          '<p>Ask <b>' + esc(name || 'the customer') + '</b> to scan this with their phone camera. They add an email and a password, and their points and visits are already in the account.</p>' +
          '<div class="pc-qr-link"><input readonly value="' + esc(link) + '"><button type="button" class="pc-btn ghost sm" data-copy>' + ic('content_copy') + 'Copy</button></div>' +
          '<small>Works once, for ' + d.valid_days + ' days. Making a new QR does not cancel this one.</small></div>';
        window.rgModal({ title: 'Account QR for ' + (name || 'customer'), icon: 'qr_code_2', html: html, confirmText: 'Done', cancelText: null, onOpen: function (card) {
          card.querySelector('[data-copy]').addEventListener('click', function () { navigator.clipboard.writeText(link).then(function () { notify('Link copied', 'success'); }); });
        } });
      });
  };

  /* ── events on the box ── */
  document.addEventListener('click', function (e) {
    var t = e.target.closest('#posCustBox [data-pc]');
    if (!t) return;
    var a = t.getAttribute('data-pc'), L = cust && cust.loyalty;
    if (a === 'open') { sheet(); return; }
    if (a === 'clear') { cust = null; refresh(); return; }
    if (a === 'qr') { window.rgCustomerAccountQR(cust.id, cust.name); return; }
    if (a === 'ptsminus' || a === 'ptsplus') { var step = Math.max(1, Math.round(10 / (L.redeem_value_per_point || 1))); pts = Math.max(L.min_redeem, Math.min(maxPoints(lastSubtotal), pts + (a === 'ptsplus' ? step : -step))); refresh(); return; }
    if (a === 'ptsmax') { pts = maxPoints(lastSubtotal); refresh(); return; }
    if (a === 'gift') { var g = cust.gifts[+t.dataset.i]; if (lastSubtotal < g.min_order) { notify('This reward needs a bill of ' + money(g.min_order) + ' or more.', 'error'); return; } gift = gift && gift.code === g.code ? null : g; refresh(); }
  });
  document.addEventListener('change', function (e) {
    var t = e.target.closest('#posCustBox [data-pc]');
    if (!t) return;
    var a = t.getAttribute('data-pc');
    if (a === 'pts') { ptsOn = t.checked; pts = maxPoints(lastSubtotal); refresh(); }
    if (a === 'wallet') { walletOn = t.checked; refresh(); }
    if (a === 'ptsval') { var L = cust.loyalty; pts = Math.max(L.min_redeem, Math.min(maxPoints(lastSubtotal), parseInt(t.value, 10) || 0)); refresh(); }
  });

  /* ── what script.js uses ── */
  window.rgPosCustomer = {
    get: function () { return cust; },
    pricing: pricing,
    wallet: walletFor,
    // the order's form fields
    fields: function (sub, total) {
      if (!cust) return null;
      var p = pricing(sub);
      return { customerId: cust.id, customerName: cust.name, customerPhone: cust.phone, couponCode: p.couponCode, redeemPoints: p.points, walletAmount: walletFor(total) };
    },
    // the summary rows under the cart (discounts, wallet)
    paintSummary: function (p, total) {
      var sum = document.querySelector('#posPage .pos-cart-summary');
      if (!sum) return;
      var rows = sum.querySelector('#posDiscRows');
      if (!rows) { rows = document.createElement('div'); rows.id = 'posDiscRows'; var first = sum.querySelector('.cart-summary-row'); sum.insertBefore(rows, first ? first.nextSibling : sum.firstChild); }   // under the subtotal, before tax
      var w = walletFor(total), h = '';
      if (p && p.coupon > 0) h += '<div class="cart-summary-row pc-disc"><span>Reward ' + esc(p.couponCode) + '</span><span>−' + money(p.coupon) + '</span></div>';
      if (p && p.pointsDiscount > 0) h += '<div class="cart-summary-row pc-disc"><span>' + p.points + ' points</span><span>−' + money(p.pointsDiscount) + '</span></div>';
      rows.innerHTML = h;
      var after = sum.querySelector('#posWalletRow');
      if (!after) { after = document.createElement('div'); after.id = 'posWalletRow'; sum.appendChild(after); }
      after.innerHTML = w > 0 ? '<div class="cart-summary-row pc-disc"><span>From wallet</span><span>−' + money(w) + '</span></div><div class="cart-summary-row pc-due"><span>To collect</span><span>' + money(Math.max(0, total - w)) + '</span></div>' : '';
      paint();
    },
    // after a sale: tell the cashier what the customer earned, then clear for the next bill
    afterSale: function (loyalty) {
      if (loyalty && loyalty.earned) {
        var e = loyalty.earned, bits = [];
        if (e.points) bits.push(e.points + ' points');
        if (e.stamp) bits.push('a stamp');
        if (e.cashback) bits.push(money(e.cashback) + ' cashback');
        if (e.reward) bits.push('a reward: ' + e.reward.label);
        if (bits.length && cust) notify(cust.name + ' earned ' + bits.join(', '), 'success');
      }
      cust = null; ptsOn = false; walletOn = false; gift = null; pts = 0;
      paint();
    },
    reset: function () { cust = null; ptsOn = false; walletOn = false; gift = null; pts = 0; paint(); },
    load: load   // pick a customer by id (resuming a held order)
  };

  function init() { paint(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
