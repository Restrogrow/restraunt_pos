/*
 * Waiter screen (views/waiter_dashboard.php): Calls · Ready to serve · POS.
 * Calls      -> staff-calls.js (get_waiter_requests / mark_attended)
 * Ready      -> ../api/get_orders.php?status=Ready, "Mark as served" -> ../api/update_order_status.php
 * POS        -> script.js (unchanged); the page's initWaiterPOS() loads it the first time the tab opens
 */
(function () {
  'use strict';
  var S = window.StaffUI;

  /* ── ready-to-serve orders ── */
  var orderSig = null, knownOrders = null, orderCount = 0;
  var listEl = document.getElementById('activeOrdersList');
  listEl.classList.add('st-grid');
  listEl.innerHTML = S.skeleton('card', 2);

  function orderCard(o) {
    var items = o.items || [];
    var isTable = !!o.table_number;
    var tile = isTable ? S.esc(o.table_number) : '<span class="material-symbols-rounded" aria-hidden="true">shopping_bag</span>';
    var title = isTable ? 'Table ' + o.table_number : (o.table_name || o.order_type || 'Takeaway');
    var no = String(o.order_number || o.id).split('-').pop(); // ORD-20261016-8155 -> 8155
    var where = [isTable ? o.area_name : '', '#' + no, S.clock(o.created_at)].filter(Boolean).join(' · ');
    return '<article class="st-ord" data-id="' + (+o.id) + '">' +
      '<div class="st-ord-top"><div class="st-tbl">' + tile + '</div>' +
        '<div class="st-call-id"><b>' + S.esc(title) + '</b><small>' + S.esc(where) + '</small></div>' +
        '<span class="st-pill ok"><span class="material-symbols-rounded">room_service</span>Ready</span></div>' +
      '<ul class="st-ord-items">' + (items.length ? items.map(function (it) {
        return '<li><span class="st-q">' + S.esc(it.quantity || 1) + '×</span><div><b>' + S.esc(it.item_name_translated || it.item_name || 'Item') + '</b>' +
          (it.notes ? '<small>Note: ' + S.esc(it.notes) + '</small>' : '') + '</div><span class="st-amt">' + S.money(it.total_price || it.unit_price || 0) + '</span></li>';
      }).join('') : '<li><div><b>' + (o.item_count || 0) + ' items</b></div></li>') + '</ul>' +
      '<div class="st-ord-foot"><small>Subtotal ' + S.money(o.subtotal) + ' · Tax ' + S.money(o.tax) + '</small><span class="st-amt">' + S.money(o.total || o.total_amount) + '</span></div>' +
      '<button type="button" class="st-btn ok lg block" data-served="' + (+o.id) + '"><span class="material-symbols-rounded" aria-hidden="true">check_circle</span>Mark as served</button>' +
    '</article>';
  }

  function loadActiveOrders() {
    return S.getJSON('../api/get_orders.php?restaurant_id=' + S.rid + '&status=Ready').then(function (res) {
      if (!res || !res.success) throw new Error((res && res.message) || 'Could not load orders');
      var orders = res.orders || [];
      var ids = orders.map(function (o) { return String(o.id); });
      var fresh = knownOrders ? ids.filter(function (id) { return knownOrders.indexOf(id) === -1; }) : [];
      knownOrders = ids;
      orderCount = orders.length;
      S.badge('[data-badge="orders"]', orders.length);
      var sig = JSON.stringify(orders.map(function (o) { return [o.id, o.order_status || o.status, o.total || o.total_amount]; }));
      if (sig !== orderSig) {
        orderSig = sig;
        listEl.innerHTML = orders.length ? orders.map(orderCard).join('')
          : '<div class="st-empty ok"><span class="material-symbols-rounded">room_service</span><b>No active orders ready to serve</b><span>When the kitchen marks food ready, it appears here.</span></div>';
      }
      var up = document.getElementById('ordersUpdated'); if (up) up.textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
      if (fresh.length) {
        var f = orders.filter(function (o) { return String(o.id) === fresh[0]; })[0];
        S.sound.play();
        S.toast(fresh.length > 1 ? fresh.length + ' orders are ready to serve' : 'Order ready – ' + (f.table_number ? 'Table ' + f.table_number : 'Takeaway'), 'success', { title: 'From the kitchen', duration: 6000 });
      }
    }).catch(function (e) {
      console.error('Error loading orders:', e);
      if (orderSig === null) listEl.innerHTML = '<div class="st-empty bad"><span class="material-symbols-rounded">wifi_off</span><b>Error loading orders</b><span>Retrying automatically…</span></div>';
    });
  }

  function markOrderServed(orderId, btn) {
    S.confirm('Mark this order as served?', { title: 'Order served', confirmText: 'Yes, served', cancelText: 'Not yet' }).then(function (ok) {
      if (!ok) return;
      if (btn) { btn.disabled = true; btn.innerHTML = '<span class="st-spin"></span>Saving…'; }
      S.postForm('../api/update_order_status.php', { orderId: orderId, status: 'Served' }).then(function (res) {
        if (!res.success) throw new Error(res.message || 'Failed to update order');
        S.toast('Order marked as served', 'success');
        orderSig = null; loadActiveOrders();
      }).catch(function (e) {
        S.toast(e.message || 'Error updating order status', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = '<span class="material-symbols-rounded">check_circle</span>Mark as served'; }
      });
    });
  }
  listEl.addEventListener('click', function (e) {
    var b = e.target.closest('[data-served]'); if (b) markOrderServed(+b.getAttribute('data-served'), b);
  });

  /* ── table calls ── */
  var calls = StaffCalls.create({
    list: document.getElementById('waiterRequestsList'),
    summary: document.getElementById('callsSummary'),
    updated: document.getElementById('lastRefresh'),
    onCount: function (n) { S.badge('[data-badge="calls"]', n, true); S.titleCount(n); }
  });

  /* ── tabs ── */
  var posReady = false;
  var tabs = S.tabs({
    prefix: 'view-', initial: 'calls', key: 'staffWaiterTab',
    onChange: function (name) {
      document.body.classList.toggle('st-pos-on', name === 'pos');
      if (name === 'calls') calls.load();
      else if (name === 'orders') loadActiveOrders();
      else if (name === 'pos' && typeof window.initWaiterPOS === 'function') {
        window.initWaiterPOS(!posReady); posReady = true;
      }
    }
  });

  calls.start(8000);
  loadActiveOrders();
  S.every(loadActiveOrders, 12000);

  document.getElementById('callsRefresh').addEventListener('click', function () { calls.load(); });
  document.getElementById('ordersRefresh').addEventListener('click', function () { orderSig = null; loadActiveOrders(); });

  // names the old page exposed (and old tab names)
  var MAP = { requests: 'calls', orders: 'orders', pos: 'pos' };
  window.switchTab = function (tab) { tabs.go(MAP[tab] || tab); };
  window.loadWaiterRequests = calls.load;
  window.markAttended = function (id) { calls.attend(id, document.querySelector('[data-attend="' + id + '"]')); };
  window.loadActiveOrders = loadActiveOrders;
  window.markOrderServed = function (id) { markOrderServed(id, document.querySelector('[data-served="' + id + '"]')); };
})();
