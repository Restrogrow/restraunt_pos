/*
 * Kitchen display board (KOT tickets): chef screen and the manager's Kitchen tab.
 * Reads  ../api/get_kot.php                     (Pending / Preparing / Ready tickets)
 * Writes ../controllers/kot_operations.php     action=update_kot_status (Pending -> Preparing -> Ready)
 *                                              action=complete_kot      (Ready -> done, order moves to Orders)
 *
 *   var kds = StaffKDS.create({ board, stages, stats, updated, onCounts: fn({pending,preparing,ready}) });
 *   kds.start();
 * Ticket timer: amber after 10 min, red after 20 min.
 */
(function () {
  'use strict';
  var S = window.StaffUI;
  var WARN_MIN = 10, LATE_MIN = 20;
  var COLS = [
    { key: 'pending', title: 'New', icon: 'fiber_new', empty: 'No new tickets', emptyIcon: 'inbox' },
    { key: 'preparing', title: 'Cooking', icon: 'local_fire_department', empty: 'Nothing on the stove', emptyIcon: 'skillet' },
    { key: 'ready', title: 'Ready', icon: 'room_service', empty: 'Nothing waiting at the pass', emptyIcon: 'room_service' }
  ];
  var ACTION = {
    pending: { label: 'Start cooking', icon: 'local_fire_department', cls: 'warn', next: 'Preparing' },
    preparing: { label: 'Mark ready', icon: 'check_circle', cls: 'ok', next: 'Ready' },
    ready: { label: 'Done – served', icon: 'done_all', cls: 'dark', next: 'Complete' }
  };

  function stageOf(k) { return String(k.kot_status || k.status || 'Pending').toLowerCase(); }
  function shortNo(k) { var n = String(k.kot_number || k.id); var m = n.match(/(\d{3,5})$/); return m ? m[1] : n; }
  function addonText(it) {
    var a = it.addons;
    if (Array.isArray(a) && a.length) {
      return a.map(function (x) { return typeof x === 'string' ? x : (x && (x.name || x.addon_name || x.title || x.label)) || ''; }).filter(Boolean).join(', ');
    }
    return it.addon_name || '';
  }

  function ticket(k, fresh) {
    var st = stageOf(k), act = ACTION[st] || ACTION.pending;
    var type = String(k.order_type || '').toLowerCase();
    var isTable = !!k.table_number;
    var tile = isTable ? S.esc(k.table_number) : '<span class="material-symbols-rounded" aria-hidden="true">' + (type === 'delivery' ? 'two_wheeler' : 'shopping_bag') + '</span>';
    var cname = k.customer_name && !/^(table customer|takeaway|walk-?in|guest)$/i.test(String(k.customer_name).trim()) ? k.customer_name : '';
    var title = isTable ? 'Table ' + k.table_number : (cname || (k.order_type || 'Takeaway'));
    var sub = [isTable ? (k.area_name || '') : '', k.order_type || ''].filter(Boolean).join(' · ');
    var who = '';
    if (cname && (k.customer_phone || k.customer_address || isTable)) {
      who = '<div class="kds-who">' + (isTable ? '<b>' + S.esc(cname) + '</b>' : '') +
        (k.customer_phone ? '<span><span class="material-symbols-rounded">call</span>' + S.esc(k.customer_phone) + '</span>' : '') +
        (k.customer_email ? '<span><span class="material-symbols-rounded">mail</span>' + S.esc(k.customer_email) + '</span>' : '') +
        (k.customer_address ? '<span><span class="material-symbols-rounded">location_on</span>' + S.esc(k.customer_address) + '</span>' : '') + '</div>';
    }
    var items = (k.items || []).map(function (it) {
      var note = it.notes || it.special_instructions, add = addonText(it);
      return '<li><span class="kds-q">' + S.esc(it.quantity || 1) + '×</span><div><b>' + S.esc(it.item_name_translated || it.item_name || it.name || 'Item') + '</b>' +
        (add ? '<span class="kds-add">+ ' + S.esc(add) + '</span>' : '') +
        (note ? '<span class="kds-note"><span class="material-symbols-rounded" aria-hidden="true">sticky_note_2</span>' + S.esc(note) + '</span>' : '') + '</div></li>';
    }).join('');
    var tnote = k.notes && !/^\s*\[KOT:/.test(k.notes) ? '<p class="kds-note kds-tnote"><span class="material-symbols-rounded" aria-hidden="true">info</span>' + S.esc(k.notes) + '</p>' : '';
    return '<article class="kds-t' + (fresh ? ' fresh' : '') + '" data-stage="' + st + '" data-id="' + (+k.id) + '" aria-label="KOT ' + S.esc(shortNo(k)) + ', ' + S.esc(title) + '">' +
      '<div class="kds-t-top"><div class="kds-tbl">' + tile + '</div>' +
        '<div class="kds-id"><small>KOT #' + S.esc(shortNo(k)) + ' · ' + (k.items || []).length + ' item' + ((k.items || []).length === 1 ? '' : 's') + '</small><b>' + S.esc(title) + '</b>' + (sub ? '<span>' + S.esc(sub) + '</span>' : '') + '</div>' +
        S.ageHtml(k.created_at, WARN_MIN, LATE_MIN, '.kds-t') + '</div>' +
      who + '<ul class="kds-items">' + items + '</ul>' + tnote +
      '<button type="button" class="st-btn lg ' + act.cls + '" data-kds-act="' + (+k.id) + '" data-next="' + act.next + '"><span class="material-symbols-rounded" aria-hidden="true">' + act.icon + '</span>' + act.label + '</button>' +
    '</article>';
  }

  window.StaffKDS = {
    create: function (o) {
      var api = {}, all = null, known = null, colSig = {}, busy = {};
      var VIEW_KEY = 'staffKdsView';
      var view = 'all';
      try { view = localStorage.getItem(VIEW_KEY) || 'all'; } catch (e) { }

      // board skeleton: three columns that keep their DOM between polls
      o.board.innerHTML = COLS.map(function (c) {
        return '<section class="kds-col" data-col="' + c.key + '" aria-label="' + c.title + ' tickets"><div class="kds-col-h"><i></i>' + c.title + '<em data-kds-count="' + c.key + '">0</em></div>' +
          '<div class="kds-col-b" data-kds-list="' + c.key + '">' + S.skeleton('card', c.key === 'pending' ? 2 : 1) + '</div></section>';
      }).join('');
      o.board.setAttribute('data-view', view);
      if (o.stages) {
        o.stages.innerHTML = [{ key: 'all', title: 'All' }].concat(COLS).map(function (c) {
          return '<button type="button" class="kds-stage' + (c.key === view ? ' on' : '') + '" data-stage="' + c.key + '" aria-pressed="' + (c.key === view) + '"><i></i>' + c.title + '<em data-kds-count="' + c.key + '">0</em></button>';
        }).join('');
        o.stages.addEventListener('click', function (e) { var b = e.target.closest('[data-stage]'); if (b) api.filter(b.getAttribute('data-stage')); });
      }

      api.filter = function (v) {
        view = v; o.board.setAttribute('data-view', v);
        try { localStorage.setItem(VIEW_KEY, v); } catch (e) { }
        if (o.stages) o.stages.querySelectorAll('[data-stage]').forEach(function (b) { var on = b.getAttribute('data-stage') === v; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on); });
      };

      function paint(list, fresh) {
        var groups = { pending: [], preparing: [], ready: [] };
        list.forEach(function (k) { var s = stageOf(k); (groups[s] || groups.pending).push(k); });
        COLS.forEach(function (c) {
          var g = groups[c.key];
          g.sort(function (a, b) { return S.parseTime(a.created_at) - S.parseTime(b.created_at); }); // oldest first
          var sig = JSON.stringify(g.map(function (k) { return [k.id, (k.items || []).length]; })) + (fresh.length ? fresh.join() : '');
          var el = o.board.querySelector('[data-kds-list="' + c.key + '"]');
          if (sig !== colSig[c.key]) {
            colSig[c.key] = sig;
            el.innerHTML = g.length ? g.map(function (k) { return ticket(k, fresh.indexOf(String(k.id)) > -1); }).join('')
              : '<div class="st-empty' + (c.key === 'ready' ? ' ok' : c.key === 'preparing' ? ' orange' : '') + '"><span class="material-symbols-rounded">' + c.emptyIcon + '</span><b>' + c.empty + '</b></div>';
          }
        });
        var counts = { pending: groups.pending.length, preparing: groups.preparing.length, ready: groups.ready.length };
        counts.all = list.length;
        Object.keys(counts).forEach(function (k) { document.querySelectorAll('[data-kds-count="' + k + '"]').forEach(function (e) { e.textContent = counts[k]; }); });
        if (o.stats) {
          var oldest = 0; groups.pending.concat(groups.preparing).forEach(function (k) { oldest = Math.max(oldest, S.since(k.created_at)); });
          var late = groups.pending.concat(groups.preparing).filter(function (k) { return S.since(k.created_at) >= LATE_MIN * 60; }).length;
          o.stats.innerHTML =
            stat('fiber_new', '#f59e0b', counts.pending, 'New') +
            stat('local_fire_department', '#f97316', counts.preparing, 'Cooking') +
            stat('room_service', '#16a34a', counts.ready, 'Ready to serve') +
            stat('timer', late ? '#dc2626' : '#2563eb', oldest ? S.fmtAge(oldest) : '–', late ? late + ' over ' + LATE_MIN + ' min' : 'Longest wait');
        }
        if (o.onCounts) o.onCounts(counts);
      }
      function stat(icon, color, val, label) {
        return '<div class="st-stat"><span class="st-ic" style="--c:' + color + '"><span class="material-symbols-rounded">' + icon + '</span></span><b>' + val + '</b><small>' + label + '</small></div>';
      }

      api.load = function () {
        return S.getJSON('../api/get_kot.php?restaurant_id=' + S.rid).then(function (res) {
          if (!res || !res.success) throw new Error((res && res.message) || 'Could not load tickets');
          var list = (res.kots || []).filter(function (k) { return !busy[k.id]; });
          var ids = list.filter(function (k) { return stageOf(k) === 'pending'; }).map(function (k) { return String(k.id); });
          var fresh = known ? ids.filter(function (id) { return known.indexOf(id) === -1; }) : [];
          known = (known || []).concat(ids).filter(function (v, i, a) { return a.indexOf(v) === i; }).slice(-300);
          all = res.kots || [];
          window.allKOTs = all;
          paint(list, fresh);
          if (o.updated) o.updated.textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
          if (fresh.length) {
            S.sound.play();
            var f = list.filter(function (k) { return String(k.id) === fresh[0]; })[0];
            S.toast(fresh.length > 1 ? fresh.length + ' new kitchen tickets' : 'KOT #' + shortNo(f) + (f.table_number ? ' – Table ' + f.table_number : ''), 'warning', { title: 'New ticket', duration: 6000 });
          }
          return list.length;
        }).catch(function (e) {
          console.error('Error loading KOT orders:', e);
          if (all === null) {
            o.board.querySelectorAll('[data-kds-list]').forEach(function (el, i) {
              el.innerHTML = i ? '' : '<div class="st-empty bad"><span class="material-symbols-rounded">wifi_off</span><b>Couldn’t load tickets</b><span>Retrying automatically…</span></div>';
            });
          }
          throw e;
        });
      };

      api.act = function (id, next, btn) {
        if (busy[id]) return;
        var t = btn && btn.closest('.kds-t');
        busy[id] = true;
        if (t) t.classList.add('busy');
        if (btn) btn.innerHTML = '<span class="st-spin"></span>Saving…';
        var req = next === 'Complete'
          ? S.postForm('../controllers/kot_operations.php', { action: 'complete_kot', kotId: id })
          : S.postForm('../controllers/kot_operations.php', { action: 'update_kot_status', kotId: id, status: next });
        req.then(function (res) {
          if (!res.success) throw new Error(res.message || 'Failed to update status');
          var no = t ? t.querySelector('.kds-id small').textContent.split('·')[0].trim() : 'Ticket';
          S.toast(next === 'Preparing' ? no + ' – cooking started' : next === 'Ready' ? no + ' is ready. The waiter has been told.' : no + ' done – order moved to Orders', 'success');
        }).catch(function (e) {
          S.toast('Error: ' + (e.message || 'Failed to update status'), 'error');
        }).then(function () {
          delete busy[id];
          colSig = {};
          api.load().catch(function () { });
        });
      };
      o.board.addEventListener('click', function (e) {
        var b = e.target.closest('[data-kds-act]');
        if (b && !b.disabled) api.act(+b.getAttribute('data-kds-act'), b.getAttribute('data-next'), b);
      });

      // smart refresh: 10 s while there are tickets, easing to 20 s when the kitchen is idle
      api.start = function () {
        var idle = 0, ms = 10000;
        var poll = S.every(function () {
          api.load().then(function (n) {
            if (!n) { idle++; if (idle > 2) ms = Math.min(20000, Math.round(ms * 1.5)); } else { idle = 0; ms = 10000; }
            poll.setMs(ms);
          }, function () { ms = Math.min(20000, Math.round(ms * 1.2)); poll.setMs(ms); });
        }, ms);
        api.load().catch(function () { });
        return api;
      };
      api.refresh = function () { colSig = {}; return api.load(); };
      return api;
    }
  };
})();
