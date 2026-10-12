/*
 * Offers > Wallet (admin)
 *
 * Customers add money with PhonePe and pay for orders from it. The owner sets
 * the limits, "add ₹X, get ₹Y extra" top-up offers and cashback on completed
 * orders, sees who holds how much, and can correct a balance by hand.
 * Saved through ../api/wallet_admin.php.
 */
(function () {
  'use strict';

  var API = '../api/wallet_admin.php';
  var st = null;

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function notify(msg, type) { if (typeof window.showNotification === 'function') window.showNotification(msg, type); }
  function cur() { return (st && st.currency) || '₹'; }
  function money(n) { return cur() + (Math.round(n * 100) / 100).toLocaleString(); }
  var TYPES = { topup: 'Added', bonus: 'Top-up bonus', spend: 'Paid for order', refund: 'Refund', cashback: 'Cashback', adjust: 'Changed by you' };

  function render() {
    var box = $('wlBody');
    if (!box || !st) return;
    var s = st.settings, k = st.stats;
    box.innerHTML =
      '<div class="scx-stats">' +
        '<div class="hi"><b>' + money(k.held) + '</b><span>In ' + k.holders + ' wallets now</span></div>' +
        '<div><b>' + money(k.topups) + '</b><span>' + k.topup_count + ' top-ups</span></div>' +
        '<div><b>' + money(k.spent) + '</b><span>Paid from wallets</span></div>' +
        '<div><b>' + money(k.bonus) + '</b><span>Bonus + cashback given</span></div>' +
        '<small>Last 30 days</small>' +
      '</div>' +
      (st.phonepe_ready ? '' : '<div class="wl-warn"><span class="material-symbols-rounded">info</span><div>Customers add money with PhonePe. Add your PhonePe keys in <b>Payments &gt; Payment Gateway</b> first, otherwise the "Add money" button won’t work for them.</div></div>') +

      '<div class="scx-cols">' +
      '<div>' +
        '<div class="up-card">' +
          '<label class="up-switch"><input type="checkbox" id="wlEnabled"' + (s.enabled ? ' checked' : '') + '><span></span> Turn on the wallet for customers</label>' +
          '<div class="scx-grid">' +
            '<label>Wallet name<input id="wlName" maxlength="40" value="' + esc(s.name) + '"></label>' +
            '<label>Smallest top-up (' + cur() + ')<input id="wlMin" type="number" min="1" value="' + (+s.min_topup) + '"></label>' +
            '<label>Biggest top-up (' + cur() + ')<input id="wlMax" type="number" min="1" value="' + (+s.max_topup) + '"></label>' +
            '<label>Most a wallet can hold (' + cur() + ')<input id="wlCap" type="number" min="1" value="' + (+s.max_balance) + '"></label>' +
            '<label>Quick amounts (comma separated)<input id="wlQuick" value="' + esc(s.quick_amounts.join(', ')) + '" placeholder="200, 500, 1000"></label>' +
          '</div>' +
        '</div>' +

        '<div class="up-card">' +
          '<h3><span class="material-symbols-rounded">redeem</span> Top-up offers</h3>' +
          '<p class="scx-note" style="margin:0 0 10px">Extra money for bigger top-ups, e.g. add ' + cur() + '1000, get ' + cur() + '100 extra. The best one reached applies.</p>' +
          s.bonus_tiers.map(function (t, i) {
            return '<div class="wl-tier" data-i="' + i + '"><span>Add</span><input class="wl-ta" type="number" min="1" value="' + (+t.amount) + '"><span>get</span><input class="wl-tb" type="number" min="1" value="' + (+t.bonus) + '"><span>extra</span>' +
              '<button type="button" class="up-pair-x wl-tdel" title="Remove">&times;</button></div>';
          }).join('') +
          (s.bonus_tiers.length < 6 ? '<button type="button" class="ts-add" id="wlAddTier"><span class="material-symbols-rounded">add</span> Add an offer</button>' : '') +
          '<h3 style="margin-top:18px"><span class="material-symbols-rounded">savings</span> Cashback</h3>' +
          '<div class="scx-grid">' +
            '<label>Cashback on completed orders (%)<input id="wlCbPct" type="number" min="0" max="50" step="0.5" value="' + (+s.cashback_pct || '') + '" placeholder="Off"></label>' +
            '<label>Most cashback per order (' + cur() + ')<input id="wlCbMax" type="number" min="0" value="' + (+s.cashback_max || '') + '" placeholder="No limit"></label>' +
          '</div>' +
          '<p class="scx-note">Cashback goes into the customer’s wallet when you mark the order Completed. Money paid from a wallet goes back by itself if an order is cancelled or rejected.</p>' +
        '</div>' +
      '</div>' +

      '<div>' +
        '<div class="up-card">' +
          '<h3><span class="material-symbols-rounded">tune</span> Correct a balance</h3>' +
          '<div class="wl-adj"><input id="wlAdjPhone" placeholder="Phone number" maxlength="15"><input id="wlAdjAmt" type="number" placeholder="+100 or -100"><input id="wlAdjNote" placeholder="Reason (customer sees it)" maxlength="120">' +
          '<button type="button" class="btn btn-primary" id="wlAdjGo">Apply</button></div>' +
        '</div>' +
        '<div class="up-card">' +
          '<h3><span class="material-symbols-rounded">account_balance_wallet</span> Customers with money</h3>' +
          (st.holders.length ? '<div class="scx-recent">' + st.holders.map(function (h) {
            return '<div><span class="ph">' + esc(h.customer_name || 'Customer') + '<small>' + esc(h.phone) + '</small></span><span></span><span class="tag used">' + money(h.balance) + '</span></div>';
          }).join('') + '</div>' : '<div class="up-empty">Nobody has added money yet.</div>') +
        '</div>' +
        '<div class="up-card">' +
          '<h3><span class="material-symbols-rounded">history</span> Latest activity</h3>' +
          (st.recent.length ? '<div class="scx-recent">' + st.recent.map(function (r) {
            var a = +r.amount;
            return '<div><span class="ph">' + esc(TYPES[r.type] || r.type) + '<small>' + esc(r.phone) + (r.order_number ? ' · ' + esc(r.order_number) : '') + '</small></span><span></span>' +
              '<span class="tag' + (a >= 0 ? ' used' : '') + '">' + (a >= 0 ? '+' : '−') + money(Math.abs(a)) + '</span></div>';
          }).join('') + '</div>' : '<div class="up-empty">No activity yet.</div>') +
        '</div>' +
      '</div>' +
      '</div>';
  }

  function load() {
    var box = $('wlBody');
    if (box && !st) box.innerHTML = '<div class="up-empty">Loading…</div>';
    fetch(API, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) { if (box) box.innerHTML = '<div class="up-empty">' + esc(d.message || 'Could not load') + '</div>'; return; }
        if (!d.settings.bonus_tiers.length && !d.settings.enabled) d.settings.bonus_tiers = [{ amount: 500, bonus: 25 }, { amount: 1000, bonus: 75 }];
        st = d;
        render();
      })
      .catch(function () { if (box) box.innerHTML = '<div class="up-empty">Could not load. Please try again.</div>'; });
  }
  function post(body) {
    return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(function (r) { return r.json(); });
  }
  function save() {
    var btn = $('wlSave');
    btn.disabled = true;
    post({ action: 'save', settings: st.settings })
      .then(function (d) { btn.disabled = false; notify(d.success ? 'Wallet saved' : (d.message || 'Could not save'), d.success ? 'success' : 'error'); if (d.success) load(); })
      .catch(function () { btn.disabled = false; notify('Network error. Please try again.', 'error'); });
  }
  function adjust() {
    var phone = $('wlAdjPhone').value, amt = +$('wlAdjAmt').value, note = $('wlAdjNote').value;
    post({ action: 'adjust', phone: phone, amount: amt, note: note })
      .then(function (d) { notify(d.success ? 'Balance is now ' + money(d.balance) : (d.message || 'Could not change it'), d.success ? 'success' : 'error'); if (d.success) load(); })
      .catch(function () { notify('Network error. Please try again.', 'error'); });
  }

  document.addEventListener('click', function (e) {
    if (!st || !e.target.closest || !e.target.closest('#walletPage')) return;
    var t;
    if ((t = e.target.closest('#wlSave'))) { save(); return; }
    if ((t = e.target.closest('#wlAdjGo'))) { adjust(); return; }
    if ((t = e.target.closest('#wlAddTier'))) { var last = st.settings.bonus_tiers[st.settings.bonus_tiers.length - 1]; st.settings.bonus_tiers.push({ amount: last ? last.amount * 2 : 500, bonus: last ? last.bonus * 2 : 25 }); render(); return; }
    if ((t = e.target.closest('.wl-tdel'))) { st.settings.bonus_tiers.splice(+t.closest('.wl-tier').getAttribute('data-i'), 1); render(); }
  });
  document.addEventListener('input', function (e) {
    if (!st || !e.target.closest || !e.target.closest('#walletPage')) return;
    var t = e.target, s = st.settings, row = t.closest('.wl-tier'), tier = row ? s.bonus_tiers[+row.getAttribute('data-i')] : null;
    if (t.id === 'wlEnabled') s.enabled = t.checked ? 1 : 0;
    else if (t.id === 'wlName') s.name = t.value;
    else if (t.id === 'wlMin') s.min_topup = +t.value;
    else if (t.id === 'wlMax') s.max_topup = +t.value;
    else if (t.id === 'wlCap') s.max_balance = +t.value;
    else if (t.id === 'wlQuick') s.quick_amounts = t.value.split(/[ ,]+/).map(Number).filter(function (n) { return n > 0; });
    else if (t.id === 'wlCbPct') s.cashback_pct = +t.value || 0;
    else if (t.id === 'wlCbMax') s.cashback_max = +t.value || 0;
    else if (tier && t.classList.contains('wl-ta')) tier.amount = +t.value || 0;
    else if (tier && t.classList.contains('wl-tb')) tier.bonus = +t.value || 0;
  });
  document.addEventListener('change', function (e) { if (e.target.id === 'wlEnabled' && st) st.settings.enabled = e.target.checked ? 1 : 0; });

  window.loadWalletAdmin = load;
})();
