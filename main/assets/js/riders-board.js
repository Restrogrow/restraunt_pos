/*
 * Delivery riders on the web dashboard (data: api/delivery_dispatch.php, engine: config/rider_engine.php).
 *
 *  - Delivery › Riders (#ridersPage → #rbRoot): each rider with what they're carrying, deliveries today,
 *    cash in hand (Settle cash), last location; delivery orders under way with an Assign dropdown;
 *    the "riders can pick deliveries themselves" switch. Refreshes every 20 seconds while open.
 *  - Any element <div class="rg-rider-assign" data-order="ID"> (the order detail's delivery box) becomes
 *    an "Assign rider" control by itself.
 * Styles live here (rb-*).
 */
(function () {
  'use strict';
  var API = '../api/delivery_dispatch.php';
  var timer = null, D = null;
  var STEP = { Pending: ['Waiting for a rider', '#9ca3af'], Preparing: ['Waiting for a rider', '#9ca3af'], Assigned: ['Assigned', '#2563eb'], Picked_Up: ['Picked up', '#d97706'], In_Transit: ['On the way', '#c1440e'], Delivered: ['Delivered', '#16a34a'] };

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ic(n) { return '<span class="material-symbols-rounded">' + n + '</span>'; }
  function notify(m, t) { if (typeof window.showNotification === 'function') window.showNotification(m, t); else if (t === 'error') console.warn(m); }
  function money(n) { return (typeof window.globalCurrencySymbol === 'string' && window.globalCurrencySymbol ? window.globalCurrencySymbol : '₹') + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 }); }
  function get(q) { return fetch(API + (q || ''), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }); }
  function post(b) { return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) }).then(function (r) { return r.json(); }); }
  function ago(ts) {
    if (!ts) return 'no location yet';
    var m = Math.round((Date.now() - new Date(String(ts).replace(' ', 'T')).getTime()) / 60000);
    return m < 1 ? 'location now' : m < 60 ? 'location ' + m + ' min ago' : 'location ' + Math.round(m / 60) + ' h ago';
  }

  function css() {
    if ($('rbCss')) return;
    var st = document.createElement('style');
    st.id = 'rbCss';
    st.textContent = [
      '#rbRoot{display:grid;gap:16px}',
      '.rb-top{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between}',
      '.rb-sw{display:inline-flex;align-items:center;gap:10px;font-weight:600;font-size:.9rem;color:#374151;cursor:pointer}.rb-sw input{width:18px;height:18px;accent-color:#c1440e}',
      '.rb-help{color:#6b7280;font-size:.86rem;margin:0}',
      '.rb-riders{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}',
      '.rb-rider{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:14px;display:grid;gap:8px}',
      '.rb-rh{display:flex;align-items:center;gap:10px}.rb-av{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;font-weight:800;color:#fff;background:linear-gradient(135deg,#f97316,#c1440e);flex:none}',
      '.rb-rh b{display:block;font-size:.95rem}.rb-rh small{color:#6b7280;font-size:.78rem}',
      '.rb-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}.rb-stat{background:#f9fafb;border-radius:10px;padding:7px;text-align:center}.rb-stat b{display:block;font-size:1.05rem}.rb-stat small{color:#6b7280;font-size:.72rem}',
      '.rb-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:8px 12px;border-radius:10px;border:1.5px solid #e5e7eb;background:#fff;font:inherit;font-size:.85rem;font-weight:700;cursor:pointer;color:#111827}',
      '.rb-btn.pri{background:#c1440e;border-color:#c1440e;color:#fff}.rb-btn:disabled{opacity:.5;cursor:wait}.rb-btn .material-symbols-rounded{font-size:1.05rem}',
      '.rb-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;overflow:hidden}.rb-card h3{margin:0;padding:14px 16px;border-bottom:1px solid #f3f4f6;font-size:1rem;display:flex;gap:8px;align-items:center}',
      '.rb-row{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr) auto;gap:12px;align-items:center;padding:12px 16px;border-bottom:1px solid #f3f4f6}.rb-row:last-child{border-bottom:0}',
      '.rb-row b{font-size:.92rem}.rb-row small{display:block;color:#6b7280;font-size:.8rem;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
      '.rb-pill{display:inline-block;font-size:.74rem;font-weight:700;padding:2px 9px;border-radius:999px;color:#fff}',
      '.rb-sel{padding:8px 10px;border:1.5px solid #e5e7eb;border-radius:10px;font:inherit;font-size:.85rem;background:#fff;max-width:200px}',
      '.rb-empty{padding:26px 16px;text-align:center;color:#6b7280;font-size:.9rem}',
      '.rg-rider-assign{margin-bottom:.75rem}.rg-rider-assign .rb-line{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.rg-rider-assign .rb-now{font-size:.88rem;color:#166534;margin-bottom:6px}',
      '@media(max-width:700px){.rb-row{grid-template-columns:1fr}}'
    ].join('');
    document.head.appendChild(st);
  }

  function riderOptions(riders, current) {
    return '<option value="">' + (current ? 'Change rider…' : 'Choose a rider…') + '</option>' + riders.map(function (r) {
      return '<option value="' + r.id + '"' + (r.id === current ? ' selected' : '') + '>' + esc(r.name) + (r.active ? ' · carrying ' + r.active : ' · free') + '</option>';
    }).join('');
  }
  function pill(st) { var s = STEP[st] || STEP.Pending; return '<span class="rb-pill" style="background:' + s[1] + '">' + s[0] + '</span>'; }

  // ── Delivery › Riders ──
  function draw() {
    var root = $('rbRoot');
    if (!root || !D) return;
    if (D.enabled === false) {
      root.innerHTML = '<div class="rb-card"><div class="rb-empty" style="display:grid;gap:12px;justify-items:center"><b style="font-size:1rem;color:#111827">Own riders are switched off</b>' +
        'Turn on "We deliver with our own riders" to add riders, assign deliveries and settle their cash.' +
        (D.can_change ? '<button class="rb-btn pri" type="button" data-rb-own="1">' + ic('two_wheeler') + 'Turn on own riders</button>' : '') + '</div></div>';
      return;
    }
    var riders = D.riders || [], dels = D.deliveries || [];
    var html = '<div class="rb-top"><p class="rb-help">Riders log in to the RestroGrow Staff app (or this website) with the email and password you set in Staff. Give each delivery to a rider, and settle the cash they collected at the end of the shift.</p>' +
      '<label class="rb-sw"><input type="checkbox" id="rbSelf"' + (D.settings && D.settings.self_pick ? ' checked' : '') + '>Riders can pick open deliveries themselves</label></div>';
    html += riders.length ? '<div class="rb-riders">' + riders.map(function (r) {
      return '<div class="rb-rider"><div class="rb-rh"><div class="rb-av">' + esc((r.name || 'R').charAt(0)) + '</div><div><b>' + esc(r.name) + '</b><small>' + esc(r.phone || '') + ' · ' + ago(r.last_seen) + '</small></div></div>' +
        '<div class="rb-stats"><div class="rb-stat"><b>' + r.active + '</b><small>carrying</small></div><div class="rb-stat"><b>' + r.delivered_today + '</b><small>today</small></div><div class="rb-stat"><b>' + money(r.cash_in_hand) + '</b><small>cash</small></div></div>' +
        (r.cash_in_hand > 0 ? '<button class="rb-btn" type="button" data-rb-settle="' + r.id + '" data-name="' + esc(r.name) + '" data-amt="' + r.cash_in_hand + '">' + ic('payments') + 'Settle ' + money(r.cash_in_hand) + '</button>' : '') + '</div>';
    }).join('') + '</div>' : '<div class="rb-card"><div class="rb-empty">No riders yet. Add one in <b>Staff</b> with the role <b>Delivery rider</b>.</div></div>';
    html += '<div class="rb-card"><h3>' + ic('two_wheeler') + 'Deliveries under way <span style="color:#6b7280;font-weight:600">' + dels.length + '</span></h3>' + (dels.length ? dels.map(function (d) {
      var who = d.rider_name ? esc(d.rider_name) : (d.via_qr ? 'Outside rider (QR link)' : '<span style="color:#b45309">No rider yet</span>');
      return '<div class="rb-row"><div><b>' + esc(d.order_number) + '</b> ' + pill(d.delivery_status) + '<small>' + esc(d.customer_name || '') + ' · ' + esc(d.address || 'No address') + '</small><small>' + esc(d.items || '') + '</small></div>' +
        '<div><b style="font-weight:600">' + who + '</b><small>' + (d.cod_due > 0 ? 'Collect ' + money(d.cod_due) + ' cash' : d.payment_status === 'Paid' ? 'Paid' : 'Online, payment pending') + ' · order ' + esc(String(d.order_status).toLowerCase()) + '</small></div>' +
        '<div>' + (riders.length && ['Picked_Up', 'In_Transit'].indexOf(d.delivery_status) === -1 ? '<select class="rb-sel" data-rb-assign="' + d.id + '">' + riderOptions(riders, d.rider_staff_id) + (d.rider_staff_id ? '<option value="0">Take it back</option>' : '') + '</select>' : '') + '</div></div>';
    }).join('') : '<div class="rb-empty">No delivery orders under way.</div>') + '</div>';
    root.innerHTML = html;
  }

  function load() {
    return get().then(function (d) {
      if (!d.success) { var r = $('rbRoot'); if (r) r.innerHTML = '<div class="rb-empty">' + esc(d.message || 'Could not load riders') + '</div>'; return; }
      D = d; draw();
    }).catch(function () { /* offline: keep what is shown */ });
  }

  window.loadRidersBoard = function () {
    css();
    var root = $('rbRoot');
    if (!root) return;
    if (!D) root.innerHTML = '<div class="rb-empty">Loading riders…</div>';
    load();
    clearInterval(timer);
    timer = setInterval(function () {
      var page = $('ridersPage');
      if (!page || !page.classList.contains('active') || document.hidden) return;
      load();
    }, 20000);
  };

  // Settings › Delivery › "We deliver with our own riders": saved straight away, and the rider menus follow it
  function setOwn(on, el) {
    if (el) el.disabled = true;
    return post({ action: 'settings', own_riders: on ? 1 : 0 }).then(function (d) {
      notify(d.message, d.success ? 'success' : 'error');
      if (!d.success) { if (el) el.checked = !on; return; }
      var nav = $('ridersNavItem'); if (nav) nav.style.display = on ? '' : 'none';
      var opt = $('memberRoleDelivery'); if (opt) { opt.hidden = !on; opt.disabled = !on; }
      var tg = $('ownRidersToggle'); if (tg) tg.checked = on;
      D = null;
      if ($('rbRoot')) load();
    }).finally(function () { if (el) el.disabled = false; });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-rb-own]');
    if (b) setOwn(true, b);
  });

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.id === 'ownRidersToggle') { setOwn(t.checked, t); return; }
    if (t.id === 'rbSelf') post({ action: 'settings', self_pick: t.checked ? 1 : 0 }).then(function (d) { notify(d.message, d.success ? 'success' : 'error'); });
    if (t.dataset && t.dataset.rbAssign && t.value !== '') {
      t.disabled = true;
      post({ action: 'assign', order_id: +t.dataset.rbAssign, rider_id: +t.value }).then(function (d) {
        notify(d.message, d.success ? 'success' : 'error');
        load();
      }).finally(function () { t.disabled = false; });
    }
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-rb-settle]');
    if (!b) return;
    var go = function () {
      b.disabled = true;
      post({ action: 'settle', rider_id: +b.dataset.rbSettle }).then(function (d) { notify(d.message, d.success ? 'success' : 'error'); load(); }).finally(function () { b.disabled = false; });
    };
    var text = b.dataset.name + ' hands over ' + money(b.dataset.amt) + ' in cash.';
    if (window.Swal) Swal.fire({ title: 'Settle cash?', text: text, showCancelButton: true, confirmButtonText: 'Cash received', confirmButtonColor: '#c1440e' }).then(function (r) { if (r.isConfirmed) go(); });
    else go();
  });

  // ── "Assign rider" inside a delivery order's detail ──
  function fillAssign(box) {
    box.setAttribute('data-ready', '1');
    css();
    var id = +box.getAttribute('data-order');
    box.innerHTML = '<div class="rb-now">Loading riders…</div>';
    get('?order_id=' + id).then(function (d) {
      if (!d.success || d.enabled === false) { box.innerHTML = ''; box.style.display = 'none'; return; }
      var del = d.delivery || {}, riders = d.riders || [];
      if (!riders.length) { box.innerHTML = '<div class="rb-now" style="color:#6b7280">Add riders in Staff (role: Delivery rider) to assign this delivery, or use the QR link below for an outside rider.</div>'; return; }
      var moving = ['Picked_Up', 'In_Transit'].indexOf(del.delivery_status) !== -1;
      box.innerHTML = '<div class="rb-now">' + (del.rider_name ? 'Rider: <b>' + esc(del.rider_name) + '</b> ' + pill(del.delivery_status) : 'No rider yet') + '</div>' +
        (del.delivery_status === 'Delivered' ? '' : '<div class="rb-line"><select class="rb-sel" data-ra-sel>' + riderOptions(riders, del.rider_staff_id) + (del.rider_staff_id && !moving ? '<option value="0">Take it back</option>' : '') + '</select>' +
        '<button class="rb-btn pri" type="button" data-ra-go="' + id + '">' + ic('two_wheeler') + 'Assign</button></div>');
    }).catch(function () { box.innerHTML = ''; });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-ra-go]');
    if (!b) return;
    var box = b.closest('.rg-rider-assign'), sel = box && box.querySelector('[data-ra-sel]');
    if (!sel || sel.value === '') { notify('Choose a rider first', 'error'); return; }
    b.disabled = true;
    post({ action: 'assign', order_id: +b.dataset.raGo, rider_id: +sel.value }).then(function (d) {
      notify(d.message, d.success ? 'success' : 'error');
      if (d.success) fillAssign(box);
    }).finally(function () { b.disabled = false; });
  });
  new MutationObserver(function () {
    document.querySelectorAll('.rg-rider-assign:not([data-ready])').forEach(fillAssign);
  }).observe(document.documentElement, { childList: true, subtree: true });
})();
