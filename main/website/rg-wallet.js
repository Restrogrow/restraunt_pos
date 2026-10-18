/*
 * Wallet card on the profile page (Offers > Wallet).
 *
 *   <div id="pfWallet" data-cur="₹"></div>
 *
 * A bank-card style balance, "Add money" (the restaurant's quick amounts and
 * top-up offers, You pay / You get) and the history. Adding money goes to
 * PhonePe; when PhonePe sends the customer back (?wallet_txn=…) the payment
 * is confirmed with PhonePe on the server before the money shows up.
 * Logged-in customers only.
 */
(function () {
  'use strict';

  var box = document.getElementById('pfWallet');
  if (!box) return;
  var rid = (document.querySelector('meta[name=restaurant-id]') || {}).content || '';
  var cur = box.getAttribute('data-cur') || '₹';
  var API = 'wallet_api.php';
  var data = null, amount = 0, panel = '', busy = false, shownBal = null;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(n) { n = Math.round(n * 100) / 100; return cur + (n % 1 ? n.toFixed(2) : n.toLocaleString('en-IN')); }
  function bonusFor(a) { var b = 0; (data.bonus_tiers || []).forEach(function (t) { if (a >= t.amount) b = t.bonus; }); return b; }
  function toast(msg, type) { if (typeof window.showToast === 'function') window.showToast(msg, type); }
  function svg(p) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>'; }
  var I = {
    wallet: svg('<path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h14a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>'),
    plus: svg('<path d="M12 5v14M5 12h14"/>'),
    list: svg('<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>'),
    gift: svg('<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C9.5 3 11 5 12 8c1-3 2.5-5 4.5-5a2.5 2.5 0 0 1 0 5"/>'),
    back: svg('<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>'),
    down: svg('<path d="M12 5v14M19 12l-7 7-7-7"/>'),
    up: svg('<path d="M12 19V5M5 12l7-7 7 7"/>'),
    lock: svg('<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>'),
    shield: svg('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>'),
  };
  var TYPES = {
    topup: ['Money added', 'in', 'down'], bonus: ['Top-up bonus', 'gift', 'gift'], spend: ['Paid for an order', 'out', 'up'],
    refund: ['Refund', 'in', 'back'], cashback: ['Cashback', 'gift', 'gift'], adjust: ['From the restaurant', 'in', 'down'],
  };

  function fmtWhen(s) {
    var d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d) ? '' : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) + ' · ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
  }

  function cardHtml(balanceHtml, meta) {
    return '<div class="wl-card"><span class="wl-shine"></span>' +
      '<div class="wl-row"><span class="wl-name">' + I.wallet + esc(data.name) + '</span><span class="wl-chipico"></span></div>' +
      balanceHtml + '<div class="wl-meta">' + meta + '</div></div>';
  }
  function offersHtml() {
    var bits = (data.bonus_tiers || []).map(function (t) {
      return '<div class="wl-offer"><i>' + I.gift + '</i><div><b>Get ' + money(t.bonus) + ' extra</b>on adding ' + money(t.amount) + '</div></div>';
    });
    if (data.cashback_pct > 0) bits.push('<div class="wl-offer cb"><i>' + I.back + '</i><div><b>' + (+data.cashback_pct) + '% cashback</b>on every order' + (data.cashback_max > 0 ? ', up to ' + money(data.cashback_max) : '') + '</div></div>');
    return bits.length ? '<div class="wl-offers"><div class="wl-track">' + bits.join('') + '</div></div>' : '';
  }

  function addHtml() {
    var b = amount ? bonusFor(amount) : 0;
    var quick = data.quick_amounts || [];
    return '<div class="wl-add"><h4>How much do you want to add?</h4>' +
      '<div class="wl-amts">' + quick.map(function (a) {
        var x = bonusFor(a);
        return '<button type="button" class="wl-amt' + (amount === a ? ' on' : '') + '" data-amt="' + a + '">' + money(a) + (x ? '<small>+' + money(x) + ' extra</small>' : '<small>&nbsp;</small>') + '</button>';
      }).join('') + '</div>' +
      '<label class="wl-own">' + cur + '<input type="number" inputmode="numeric" id="wlAmount" min="' + data.min_topup + '" max="' + data.max_topup + '" placeholder="Other amount (' + money(data.min_topup) + ' to ' + money(data.max_topup) + ')" value="' + (amount && quick.indexOf(amount) === -1 ? amount : '') + '"></label>' +
      '<div class="wl-sum"><div>You pay<b>' + (amount ? money(amount) : '—') + '</b></div><span class="arrow">→</span><div class="get">You get<b>' + (amount ? money(amount + b) : '—') + '</b></div></div>' +
      '<button type="button" class="wl-pay" data-wl="pay"' + (amount && !busy ? '' : ' disabled') + '>' + I.lock + (busy ? 'Opening PhonePe…' : 'Pay ' + (amount ? money(amount) : '') + ' with PhonePe') + '</button>' +
      '<p class="wl-fine">' + I.shield + '<span>Secure payment by PhonePe. Wallet money can be used only at this restaurant, and comes back here if an order is cancelled.</span></p>' +
    '</div>';
  }

  function histHtml() {
    var hist = data.history || [];
    if (!hist.length) return '<div class="wl-empty">No activity yet. Add money once and pay for orders in a tap.</div>';
    return '<div class="wl-hist"><h4><span>History</span></h4>' + hist.slice(0, 25).map(function (h) {
      var t = TYPES[h.type] || [h.type, h.amount >= 0 ? 'in' : 'out', h.amount >= 0 ? 'down' : 'up'], plus = h.amount >= 0;
      return '<div class="wl-item"><span class="wl-dot ' + t[1] + '">' + I[t[2]] + '</span>' +
        '<span class="wl-what">' + esc(t[0]) + '<small>' + esc(h.order ? 'Order ' + h.order : (h.note || '')) + ' · ' + fmtWhen(h.when) + '</small></span>' +
        '<span class="wl-amtx' + (plus ? ' in' : '') + '">' + (plus ? '+' : '−') + money(Math.abs(h.amount)) + '</span></div>';
    }).join('') + '</div>';
  }

  function render() {
    if (!data || !data.enabled) { box.innerHTML = ''; box.hidden = true; showStat(); return; }
    box.hidden = false;
    if (!data.logged_in) {
      box.innerHTML = '<div class="wl-wrap">' +
        cardHtml('<div class="wl-lbl">Your wallet</div><div class="wl-bal" style="font-size:22px">Log in to see it</div>', '<span>Add money, pay in one tap</span>') +
        offersHtml() +
        '<div class="wl-actions"><a class="wl-act main full" href="' + esc(window.loginUrl || 'login.php') + '">Log in</a></div></div>';
      startStrip();
      showStat();
      return;
    }
    box.innerHTML = '<div class="wl-wrap">' +
      cardHtml('<div class="wl-lbl">Available balance</div><div class="wl-bal" id="wlBal">' + money(shownBal == null ? data.balance : shownBal) + '</div>',
        '<span>•••• ' + esc(data.phone_last4 || '') + '</span><span>Only at this restaurant</span>') +
      '<div class="wl-actions">' +
        '<button type="button" class="wl-act ' + (panel === 'add' ? 'on' : 'main') + '" data-wl="add">' + I.plus + (panel === 'add' ? 'Close' : 'Add money') + '</button>' +
        '<button type="button" class="wl-act' + (panel === 'hist' ? ' on' : '') + '" data-wl="hist">' + I.list + 'History</button>' +
      '</div>' +
      offersHtml() +
      (panel === 'add' ? addHtml() : '') +
      (panel === 'hist' ? histHtml() : '') +
    '</div>';
    if (shownBal == null) countUp();
    startStrip();
    showStat();
  }

  // Offers strip: scrolls by itself in a loop when it doesn't fit (pauses on touch / hover)
  function startStrip() {
    var wrap = box.querySelector('.wl-offers'), track = wrap && wrap.querySelector('.wl-track');
    if (!track || track.scrollWidth <= wrap.clientWidth + 2) return;
    var w = track.scrollWidth;
    track.innerHTML += track.innerHTML;                       // two copies: the loop is seamless
    wrap.classList.add('marquee');
    wrap.style.setProperty('--wl-d', Math.max(10, Math.round(w / 40)) + 's');
    wrap.addEventListener('touchstart', function () { wrap.classList.add('hold'); }, { passive: true });
    wrap.addEventListener('touchend', function () { wrap.classList.remove('hold'); });
  }

  // Wallet balance in the profile stats, between Orders and Total Spent
  function showStat() {
    var card = document.getElementById('statWalletCard'), num = document.getElementById('statWallet');
    if (!card || !num) return;
    var on = !!(data && data.enabled && data.logged_in);
    card.hidden = !on;
    if (card.parentNode) card.parentNode.classList.toggle('has-wallet', on);
    if (on) num.textContent = money(+data.balance || 0);
  }

  // The balance counts up the first time it shows
  function countUp() {
    var el = document.getElementById('wlBal'), to = +data.balance || 0;
    shownBal = to;
    if (!el || !to || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) return;
    var t0 = performance.now();
    (function step(t) {
      var p = Math.min(1, (t - t0) / 900), e = 1 - Math.pow(1 - p, 3);
      el.textContent = money(to * e);
      if (p < 1) requestAnimationFrame(step); else el.textContent = money(to);
    })(t0);
  }

  function load() {
    return fetch(API + '?action=summary&restaurant_id=' + encodeURIComponent(rid), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.success) { data = d; render(); } })
      .catch(function () {});
  }

  function pay() {
    if (!amount || busy) return;
    if (amount < data.min_topup || amount > data.max_topup) { toast('Add between ' + money(data.min_topup) + ' and ' + money(data.max_topup), 'error'); return; }
    busy = true; render();
    var fd = new FormData();
    fd.append('action', 'topup'); fd.append('restaurant_id', rid); fd.append('amount', amount);
    fd.append('return_path', location.pathname + location.search);
    fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success && d.payment_url) { window.location.href = d.payment_url; return; }
        busy = false; render(); toast(d.message || 'Could not start the payment', 'error');
      })
      .catch(function () { busy = false; render(); toast('Network error. Please try again.', 'error'); });
  }

  // Back from PhonePe: confirm the payment (with PhonePe, on the server)
  function confirmReturn() {
    var q = new URLSearchParams(location.search), txn = q.get('wallet_txn');
    if (!txn) return;
    var demo = q.get('wallet_demo') ? '&demo=1' : '', tries = 0;
    q.delete('wallet_txn'); q.delete('wallet_demo');
    try { history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : '')); } catch (e) {}
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    (function check() {
      fetch(API + '?action=status&restaurant_id=' + encodeURIComponent(rid) + '&txn=' + encodeURIComponent(txn) + demo, { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.success && d.status === 'Success') {
            toast(money(d.amount) + ' added to your wallet' + (d.bonus ? ' + ' + money(d.bonus) + ' extra' : ''), 'success');
            shownBal = null;
            load().then(function () { var bal = document.getElementById('wlBal'); if (bal) bal.classList.add('pop'); });
          } else if (d.success && d.status === 'Failed') {
            toast('The payment did not go through. No money was taken.', 'error');
          } else if (++tries < 45) {
            setTimeout(check, tries < 15 ? 2000 : 6000);   // ~30 s fast, then up to ~3 min
          } else {
            toast('Still waiting for PhonePe. The money shows up here as soon as it is confirmed.', 'info');
          }
        })
        .catch(function () { if (++tries < 45) setTimeout(check, tries < 15 ? 2000 : 6000); });
    })();
  }

  box.addEventListener('click', function (e) {
    var t = e.target.closest('[data-wl], [data-amt]');
    if (!t) return;
    if (t.hasAttribute('data-amt')) { amount = +t.getAttribute('data-amt'); render(); return; }
    var a = t.getAttribute('data-wl');
    if (a === 'add') { panel = panel === 'add' ? '' : 'add'; if (panel && !amount) { var q = data.quick_amounts || []; amount = q[1] || q[0] || 0; } render(); }
    else if (a === 'hist') { panel = panel === 'hist' ? '' : 'hist'; render(); }
    else if (a === 'pay') pay();
  });
  box.addEventListener('input', function (e) {
    if (e.target.id !== 'wlAmount') return;
    amount = Math.max(0, Math.round(+e.target.value || 0));
    var b = bonusFor(amount), sum = box.querySelector('.wl-sum'), btn = box.querySelector('[data-wl="pay"]');
    box.querySelectorAll('.wl-amt').forEach(function (x) { x.classList.toggle('on', +x.getAttribute('data-amt') === amount); });
    if (sum) sum.innerHTML = '<div>You pay<b>' + (amount ? money(amount) : '—') + '</b></div><span class="arrow">→</span><div class="get">You get<b>' + (amount ? money(amount + b) : '—') + '</b></div>';
    if (btn) { btn.disabled = !amount; btn.innerHTML = I.lock + 'Pay ' + (amount ? money(amount) : '') + ' with PhonePe'; }
  });

  var statCard = document.getElementById('statWalletCard');
  if (statCard) {
    var go = function () { box.scrollIntoView({ behavior: 'smooth', block: 'start' }); };
    statCard.addEventListener('click', go);
    statCard.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
  }

  load().then(confirmReturn);
})();
