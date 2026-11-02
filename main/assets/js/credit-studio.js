/* Credit page (dashboard #creditPage, api/credit_admin.php, config/credit_ledger.php).
   Who owes money, with the oldest debts first. Open an account to see its statement — every bill put on
   it and every payment back, each with the balance it left — and to take a payment, add an amount by
   hand, or write a correction. A payment in cash also goes into the open drawer shift as cash in, and
   the window says so, because that is the bit a cashier needs to trust.
   window.RGCredit.load() runs when the page opens (script.js showPage). */
(function () {
  var API = '../api/credit_admin.php';
  var S = { data: null, filter: 'owing' };
  var PAY = ['Cash', 'UPI', 'Card', 'Bank transfer', 'eSewa', 'Khalti', 'Other'];
  var FILTERS = [['owing', 'Owing'], ['clear', 'Settled'], ['all', 'All'], ['off', 'Switched off']];

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function notify(m, t) { if (typeof window.showNotification === 'function') window.showNotification(m, t); }
  function cur() { return S.data ? S.data.currency : ''; }
  function money(n) { return cur() + (+n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function when(s) { return s ? new Date(String(s).replace(' ', 'T')).toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—'; }
  function dayOnly(s) { return s ? new Date(String(s).replace(' ', 'T')).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '—'; }
  function ago(s) {
    if (!s) return 'nothing yet';
    var d = Math.floor((Date.now() - new Date(String(s).replace(' ', 'T')).getTime()) / 86400000);
    return d <= 0 ? 'today' : (d === 1 ? 'yesterday' : d + ' days ago');
  }
  function post(body) {
    return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); });
  }
  function getJson(q) { return fetch(API + (q || ''), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }); }

  function css() {
    if ($('crStyles')) return;
    var s = document.createElement('style');
    s.id = 'crStyles';
    s.textContent =
      '.cr-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}' +
      '.cr-stat{background:#fff;border:1px solid #eef0f4;border-radius:14px;padding:12px 14px;min-width:0}.cr-stat small{display:block;color:#6b7280;font-size:12px}.cr-stat b{font-size:22px;word-break:break-word}.cr-stat em{display:block;font-style:normal;color:#6b7280;font-size:12px;margin-top:2px}' +
      '.cr-stat.owed b{color:#b45309}.cr-stat.stale b{color:#b91c1c}' +
      '.cr-chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}' +
      '.cr-chips button{border:1px solid #e5e7eb;background:#fff;border-radius:999px;padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer;color:#374151}' +
      '.cr-chips button.on{background:#111827;color:#fff;border-color:#111827}' +
      '.cr-list{display:grid;gap:10px}' +
      '.cr-acc{background:#fff;border:1px solid #eef0f4;border-radius:14px;padding:12px 14px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;cursor:pointer;min-width:0}' +
      '.cr-acc:hover{border-color:#ddd6fe;background:#fdfdff}.cr-acc b{font-size:15px}.cr-acc small{color:#6b7280;display:block}' +
      '.cr-acc .amt{text-align:right;white-space:nowrap}.cr-acc .amt b{font-size:18px;color:#b45309}.cr-acc .amt b.clear{color:#047857}' +
      '.cr-warn{display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:600;background:#fee2e2;color:#991b1b;margin-left:6px}' +
      '.cr-lim{display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:600;background:#f3f4f6;color:#4b5563;margin-left:6px}' +
      '.cr-empty{background:#fff;border:1px dashed #d1d5db;border-radius:14px;padding:30px;text-align:center;color:#6b7280}' +
      '.cr-ov{position:fixed;inset:0;background:rgba(17,24,39,.5);z-index:10000;display:flex;align-items:center;justify-content:center;padding:16px}' +
      '.cr-md{background:#fff;border-radius:16px;width:100%;max-width:620px;max-height:92vh;overflow:auto;padding:20px;position:relative}' +
      '.cr-md h3{margin:0 0 4px;font-size:18px;padding-right:36px}.cr-md p.sub{margin:0 0 14px;color:#6b7280;font-size:13px}' +
      '.cr-md .x{position:absolute;right:14px;top:14px;border:0;background:#f3f4f6;border-radius:50%;width:32px;height:32px;cursor:pointer}' +
      '.cr-md label{display:block;font-size:13px;font-weight:600;color:#374151;margin:0 0 4px}' +
      '.cr-md input,.cr-md select,.cr-md textarea{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #d1d5db;border-radius:9px;font-size:15px;font-family:inherit}' +
      '.cr-md .f{margin-bottom:12px}.cr-md .g{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}' +
      '.cr-md .ft{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap}' +
      '.cr-b{border:0;border-radius:9px;padding:9px 14px;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;font-family:inherit}' +
      '.cr-b.primary{background:#7c3aed;color:#fff}.cr-b.plain{background:#f3f4f6;color:#111827}.cr-b.off{background:#fff;color:#b91c1c;border:1.5px solid #fca5a5}' +
      '.cr-b .material-symbols-rounded{font-size:18px}.cr-b:disabled{opacity:.5;cursor:default}' +
      '.cr-bal{background:#f5f3ff;border:1px solid #ddd6fe;border-radius:12px;padding:12px 14px;margin-bottom:14px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}' +
      '.cr-bal b{font-size:22px;color:#5b21b6}' +
      '.cr-tw{overflow-x:auto}.cr-t{width:100%;border-collapse:collapse;font-size:13px;min-width:520px}' +
      '.cr-t th{text-align:left;color:#6b7280;font-weight:600;padding:8px 10px;border-bottom:1px solid #eef0f4;white-space:nowrap}' +
      '.cr-t td{padding:9px 10px;border-bottom:1px solid #f6f7f9;vertical-align:top}' +
      '.cr-t td.r{text-align:right;white-space:nowrap}.cr-t td.charge{color:#b45309;font-weight:600}.cr-t td.payment{color:#047857;font-weight:600}' +
      '.cr-hint{font-size:12px;color:#6b7280;margin-top:4px}';
    document.head.appendChild(s);
  }

  function render() {
    var root = $('creditRoot');
    if (!root || !S.data) return;
    var o = S.data.overview;
    var html = '<div class="cr-stats">' +
      '<div class="cr-stat owed"><small>Out on credit</small><b>' + money(o.owed) + '</b><em>across ' + o.accounts + ' account' + (o.accounts === 1 ? '' : 's') + '</em></div>' +
      (o.stale ? '<div class="cr-stat stale"><small>Not paid in 30 days</small><b>' + o.stale + '</b><em>worth a phone call</em></div>' : '') +
      '</div>' +
      '<div class="cr-chips">' + FILTERS.map(function (f) {
        return '<button data-cr-filter="' + f[0] + '"' + (S.filter === f[0] ? ' class="on"' : '') + '>' + f[1] + '</button>';
      }).join('') + '</div>';

    if (!S.data.accounts.length) {
      html += '<div class="cr-empty">' + (S.filter === 'owing' ? 'Nobody owes anything right now.' : 'No accounts here.') +
        '<div class="cr-hint" style="margin-top:8px">A bill settled with a payment method called Credit (or Udhaar / Khata) lands on the customer’s account by itself, using the phone number on the bill.</div></div>';
    } else {
      html += '<div class="cr-list">' + S.data.accounts.map(function (a) {
        return '<div class="cr-acc" data-cr-open="' + a.id + '">' +
          '<div style="min-width:0"><b>' + esc(a.name || a.phone) + '</b>' +
            (a.limit > 0 ? '<span class="cr-lim">limit ' + money(a.limit) + '</span>' : '') +
            (!a.active ? '<span class="cr-warn">switched off</span>' : '') +
            '<small>' + esc(a.phone) + ' · last movement ' + ago(a.last_at) + '</small></div>' +
          '<div class="amt"><b class="' + (a.balance > 0.005 ? '' : 'clear') + '">' + money(a.balance) + '</b>' +
            '<small>' + (a.balance > 0.005 ? 'owing' : 'settled') + '</small></div>' +
          '</div>';
      }).join('') + '</div>';
    }
    root.innerHTML = html;
  }

  function load() {
    css();
    var root = $('creditRoot');
    if (root && !S.data) root.innerHTML = '<div class="loading">Loading…</div>';
    return getJson('?filter=' + S.filter).then(function (d) {
      if (!d.success) {
        if (root) root.innerHTML = '<div class="cr-empty">' + esc(d.message || 'Could not load credit accounts.') + '</div>';
        return;
      }
      S.data = d;
      render();
    }).catch(function () {
      if (root) root.innerHTML = '<div class="cr-empty">Could not load credit accounts. <button class="cr-b plain" data-cr="reload">Try again</button></div>';
    });
  }

  function modal(html) {
    var ov = document.createElement('div');
    ov.className = 'cr-ov';
    ov.innerHTML = '<div class="cr-md"><button class="x" aria-label="Close" data-cr="shut">&times;</button>' + html + '</div>';
    ov.addEventListener('click', function (e) { if (e.target === ov || e.target.closest('[data-cr="shut"]')) ov.remove(); });
    document.body.appendChild(ov);
    var first = ov.querySelector('input,select,textarea');
    if (first) first.focus();
    return ov;
  }

  function editAccount(a) {
    a = a || {};
    var ov = modal('<h3>' + (a.id ? 'Edit account' : 'New credit account') + '</h3>' +
      '<p class="sub">The phone number is how a POS bill finds this account, so it has to match what the customer gives at the counter.</p>' +
      '<div class="g"><div><label for="crName">Name</label><input id="crName" maxlength="120" value="' + esc(a.name || '') + '"></div>' +
      '<div><label for="crPhone">Phone</label><input id="crPhone" type="tel" maxlength="15" value="' + esc(a.phone || '') + '"></div></div>' +
      '<div class="g"><div><label for="crLimit">Credit limit</label><input id="crLimit" type="number" min="0" step="0.01" value="' + (a.limit || 0) + '">' +
        '<div class="cr-hint">0 means no limit. A bill over the limit is refused at the counter.</div></div>' +
      '<div><label for="crActive">Account</label><select id="crActive"><option value="1"' + (a.active === false ? '' : ' selected') + '>On</option>' +
        '<option value="0"' + (a.active === false ? ' selected' : '') + '>Switched off</option></select></div></div>' +
      '<div class="f"><label for="crNote">Note</label><input id="crNote" maxlength="255" value="' + esc(a.notes || '') + '"></div>' +
      '<div class="ft"><button class="cr-b plain" data-cr="shut">Cancel</button><button class="cr-b primary" data-cr="do-save">Save</button></div>');
    ov.querySelector('[data-cr="do-save"]').addEventListener('click', function (e) {
      var btn = e.target.closest('button');
      btn.disabled = true;
      post({ action: 'save', account: { id: a.id || 0, name: $('crName').value, phone: $('crPhone').value,
        limit: parseFloat($('crLimit').value) || 0, notes: $('crNote').value, active: $('crActive').value === '1' } })
        .then(function (d) {
          if (!d.success) { btn.disabled = false; return notify(d.message || 'Could not save', 'error'); }
          ov.remove();
          load();
        }).catch(function () { btn.disabled = false; notify('Could not save', 'error'); });
    });
  }

  function statement(id) {
    getJson('?account=' + id).then(function (d) {
      if (!d.success) return notify(d.message || 'Could not load that account', 'error');
      var s = d.statement, a = s.account;
      var rows = s.entries.length ? s.entries.map(function (e) {
        var sign = e.type === 'payment' ? '− ' : (e.type === 'charge' ? '+ ' : '');
        return '<tr><td>' + when(e.at) + '<br><small style="color:#9ca3af">' + esc(e.by || '—') + '</small></td>' +
          '<td>' + (e.type === 'charge' ? 'Bill on account' : (e.type === 'payment' ? 'Paid back' : 'Correction')) +
            (e.order ? '<br><small style="color:#9ca3af">' + esc(e.order) + '</small>' : '') +
            (e.method ? '<br><small style="color:#9ca3af">' + esc(e.method) + '</small>' : '') +
            (e.note ? '<br><small style="color:#9ca3af">' + esc(e.note) + '</small>' : '') + '</td>' +
          '<td class="r ' + e.type + '">' + sign + money(e.amount) + '</td>' +
          '<td class="r">' + money(e.balance_after) + '</td></tr>';
      }).join('') : '<tr><td colspan="4" style="color:#6b7280;padding:18px 10px">Nothing on this account yet.</td></tr>';

      var ov = modal('<h3>' + esc(a.name || a.phone) + '</h3>' +
        '<p class="sub">' + esc(a.phone) + ' · on the books since ' + dayOnly(a.since) +
          (a.limit > 0 ? ' · limit ' + money(a.limit) : '') + (a.active ? '' : ' · switched off') + '</p>' +
        '<div class="cr-bal"><span>Outstanding<br><b>' + money(a.balance) + '</b></span>' +
          '<span style="text-align:right;color:#6b7280;font-size:13px">put on account ' + money(s.totals.charged) +
          '<br>paid back ' + money(s.totals.paid) + '</span></div>' +
        (d.can_edit ? '<div class="ft" style="justify-content:flex-start;margin:0 0 14px">' +
          '<button class="cr-b primary" data-cr="do-pay"><span class="material-symbols-rounded">payments</span>Take a payment</button>' +
          '<button class="cr-b plain" data-cr="do-charge">Add an amount</button>' +
          '<button class="cr-b plain" data-cr="do-adjust">Correction</button>' +
          '<button class="cr-b plain" data-cr="do-edit">Edit account</button></div>' : '') +
        '<div class="cr-tw"><table class="cr-t"><thead><tr><th>When</th><th>What</th><th style="text-align:right">Amount</th><th style="text-align:right">Balance</th></tr></thead>' +
        '<tbody>' + rows + '</tbody></table></div>');

      ov.addEventListener('click', function (e) {
        var hit = e.target.closest ? e.target.closest('[data-cr]') : null;
        if (!hit) return;
        var what = hit.getAttribute('data-cr');
        if (what === 'do-pay') { ov.remove(); takePayment(a); }
        else if (what === 'do-charge') { ov.remove(); addCharge(a); }
        else if (what === 'do-adjust') { ov.remove(); correction(a); }
        else if (what === 'do-edit') { ov.remove(); editAccount(a); }
      });
    });
  }

  function takePayment(a) {
    var ov = modal('<h3>Payment from ' + esc(a.name || a.phone) + '</h3>' +
      '<p class="sub">They owe ' + money(a.balance) + '. Paid in cash, this also goes into the open drawer shift as cash in — it is not counted as a new sale, because the sale was counted when the bill was made.</p>' +
      '<div class="g"><div><label for="crPayAmt">How much</label><input id="crPayAmt" type="number" min="0.01" step="0.01" max="' + a.balance + '" value="' + a.balance + '" inputmode="decimal"></div>' +
      '<div><label for="crPayHow">How</label><select id="crPayHow">' + PAY.map(function (p) { return '<option>' + p + '</option>'; }).join('') + '</select></div></div>' +
      '<div class="f"><label for="crPayNote">Note</label><input id="crPayNote" maxlength="255" placeholder="Optional"></div>' +
      '<div class="ft"><button class="cr-b plain" data-cr="shut">Cancel</button><button class="cr-b primary" data-cr="go">Save payment</button></div>');
    ov.querySelector('[data-cr="go"]').addEventListener('click', function (e) {
      var btn = e.target.closest('button');
      btn.disabled = true;
      post({ action: 'pay', id: a.id, amount: parseFloat($('crPayAmt').value) || 0, method: $('crPayHow').value, note: $('crPayNote').value })
        .then(function (d) {
          if (!d.success) { btn.disabled = false; return notify(d.message || 'Could not save', 'error'); }
          ov.remove();
          notify(d.in_drawer ? 'Payment saved, and put into the drawer.' : 'Payment saved.', 'success');
          load();
        }).catch(function () { btn.disabled = false; notify('Could not save', 'error'); });
    });
  }

  function addCharge(a) {
    var ov = modal('<h3>Add an amount to ' + esc(a.name || a.phone) + '</h3>' +
      '<p class="sub">For something billed outside the POS. A POS bill put on the account arrives here by itself.</p>' +
      '<div class="f"><label for="crChAmt">How much</label><input id="crChAmt" type="number" min="0.01" step="0.01" inputmode="decimal"></div>' +
      '<div class="f"><label for="crChNote">What for</label><input id="crChNote" maxlength="255" placeholder="Catering on the 12th"></div>' +
      '<div class="ft"><button class="cr-b plain" data-cr="shut">Cancel</button><button class="cr-b primary" data-cr="go">Add</button></div>');
    ov.querySelector('[data-cr="go"]').addEventListener('click', function (e) {
      var btn = e.target.closest('button');
      btn.disabled = true;
      post({ action: 'charge', id: a.id, amount: parseFloat($('crChAmt').value) || 0, note: $('crChNote').value })
        .then(function (d) {
          if (!d.success) {
            btn.disabled = false;
            if (/limit/i.test(d.message || '') && window.confirm(d.message + '\n\nPut it on the account anyway?')) {
              return post({ action: 'charge', id: a.id, amount: parseFloat($('crChAmt').value) || 0, note: $('crChNote').value, force: true })
                .then(function (f) { if (f.success) { ov.remove(); load(); } else notify(f.message || 'Could not add', 'error'); });
            }
            return notify(d.message || 'Could not add', 'error');
          }
          ov.remove();
          load();
        }).catch(function () { btn.disabled = false; notify('Could not add', 'error'); });
    });
  }

  function correction(a) {
    var ov = modal('<h3>Correction on ' + esc(a.name || a.phone) + '</h3>' +
      '<p class="sub">Use a minus to reduce what they owe. The reason is kept with it, so the balance is never quietly changed.</p>' +
      '<div class="f"><label for="crAdjAmt">Amount (minus to reduce)</label><input id="crAdjAmt" type="number" step="0.01" inputmode="decimal" placeholder="-100"></div>' +
      '<div class="f"><label for="crAdjNote">Why</label><input id="crAdjNote" maxlength="255" placeholder="Double charged on the 3rd"></div>' +
      '<div class="ft"><button class="cr-b plain" data-cr="shut">Cancel</button><button class="cr-b primary" data-cr="go">Save correction</button></div>');
    ov.querySelector('[data-cr="go"]').addEventListener('click', function (e) {
      var btn = e.target.closest('button');
      btn.disabled = true;
      post({ action: 'adjust', id: a.id, amount: parseFloat($('crAdjAmt').value) || 0, note: $('crAdjNote').value })
        .then(function (d) {
          if (!d.success) { btn.disabled = false; return notify(d.message || 'Could not save', 'error'); }
          ov.remove();
          load();
        }).catch(function () { btn.disabled = false; notify('Could not save', 'error'); });
    });
  }

  document.addEventListener('click', function (e) {
    if (!e.target || !e.target.closest) return;
    var f = e.target.closest('[data-cr-filter]');
    if (f) { S.filter = f.getAttribute('data-cr-filter'); load(); return; }
    var open = e.target.closest('[data-cr-open]');
    if (open) { statement(parseInt(open.getAttribute('data-cr-open'), 10)); return; }
    if (e.target.closest('#crAddBtn')) { if (S.data && S.data.can_edit) editAccount(null); return; }
    var hit = e.target.closest('[data-cr]');
    if (hit && hit.getAttribute('data-cr') === 'reload') load();
  });

  window.RGCredit = { load: load };
})();
