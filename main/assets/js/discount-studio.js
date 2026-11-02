/* Discounts given page (dashboard #discountsPage, api/discount_admin.php, config/staff_discount.php).
   What discounts cost over a period, broken down by who gave them and why, then every single one in a
   list. A discount marked "over cap" is one a manager had to put through — those are the ones worth
   reading. Limits sets the ceiling per role: up to that much, no questions; over it, only a manager can
   put the bill through. With no ceiling set nothing is refused, which is how it starts.
   window.RGDiscounts.load() runs when the page opens (script.js showPage). */
(function () {
  var API = '../api/discount_admin.php';
  var S = { data: null, from: null, to: null, tab: 'report' };

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function notify(m, t) { if (typeof window.showNotification === 'function') window.showNotification(m, t); }
  function cur() { return S.data ? S.data.currency : ''; }
  function money(n) { return cur() + (+n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function when(s) { return s ? new Date(String(s).replace(' ', 'T')).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : '—'; }
  function dayLabel(s) { return s ? new Date(s + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) : ''; }
  function post(body) {
    return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); });
  }
  function getJson(q) { return fetch(API + (q || ''), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }); }

  function css() {
    if ($('dsStyles')) return;
    var s = document.createElement('style');
    s.id = 'dsStyles';
    s.textContent =
      '.ds-bar{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px}' +
      '.ds-bar label{display:block;font-size:12px;font-weight:600;color:#6b7280;margin-bottom:3px}' +
      '.ds-bar input{padding:8px 10px;border:1px solid #d1d5db;border-radius:9px;font-size:14px;font-family:inherit}' +
      '.ds-b{border:0;border-radius:9px;padding:9px 14px;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;font-family:inherit}' +
      '.ds-b.primary{background:#7c3aed;color:#fff}.ds-b.plain{background:#f3f4f6;color:#111827}.ds-b:disabled{opacity:.5;cursor:default}' +
      '.ds-b .material-symbols-rounded{font-size:18px}' +
      '.ds-tabs{display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap}' +
      '.ds-tabs button{border:1px solid #e5e7eb;background:#fff;border-radius:999px;padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer;color:#374151}' +
      '.ds-tabs button.on{background:#111827;color:#fff;border-color:#111827}' +
      '.ds-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}' +
      '.ds-stat{background:#fff;border:1px solid #eef0f4;border-radius:14px;padding:12px 14px;min-width:0}.ds-stat small{display:block;color:#6b7280;font-size:12px}.ds-stat b{font-size:22px;word-break:break-word}.ds-stat em{display:block;font-style:normal;color:#6b7280;font-size:12px;margin-top:2px}' +
      '.ds-stat.given b{color:#b45309}.ds-stat.over b{color:#b91c1c}' +
      '.ds-card{background:#fff;border:1px solid #eef0f4;border-radius:14px;padding:16px;margin-bottom:16px;min-width:0}' +
      '.ds-card h3{margin:0 0 4px;font-size:16px}.ds-card p.sub{margin:0 0 12px;color:#6b7280;font-size:13px}' +
      '.ds-tw{overflow-x:auto}.ds-t{width:100%;border-collapse:collapse;font-size:13px;min-width:560px}' +
      '.ds-t th{text-align:left;color:#6b7280;font-weight:600;padding:8px 10px;border-bottom:1px solid #eef0f4;white-space:nowrap}' +
      '.ds-t td{padding:9px 10px;border-bottom:1px solid #f6f7f9;vertical-align:top}' +
      '.ds-t td.r,.ds-t th.r{text-align:right;white-space:nowrap}' +
      '.ds-pill{display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:600;background:#fee2e2;color:#991b1b;margin-left:6px}' +
      '.ds-bars{display:grid;gap:8px}.ds-bars .row{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;font-size:13px}' +
      '.ds-bars .track{grid-column:1/-1;height:6px;background:#f3f4f6;border-radius:999px;overflow:hidden}' +
      '.ds-bars .fill{height:100%;background:#fcd34d}' +
      '.ds-empty{background:#fff;border:1px dashed #d1d5db;border-radius:14px;padding:30px;text-align:center;color:#6b7280}' +
      '.ds-caps{display:grid;gap:10px}' +
      '.ds-cap{display:grid;grid-template-columns:1fr 110px 110px;gap:10px;align-items:center;background:#fafafb;border-radius:10px;padding:10px 12px}' +
      '.ds-cap b{font-size:14px}.ds-cap input{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit}' +
      '.ds-cap small{display:block;color:#9ca3af;font-size:11px}' +
      '@media (max-width:560px){.ds-cap{grid-template-columns:1fr 1fr}.ds-cap b{grid-column:1/-1}}' +
      '.ds-hint{font-size:12px;color:#6b7280;margin-top:8px}';
    document.head.appendChild(s);
  }

  function bar() {
    return '<div class="ds-bar">' +
      '<div><label for="dsFrom">From</label><input type="date" id="dsFrom" value="' + (S.from || '') + '"></div>' +
      '<div><label for="dsTo">To</label><input type="date" id="dsTo" value="' + (S.to || '') + '"></div>' +
      '<button class="ds-b primary" data-ds="apply">Show</button>' +
      '</div>' +
      '<div class="ds-tabs">' +
        '<button data-ds-tab="report"' + (S.tab === 'report' ? ' class="on"' : '') + '>Report</button>' +
        '<button data-ds-tab="list"' + (S.tab === 'list' ? ' class="on"' : '') + '>Every discount</button>' +
        (S.data.can_edit ? '<button data-ds-tab="caps"' + (S.tab === 'caps' ? ' class="on"' : '') + '>Limits</button>' : '') +
      '</div>';
  }

  function bars(items, label, key) {
    var max = items.reduce(function (m, i) { return Math.max(m, i.given); }, 0) || 1;
    if (!items.length) return '<p class="sub">Nothing in this period.</p>';
    return '<div class="ds-bars">' + items.map(function (i) {
      return '<div class="row"><span>' + esc(i[key] || '—') + (i.role ? ' <small style="color:#9ca3af">' + esc(i.role) + '</small>' : '') +
        (i.over_cap ? '<span class="ds-pill">' + i.over_cap + ' over cap</span>' : '') +
        '</span><span><b>' + money(i.given) + '</b> <small style="color:#9ca3af">' + i.bills + ' bill' + (i.bills === 1 ? '' : 's') + '</small></span>' +
        '<div class="track"><div class="fill" style="width:' + Math.round(i.given / max * 100) + '%"></div></div></div>';
    }).join('') + '</div>';
  }

  function report(r) {
    var t = r.totals;
    return '<div class="ds-stats">' +
      '<div class="ds-stat given"><small>Discount given</small><b>' + money(t.given) + '</b><em>on ' + t.bills + ' bill' + (t.bills === 1 ? '' : 's') + '</em></div>' +
      '<div class="ds-stat"><small>Share of those bills</small><b>' + t.share + '%</b><em>' + money(t.billed) + ' billed</em></div>' +
      (t.over_cap ? '<div class="ds-stat over"><small>Over the cap</small><b>' + t.over_cap + '</b><em>a manager put these through</em></div>' : '') +
      '</div>' +
      '<div class="ds-card"><h3>Who gave it</h3>' + bars(r.by_staff, 'Who', 'name') + '</div>' +
      '<div class="ds-card"><h3>Why</h3>' + bars(r.by_reason, 'Reason', 'reason') + '</div>' +
      (r.by_day.length > 1 ? '<div class="ds-card"><h3>Day by day</h3><div class="ds-tw"><table class="ds-t">' +
        '<thead><tr><th>Day</th><th class="r">Bills</th><th class="r">Given</th></tr></thead><tbody>' +
        r.by_day.map(function (d) {
          return '<tr><td>' + dayLabel(d.day) + '</td><td class="r">' + d.bills + '</td><td class="r">' + money(d.given) + '</td></tr>';
        }).join('') + '</tbody></table></div></div>' : '');
  }

  function list(rows) {
    if (!rows.length) return '<div class="ds-empty">No discount was given in this period.</div>';
    return '<div class="ds-card"><div class="ds-tw"><table class="ds-t">' +
      '<thead><tr><th>When</th><th>Bill</th><th>Who</th><th>Why</th><th class="r">Off</th><th class="r">Bill total</th></tr></thead><tbody>' +
      rows.map(function (d) {
        return '<tr><td>' + when(d.at) + '</td>' +
          '<td>' + esc(d.order || '—') + '<br><small style="color:#9ca3af">' + esc(d.order_type || '') + '</small></td>' +
          '<td>' + esc(d.by || '—') + '<br><small style="color:#9ca3af">' + esc(d.role || '') + '</small>' +
            (d.over_cap ? '<span class="ds-pill">over cap</span>' : '') + '</td>' +
          '<td>' + esc(d.reason || 'Not given') + (d.note ? '<br><small style="color:#9ca3af">' + esc(d.note) + '</small>' : '') + '</td>' +
          '<td class="r">' + money(d.amount) + (d.kind === 'percent' ? '<br><small style="color:#9ca3af">' + d.value + '%</small>' : '') + '</td>' +
          '<td class="r">' + money(d.bill_total) + '</td></tr>';
      }).join('') + '</tbody></table></div></div>';
  }

  function caps() {
    var set = S.data.settings.caps || {};
    return '<div class="ds-card"><h3>How much each role may take off a bill</h3>' +
      '<p class="sub">Leave both at 0 for no limit. Whichever is reached first applies. Over the limit, only the owner or a manager can put the bill through — the counter is told to fetch one.</p>' +
      '<div class="ds-caps">' + S.data.roles.map(function (role) {
        var c = null;
        Object.keys(set).forEach(function (k) { if (k.toLowerCase() === role.toLowerCase()) c = set[k]; });
        return '<div class="ds-cap"><b>' + esc(role) + '</b>' +
          '<div><input type="number" min="0" max="100" step="0.01" data-ds-pct="' + esc(role) + '" value="' + (c ? c.percent : 0) + '"><small>% of the bill</small></div>' +
          '<div><input type="number" min="0" step="0.01" data-ds-amt="' + esc(role) + '" value="' + (c ? c.amount : 0) + '"><small>' + cur() + ' at most</small></div>' +
          '</div>';
      }).join('') + '</div>' +
      '<div class="ds-hint"><label style="display:inline-flex;align-items:center;gap:8px;font-size:14px;font-weight:600;color:#374151">' +
        '<input type="checkbox" id="dsNeedReason" style="width:auto"' + (S.data.settings.require_reason ? ' checked' : '') + '>' +
        'A discount must have a reason</label><br>' +
        '<span>The owner and managers have no limit: it is their call. A role with no limit set is not restricted either.</span></div>' +
      '<div class="ds-bar" style="margin:14px 0 0"><button class="ds-b primary" data-ds="save-caps">Save limits</button></div></div>';
  }

  function render() {
    var el = $('discountsRoot');
    if (!el || !S.data) return;
    var body = S.tab === 'caps' ? caps() : (S.tab === 'list' ? list(S.data.list) : report(S.data.report));
    el.innerHTML = bar() + body;
  }

  function load() {
    css();
    var el = $('discountsRoot');
    if (el && !S.data) el.innerHTML = '<div class="loading">Loading…</div>';
    var q = (S.from && S.to) ? ('?from=' + S.from + '&to=' + S.to) : '';
    return getJson(q).then(function (d) {
      if (!d.success) {
        if (el) el.innerHTML = '<div class="ds-empty">' + esc(d.message || 'Could not load the discount report.') + '</div>';
        return;
      }
      S.data = d;
      S.from = d.report.from;
      S.to = d.report.to;
      render();
    }).catch(function () {
      if (el) el.innerHTML = '<div class="ds-empty">Could not load. <button class="ds-b plain" data-ds="reload">Try again</button></div>';
    });
  }

  function saveCaps(btn) {
    var caps = {};
    S.data.roles.forEach(function (role) {
      var pct = parseFloat(document.querySelector('[data-ds-pct="' + role.replace(/"/g, '\\"') + '"]').value) || 0;
      var amt = parseFloat(document.querySelector('[data-ds-amt="' + role.replace(/"/g, '\\"') + '"]').value) || 0;
      if (pct > 0 || amt > 0) caps[role] = { percent: pct, amount: amt };
    });
    btn.disabled = true;
    post({ action: 'save_settings', settings: { caps: caps, reasons: S.data.settings.reasons,
           require_reason: $('dsNeedReason') ? $('dsNeedReason').checked : false } })
      .then(function (d) {
        btn.disabled = false;
        if (!d.success) return notify(d.message || 'Could not save', 'error');
        S.data.settings = d.settings;
        notify('Limits saved.', 'success');
        render();
      }).catch(function () { btn.disabled = false; notify('Could not save', 'error'); });
  }

  document.addEventListener('click', function (e) {
    if (!e.target || !e.target.closest) return;
    var tab = e.target.closest('[data-ds-tab]');
    if (tab) { S.tab = tab.getAttribute('data-ds-tab'); render(); return; }
    var hit = e.target.closest('[data-ds]');
    if (!hit) return;
    var what = hit.getAttribute('data-ds');
    if (what === 'apply') {
      S.from = $('dsFrom').value || S.from;
      S.to = $('dsTo').value || S.to;
      load();
    } else if (what === 'save-caps') saveCaps(hit);
    else if (what === 'reload') load();
  });

  window.RGDiscounts = { load: load };
})();
