/* Attendance and Payroll pages (dashboard #attendancePage / #payrollPage, api/attendance_admin.php,
   config/attendance.php). One script for both, told which one it is by RGAttendance.load('attendance'|'payroll').

   Attendance: the month as a grid, one row per person and one cell per day. Tap a cell to set the day;
   the cell shows its letter (P, H, A, L, O, F) so a month reads at a glance. "Today" has its own row of
   buttons so marking the day everyone is standing in front of you takes one tap each.
   Payroll: what each person earned that month from that attendance, with advances, bonuses and
   deductions, and Mark paid — which freezes the figures so a payslip never changes afterwards.
   window.RGAttendance.load(page) runs when either page opens (script.js showPage). */
(function () {
  var API = '../api/attendance_admin.php';
  var S = { page: 'attendance', period: null, data: null };
  var LETTER = { present: 'P', half: 'H', absent: 'A', paid_leave: 'L', week_off: 'O', holiday: 'F' };
  var TONE = { present: 'p', half: 'h', absent: 'a', paid_leave: 'l', week_off: 'o', holiday: 'f' };
  var PAY = ['Cash', 'UPI', 'Bank transfer', 'Card', 'Other'];

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function notify(m, t) { if (typeof window.showNotification === 'function') window.showNotification(m, t); }
  function cur() { return S.data ? S.data.currency : ''; }
  function money(n) { return cur() + (+n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function root() { return $(S.page === 'payroll' ? 'payrollRoot' : 'attendanceRoot'); }
  function hhmm(min) { var h = Math.floor(min / 60); return min ? (h + 'h' + (min % 60 ? ' ' + (min % 60) + 'm' : '')) : '—'; }
  function post(body) {
    return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); });
  }
  function getJson(q) { return fetch(API + q, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }); }
  function monthLabel(p) {
    var d = new Date(p + '-01T00:00:00');
    return d.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
  }
  function shiftMonth(p, by) {
    var d = new Date(p + '-01T00:00:00');
    d.setMonth(d.getMonth() + by);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
  }

  function css() {
    if ($('atStyles')) return;
    var s = document.createElement('style');
    s.id = 'atStyles';
    s.textContent =
      '.at-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px}.at-bar b{font-size:15px;margin:0 4px}' +
      '.at-b{border:0;border-radius:9px;padding:8px 13px;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;font-family:inherit}' +
      '.at-b.primary{background:#7c3aed;color:#fff}.at-b.plain{background:#f3f4f6;color:#111827}.at-b.off{background:#fff;color:#b91c1c;border:1.5px solid #fca5a5}' +
      '.at-b .material-symbols-rounded{font-size:18px}.at-b:disabled{opacity:.5;cursor:default}' +
      '.at-card{background:#fff;border:1px solid #eef0f4;border-radius:14px;padding:16px;margin-bottom:16px;min-width:0}' +
      '.at-card h3{margin:0 0 4px;font-size:16px}.at-card p.sub{margin:0 0 12px;color:#6b7280;font-size:13px}' +
      '.at-today{display:grid;gap:8px}.at-today .row{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;background:#fafafb;border-radius:10px;padding:8px 12px}' +
      '.at-today .row b{font-size:14px}.at-today .row small{color:#6b7280;display:block}' +
      '.at-pick{display:flex;gap:4px;flex-wrap:wrap}' +
      '.at-pick button{border:1.5px solid #e5e7eb;background:#fff;border-radius:8px;width:34px;height:34px;font-size:13px;font-weight:700;cursor:pointer;color:#374151;font-family:inherit}' +
      '.at-pick button.on{color:#fff}' +
      '.at-pick button.p.on,.at-cell.p{background:#dcfce7;color:#166534;border-color:#86efac}' +
      '.at-pick button.h.on,.at-cell.h{background:#fef3c7;color:#92400e;border-color:#fcd34d}' +
      '.at-pick button.a.on,.at-cell.a{background:#fee2e2;color:#991b1b;border-color:#fca5a5}' +
      '.at-pick button.l.on,.at-cell.l{background:#dbeafe;color:#1e40af;border-color:#93c5fd}' +
      '.at-pick button.o.on,.at-cell.o{background:#f3f4f6;color:#4b5563;border-color:#d1d5db}' +
      '.at-pick button.f.on,.at-cell.f{background:#ede9fe;color:#5b21b6;border-color:#c4b5fd}' +
      '.at-gw{overflow-x:auto;background:#fff;border:1px solid #eef0f4;border-radius:14px}' +
      '.at-g{border-collapse:separate;border-spacing:0;font-size:12px;table-layout:fixed}' +
      '.at-g th,.at-g td{border-bottom:1px solid #f1f2f5;border-right:1px solid #f6f7f9;padding:0;height:34px}' +
      '.at-g thead th{position:sticky;top:0;background:#fafafb;font-weight:600;color:#4b5563;padding:6px 2px;text-align:center;line-height:1.1;width:30px}' +
      '.at-g thead th.we{background:#f5f3ff}.at-g thead th.nm{width:150px;text-align:left;padding-left:10px}' +
      '.at-g .nm{position:sticky;left:0;background:#fff;z-index:1;padding:0 10px;font-weight:700;text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;width:150px}' +
      '.at-g .ct{position:sticky;right:0;background:#fff;z-index:1;padding:0 10px;white-space:nowrap;font-weight:600;color:#4b5563;border-left:1px solid #eef0f4}' +
      '.at-cell{display:block;width:100%;height:100%;border:0;background:transparent;cursor:pointer;font-weight:700;font-size:12px;font-family:inherit;color:#d1d5db}' +
      '.at-cell:hover{outline:2px solid #ddd6fe;outline-offset:-2px}.at-cell.na{cursor:default}.at-cell.na:hover{outline:0}' +
      '.at-key{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;font-size:12px;color:#6b7280}' +
      '.at-key span b{display:inline-block;width:18px;height:18px;line-height:18px;text-align:center;border-radius:5px;margin-right:4px;font-size:11px}' +
      '.at-tw{overflow-x:auto}.at-t{width:100%;border-collapse:collapse;font-size:13px;min-width:720px}' +
      '.at-t th{text-align:left;color:#6b7280;font-weight:600;padding:8px 10px;border-bottom:1px solid #eef0f4;white-space:nowrap}' +
      '.at-t td{padding:9px 10px;border-bottom:1px solid #f6f7f9;white-space:nowrap}' +
      '.at-t td.r,.at-t th.r{text-align:right}.at-t tr.paid{background:#f9fdfb}' +
      '.at-t td.net{font-weight:700}.at-t td.neg{color:#b91c1c}' +
      '.at-empty{background:#fff;border:1px dashed #d1d5db;border-radius:14px;padding:30px;text-align:center;color:#6b7280}' +
      '.at-ov{position:fixed;inset:0;background:rgba(17,24,39,.5);z-index:10000;display:flex;align-items:center;justify-content:center;padding:16px}' +
      '.at-md{background:#fff;border-radius:16px;width:100%;max-width:560px;max-height:92vh;overflow:auto;padding:20px;position:relative}' +
      '.at-md h3{margin:0 0 4px;font-size:18px;padding-right:36px}.at-md p.sub{margin:0 0 14px;color:#6b7280;font-size:13px}' +
      '.at-md .x{position:absolute;right:14px;top:14px;border:0;background:#f3f4f6;border-radius:50%;width:32px;height:32px;cursor:pointer}' +
      '.at-md label{display:block;font-size:13px;font-weight:600;color:#374151;margin:0 0 4px}' +
      '.at-md input,.at-md select,.at-md textarea{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #d1d5db;border-radius:9px;font-size:15px;font-family:inherit}' +
      '.at-md .f{margin-bottom:12px}.at-md .g{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}' +
      '.at-md .ft{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap}' +
      '.at-hint{font-size:12px;color:#6b7280;margin-top:4px}' +
      '.at-ent{display:grid;gap:6px;margin-top:10px}.at-ent div{display:flex;justify-content:space-between;gap:10px;font-size:13px;background:#fafafb;border-radius:9px;padding:7px 10px;flex-wrap:wrap;align-items:center}';
    document.head.appendChild(s);
  }

  function bar() {
    return '<div class="at-bar">' +
      '<button class="at-b plain" data-at="prev"><span class="material-symbols-rounded">chevron_left</span></button>' +
      '<b>' + monthLabel(S.period) + '</b>' +
      '<button class="at-b plain" data-at="next"><span class="material-symbols-rounded">chevron_right</span></button>' +
      '<button class="at-b plain" data-at="this">This month</button>' +
      (S.page === 'attendance' ? '<button class="at-b plain" data-at="salaries"><span class="material-symbols-rounded">payments</span>Salaries</button>' : '') +
      '</div>';
  }

  /* ---------- attendance ---------- */

  function todayCard(m) {
    if (!S.data.can_edit) return '';
    var today = S.data.today;
    if (today.slice(0, 7) !== S.period) return '';
    var dayNum = parseInt(today.slice(8), 10);
    return '<div class="at-card"><h3>Today</h3><p class="sub">' +
      new Date(today + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' }) +
      ' — one tap each.</p><div class="at-today">' +
      m.staff.filter(function (r) { return r.staff.active; }).map(function (r) {
        var d = r.days[dayNum];
        return '<div class="row"><div><b>' + esc(r.staff.name) + '</b><small>' + esc(r.staff.role || '') +
          (d && d.in ? ' · in ' + d.in + (d.out ? ', out ' + d.out : ', still in') : '') + '</small></div>' +
          '<div class="at-pick">' + Object.keys(LETTER).map(function (k) {
            return '<button class="' + TONE[k] + (d && d.status === k ? ' on' : '') +
              '" title="' + statusLabel(k) + '" data-at-set="' + r.staff.id + '|' + today + '|' + k + '">' + LETTER[k] + '</button>';
          }).join('') + '</div></div>';
      }).join('') + '</div></div>';
  }

  function statusLabel(k) {
    var found = (S.data.statuses || []).filter(function (s) { return s.key === k; })[0];
    return found ? found.label : k;
  }
  // The stored word for a salary type ("monthly") or a payroll entry ("advance") is a key, not
  // something to put in front of someone — config/attendance.php holds the reading for each.
  function salaryLabel(k) { return ((S.data || {}).salary_type_labels || {})[k] || k; }
  function entryLabel(k) { return ((S.data || {}).entry_type_labels || {})[k] || k; }

  function grid(m) {
    var days = m.days_in_month;
    if (!m.staff.length) return '<div class="at-empty">No staff yet. Add the team in Staff first.</div>';
    var head = '<tr><th class="nm">Name</th>';
    for (var i = 1; i <= days; i++) {
      var dow = new Date(S.period + '-' + String(i).padStart(2, '0') + 'T00:00:00').getDay();
      head += '<th class="' + (dow === 0 || dow === 6 ? 'we' : '') + '">' + i + '</th>';
    }
    head += '<th class="ct" style="text-align:right">P / A / H</th></tr>';

    var body = m.staff.map(function (r) {
      var cells = '';
      for (var i = 1; i <= days; i++) {
        var day = S.period + '-' + String(i).padStart(2, '0');
        var d = r.days[i];
        var future = day > S.data.today;
        var klass = 'at-cell ' + (d ? TONE[d.status] : '') + (future || !S.data.can_edit ? ' na' : '');
        var title = esc(r.staff.name) + ' · ' + day + (d ? ' · ' + statusLabel(d.status) + (d.note ? ' · ' + d.note : '') : '');
        cells += '<td><button class="' + klass + '" title="' + title + '"' +
          (future || !S.data.can_edit ? ' disabled' : ' data-at-day="' + r.staff.id + '|' + day + '"') + '>' +
          (d ? LETTER[d.status] : (future ? '' : '·')) + '</button></td>';
      }
      var c = r.counts;
      return '<tr><td class="nm" title="' + esc(r.staff.name) + '">' + esc(r.staff.name) +
        (r.staff.active ? '' : ' <small style="color:#9ca3af">(off)</small>') + '</td>' + cells +
        '<td class="ct">' + c.present + ' / ' + c.absent + ' / ' + c.half + (c.minutes ? ' · ' + hhmm(c.minutes) : '') + '</td></tr>';
    }).join('');

    return '<div class="at-gw"><table class="at-g" style="min-width:' + (160 + days * 30 + 120) + 'px">' +
      '<thead>' + head + '</thead><tbody>' + body + '</tbody></table></div>' +
      '<div class="at-key">' + Object.keys(LETTER).map(function (k) {
        return '<span><b class="at-cell ' + TONE[k] + '" style="border:0">' + LETTER[k] + '</b>' + statusLabel(k) + '</span>';
      }).join('') + '</div>';
  }

  /* ---------- payroll ---------- */

  function payrollTable(p) {
    if (!p.rows.length) return '<div class="at-empty">No staff yet. Add the team in Staff first.</div>';
    var rows = p.rows.map(function (r) {
      var s = r.staff;
      var noSalary = s.salary <= 0;
      return '<tr class="' + (r.paid ? 'paid' : '') + '"><td><b>' + esc(s.name) + '</b><br><small style="color:#9ca3af">' +
          esc(s.role || '') + ' · ' + esc(salaryLabel(s.salary_type)) + (noSalary ? ' · no salary set' : ' ' + money(s.salary)) + '</small></td>' +
        '<td><small>' + esc(r.basis || '—') + '</small></td>' +
        '<td class="r">' + money(r.gross) + '</td>' +
        '<td class="r">' + (r.bonus ? '+ ' + money(r.bonus) : '—') + '</td>' +
        '<td class="r">' + (r.deductions ? '− ' + money(r.deductions) : '—') + '</td>' +
        '<td class="r">' + (r.advances ? '− ' + money(r.advances) : '—') + '</td>' +
        '<td class="r net' + (r.net < 0 ? ' neg' : '') + '">' + money(r.net) + '</td>' +
        '<td class="r">' + (r.paid
          ? '<small style="color:#047857">paid ' + esc(r.pay_method || '') + '</small>' +
            (S.data.can_edit ? ' <button class="at-b plain" style="padding:4px 9px;font-size:12px" data-at-reopen="' + s.id + '">Reopen</button>' : '')
          : (S.data.can_edit
              ? '<button class="at-b plain" style="padding:5px 10px;font-size:12px" data-at-entry="' + s.id + '">Advance</button> ' +
                '<button class="at-b primary" style="padding:5px 10px;font-size:12px" data-at-pay="' + s.id + '">Mark paid</button>'
              : '')) + '</td></tr>';
    }).join('');

    var t = p.totals;
    return '<div class="at-card"><h3>' + monthLabel(S.period) + '</h3>' +
      '<p class="sub">Worked out from attendance. A day nobody marked is not treated as absent — only a day actually marked absent costs money.</p>' +
      '<div class="at-tw"><table class="at-t"><thead><tr><th>Who</th><th>Worked</th><th class="r">Gross</th><th class="r">Bonus</th>' +
      '<th class="r">Deducted</th><th class="r">Advance</th><th class="r">Net</th><th class="r"></th></tr></thead>' +
      '<tbody>' + rows + '</tbody><tfoot><tr><th colspan="2">Total</th><th class="r">' + money(t.gross) + '</th>' +
      '<th class="r">' + money(t.bonus) + '</th><th class="r">' + money(t.deductions) + '</th><th class="r">' + money(t.advances) +
      '</th><th class="r">' + money(t.net) + '</th><th class="r"><small style="color:#047857">' + money(t.paid) + ' paid</small></th></tr></tfoot>' +
      '</table></div></div>' +
      (p.entries.length ? '<div class="at-card"><h3>Advances, bonuses and deductions</h3><div class="at-ent">' +
        p.entries.map(function (e) {
          return '<div><span><b>' + esc(e.staff) + '</b> · ' + esc(entryLabel(e.type)) + (e.note ? ' · ' + esc(e.note) : '') +
            ' <small style="color:#9ca3af">' + esc(e.by) + '</small></span><span>' + money(e.amount) +
            (S.data.can_edit ? ' <button class="at-b off" style="padding:3px 8px;font-size:12px" data-at-del="' + e.id + '">Remove</button>' : '') +
            '</span></div>';
        }).join('') + '</div></div>' : '');
  }

  /* ---------- load and render ---------- */

  function render() {
    var el = root();
    if (!el || !S.data) return;
    if (S.page === 'payroll') el.innerHTML = bar() + payrollTable(S.data.payroll);
    else el.innerHTML = bar() + todayCard(S.data.month) + '<div class="at-card"><h3>' + monthLabel(S.period) +
      '</h3><p class="sub">Tap any day to set it. A blank day is simply not marked.</p>' + grid(S.data.month) + '</div>';
  }

  function load(page) {
    css();
    if (page) S.page = page;
    var el = root();
    if (el && !S.data) el.innerHTML = '<div class="loading">Loading…</div>';
    var view = S.page === 'payroll' ? 'payroll' : 'month';
    return getJson('?view=' + view + (S.period ? '&period=' + S.period : '')).then(function (d) {
      if (!d.success) {
        if (el) el.innerHTML = '<div class="at-empty">' + esc(d.message || 'Could not load.') + '</div>';
        return;
      }
      S.data = d;
      S.period = d.period;
      render();
    }).catch(function () {
      if (el) el.innerHTML = '<div class="at-empty">Could not load. <button class="at-b plain" data-at="reload">Try again</button></div>';
    });
  }

  function modal(html) {
    var ov = document.createElement('div');
    ov.className = 'at-ov';
    ov.innerHTML = '<div class="at-md"><button class="x" aria-label="Close" data-at="shut">&times;</button>' + html + '</div>';
    ov.addEventListener('click', function (e) { if (e.target === ov || e.target.closest('[data-at="shut"]')) ov.remove(); });
    document.body.appendChild(ov);
    var first = ov.querySelector('input,select,textarea');
    if (first) first.focus();
    return ov;
  }

  function setDay(staffId, date, status) {
    return post({ action: 'mark', staff_id: staffId, date: date, status: status }).then(function (d) {
      if (!d.success) return notify(d.message || 'Could not save', 'error');
      load();
    });
  }

  /** The full day window: status, punch times, overtime and a note. */
  function dayWindow(staffId, date) {
    var who = null;
    S.data.month.staff.forEach(function (r) { if (r.staff.id === staffId) who = r; });
    var d = (who && who.days[parseInt(date.slice(8), 10)]) || {};
    var ov = modal('<h3>' + esc(who ? who.staff.name : '') + '</h3>' +
      '<p class="sub">' + new Date(date + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) + '</p>' +
      '<div class="f"><label for="atSt">Day</label><select id="atSt">' +
        (S.data.statuses || []).map(function (s) { return '<option value="' + s.key + '"' + (d.status === s.key ? ' selected' : '') + '>' + esc(s.label) + '</option>'; }).join('') +
      '</select></div>' +
      '<div class="g"><div><label for="atIn">Clocked in</label><input id="atIn" type="time" value="' + (d.in || '') + '"></div>' +
      '<div><label for="atOut">Clocked out</label><input id="atOut" type="time" value="' + (d.out || '') + '"></div></div>' +
      '<div class="f"><label for="atOt">Overtime (minutes)</label><input id="atOt" type="number" min="0" max="1440" value="' + (d.overtime_minutes || 0) + '"></div>' +
      '<div class="f"><label for="atNote">Note</label><input id="atNote" maxlength="255" value="' + esc(d.note || '') + '"></div>' +
      '<div class="ft"><button class="at-b plain" data-at="shut">Cancel</button><button class="at-b primary" data-at="go">Save</button></div>');
    ov.querySelector('[data-at="go"]').addEventListener('click', function (e) {
      var btn = e.target.closest('button');
      btn.disabled = true;
      post({ action: 'mark', staff_id: staffId, date: date, status: $('atSt').value,
             punch_in: $('atIn').value, punch_out: $('atOut').value,
             overtime_minutes: parseInt($('atOt').value, 10) || 0, note: $('atNote').value })
        .then(function (r) {
          if (!r.success) { btn.disabled = false; return notify(r.message || 'Could not save', 'error'); }
          ov.remove();
          load();
        }).catch(function () { btn.disabled = false; notify('Could not save', 'error'); });
    });
  }

  /** Salaries for the whole team, in one window. */
  function salaries() {
    getJson('?view=staff').then(function (d) {
      if (!d.success) return notify(d.message || 'Could not load', 'error');
      var ov = modal('<h3>Salaries</h3><p class="sub">How each person is paid. Payroll uses this with the month’s attendance.</p>' +
        d.staff.map(function (s) {
          return '<div class="f" style="border-bottom:1px solid #f3f4f6;padding-bottom:12px">' +
            '<label>' + esc(s.name) + ' <small style="font-weight:500;color:#9ca3af">' + esc(s.role || '') + (s.active ? '' : ' · off') + '</small></label>' +
            '<div class="g" style="margin-bottom:0">' +
              '<select data-at-sal-type="' + s.id + '">' + (d.salary_types || []).map(function (t) {
                return '<option value="' + esc(t) + '"' + (s.salary_type === t ? ' selected' : '') + '>' + esc(salaryLabel(t)) + '</option>';
              }).join('') + '</select>' +
              '<input type="number" min="0" step="0.01" data-at-sal-amt="' + s.id + '" value="' + s.salary + '">' +
            '</div></div>';
        }).join('') +
        '<div class="at-hint">Monthly salary = a fixed salary, an absent day costs one day of it · Per day = a day’s wage per day worked · Per hour = a rate per hour from the clock in / out</div>' +
        '<div class="ft"><button class="at-b plain" data-at="shut">Close</button><button class="at-b primary" data-at="go">Save all</button></div>');
      ov.querySelector('[data-at="go"]').addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        btn.disabled = true;
        var jobs = d.staff.map(function (s) {
          return post({ action: 'salary', staff_id: s.id,
            salary_type: ov.querySelector('[data-at-sal-type="' + s.id + '"]').value,
            salary: parseFloat(ov.querySelector('[data-at-sal-amt="' + s.id + '"]').value) || 0 });
        });
        Promise.all(jobs).then(function (all) {
          var bad = all.filter(function (r) { return !r.success; })[0];
          if (bad) { btn.disabled = false; return notify(bad.message || 'Could not save every salary', 'error'); }
          ov.remove();
          notify('Salaries saved.', 'success');
          load();
        }).catch(function () { btn.disabled = false; notify('Could not save', 'error'); });
      });
    });
  }

  function entryWindow(staffId) {
    var name = '';
    S.data.payroll.rows.forEach(function (r) { if (r.staff.id === staffId) name = r.staff.name; });
    var ov = modal('<h3>' + esc(name) + '</h3><p class="sub">Added to ' + monthLabel(S.period) + '. An advance and a deduction come off the net; a bonus adds to it.</p>' +
      '<div class="g"><div><label for="atEType">What</label><select id="atEType">' +
        '<option value="advance">Advance taken</option><option value="deduction">Deduction</option><option value="bonus">Bonus</option></select></div>' +
      '<div><label for="atEAmt">How much</label><input id="atEAmt" type="number" min="0.01" step="0.01" inputmode="decimal"></div></div>' +
      '<div class="f"><label for="atENote">Note</label><input id="atENote" maxlength="255" placeholder="Took it on the 5th"></div>' +
      '<div class="ft"><button class="at-b plain" data-at="shut">Cancel</button><button class="at-b primary" data-at="go">Save</button></div>');
    ov.querySelector('[data-at="go"]').addEventListener('click', function (e) {
      var btn = e.target.closest('button');
      btn.disabled = true;
      post({ action: 'entry', staff_id: staffId, period: S.period, type: $('atEType').value,
             amount: parseFloat($('atEAmt').value) || 0, note: $('atENote').value })
        .then(function (r) {
          if (!r.success) { btn.disabled = false; return notify(r.message || 'Could not save', 'error'); }
          ov.remove();
          load();
        }).catch(function () { btn.disabled = false; notify('Could not save', 'error'); });
    });
  }

  function payWindow(staffId) {
    var row = null;
    S.data.payroll.rows.forEach(function (r) { if (r.staff.id === staffId) row = r; });
    if (!row) return;
    var ov = modal('<h3>Mark ' + esc(row.staff.name) + ' paid</h3>' +
      '<p class="sub">' + monthLabel(S.period) + ' · net ' + money(row.net) +
      '. The figures are frozen once this is saved, so correcting attendance later will not change the payslip. Reopen the month if you need to change it.</p>' +
      (row.net < 0 ? '<p class="sub" style="color:#b91c1c">The net is negative: the advances taken are more than the month earned.</p>' : '') +
      '<div class="f"><label for="atPayHow">Paid by</label><select id="atPayHow">' +
        PAY.map(function (p) { return '<option>' + p + '</option>'; }).join('') + '</select>' +
        '<div class="at-hint">Paid out of the drawer? Also record it as cash out in the Cash book, so the drawer still tallies.</div></div>' +
      '<div class="ft"><button class="at-b plain" data-at="shut">Cancel</button><button class="at-b primary" data-at="go">Mark paid</button></div>');
    ov.querySelector('[data-at="go"]').addEventListener('click', function (e) {
      var btn = e.target.closest('button');
      btn.disabled = true;
      post({ action: 'pay', staff_id: staffId, period: S.period, method: $('atPayHow').value })
        .then(function (r) {
          if (!r.success) { btn.disabled = false; return notify(r.message || 'Could not save', 'error'); }
          ov.remove();
          notify('Marked paid.', 'success');
          load();
        }).catch(function () { btn.disabled = false; notify('Could not save', 'error'); });
    });
  }

  document.addEventListener('click', function (e) {
    if (!e.target || !e.target.closest) return;
    var set = e.target.closest('[data-at-set]');
    if (set) {
      var p = set.getAttribute('data-at-set').split('|');
      setDay(parseInt(p[0], 10), p[1], p[2]);
      return;
    }
    var day = e.target.closest('[data-at-day]');
    if (day) {
      var q = day.getAttribute('data-at-day').split('|');
      dayWindow(parseInt(q[0], 10), q[1]);
      return;
    }
    var ent = e.target.closest('[data-at-entry]');
    if (ent) { entryWindow(parseInt(ent.getAttribute('data-at-entry'), 10)); return; }
    var pay = e.target.closest('[data-at-pay]');
    if (pay) { payWindow(parseInt(pay.getAttribute('data-at-pay'), 10)); return; }
    var del = e.target.closest('[data-at-del]');
    if (del) {
      if (!window.confirm('Remove this entry?')) return;
      post({ action: 'entry_delete', id: parseInt(del.getAttribute('data-at-del'), 10) })
        .then(function (d) { if (d.success) load(); else notify(d.message || 'Could not remove', 'error'); });
      return;
    }
    var re = e.target.closest('[data-at-reopen]');
    if (re) {
      if (!window.confirm('Reopen this month? The frozen payslip figures are thrown away and worked out again.')) return;
      post({ action: 'reopen', staff_id: parseInt(re.getAttribute('data-at-reopen'), 10), period: S.period })
        .then(function (d) { if (d.success) load(); else notify(d.message || 'Could not reopen', 'error'); });
      return;
    }
    var hit = e.target.closest('[data-at]');
    if (!hit) return;
    var what = hit.getAttribute('data-at');
    if (what === 'prev') { S.period = shiftMonth(S.period, -1); load(); }
    else if (what === 'next') { S.period = shiftMonth(S.period, 1); load(); }
    else if (what === 'this') { S.period = null; load(); }
    else if (what === 'salaries') salaries();
    else if (what === 'reload') load();
  });

  window.RGAttendance = { load: load };
})();
