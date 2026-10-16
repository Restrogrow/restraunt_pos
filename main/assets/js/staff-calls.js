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

  function card(r, fresh) {
    var ti = typeInfo(r.request_type);
    var tbl = r.table_number || '?';
    var sec = S.since(r.created_at), cls = S.ageClass(sec, WARN_MIN, LATE_MIN);
    return '<article class="st-call ' + (cls === 'ok' ? '' : cls) + (fresh ? ' fresh' : '') + '" data-id="' + (+r.id) + '" aria-label="Table ' + S.esc(tbl) + ' call">' +
      '<div class="st-call-top">' +
        '<div class="st-tbl" aria-hidden="true">' + S.esc(tbl) + '</div>' +
        '<div class="st-call-id"><b>Table ' + S.esc(tbl) + '</b><small>' + S.esc(r.area_name || 'Dining area') + ' · called ' + S.esc(S.clock(r.created_at)) + '</small></div>' +
        S.ageHtml(r.created_at, WARN_MIN, LATE_MIN, '.st-call') +
      '</div>' +
      '<div class="st-call-why"><span class="material-symbols-rounded" aria-hidden="true">' + ti.icon + '</span><div><b>' + S.esc(ti.label) + '</b>' +
        (r.notes ? '<p>“' + S.esc(r.notes) + '”</p>' : '') + '</div></div>' +
      '<button type="button" class="st-btn ok lg block" data-attend="' + (+r.id) + '"><span class="material-symbols-rounded" aria-hidden="true">check_circle</span>Attended</button>' +
    '</article>';
  }

  window.StaffCalls = {
    create: function (o) {
      var known = null, sig = null, busy = {}, last = [];
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

      function render(list, freshIds) {
        var s = list.map(function (r) { return r.id; }).join(',');
        if (s === sig && !freshIds.length) { paintSummary(list); return; }
        sig = s;
        if (!list.length) {
          o.list.innerHTML = '<div class="st-empty ok"><span class="material-symbols-rounded">done_all</span><b>No table calls right now</b><span>When a guest taps “Call waiter”, it shows up here with a sound.</span></div>';
        } else {
          o.list.innerHTML = list.map(function (r) { return card(r, freshIds.indexOf(String(r.id)) > -1); }).join('');
        }
        paintSummary(list);
      }

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
        if (e.target.closest('[data-calls-retry]')) { o.list.innerHTML = S.skeleton('card', 2); api.load(); }
      });

      api.start = function (ms) { api.load(); api.poll = S.every(api.load, ms || 8000); return api; };
      api.count = function () { return last.length; };
      o.list.classList.add('st-grid');
      o.list.setAttribute('aria-live', 'polite');
      o.list.innerHTML = S.skeleton('card', 3);
      return api;
    }
  };
})();
