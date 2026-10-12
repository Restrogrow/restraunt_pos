/*
 * Offers > Today's Special (admin)
 *
 * A dish for each weekday (or one for every day), a chef's note and an optional
 * daily limit. The home page shows today's one above Bestsellers with the real
 * number of plates left. Saved through ../api/todays_special.php.
 */
(function () {
  'use strict';

  var API = '../api/todays_special.php';
  var DAYS = [[7, 'Every day', 'Used on days without their own special'], [1, 'Monday'], [2, 'Tuesday'], [3, 'Wednesday'], [4, 'Thursday'], [5, 'Friday'], [6, 'Saturday'], [0, 'Sunday']];
  var items = [], slots = {}, today = null;

  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function notify(msg, type) {
    if (typeof window.showNotification === 'function') window.showNotification(msg, type);
    else if (typeof window.showToastFallback === 'function') window.showToastFallback(msg, type);
  }

  function dishOptions(sel) {
    var html = '<option value="">No special</option>', cat = null;
    items.forEach(function (it) {
      if (it.category !== cat) { if (cat !== null) html += '</optgroup>'; cat = it.category; html += '<optgroup label="' + esc(cat) + '">'; }
      html += '<option value="' + it.id + '"' + (String(sel) === String(it.id) ? ' selected' : '') + '>' + esc(it.name) + '</option>';
    });
    return html + (cat !== null ? '</optgroup>' : '');
  }

  function render() {
    var box = $('tsSlots');
    if (!box) return;
    var todayWd = new Date().getDay();
    box.innerHTML = DAYS.map(function (d) {
      var s = slots[d[0]] || {};
      var isToday = d[0] === todayWd;
      return '<div class="ts-row' + (d[0] === 7 ? ' every' : '') + '" data-wd="' + d[0] + '">' +
        '<div class="ts-day"><b>' + d[1] + (isToday ? ' <span class="ts-today">Today</span>' : '') + '</b>' + (d[2] ? '<small>' + d[2] + '</small>' : '') + '</div>' +
        '<select class="ts-dish" aria-label="' + d[1] + ' special">' + dishOptions(s.menu_item_id) + '</select>' +
        '<input class="ts-note" maxlength="160" placeholder="Chef\'s note, e.g. Slow-cooked for 6 hours" value="' + esc(s.note || '') + '">' +
        '<input class="ts-limit" type="number" min="0" max="9999" placeholder="No limit" value="' + (s.daily_limit || '') + '" aria-label="Plates per day">' +
      '</div>';
    }).join('');
    var t = $('tsTodayNow');
    if (t) {
      if (today) {
        var it = items.filter(function (x) { return x.id === today.menu_item_id; })[0];
        t.innerHTML = '<span class="material-symbols-rounded">today</span> Showing today (' + esc(today.weekday_name) + '): <b>' + esc(it ? it.name : 'dish') + '</b>' +
          (today.daily_limit ? ' · ' + Math.max(0, today.daily_limit - today.sold_today) + ' of ' + today.daily_limit + ' plates left' : '');
      } else {
        t.innerHTML = '<span class="material-symbols-rounded">info</span> No special today — pick a dish for today or for “Every day”.';
      }
    }
  }

  function load() {
    var box = $('tsSlots');
    if (box) box.innerHTML = '<div class="ts-empty">Loading…</div>';
    fetch(API, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) { if (box) box.innerHTML = '<div class="ts-empty">' + esc(d.message || 'Could not load') + '</div>'; return; }
        items = d.items || []; today = d.today || null; slots = {};
        (d.slots || []).forEach(function (s) { slots[s.weekday] = s; });
        render();
      })
      .catch(function () { if (box) box.innerHTML = '<div class="ts-empty">Could not load. Please try again.</div>'; });
  }

  function save() {
    var out = [];
    document.querySelectorAll('#tsSlots .ts-row').forEach(function (row) {
      var dish = row.querySelector('.ts-dish').value;
      if (!dish) return;
      out.push({ weekday: +row.getAttribute('data-wd'), menu_item_id: +dish, note: row.querySelector('.ts-note').value, daily_limit: +row.querySelector('.ts-limit').value || null });
    });
    var btn = $('tsSave');
    btn.disabled = true;
    fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ slots: out }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        if (!d.success) { notify(d.message || 'Could not save', 'error'); return; }
        notify("Today's Special saved", 'success');
        load();
      })
      .catch(function () { btn.disabled = false; notify('Network error. Please try again.', 'error'); });
  }

  document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('#tsSave')) save();
  });
  window.loadTodaysSpecialAdmin = load;
})();
