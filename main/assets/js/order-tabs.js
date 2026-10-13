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
    var counts = {};
    Object.keys(GROUPS).forEach(function (g) { counts[g] = st.orders.filter(function (o) { return GROUPS[g].test(statusOf(o)); }).length; });
    el.innerHTML = Object.keys(GROUPS).map(function (g) {
      var on = st.tab === g;
      return '<button type="button" role="tab" aria-selected="' + on + '" class="ot-tab ot-' + g + (on ? ' on' : '') + '" data-ot-key="' + key + '" data-ot-tab="' + g + '">' +
        '<span class="material-symbols-rounded">' + GROUPS[g].icon + '</span>' + GROUPS[g].label + '<em>' + counts[g] + '</em></button>';
    }).join('');
  }

  /** called by displayOrders / displayOnlineOrders with the full list; returns the orders to show */
  window.rgOrderTabs = function (key, orders, render) {
    orders = Array.isArray(orders) ? orders : [];
    if (!state[key]) state[key] = { tab: saved(key) };
    var st = state[key];
    if (!st.rendering) { st.orders = orders; st.render = render; }
    drawBar(key);
    var shown = orders.filter(function (o) { return GROUPS[st.tab].test(statusOf(o)); });
    if (!shown.length && orders.length) {
      // the list has orders, just none in this tab: say so instead of the generic "no orders"
      setTimeout(function () {
        var c = document.getElementById(LISTS[key]);
        if (c && state[key].tab === st.tab && !c.querySelector('.order-card')) c.innerHTML = '<div class="ot-empty"><span class="material-symbols-rounded">' + GROUPS[st.tab].icon + '</span><b>' + GROUPS[st.tab].empty + '</b><button type="button" data-ot-key="' + key + '" data-ot-tab="all">Show all ' + orders.length + ' orders</button></div>';
      }, 0);
    }
    return shown;
  };

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-ot-tab]');
    if (!b) return;
    var key = b.dataset.otKey, st = state[key];
    if (!st || !st.render) return;
    st.tab = b.dataset.otTab;
    remember(key, st.tab);
    st.rendering = true;
    try { st.render(st.orders); } finally { st.rendering = false; }
  });
})();
