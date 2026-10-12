/*
 * Offers > Today's Special (admin)
 *
 * Several dishes for each weekday (or for every day), each with a chef's note
 * and an optional daily limit. The home page shows today's ones above
 * Bestsellers with the real number of plates left. Saved through
 * ../api/todays_special.php.
 */
(function () {
  'use strict';

  var API = '../api/todays_special.php';
  var DAYS = [[7, 'Every day', 'Used on days without their own specials'], [1, 'Monday'], [2, 'Tuesday'], [3, 'Wednesday'], [4, 'Thursday'], [5, 'Friday'], [6, 'Saturday'], [0, 'Sunday']];
  var items = [], byDay = {}, today = null, MAX = 6;

  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function notify(msg, type) {
    if (typeof window.showNotification === 'function') window.showNotification(msg, type);
    else if (typeof window.showToastFallback === 'function') window.showToastFallback(msg, type);
  }
  function dishOptions(sel) {
    var html = '<option value="">Pick a dish</option>', cat = null;
    items.forEach(function (it) {
      if (it.category !== cat) { if (cat !== null) html += '</optgroup>'; cat = it.category; html += '<optgroup label="' + esc(cat) + '">'; }
      html += '<option value="' + it.id + '"' + (String(sel) === String(it.id) ? ' selected' : '') + '>' + esc(it.name) + '</option>';
    });
    return html + (cat !== null ? '</optgroup>' : '');
  }
  function entryHtml(e) {
    e = e || {};
    return '<div class="ts-entry">' +
      '<select class="ts-dish" aria-label="Dish">' + dishOptions(e.menu_item_id) + '</select>' +
      '<input class="ts-note" maxlength="160" placeholder="Chef\'s note, e.g. Slow-cooked for 6 hours" value="' + esc(e.note || '') + '">' +
      '<input class="ts-limit" type="number" min="0" max="9999" placeholder="No limit" value="' + (e.daily_limit || '') + '" aria-label="Plates per day">' +
      '<button type="button" class="ts-del" title="Remove" aria-label="Remove this dish">&times;</button>' +
    '</div>';
  }

  // Keep what's typed when re-drawing
  function readForm() {
    var out = {};
    document.querySelectorAll('#tsSlots .ts-row').forEach(function (row) {
      var wd = +row.getAttribute('data-wd');
      out[wd] = [];
      row.querySelectorAll('.ts-entry').forEach(function (en) {
        out[wd].push({ menu_item_id: en.querySelector('.ts-dish').value, note: en.querySelector('.ts-note').value, daily_limit: +en.querySelector('.ts-limit').value || null });
      });
    });
    return out;
  }

  function render() {
    var box = $('tsSlots');
    if (!box) return;
    var todayWd = new Date().getDay();
    box.innerHTML = DAYS.map(function (d) {
      var list = byDay[d[0]] || [];
      return '<div class="ts-row' + (d[0] === 7 ? ' every' : '') + '" data-wd="' + d[0] + '">' +
        '<div class="ts-day"><b>' + d[1] + (d[0] === todayWd ? ' <span class="ts-today">Today</span>' : '') + '</b>' + (d[2] ? '<small>' + d[2] + '</small>' : '') +
          '<span class="ts-count">' + (list.length ? list.length + ' dish' + (list.length > 1 ? 'es' : '') : 'none') + '</span></div>' +
        '<div class="ts-entries">' + list.map(entryHtml).join('') +
          '<button type="button" class="ts-add"' + (list.length >= MAX ? ' disabled' : '') + '><span class="material-symbols-rounded">add</span> Add dish' + (list.length >= MAX ? ' (max ' + MAX + ')' : '') + '</button>' +
        '</div></div>';
    }).join('');
    var t = $('tsTodayNow');
    if (t) {
      if (today && today.items && today.items.length) {
        var names = today.items.map(function (x) { var it = items.filter(function (i) { return i.id === x.menu_item_id; })[0]; return (it ? it.name : 'dish') + (x.daily_limit ? ' (' + Math.max(0, x.daily_limit - x.sold_today) + '/' + x.daily_limit + ' left)' : ''); });
        t.innerHTML = '<span class="material-symbols-rounded">today</span> Showing today (' + esc(today.weekday_name) + '): <b>' + esc(names.join(', ')) + '</b>';
      } else {
        t.innerHTML = '<span class="material-symbols-rounded">info</span> No special today. Add a dish for today or for “Every day”.';
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
        items = d.items || []; today = d.today || null; MAX = d.max || 6; byDay = {};
        (d.specials || []).forEach(function (s) { (byDay[s.weekday] = byDay[s.weekday] || []).push(s); });
        render();
      })
      .catch(function () { if (box) box.innerHTML = '<div class="ts-empty">Could not load. Please try again.</div>'; });
  }

  function save() {
    var form = readForm(), out = [];
    Object.keys(form).forEach(function (wd) {
      form[wd].forEach(function (e) { if (e.menu_item_id) out.push({ weekday: +wd, menu_item_id: +e.menu_item_id, note: e.note, daily_limit: e.daily_limit }); });
    });
    var btn = $('tsSave');
    btn.disabled = true;
    fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ specials: out }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        if (!d.success) { notify(d.message || 'Could not save', 'error'); return; }
        notify("Today's Specials saved", 'success');
        load();
      })
      .catch(function () { btn.disabled = false; notify('Network error. Please try again.', 'error'); });
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('#tsSave, #todaysSpecialPage .ts-add, #todaysSpecialPage .ts-del') : null;
    if (!t) return;
    if (t.id === 'tsSave') { save(); return; }
    var row = t.closest('.ts-row'), wd = +row.getAttribute('data-wd');
    byDay = readForm();
    if (t.classList.contains('ts-add')) {
      if ((byDay[wd] || []).length < MAX) (byDay[wd] = byDay[wd] || []).push({});
    } else {
      var idx = Array.prototype.indexOf.call(row.querySelectorAll('.ts-entry'), t.closest('.ts-entry'));
      byDay[wd].splice(idx, 1);
    }
    render();
    if (t.classList.contains('ts-add')) { var last = $('tsSlots').querySelector('.ts-row[data-wd="' + wd + '"] .ts-entry:last-of-type .ts-dish'); if (last) last.focus(); }
  });
  window.loadTodaysSpecialAdmin = load;
})();
