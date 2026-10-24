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
  // wa.me number: a local number gets the restaurant's dial code (91 when the country is unknown)
  function waNum(v) {
    var d = String(v || '').replace(/\D/g, ''), cc = String(window.restaurantDialCode || '').replace(/\D/g, '') || '91';
    var min = window.restaurantPhoneMin || 10, max = window.restaurantPhoneMax || 10;
    if (d.length > max && d.indexOf(cc) === 0 && d.length - cc.length >= min) return d;
    if (d.charAt(0) === '0' && d.length - 1 >= min && d.length - 1 <= max) d = d.slice(1);
    return d.length >= min && d.length <= max ? cc + d : d;
  }
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var TYPE_ICON = { 'delivery': 'two_wheeler', 'takeaway': 'shopping_bag', 'dine-in': 'restaurant', 'dine in': 'restaurant', 'dinein': 'restaurant' };
  var STATUS = {
    Pending: ['New', 'amber'], Scheduled: ['Scheduled', 'violet'], Accepted: ['Accepted', 'blue'], Preparing: ['Preparing', 'orange'],
    Ready: ['Ready', 'green'], Served: ['Served', 'sky'], Completed: ['Completed', 'done'], Cancelled: ['Cancelled', 'red'], Rejected: ['Rejected', 'red']
  };
  var NEXT = { Accepted: ['Preparing', 'Start cooking', 'skillet'], Preparing: ['Ready', 'Mark as ready', 'check_circle'], Ready: ['Served', 'Mark as served', 'room_service'], Served: ['Completed', 'Mark as completed', 'task_alt'] };
  // Takeaway and delivery use the same statuses with their own words (no extra database status):
  //   takeaway: Ready = Ready for pickup, Served = Picked up
  //   delivery: Ready = Ready for pickup (by the rider), Served = Out for delivery, Completed = Delivered
  function kindOf(type) { var t = String(type || '').toLowerCase(); return t.indexOf('deliver') >= 0 ? 'del' : (t.indexOf('take') >= 0 || t.indexOf('pick') >= 0) ? 'take' : ''; }
  function stageWord(st, kind) {
    if (kind && st === 'Ready') return 'Ready for pickup';
    if (kind === 'take' && st === 'Served') return 'Picked up';
    if (kind === 'del' && st === 'Served') return 'Out for delivery';
    if (kind === 'del' && st === 'Completed') return 'Delivered';
    return null;
  }
  function nextStep(st, kind) {
    if (kind === 'take' && st === 'Ready') return ['Served', 'Mark as picked up', 'shopping_bag'];
    if (kind === 'del' && st === 'Ready') return ['Served', 'Send out for delivery', 'two_wheeler'];
    if (kind === 'del' && st === 'Served') return ['Completed', 'Mark as delivered', 'where_to_vote'];
    return NEXT[st] || null;
  }
  window.rgStageWord = stageWord;

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

  /* ── Online Orders: premium card (teal order strips, dish photos, PAID stamp, round action buttons) ── */
  function thumb(it, cls) {
    return '<span class="' + cls + '">' + (it && it.item_image ? '<img alt="" loading="lazy" src="../api/image.php?path=' + encodeURIComponent(it.item_image) + '" onerror="this.remove()">' : '') + ic('image') + '</span>';
  }
  function vegDot(it) {
    var t = String((it && it.item_type) || '').toLowerCase();
    if (!t) return '';
    return '<i class="o2-veg ' + (t.indexOf('non') >= 0 ? 'nv' : t.indexOf('egg') >= 0 ? 'egg' : 'v') + '" title="' + esc(it.item_type) + '"></i>';
  }
  function premium(o) {
    var st = o.order_status || 'Pending', S = STATUS[st] || [st, 'muted'];
    var type = o.order_type || 'Order', tKey = type.toLowerCase(), isDel = tKey.indexOf('deliver') >= 0;
    var isTake = tKey.indexOf('take') >= 0 || tKey.indexOf('pick') >= 0;
    var kind = kindOf(type);
    if (stageWord(st, kind)) S = [stageWord(st, kind), S[1]];
    var stopped = st === 'Cancelled' || st === 'Rejected', done = st === 'Completed';
    var id = parseInt(o.id, 10), num = esc(o.order_number || o.id);
    (window._rgOrders = window._rgOrders || {})[id] = o;   // for the Update payment window
    var pay = payLabel(o), paid = (o.payment_status || '') === 'Paid';
    var items = Array.isArray(o.items) ? o.items : [];
    var name = o.customer_name || 'Customer';
    var tel = o.customer_phone ? String(o.customer_phone).replace(/[^0-9+]/g, '') : '';

    var head = '<div class="o2-head"><span class="o2-av">' + esc(name.trim().charAt(0).toUpperCase() || 'C') + '</span><div class="o2-who"><b title="' + esc(name) + '">' + esc(name) + '</b><small>' +
      (o.customer_email ? '<span>' + ic('mail') + esc(o.customer_email) + '</span>' : '') +
      (o.customer_phone ? '<a href="tel:' + esc(tel) + '">' + ic('call') + esc(o.customer_phone) + '</a>' : '') + '</small></div></div>';
    var strips = '<div class="o2-idrow"><div class="o2-no"><small>Order</small><b>#' + num + '</b></div><span class="o2-typechip t-' + (isDel ? 'del' : tKey.indexOf('take') >= 0 ? 'take' : 'dine') + '">' + ic(TYPE_ICON[tKey] || 'receipt_long') + esc(type) + '</span></div>';
    var badges = '<div class="o2-badges"><span class="oc-st">' + esc(S[0]) + '</span>' +
      (o.table_number ? '<span class="o2-tag">' + ic('table_restaurant') + 'Table ' + esc(o.table_number) + '</span>' : '') +
      (+o.is_scheduled === 1 && o.scheduled_at ? '<span class="o2-sched">' + ic('event') + esc(when(o.scheduled_at).split(' · ')[0]) + '</span>' : '') + '</div>';

    // every dish in the same row: photo, name (size, add-ons, note), quantity, price
    var MAXI = 4;
    var itemRow = function (it) {
      var sub = [it.variation_name ? esc(it.variation_name) : "", it.addon_names && it.addon_names.length ? "+ " + esc(it.addon_names.join(", ")) : ""].filter(Boolean).join(" · ");
      return "<div class=\"o2-it\">" + thumb(it, "o2-t") + "<div class=\"o2-it-n\"><b>" + vegDot(it) + "<span>" + esc(it.item_name) + "</span></b>" + (sub ? "<small>" + sub + "</small>" : "") +
        (it.notes ? "<small class=\"o2-in\">" + ic("edit_note") + esc(it.notes) + "</small>" : "") + "</div><span class=\"o2-it-q\">×" + (+it.quantity || 1) + "</span><b class=\"o2-it-p\">" + money(it.total_price) + "</b></div>";
    };
    var qtyAll = items.reduce(function (a, it) { return a + (+it.quantity || 0); }, 0);
    var itemsHtml = items.length ? "<div class=\"o2-items\"><div class=\"o2-items-h\">" + items.length + " dish" + (items.length === 1 ? "" : "es") + " · " + qtyAll + " item" + (qtyAll === 1 ? "" : "s") + "</div>" +
      items.slice(0, MAXI).map(itemRow).join("") +
      (items.length > MAXI ? "<button type=\"button\" class=\"o2-all\" onclick=\"showFullOrderDetails(" + id + ")\">+" + (items.length - MAXI) + " more dish" + (items.length - MAXI === 1 ? "" : "es") + "</button>" : "") + "</div>" : "";
    var info = '';
    if (o.customer_address && isDel) info += '<div class="oc-row">' + ic('location_on') + '<span>' + esc(o.customer_address) + (o.landmark ? '<small>Landmark: ' + esc(o.landmark) + '</small>' : '') + '</span></div>';
    if (o.notes) info += '<div class="oc-note">' + ic('sticky_note_2') + '<span>' + esc(o.notes) + '</span></div>';
    var extra = '';
    if (!stopped && !done) {
      if (isDel && typeof window.buildDeliveryMapHtml === 'function') extra += window.buildDeliveryMapHtml(o);
      if (typeof window.buildPaymentProofHtml === 'function') extra += window.buildPaymentProofHtml(o);
    }
    // payment, next to the total: paid (green tick) or still to collect (orange)
    var pm = o.payment_method ? esc(o.payment_method) : '', psx = o.payment_status || 'Pending';
    var payTag = paid ? '<span class="o2-paytag ok">' + ic('check_circle') + 'Paid' + (pm ? ' · ' + pm : '') + '</span>'
      : psx === 'Refunded' ? '<span class="o2-paytag muted">' + ic('undo') + 'Refunded</span>'
      : stopped ? '<span class="o2-paytag muted">Not charged</span>'
      : '<span class="o2-paytag due">' + ic('schedule') + (psx === 'Partially Paid' ? 'Part paid' : 'To collect') + (pm ? ' · ' + pm : '') + '</span>';
    var t = new Date(String(o.created_at || '').replace(' ', 'T'));
    var placed = isNaN(t) ? esc(o.created_at || '') : t.toLocaleString([], Object.assign({ day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }, t.getFullYear() !== new Date().getFullYear() ? { year: 'numeric' } : {}));

    // actions: same functions as before; main one as a pill, the rest as round buttons
    var main = '';
    if (st === 'Pending') {
      main = '<button type="button" class="o2-pill o2-ok" onclick="handleAcceptOrderClick(' + id + ', \'' + esc(type) + '\', this, ' + (o.address_lat != null ? +o.address_lat : 'null') + ', ' + (o.address_lng != null ? +o.address_lng : 'null') + ')">' + ic('check') + 'Accept<span class="loading-spinner" style="display:none"></span></button>' +
        '<button type="button" class="o2-pill o2-no" onclick="rejectOnlineOrder(' + id + ', \'' + num + '\', this)">' + ic('close') + 'Reject<span class="loading-spinner" style="display:none"></span></button>';
    } else if (nextStep(st, kind)) {
      var nx = nextStep(st, kind);
      main = '<button type="button" class="o2-pill" onclick="updateOrderStatus(' + id + ', \'' + nx[0] + '\')">' + ic(nx[2]) + nx[1] + '</button>';
    } else {
      main = '<span class="o2-pill o2-flat">' + ic(stopped ? 'block' : 'task_alt') + esc(S[0]) + '</span>';
    }
    var round = '';
    if (window.whatsappEnabled && window.whatsappPhone) {
      var txt = (st === 'Pending' ? 'New ' : '') + 'Order #' + (o.order_number || id) + '\nCustomer: ' + (o.customer_name || '') + '\nPhone: ' + (o.customer_phone || '') + '\nType: ' + type + '\nTotal: ' + money(o.total);
      round += '<a class="o2-rb o2-wa" target="_blank" rel="noopener" title="WhatsApp" aria-label="WhatsApp" href="https://wa.me/' + esc(waNum(window.whatsappPhone)) + '?text=' + encodeURIComponent(txt) + '">' + ic('chat') + '</a>';
    }
    if (tel) round += '<a class="o2-rb" title="Call ' + esc(o.customer_phone) + '" aria-label="Call customer" href="tel:' + esc(tel) + '">' + ic('call') + '</a>';
    round += '<button type="button" class="o2-rb" title="Print" aria-label="Print" onclick="printOrder(' + id + ')">' + ic('print') + '</button>';
    round += '<button type="button" class="o2-rb" title="Details" aria-label="Details" onclick="showFullOrderDetails(' + id + ')">' + ic('visibility') + '</button>';
    var ps = o.payment_status || 'Pending';
    // Update payment: method (Cash, UPI, Card...) and paid / part paid / refunded, in a modal
    if (st !== 'Pending' && !(stopped && ps === 'Pending') && ps !== 'Refunded') round += '<button type="button" class="o2-rb o2-paid" title="Update payment" aria-label="Update payment" onclick="rgUpdatePayment(' + id + ')">' + ic('payments') + '</button>';
    if (!stopped && !done && st !== 'Pending') round += '<button type="button" class="o2-rb o2-cancel" title="Cancel order" aria-label="Cancel order" onclick="updateOrderStatus(' + id + ', \'Cancelled\')">' + ic('block') + '</button>';

    return '<div class="order-card oc o2 oc-' + S[1] + (stopped ? ' oc-stopped' : '') + '" data-status="' + esc(st) + '">' +
      head + strips + badges + itemsHtml + (info ? '<div class="o2-info">' + info + '</div>' : '') + extra +
      '<div class="o2-sum"><span class="o2-when" title="Order placed">' + ic('schedule') + placed + '</span><div class="o2-amt">' + payTag + '<b>' + money(o.total) + '</b></div></div>' +
      '<div class="o2-foot"><div class="o2-main">' + main + '</div><div class="o2-round">' + round + '</div></div></div>';
  }

  window.rgOrderCard = function (o, page) {
    if (page === 'online') return premium(o);
    var st = o.order_status || 'Pending', S = STATUS[st] || [st, 'muted'];
    var type = o.order_type || '', tKey = type.toLowerCase();
    var isDel = tKey.indexOf('deliver') >= 0, online = page === 'online';
    if (stageWord(st, kindOf(type))) S = [stageWord(st, kindOf(type)), S[1]];
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
    } else if (online && nextStep(st, kindOf(type))) {
      var nx2 = nextStep(st, kindOf(type));
      main = '<div class="oc-main"><button type="button" class="oc-btn oc-next" onclick="updateOrderStatus(' + id + ', \'' + nx2[0] + '\')">' + ic(nx2[2]) + nx2[1] + '</button></div>';
    }
    var small = '<button type="button" class="oc-sbtn" onclick="showFullOrderDetails(' + id + ')">' + ic('visibility') + 'Details</button>' +
      '<button type="button" class="oc-sbtn" onclick="printOrder(' + id + ')">' + ic('print') + 'Print</button>';
    var ps = o.payment_status || 'Pending';
    if (ps !== 'Paid' && ps !== 'Refunded' && !stopped && st !== 'Pending') small += '<button type="button" class="oc-sbtn oc-paid" onclick="markOrderPaid(' + id + ', this)">' + ic('payments') + 'Mark paid</button>';
    if (online && window.whatsappEnabled && window.whatsappPhone) {
      var txt = (st === 'Pending' ? 'New ' : '') + 'Order #' + (o.order_number || id) + '\nCustomer: ' + (o.customer_name || '') + '\nPhone: ' + (o.customer_phone || '') + '\nType: ' + type + '\nTotal: ' + money(o.total);
      small += '<a class="oc-sbtn oc-wa" target="_blank" rel="noopener" href="https://wa.me/' + esc(waNum(window.whatsappPhone)) + '?text=' + encodeURIComponent(txt) + '">' + ic('chat') + 'WhatsApp</a>';
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
