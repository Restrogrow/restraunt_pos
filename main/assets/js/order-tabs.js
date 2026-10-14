/**
 * Orders and Online Orders: Ongoing / Completed / Cancelled / All tabs above the list, with counts.
 * script.js calls window.rgOrderTabs(key, orders, render) at the start of displayOrders and
 * displayOnlineOrders; this keeps the full list, draws the tabs and returns only the chosen group.
 * The other filters (search, status, payment, type, date) still decide what is loaded.
 */
(function () {
  'use strict';
  var GROUPS = {
    ongoing: { label: 'Ongoing', icon: 'schedule', test: function (s) { return ['Pending', 'Scheduled', 'Accepted', 'Preparing', 'Ready', 'Served'].indexOf(s) >= 0; }, empty: 'No ongoing orders right now.' },
    completed: { label: 'Completed', icon: 'task_alt', test: function (s) { return s === 'Completed'; }, empty: 'No completed orders for these filters.' },
    cancelled: { label: 'Cancelled', icon: 'block', test: function (s) { return s === 'Cancelled' || s === 'Rejected'; }, empty: 'No cancelled or rejected orders.' },
    all: { label: 'All', icon: 'list', test: function () { return true; }, empty: 'No orders found.' }
  };
  var LISTS = { orders: 'ordersList', online: 'onlineOrdersList' };
  var state = {};
  function saved(key) { try { return localStorage.getItem('rg_order_tab_' + key) || 'ongoing'; } catch (e) { return 'ongoing'; } }
  function remember(key, tab) { try { localStorage.setItem('rg_order_tab_' + key, tab); } catch (e) {} }
  function statusOf(o) { return String((o && o.order_status) || 'Pending'); }
  // second row under Ongoing: one chip per stage
  var STAGES = [['Pending', 'New'], ['Scheduled', 'Scheduled'], ['Accepted', 'Accepted'], ['Preparing', 'Preparing'], ['Ready', 'Ready'], ['Served', 'Served']];
  // order type (Online Orders): All · Delivery · Dine-in · Takeaway, from the order_type field
  var TYPES = [['all', 'All types', 'list'], ['delivery', 'Delivery', 'two_wheeler'], ['dinein', 'Dine-in', 'restaurant'], ['takeaway', 'Takeaway', 'shopping_bag']];
  var TYPED = { online: true };
  function typeOf(o) { var t = String((o && o.order_type) || '').toLowerCase().replace(/[^a-z]/g, ''); return t.indexOf('deliver') >= 0 ? 'delivery' : (t.indexOf('take') >= 0 || t.indexOf('pick') >= 0) ? 'takeaway' : t.indexOf('dine') >= 0 ? 'dinein' : 'other'; }
  function savedType(key) { try { return localStorage.getItem('rg_order_type_' + key) || 'all'; } catch (e) { return 'all'; } }
  function rememberType(key, v) { try { localStorage.setItem('rg_order_type_' + key, v); } catch (e) {} }
  function money(v) { return typeof window.formatCurrency === 'function' ? window.formatCurrency(v) : (window.globalCurrencySymbol || '₹') + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }); }
  function typeBar(key, el, st) {
    if (!TYPED[key]) return;
    var tb = document.getElementById('rgOrderType_' + key);
    if (!tb) { tb = document.createElement('div'); tb.id = 'rgOrderType_' + key; tb.className = 'ot-types'; el.parentNode.insertBefore(tb, el); }
    var all = st.all || [];
    var row = TYPES.map(function (t) {
      var n = t[0] === 'all' ? all.length : all.filter(function (o) { return typeOf(o) === t[0]; }).length, on = st.type === t[0];
      return '<button type="button" role="tab" aria-selected="' + on + '" class="ot-type' + (on ? ' on' : '') + '" data-ot-key="' + key + '" data-ot-type="' + t[0] + '">' +
        '<span class="ot-type-ic"><span class="material-symbols-rounded">' + t[2] + '</span></span><span class="ot-type-l">' + t[1] + '<small>' + n + ' order' + (n === 1 ? '' : 's') + '</small></span></button>';
    }).join('');
    // summary of the chosen type, from what is loaded with the current filters
    var list = st.orders;
    var cnt = function (f) { return list.filter(function (o) { return f(statusOf(o)); }).length; };
    var live = list.filter(function (o) { var x = statusOf(o); return x !== 'Cancelled' && x !== 'Rejected'; });
    var value = live.reduce(function (a, o) { return a + (+o.total || 0); }, 0);
    var doneVal = list.filter(function (o) { return statusOf(o) === 'Completed'; }).reduce(function (a, o) { return a + (+o.total || 0); }, 0);
    var stat = function (icon, label, v, cls) { return '<div class="ot-stat ' + (cls || '') + '"><span class="material-symbols-rounded">' + icon + '</span><div><small>' + label + '</small><b>' + v + '</b></div></div>'; };
    var pickup = st.type === 'takeaway' || st.type === 'delivery';
    tb.innerHTML = '<div class="ot-type-row" role="tablist" aria-label="Order type">' + row + '</div><div class="ot-stats">' +
      stat('receipt_long', 'Orders', list.length, 'ink') +
      stat('fiber_new', 'New', cnt(function (x) { return x === 'Pending'; }), 'amber') +
      stat('skillet', 'Preparing', cnt(function (x) { return x === 'Accepted' || x === 'Preparing'; }), 'orange') +
      stat('check_circle', pickup ? 'Ready for pickup' : 'Ready', cnt(function (x) { return x === 'Ready'; }), 'green') +
      stat('task_alt', 'Completed', cnt(function (x) { return x === 'Completed'; }), 'done') +
      stat('block', 'Cancelled', cnt(function (x) { return x === 'Cancelled' || x === 'Rejected'; }), 'red') +
      stat('payments', 'Total value', money(value), 'value') +
      stat('account_balance_wallet', 'Done value', money(doneVal), 'value') + '</div>';
  }
  function savedSub(key) { try { return localStorage.getItem('rg_order_sub_' + key) || ''; } catch (e) { return ''; } }
  function rememberSub(key, v) { try { localStorage.setItem('rg_order_sub_' + key, v); } catch (e) {} }

  function bar(key) {
    var list = document.getElementById(LISTS[key]);
    if (!list) return null;
    var el = document.getElementById('rgOrderTabs_' + key);
    if (!el) {
      el = document.createElement('div');
      el.id = 'rgOrderTabs_' + key;
      el.className = 'ot-tabs';
      el.setAttribute('role', 'tablist');
      list.parentNode.insertBefore(el, list);
    }
    return el;
  }
  function drawBar(key) {
    var el = bar(key), st = state[key];
    if (!el || !st) return;
    typeBar(key, el, st);
    var counts = {};
    Object.keys(GROUPS).forEach(function (g) { counts[g] = st.orders.filter(function (o) { return GROUPS[g].test(statusOf(o)); }).length; });
    el.innerHTML = Object.keys(GROUPS).map(function (g) {
      var on = st.tab === g;
      return '<button type="button" role="tab" aria-selected="' + on + '" class="ot-tab ot-' + g + (on ? ' on' : '') + '" data-ot-key="' + key + '" data-ot-tab="' + g + '">' +
        '<span class="material-symbols-rounded">' + GROUPS[g].icon + '</span>' + GROUPS[g].label + '<em>' + counts[g] + '</em></button>';
    }).join('');
    // stage chips, only under Ongoing
    var sub = document.getElementById('rgOrderSub_' + key);
    if (!sub) { sub = document.createElement('div'); sub.id = 'rgOrderSub_' + key; sub.className = 'ot-sub'; el.parentNode.insertBefore(sub, el.nextSibling); }
    if (st.tab !== 'ongoing') { sub.hidden = true; return; }
    sub.hidden = false;
    var ongoing = st.orders.filter(function (o) { return GROUPS.ongoing.test(statusOf(o)); });
    var chip = function (v, label, n) { var on = (st.sub || '') === v; return '<button type="button" class="ot-chip' + (on ? ' on' : '') + (n ? '' : ' zero') + (v ? ' st-' + v.toLowerCase() : '') + '" data-ot-key="' + key + '" data-ot-sub="' + v + '" aria-pressed="' + on + '">' + label + '<em>' + n + '</em></button>'; };
    sub.innerHTML = chip('', 'All ongoing', ongoing.length) + STAGES.map(function (x) { var lb = st.type === 'takeaway' && x[0] === 'Served' ? 'Picked up' : st.type === 'delivery' && x[0] === 'Served' ? 'Out for delivery' : (st.type === 'takeaway' || st.type === 'delivery') && x[0] === 'Ready' ? 'Ready for pickup' : x[1]; return chip(x[0], lb, ongoing.filter(function (o) { return statusOf(o) === x[0]; }).length); }).join('');
  }

  /** called by displayOrders / displayOnlineOrders with the full list; returns the orders to show */
  window.rgOrderTabs = function (key, orders, render) {
    orders = Array.isArray(orders) ? orders : [];
    if (!state[key]) state[key] = { tab: saved(key), sub: savedSub(key), type: TYPED[key] ? savedType(key) : 'all' };
    var st = state[key];
    if (!st.rendering) { st.all = orders; st.render = render; }
    // order type first; Ongoing / Completed and the stage chips then work inside it
    st.orders = st.type === 'all' ? st.all : st.all.filter(function (o) { return typeOf(o) === st.type; });
    orders = st.orders;
    drawBar(key);
    var shown = orders.filter(function (o) { var s = statusOf(o); return GROUPS[st.tab].test(s) && (st.tab !== 'ongoing' || !st.sub || s === st.sub); });
    if (!shown.length && orders.length) {
      // the list has orders, just none in this tab: say so instead of the generic "no orders"
      setTimeout(function () {
        var c = document.getElementById(LISTS[key]);
        if (c && state[key].tab === st.tab && !c.querySelector('.order-card')) c.innerHTML = '<div class="ot-empty"><span class="material-symbols-rounded">' + GROUPS[st.tab].icon + '</span><b>' + (st.tab === 'ongoing' && st.sub ? 'No ' + ((STAGES.filter(function (x) { return x[0] === st.sub; })[0] || [0, st.sub])[1]).toLowerCase() + ' orders right now.' : GROUPS[st.tab].empty) + '</b><button type="button" data-ot-key="' + key + '" data-ot-tab="all">Show all ' + orders.length + ' orders</button></div>';
      }, 0);
    }
    if (!orders.length && st.all.length && st.type !== 'all') {
      // orders exist, just none of this type (e.g. no dine-in orders online)
      var tl = (TYPES.filter(function (x) { return x[0] === st.type; })[0] || [0, st.type, 'list']);
      setTimeout(function () {
        var c = document.getElementById(LISTS[key]);
        if (c && state[key].type === st.type && !c.querySelector('.order-card')) c.innerHTML = '<div class="ot-empty"><span class="material-symbols-rounded">' + tl[2] + '</span><b>No ' + tl[1].toLowerCase() + ' orders for these filters.</b><button type="button" data-ot-key="' + key + '" data-ot-type="all">Show all types (' + st.all.length + ')</button></div>';
      }, 0);
    }
    return shown;
  };

  document.addEventListener('click', function (e) {
    var c = e.target.closest('[data-ot-sub]');
    if (c) {
      var k = c.dataset.otKey, s2 = state[k];
      if (!s2 || !s2.render) return;
      s2.sub = c.dataset.otSub; rememberSub(k, s2.sub);
      s2.rendering = true;
      try { s2.render(s2.all); } finally { s2.rendering = false; }
      return;
    }
    var ty = e.target.closest('[data-ot-type]');
    if (ty) {
      var k3 = ty.dataset.otKey, s3 = state[k3];
      if (!s3 || !s3.render) return;
      s3.type = ty.dataset.otType; rememberType(k3, s3.type);
      s3.rendering = true;
      try { s3.render(s3.all); } finally { s3.rendering = false; }
      return;
    }
    var b = e.target.closest('[data-ot-tab]');
    if (!b) return;
    var key = b.dataset.otKey, st = state[key];
    if (!st || !st.render) return;
    st.tab = b.dataset.otTab;
    remember(key, st.tab);
    st.rendering = true;
    try { st.render(st.all); } finally { st.rendering = false; }
  });
})();
