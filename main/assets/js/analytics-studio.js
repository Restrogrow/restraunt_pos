/**
 * Analytics page (restaurant dashboard). Data: api/analytics_studio.php. Styles: an-* in studio-ui.css.
 * Replaces the old window.loadAnalytics; the period select (#analyticsPeriod) stays in the page header.
 */
(function () {
  'use strict';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var cur = function () { return window.globalCurrencySymbol || '₹'; };
  var money = function (v) { return cur() + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 }); };
  var num = function (v, d) { return Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: d || 0 }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var WD = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  var REASONS = { out_of_stock: 'Item out of stock', kitchen_busy: 'Kitchen too busy', closing: 'Closing soon', too_far: 'Address too far', no_answer: 'Could not reach customer',
    customer_asked: 'Customer asked to cancel', kitchen_issue: 'Kitchen problem', other: 'Other reason', not_given: 'No reason given' };
  var CLASS = {
    star: ['Stars', 'star', '#16a34a', 'Popular and earn well. Keep them perfect and always in stock.'],
    workhorse: ['Workhorses', 'local_fire_department', '#2563eb', 'Popular but earn less. Small price rise or smaller cost.'],
    puzzle: ['Puzzles', 'help', '#d97706', 'Earn well but few orders. Promote, rename or re-photo.'],
    dog: ['Dogs', 'block', '#dc2626', 'Few orders and low earnings. Rework or remove.']
  };
  var state = { days: 30, metric: 'revenue', data: null };

  function delta(v, invert) {
    if (v === null || v === undefined) return '';   // nothing to compare with yet
    if (v === 0) return '<em class="an-d">0%</em>';
    var good = invert ? v < 0 : v > 0;
    return '<em class="an-d ' + (good ? 'up' : 'down') + '">' + ic(v > 0 ? 'arrow_upward' : 'arrow_downward') + Math.abs(v) + '%</em>';
  }
  function kpi(icon, color, label, value, change, sub, invert) {
    return '<div class="an-kpi" style="--c:' + color + '"><span class="an-kpi-ic">' + ic(icon) + '</span><div><small>' + label + '</small><b>' + value + '</b>' +
      '<span>' + delta(change, invert) + (sub ? ' ' + sub : '') + '</span></div></div>';
  }
  function card(title, sub, body, extra) {
    return '<section class="an-card' + (extra ? ' ' + extra : '') + '"><header><h3>' + title + '</h3>' + (sub ? '<small>' + sub + '</small>' : '') + '</header>' + body + '</section>';
  }
  function empty(t) { return '<div class="an-empty">' + ic('insights') + esc(t) + '</div>'; }

  /* line chart: this period (solid) vs the one before (dashed) */
  function lineChart(rows, key) {
    var W = 720, H = 200, P = 28;
    var vals = rows.map(function (r) { return +r[key] || 0; }), prev = rows.map(function (r) { return +r['prev_' + key] || 0; });
    var max = Math.max.apply(null, vals.concat(prev).concat([1]));
    var x = function (i) { return P + (rows.length < 2 ? 0 : i * (W - 2 * P) / (rows.length - 1)); };
    var y = function (v) { return H - P - v * (H - 2 * P) / max; };
    var path = function (arr) { return arr.map(function (v, i) { return (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(v).toFixed(1); }).join(' '); };
    var area = path(vals) + ' L' + x(vals.length - 1).toFixed(1) + ' ' + (H - P) + ' L' + x(0).toFixed(1) + ' ' + (H - P) + ' Z';
    var grid = [0, .5, 1].map(function (f) { var v = max * f; return '<line x1="' + P + '" x2="' + (W - P) + '" y1="' + y(v) + '" y2="' + y(v) + '" class="g"/><text x="' + (P - 4) + '" y="' + (y(v) + 4) + '" text-anchor="end">' + (key === 'revenue' ? cur() + (v >= 1000 ? (v / 1000).toFixed(v >= 10000 ? 0 : 1) + 'k' : Math.round(v)) : Math.round(v)) + '</text>'; }).join('');
    var step = Math.max(1, Math.ceil(rows.length / 8));
    var labels = rows.map(function (r, i) { if (i % step) return ''; var d = new Date(r.label + 'T00:00:00'); return '<text x="' + x(i) + '" y="' + (H - 6) + '" text-anchor="middle">' + d.getDate() + ' ' + d.toLocaleString('en', { month: 'short' }) + '</text>'; }).join('');
    var dots = vals.map(function (v, i) { return '<circle cx="' + x(i) + '" cy="' + y(v) + '" r="3"><title>' + esc(rows[i].label) + ': ' + (key === 'revenue' ? money(v) : num(v) + ' orders') + ' (before: ' + (key === 'revenue' ? money(prev[i]) : num(prev[i])) + ')</title></circle>'; }).join('');
    return '<svg class="an-line" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" role="img" aria-label="Trend">' + grid +
      '<path d="' + area + '" class="a"/><path d="' + path(prev) + '" class="p"/><path d="' + path(vals) + '" class="c"/>' + dots + labels + '</svg>' +
      '<div class="an-legend"><span><i class="c"></i>This period</span><span><i class="p"></i>Period before</span></div>';
  }
  function bars(list, valKey, labelKey, fmt, color) {
    var max = Math.max.apply(null, list.map(function (r) { return +r[valKey] || 0; }).concat([1]));
    return '<div class="an-bars">' + list.map(function (r) {
      var v = +r[valKey] || 0;
      return '<div class="an-bar"><span>' + esc(r[labelKey]) + '</span><div><i style="width:' + Math.max(2, v * 100 / max) + '%;background:' + (color || 'var(--sx-accent)') + '"></i></div><b>' + fmt(v, r) + '</b></div>';
    }).join('') + '</div>';
  }
  function heatmap(heat) {
    var max = 0, first = 23, last = 0;
    heat.forEach(function (row) { row.forEach(function (v, h) { if (v > max) max = v; if (v) { first = Math.min(first, h); last = Math.max(last, h); } }); });
    if (!max) return empty('No orders in this period yet');
    first = Math.max(0, first - 1); last = Math.min(23, last + 1);
    var hours = []; for (var h = first; h <= last; h++) hours.push(h);
    var lab = function (h) { return (h % 12 || 12) + (h < 12 ? 'a' : 'p'); };
    return '<div class="an-heat" style="--cols:' + hours.length + '"><span></span>' + hours.map(function (h) { return '<span class="hl">' + lab(h) + '</span>'; }).join('') +
      heat.map(function (row, d) {
        return '<span class="dl">' + WD[d] + '</span>' + hours.map(function (h) {
          var v = row[h], a = v ? 0.12 + 0.88 * v / max : 0;
          return '<i style="--a:' + a.toFixed(2) + '" title="' + WD[d] + ' ' + lab(h) + 'm: ' + v + ' order' + (v === 1 ? '' : 's') + '">' + (v && v / max > 0.6 ? v : '') + '</i>';
        }).join('');
      }).join('') + '</div><div class="an-legend"><span>Fewer</span><span class="an-scale"></span><span>More orders</span></div>';
  }

  function render(d) {
    var box = document.getElementById('analyticsContent');
    var k = d.kpi, c = d.change, m = d.menu, cu = d.customers, w = d.web, rv = d.reviews;
    var html = '';

    /* what to do */
    html += '<section class="an-tips"><header><h3>' + ic('lightbulb') + 'What to do now</h3><small>Worked out from your last ' + d.days + ' days</small></header>' +
      (d.tips.length ? '<div class="an-tip-grid">' + d.tips.map(function (t) {
        return '<div class="an-tip ' + esc(t.kind) + '"><span class="an-tip-ic">' + ic(esc(t.icon)) + '</span><div><b>' + esc(t.title) + '</b><p>' + esc(t.text) + '</p>' +
          (t.action ? '<button type="button" data-an-go="' + esc(t.action.page) + '">' + esc(t.action.label) + ic('arrow_forward') + '</button>' : '') + '</div></div>';
      }).join('') + '</div>' : empty('Not enough orders yet for tips. They appear once orders come in.')) + '</section>';

    /* headline numbers */
    html += '<div class="an-kpis">' +
      kpi('payments', '#16a34a', 'Sales', money(k.revenue), c.revenue, 'vs ' + money(d.prev.revenue)) +
      kpi('receipt_long', '#2563eb', 'Orders', num(k.orders), c.orders, num(k.orders / d.days, 1) + ' a day') +
      kpi('shopping_bag', '#7c3aed', 'Average bill', money(k.aov), c.aov, num(k.items_per_order, 1) + ' items') +
      kpi('group', '#0891b2', 'Customers', num(k.customers), c.customers, num(k.new_customers) + ' new') +
      kpi('repeat', '#d97706', 'Repeat customers', num(k.repeat_rate, 1) + '%', c.repeat_rate, num(k.returning) + ' ordered again') +
      kpi('sell', '#db2777', 'Discounts given', money(k.discount), c.discount, '', true) +
      kpi('cancel', '#dc2626', 'Rejected / cancelled', num(k.cancel_rate, 1) + '%', c.cancel_rate, num(k.cancelled) + ' orders', true) +
      kpi('star', '#f59e0b', 'Rating', rv.avg !== null ? num(rv.avg, 2) + ' / 5' : '–', rv.avg !== null && rv.prev_avg ? Math.round((rv.avg - rv.prev_avg) * 100 / rv.prev_avg * 10) / 10 : (rv.avg !== null ? null : 0), num(rv.count) + ' reviews') +
      '</div>';

    /* trend */
    html += card('Sales trend', d.bucket > 1 ? 'by week' : 'by day',
      '<div class="an-seg"><button type="button" data-an-metric="revenue"' + (state.metric === 'revenue' ? ' class="on"' : '') + '>Sales</button><button type="button" data-an-metric="orders"' + (state.metric === 'orders' ? ' class="on"' : '') + '>Orders</button></div>' +
      (k.orders || d.prev.orders ? lineChart(d.trend, state.metric) : empty('No orders in this period yet')), 'an-wide');

    /* when */
    html += '<div class="an-cols">' +
      card('Busy hours', 'orders by day and hour', heatmap(d.heat)) +
      card('Sales by weekday', 'this period', bars(d.weekday_sales.map(function (v, i) { return { l: WD[i], v: v }; }), 'v', 'l', function (v) { return money(v); }, '#7c3aed')) +
      '</div>';

    /* menu engineering */
    var groups = { star: [], workhorse: [], puzzle: [], dog: [] };
    m.items.forEach(function (it) { groups[it.class].push(it); });
    html += card('Menu analysis', m.with_cost ? 'popularity × profit per plate (from your recipes)' : 'popularity × price (add recipes in Inventory for real profit)',
      (m.items.length ? '<div class="an-matrix">' + ['star', 'puzzle', 'workhorse', 'dog'].map(function (cl) {
        var C = CLASS[cl];
        return '<div class="an-q" style="--c:' + C[2] + '"><header>' + ic(C[1]) + '<b>' + C[0] + '</b><em>' + groups[cl].length + '</em></header><p>' + C[3] + '</p><div class="an-chips">' +
          (groups[cl].length ? groups[cl].slice(0, 8).map(function (it) { return '<span title="' + esc(it.qty + ' sold · ' + money(it.revenue)) + '">' + esc(it.name) + '</span>'; }).join('') + (groups[cl].length > 8 ? '<span class="more">+' + (groups[cl].length - 8) + '</span>' : '') : '<span class="none">None</span>') +
          '</div></div>';
      }).join('') + '</div>' +
        '<div class="an-note">' + ic('info') + '<span><b>' + m.pareto + '</b> of your ' + m.item_count + ' selling dishes make 80% of sales. ' +
        (m.single_item_orders ? '<b>' + num(m.single_item_orders) + '</b> orders had only one item. ' : '') + (m.unsold.length ? '<b>' + m.unsold.length + '</b> dishes were not ordered at all.' : 'Every dish on the menu sold at least once.') + '</span></div>' +
        '<div class="an-table"><table><thead><tr><th>Dish</th><th>Sold</th><th>Sales</th><th>Share</th><th>Avg price</th>' + (m.with_cost ? '<th>Food cost</th>' : '') + '<th>Type</th></tr></thead><tbody>' +
        m.items.slice(0, 15).map(function (it) {
          var C = CLASS[it.class];
          return '<tr><td><b>' + esc(it.name) + '</b>' + (it.category ? '<small>' + esc(it.category) + '</small>' : '') + '</td><td>' + num(it.qty) + '</td><td>' + money(it.revenue) + '</td>' +
            '<td><div class="an-share"><i style="width:' + Math.min(100, it.rev_share * 3) + '%"></i></div>' + num(it.rev_share, 1) + '%</td><td>' + money(it.avg_price) + '</td>' +
            (m.with_cost ? '<td>' + (it.food_cost_pct !== null ? '<span class="an-pill ' + (it.food_cost_pct > 38 ? 'bad' : it.food_cost_pct > 30 ? 'warn' : 'ok') + '">' + num(it.food_cost_pct, 1) + '%</span>' : '<small>no recipe</small>') + '</td>' : '') +
            '<td><span class="an-pill" style="--c:' + C[2] + '">' + C[0].replace(/s$/, '') + '</span></td></tr>';
        }).join('') + '</tbody></table></div>' : empty('No dishes sold in this period yet')), 'an-wide');

    html += '<div class="an-cols">' +
      card('Ordered together', 'ideas for combos', d.menu.pairs.length ? '<div class="an-list">' + d.menu.pairs.map(function (p) {
        return '<div class="an-li">' + ic('join_inner') + '<div><b>' + esc(p.x) + ' + ' + esc(p.y) + '</b><small>in ' + p.n + ' orders together</small></div></div>'; }).join('') + '</div>' : empty('Not enough orders with several dishes yet')) +
      card('Sales by category', '', Object.keys(m.categories).length ? bars(Object.keys(m.categories).slice(0, 8).map(function (k2) { return { l: k2, v: m.categories[k2] }; }), 'v', 'l', function (v) { return money(v); }, '#0891b2') : empty('No sales yet')) +
      '</div>';
    if (m.unsold.length) html += card('Not ordered in this period', m.unsold.length + ' dishes', '<div class="an-chips wrap">' + m.unsold.map(function (u) { return '<span>' + esc(u.name) + (u.category ? ' <small>' + esc(u.category) + '</small>' : '') + '</span>'; }).join('') + '</div>', 'an-wide');

    /* customers */
    var life = cu.lifetime;
    html += '<div class="an-cols">' +
      card('Customers', 'this period', '<div class="an-split"><div style="--w:' + (k.customers ? k.new_customers * 100 / k.customers : 0) + '%"><i class="n"></i><i class="r"></i></div>' +
        '<p><span class="n"></span>' + num(k.new_customers) + ' first-time · <span class="r"></span>' + num(k.customers - k.new_customers) + ' had ordered before</p></div>' +
        '<div class="an-kv"><span>Customers so far</span><b>' + num(life.customers) + '</b><span>Orders per customer</span><b>' + num(life.avg_orders, 1) + '</b><span>Spend per customer (lifetime)</span><b>' + money(life.avg_spend) + '</b></div>' +
        (cu.top.length ? '<h4>Top customers</h4><div class="an-list">' + cu.top.map(function (t, i) {
          return '<div class="an-li"><span class="an-rank">' + (i + 1) + '</span><div><b>' + esc(t.name || 'Guest') + '</b><small>' + esc(t.phone_masked) + ' · ' + t.n + ' orders</small></div><em>' + money(t.s) + '</em></div>'; }).join('') + '</div>' : '')) +
      card('Regulars who stopped coming', cu.lapsed_count + ' customers · 3+ orders, none in 3 weeks', cu.lapsed.length ? '<div class="an-list">' + cu.lapsed.map(function (t) {
        return '<div class="an-li">' + ic('person_off') + '<div><b>' + esc(t.name || 'Guest') + '</b><small>' + esc(t.phone_masked) + ' · ' + t.n + ' orders · ' + money(t.s) + ' spent</small></div><em class="away">' + t.days_away + ' days</em></div>'; }).join('') + '</div>' +
        '<button type="button" class="an-btn" data-an-go="whatsappPage">' + ic('chat') + 'Send them an offer on WhatsApp</button>' : empty('No lapsed regulars. Nice!')) +
      '</div>';

    /* channels */
    var chan = function (list, color) { return list.length ? bars(list, 's', 'k', function (v, r) { return money(v) + ' <small>' + r.n + '</small>'; }, color) : empty('No orders'); };
    html += '<div class="an-cols three">' +
      card('Where orders come from', '', chan(d.channels.source.map(function (r) { return { k: r.k === 'website' ? 'Website' : r.k === 'pos' ? 'POS / counter' : r.k, n: r.n, s: r.s }; }), '#2563eb')) +
      card('Order types', '', chan(d.channels.type, '#7c3aed')) +
      card('Payment methods', '', chan(d.channels.payment, '#16a34a')) + '</div>';

    /* website, reviews, stopped */
    var stops = Object.keys(d.stop_reasons);
    var dist = [5, 4, 3, 2, 1].map(function (s) { return { l: s + ' ★', v: +(rv.dist[s] || 0) }; });
    html += '<div class="an-cols three">' +
      card('Website', 'visitors who ordered', w.visitors ? '<div class="an-funnel"><div><b>' + num(w.visitors) + '</b><small>visitors</small></div>' + ic('arrow_forward') + '<div><b>' + num(w.orders) + '</b><small>orders</small></div>' + ic('arrow_forward') +
        '<div class="hl"><b>' + num(w.conversion, 1) + '%</b><small>order rate</small></div></div><p class="an-sub">Before: ' + num(w.prev_conversion, 1) + '%. Good websites turn 3 to 8% of visitors into orders.</p>' +
        (w.sources && w.sources.length ? '<h4>Visitors from</h4>' + bars(w.sources, 'n', 'src', function (v) { return num(v); }, '#0891b2') : '') : empty('No website visits recorded in this period')) +
      card('Reviews', rv.count + ' in this period', rv.count ? '<div class="an-stars"><b>' + num(rv.avg, 1) + '</b><span>' + '★★★★★'.slice(0, Math.round(rv.avg)) + '<i>' + '★★★★★'.slice(Math.round(rv.avg)) + '</i></span></div>' + bars(dist, 'v', 'l', function (v) { return num(v); }, '#f59e0b') +
        (rv.low.length ? '<h4>Low ratings to read</h4><div class="an-list">' + rv.low.map(function (r) { return '<div class="an-li">' + ic('sentiment_dissatisfied') + '<div><b>' + r.rating + '★ · ' + esc(r.customer_name || 'Customer') + '</b><small>' + esc(r.review) + '</small></div></div>'; }).join('') + '</div>' : '') : empty('No reviews in this period')) +
      card('Orders you lost', num(k.cancelled) + ' rejected or cancelled', stops.length ? bars(stops.map(function (s) { return { l: REASONS[s] || s.replace(/_/g, ' '), v: d.stop_reasons[s] }; }), 'v', 'l', function (v) { return num(v); }, '#dc2626') +
        '<p class="an-sub">Pick a reason when you reject or cancel, so this shows what to fix.</p>' : empty('No rejected or cancelled orders')) +
      '</div>';

    /* forecast */
    html += card('Next 7 days', 'expected, from the same weekday over the last 4 weeks', '<div class="an-fc">' + d.forecast.map(function (f, i) {
      var maxF = Math.max.apply(null, d.forecast.map(function (x) { return x.revenue; }).concat([1]));
      return '<div class="' + (i === 0 ? 'today' : '') + '"><span>' + (i === 0 ? 'Today' : f.day) + '</span><div><i style="height:' + Math.max(4, f.revenue * 100 / maxF) + '%"></i></div><b>' + money(f.revenue) + '</b><small>~' + num(f.orders, 0) + ' orders</small></div>';
    }).join('') + '</div><p class="an-sub">Use it to plan stock and staff. Festivals, weather and offers can change it.</p>', 'an-wide');

    box.innerHTML = '<div class="an">' + html + '</div>';
  }

  function load() {
    var box = document.getElementById('analyticsContent');
    if (!box) return;
    var sel = document.getElementById('analyticsPeriod');
    if (sel && ![].some.call(sel.options, function (o) { return o.value === '365'; })) { var o = document.createElement('option'); o.value = '365'; o.textContent = 'Last 12 months'; sel.appendChild(o); }
    state.days = sel ? +sel.value || 30 : 30;
    if (!state.data) box.innerHTML = '<div class="an-loading"><span class="loading-spinner"></span>Working out your numbers…</div>';
    fetch('../api/analytics_studio.php?days=' + state.days, { cache: 'no-store' })
      .then(function (r) { return r.text(); })
      .then(function (t) {
        var d; try { d = JSON.parse(t); } catch (e) { throw new Error('The server is busy. Please try again.'); }
        if (!d.success) throw new Error(d.message || 'Could not load analytics');
        state.data = d; render(d);
      })
      .catch(function (e) { box.innerHTML = '<div class="an-empty err">' + ic('error') + esc(e.message) + ' <button type="button" class="an-btn" onclick="loadAnalytics()">Try again</button></div>'; });
  }
  window.loadAnalytics = load;

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-an-metric]');
    if (b) { state.metric = b.dataset.anMetric; if (state.data) render(state.data); return; }
    var g = e.target.closest('[data-an-go]');
    if (g && typeof window.showPage === 'function' && document.getElementById(g.dataset.anGo)) { window.showPage(g.dataset.anGo); window.scrollTo(0, 0); }
  });
  // the period select keeps calling loadAnalytics() (inline onchange); reload when the page opens
  document.addEventListener('click', function (e) {
    var l = e.target.closest('.nav-link[data-page="analyticsPage"]');
    if (l) setTimeout(load, 50);
  }, true);
})();
