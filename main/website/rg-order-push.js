/*
 * "Get updates on this phone": push notifications for one order (api/customer_push.php,
 * config/customer_push.php). Used on the tracking page and the "Order placed" box.
 *
 *   <div data-rg-push data-order="ORD-…" data-phone="98…" data-sw="…/sw.php" data-scope="/…/"
 *        data-icon="logo address" data-page="tracking page address"></div>
 *   RGOrderPush.mount(el)      draws the card into el (done by itself for [data-rg-push] on load)
 *
 * A phone that already allowed notifications is added without asking again. Hidden where the
 * browser can't do it (iPhone outside the home-screen app) or the customer said no before.
 */
(function () {
  'use strict';

  var API = '../api/customer_push.php';
  var BELL = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>';
  var TICK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>';

  function supported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && window.isSecureContext !== false;
  }

  function css() {
    if (document.getElementById('rgPushCss')) return;
    var s = document.createElement('style');
    s.id = 'rgPushCss';
    s.textContent =
      '.rgp{--c:var(--tk,var(--primary-red,#e23744));display:flex;align-items:center;gap:12px;padding:12px 14px;margin:0 0 14px;border-radius:16px;background:#fff;border:1px solid rgba(17,24,39,.08);box-shadow:0 2px 10px rgba(17,24,39,.05);text-align:left;font-family:inherit}' +
      '.rgp .ic{flex:none;width:38px;height:38px;border-radius:12px;display:grid;place-items:center;color:var(--c);background:color-mix(in srgb,var(--c) 12%,#fff)}' +
      '.rgp .ic svg{width:20px;height:20px}' +
      '.rgp .tx{flex:1;min-width:0;line-height:1.3}' +
      '.rgp .tx b{display:block;font-size:13.5px;color:#111827}' +
      '.rgp .tx small{display:block;font-size:11.5px;color:#6b7280;margin-top:2px}' +
      '.rgp button{flex:none;border:0;border-radius:999px;padding:9px 14px;font:600 12.5px/1 inherit;font-family:inherit;color:#fff;background:var(--c);cursor:pointer}' +
      '.rgp button:disabled{opacity:.6;cursor:default}' +
      '.rgp.on .ic{color:#16a34a;background:#dcfce7}' +
      '.rgp.on{padding:10px 14px}' +
      '.rgp[hidden]{display:none}';
    document.head.appendChild(s);
  }

  function b64ToBytes(b64) {
    var pad = '='.repeat((4 - b64.length % 4) % 4);
    var raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }

  function registration(sw, scope) {
    return navigator.serviceWorker.getRegistration(scope || undefined).then(function (reg) {
      if (reg) return reg;
      return navigator.serviceWorker.register(sw, scope ? { scope: scope } : undefined);
    }).then(function () { return navigator.serviceWorker.ready; });
  }

  function subscription(reg) {
    return reg.pushManager.getSubscription().then(function (sub) {
      if (sub) return sub;
      return fetch('../api/get_vapid_key.php', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.publicKey) throw new Error('no key');
        return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(d.publicKey) });
      });
    });
  }

  function abs(u) { try { return u ? new URL(u, document.baseURI).href : ''; } catch (e) { return ''; } }

  // asks only when ask is true (a tap); otherwise uses permission already given
  function enable(o, ask) {
    var perm = ask ? Notification.requestPermission() : Promise.resolve(Notification.permission);
    return Promise.resolve(perm).then(function (p) {
      if (p !== 'granted') throw new Error(p);
      return registration(o.sw, o.scope);
    }).then(subscription).then(function (sub) {
      return fetch(API, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order_number: o.order, customer_phone: o.phone, subscription: sub.toJSON(), page_url: abs(o.page) || location.href, icon_url: abs(o.icon) })
      }).then(function (r) { return r.json(); });
    }).then(function (d) {
      if (!d || !d.success) throw new Error((d && d.message) || 'failed');
      return d;
    });
  }

  function draw(el, state) {
    el.className = 'rgp' + (state === 'on' ? ' on' : '');
    el.innerHTML = state === 'on'
      ? '<span class="ic">' + TICK + '</span><span class="tx"><b>Order updates are on</b><small>We\'ll notify this phone as your order moves along.</small></span>'
      : '<span class="ic">' + BELL + '</span><span class="tx"><b>Get updates on this phone</b><small>Know when it\'s accepted, ready and on the way, even with this page closed.</small></span><button type="button">' + (state === 'busy' ? 'Turning on…' : 'Turn on') + '</button>';
    var b = el.querySelector('button');
    if (b) {
      b.disabled = state === 'busy';
      b.onclick = function () {
        draw(el, 'busy');
        enable(el._rgp, true).then(function (d) {
          if (d.finished) { el.hidden = true; return; }
          draw(el, 'on');
        }).catch(function (e) {
          // said no: nothing more to offer; anything else: let them try again
          if (Notification.permission === 'denied') { el.hidden = true; return; }
          draw(el, 'off');
          if (window.RGNotify && RGNotify.toast) RGNotify.toast('Couldn\'t turn on notifications. Please try again.', 'error');
        });
      };
    }
  }

  function mount(el) {
    if (!el || el._rgp) return;
    var o = { order: el.getAttribute('data-order'), phone: el.getAttribute('data-phone'), sw: el.getAttribute('data-sw'),
              scope: el.getAttribute('data-scope'), icon: el.getAttribute('data-icon'), page: el.getAttribute('data-page') };
    el._rgp = o;
    if (!supported() || !o.order || !o.phone || !o.sw || Notification.permission === 'denied') { el.hidden = true; return; }
    css();
    if (Notification.permission === 'granted') {
      // already allowed on this phone: add this order quietly
      draw(el, 'on');
      enable(o, false).then(function (d) { if (d.finished) el.hidden = true; }).catch(function () { draw(el, 'off'); });
    } else {
      draw(el, 'off');
    }
    el.hidden = false;
  }

  window.RGOrderPush = { mount: mount, supported: supported };

  function auto() { Array.prototype.forEach.call(document.querySelectorAll('[data-rg-push]'), mount); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', auto); else auto();
})();
