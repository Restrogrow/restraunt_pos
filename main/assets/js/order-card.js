/**
 * One order card for Orders and Online Orders (window.rgOrderCard(order, page)), so every card has the
 * same parts in the same places: number + total, type + time, status + payment, customer / table /
 * address / note rows, items, and the buttons at the bottom. Styles: .oc-* in studio-ui.css.
 * Buttons call the same functions as before (handleAcceptOrderClick, rejectOnlineOrder,
 * updateOrderStatus, showFullOrderDetails, printOrder, markOrderPaid).
 */
(function () {
  'use strict';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var money = function (v) { return typeof window.formatCurrency === 'function' ? window.formatCurrency(v) : (window.globalCurrencySymbol || '₹') + (+v || 0).toFixed(2); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var TYPE_ICON = { 'delivery': 'two_wheeler', 'takeaway': 'shopping_bag', 'dine-in': 'restaurant', 'dine in': 'restaurant', 'dinein': 'restaurant' };
  var STATUS = {
    Pending: ['New', 'amber'], Scheduled: ['Scheduled', 'violet'], Accepted: ['Accepted', 'blue'], Preparing: ['Preparing', 'orange'],
    Ready: ['Ready', 'green'], Served: ['Served', 'sky'], Completed: ['Completed', 'done'], Cancelled: ['Cancelled', 'red'], Rejected: ['Rejected', 'red']
  };
  var NEXT = { Accepted: ['Preparing', 'Start cooking', 'skillet'], Preparing: ['Ready', 'Mark as ready', 'check_circle'], Ready: ['Served', 'Mark as served', 'room_service'], Served: ['Completed', 'Mark as completed', 'task_alt'] };

  function when(d) {
    if (!d) return '';
    var t = new Date(String(d).replace(' ', 'T'));
    if (isNaN(t)) return esc(d);
    var now = new Date(), sameDay = t.toDateString() === now.toDateString();
    var time = t.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    var mins = Math.round((now - t) / 60000);
    var ago = mins < 1 ? 'just now' : mins < 60 ? mins + ' min ago' : mins < 1440 ? Math.round(mins / 60) + ' h ago' : '';
    return (sameDay ? time : t.toLocaleDateString([], { day: 'numeric', month: 'short' }) + ', ' + time) + (ago ? ' · ' + ago : '');
  }
  function payLabel(o) {
    var ps = o.payment_status || 'Pending', pm = o.payment_method || '';
    if (ps === 'Paid') return ['Paid' + (pm ? ' · ' + pm : ''), 'ok'];
    if (ps === 'Refunded') return ['Refunded', 'muted'];
    if (ps === 'Partially Paid') return ['Part paid', 'warn'];
    return ['Unpaid' + (pm ? ' · ' + pm : ''), 'warn'];
  }

  window.rgOrderCard = function (o, page) {
    var st = o.order_status || 'Pending', S = STATUS[st] || [st, 'muted'];
    var type = o.order_type || '', tKey = type.toLowerCase();
    var isDel = tKey.indexOf('deliver') >= 0, online = page === 'online';
    var stopped = st === 'Cancelled' || st === 'Rejected', done = st === 'Completed';
    var id = parseInt(o.id, 10), num = esc(o.order_number || o.id);
    var pay = payLabel(o);
    var items = Array.isArray(o.items) ? o.items : [];
    var MAX = 4;

    var rows = '';
    var who = esc(o.customer_name || (online ? 'Customer' : 'Walk-in'));
    var phone = o.customer_phone ? ' · <a href="tel:' + esc(String(o.customer_phone).replace(/[^0-9+]/g, '')) + '" onclick="event.stopPropagation()">' + esc(o.customer_phone) + '</a>' : '';
    rows += '<div class="oc-row">' + ic('person') + '<span>' + who + phone + '</span></div>';
    if (!online || o.table_name) { if (!isDel && (o.table_name || tKey.indexOf('dine') >= 0)) rows += '<div class="oc-row">' + ic('table_restaurant') + '<span>' + esc(o.table_name || 'Walk-in') + '</span></div>'; }
    if (o.customer_address && (isDel || online)) rows += '<div class="oc-row">' + ic('location_on') + '<span>' + esc(o.customer_address) + (o.landmark ? '<small>Landmark: ' + esc(o.landmark) + '</small>' : '') + '</span></div>';
    if (+o.is_scheduled === 1 && o.scheduled_at) rows += '<div class="oc-row oc-sched">' + ic('event') + '<span>' + (st === 'Scheduled' ? 'Goes to the kitchen at ' : 'Scheduled for ') + esc(when(o.scheduled_at).split(' · ')[0]) + '</span></div>';
    if (o.notes) rows += '<div class="oc-note">' + ic('sticky_note_2') + '<span>' + esc(o.notes) + '</span></div>';

    var extra = '';
    if (online && !stopped && !done) {
      if (isDel && typeof window.buildDeliveryMapHtml === 'function') extra += window.buildDeliveryMapHtml(o);
      if (typeof window.buildPaymentProofHtml === 'function') extra += window.buildPaymentProofHtml(o);
    }

    var itemsHtml = '';
    if (items.length) {
      var qty = items.reduce(function (a, it) { return a + (+it.quantity || 0); }, 0);
      itemsHtml = '<div class="oc-items"><div class="oc-items-h">' + qty + ' item' + (qty === 1 ? '' : 's') + '</div>' +
        items.slice(0, MAX).map(function (it) {
          return '<div class="oc-item"><span class="oc-q">' + (+it.quantity || 1) + '×</span><span class="oc-n">' + esc(it.item_name) + (it.variation_name ? ' <small>' + esc(it.variation_name) + '</small>' : '') +
            (it.notes ? '<small class="oc-in">' + ic('edit_note') + esc(it.notes) + '</small>' : '') + '</span><span class="oc-p">' + money(it.total_price) + '</span></div>';
        }).join('') +
        (items.length > MAX ? '<button type="button" class="oc-more" onclick="showFullOrderDetails(' + id + ')">+' + (items.length - MAX) + ' more</button>' : '') + '</div>';
    }

    // buttons: one main action, then the same small buttons on every card
    var main = '';
    if (online && st === 'Pending') {
      main = '<div class="oc-main two"><button type="button" class="oc-btn oc-accept" onclick="handleAcceptOrderClick(' + id + ', \'' + esc(type) + '\', this, ' + (o.address_lat != null ? +o.address_lat : 'null') + ', ' + (o.address_lng != null ? +o.address_lng : 'null') + ')">' + ic('check') + 'Accept<span class="loading-spinner" style="display:none"></span></button>' +
        '<button type="button" class="oc-btn oc-reject" onclick="rejectOnlineOrder(' + id + ', \'' + num + '\', this)">' + ic('close') + 'Reject<span class="loading-spinner" style="display:none"></span></button></div>';
    } else if (online && NEXT[st]) {
      main = '<div class="oc-main"><button type="button" class="oc-btn oc-next" onclick="updateOrderStatus(' + id + ', \'' + NEXT[st][0] + '\')">' + ic(NEXT[st][2]) + NEXT[st][1] + '</button></div>';
    }
    var small = '<button type="button" class="oc-sbtn" onclick="showFullOrderDetails(' + id + ')">' + ic('visibility') + 'Details</button>' +
      '<button type="button" class="oc-sbtn" onclick="printOrder(' + id + ')">' + ic('print') + 'Print</button>';
    var ps = o.payment_status || 'Pending';
    if (ps !== 'Paid' && ps !== 'Refunded' && !stopped && st !== 'Pending') small += '<button type="button" class="oc-sbtn oc-paid" onclick="markOrderPaid(' + id + ', this)">' + ic('payments') + 'Mark paid</button>';
    if (online && window.whatsappEnabled && window.whatsappPhone) {
      var txt = (st === 'Pending' ? 'New ' : '') + 'Order #' + (o.order_number || id) + '\nCustomer: ' + (o.customer_name || '') + '\nPhone: ' + (o.customer_phone || '') + '\nType: ' + type + '\nTotal: ' + money(o.total);
      small += '<a class="oc-sbtn oc-wa" target="_blank" rel="noopener" href="https://wa.me/' + esc(window.whatsappPhone) + '?text=' + encodeURIComponent(txt) + '">' + ic('chat') + 'WhatsApp</a>';
    }
    if (!stopped && !done && !(online && st === 'Pending')) small += '<button type="button" class="oc-sbtn oc-cancel" onclick="updateOrderStatus(' + id + ', \'Cancelled\')">' + ic('block') + 'Cancel</button>';

    return '<div class="order-card oc oc-' + S[1] + (stopped ? ' oc-stopped' : '') + '" data-status="' + esc(st) + '">' +
      '<div class="oc-top"><div class="oc-id"><b>#' + num + '</b><small>' + ic(TYPE_ICON[tKey] || 'receipt_long') + esc(type || 'Order') + ' · ' + when(o.created_at) + '</small></div>' +
      '<div class="oc-total">' + money(o.total) + '</div></div>' +
      '<div class="oc-badges"><span class="oc-st">' + esc(S[0]) + '</span><span class="oc-pay ' + pay[1] + '">' + esc(pay[0]) + '</span></div>' +
      '<div class="oc-rows">' + rows + '</div>' + extra + itemsHtml +
      '<div class="oc-foot">' + main + '<div class="oc-small">' + small + '</div></div></div>';
  };
})();
