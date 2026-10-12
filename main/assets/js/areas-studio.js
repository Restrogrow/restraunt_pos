/*
 * Tables › Areas (api/areas_studio.php).
 *
 * The floor at a glance: each area as a card with its own icon and colour, its
 * tables as live chips (free / busy with how long / reserved), seats, occupancy
 * and today's sales. Add tables in bulk, drag areas into order, edit in a
 * preview editor, and delete safely (its tables move to another area first).
 * Live status refreshes every 30 s while the page is open.
 */
(function () {
  'use strict';

  var API = '../api/areas_studio.php';
  var PALETTE = ['#7c3aed', '#0ea5e9', '#f97316', '#10b981', '#e11d48', '#eab308', '#6366f1', '#14b8a6', '#8b5cf6', '#475569'];
  var IDEAS = [['Ground Floor', 'storefront', '#7c3aed'], ['First Floor', 'stairs', '#0ea5e9'], ['Rooftop', 'roofing', '#f97316'], ['Outdoor', 'deck', '#10b981'],
               ['AC Hall', 'ac_unit', '#6366f1'], ['Private Dining', 'meeting_room', '#e11d48'], ['Bar', 'local_bar', '#eab308'], ['Family Section', 'family_restroom', '#14b8a6']];
  var ICON_NAMES = { restaurant: 'Dining', deck: 'Outdoor', roofing: 'Rooftop', local_bar: 'Bar', ac_unit: 'AC', park: 'Garden', meeting_room: 'Private', celebration: 'Party',
    weekend: 'Lounge', family_restroom: 'Family', coffee: 'Café', stairs: 'Upstairs', wine_bar: 'Wine', storefront: 'Ground', pool: 'Poolside', nightlife: 'Night' };
  var D = null, edit = null, addFor = null, delFor = null, ideasPicked = {}, pollT = null, dragId = null;

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function notify(m, t) { if (typeof window.showNotification === 'function') window.showNotification(m, t); }
  function money(n) { return ((D && D.currency) || '₹') + Math.round(n || 0).toLocaleString('en-IN'); }
  function post(b) { return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) }).then(function (r) { return r.json(); }); }

  /** Icon and colour: the owner's, else guessed from the name. */
  function guessIcon(name) {
    var n = String(name).toLowerCase();
    var rules = [[/roof|terrace top/, 'roofing'], [/outdoor|terrace|patio|deck|lawn/, 'deck'], [/garden|park/, 'park'], [/bar|pub/, 'local_bar'], [/\bac\b|air/, 'ac_unit'],
      [/private|vip|cabin/, 'meeting_room'], [/party|banquet|event/, 'celebration'], [/lounge|sofa/, 'weekend'], [/family/, 'family_restroom'], [/caf|coffee/, 'coffee'],
      [/first|second|upper|floor 1|1st|2nd|mezz/, 'stairs'], [/ground|main|hall/, 'storefront'], [/pool/, 'pool'], [/wine/, 'wine_bar']];
    for (var i = 0; i < rules.length; i++) if (rules[i][0].test(n)) return rules[i][1];
    return 'restaurant';
  }
  function look(a, i) { return { icon: a.icon || guessIcon(a.name), color: a.color || PALETTE[i % PALETTE.length] }; }
  function mins(since) { if (!since || !D) return ''; var m = Math.max(0, Math.round((new Date(D.now.replace(' ', 'T')) - new Date(since.replace(' ', 'T'))) / 60000)); return m < 60 ? m + 'm' : Math.floor(m / 60) + 'h ' + (m % 60) + 'm'; }
  function prefixFor(a) { var w = String(a.name).replace(/[^A-Za-z ]/g, '').trim().split(/\s+/); var p = w.length > 1 && /floor|hall|section|area/i.test(w[1]) ? w[0][0] : (w[0] || 'T')[0]; return (p || 'T').toUpperCase(); }
  function nextNumbers(prefix, count) {
    var used = {}, max = 0;
    D.areas.forEach(function (a) { a.tables.forEach(function (t) { var u = String(t.number).toUpperCase(); used[u] = 1; var m = new RegExp('^' + prefix + '(\\d+)$').exec(u); if (m) max = Math.max(max, +m[1]); }); });
    var out = [], n = max; while (out.length < count) { n++; if (!used[prefix + n]) out.push(prefix + n); } return out;
  }

  function load() {
    var el = $('areasRoot'); if (!el) return;
    if (!D) el.innerHTML = '<div class="ar-loading"><span></span><span></span><span></span></div>';
    return fetch(API, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.success) { el.innerHTML = '<div class="up-empty">' + esc(d.message || 'Could not load areas') + '</div>'; return; }
      D = d; draw();
      clearTimeout(pollT);
      pollT = setTimeout(function () { var pg = $('areaPage'); if (pg && pg.classList.contains('active') && !edit && !addFor && !delFor) load(); }, 30000);
    }).catch(function () { if (!D) el.innerHTML = '<div class="up-empty">Network error. Please try again.</div>'; });
  }

  /* ── pieces ── */
  function hero() {
    var tables = 0, seats = 0, busy = 0, reserved = 0, rev = 0, orders = 0;
    D.areas.forEach(function (a) { a.tables.forEach(function (t) { tables++; seats += t.seats; if (t.status === 'busy') busy++; if (t.status === 'reserved') reserved++; }); rev += a.sales.revenue; orders += a.sales.orders; });
    var pct = tables ? Math.round(busy * 100 / tables) : 0;
    return '<div class="ar-hero"><div class="ar-hero-l"><span class="ar-live"><i></i>Live</span><h2>Your floor</h2><p>' + (tables ? busy + ' of ' + tables + ' tables busy' + (reserved ? ', ' + reserved + ' reserved' : '') + ' right now.' : 'Add your areas and tables to see your floor here.') + '</p></div>' +
      '<div class="ar-ring" style="--p:' + pct + '"><div><b>' + pct + '%</b><span>full</span></div></div>' +
      '<div class="ar-kpis"><div><b>' + D.areas.length + '</b><span>Areas</span></div><div><b>' + tables + '</b><span>Tables</span></div><div><b>' + seats + '</b><span>Seats</span></div><div><b>' + money(rev) + '</b><span>Table sales today' + (orders ? ' · ' + orders + ' orders' : '') + '</span></div></div></div>';
  }
  function minsNum(since) { return since && D ? Math.round((new Date(D.now.replace(' ', 'T')) - new Date(since.replace(' ', 'T'))) / 60000) : 0; }
  function chip(t, color) {
    // open for over 6 hours: almost always a ticket nobody closed
    var stale = t.status === 'busy' && minsNum(t.since) > 360;
    var title = (stale ? 'Open for over 6 hours: finish or cancel its kitchen ticket. ' : '') + t.number + ' · ' + t.seats + ' seats' + (t.status === 'busy' ? ' · busy ' + mins(t.since) + (t.bill ? ' · ' + money(t.bill) : '') : t.status === 'reserved' ? ' · reserved' + (t.guest ? ' for ' + t.guest : '') + (t.slot ? ' at ' + t.slot : '') : t.status === 'off' ? ' · switched off' : ' · free');
    return '<div class="ar-t ' + t.status + (stale ? ' stale' : '') + '" title="' + esc(title) + '" style="--c:' + color + '"><b>' + esc(t.number) + '</b><small>' +
      (t.status === 'busy' ? '<span class="material-symbols-rounded">schedule</span>' + mins(t.since) : t.status === 'reserved' ? '<span class="material-symbols-rounded">event</span>' + esc(t.slot || 'Today') : '<span class="material-symbols-rounded">person</span>' + t.seats) + '</small></div>';
  }
  function card(a, i) {
    var L = look(a, i), tb = a.tables, busy = 0, res = 0, seats = 0;
    tb.forEach(function (t) { seats += t.seats; if (t.status === 'busy') busy++; if (t.status === 'reserved') res++; });
    var staleN = tb.filter(function (t) { return t.status === 'busy' && minsNum(t.since) > 360; }).length;
    var free = tb.length - busy - res, w = function (n) { return tb.length ? (n * 100 / tb.length) : 0; };
    var body;
    if (delFor === a.id) {
      var others = D.areas.filter(function (x) { return x.id !== a.id; });
      body = '<div class="ar-del"><span class="material-symbols-rounded">delete</span><div><b>Delete “' + esc(a.name) + '”?</b>' +
        (tb.length ? (others.length ? '<small>Its ' + tb.length + ' table' + (tb.length > 1 ? 's move' : ' moves') + ' to</small><select id="arMoveTo">' + others.map(function (o) { return '<option value="' + o.id + '">' + esc(o.name) + '</option>'; }).join('') + '</select>'
                                    : '<small>Add another area first, so its ' + tb.length + ' tables have somewhere to go.</small>')
                   : '<small>It has no tables. This can’t be undone.</small>') +
        '</div><div class="ar-del-acts"><button type="button" class="btn btn-secondary" data-ar-del-cancel>Keep it</button>' + (tb.length && !others.length ? '' : '<button type="button" class="btn ar-danger" data-ar-del-go="' + a.id + '">Delete</button>') + '</div></div>';
    } else if (addFor === a.id) {
      var p = prefixFor(a), c = 4, s = 4;
      body = '<div class="ar-add" data-ar-add-form="' + a.id + '"><div class="ar-add-row">' +
        '<label>How many<div class="ar-step"><button type="button" data-ar-step="-1">−</button><input type="number" id="arAddCount" min="1" max="30" value="' + c + '"><button type="button" data-ar-step="1">+</button></div></label>' +
        '<label>Seats each<select id="arAddSeats">' + [2, 4, 6, 8, 10, 12].map(function (n) { return '<option' + (n === s ? ' selected' : '') + '>' + n + '</option>'; }).join('') + '</select></label>' +
        '<label>Name starts with<input id="arAddPrefix" maxlength="3" value="' + esc(p) + '"></label></div>' +
        '<div class="ar-add-prev" id="arAddPrev"></div>' +
        '<div class="ar-add-acts"><button type="button" class="btn btn-secondary" data-ar-add-cancel>Cancel</button><button type="button" class="btn btn-primary" data-ar-add-go="' + a.id + '"><span class="material-symbols-rounded">add</span> Add tables</button></div></div>';
    } else {
      body = (staleN ? '<div class="ar-stale"><span class="material-symbols-rounded">history</span>' + staleN + ' table' + (staleN > 1 ? 's have' : ' has') + ' been busy for over 6 hours. Close the old kitchen ticket' + (staleN > 1 ? 's' : '') + ' so ' + (staleN > 1 ? 'they show' : 'it shows') + ' as free.</div>' : '') +
        (tb.length ? '<div class="ar-tables">' + tb.map(function (t) { return chip(t, L.color); }).join('') + '</div>'
                        : '<div class="ar-none"><span class="material-symbols-rounded">table_restaurant</span>No tables here yet</div>') +
        '<div class="ar-foot">' + (D.can_tables ? '<button type="button" class="ar-link" data-ar-add="' + a.id + '"><span class="material-symbols-rounded">add_circle</span>Add tables</button>' : '') +
        '<button type="button" class="ar-link" data-ar-nav="tableMapPage"><span class="material-symbols-rounded">map</span>Table map</button>' +
        '<button type="button" class="ar-link" data-ar-nav="qrCodesPage"><span class="material-symbols-rounded">qr_code_2</span>QR codes</button></div>';
    }
    return '<div class="ar-card" draggable="true" data-ar-id="' + a.id + '" style="--c:' + L.color + ';animation-delay:' + (i * 40) + 'ms">' +
      '<div class="ar-top"><span class="ar-grip material-symbols-rounded" title="Drag to reorder">drag_indicator</span><span class="ar-ic material-symbols-rounded">' + L.icon + '</span>' +
        '<div class="ar-name"><b>' + esc(a.name) + '</b><small>' + (a.note ? esc(a.note) : tb.length + ' table' + (tb.length === 1 ? '' : 's') + ' · ' + seats + ' seats') + '</small></div>' +
        '<button type="button" class="ar-icon-btn" data-ar-edit="' + a.id + '" title="Edit"><span class="material-symbols-rounded">edit</span></button>' +
        '<button type="button" class="ar-icon-btn" data-ar-del="' + a.id + '" title="Delete"><span class="material-symbols-rounded">delete</span></button></div>' +
      '<div class="ar-stats"><div><b>' + busy + '</b><span>Busy</span></div><div><b>' + res + '</b><span>Reserved</span></div><div><b>' + free + '</b><span>Free</span></div><div><b>' + money(a.sales.revenue) + '</b><span>Today' + (a.sales.orders ? ' · ' + a.sales.orders : '') + '</span></div></div>' +
      '<div class="ar-bar" title="' + busy + ' busy, ' + res + ' reserved, ' + free + ' free"><i class="b" style="width:' + w(busy) + '%"></i><i class="r" style="width:' + w(res) + '%"></i><i class="f" style="width:' + w(free) + '%"></i></div>' +
      body + '</div>';
  }
  function empty() {
    return '<div class="ar-empty"><div class="ar-empty-art"><span class="material-symbols-rounded">storefront</span><span class="material-symbols-rounded">deck</span><span class="material-symbols-rounded">roofing</span></div>' +
      '<h3>Set up your floor</h3><p>Areas group your tables: a ground floor, a rooftop, an AC hall. Pick the ones you have and add tables next.</p>' +
      '<div class="ar-ideas">' + IDEAS.map(function (x, i) { return '<button type="button" class="' + (ideasPicked[i] ? 'on' : '') + '" data-ar-idea="' + i + '" style="--c:' + x[2] + '"><span class="material-symbols-rounded">' + x[1] + '</span>' + x[0] + '</button>'; }).join('') + '</div>' +
      '<div class="ar-empty-acts"><button type="button" class="btn btn-primary" data-ar-ideas-go' + (Object.keys(ideasPicked).length ? '' : ' disabled') + '>Create ' + (Object.keys(ideasPicked).length || '') + ' area' + (Object.keys(ideasPicked).length === 1 ? '' : 's') + '</button><button type="button" class="btn btn-secondary" data-ar-new>Name my own</button></div></div>';
  }
  function editor() {
    if (!edit) return '';
    var L = { icon: edit.icon || guessIcon(edit.name || ''), color: edit.color || PALETTE[(D.areas.length) % PALETTE.length] };
    var sugg = IDEAS.filter(function (x) { return !D.areas.some(function (a) { return a.name.toLowerCase() === x[0].toLowerCase() && a.id !== edit.id; }); }).slice(0, 6);
    return '<div class="ar-modal" data-ar-modal><div class="ar-sheet" role="dialog" aria-label="' + (edit.id ? 'Edit area' : 'New area') + '">' +
      '<div class="ar-prev" style="--c:' + L.color + '"><span class="ar-ic material-symbols-rounded">' + L.icon + '</span><div><b>' + esc(edit.name || 'New area') + '</b><small>' + esc(edit.note || (edit.id ? 'Your area' : 'Tables you add will show here')) + '</small></div></div>' +
      '<div class="ar-form">' +
        '<label class="ar-lbl">Name<input id="arName" maxlength="100" value="' + esc(edit.name || '') + '" placeholder="e.g. Rooftop" autocomplete="off"></label>' +
        (!edit.id && sugg.length ? '<div class="ar-sugg">' + sugg.map(function (x) { return '<button type="button" data-ar-sugg="' + esc(x[0]) + '|' + x[1] + '|' + x[2] + '">' + esc(x[0]) + '</button>'; }).join('') + '</div>' : '') +
        '<div class="ar-lbl">Icon</div><div class="ar-icons">' + D.icons.map(function (ic) { return '<button type="button" class="' + (ic === L.icon ? 'on' : '') + '" data-ar-icon="' + ic + '" title="' + esc(ICON_NAMES[ic] || ic) + '"><span class="material-symbols-rounded">' + ic + '</span></button>'; }).join('') + '</div>' +
        '<div class="ar-lbl">Colour</div><div class="ar-colors">' + PALETTE.map(function (c) { return '<button type="button" class="' + (c === L.color ? 'on' : '') + '" data-ar-color="' + c + '" style="background:' + c + '" aria-label="' + c + '"></button>'; }).join('') + '</div>' +
        '<label class="ar-lbl"><span>Short note <i>optional</i></span><input id="arNote" maxlength="120" value="' + esc(edit.note || '') + '" placeholder="e.g. Smoking allowed, live music on weekends"></label>' +
      '</div>' +
      '<div class="ar-sheet-acts"><button type="button" class="btn btn-secondary" data-ar-close>Cancel</button><button type="button" class="btn btn-primary" data-ar-save>' + (edit.id ? 'Save changes' : 'Create area') + '</button></div>' +
    '</div></div>';
  }

  function draw() {
    var el = $('areasRoot'); if (!el || !D) return;
    var unassigned = D.unassigned ? '<div class="ar-warn"><span class="material-symbols-rounded">warning</span>' + D.unassigned + ' table' + (D.unassigned > 1 ? 's are' : ' is') + ' not in any area (an area was deleted earlier). Open Tables to put ' + (D.unassigned > 1 ? 'them' : 'it') + ' in one.</div>' : '';
    el.innerHTML = hero() + unassigned +
      (D.areas.length ? '<div class="ar-bar-top"><div class="ar-legend"><span class="f">Free</span><span class="b">Busy</span><span class="r">Reserved</span><small>Drag cards to change the order</small></div>' +
        '<button type="button" class="btn btn-primary" data-ar-new><span class="material-symbols-rounded">add</span> New area</button></div>' +
        '<div class="ar-grid">' + D.areas.map(card).join('') + '</div>' : empty()) +
      editor();
    if (addFor) updatePreview();
    if (edit) { var n = $('arName'); if (n && !n.value) n.focus(); }
  }
  function updatePreview() {
    var a = D.areas.filter(function (x) { return x.id === addFor; })[0], box = $('arAddPrev'); if (!a || !box) return;
    var c = Math.max(1, Math.min(30, +($('arAddCount') || {}).value || 1)), p = String(($('arAddPrefix') || {}).value || 'T').toUpperCase().replace(/[^A-Z]/g, '') || 'T';
    var names = nextNumbers(p, c), s = +($('arAddSeats') || {}).value || 4;
    box.innerHTML = '<small>Adds ' + c + ' table' + (c > 1 ? 's' : '') + ' · ' + (c * s) + ' seats</small><div class="ar-tables">' + names.map(function (n) { return '<div class="ar-t new" style="--c:' + look(a, D.areas.indexOf(a)).color + '"><b>' + n + '</b><small><span class="material-symbols-rounded">person</span>' + s + '</small></div>'; }).join('') + '</div>';
  }
  function readEditor() { var n = $('arName'), t = $('arNote'); if (n) edit.name = n.value; if (t) edit.note = t.value; }
  function closeEditor() { edit = null; draw(); }

  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('#areasRoot') || !D) return;
    var t;
    if (e.target.matches('[data-ar-modal]') || e.target.closest('[data-ar-close]')) { closeEditor(); return; }
    if (e.target.closest('[data-ar-new]')) { edit = { id: 0, name: '', icon: null, color: null, note: '' }; draw(); return; }
    if ((t = e.target.closest('[data-ar-edit]'))) { var a = D.areas.filter(function (x) { return x.id === +t.getAttribute('data-ar-edit'); })[0]; var L = look(a, D.areas.indexOf(a)); edit = { id: a.id, name: a.name, icon: L.icon, color: L.color, note: a.note }; draw(); return; }
    if ((t = e.target.closest('[data-ar-sugg]'))) { readEditor(); var v = t.getAttribute('data-ar-sugg').split('|'); edit.name = v[0]; edit.icon = v[1]; edit.color = v[2]; draw(); return; }
    if ((t = e.target.closest('[data-ar-icon]'))) { readEditor(); edit.icon = t.getAttribute('data-ar-icon'); edit.iconPicked = true; draw(); return; }
    if ((t = e.target.closest('[data-ar-color]'))) { readEditor(); edit.color = t.getAttribute('data-ar-color'); draw(); return; }
    if ((t = e.target.closest('[data-ar-save]'))) {
      readEditor(); var L2 = { icon: edit.icon || guessIcon(edit.name), color: edit.color || PALETTE[D.areas.length % PALETTE.length] };
      t.disabled = true;
      post({ action: 'save', id: edit.id, name: edit.name, icon: L2.icon, color: L2.color, note: edit.note }).then(function (d) {
        t.disabled = false;
        if (!d.success) { notify(d.message || 'Could not save', 'error'); return; }
        notify(edit.id ? 'Area saved' : '“' + edit.name.trim() + '” created. Add its tables next.', 'success');
        var created = !edit.id ? d.id : null; edit = null;
        load().then(function () { if (created && D.can_tables) { addFor = created; draw(); } });
      });
      return;
    }
    if ((t = e.target.closest('[data-ar-del]'))) { delFor = +t.getAttribute('data-ar-del'); addFor = null; draw(); return; }
    if (e.target.closest('[data-ar-del-cancel]')) { delFor = null; draw(); return; }
    if ((t = e.target.closest('[data-ar-del-go]'))) {
      var sel = $('arMoveTo'); t.disabled = true;
      post({ action: 'delete', id: +t.getAttribute('data-ar-del-go'), move_to: sel ? +sel.value : 0 }).then(function (d) {
        if (!d.success) { t.disabled = false; notify(d.message || 'Could not delete', 'error'); return; }
        notify('Area deleted' + (d.moved ? '. ' + d.moved + ' table' + (d.moved > 1 ? 's' : '') + ' moved' : ''), 'success'); delFor = null; load();
      });
      return;
    }
    if ((t = e.target.closest('[data-ar-add]'))) { addFor = +t.getAttribute('data-ar-add'); delFor = null; draw(); var c = $('arAddCount'); if (c) c.focus(); return; }
    if (e.target.closest('[data-ar-add-cancel]')) { addFor = null; draw(); return; }
    if ((t = e.target.closest('[data-ar-step]'))) { var inp = $('arAddCount'); inp.value = Math.max(1, Math.min(30, (+inp.value || 1) + +t.getAttribute('data-ar-step'))); updatePreview(); return; }
    if ((t = e.target.closest('[data-ar-add-go]'))) {
      t.disabled = true;
      post({ action: 'add_tables', id: addFor, count: +$('arAddCount').value, capacity: +$('arAddSeats').value, prefix: $('arAddPrefix').value }).then(function (d) {
        t.disabled = false;
        if (!d.success) { notify(d.message || 'Could not add tables', 'error'); return; }
        notify(d.created.length + ' table' + (d.created.length > 1 ? 's' : '') + ' added: ' + d.created.join(', '), 'success'); addFor = null; load();
      });
      return;
    }
    if ((t = e.target.closest('[data-ar-nav]'))) { var nav = document.querySelector('[data-page="' + t.getAttribute('data-ar-nav') + '"]'); if (nav) nav.click(); return; }
    if ((t = e.target.closest('[data-ar-idea]'))) { var k = t.getAttribute('data-ar-idea'); if (ideasPicked[k]) delete ideasPicked[k]; else ideasPicked[k] = 1; draw(); return; }
    if ((t = e.target.closest('[data-ar-ideas-go]'))) {
      var keys = Object.keys(ideasPicked).sort(); t.disabled = true;
      post({ action: 'quick', names: keys.map(function (k) { return IDEAS[k][0]; }), styles: keys.map(function (k) { return { icon: IDEAS[k][1], color: IDEAS[k][2] }; }) }).then(function (d) {
        notify(d.success ? d.created + ' area' + (d.created === 1 ? '' : 's') + ' created. Add tables to each.' : (d.message || 'Could not create'), d.success ? 'success' : 'error'); ideasPicked = {}; load();
      });
    }
  });
  document.addEventListener('input', function (e) {
    if (!D || !e.target.closest || !e.target.closest('#areasRoot')) return;
    if (e.target.id === 'arAddCount' || e.target.id === 'arAddPrefix') updatePreview();
    if (e.target.id === 'arName' || e.target.id === 'arNote') {
      readEditor();
      var b = document.querySelector('.ar-prev b'), s = document.querySelector('.ar-prev small');
      if (b) b.textContent = edit.name || 'New area';
      if (s) s.textContent = edit.note || (edit.id ? 'Your area' : 'Tables you add will show here');
      if (e.target.id === 'arName' && !edit.id && !edit.iconPicked) {
        var g = guessIcon(edit.name), ic = document.querySelector('.ar-prev .ar-ic');
        if (ic) ic.textContent = g;
        document.querySelectorAll('[data-ar-icon]').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-ar-icon') === g); });
      }
    }
  });
  document.addEventListener('change', function (e) { if (D && e.target.id === 'arAddSeats') updatePreview(); });
  document.addEventListener('keydown', function (e) {
    if (!edit) return;
    if (e.key === 'Escape') closeEditor();
    if (e.key === 'Enter' && e.target && e.target.id === 'arName') { e.preventDefault(); var s = document.querySelector('[data-ar-save]'); if (s) s.click(); }
  });

  // Drag areas into order
  document.addEventListener('dragstart', function (e) { var c = e.target.closest && e.target.closest('.ar-card'); if (!c) return; dragId = +c.getAttribute('data-ar-id'); c.classList.add('drag'); e.dataTransfer.effectAllowed = 'move'; });
  document.addEventListener('dragend', function (e) { var c = e.target.closest && e.target.closest('.ar-card'); if (c) c.classList.remove('drag'); dragId = null; document.querySelectorAll('.ar-card.over').forEach(function (x) { x.classList.remove('over'); }); });
  document.addEventListener('dragover', function (e) { var c = e.target.closest && e.target.closest('.ar-card'); if (!c || dragId == null) return; e.preventDefault(); document.querySelectorAll('.ar-card.over').forEach(function (x) { if (x !== c) x.classList.remove('over'); }); c.classList.add('over'); });
  document.addEventListener('drop', function (e) {
    var c = e.target.closest && e.target.closest('.ar-card'); if (!c || dragId == null) return; e.preventDefault();
    var to = +c.getAttribute('data-ar-id'); if (to === dragId) return;
    var ids = D.areas.map(function (a) { return a.id; }), from = ids.indexOf(dragId);
    ids.splice(from, 1); ids.splice(ids.indexOf(to) + (from <= ids.indexOf(to) ? 1 : 0), 0, dragId);
    D.areas.sort(function (x, y) { return ids.indexOf(x.id) - ids.indexOf(y.id); }); draw();
    post({ action: 'reorder', ids: ids }).then(function (d) { notify(d.success ? 'Order saved' : 'Could not save the order', d.success ? 'success' : 'error'); });
  });

  window.loadAreasStudio = function () { load(); };
})();
