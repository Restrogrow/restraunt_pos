/*
 * POS offline billing. When the internet drops, the POS keeps working:
 *
 *  - Data on this device: the last menu, categories, tables, add-ons and session info are kept
 *    (localStorage, per restaurant) every time they load; with no internet those copies are used
 *    (the menu is filtered here when a filter was never loaded online).
 *  - Bills: script.js sends Pay and KOT through window.rgPosSend(). With no connection the bill goes
 *    into a queue on this device with a unique reference, the time, and a local number (OFF-…);
 *    the bill prints as usual and the kitchen gets a printed ticket made here.
 *  - Sync: when the connection is back (and every 30 s, or "Sync now") queued bills are sent in
 *    order. The server saves each reference once (pos_sync_log), keeps the bill's offline time and
 *    marks it [Offline bill]. A KOT made offline is matched to its real order before its Pay is sent.
 *    A bill the server refuses (a price changed, an item was removed) stays as "needs attention":
 *    Retry, Open in cart (to fix and bill again) or Discard.
 *  - Options (the sync panel): Sync now, sync automatically on/off, refresh the saved menu.
 *  - Customer points, wallet and rewards need the internet; offline the customer still goes on the
 *    bill and earns when it syncs.
 * Styles po-* in assets/css/studio-ui.css. Server: controllers/pos_operations.php (pos_ref, offline_at).
 */
