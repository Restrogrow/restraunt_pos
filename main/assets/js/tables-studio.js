/*
 * Tables › Tables (api/tables_studio.php).
 *
 * Every table with its live status, grouped by area: search, filter by area or
 * status, grid or list view, edit in a preview editor (rename warns that the
 * printed QR code changes), switch a table off, see and print its QR code, and
 * change many at once (move, seats, on/off, delete). Refreshes every 30 s.
 * Shared look and helpers: assets/css/studio-ui.css, assets/js/studio-ui.js.
 */
(function () {
  'use strict';

  var API = '../api/tables_studio.php';
  var D = null, q = '', fArea = 0, fStatus = 'all', view = 'grid', selecting = false, sel = {}, edit = null, qrFor = null, pollT = null, delAsk = false;
  try { view = localStorage.getItem('rgTablesView') || 'grid'; } catch (e) {}

  function $(id) { return document.getElementById(id); }
  function esc(s) { return SX.esc(s); }
  function money(n) { return SX.money(n, D && D.currency); }
  function post(b) { return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) }).then(function (r) { return r.json(); }); }
  function area(id) { return D.areas.filter(function (a) { return a.id === id; })[0]; }
  function look(id) { var a = area(id); return SX.look(a, D.areas.indexOf(a)); }
  function mins(t) { return SX.minsBetween(t.since, D.now); }

  function load() {
    var el = $('tablesRoot'); if (!el) return Promise.resolve();
    if (!D) el.innerHTML = '<div class="sx-loading"><span></span><span></span><span></span></div>';
    return fetch(API, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.success) { el.innerHTML = '<div class="sx-empty"><span class="material-symbols-rounded">error</span><b>' + esc(d.message || 'Could not load tables') + '</b></div>'; return; }
      D = d; draw();
      clearTimeout(pollT);
      pollT = setTimeout(function () { if (SX.isActive('tablesPage') && !edit && !qrFor && !selecting) load(); }, 30000);
    }).catch(function () { if (!D) el.innerHTML = '<div class="sx-empty"><span class="material-symbols-rounded">wifi_off</span><b>Network error</b><span>Please try again.</span></div>'; });
  }

  function visible() {
    var s = q.trim().toLowerCase();
    return D.tables.filter(function (t) {
      if (fArea && t.area_id !== fArea) return false;
      if (fStatus !== 'all' && t.status !== fStatus) return false;
      if (s && String(t.number).toLowerCase().indexOf(s) === -1 && String((area(t.area_id) || {}).name || '').toLowerCase().indexOf(s) === -1) return false;
      return true;
    });
  }

  function statusPill(t) {
    if (t.status === 'busy') { var m = mins(t); return '<span class="sx-pill ' + (m > 360 ? 'warn' : 'bad') + '"><span class="material-symbols-rounded">schedule</span>Busy ' + SX.fmtMins(m) + (t.bill ? ' · ' + money(t.bill) : '') + '</span>'; }
    if (t.status === 'reserved') return '<span class="sx-pill wait"><span class="material-symbols-rounded">event</span>Reserved' + (t.slot ? ' ' + esc(t.slot) : '') + '</span>';
    if (t.status === 'off') return '<span class="sx-pill muted"><span class="material-symbols-rounded">block</span>Switched off</span>';
    return '<span class="sx-pill ok"><span class="material-symbols-rounded">check_circle</span>Free</span>';
  }
  function seatDots(n) { var s = ''; for (var i = 0; i < Math.min(n, 10); i++) s += '<i></i>'; return '<span class="tb-seats" title="' + n + ' seats">' + s + (n > 10 ? '<b>+' + (n - 10) + '</b>' : '') + '</span>'; }

  function card(t) {
    var L = look(t.area_id), a = area(t.area_id), on = !!sel[t.id];
    var stale = t.status === 'busy' && mins(t) > 360;
    if (view === 'list') {
      return '<div class="tb-row' + (on ? ' sel' : '') + '" data-tb="' + t.id + '" style="--c:' + L.color + '">' +
        (selecting ? '<label class="sx-check"><input type="checkbox" data-tb-sel="' + t.id + '"' + (on ? ' checked' : '') + '><span></span></label>' : '') +
        '<span class="tb-num">' + esc(t.number) + '</span><span class="tb-area"><span class="material-symbols-rounded">' + L.icon + '</span>' + esc(a ? a.name : '—') + '</span>' +
        seatDots(t.seats) + '<span class="tb-row-st">' + statusPill(t) + '</span><span class="tb-today">' + (t.today.orders ? t.today.orders + ' · ' + money(t.today.revenue) : '<i>No orders today</i>') + '</span>' +
        '<span class="tb-acts"><button type="button" class="sx-icon-btn" data-tb-qr="' + t.id + '" title="QR code"><span class="material-symbols-rounded">qr_code_2</span></button>' +
        '<button type="button" class="sx-icon-btn" data-tb-edit="' + t.id + '" title="Edit"><span class="material-symbols-rounded">edit</span></button></span></div>';
    }
    return '<div class="tb-card ' + t.status + (on ? ' sel' : '') + (stale ? ' stale' : '') + '" data-tb="' + t.id + '" style="--c:' + L.color + '">' +
      (selecting ? '<label class="sx-check tb-check"><input type="checkbox" data-tb-sel="' + t.id + '"' + (on ? ' checked' : '') + '><span></span></label>' : '') +
      '<div class="tb-head"><span class="tb-num">' + esc(t.number) + '</span>' + seatDots(t.seats) + '</div>' +
      '<div class="tb-st">' + statusPill(t) + '</div>' +
      (t.status === 'reserved' && t.guest ? '<div class="tb-sub">For ' + esc(t.guest) + '</div>' : '<div class="tb-sub">' + (t.today.orders ? t.today.orders + ' order' + (t.today.orders > 1 ? 's' : '') + ' today · ' + money(t.today.revenue) : 'No orders today') + '</div>') +
      '<div class="tb-foot"><button type="button" class="sx-icon-btn" data-tb-qr="' + t.id + '" title="QR code"><span class="material-symbols-rounded">qr_code_2</span></button>' +
        '<button type="button" class="sx-icon-btn tb-toggle' + (t.available ? ' on' : '') + '" data-tb-toggle="' + t.id + '" title="' + (t.available ? 'Switch off (no new orders)' : 'Switch on') + '"><span class="material-symbols-rounded">' + (t.available ? 'toggle_on' : 'toggle_off') + '</span></button>' +
        '<button type="button" class="sx-icon-btn" data-tb-edit="' + t.id + '" title="Edit"><span class="material-symbols-rounded">edit</span></button></div></div>';
  }

  function toolbar(list) {
    var counts = {}; D.tables.forEach(function (t) { counts[t.status] = (counts[t.status] || 0) + 1; });
    return '<div class="sx-toolbar">' +
      '<label class="sx-search"><span class="material-symbols-rounded">search</span><input id="tbSearch" type="search" placeholder="Find a table or area" value="' + esc(q) + '" autocomplete="off"></label>' +
      sxRight() + '</div><div class="sx-filters"><div class="sx-seg">' + [['all', 'All', D.tables.length], ['free', 'Free', counts.free || 0], ['busy', 'Busy', counts.busy || 0], ['reserved', 'Reserved', counts.reserved || 0], ['off', 'Off', counts.off || 0]].map(function (s) {
        return '<button type="button" class="' + (fStatus === s[0] ? 'on' : '') + '" data-tb-status="' + s[0] + '">' + s[1] + ' <em>' + s[2] + '</em></button>'; }).join('') + '</div>' +
      (D.areas.length > 1 ? '<div class="sx-chips">' + [{ id: 0, name: 'All areas' }].concat(D.areas).map(function (a) {
        var L = a.id ? look(a.id) : null, n = a.id ? D.tables.filter(function (t) { return t.area_id === a.id; }).length : D.tables.length;
        return '<button type="button" class="' + (fArea === a.id ? 'on' : '') + '" data-tb-area="' + a.id + '"' + (L ? ' style="--c:' + L.color + '"' : '') + '>' + (L ? '<span class="material-symbols-rounded">' + L.icon + '</span>' : '') + esc(a.name) + ' <em>' + n + '</em></button>'; }).join('') + '</div>' : '') + '</div>';
  }
  function sxRight() {
    return '<div class="sx-toolbar-r"><div class="sx-seg sx-seg-icons"><button type="button" class="' + (view === 'grid' ? 'on' : '') + '" data-tb-view="grid" title="Grid"><span class="material-symbols-rounded">grid_view</span></button><button type="button" class="' + (view === 'list' ? 'on' : '') + '" data-tb-view="list" title="List"><span class="material-symbols-rounded">view_list</span></button></div>' +
      '<button type="button" class="btn btn-secondary" data-tb-select>' + (selecting ? 'Done' : '<span class="material-symbols-rounded">checklist</span> Select') + '</button>' +
      '<button type="button" class="btn btn-primary" data-tb-new><span class="material-symbols-rounded">add</span> New table</button></div>';
  }

  function stats() {
    var seats = 0, c = { free: 0, busy: 0, reserved: 0, off: 0 };
    D.tables.forEach(function (t) { seats += t.seats; c[t.status]++; });
    return '<div class="sx-stats">' + [['table_restaurant', D.tables.length, 'Tables'], ['event_seat', seats, 'Seats'], ['check_circle', c.free, 'Free now', 'ok'], ['schedule', c.busy, 'Busy now', 'bad'], ['event', c.reserved, 'Reserved', 'wait'], ['block', c.off, 'Switched off', 'muted']].map(function (s) {
      return '<div class="' + (s[3] || '') + '"><span class="material-symbols-rounded">' + s[0] + '</span><b>' + s[1] + '</b><small>' + s[2] + '</small></div>'; }).join('') + '</div>';
  }

  function body(list) {
    if (!D.areas.length) return '<div class="sx-empty"><span class="material-symbols-rounded">storefront</span><b>Add an area first</b><span>Tables live in areas (Ground floor, Rooftop…). Set those up, then add tables.</span><button type="button" class="btn btn-primary" data-tb-go="areaPage">Open Areas</button></div>';
    if (!D.tables.length) return '<div class="sx-empty"><span class="material-symbols-rounded">table_restaurant</span><b>No tables yet</b><span>Add them one by one here, or several at once from Areas › Add tables.</span><div class="sx-empty-acts"><button type="button" class="btn btn-primary" data-tb-new>New table</button><button type="button" class="btn btn-secondary" data-tb-go="areaPage">Add several in Areas</button></div></div>';
    if (!list.length) return '<div class="sx-empty"><span class="material-symbols-rounded">search_off</span><b>No tables match</b><span>Try another word or filter.</span><button type="button" class="btn btn-secondary" data-tb-clear>Clear filters</button></div>';
    var groups = D.areas.map(function (a) { return { a: a, t: list.filter(function (t) { return t.area_id === a.id; }) }; }).filter(function (g) { return g.t.length; });
    return groups.map(function (g, gi) {
      var L = look(g.a.id), seats = g.t.reduce(function (s, t) { return s + t.seats; }, 0), allSel = g.t.every(function (t) { return sel[t.id]; });
      return '<section class="tb-group" style="--c:' + L.color + ';animation-delay:' + (gi * 50) + 'ms"><div class="tb-group-h"><span class="sx-ic material-symbols-rounded">' + L.icon + '</span><b>' + esc(g.a.name) + '</b><small>' + g.t.length + ' table' + (g.t.length > 1 ? 's' : '') + ' · ' + seats + ' seats</small>' +
        (selecting ? '<button type="button" class="sx-link" data-tb-selarea="' + g.a.id + '">' + (allSel ? 'Unselect all' : 'Select all') + '</button>' : '') + '</div>' +
        '<div class="' + (view === 'list' ? 'tb-list' : 'tb-grid') + '">' + g.t.map(card).join('') + '</div></section>';
    }).join('');
  }

  function bulkBar() {
    var ids = Object.keys(sel).map(Number); if (!selecting) return '';
    return '<div class="sx-bulk' + (ids.length ? ' on' : '') + '"><b>' + ids.length + ' selected</b>' +
      (ids.length ? '<select id="tbBulkMove"><option value="">Move to…</option>' + D.areas.map(function (a) { return '<option value="' + a.id + '">' + esc(a.name) + '</option>'; }).join('') + '</select>' +
        '<select id="tbBulkSeats"><option value="">Seats…</option>' + [2, 4, 6, 8, 10, 12].map(function (n) { return '<option value="' + n + '">' + n + ' seats</option>'; }).join('') + '</select>' +
        '<button type="button" class="btn btn-secondary btn-sm" data-tb-bulk-avail="0">Switch off</button><button type="button" class="btn btn-secondary btn-sm" data-tb-bulk-avail="1">Switch on</button>' +
        '<button type="button" class="btn btn-sm ' + (delAsk ? 'sx-danger' : 'btn-secondary sx-danger-ghost') + '" data-tb-bulk-del>' + (delAsk ? 'Delete ' + ids.length + '? Click again' : '<span class="material-symbols-rounded">delete</span> Delete') + '</button>' : '<span class="sx-muted">Tap tables to select them</span>') +
      '<button type="button" class="sx-link" data-tb-select>Done</button></div>';
  }

  function editor() {
    if (!edit) return '';
    var L = look(edit.area_id || (D.areas[0] || {}).id), orig = edit.id ? D.tables.filter(function (t) { return t.id === edit.id; })[0] : null;
    var renamed = orig && String(orig.number) !== String(edit.number || '');
    return '<div class="sx-modal" data-tb-modal><div class="sx-sheet" role="dialog" aria-label="' + (edit.id ? 'Edit table' : 'New table') + '">' +
      '<div class="sx-sheet-hero" style="--c:' + L.color + '"><div class="tb-prev"><span class="tb-num">' + esc(edit.number || 'T?') + '</span>' + seatDots(edit.seats) + '</div>' +
        '<div><b>' + (edit.id ? 'Edit table' : 'New table') + '</b><small>' + esc((area(edit.area_id) || {}).name || 'Pick an area') + ' · ' + edit.seats + ' seats' + (edit.available ? '' : ' · switched off') + '</small></div></div>' +
      '<div class="sx-form">' +
        '<label class="sx-lbl">Table name<input id="tbNum" maxlength="50" value="' + esc(edit.number || '') + '" placeholder="e.g. T7" autocomplete="off"></label>' +
        (renamed ? '<div class="sx-note warn"><span class="material-symbols-rounded">qr_code_2</span>The QR code is made from the name. After renaming, print the new QR code for this table; the old one stops working.</div>' : '') +
        '<div class="sx-lbl">Seats</div><div class="tb-seat-pick"><div class="sx-step"><button type="button" data-tb-seat="-1">−</button><input type="number" id="tbSeats" min="1" max="50" value="' + edit.seats + '"><button type="button" data-tb-seat="1">+</button></div>' +
          [2, 4, 6, 8].map(function (n) { return '<button type="button" class="sx-chip-btn' + (edit.seats === n ? ' on' : '') + '" data-tb-seatset="' + n + '">' + n + '</button>'; }).join('') + '</div>' +
        '<div class="sx-lbl">Area</div><div class="sx-chips sx-chips-wrap">' + D.areas.map(function (a) { var La = look(a.id); return '<button type="button" class="' + (edit.area_id === a.id ? 'on' : '') + '" data-tb-setarea="' + a.id + '" style="--c:' + La.color + '"><span class="material-symbols-rounded">' + La.icon + '</span>' + esc(a.name) + '</button>'; }).join('') + '</div>' +
        '<label class="sx-switch-row"><span><b>Taking orders</b><small>Switch off a table that’s broken or kept for staff. Its QR code then won’t take orders.</small></span><span class="up-switch"><input type="checkbox" id="tbAvail"' + (edit.available ? ' checked' : '') + '><span></span></span></label>' +
        (edit.id ? '<div class="sx-danger-zone">' + (orig && orig.status === 'busy' ? '<span class="sx-muted">This table has an open order, so it can’t be deleted now.</span>' : '<button type="button" class="sx-link sx-link-danger" data-tb-del-one="' + edit.id + '">' + (edit.delAsk ? 'Click again to delete ' + esc(orig.number) : '<span class="material-symbols-rounded">delete</span>Delete this table') + '</button>') + '</div>' : '') +
      '</div>' +
      '<div class="sx-sheet-acts"><button type="button" class="btn btn-secondary" data-tb-close>Cancel</button><button type="button" class="btn btn-primary" data-tb-save>' + (edit.id ? 'Save changes' : 'Add table') + '</button></div>' +
    '</div></div>';
  }

  function qrModal() {
    if (!qrFor) return '';
    var t = D.tables.filter(function (x) { return x.id === qrFor; })[0]; if (!t) return '';
    var L = look(t.area_id), url = SX.tableUrl(t.number), img = SX.qrImage(t.number, 360);
    return '<div class="sx-modal" data-tb-modal><div class="sx-sheet sx-sheet-sm" role="dialog" aria-label="QR code for ' + esc(t.number) + '">' +
      '<div class="sx-sheet-hero" style="--c:' + L.color + '"><span class="sx-ic material-symbols-rounded">qr_code_2</span><div><b>Table ' + esc(t.number) + '</b><small>' + esc((area(t.area_id) || {}).name || '') + ' · guests scan to order</small></div></div>' +
      '<div class="tb-qr"><img src="' + esc(img) + '" alt="QR code for table ' + esc(t.number) + '" width="220" height="220"><code>' + esc(url) + '</code>' +
        (t.available ? '' : '<div class="sx-note warn"><span class="material-symbols-rounded">block</span>This table is switched off, so the QR code won’t take orders.</div>') + '</div>' +
      '<div class="sx-sheet-acts"><button type="button" class="btn btn-secondary" data-tb-open-url="' + esc(url) + '"><span class="material-symbols-rounded">open_in_new</span> Open</button>' +
        '<button type="button" class="btn btn-secondary" data-tb-qr-print="' + t.id + '"><span class="material-symbols-rounded">print</span> Print</button>' +
        '<button type="button" class="btn btn-primary" data-tb-qr-dl="' + t.id + '"><span class="material-symbols-rounded">download</span> Download</button></div></div></div>';
  }

  function draw(keepFocus) {
    var el = $('tablesRoot'); if (!el || !D) return;
    var f = document.activeElement && document.activeElement.id === 'tbSearch' ? document.activeElement.selectionStart : null;
    var list = visible();
    el.innerHTML = stats() + toolbar(list) + '<div class="tb-body' + (selecting ? ' selecting' : '') + '">' + body(list) + '</div>' + bulkBar() + editor() + qrModal();
    if (f !== null) { var s = $('tbSearch'); s.focus(); s.setSelectionRange(f, f); }
    if (edit && !keepFocus) { var n = $('tbNum'); if (n && !edit.number) n.focus(); }
  }
  function readEdit() { var n = $('tbNum'), s = $('tbSeats'), a = $('tbAvail'); if (n) edit.number = n.value; if (s) edit.seats = Math.max(1, Math.min(50, +s.value || 1)); if (a) edit.available = a.checked ? 1 : 0; }
  function nextName(areaId) {
    var a = area(areaId), p = (String((a || {}).name || 'T').replace(/[^A-Za-z]/g, '')[0] || 'T').toUpperCase(), max = 0, used = {};
    D.tables.forEach(function (t) { var u = String(t.number).toUpperCase(); used[u] = 1; var m = new RegExp('^' + p + '(\\d+)$').exec(u); if (m) max = Math.max(max, +m[1]); });
    var n = max + 1; while (used[p + n]) n++; return p + n;
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('#tablesRoot') || !D) return;
    var t;
    if (e.target.matches('[data-tb-modal]') || e.target.closest('[data-tb-close]')) { edit = null; qrFor = null; draw(); return; }
    if ((t = e.target.closest('[data-tb-status]'))) { fStatus = t.getAttribute('data-tb-status'); draw(); return; }
    if ((t = e.target.closest('[data-tb-area]'))) { fArea = +t.getAttribute('data-tb-area'); draw(); return; }
    if ((t = e.target.closest('[data-tb-view]'))) { view = t.getAttribute('data-tb-view'); try { localStorage.setItem('rgTablesView', view); } catch (x) {} draw(); return; }
    if (e.target.closest('[data-tb-clear]')) { q = ''; fArea = 0; fStatus = 'all'; draw(); return; }
    if ((t = e.target.closest('[data-tb-go]'))) { SX.goTo(t.getAttribute('data-tb-go')); return; }
    if (e.target.closest('[data-tb-select]')) { selecting = !selecting; sel = {}; delAsk = false; draw(); return; }
    if ((t = e.target.closest('[data-tb-selarea]'))) {
      var aid = +t.getAttribute('data-tb-selarea'), inA = visible().filter(function (x) { return x.area_id === aid; }), all = inA.every(function (x) { return sel[x.id]; });
      inA.forEach(function (x) { if (all) delete sel[x.id]; else sel[x.id] = 1; }); delAsk = false; draw(); return;
    }
    if (selecting && (t = e.target.closest('[data-tb]')) && !e.target.closest('button')) {
      var id = +t.getAttribute('data-tb'); if (sel[id]) delete sel[id]; else sel[id] = 1; delAsk = false; draw(); return;
    }
    if (e.target.closest('[data-tb-new]')) { var a0 = fArea || (D.areas[0] || {}).id; edit = { id: 0, number: nextName(a0), seats: 4, area_id: a0, available: 1 }; draw(); var n = $('tbNum'); if (n) { n.focus(); n.select(); } return; }
    if ((t = e.target.closest('[data-tb-edit]'))) { var o = D.tables.filter(function (x) { return x.id === +t.getAttribute('data-tb-edit'); })[0]; edit = { id: o.id, number: o.number, seats: o.seats, area_id: o.area_id, available: o.available }; draw(); return; }
    if ((t = e.target.closest('[data-tb-seat]'))) { readEdit(); edit.seats = Math.max(1, Math.min(50, edit.seats + +t.getAttribute('data-tb-seat'))); draw(true); return; }
    if ((t = e.target.closest('[data-tb-seatset]'))) { readEdit(); edit.seats = +t.getAttribute('data-tb-seatset'); draw(true); return; }
    if ((t = e.target.closest('[data-tb-setarea]'))) { readEdit(); edit.area_id = +t.getAttribute('data-tb-setarea'); draw(true); return; }
    if ((t = e.target.closest('[data-tb-save]'))) {
      readEdit(); t.disabled = true;
      post({ action: 'save', id: edit.id, number: edit.number, seats: edit.seats, area_id: edit.area_id, available: edit.available }).then(function (d) {
        t.disabled = false;
        if (!d.success) { SX.notify(d.message || 'Could not save', 'error'); return; }
        SX.notify(edit.id ? (d.renamed ? 'Saved. Print the new QR code for this table.' : 'Table saved') : 'Table ' + edit.number.trim() + ' added', d.renamed ? 'warning' : 'success');
        var showQr = d.renamed ? edit.id : null; edit = null; load().then(function () { if (showQr) { qrFor = showQr; draw(); } });
      });
      return;
    }
    if ((t = e.target.closest('[data-tb-del-one]'))) {
      if (!edit.delAsk) { readEdit(); edit.delAsk = true; draw(true); return; }
      post({ action: 'delete', ids: [+t.getAttribute('data-tb-del-one')] }).then(function (d) {
        SX.notify(d.success ? (d.deleted ? 'Table deleted' : 'It has an open order, so it was kept') : (d.message || 'Could not delete'), d.success && d.deleted ? 'success' : 'error'); edit = null; load();
      });
      return;
    }
    if ((t = e.target.closest('[data-tb-toggle]'))) {
      var tb = D.tables.filter(function (x) { return x.id === +t.getAttribute('data-tb-toggle'); })[0];
      post({ action: 'bulk', ids: [tb.id], available: tb.available ? 0 : 1 }).then(function (d) { SX.notify(d.success ? 'Table ' + tb.number + (tb.available ? ' switched off' : ' switched on') : (d.message || 'Could not change'), d.success ? 'success' : 'error'); load(); });
      return;
    }
    if ((t = e.target.closest('[data-tb-qr]'))) { qrFor = +t.getAttribute('data-tb-qr'); draw(); return; }
    if ((t = e.target.closest('[data-tb-open-url]'))) { window.open(t.getAttribute('data-tb-open-url'), '_blank', 'noopener'); return; }
    if ((t = e.target.closest('[data-tb-qr-dl]')) || (t = e.target.closest('[data-tb-qr-print]'))) {
      var tq = D.tables.filter(function (x) { return x.id === +(t.getAttribute('data-tb-qr-dl') || t.getAttribute('data-tb-qr-print')); })[0], img = SX.qrImage(tq.number, 600);
      if (t.hasAttribute('data-tb-qr-dl') && typeof window.downloadQRCode === 'function') window.downloadQRCode(img, tq.number);
      else if (typeof window.printQRCode === 'function') window.printQRCode(img, tq.number);
      return;
    }
    if ((t = e.target.closest('[data-tb-bulk-avail]'))) bulk({ available: +t.getAttribute('data-tb-bulk-avail') }, t);
    if ((t = e.target.closest('[data-tb-bulk-del]'))) {
      if (!delAsk) { delAsk = true; draw(); return; }
      var ids = Object.keys(sel).map(Number);
      post({ action: 'delete', ids: ids }).then(function (d) {
        SX.notify(d.success ? d.deleted + ' deleted' + (d.kept_busy ? ', ' + d.kept_busy + ' kept (open order)' : '') : (d.message || 'Could not delete'), d.success ? 'success' : 'error');
        sel = {}; delAsk = false; load();
      });
    }
  });
  function bulk(change, btn) {
    var ids = Object.keys(sel).map(Number); if (!ids.length) return;
    if (btn) btn.disabled = true;
    post(Object.assign({ action: 'bulk', ids: ids }, change)).then(function (d) {
      SX.notify(d.success ? d.updated + ' table' + (d.updated > 1 ? 's' : '') + ' updated' : (d.message || 'Could not change'), d.success ? 'success' : 'error'); sel = {}; load();
    });
  }
  document.addEventListener('change', function (e) {
    if (!D || !e.target.closest || !e.target.closest('#tablesRoot')) return;
    var t = e.target;
    if (t.id === 'tbBulkMove' && t.value) bulk({ move_to: +t.value });
    if (t.id === 'tbBulkSeats' && t.value) bulk({ seats: +t.value });
    if (t.hasAttribute('data-tb-sel')) { var id = +t.getAttribute('data-tb-sel'); if (t.checked) sel[id] = 1; else delete sel[id]; delAsk = false; draw(); }
    if (t.id === 'tbAvail' || t.id === 'tbSeats') { readEdit(); draw(true); }
  });
  document.addEventListener('input', function (e) {
    if (!D || !e.target.closest || !e.target.closest('#tablesRoot')) return;
    if (e.target.id === 'tbSearch') { q = e.target.value; draw(); }
    if (e.target.id === 'tbNum') {
      readEdit();
      var p = document.querySelector('.tb-prev .tb-num'); if (p) p.textContent = edit.number || 'T?';
      var orig = edit.id ? D.tables.filter(function (x) { return x.id === edit.id; })[0] : null, warn = document.querySelector('.sx-form .sx-note.warn');
      if (orig && (String(orig.number) !== edit.number) !== !!warn) { var pos = e.target.selectionStart; draw(true); var n = $('tbNum'); n.focus(); n.setSelectionRange(pos, pos); }
    }
  });
  document.addEventListener('keydown', function (e) {
    if (!edit && !qrFor) return;
    if (e.key === 'Escape') { edit = null; qrFor = null; draw(); }
    if (e.key === 'Enter' && edit && e.target && (e.target.id === 'tbNum' || e.target.id === 'tbSeats')) { e.preventDefault(); var s = document.querySelector('[data-tb-save]'); if (s) s.click(); }
  });

  window.loadTablesStudio = function () { load(); };
})();
