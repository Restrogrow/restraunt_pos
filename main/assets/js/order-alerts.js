/*
 * New-order alerts that work when the dashboard is in the background.
 *
 *  - rgOrderAlert(order): called by the new-order popup (script.js). If the tab is hidden it
 *    shows a system notification (same tag as the server push, so you never get two) and
 *    flashes the tab title until you look.
 *  - Sound and notifications need one click from the owner (browser rules): a small
 *    "Turn on order alerts" chip appears until that's done, then disappears.
 *  - rgEnablePush() / rgDisablePush(): this device's push subscription (server side:
 *    api/save_push_subscription.php, sent by config/push_notification.php). Used by the chip
 *    and by Settings › Account › Push notifications.
 *  - Clicking a notification opens Online Orders (service worker posts 'rg-open-order').
 */
(function () {
  'use strict';
  var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  var titleBase = document.title, titleTimer = 0;

  function keyToBytes(k) {
    var pad = '='.repeat((4 - k.length % 4) % 4), b = atob((k + pad).replace(/-/g, '+').replace(/_/g, '/'));
    var out = new Uint8Array(b.length); for (var i = 0; i < b.length; i++) out[i] = b.charCodeAt(i); return out;
  }
  function swReady() { return navigator.serviceWorker.getRegistration('../').then(function (r) { return r || navigator.serviceWorker.ready; }); }

  /** Ask permission (if needed), subscribe this device and save it. Resolves true when on. */
  window.rgEnablePush = function () {
    if (!supported) return Promise.resolve(false);
    var ask = Notification.permission === 'granted' ? Promise.resolve('granted') : Notification.requestPermission();
    return ask.then(function (perm) {
      if (perm !== 'granted') return false;
      return swReady().then(function (reg) {
        return reg.pushManager.getSubscription().then(function (sub) {
          if (sub) return sub;
          return fetch('../api/get_vapid_key.php', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.publicKey) throw new Error('no key');
            return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyToBytes(d.publicKey) });
          });
        });
      }).then(function (sub) {
        return fetch('../api/save_push_subscription.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(sub.toJSON ? sub.toJSON() : sub) })
          .then(function (r) { return r.json(); }).then(function (d) { return !!d.success; });
      });
    }).catch(function (e) { console.warn('[alerts] push not set up:', e && e.message); return false; });
  };
  window.rgDisablePush = function () {
    if (!supported) return Promise.resolve();
    return swReady().then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) { return sub && sub.unsubscribe(); }).catch(function () {});
  };
  window.rgPushState = function () {
    if (!supported) return Promise.resolve('unsupported');
    if (Notification.permission === 'denied') return Promise.resolve('blocked');
    return swReady().then(function (reg) { return reg.pushManager.getSubscription(); })
      .then(function (sub) { return sub && Notification.permission === 'granted' ? 'on' : 'off'; }).catch(function () { return 'off'; });
  };

  /* ── when a new order arrives ── */
  function flashTitle(text) {
    clearInterval(titleTimer);
    var on = false;
    titleTimer = setInterval(function () { on = !on; document.title = on ? text : titleBase; }, 1000);
  }
  function stopTitle() { clearInterval(titleTimer); titleTimer = 0; document.title = titleBase; }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) stopTitle(); });
  window.addEventListener('focus', stopTitle);

  window.rgOrderAlert = function (order) {
    if (!order) return;
    var num = order.order_number || order.id;
    if (!document.hidden && document.hasFocus()) return;   // the popup and the ring are enough
    flashTitle('New order #' + num);
    if (!supported || Notification.permission !== 'granted') return;
    var cur = window.globalCurrencySymbol || '₹';
    var type = String(order.order_type || '').replace(/_/g, ' ');
    var body = cur + parseFloat(order.total || order.subtotal || 0).toFixed(0) + (type ? ' · ' + type : '') + (order.customer_name ? ' · ' + order.customer_name : '');
    swReady().then(function (reg) {
      return reg.showNotification('New order #' + num, {
        body: body, tag: 'order-' + order.id, renotify: true, requireInteraction: true,
        icon: '../assets/images/logo-transparent.png', badge: '../assets/images/logo-transparent.png', vibrate: [200, 100, 200],
        data: { url: '../views/dashboard.php', orderId: order.id },
        actions: [{ action: 'view', title: 'View order' }, { action: 'dismiss', title: 'Dismiss' }],
      });
    }).catch(function () {});
  };

  // the popup closing (accepted / rejected) also stops the title flashing
  var close0 = window.closeNewOrderOverlay;
  Object.defineProperty(window, 'closeNewOrderOverlay', {
    configurable: true,
    get: function () { return function () { stopTitle(); return close0 && close0.apply(this, arguments); }; },
    set: function (f) { close0 = f; },
  });

  // clicking a notification: open Online Orders
  function openOrders() { if (typeof window.showPage === 'function') window.showPage('onlineOrdersPage'); }
  if ('serviceWorker' in navigator) navigator.serviceWorker.addEventListener('message', function (e) {
    if (e.data && e.data.type === 'rg-open-order') { stopTitle(); openOrders(); }
    // other notifications open their own section (a low-stock alert opens Inventory)
    if (e.data && e.data.type === 'rg-open-page' && /^[a-zA-Z]+Page$/.test(e.data.page || '') && document.getElementById(e.data.page) && typeof window.showPage === 'function') window.showPage(e.data.page);
  });
  if (/[?&]open_order=/.test(location.search)) setTimeout(openOrders, 600);

  /* ── one-time setup chip: sound needs a click, notifications need permission ── */
  var soundReady = false;
  ['click', 'keydown', 'touchstart'].forEach(function (ev) { document.addEventListener(ev, function () { soundReady = true; refreshChip(); }, { once: true, capture: true }); });
  var chip = null;
  function refreshChip() {
    var needPerm = supported && Notification.permission === 'default';
    var show = !soundReady || needPerm;
    try { if (localStorage.getItem('rg_alerts_chip_closed') === '1' && !needPerm) show = false; } catch (e) {}
    if (!show) { if (chip) { chip.remove(); chip = null; } return; }
    if (chip) return;
    chip = document.createElement('div');
    chip.className = 'rg-alert-chip';
    chip.setAttribute('role', 'status');
    chip.innerHTML = '<span class="material-symbols-rounded">notifications_active</span><span><b>Turn on order alerts</b><small>Ring and notify me about new orders, even in another tab</small></span>' +
      '<button type="button" class="rg-alert-on">Turn on</button><button type="button" class="rg-alert-x" aria-label="Not now"><span class="material-symbols-rounded">close</span></button>';
    document.body.appendChild(chip);
    chip.querySelector('.rg-alert-on').addEventListener('click', function () {
      soundReady = true;
      window.rgEnablePush().then(function (ok) {
        if (typeof window.showNotification === 'function') window.showNotification(ok ? 'Order alerts are on for this device' : 'Sound is on. Notifications were not allowed; you can allow them in the browser’s site settings.', ok ? 'success' : 'info');
        var t = document.getElementById('notifications'); if (t && ok) t.checked = true;
        if (chip) { chip.remove(); chip = null; }
      });
    });
    chip.querySelector('.rg-alert-x').addEventListener('click', function () {
      try { localStorage.setItem('rg_alerts_chip_closed', '1'); } catch (e) {}
      if (chip) { chip.remove(); chip = null; }
    });
  }

  /* ── Settings › Account › Push notifications: really turns them on / off for this device ── */
  function syncToggle() {
    var t = document.getElementById('notifications');
    if (!t) return;
    window.rgPushState().then(function (st) {
      t.checked = st === 'on';
      t.disabled = st === 'unsupported';
      var row = t.closest('.st-toggle');
      var small = row && row.querySelector('small');
      if (!small && row) { small = document.createElement('small'); row.querySelector('span').appendChild(small); }
      if (small) small.textContent = st === 'blocked' ? 'Blocked in this browser. Allow notifications in the site settings, then switch it on.' : st === 'unsupported' ? 'This browser can’t show notifications.' : 'New orders show up on this device even when the dashboard is in the background.';
    });
  }
  document.addEventListener('change', function (e) {
    if (e.target.id !== 'notifications') return;
    var t = e.target;
    if (t.checked) window.rgEnablePush().then(function (ok) { if (!ok) t.checked = false; syncToggle(); if (typeof window.showNotification === 'function') window.showNotification(ok ? 'Notifications are on for this device' : 'Notifications were not allowed', ok ? 'success' : 'error'); });
    else window.rgDisablePush().then(syncToggle);
  }, true);

  function start() { refreshChip(); syncToggle(); setTimeout(syncToggle, 1500); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
