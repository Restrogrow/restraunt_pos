/*
 * Dashboard home: today at a glance, what needs attention, orders in progress,
 * the last 7 days, the floor and today's best dishes.
 * Data: api/dashboard_home.php. Styles: dh-* in assets/css/studio-ui.css.
 * script.js's loadDashboardStats() hands over to window.loadDashboardHome().
 */
(function () {
  'use strict';
  var D = null, metric = 'sales', timer = 0, busy = false;
  var esc = function (s) { return window.SX ? SX.esc(s) : String(s == null ? '' : s); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var money = function (n, dec) { var c = (D && D.currency) || '₹'; return c + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: dec ? 2 : 0, minimumFractionDigits: 0 }); };
  var num = function (n) { return Number(n || 0).toLocaleString('en-IN'); };
  // ₹950, ₹9.3k, ₹1.2L: short enough for a bar label
  var short = function (v) { var c = (D && D.currency) || '₹'; return c + (v >= 100000 ? (v / 100000).toFixed(1).replace(/\.0$/, '') + 'L' : v >= 1000 ? (v / 1000).toFixed(1).replace(/\.0$/, '') + 'k' : Math.round(v)); };

  function delta(now, before) {
    if (!before && !now) return '<span class="dh-delta flat">No sales yet today</span>';
    if (!before) return '<span class="dh-delta up">' + ic('north_east') + 'New today</span>';
    var p = Math.round((now - before) * 100 / before);
    if (p === 0) return '<span class="dh-delta flat">Same as yesterday</span>';
    return '<span class="dh-delta ' + (p > 0 ? 'up' : 'down') + '">' + ic(p > 0 ? 'north_east' : 'south_east') + Math.abs(p) + '% <em>vs this time yesterday</em></span>';
  }
  function spark(values) {
    var max = Math.max.apply(null, values.concat([1])), w = 96, h = 28, step = w / (values.length - 1 || 1);
    var pts = values.map(function (v, i) { return (i * step).toFixed(1) + ',' + (h - 2 - (v / max) * (h - 4)).toFixed(1); }).join(' ');
    return '<svg class="dh-spark" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" aria-hidden="true"><polyline points="' + pts + '" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/></svg>';
  }
  function ago(ts) {
    var m = Math.max(0, Math.round((Date.now() - new Date(String(ts).replace(' ', 'T')).getTime()) / 60000));
    return m < 1 ? 'just now' : m < 60 ? m + ' min ago' : m < 1440 ? Math.floor(m / 60) + ' h ago' : Math.floor(m / 1440) + ' d ago';
  }
  var STATUS = { Pending: ['wait', 'New'], Accepted: ['wait', 'Accepted'], Preparing: ['muted', 'Cooking'], Ready: ['ok', 'Ready'] };

  function kpis() {
    var t = D.today, y = D.yesterday, last7 = D.days.slice(7);
    return '<section class="dh-kpis">' +
      '<div class="dh-kpi"><span class="dh-k-l">Sales today</span><b>' + money(t.sales) + '</b>' + delta(t.sales, y.sales) + '<span class="dh-k-s" style="color:#2563eb">' + spark(last7.map(function (d) { return d.sales; })) + '</span></div>' +
      '<div class="dh-kpi"><span class="dh-k-l">Orders today</span><b>' + num(t.orders) + '</b>' + (t.orders || y.orders ? delta(t.orders, y.orders) : '<span class="dh-delta flat">No orders yet today</span>') + '<span class="dh-k-s" style="color:#7c3aed">' + spark(last7.map(function (d) { return d.orders; })) + '</span></div>' +
      '<div class="dh-kpi"><span class="dh-k-l">Average order</span><b>' + (t.orders ? money(t.avg) : '–') + '</b>' + (t.orders && y.orders ? delta(t.avg, y.avg) : '<span class="dh-delta flat">' + (y.orders ? 'Yesterday ' + money(y.avg) : 'Shows after the first order') + '</span>') + '</div>' +
      '<div class="dh-kpi"><span class="dh-k-l">In progress</span><b>' + num(D.live_count) + '</b><span class="dh-delta flat">' + num(D.kot) + ' kitchen ticket' + (D.kot === 1 ? '' : 's') + ' open</span></div>' +
      '</section>';
  }

  function attention() {
    var a = D.attention, rows = [];
    if (a.to_accept) rows.push(['bad', 'shopping_bag', a.to_accept + ' website order' + (a.to_accept > 1 ? 's' : '') + ' to accept', 'Customers are waiting for your answer', 'onlineOrdersPage']);
    if (a.calls) rows.push(['warn', 'notifications_active', a.calls + ' table' + (a.calls > 1 ? 's' : '') + ' calling a waiter', 'From the table QR codes', 'waiterRequestsPage']);
    if (a.reservations_pending) rows.push(['warn', 'event_seat', a.reservations_pending + ' reservation' + (a.reservations_pending > 1 ? 's' : '') + ' to confirm', 'For today', 'reservationsPage']);
    if (a.catering) rows.push(['warn', 'celebration', a.catering + ' catering request' + (a.catering > 1 ? 's' : ''), 'Waiting for a reply', 'cateringPage']);
    if (a.low_stock) rows.push(['warn', 'inventory_2', a.low_stock + ' stock item' + (a.low_stock > 1 ? 's' : '') + ' running low', (a.low_names && a.low_names.length ? a.low_names.join(', ') + (a.low_stock > a.low_names.length ? ' and ' + (a.low_stock - a.low_names.length) + ' more' : '') + '. ' : '') + 'Reorder before service', 'inventoryPage']);
    if (a.stale_kot) rows.push(['muted', 'schedule', a.stale_kot + ' kitchen ticket' + (a.stale_kot > 1 ? 's' : '') + ' open for over 6 hours', 'Close them so tables show as free', 'kotPage']);
    return '<section class="dh-card dh-att"><div class="dh-card-h"><b>Needs attention</b>' + (rows.length ? '<span class="sx-pill ' + (rows[0][0] === 'bad' ? 'bad' : 'warn') + '">' + rows.length + '</span>' : '') + '</div>' +
      (rows.length ? '<div class="dh-att-list">' + rows.map(function (r) {
        return '<button type="button" class="dh-att-row ' + r[0] + '" data-dh-go="' + r[4] + '"><span class="dh-att-ic">' + ic(r[1]) + '</span><span><b>' + esc(r[2]) + '</b><small>' + esc(r[3]) + '</small></span>' + ic('chevron_right') + '</button>';
      }).join('') + '</div>'
        : '<div class="dh-clear">' + ic('task_alt') + '<b>All clear</b><span>Nothing is waiting on you right now.</span></div>') +
      '</section>';
  }

  function chart() {
    var days = D.days.slice(7), prev = D.days.slice(0, 7);
    var key = metric, vals = days.map(function (d) { return d[key]; }), max = Math.max.apply(null, vals.concat([1]));
    var sum = vals.reduce(function (a, b) { return a + b; }, 0), psum = prev.reduce(function (a, d) { return a + d[key]; }, 0);
    var fmt = key === 'sales' ? function (v) { return money(v); } : num;
    var p = psum ? Math.round((sum - psum) * 100 / psum) : null;
    var todayIso = days[days.length - 1].date;
    return '<section class="dh-card dh-chart"><div class="dh-card-h"><div><b>Last 7 days</b><span class="dh-total">' + fmt(sum) + (p !== null ? ' <em class="' + (p >= 0 ? 'up' : 'down') + '">' + (p >= 0 ? '+' : '') + p + '% vs the 7 days before</em>' : '') + '</span></div>' +
      '<div class="sx-seg"><button type="button" data-dh-metric="sales" class="' + (key === 'sales' ? 'on' : '') + '">Sales</button><button type="button" data-dh-metric="orders" class="' + (key === 'orders' ? 'on' : '') + '">Orders</button></div></div>' +
      '<div class="dh-bars">' + days.map(function (d) {
        var h = Math.max(2, Math.round(d[key] / max * 100)), dt = new Date(d.date + 'T00:00:00');
        return '<div class="dh-bar' + (d.date === todayIso ? ' today' : '') + '" title="' + esc(dt.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'short' }) + ': ' + fmt(d[key])) + '">' +
          '<span class="dh-bar-v">' + (d[key] ? (key === 'sales' ? short(d[key]) : num(d[key])) : '') + '</span>' +
          '<i style="height:' + h + '%"></i><span class="dh-bar-d">' + (d.date === todayIso ? 'Today' : dt.toLocaleDateString([], { weekday: 'short' })) + '</span></div>';
      }).join('') + '</div></section>';
  }

  function liveOrders() {
    return '<section class="dh-card dh-live"><div class="dh-card-h"><b>Orders in progress</b><button type="button" class="sx-link" data-dh-go="ordersPage">All orders' + ic('arrow_forward') + '</button></div>' +
      (D.live.length ? '<div class="dh-table" role="table">' + D.live.map(function (o) {
        var st = STATUS[o.status] || ['muted', o.status];
        var where = o.table ? 'Table ' + o.table : o.type;
        return '<button type="button" class="dh-row" role="row" data-dh-go="' + (o.source === 'website' && o.status === 'Pending' ? 'onlineOrdersPage' : 'ordersPage') + '">' +
          '<span class="dh-o-num" title="' + esc(o.number) + '">#' + esc(String(o.number).split('-').pop()) + '</span>' +
          '<span class="dh-o-who"><b>' + esc(o.customer || 'Walk-in') + '</b><small>' + esc(where) + (o.source === 'website' ? ' · Website' : '') + '</small></span>' +
          '<span class="sx-pill ' + st[0] + '">' + st[1] + '</span>' +
          '<span class="dh-o-amt">' + money(o.total) + '</span><span class="dh-o-ago">' + ago(o.at) + '</span></button>';
      }).join('') + '</div>' + (D.live_count > D.live.length ? '<p class="dh-more">+ ' + (D.live_count - D.live.length) + ' more in Orders</p>' : '')
        : '<div class="dh-empty big">' + ic('receipt_long') + '<b>No orders in progress</b><span>New orders show up here and ring.</span>' +
          '<div class="dh-empty-actions"><button type="button" class="btn btn-primary" data-dh-go="posPage">' + ic('point_of_sale') + 'Take an order</button>' +
          '<button type="button" class="btn btn-secondary" data-dh-go="onlineOrdersPage">' + ic('language') + 'Online orders</button></div></div>') +
      '</section>';
  }

  function side() {
    var t = D.tables, pct = t.total ? Math.round(t.busy * 100 / t.total) : 0;
    var floor = '<section class="dh-card"><div class="dh-card-h"><b>Floor</b><button type="button" class="sx-link" data-dh-go="tableMapPage">Table map' + ic('arrow_forward') + '</button></div>' +
      (t.total ? '<div class="dh-floor"><b>' + t.busy + '</b><span>of ' + t.total + ' tables busy</span></div><div class="dh-meter"><i style="width:' + pct + '%"></i></div><p class="dh-sub">' + (t.total - t.busy) + ' free right now</p>'
        : '<div class="dh-empty sm">' + ic('table_restaurant') + '<span>Add your tables to see who’s seated.</span></div>') + '</section>';
    var resv = '<section class="dh-card"><div class="dh-card-h"><b>Reservations today</b><button type="button" class="sx-link" data-dh-go="reservationsPage">All' + ic('arrow_forward') + '</button></div>' +
      (D.reservations.length ? '<ul class="dh-list">' + D.reservations.map(function (r) {
        return '<li><span class="dh-time">' + esc(r.time) + '</span><span class="dh-li-main">' + esc(r.name) + '<small>' + r.guests + ' guest' + (r.guests === 1 ? '' : 's') + '</small></span>' + (r.status === 'Pending' ? '<span class="sx-pill wait">To confirm</span>' : '') + '</li>';
      }).join('') + '</ul>' : '<p class="dh-sub">No bookings for today.</p>') + '</section>';
    var top = '<section class="dh-card"><div class="dh-card-h"><b>Best sellers today</b></div>' +
      (D.top.length ? '<ol class="dh-top">' + D.top.map(function (d, i) {
        var max = D.top[0].qty || 1;
        return '<li><span class="dh-rank">' + (i + 1) + '</span><span class="dh-li-main">' + esc(d.name) + '<span class="dh-mini"><i style="width:' + Math.round(d.qty * 100 / max) + '%"></i></span></span><span class="dh-qty">' + d.qty + ' sold</span></li>';
      }).join('') + '</ol>' : '<p class="dh-sub">Shows once today’s first dishes are sold.</p>') + '</section>';
    return floor + resv + top;
  }

  // festivals and days coming up in the next 30 days, each a tap away from a ready campaign (campaign-calendar.js)
  function upcoming() {
    if (typeof window.rgUpcomingOccasions !== 'function') return '';
    var list = window.rgUpcomingOccasions(3, 30);
    if (!list.length) return '';
    return '<section class="dh-card dh-soon"><div class="dh-card-h"><b>Coming up</b><button type="button" class="sx-link" data-dh-go="marketingCalendarPage">Calendar' + ic('arrow_forward') + '</button></div><ul class="dh-list">' +
      list.map(function (x) {
        return '<li><span class="dh-soon-ic t-' + esc(x.type) + '">' + ic(x.icon || 'event') + '</span><span class="dh-li-main">' + esc(x.name) + '<small>' + (x.days === 0 ? 'Today' : x.days === 1 ? 'Tomorrow' : 'In ' + x.days + ' days') + ' · ' + esc(x.idea || '') + '</small></span>' +
          '<button type="button" class="dh-soon-go" data-dh-camp="' + esc(x.name) + '">' + ic('campaign') + 'Plan it</button></li>';
      }).join('') + '</ul></section>';
  }

  var SHORTCUTS = [['posPage', 'point_of_sale', 'POS'], ['kotPage', 'skillet', 'Kitchen'], ['menuItemsPage', 'restaurant_menu', 'Menu'], ['tablesPage', 'table_restaurant', 'Tables'],
    ['customersPage', 'group', 'Customers'], ['offersHomePage', 'sell', 'Offers'], ['reportsPage', 'monitoring', 'Reports'], ['settingsPage', 'settings', 'Settings']];

  // the shortcuts sit under Orders in progress, so the left column doesn't end in a gap
  function quick() {
    return '<section class="dh-card dh-quick"><div class="dh-card-h"><b>Quick actions</b></div><nav class="dh-shortcuts" aria-label="Shortcuts">' +
      SHORTCUTS.map(function (s) { return '<button type="button" data-dh-go="' + s[0] + '">' + ic(s[1]) + '<span>' + s[2] + '</span></button>'; }).join('') + '</nav></section>';
  }

  // Two columns end at about the same height: while the right one is taller by more than its last
  // card, that card moves under the left column (Best sellers, then Reservations, then Floor).
  // Only on wide screens; phones show one column anyway.
  function balance(root) {
    if (window.innerWidth <= 1100) return;
    var cols = root.querySelectorAll('.dh-grid > .dh-col');
    if (cols.length < 2) return;
    var left = cols[0], right = cols[1];
    for (var i = 0; i < 3; i++) {
      var last = right.lastElementChild;
      if (!last || right.children.length < 3) break;
      if (right.offsetHeight - left.offsetHeight <= last.offsetHeight) break;
      left.appendChild(last);
    }
  }
  var lastWide = window.innerWidth > 1100, resizeT = null;
  window.addEventListener('resize', function () {
    clearTimeout(resizeT);
    resizeT = setTimeout(function () {
      var wide = window.innerWidth > 1100;
      if (wide !== lastWide) { lastWide = wide; draw(); }   // crossing to one or two columns: lay out again
    }, 200);
  });

  function draw() {
    var root = document.getElementById('dhRoot');
    if (!root || !D) return;
    root.innerHTML = kpis() +
      '<div class="dh-grid">' + '<div class="dh-col">' + chart() + liveOrders() + quick() + '</div>' + '<div class="dh-col">' + upcoming() + attention() + side() + '</div>' + '</div>';
    balance(root);
    // header
    var h = new Date().getHours();
    var hello = document.getElementById('dhHello'); if (hello) hello.textContent = h < 5 ? 'Working late' : h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
    var date = document.getElementById('dhDate'); if (date) date.textContent = new Date().toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long' });
    var store = document.getElementById('dhStore');
    if (store) {
      store.hidden = D.store.open === null;
      store.className = 'dh-store ' + (D.store.open ? 'open' : 'closed');
      store.innerHTML = '<i></i>' + (D.store.open ? 'Open now' : 'Closed') + (D.store.hours ? ' · ' + esc(D.store.hours) : '');
    }
    var stamp = 'Updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    var up = document.getElementById('dashboardTime'); if (up) up.textContent = stamp;
    var rb = document.querySelector('#dashboardPage .dh-icon'); if (rb) rb.title = 'Refresh (' + stamp.toLowerCase() + ')';
  }

  // Opening the page asks for this up to three times within a second or two (page shown, page loaded,
  // a waiting order's popup); one answer is enough. The Refresh button passes force.
  var lastLoad = 0;
  window.loadDashboardHome = function (force) {
    if (busy) return;
    if (!force && D && Date.now() - lastLoad < 2000) return;
    busy = true;
    lastLoad = Date.now();
    return fetch('../api/dashboard_home.php', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      busy = false;
      var root = document.getElementById('dhRoot');
      if (!d.success) { if (root && !D) root.innerHTML = '<div class="sx-empty">' + ic('error') + '<b>Couldn’t load the dashboard</b><span>' + esc(d.message || 'Please refresh.') + '</span></div>'; return; }
      D = d; draw();
    }).catch(function () { busy = false; }).then(function () {
      clearTimeout(timer);
      // keep it fresh while it's on screen
      timer = setTimeout(function () { var pg = document.getElementById('dashboardPage'); if (pg && pg.classList.contains('active') && !document.hidden) window.loadDashboardHome(); }, 60000);
    });
  };

  document.addEventListener('click', function (e) {
    var c = e.target.closest('[data-dh-camp]');
    if (c && c.closest('#dashboardPage')) { if (window.rgStartCampaign) window.rgStartCampaign(c.getAttribute('data-dh-camp')); return; }
    var t = e.target.closest('[data-dh-go], [data-dh-metric]');
    if (!t || !t.closest('#dashboardPage')) return;
    if (t.hasAttribute('data-dh-metric')) { metric = t.getAttribute('data-dh-metric'); draw(); return; }
    var pg = t.getAttribute('data-dh-go');
    var nav = document.querySelector('.nav-link[data-page="' + pg + '"]');
    if (nav) nav.click(); else if (typeof window.showPage === 'function') window.showPage(pg);
  });
  document.addEventListener('visibilitychange', function () {
    var pg = document.getElementById('dashboardPage');
    if (!document.hidden && pg && pg.classList.contains('active')) window.loadDashboardHome();
  });
})();
