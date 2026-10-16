/**
 * Waiter Requests (dashboard › Waiter Requests): a live board of tables waiting, oldest first, coloured by
 * how long they've waited, with today's attended requests and response times.
 * Data: api/waiter_requests_overview.php, refreshed every 15 s while the page is open.
 * Mark attended reuses window.markAttended (script.js). Styles: wrq-* in studio-ui.css.
 */
(function () {
  'use strict';
  var D = null, base = 0, area = '';
  var esc = function (s) { return window.SX ? SX.esc(s) : String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  function dur(s) {
    if (s == null) return '—';
    s = Math.max(0, Math.round(s));
    if (s < 60) return s + 's';
    var m = Math.floor(s / 60);
    return m < 60 ? m + 'm ' + String(s % 60).padStart(2, '0') + 's' : Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
  }
  function heat(s) { return s >= 480 ? 'hot' : s >= 180 ? 'warm' : 'ok'; }
  function kind(r) {
    var order = r.request_type === 'Order' || /Order request:/.test(r.notes || '');
    if (order) return ['receipt_long', 'Wants to order', 'order'];
    // calls from the customer website say what they need
    if (r.request_type === 'Bill') return ['payments', 'Wants the bill', 'call'];
    if (r.request_type === 'Water') return ['water_drop', 'Wants water', 'call'];
    if (r.request_type === 'Cutlery') return ['restaurant', 'Cutlery or napkins', 'call'];
    return ['room_service', 'Calling a waiter', 'call'];
  }

  // "Customers can call a waiter from your website": the switch at the top of the page
  function websiteSwitch() {
    var w = D.website_calls;
    if (!w) return '';
    var hint = !w.dinein ? 'Dine-in is off, so the button is hidden on your website.'
      : !w.tables ? 'Add your tables first: customers pick theirs, or it comes from the table QR code.'
      : w.enabled ? 'A “Call waiter” button shows on your website for guests at a table. Calls appear here with a sound.'
      : 'Switched off: guests won’t see a “Call waiter” button on your website.';
    return '<label class="sx-switch-row wrq-switch"><span><b>' + ic('room_service') + 'Guests can call a waiter from your website</b><small>' + esc(hint) + '</small></span>' +
      '<span class="up-switch"><input type="checkbox" id="wrqWebSwitch"' + (w.enabled ? ' checked' : '') + (w.can_edit ? '' : ' disabled title="Only the owner can change this"') + '><span></span></span></label>';
  }

  function load() {
    var page = document.getElementById('waiterRequestsPage');
    if (!page) return;
    var box = document.getElementById('wrqRoot');
    if (!box) {
      box = document.createElement('div'); box.id = 'wrqRoot';
      var old = document.getElementById('waiterRequestsList');
      if (old) { old.style.display = 'none'; old.parentNode.insertBefore(box, old); } else page.querySelector('.page-content').appendChild(box);
    }
    if (!D) box.innerHTML = '<div class="cbx-loading"><span></span><span></span><span></span></div>';
    fetch('../api/waiter_requests_overview.php', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.success) { box.innerHTML = '<div class="sx-note warn">' + ic('warning') + '<span>' + esc(d.message || 'Could not load requests') + '</span></div>'; return; }
      D = d; base = Date.now(); draw();
    }).catch(function () { if (!D) box.innerHTML = '<div class="sx-note warn">' + ic('warning') + '<span>No connection. Retrying…</span></div>'; });
  }

  function draw() {
    var box = document.getElementById('wrqRoot');
    if (!box || !D) return;
    var s = D.stats, areas = {};
    D.waiting.forEach(function (r) { var a = r.area_name || 'No area'; areas[a] = (areas[a] || 0) + 1; });
    var list = D.waiting.filter(function (r) { return !area || (r.area_name || 'No area') === area; });
    box.innerHTML = websiteSwitch() +
      '<div class="sx-stats wrq-stats">' +
        '<div class="' + (s.waiting ? 'bad' : 'ok') + '">' + ic(s.waiting ? 'notifications_active' : 'notifications_off') + '<b>' + s.waiting + '</b><small>Waiting now</small></div>' +
        '<div class="' + (s.oldest >= 480 ? 'bad' : s.oldest >= 180 ? 'wait' : '') + '">' + ic('timer') + '<b data-wrq-oldest>' + (s.waiting ? dur(s.oldest) : '—') + '</b><small>Longest wait</small></div>' +
        '<div>' + ic('check_circle') + '<b>' + s.attended_today + '</b><small>Attended today</small></div>' +
        '<div>' + ic('speed') + '<b>' + dur(s.avg_response) + '</b><small>Average response today</small></div>' +
      '</div>' +
      (Object.keys(areas).length > 1 ? '<div class="cbx-chips wrq-areas"><button type="button" class="cbx-chip' + (!area ? ' on' : '') + '" data-wrq-area="">All <em>' + s.waiting + '</em></button>' +
        Object.keys(areas).sort().map(function (a) { return '<button type="button" class="cbx-chip' + (area === a ? ' on' : '') + '" data-wrq-area="' + esc(a) + '">' + esc(a) + ' <em>' + areas[a] + '</em></button>'; }).join('') + '</div>' : '') +
      (list.length ? '<div class="wrq-grid">' + list.map(card).join('') + '</div>' :
        '<div class="wrq-calm"><span class="wrq-calm-ic">' + ic('done_all') + '</span><h3>All tables are looked after</h3><p>New calls from tables appear here straight away, with a timer.</p></div>') +
      '<div class="wrq-done-h"><b>' + ic('history') + 'Attended today</b><small>' + D.attended.length + '</small></div>' +
      (D.attended.length ? '<div class="wrq-done">' + D.attended.map(function (r) {
        var k = kind(r);
        return '<div class="wrq-drow"><span class="wrq-tbl sm">' + esc(r.table_number || '—') + '</span><span class="wrq-dk">' + ic(k[0]) + esc(k[1]) + '<small>' + esc(r.area_name || '') + '</small></span>' +
          '<span class="wrq-resp ' + (r.response == null ? '' : heat(r.response)) + '">' + ic('timer') + dur(r.response) + '</span><span class="wrq-at">' + new Date(String(r.attended_at).replace(' ', 'T')).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) + '</span></div>';
      }).join('') + '</div>' : '<p class="sx-muted wrq-none">Nothing attended yet today.</p>');
  }

  function card(r, i) {
    var k = kind(r), h = heat(r.waited);
    var items = (r.notes || '').replace(/^Order request:\s*/i, '');
    return '<article class="wrq-card ' + h + ' ' + k[2] + '" style="--d:' + Math.min(i, 12) * 50 + 'ms" data-wrq-id="' + r.id + '">' +
      '<div class="wrq-top"><span class="wrq-tbl">' + esc(r.table_number || '—') + '</span><div class="wrq-what"><b>' + ic(k[0]) + esc(k[1]) + '</b><small>' + esc(r.area_name || 'No area') + '</small></div>' +
        '<span class="wrq-timer" data-wrq-waited="' + r.waited + '">' + ic('timer') + dur(r.waited) + '</span></div>' +
      (k[2] === 'order' && items ? '<p class="wrq-note">' + esc(items.length > 140 ? items.slice(0, 140) + '…' : items) + '</p>' : (r.notes && k[2] !== 'order' ? '<p class="wrq-note">' + esc(r.notes) + '</p>' : '')) +
      '<div class="wrq-acts"><button type="button" class="btn btn-primary btn-sm wrq-go" data-wrq-done="' + r.id + '">' + ic('check') + 'Attended</button>' +
        (k[2] === 'order' ? '<button type="button" class="btn btn-secondary btn-sm" data-wrq-order="' + r.id + '">' + ic('receipt_long') + 'See order</button>' : '') + '</div></article>';
  }

  // timers tick between refreshes
  setInterval(function () {
    if (!D || !document.getElementById('wrqRoot')) return;
    var add = (Date.now() - base) / 1000;
    document.querySelectorAll('[data-wrq-waited]').forEach(function (el) {
      var s = +el.getAttribute('data-wrq-waited') + add;
      el.innerHTML = ic('timer') + dur(s);
      var c = el.closest('.wrq-card'); if (c) { c.classList.remove('ok', 'warm', 'hot'); c.classList.add(heat(s)); }
    });
    var o = document.querySelector('[data-wrq-oldest]');
    if (o && D.stats.waiting) o.textContent = dur(D.stats.oldest + add);
  }, 1000);
  setInterval(function () { if (window.SX && SX.isActive('waiterRequestsPage')) load(); }, 15000);

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-wrq-done],[data-wrq-order],[data-wrq-area]');
    if (!t || !D) return;
    if (t.hasAttribute('data-wrq-area')) { area = t.getAttribute('data-wrq-area'); draw(); return; }
    var id = +(t.getAttribute('data-wrq-done') || t.getAttribute('data-wrq-order'));
    var r = D.waiting.filter(function (x) { return x.id === id; })[0];
    if (t.hasAttribute('data-wrq-order')) { if (r && window.showOrderItems) window.showOrderItems(r.notes || ''); return; }
    var c = t.closest('.wrq-card'); if (c) c.classList.add('leaving');
    if (window.markAttended) window.markAttended(id);   // reloads the hidden list, which reloads this board
  });

  function watch() {
    var el = document.getElementById('waiterRequestsList');
    if (!el || el._wrqWatched) return;
    el._wrqWatched = true;
    var t = null;
    new MutationObserver(function () { clearTimeout(t); t = setTimeout(load, 200); }).observe(el, { childList: true });
  }
  document.addEventListener('click', function (e) { if (e.target.closest('.nav-link[data-page="waiterRequestsPage"]')) setTimeout(function () { watch(); load(); }, 50); });

  // the website "Call waiter" switch saves straight away (api/app_settings.php, owner or branch admin)
  document.addEventListener('change', function (e) {
    var sw = e.target.closest('#wrqWebSwitch');
    if (!sw || !D || !D.website_calls) return;
    var on = sw.checked;
    sw.disabled = true;
    var fd = new FormData();
    fd.append('call_waiter_enabled', on ? '1' : '0');
    fetch('../api/app_settings.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.success) throw new Error(d.message || 'Could not save');
      D.website_calls.enabled = on;
      if (typeof window.showNotification === 'function') window.showNotification(on ? 'Guests can now call a waiter from your website' : 'Call waiter is off on your website', 'success');
    }).catch(function (err) {
      sw.checked = !on;
      if (typeof window.showNotification === 'function') window.showNotification(err.message || 'Could not save', 'error');
    }).then(function () { sw.disabled = false; draw(); });
  });
  document.addEventListener('DOMContentLoaded', function () { watch(); if (window.SX && SX.isActive('waiterRequestsPage')) load(); });
})();
