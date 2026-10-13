/*
 * Reservations (#reservationsPage): day strip, numbers, status tabs, search, and the bookings of each day as a
 * timeline with one-tap actions (confirm / decline, check in / no show, complete, assign table, call, WhatsApp, edit).
 *
 * Data and saving are unchanged (script.js): loadReservations() → api/get_reservations.php → RGResv.render(list);
 * status: window.updateReservationStatus(id, status); table: window.assignTable(id); edit: window.editReservation(...);
 * new: #addReservationBtn (the existing form). Styles rv-* in assets/css/studio-ui.css.
 */
(function () {
  'use strict';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var store = { get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} } };
  var S = { list: [], day: 'today', status: store.get('rv-status') || 'all', q: '', date: '' };

  function ymd(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function addDays(n) { var d = new Date(); d.setHours(0, 0, 0, 0); d.setDate(d.getDate() + n); return d; }
  var TODAY = function () { return ymd(addDays(0)); };
  // "7:30 PM" / "19:30" → minutes after midnight
  function mins(t) {
    var m = String(t || '').trim().match(/^(\d{1,2}):(\d{2})\s*(AM|PM)?$/i);
    if (!m) return 0;
    var h = +m[1] % 12; if (!m[3]) h = +m[1]; else if (/pm/i.test(m[3])) h += 12;
    return h * 60 + +m[2];
  }
  function niceTime(t) { var x = mins(t), h = Math.floor(x / 60), m = x % 60; return ((h % 12) || 12) + ':' + String(m).padStart(2, '0') + ' ' + (h < 12 ? 'AM' : 'PM'); }
  function dayLabel(s) {
    var t = TODAY(), tm = ymd(addDays(1)), y = ymd(addDays(-1));
    var d = new Date(s + 'T00:00'), w = d.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' });
    return s === t ? 'Today · ' + w : s === tm ? 'Tomorrow · ' + w : s === y ? 'Yesterday · ' + w : w;
  }
  var ST = {
    'Pending': ['wait', 'hourglass_top', 'To confirm'], 'Confirmed': ['ok', 'event_available', 'Confirmed'], 'Checked In': ['seat', 'event_seat', 'Seated'],
    'Completed': ['done', 'task_alt', 'Done'], 'Cancelled': ['bad', 'event_busy', 'Cancelled'], 'No Show': ['bad', 'person_off', 'No show']
  };
  var TABS = [['all', 'All'], ['Pending', 'To confirm'], ['Confirmed', 'Confirmed'], ['Checked In', 'Seated'], ['Completed', 'Done'], ['closed', 'Cancelled / no show']];
  function inStatus(r) { return S.status === 'all' || (S.status === 'closed' ? (r.status === 'Cancelled' || r.status === 'No Show') : r.status === S.status); }
  function inDay(r) {
    var d = r.reservation_date, t = TODAY();
    if (S.day === 'upcoming') return d >= t;
    if (S.day === 'past') return d < t;
    if (S.day === 'date') return d === S.date;
    return d === S.day || (S.day === 'today' && d === t);
  }
  function match(r) {
    if (!S.q) return true;
    return (r.customer_name + ' ' + r.phone + ' ' + (r.email || '') + ' ' + (r.table_number || '')).toLowerCase().indexOf(S.q) > -1;
  }
  function lateBy(r) {
    if (r.status !== 'Confirmed' && r.status !== 'Pending') return 0;
    if (r.reservation_date !== TODAY()) return 0;
    var n = new Date(), now = n.getHours() * 60 + n.getMinutes(), late = now - mins(r.time_slot);
    return late >= 10 ? late : 0;
  }

  function strip() {
    var t = TODAY(), counts = {};
    S.list.forEach(function (r) { if (r.status !== 'Cancelled' && r.status !== 'No Show') counts[r.reservation_date] = (counts[r.reservation_date] || 0) + 1; });
    var days = [];
    for (var i = 0; i < 7; i++) days.push(ymd(addDays(i)));
    var up = S.list.filter(function (r) { return r.reservation_date >= t && r.status !== 'Cancelled' && r.status !== 'No Show'; }).length;
    var cur = S.day === 'today' ? t : S.day;
    return '<div class="rv-days">' + days.map(function (d, i) {
      var dt = new Date(d + 'T00:00');
      return '<button type="button" class="rv-day' + (cur === d ? ' on' : '') + '" data-rv-day="' + d + '"><small>' + (i === 0 ? 'Today' : i === 1 ? 'Tomorrow' : dt.toLocaleDateString([], { weekday: 'short' })) + '</small><b>' + dt.getDate() + '</b><em>' + (counts[d] ? counts[d] + ' booking' + (counts[d] > 1 ? 's' : '') : '–') + '</em></button>';
    }).join('') +
      '<button type="button" class="rv-day wide' + (S.day === 'upcoming' ? ' on' : '') + '" data-rv-day="upcoming"><small>All</small><b>' + ic('event_upcoming') + '</b><em>' + up + ' upcoming</em></button>' +
      '<button type="button" class="rv-day wide' + (S.day === 'past' ? ' on' : '') + '" data-rv-day="past"><small>Earlier</small><b>' + ic('history') + '</b><em>past</em></button>' +
      '<label class="rv-day wide pick' + (S.day === 'date' ? ' on' : '') + '"><small>Pick a day</small><b>' + ic('calendar_month') + '</b><input type="date" data-rv-date value="' + esc(S.date) + '" aria-label="Pick a day"></label>' +
    '</div>';
  }
  function stats(rows) {
    var live = rows.filter(function (r) { return r.status !== 'Cancelled' && r.status !== 'No Show'; });
    var guests = live.reduce(function (a, r) { return a + (+r.no_of_guests || 0); }, 0);
    var pending = rows.filter(function (r) { return r.status === 'Pending'; }).length;
    var next = null;
    if (S.day === 'today' || S.day === TODAY()) {
      var n = new Date(), now = n.getHours() * 60 + n.getMinutes();
      live.filter(function (r) { return (r.status === 'Confirmed' || r.status === 'Pending') && mins(r.time_slot) >= now - 10; })
        .sort(function (a, b) { return mins(a.time_slot) - mins(b.time_slot); }).slice(0, 1).forEach(function (r) { next = r; });
    }
    var t = [['event', '#2563eb', 'Bookings', live.length], ['groups', '#7c3aed', 'Guests', guests], ['hourglass_top', pending ? '#d97706' : '#16a34a', 'To confirm', pending],
      ['schedule', '#0891b2', 'Next to arrive', next ? niceTime(next.time_slot) : '–', next ? esc(next.customer_name) + ' · ' + next.no_of_guests : '']];
    return '<div class="lg-stats rv-stats">' + t.map(function (x) { return '<div class="lg-stat" style="--c:' + x[1] + '"><span class="sx-ic material-symbols-rounded">' + x[0] + '</span><div><small>' + x[2] + '</small><b>' + x[3] + '</b>' + (x[4] ? '<span class="iv-sub">' + x[4] + '</span>' : '') + '</div></div>'; }).join('') + '</div>';
  }
  function actions(r) {
    var id = r.id, a = [];
    var btn = function (cls, icon, label, status) { return '<button type="button" class="rv-act ' + cls + '" data-rv-status="' + status + '" data-id="' + id + '">' + ic(icon) + label + '</button>'; };
    if (r.status === 'Pending') { a.push(btn('go', 'check', 'Confirm', 'Confirmed')); a.push(btn('no', 'close', 'Decline', 'Cancelled')); }
    else if (r.status === 'Confirmed') { a.push(btn('go', 'event_seat', 'Check in', 'Checked In')); a.push(btn('no', 'person_off', 'No show', 'No Show')); }
    else if (r.status === 'Checked In') { a.push(btn('go', 'task_alt', 'Complete', 'Completed')); }
    return a.join('');
  }
  function card(r) {
    var st = ST[r.status] || ['muted', 'event', r.status], late = lateBy(r), phone = String(r.phone || '').replace(/[^0-9+]/g, ''), wa = phone.replace(/^\+/, '');
    if (wa.length === 10) wa = '91' + wa; else if (wa.length === 11 && wa.charAt(0) === '0') wa = '91' + wa.slice(1);
    var open = r.status === 'Pending' || r.status === 'Confirmed' || r.status === 'Checked In';
    return '<article class="rv-card ' + st[0] + (late ? ' late' : '') + '" data-rv-id="' + r.id + '">' +
      '<div class="rv-time"><b>' + esc(niceTime(r.time_slot)) + '</b><small>' + esc(r.meal_type || '') + '</small></div>' +
      '<div class="rv-main">' +
        '<div class="rv-top"><b class="rv-name">' + esc(r.customer_name) + '</b><span class="sx-pill ' + st[0] + '">' + ic(st[1]) + st[2] + '</span>' + (late ? '<span class="sx-pill bad">' + ic('alarm') + 'Late ' + late + ' min</span>' : '') + '</div>' +
        '<div class="rv-meta"><span>' + ic('groups') + r.no_of_guests + ' guest' + (r.no_of_guests == 1 ? '' : 's') + '</span>' +
          (r.table_number ? '<span class="rv-table">' + ic('table_restaurant') + 'Table ' + esc(r.table_number) + (r.area_name ? ' · ' + esc(r.area_name) : '') + '</span>' : (open ? '<button type="button" class="rv-assign" data-rv-assign="' + r.id + '">' + ic('add') + 'Assign table</button>' : '')) +
          (phone ? '<a href="tel:' + esc(phone) + '">' + ic('call') + esc(r.phone) + '</a>' : '') +
          (wa.length >= 11 && open ? '<a href="https://wa.me/' + esc(wa) + '?text=' + encodeURIComponent('Hi ' + r.customer_name + ', about your table for ' + r.no_of_guests + ' at ' + niceTime(r.time_slot) + ' on ' + dayLabel(r.reservation_date) + '.') + '" target="_blank" rel="noopener">' + ic('chat') + 'WhatsApp</a>' : '') + '</div>' +
        (r.special_request ? '<div class="rv-req">' + ic('sticky_note_2') + esc(r.special_request) + '</div>' : '') +
      '</div>' +
      '<div class="rv-acts">' + actions(r) +
        '<button type="button" class="sx-icon-btn" data-rv-edit="' + r.id + '" title="Edit">' + ic('edit') + '</button>' +
        '<select class="rv-more" data-rv-change="' + r.id + '" title="Change status" aria-label="Change status"><option value="">More</option>' +
          Object.keys(ST).filter(function (k) { return k !== r.status; }).map(function (k) { return '<option value="' + k + '">' + ST[k][2] + '</option>'; }).join('') + '</select>' +
      '</div>' +
    '</article>';
  }

  function render(list) {
    if (list) S.list = list.slice();
    var root = document.getElementById('reservationList');
    if (!root) return;
    var page = document.getElementById('reservationsPage');
    var bar = page && page.querySelector('.rv-bar');
    if (page && !bar) {
      bar = document.createElement('div'); bar.className = 'rv-bar';
      root.parentNode.insertBefore(bar, root);
    }
    var inDays = S.list.filter(inDay), rows = inDays.filter(inStatus).filter(match);
    var counts = {}; inDays.forEach(function (r) { counts[r.status] = (counts[r.status] || 0) + 1; });
    var tabCount = function (k) { return k === 'all' ? inDays.length : k === 'closed' ? (counts['Cancelled'] || 0) + (counts['No Show'] || 0) : (counts[k] || 0); };
    if (bar) bar.innerHTML = strip() + stats(inDays) +
      '<div class="sx-toolbar rv-tools"><div class="sx-seg">' + TABS.map(function (t) { return '<button type="button" class="' + (S.status === t[0] ? 'on' : '') + '" data-rv-tab="' + t[0] + '">' + t[1] + ' <em>' + tabCount(t[0]) + '</em></button>'; }).join('') + '</div>' +
        '<div class="sx-search">' + ic('search') + '<input type="text" data-rv-q value="' + esc(S.q) + '" placeholder="Name, phone or table"></div></div>';
    root.className = 'menu-list rv-list';
    if (!S.list.length) { root.innerHTML = '<div class="sx-empty">' + ic('event') + '<b>No reservations yet</b><span>Bookings from your website and the ones you add here show up by day.</span><button type="button" class="btn btn-primary" data-rv-new>' + ic('add') + 'Add reservation</button></div>'; return; }
    if (!rows.length) { root.innerHTML = '<div class="sx-empty">' + ic('event_available') + '<b>' + (S.q ? 'Nothing matches “' + esc(S.q) + '”' : 'No bookings here') + '</b><span>Pick another day or status, or add a booking.</span><button type="button" class="btn btn-secondary" data-rv-new>' + ic('add') + 'Add reservation</button></div>'; return; }
    var past = S.day === 'past', by = {};
    rows.sort(function (a, b) { return (a.reservation_date < b.reservation_date ? -1 : a.reservation_date > b.reservation_date ? 1 : mins(a.time_slot) - mins(b.time_slot)) * (past ? -1 : 1); });
    rows.forEach(function (r) { (by[r.reservation_date] = by[r.reservation_date] || []).push(r); });
    root.innerHTML = Object.keys(by).map(function (d) {
      var g = by[d].filter(function (r) { return r.status !== 'Cancelled' && r.status !== 'No Show'; }).reduce(function (a, r) { return a + (+r.no_of_guests || 0); }, 0);
      return '<section class="rv-group"><h3>' + esc(dayLabel(d)) + '<small>' + by[d].length + ' booking' + (by[d].length > 1 ? 's' : '') + ' · ' + g + ' guests</small></h3><div class="rv-cards">' + by[d].map(card).join('') + '</div></section>';
    }).join('');
  }

  function find(id) { return S.list.filter(function (r) { return String(r.id) === String(id); })[0]; }
  document.addEventListener('click', function (e) {
    if (!e.target.closest('#reservationsPage')) return;
    var t = e.target.closest('[data-rv-day],[data-rv-tab],[data-rv-status],[data-rv-assign],[data-rv-edit],[data-rv-new]');
    if (!t) return;
    if (t.hasAttribute('data-rv-day')) { var v = t.getAttribute('data-rv-day'); S.day = v === TODAY() ? 'today' : v; render(); return; }
    if (t.hasAttribute('data-rv-tab')) { S.status = t.getAttribute('data-rv-tab'); store.set('rv-status', S.status); render(); return; }
    if (t.hasAttribute('data-rv-new')) { var add = document.getElementById('addReservationBtn'); if (add) add.click(); return; }
    if (t.hasAttribute('data-rv-assign')) { if (window.assignTable) window.assignTable(+t.getAttribute('data-rv-assign')); return; }
    if (t.hasAttribute('data-rv-edit')) {
      var r = find(t.getAttribute('data-rv-edit'));
      if (r && window.editReservation) window.editReservation(r.id, r.customer_name, r.reservation_date, r.time_slot, r.no_of_guests, r.meal_type || '', r.phone, r.email || '', r.special_request || '', r.table_id || '');
      return;
    }
    if (t.hasAttribute('data-rv-status')) {
      var id = +t.getAttribute('data-id'), st = t.getAttribute('data-rv-status');
      t.disabled = true; t.innerHTML = '<span class="loading-spinner"></span>';
      if (window.updateReservationStatus) window.updateReservationStatus(id, st);
    }
  });
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.matches && t.matches('[data-rv-change]') && t.value) { if (window.updateReservationStatus) window.updateReservationStatus(+t.getAttribute('data-rv-change'), t.value); return; }
    if (t.matches && t.matches('[data-rv-date]')) { S.date = t.value; S.day = t.value ? 'date' : 'today'; render(); }
  });
  document.addEventListener('input', function (e) {
    if (e.target.matches && e.target.matches('[data-rv-q]')) {
      S.q = e.target.value.toLowerCase().trim();
      var pos = e.target.selectionStart; render();
      var n = document.querySelector('[data-rv-q]'); if (n) { n.focus(); try { n.setSelectionRange(pos, pos); } catch (er) {} }
    }
  });
  // keep "Late" and "Next to arrive" current while the page is open
  setInterval(function () { var p = document.getElementById('reservationsPage'); if (p && p.classList.contains('active') && S.list.length && !document.querySelector('[data-rv-q]:focus')) render(); }, 60000);

  window.RGResv = { render: render };
})();
