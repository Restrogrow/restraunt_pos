/*
 * The "New order" popup and the reason picker for rejecting / cancelling an order.
 *
 *  RGNewOrder.show(order)   fills #newOrderOverlay (script.js polls api/get_latest_pending_order.php and calls it)
 *  RGNewOrder.accept()      accept, with an optional "ready in" time (and the delivery-charge prompt for per-km delivery)
 *  RGNewOrder.reject()      pick a reason, then reject
 *  RGNewOrder.later()       close and stop the ring; the order waits in Online Orders
 *  RGReason.open(opts)      5 common reasons + "Other"; resolves {code, text, strike} or null
 *
 * Also replaces rejectOnlineOrder() and the "Cancelled" path of updateOrderStatus() so every reject /
 * cancel in the dashboard asks why. The reason goes to api/update_order_status.php (reason + reason_code):
 * preset reasons are shown to the customer on the tracking page (config/order_reasons.php); only
 * "Couldn't reach the customer" or a custom reason marked as fake counts as a strike against the number.
 * Styles: no-* and rr-* in assets/css/studio-ui.css.
 */
(function () {
  'use strict';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var cur = function () { return window.globalCurrencySymbol || '₹'; };
  var money = function (n) { var v = Math.round((+n || 0) * 100) / 100; return cur() + v.toLocaleString('en-IN', { minimumFractionDigits: v % 1 ? 2 : 0, maximumFractionDigits: 2 }); };
  var notify = function (m, t) { if (typeof window.showNotification === 'function') window.showNotification(m, t); };
  var go = function (page) { if (typeof window.showPage === 'function') window.showPage(page); };
  var post = function (body) {
    return fetch('../api/update_order_status.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(body).toString() })
      .then(function (r) { return r.json(); }).catch(function () { return { success: false, message: 'No connection. Please try again.' }; });
  };
  var reloadLists = function () {
    if (document.getElementById('ordersList') && typeof window.loadOrders === 'function') window.loadOrders();
    if (document.getElementById('onlineOrdersList') && typeof window.loadOnlineOrders === 'function') window.loadOnlineOrders();
  };
  function isCash(m) { return /cash|cod|counter|pay at/i.test(m || ''); }
  function isOnline(m) { return /phonepe|online|netbanking|card/i.test(m || ''); }
  function isQr(m) { return /upi|qr/i.test(m || '') && !/phonepe/i.test(m || ''); }
  function when(s) { var d = s ? new Date(String(s).replace(' ', 'T')) : null; return d && !isNaN(d) ? d : null; }
  function ago(d) {
    if (!d) return '';
    var m = Math.max(0, Math.round((Date.now() - d.getTime()) / 60000));
    return m < 1 ? 'Just now' : m < 60 ? m + ' min ago' : Math.floor(m / 60) + ' h ' + (m % 60) + ' min ago';
  }
  function clock(d) { return d ? d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : ''; }
  function dayWord(d) {
    if (!d) return '';
    var t = new Date(); t.setHours(0, 0, 0, 0); var x = new Date(d); x.setHours(0, 0, 0, 0);
    var diff = Math.round((x - t) / 86400000);
    return diff === 0 ? 'today' : diff === 1 ? 'tomorrow' : d.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' });
  }
  function ordinal(n) { var s = ['th', 'st', 'nd', 'rd'], v = n % 100; return n + (s[(v - 20) % 10] || s[v] || s[0]); }

  /* ───────────── reason picker ───────────── */
  var REASONS = {
    reject: function (o) {
      var del = o && /deliver/i.test(o.order_type || '');
      return [
        ['out_of_stock', 'inventory_2', 'Items out of stock', 'Some dishes in this order have run out'],
        ['too_busy', 'hourglass_top', 'Kitchen is too busy', 'Too many orders to make this one in time'],
        ['closed', 'door_front', 'Closed or closing', 'The restaurant is closed or about to close'],
        del ? ['too_far', 'wrong_location', 'Address too far', 'Outside the area you deliver to'] : ['kitchen_issue', 'soup_kitchen', 'Kitchen problem', 'Equipment, gas or staff problem'],
        ['no_answer', 'phone_disabled', 'Couldn’t reach the customer', 'No answer, or the order looks fake. Counts as a strike']
      ];
    },
    cancel: function (o) {
      var del = !o || /deliver/i.test(o.order_type || '');
      return [
        ['customer_asked', 'person_cancel', 'Customer asked to cancel', 'They called or messaged to cancel'],
        ['out_of_stock', 'inventory_2', 'Items ran out', 'Couldn’t finish the order'],
        ['kitchen_issue', 'soup_kitchen', 'Kitchen problem', 'Equipment, gas or staff problem'],
        del ? ['delivery_issue', 'two_wheeler', 'Can’t deliver', 'No rider, or the address can’t be reached'] : ['closed', 'door_front', 'Closing early', 'The restaurant has to close'],
        ['no_answer', 'phone_disabled', 'Couldn’t reach the customer', 'Didn’t pick up or never came. Counts as a strike']
      ];
    }
  };
  var RR = null;   // the open picker: {mode, order, code, resolve}
  function refundNote(o) {
    if (!o) return '';
    var paid = String(o.payment_status || '') === 'Paid', wallet = +o.wallet_paid || 0;
    var parts = [];
    if (paid && isOnline(o.payment_method)) parts.push('This order was paid online (' + esc(o.payment_method) + ', ' + money(o.total) + '). Refund it from your PhonePe business dashboard.');
    else if (paid) parts.push('This order is marked paid (' + esc(o.payment_method || '') + '). Give the money back to the customer.');
    if (wallet > 0) parts.push(money(wallet) + ' paid from the customer’s wallet goes back to their wallet by itself.');
    return parts.length ? '<div class="rr-refund">' + ic('currency_rupee') + '<span>' + parts.join(' ') + '</span></div>' : '';
  }
  function paintReason() {
    var host = document.getElementById('rgReason');
    if (!host) { host = document.createElement('div'); host.id = 'rgReason'; document.body.appendChild(host); }
    if (!RR) { host.innerHTML = ''; return; }
    var list = REASONS[RR.mode](RR.order), rej = RR.mode === 'reject', num = RR.order ? (RR.order.order_number || RR.order.id) : RR.number;
    host.innerHTML = '<div class="rr-back" data-rr-back><div class="rr-sheet" role="dialog" aria-modal="true" aria-label="' + (rej ? 'Reject order' : 'Cancel order') + '">' +
      '<header><div><b>' + (rej ? 'Why can’t you take this order?' : 'Why are you cancelling?') + '</b><small>Order #' + esc(num || '') + (rej ? ' · the customer sees a short, polite reason' : ' · the customer sees a short, polite reason') + '</small></div>' +
        '<button type="button" class="rr-x" data-rr-close aria-label="Close">' + ic('close') + '</button></header>' +
      '<div class="rr-list">' + list.map(function (r) {
        return '<button type="button" class="rr-opt' + (RR.code === r[0] ? ' on' : '') + (r[0] === 'no_answer' ? ' warn' : '') + '" data-rr-pick="' + r[0] + '">' + ic(r[1]) + '<span><b>' + r[2] + '</b><small>' + r[3] + '</small></span><i class="rr-dot"></i></button>';
      }).join('') +
        '<button type="button" class="rr-opt' + (RR.code === 'other' ? ' on' : '') + '" data-rr-pick="other">' + ic('edit_note') + '<span><b>Other reason</b><small>Write your own (only you see it)</small></span><i class="rr-dot"></i></button>' +
      '</div>' +
      (RR.code === 'other' ? '<div class="rr-custom"><textarea id="rrText" maxlength="200" rows="2" placeholder="Type the reason">' + esc(RR.text || '') + '</textarea>' +
        '<label class="rr-strike"><input type="checkbox" id="rrStrike"' + (RR.strike ? ' checked' : '') + '><span>Count it as a fake order (3 strikes block this phone number)</span></label></div>' : '') +
      refundNote(RR.order) +
      '<footer><button type="button" class="btn btn-secondary" data-rr-close>' + (rej ? 'Back' : 'Keep the order') + '</button>' +
        '<button type="button" class="btn sx-danger rr-go" data-rr-ok' + (RR.code ? '' : ' disabled') + '>' + ic(rej ? 'block' : 'cancel') + (rej ? 'Reject order' : 'Cancel order') + '</button></footer>' +
    '</div></div>';
    var t = document.getElementById('rrText'); if (t && RR.code === 'other') setTimeout(function () { t.focus(); }, 30);
  }
  var RGReason = {
    open: function (opts) {
      return new Promise(function (resolve) {
        RR = { mode: opts.mode === 'cancel' ? 'cancel' : 'reject', order: opts.order || null, number: opts.number || '', code: '', text: '', strike: false, resolve: resolve };
        paintReason();
        // no order details yet (cancel from a list card): fetch them for the payment / refund note
        if (!RR.order && opts.orderId) {
          var mine = RR;
          fetch('../api/get_order_details_by_id.php?id=' + encodeURIComponent(opts.orderId), { cache: 'no-store' }).then(function (r) { return r.json(); })
            .then(function (d) { if (RR === mine && d && d.success && d.order) { RR.order = d.order; paintReason(); } }).catch(function () {});
        }
      });
    }
  };
  function closeReason(result) { var r = RR; RR = null; paintReason(); if (r) r.resolve(result || null); }
  document.addEventListener('click', function (e) {
    if (!RR) return;
    var t = e.target.closest('[data-rr-pick],[data-rr-close],[data-rr-ok],[data-rr-back]');
    if (!t) return;
    if (t.hasAttribute('data-rr-back')) { if (e.target === t) closeReason(null); return; }
    if (t.hasAttribute('data-rr-close')) { closeReason(null); return; }
    if (t.hasAttribute('data-rr-pick')) { var tx = document.getElementById('rrText'); if (tx) RR.text = tx.value; RR.code = t.getAttribute('data-rr-pick'); paintReason(); return; }
    if (t.hasAttribute('data-rr-ok')) {
      var list = REASONS[RR.mode](RR.order), hit = list.filter(function (r) { return r[0] === RR.code; })[0];
      var text = RR.code === 'other' ? ((document.getElementById('rrText') || {}).value || '').trim() : (hit ? hit[2] : '');
      if (!text) { var ta = document.getElementById('rrText'); if (ta) { ta.classList.add('rr-need'); ta.focus(); } return; }
      var strike = RR.code === 'other' ? !!((document.getElementById('rrStrike') || {}).checked) : RR.code === 'no_answer';
      closeReason({ code: RR.code, text: text, strike: strike });
    }
  });
  document.addEventListener('input', function (e) { if (RR && e.target.id === 'rrText') { RR.text = e.target.value; e.target.classList.remove('rr-need'); } });
  document.addEventListener('change', function (e) { if (RR && e.target.id === 'rrStrike') RR.strike = e.target.checked; });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && RR) { e.stopPropagation(); closeReason(null); } }, true);
  window.RGReason = RGReason;

  /* ───────────── the new-order popup ───────────── */
  var O = null, prep = 0, dlv = 0, tick = null, busy = false, watch = null;

  // The owner often has the dashboard open in many tabs: each rings for a new order. When one tab
  // accepts / rejects / closes it ("Later"), the others close it and stop ringing (BroadcastChannel,
  // or the storage event in browsers without it). Another phone or PC answering it is caught by
  // checking the order every 8 seconds while the popup is open.
  var CH = 'rg-new-order', bc = null;
  try { if ('BroadcastChannel' in window) bc = new BroadcastChannel(CH); } catch (e) { bc = null; }
  function tell(id, kind) {
    var msg = { id: +id, kind: kind, t: Date.now() };
    try { if (bc) bc.postMessage(msg); else localStorage.setItem(CH, JSON.stringify(msg)); } catch (e) {}
  }
  function heard(msg) {
    if (!msg || !msg.id) return;
    // don't pop it up again here
    if (+msg.id > (window._lastSeenOrderId || 0)) window._lastSeenOrderId = +msg.id;
    if (!O || +O.id !== +msg.id || busy) return;
    var num = O.order_number || O.id;
    close();
    if (msg.kind === 'accepted') notify('Order #' + num + ' was accepted on another screen', 'success');
    else if (msg.kind === 'rejected') notify('Order #' + num + ' was rejected on another screen', 'info');
    else if (msg.kind === 'answered') notify('Order #' + num + ' was ' + (msg.status ? String(msg.status).toLowerCase() : 'answered') + ' on another screen', 'info');
    if (msg.kind !== 'later') reloadLists();
  }
  if (bc) bc.onmessage = function (e) { heard(e.data); };
  window.addEventListener('storage', function (e) {
    if (e.key !== CH || !e.newValue) return;
    try { heard(JSON.parse(e.newValue)); } catch (er) {}
  });
  // another device answered it: the order is no longer Pending
  function startWatch() {
    clearInterval(watch);
    watch = setInterval(function () {
      if (!O || busy) return;
      var id = O.id;
      fetch('../api/get_order_details_by_id.php?id=' + encodeURIComponent(id), { cache: 'no-store', credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var st = d && d.success && d.order ? String(d.order.order_status || '') : '';
          if (st && st !== 'Pending' && O && +O.id === +id && !busy) heard({ id: id, kind: 'answered', status: st });
        }).catch(function () {});
    }, 8000);
  }
  var PREP = [5, 10, 15, 20, 30, 45];
  var T_MAX = 300, T_STEP = 5;
  // "Ready in": preparing time for every order, plus delivery time for delivery orders.
  // Chips pick a time, − / + move it by 5 minutes, the box takes any number.
  function isDelivery(o) { return /deliver/i.test((o && o.order_type) || ''); }
  function clockIn(min) { var d = new Date(Date.now() + min * 60000); return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }
  function timeRow(k, label, icon, val) {
    return '<div class="no-trow" data-k="' + k + '"><span class="no-tl">' + ic(icon) + label + '</span>' +
      '<div class="no-prep-opts">' + PREP.map(function (m) { return '<button type="button" data-m="' + m + '" class="' + (val === m ? 'on' : '') + '" onclick="RGNewOrder.setT(\'' + k + '\',' + m + ')">' + m + '</button>'; }).join('') + '</div>' +
      '<div class="no-step"><button type="button" aria-label="5 minutes less" onclick="RGNewOrder.stepT(\'' + k + '\',-1)">' + ic('remove') + '</button>' +
      '<input type="number" min="0" max="' + T_MAX + '" step="1" inputmode="numeric" value="' + (val || '') + '" placeholder="0" oninput="RGNewOrder.typeT(\'' + k + '\',this.value)" aria-label="' + label + ' in minutes"><em>min</em>' +
      '<button type="button" aria-label="5 minutes more" onclick="RGNewOrder.stepT(\'' + k + '\',1)">' + ic('add') + '</button></div></div>';
  }
  function timeSummary(o) {
    if (!prep && !dlv) return 'Optional. The customer sees the time on their tracking page.';
    if (!isDelivery(o)) return 'Ready around <b>' + clockIn(prep) + '</b>. The customer sees this on their tracking page.';
    return (prep ? 'Ready around <b>' + clockIn(prep) + '</b> · ' : '') + 'at the customer around <b>' + clockIn(prep + dlv) + '</b> (' + (prep + dlv) + ' min).';
  }
  function acceptLabel() {
    var t = prep && dlv ? prep + ' + ' + dlv + ' min' : (prep || dlv) ? (prep || dlv) + ' min' : '';
    return ic('check_circle') + 'Accept' + (t ? ' · ' + t : '');
  }
  function payBlock(o) {
    var pm = o.payment_method || '', ps = String(o.payment_status || ''), total = +o.total || +o.subtotal || 0, wallet = +o.wallet_paid || 0, due = Math.max(0, total - wallet);
    var type = (o.order_type || '').toLowerCase(), where = /deliver/.test(type) ? 'on delivery' : /take|pick/.test(type) ? 'at pickup' : 'at the table';
    var cls, icon, title, sub = '';
    if (ps === 'Paid') { cls = 'ok'; icon = 'verified'; title = 'Paid · ' + money(total); sub = 'Paid ' + (isOnline(pm) ? 'online with ' + pm : 'by ' + pm) + '. Nothing to collect.'; }
    else if (isQr(pm)) { cls = 'warn'; icon = 'qr_code_2'; title = 'UPI QR · ' + money(due); sub = o.payment_proof_status ? 'Check the payment screenshot below before you accept.' : 'The customer pays to your UPI QR. Check you received ' + money(due) + '.'; }
    else if (isCash(pm)) { cls = 'cash'; icon = 'payments'; title = 'Collect ' + money(due) + ' in cash'; sub = 'Cash ' + where + '.'; }
    else if (ps === 'Partially Paid') { cls = 'warn'; icon = 'pending'; title = 'Part paid · ' + money(due) + ' left'; sub = pm; }
    else { cls = 'warn'; icon = 'hourglass_top'; title = (pm || 'Payment') + ' · ' + money(due); sub = 'Payment ' + (ps || 'pending').toLowerCase() + '.'; }
    if (wallet > 0) sub += ' ' + money(wallet) + ' already paid from wallet.';
    return '<div class="no-pay ' + cls + '">' + ic(icon) + '<div><b>' + esc(title) + '</b><small>' + esc(sub) + '</small></div></div>';
  }
  function bill(o) {
    var rows = [], n = function (v) { return +v || 0; };
    var sub = n(o.subtotal), tax = n(o.tax), total = n(o.total);
    if (sub) rows.push(['Items', money(sub)]);
    if (n(o.discount_amount)) rows.push([(o.coupon_code ? 'Coupon ' + o.coupon_code : 'Discount'), '−' + money(o.discount_amount)]);
    if (n(o.loyalty_discount_amount)) rows.push(['Rewards', '−' + money(o.loyalty_discount_amount)]);
    if (n(o.vip_discount)) rows.push(['VIP discount', '−' + money(o.vip_discount)]);
    if (n(o.delivery_charge)) rows.push(['Delivery', money(o.delivery_charge)]);
    if (tax) rows.push(['Tax', money(tax)]);
    var wallet = n(o.wallet_paid), grand = total || sub + tax, unpaid = String(o.payment_status || '') !== 'Paid';
    return '<div class="no-bill">' + rows.map(function (r) { return '<div><span>' + esc(r[0]) + '</span><span>' + r[1] + '</span></div>'; }).join('') +
      '<div class="no-total"><span>Total</span><b>' + money(grand) + '</b></div>' +
      (wallet > 0 ? '<div><span>Paid from wallet</span><span>−' + money(wallet) + '</span></div>' + (unpaid ? '<div class="no-due"><span>To collect</span><b>' + money(Math.max(0, grand - wallet)) + '</b></div>' : '') : '') +
    '</div>';
  }
  // a combo is one priced line (variation 'Combo') followed by its dishes at 0 noted "Combo: <name>"
  function inCombo(i) { return /^Combo: /.test(i.notes || '') && !(+i.total_price); }
  function items(o) {
    if (!o.items || !o.items.length) return '<div class="no-items-none">' + (+o.item_count || 0) + ' item(s)</div>';
    var count = o.items.reduce(function (a, i) { return a + (inCombo(i) ? 0 : (+i.quantity || 1)); }, 0);
    return '<div class="no-sec-h">' + count + ' item' + (count === 1 ? '' : 's') + '</div><div class="no-items">' + o.items.map(function (i) {
      if (inCombo(i)) {
        return '<div class="no-item no-sub"><span class="no-item-main"><b>' + esc(i.item_name) + '</b>' + (i.variation_name ? '<small>' + esc(i.variation_name) + '</small>' : '') + '</span><span class="no-qty">× ' + (+i.quantity || 1) + '</span></div>';
      }
      if (i.variation_name === 'Combo') {
        return '<div class="no-item no-combo"><span class="no-thumb">' + ic('fastfood') + '</span>' +
          '<span class="no-item-main"><b>' + esc(i.item_name) + '</b><small class="no-combo-tag">Combo</small></span>' +
          '<span class="no-qty">× ' + (+i.quantity || 1) + '</span><span class="no-price">' + money(i.total_price) + '</span></div>';
      }
      var t = String(i.item_type || '').toLowerCase().replace(' ', '-');
      var extras = [i.variation_name].concat(i.addon_names || []).filter(Boolean).join(' · ');
      return '<div class="no-item"><span class="no-thumb">' + (i.item_image ? '<img alt="" loading="lazy" src="../api/image.php?path=' + encodeURIComponent(i.item_image) + '" onerror="this.remove()">' : '') + ic('restaurant') + '</span>' +
        '<span class="no-item-main"><b>' + (t ? '<i class="no-veg ' + esc(t) + '"></i>' : '') + esc(i.item_name) + '</b>' + (extras ? '<small>' + esc(extras) + '</small>' : '') + (i.notes ? '<em>' + ic('edit_note') + esc(i.notes) + '</em>' : '') + '</span>' +
        '<span class="no-qty">× ' + (+i.quantity || 1) + '</span><span class="no-price">' + money(i.total_price) + '</span></div>';
    }).join('') + '</div>';
  }
  function paint() {
    var card = document.getElementById('noCard');
    if (!card || !O) return;
    var o = O, created = when(o.created_at), type = String(o.order_type || '').replace(/_/g, ' ');
    var typeIc = /deliver/i.test(type) ? 'two_wheeler' : /take|pick/i.test(type) ? 'shopping_bag' : 'table_restaurant';
    var sched = (+o.is_scheduled === 1 || o.is_scheduled === true) && o.scheduled_at ? when(o.scheduled_at) : null;
    var prev = o.previous_orders, isDel = /deliver/i.test(type);
    var km = isDel && (window.enableKmDelivery == 1 || window.enableKmDelivery === true);
    card.innerHTML =
      '<header class="no-head"><span class="no-bell">' + ic('notifications_active') + '</span>' +
        '<div class="no-head-t"><small>New order</small><b>#' + esc(o.order_number || o.id) + '</b></div>' +
        '<div class="no-when"><b id="noAgo">' + ago(created) + '</b><small>' + (created ? 'at ' + clock(created) : '') + '</small></div>' +
        '<button type="button" class="no-x" onclick="RGNewOrder.later()" title="Decide later" aria-label="Close">' + ic('close') + '</button></header>' +
      '<div class="no-chips"><span class="no-chip">' + ic(typeIc) + esc(type ? type.charAt(0).toUpperCase() + type.slice(1) : 'Order') + (o.table_number ? ' · Table ' + esc(o.table_number) : '') + '</span>' +
        (sched ? '<span class="no-chip sched">' + ic('schedule') + 'For ' + clock(sched) + ' ' + dayWord(sched) + '</span>' : '') +
        (+o.others_waiting ? '<button type="button" class="no-chip more" onclick="RGNewOrder.later(\'onlineOrdersPage\')">' + ic('notifications') + '+' + o.others_waiting + ' more waiting</button>' : '') + '</div>' +
      '<div class="no-body">' +
        '<div class="no-cust"><span class="no-av">' + esc((o.customer_name || 'G').trim().charAt(0).toUpperCase()) + '</span>' +
          '<div><b>' + esc(o.customer_name || 'Guest') + (typeof prev === 'number' ? (prev === 0 ? ' <span class="no-tag new">New customer</span>' : ' <span class="no-tag">' + ordinal(prev + 1) + ' order</span>') : '') + '</b>' +
          (o.customer_phone ? '<a href="tel:' + esc(String(o.customer_phone).replace(/[^0-9+]/g, '')) + '">' + ic('call') + esc(o.customer_phone) + '</a>' : '') + '</div></div>' +
        (isDel && o.customer_address ? '<div class="no-addr">' + ic('location_on') + '<div><span>' + esc(o.customer_address) + '</span>' + (o.landmark ? '<small>Landmark: ' + esc(o.landmark) + '</small>' : '') + '</div></div>' : '') +
        '<div id="notifMapContainer"></div>' +
        payBlock(o) +
        '<div id="notifPaymentProofContainer"></div>' +
        (o.notes ? '<div class="no-note">' + ic('sticky_note_2') + '<span><b>Note from the customer</b>' + esc(o.notes) + '</span></div>' : '') +
        items(o) + bill(o) +
      '</div>' +
      '<div class="no-prep no-times"><div class="no-th">' + ic('timer') + 'Ready in <em>minutes</em></div>' +
        timeRow('prep', 'Preparing', 'skillet', prep) +
        (isDelivery(o) ? timeRow('dlv', 'Delivery', 'two_wheeler', dlv) : '') +
        '<small id="noTimeSum">' + timeSummary(o) + (km ? ' You’ll confirm the delivery charge next.' : '') + '</small></div>' +
      '<footer class="no-foot">' +
        '<button type="button" class="no-later" onclick="RGNewOrder.later()" title="Close and decide later; it waits in Online Orders">' + ic('schedule') + '<span>Later</span></button>' +
        '<button type="button" class="no-reject" id="notifRejectBtn" onclick="RGNewOrder.reject()">' + ic('block') + 'Reject</button>' +
        '<button type="button" class="no-accept" id="notifAcceptBtn" onclick="RGNewOrder.accept()">' + acceptLabel() + '</button>' +
      '</footer>';
    var map = document.getElementById('notifMapContainer');
    if (map) map.innerHTML = isDel && typeof window.buildDeliveryMapHtml === 'function' ? window.buildDeliveryMapHtml(o) : '';
    var proof = document.getElementById('notifPaymentProofContainer');
    if (proof) proof.innerHTML = typeof window.buildPaymentProofHtml === 'function' ? window.buildPaymentProofHtml(o, 160) : '';
  }
  function close(keepRing) {
    var ov = document.getElementById('newOrderOverlay');
    if (ov) ov.classList.remove('show');
    document.body.style.overflow = '';
    clearInterval(tick); tick = null;
    clearInterval(watch); watch = null;
    if (!keepRing && typeof window.stopNewOrderRing === 'function') window.stopNewOrderRing();
    window._currentNotifOrderId = null;
    O = null;
  }
  function setBusy(which) {
    busy = !!which;
    ['notifAcceptBtn', 'notifRejectBtn'].forEach(function (id) { var b = document.getElementById(id); if (b) b.disabled = busy; });
    var b = which && document.getElementById(which === 'accept' ? 'notifAcceptBtn' : 'notifRejectBtn');
    if (b) b.innerHTML = '<span class="loading-spinner"></span>' + (which === 'accept' ? 'Accepting…' : 'Rejecting…');
  }

  // only the chips and the Accept label change (a full repaint would reload the map)
  function showPrep(typing) {
    [['prep', prep], ['dlv', dlv]].forEach(function (x) {
      var row = document.querySelector('#noCard .no-trow[data-k="' + x[0] + '"]');
      if (!row) return;
      row.querySelectorAll('.no-prep-opts button').forEach(function (b) { b.classList.toggle('on', +b.dataset.m === x[1]); });
      var inp = row.querySelector('input');
      if (inp && typing !== x[0]) inp.value = x[1] || '';
      row.classList.toggle('set', x[1] > 0);
    });
    var sum = document.getElementById('noTimeSum');
    var km = O && isDelivery(O) && (window.enableKmDelivery == 1 || window.enableKmDelivery === true);
    if (sum && O) sum.innerHTML = timeSummary(O) + (km ? ' You’ll confirm the delivery charge next.' : '');
    var a = document.getElementById('notifAcceptBtn');
    if (a && !busy) a.innerHTML = acceptLabel();
  }
  function clampT(n) { n = Math.round(+n || 0); return Math.max(0, Math.min(T_MAX, n)); }

  var RGNewOrder = {
    show: function (order) {
      if (!order) return;
      var ov = document.getElementById('newOrderOverlay');
      if (!ov) return;
      O = order; prep = 0; dlv = 0; busy = false;
      window._currentNotifOrder = order;
      window._currentNotifOrderId = parseInt(order.id, 10) || null;
      if (typeof window.startNewOrderRing === 'function') window.startNewOrderRing();
      if (typeof window.rgOrderAlert === 'function') window.rgOrderAlert(order);   // background: system notification + tab title
      paint();
      ov.classList.add('show');
      document.body.style.overflow = 'hidden';
      clearInterval(tick);
      tick = setInterval(function () { var a = document.getElementById('noAgo'); if (a && O) a.textContent = ago(when(O.created_at)); }, 30000);
      startWatch();
    },
    // k = 'prep' (preparing) or 'dlv' (delivery)
    setT: function (k, m) {
      if (k === 'dlv') dlv = dlv === m ? 0 : m; else prep = prep === m ? 0 : m;
      showPrep();
    },
    stepT: function (k, dir) {
      var cur = k === 'dlv' ? dlv : prep;
      // from an odd number, the first step lands on a multiple of 5
      var next = dir > 0 ? (Math.floor(cur / T_STEP) + 1) * T_STEP : (Math.ceil(cur / T_STEP) - 1) * T_STEP;
      next = clampT(next);
      if (k === 'dlv') dlv = next; else prep = next;
      showPrep();
    },
    typeT: function (k, v) {
      var n = clampT(v);
      if (k === 'dlv') dlv = n; else prep = n;
      showPrep(k);
    },
    // kept for anything still calling the old names
    prep: function (m) { RGNewOrder.setT('prep', m); },
    prepCustom: function (v) { RGNewOrder.typeT('prep', v); },
    later: function (page) {
      var num = O ? (O.order_number || O.id) : '';
      if (O) tell(O.id, 'later');
      close();
      if (num) notify('Order #' + num + ' is waiting in Online Orders', 'info');
      if (page) go(page);
    },
    accept: function () {
      if (!O || busy) return;
      var o = O, id = parseInt(o.id, 10), minutes = prep, dmin = isDelivery(o) ? dlv : 0;
      window._overlayProcessing = true;
      var km = /deliver/i.test(o.order_type || '') && (window.enableKmDelivery == 1 || window.enableKmDelivery === true);
      var done = function (d) {
        window._overlayProcessing = false;
        if (d && d.cancelled) return;
        notify(d && d.success ? 'Order #' + (o.order_number || id) + ' accepted' + (minutes ? ' · ready in ' + minutes + ' min' : '') + (dmin ? ' · delivery ' + dmin + ' min' : '') : ((d && d.message) || 'Could not accept the order'), d && d.success ? 'success' : 'error');
        if (d && d.success) { tell(id, 'accepted'); reloadLists(); go('onlineOrdersPage'); }
      };
      if (km && typeof window.acceptOrderWithDeliveryCharge === 'function') {
        // the delivery-charge prompt needs the popup out of the way
        close();
        window.acceptOrderWithDeliveryCharge(id, o.order_type, o.address_lat, o.address_lng, minutes, dmin).then(done).catch(function () { done({ success: false, message: 'No connection. Please try again.' }); });
        return;
      }
      setBusy('accept');
      var body = { orderId: id, status: 'Accepted' };
      if (minutes) body.prep_minutes = minutes;
      if (dmin) body.delivery_minutes = dmin;
      post(body).then(function (d) {
        if (d.success) close();
        else { setBusy(false); paint(); }
        done(d);
      });
    },
    reject: function () {
      if (!O || busy) return;
      var o = O;
      if (typeof window.stopNewOrderRing === 'function') window.stopNewOrderRing();   // quiet while choosing
      RGReason.open({ mode: 'reject', order: o }).then(function (r) {
        if (!r || O !== o) return;
        window._overlayProcessing = true;
        setBusy('reject');
        post({ orderId: o.id, status: 'Rejected', reason: r.text, reason_code: r.code, strike: r.strike ? 1 : '' }).then(function (d) {
          window._overlayProcessing = false;
          if (d.success) { tell(o.id, 'rejected'); close(); notify('Order #' + (o.order_number || o.id) + ' rejected: ' + r.text, 'info'); reloadLists(); go('onlineOrdersPage'); }
          else { setBusy(false); paint(); notify(d.message || 'Could not reject the order', 'error'); }
        });
      });
    },
    close: function () { close(); }
  };
  window.RGNewOrder = RGNewOrder;

  /* ───────────── reject / cancel everywhere else asks why too ───────────── */
  function askAndSet(orderId, status, btn, number) {
    return RGReason.open({ mode: status === 'Rejected' ? 'reject' : 'cancel', orderId: orderId, number: number }).then(function (r) {
      if (!r) return;
      if (btn) btn.disabled = true;
      return post({ orderId: orderId, status: status, reason: r.text, reason_code: r.code, strike: r.strike ? 1 : '' }).then(function (d) {
        if (btn) btn.disabled = false;
        if (d.success) { notify('Order ' + (status === 'Rejected' ? 'rejected' : 'cancelled') + ': ' + r.text, 'info'); reloadLists(); }
        else notify(d.message || 'Could not update the order', 'error');
      });
    });
  }
  window.rejectOnlineOrder = function (orderId, orderNumber, btn) { return askAndSet(orderId, 'Rejected', btn, orderNumber); };
  var origUpdate = window.updateOrderStatus;
  if (typeof origUpdate === 'function') {
    window.updateOrderStatus = function (orderId, status, btn) {
      if (status === 'Cancelled') return askAndSet(orderId, 'Cancelled', btn);
      return origUpdate.apply(this, arguments);
    };
  }
})();
