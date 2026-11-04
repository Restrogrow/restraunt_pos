/*
 * Table calls ("waiter requests") board, used by the waiter and manager screens.
 * Reads  ../api/get_waiter_requests.php (pending calls)
 * Writes ../controllers/waiter_request_operations.php  action=mark_attended
 *
 *   var calls = StaffCalls.create({ list: el, summary: el, onCount: fn(n), onNew: fn(newCalls) });
 *   calls.load();  calls.start(ms);
 */
(function () {
  'use strict';
  var S = window.StaffUI;
  var WARN_MIN = 2, LATE_MIN = 5; // a guest waiting 2+ min is amber, 5+ min is red

  var TYPES = {
    bill: { icon: 'receipt_long', label: 'Asked for the bill' },
    water: { icon: 'water', label: 'Needs water' },
    order: { icon: 'restaurant_menu', label: 'Wants to order' },
    cutlery: { icon: 'restaurant_menu', label: 'Needs cutlery or napkins' },
    general: { icon: 'room_service', label: 'Needs a waiter' }
  };
  function typeInfo(t) {
    var k = String(t || 'general').toLowerCase().trim();
    if (TYPES[k]) return TYPES[k];
    if (/bill|pay|check/.test(k)) return TYPES.bill;
    if (/water|drink/.test(k)) return TYPES.water;
    if (/cutlery|napkin|spoon|fork/.test(k)) return TYPES.cutlery;
    if (/order|menu|food/.test(k)) return TYPES.order;
    return { icon: 'room_service', label: t ? String(t) : TYPES.general.label };
  }

  /** Has somebody said they are going to this table, and is it me? (waiter_requests.taken_by) */
  function taken(r) { return !!(r.taken_by_name || r.taken_by); }
  function mine(r) { return taken(r) && S.isMine(r.taken_by, r.taken_by_name); }

  function card(r, fresh) {
    var ti = typeInfo(r.request_type);
    var tbl = r.table_number || '?';
    var sec = S.since(r.created_at), cls = S.ageClass(sec, WARN_MIN, LATE_MIN);
    var who = taken(r), its = mine(r);
    // somebody is already walking over: say so plainly, and stop shouting the age colour at everyone
    var note = who
      ? '<div class="st-call-taken' + (its ? ' me' : '') + '"><span class="material-symbols-rounded" aria-hidden="true">' +
          (its ? 'person_check' : 'directions_walk') + '</span>' +
          (its ? 'You are going' : S.esc(r.taken_by_name || 'Someone') + ' is going') + '</div>'
      : '';
    var acts = who
      ? (its
          ? '<div class="st-call-acts"><button type="button" class="st-btn ok lg" data-attend="' + (+r.id) + '"><span class="material-symbols-rounded" aria-hidden="true">check_circle</span>Attended</button>' +
            '<button type="button" class="st-btn ghost" data-release="' + (+r.id) + '">Not me</button></div>'
          : '<button type="button" class="st-btn ghost lg block" data-attend="' + (+r.id) + '"><span class="material-symbols-rounded" aria-hidden="true">check_circle</span>Attended</button>')
      : '<div class="st-call-acts"><button type="button" class="st-btn lg" data-take="' + (+r.id) + '"><span class="material-symbols-rounded" aria-hidden="true">waving_hand</span>I’m going</button>' +
        '<button type="button" class="st-btn ok lg" data-attend="' + (+r.id) + '"><span class="material-symbols-rounded" aria-hidden="true">check_circle</span>Attended</button></div>';

    return '<article class="st-call ' + (who && !its ? 'taken ' : '') + (cls === 'ok' || (who && !its) ? '' : cls) + (fresh ? ' fresh' : '') + '" data-id="' + (+r.id) + '" aria-label="Table ' + S.esc(tbl) + ' call">' +
      '<div class="st-call-top">' +
        '<div class="st-tbl" aria-hidden="true">' + S.esc(tbl) + '</div>' +
        '<div class="st-call-id"><b>Table ' + S.esc(tbl) + '</b><small>' + S.esc(r.area_name || 'Dining area') + ' · called ' + S.esc(S.clock(r.created_at)) + '</small></div>' +
        S.ageHtml(r.created_at, WARN_MIN, LATE_MIN, '.st-call') +
      '</div>' +
      '<div class="st-call-why"><span class="material-symbols-rounded" aria-hidden="true">' + ti.icon + '</span><div><b>' + S.esc(ti.label) + '</b>' +
        (r.notes ? '<p>“' + S.esc(r.notes) + '”</p>' : '') + '</div></div>' +
      note + acts +
    '</article>';
  }

  /** All / nobody going yet / the ones I took. Only worth showing once a call has been taken. */
  var FILTERS = [
    { key: 'all', label: 'All', keep: function () { return true; } },
    { key: 'free', label: 'Nobody going', keep: function (r) { return !taken(r); } },
    { key: 'mine', label: 'Mine', keep: mine }
  ];

  window.StaffCalls = {
    create: function (o) {
      var known = null, sig = null, busy = {}, last = [];
      var filter = 'all', chips = null;
      var api = {};

      function paintSummary(list) {
        if (!o.summary) return;
        if (!list.length) { o.summary.innerHTML = ''; return; }
        var oldest = 0; list.forEach(function (r) { oldest = Math.max(oldest, S.since(r.created_at)); });
        var late = list.filter(function (r) { return S.since(r.created_at) >= LATE_MIN * 60; }).length;
        o.summary.innerHTML = '<span class="st-pill info"><span class="material-symbols-rounded">notifications_active</span>' + list.length + ' waiting</span>' +
          '<span class="st-pill ' + S.ageClass(oldest, WARN_MIN, LATE_MIN).replace('late', 'bad') + '"><span class="material-symbols-rounded">timer</span>Longest ' + S.fmtAge(oldest) + '</span>' +
          (late ? '<span class="st-pill bad">' + late + ' over ' + LATE_MIN + ' min</span>' : '');
      }

      function paintChips(list) {
        if (!chips) return;
        // nothing is taken yet, so "Nobody going" and "Mine" would say the same thing as "All"
        if (!list.some(taken)) { chips.innerHTML = ''; chips.hidden = true; return; }
        chips.hidden = false;
        chips.innerHTML = FILTERS.map(function (f) {
          var n = list.filter(f.keep).length;
          return '<button type="button" class="st-chip' + (filter === f.key ? ' on' : '') + '" data-calls-filter="' + f.key + '"' +
            (filter === f.key ? ' aria-current="true"' : '') + '>' + f.label + ' <em>' + n + '</em></button>';
        }).join('');
      }

      function render(list, freshIds) {
        paintChips(list);
        var f = FILTERS.filter(function (x) { return x.key === filter; })[0] || FILTERS[0];
        var shown = list.filter(f.keep);
        // the signature carries who has taken what, so a card repaints the moment someone takes it
        var s = filter + '|' + list.map(function (r) { return r.id + ':' + (r.taken_by_name || ''); }).join(',');
        if (s === sig && !freshIds.length) { paintSummary(list); return; }
        sig = s;
        if (!shown.length) {
          o.list.innerHTML = list.length
            ? '<div class="st-empty ok"><span class="material-symbols-rounded">filter_alt_off</span><b>' +
              (filter === 'mine' ? 'You haven’t taken any calls' : 'Every call has someone going') +
              '</b><span>Tap “All” to see the other ' + list.length + '.</span></div>'
            : '<div class="st-empty ok"><span class="material-symbols-rounded">done_all</span><b>No table calls right now</b><span>When a guest taps “Call waiter”, it shows up here with a sound.</span></div>';
        } else {
          o.list.innerHTML = shown.map(function (r) { return card(r, freshIds.indexOf(String(r.id)) > -1); }).join('');
        }
        paintSummary(list);
      }

      /** "I'm going" / "Not me". The call stays on everyone's board either way. */
      api.take = function (id, btn, release) {
        if (busy[id]) return;
        busy[id] = true;
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="st-spin"></span>'; }
        S.postForm('../controllers/waiter_request_operations.php', { action: 'take', requestId: id, release: release ? '1' : '' })
          .then(function (res) {
            delete busy[id];
            if (!res.success) { S.toast(res.message || 'Could not update', 'warning'); }
            else if (!release) S.toast(res.message || 'The table knows you are coming', 'success');
            sig = null;
            return api.load();
          })
          .catch(function (e) {
            delete busy[id];
            S.toast(e.message || 'Could not update', 'error');
            sig = null; api.load();
          });
      };

      api.load = function () {
        return S.getJSON('../api/get_waiter_requests.php?restaurant_id=' + S.rid).then(function (res) {
          if (!res || !res.success) throw new Error((res && res.message) || 'Could not load calls');
          var list = (res.requests || []).filter(function (r) { return String(r.status || 'Pending').toLowerCase() === 'pending' && !busy[r.id]; });
          list.sort(function (a, b) { return S.parseTime(a.created_at) - S.parseTime(b.created_at); }); // longest wait first
          var ids = list.map(function (r) { return String(r.id); });
          var fresh = known ? ids.filter(function (id) { return known.indexOf(id) === -1; }) : [];
          known = ids; last = list;
          render(list, fresh);
          if (o.updated) o.updated.textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
          if (o.onCount) o.onCount(list.length);
          if (fresh.length) {
            var nw = list.filter(function (r) { return fresh.indexOf(String(r.id)) > -1; });
            S.sound.play();
            var first = nw[0];
            S.toast(nw.length > 1 ? nw.length + ' new table calls' : 'Table ' + (first.table_number || '?') + ' – ' + typeInfo(first.request_type).label.toLowerCase(), 'warning', { title: 'New table call', duration: 6000 });
            if (o.onNew) o.onNew(nw);
          }
          return list;
        }).catch(function (e) {
          console.error('Error loading requests:', e);
          if (known === null) o.list.innerHTML = '<div class="st-empty bad"><span class="material-symbols-rounded">wifi_off</span><b>Couldn’t load table calls</b><span>Check the connection – retrying automatically.</span><button type="button" class="st-btn ghost" data-calls-retry>Try again</button></div>';
        });
      };

      api.attend = function (id, btn) {
        if (busy[id]) return;
        busy[id] = true;
        var cardEl = btn && btn.closest('.st-call');
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="st-spin"></span>Saving…'; }
        S.postForm('../controllers/waiter_request_operations.php', { action: 'mark_attended', requestId: id }).then(function (res) {
          if (!res.success) throw new Error(res.message || 'Failed to update request');
          var tbl = cardEl ? cardEl.querySelector('.st-tbl').textContent : '';
          S.toast((tbl ? 'Table ' + tbl + ' ' : 'Call ') + 'marked as attended', 'success');
          if (cardEl) { cardEl.classList.add('gone'); }
          setTimeout(function () { delete busy[id]; sig = null; api.load(); }, 280);
        }).catch(function (e) {
          delete busy[id];
          S.toast(e.message || 'Error updating status', 'error');
          if (btn) { btn.disabled = false; btn.innerHTML = '<span class="material-symbols-rounded">check_circle</span>Attended'; }
        });
      };

      o.list.addEventListener('click', function (e) {
        var b = e.target.closest('[data-attend]');
        if (b) { api.attend(+b.getAttribute('data-attend'), b); return; }
        var t = e.target.closest('[data-take]');
        if (t) { api.take(+t.getAttribute('data-take'), t, false); return; }
        var r = e.target.closest('[data-release]');
        if (r) { api.take(+r.getAttribute('data-release'), r, true); return; }
        if (e.target.closest('[data-calls-retry]')) { o.list.innerHTML = S.skeleton('card', 2); api.load(); }
      });

      api.start = function (ms) { api.load(); api.poll = S.every(api.load, ms || 8000); return api; };
      api.count = function () { return last.length; };

      // the filter bar sits above the grid, not inside it
      chips = document.createElement('div');
      chips.className = 'st-chips';
      chips.hidden = true;
      chips.addEventListener('click', function (e) {
        var c = e.target.closest('[data-calls-filter]');
        if (!c) return;
        filter = c.getAttribute('data-calls-filter');
        sig = null;
        render(last, []);
      });
      if (o.list.parentNode) o.list.parentNode.insertBefore(chips, o.list);

      o.list.classList.add('st-grid');
      o.list.setAttribute('aria-live', 'polite');
      o.list.innerHTML = S.skeleton('card', 3);
      return api;
    }
  };
})();
