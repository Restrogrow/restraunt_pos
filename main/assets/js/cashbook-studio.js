/* Cash book page (dashboard #cashbookPage, api/cashbook.php, config/cash_session.php).
   No shift open: the "Open the drawer" card, with the cash already in it. Shift open: what the drawer
   should hold right now, what was taken by each payment method, the cash that moved in and out, and
   Close shift — which asks for the counted cash and shows the difference. Below that, the shifts
   already closed; opening one shows its frozen report (the "Z report"), which prints.
   Only cash counts towards the drawer: card, UPI and wallet are shown but greyed, because they never
   go into it. window.RGCashbook.load() runs when the page opens (script.js showPage). */
(function () {
  var API = '../api/cashbook.php';
  var S = { data: null, report: null };

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function notify(m, t) { if (typeof window.showNotification === 'function') window.showNotification(m, t); }
  function cur() { return S.data ? S.data.currency : ''; }
  function money(n) { return cur() + (+n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function when(s) { return s ? new Date(String(s).replace(' ', 'T')).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : '—'; }
  function post(body) {
    return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); });
  }
  function getJson(q) { return fetch(API + (q || ''), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }); }

  function css() {
    if ($('cbStyles')) return;
    var s = document.createElement('style');
    s.id = 'cbStyles';
    s.textContent =
      '.cb-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}' +
      '.cb-stat{background:#fff;border:1px solid #eef0f4;border-radius:14px;padding:12px 14px;min-width:0}.cb-stat small{display:block;color:#6b7280;font-size:12px}.cb-stat b{font-size:22px;word-break:break-word}.cb-stat em{display:block;font-style:normal;color:#6b7280;font-size:12px;margin-top:2px}' +
      '.cb-stat.big b{color:#047857}.cb-stat.short b{color:#b91c1c}.cb-stat.over b{color:#b45309}' +
      '.cb-card{background:#fff;border:1px solid #eef0f4;border-radius:14px;padding:16px;margin-bottom:16px;min-width:0}' +
      '.cb-card h3{margin:0 0 4px;font-size:16px}.cb-card p.sub{margin:0 0 14px;color:#6b7280;font-size:13px}' +
      '.cb-open{background:linear-gradient(180deg,#f5f3ff,#fff);border:1px solid #ddd6fe}' +
      '.cb-row{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:14px;flex-wrap:wrap}.cb-row:last-child{border-bottom:0}' +
      '.cb-row.tot{font-weight:700;border-top:2px solid #e5e7eb;border-bottom:0;margin-top:4px;padding-top:10px}' +
      '.cb-row .mut{color:#9ca3af}.cb-row span.rt{text-align:right;white-space:nowrap}' +
      '.cb-tag{display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:600;background:#f3f4f6;color:#4b5563;margin-left:6px}' +
      '.cb-tag.cash{background:#dcfce7;color:#166534}' +
      '.cb-acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}' +
      '.cb-b{border:0;border-radius:9px;padding:9px 14px;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;font-family:inherit}' +
      '.cb-b.primary{background:#7c3aed;color:#fff}.cb-b.plain{background:#f3f4f6;color:#111827}.cb-b.off{background:#fff;color:#b91c1c;border:1.5px solid #fca5a5}' +
      '.cb-b .material-symbols-rounded{font-size:18px}.cb-b:disabled{opacity:.5;cursor:default}' +
      '.cb-mv{display:grid;gap:6px;margin-top:10px}.cb-mv div{display:flex;justify-content:space-between;gap:10px;font-size:13px;background:#fafafb;border-radius:9px;padding:7px 10px;flex-wrap:wrap}' +
      '.cb-mv b.in{color:#047857}.cb-mv b.out{color:#b91c1c}' +
      '.cb-tw{overflow-x:auto}.cb-t{width:100%;border-collapse:collapse;font-size:13px;min-width:620px}' +
      '.cb-t th{text-align:left;color:#6b7280;font-weight:600;padding:8px 10px;border-bottom:1px solid #eef0f4;white-space:nowrap}' +
      '.cb-t td{padding:9px 10px;border-bottom:1px solid #f6f7f9;white-space:nowrap}.cb-t tr.clk{cursor:pointer}.cb-t tr.clk:hover{background:#fafafb}' +
      '.cb-t td.neg{color:#b91c1c;font-weight:600}.cb-t td.pos{color:#b45309;font-weight:600}.cb-t td.ok{color:#047857}' +
      '.cb-empty{background:#fff;border:1px dashed #d1d5db;border-radius:14px;padding:30px;text-align:center;color:#6b7280}' +
      '.cb-ov{position:fixed;inset:0;background:rgba(17,24,39,.5);z-index:10000;display:flex;align-items:center;justify-content:center;padding:16px}' +
      '.cb-md{background:#fff;border-radius:16px;width:100%;max-width:520px;max-height:92vh;overflow:auto;padding:20px;position:relative}' +
      '.cb-md h3{margin:0 0 14px;font-size:18px;padding-right:36px}.cb-md .x{position:absolute;right:14px;top:14px;border:0;background:#f3f4f6;border-radius:50%;width:32px;height:32px;cursor:pointer}' +
      '.cb-md label{display:block;font-size:13px;font-weight:600;color:#374151;margin:0 0 4px}' +
      '.cb-md input,.cb-md textarea,.cb-md select{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #d1d5db;border-radius:9px;font-size:15px;font-family:inherit}' +
      '.cb-md .f{margin-bottom:12px}.cb-md .ft{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap}' +
      '.cb-hint{font-size:12px;color:#6b7280;margin-top:4px}.cb-diff{font-size:15px;font-weight:700;margin-top:6px}' +
      '@media print{body *{visibility:hidden}#cbPrint,#cbPrint *{visibility:visible}#cbPrint{position:absolute;left:0;top:0;width:100%;padding:0}#cbPrint .x,#cbPrint .ft{display:none}}';
    document.head.appendChild(s);
  }

  /* ---------- the open shift ---------- */

  function methodRows(methods) {
    if (!methods || !methods.length) return '<div class="cb-row"><span class="mut">Nothing taken yet</span></div>';
    return methods.map(function (m) {
      return '<div class="cb-row"><span>' + esc(m.method) +
        '<span class="cb-tag' + (m.cash ? ' cash' : '') + '">' + (m.cash ? 'in the drawer' : 'not cash') + '</span>' +
        (m.refunded > 0 ? ' <span class="mut">· ' + money(m.refunded) + ' refunded</span>' : '') +
        '</span><span class="rt">' + money(m.amount) + '</span></div>';
    }).join('');
  }

  function openShiftHtml(r) {
    var c = r.cash;
    return '' +
      '<div class="cb-stats">' +
        '<div class="cb-stat big"><small>Cash the drawer should hold</small><b>' + money(c.expected) + '</b><em>count it before you close</em></div>' +
        '<div class="cb-stat"><small>Opening balance</small><b>' + money(c.opening) + '</b><em>' + esc(r.session.opened_by || '—') + ' · ' + when(r.session.opened_at) + '</em></div>' +
        '<div class="cb-stat"><small>Cash taken</small><b>' + money(c.taken) + '</b><em>' + (c.refunded > 0 ? money(c.refunded) + ' refunded' : 'bills paid in cash') + '</em></div>' +
        '<div class="cb-stat"><small>Bills this shift</small><b>' + r.bills.orders + '</b><em>' + money(r.bills.net) + ' billed</em></div>' +
      '</div>' +
      '<div class="cb-card">' +
        '<h3>Taken this shift</h3><p class="sub">Every bill settled while this shift was open. Only cash goes into the drawer.</p>' +
        methodRows(r.methods) +
        '<div class="cb-row tot"><span>Total taken</span><span class="rt">' + money(r.takings) + '</span></div>' +
      '</div>' +
      '<div class="cb-card">' +
        '<h3>Cash in and out</h3><p class="sub">Cash that was not a bill: petty cash, a bank drop, change brought in.</p>' +
        '<div class="cb-row"><span>Opening balance</span><span class="rt">' + money(c.opening) + '</span></div>' +
        '<div class="cb-row"><span>Cash from bills</span><span class="rt">+ ' + money(c.taken) + '</span></div>' +
        (c.refunded > 0 ? '<div class="cb-row"><span>Cash refunded</span><span class="rt">− ' + money(c.refunded) + '</span></div>' : '') +
        '<div class="cb-row"><span>Cash brought in</span><span class="rt">+ ' + money(c.in) + '</span></div>' +
        '<div class="cb-row"><span>Cash taken out</span><span class="rt">− ' + money(c.out) + '</span></div>' +
        '<div class="cb-row tot"><span>Should be in the drawer</span><span class="rt">' + money(c.expected) + '</span></div>' +
        (r.movements.length ? '<div class="cb-mv">' + r.movements.map(function (m) {
          return '<div><span>' + esc(m.reason) + ' <span class="mut">· ' + esc(m.by || '—') + ' · ' + when(m.at) + '</span></span>' +
            '<b class="' + m.direction + '">' + (m.direction === 'in' ? '+ ' : '− ') + money(m.amount) + '</b></div>';
        }).join('') + '</div>' : '') +
        (S.data.can_edit ? '<div class="cb-acts">' +
          '<button class="cb-b plain" data-cb="in"><span class="material-symbols-rounded">add</span>Cash in</button>' +
          '<button class="cb-b plain" data-cb="out"><span class="material-symbols-rounded">remove</span>Cash out</button>' +
          '<button class="cb-b primary" data-cb="close"><span class="material-symbols-rounded">lock</span>Count and close shift</button>' +
          '</div>' : '') +
      '</div>';
  }

  function noShiftHtml() {
    return '<div class="cb-card cb-open">' +
      '<h3>No shift is open</h3>' +
      '<p class="sub">Open the drawer with the cash already in it. Bills still work without a shift, but their cash is not tracked, so nothing can be reconciled at the end of the day.</p>' +
      (S.data.can_edit
        ? '<div class="cb-acts"><button class="cb-b primary" data-cb="open"><span class="material-symbols-rounded">lock_open</span>Open the drawer</button></div>'
        : '<p class="sub">Ask the owner or a manager to open it.</p>') +
      '</div>';
  }

  function shiftsTable(list) {
    var past = list.filter(function (s) { return s.status === 'closed'; });
    if (!past.length) return '<div class="cb-empty">No shift has been closed yet this month.</div>';
    return '<div class="cb-card"><h3>Shifts closed this month</h3><p class="sub">Open one to see its report.</p><div class="cb-tw"><table class="cb-t">' +
      '<thead><tr><th>Opened</th><th>Closed</th><th>By</th><th>Opening</th><th>Expected</th><th>Counted</th><th>Difference</th></tr></thead><tbody>' +
      past.map(function (s) {
        var d = +s.difference || 0;
        var cls = d < -0.005 ? 'neg' : (d > 0.005 ? 'pos' : 'ok');
        var txt = d < -0.005 ? money(Math.abs(d)) + ' short' : (d > 0.005 ? money(d) + ' over' : 'Tallied');
        return '<tr class="clk" data-cb-open-report="' + s.id + '"><td>' + when(s.opened_at) + '</td><td>' + when(s.closed_at) + '</td>' +
          '<td>' + esc(s.closed_by || s.opened_by || '—') + '</td><td>' + money(s.opening) + '</td><td>' + money(s.expected) +
          '</td><td>' + money(s.counted) + '</td><td class="' + cls + '">' + txt + '</td></tr>';
      }).join('') + '</tbody></table></div></div>';
  }

  function render() {
    var root = $('cashbookRoot');
    if (!root || !S.data) return;
    root.innerHTML = (S.data.open ? openShiftHtml(S.data.open) : noShiftHtml()) + shiftsTable(S.data.sessions || []);
  }

  function load() {
    css();
    var root = $('cashbookRoot');
    if (root && !S.data) root.innerHTML = '<div class="loading">Loading…</div>';
    return getJson().then(function (d) {
      if (!d.success) {
        if (root) root.innerHTML = '<div class="cb-empty">' + esc(d.message || 'Could not load the cash book.') + '</div>';
        return;
      }
      S.data = d;
      render();
    }).catch(function () {
      if (root) root.innerHTML = '<div class="cb-empty">Could not load the cash book. <button class="cb-b plain" data-cb="reload">Try again</button></div>';
    });
  }

  /* ---------- windows ---------- */

  function modal(html, wide) {
    var ov = document.createElement('div');
    ov.className = 'cb-ov';
    ov.innerHTML = '<div class="cb-md"' + (wide ? ' id="cbPrint" style="max-width:640px"' : '') + '>' +
      '<button class="x" aria-label="Close" data-cb="shut">&times;</button>' + html + '</div>';
    ov.addEventListener('click', function (e) { if (e.target === ov || e.target.closest('[data-cb="shut"]')) ov.remove(); });
    document.body.appendChild(ov);
    var first = ov.querySelector('input,select,textarea');
    if (first) first.focus();
    return ov;
  }

  function openDrawer() {
    var ov = modal('<h3>Open the drawer</h3>' +
      '<div class="f"><label for="cbOpenAmt">Cash already in the drawer</label>' +
      '<input type="number" id="cbOpenAmt" min="0" step="0.01" value="0" inputmode="decimal">' +
      '<div class="cb-hint">Count it now. Everything taken from here on is measured against this.</div></div>' +
      '<div class="f"><label for="cbOpenNote">Note (optional)</label><input type="text" id="cbOpenNote" maxlength="255" placeholder="Morning float"></div>' +
      '<div class="ft"><button class="cb-b plain" data-cb="shut">Cancel</button><button class="cb-b primary" data-cb="do-open">Open shift</button></div>');
    ov.querySelector('[data-cb="do-open"]').addEventListener('click', function (b) {
      var btn = b.target.closest('button');
      btn.disabled = true;
      post({ action: 'open', opening: parseFloat($('cbOpenAmt').value) || 0, note: $('cbOpenNote').value })
        .then(function (d) {
          if (!d.success) { btn.disabled = false; return notify(d.message || 'Could not open the shift', 'error'); }
          ov.remove();
          notify('Shift open. The drawer is being tracked.', 'success');
          load();
        }).catch(function () { btn.disabled = false; notify('Could not open the shift', 'error'); });
    });
  }

  function moveCash(direction) {
    var out = direction === 'out';
    var ov = modal('<h3>' + (out ? 'Cash taken out' : 'Cash brought in') + '</h3>' +
      '<div class="f"><label for="cbMvAmt">How much</label><input type="number" id="cbMvAmt" min="0.01" step="0.01" inputmode="decimal"></div>' +
      '<div class="f"><label for="cbMvWhy">What for</label><input type="text" id="cbMvWhy" maxlength="160" placeholder="' +
        (out ? 'Vegetables from the market' : 'Change brought in') + '"></div>' +
      '<div class="ft"><button class="cb-b plain" data-cb="shut">Cancel</button><button class="cb-b primary" data-cb="do-move">Save</button></div>');
    ov.querySelector('[data-cb="do-move"]').addEventListener('click', function (b) {
      var btn = b.target.closest('button');
      btn.disabled = true;
      post({ action: 'move', direction: direction, amount: parseFloat($('cbMvAmt').value) || 0, reason: $('cbMvWhy').value })
        .then(function (d) {
          if (!d.success) { btn.disabled = false; return notify(d.message || 'Could not save', 'error'); }
          ov.remove();
          load();
        }).catch(function () { btn.disabled = false; notify('Could not save', 'error'); });
    });
  }

  function closeShift() {
    var expected = S.data.open.cash.expected;
    var ov = modal('<h3>Count and close the shift</h3>' +
      '<div class="f"><label for="cbCount">Cash you counted in the drawer</label>' +
      '<input type="number" id="cbCount" min="0" step="0.01" inputmode="decimal" placeholder="0.00">' +
      '<div class="cb-hint">The system expects ' + money(expected) + '. Enter what is really there — a difference is worth knowing, not hiding.</div>' +
      '<div class="cb-diff" id="cbDiff"></div></div>' +
      '<div class="f"><label for="cbCloseNote">Note (optional)</label><input type="text" id="cbCloseNote" maxlength="255" placeholder="Why it is short or over"></div>' +
      '<div class="ft"><button class="cb-b plain" data-cb="shut">Cancel</button><button class="cb-b primary" data-cb="do-close">Close the shift</button></div>');
    var input = $('cbCount');
    function show() {
      var el = $('cbDiff');
      if (input.value === '') { el.textContent = ''; return; }
      var d = Math.round(((parseFloat(input.value) || 0) - expected) * 100) / 100;
      el.textContent = Math.abs(d) < 0.005 ? 'Tallies exactly.' : (d < 0 ? money(Math.abs(d)) + ' short' : money(d) + ' over');
      el.style.color = Math.abs(d) < 0.005 ? '#047857' : (d < 0 ? '#b91c1c' : '#b45309');
    }
    input.addEventListener('input', show);
    ov.querySelector('[data-cb="do-close"]').addEventListener('click', function (b) {
      if (input.value === '') return notify('Enter the cash you counted.', 'error');
      var btn = b.target.closest('button');
      btn.disabled = true;
      post({ action: 'close', counted: parseFloat(input.value) || 0, note: $('cbCloseNote').value })
        .then(function (d) {
          if (!d.success) { btn.disabled = false; return notify(d.message || 'Could not close the shift', 'error'); }
          ov.remove();
          notify('Shift closed.', 'success');
          load().then(function () { showReport(d.report); });
        }).catch(function () { btn.disabled = false; notify('Could not close the shift', 'error'); });
    });
  }

  /** The shift report, the same whether it just closed or is opened from the list months later. */
  function showReport(r) {
    var c = r.cash || {};
    var cl = r.closed || null;
    var d = cl ? (+cl.difference || 0) : 0;
    modal('<h3>Shift report</h3>' +
      '<div class="cb-row"><span>Opened</span><span class="rt">' + when(r.session.opened_at) + ' · ' + esc(r.session.opened_by || '—') + '</span></div>' +
      (cl ? '<div class="cb-row"><span>Closed</span><span class="rt">' + when(cl.at || r.session.closed_at) + ' · ' + esc(cl.by || r.session.closed_by || '—') + '</span></div>' : '') +
      '<div class="cb-row"><span>Bills</span><span class="rt">' + r.bills.orders + ' · ' + money(r.bills.net) + '</span></div>' +
      (r.bills.discount > 0 ? '<div class="cb-row"><span>Discount given</span><span class="rt">' + money(r.bills.discount) + '</span></div>' : '') +
      '<h3 style="font-size:15px;margin:16px 0 6px">How it was paid</h3>' + methodRows(r.methods) +
      '<div class="cb-row tot"><span>Total taken</span><span class="rt">' + money(r.takings) + '</span></div>' +
      '<h3 style="font-size:15px;margin:16px 0 6px">The drawer</h3>' +
      '<div class="cb-row"><span>Opening balance</span><span class="rt">' + money(c.opening) + '</span></div>' +
      '<div class="cb-row"><span>Cash from bills</span><span class="rt">+ ' + money(c.taken) + '</span></div>' +
      (c.refunded > 0 ? '<div class="cb-row"><span>Cash refunded</span><span class="rt">− ' + money(c.refunded) + '</span></div>' : '') +
      '<div class="cb-row"><span>Cash brought in</span><span class="rt">+ ' + money(c.in) + '</span></div>' +
      '<div class="cb-row"><span>Cash taken out</span><span class="rt">− ' + money(c.out) + '</span></div>' +
      '<div class="cb-row tot"><span>Expected</span><span class="rt">' + money(cl ? cl.expected : c.expected) + '</span></div>' +
      (cl ? '<div class="cb-row"><span>Counted</span><span class="rt">' + money(cl.counted) + '</span></div>' +
        '<div class="cb-row tot"><span>Difference</span><span class="rt" style="color:' +
          (Math.abs(d) < 0.005 ? '#047857' : (d < 0 ? '#b91c1c' : '#b45309')) + '">' +
          (Math.abs(d) < 0.005 ? 'Tallied' : (d < 0 ? money(Math.abs(d)) + ' short' : money(d) + ' over')) + '</span></div>' : '') +
      (r.close_note ? '<div class="cb-row"><span class="mut">' + esc(r.close_note) + '</span></div>' : '') +
      '<div class="ft"><button class="cb-b plain" data-cb="print">Print</button><button class="cb-b primary" data-cb="shut">Done</button></div>', true);
  }

  document.addEventListener('click', function (e) {
    if (!e.target || !e.target.closest) return;
    var row = e.target.closest('[data-cb-open-report]');
    if (row) {
      getJson('?session=' + row.getAttribute('data-cb-open-report')).then(function (d) {
        if (d.success) showReport(d.report); else notify(d.message || 'Could not load that shift', 'error');
      });
      return;
    }
    var hit = e.target.closest('[data-cb]');
    if (!hit) return;
    var what = hit.getAttribute('data-cb');
    if (what === 'open') openDrawer();
    else if (what === 'in') moveCash('in');
    else if (what === 'out') moveCash('out');
    else if (what === 'close') closeShift();
    else if (what === 'print') window.print();
    else if (what === 'reload') load();
  });

  window.RGCashbook = { load: load };
})();
