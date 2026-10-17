/*
 * Manager screen (views/manager_dashboard.php).
 * Overview  ../api/get_dashboard_stats.php + ../api/dashboard_home.php (7-day sales) + ../api/get_orders.php?active=1
 * Orders    ../api/get_orders.php (today), "Mark as served" -> ../api/update_order_status.php
 * Kitchen   staff-kds.js       Calls  staff-calls.js       Tables  ../api/get_table_status.php
 * Customers ../api/get_customers.php   Payments ../api/get_payments.php   Reports ../api/get_sales_report.php
 * POS       script.js (same POS as the waiter screen)
 */
(function () {
  'use strict';
  var S = window.StaffUI;
  var $ = function (id) { return document.getElementById(id); };
  var rid = S.rid;

  function empty(icon, title, text, cls) {
    return '<div class="st-empty ' + (cls || '') + '"><span class="material-symbols-rounded">' + icon + '</span><b>' + title + '</b>' + (text ? '<span>' + text + '</span>' : '') + '</div>';
  }
  function failRow(cols, what) { return '<tr><td colspan="' + cols + '">' + empty('wifi_off', 'Failed to load ' + what, 'Please try again.', 'bad') + '</td></tr>'; }
  function skelRows(cols, n) { var r = ''; for (var i = 0; i < (n || 5); i++) r += '<tr><td colspan="' + cols + '"><div class="st-skel h-row"></div></td></tr>'; return r; }
  function statusPill(s) {
    var k = String(s || 'Pending').toLowerCase();
    var c = { pending: 'warn', accepted: 'info', preparing: 'orange', ready: 'ok', served: 'info', completed: 'muted', paid: 'ok', success: 'ok', cancelled: 'bad', failed: 'bad', scheduled: 'muted' }[k] || 'muted';
    return '<span class="st-pill ' + c + '">' + S.esc(s || 'Pending') + '</span>';
  }
  function stamp(ts) {
    var t = S.parseTime(ts); if (isNaN(t)) return '';
    var d = new Date(t), today = new Date(S.now());
    var same = d.toDateString() === today.toDateString();
    return same ? S.clock(ts) : d.toLocaleDateString([], { day: 'numeric', month: 'short' }) + ', ' + S.clock(ts);
  }
  function ago(ts) { return S.fmtAge(S.since(ts)) + ' ago'; }
  function plural(n, w) { n = +n || 0; return n + ' ' + w + (n === 1 ? '' : 's'); }

  /* ════ Overview ════ */
  var lastStats = null;
  function loadStats() {
    return S.getJSON('../api/get_dashboard_stats.php?restaurant_id=' + rid).then(function (res) {
      if (!res || !res.success) throw new Error('stats');
      var st = res.stats || {}; lastStats = st;
      var busy = Math.max(0, (st.totalTables || 0) - (st.availableTables || 0));
      $('todayRevenue').textContent = S.money(st.todayRevenue || 0);
      $('todayOrders').textContent = st.todayOrders || 0;
      $('inProgress').textContent = st.pendingOrders || 0;
      $('activeKot').textContent = st.activeKOT || 0;
      $('activeTables').textContent = busy + (st.totalTables ? ' / ' + st.totalTables : '');
      S.badge('[data-badge="kitchen"]', st.activeKOT || 0);
      S.badge('[data-badge="orders"]', st.pendingOrders || 0);
    }).catch(function (e) { console.error('Error loading stats:', e); });
  }
  function loadHome() {
    loadStats();
    // 7 days of sales + yesterday comparison
    S.getJSON('../api/dashboard_home.php?restaurant_id=' + rid).then(function (h) {
      if (!h || !h.success) return;
      var t = (h.today || {}).sales || 0, y = (h.yesterday || {}).sales || 0;
      var d = $('revDelta');
      if (d) {
        if (y > 0) { var p = Math.round((t - y) / y * 100); d.className = 'st-delta ' + (p >= 0 ? 'up' : 'down'); d.textContent = (p >= 0 ? '▲ ' : '▼ ') + Math.abs(p) + '% vs yesterday'; }
        else d.textContent = 'Today so far';
      }
      var days = (h.days || []).slice(-7), max = 1;
      days.forEach(function (x) { max = Math.max(max, +x.sales || 0); });
      var todayKey = (h.now || '').slice(0, 10);
      var sum = 0; days.forEach(function (x) { sum += +x.sales || 0; });
      $('mgWeekTotal').textContent = S.money(Math.round(sum));
      $('mgBars').innerHTML = days.map(function (x) {
        var dt = new Date(x.date + 'T00:00:00'), isToday = x.date === todayKey;
        var v = +x.sales || 0, k = v >= 1000 ? (Math.round(v / 100) / 10) + 'k' : Math.round(v);
        return '<div class="mg-bar' + (isToday ? ' today' : '') + '" title="' + S.esc(x.date) + ': ' + S.money(v) + ' · ' + (x.orders || 0) + ' orders"><b>' + k + '</b><i style="height:' + Math.max(3, Math.round(v / max * 100)) + '%"></i><span>' + (isToday ? 'Today' : dt.toLocaleDateString([], { weekday: 'short' })) + '</span></div>';
      }).join('');
    }).catch(function () { });
    // orders still being worked on
    S.getJSON('../api/get_orders.php?restaurant_id=' + rid + '&active=1&limit=8').then(function (res) {
      var list = (res && res.orders) || [];
      $('mgLive').innerHTML = list.length ? '<div class="st-rows">' + list.map(function (o) {
        var where = o.table_number ? 'Table ' + o.table_number : (o.order_type || 'Takeaway');
        return '<div class="st-row"><div><b>' + S.esc(where) + ' · #' + S.esc(String(o.order_number || o.id).slice(-4)) + '</b><small>' + plural(o.item_count || (o.items || []).length, 'item') + ' · ' + ago(o.created_at) + '</small></div>' +
          '<div class="st-row-r">' + statusPill(o.order_status || o.status) + '<span class="st-amt">' + S.money(o.total || o.total_amount) + '</span></div></div>';
      }).join('') + '</div>' : '<div class="st-empty ok" style="border:0;padding:20px"><span class="material-symbols-rounded">done_all</span><b>Nothing in progress</b><span>New orders show up here.</span></div>';
    }).catch(function () { $('mgLive').innerHTML = empty('wifi_off', 'Couldn’t load orders', '', 'bad'); });
  }

  /* ════ Orders ════ */
  var allOrders = [], orderFilter = 'All', orderSearch = '';
  function loadOrders() {
    $('ordersTbody').innerHTML = allOrders.length ? $('ordersTbody').innerHTML : skelRows(7);
    return S.getJSON('../api/get_orders.php?restaurant_id=' + rid + '&limit=100').then(function (res) {
      if (!res || !res.success) throw new Error('orders');
      allOrders = res.orders || [];
      paintOrders();
    }).catch(function (e) { console.error('Error loading orders:', e); $('ordersTbody').innerHTML = failRow(7, 'orders'); });
  }
  function paintOrders() {
    var counts = { All: allOrders.length };
    allOrders.forEach(function (o) { var s = o.order_status || o.status || 'Pending'; counts[s] = (counts[s] || 0) + 1; });
    var order = ['All', 'Pending', 'Accepted', 'Preparing', 'Ready', 'Served', 'Completed', 'Cancelled'];
    Object.keys(counts).forEach(function (k) { if (order.indexOf(k) < 0) order.push(k); });
    $('orderChips').innerHTML = order.filter(function (k) { return counts[k] || k === 'All'; }).map(function (k) {
      return '<button type="button" class="' + (k === orderFilter ? 'on' : '') + '" data-ofilter="' + S.esc(k) + '">' + S.esc(k) + '<em>' + (counts[k] || 0) + '</em></button>';
    }).join('');
    var q = orderSearch.toLowerCase();
    var list = allOrders.filter(function (o) {
      if (orderFilter !== 'All' && (o.order_status || o.status || 'Pending') !== orderFilter) return false;
      if (!q) return true;
      return [o.order_number, o.id, o.table_number, o.customer_name, o.customer_phone].join(' ').toLowerCase().indexOf(q) > -1;
    });
    $('ordersTbody').innerHTML = list.length ? list.map(function (o) {
      var st = o.order_status || o.status || 'Pending';
      var where = o.table_number ? 'Table ' + o.table_number + (o.area_name ? ' · ' + o.area_name : '') : (o.order_type || 'Takeaway');
      return '<tr><td><b>#' + S.esc(o.order_number || o.id) + '</b><br><small class="st-updated">' + S.esc(stamp(o.created_at)) + '</small></td>' +
        '<td>' + S.esc(where) + (o.customer_name && o.customer_name !== 'Table Customer' ? '<br><small class="st-updated">' + S.esc(o.customer_name) + '</small>' : '') + '</td>' +
        '<td class="r">' + plural(o.item_count || (o.items || []).length, 'item') + '</td>' +
        '<td class="r"><b>' + S.money(o.total || o.total_amount) + '</b><br><small class="st-updated">' + S.esc(o.payment_method || '') + ' ' + S.esc(o.payment_status || '') + '</small></td>' +
        '<td>' + statusPill(st) + '</td>' +
        '<td class="r">' + (st === 'Ready' ? '<button type="button" class="st-btn ok sm" data-served="' + (+o.id) + '"><span class="material-symbols-rounded">check_circle</span>Mark served</button>' : '') + '</td></tr>';
    }).join('') : '<tr><td colspan="6">' + empty('receipt_long', 'No orders found', orderFilter !== 'All' || q ? 'Try another filter.' : 'Today’s orders show up here.') + '</td></tr>';
  }
  $('orderChips').addEventListener('click', function (e) { var b = e.target.closest('[data-ofilter]'); if (b) { orderFilter = b.getAttribute('data-ofilter'); paintOrders(); } });
  $('orderSearch').addEventListener('input', function () { orderSearch = this.value.trim(); paintOrders(); });
  $('ordersTbody').addEventListener('click', function (e) {
    var b = e.target.closest('[data-served]'); if (!b) return;
    S.confirm('Mark this order as served?', { title: 'Order served', confirmText: 'Yes, served', cancelText: 'Not yet' }).then(function (ok) {
      if (!ok) return;
      b.disabled = true; b.innerHTML = '<span class="st-spin"></span>';
      S.postForm('../api/update_order_status.php', { orderId: b.getAttribute('data-served'), status: 'Served' }).then(function (r) {
        if (!r.success) throw new Error(r.message || 'Failed to update order');
        S.toast('Order marked as served', 'success'); loadOrders(); loadStats();
      }).catch(function (err) { S.toast(err.message || 'Error updating order', 'error'); b.disabled = false; b.textContent = 'Mark served'; });
    });
  });

  /* ════ Tables ════ */
  function loadTables() {
    return S.getJSON('../api/get_table_status.php?restaurant_id=' + rid).then(function (res) {
      var list = (res && res.data) || [];
      if (!list.length) { $('mgTables').innerHTML = empty('table_restaurant', 'No tables yet', 'Tables are added from the owner dashboard.'); return; }
      var areas = {}, order = [];
      list.forEach(function (t) { var a = t.area_name || 'Tables'; if (!areas[a]) { areas[a] = []; order.push(a); } areas[a].push(t); });
      var busy = list.filter(function (t) { return t.status === 'occupied'; }).length;
      $('tablesSummary').innerHTML = '<span class="st-pill orange">' + busy + ' occupied</span><span class="st-pill ok">' + (list.length - busy) + ' free</span>';
      $('mgTables').innerHTML = order.map(function (a) {
        return '<div class="mg-area">' + S.esc(a) + '</div><div class="mg-tables">' + areas[a].map(function (t) {
          var occ = t.status === 'occupied', k = (t.kot_info || [])[0];
          var off = !occ && (t.is_available === false || t.is_available === 0);
          return '<div class="mg-tb ' + (occ ? 'busy' : off ? 'off' : '') + '"><b>' + S.esc(t.table_number) + '</b>' +
            '<small>' + (occ ? 'Occupied' + (t.order_timer ? ' · ' + S.esc(t.order_timer) : '') : off ? 'Not available' : 'Free') + ' · ' + (t.capacity || '?') + ' seats</small>' +
            (k ? '<small>KOT ' + S.esc(k.kot_status || '') + ' · ' + S.money(k.total) + '</small>' : '') + '</div>';
        }).join('') + '</div>';
      }).join('');
    }).catch(function () { $('mgTables').innerHTML = empty('wifi_off', 'Couldn’t load tables', 'Please try again.', 'bad'); });
  }

  /* ════ Customers (first 200, newest activity first; search filters them on the device) ════ */
  var allCust = null;
  function loadCustomers() {
    if (!allCust) $('customersTbody').innerHTML = skelRows(5);
    return S.getJSON('../api/get_customers.php?restaurant_id=' + rid + '&limit=200').then(function (res) {
      if (!res || !res.success) throw new Error('customers');
      allCust = res.data || res.customers || [];
      paintCustomers();
    }).catch(function (e) { console.error('Error loading customers:', e); $('customersTbody').innerHTML = failRow(5, 'customers'); });
  }
  function paintCustomers() {
    var q = $('custSearch').value.trim().toLowerCase();
    var list = (allCust || []).filter(function (c) { return !q || [c.customer_name, c.phone, c.email].join(' ').toLowerCase().indexOf(q) > -1; });
    $('custCount').textContent = list.length;
    $('customersTbody').innerHTML = list.length ? list.map(function (c) {
      return '<tr><td><b>' + (S.esc(c.customer_name) || 'N/A') + '</b><br><small class="st-updated">' + (S.esc(c.email) || '') + '</small></td>' +
        '<td>' + (S.esc(c.phone) || 'N/A') + '</td><td class="r">' + plural(c.total_visits, 'visit') + '</td>' +
        '<td class="r"><b>' + S.money(c.total_spent || 0) + '</b></td><td class="r">' + S.esc(c.last_visit_date || 'N/A') + '</td></tr>';
    }).join('') : '<tr><td colspan="5">' + empty('group', 'No customers found', q ? 'Try another name or number.' : '') + '</td></tr>';
  }
  $('custSearch').addEventListener('input', paintCustomers);

  /* ════ Payments ════ */
  var allPays = [], payFilter = 'All';
  function loadPayments() {
    if (!allPays.length) $('paymentsTbody').innerHTML = skelRows(5);
    return S.getJSON('../api/get_payments.php?restaurant_id=' + rid).then(function (res) {
      if (!res || !res.success) throw new Error('payments');
      allPays = res.payments || [];
      paintPays();
    }).catch(function (e) { console.error('Error loading payments:', e); $('paymentsTbody').innerHTML = failRow(5, 'payments'); });
  }
  function paintPays() {
    var counts = { All: allPays.length };
    allPays.forEach(function (p) { var m = p.payment_method || 'Other'; counts[m] = (counts[m] || 0) + 1; });
    $('payChips').innerHTML = Object.keys(counts).map(function (k) { return '<button type="button" class="' + (k === payFilter ? 'on' : '') + '" data-pfilter="' + S.esc(k) + '">' + S.esc(k) + '<em>' + counts[k] + '</em></button>'; }).join('');
    var list = allPays.filter(function (p) { return payFilter === 'All' || (p.payment_method || 'Other') === payFilter; });
    $('paymentsTbody').innerHTML = list.length ? list.map(function (p) {
      return '<tr><td><b>' + S.esc(p.order_number ? '#' + p.order_number : (p.transaction_id || 'N/A')) + '</b><br><small class="st-updated">' + S.esc(p.transaction_id || p.reference_number || (p.table_number ? 'Table ' + p.table_number : '')) + '</small></td>' +
        '<td class="r"><b>' + S.money(p.amount || 0) + '</b></td><td>' + S.esc(p.payment_method || 'N/A') + '</td><td>' + statusPill(p.payment_status || 'Pending') + '</td><td class="r">' + S.esc(stamp(p.created_at)) + '</td></tr>';
    }).join('') : '<tr><td colspan="5">' + empty('payments', 'No payments found', '') + '</td></tr>';
  }
  $('payChips').addEventListener('click', function (e) { var b = e.target.closest('[data-pfilter]'); if (b) { payFilter = b.getAttribute('data-pfilter'); paintPays(); } });

  /* ════ Reports ════ */
  function loadReports() {
    return S.getJSON('../api/get_sales_report.php?restaurant_id=' + rid).then(function (res) {
      if (!res || !res.success) throw new Error('report');
      var s = res.summary || {};
      var total = +(s.total_sales || res.total_revenue || 0), orders = +(s.total_orders || res.total_orders || 0);
      var avg = orders ? total / orders : +(res.average_order_value || 0);
      $('salesReport').innerHTML =
        '<div class="st-stats">' +
          tile('payments', '#2563eb', S.money(total), 'Total sales') + tile('receipt_long', '#7c3aed', orders, 'Orders') +
          tile('trending_up', '#16a34a', S.money(Math.round(avg)), 'Average order') + tile('restaurant', '#f97316', s.total_items || 0, 'Items sold') +
          tile('group', '#0f766e', s.total_customers || 0, 'Customers') +
        '</div>';
      // payment_methods covers the whole day; sales_details stops at the latest 100 orders
      var byM = {};
      if (Array.isArray(res.payment_methods)) res.payment_methods.forEach(function (r) { var m = r.payment_method || 'Other'; byM[m] = (byM[m] || 0) + (+r.amount || 0); });
      else (res.sales_details || []).forEach(function (r) { var m = r.payment_method || 'Other'; byM[m] = (byM[m] || 0) + (+r.total || 0); });
      var ms = Object.keys(byM).sort(function (a, b) { return byM[b] - byM[a]; }), mx = ms.length ? byM[ms[0]] : 1;
      $('payMix').innerHTML = ms.length ? '<div class="mg-split">' + ms.map(function (m) {
        return '<div class="mg-split-row"><span>' + S.esc(m) + '</span><span class="tr"><i style="width:' + Math.max(3, Math.round(byM[m] / mx * 100)) + '%"></i></span><span class="st-amt">' + S.money(Math.round(byM[m])) + '</span></div>';
      }).join('') + '</div>' : '<p class="st-sub">No sales yet today.</p>';
    }).catch(function (e) { console.error('Error loading reports:', e); $('salesReport').innerHTML = empty('wifi_off', 'Failed to load report', 'Please try again.', 'bad'); });
  }
  function tile(icon, c, v, l) { return '<div class="st-stat"><span class="st-ic" style="--c:' + c + '"><span class="material-symbols-rounded">' + icon + '</span></span><b>' + v + '</b><small>' + l + '</small></div>'; }

  /* ════ Kitchen + Calls (shared with the chef / waiter screens) ════ */
  var kds = null;
  var calls = StaffCalls.create({
    list: $('mgCallsList'), summary: $('callsSummary'), updated: $('callsUpdated'),
    onCount: function (n) {
      S.badge('[data-badge="calls"]', n, true);
      $('pendingRequests').textContent = n;
      S.titleCount(n);
      $('mgAttention').innerHTML = n ? '<button type="button" class="st-btn warn block" data-tab="calls"><span class="material-symbols-rounded">notifications_active</span>' + n + ' table call' + (n > 1 ? 's' : '') + ' waiting</button>'
        : '<div class="st-row" style="border:0"><div><b>No table calls waiting</b><small>Guests calling a waiter show up here.</small></div><span class="st-pill ok">All clear</span></div>';
    }
  });

  /* ════ POS ════ */
  var posReady = false;

  /* ════ tabs ════ */
  var LOADERS = {
    home: loadHome, orders: loadOrders, tables: loadTables, customers: loadCustomers, payments: loadPayments, reports: loadReports,
    calls: function () { calls.load(); },
    kitchen: function () {
      if (!kds) kds = StaffKDS.create({ board: $('mgBoard'), stages: $('mgStages'), updated: $('kitchenUpdated'), onCounts: function (c) { S.badge('[data-badge="kitchen"]', c.all); } }).start();
      else kds.refresh().catch(function () { });
    },
    pos: function () { if (typeof window.initManagerPOS === 'function') { window.initManagerPOS(!posReady); posReady = true; } }
  };
  var tabs = S.tabs({
    prefix: 'view-', initial: 'home', key: 'staffManagerTab',
    onChange: function (name) {
      document.body.classList.toggle('st-pos-on', name === 'pos');
      closeMore();
      document.querySelectorAll('.st-tb[data-more]').forEach(function (b) { b.classList.toggle('on', ['tables', 'pos', 'customers', 'payments', 'reports'].indexOf(name) > -1); });
      if (LOADERS[name]) LOADERS[name]();
    }
  });

  /* phone "More" sheet */
  var more = $('mgMore');
  function closeMore() { if (more) more.hidden = true; }
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-more]')) { more.hidden = false; return; }
    if (e.target === more || e.target.closest('[data-more-close]')) closeMore();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMore(); });

  document.querySelectorAll('[data-refresh]').forEach(function (b) {
    b.addEventListener('click', function () { var n = tabs.current(); if (LOADERS[n]) LOADERS[n](); if (n === 'home') calls.load(); });
  });

  calls.start(10000);
  if (tabs.current() !== 'home') loadStats();
  S.every(function () {
    var n = tabs.current();
    if (n === 'home') loadHome(); else loadStats();
    if (n === 'orders') loadOrders();
    if (n === 'tables') loadTables();
  }, 30000);

  // names the old page exposed
  window.switchTab = function (t) { tabs.go(t); };
  window.loadStats = loadStats; window.loadOrders = loadOrders; window.loadCustomers = loadCustomers; window.loadPayments = loadPayments; window.loadReports = loadReports;
})();
