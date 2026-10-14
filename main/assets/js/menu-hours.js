/*
 * Menus by time of day: the "Hours" button on a category card (Menu › Category) sets the days and
 * times the category is served, e.g. Breakfast 7–11 am. Outside them the website shows the dishes
 * with their hours instead of ADD and refuses them in orders; the POS shows the hours but still sells.
 * Saves with controllers/menu_operations.php (action set_hours); rules in config/menu_hours.php.
 * Styles mh-* in assets/css/studio-ui.css.
 */
(function () {
  'use strict';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var say = function (m, t) { if (window.SX && SX.notify) SX.notify(m, t); else if (window.showNotification) showNotification(m, t); };
  var DAYS = [['1', 'Mon'], ['2', 'Tue'], ['3', 'Wed'], ['4', 'Thu'], ['5', 'Fri'], ['6', 'Sat'], ['7', 'Sun']];
  var PRESETS = [['Breakfast', '07:00', '11:00'], ['Lunch', '12:00', '16:00'], ['Evening snacks', '16:00', '19:00'], ['Dinner', '19:00', '23:00'], ['Late night', '22:00', '02:00']];

  function hm(t) {
    var p = String(t).split(':'), h = +p[0], m = +p[1], ap = h < 12 ? 'am' : 'pm';
    return (h % 12 || 12) + (m ? ':' + String(m).padStart(2, '0') : '') + ' ' + ap;
  }
  function preview(st) {
    if (st.always) return 'Served whenever the restaurant is open.';
    var bits = [];
    if (st.from && st.to) bits.push(hm(st.from) + ' to ' + hm(st.to) + (st.to < st.from ? ' (past midnight)' : ''));
    var d = st.days.length === 7 ? 'every day' : st.days.length ? DAYS.filter(function (x) { return st.days.indexOf(x[0]) >= 0; }).map(function (x) { return x[1]; }).join(', ') : '';
    if (d) bits.push(d);
    return 'Served ' + bits.join(', ') + '. Outside these times the website shows the hours instead of ADD; the POS can still bill it.';
  }

  function open(menu) {
    var st = {
      always: !(menu.avail_days || (menu.avail_from && menu.avail_to)),
      days: (menu.avail_days || '1234567').split('').filter(function (c) { return /[1-7]/.test(c); }),
      from: (menu.avail_from || '07:00').slice(0, 5), to: (menu.avail_to || '11:00').slice(0, 5)
    };
    if (!menu.avail_from && menu.avail_days) { st.from = ''; st.to = ''; }
    var ov = document.createElement('div'); ov.className = 'mh-ov';
    ov.innerHTML = '<div class="mh-card" role="dialog" aria-modal="true" aria-label="When is it served"><div class="mh-h"><span class="mh-ic">' + ic('schedule') + '</span><div><b>When is ' + esc(menu.menu_name) + ' served?</b><small>For menus like breakfast, lunch or late night</small></div><button type="button" class="mh-x" aria-label="Close">' + ic('close') + '</button></div><div class="mh-b"></div>' +
      '<div class="mh-f"><button type="button" class="mh-cancel">Cancel</button><button type="button" class="mh-save">' + ic('check') + 'Save hours</button></div></div>';
    document.body.appendChild(ov);
    var body = ov.querySelector('.mh-b');
    function paint() {
      body.innerHTML =
        '<div class="mh-seg"><button type="button" data-mh="always"' + (st.always ? ' class="on"' : '') + '>' + ic('event_available') + 'All the time</button><button type="button" data-mh="limit"' + (!st.always ? ' class="on"' : '') + '>' + ic('schedule') + 'Only at certain times</button></div>' +
        (st.always ? '' :
          '<label class="mh-l">Quick pick</label><div class="mh-pre">' + PRESETS.map(function (p, i) { return '<button type="button" data-mh-pre="' + i + '"' + (st.from === p[1] && st.to === p[2] ? ' class="on"' : '') + '>' + p[0] + '<small>' + hm(p[1]) + '–' + hm(p[2]) + '</small></button>'; }).join('') + '</div>' +
          '<div class="mh-times"><label>From<input type="time" data-mh-t="from" value="' + esc(st.from) + '"></label><span>to</span><label>Until<input type="time" data-mh-t="to" value="' + esc(st.to) + '"></label><button type="button" class="mh-allday" data-mh="allday">All day</button></div>' +
          '<label class="mh-l">Days</label><div class="mh-days">' + DAYS.map(function (d) { return '<button type="button" data-mh-day="' + d[0] + '"' + (st.days.indexOf(d[0]) >= 0 ? ' class="on"' : '') + '>' + d[1] + '</button>'; }).join('') + '</div>' +
          '<div class="mh-quickdays"><button type="button" data-mh-days="1234567">Every day</button><button type="button" data-mh-days="12345">Mon–Fri</button><button type="button" data-mh-days="67">Weekends</button></div>') +
        '<p class="mh-prev">' + ic('info') + esc(preview(st)) + '</p>';
    }
    paint();
    function close() { ov.remove(); }
    ov.addEventListener('click', function (e) {
      if (e.target === ov || e.target.closest('.mh-x') || e.target.closest('.mh-cancel')) { close(); return; }
      var b;
      if ((b = e.target.closest('[data-mh]'))) {
        var k = b.getAttribute('data-mh');
        if (k === 'always') st.always = true;
        if (k === 'limit') { st.always = false; if (!st.days.length) st.days = '1234567'.split(''); }
        if (k === 'allday') { st.from = ''; st.to = ''; }
        paint(); return;
      }
      if ((b = e.target.closest('[data-mh-pre]'))) { var p = PRESETS[+b.getAttribute('data-mh-pre')]; st.from = p[1]; st.to = p[2]; paint(); return; }
      if ((b = e.target.closest('[data-mh-day]'))) {
        var d = b.getAttribute('data-mh-day'), i = st.days.indexOf(d);
        if (i >= 0) { if (st.days.length === 1) { say('Keep at least one day.', 'error'); return; } st.days.splice(i, 1); } else st.days.push(d);
        st.days.sort(); paint(); return;
      }
      if ((b = e.target.closest('[data-mh-days]'))) { st.days = b.getAttribute('data-mh-days').split(''); paint(); return; }
      if (e.target.closest('.mh-save')) save();
    });
    ov.addEventListener('input', function (e) { var t = e.target.getAttribute('data-mh-t'); if (t) { st[t] = e.target.value; ov.querySelector('.mh-prev').innerHTML = ic('info') + esc(preview(st)); ov.querySelectorAll('[data-mh-pre]').forEach(function (x) { x.classList.remove('on'); }); } });
    function save() {
      if (!st.always) {
        if ((st.from && !st.to) || (!st.from && st.to)) { say('Pick both times, or tap All day.', 'error'); return; }
        if (st.from && st.from === st.to) { say('The start and end times are the same.', 'error'); return; }
        if (!st.from && st.days.length === 7) { st.always = true; }
      }
      var fd = new FormData();
      fd.append('action', 'set_hours'); fd.append('menuId', menu.id);
      if (st.always) fd.append('always', '1');
      else { fd.append('days', st.days.join('')); fd.append('from', st.from || ''); fd.append('to', st.to || ''); }
      var btn = ov.querySelector('.mh-save'); btn.disabled = true;
      fetch('../controllers/menu_operations.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
        btn.disabled = false;
        if (!d.success) { say(d.message || 'Could not save the hours.', 'error'); return; }
        say(d.hours_label ? menu.menu_name + ': ' + d.hours_label : menu.menu_name + ' is served all the time', 'success');
        close();
        if (typeof window.loadMenus === 'function') window.loadMenus();
      }).catch(function () { btn.disabled = false; say('Network error. Please try again.', 'error'); });
    }
  }

  window.rgMenuHours = { open: open };
  // the Hours button on a category card (script.js displayMenus)
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-menu-hours]');
    if (!b) return;
    try { open(JSON.parse(b.getAttribute('data-menu-hours'))); } catch (err) {}
  });
})();
