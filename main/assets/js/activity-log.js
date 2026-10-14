/*
 * Activity log page (Staff › Activity log): who did what, newest first, by day. Filters: kind of
 * action (chips with counts), person, dates, search. Data: api/activity_log.php (owner only),
 * written by logActivity() in config/activity_log.php. Styles al-* in assets/css/studio-ui.css.
 */
(function () {
  'use strict';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var ICON = { order: 'receipt_long', payment: 'payments', kot: 'soup_kitchen', pos: 'point_of_sale', menu: 'restaurant_menu', coupon: 'local_offer', customer: 'group', staff: 'badge', settings: 'settings', login: 'login' };
  // the actions an owner would want to look at twice
  var WATCH = { 'order.cancel': 1, 'order.reject': 1, 'order.delete': 1, 'payment.refund': 1, 'kot.cancel': 1, 'menu.price': 1, 'menu.delete': 1, 'pos.discount': 1, 'customer.delete': 1, 'staff.delete': 1, 'coupon.delete': 1 };
  var st = { group: '', person: '', range: '7', from: '', to: '', q: '', entries: [], more: false, loading: false, data: null };
  var root = function () { return document.getElementById('alRoot'); };

  function ymd(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function dates() {
    if (st.range === 'custom') return { from: st.from, to: st.to };
    if (st.range === 'all') return { from: '', to: '' };
    var d = new Date(); d.setDate(d.getDate() - (parseInt(st.range, 10) - 1));
    return { from: ymd(d), to: '' };
  }
  function load(more) {
    if (st.loading) return;
    st.loading = true;
    var dt = dates(), qs = new URLSearchParams({ group: st.group, person: st.person, from: dt.from, to: dt.to, q: st.q });
    if (more && st.entries.length) qs.set('before', st.entries[st.entries.length - 1].id);
    if (!more) paintList(true);
    fetch('../api/activity_log.php?' + qs.toString(), { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      st.loading = false;
      if (!d.success) { root().innerHTML = '<div class="al-empty">' + ic('lock') + '<b>' + esc(d.message || 'Could not load the activity log.') + '</b></div>'; return; }
      st.data = d; st.more = d.more;
      st.entries = more ? st.entries.concat(d.entries) : d.entries;
      paint();
    }).catch(function () { st.loading = false; root().innerHTML = '<div class="al-empty">' + ic('wifi_off') + '<b>Could not load the activity log.</b><p>Check the connection and try again.</p></div>'; });
  }

  function paint() {
    var d = st.data, el = root(); if (!el || !d) return;
    var total = 0; Object.keys(d.counts || {}).forEach(function (k) { total += d.counts[k]; });
    var chips = '<button type="button" data-al-g=""' + (st.group === '' ? ' class="on"' : '') + '>All<em>' + total + '</em></button>' +
      Object.keys(d.groups).map(function (g) { var n = (d.counts || {})[g] || 0; return '<button type="button" data-al-g="' + g + '"' + (st.group === g ? ' class="on"' : '') + (n ? '' : ' disabled') + '>' + ic(ICON[g] || 'history') + esc(d.groups[g]) + '<em>' + n + '</em></button>'; }).join('');
    var people = '<option value="">Everyone</option>' + (d.people || []).map(function (p) { return '<option value="' + esc(p.actor_name) + '"' + (st.person === p.actor_name ? ' selected' : '') + '>' + esc(p.actor_name) + (p.actor_role ? ' · ' + esc(p.actor_role) : '') + '</option>'; }).join('');
    var ranges = [['1', 'Today'], ['7', '7 days'], ['30', '30 days'], ['all', 'All time'], ['custom', 'Pick dates']];
    el.innerHTML =
      '<div class="al-bar"><div class="sx-search al-search">' + ic('search') + '<input type="search" data-al-q placeholder="Search: an order number, a dish, a coupon…" value="' + esc(st.q) + '"></div>' +
      '<select class="filter-select" data-al-person aria-label="Person">' + people + '</select>' +
      '<div class="al-range">' + ranges.map(function (r) { return '<button type="button" data-al-r="' + r[0] + '"' + (st.range === r[0] ? ' class="on"' : '') + '>' + r[1] + '</button>'; }).join('') + '</div>' +
      (st.range === 'custom' ? '<div class="al-dates"><input type="date" data-al-from value="' + esc(st.from) + '"><span>to</span><input type="date" data-al-to value="' + esc(st.to) + '"></div>' : '') + '</div>' +
      '<div class="al-chips">' + chips + '</div><div class="al-list"></div>';
    paintList(false);
  }
  function when(t) { var d = new Date(String(t).replace(' ', 'T')); return isNaN(d) ? t : d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }
  function dayLabel(t) {
    var d = new Date(String(t).replace(' ', 'T')), today = new Date(), y = new Date(); y.setDate(today.getDate() - 1);
    if (ymd(d) === ymd(today)) return 'Today';
    if (ymd(d) === ymd(y)) return 'Yesterday';
    return d.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short', year: d.getFullYear() === today.getFullYear() ? undefined : 'numeric' });
  }
  function paintList(loading) {
    var list = root() && root().querySelector('.al-list');
    if (!list) { if (loading && root() && !root().innerHTML) root().innerHTML = '<div class="al-empty">' + ic('history') + '<b>Loading…</b></div>'; return; }
    if (loading) { list.innerHTML = '<div class="al-empty">' + ic('history') + '<b>Loading…</b></div>'; return; }
    if (!st.entries.length) {
      list.innerHTML = '<div class="al-empty">' + ic('history') + '<b>Nothing here yet</b><p>' + (st.q || st.group || st.person ? 'Nothing matches these filters.' : 'Cancellations, deleted orders, discounts, price changes, staff changes and logins show up here as they happen.') + '</p></div>';
      return;
    }
    var html = '', last = '';
    st.entries.forEach(function (e) {
      var day = dayLabel(e.created_at);
      if (day !== last) { html += '<h4 class="al-day">' + esc(day) + '</h4>'; last = day; }
      var det = e.details && typeof e.details === 'object' ? e.details : null, extra = '';
      if (det && det.reason && String(e.summary).indexOf(det.reason) < 0) extra = '<small class="al-why">' + ic('chat') + esc(det.reason) + '</small>';
      html += '<div class="al-row g-' + esc(e.group) + (WATCH[e.action] ? ' watch' : '') + '"><span class="al-ic">' + ic(ICON[e.group] || 'history') + '</span>' +
        '<span class="al-main"><b>' + esc(e.summary) + '</b>' + extra + '<small class="al-who">' + ic('person') + esc(e.actor_name || 'Someone') + (e.actor_role ? ' · ' + esc(e.actor_role) : '') + '</small></span>' +
        '<time>' + esc(when(e.created_at)) + '</time></div>';
    });
    if (st.more) html += '<button type="button" class="al-more" data-al-more>' + ic('expand_more') + 'Show older</button>';
    list.innerHTML = html;
  }

  var qTimer = 0;
  document.addEventListener('click', function (e) {
    if (!e.target.closest('#alRoot')) return;
    var b;
    if ((b = e.target.closest('[data-al-g]'))) { st.group = b.getAttribute('data-al-g'); load(); return; }
    if ((b = e.target.closest('[data-al-r]'))) {
      st.range = b.getAttribute('data-al-r');
      if (st.range === 'custom') { if (!st.from) { var d = new Date(); d.setDate(d.getDate() - 6); st.from = ymd(d); st.to = ymd(new Date()); } }
      load(); return;
    }
    if (e.target.closest('[data-al-more]')) load(true);
  });
  document.addEventListener('change', function (e) {
    if (!e.target.closest('#alRoot')) return;
    if (e.target.matches('[data-al-person]')) { st.person = e.target.value; load(); }
    if (e.target.matches('[data-al-from]')) { st.from = e.target.value; load(); }
    if (e.target.matches('[data-al-to]')) { st.to = e.target.value; load(); }
  });
  document.addEventListener('input', function (e) {
    if (!e.target.matches || !e.target.matches('#alRoot [data-al-q]')) return;
    st.q = e.target.value.trim();
    clearTimeout(qTimer);
    qTimer = setTimeout(function () {
      var pos = e.target.selectionStart; load();
      // keep typing in the box after the list redraws
      var once = setInterval(function () { var i = root() && root().querySelector('[data-al-q]'); if (i && !st.loading) { clearInterval(once); i.focus(); try { i.setSelectionRange(pos, pos); } catch (er) {} } }, 50);
      setTimeout(function () { clearInterval(once); }, 3000);
    }, 350);
  });

  // load each time the page opens
  function init() {
    var page = document.getElementById('activityPage'); if (!page) return;
    var was = false;
    new MutationObserver(function () { var on = page.classList.contains('active'); if (on && !was) load(); was = on; }).observe(page, { attributes: true, attributeFilter: ['class'] });
    if (page.classList.contains('active')) load();
  }
  window.rgActivityLog = { reload: function () { load(); } };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
