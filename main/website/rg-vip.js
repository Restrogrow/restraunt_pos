/*
 * VIP membership on the rewards page (Loyalty Studio > VIP membership).
 *
 *   <div id="lxVip"></div>   — loyalty.php puts this in its body; window.lxVipMount() fills it
 *
 * A member sees their card (plan, valid till, perks, what it has saved them).
 * Everyone else sees the plans: join with PhonePe or from the wallet. After
 * PhonePe the customer comes back with ?vip_txn=…, checked here with
 * vip_api.php?action=status (which asks PhonePe itself).
 */
(function () {
  'use strict';

  var API = 'vip_api.php', V = null, open = null, renewShown = false, busy = false;
  var CUR = '₹';

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(n) { n = Math.round(n * 100) / 100; return CUR + (n % 1 ? n.toFixed(2) : n.toLocaleString('en-IN')); }
  function day(d) { var x = new Date(String(d).replace(' ', 'T')); return isNaN(x) ? d : x.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }); }
  function toast(msg, type) {
    var t = document.querySelector('.lx-toast'); if (t) t.remove();
    t = document.createElement('div'); t.className = 'lx-toast' + (type === 'error' ? ' error' : ''); t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('show'); });
    setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, 3200);
  }
  var LEN = { 30: 'month', 90: '3 months', 180: '6 months', 365: 'year' };
  function per(p) { return LEN[p.duration_days] || p.duration_days + ' days'; }

  function perks(p) {
    var out = [], prog = V.program_type;
    if (+p.discount_pct > 0) out.push(['fa-percent', (+p.discount_pct) + '% off every order' + (+p.discount_max > 0 ? ' (up to ' + money(p.discount_max) + ')' : '')]);
    if (+p.free_delivery) out.push(['fa-truck-fast', 'Free delivery' + (+p.free_delivery_min > 0 ? ' above ' + money(p.free_delivery_min) : '')]);
    if (+p.earn_boost > 1 && prog !== 'stamps') out.push(['fa-bolt', (+p.earn_boost) + 'x ' + (prog === 'cashback' ? 'cashback' : 'points')]);
    if (p.gift) out.push(['fa-gift', 'Welcome gift: ' + p.gift]);
    return out;
  }

  function memberCard() {
    var m = V.membership;
    return '<section class="lx-sec lxv-sec in"><div class="lxv-card">' +
      '<div class="lxv-card-top"><span class="lxv-crest"><i class="fa fa-gem"></i></span><div><small>' + esc(V.name) + ' member</small><b>' + esc(m.plan) + ' plan</b></div>' +
        '<span class="lxv-left">' + m.days_left + ' day' + (m.days_left === 1 ? '' : 's') + ' left</span></div>' +
      '<div class="lxv-perks">' + perks(m).filter(function (x) { return x[0] !== 'fa-gift'; }).map(function (x) { return '<span><i class="fa ' + x[0] + '"></i>' + esc(x[1]) + '</span>'; }).join('') + '</div>' +
      '<div class="lxv-foot"><div><small>Saved so far</small><b>' + money(m.saved) + '</b></div><div><small>Valid till</small><b>' + esc(day(m.ends_at)) + '</b></div></div>' +
    '</div>' +
    (V.plans.length ? '<button type="button" class="lx-btn light lxv-renew" data-vip-renew>' + (renewShown ? 'Hide plans' : 'Extend membership') + '</button>' + (renewShown ? plansHtml(true) : '') : '') +
    '</section>';
  }

  function plansHtml(renew) {
    return '<div class="lxv-plans">' + V.plans.map(function (p) {
      var o = open === p.id, w = V.wallet_balance;
      return '<div class="lxv-plan' + (p.highlight ? ' hl' : '') + (o ? ' open' : '') + '">' + (p.highlight ? '<span class="lxv-badge">Most popular</span>' : '') +
        '<b class="lxv-name">' + esc(p.name) + '</b>' +
        '<div class="lxv-price"><b>' + money(p.price) + '</b><span>/ ' + esc(per(p)) + '</span></div>' +
        (p.duration_days > 31 ? '<small class="lxv-pm">' + money(Math.round(p.price / (p.duration_days / 30))) + ' a month</small>' : '<small class="lxv-pm">&nbsp;</small>') +
        '<ul>' + perks(p).map(function (x) { return '<li><i class="fa ' + x[0] + '"></i>' + esc(x[1]) + '</li>'; }).join('') + '</ul>' +
        (!V.logged_in ? '<a class="lx-btn gold" href="' + esc(window.lxLoginUrl) + '">Log in to join</a>'
          : o ? '<div class="lxv-pay"><button type="button" class="lx-btn gold" data-vip-pay="phonepe" data-plan="' + p.id + '"><i class="fa fa-lock"></i> Pay ' + money(p.price) + '</button>' +
                (w != null ? '<button type="button" class="lx-btn light" data-vip-pay="wallet" data-plan="' + p.id + '"' + (w < p.price ? ' disabled' : '') + '><i class="fa fa-wallet"></i> From wallet (' + money(w) + ')</button>' : '') +
                '<small>Secure payment. Membership starts ' + (renew ? 'when your current one ends' : 'right away') + '.</small></div>'
              : '<button type="button" class="lx-btn gold" data-vip-open="' + p.id + '">' + (renew ? 'Extend with ' + esc(p.name) : 'Join ' + esc(V.name)) + '</button>') +
      '</div>';
    }).join('') + '</div>';
  }

  function joinSection() {
    var best = V.plans.reduce(function (b, p) { return Math.max(b, +p.discount_pct || 0); }, 0);
    return '<section class="lx-sec lxv-sec in"><div class="lxv-head"><span class="lxv-crest"><i class="fa fa-gem"></i></span><div><h2>' + esc(V.name) + '</h2>' +
      '<p>' + esc(V.tagline || (best ? 'Up to ' + best + '% off every order, plus member perks.' : 'Member perks on every order.')) + '</p></div></div>' +
      plansHtml(false) + '</section>';
  }

  function draw() {
    var el = document.getElementById('lxVip');
    if (!el) return;
    if (!V || !V.enabled || !V.plans) { el.innerHTML = ''; return; }
    if (!V.membership && !V.plans.length) { el.innerHTML = ''; return; }
    el.innerHTML = V.membership ? memberCard() : joinSection();
  }

  function fetchSummary() {
    return fetch(API + '?action=summary&restaurant_id=' + encodeURIComponent(window.restaurantId), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.success) { V = d; if (d.currency) CUR = d.currency; } draw(); });
  }

  // Back from PhonePe
  function checkReturn() {
    var q = new URLSearchParams(location.search), txn = q.get('vip_txn');
    if (!txn) return Promise.resolve();
    var demo = q.get('vip_demo') ? '&demo=1' : '';
    q.delete('vip_txn'); q.delete('vip_demo');
    history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q.toString() : '') + location.hash);
    return fetch(API + '?action=status&restaurant_id=' + encodeURIComponent(window.restaurantId) + '&txn=' + encodeURIComponent(txn) + demo, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) { toast(d.message || 'Could not check the payment', 'error'); return; }
        if (d.status === 'Active') { toast('Welcome to the club! Your perks are on.'); setTimeout(function () { location.reload(); }, 1400); }
        else if (d.status === 'Failed') toast('The payment did not go through. You were not charged.', 'error');
        else toast('Payment is still processing. Check back in a minute.');
      }).catch(function () {});
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('#lxVip')) return;
    var t;
    if (e.target.closest('[data-vip-renew]')) { renewShown = !renewShown; open = null; draw(); return; }
    if ((t = e.target.closest('[data-vip-open]'))) { open = +t.getAttribute('data-vip-open'); draw(); return; }
    if ((t = e.target.closest('[data-vip-pay]'))) {
      if (busy) return;
      busy = true; t.disabled = true;
      var fd = new FormData();
      fd.append('action', 'join'); fd.append('restaurant_id', window.restaurantId); fd.append('plan_id', t.getAttribute('data-plan'));
      fd.append('pay', t.getAttribute('data-vip-pay')); fd.append('return_path', location.pathname + location.search);
      fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
        busy = false; t.disabled = false;
        if (!d.success) { toast(d.message || 'Could not start the payment', 'error'); return; }
        if (d.payment_url) { location.href = d.payment_url; return; }
        toast('Welcome to the club! Your perks are on.');
        setTimeout(function () { location.reload(); }, 1400);
      }).catch(function () { busy = false; t.disabled = false; toast('Network error. Please try again.', 'error'); });
    }
  });

  var ready = checkReturn().then(fetchSummary);
  window.lxVipMount = function () { ready.then(draw); };
})();
