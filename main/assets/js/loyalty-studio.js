/*
 * Loyalty Studio (Growth > Loyalty & Rewards).
 *
 * Tabs: Program (cashback / points / stamps + the existing points, levels and
 * referral settings), Autopilot, Games, VIP and Performance. Each tab draws
 * itself from ../api/loyalty_admin.php?section=<tab>.
 */
(function () {
  'use strict';

  var API = '../api/loyalty_admin.php';
  var TABS = [['program', 'Program', 'workspace_premium'], ['autopilot', 'Autopilot', 'auto_mode'], ['games', 'Games', 'sports_esports'], ['vip', 'VIP membership', 'diamond'], ['card', 'Phone card', 'wallet'], ['performance', 'Performance', 'insights']];
  var tab = 'program', P = null, D = {};

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function notify(msg, type) { if (typeof window.showNotification === 'function') window.showNotification(msg, type); }
  function cur() { return (D.currency) || '₹'; }
  function money(n) { return cur() + (Math.round(n * 100) / 100).toLocaleString('en-IN'); }
  function api(section) { return fetch(API + '?section=' + section, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }); }
  function post(body) { return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(function (r) { return r.json(); }); }

  function drawTabs() {
    var el = $('lsTabs');
    if (!el) return;
    el.innerHTML = TABS.map(function (t) {
      return '<button type="button" class="ls-tab' + (t[0] === tab ? ' on' : '') + '" data-ls-tab="' + t[0] + '"><span class="material-symbols-rounded">' + t[2] + '</span>' + t[1] + '</button>';
    }).join('');
    var legacy = $('lsLegacy');
    if (legacy) legacy.style.display = tab === 'program' ? '' : 'none';
  }

  /* ── Program ── */
  var TYPES = [
    ['cashback', 'Cashback', 'account_balance_wallet', 'A % of every order back in their wallet', 'Simple and well liked. Customers spend it on their next order.'],
    ['points', 'Points', 'toll', 'Points per ' + (window.globalCurrencySymbol || '₹') + ' spent, swapped for rewards', 'Flexible: discounts, free dishes and levels.'],
    ['stamps', 'Stamp card', 'approval', 'One stamp per order, a reward when the card is full', 'Best for frequent visits: cafés, quick bites.']
  ];
  function programHtml() {
    var p = P, m = D.members || {};
    var stamps = '';
    for (var i = 0; i < Math.min(p.stamp_goal, 12); i++) stamps += '<span class="ls-st' + (i < Math.min(3, p.stamp_goal - 1) ? ' on' : '') + (i === p.stamp_goal - 1 ? ' gift' : '') + '">' + (i < Math.min(3, p.stamp_goal - 1) ? '<span class="material-symbols-rounded">check</span>' : (i === p.stamp_goal - 1 ? '<span class="material-symbols-rounded">redeem</span>' : i + 1)) + '</span>';
    var itemOpts = '<option value="">Pick a dish</option>' + (D.items || []).map(function (it) { return '<option value="' + it.id + '"' + (+p.stamp_reward_item_id === it.id ? ' selected' : '') + '>' + esc(it.name) + ' · ' + money(it.price) + '</option>'; }).join('');
    return '<div class="ls-hero"><div><label class="up-switch" style="margin:0"><input type="checkbox" id="lsOn"' + (p.loyalty_enabled ? ' checked' : '') + '><span></span> <b>Loyalty program is ' + (p.loyalty_enabled ? 'on' : 'off') + '</b></label>' +
        '<p>Every completed order with a phone number earns, on the website, POS and table orders alike.</p></div>' +
        '<div class="ls-kpis"><div><b>' + (m.total || 0) + '</b><span>Members</span></div><div><b>' + (m.with_birthday || 0) + '</b><span>Birthdays known</span></div><div><b>' + (m.opted_in || 0) + '</b><span>WhatsApp opt-ins</span></div></div></div>' +
      '<h3 class="ls-h">How customers earn</h3>' +
      '<div class="ls-types">' + TYPES.map(function (t) {
        return '<button type="button" class="ls-type' + (p.program_type === t[0] ? ' on' : '') + '" data-ls-type="' + t[0] + '"><span class="material-symbols-rounded">' + t[2] + '</span><b>' + t[1] + (t[0] === 'cashback' ? ' <em>Recommended</em>' : '') + '</b><small>' + t[3] + '</small><i>' + t[4] + '</i></button>';
      }).join('') + '</div>' +
      '<div class="ls-box">' +
      (p.program_type === 'cashback'
        ? '<div class="ls-grid"><label>Cashback on every completed order (%)<input type="number" id="lsCbPct" min="0.5" max="50" step="0.5" value="' + p.cashback_pct + '"></label>' +
          '<label>Most cashback per order (' + cur() + ')<input type="number" id="lsCbMax" min="0" value="' + (p.cashback_max || '') + '" placeholder="No limit"></label></div>' +
          '<div class="ls-example"><span class="material-symbols-rounded">lightbulb</span>A ' + money(600) + ' order gives <b>' + money(Math.min(600 * p.cashback_pct / 100, p.cashback_max > 0 ? p.cashback_max : 1e9)) + '</b> back, ready for the next meal. Cashback goes into the customer’s wallet (Offers › Wallet) and comes off if the order is cancelled.' + (D.phonepe_ready ? '' : ' Customers can spend cashback without PhonePe; adding money to the wallet needs your PhonePe keys.') + '</div>'
        : p.program_type === 'stamps'
        ? '<div class="ls-stamp-preview"><div class="ls-stamps">' + stamps + '</div><small>What customers see on their rewards page</small></div>' +
          '<div class="ls-grid"><label>Stamps to fill the card<select id="lsStGoal">' + [4, 5, 6, 7, 8, 9, 10, 12].map(function (n) { return '<option' + (n === p.stamp_goal ? ' selected' : '') + '>' + n + '</option>'; }).join('') + '</select></label>' +
          '<label>Orders above (' + cur() + ')<input type="number" id="lsStMin" min="0" value="' + (p.stamp_min_order || '') + '" placeholder="Any order"></label>' +
          '<label>Reward for a full card<select id="lsStKind"><option value="item"' + (p.stamp_reward_kind === 'item' ? ' selected' : '') + '>A free dish</option><option value="flat"' + (p.stamp_reward_kind === 'flat' ? ' selected' : '') + '>' + cur() + ' off</option><option value="percent"' + (p.stamp_reward_kind === 'percent' ? ' selected' : '') + '>% off</option></select></label>' +
          (p.stamp_reward_kind === 'item' ? '<label>Free dish<select id="lsStItem">' + itemOpts + '</select></label>' : '<label>Amount<input type="number" id="lsStVal" min="1" value="' + (p.stamp_reward_value || '') + '" placeholder="' + (p.stamp_reward_kind === 'percent' ? '%' : cur()) + '"></label>') +
          '</div>'
        : '<div class="ls-example"><span class="material-symbols-rounded">tune</span>Customers earn <b>' + p.earn_points_per_amount + ' points per ' + money(p.earn_amount_threshold) + '</b>, each worth ' + money(p.redeem_value_per_point) + '. Edit the rates, levels, free-dish rewards and referrals below.</div>') +
      '<div class="ls-grid" style="margin-top:12px"><label>Gift codes stay valid for<select id="lsValid">' + [7, 14, 30, 60, 90].map(function (n) { return '<option value="' + n + '"' + (n === p.reward_valid_days ? ' selected' : '') + '>' + n + ' days</option>'; }).join('') + '</select></label></div>' +
      '</div>' +
      '<div style="display:flex;justify-content:flex-end;margin-top:10px"><button type="button" class="btn btn-primary" id="lsSave"><span class="material-symbols-rounded">save</span> Save program</button></div>' +
      (p.program_type === 'points' ? '<h3 class="ls-h" style="margin-top:22px">Points, levels and referrals</h3>' : '<h3 class="ls-h" style="margin-top:22px">Levels, referrals and counter QR</h3>');
  }
  function readProgram() {
    var v = function (id) { var el = $(id); return el ? el.value : undefined; };
    if ($('lsCbPct')) { P.cashback_pct = +v('lsCbPct') || 0; P.cashback_max = +v('lsCbMax') || 0; }
    if ($('lsStGoal')) { P.stamp_goal = +v('lsStGoal'); P.stamp_min_order = +v('lsStMin') || 0; P.stamp_reward_kind = v('lsStKind'); }
    if ($('lsStItem')) P.stamp_reward_item_id = +v('lsStItem') || null;
    if ($('lsStVal')) P.stamp_reward_value = +v('lsStVal') || 0;
    if ($('lsValid')) P.reward_valid_days = +v('lsValid');
    if ($('lsOn')) P.loyalty_enabled = $('lsOn').checked ? 1 : 0;
  }
  function syncLegacy() {
    // the old master switch and the points-only card follow the program
    var on = $('gsLoyaltyEnabled'); if (on) on.checked = !!P.loyalty_enabled;
    var ptsCard = $('gsEarnAmountThreshold') && $('gsEarnAmountThreshold').closest('.section-card');
    if (ptsCard) ptsCard.style.display = P.program_type === 'points' ? '' : 'none';
  }

  function drawPanel() {
    var el = $('lsPanel');
    if (!el) return;
    if (tab === 'program') { el.innerHTML = P ? programHtml() : '<div class="up-empty">Loading…</div>'; if (P) syncLegacy(); return; }
    if (window['lsDraw_' + tab]) { window['lsDraw_' + tab](el, D[tab]); return; }
    el.innerHTML = '<div class="up-empty">Loading…</div>';
  }

  function load() {
    drawTabs(); drawPanel();
    if (tab === 'program') {
      api('program').then(function (d) {
        if (!d.success) { $('lsPanel').innerHTML = '<div class="up-empty">' + esc(d.message || 'Could not load') + '</div>'; return; }
        D.currency = d.currency; D.items = d.items; D.members = d.members; D.phonepe_ready = d.phonepe_ready; P = d.program;
        drawPanel();
      });
    } else {
      api(tab).then(function (d) { D[tab] = d; if (d.currency) D.currency = d.currency; drawPanel(); });
    }
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('#growthSettingsPage')) return;
    var t;
    if ((t = e.target.closest('[data-ls-tab]'))) { tab = t.getAttribute('data-ls-tab'); load(); return; }
    if ((t = e.target.closest('[data-ls-type]'))) { readProgram(); P.program_type = t.getAttribute('data-ls-type'); drawPanel(); return; }
    if ((t = e.target.closest('#lsSave'))) {
      readProgram(); t.disabled = true;
      post(Object.assign({ section: 'program' }, P)).then(function (d) {
        t.disabled = false;
        notify(d.success ? 'Loyalty program saved' : (d.message || 'Could not save'), d.success ? 'success' : 'error');
        if (d.success) { load(); if (typeof window.loadGrowthSettings === 'function') window.loadGrowthSettings(); }
      }).catch(function () { t.disabled = false; notify('Network error. Please try again.', 'error'); });
    }
  });
  document.addEventListener('change', function (e) {
    if (!e.target.closest || !e.target.closest('#lsPanel')) return;
    if (tab === 'program' && (e.target.id === 'lsStKind' || e.target.id === 'lsStGoal' || e.target.id === 'lsOn')) { readProgram(); drawPanel(); }
  });

  window.loadLoyaltyStudio = function () { load(); };
  window.lsApi = { api: api, post: post, esc: esc, money: money, notify: notify, reload: load, data: D };
})();
