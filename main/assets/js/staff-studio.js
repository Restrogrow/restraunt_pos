/**
 * Staff page (dashboard › Staff): the team as cards, by role, with when each person last logged in /
 * was active and what they did lately; pause or give back someone's access.
 * Data: api/staff_overview.php. Add / Edit / Delete reuse the existing staff modal and
 * window.editStaff / window.deleteStaff (script.js). Styles: stf-* in studio-ui.css.
 */
(function () {
  'use strict';
  var D = null;
  var role = '';
  var esc = function (s) { return window.SX ? SX.esc(s) : String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var notify = function (m, t) { if (window.SX) SX.notify(m, t); };
  var ROLE = { Manager: ['#7c3aed', 'shield_person'], Waiter: ['#0891b2', 'room_service'], Chef: ['#ea580c', 'skillet'], Cashier: ['#16a34a', 'point_of_sale'], Delivery: ['#db2777', 'two_wheeler'] };
  function look(r) { return ROLE[r] || ['#64748b', 'person']; }
  // "Neha (Koramangala)" -> "NE"; "Ravi Kumar" -> "RK"
  function initials(name) {
    var w = String(name || '').replace(/\(.*?\)/g, ' ').replace(/[^\p{L}\p{N} ]/gu, ' ').trim().split(/\s+/).filter(Boolean);
    if (!w.length) return '?';
    return (w.length > 1 ? w[0].charAt(0) + w[1].charAt(0) : w[0].slice(0, 2)).toUpperCase();
  }
  function ago(d) {
    if (!d) return 'never';
    var s = (Date.now() - new Date(String(d).replace(' ', 'T')).getTime()) / 1000;
    if (s < 90) return 'just now';
    if (s < 3600) return Math.round(s / 60) + ' min ago';
    if (s < 86400) return Math.round(s / 3600) + ' h ago';
    var dd = Math.round(s / 86400);
    return dd < 30 ? dd + (dd === 1 ? ' day ago' : ' days ago') : new Date(String(d).replace(' ', 'T')).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  }
  function fresh(d) { return d && (Date.now() - new Date(String(d).replace(' ', 'T')).getTime()) < 15 * 60000; }

  function load() {
    var page = document.getElementById('staffPage');
    if (!page) return;
    var box = document.getElementById('stfRoot');
    if (!box) {
      box = document.createElement('div'); box.id = 'stfRoot';
      var tbl = page.querySelector('.table-wrapper');
      if (tbl) { tbl.style.display = 'none'; tbl.parentNode.insertBefore(box, tbl); } else page.querySelector('.page-content').appendChild(box);
    }
    if (!D) box.innerHTML = '<div class="cbx-loading"><span></span><span></span><span></span></div>';
    fetch('../api/staff_overview.php', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.success) { box.innerHTML = '<div class="sx-note warn">' + ic('warning') + '<span>' + esc(d.message || 'Could not load staff') + '</span></div>'; return; }
      D = d; draw();
    }).catch(function () { box.innerHTML = '<div class="sx-note warn">' + ic('warning') + '<span>No connection. Please try again.</span></div>'; });
  }

  function list() {
    var q = ((document.getElementById('staffSearch') || {}).value || '').toLowerCase().trim();
    var sort = (document.getElementById('staffSortBy') || {}).value || 'name';
    var out = D.staff.filter(function (s) {
      return (!role || s.role === role) && (!q || [s.member_name, s.email, s.phone, s.role].join(' ').toLowerCase().indexOf(q) !== -1);
    });
    out.sort(function (a, b) {
      if (a.is_active !== b.is_active) return b.is_active - a.is_active;
      return sort === 'role' ? (a.role.localeCompare(b.role) || a.member_name.localeCompare(b.member_name)) : a.member_name.localeCompare(b.member_name);
    });
    return out;
  }

  function draw() {
    var box = document.getElementById('stfRoot');
    if (!box || !D) return;
    var t = D.totals, roles = Object.keys(t.roles).sort();
    var rows = list();
    box.innerHTML =
      '<div class="sx-stats stf-stats">' +
        '<div>' + ic('groups') + '<b>' + t.total + '</b><small>Team members</small></div>' +
        '<div class="ok">' + ic('check_circle') + '<b>' + t.active + '</b><small>Can log in</small></div>' +
        '<div>' + ic('bolt') + '<b>' + t.today + '</b><small>Active today</small></div>' +
        '<div class="wait">' + ic('block') + '<b>' + (t.total - t.active) + '</b><small>Access paused</small></div>' +
      '</div>' +
      (roles.length ? '<div class="cbx-chips stf-roles"><button type="button" class="cbx-chip' + (!role ? ' on' : '') + '" data-stf-role="">All <em>' + t.total + '</em></button>' +
        roles.map(function (r) { return '<button type="button" class="cbx-chip' + (role === r ? ' on' : '') + '" data-stf-role="' + esc(r) + '" style="--c:' + look(r)[0] + '">' + ic(look(r)[1]) + esc(r) + ' <em>' + t.roles[r] + '</em></button>'; }).join('') + '</div>' : '') +
      (rows.length ? '<div class="stf-grid">' + rows.map(card).join('') + '</div>' :
        '<div class="cbx-empty"><div class="cbx-empty-art">' + ic('groups') + ic('person_add') + '</div><h3>' + (D.staff.length ? 'Nobody matches' : 'No team members yet') + '</h3><p>' +
        (D.staff.length ? 'Try another search or role.' : 'Add your managers, waiters and chefs. Each gets their own login with only what their role needs.') + '</p>' +
        (!D.staff.length && D.can_edit ? '<button type="button" class="btn btn-primary" data-stf-add>' + ic('add') + 'Add staff</button>' : '') + '</div>');
  }

  function card(s, i) {
    var l = look(s.role), on = fresh(s.last_active);
    return '<article class="stf-card' + (s.is_active ? '' : ' paused') + '" style="--c:' + l[0] + ';--d:' + Math.min(i, 12) * 50 + 'ms">' +
      '<div class="stf-top"><span class="stf-av">' + esc(initials(s.member_name)) + (on ? '<i class="stf-on" title="Active in the last 15 minutes"></i>' : '') + '</span>' +
        '<div class="stf-id"><b>' + esc(s.member_name) + '</b><span class="stf-role">' + ic(l[1]) + esc(s.role) + '</span></div>' +
        (s.is_active ? '' : '<span class="sx-pill wait">' + ic('block') + 'Paused</span>') + '</div>' +
      '<div class="stf-contact">' + (s.phone ? '<a href="tel:' + esc(s.phone) + '">' + ic('call') + esc(s.phone) + '</a>' : '') + (s.email ? '<a href="mailto:' + esc(s.email) + '">' + ic('mail') + '<span>' + esc(s.email) + '</span></a>' : '') + '</div>' +
      '<div class="stf-meta"><div><small>Last login</small><b>' + ago(s.last_login) + '</b></div><div><small>Last active</small><b' + (on ? ' class="ok"' : '') + '>' + ago(s.last_active) + '</b></div><div><small>Actions · 7 days</small><b>' + s.actions_7d + '</b></div></div>' +
      (s.recent.length ? '<ul class="stf-recent">' + s.recent.map(function (r) { return '<li><span>' + esc(r.summary) + '</span><small>' + ago(r.at) + '</small></li>'; }).join('') + '</ul>' : '<p class="stf-quiet">' + ic('history') + 'No actions recorded yet.</p>') +
      (D.can_edit ? '<div class="stf-acts"><button type="button" class="btn btn-secondary btn-sm" data-stf-edit="' + s.id + '">' + ic('edit') + 'Edit</button>' +
        '<button type="button" class="btn btn-secondary btn-sm" data-stf-toggle="' + s.id + '">' + ic(s.is_active ? 'block' : 'check_circle') + (s.is_active ? 'Pause access' : 'Give access') + '</button>' +
        '<span class="cbx-grow"></span><button type="button" class="sx-icon-btn" data-stf-del="' + s.id + '" title="Delete">' + ic('delete') + '</button></div>' : '') +
    '</article>';
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-stf-role],[data-stf-edit],[data-stf-toggle],[data-stf-del],[data-stf-add]');
    if (!t || !D) return;
    var find = function (id) { return D.staff.filter(function (s) { return s.id === +id; })[0]; };
    if (t.hasAttribute('data-stf-role')) { role = t.getAttribute('data-stf-role'); draw(); return; }
    if (t.hasAttribute('data-stf-add')) { var b = document.getElementById('addStaffBtn'); if (b) b.click(); return; }
    if (t.hasAttribute('data-stf-edit')) { var s = find(t.getAttribute('data-stf-edit')); if (s && window.editStaff) window.editStaff(s.id, s.member_name, s.email || '', s.phone || '', s.role); return; }
    if (t.hasAttribute('data-stf-del')) { var d = find(t.getAttribute('data-stf-del')); if (d && window.deleteStaff) window.deleteStaff(d.id, d.member_name); return; }
    if (t.hasAttribute('data-stf-toggle')) {
      var m = find(t.getAttribute('data-stf-toggle')); if (!m) return;
      var on = !m.is_active;
      t.disabled = true;
      fetch('../api/staff_overview.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'toggle', id: m.id, is_active: on ? 1 : 0 }) })
        .then(function (r) { return r.json(); }).then(function (x) {
          notify(x.success ? (on ? m.member_name + ' can log in again' : m.member_name + ' can’t log in now') : (x.message || 'Could not save'), x.success ? 'success' : 'error');
          load();
        }).catch(function () { t.disabled = false; notify('No connection. Please try again.', 'error'); });
    }
  });
  document.addEventListener('input', function (e) { if (e.target.id === 'staffSearch' && D) draw(); });
  document.addEventListener('change', function (e) { if (e.target.id === 'staffSortBy' && D) draw(); });

  // the old table still gets filled by script.js (export uses it); when it changes (after add / edit /
  // delete), the cards reload too
  function watch() {
    var tb = document.getElementById('staffList');
    if (!tb || tb._stfWatched) return;
    tb._stfWatched = true;
    var t = null;
    new MutationObserver(function () { clearTimeout(t); t = setTimeout(load, 150); }).observe(tb, { childList: true });
  }
  document.addEventListener('click', function (e) { if (e.target.closest('.nav-link[data-page="staffPage"]')) setTimeout(function () { watch(); load(); }, 50); });
  document.addEventListener('DOMContentLoaded', function () { watch(); if (window.SX && SX.isActive('staffPage')) load(); });
})();