(function () {
  'use strict';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var money = function (n) { return (window.globalCurrencySymbol || '₹') + Number(n || 0).toFixed(2); };
  var say = function (m, t) { if (window.SX && SX.notify) SX.notify(m, t); else if (window.showNotification) showNotification(m, t); };
  var RID = ((document.getElementById('restaurantId') || {}).textContent || '').trim() || 'x';
  var K = function (k) { return 'rgoff:' + RID + ':' + k; };
  var get = function (k, d) { try { var v = localStorage.getItem(K(k)); return v ? JSON.parse(v) : d; } catch (e) { return d; } };
  var put = function (k, v) { try { localStorage.setItem(K(k), JSON.stringify(v)); return true; } catch (e) { return false; } };
  var lastFail = 0, syncing = false;

  /* ── data kept on this device ── */
  var CACHE = [/\/api\/get_menu_items\.php/, /\/api\/get_menus\.php/, /\/api\/get_tables\.php/, /\/api\/get_addons\.php/, /\/admin\/get_session\.php/, /\/api\/get_categories\.php/];
  function keyOf(url) {
    var u = new URL(url, location.href), q = [];
    u.searchParams.forEach(function (v, k) { if (k !== '_' && k !== 't') q.push(k + '=' + v); });
    return 'data:' + u.pathname.replace(/^.*\/(api|admin)\//, '$1/') + (q.length ? '?' + q.sort().join('&') : '');
  }
  function fromCache(url) {
    var hit = get(keyOf(url), null);
    if (hit) return hit.body;
    // a menu filter never loaded online: filter the full menu here
    if (/get_menu_items\.php/.test(url)) {
      var u = new URL(url, location.href), all = null;
      ['data:api/get_menu_items.php', 'data:api/get_menu_items.php?limit=200&page=1'].some(function (k) { all = get(k, null); return !!all; });
      if (!all) return null;
      try {
        var d = JSON.parse(all.body), list = d.data || [];
        var m = u.searchParams.get('menu'), ty = u.searchParams.get('type');
        if (m) list = list.filter(function (i) { return String(i.menu_id) === String(m); });
        if (ty) list = list.filter(function (i) { return String(i.item_type) === ty; });
        d.data = list;
        return JSON.stringify(d);
      } catch (e) { return null; }
    }
    return null;
  }
  var realFetch = window.fetch;
  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    var method = ((init && init.method) || (input && input.method) || 'GET').toUpperCase();
    if (method !== 'GET' || !CACHE.some(function (r) { return r.test(url); })) return realFetch.apply(this, arguments);
    return realFetch.apply(this, arguments).then(function (resp) {
      if (resp.ok) resp.clone().text().then(function (body) { if (body && body.charAt(0) === '{' && body.indexOf('"success":false') < 0) put(keyOf(url), { at: Date.now(), body: body }); }).catch(function () {});
      return resp;
    }).catch(function (err) {
      lastFail = Date.now(); paint();
      var body = fromCache(url);
      if (body) return new Response(body, { status: 200, headers: { 'Content-Type': 'application/json', 'X-RG-Offline': '1' } });
      throw err;
    });
  };

  function isOffline() { return navigator.onLine === false || Date.now() - lastFail < 15000; }

  /* ── the queue ── */
  function queue() { return get('queue', []); }
  function saveQueue(q) { put('queue', q); paint(); }
  function refmap() { return get('refmap', {}); }
  function uuid() { return 'off-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10); }
  function localNumber() {
    var n = (get('counter', 0) || 0) + 1; put('counter', n);
    var d = new Date();
    return 'OFF-' + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0') + '-' + String(n).padStart(3, '0');
  }

  function enqueue(kind, body, meta) {
    var ref = uuid(), entry = { ref: ref, kind: kind, at: Date.now(), number: (body.get('openOrderId') || '').indexOf('local:') === 0 ? '' : localNumber(), status: 'waiting', error: '', total: meta.total || 0,
      table: meta.tableLabel || '', cart: meta.cart || [] };
    var open = body.get('openOrderId') || '';
    if (open.indexOf('local:') === 0) {
      entry.openRef = open.slice(6); body.delete('openOrderId');
      var first = queue().filter(function (x) { return x.ref === entry.openRef; })[0];
      if (first) entry.number = first.number;   // the same bill: keep its number
    }
    if (!entry.number) entry.number = localNumber();
    // the kitchen gets a ticket printed here now, so none is made on the KOT screen when it syncs:
    // what the kitchen doesn't have yet (kitchenItems), or the whole cart for a bill paid straight away
    var kitchen = null;
    try { kitchen = body.has('kitchenItems') ? JSON.parse(body.get('kitchenItems') || '[]') : null; } catch (e) {}
    var addonsToo = !body.has('kitchenItems') || body.get('kitchenAddons') === '1';
    if (!kitchen) kitchen = (meta.cart || []).filter(function (i) { return !i.isAddon; }).map(function (i) { return { name: i.name, quantity: i.quantity, addons: i.addons || [] }; });
    var names = {}; (meta.cart || []).forEach(function (c) { names[c.id] = c; });
    kitchen = kitchen.map(function (k) { var c = names[k.id] || {}; return { name: k.name || c.name || 'Item', quantity: k.quantity, addons: (k.addons || []).map(function (a) { return { name: a.name || ((c.addons || []).filter(function (x) { return String(x.id) === String(a.id); })[0] || {}).name || '' }; }) }; });
    if (addonsToo) (meta.cart || []).filter(function (i) { return i.isAddon; }).forEach(function (i) { kitchen.push({ name: i.name, quantity: i.quantity, extra: true }); });
    body.set('kitchenItems', '[]'); body.delete('kitchenAddons');
    body.set('pos_ref', ref);
    body.set('offline_at', new Date(entry.at).toISOString());
    entry.body = body.toString();
    var q = queue(); q.push(entry); saveQueue(q);
    return { success: true, offline: true, ref: ref, order_id: 'local:' + ref, order_number: entry.number, kot_id: null, kot_number: null, loyalty: null,
      localKot: kitchen };
  }

  async function post(body) {
    var resp = await realFetch('../controllers/pos_operations.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body });
    var text = await resp.text(), data = null;
    try { data = JSON.parse(text); } catch (e) {}
    if (!resp.ok && !data) throw new Error('Server error (' + resp.status + ')');
    if (!data) throw new Error('Invalid response from server');
    return data;
  }

  /** Pay / KOT from script.js: online as before; no connection, or a bill that waits for an offline one: queued. */
  window.rgPosSend = async function (kind, formData, meta) {
    meta = meta || {};
    var body = new URLSearchParams(formData.toString());
    var open = body.get('openOrderId') || '';
    if (open.indexOf('local:') === 0) {
      var real = refmap()[open.slice(6)];
      if (real) body.set('openOrderId', real);
      else return enqueue(kind, body, meta);   // its open bill hasn't synced yet: keep the order of things
    }
    if (navigator.onLine === false) return enqueue(kind, body, meta);
    try {
      var r = await post(body);
      lastFail = 0;
      if (queue().length) setTimeout(sync, 500);
      return r;
    } catch (e) {
      if (e instanceof TypeError || /Failed to fetch|NetworkError|Load failed/i.test(e.message)) { lastFail = Date.now(); return enqueue(kind, body, meta); }
      throw e;
    }
  };

  /* ── sync ── */
  async function sync(manual) {
    if (syncing) return;
    var q = queue();
    if (!q.length) { if (manual) say('Nothing to sync. Every bill is on the server.', 'success'); return; }
    if (navigator.onLine === false) { if (manual) say('Still offline. Bills will sync when the internet is back.', 'info'); return; }
    syncing = true; paint();
    var done = 0, failed = 0;
    for (var i = 0; i < q.length; i++) {
      var e = q[i];
      if (e.status === 'failed' && !manual) continue;
      var body = new URLSearchParams(e.body);
      if (e.openRef) {
        var real = refmap()[e.openRef];
        if (!real) { e.status = 'waiting'; e.error = 'Waiting for its KOT to sync first'; continue; }
        body.set('openOrderId', real);
      }
      e.status = 'syncing'; saveQueue(q);
      try {
        var r = await post(body);
        if (r && r.success) {
          if (r.order_id) { var m = refmap(); m[e.ref] = r.order_id; put('refmap', m); }
          var hist = get('history', []); hist.unshift({ number: e.number, order: r.order_number, total: e.total, at: e.at, synced: Date.now() }); put('history', hist.slice(0, 30));
          q.splice(i, 1); i--; done++;
        } else {
          e.status = 'failed'; e.error = (r && r.message) || 'The server refused this bill'; failed++;
        }
      } catch (err) {
        e.status = 'waiting'; e.error = 'No connection';
        lastFail = Date.now();
        break;   // still offline: try again later, in the same order
      }
      saveQueue(q);
    }
    saveQueue(q);
    syncing = false; paint();
    if (done) say(done + ' offline bill' + (done === 1 ? '' : 's') + ' synced', 'success');
    if (failed) say(failed + ' offline bill' + (failed === 1 ? ' needs' : 's need') + ' attention. Open the sync panel.', 'error');
    if (done && typeof window.loadOrders === 'function' && document.getElementById('ordersPage') && document.getElementById('ordersPage').classList.contains('active')) window.loadOrders();
  }

  /* ── the kitchen ticket made on this device ── */
  function printLocalKot(items, number, table, then) {
    var rows = (items || []).map(function (it) {
      return '<tr><td style="width:36px;font-weight:800;font-size:15px;vertical-align:top;">' + (it.quantity || 1) + '</td><td style="padding:4px 0;font-size:14px;font-weight:700;">' + esc(it.name) + (it.extra ? ' <small>(add-on)</small>' : '') +
        ((it.addons || []).length ? '<div style="font-size:12px;color:#b45309;">+ ' + it.addons.map(function (a) { return esc(a.name || ''); }).join(', ') + '</div>' : '') + '</td></tr>';
    }).join('');
    var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>KOT ' + esc(number) + '</title><style>' + (window.getThermalReceiptCSS ? window.getThermalReceiptCSS() : 'body{font-family:monospace;width:72mm}') +
      ' table{width:100%;border-collapse:collapse}td{border-bottom:1px dashed #ccc}</style></head><body><div style="text-align:center;font-weight:900;font-size:22px;">KOT</div>' +
      '<div style="text-align:center;font-size:12px;">' + esc(number) + ' · ' + esc(table || 'Takeaway') + ' · ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + '</div>' +
      '<div style="text-align:center;font-size:11px;margin:4px 0 8px;padding:3px;border:1px dashed #000;">Made offline. It shows in the system once synced.</div><table>' + rows + '</table></body></html>';
    if (window.showPrintPreview) window.showPrintPreview('KOT ' + number, html, then); else if (then) then();
  }

  /* ── status pill and panel ── */
  function pill() {
    var head = document.querySelector('#posPage .page-header');   // beside "Point of Sale"
    if (!head) return null;
    var p = document.getElementById('poPill');
    if (!p) { p = document.createElement('button'); p.type = 'button'; p.id = 'poPill'; p.className = 'po-pill'; head.classList.add('po-head'); head.appendChild(p); }
    return p;
  }
  var wasOff = null;
  function paint() {
    var q = queue(), failed = q.filter(function (e) { return e.status === 'failed'; }).length, off = isOffline();
    // online <-> offline: the cart's discounts change (no points or wallet offline)
    if (wasOff !== null && wasOff !== off && window.rgPosCartApi) setTimeout(function () { window.rgPosCartApi.refresh(); }, 0);
    wasOff = off;
    var p = pill();
    if (p) {
      var cls = syncing ? 'sync' : failed ? 'bad' : off ? 'off' : q.length ? 'wait' : 'on';
      p.className = 'po-pill ' + cls;
      p.innerHTML = ic(syncing ? 'sync' : failed ? 'error' : off ? 'cloud_off' : q.length ? 'upload' : 'cloud') +
        (syncing ? 'Syncing…' : failed ? failed + ' need attention' : off ? 'Offline' + (q.length ? ' · ' + q.length + ' waiting' : '') : q.length ? q.length + ' to sync' : 'Online');
      p.title = 'Offline billing and sync';
    }
    var bar = document.getElementById('poBar');
    if (off) {
      if (!bar) { bar = document.createElement('div'); bar.id = 'poBar'; bar.className = 'po-bar'; document.body.appendChild(bar); }
      bar.innerHTML = ic('cloud_off') + '<span><b>You are offline.</b> The POS still bills; ' + (q.length ? q.length + ' bill' + (q.length === 1 ? '' : 's') + ' will sync' : 'bills sync') + ' when the internet is back.</span><button type="button" data-po-open>Details</button>';
    } else if (bar) bar.remove();
    var panel = document.getElementById('poPanel'); if (panel) panelBody(panel);
  }
  function ago(t) { var m = Math.round((Date.now() - t) / 60000); return m < 1 ? 'just now' : m < 60 ? m + ' min ago' : Math.round(m / 60) + ' h ago'; }
  function panelBody(el) {
    var q = queue(), hist = get('history', []), auto = get('auto', true), menuAt = (get('data:api/get_menu_items.php', null) || get('data:api/get_menu_items.php?limit=200&page=1', null) || {}).at;
    el.querySelector('.po-body').innerHTML =
      '<div class="po-state ' + (isOffline() ? 'off' : 'on') + '">' + ic(isOffline() ? 'cloud_off' : 'cloud') + '<span><b>' + (isOffline() ? 'Offline' : 'Online') + '</b>' + (isOffline() ? 'Bills are kept on this device and sync when the internet is back.' : 'Bills go straight to the server.') + '</span></div>' +
      '<div class="po-acts"><button type="button" class="po-btn" data-po-sync' + (q.length ? '' : ' disabled') + '>' + ic('sync') + 'Sync now</button>' +
      '<label class="po-sw"><input type="checkbox" data-po-auto' + (auto ? ' checked' : '') + '><i></i>Sync automatically</label></div>' +
      '<h4>Waiting to sync <em>' + q.length + '</em></h4>' +
      (q.length ? q.map(function (e) {
        return '<div class="po-row ' + e.status + '"><span class="po-n"><b>' + esc(e.number) + '</b><small>' + (e.kind === 'kot' ? 'KOT (open bill)' : 'Paid bill') + ' · ' + esc(e.table || 'Takeaway') + ' · ' + ago(e.at) + '</small>' +
          (e.error ? '<small class="po-err">' + ic(e.status === 'failed' ? 'error' : 'schedule') + esc(e.error) + '</small>' : '') + '</span><span class="po-t">' + money(e.total) + '</span>' +
          '<span class="po-ra">' + (e.status === 'failed' ? '<button type="button" data-po-retry="' + e.ref + '" title="Try again">' + ic('refresh') + '</button><button type="button" data-po-cart="' + e.ref + '" title="Open in the cart to fix and bill again">' + ic('shopping_cart') + '</button>' : '') +
          '<button type="button" data-po-del="' + e.ref + '" title="Discard">' + ic('delete') + '</button></span></div>';
      }).join('') : '<p class="po-hint">' + ic('check_circle') + 'Nothing waiting. Every bill is on the server.</p>') +
      (hist.length ? '<h4>Synced recently</h4>' + hist.slice(0, 6).map(function (h) { return '<div class="po-row done"><span class="po-n"><b>' + esc(h.number) + '</b><small>became ' + esc(h.order || '') + ' · ' + ago(h.synced) + '</small></span><span class="po-t">' + money(h.total) + '</span></div>'; }).join('') : '') +
      '<h4>Saved on this device</h4><p class="po-hint">' + ic('restaurant_menu') + 'Menu ' + (menuAt ? 'saved ' + ago(menuAt) : 'not saved yet: open the POS once while online') + '. <button type="button" class="po-link" data-po-refresh>Refresh now</button></p>';
  }
  function openPanel() {
    var ov = document.createElement('div'); ov.className = 'po-ov'; ov.id = 'poPanel';
    ov.innerHTML = '<div class="po-sheet" role="dialog" aria-modal="true" aria-label="Offline billing and sync"><div class="po-h"><b>Offline billing and sync</b><button type="button" class="po-x" aria-label="Close">' + ic('close') + '</button></div><div class="po-body"></div></div>';
    document.body.appendChild(ov); panelBody(ov);
    ov.addEventListener('click', function (e) { if (e.target === ov || e.target.closest('.po-x')) ov.remove(); });
  }

  document.addEventListener('click', function (e) {
    if (e.target.closest('#poPill') || e.target.closest('[data-po-open]')) { openPanel(); return; }
    if (e.target.closest('[data-po-sync]')) { sync(true); return; }
    if (e.target.closest('[data-po-refresh]')) {
      Promise.all(['../api/get_menu_items.php', '../api/get_menu_items.php?limit=200&page=1', '../api/get_menus.php', '../api/get_tables.php', '../api/get_addons.php?available=1'].map(function (u) { return window.fetch(u).catch(function () {}); }))
        .then(function () { say(isOffline() ? 'Offline: kept the menu you already have.' : 'Menu saved on this device', isOffline() ? 'info' : 'success'); paint(); });
      return;
    }
    var r = e.target.closest('[data-po-retry]');
    if (r) { var q = queue(); q.forEach(function (x) { if (x.ref === r.dataset.poRetry) { x.status = 'waiting'; x.error = ''; } }); saveQueue(q); sync(true); return; }
    var d = e.target.closest('[data-po-del]');
    if (d) {
      var ref = d.dataset.poDel;
      var ok = window.rgConfirm ? window.rgConfirm('This bill was never saved on the server. Discard it for good?', { title: 'Discard offline bill', confirmText: 'Discard', danger: true }) : Promise.resolve(confirm('Discard this offline bill?'));
      ok.then(function (yes) { if (!yes) return; saveQueue(queue().filter(function (x) { return x.ref !== ref; })); });
      return;
    }
    var c = e.target.closest('[data-po-cart]');
    if (c) {
      var api = window.rgPosCartApi, entry = queue().filter(function (x) { return x.ref === c.dataset.poCart; })[0];
      if (!api || !entry) return;
      if (api.get().length) { say('Finish or clear the current cart first.', 'error'); return; }
      // today's prices (a changed price is the usual reason a bill is refused): from the server, else this device's menu
      saveQueue(queue().filter(function (x) { return x.ref !== entry.ref; }));
      window.fetch('../api/get_menu_items.php').then(function (r) { return r.json(); }).catch(function () { return null; }).then(function (fresh) {
        var cart = JSON.parse(JSON.stringify(entry.cart || [])), menu = {}, list = fresh && fresh.data;
        if (!list) try { list = JSON.parse((get('data:api/get_menu_items.php', null) || get('data:api/get_menu_items.php?limit=200&page=1', null) || { body: '{}' }).body).data; } catch (err) {}
        (list || []).forEach(function (m) { menu[m.id] = m; });
        cart.forEach(function (l) { var m = menu[l.id]; if (m && !l.variationName && !l.isAddon && m.base_price != null) l.price = parseFloat(m.base_price); });
        api.set(cart);
        // its KOT already reached the server: paying settles that open bill (the kitchen has every dish)
        var real = entry.openRef && refmap()[entry.openRef];
        if (real && window.posMarkAllSent) { window.posOpenBill = { id: real, number: entry.number }; window.posMarkAllSent(cart); api.refresh(); }
        if (typeof window.loadPOSMenuItems === 'function') window.loadPOSMenuItems();
        var pn = document.getElementById('poPanel'); if (pn) pn.remove();
        if (window.showPage) window.showPage('posPage');
        say('Bill ' + entry.number + ' is back in the cart with current prices. Check it and bill again.', 'info');
      });
    }
  });
  document.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('[data-po-auto]')) { put('auto', e.target.checked); if (e.target.checked) sync(); } });

  window.addEventListener('online', function () { lastFail = 0; paint(); if (get('auto', true)) setTimeout(sync, 800); });
  window.addEventListener('offline', paint);
  setInterval(function () { paint(); if (get('auto', true) && queue().length && !isOffline()) sync(); }, 30000);

  window.rgPosOffline = { isOffline: isOffline, sync: sync, queue: queue, printLocalKot: printLocalKot, openPanel: openPanel };
  function init() { paint(); if (get('auto', true) && queue().length) setTimeout(sync, 3000); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
