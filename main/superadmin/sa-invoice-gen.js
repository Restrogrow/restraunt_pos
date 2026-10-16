/**
 * Superadmin › Invoice generator. Pick the restaurant, plan, months, extras, discount and payment;
 * the server gives the number (RG/2026-27/M0001), dates, GST split and saves it, then the branded
 * PDF downloads. Below: every generated invoice (open, download, mark paid, cancel).
 * Data: api_platform.php (invoiceData, invoiceCreate, invoiceList, invoiceMark, invoiceCatalog, invoiceView).
 * Loaded after sa-platform.js, which calls window.saInvoiceGen.load() when the page opens.
 */
(function () {
  'use strict';
  var API = 'api_platform.php';
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var money = function (n) { return '₹' + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var rs = function (n) { return '₹' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }); };
  var dmy = function (s) { if (!s) return ''; var t = new Date(String(s).slice(0, 10) + 'T00:00:00'); return isNaN(t) ? s : t.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }); };
  var ymd = function (t) { return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0'); };
  var round2 = function (n) { return Math.round(+(n * 100).toFixed(6)) / 100; };   // like PHP round(): 2298.615 → 2298.62
  function get(action, q) { return fetch(API + '?action=' + action + (q || ''), { cache: 'no-store' }).then(res); }
  function post(action, body) { return fetch(API + '?action=' + action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) }).then(res); }
  function res(r) {
    return r.text().then(function (t) {
      var d; try { d = JSON.parse(t); } catch (e) { throw new Error(r.status >= 500 ? 'Server is busy, try again' : 'Unexpected reply from server'); }
      if (d.session_expired) { location.href = 'login.php'; throw new Error('Session expired'); }
      if (!d.success) throw new Error(d.message || 'Something went wrong');
      return d;
    });
  }
  function toast(m, type) { if (window.Swal) Swal.fire({ icon: type || 'success', text: m, confirmButtonColor: '#2563eb', timer: type === 'error' ? undefined : 2200, showConfirmButton: type === 'error' }); else alert(m); }
  var viewUrl = function (id, dl) { return API + '?action=invoiceView&id=' + id + (dl ? '&dl=1' : ''); };

  var METHODS = ['UPI', 'Bank transfer', 'Cash', 'Card', 'PhonePe', 'Cheque'];
  var MONTHS = [1, 3, 6, 12];
  var DISCOUNTS = [0, 5, 10, 15, 20];
  var DUE = [7, 15, 30];

  var rests = null;          // [{id, restaurant_id, restaurant_name}]
  var data = null;           // invoiceData for the chosen restaurant
  var st = null;             // the choices
  var listQ = '';
  function fresh() {
    return { plan: data ? data.suggested_plan : 'small', months: 1, from: data ? data.period_from : '', addons: {}, custom: [],
      discType: 'pct', discVal: 0, status: 'paid', method: 'UPI', ref: '', dueDays: 7, extend: true, notes: '' };
  }

  /* ── totals, same rules as the server (saInvCompute) ── */
  function lines() {
    if (!data) return [];
    var cat = data.catalog, out = [];
    var plan = cat.plans.filter(function (p) { return p.key === st.plan; })[0];
    if (plan && st.months > 0) out.push({ desc: plan.name + ' plan · RestroGrow POS', sub: period(), qty: st.months, unit: st.months > 1 ? 'months' : 'month', rate: +plan.price });
    cat.addons.forEach(function (a) {
      if (!st.addons[a.key]) return;
      var q = a.per ? Math.max(1, +st.addons[a.key] || 1) : 1;
      out.push({ desc: a.name, sub: a.sub, qty: q, unit: a.per ? (q > 1 ? a.per + 's' : a.per) : '', rate: +a.price });
    });
    st.custom.forEach(function (c) { if (c.desc.trim() && +c.rate > 0) out.push({ desc: c.desc.trim(), sub: '', qty: Math.max(1, +c.qty || 1), unit: '', rate: +c.rate }); });
    return out;
  }
  function totals() {
    var ls = lines(), sub = 0;
    ls.forEach(function (l) { l.amount = round2(l.qty * l.rate); sub += l.amount; });
    sub = round2(sub);
    var disc = st.discType === 'amt' ? +st.discVal || 0 : round2(sub * Math.min(+st.discVal || 0, 100) / 100);
    disc = round2(Math.max(0, Math.min(disc, sub)));
    var net = round2(sub - disc), rate = +data.settings.gst_percent || 0, taxable, tax, total;
    if (data.settings.prices_include_gst) { taxable = rate > 0 ? round2(net * 100 / (100 + rate)) : net; tax = round2(net - taxable); total = net; }
    else { taxable = net; tax = round2(net * rate / 100); total = round2(net + tax); }
    var cgst = round2(tax / 2);
    return { lines: ls, sub: sub, disc: disc, taxable: taxable, rate: rate, tax: tax, cgst: cgst, sgst: round2(tax - cgst), total: total, inter: !!data.inter };
  }
  function periodTo() {
    if (!st.from || !st.months) return '';
    var t = new Date(st.from + 'T00:00:00');
    var d = t.getDate();
    t.setMonth(t.getMonth() + st.months);
    if (t.getDate() !== d) t.setDate(0);           // e.g. 31 Jan + 1 month → end of Feb, like PHP's strtotime rolls
    t.setDate(t.getDate() - 1);
    return ymd(t);
  }
  function period() { return st.from && st.months ? 'Subscription ' + dmy(st.from) + ' – ' + dmy(periodTo()) : ''; }

  /* ── page ── */
  function load() {
    var box = $('spInvoiceGen');
    if (!box) return;
    $('spInvoiceGenActs').innerHTML = '<button class="btn btn-outline" type="button" data-ig="prices"><span class="material-symbols-rounded">sell</span>Prices</button>';
    box.innerHTML = '<div class="ig"><div class="ig-main" id="igMain"></div><aside class="ig-side" id="igSide"></aside></div><div id="igList"></div>';
    var p = rests ? Promise.resolve(rests) : get('restaurantOptions').then(function (d) { rests = d.rows; return rests; });
    p.then(function () { drawMain(); drawSide(); }).catch(function (e) { $('igMain').innerHTML = '<div class="ig-empty">' + esc(e.message) + '</div>'; });
    loadList();
  }

  function stepHead(n, title, hint) { return '<div class="ig-step-h"><span class="ig-n">' + n + '</span><div><b>' + title + '</b>' + (hint ? '<small>' + hint + '</small>' : '') + '</div></div>'; }

  function drawMain() {
    var m = $('igMain');
    if (!data) {
      m.innerHTML = '<section class="ig-step">' + stepHead(1, 'Who is it for?', 'Search by restaurant name or ID') +
        '<div class="ig-combo"><span class="material-symbols-rounded">search</span><input id="igSearch" autocomplete="off" placeholder="Start typing a restaurant name…"><div class="ig-drop" id="igDrop"></div></div>' +
        '<div class="ig-quick" id="igQuick"></div></section>' +
        '<section class="ig-step ig-ghost">' + stepHead(2, 'Plan & months') + '</section><section class="ig-step ig-ghost">' + stepHead(3, 'Extras') + '</section><section class="ig-step ig-ghost">' + stepHead(4, 'Payment') + '</section>';
      drawDrop('');
      setTimeout(function () { var s = $('igSearch'); if (s) s.focus(); }, 30);
      return;
    }
    var r = data.restaurant, cat = data.catalog, exp = r.expires_on, past = exp && exp < data.today;
    var plans = cat.plans.filter(function (p) { return !p.hidden; });
    var addons = cat.addons.filter(function (a) { return !a.hidden; });
    m.innerHTML =
      // 1. restaurant
      '<section class="ig-step done">' + stepHead(1, 'Billed to') +
        '<div class="ig-who"><div class="ig-av">' + esc((r.restaurant_name || '?').trim().charAt(0).toUpperCase()) + '</div><div class="ig-who-t"><b>' + esc(r.restaurant_name) + '</b>' +
          '<small>' + esc(r.restaurant_id) + (r.owner_name ? ' · ' + esc(r.owner_name) : '') + (r.phone ? ' · ' + esc(r.phone) : '') + '</small>' +
          '<div class="ig-tags"><span>' + data.tables + ' table' + (data.tables === 1 ? '' : 's') + '</span>' + (data.branches > 1 ? '<span>' + data.branches + ' branches</span>' : '') +
          '<span class="' + (past ? 'bad' : '') + '">' + esc(r.subscription_status || 'trial') + (exp ? ' · ' + (past ? 'ended ' : 'until ') + dmy(exp) : '') + '</span>' +
          (r.gstin_no ? '<span>GSTIN ' + esc(r.gstin_no) + '</span>' : '<span class="warn">No GSTIN</span>') +
          (data.last_invoice ? '<span>Last: ' + esc(data.last_invoice.invoice_no) + ' · ' + rs(data.last_invoice.total) + '</span>' : '') + '</div></div>' +
          '<button type="button" class="ig-link" data-ig="change">Change</button></div></section>' +
      // 2. plan + months
      '<section class="ig-step">' + stepHead(2, 'Plan & months', 'Suggested from their tables and branches') +
        '<div class="ig-plans">' + plans.map(function (p) {
          return '<button type="button" class="ig-plan' + (st.plan === p.key ? ' on' : '') + '" data-plan="' + p.key + '">' + (p.key === data.suggested_plan ? '<i class="ig-sug">Suggested</i>' : '') +
            '<b>' + esc(p.name) + '</b><small>' + esc(p.sub) + '</small><span>' + rs(p.price) + '<em>/month</em></span></button>';
        }).join('') + '<button type="button" class="ig-plan ig-none' + (st.plan === '' ? ' on' : '') + '" data-plan=""><b>No plan</b><small>Only extras on this invoice</small><span>—</span></button></div>' +
        (st.plan ? '<div class="ig-row"><div class="ig-chips" role="radiogroup" aria-label="Months">' + MONTHS.map(function (n) {
            return '<button type="button" class="ig-chip' + (st.months === n ? ' on' : '') + '" data-months="' + n + '">' + (n === 12 ? '1 year' : n + ' month' + (n > 1 ? 's' : '')) + '</button>';
          }).join('') + '</div>' +
          '<label class="ig-from">Starts <input type="date" id="igFrom" value="' + esc(st.from) + '"></label></div>' +
          '<div class="ig-period"><span class="material-symbols-rounded">event_available</span>' + dmy(st.from) + ' → ' + dmy(periodTo()) +
            '<small>' + (exp && !past ? 'starts the day after their current plan ends' : 'starts today') + '</small></div>' : '') +
      '</section>' +
      // 3. extras
      '<section class="ig-step">' + stepHead(3, 'Extras', 'One-time services, tap to add') +
        '<div class="ig-addons">' + addons.map(function (a) {
          var on = !!st.addons[a.key];
          return '<div class="ig-addon' + (on ? ' on' : '') + '" data-addon="' + a.key + '" role="checkbox" aria-checked="' + on + '" tabindex="0"><span class="ig-ck"><span class="material-symbols-rounded">check</span></span>' +
            '<div class="ig-addon-t"><b>' + esc(a.name) + '</b><small>' + esc(a.sub) + '</small></div><span class="ig-price">' + rs(a.price) + (a.per ? '<em>/' + esc(a.per) + '</em>' : '') + '</span>' +
            (a.per && on ? '<div class="ig-qty" data-noflip><button type="button" data-qty="-1" aria-label="Fewer">−</button><input type="number" min="1" max="500" value="' + (+st.addons[a.key] || 1) + '" data-qtyfor="' + a.key + '" aria-label="How many"><button type="button" data-qty="1" aria-label="More">+</button></div>' : '') +
            '</div>';
        }).join('') + '</div>' +
        '<div class="ig-custom">' + st.custom.map(function (c, i) {
          return '<div class="ig-cl" data-ci="' + i + '"><input placeholder="Description" value="' + esc(c.desc) + '" data-cf="desc"><input type="number" min="1" placeholder="Qty" value="' + esc(c.qty) + '" data-cf="qty" class="q"><input type="number" min="0" step="0.01" placeholder="Rate ₹" value="' + esc(c.rate || '') + '" data-cf="rate" class="r"><button type="button" class="ig-x" data-cdel="' + i + '" aria-label="Remove">×</button></div>';
        }).join('') + '<button type="button" class="ig-link" data-ig="addline"><span class="material-symbols-rounded">add</span>Add something else</button></div>' +
      '</section>' +
      // 4. discount + payment
      '<section class="ig-step">' + stepHead(4, 'Discount & payment') +
        '<div class="ig-lbl">Discount</div><div class="ig-chips">' + DISCOUNTS.map(function (n) {
          return '<button type="button" class="ig-chip' + (st.discType === 'pct' && +st.discVal === n ? ' on' : '') + '" data-disc="' + n + '">' + (n ? n + '%' : 'None') + '</button>';
        }).join('') + '<label class="ig-chip ig-chip-in' + (st.discType === 'amt' ? ' on' : '') + '">₹ <input type="number" min="0" step="1" id="igDiscAmt" placeholder="Amount" value="' + (st.discType === 'amt' && st.discVal ? esc(st.discVal) : '') + '"></label></div>' +
        '<div class="ig-lbl">Has it been paid?</div><div class="ig-seg"><button type="button" class="' + (st.status === 'paid' ? 'on' : '') + '" data-status="paid"><span class="material-symbols-rounded">task_alt</span>Paid</button><button type="button" class="' + (st.status === 'due' ? 'on' : '') + '" data-status="due"><span class="material-symbols-rounded">schedule</span>Not yet</button></div>' +
        (st.status === 'paid'
          ? '<div class="ig-lbl">Paid by</div><div class="ig-chips">' + METHODS.map(function (x) { return '<button type="button" class="ig-chip' + (st.method === x ? ' on' : '') + '" data-method="' + esc(x) + '">' + esc(x) + '</button>'; }).join('') + '</div>' +
            '<input class="ig-in" id="igRef" placeholder="Transaction / UTR number (optional)" value="' + esc(st.ref) + '" maxlength="100">' +
            (st.plan ? '<label class="ig-toggle"><input type="checkbox" id="igExtend"' + (st.extend ? ' checked' : '') + '><span></span><div><b>Extend their plan to ' + dmy(periodTo()) + '</b><small>Marks them active, so the restaurant keeps running</small></div></label>' : '')
          : '<div class="ig-lbl">Pay within</div><div class="ig-chips">' + DUE.map(function (n) { return '<button type="button" class="ig-chip' + (st.dueDays === n ? ' on' : '') + '" data-due="' + n + '">' + n + ' days</button>'; }).join('') + '</div>' +
            (data.settings.biz_upi ? '<div class="ig-note ok"><span class="material-symbols-rounded">qr_code_2</span>A UPI QR code for the exact amount is printed on the invoice.</div>' : '<div class="ig-note"><span class="material-symbols-rounded">info</span>Add your UPI ID and bank details in <a href="#" data-sp-go="settings">Settings</a> to print a pay-by-QR on unpaid invoices.</div>')) +
        '<details class="ig-more"' + (st.notes ? ' open' : '') + '><summary>Add a note on the invoice</summary><textarea class="ig-in" id="igNotes" rows="2" maxlength="600" placeholder="e.g. Thank you for renewing early!">' + esc(st.notes) + '</textarea></details>' +
      '</section>';
  }

  function drawDrop(q) {
    var drop = $('igDrop'), quick = $('igQuick');
    if (!drop || !rests) return;
    q = q.trim().toLowerCase();
    var hits = q ? rests.filter(function (r) { return (r.restaurant_name + ' ' + r.restaurant_id).toLowerCase().indexOf(q) !== -1; }).slice(0, 8) : [];
    drop.innerHTML = hits.map(function (r, i) { return '<button type="button" class="' + (i === 0 ? 'act' : '') + '" data-pick="' + r.id + '"><b>' + esc(r.restaurant_name) + '</b><small>' + esc(r.restaurant_id) + '</small></button>'; }).join('') ||
      (q ? '<div class="ig-nohit">No restaurant matches “' + esc(q) + '”</div>' : '');
    drop.classList.toggle('open', !!q);
    if (quick) quick.innerHTML = q ? '' : '<small>' + rests.length + ' restaurants · type a few letters</small>';
  }

  function drawSide() {
    var s = $('igSide');
    if (!s) return;
    if (!data) {
      s.innerHTML = '<div class="ig-paper ig-paper-empty"><img src="../assets/images/logo-transparent.png" alt="RestroGrow"><p>Choose a restaurant and the invoice builds itself here.</p></div>';
      return;
    }
    var t = totals(), r = data.restaurant;
    var dueOn = new Date(); dueOn.setDate(dueOn.getDate() + st.dueDays);
    s.innerHTML =
      '<div class="ig-paper">' +
        '<div class="ig-p-top"><img src="../assets/images/logo-transparent.png" alt="RestroGrow"><span class="ig-p-st ' + st.status + '">' + (st.status === 'paid' ? 'Paid' : 'Due ' + dmy(ymd(dueOn))) + '</span></div>' +
        '<div class="ig-p-no"><small>' + (data.settings.biz_gstin ? 'Tax invoice' : 'Invoice') + ' · number given on create</small><b>' + esc(data.invoice_no) + '</b><small>' + dmy(data.today) + '</small></div>' +
        '<div class="ig-p-to"><small>Billed to</small><b>' + esc(r.restaurant_name) + '</b></div>' +
        '<div class="ig-p-lines">' + (t.lines.length ? t.lines.map(function (l) {
          return '<div class="ig-p-l"><div><b>' + esc(l.desc) + '</b><small>' + l.qty + (l.unit ? ' ' + esc(l.unit) : '') + ' × ' + rs(l.rate) + '</small></div><span>' + rs(l.amount) + '</span></div>';
        }).join('') : '<div class="ig-p-l empty">Pick a plan or an extra</div>') + '</div>' +
        '<div class="ig-p-tot">' +
          (t.disc > 0 ? '<div><span>Subtotal</span><span>' + money(t.sub) + '</span></div><div class="g"><span>Discount</span><span>−' + money(t.disc) + '</span></div>' : '') +
          '<div><span>Taxable value</span><span>' + money(t.taxable) + '</span></div>' +
          (t.rate > 0 ? (t.inter ? '<div><span>IGST ' + t.rate + '%</span><span>' + money(t.tax) + '</span></div>' : '<div><span>CGST ' + t.rate / 2 + '%</span><span>' + money(t.cgst) + '</span></div><div><span>SGST ' + t.rate / 2 + '%</span><span>' + money(t.sgst) + '</span></div>') : '') +
        '</div>' +
        '<div class="ig-p-grand"><span>Total</span><b>' + money(t.total) + '</b></div>' +
        '<small class="ig-p-gst">' + (data.settings.prices_include_gst ? 'Prices include GST' : 'GST added on top') + (t.inter ? ' · IGST (other state)' : '') + '</small>' +
      '</div>' +
      '<button type="button" class="ig-go" data-ig="create"' + (t.lines.length ? '' : ' disabled') + '><span class="material-symbols-rounded">picture_as_pdf</span>Create invoice & download PDF</button>' +
      '<button type="button" class="ig-go2" data-ig="create-open"' + (t.lines.length ? '' : ' disabled') + '>Create and preview first</button>' +
      (data.settings.biz_ready ? '' : '<div class="ig-note"><span class="material-symbols-rounded">info</span>Your business address isn’t set. Add it in <a href="#" data-sp-go="settings">Settings</a> so it prints on the invoice.</div>');
  }

  function choose(id) {
    $('igMain').innerHTML = '<div class="ig-loading"><span></span>Loading their details…</div>';
    get('invoiceData', '&id=' + id).then(function (d) { data = d; st = fresh(); st.addons = {}; drawMain(); drawSide(); })
      .catch(function (e) { data = null; drawMain(); drawSide(); toast(e.message, 'error'); });
  }

  function create(preview) {
    if (!data) return;
    var t = totals();
    if (!t.lines.length) { toast('Pick a plan or at least one extra', 'error'); return; }
    var btns = document.querySelectorAll('[data-ig^="create"]');
    btns.forEach(function (b) { b.disabled = true; });
    var win = window.open('', '_blank');          // opened now, so the browser doesn't block it
    if (win) win.document.write('<p style="font:16px system-ui;padding:24px;color:#6b7280">Creating the invoice…</p>');
    post('invoiceCreate', {
      id: data.restaurant.id, plan: st.plan, months: st.plan ? st.months : 0, period_from: st.from,
      addons: Object.keys(st.addons).filter(function (k) { return st.addons[k]; }).map(function (k) { return { key: k, qty: +st.addons[k] || 1 }; }),
      custom: st.custom, discount_type: st.discType, discount_value: +st.discVal || 0,
      status: st.status, method: st.method, reference: st.ref, due_days: st.dueDays, extend: st.status === 'paid' && st.plan && st.extend ? 1 : 0, notes: st.notes
    }).then(function (d) {
      var url = viewUrl(d.invoice.id, !preview);
      if (win) win.location.href = url;
      var keep = data.restaurant.id;
      data = null; st = null; drawMain(); drawSide(); loadList();
      if (window.Swal) Swal.fire({ icon: 'success', title: d.invoice.invoice_no, html: esc(d.message) + '<br><b style="font-size:1.3rem">' + money(d.invoice.total) + '</b>' + (win ? '' : '<br><a href="' + url + '" target="_blank" rel="noopener">Open the invoice</a>'),
        confirmButtonText: 'New invoice', confirmButtonColor: '#2563eb', showCancelButton: true, cancelButtonText: 'Another for the same restaurant' })
        .then(function (r) { if (r.dismiss === Swal.DismissReason.cancel) choose(keep); });
    }).catch(function (e) {
      if (win) win.close();
      btns.forEach(function (b) { b.disabled = false; });
      toast(e.message, 'error');
    });
  }

  /* ── history ── */
  var SL = { paid: ['Paid', 'paid'], due: ['Unpaid', 'due'], void: ['Cancelled', 'void'] };
  function loadList() {
    var box = $('igList');
    if (!box) return;
    get('invoiceList', listQ ? '&q=' + encodeURIComponent(listQ) : '').then(function (d) {
      var s = d.sum || {};
      box.innerHTML = '<div class="card ig-hist"><div class="card-header"><div><div class="card-title">Generated invoices</div><small class="sp-muted">Financial year ' + esc(d.fy) + ' · ' + (+s.n || 0) + ' issued · ' + money(s.paid) + ' paid' + (+s.due_n ? ' · <b style="color:#b45309">' + s.due_n + ' unpaid (' + money(s.due) + ')</b>' : '') + '</small></div>' +
        '<div class="sa-search" style="max-width:280px"><span class="material-symbols-rounded">search</span><input id="igListQ" placeholder="Number or restaurant" value="' + esc(listQ) + '"></div></div><div class="card-body">' +
        (d.rows.length ? '<div style="overflow-x:auto"><table class="ig-tbl"><thead><tr><th>Invoice</th><th>Restaurant</th><th>Date</th><th>Period</th><th class="r">Total</th><th>Status</th><th></th></tr></thead><tbody>' + d.rows.map(function (r) {
          var sl = SL[r.status] || SL.paid;
          return '<tr><td><b>' + esc(r.invoice_no) + '</b></td><td>' + esc(r.restaurant_name) + '<br><small class="sp-muted">' + esc(r.restaurant_id) + '</small></td><td>' + dmy(r.invoice_date) + '</td>' +
            '<td>' + (r.period_from ? dmy(r.period_from) + ' – ' + dmy(r.period_to) : '<span class="sp-muted">—</span>') + '</td><td class="r"><b>' + money(r.total) + '</b></td>' +
            '<td><span class="ig-pill ' + sl[1] + '">' + sl[0] + '</span>' + (r.status === 'due' && r.due_date ? '<br><small class="sp-muted">by ' + dmy(r.due_date) + '</small>' : r.status === 'paid' && r.method ? '<br><small class="sp-muted">' + esc(r.method) + '</small>' : '') + '</td>' +
            '<td><div class="ig-acts"><a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="' + viewUrl(r.id) + '" title="Open"><span class="material-symbols-rounded">visibility</span></a>' +
              '<a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="' + viewUrl(r.id, true) + '" title="Download PDF"><span class="material-symbols-rounded">download</span></a>' +
              (r.status !== 'void' ? '<button class="btn btn-outline btn-sm" type="button" data-igmail="' + r.id + '" data-no="' + esc(r.invoice_no) + '" data-to="' + esc(r.email || '') + '" title="Email to restaurant"><span class="material-symbols-rounded">mail</span></button>' : '') +
              (r.status === 'due' ? '<button class="btn btn-outline btn-sm" type="button" data-igpaid="' + r.id + '" title="Mark paid"><span class="material-symbols-rounded">task_alt</span></button>' : '') +
              (r.status !== 'void' ? '<button class="btn btn-outline btn-sm" type="button" data-igvoid="' + r.id + '" data-no="' + esc(r.invoice_no) + '" title="Cancel invoice"><span class="material-symbols-rounded">block</span></button>' : '') +
            '</div></td></tr>';
        }).join('') + '</tbody></table></div>' : '<div class="ig-empty"><span class="material-symbols-rounded">receipt_long</span>' + (listQ ? 'Nothing matches “' + esc(listQ) + '”' : 'No invoices yet. Your first one gets ' + esc((data && data.invoice_no) || 'number M0001') + '.') + '</div>') +
        '</div></div>';
      if (listQ) { var i = $('igListQ'); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } }
    }).catch(function (e) { box.innerHTML = '<div class="ig-empty">' + esc(e.message) + '</div>'; });
  }

  function markPaid(id) {
    Swal.fire({ title: 'Mark as paid', html: '<select id="igMp" class="swal2-select" style="display:flex;width:80%;margin:8px auto">' + METHODS.map(function (m) { return '<option>' + m + '</option>'; }).join('') + '</select><input id="igMr" class="swal2-input" placeholder="Transaction / UTR number (optional)">',
      showCancelButton: true, confirmButtonText: 'Mark paid', confirmButtonColor: '#16a34a', preConfirm: function () { return { method: $('igMp').value, reference: $('igMr').value }; } })
      .then(function (r) { if (r.isConfirmed) post('invoiceMark', { id: id, status: 'paid', method: r.value.method, reference: r.value.reference }).then(function (d) { toast(d.message); loadList(); }).catch(function (e) { toast(e.message, 'error'); }); });
  }
  function mailInv(id, no, to) {
    Swal.fire({ title: 'Email ' + no, input: 'email', inputValue: to || '', inputLabel: 'Send to', inputPlaceholder: 'owner@restaurant.com',
      showCancelButton: true, confirmButtonText: 'Send email', confirmButtonColor: '#2563eb' })
      .then(function (r) { if (r.isConfirmed) post('invoiceEmail', { id: id, to: r.value }).then(function (d) { toast(d.message + ' to ' + r.value); }).catch(function (e) { toast(e.message, 'error'); }); });
  }
  function cancelInv(id, no) {
    Swal.fire({ icon: 'warning', title: 'Cancel ' + no + '?', text: 'It stays in the list as cancelled and its number is not reused (GST rule). This does not undo a plan extension.', showCancelButton: true, confirmButtonText: 'Cancel invoice', cancelButtonText: 'Keep it', confirmButtonColor: '#dc2626' })
      .then(function (r) { if (r.isConfirmed) post('invoiceMark', { id: id, status: 'void' }).then(function (d) { toast(d.message); loadList(); }).catch(function (e) { toast(e.message, 'error'); }); });
  }

  /* ── prices ── */
  function editPrices() {
    get('invoiceCatalog').then(function (d) {
      var c = d.catalog;
      var row = function (grp, x) {
        var fixed = grp === 'plans' && x.key === 'standard';
        return '<tr data-grp="' + grp + '" data-key="' + x.key + '"><td><input class="swal2-input ig-pi" data-f="name" value="' + esc(x.name) + '"></td>' +
          '<td><input class="swal2-input ig-pi" data-f="price" type="number" min="0" step="1" value="' + esc(x.price) + '"' + (fixed ? ' disabled title="Set in Settings › monthly price"' : '') + '></td>' +
          '<td style="text-align:center"><input type="checkbox" data-f="show"' + (x.hidden ? '' : ' checked') + '></td></tr>';
      };
      Swal.fire({
        title: 'Plans & extras prices', width: 640, showCancelButton: true, confirmButtonText: 'Save prices', confirmButtonColor: '#2563eb',
        html: '<div class="ig-prices"><p>' + (data && data.settings.prices_include_gst === false ? 'Prices are before GST.' : 'Prices include GST (change in Settings).') + ' Per month for plans.</p>' +
          '<table><thead><tr><th>Plan</th><th>₹ / month</th><th>Show</th></tr></thead><tbody>' + c.plans.map(function (x) { return row('plans', x); }).join('') + '</tbody></table>' +
          '<table><thead><tr><th>Extra</th><th>₹</th><th>Show</th></tr></thead><tbody>' + c.addons.map(function (x) { return row('addons', x); }).join('') + '</tbody></table></div>',
        preConfirm: function () {
          var out = { plans: [], addons: [] };
          document.querySelectorAll('.ig-prices tr[data-key]').forEach(function (tr) {
            var src = c[tr.dataset.grp].filter(function (x) { return x.key === tr.dataset.key; })[0] || {};
            out[tr.dataset.grp].push({ key: tr.dataset.key, name: tr.querySelector('[data-f=name]').value, sub: src.sub || '', price: +tr.querySelector('[data-f=price]').value || 0, hidden: !tr.querySelector('[data-f=show]').checked });
          });
          return out;
        }
      }).then(function (r) {
        if (!r.isConfirmed) return;
        post('invoiceCatalog', r.value).then(function (d2) { toast(d2.message); if (data) { data.catalog = d2.catalog; drawMain(); drawSide(); } }).catch(function (e) { toast(e.message, 'error'); });
      });
    }).catch(function (e) { toast(e.message, 'error'); });
  }

  /* ── events (only inside the generator page) ── */
  function inPage(e) { return e.target.closest && (e.target.closest('#invoiceGenPage') || e.target.closest('.ig-prices')); }
  document.addEventListener('click', function (e) {
    if (!inPage(e)) return;
    var t = e.target, m;
    if ((m = t.closest('[data-pick]'))) { choose(+m.dataset.pick); return; }
    if ((m = t.closest('[data-ig]'))) {
      var a = m.dataset.ig;
      if (a === 'change') { data = null; st = null; drawMain(); drawSide(); }
      else if (a === 'create') create(false);
      else if (a === 'create-open') create(true);
      else if (a === 'prices') editPrices();
      else if (a === 'addline') { st.custom.push({ desc: '', qty: 1, rate: '' }); drawMain(); var ins = document.querySelectorAll('.ig-cl input[data-cf=desc]'); if (ins.length) ins[ins.length - 1].focus(); }
      return;
    }
    if (!st) {
      if ((m = t.closest('[data-igmail]'))) mailInv(+m.dataset.igmail, m.dataset.no, m.dataset.to);
      else if ((m = t.closest('[data-igpaid]'))) markPaid(+m.dataset.igpaid);
      else if ((m = t.closest('[data-igvoid]'))) cancelInv(+m.dataset.igvoid, m.dataset.no);
      return;
    }
    if ((m = t.closest('[data-igmail]'))) { mailInv(+m.dataset.igmail, m.dataset.no, m.dataset.to); return; }
    if ((m = t.closest('[data-igpaid]'))) { markPaid(+m.dataset.igpaid); return; }
    if ((m = t.closest('[data-igvoid]'))) { cancelInv(+m.dataset.igvoid, m.dataset.no); return; }
    if ((m = t.closest('[data-plan]'))) { st.plan = m.dataset.plan; if (!st.plan) st.extend = false; else st.extend = true; redraw(); return; }
    if ((m = t.closest('[data-months]'))) { st.months = +m.dataset.months; redraw(); return; }
    if ((m = t.closest('[data-qty]'))) {
      var box = m.closest('[data-addon]'), k = box.dataset.addon;
      st.addons[k] = Math.max(1, Math.min(500, (+st.addons[k] || 1) + +m.dataset.qty)); redraw(); return;
    }
    if (t.closest('[data-noflip]')) return;
    if ((m = t.closest('[data-addon]'))) {
      var key = m.dataset.addon, ad = data.catalog.addons.filter(function (x) { return x.key === key; })[0];
      st.addons[key] = st.addons[key] ? 0 : (ad && ad.per ? Math.max(1, data.tables || 1) : 1);
      redraw(); return;
    }
    if ((m = t.closest('[data-cdel]'))) { st.custom.splice(+m.dataset.cdel, 1); redraw(); return; }
    if ((m = t.closest('[data-disc]'))) { st.discType = 'pct'; st.discVal = +m.dataset.disc; redraw(); return; }
    if ((m = t.closest('[data-status]'))) { st.status = m.dataset.status; redraw(); return; }
    if ((m = t.closest('[data-method]'))) { st.method = m.dataset.method; redraw(); return; }
    if ((m = t.closest('[data-due]'))) { st.dueDays = +m.dataset.due; redraw(); return; }
  });
  document.addEventListener('keydown', function (e) {
    if (!inPage(e)) return;
    if (e.target.id === 'igSearch' && e.key === 'Enter') { var f = document.querySelector('#igDrop [data-pick]'); if (f) choose(+f.dataset.pick); e.preventDefault(); }
    if ((e.key === ' ' || e.key === 'Enter') && e.target.matches && e.target.matches('[data-addon]')) { e.preventDefault(); e.target.click(); }
  });
  var lt = 0;
  document.addEventListener('input', function (e) {
    if (!inPage(e)) return;
    var t = e.target;
    if (t.id === 'igSearch') { drawDrop(t.value); return; }
    if (t.id === 'igListQ') { listQ = t.value; clearTimeout(lt); lt = setTimeout(loadList, 300); return; }
    if (!st) return;
    if (t.id === 'igRef') { st.ref = t.value; return; }
    if (t.id === 'igNotes') { st.notes = t.value; return; }
    if (t.id === 'igDiscAmt') { st.discType = t.value ? 'amt' : 'pct'; st.discVal = +t.value || 0; t.closest('.ig-chip-in').classList.toggle('on', !!t.value); document.querySelectorAll('[data-disc]').forEach(function (b) { b.classList.toggle('on', !t.value && +b.dataset.disc === 0); }); drawSide(); return; }
    if (t.dataset.qtyfor) { st.addons[t.dataset.qtyfor] = Math.max(1, Math.min(500, +t.value || 1)); drawSide(); return; }
    if (t.dataset.cf) { var i = +t.closest('[data-ci]').dataset.ci; st.custom[i][t.dataset.cf] = t.value; drawSide(); return; }
  });
  document.addEventListener('change', function (e) {
    if (!inPage(e) || !st) return;
    var t = e.target;
    if (t.id === 'igFrom' && t.value) { st.from = t.value; redraw(); }
    else if (t.id === 'igExtend') { st.extend = t.checked; }
  });
  function redraw() {
    var y = window.scrollY;
    drawMain(); drawSide();
    window.scrollTo(0, y);
  }

  window.saInvoiceGen = { load: load };
})();
