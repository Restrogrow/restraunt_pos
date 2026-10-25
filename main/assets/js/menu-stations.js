/*
 * Kitchen or bar: the "Kitchen / Bar" button on a category card (Menu › Category) sets where its
 * dishes are made, and any dish in it made at the other place (a lassi in Beverages made in the
 * kitchen, say). Bar dishes go on a Bar Order Ticket (BOT) instead of the KOT, with its own
 * number, screen tab and printer.
 * Saves with controllers/menu_operations.php (get_station / set_station); rules in config/prep_stations.php.
 * Styles: the mh-* dialog of menu-hours.js, plus ms-* in assets/css/studio-ui.css.
 */
(function () {
  'use strict';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var say = function (m, t) { if (window.SX && SX.notify) SX.notify(m, t); else if (window.showNotification) showNotification(m, t); };
  var PLACES = [['kitchen', 'Kitchen', 'soup_kitchen', 'KOT'], ['bar', 'Bar', 'local_bar', 'BOT']];

  function post(fields) {
    var fd = new FormData();
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch('../controllers/menu_operations.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }

  function open(menu) {
    var ov = document.createElement('div'); ov.className = 'mh-ov';
    ov.innerHTML = '<div class="mh-card" role="dialog" aria-modal="true" aria-label="Where is it made"><div class="mh-h"><span class="mh-ic">' + ic('local_bar') + '</span><div><b>Where is ' + esc(menu.menu_name) + ' made?</b><small>Bar dishes print on a Bar Order Ticket (BOT), not the KOT</small></div><button type="button" class="mh-x" aria-label="Close">' + ic('close') + '</button></div><div class="mh-b"><p class="mh-prev">' + ic('hourglass_top') + 'Loading…</p></div>' +
      '<div class="mh-f"><button type="button" class="mh-cancel">Cancel</button><button type="button" class="mh-save" disabled>' + ic('check') + 'Save</button></div></div>';
    document.body.appendChild(ov);
    var body = ov.querySelector('.mh-b');
    var st = null;   // { station, items: [{ id, name, own: 'kitchen' | 'bar' }] }

    function where(it) { return it.own || st.station; }
    function paint() {
      var other = st.items.filter(function (it) { return where(it) !== st.station; }).length;
      var place = PLACES.filter(function (p) { return p[0] === st.station; })[0];
      body.innerHTML =
        '<label class="mh-l">All dishes in ' + esc(menu.menu_name) + ' are made at</label>' +
        '<div class="mh-seg">' + PLACES.map(function (p) { return '<button type="button" data-ms="' + p[0] + '"' + (st.station === p[0] ? ' class="on"' : '') + '>' + ic(p[2]) + p[1] + ' <small>(' + p[3] + ')</small></button>'; }).join('') + '</div>' +
        (st.items.length ? '<label class="mh-l">Dishes made somewhere else' + (other ? ' <em class="ms-n">' + other + '</em>' : '') + '</label>' +
          '<div class="ms-list">' + st.items.map(function (it) {
            var w = where(it);
            return '<div class="ms-row' + (w !== st.station ? ' diff' : '') + '"><span>' + esc(it.name) + '</span><div class="ms-pick">' + PLACES.map(function (p) {
              return '<button type="button" data-ms-item="' + it.id + '" data-ms-to="' + p[0] + '" aria-pressed="' + (w === p[0]) + '"' + (w === p[0] ? ' class="on"' : '') + '>' + ic(p[2]) + p[1] + '</button>';
            }).join('') + '</div></div>';
          }).join('') + '</div>' : '<p class="mh-prev">' + ic('info') + 'No dishes in this category yet.</p>') +
        '<p class="mh-prev">' + ic('info') + esc(place[1] === 'Bar'
          ? 'New orders send these dishes to the bar on a BOT' + (other ? ', except the ' + other + ' made in the kitchen' : '') + '. Set where it prints in Settings › Printer › Bar tickets.'
          : 'These dishes go to the kitchen on the KOT' + (other ? ', except the ' + other + ' made at the bar (BOT)' : '') + '.') + '</p>';
    }

    post({ action: 'get_station', menuId: menu.id }).then(function (d) {
      if (!d.success) { say(d.message || 'Could not load this category.', 'error'); ov.remove(); return; }
      st = { station: d.station, items: (d.items || []).map(function (it) { return { id: it.id, name: it.name, own: it.prep_station || '' }; }) };
      ov.querySelector('.mh-save').disabled = false;
      paint();
    }).catch(function () { say('Network error. Please try again.', 'error'); ov.remove(); });

    function close() { ov.remove(); }
    ov.addEventListener('click', function (e) {
      if (e.target === ov || e.target.closest('.mh-x') || e.target.closest('.mh-cancel')) { close(); return; }
      if (!st) return;
      var b;
      if ((b = e.target.closest('[data-ms]'))) { st.station = b.getAttribute('data-ms'); paint(); return; }
      if ((b = e.target.closest('[data-ms-item]'))) {
        var id = b.getAttribute('data-ms-item'), to = b.getAttribute('data-ms-to');
        st.items.forEach(function (it) { if (String(it.id) === id) it.own = to === st.station ? '' : to; });
        paint(); return;
      }
      if (e.target.closest('.mh-save')) save();
    });
    function save() {
      var items = {};
      st.items.forEach(function (it) { items[it.id] = where(it) === st.station ? '' : where(it); });
      var btn = ov.querySelector('.mh-save'); btn.disabled = true;
      post({ action: 'set_station', menuId: menu.id, station: st.station, items: JSON.stringify(items) }).then(function (d) {
        btn.disabled = false;
        if (!d.success) { say(d.message || 'Could not save.', 'error'); return; }
        say(menu.menu_name + (d.station === 'bar' ? ' is made at the bar: its dishes print on a BOT' : ' is made in the kitchen'), 'success');
        close();
        if (typeof window.loadMenus === 'function') window.loadMenus();
      }).catch(function () { btn.disabled = false; say('Network error. Please try again.', 'error'); });
    }
  }

  window.rgMenuStations = { open: open };
  // the Kitchen / Bar button on a category card (script.js displayMenus)
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-menu-station]');
    if (!b) return;
    try { open(JSON.parse(b.getAttribute('data-menu-station'))); } catch (err) {}
  });
})();
