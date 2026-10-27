/* Rooms page (dashboard #roomsPage, api/rooms_admin.php, config/rooms.php).
   Tabs: Bookings (requests to confirm, check-in / check-out, bill, paid), Calendar (each room by night: who is in
   which room, give a stay its room), Room types (photos, prices), Room service QR (one code per room), Reports
   (occupancy, ADR, RevPAR, revenue) and Settings (times, cancellation, house rules, room tax, bill look, room service).
   The Bill window adds extra charges / discount / advance, opens the printable bill (website/room_bill.php) and sends
   the guest its link on WhatsApp. Also the Settings › Ordering "Rooms" switch (#enableRoomsToggle), which saves
   straight away and shows the sidebar item. window.RGRooms.load() runs when the page opens (script.js showPage). */
(function () {
  var API = '../api/rooms_admin.php';
  var S = { data: null, tab: 'bookings', filter: 'upcoming', calFrom: null, report: null, range: 'month', custom: null, qr: null };
  var AMENITIES = ['WiFi', 'AC', 'TV', 'Hot water', 'Attached bathroom', 'Balcony', 'Breakfast', 'Parking', 'Room service', 'Mountain view'];
  var EXTRAS = ['Extra bed', 'Breakfast', 'Laundry', 'Early check-in', 'Late check-out', 'Airport / station pickup', 'Mini bar'];
  var PAY = ['Cash', 'UPI', 'Card', 'Bank transfer', 'eSewa', 'Khalti', 'Other'];
  var CAL_DAYS = 14;
  var NEXT = {
    'Pending': [['Confirmed', 'Confirm', 'primary'], ['Declined', 'Decline', 'off']],
    'Confirmed': [['Checked in', 'Check in', 'primary'], ['Cancelled', 'Cancel', 'off']],
    'Checked in': [['Checked out', 'Check out', 'primary']],
    'Checked out': [], 'Cancelled': [['Confirmed', 'Take back', 'plain']], 'Declined': [['Confirmed', 'Take back', 'plain']]
  };
  var TONE = { 'Pending': 'amber', 'Confirmed': 'blue', 'Checked in': 'green', 'Checked out': 'grey', 'Cancelled': 'red', 'Declined': 'red' };
  var HOLDING = ['Pending', 'Confirmed', 'Checked in'];

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function notify(m, t) { if (typeof window.showNotification === 'function') window.showNotification(m, t); }
  function money(n) { return (S.data ? S.data.currency : '') + (+n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }); }
  function day(d) { return new Date(d + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' }); }
  function ymd(x) { return x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0'); }
  function addDays(d, n) { var x = new Date(d + 'T00:00:00'); x.setDate(x.getDate() + n); return ymd(x); }
  function nums(s) { return String(s || '').split(/[,\s]+/).map(function (x) { return x.trim(); }).filter(Boolean); }
  function byId(id) { return S.data.bookings.filter(function (x) { return x.id === id; })[0]; }
  function post(body) {
    return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); });
  }
  function getJson(q) { return fetch(API + q, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }); }

  function css() {
    if ($('rmStyles')) return;
    var s = document.createElement('style');
    s.id = 'rmStyles';
    s.textContent =
      '.rm-off{display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap;background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;padding:14px 16px;margin-bottom:16px;font-size:14px;color:#7c2d12}' +
      '.rm-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}' +
      '.rm-stat{background:#fff;border:1px solid #eef0f4;border-radius:14px;padding:12px 14px}.rm-stat small{display:block;color:#6b7280;font-size:12px}.rm-stat b{font-size:22px}.rm-stat em{display:block;font-style:normal;color:#6b7280;font-size:12px;margin-top:2px}' +
      '.rm-tabs{display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap}.rm-tabs button,.rm-chips button{border:1px solid #e5e7eb;background:#fff;border-radius:999px;padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer;color:#374151}' +
      '.rm-tabs button.on,.rm-chips button.on{background:#111827;color:#fff;border-color:#111827}.rm-chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;align-items:center}' +
      '.rm-list{display:grid;gap:10px}.rm-bk{background:#fff;border:1px solid #eef0f4;border-radius:14px;padding:12px 14px;display:grid;gap:8px}' +
      '.rm-bk-top{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}.rm-bk-top b{font-size:15px}.rm-bk small{color:#6b7280}' +
      '.rm-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:600}.rm-amber{background:#fef3c7;color:#92400e}.rm-blue{background:#dbeafe;color:#1e40af}.rm-green{background:#dcfce7;color:#166534}.rm-grey{background:#f3f4f6;color:#4b5563}.rm-red{background:#fee2e2;color:#991b1b}' +
      '.rm-acts{display:flex;gap:6px;flex-wrap:wrap}.rm-b{border:0;border-radius:9px;padding:7px 12px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:4px;font-family:inherit}' +
      '.rm-b.primary{background:#7c3aed;color:#fff}.rm-b.plain{background:#f3f4f6;color:#111827}.rm-b.off{background:#fff;color:#b91c1c;border:1.5px solid #fca5a5}.rm-b .material-symbols-rounded{font-size:17px}.rm-b:disabled{opacity:.5;cursor:default}' +
      '.rm-types{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}.rm-type{background:#fff;border:1px solid #eef0f4;border-radius:14px;overflow:hidden}' +
      '.rm-type img,.rm-type .ph{width:100%;aspect-ratio:16/10;object-fit:cover;background:#f3f4f6;display:flex;align-items:center;justify-content:center;color:#9ca3af}.rm-type .bd{padding:12px 14px;display:grid;gap:6px}' +
      '.rm-empty{background:#fff;border:1px dashed #d1d5db;border-radius:14px;padding:30px;text-align:center;color:#6b7280}' +
      '.rm-ov{position:fixed;inset:0;background:rgba(17,24,39,.5);z-index:10000;display:flex;align-items:center;justify-content:center;padding:16px}' +
      '.rm-md{background:#fff;border-radius:16px;width:100%;max-width:560px;max-height:92vh;overflow:auto;padding:20px;position:relative}.rm-md h3{margin:0 0 14px;font-size:18px;padding-right:36px}.rm-md h4{margin:16px 0 8px;font-size:14px}' +
      '.rm-md label,.rm-set label.l{display:block;font-size:13px;font-weight:600;color:#374151;margin:0 0 4px}.rm-md input,.rm-md select,.rm-md textarea,.rm-set input,.rm-set select,.rm-set textarea{width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #d1d5db;border-radius:9px;font-size:14px;font-family:inherit}' +
      '.rm-md .g,.rm-set .g{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}.rm-md .f,.rm-set .f{margin-bottom:12px}.rm-md .x{position:absolute;right:14px;top:14px;border:0;background:#f3f4f6;border-radius:50%;width:32px;height:32px;cursor:pointer}' +
      '.rm-am{display:flex;flex-wrap:wrap;gap:6px}.rm-am label{display:inline-flex;align-items:center;gap:5px;margin:0;font-weight:500;border:1px solid #e5e7eb;border-radius:999px;padding:4px 10px;cursor:pointer;font-size:13px}.rm-am input{width:auto}' +
      '.rm-ph{display:flex;gap:8px;flex-wrap:wrap}.rm-ph div{position:relative;width:86px;height:64px;border-radius:8px;overflow:hidden;background:#f3f4f6}.rm-ph img{width:100%;height:100%;object-fit:cover}.rm-ph button{position:absolute;right:2px;top:2px;border:0;background:rgba(0,0,0,.6);color:#fff;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:12px;line-height:20px;padding:0}' +
      '.rm-ph .add{display:flex;align-items:center;justify-content:center;border:1.5px dashed #c4b5fd;color:#7c3aed;cursor:pointer;font-size:12px;text-align:center}' +
      '.rm-md .ft{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap}.rm-hint{font-size:12px;color:#6b7280;margin-top:4px}' +
      /* calendar */
      '.rm-calbar{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:10px}.rm-calbar b{margin:0 6px;font-size:14px}' +
      '.rm-calwrap{overflow-x:auto;background:#fff;border:1px solid #eef0f4;border-radius:14px}' +
      '.rm-cal{border-collapse:separate;border-spacing:0;width:100%;min-width:' + (110 + CAL_DAYS * 58) + 'px;table-layout:fixed;font-size:12px}.rm-cal thead th.rn{width:110px}.rm-cal th,.rm-cal td{border-bottom:1px solid #f1f2f5;border-right:1px solid #f6f7f9;padding:0;height:38px;min-width:58px}' +
      '.rm-cal thead th{position:sticky;top:0;background:#fafafb;font-weight:600;color:#4b5563;padding:6px 4px;text-align:center;line-height:1.2}.rm-cal thead th.today{color:#7c3aed}.rm-cal thead th.we{background:#f5f3ff}' +
      '.rm-cal .rn{position:sticky;left:0;background:#fff;z-index:1;padding:0 10px;font-weight:700;text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.rm-cal tr.grp td{background:#f9fafb;font-weight:700;color:#374151;padding:6px 10px;height:auto}' +
      '.rm-cal td.free{cursor:pointer}.rm-cal td.free:hover{background:#f5f3ff}' +
      '.rm-bar{margin:4px 2px;border-radius:8px;padding:4px 8px;font-weight:600;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;border:0;width:calc(100% - 4px);text-align:left;font-family:inherit;font-size:12px}' +
      '.rm-bar.amber{background:#fef3c7;color:#92400e}.rm-bar.blue{background:#dbeafe;color:#1e40af}.rm-bar.green{background:#dcfce7;color:#166534}.rm-bar.grey{background:#f3f4f6;color:#4b5563}' +
      '.rm-unas{display:flex;gap:6px;flex-wrap:wrap;padding:6px 10px}.rm-unas button{border:1.5px dashed #c4b5fd;background:#fff;color:#5b21b6;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:600;cursor:pointer}' +
      '.rm-rooms{display:flex;flex-wrap:wrap;gap:8px}.rm-rooms label{display:inline-flex;align-items:center;gap:6px;border:1.5px solid #e5e7eb;border-radius:10px;padding:8px 12px;margin:0;cursor:pointer;font-weight:600}' +
      '.rm-rooms label.busy{opacity:.45;cursor:not-allowed}.rm-rooms input{width:auto}' +
      /* bill */
      '.rm-ex{display:grid;grid-template-columns:1fr 110px 32px;gap:6px;margin-bottom:6px}.rm-ex button{border:0;background:#fee2e2;color:#991b1b;border-radius:8px;cursor:pointer}' +
      '.rm-qx{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0}.rm-qx button{border:1px solid #e5e7eb;background:#fff;border-radius:999px;padding:4px 10px;font-size:12px;cursor:pointer}' +
      '.rm-sum{background:#f8f9fb;border-radius:12px;padding:10px 12px;font-size:13px;margin-top:12px}.rm-sum div{display:flex;justify-content:space-between;gap:10px;padding:3px 0}.rm-sum .t{font-weight:800;font-size:15px;border-top:1px dashed #d1d5db;margin-top:4px;padding-top:6px}' +
      /* qr */
      '.rm-qrs{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}.rm-qr{background:#fff;border:1px solid #eef0f4;border-radius:14px;padding:14px;text-align:center}.rm-qr img{width:140px;height:140px}.rm-qr b{display:block;font-size:16px;margin-top:6px}.rm-qr small{color:#6b7280}' +
      /* reports */
      '.rm-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:14px}' +
      '.rm-card{background:#fff;border:1px solid #eef0f4;border-radius:14px;padding:14px;margin-bottom:14px}.rm-card h4{margin:0 0 10px;font-size:14px}' +
      '.rm-chart{display:flex;align-items:flex-end;gap:2px;height:150px;border-bottom:1px solid #e5e7eb;position:relative}.rm-chart div{flex:1;min-width:3px;background:#7c3aed;border-radius:4px 4px 0 0;position:relative;cursor:default}.rm-chart div:hover{background:#5b21b6}' +
      '.rm-chart div:hover::after{content:attr(data-tip);position:absolute;bottom:100%;left:50%;transform:translateX(-50%);margin-bottom:6px;background:#111827;color:#fff;font-size:11px;padding:4px 7px;border-radius:6px;white-space:nowrap;z-index:2}' +
      '.rm-axis{display:flex;justify-content:space-between;color:#6b7280;font-size:11px;margin-top:4px}' +
      '.rm-tblw{overflow-x:auto}.rm-tbl{width:100%;border-collapse:collapse;font-size:13px}.rm-tbl th{text-align:left;color:#6b7280;font-size:12px;font-weight:600;border-bottom:1px solid #e5e7eb;padding:6px}.rm-tbl td{padding:7px 6px;border-bottom:1px solid #f3f4f6}.rm-tbl .r{text-align:right}' +
      /* settings */
      '.rm-set{display:grid;gap:14px;max-width:760px}.rm-set .rm-card{margin:0}.rm-set .sw{display:flex;gap:10px;align-items:flex-start;margin-bottom:10px;font-size:14px;cursor:pointer}.rm-set .sw input{width:auto;margin-top:3px}' +
      '@media(max-width:600px){.rm-md .g,.rm-set .g{grid-template-columns:1fr}}';
    document.head.appendChild(s);
  }

  function load() {
    css();
    var root = $('roomsRoot');
    if (!root) return;
    getJson('').then(function (d) {
      if (!d.success) { root.innerHTML = '<div class="rm-empty">' + esc(d.message || 'Couldn\'t load rooms.') + '</div>'; return; }
      S.data = d;
      if (!S.calFrom) S.calFrom = d.today;
      document.querySelectorAll('.rm-ov.rm-detail').forEach(function (o) { o.remove(); });
      render();
    }).catch(function () { root.innerHTML = '<div class="rm-empty">Couldn\'t load rooms. <a href="#" onclick="RGRooms.load();return false">Try again</a></div>'; });
  }

  function render() {
    var d = S.data, root = $('roomsRoot');
    var h = '';
    if (!d.enabled) {
      h += '<div class="rm-off"><span><b>Rooms is off on your website.</b> Add your room types, then switch it on so guests can book.</span>' +
        (d.can_edit ? '<button type="button" class="rm-b primary" data-rm="enable">Switch on</button>' : '') + '</div>';
    }
    h += '<div class="rm-stats">' +
      '<div class="rm-stat"><small>Arriving today</small><b>' + d.stats.arrivals + '</b></div>' +
      '<div class="rm-stat"><small>Leaving today</small><b>' + d.stats.departures + '</b></div>' +
      '<div class="rm-stat"><small>Requests to confirm</small><b>' + d.stats.pending + '</b></div>' +
      '<div class="rm-stat"><small>Rooms taken tonight</small><b>' + d.stats.occupied + ' / ' + d.stats.rooms + '</b></div></div>';
    var tabs = [['bookings', 'Bookings'], ['calendar', 'Calendar'], ['types', 'Room types (' + d.types.length + ')'], ['qr', 'Room service QR'], ['reports', 'Reports'], ['settings', 'Settings']];
    h += '<div class="rm-tabs">' + tabs.map(function (t) { return '<button type="button" data-tab="' + t[0] + '" class="' + (S.tab === t[0] ? 'on' : '') + '">' + t[1] + '</button>'; }).join('') + '</div>';
    h += '<div id="rmTabBody">' + (S.tab === 'types' ? renderTypes() : S.tab === 'calendar' ? renderCalendar() : S.tab === 'qr' ? '<div class="rm-empty">Loading QR codes…</div>'
      : S.tab === 'reports' ? '<div class="rm-empty">Loading report…</div>' : S.tab === 'settings' ? renderSettings() : renderBookings()) + '</div>';
    root.innerHTML = h;
    if (S.tab === 'qr') loadQr();
    if (S.tab === 'reports') loadReport();
  }

  // ───────────── Bookings ─────────────

  function card(b) {
    var d = S.data;
    var acts = d.can_edit ? (NEXT[b.status] || []).map(function (a) {
      return '<button type="button" class="rm-b ' + a[2] + '" data-status="' + esc(a[0]) + '" data-id="' + b.id + '">' + a[1] + '</button>';
    }).join('') : '';
    if (d.can_edit && HOLDING.indexOf(b.status) !== -1) {
      acts += '<button type="button" class="rm-b plain" data-assign="' + b.id + '"><span class="material-symbols-rounded">bed</span>' + (b.room_numbers ? 'Change room' : 'Give a room') + '</button>';
    }
    if (['Confirmed', 'Checked in', 'Checked out'].indexOf(b.status) !== -1) {
      acts += '<button type="button" class="rm-b plain" data-bill="' + b.id + '"><span class="material-symbols-rounded">receipt_long</span>Bill</button>';
      if (d.can_edit) acts += b.paid ? '<button type="button" class="rm-b plain" data-unpaid="' + b.id + '"><span class="material-symbols-rounded">undo</span>Mark unpaid</button>'
        : '<button type="button" class="rm-b plain" data-bill="' + b.id + '"><span class="material-symbols-rounded">payments</span>Settle</button>';
    }
    var wa = b.phone.replace(/\D/g, '');
    acts += '<a class="rm-b plain" href="tel:' + esc(b.phone) + '"><span class="material-symbols-rounded">call</span>Call</a>' +
      '<a class="rm-b plain" href="https://wa.me/' + esc(wa) + '" target="_blank" rel="noopener"><span class="material-symbols-rounded">chat</span>WhatsApp</a>';
    return '<div class="rm-bk"><div class="rm-bk-top"><div><b>' + esc(b.name) + '</b> <small>· ' + esc(b.phone) + (b.source === 'website' ? ' · from website' : '') + '</small></div>' +
      '<div><span class="rm-pill rm-' + (TONE[b.status] || 'grey') + '">' + esc(b.status) + '</span> ' +
      (b.paid ? '<span class="rm-pill rm-green">Paid' + (b.pay_method ? ' · ' + esc(b.pay_method) : '') + '</span>' : (b.status !== 'Cancelled' && b.status !== 'Declined' ? '<span class="rm-pill rm-grey">Not paid</span>' : '')) + '</div></div>' +
      '<div>' + esc(b.type) + (b.rooms > 1 ? ' × ' + b.rooms + ' rooms' : '') + ' · ' + b.guests + ' guest' + (b.guests === 1 ? '' : 's') +
      (b.room_numbers ? ' · Room ' + esc(b.room_numbers) : '') + '</div>' +
      '<div><b>' + day(b.in) + '</b> → <b>' + day(b.out) + '</b> <small>· ' + b.nights + ' night' + (b.nights === 1 ? '' : 's') + ' · ' + money(b.total) +
      (b.advance > 0 ? ' · advance ' + money(b.advance) : '') + (b.bill_no ? ' · bill ' + esc(d.settings.bill_prefix + b.bill_no) : '') + '</small></div>' +
      (b.food ? '<small><span class="material-symbols-rounded" style="font-size:15px;vertical-align:-3px">room_service</span> Room service on the bill: ' + b.food.orders + ' order' + (b.food.orders === 1 ? '' : 's') + ' · ' + money(b.food.total) + '</small>' : '') +
      (b.notes ? '<small>' + (b.source === 'website' ? 'Guest note: ' : 'Note: ') + esc(b.notes) + '</small>' : '') +
      (b.owner_note ? '<small>Your note: ' + esc(b.owner_note) + '</small>' : '') +
      '<div class="rm-acts">' + acts + '</div></div>';
  }

  function renderBookings() {
    var d = S.data, t = d.today;
    var groups = {
      upcoming: function (b) { return HOLDING.indexOf(b.status) !== -1; },
      pending: function (b) { return b.status === 'Pending'; },
      house: function (b) { return b.status === 'Checked in'; },
      today: function (b) { return (b.in === t && ['Pending', 'Confirmed'].indexOf(b.status) !== -1) || (b.out === t && b.status === 'Checked in'); },
      unpaid: function (b) { return !b.paid && ['Checked in', 'Checked out'].indexOf(b.status) !== -1; },
      done: function (b) { return ['Checked out', 'Cancelled', 'Declined'].indexOf(b.status) !== -1; }
    };
    var names = [['upcoming', 'Upcoming'], ['pending', 'Requests'], ['today', 'Today'], ['house', 'In house'], ['unpaid', 'To collect'], ['done', 'Past & cancelled']];
    var list = d.bookings.filter(groups[S.filter]);
    if (S.filter === 'done') list = list.slice().reverse();
    var h = '<div class="rm-chips">' + names.map(function (n) {
      var c = d.bookings.filter(groups[n[0]]).length;
      return '<button type="button" data-filter="' + n[0] + '" class="' + (S.filter === n[0] ? 'on' : '') + '">' + n[1] + (c ? ' · ' + c : '') + '</button>';
    }).join('') + '</div>';
    if (!d.types.length) return h + '<div class="rm-empty">Add your first room type (for example "Deluxe Double, 4 rooms") to start taking bookings.<br><br><button type="button" class="rm-b primary" data-rm="add-type">Add room type</button></div>';
    if (!list.length) return h + '<div class="rm-empty">No bookings here.</div>';
    return h + '<div class="rm-list">' + list.map(card).join('') + '</div>';
  }

  // ───────────── Calendar: rooms × nights ─────────────

  function renderCalendar() {
    var d = S.data;
    if (!d.types.length) return '<div class="rm-empty">Add a room type first.</div>';
    var days = [];
    for (var i = 0; i < CAL_DAYS; i++) days.push(addDays(S.calFrom, i));
    var last = addDays(S.calFrom, CAL_DAYS);
    var shown = d.bookings.filter(function (b) { return ['Pending', 'Confirmed', 'Checked in', 'Checked out'].indexOf(b.status) !== -1 && b.in < last && b.out > S.calFrom; });
    var h = '<div class="rm-calbar"><button type="button" class="rm-b plain" data-cal="-7"><span class="material-symbols-rounded">chevron_left</span>Week</button>' +
      '<button type="button" class="rm-b plain" data-cal="0">Today</button><button type="button" class="rm-b plain" data-cal="7">Week<span class="material-symbols-rounded">chevron_right</span></button>' +
      '<b>' + day(S.calFrom) + ' – ' + day(addDays(S.calFrom, CAL_DAYS - 1)) + '</b><small class="rm-hint">Tap a free night to add a booking, tap a stay to open it.</small></div>';
    h += '<div class="rm-calwrap"><table class="rm-cal"><thead><tr><th class="rn">Room</th>' + days.map(function (x) {
      var dt = new Date(x + 'T00:00:00'), wd = dt.getDay();
      return '<th class="' + (x === d.today ? 'today ' : '') + (wd === 0 || wd === 6 ? 'we' : '') + '">' + dt.toLocaleDateString(undefined, { weekday: 'short' }) + '<br>' + dt.getDate() + '</th>';
    }).join('') + '</tr></thead><tbody>';
    d.types.forEach(function (t) {
      var rooms = nums(t.room_numbers);
      h += '<tr class="grp"><td class="rn" colspan="' + (CAL_DAYS + 1) + '">' + esc(t.name) + ' <small style="font-weight:400;color:#6b7280">· ' + money(t.price) + ' a night</small></td></tr>';
      if (!rooms.length) {
        h += '<tr><td class="rn" colspan="' + (CAL_DAYS + 1) + '" style="font-weight:400;color:#6b7280;white-space:normal;padding:8px 10px">Add room numbers to this room type (for example 101, 102) to see each room here. ' +
          (d.can_edit ? '<button type="button" class="rm-b plain" data-edit="' + t.id + '">Add room numbers</button>' : '') + '</td></tr>';
      }
      rooms.forEach(function (rn) {
        var mine = shown.filter(function (b) { return b.type_id === t.id && nums(b.room_numbers).map(function (x) { return x.toLowerCase(); }).indexOf(rn.toLowerCase()) !== -1; });
        h += '<tr><td class="rn">' + esc(rn) + '</td>';
        for (var i = 0; i < days.length;) {
          var x = days[i];
          var b = mine.filter(function (bb) { return bb.in <= x && bb.out > x; })[0];
          if (b) {
            var span = 0;
            while (i + span < days.length && days[i + span] < b.out) span++;
            h += '<td colspan="' + span + '"><button type="button" class="rm-bar ' + (TONE[b.status] || 'grey') + '" data-open="' + b.id + '" title="' + esc(b.name + ' · ' + b.status + ' · ' + day(b.in) + ' – ' + day(b.out)) + '">' + esc(b.name) + (b.paid ? ' ✓' : '') + '</button></td>';
            i += span;
          } else {
            h += '<td class="' + (d.can_edit ? 'free' : '') + '" data-free="' + t.id + '|' + esc(rn) + '|' + x + '"></td>';
            i++;
          }
        }
        h += '</tr>';
      });
      // stays of this type with no room (or fewer rooms than booked) yet
      var open = shown.filter(function (b) { return b.type_id === t.id && HOLDING.indexOf(b.status) !== -1 && nums(b.room_numbers).length < b.rooms; });
      if (open.length) {
        h += '<tr><td colspan="' + (CAL_DAYS + 1) + '"><div class="rm-unas"><small style="align-self:center;color:#6b7280">No room yet:</small>' + open.map(function (b) {
          return '<button type="button" data-assign="' + b.id + '">' + esc(b.name) + ' · ' + day(b.in) + ' – ' + day(b.out) + (b.rooms > 1 ? ' · ' + b.rooms + ' rooms' : '') + '</button>';
        }).join('') + '</div></td></tr>';
      }
    });
    return h + '</tbody></table></div>';
  }

  /** Which room(s) a stay is in. checkIn = true: this is the Check in step (saves the status too). */
  function assignModal(b, checkIn) {
    var d = S.data;
    var t = d.types.filter(function (x) { return x.id === b.type_id; })[0] || { room_numbers: '', name: b.type };
    var rooms = nums(t.room_numbers), mine = nums(b.room_numbers).map(function (x) { return x.toLowerCase(); });
    var busy = {};
    d.bookings.forEach(function (o) {
      if (o.id === b.id || HOLDING.indexOf(o.status) === -1 || !(o.in < b.out && o.out > b.in)) return;
      nums(o.room_numbers).forEach(function (n) { busy[n.toLowerCase()] = o.name; });
    });
    var body = rooms.length
      ? '<div class="rm-rooms">' + rooms.map(function (n) {
          var who = busy[n.toLowerCase()];
          return '<label class="' + (who ? 'busy' : '') + '" title="' + (who ? 'Taken by ' + esc(who) : 'Free') + '"><input type="checkbox" value="' + esc(n) + '"' + (mine.indexOf(n.toLowerCase()) !== -1 ? ' checked' : '') + (who ? ' disabled' : '') + '>' + esc(n) + '</label>';
        }).join('') + '</div><div class="rm-hint">Pick ' + (b.rooms === 1 ? 'one room' : 'up to ' + b.rooms + ' rooms') + '. Greyed rooms are taken by another stay on these nights.</div>'
      : '<div class="f"><label>Room number</label><input id="rmaNo" value="' + esc(b.room_numbers) + '" placeholder="101"><div class="rm-hint">Tip: add room numbers to the room type to pick them here and see them on the Calendar.</div></div>';
    var ov = modal('<h3>' + (checkIn ? 'Check in ' : 'Room for ') + esc(b.name) + '</h3><p class="rm-hint" style="margin:-8px 0 12px">' + esc(b.type) + ' · ' + day(b.in) + ' → ' + day(b.out) + '</p>' + body +
      '<div class="ft"><button type="button" class="rm-b plain" data-close>Cancel</button><button type="button" class="rm-b primary" id="rmaSave">' + (checkIn ? 'Check in' : 'Save') + '</button></div>');
    ov.addEventListener('change', function (e) {
      if (e.target.type !== 'checkbox' || !e.target.checked) return;
      if (ov.querySelectorAll('.rm-rooms input:checked').length > b.rooms) { e.target.checked = false; notify('This stay has ' + b.rooms + ' room' + (b.rooms === 1 ? '' : 's'), 'error'); }
    });
    $('rmaSave').addEventListener('click', function () {
      var list = rooms.length ? Array.prototype.map.call(ov.querySelectorAll('.rm-rooms input:checked'), function (c) { return c.value; }) : nums($('rmaNo').value);
      var btn = this; btn.disabled = true;
      var req = checkIn ? post({ action: 'status', id: b.id, status: 'Checked in', room_numbers: list.join(', ') }) : post({ action: 'assign', id: b.id, room_numbers: list });
      req.then(function (r) {
        btn.disabled = false;
        if (!r.success) { notify(r.message || 'Could not save', 'error'); return; }
        ov.remove(); notify(checkIn ? 'Checked in' : 'Room saved', 'success'); load();
      }).catch(function () { btn.disabled = false; notify('Network error. Please try again.', 'error'); });
    });
  }

  function openBooking(id) {
    var b = byId(id);
    if (!b) return;
    modal('<h3>' + esc(b.name) + '</h3>' + card(b)).classList.add('rm-detail');
  }

  // ───────────── Room types ─────────────

  function renderTypes() {
    var d = S.data;
    if (!d.types.length) return '<div class="rm-empty">No room types yet.<br><br><button type="button" class="rm-b primary" data-rm="add-type">Add room type</button></div>';
    return '<div class="rm-types">' + d.types.map(function (t) {
      return '<div class="rm-type">' + (t.photos[0] ? '<img src="../' + esc(t.photos[0]) + '" alt="" loading="lazy">' : '<div class="ph"><span class="material-symbols-rounded">meeting_room</span></div>') +
        '<div class="bd"><div style="display:flex;justify-content:space-between;gap:8px"><b>' + esc(t.name) + '</b><b>' + money(t.price) + '<small style="font-weight:400;color:#6b7280"> / night</small></b></div>' +
        '<small>' + t.room_count + ' room' + (t.room_count === 1 ? '' : 's') + ' · up to ' + t.max_guests + ' guest' + (t.max_guests === 1 ? '' : 's') + (t.room_numbers ? ' · ' + esc(t.room_numbers) : '') + '</small>' +
        (t.amenities.length ? '<small>' + esc(t.amenities.join(' · ')) + '</small>' : '') +
        (t.active ? '' : '<span class="rm-pill rm-grey" style="justify-self:start">Hidden from website</span>') +
        (d.can_edit ? '<div class="rm-acts"><button type="button" class="rm-b plain" data-edit="' + t.id + '"><span class="material-symbols-rounded">edit</span>Edit</button>' +
          '<button type="button" class="rm-b off" data-del="' + t.id + '">Delete</button></div>' : '') + '</div></div>';
    }).join('') + '</div>';
  }

  function modal(html) {
    var ov = document.createElement('div');
    ov.className = 'rm-ov';
    ov.innerHTML = '<div class="rm-md" role="dialog" aria-modal="true"><button type="button" class="x" aria-label="Close">✕</button>' + html + '</div>';
    document.body.appendChild(ov);
    ov.addEventListener('click', function (e) { if (e.target === ov || e.target.closest('.x') || e.target.closest('[data-close]')) ov.remove(); });
    return ov;
  }

  function editType(t) {
    t = t || { name: '', price: '', max_guests: 2, room_count: 1, room_numbers: '', description: '', amenities: [], photos: [], active: true };
    var photos = t.photos.slice();
    var ov = modal('<h3>' + (t.id ? 'Edit room type' : 'Add room type') + '</h3>' +
      '<div class="f"><label>Name</label><input id="rmtName" maxlength="100" placeholder="e.g. Deluxe Double" value="' + esc(t.name) + '"></div>' +
      '<div class="g"><div><label>Price per night (' + esc(S.data.currency) + ')</label><input id="rmtPrice" type="number" min="0" step="1" value="' + esc(t.price) + '"></div>' +
      '<div><label>Guests per room</label><input id="rmtGuests" type="number" min="1" max="20" value="' + esc(t.max_guests) + '"></div></div>' +
      '<div class="g"><div><label>How many rooms of this type</label><input id="rmtCount" type="number" min="1" max="500" value="' + esc(t.room_count) + '"></div>' +
      '<div><label>Room numbers</label><input id="rmtNumbers" placeholder="101, 102, 103" value="' + esc(t.room_numbers) + '"><div class="rm-hint">Needed for the Calendar and room service QR codes</div></div></div>' +
      '<div class="f"><label>Description</label><textarea id="rmtDesc" rows="3" maxlength="1000" placeholder="Bed, size, view…">' + esc(t.description) + '</textarea></div>' +
      '<div class="f"><label>Amenities</label><div class="rm-am">' + AMENITIES.concat(t.amenities.filter(function (a) { return AMENITIES.indexOf(a) === -1; })).map(function (a) {
        return '<label><input type="checkbox" value="' + esc(a) + '"' + (t.amenities.indexOf(a) !== -1 ? ' checked' : '') + '>' + esc(a) + '</label>';
      }).join('') + '</div><input id="rmtMore" placeholder="Other amenities, separated by commas" style="margin-top:8px"></div>' +
      '<div class="f"><label>Photos (up to 6, the first is the cover)</label><div class="rm-ph" id="rmtPhotos"></div><input type="file" id="rmtFile" accept="image/jpeg,image/png,image/webp" multiple hidden></div>' +
      '<div class="f"><label style="display:flex;gap:8px;align-items:center;font-weight:500"><input type="checkbox" id="rmtActive" style="width:auto"' + (t.active ? ' checked' : '') + '>Show on the website</label></div>' +
      '<div class="ft"><button type="button" class="rm-b plain" data-close>Cancel</button><button type="button" class="rm-b primary" id="rmtSave">Save</button></div>');
    function drawPhotos() {
      $('rmtPhotos').innerHTML = photos.map(function (p, i) { return '<div><img src="../' + esc(p) + '" alt=""><button type="button" data-rmph="' + i + '" aria-label="Remove photo">✕</button></div>'; }).join('') +
        (photos.length < 6 ? '<div class="add" id="rmtAdd">+ Add<br>photos</div>' : '');
    }
    drawPhotos();
    ov.addEventListener('click', function (e) {
      var rm = e.target.closest('[data-rmph]');
      if (rm) { photos.splice(+rm.dataset.rmph, 1); drawPhotos(); }
      if (e.target.closest('#rmtAdd')) $('rmtFile').click();
    });
    $('rmtFile').addEventListener('change', function () {
      var files = Array.prototype.slice.call(this.files, 0, 6 - photos.length);
      this.value = '';
      files.reduce(function (p, f) {
        return p.then(function () {
          var fd = new FormData(); fd.append('action', 'upload'); fd.append('photo', f);
          return fetch(API, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.success) { photos.push(d.path); drawPhotos(); } else notify(d.message || 'Could not upload the photo', 'error');
          });
        });
      }, Promise.resolve());
    });
    $('rmtSave').addEventListener('click', function () {
      var amen = Array.prototype.map.call(ov.querySelectorAll('.rm-am input:checked'), function (c) { return c.value; })
        .concat($('rmtMore').value.split(',').map(function (s) { return s.trim(); }).filter(Boolean));
      var btn = this; btn.disabled = true;
      post({ action: 'save_type', type: { id: t.id || 0, name: $('rmtName').value, price: $('rmtPrice').value, max_guests: $('rmtGuests').value, room_count: $('rmtCount').value,
        room_numbers: $('rmtNumbers').value, description: $('rmtDesc').value, amenities: amen, photos: photos, active: $('rmtActive').checked ? 1 : 0 } }).then(function (d) {
        btn.disabled = false;
        if (!d.success) { notify(d.message || 'Could not save', 'error'); return; }
        ov.remove(); notify('Room type saved', 'success'); if (S.tab !== 'calendar') S.tab = 'types'; load();
      }).catch(function () { btn.disabled = false; notify('Network error. Please try again.', 'error'); });
    });
  }

  function addBooking(pre) {
    var d = S.data;
    pre = pre || {};
    if (!d.types.length) { notify('Add a room type first', 'error'); editType(); return; }
    var ci = pre.in || d.today;
    var ov = modal('<h3>Add booking</h3>' +
      '<div class="f"><label>Room type</label><select id="rmbType">' + d.types.map(function (t) { return '<option value="' + t.id + '" data-price="' + t.price + '"' + (pre.type === t.id ? ' selected' : '') + '>' + esc(t.name) + ' · ' + money(t.price) + '/night</option>'; }).join('') + '</select><div class="rm-hint" id="rmbFree"></div></div>' +
      '<div class="g"><div><label>Check-in</label><input id="rmbIn" type="date" value="' + ci + '"></div><div><label>Check-out</label><input id="rmbOut" type="date" value="' + addDays(ci, 1) + '"></div></div>' +
      '<div class="g"><div><label>Guests</label><input id="rmbGuests" type="number" min="1" value="2"></div><div><label>Rooms</label><input id="rmbRooms" type="number" min="1" value="1"></div></div>' +
      '<div class="g"><div><label>Guest name</label><input id="rmbName" maxlength="120"></div><div><label>Phone</label><input id="rmbPhone" type="tel" maxlength="15"></div></div>' +
      '<div class="g"><div><label>Price per night</label><input id="rmbPrice" type="number" min="0"></div><div><label>Room number (optional)</label><input id="rmbNumbers" placeholder="101" value="' + esc(pre.room || '') + '"></div></div>' +
      '<div class="f"><label>Note (optional)</label><input id="rmbNotes" maxlength="500"></div>' +
      '<div class="ft"><button type="button" class="rm-b plain" data-close>Cancel</button><button type="button" class="rm-b primary" id="rmbSave">Save booking</button></div>');
    function free() {
      var o = $('rmbType').selectedOptions[0];
      $('rmbPrice').placeholder = o.dataset.price;
      getJson('?free=1&in=' + $('rmbIn').value + '&out=' + $('rmbOut').value).then(function (r) {
        $('rmbFree').textContent = r.success ? (r.free[o.value] || 0) + ' room(s) of this type free for those nights' : (r.message || '');
      }).catch(function () {});
    }
    ['rmbType', 'rmbIn', 'rmbOut'].forEach(function (id) { $(id).addEventListener('change', free); });
    free();
    $('rmbSave').addEventListener('click', function () {
      var btn = this; btn.disabled = true;
      post({ action: 'add_booking', booking: { room_type_id: $('rmbType').value, in: $('rmbIn').value, out: $('rmbOut').value, guests: $('rmbGuests').value, rooms: $('rmbRooms').value,
        name: $('rmbName').value, phone: $('rmbPhone').value, price: $('rmbPrice').value, room_numbers: $('rmbNumbers').value, notes: $('rmbNotes').value } }).then(function (r) {
        btn.disabled = false;
        if (!r.success) { notify(r.message || 'Could not save', 'error'); return; }
        ov.remove(); notify('Booking added', 'success'); if (S.tab !== 'calendar') { S.tab = 'bookings'; S.filter = 'upcoming'; } load();
      }).catch(function () { btn.disabled = false; notify('Network error. Please try again.', 'error'); });
    });
  }

  function setStatus(id, status) {
    var b = byId(id);
    if (status === 'Checked in' && b) { assignModal(b, true); return; }
    if ((status === 'Cancelled' || status === 'Declined') && !window.confirm(status === 'Declined' ? 'Decline this booking request?' : 'Cancel this booking?')) return;
    post({ action: 'status', id: id, status: status }).then(function (d) {
      if (!d.success) { notify(d.message || 'Could not update', 'error'); return; }
      notify('Booking ' + status.toLowerCase(), 'success');
      load();
      if (status === 'Checked out' && b && !b.paid) setTimeout(function () { billModal(id); }, 400);   // settle the bill next
    });
  }

  // ───────────── Bill ─────────────

  function billModal(id) {
    var ov = modal('<h3>Room bill</h3><div class="rm-empty" style="border:0">Loading…</div>');
    ov.classList.add('rm-detail');
    var box = ov.querySelector('.rm-md');
    getJson('?bill=' + id).then(function (r) {
      if (!r.success) { box.innerHTML = '<button type="button" class="x" aria-label="Close">✕</button><div class="rm-empty">' + esc(r.message || 'Could not load the bill') + '</div>'; return; }
      drawBill(ov, box, r);
    }).catch(function () { box.innerHTML = '<button type="button" class="x" aria-label="Close">✕</button><div class="rm-empty">Network error. Please try again.</div>'; });
  }

  function drawBill(ov, box, r) {
    var b = r.bill, can = S.data.can_edit && !b.paid, dis = can ? '' : ' disabled';
    var extras = b.extras.slice();
    var bedPrice = +S.data.settings.extra_bed || 0;
    var link = location.origin + location.pathname.replace(/\/views\/[^\/]*$/, '/website/room_bill.php') + '?r=' + encodeURIComponent(r.rid) + '&b=' + b.id + '&k=' + r.code;
    var cur = b.currency;
    function m(n) { return cur + (+n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function exRows() {
      return extras.map(function (x, i) {
        return '<div class="rm-ex"><input data-exl="' + i + '" value="' + esc(x.label) + '" maxlength="80" placeholder="What for"' + dis + '>' +
          '<input data-exa="' + i + '" type="number" min="0" value="' + esc(x.amount) + '" placeholder="Amount"' + dis + '>' + (can ? '<button type="button" data-exdel="' + i + '" aria-label="Remove">✕</button>' : '<span></span>') + '</div>';
      }).join('');
    }
    var sum = '<div class="rm-sum">' + b.lines.map(function (l) { return '<div><span>' + esc(l.label) + (l.detail ? ' <small>(' + esc(l.detail) + ')</small>' : '') + '</span><span>' + m(l.amount) + '</span></div>'; }).join('') +
      (b.discount > 0 ? '<div><span>Discount</span><span>−' + m(b.discount) + '</span></div>' : '') +
      b.tax_lines.map(function (t) { return '<div><span>' + esc(t.label) + (b.tax_inclusive ? ' (included)' : '') + '</span><span>' + m(t.amount) + '</span></div>'; }).join('') +
      '<div><b>Room total</b><b>' + m(b.stay_total) + '</b></div>' +
      (b.food.length ? '<div><span>Room service (' + b.food.length + ' order' + (b.food.length === 1 ? '' : 's') + ')</span><span>' + m(b.food_total) + '</span></div>' : '') +
      '<div class="t"><span>Grand total</span><span>' + m(b.grand_total) + '</span></div>' +
      (b.advance > 0 ? '<div><span>Advance</span><span>−' + m(b.advance) + '</span></div>' : '') +
      (b.paid ? '<div><span>Paid' + (b.pay_method ? ' · ' + esc(b.pay_method) : '') + '</span><span>' + m(b.grand_total - b.advance - b.balance) + '</span></div>' : '') +
      '<div class="t"><span>' + (b.paid ? 'Still to pay (room service after settling)' : 'Balance to pay') + '</span><span>' + m(b.balance) + '</span></div></div>';
    box.innerHTML = '<button type="button" class="x" aria-label="Close">✕</button><h3>Bill · ' + esc(b.guest.name) + '</h3>' +
      '<p class="rm-hint" style="margin:-8px 0 12px">' + esc(b.stay.type) + (b.stay.room_numbers ? ' · Room ' + esc(b.stay.room_numbers) : '') + ' · ' + day(b.stay.in) + ' → ' + day(b.stay.out) + ' · ' + b.stay.nights + ' night' + (b.stay.nights === 1 ? '' : 's') +
      (b.bill_label ? ' · Bill ' + esc(b.bill_label) : '') + '</p>' +
      (b.paid ? '<p class="rm-hint" style="margin-bottom:10px">This bill is paid.' + (b.balance > 0 ? ' Room service was ordered after that: mark the stay unpaid, then settle it again.' : ' Mark it unpaid to change it.') + '</p>' : '') +
      '<div class="g"><div><label>Rate a night</label><input id="rbRate" type="number" min="0" value="' + esc(b.lines[0].rate) + '"' + dis + '></div>' +
      '<div><label>Discount (' + esc(cur) + ')</label><input id="rbDisc" type="number" min="0" value="' + esc(b.discount || '') + '"' + dis + '></div></div>' +
      '<h4>Extra charges</h4><div id="rbEx">' + exRows() + '</div>' +
      (can ? '<div class="rm-qx">' + EXTRAS.map(function (x) { return '<button type="button" data-exadd="' + esc(x) + '">+ ' + esc(x) + '</button>'; }).join('') + '<button type="button" data-exadd="">+ Other</button></div>' : '') +
      '<div class="g" style="margin-top:12px"><div><label>Advance received (' + esc(cur) + ')</label><input id="rbAdv" type="number" min="0" value="' + esc(b.advance || '') + '"' + dis + '></div>' +
      '<div><label>Guest email (optional)</label><input id="rbEmail" type="email" value="' + esc(b.guest.email) + '"' + dis + '></div></div>' +
      '<div class="g"><div><label>Company name (optional)</label><input id="rbCo" maxlength="150" value="' + esc(b.guest.company) + '"' + dis + '></div>' +
      '<div><label>Company ' + (S.data.india ? 'GSTIN' : 'tax ID') + ' (optional)</label><input id="rbCoTax" maxlength="30" value="' + esc(b.guest.company_tax_id) + '"' + dis + '></div></div>' +
      sum +
      '<div class="ft">' + (can ? '<button type="button" class="rm-b plain" id="rbSave">Save changes</button>' : '') +
      '<a class="rm-b plain" href="../website/room_bill.php?b=' + b.id + '" target="_blank" rel="noopener"><span class="material-symbols-rounded">print</span>Print / PDF</a>' +
      '<a class="rm-b plain" target="_blank" rel="noopener" href="https://wa.me/' + esc(r.wa) + '?text=' + encodeURIComponent('Hello ' + b.guest.name + ', here is your bill from ' + b.hotel.name + ': ' + link) + '"><span class="material-symbols-rounded">chat</span>Send on WhatsApp</a>' +
      (can ? '<button type="button" class="rm-b primary" id="rbPaid"><span class="material-symbols-rounded">payments</span>Mark paid</button>' : '') + '</div>';
    if (!can) return;
    function read() {
      return { rate: $('rbRate').value, discount: $('rbDisc').value, advance: $('rbAdv').value, email: $('rbEmail').value, company_name: $('rbCo').value, company_tax_id: $('rbCoTax').value,
        extras: extras.filter(function (x) { return x.label && +x.amount > 0; }) };
    }
    function save() {
      return post({ action: 'save_bill', id: b.id, bill: read() }).then(function (x) {
        if (!x.success) { notify(x.message || 'Could not save the bill', 'error'); return null; }
        return x;
      });
    }
    box.oninput = function (e) {
      var ds = e.target.dataset;
      if (ds.exl !== undefined) extras[+ds.exl].label = e.target.value;
      else if (ds.exa !== undefined) extras[+ds.exa].amount = e.target.value;
    };
    box.onclick = function (e) {
      var el = e.target.closest('[data-exadd]');
      if (el) {
        var label = el.dataset.exadd;
        extras.push({ label: label, amount: label === 'Extra bed' && bedPrice ? bedPrice * b.stay.nights : '' });
        $('rbEx').innerHTML = exRows();
        var last = $('rbEx').querySelectorAll(label ? '[data-exa]' : '[data-exl]');
        if (last.length) last[last.length - 1].focus();
        return;
      }
      if ((el = e.target.closest('[data-exdel]'))) { extras.splice(+el.dataset.exdel, 1); $('rbEx').innerHTML = exRows(); }
    };
    $('rbSave').addEventListener('click', function () {
      var btn = this; btn.disabled = true;
      save().then(function (x) { btn.disabled = false; if (x) { notify('Bill saved', 'success'); r.bill = x.bill; drawBill(ov, box, r); } })
        .catch(function () { btn.disabled = false; notify('Network error. Please try again.', 'error'); });
    });
    $('rbPaid').addEventListener('click', function () {
      var btn = this; btn.disabled = true;
      save().then(function (x) {
        btn.disabled = false;
        if (!x) return;
        ov.remove();
        settleModal(b.id, x.bill.balance, cur);
      }).catch(function () { btn.disabled = false; notify('Network error. Please try again.', 'error'); });
    });
  }

  function settleModal(id, balance, cur) {
    var ov = modal('<h3>Mark the stay paid</h3>' +
      '<p style="font-size:15px;margin-bottom:12px">Balance to collect: <b>' + esc(cur) + (+balance || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }) + '</b></p>' +
      '<div class="f"><label>Paid by</label><div class="rm-am">' + PAY.map(function (p, i) { return '<label><input type="radio" name="rmPay" value="' + p + '"' + (i === 0 ? ' checked' : '') + '>' + p + '</label>'; }).join('') + '</div></div>' +
      '<p class="rm-hint">Room service orders on this bill are marked paid too.</p>' +
      '<div class="ft"><button type="button" class="rm-b plain" data-close>Cancel</button><button type="button" class="rm-b primary" id="rmPaySave">Mark paid</button></div>');
    $('rmPaySave').addEventListener('click', function () {
      var btn = this; btn.disabled = true;
      post({ action: 'paid', id: id, paid: 1, method: (ov.querySelector('input[name=rmPay]:checked') || {}).value || 'Cash' }).then(function (d) {
        btn.disabled = false;
        if (!d.success) { notify(d.message || 'Could not update', 'error'); return; }
        ov.remove(); notify('Marked paid', 'success'); load();
      }).catch(function () { btn.disabled = false; notify('Network error. Please try again.', 'error'); });
    });
  }

  // ───────────── Room service QR codes ─────────────

  /** The menu link a room's QR code opens: the table QR link (studio-ui.js SX.tableUrl) with ?room= instead of ?table= */
  function roomUrl(param) {
    var base = window.SX && window.SX.tableUrl ? window.SX.tableUrl('__T__') : location.origin + '/?table=__T__';
    return base.replace(/([?&])table=__T__/, '$1room=' + encodeURIComponent(param));
  }
  function qrImg(url, size) { return 'https://api.qrserver.com/v1/create-qr-code/?size=' + size + 'x' + size + '&margin=8&data=' + encodeURIComponent(url); }

  function loadQr() {
    getJson('?qr=1').then(function (r) {
      var body = $('rmTabBody');
      if (!body || S.tab !== 'qr') return;
      if (!r.success) { body.innerHTML = '<div class="rm-empty">' + esc(r.message || 'Could not load') + '</div>'; return; }
      S.qr = r.rooms;
      var st = S.data.settings;
      var h = '<p class="rm-hint" style="font-size:13px;margin-bottom:12px">Print one QR code for each room. Guests scan it to order food to the room' +
        (st.room_charge ? ', and the guest checked in to that room can add it to the room bill (they type the phone number the room was booked with)' : '') +
        '. Orders reach your kitchen like any website order, marked "ROOM SERVICE - Room …".</p>';
      if (!st.room_service) h += '<div class="rm-off"><span><b>Room service is off.</b> Switch it on in Settings for these codes to work.</span><button type="button" class="rm-b primary" data-tab="settings">Settings</button></div>';
      if (!r.rooms.length) { body.innerHTML = h + '<div class="rm-empty">Add room numbers to your room types (Room types › Edit) to make their QR codes.</div>'; return; }
      h += '<div class="rm-chips"><button type="button" class="rm-b primary" data-qrprint="all"><span class="material-symbols-rounded">print</span>Print all</button>' +
        (S.data.can_edit ? '<button type="button" class="rm-b off" data-rm="new-qr">Make new QR codes</button>' : '') + '</div>';
      h += '<div class="rm-qrs">' + r.rooms.map(function (q, i) {
        var url = roomUrl(q.param);
        return '<div class="rm-qr"><img src="' + esc(qrImg(url, 280)) + '" alt="QR code for room ' + esc(q.room) + '" loading="lazy"><b>Room ' + esc(q.room) + '</b><small>' + esc(q.type) + '</small>' +
          '<div class="rm-acts" style="justify-content:center;margin-top:8px"><button type="button" class="rm-b plain" data-qrprint="' + i + '"><span class="material-symbols-rounded">print</span>Print</button>' +
          '<a class="rm-b plain" href="' + esc(url) + '" target="_blank" rel="noopener"><span class="material-symbols-rounded">open_in_new</span>Open</a></div></div>';
      }).join('') + '</div>';
      body.innerHTML = h;
    }).catch(function () { var body = $('rmTabBody'); if (body) body.innerHTML = '<div class="rm-empty">Network error. <a href="#" data-tab="qr">Try again</a></div>'; });
  }

  function printQr(which) {
    var list = which === 'all' ? S.qr : [S.qr[+which]];
    var w = window.open('', '_blank');
    if (!w) { notify('Allow pop-ups to print', 'error'); return; }
    w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Room service QR codes</title><style>body{font-family:Arial,sans-serif;margin:0}' +
      '.c{width:90mm;height:115mm;border:1px dashed #bbb;display:inline-flex;flex-direction:column;align-items:center;justify-content:center;margin:4mm;page-break-inside:avoid;text-align:center;vertical-align:top}' +
      '.c img{width:62mm;height:62mm}.c h1{font-size:24px;margin:8px 0 2px}.c p{margin:2px 0;color:#444;font-size:14px}</style></head><body>' +
      list.map(function (q) {
        return '<div class="c"><img src="' + esc(qrImg(roomUrl(q.param), 600)) + '"><h1>Room ' + esc(q.room) + '</h1><p>Scan to order food to your room</p></div>';
      }).join('') + '<script>window.onload=function(){setTimeout(function(){window.print()},400)}<\/script></body></html>');
    w.document.close();
  }

  // ───────────── Reports ─────────────

  function rangeDates(key) {
    var t = new Date(S.data.today + 'T00:00:00'), y = t.getFullYear(), mo = t.getMonth();
    if (key === 'last') return [ymd(new Date(y, mo - 1, 1)), ymd(new Date(y, mo, 0))];
    if (key === '30') return [addDays(S.data.today, -29), S.data.today];
    if (key === 'next30') return [S.data.today, addDays(S.data.today, 29)];
    if (key === 'year') return [y + '-01-01', y + '-12-31'];
    if (key === 'custom' && S.custom) return S.custom;
    return [ymd(new Date(y, mo, 1)), ymd(new Date(y, mo + 1, 0))];
  }

  function loadReport() {
    var r = rangeDates(S.range);
    getJson('?report=1&from=' + r[0] + '&to=' + r[1]).then(function (x) {
      var body = $('rmTabBody');
      if (!body || S.tab !== 'reports') return;
      if (!x.success) { body.innerHTML = '<div class="rm-empty">' + esc(x.message || 'Could not load the report') + '</div>'; return; }
      S.report = x.report;
      body.innerHTML = renderReport(x.report);
    }).catch(function () { var body = $('rmTabBody'); if (body) body.innerHTML = '<div class="rm-empty">Network error. <a href="#" data-tab="reports">Try again</a></div>'; });
  }

  function renderReport(p) {
    var ranges = [['month', 'This month'], ['last', 'Last month'], ['30', 'Last 30 days'], ['next30', 'Next 30 days'], ['year', 'This year'], ['custom', 'Pick dates']];
    var h = '<div class="rm-chips">' + ranges.map(function (r) { return '<button type="button" data-range="' + r[0] + '" class="' + (S.range === r[0] ? 'on' : '') + '">' + r[1] + '</button>'; }).join('') +
      (S.range === 'custom' ? '<input type="date" id="rmFrom" value="' + p.from + '" style="padding:6px 8px;border:1px solid #d1d5db;border-radius:8px"> – <input type="date" id="rmTo" value="' + p.to + '" style="padding:6px 8px;border:1px solid #d1d5db;border-radius:8px"><button type="button" class="rm-b primary" data-rm="custom-go">Show</button>' : '') +
      '<button type="button" class="rm-b plain" data-rm="csv"><span class="material-symbols-rounded">download</span>CSV</button></div>';
    function tile(label, val, sub) { return '<div class="rm-stat"><small>' + label + '</small><b>' + val + '</b>' + (sub ? '<em>' + sub + '</em>' : '') + '</div>'; }
    h += '<div class="rm-kpis">' +
      tile('Occupancy', p.occupancy + '%', p.sold + ' of ' + p.available + ' room nights') +
      tile('Average rate (ADR)', money(p.adr), 'a room night sold') +
      tile('RevPAR', money(p.revpar), 'room revenue ÷ room nights you had') +
      tile('Room revenue', money(p.revenue), p.extras > 0 ? '+ ' + money(p.extras) + ' extra charges' : 'before tax') +
      tile('Stays', p.stays, p.stays ? 'average ' + p.avg_nights + ' night' + (p.avg_nights === 1 ? '' : 's') : '') +
      tile('Room service', money(p.room_service.total), p.room_service.orders + ' order' + (p.room_service.orders === 1 ? '' : 's') + (p.room_service.on_bill > 0 ? ' · ' + money(p.room_service.on_bill) + ' on room bills' : '')) +
      tile('Cancelled / declined', p.cancelled + ' / ' + p.declined, p.pending ? p.pending + ' request' + (p.pending === 1 ? '' : 's') + ' waiting' : '') +
      tile('To collect now', money(p.unpaid.total), p.unpaid.stays + ' stay' + (p.unpaid.stays === 1 ? '' : 's') + ' checked in / out, not paid') + '</div>';
    // occupancy night by night: one series, one hue; hover a bar for the night's number
    h += '<div class="rm-card"><h4>Occupancy night by night</h4><div class="rm-chart" role="img" aria-label="Occupancy for each night from ' + esc(day(p.from)) + ' to ' + esc(day(p.to)) + '">' + p.nights.map(function (n) {
      var pct = Math.min(100, n.pct);
      return '<div style="height:' + (pct ? Math.max(2, pct) : 0) + '%" data-tip="' + esc(day(n.day) + ': ' + n.pct + '% · ' + n.sold + ' room' + (n.sold === 1 ? '' : 's')) + '"></div>';
    }).join('') + '</div><div class="rm-axis"><span>' + esc(day(p.from)) + '</span><span>Full height = every room taken</span><span>' + esc(day(p.to)) + '</span></div></div>';
    h += '<div class="rm-card"><h4>By room type</h4><div class="rm-tblw"><table class="rm-tbl"><thead><tr><th>Room type</th><th class="r">Rooms</th><th class="r">Stays</th><th class="r">Nights sold</th><th class="r">Occupancy</th><th class="r">Revenue</th><th class="r">ADR</th></tr></thead><tbody>' +
      p.by_type.map(function (t) {
        return '<tr><td>' + esc(t.name) + '</td><td class="r">' + t.rooms + '</td><td class="r">' + t.stays + '</td><td class="r">' + t.sold + '</td><td class="r">' + t.occupancy + '%</td><td class="r">' + money(t.revenue) + '</td><td class="r">' + (t.sold ? money(t.revenue / t.sold) : '–') + '</td></tr>';
      }).join('') + '</tbody></table></div></div>';
    var src = Object.keys(p.by_source);
    h += '<div class="rm-card"><h4>Where bookings came from</h4>' + (src.length ? '<table class="rm-tbl"><tbody>' + src.map(function (k) { return '<tr><td>' + esc(k) + '</td><td class="r">' + p.by_source[k] + ' stay' + (p.by_source[k] === 1 ? '' : 's') + '</td></tr>'; }).join('') + '</tbody></table>' : '<p class="rm-hint">No stays in these dates.</p>') +
      (p.discounts > 0 ? '<p class="rm-hint" style="margin-top:8px">Discounts given: ' + money(p.discounts) + '</p>' : '') + '</div>';
    return h;
  }

  function csv() {
    var p = S.report;
    if (!p) return;
    var rows = [['Night', 'Rooms sold', 'Occupancy %']].concat(p.nights.map(function (n) { return [n.day, n.sold, n.pct]; }));
    rows.push([], ['Room type', 'Rooms', 'Stays', 'Nights sold', 'Occupancy %', 'Revenue']);
    p.by_type.forEach(function (t) { rows.push([t.name, t.rooms, t.stays, t.sold, t.occupancy, t.revenue]); });
    rows.push([], ['Occupancy %', p.occupancy], ['ADR', p.adr], ['RevPAR', p.revpar], ['Room revenue', p.revenue], ['Extra charges', p.extras], ['Room service', p.room_service.total]);
    var text = rows.map(function (r) { return r.map(function (c) { c = String(c == null ? '' : c); return /[",\n]/.test(c) ? '"' + c.replace(/"/g, '""') + '"' : c; }).join(','); }).join('\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([text], { type: 'text/csv' }));
    a.download = 'rooms-report-' + p.from + '-to-' + p.to + '.csv';
    document.body.appendChild(a); a.click(); a.remove();
  }

  // ───────────── Settings ─────────────

  function renderSettings() {
    var d = S.data, s = d.settings, dis = d.can_edit ? '' : ' disabled';
    var cancelNames = { free_24h: 'Free up to 24 hours before', free_48h: 'Free up to 48 hours before', free_7d: 'Free up to 7 days before', no_free: 'No free cancellation', custom: 'My own words' };
    function sw(id, on, label, hint) { return '<label class="sw"><input type="checkbox" id="' + id + '"' + (on ? ' checked' : '') + dis + '><span>' + label + (hint ? '<br><small class="rm-hint">' + hint + '</small>' : '') + '</span></label>'; }
    var autoTax = !d.india && s.tax_mode === 'auto';
    return '<div class="rm-set">' +
      '<div class="rm-card"><h4>Check-in and check-out</h4><div class="g"><div><label class="l">Check-in from</label><input type="time" id="rsIn" value="' + esc(s.checkin_time) + '"' + dis + '></div>' +
      '<div><label class="l">Check-out by</label><input type="time" id="rsOut" value="' + esc(s.checkout_time) + '"' + dis + '></div></div>' +
      '<div class="f"><label class="l">Extra bed, a night (' + esc(d.currency) + ', 0 = not offered)</label><input type="number" min="0" id="rsBed" value="' + esc(s.extra_bed) + '"' + dis + '><div class="rm-hint">Guests can ask for one when booking; add it to the bill with one tap.</div></div></div>' +
      '<div class="rm-card"><h4>Cancellation policy</h4><div class="f"><select id="rsCancel"' + dis + '>' + Object.keys(d.cancel_options).map(function (k) { return '<option value="' + k + '"' + (s.cancel === k ? ' selected' : '') + '>' + esc(cancelNames[k] || k) + '</option>'; }).join('') + '</select>' +
      '<div class="rm-hint" id="rsCancelText">' + esc(d.cancel_options[s.cancel] || '') + '</div></div>' +
      '<div class="f" id="rsCustomBox" style="display:' + (s.cancel === 'custom' ? '' : 'none') + '"><label class="l">Your cancellation policy</label><textarea id="rsCustom" rows="3" maxlength="600"' + dis + '>' + esc(s.cancel_text) + '</textarea></div></div>' +
      '<div class="rm-card"><h4>House rules</h4><div class="rm-am" id="rsRuleBox" style="margin-bottom:10px">' + d.rule_options.map(function (r) {
        return '<label><input type="checkbox" value="' + r.key + '"' + (s.rules.indexOf(r.key) !== -1 ? ' checked' : '') + dis + '>' + esc(r.label) + '</label>';
      }).join('') + '</div><label class="l">Your own rules (one a line)</label><textarea id="rsRules" rows="4" maxlength="2400" placeholder="Gate closes at 11 PM&#10;Pool open 7 AM – 8 PM"' + dis + '>' + esc(s.house_rules) + '</textarea>' +
      '<div class="rm-hint">Shown to guests on your Rooms page before they book.</div></div>' +
      '<div class="rm-card"><h4>Room tax</h4><div class="f"><select id="rsTax"' + dis + '>' +
      (d.india ? '<option value="auto"' + (s.tax_mode === 'auto' ? ' selected' : '') + '>GST for hotel rooms (5% up to ₹7,500 a night, 18% above)</option>' : '') +
      '<option value="restaurant"' + (s.tax_mode === 'restaurant' || autoTax ? ' selected' : '') + '>Same tax as my food bills</option>' +
      '<option value="fixed"' + (s.tax_mode === 'fixed' ? ' selected' : '') + '>My own rate</option><option value="none"' + (s.tax_mode === 'none' ? ' selected' : '') + '>No tax on rooms</option></select></div>' +
      '<div class="f" id="rsPctBox" style="display:' + (s.tax_mode === 'fixed' ? '' : 'none') + '"><label class="l">Tax rate (%)</label><input type="number" min="0" max="50" step="0.01" id="rsPct" value="' + esc(s.tax_percent) + '"' + dis + '></div>' +
      sw('rsIncl', s.tax_inclusive, 'My room prices already include tax', 'The bill then shows the tax inside the price instead of adding it.') +
      (d.india ? '<p class="rm-hint">Check the rate with your CA. Your GSTIN is printed on the bill when it is on in Settings › Tax and bill.</p>' : '') + '</div>' +
      '<div class="rm-card"><h4>Bill</h4><div class="g"><div><label class="l">Bill title</label><input id="rsTitle" maxlength="40" placeholder="Tax Invoice" value="' + esc(s.bill_title) + '"' + dis + '><div class="rm-hint">Empty: "Tax Invoice" when there is tax, else "Bill"</div></div>' +
      '<div><label class="l">Bill number starts with</label><input id="rsPrefix" maxlength="10" placeholder="RM-" value="' + esc(s.bill_prefix) + '"' + dis + '><div class="rm-hint">Then 1, 2, 3… (RM-1, RM-2)</div></div></div>' +
      '<div class="f"><label class="l">Note at the bottom</label><textarea id="rsFooter" rows="2" maxlength="400"' + dis + '>' + esc(s.bill_footer) + '</textarea></div>' +
      sw('rsSign', s.bill_signature, 'Signature line') + sw('rsFood', s.bill_food, 'List each room service order on the bill', 'Off: one "Room service" total line.') +
      sw('rsBillRules', s.bill_rules, 'Print check-in times, cancellation and house rules on the bill') + '</div>' +
      '<div class="rm-card"><h4>Room service</h4>' + sw('rsSvc', s.room_service, 'Guests can order food from the QR code in their room', 'Print the codes from the Room service QR tab.') +
      sw('rsCharge', s.room_charge, 'Let the checked-in guest add food to the room bill', 'Paid at checkout with the stay. They type the phone number the room was booked with.') + '</div>' +
      (d.can_edit ? '<div><button type="button" class="rm-b primary" data-rm="save-settings" style="padding:10px 20px">Save settings</button></div>' : '') + '</div>';
  }

  function saveSettings(btn) {
    var rules = Array.prototype.map.call(document.querySelectorAll('#rsRuleBox input:checked'), function (c) { return c.value; });
    btn.disabled = true;
    post({ action: 'save_settings', settings: {
      checkin_time: $('rsIn').value, checkout_time: $('rsOut').value, extra_bed: $('rsBed').value, cancel: $('rsCancel').value, cancel_text: $('rsCustom').value,
      rules: rules, house_rules: $('rsRules').value, tax_mode: $('rsTax').value, tax_percent: $('rsPct').value, tax_inclusive: $('rsIncl').checked,
      bill_title: $('rsTitle').value, bill_prefix: $('rsPrefix').value, bill_footer: $('rsFooter').value, bill_signature: $('rsSign').checked, bill_food: $('rsFood').checked,
      bill_rules: $('rsBillRules').checked, room_service: $('rsSvc').checked, room_charge: $('rsCharge').checked } }).then(function (r) {
      btn.disabled = false;
      if (!r.success) { notify(r.message || 'Could not save', 'error'); return; }
      S.data.settings = r.settings; notify('Room settings saved', 'success'); render();
    }).catch(function () { btn.disabled = false; notify('Network error. Please try again.', 'error'); });
  }

  function setEnabled(on, fromToggle) {
    return post({ action: 'enable', on: on ? 1 : 0 }).then(function (d) {
      if (!d.success) { notify(d.message || 'Could not save', 'error'); if (fromToggle) fromToggle.checked = !on; return; }
      var li = $('roomsNavItem'); if (li) li.style.display = on ? '' : 'none';
      var tg = $('enableRoomsToggle'); if (tg) tg.checked = on;
      notify(on ? 'Rooms is on. Guests can book on your website.' : 'Rooms is off on your website.', 'success');
      if (S.data) { S.data.enabled = on; if ($('roomsRoot') && $('roomsPage') && $('roomsPage').classList.contains('active')) render(); }
    }).catch(function () { if (fromToggle) fromToggle.checked = !on; notify('Network error. Please try again.', 'error'); });
  }

  document.addEventListener('click', function (e) {
    // the Rooms page, and the windows it opened for a stay (from the Calendar, the bill)
    if (!e.target.closest('#roomsPage') && !e.target.closest('.rm-ov.rm-detail')) return;
    var el;
    if ((el = e.target.closest('[data-tab]'))) { e.preventDefault(); S.tab = el.dataset.tab; render(); }
    else if ((el = e.target.closest('[data-filter]'))) { S.filter = el.dataset.filter; render(); }
    else if ((el = e.target.closest('[data-status]'))) setStatus(+el.dataset.id, el.dataset.status);
    else if ((el = e.target.closest('[data-unpaid]'))) {
      if (!window.confirm('Mark this stay unpaid again?')) return;
      post({ action: 'paid', id: +el.dataset.unpaid, paid: 0 }).then(function (d) { if (d.success) load(); else notify(d.message || 'Could not update', 'error'); });
    }
    else if ((el = e.target.closest('[data-bill]'))) billModal(+el.dataset.bill);
    else if ((el = e.target.closest('[data-assign]'))) { var ab = byId(+el.dataset.assign); if (ab) assignModal(ab, false); }
    else if ((el = e.target.closest('[data-open]'))) openBooking(+el.dataset.open);
    else if ((el = e.target.closest('[data-free]'))) {
      if (!S.data.can_edit) return;
      var p = el.dataset.free.split('|');
      addBooking({ type: +p[0], room: p[1], in: p[2] });
    }
    else if ((el = e.target.closest('[data-cal]'))) { var n = +el.dataset.cal; S.calFrom = n ? addDays(S.calFrom, n) : S.data.today; render(); }
    else if ((el = e.target.closest('[data-edit]'))) editType(S.data.types.filter(function (t) { return t.id === +el.dataset.edit; })[0]);
    else if ((el = e.target.closest('[data-del]'))) {
      if (!window.confirm('Delete this room type? Its photos are removed too.')) return;
      post({ action: 'delete_type', id: +el.dataset.del }).then(function (d) { if (d.success) { notify('Room type deleted', 'success'); load(); } else notify(d.message || 'Could not delete', 'error'); });
    }
    else if ((el = e.target.closest('[data-qrprint]'))) printQr(el.dataset.qrprint);
    else if ((el = e.target.closest('[data-range]'))) {
      S.range = el.dataset.range;
      if (S.range === 'custom' && !S.custom) S.custom = rangeDates('month');
      render();
    }
    else if (e.target.closest('[data-rm="custom-go"]')) { S.custom = [$('rmFrom').value, $('rmTo').value]; render(); }
    else if (e.target.closest('[data-rm="csv"]')) csv();
    else if (e.target.closest('[data-rm="save-settings"]')) saveSettings(e.target.closest('[data-rm="save-settings"]'));
    else if (e.target.closest('[data-rm="new-qr"]')) {
      if (!window.confirm('Make new QR codes for every room? The ones you printed stop working, so print and put up the new ones.')) return;
      post({ action: 'new_qr' }).then(function (d) { if (d.success) { notify('New QR codes made. Print them now.', 'success'); render(); } else notify(d.message || 'Could not update', 'error'); });
    }
    else if (e.target.closest('[data-rm="enable"]')) setEnabled(true);
    else if (e.target.closest('[data-rm="add-type"]') || e.target.closest('#rmAddTypeBtn')) { if (S.data) editType(); }
    else if (e.target.closest('#rmAddBookingBtn')) { if (S.data) addBooking(); }
  });
  document.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'enableRoomsToggle') setEnabled(e.target.checked, e.target);
    if (e.target && e.target.id === 'rsCancel' && S.data) {
      $('rsCustomBox').style.display = e.target.value === 'custom' ? '' : 'none';
      $('rsCancelText').textContent = S.data.cancel_options[e.target.value] || '';
    }
    if (e.target && e.target.id === 'rsTax') $('rsPctBox').style.display = e.target.value === 'fixed' ? '' : 'none';
  });

  window.RGRooms = { load: load };
})();
