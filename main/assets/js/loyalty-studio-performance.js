/*
 * Loyalty Studio > Performance tab: what loyalty brings in. Revenue from orders
 * it helped (personal gift codes, points, member discount, wallet), each source's
 * return, members vs guests, repeat rate, a 12-week trend and what is owed to
 * customers right now. Draws through window.lsDraw_performance (loyalty-studio.js).
 */
(function () {
  'use strict';

  var NAMES = {
    welcome: ['waving_hand', 'Welcome gifts'], birthday: ['cake', 'Birthday treats'], anniversary: ['favorite', 'Anniversary gifts'],
    winback: ['history', 'We miss you'], tier_up: ['military_tech', 'Level up gifts'], milestone: ['emoji_events', 'Order milestones'],
    referral: ['group_add', 'Referral gifts'], stamps: ['approval', 'Stamp cards'], challenge: ['flag', 'Challenges'], streak: ['local_fire_department', 'Weekly streaks'],
    vip: ['diamond', 'Member welcome gifts'], scratch: ['casino', 'Scratch card / wheel'], points: ['toll', 'Points redeemed'], vip_orders: ['workspace_premium', 'Member discount']
  };
  var D = null, days = 30;

  function L() { return window.lsApi; }
  function esc(s) { return L().esc(s); }
  function money(n) { return L().money(Math.round(n)); }
  function pct(a, b) { return b > 0 ? Math.round(a * 100 / b) : 0; }
  function delta(now, prev) {
    if (!prev) return now > 0 ? '<em class="up">New</em>' : '';
    var d = Math.round((now - prev) * 100 / prev);
    return '<em class="' + (d >= 0 ? 'up' : 'down') + '">' + (d >= 0 ? '+' : '') + d + '% vs previous</em>';
  }

  function trend() {
    var W = D.weeks || [];
    if (!W.length) return '<div class="lsa-empty"><span class="material-symbols-rounded">bar_chart</span><b>No orders in the last 12 weeks</b></div>';
    var max = Math.max.apply(null, W.map(function (w) { return w.revenue; })) || 1;
    return '<div class="lsp-chart">' + W.map(function (w) {
      var h = Math.max(2, Math.round(w.revenue / max * 100)), lh = w.revenue ? Math.round(w.loyal / w.revenue * h) : 0;
      var d = new Date(w.from + 'T00:00:00');
      return '<div class="lsp-col" title="' + esc(money(w.revenue) + ' total, ' + money(w.loyal) + ' with loyalty') + '"><div class="lsp-bar" style="height:' + h + '%"><i style="height:' + (h ? Math.round(lh / h * 100) : 0) + '%"></i></div>' +
        '<small>' + d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) + '</small></div>';
    }).join('') + '</div><div class="lsp-legend"><span><i class="all"></i>All orders</span><span><i class="loyal"></i>Orders loyalty helped</span></div>';
  }

  function sources() {
    var S = D.sources || [];
    if (!S.length) return '<div class="lsa-empty"><span class="material-symbols-rounded">redeem</span><b>No gift codes yet in this period</b><span>Turn on Autopilot and Games: their gifts and the orders they bring show up here.</span></div>';
    return '<div class="lsp-table"><div class="lsp-tr lsp-th"><span>Source</span><span>Sent</span><span>Used</span><span>Orders brought in</span><span>Cost</span><span>Return</span></div>' +
      S.map(function (s) {
        var n = NAMES[s.source] || ['redeem', s.source], ret = s.cost > 0 ? (s.revenue / s.cost) : 0;
        return '<div class="lsp-tr"><span class="lsp-src"><span class="material-symbols-rounded">' + n[0] + '</span>' + esc(n[1]) + '</span>' +
          '<span>' + (s.issued == null ? '<i class="lsp-na">-</i>' : s.issued) + '</span>' +
          '<span>' + s.used + (s.issued ? ' <small>' + pct(s.used, s.issued) + '%</small>' : '') + '</span>' +
          '<span><b>' + money(s.revenue) + '</b></span><span>' + money(s.cost) + '</span>' +
          '<span>' + (ret ? '<b class="lsp-ret">' + ret.toFixed(1) + 'x</b>' : '<i class="lsp-na">-</i>') + '</span></div>';
      }).join('') + '</div>';
  }

  function segments() {
    var g = D.segments, rows = [['members', 'Signed-up members', 'person'], ['guests', 'Guests (phone only)', 'person_outline']];
    if (g.vip.orders) rows.push(['vip', 'VIP members', 'diamond']);
    var aovs = rows.map(function (r) { var x = g[r[0]]; return x.orders ? x.revenue / x.orders : 0; });
    var max = Math.max.apply(null, aovs) || 1;
    return '<div class="lsp-segs">' + rows.map(function (r, i) {
      var x = g[r[0]], aov = aovs[i], freq = x.customers ? x.orders / x.customers : 0;
      return '<div class="lsp-seg ' + r[0] + '"><div class="lsp-seg-h"><span class="material-symbols-rounded">' + r[2] + '</span><b>' + r[1] + '</b><small>' + x.customers + ' customer' + (x.customers === 1 ? '' : 's') + '</small></div>' +
        '<div class="lsp-meter"><span>Average order</span><b>' + money(aov) + '</b><div><i style="width:' + Math.round(aov / max * 100) + '%"></i></div></div>' +
        '<div class="lsp-seg-f"><div><b>' + freq.toFixed(1) + '</b><span>Orders each</span></div><div><b>' + money(x.revenue) + '</b><span>Revenue</span></div></div></div>';
    }).join('') + '</div>';
  }

  function owed() {
    var o = D.owed, items = [];
    if (D.program_type === 'points' || o.points) items.push(['toll', 'Points not yet used', o.points.toLocaleString('en-IN') + ' points', 'Worth ' + money(o.points_value) + ' in discounts']);
    items.push(['account_balance_wallet', 'Money in wallets', money(o.wallet), 'Top-ups, refunds and cashback customers can spend']);
    items.push(['redeem', 'Unused gift codes', o.gift_codes + ' code' + (o.gift_codes === 1 ? '' : 's'), money(o.gift_flat) + ' in fixed amounts' + (o.gift_pct ? ' + ' + o.gift_pct + ' % codes' : '')]);
    if (o.stamp_cards || D.program_type === 'stamps') items.push(['approval', 'Stamp cards in progress', o.stamp_cards + ' card' + (o.stamp_cards === 1 ? '' : 's'), 'Each fills up to one reward']);
    if (o.vip_members) items.push(['diamond', 'Active VIP members', o.vip_members + '', 'Their perks run until their plan ends']);
    return '<div class="lsp-owed">' + items.map(function (x) {
      return '<div><span class="material-symbols-rounded">' + x[0] + '</span><small>' + x[1] + '</small><b>' + esc(x[2]) + '</b><em>' + esc(x[3]) + '</em></div>';
    }).join('') + '</div>';
  }

  function tips() {
    var t = [], n = D.now, r = D.repeat, g = D.segments;
    var rate = pct(r.repeaters, r.customers);
    if (r.customers && rate < 35) t.push(['history', 'Only ' + rate + '% of customers ordered twice in this period. A "We miss you" gift in Autopilot brings quiet customers back.', 'autopilot']);
    if (!n.loyal_orders) t.push(['auto_mode', 'No orders used a loyalty reward yet. Turn on the welcome gift so every first order earns a reason to return.', 'autopilot']);
    if (g.guests.orders > g.members.orders * 3) t.push(['person_add', 'Most orders come from guests. A welcome gift or a stamp card gives them a reason to sign up.', 'program']);
    var codes = (D.sources || []).filter(function (s) { return s.issued; });
    var low = codes.filter(function (s) { return s.issued >= 5 && pct(s.used, s.issued) < 15; })[0];
    if (low) t.push(['tune', (NAMES[low.source] || [0, low.source])[1] + ': only ' + pct(low.used, low.issued) + '% get used. A bigger gift or a lower minimum order usually fixes this.', 'autopilot']);
    if (!t.length) t.push(['thumb_up', 'Loyalty is pulling its weight. Keep an eye on the return of each source and drop the ones under 2x.', null]);
    return '<div class="lsp-tips">' + t.slice(0, 3).map(function (x) {
      return '<div><span class="material-symbols-rounded">' + x[0] + '</span><p>' + esc(x[1]) + '</p>' + (x[2] ? '<button type="button" class="lsa-edit" data-ls-tab="' + x[2] + '">Open<span class="material-symbols-rounded">arrow_forward</span></button>' : '') + '</div>';
    }).join('') + '</div>';
  }

  function draw(el) {
    if (!D) { el.innerHTML = '<div class="up-empty">Loading…</div>'; return; }
    var n = D.now, p = D.prev, r = D.repeat;
    var share = pct(n.loyal_revenue, n.revenue), roi = n.given > 0 ? n.loyal_revenue / n.given : 0;
    el.innerHTML =
      '<div class="lsp-top"><h3 class="ls-h" style="margin:0">Last ' + (days === 365 ? '12 months' : days + ' days') + '</h3><div class="lsp-range">' +
        [[30, '30 days'], [90, '90 days'], [365, '12 months']].map(function (x) { return '<button type="button" data-lsp-days="' + x[0] + '"' + (x[0] === days ? ' class="on"' : '') + '>' + x[1] + '</button>'; }).join('') + '</div></div>' +
      '<div class="ls-hero lsp-hero"><div><small>Revenue loyalty helped bring in</small><b class="lsp-big">' + money(n.loyal_revenue) + '</b>' +
        '<p>' + share + '% of all revenue (' + money(n.revenue) + ') · ' + n.loyal_orders + ' of ' + n.orders + ' orders ' + delta(n.loyal_revenue, p.loyal_revenue) + '</p></div>' +
        '<div class="ls-kpis"><div><b>' + (roi ? roi.toFixed(1) + 'x' : '-') + '</b><span>Return on rewards</span></div><div><b>' + money(n.given) + '</b><span>Rewards given</span></div><div><b>' + pct(r.repeaters, r.customers) + '%</b><span>Came back (2+ orders)</span></div></div></div>' +
      '<div class="lsp-grid"><div class="ls-box"><h3 class="ls-h">Last 12 weeks</h3>' + trend() + '</div>' +
        '<div class="ls-box"><h3 class="ls-h">Worth doing next</h3>' + tips() + '</div></div>' +
      '<div class="ls-box" style="margin-top:14px"><h3 class="ls-h">Where it came from</h3>' + sources() + '</div>' +
      '<h3 class="ls-h" style="margin-top:20px">Members vs guests</h3>' + segments() +
      '<h3 class="ls-h" style="margin-top:20px">What customers can still claim</h3><p class="lsa-fine" style="margin:-4px 0 10px">Rewards earned but not used yet: plan for them like you plan stock.</p>' + owed() +
      '<p class="lsa-fine">An order counts as loyalty-helped when it used a personal gift code, scratch card or wheel prize, points, the member discount or wallet money. Return = revenue from those orders divided by the discount given on them. Cancelled and rejected orders are left out.</p>';
  }

  window.lsDraw_performance = function (el, data) { if (data && data.success) { D = data; days = data.days; } draw(el); };

  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('#lsPanel') || !document.querySelector('.lsp-top')) return;
    var t = e.target.closest('[data-lsp-days]');
    if (!t) return;
    days = +t.getAttribute('data-lsp-days');
    L().api('performance&days=' + days).then(function (d) { if (d.success) { D = d; draw(document.getElementById('lsPanel')); } });
  });
})();
