/**
 * Superadmin platform screens (data: api_platform.php; coupons use api.php):
 * Restaurant 360, Analytics, Renewals, Coupons, Leads, Recordings, Support, Announcements, Invoices & GST,
 * Security & activity, Platform health, Settings (save), bulk actions on Restaurants.
 * Page switching stays in dashboard.php; this file loads a page when its sidebar item is clicked.
 */
(function () {
  'use strict';
  var API = 'api_platform.php';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var inr = function (n) { return '₹' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 }); };
  var num = function (n) { return Number(n || 0).toLocaleString('en-IN'); };
  var day = function (d) { if (!d) return '-'; var t = new Date(String(d).replace(' ', 'T')); return isNaN(t) ? esc(d) : t.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }); };
  var when = function (d) { if (!d) return '-'; var t = new Date(String(d).replace(' ', 'T')); return isNaN(t) ? esc(d) : t.toLocaleString('en-IN', Object.assign({ day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }, t.getFullYear() !== new Date().getFullYear() ? { year: 'numeric' } : {})); };
  var ago = function (d) {
    if (!d) return 'never';
    var t = new Date(String(d).replace(' ', 'T')), s = (Date.now() - t) / 1000;
    if (isNaN(s)) return esc(d);
    if (s < 3600) return Math.max(1, Math.round(s / 60)) + ' min ago';
    if (s < 86400) return Math.round(s / 3600) + ' h ago';
    return Math.round(s / 86400) + ' days ago';
  };
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
  function alertMsg(m, type) { if (window.Swal) Swal.fire({ icon: type || 'success', text: m, confirmButtonColor: '#2563eb', timer: type === 'error' ? undefined : 1800, showConfirmButton: type === 'error' }); else alert(m); }
  function fail(e) { alertMsg(e && e.message ? e.message : String(e), 'error'); }
  function confirmIt(m, ok) {
    if (!window.Swal) return Promise.resolve(window.confirm(m));
    return Swal.fire({ text: m, icon: 'question', showCancelButton: true, confirmButtonText: ok || 'Yes', confirmButtonColor: '#2563eb', cancelButtonColor: '#6b7280' }).then(function (r) { return r.isConfirmed; });
  }
  function ask(title, html, preConfirm) {
    return Swal.fire({ title: title, html: html, showCancelButton: true, confirmButtonText: 'Save', confirmButtonColor: '#2563eb', focusConfirm: false, width: 560, preConfirm: preConfirm })
      .then(function (r) { return r.isConfirmed ? r.value : null; });
  }
  var $ = function (id) { return document.getElementById(id); };
  var empty = function (icon, text) { return '<div class="sp-empty"><span class="material-symbols-rounded">' + icon + '</span>' + text + '</div>'; };
  var stat = function (icon, color, label, value, sub) {
    return '<div class="stat-card" data-icon="' + icon + '" style="--c:' + color + '"><div class="stat-label">' + label + '</div><div class="stat-value">' + value + '</div>' + (sub ? '<small>' + sub + '</small>' : '') + '</div>';
  };
  function seg(id, items, cur) {
    return '<div class="sa-seg" id="' + id + '">' + items.map(function (x) {
      return '<button type="button" data-v="' + esc(x[0]) + '"' + (x[0] === cur ? ' class="on"' : '') + '>' + esc(x[1]) + (x[2] != null ? '<em>' + x[2] + '</em>' : '') + '</button>';
    }).join('') + '</div>';
  }
  function bars(rows, key, fmt, color) {
    var max = Math.max.apply(null, rows.map(function (r) { return +r[key] || 0; }).concat([1]));
    return '<div class="sp-bars">' + rows.map(function (r) {
      var v = +r[key] || 0;
      return '<div class="sp-bar" title="' + esc(r.month || r.d) + ': ' + esc(fmt(v)) + '"><i style="height:' + Math.max(2, Math.round(v * 100 / max)) + '%;background:' + color + '"></i><span>' + esc(String(r.month || r.d).split(' ')[0]) + '</span></div>';
    }).join('') + '</div>';
  }

  /* ════════ 2. Analytics ════════ */
  function loadAnalytics() {
    var box = $('spAnalytics');
    get('analytics').then(function (d) {
      var c = d.counts;
      box.innerHTML = '<div class="stats-grid">' +
        stat('payments', '#16a34a', 'Monthly recurring revenue', inr(d.mrr), inr(d.arr) + ' a year · estimate') +
        stat('storefront', '#2563eb', 'Restaurants', num(c.total), num(c.active) + ' active') +
        stat('workspace_premium', '#7c3aed', 'Paying', num(c.paying), num(c.on_trial) + ' on trial') +
        stat('trending_down', '#dc2626', 'Lost in 30 days', num(d.churn_30d), num(c.lapsed) + ' lapsed or disabled in total') +
        '</div><div class="stats-grid">' +
        stat('swap_horiz', '#0891b2', 'Trial to paid', d.conversion + '%', num(d.ever_paid) + ' restaurants have paid') +
        stat('receipt_long', '#d97706', 'Orders today (all)', num(d.today.n), inr(d.today.s) + ' sales') +
        stat('calendar_month', '#2563eb', 'Orders this month', num(d.this_month.n), inr(d.this_month.s) + ' sales') +
        stat('sell', '#16a34a', 'Plan price', inr(d.plan_price), 'per month (Settings)') +
        '</div>' +
        '<div class="sp-cols"><div class="card"><div class="card-header"><div class="card-title">Subscription money received</div><small class="sp-muted">last 12 months</small></div><div class="card-body">' + bars(d.months, 'revenue', inr, '#16a34a') + '</div></div>' +
        '<div class="card"><div class="card-header"><div class="card-title">New restaurants</div><small class="sp-muted">last 12 months</small></div><div class="card-body">' + bars(d.months, 'new', num, '#2563eb') + '</div></div></div>' +
        '<div class="card"><div class="card-header"><div class="card-title">Orders on the platform</div><small class="sp-muted">all restaurants, last 12 months</small></div><div class="card-body">' + bars(d.months, 'orders', num, '#7c3aed') + '</div></div>' +
        '<div class="sp-cols"><div class="card"><div class="card-header"><div class="card-title">Top restaurants</div><small class="sp-muted">sales, last 30 days</small></div><div class="card-body">' +
        (d.top.length ? '<table><thead><tr><th>Restaurant</th><th>Orders</th><th>Sales</th><th>vs before</th></tr></thead><tbody>' + d.top.map(function (r) {
          var prev = +r.prev_orders, cur = +r.cur_orders, ch = prev ? Math.round((cur - prev) * 100 / prev) : null;
          return '<tr><td><a href="#" data-sp360="' + r.id + '">' + esc(r.restaurant_name) + '</a></td><td>' + num(cur) + '</td><td>' + inr(r.cur_sales) + '</td><td>' +
            (ch == null ? '<span class="sp-muted">new</span>' : '<span class="badge ' + (ch >= 0 ? 'badge-success' : 'badge-danger') + '">' + (ch >= 0 ? '+' : '') + ch + '%</span>') + '</td></tr>';
        }).join('') + '</tbody></table>' : empty('bar_chart', 'No orders yet')) + '</div></div>' +
        '<div class="card"><div class="card-header"><div class="card-title">Needs attention</div><small class="sp-muted">no orders in 7 days, or orders halved</small></div><div class="card-body">' +
        (d.at_risk.length ? '<div class="sp-list">' + d.at_risk.map(function (r) {
          return '<div class="sp-li"><span class="sa-ini" style="background:#fef2f2;color:#b91c1c">' + esc((r.restaurant_name || '?').charAt(0)) + '</span><div><b><a href="#" data-sp360="' + r.id + '">' + esc(r.restaurant_name) + '</a></b><small>Last order ' + ago(r.last_order) +
            ' · ' + num(r.cur_orders) + ' orders this month vs ' + num(r.prev_orders) + ' before</small></div></div>';
        }).join('') + '</div>' : empty('verified', 'Every active restaurant is taking orders')) + '</div></div></div>';
    }).catch(function (e) { box.innerHTML = empty('error', esc(e.message)); });
  }

  /* ════════ 3. Renewals ════════ */
  var renewDays = null;
  function loadRenewals() {
    var box = $('spRenewals');
    get('renewals', renewDays ? '&days=' + renewDays : '').then(function (d) {
      renewDays = d.days;
      $('spRenewalsActs').innerHTML = seg('spRenewSeg', [['7', 'Next 7 days'], ['15', '15 days'], ['30', '30 days'], ['60', '60 days']], String(d.days));
      var over = d.rows.filter(function (r) { return +r.days_left < 0; }), soon = d.rows.filter(function (r) { return +r.days_left >= 0; });
      var row = function (r) {
        var dl = +r.days_left;
        return '<tr><td><div class="sa-rest" data-sp360="' + r.id + '"><span class="sa-ini">' + esc((r.restaurant_name || '?').charAt(0)) + '</span><div><b>' + esc(r.restaurant_name) + '</b><small>' + esc(r.owner_name || r.username) + (r.phone ? ' · ' + esc(r.phone) : '') + '</small></div></div></td>' +
          '<td><span class="badge ' + (r.subscription_status === 'active' ? 'badge-success' : 'badge-info') + '">' + esc(r.subscription_status === 'active' ? 'Paid plan' : 'Trial') + '</span></td>' +
          '<td><b>' + day(r.expires_on) + '</b><br><span class="badge ' + (dl < 0 ? 'badge-danger' : dl <= 3 ? 'badge-warning' : 'badge-info') + '">' + (dl < 0 ? 'ended ' + (-dl) + ' days ago' : dl === 0 ? 'ends today' : dl + ' days left') + '</span></td>' +
          '<td>' + (r.last_paid ? day(r.last_paid) : '<span class="sp-muted">never</span>') + (+r.open_links ? '<br><small class="sp-muted">' + r.open_links + ' link sent, unpaid</small>' : '') + '</td>' +
          '<td><div class="sa-acts"><button class="btn btn-primary btn-sm" data-sp-link="' + esc(r.restaurant_id) + '"><span class="material-symbols-rounded">add_link</span>Payment link</button>' +
          (r.phone ? '<button class="btn btn-outline btn-sm" data-sp-remind="' + r.id + '"><span class="material-symbols-rounded">chat</span>Remind</button>' : '') +
          '<button class="btn btn-outline btn-sm" data-sp-extend="' + r.id + '"><span class="material-symbols-rounded">more_time</span>Extend</button></div></td></tr>';
      };
      var table = function (rows) { return '<div style="overflow-x:auto"><table><thead><tr><th>Restaurant</th><th>Plan</th><th>Ends</th><th>Last paid</th><th></th></tr></thead><tbody>' + rows.map(row).join('') + '</tbody></table></div>'; };
      spRenewRows = {}; d.rows.forEach(function (r) { spRenewRows[r.id] = r; });
      box.innerHTML = '<div class="stats-grid">' + stat('event_upcoming', '#d97706', 'Ending in ' + d.days + ' days', num(soon.length)) + stat('event_busy', '#dc2626', 'Ended in the last 30 days', num(over.length)) +
        stat('payments', '#16a34a', 'Possible renewals', inr(soon.length * d.plan_price), 'at ' + inr(d.plan_price) + ' a month') + '</div>' +
        '<div class="card"><div class="card-header"><div class="card-title">Ending soon</div></div><div class="card-body">' + (soon.length ? table(soon) : empty('event_available', 'Nothing ends in the next ' + d.days + ' days')) + '</div></div>' +
        '<div class="card"><div class="card-header"><div class="card-title">Ended recently, not renewed</div></div><div class="card-body">' + (over.length ? table(over) : empty('task_alt', 'Nobody lapsed in the last 30 days')) + '</div></div>';
      setBadge('spRenewBadge', soon.length);
    }).catch(function (e) { box.innerHTML = empty('error', esc(e.message)); });
  }
  var spRenewRows = {};
  function waLink(phone, text) {
    var digits = String(phone || '').replace(/\D/g, '');
    if (digits.length === 10) digits = '91' + digits;
    return 'https://wa.me/' + digits + '?text=' + encodeURIComponent(text);
  }
  function openPaymentLink(rid, amount) {
    var mi = document.querySelector('.menu-item[data-page="paymentLinks"]');
    if (mi) mi.click();
    setTimeout(function () {
      var b = $('btnCreatePaymentLink'); if (b) b.click();
      var tries = 0, t = setInterval(function () {
        var sel = $('cplmRestaurant');
        if (sel && [].some.call(sel.options, function (o) { return o.value === rid; })) {
          clearInterval(t); sel.value = rid; sel.dispatchEvent(new Event('change'));
          if ($('cplmAmount') && amount) $('cplmAmount').value = amount;
          if ($('cplmNotes') && !$('cplmNotes').value) $('cplmNotes').value = 'Monthly subscription renewal';
        } else if (++tries > 30) clearInterval(t);
      }, 150);
    }, 200);
  }

  /* ════════ 4. Coupons (api.php) ════════ */
  var couponRid = '';
  function loadCoupons() {
    var box = $('spCoupons');
    Promise.all([fetch('api.php?action=getCoupons' + (couponRid ? '&restaurant_id=' + encodeURIComponent(couponRid) : ''), { cache: 'no-store' }).then(res), restOptions()]).then(function (all) {
      var rows = all[0].coupons || [], opts = all[1];
      $('spCouponsActs').innerHTML = '<select id="spCouponRest"><option value="">All restaurants</option>' + opts.map(function (o) { return '<option value="' + esc(o.restaurant_id) + '"' + (o.restaurant_id === couponRid ? ' selected' : '') + '>' + esc(o.restaurant_name) + '</option>'; }).join('') +
        '</select><button class="btn btn-primary" id="spCouponNew"><span class="material-symbols-rounded">add</span>New coupon</button>';
      var today = new Date().toISOString().slice(0, 10);
      var live = rows.filter(function (c) { return +c.is_active && (!c.valid_until || c.valid_until >= today); }).length;
      spCouponRows = {}; rows.forEach(function (c) { spCouponRows[c.id] = c; });
      box.innerHTML = '<div class="stats-grid">' + stat('sell', '#2563eb', 'Coupons', num(rows.length)) + stat('check_circle', '#16a34a', 'Running now', num(live)) +
        stat('redeem', '#7c3aed', 'Times used', num(rows.reduce(function (a, c) { return a + (+c.current_uses || 0); }, 0))) + '</div>' +
        '<div class="card"><div class="card-body">' + (rows.length ? '<div style="overflow-x:auto"><table><thead><tr><th>Code</th><th>Restaurant</th><th>Discount</th><th>Min order</th><th>Used</th><th>Valid</th><th>Status</th><th></th></tr></thead><tbody>' + rows.map(function (c) {
          var expired = c.valid_until && c.valid_until < today;
          return '<tr><td><code class="sp-code">' + esc(c.coupon_code) + '</code>' + (c.description ? '<br><small class="sp-muted">' + esc(c.description) + '</small>' : '') + '</td><td>' + esc(c.restaurant_name || c.restaurant_id) + '</td>' +
            '<td><b>' + (c.discount_type === 'percent' ? (+c.discount_value) + '%' : inr(c.discount_value)) + '</b></td><td>' + (+c.minimum_order_amount ? inr(c.minimum_order_amount) : '-') + '</td>' +
            '<td>' + num(c.current_uses) + (+c.max_uses ? ' / ' + num(c.max_uses) : '') + '</td><td>' + (c.valid_from ? day(c.valid_from) : '') + (c.valid_until ? ' → ' + day(c.valid_until) : (c.valid_from ? '' : 'Always')) + '</td>' +
            '<td>' + (expired ? '<span class="badge badge-danger">Expired</span>' : +c.is_active ? '<span class="badge badge-success">On</span>' : '<span class="badge badge-warning">Off</span>') + '</td>' +
            '<td><div class="sa-acts"><button class="btn btn-outline btn-sm" data-sp-cedit="' + c.id + '">Edit</button><button class="btn btn-outline btn-sm" data-sp-ctoggle="' + c.id + '">' + (+c.is_active ? 'Turn off' : 'Turn on') + '</button>' +
            '<button class="sa-more-btn" data-sp-cdel="' + c.id + '" title="Delete" aria-label="Delete"><span class="material-symbols-rounded">delete</span></button></div></td></tr>';
        }).join('') + '</tbody></table></div>' : empty('sell', 'No coupons yet')) + '</div></div>';
    }).catch(function (e) { box.innerHTML = empty('error', esc(e.message)); });
  }
  var spCouponRows = {};
  function couponForm(c) {
    restOptions().then(function (opts) {
      c = c || {};
      var f = function (id, label, input) { return '<div class="sp-f"><label for="' + id + '">' + label + '</label>' + input + '</div>'; };
      ask(c.id ? 'Edit coupon' : 'New coupon',
        '<div class="sp-form">' +
        (c.id ? '' : f('cfRest', 'Restaurant', '<select id="cfRest">' + opts.map(function (o) { return '<option value="' + esc(o.restaurant_id) + '"' + (o.restaurant_id === couponRid ? ' selected' : '') + '>' + esc(o.restaurant_name) + '</option>'; }).join('') + '</select>')) +
        f('cfCode', 'Code', '<input id="cfCode" maxlength="50" value="' + esc(c.coupon_code || '') + '" placeholder="e.g. WELCOME10" style="text-transform:uppercase">') +
        '<div class="sp-grid2">' + f('cfType', 'Type', '<select id="cfType"><option value="percent"' + (c.discount_type !== 'flat' ? ' selected' : '') + '>% off</option><option value="flat"' + (c.discount_type === 'flat' ? ' selected' : '') + '>₹ off</option></select>') +
        f('cfVal', 'Discount', '<input id="cfVal" type="number" min="0" step="0.01" value="' + esc(c.discount_value || '') + '">') +
        f('cfMin', 'Minimum order (₹)', '<input id="cfMin" type="number" min="0" value="' + esc(+c.minimum_order_amount || '') + '">') +
        f('cfMax', 'Max uses (0 = no limit)', '<input id="cfMax" type="number" min="0" value="' + esc(+c.max_uses || 0) + '">') +
        f('cfFrom', 'Valid from', '<input id="cfFrom" type="date" value="' + esc(c.valid_from || '') + '">') + f('cfUntil', 'Valid until', '<input id="cfUntil" type="date" value="' + esc(c.valid_until || '') + '">') + '</div>' +
        f('cfDesc', 'Description (optional)', '<input id="cfDesc" maxlength="255" value="' + esc(c.description || '') + '">') + '</div>',
        function () {
          var v = { coupon_code: $('cfCode').value.trim().toUpperCase(), discount_type: $('cfType').value, discount_value: +$('cfVal').value, minimum_order_amount: +$('cfMin').value || 0,
            max_uses: +$('cfMax').value || 0, valid_from: $('cfFrom').value || null, valid_until: $('cfUntil').value || null, description: $('cfDesc').value.trim() };
          if (!/^[A-Z0-9_-]{3,50}$/.test(v.coupon_code)) { Swal.showValidationMessage('Code: 3 to 50 letters or numbers'); return false; }
          if (!(v.discount_value > 0) || (v.discount_type === 'percent' && v.discount_value > 100)) { Swal.showValidationMessage('Enter a discount (1 to 100 for %)'); return false; }
          if (v.valid_from && v.valid_until && v.valid_until < v.valid_from) { Swal.showValidationMessage('Valid until is before valid from'); return false; }
          if (c.id) v.id = c.id; else v.restaurant_id = $('cfRest').value;
          return v;
        }).then(function (v) {
        if (!v) return;
        fetch('api.php?action=' + (v.id ? 'updateCoupon' : 'createCoupon'), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(v) }).then(res)
          .then(function () { alertMsg(v.id ? 'Coupon saved' : 'Coupon created'); loadCoupons(); }).catch(fail);
      });
    });
  }

  /* ════════ 5. Leads ════════ */
  var leadStage = 'all';
  var STAGES = [['new', 'New'], ['contacted', 'Contacted'], ['demo', 'Demo done'], ['won', 'Won'], ['lost', 'Lost']];
  var stageName = function (s) { var x = STAGES.filter(function (a) { return a[0] === s; })[0]; return x ? x[1] : s; };
  var spLeadRows = {};
  function loadLeads() {
    var box = $('spLeads');
    return get('leads', '&stage=' + leadStage).then(function (d) {
      var total = 0; for (var k in d.counts) total += +d.counts[k];
      var rc = d.recordings || { files: 0, leads: 0 };
      if (rc.chunk_bytes) recChunk = +rc.chunk_bytes;
      $('spLeadsActs').innerHTML = (rc.files ? '<a class="btn btn-outline" href="' + API + '?action=downloadLeadRecordings&scope=leads" download title="One ZIP with a folder for every lead you called"><span class="material-symbols-rounded">download</span>Download all recordings <em class="sp-cnt">' + num(rc.files) + '</em></a>'
        : '<button class="btn btn-outline" type="button" disabled title="Upload call recordings on a lead first"><span class="material-symbols-rounded">download</span>Download all recordings</button>') +
        '<button class="btn btn-primary" id="spLeadNew"><span class="material-symbols-rounded">person_add</span>Add lead</button>';
      spLeadRows = {}; d.rows.forEach(function (r) { spLeadRows[r.id] = r; });
      var today = new Date().toISOString().slice(0, 10);
      box.innerHTML = '<div class="card"><div class="card-header">' + seg('spLeadSeg', [['all', 'All', total]].concat(STAGES.map(function (s) { return [s[0], s[1], +(d.counts[s[0]] || 0)]; })), leadStage) + '</div><div class="card-body">' +
        (d.rows.length ? '<div class="sp-list">' + d.rows.map(function (r) {
          var due = r.follow_up_on && r.follow_up_on <= today && ['won', 'lost'].indexOf(r.stage) < 0;
          return '<div class="sp-li sp-lead"><span class="sa-ini">' + esc((r.name || '?').charAt(0).toUpperCase()) + '</span><div><b>' + esc(r.name) + (r.restaurant_name_hint ? ' <span class="sp-muted">· ' + esc(r.restaurant_name_hint) + '</span>' : '') + '</b>' +
            '<small>' + [r.phone, r.email].filter(Boolean).map(esc).join(' · ') + ' · ' + when(r.created_at) + '</small>' + (r.message ? '<p>' + esc(r.message) + '</p>' : '') + (r.lead_notes ? '<p class="sp-note"><span class="material-symbols-rounded">sticky_note_2</span>' + esc(r.lead_notes) + '</p>' : '') + '</div>' +
            '<div class="sp-lead-r"><span class="badge ' + ({ new: 'badge-info', won: 'badge-success', lost: 'badge-danger' }[r.stage] || 'badge-warning') + '">' + esc(stageName(r.stage)) + '</span>' +
            (r.follow_up_on ? '<small class="' + (due ? 'sp-due' : 'sp-muted') + '">Follow up ' + day(r.follow_up_on) + '</small>' : '') +
            '<div class="sa-acts">' + (r.phone ? '<a class="sa-more-btn" href="tel:' + esc(r.phone) + '" title="Call"><span class="material-symbols-rounded">call</span></a><a class="sa-more-btn" target="_blank" rel="noopener" href="' + waLink(r.phone, 'Hi ' + r.name + ', this is RestroGrow. Thanks for your enquiry!') + '" title="WhatsApp"><span class="material-symbols-rounded">chat</span></a>' : '') +
            '<button class="btn btn-outline btn-sm" data-sp-rec="' + r.id + '" title="Call recordings"><span class="material-symbols-rounded">phone</span>' + (r.recordings.length ? r.recordings.length : 'Add') + '</button>' +
            '<button class="btn btn-outline btn-sm" data-sp-lead="' + r.id + '">Update</button>' + (r.stage !== 'won' ? '<button class="btn btn-primary btn-sm" data-sp-convert="' + r.id + '">Create restaurant</button>' : '') + '</div></div></div>';
        }).join('') + '</div>' : empty('person_search', leadStage === 'all' ? 'No enquiries yet. Website contact form messages show up here.' : 'No leads in this stage')) + '</div></div>';
      setBadge('spLeadBadge', +(d.counts.new || 0));
    }).catch(function (e) { box.innerHTML = empty('error', esc(e.message)); });
  }

  /* call recordings on a lead: upload many at once, play, download, delete */
  var recLead = 0, recBusy = false, recChunk = 1048576;
  var REC_ACCEPT = 'audio/*,.mp3,.m4a,.aac,.wav,.ogg,.oga,.opus,.amr,.3gp,.webm,.flac,.wma,.mp4';
  var fsize = function (b) { b = +b || 0; return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; };
  function recHtml(r) {
    var list = r.recordings || [];
    return '<div class="sp-rec">' +
      '<label class="sp-rec-drop" id="spRecDrop"><span class="material-symbols-rounded">upload</span><b>Upload call recordings</b><small>Pick or drop one or many audio files (mp3, m4a, wav, amr, ogg…)</small>' +
      '<input type="file" id="spRecFile" multiple accept="' + REC_ACCEPT + '" hidden></label>' +
      '<div class="sp-rec-status" id="spRecStatus"></div>' +
      (list.length ? '<div class="sp-rec-list">' + list.map(function (x) {
        return '<div class="sp-rec-item"><div class="sp-rec-top"><span class="material-symbols-rounded">call</span><b title="' + esc(x.original_name) + '">' + esc(x.original_name) + '</b>' +
          '<small>' + fsize(x.size_bytes) + ' · ' + when(x.created_at) + '</small>' +
          '<a class="sa-more-btn" href="' + API + '?action=leadRecording&dl=1&id=' + x.id + '" download title="Download"><span class="material-symbols-rounded">download</span></a>' +
          '<button class="sa-more-btn" type="button" data-sp-recdel="' + x.id + '" title="Delete"><span class="material-symbols-rounded">delete</span></button></div>' +
          '<audio controls preload="none" src="' + API + '?action=leadRecording&id=' + x.id + '"></audio></div>';
      }).join('') + '</div>' +
      (list.length > 1 ? '<a class="btn btn-outline btn-sm sp-rec-all" href="' + API + '?action=downloadLeadRecordings&lead_id=' + r.id + '" download><span class="material-symbols-rounded">download</span>Download these ' + list.length + ' as ZIP</a>' : '')
      : '<p class="sp-muted sp-rec-none">No recordings for this lead yet.</p>') + '</div>';
  }
  function openRecordings(id) {
    var r = spLeadRows[id]; if (!r) return;
    recLead = id;
    Swal.fire({ title: 'Call recordings', html: '<div class="sp-rec-who">' + esc(r.name) + (r.restaurant_name_hint ? ' · ' + esc(r.restaurant_name_hint) : '') + (r.phone ? ' · ' + esc(r.phone) : '') + '</div><div id="spRecBody">' + recHtml(r) + '</div>',
      showConfirmButton: false, showCloseButton: true, width: 640, willClose: function () { recLead = 0; } });
  }
  function redrawRecordings() {
    return loadLeads().then(function () { var b = $('spRecBody'); if (b && recLead && spLeadRows[recLead]) b.innerHTML = recHtml(spLeadRows[recLead]); });
  }
  function uploadRecordings(files) {
    if (!recLead) return;
    uploadAudio(files, recLead, 'spRecStatus', redrawRecordings);
  }
  // shared by a lead's recordings window and the Recordings page; leadId 0 = no lead
  function uploadAudio(files, lead, statusId, after) {
    if (recBusy || !files || !files.length) return;
    files = Array.prototype.slice.call(files);
    var done = 0, errors = [];
    recBusy = true;
    var show = function (txt) { var s = $(statusId); if (s) s.innerHTML = txt; };
    // one file per request so a big batch never hits the server's total upload limit
    var next = function (i) {
      if (i >= files.length) {
        recBusy = false;
        return Promise.resolve(after()).then(function () {
          show((done ? '<span class="sp-ok">' + done + ' uploaded</span>' : '') + errors.map(function (m) { return '<span class="sp-err">' + esc(m) + '</span>'; }).join(''));
        });
      }
      var file = files[i], uid = '';
      for (var k = 0; k < 24; k++) uid += '0123456789abcdef'.charAt(Math.floor(Math.random() * 16));
      // no size limit: the file goes up in pieces the server joins back together
      var piece = function (offset, tries) {
        show('<span class="sp-busy">Uploading ' + (i + 1) + ' of ' + files.length + ': ' + esc(file.name) + (file.size > recChunk ? ' · ' + Math.floor(offset * 100 / file.size) + '%' : '') + '…</span>');
        var end = Math.min(file.size, offset + recChunk);
        var fd = new FormData();
        fd.append('lead_id', lead); fd.append('name', file.name); fd.append('upload_id', uid);
        fd.append('offset', offset); fd.append('total', file.size); fd.append('file', file.slice(offset, end), file.name);
        return fetch(API + '?action=uploadLeadRecording', { method: 'POST', body: fd }).then(res, function (e) {
          // network dropped: try the same piece again a few times
          if (tries < 3) return new Promise(function (ok) { setTimeout(ok, 2000 * (tries + 1)); }).then(function () { return piece(offset, tries + 1); }).then(function () { return { done: true }; });
          throw e;
        }).then(function (d) { if (!d.done && d.received != null) return piece(d.received, 0); });
      };
      return piece(0, 0)
        .then(function () { done++; }, function (e) { errors.push(file.name + ': ' + e.message); })
        .then(function () { return next(i + 1); });
    };
    next(0);
  }

  /* ════════ Recordings page: all call recordings, with or without a lead ════════ */
  var recsQ = '', recsTimer = 0, recsRows = {}, recsLeads = [], recsUploadLead = '';
  var leadLabel = function (l) { return [l.name, l.restaurant_name_hint, l.phone].filter(Boolean).join(' · '); };
  function loadRecordings() {
    var box = $('spRecs');
    return get('recordings', '&q=' + encodeURIComponent(recsQ)).then(function (d) {
      recsRows = {}; d.rows.forEach(function (r) { recsRows[r.id] = r; });
      recsLeads = d.leads || [];
      if (d.chunk_bytes) recChunk = +d.chunk_bytes;
      if (!$('spRecsSearch')) {
        $('spRecsActs').innerHTML = '<input id="spRecsSearch" type="search" placeholder="Search file, lead or phone" aria-label="Search recordings" value="' + esc(recsQ) + '">' +
          '<a class="btn btn-primary" id="spRecsAll" href="' + API + '?action=downloadLeadRecordings" download><span class="material-symbols-rounded">download</span>Download all <em class="sp-cnt"></em></a>';
      }
      var all = $('spRecsAll');
      all.querySelector('em').textContent = num(d.total);
      all.classList.toggle('sp-off', !d.total);
      var leadOpts = '<option value="">No lead</option>' + recsLeads.map(function (l) { return '<option value="' + l.id + '"' + (String(l.id) === recsUploadLead ? ' selected' : '') + '>' + esc(leadLabel(l)) + '</option>'; }).join('');
      box.innerHTML = '<div class="card"><div class="card-body sp-rec">' +
        '<label class="sp-rec-drop" id="spRecsDrop"><span class="material-symbols-rounded">upload</span><b>Upload recordings</b><small>Pick or drop as many audio files as you like (mp3, m4a, wav, amr, ogg…). Any size</small>' +
        '<input type="file" id="spRecsFile" multiple accept="' + REC_ACCEPT + '" hidden></label>' +
        '<div class="sp-rec-link"><label for="spRecsLead">Link new uploads to a lead (optional)</label><select id="spRecsLead">' + leadOpts + '</select></div>' +
        '<div class="sp-rec-status" id="spRecsStatus"></div></div></div>' +
        '<div class="card"><div class="card-header"><div class="card-title">' + num(d.total) + ' recordings <small class="sp-muted">· ' + fsize(d.bytes) + ' on the server</small></div>' +
        (d.rows.length ? '<div class="sp-rec-bulk"><label class="sp-rec-ckall"><input type="checkbox" id="spRecsCkAll"> All</label><a class="btn btn-outline btn-sm sp-off" id="spRecsSel" download><span class="material-symbols-rounded">download</span>Download selected <em class="sp-cnt">0</em></a></div>' : '') +
        '</div><div class="card-body">' +
        (d.rows.length ? '<div class="sp-rec-list sp-rec-page">' + d.rows.map(function (x) {
          return '<div class="sp-rec-item"><div class="sp-rec-top"><input type="checkbox" class="sp-rec-ck" value="' + x.id + '" aria-label="Select"><span class="material-symbols-rounded">call</span>' +
            '<div class="sp-rec-name"><b title="' + esc(x.original_name) + '">' + esc(x.original_name) + '</b>' +
            '<small>' + (x.lead_name != null ? '<span class="sp-rec-tag">' + esc(leadLabel({ name: x.lead_name, restaurant_name_hint: x.restaurant_name_hint, phone: x.lead_phone })) + '</span>' : '') + fsize(x.size_bytes) + ' · ' + when(x.created_at) + '</small></div>' +
            '<button class="sa-more-btn" type="button" data-sp-recmove="' + x.id + '" title="' + (+x.lead_id ? 'Change lead' : 'Link to a lead') + '"><span class="material-symbols-rounded">person_add</span></button>' +
            '<a class="sa-more-btn" href="' + API + '?action=leadRecording&dl=1&id=' + x.id + '" download title="Download"><span class="material-symbols-rounded">download</span></a>' +
            '<button class="sa-more-btn" type="button" data-sp-recdel="' + x.id + '" data-sp-recpage="1" title="Delete"><span class="material-symbols-rounded">delete</span></button></div>' +
            '<audio controls preload="none" src="' + API + '?action=leadRecording&id=' + x.id + '"></audio></div>';
        }).join('') + '</div>' : empty('phone', recsQ ? 'No recordings match your search' : 'No recordings yet. Upload your call recordings above.')) + '</div></div>';
      var s = $('spRecsSearch');
      if (s && !s.dataset.bound) {
        s.dataset.bound = '1';
        s.addEventListener('input', function () { recsQ = s.value.trim(); clearTimeout(recsTimer); recsTimer = setTimeout(loadRecordings, 300); });
      }
    }).catch(function (e) { box.innerHTML = empty('error', esc(e.message)); });
  }
  function recsSelection() {
    var ids = Array.prototype.map.call(document.querySelectorAll('.sp-rec-ck:checked'), function (c) { return c.value; });
    var b = $('spRecsSel'); if (!b) return;
    b.querySelector('em').textContent = ids.length;
    b.classList.toggle('sp-off', !ids.length);
    b.href = ids.length ? API + '?action=downloadLeadRecordings&ids=' + ids.join(',') : '#';
    var all = $('spRecsCkAll'), n = document.querySelectorAll('.sp-rec-ck').length;
    if (all) { all.checked = n > 0 && ids.length === n; all.indeterminate = ids.length > 0 && ids.length < n; }
  }
  function moveRecording(id) {
    var x = recsRows[id]; if (!x) return;
    Swal.fire({ title: 'Link to a lead', input: 'select', inputOptions: new Map([['0', 'No lead']].concat(recsLeads.map(function (l) { return [String(l.id), leadLabel(l)]; }))),
      inputValue: String(+x.lead_id || 0), showCancelButton: true, confirmButtonText: 'Save', confirmButtonColor: '#2563eb' })
      .then(function (r) { if (r.isConfirmed) post('moveRecording', { id: id, lead_id: +r.value }).then(function (d) { alertMsg(d.message); loadRecordings(); }).catch(fail); });
  }
  function leadForm(r) {
    r = r || {};
    var f = function (id, label, input) { return '<div class="sp-f"><label for="' + id + '">' + label + '</label>' + input + '</div>'; };
    ask(r.id ? 'Update lead' : 'Add lead', '<div class="sp-form">' +
      (r.id ? '' : '<div class="sp-grid2">' + f('lfName', 'Name', '<input id="lfName" maxlength="160">') + f('lfPhone', 'Phone', '<input id="lfPhone" maxlength="20">') + '</div>' + f('lfEmail', 'Email', '<input id="lfEmail" type="email">')) +
      '<div class="sp-grid2">' + f('lfStage', 'Stage', '<select id="lfStage">' + STAGES.map(function (s) { return '<option value="' + s[0] + '"' + ((r.stage || 'new') === s[0] ? ' selected' : '') + '>' + s[1] + '</option>'; }).join('') + '</select>') +
      f('lfFu', 'Follow up on', '<input id="lfFu" type="date" value="' + esc(r.follow_up_on || '') + '">') + '</div>' +
      f('lfRest', 'Restaurant name', '<input id="lfRest" maxlength="160" value="' + esc(r.restaurant_name_hint || '') + '">') +
      f('lfNotes', 'Notes', '<textarea id="lfNotes" rows="3">' + esc(r.lead_notes || '') + '</textarea>') + '</div>',
      function () {
        var v = { id: r.id || 0, stage: $('lfStage').value, follow_up_on: $('lfFu').value, restaurant_name_hint: $('lfRest').value, lead_notes: $('lfNotes').value };
        if (!r.id) { v.name = $('lfName').value.trim(); v.phone = $('lfPhone').value.trim(); v.email = $('lfEmail').value.trim(); if (!v.name) { Swal.showValidationMessage('Name is required'); return false; } }
        return v;
      }).then(function (v) { if (v) post('saveLead', v).then(function () { alertMsg('Lead saved'); loadLeads(); }).catch(fail); });
  }
  function convertLead(r) {
    // opens the normal New restaurant form, prefilled; the lead is marked Won when the restaurant appears
    var mi = document.querySelector('.menu-item[data-page="restaurants"]'); if (mi) mi.click();
    setTimeout(function () {
      var b = $('btnNew'); if (b) b.click();
      var m = document.querySelector('#createModal');
      if (m) {
        var set = function (sel, v) { var el = m.querySelector(sel); if (el && v && !el.value) el.value = v; };
        set('#cmName', r.restaurant_name_hint || r.name);
        set('#cmEmail', r.email);
      }
      post('saveLead', { id: r.id, stage: 'demo', follow_up_on: r.follow_up_on, restaurant_name_hint: r.restaurant_name_hint, lead_notes: r.lead_notes }).catch(function () {});
      alertMsg('Fill in the login and create the restaurant. Then mark the lead as Won.', 'info');
    }, 250);
  }

  /* ════════ 6. Website chatbot leads ════════ */
  var chatLeadStatus = 'all', chatLeadQuery = '', selectedChatLead = 0, chatLeadSearchTimer = 0;
  var CHAT_STATUSES = [['new', 'New'], ['contacted', 'Contacted'], ['qualified', 'Qualified'], ['closed', 'Closed']];
  function chatStatusName(status) {
    var row = CHAT_STATUSES.filter(function (x) { return x[0] === status; })[0];
    return row ? row[1] : status;
  }
  function loadChatLeads() {
    var box = $('spChatLeads');
    if (!box) return;
    if (!$('spChatLeadSearch')) {
      $('spChatLeadsActs').innerHTML = '<input id="spChatLeadSearch" type="search" placeholder="Search name, restaurant, email or phone" value="' + esc(chatLeadQuery) + '" aria-label="Search chat leads"><button class="btn btn-outline" id="spChatLeadRefresh" type="button"><span class="material-symbols-rounded">refresh</span>Refresh</button>';
    }
    box.innerHTML = '<div class="sp-loading">Loading chat leads…</div>';
    $('spChatConversation').innerHTML = '';
    selectedChatLead = 0;
    get('chatLeads', '&status=' + encodeURIComponent(chatLeadStatus) + '&q=' + encodeURIComponent(chatLeadQuery)).then(function (d) {
      var total = 0;
      Object.keys(d.counts || {}).forEach(function (key) { total += +d.counts[key]; });
      setBadge('spChatLeadBadge', +(d.counts.new || 0));
      var filters = [['all', 'All', total]].concat(CHAT_STATUSES.map(function (s) { return [s[0], s[1], +(d.counts[s[0]] || 0)]; }));
      box.innerHTML = '<div class="card"><div class="card-header">' + seg('spChatLeadSeg', filters, chatLeadStatus) + '</div><div class="card-body">' +
        (d.rows.length ? '<div class="sp-list">' + d.rows.map(function (r) {
          var badge = { new: 'badge-info', contacted: 'badge-warning', qualified: 'badge-success', closed: 'badge-danger' }[r.status] || 'badge-info';
          return '<div class="sp-li sp-lead sp-chat-lead"><span class="sa-ini">' + esc((r.name || '?').charAt(0).toUpperCase()) + '</span><div><b>' + esc(r.name) + ' <span class="sp-muted">· ' + esc(r.restaurant_name) + '</span></b>' +
            '<small>' + esc(r.phone) + ' · ' + esc(r.email) + ' · ' + when(r.updated_at) + '</small><p class="sp-chat-preview">' + esc(r.last_message || 'No messages yet') + '</p></div>' +
            '<div class="sp-lead-r"><span class="badge ' + badge + '">' + esc(chatStatusName(r.status)) + '</span><small>' + num(r.message_count) + ' messages</small>' + (+r.voice_count ? '<small class="sp-voice-tag">Voice call · ' + num(r.voice_count) + ' lines</small>' : '') +
            '<button class="btn btn-outline btn-sm" type="button" data-sp-chat-open="' + (+r.id) + '">View conversation</button></div></div>';
        }).join('') + '</div>' : empty('forum', chatLeadQuery ? 'No chat leads match your search' : 'No website chat leads yet')) + '</div></div>';
      var search = $('spChatLeadSearch');
      search.addEventListener('input', function () {
        chatLeadQuery = search.value.trim();
        window.clearTimeout(chatLeadSearchTimer);
        chatLeadSearchTimer = window.setTimeout(loadChatLeads, 300);
      });
    }).catch(function (e) { box.innerHTML = empty('error', esc(e.message)); });
  }
  function showChatConversation(id) {
    var box = $('spChatConversation');
    selectedChatLead = id;
    box.innerHTML = '<div class="card"><div class="card-body"><div class="sp-loading">Loading conversation…</div></div></div>';
    get('chatConversation', '&id=' + encodeURIComponent(id)).then(function (d) {
      var lead = d.lead;
      var options = CHAT_STATUSES.map(function (s) {
        return '<option value="' + s[0] + '"' + (s[0] === lead.status ? ' selected' : '') + '>' + s[1] + '</option>';
      }).join('');
      var transcript = d.messages.length ? d.messages.map(function (m) {
        var voice = m.channel === 'voice' ? ' <em class="sp-voice-tag">voice call</em>' : '';
        return '<div class="sp-chat-message ' + (m.sender === 'visitor' ? 'visitor' : 'assistant') + (voice ? ' voice' : '') + '"><span>' + (m.sender === 'visitor' ? 'Visitor' : 'RestroGrow') + voice + '</span><p>' + esc(m.message) + '</p><small>' + when(m.created_at) + '</small></div>';
      }).join('') : empty('chat', 'This conversation has no messages');
      box.innerHTML = '<div class="card sp-chat-detail"><div class="card-header"><div><div class="card-title">' + esc(lead.name) + ' · ' + esc(lead.restaurant_name) + '</div><small class="sp-muted">Lead #' + (+lead.id) + ' · Started ' + when(lead.created_at) + '</small></div>' +
        '<label class="sp-chat-status">Lead status <select id="spChatLeadStatus" data-chat-lead="' + (+lead.id) + '">' + options + '</select></label></div>' +
        '<div class="card-body"><div class="sp-kv sp-chat-contact"><span>Email</span><b><a href="mailto:' + encodeURIComponent(lead.email) + '">' + esc(lead.email) + '</a></b><span>Phone</span><b><a href="tel:' + encodeURIComponent(lead.phone) + '">' + esc(lead.phone) + '</a></b><span>Follow-up consent</span><b>Given · ' + when(lead.consent_at) + '</b></div>' +
        '<div class="sp-chat-transcript" aria-label="Chat transcript">' + transcript + '</div></div></div>';
      box.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }).catch(function (e) { box.innerHTML = empty('error', esc(e.message)); });
  }

  /* ════════ 10. Support ════════ */
  var ticketStatus = 'active', openTicket = 0;
  var TSTAT = { open: ['Needs reply', 'badge-danger'], waiting: ['Waiting on us', 'badge-warning'], answered: ['Replied', 'badge-success'], closed: ['Closed', 'badge-info'] };
  function loadSupport() {
    var box = $('spSupport');
    get('tickets', '&status=' + ticketStatus).then(function (d) {
      var c = d.counts || {};
      box.innerHTML = '<div class="sp-split"><div class="card sp-inbox"><div class="card-header">' + seg('spTicketSeg', [['active', 'To answer', (+(c.open || 0)) + (+(c.waiting || 0))], ['answered', 'Replied', +(c.answered || 0)], ['closed', 'Closed', +(c.closed || 0)], ['all', 'All']], ticketStatus) + '</div><div class="card-body">' +
        (d.rows.length ? '<div class="sp-list">' + d.rows.map(function (t) {
          return '<button type="button" class="sp-li sp-ticket' + (+t.id === openTicket ? ' on' : '') + '" data-sp-ticket="' + t.id + '">' + (+t.unread_admin ? '<span class="pn-dot"></span>' : '') + '<div><b>' + esc(t.subject) + '</b><small>' + esc(t.restaurant_name || t.restaurant_id) + ' · ' + ago(t.updated_at) + '</small><small>' + esc((t.last_message || '').slice(0, 90)) + '</small></div>' +
            '<span class="badge ' + (TSTAT[t.status] || ['', 'badge-info'])[1] + '">' + esc((TSTAT[t.status] || [t.status])[0]) + '</span></button>';
        }).join('') + '</div>' : empty('support_agent', 'No requests here. Restaurants send them from Help & support in their dashboard.')) + '</div></div><div class="card sp-thread" id="spThread">' + empty('forum', 'Pick a request on the left') + '</div></div>';
      setBadge('spTicketBadge', d.unread);
      if (openTicket) showTicket(openTicket);
    }).catch(function (e) { box.innerHTML = empty('error', esc(e.message)); });
  }
  function showTicket(id) {
    openTicket = id;
    var box = $('spThread');
    if (!box) return;
    document.querySelectorAll('.sp-ticket').forEach(function (b) { b.classList.toggle('on', +b.dataset.spTicket === id); });
    get('ticket', '&id=' + id).then(function (d) {
      var t = d.ticket;
      var dot = document.querySelector('.sp-ticket[data-sp-ticket="' + id + '"] .pn-dot'); if (dot) dot.remove();
      box.innerHTML = '<div class="card-header"><div><div class="card-title">' + esc(t.subject) + '</div><small class="sp-muted"><a href="#" data-sp360="' + t.user_id + '">' + esc(t.restaurant_name || t.restaurant_id) + '</a> · ' + esc(t.category) + ' · opened ' + when(t.created_at) + (t.phone ? ' · ' + esc(t.phone) : '') + '</small></div>' +
        '<select id="spTicketStatus">' + Object.keys(TSTAT).map(function (k) { return '<option value="' + k + '"' + (t.status === k ? ' selected' : '') + '>' + TSTAT[k][0] + '</option>'; }).join('') + '</select></div>' +
        '<div class="card-body"><div class="pn-msgs">' + d.messages.map(function (m) {
          return '<div class="pn-m' + (m.sender === 'superadmin' ? ' me' : '') + '">' + esc(m.body) + '<small>' + esc(m.sender === 'superadmin' ? 'You (RestroGrow support)' : (m.sender_name || 'Restaurant')) + ' · ' + when(m.created_at) + '</small></div>';
        }).join('') + '</div>' +
        '<details class="sp-ctx"><summary>Their recent orders and errors</summary><div class="sp-cols">' +
        '<div>' + (d.recent_orders.length ? d.recent_orders.map(function (o) { return '<div class="sp-mini"><b>#' + esc(o.order_number) + '</b> ' + esc(o.order_status) + ' · ' + inr(o.total) + ' · ' + when(o.created_at) + '</div>'; }).join('') : '<div class="sp-muted">No orders</div>') + '</div>' +
        '<div>' + (d.recent_errors.length ? d.recent_errors.map(function (e) { return '<div class="sp-mini"><span class="badge ' + (e.severity === 'warning' ? 'badge-warning' : 'badge-danger') + '">' + esc(e.severity) + '</span> ' + esc(String(e.message).slice(0, 120)) + ' · ' + when(e.created_at) + '</div>'; }).join('') : '<div class="sp-muted">No errors</div>') + '</div></div></details></div>' +
        '<div class="sp-reply"><textarea id="spReplyBody" rows="3" placeholder="Write your reply. The restaurant sees it in Help & support."></textarea><div class="sa-acts"><button class="btn btn-outline" data-sp-reply="closed">Reply and close</button><button class="btn btn-primary" data-sp-reply="answered"><span class="material-symbols-rounded">send</span>Send reply</button></div></div>';
      var mb = box.querySelector('.pn-msgs'); if (mb) mb.scrollTop = mb.scrollHeight;
    }).catch(function (e) { box.innerHTML = empty('error', esc(e.message)); });
  }

  /* ════════ 9. Announcements ════════ */
  var LEVELS = { info: ['Information', '#2563eb'], success: ['Good news', '#16a34a'], warning: ['Warning', '#d97706'], danger: ['Urgent', '#dc2626'] };
  var spAnnRows = {};
  function loadAnnouncements() {
    var box = $('spAnnouncements');
    $('spAnnouncementsActs').innerHTML = '<button class="btn btn-primary" id="spAnnNew"><span class="material-symbols-rounded">add</span>New announcement</button>';
    Promise.all([get('announcements'), restOptions()]).then(function (all) {
      var rows = all[0].rows, opts = all[1], nameOf = {};
      opts.forEach(function (o) { nameOf[o.restaurant_id] = o.restaurant_name; });
      spAnnRows = {}; rows.forEach(function (a) { spAnnRows[a.id] = a; });
      var now = new Date();
      box.innerHTML = rows.length ? '<div class="sp-list">' + rows.map(function (a) {
        var ended = a.ends_at && new Date(a.ends_at.replace(' ', 'T')) < now, future = a.starts_at && new Date(a.starts_at.replace(' ', 'T')) > now;
        var state = !+a.is_active ? ['Stopped', 'badge-warning'] : ended ? ['Ended', 'badge-info'] : future ? ['Scheduled', 'badge-info'] : ['Showing now', 'badge-success'];
        var to = +a.target_all ? 'All restaurants' : String(a.target_ids || '').split(',').filter(Boolean).map(function (id) { return nameOf[id] || id; }).join(', ');
        return '<div class="card sp-ann" style="border-left:4px solid ' + (LEVELS[a.level] || LEVELS.info)[1] + '"><div class="card-body"><div class="sp-ann-h"><b>' + esc(a.title) + '</b><span class="badge ' + state[1] + '">' + state[0] + '</span></div>' +
          (a.body ? '<p>' + esc(a.body) + '</p>' : '') + '<small class="sp-muted">' + esc((LEVELS[a.level] || LEVELS.info)[0]) + ' · to ' + esc(to) + (a.starts_at ? ' · from ' + when(a.starts_at) : '') + (a.ends_at ? ' · until ' + when(a.ends_at) : '') + ' · closed by ' + num(a.dismissed) + ' · by ' + esc(a.created_by || '-') + '</small>' +
          '<div class="sa-acts" style="margin-top:10px"><button class="btn btn-outline btn-sm" data-sp-ann="' + a.id + '">Edit</button><button class="btn btn-outline btn-sm" data-sp-annt="' + a.id + '">' + (+a.is_active ? 'Stop showing' : 'Show again') + '</button>' +
          '<button class="sa-more-btn" data-sp-annd="' + a.id + '" title="Delete" aria-label="Delete"><span class="material-symbols-rounded">delete</span></button></div></div></div>';
      }).join('') + '</div>' : '<div class="card"><div class="card-body">' + empty('campaign', 'No announcements yet. They appear as a bar at the top of restaurant dashboards: new features, maintenance, payment reminders.') + '</div></div>';
    }).catch(function (e) { box.innerHTML = empty('error', esc(e.message)); });
  }
  function annForm(a, presetIds) {
    restOptions().then(function (opts) {
      a = a || {};
      var ids = presetIds || String(a.target_ids || '').split(',').filter(Boolean);
      var toAll = presetIds ? false : (a.id ? +a.target_all === 1 : true);
      var dt = function (v) { return v ? String(v).replace(' ', 'T').slice(0, 16) : ''; };
      var f = function (id, label, input) { return '<div class="sp-f"><label for="' + id + '">' + label + '</label>' + input + '</div>'; };
      ask(a.id ? 'Edit announcement' : 'New announcement', '<div class="sp-form">' +
        f('afTitle', 'Title', '<input id="afTitle" maxlength="160" value="' + esc(a.title || '') + '" placeholder="e.g. New: online payments are here">') +
        f('afBody', 'Message (optional)', '<textarea id="afBody" rows="3" maxlength="2000">' + esc(a.body || '') + '</textarea>') +
        '<div class="sp-grid2">' + f('afLevel', 'Type', '<select id="afLevel">' + Object.keys(LEVELS).map(function (k) { return '<option value="' + k + '"' + ((a.level || 'info') === k ? ' selected' : '') + '>' + LEVELS[k][0] + '</option>'; }).join('') + '</select>') +
        f('afDis', 'Can they close it?', '<select id="afDis"><option value="1"' + (a.dismissible !== '0' && a.dismissible !== 0 ? ' selected' : '') + '>Yes</option><option value="0"' + (a.dismissible === '0' || a.dismissible === 0 ? ' selected' : '') + '>No, keep it showing</option></select>') +
        f('afFrom', 'Show from (optional)', '<input id="afFrom" type="datetime-local" value="' + esc(dt(a.starts_at)) + '">') + f('afUntil', 'Until (optional)', '<input id="afUntil" type="datetime-local" value="' + esc(dt(a.ends_at)) + '">') + '</div>' +
        f('afTo', 'Show to', '<select id="afTo"><option value="all"' + (toAll ? ' selected' : '') + '>All restaurants</option><option value="some"' + (!toAll ? ' selected' : '') + '>Only the ones I pick</option></select>') +
        '<div id="afPick" class="sp-pick"' + (toAll ? ' hidden' : '') + '>' + opts.map(function (o) { return '<label><input type="checkbox" value="' + esc(o.restaurant_id) + '"' + (ids.indexOf(o.restaurant_id) >= 0 ? ' checked' : '') + '> ' + esc(o.restaurant_name) + '</label>'; }).join('') + '</div></div>',
        function () {
          var some = $('afTo').value === 'some';
          var picked = [].map.call(document.querySelectorAll('#afPick input:checked'), function (x) { return x.value; });
          var v = { id: a.id || 0, title: $('afTitle').value.trim(), body: $('afBody').value.trim(), level: $('afLevel').value, dismissible: $('afDis').value === '1', starts_at: $('afFrom').value, ends_at: $('afUntil').value,
            target_all: !some, target_ids: some ? picked : [], is_active: a.id ? +a.is_active === 1 : true };
          if (!v.title) { Swal.showValidationMessage('Write a title'); return false; }
          if (some && !picked.length) { Swal.showValidationMessage('Pick at least one restaurant'); return false; }
          if (v.starts_at && v.ends_at && v.ends_at <= v.starts_at) { Swal.showValidationMessage('"Until" must be after "Show from"'); return false; }
          return v;
        }).then(function (v) { if (v) post('saveAnnouncement', v).then(function () { alertMsg('Announcement saved'); var mi = document.querySelector('.menu-item[data-page="announcements"]'); if (mi && !mi.classList.contains('active')) mi.click(); else loadAnnouncements(); }).catch(fail); });
      setTimeout(function () { var to = $('afTo'); if (to) to.addEventListener('change', function () { $('afPick').hidden = to.value !== 'some'; }); }, 50);
    });
  }

  /* ════════ 12. Invoices ════════ */
  var invMonth = new Date().toISOString().slice(0, 7);
  function loadInvoices() {
    var box = $('spInvoices');
    $('spInvoicesActs').innerHTML = '<input type="month" id="spInvMonth" value="' + invMonth + '"><a class="btn btn-outline" id="spInvCsv" href="' + API + '?action=exportInvoices&month=' + invMonth + '"><span class="material-symbols-rounded">download</span>Export for accountant (CSV)</a>';
    get('invoices', '&month=' + invMonth).then(function (d) {
      box.innerHTML = (d.gst_ready ? '' : '<div class="sp-hint"><span class="material-symbols-rounded">info</span><div>Add your business name, address and GSTIN in <a href="#" data-sp-go="settings">Settings</a> so invoices print as tax invoices.</div></div>') +
        '<div class="stats-grid">' + stat('receipt_long', '#2563eb', 'Invoices', num(d.rows.length)) + stat('account_balance_wallet', '#16a34a', 'Taxable value', inr(d.sum.base)) + stat('percent', '#d97706', 'GST', inr(d.sum.tax)) + stat('payments', '#7c3aed', 'Total', inr(d.sum.total)) + '</div>' +
        '<div class="card"><div class="card-body">' + (d.rows.length ? '<div style="overflow-x:auto"><table><thead><tr><th>Invoice</th><th>Date</th><th>Restaurant</th><th>Plan</th><th>Taxable</th><th>GST</th><th>Total</th><th></th></tr></thead><tbody>' + d.rows.map(function (r) {
          return '<tr><td><b>' + esc(r.invoice_no) + '</b></td><td>' + day(r.created_at) + '</td><td>' + esc(r.restaurant_name || r.restaurant_id) + (r.gstin_no ? '<br><small class="sp-muted">GSTIN ' + esc(r.gstin_no) + '</small>' : '') + '</td>' +
            '<td>' + esc(r.subscription_type) + (+r.duration_months ? ' · ' + r.duration_months + ' mo' : '') + '<br><small class="sp-muted">' + esc(r.payment_method || '') + '</small></td><td>' + inr(r.gst.base) + '</td><td>' + inr(r.gst.tax) + '</td><td><b>' + inr(r.gst.total) + '</b></td>' +
            '<td><a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="' + API + '?action=' + (r.kind === 'manual' ? 'invoiceView' : 'invoice') + '&id=' + r.id + '"><span class="material-symbols-rounded">picture_as_pdf</span>Invoice</a></td></tr>';
        }).join('') + '</tbody></table></div>' : empty('request_quote', 'No paid subscriptions in this month')) + '</div></div>';
    }).catch(function (e) { box.innerHTML = empty('error', esc(e.message)); });
  }

  /* ════════ 12b. Invoice generator: sa-invoice-gen.js ════════ */
  function loadInvoiceGen() { if (window.saInvoiceGen) window.saInvoiceGen.load(); }

  /* ════════ 7. Security & activity ════════ */
  var secTab = 'logins', secQ = '', secOutcome = '';
  var OUT = { success: ['Signed in', 'badge-success'], failed: ['Wrong password', 'badge-danger'], locked_out: ['Locked out', 'badge-danger'], blocked_ip: ['Blocked IP', 'badge-danger'], logout: ['Logged out', 'badge-info'] };
  function loadSecurity() {
    var box = $('spSecurity');
    $('spSecurityActs').innerHTML = seg('spSecTab', [['logins', 'Logins'], ['blocked', 'Blocked IPs'], ['activity', 'Superadmin activity']], secTab);
    if (secTab === 'activity') {
      get('auditLog', secQ ? '&q=' + encodeURIComponent(secQ) : '').then(function (d) {
        box.innerHTML = '<div class="card"><div class="card-header"><div class="card-title">Everything done in this panel</div>' + searchBox('spSecQ', 'Search action, restaurant, who') + '</div><div class="card-body">' +
          (d.rows.length ? '<div style="overflow-x:auto"><table><thead><tr><th>When</th><th>Who</th><th>What</th><th>On</th><th>Details</th></tr></thead><tbody>' + d.rows.map(function (r) {
            return '<tr><td>' + when(r.created_at) + '</td><td>' + esc(r.admin_name || '-') + '</td><td><code class="sp-code">' + esc(r.action) + '</code></td><td>' + esc(r.target || '') + '</td><td class="sp-wrap">' + esc(r.details || '') + '<br><small class="sp-muted">' + esc(r.ip_address || '') + '</small></td></tr>';
          }).join('') + '</tbody></table></div>' : empty('history', 'Nothing recorded yet. From now on every change made here is listed.')) + '</div></div>';
        keepFocus('spSecQ');
      }).catch(function (e) { box.innerHTML = empty('error', esc(e.message)); });
      return;
    }
    get('security', '&q=' + encodeURIComponent(secQ) + '&outcome=' + secOutcome).then(function (d) {
      var s = d.summary || {};
      var head = '<div class="stats-grid">' + stat('login', '#16a34a', 'Sign-ins (24 h)', num(s.success_24h)) + stat('password', '#dc2626', 'Wrong passwords (24 h)', num(s.failed_24h)) +
        stat('lock', '#d97706', 'Locked / blocked (24 h)', num(s.locked_24h)) + stat('devices', '#2563eb', 'New devices (7 days)', num(s.new_devices_7d)) + '</div>' +
        (d.suspicious.length ? '<div class="sp-hint warn"><span class="material-symbols-rounded">gpp_maybe</span><div><b>Many wrong passwords from one place in the last 24 hours</b>' + d.suspicious.map(function (x) {
          return '<div>' + esc(x.ip_address) + ': ' + num(x.n) + ' tries (' + esc(String(x.tried || '').slice(0, 80)) + '), last ' + ago(x.last_at) + '</div>'; }).join('') + '</div></div>' : '');
      if (secTab === 'blocked') {
        box.innerHTML = head + '<div class="card"><div class="card-header"><div class="card-title">Blocked IP addresses</div><small class="sp-muted">Restaurants block these from ordering or logging in</small></div><div class="card-body">' +
          (d.blocked.length ? '<div style="overflow-x:auto"><table><thead><tr><th>IP</th><th>Restaurant</th><th>Reason</th><th>By</th><th>When</th><th></th></tr></thead><tbody>' + d.blocked.map(function (b) {
            return '<tr><td><code class="sp-code">' + esc(b.ip_address) + '</code></td><td>' + esc(b.restaurant_name || b.restaurant_id || 'All') + '</td><td>' + esc(b.reason || '') + '</td><td>' + esc(b.blocked_by || '') + '</td><td>' + when(b.created_at) + '</td>' +
              '<td><button class="btn btn-outline btn-sm" data-sp-unblock="' + b.id + '">Unblock</button></td></tr>';
          }).join('') + '</tbody></table></div>' : empty('shield', 'No blocked IPs')) + '</div></div>';
        return;
      }
      box.innerHTML = head + '<div class="card"><div class="card-header"><select id="spSecOutcome"><option value="">All results</option>' + Object.keys(OUT).map(function (k) { return '<option value="' + k + '"' + (secOutcome === k ? ' selected' : '') + '>' + OUT[k][0] + '</option>'; }).join('') + '</select>' +
        searchBox('spSecQ', 'Login, IP or restaurant') + '</div><div class="card-body">' +
        (d.logins.length ? '<div style="overflow-x:auto"><table><thead><tr><th>When</th><th>Restaurant</th><th>Login</th><th>Result</th><th>Device</th><th>IP</th></tr></thead><tbody>' + d.logins.map(function (l) {
          var o = OUT[l.outcome] || [l.outcome, 'badge-info'];
          return '<tr><td>' + when(l.created_at) + '</td><td>' + esc(l.restaurant_name || l.restaurant_id || '-') + '</td><td>' + esc(l.actor_name || l.username_tried) + '<br><small class="sp-muted">' + esc(l.user_type) + '</small></td>' +
            '<td><span class="badge ' + o[1] + '">' + esc(o[0]) + '</span>' + (+l.is_new_device ? ' <span class="badge badge-warning">New device</span>' : '') + '</td><td class="sp-wrap">' + esc(l.device_label || String(l.user_agent || '').slice(0, 60)) + '</td><td><code class="sp-code">' + esc(l.ip_address || '') + '</code></td></tr>';
        }).join('') + '</tbody></table></div>' : empty('login', 'No logins match')) + '</div></div>';
      keepFocus('spSecQ');
    }).catch(function (e) { box.innerHTML = empty('error', esc(e.message)); });
  }
  function searchBox(id, ph) { return '<div class="sa-search"><span class="material-symbols-rounded">search</span><input id="' + id + '" placeholder="' + esc(ph) + '" value="' + esc(secQ) + '"></div>'; }
  var refocus = null;
  function keepFocus(id) { if (refocus === id) { var el = $(id); if (el) { el.focus(); el.setSelectionRange(el.value.length, el.value.length); } } }

  /* ════════ 11. Platform health ════════ */
  function loadHealth() {
    var box = $('spHealth');
    $('spHealthActs').innerHTML = '<button class="btn btn-outline" id="spHealthRe"><span class="material-symbols-rounded">refresh</span>Check again</button>';
    box.innerHTML = '<div class="sp-loading">Checking…</div>';
    get('health').then(function (d) {
      var wa = d.whatsapp, ws = wa.by_status_7d || {};
      box.innerHTML = '<div class="sp-checks">' + d.checks.map(function (c) {
        return '<div class="sp-check ' + (c.ok ? 'ok' : 'bad') + '"><span class="material-symbols-rounded">' + (c.ok ? 'check_circle' : 'error') + '</span><div><b>' + esc(c.label) + '</b><small>' + esc(c.note) + '</small></div></div>';
      }).join('') + '</div>' +
        '<div class="stats-grid">' + stat('database', '#2563eb', 'Database', d.db_mb + ' MB') + stat('folder', '#7c3aed', 'Uploads', d.uploads.mb + ' MB', num(d.uploads.files) + (d.uploads.more ? '+' : '') + ' files') +
        stat('notifications_active', '#16a34a', 'Push devices', num(d.push.devices), num(d.push.restaurants) + ' dashboards') + stat('bug_report', '#dc2626', 'Errors (7 days)', num(d.errors.d7), num(d.errors.unread) + ' unread') + '</div>' +
        '<div class="sp-cols"><div class="card"><div class="card-header"><div class="card-title">WhatsApp queue</div><small class="sp-muted">last 7 days · last sent ' + ago(wa.last_sent) + '</small></div><div class="card-body">' +
        '<div class="sp-chips">' + (Object.keys(ws).length ? Object.keys(ws).map(function (k) { return '<span class="badge ' + (/fail|error/.test(k) ? 'badge-danger' : k === 'sent' ? 'badge-success' : 'badge-info') + '">' + esc(k) + ': ' + num(ws[k]) + '</span>'; }).join(' ') : '<span class="sp-muted">Nothing sent in 7 days</span>') + '</div>' +
        (wa.stuck ? '<div class="sp-hint warn" style="margin-top:10px"><span class="material-symbols-rounded">schedule</span><div>' + wa.stuck + ' messages waiting over 30 minutes. Check the WhatsApp sender (cron) is running.</div></div>' : '') +
        (wa.failed.length ? '<table style="margin-top:10px"><thead><tr><th>Restaurant</th><th>Message</th><th>Error</th><th>When</th></tr></thead><tbody>' + wa.failed.map(function (f) {
          return '<tr><td>' + esc(f.restaurant_name || f.restaurant_id) + '</td><td>' + esc(f.template_name || f.kind) + '</td><td class="sp-wrap">' + esc(f.error || '') + '</td><td>' + when(f.created_at) + '</td></tr>'; }).join('') + '</tbody></table>' : '') + '</div></div>' +
        '<div class="card"><div class="card-header"><div class="card-title">Biggest tables</div><small class="sp-muted">' + d.db_mb + ' MB total</small></div><div class="card-body"><table><thead><tr><th>Table</th><th>Rows</th><th>Size</th></tr></thead><tbody>' + d.db_tables.map(function (t) {
          return '<tr><td><code class="sp-code">' + esc(t.t) + '</code></td><td>' + num(t.r) + '</td><td>' + esc(t.mb) + ' MB</td></tr>'; }).join('') + '</tbody></table></div></div></div>' +
        '<div class="sp-muted" style="font-size:.8rem">Server time ' + esc(d.server_time) + ' · PHP ' + esc(d.php) + ' · last order on the platform ' + ago(d.last_order) + (d.backups.length ? ' · backups: ' + d.backups.map(function (b) { return esc(b.file) + ' (' + b.mb + ' MB, ' + esc(b.at) + ')'; }).join(', ') : '') + '</div>';
    }).catch(function (e) { box.innerHTML = empty('error', esc(e.message)); });
  }

  /* ════════ Settings: really save ════════ */
  var SET_MAP = { trialDays: 'trial_days', subscriptionPrice: 'plan_price', subscriptionPriceYearly: 'plan_price_yearly', autoRenewal: 'auto_renewal', renewalNoticeDays: 'renewal_notice_days',
    bizName: 'biz_name', bizGstin: 'biz_gstin', bizEmail: 'biz_email', bizPhone: 'biz_phone', bizAddress: 'biz_address', gstPercent: 'gst_percent', pricesIncludeGst: 'prices_include_gst', invoicePrefix: 'invoice_prefix', bizUpi: 'biz_upi', bizBank: 'biz_bank' };
  function loadSettings() {
    get('getSettings').then(function (d) { Object.keys(SET_MAP).forEach(function (id) { var el = $(id); if (el && d.settings[SET_MAP[id]] != null) el.value = d.settings[SET_MAP[id]]; }); }).catch(function () {});
    // senders for the Email check (MAIL_FROM_* in main/.env)
    var LABEL = { billing: 'Billing', alerts: 'Alerts (new orders)', orders: 'Order confirmations', accounts: 'Password resets', support: 'Support', sales: 'Sales' };
    get('mailSenders').then(function (d) {
      var sel = $('spTestEmailFrom'); if (!sel) return;
      var keep = sel.value;
      sel.innerHTML = '<option value="">Default · ' + esc(d.default) + '</option>' + d.senders.map(function (x) { return '<option value="' + esc(x.purpose) + '">' + esc(LABEL[x.purpose] || x.purpose) + ' · ' + esc(x.from) + (x.set ? '' : ' (not set, uses default)') + '</option>'; }).join('');
      sel.value = keep;
    }).catch(function () {});
  }
  function saveSettings(btn) {
    var v = {};
    Object.keys(SET_MAP).forEach(function (id) { var el = $(id); if (el) v[SET_MAP[id]] = el.value; });
    if (v.biz_gstin && !/^[0-9]{2}[A-Z0-9]{13}$/i.test(v.biz_gstin.trim())) { alertMsg('GSTIN should be 15 characters, like 29ABCDE1234F1Z5', 'error'); return; }
    btn.disabled = true;
    post('saveSettings', v).then(function () { btn.disabled = false; alertMsg('Settings saved'); }).catch(function (e) { btn.disabled = false; fail(e); });
  }

  /* ════════ 1. Restaurant 360 ════════ */
  var drawer;
  function open360(id) {
    if (!drawer) {
      drawer = document.createElement('div');
      drawer.className = 'sp-drawer-ov';
      drawer.innerHTML = '<aside class="sp-drawer" role="dialog" aria-modal="true" aria-label="Restaurant details"><div class="sp-dr-body"></div></aside>';
      document.body.appendChild(drawer);
      drawer.addEventListener('click', function (e) { if (e.target === drawer || e.target.closest('[data-sp-close]')) drawer.classList.remove('show'); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape') drawer.classList.remove('show'); });
    }
    var body = drawer.querySelector('.sp-dr-body');
    body.innerHTML = '<div class="sp-loading">Loading…</div>';
    drawer.classList.add('show');
    get('restaurant360', '&id=' + id).then(function (d) {
      var r = d.restaurant, s = d.sales, dl = +r.days_left;
      var flag = function (on, label) { return '<span class="sa-feat' + (+on ? ' on' : '') + '" style="cursor:default">' + label + '</span>'; };
      var days = []; for (var i = 13; i >= 0; i--) { var dt = new Date(Date.now() - i * 864e5); var k = dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0'); var hit = d.daily.filter(function (x) { return x.d === k; })[0]; days.push({ d: dt.getDate() + '', n: hit ? +hit.n : 0 }); }
      var wa = d.whatsapp;
      body.innerHTML = '<div class="sp-dr-head"><span class="sa-ini" style="width:48px;height:48px;font-size:1.2rem">' + esc((r.restaurant_name || '?').charAt(0)) + '</span><div><h2>' + esc(r.restaurant_name) + '</h2><small>' + esc(r.username) + ' · <code class="sp-code">' + esc(r.restaurant_id) + '</code> · since ' + day(r.created_at) + '</small></div>' +
        '<button class="sa-more-btn" data-sp-close aria-label="Close"><span class="material-symbols-rounded">close</span></button></div>' +
        '<div class="sa-acts sp-dr-acts"><button class="btn btn-primary btn-sm" onclick="openAdminPanel(' + r.id + ')"><span class="material-symbols-rounded">dashboard</span>Open their dashboard</button><button class="btn btn-outline btn-sm" onclick="openWebsite(\'' + esc(r.restaurant_id) + '\')"><span class="material-symbols-rounded">language</span>Website</button>' +
        '<button class="btn btn-outline btn-sm" data-sp-link="' + esc(r.restaurant_id) + '"><span class="material-symbols-rounded">add_link</span>Payment link</button><button class="btn btn-outline btn-sm" data-sp-extend="' + r.id + '"><span class="material-symbols-rounded">more_time</span>Extend</button>' +
        '<button class="btn btn-outline btn-sm" data-sp-annfor="' + esc(r.restaurant_id) + '"><span class="material-symbols-rounded">campaign</span>Send notice</button></div>' +
        '<div class="sp-dr-grid">' +
        '<div class="sp-box"><small>Plan</small><b>' + esc(+r.is_active ? (r.subscription_status === 'active' ? 'Paid' : r.subscription_status === 'trial' ? 'Trial' : r.subscription_status) : 'Disabled') + '</b><span class="badge ' + (dl < 0 ? 'badge-danger' : dl <= 7 ? 'badge-warning' : 'badge-success') + '">' + (dl < 0 ? 'ended ' + day(r.expires_on) : 'until ' + day(r.expires_on) + ' (' + dl + ' d)') + '</span></div>' +
        '<div class="sp-box"><small>Today</small><b>' + inr(s.today_sales) + '</b><span class="sp-muted">' + num(s.today_orders) + ' orders</span></div>' +
        '<div class="sp-box"><small>This month</small><b>' + inr(s.month_sales) + '</b><span class="sp-muted">' + num(s.month_orders) + ' orders</span></div>' +
        '<div class="sp-box"><small>All time</small><b>' + inr(s.all_sales) + '</b><span class="sp-muted">' + num(s.all_orders) + ' orders · last ' + ago(s.last_order_at) + '</span></div></div>' +
        '<div class="sp-sec"><h4>Orders, last 14 days</h4>' + bars(days, 'n', num, '#2563eb') + '</div>' +
        '<div class="sp-sec"><h4>Setup</h4><div class="sp-kv"><span>Menu items</span><b>' + num(d.menu_items) + '</b><span>Staff</span><b>' + num(d.staff) + '</b><span>Customers</span><b>' + num(d.customers) + '</b><span>Push devices</span><b>' + num(d.push_devices) + '</b>' +
        '<span>Last login</span><b>' + ago(d.last_login) + '</b><span>WhatsApp</span><b>' + (wa && wa.connected_at && !wa.lost_at ? 'Connected' + (wa.rg_phone ? ' · ' + esc(wa.rg_phone) : '') : wa && wa.lost_at ? '<span style="color:#dc2626">Disconnected</span>' : 'Not connected') + '</b>' +
        '<span>Owner</span><b>' + esc(r.owner_name || '-') + '</b><span>Contact</span><b>' + [r.phone, r.email].filter(Boolean).map(esc).join(' · ') + '</b>' + (r.custom_domain ? '<span>Domain</span><b>' + esc(r.custom_domain) + '</b>' : '') + '</div>' +
        '<div class="sa-feats" style="max-width:none;margin-top:10px">' + flag(r.payment_gateway_type && r.payment_gateway_type !== 'cash_only', 'Online pay') + flag(r.whatsapp_orders, 'WhatsApp orders') + flag(r.show_install_app, 'Install app') + flag(r.photo_gallery_enabled, 'Gallery') +
        flag(r.meal_subscriptions_enabled, 'Meal plans') + flag(r.enable_delivery, 'Delivery') + flag(r.enable_takeaway, 'Takeaway') + flag(r.enable_dinein, 'Dine-in') + flag(r.enable_reservations, 'Reservations') + flag(r.cod_enabled, 'Cash on delivery') + '</div></div>' +
        '<div class="sp-sec"><h4>Subscription payments</h4>' + (d.payments.length ? '<table><tbody>' + d.payments.map(function (p) {
          return '<tr><td>' + day(p.created_at) + '</td><td>' + inr(p.amount) + '</td><td><span class="badge ' + (p.payment_status === 'success' ? 'badge-success' : p.payment_status === 'pending' ? 'badge-warning' : 'badge-danger') + '">' + esc(p.payment_status) + '</span></td><td>' + (p.payment_status === 'success' ? '<a target="_blank" rel="noopener" href="' + API + '?action=invoice&id=' + p.id + '">' + esc(p.invoice_no) + '</a>' : '') + '</td></tr>';
        }).join('') + '</tbody></table>' : '<div class="sp-muted">No payments yet</div>') + '</div>' +
        '<div class="sp-sec"><h4>Support requests</h4>' + (d.tickets.length ? d.tickets.map(function (t) { return '<div class="sp-mini"><a href="#" data-sp-goticket="' + t.id + '">' + esc(t.subject) + '</a> · ' + esc((TSTAT[t.status] || [t.status])[0]) + ' · ' + ago(t.updated_at) + '</div>'; }).join('') : '<div class="sp-muted">None</div>') + '</div>' +
        '<div class="sp-sec"><h4>Errors <small class="sp-muted">' + num(d.errors_7d) + ' in 7 days</small></h4>' + (d.errors.length ? d.errors.map(function (e) { return '<div class="sp-mini"><span class="badge ' + (e.severity === 'warning' ? 'badge-warning' : 'badge-danger') + '">' + esc(e.severity) + '</span> ' + esc(String(e.message).slice(0, 140)) + ' · ' + ago(e.created_at) + '</div>'; }).join('') : '<div class="sp-muted">None</div>') + '</div>' +
        '<div class="sp-sec"><h4>Recent logins</h4>' + (d.logins.length ? d.logins.map(function (l) { var o = OUT[l.outcome] || [l.outcome, 'badge-info']; return '<div class="sp-mini"><span class="badge ' + o[1] + '">' + esc(o[0]) + '</span> ' + esc(l.actor_name || l.username_tried) + ' · ' + esc(l.device_label || '') + ' · ' + esc(l.ip_address || '') + ' · ' + ago(l.created_at) + '</div>'; }).join('') : '<div class="sp-muted">None recorded</div>') + '</div>';
    }).catch(function (e) { body.innerHTML = '<div class="sp-dr-head"><b>Restaurant</b><button class="sa-more-btn" data-sp-close aria-label="Close"><span class="material-symbols-rounded">close</span></button></div>' + empty('error', esc(e.message)); });
  }

  /* ════════ 13. Bulk actions on Restaurants ════════ */
  function picked() { return [].map.call(document.querySelectorAll('.sp-ck-row:checked'), function (x) { return +x.value; }); }
  function refreshBulk() {
    var bar = $('spBulk'); if (!bar) return;
    var ids = picked(), all = document.querySelectorAll('.sp-ck-row'), ck = $('spCkAll');
    if (ck) { ck.checked = ids.length && ids.length === all.length; ck.indeterminate = ids.length > 0 && ids.length < all.length; }
    bar.hidden = !ids.length;
    if (!ids.length) return;
    bar.innerHTML = '<b>' + ids.length + ' selected</b>' +
      '<select id="spBulkDo"><option value="">Choose an action…</option><optgroup label="Features"><option value="feature:whatsapp_orders:1">Turn on WhatsApp orders</option><option value="feature:whatsapp_orders:0">Turn off WhatsApp orders</option>' +
      '<option value="feature:show_install_app:1">Turn on Install app</option><option value="feature:show_install_app:0">Turn off Install app</option><option value="feature:photo_gallery_enabled:1">Allow Gallery</option><option value="feature:photo_gallery_enabled:0">Block Gallery</option>' +
      '<option value="feature:meal_subscriptions_enabled:1">Allow Meal plans</option><option value="feature:meal_subscriptions_enabled:0">Block Meal plans</option></optgroup>' +
      '<optgroup label="Plan"><option value="extend">Extend trial / plan by days…</option><option value="enable">Enable</option><option value="disable">Disable</option></optgroup>' +
      '<optgroup label="Message"><option value="announce">Send them an announcement…</option></optgroup></select>' +
      '<button class="btn btn-primary btn-sm" id="spBulkGo">Apply</button><button class="btn btn-outline btn-sm" id="spBulkClear">Clear</button>';
  }
  function runBulk() {
    var ids = picked(), v = ($('spBulkDo') || {}).value;
    if (!ids.length || !v) return;
    if (v === 'announce') {
      var rids = ids.map(function (id) { var r = (typeof saRestaurantsMap !== 'undefined' ? saRestaurantsMap : {})[id]; return r && r.restaurant_id; }).filter(Boolean);
      annForm(null, rids); return;
    }
    var body = { ids: ids }, label = ($('spBulkDo').selectedOptions[0] || {}).textContent;
    var go = function () {
      confirmIt(label + ' for ' + ids.length + ' restaurant' + (ids.length > 1 ? 's' : '') + '?', 'Apply').then(function (ok) {
        if (!ok) return;
        post('bulk', body).then(function (d) { alertMsg(d.message); if (typeof fetchRestaurants === 'function') fetchRestaurants(); }).catch(fail);
      });
    };
    if (v.indexOf('feature:') === 0) { var p = v.split(':'); body.do = 'feature'; body.feature = p[1]; body.on = p[2] === '1'; go(); }
    else if (v === 'extend') {
      Swal.fire({ title: 'Extend by how many days?', input: 'number', inputValue: 30, inputAttributes: { min: 1, max: 365 }, showCancelButton: true, confirmButtonColor: '#2563eb',
        inputValidator: function (x) { return !(+x >= 1 && +x <= 365) ? 'Enter 1 to 365' : null; } }).then(function (r) { if (r.isConfirmed) { body.do = 'extend'; body.days = +r.value; label = 'Extend by ' + body.days + ' days'; go(); } });
    } else { body.do = v; go(); }
  }

  /* ════════ shared ════════ */
  var optsCache = null;
  function restOptions() {
    if (optsCache) return Promise.resolve(optsCache);
    return get('restaurantOptions').then(function (d) { optsCache = d.rows; setTimeout(function () { optsCache = null; }, 60000); return d.rows; });
  }
  function setBadge(id, n) { var b = $(id); if (!b) return; b.style.display = n > 0 ? 'inline-block' : 'none'; b.textContent = n > 99 ? '99+' : n; }
  function loadBadges() { get('badges').then(function (d) { setBadge('spTicketBadge', d.tickets); setBadge('spLeadBadge', d.leads); setBadge('spRenewBadge', d.renewals); }).catch(function () {}); }
  function extend(id) {
    Swal.fire({ title: 'Extend by how many days?', text: 'Adds days to the current end date (or from today if it already ended). The restaurant is switched back on.', input: 'number', inputValue: 30,
      inputAttributes: { min: 1, max: 365 }, showCancelButton: true, confirmButtonColor: '#2563eb', inputValidator: function (x) { return !(+x >= 1 && +x <= 365) ? 'Enter 1 to 365' : null; } })
      .then(function (r) { if (r.isConfirmed) post('extendRenewal', { id: id, days: +r.value }).then(function (d) { alertMsg(d.message); reloadActive(); if (drawer && drawer.classList.contains('show')) open360(id); }).catch(fail); });
  }
  var LOADERS = { analytics: loadAnalytics, renewals: loadRenewals, coupons: loadCoupons, leads: loadLeads, chatLeads: loadChatLeads, recordings: loadRecordings, support: loadSupport, announcements: loadAnnouncements, invoices: loadInvoices, invoiceGen: loadInvoiceGen, security: loadSecurity, health: loadHealth, settings: loadSettings };
  function reloadActive() { var a = document.querySelector('.menu-item.active'); if (a && LOADERS[a.dataset.page]) LOADERS[a.dataset.page](); }

  document.addEventListener('click', function (e) {
    var t = e.target, m;
    if (t.closest('.menu-item[data-page]') && drawer) drawer.classList.remove('show');
    if ((m = t.closest('.menu-item[data-page]')) && LOADERS[m.dataset.page]) { setTimeout(function () { LOADERS[m.dataset.page](); }, 0); return; }
    if ((m = t.closest('[data-sp360]'))) { e.preventDefault(); var mm = m.closest('.sa-more'); if (mm) mm.classList.remove('open'); open360(+m.dataset.sp360); return; }
    if ((m = t.closest('[data-sp-go]'))) { e.preventDefault(); var mi = document.querySelector('.menu-item[data-page="' + m.dataset.spGo + '"]'); if (mi) mi.click(); return; }
    if ((m = t.closest('[data-sp-link]'))) { if (drawer) drawer.classList.remove('show'); var rr = Object.keys(spRenewRows).map(function (k) { return spRenewRows[k]; }).filter(function (x) { return x.restaurant_id === m.dataset.spLink; })[0];
      get('getSettings').then(function (d) { openPaymentLink(m.dataset.spLink, d.settings.plan_price); }).catch(function () { openPaymentLink(m.dataset.spLink, ''); }); return; }
    if ((m = t.closest('[data-sp-remind]'))) {
      var r = spRenewRows[m.dataset.spRemind]; if (!r) return;
      var dl = +r.days_left;
      var text = 'Hi ' + (r.owner_name || r.restaurant_name) + ', this is RestroGrow. Your ' + (r.subscription_status === 'active' ? 'plan' : 'free trial') + ' for ' + r.restaurant_name + (dl < 0 ? ' ended on ' : dl === 0 ? ' ends today, ' : ' ends on ') + day(r.expires_on) +
        '. Renew to keep your website, orders and POS running. Reply here and we will send you the payment link.';
      window.open(waLink(r.phone, text), '_blank', 'noopener');
      post('logReminder', { id: r.id }).catch(function () {});
      return;
    }
    if ((m = t.closest('[data-sp-extend]'))) { extend(+m.dataset.spExtend); return; }
    if ((m = t.closest('[data-sp-annfor]'))) { annForm(null, [m.dataset.spAnnfor]); return; }
    if ((m = t.closest('#spRenewSeg button'))) { renewDays = +m.dataset.v; loadRenewals(); return; }
    if (t.closest('#spCouponNew')) { couponForm(null); return; }
    if ((m = t.closest('[data-sp-cedit]'))) { couponForm(spCouponRows[m.dataset.spCedit]); return; }
    if ((m = t.closest('[data-sp-ctoggle]'))) { var c = spCouponRows[m.dataset.spCtoggle]; fetch('api.php?action=toggleCoupon', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: c.id, is_active: +c.is_active ? 0 : 1 }) }).then(res).then(loadCoupons).catch(fail); return; }
    if ((m = t.closest('[data-sp-cdel]'))) { var cd = spCouponRows[m.dataset.spCdel]; confirmIt('Delete coupon ' + cd.coupon_code + '? Customers can no longer use it.', 'Delete').then(function (ok) { if (ok) fetch('api.php?action=deleteCoupon', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: cd.id }) }).then(res).then(loadCoupons).catch(fail); }); return; }
    if ((m = t.closest('#spLeadSeg button'))) { leadStage = m.dataset.v; loadLeads(); return; }
    if ((m = t.closest('#spChatLeadSeg button'))) { chatLeadStatus = m.dataset.v; loadChatLeads(); return; }
    if (t.closest('#spChatLeadRefresh')) { loadChatLeads(); return; }
    if ((m = t.closest('[data-sp-chat-open]'))) { showChatConversation(+m.dataset.spChatOpen); return; }
    if (t.closest('#spLeadNew')) { leadForm(null); return; }
    if ((m = t.closest('[data-sp-lead]'))) { leadForm(spLeadRows[m.dataset.spLead]); return; }
    if ((m = t.closest('[data-sp-recmove]'))) { moveRecording(+m.dataset.spRecmove); return; }
    if ((m = t.closest('a.sp-off'))) { e.preventDefault(); return; }
    if ((m = t.closest('[data-sp-rec]'))) { openRecordings(+m.dataset.spRec); return; }
    if ((m = t.closest('[data-sp-recdel]'))) {
      // first tap arms the button, second tap deletes (keeps the recordings window open)
      if (!m.classList.contains('armed')) { m.classList.add('armed'); m.title = 'Tap again to delete'; setTimeout(function () { m.classList.remove('armed'); m.title = 'Delete'; }, 3000); return; }
      m.disabled = true;
      post('deleteLeadRecording', { id: +m.dataset.spRecdel }).then(m.dataset.spRecpage ? loadRecordings : redrawRecordings).catch(function (er) { m.disabled = false; fail(er); });
      return;
    }
    if ((m = t.closest('[data-sp-convert]'))) { convertLead(spLeadRows[m.dataset.spConvert]); return; }
    if ((m = t.closest('#spTicketSeg button'))) { ticketStatus = m.dataset.v; openTicket = 0; loadSupport(); return; }
    if ((m = t.closest('[data-sp-ticket]'))) { showTicket(+m.dataset.spTicket); return; }
    if ((m = t.closest('[data-sp-goticket]'))) { e.preventDefault(); if (drawer) drawer.classList.remove('show'); ticketStatus = 'all'; openTicket = +m.dataset.spGoticket; var ms = document.querySelector('.menu-item[data-page="support"]'); if (ms) ms.click(); return; }
    if ((m = t.closest('[data-sp-reply]'))) {
      var bodyEl = $('spReplyBody'), st = m.dataset.spReply;
      if (!bodyEl.value.trim() && st !== 'closed') { bodyEl.focus(); return; }
      m.disabled = true;
      post('replyTicket', { id: openTicket, body: bodyEl.value, status: st }).then(function (d) { alertMsg(d.message); loadSupport(); }).catch(function (er) { m.disabled = false; fail(er); });
      return;
    }
    if (t.closest('#spAnnNew')) { annForm(null); return; }
    if ((m = t.closest('[data-sp-ann]'))) { annForm(spAnnRows[m.dataset.spAnn]); return; }
    if ((m = t.closest('[data-sp-annt]'))) { var an = spAnnRows[m.dataset.spAnnt]; post('toggleAnnouncement', { id: an.id, is_active: +an.is_active ? 0 : 1 }).then(loadAnnouncements).catch(fail); return; }
    if ((m = t.closest('[data-sp-annd]'))) { confirmIt('Delete this announcement?', 'Delete').then(function (ok) { if (ok) post('deleteAnnouncement', { id: +m.dataset.spAnnd }).then(loadAnnouncements).catch(fail); }); return; }
    if ((m = t.closest('#spSecTab button'))) { secTab = m.dataset.v; secQ = ''; loadSecurity(); return; }
    if ((m = t.closest('[data-sp-unblock]'))) { confirmIt('Unblock this IP address?', 'Unblock').then(function (ok) { if (ok) post('unblockIp', { id: +m.dataset.spUnblock }).then(function (d) { alertMsg(d.message); loadSecurity(); }).catch(fail); }); return; }
    if (t.closest('#spHealthRe')) { loadHealth(); return; }
    if (t.closest('#saveSettings')) { e.preventDefault(); e.stopImmediatePropagation(); saveSettings(t.closest('#saveSettings')); return; }
    if ((m = t.closest('#spTestEmailBtn'))) {
      var out = $('spTestEmailResult'), to = ($('spTestEmailTo') || {}).value || '';
      m.disabled = true; out.style.color = ''; out.textContent = 'Sending…';
      post('testEmail', { to: to.trim(), purpose: ($('spTestEmailFrom') || {}).value || '' }).then(function (d) { m.disabled = false; out.style.color = '#15803d'; out.textContent = '✓ ' + d.message; })
        .catch(function (er) { m.disabled = false; out.style.color = '#dc2626'; out.textContent = er.message; });
      return;
    }
    if (t.closest('#spBulkGo')) { runBulk(); return; }
    if (t.closest('#spBulkClear')) { document.querySelectorAll('.sp-ck-row, #spCkAll').forEach(function (x) { x.checked = false; }); refreshBulk(); return; }
  }, true);

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.id === 'spCkAll') { document.querySelectorAll('.sp-ck-row').forEach(function (x) { x.checked = t.checked; }); refreshBulk(); }
    else if (t.classList.contains('sp-ck-row')) refreshBulk();
    else if (t.id === 'spRecFile') { uploadRecordings(t.files); t.value = ''; }
    else if (t.id === 'spRecsFile') { uploadAudio(t.files, +recsUploadLead || 0, 'spRecsStatus', loadRecordings); t.value = ''; }
    else if (t.id === 'spRecsLead') recsUploadLead = t.value;
    else if (t.id === 'spRecsCkAll') { document.querySelectorAll('.sp-rec-ck').forEach(function (x) { x.checked = t.checked; }); recsSelection(); }
    else if (t.classList.contains('sp-rec-ck')) recsSelection();
    else if (t.id === 'spCouponRest') { couponRid = t.value; loadCoupons(); }
    else if (t.id === 'spInvMonth') { invMonth = t.value || invMonth; loadInvoices(); }
    else if (t.id === 'spSecOutcome') { secOutcome = t.value; loadSecurity(); }
    else if (t.id === 'spChatLeadStatus') {
      var chatId = +t.dataset.chatLead, nextStatus = t.value;
      t.disabled = true;
      post('updateChatLead', { id: chatId, status: nextStatus }).then(function (d) {
        alertMsg(d.message);
        loadChatLeads();
        showChatConversation(chatId);
      }).catch(function (er) { t.disabled = false; fail(er); });
    }
    else if (t.id === 'spTicketStatus' && openTicket) { post('replyTicket', { id: openTicket, body: '', status: t.value }).then(function () { loadSupport(); }).catch(fail); }
  });
  ['dragover', 'dragleave', 'drop'].forEach(function (ev) {
    document.addEventListener(ev, function (e) {
      var z = e.target.closest && e.target.closest('#spRecDrop, #spRecsDrop'); if (!z) return;
      e.preventDefault();
      z.classList.toggle('over', ev === 'dragover');
      if (ev === 'drop') { if (z.id === 'spRecsDrop') uploadAudio(e.dataTransfer.files, +recsUploadLead || 0, 'spRecsStatus', loadRecordings); else uploadRecordings(e.dataTransfer.files); }
    });
  });
  var qTimer = 0;
  document.addEventListener('input', function (e) {
    if (e.target.id !== 'spSecQ') return;
    secQ = e.target.value; refocus = 'spSecQ';
    clearTimeout(qTimer); qTimer = setTimeout(loadSecurity, 350);
  });

  // keep the restaurants table's selection boxes in step when rows are redrawn
  var tb = null;
  function watchRows() {
    tb = $('restaurantsTbody');
    if (!tb) return;
    new MutationObserver(function () { var ck = $('spCkAll'); if (ck) { ck.checked = false; ck.indeterminate = false; } refreshBulk(); }).observe(tb, { childList: true });
  }

  function start() {
    watchRows();
    loadBadges();
    setInterval(function () { if (!document.hidden) loadBadges(); }, 60000);
    var a = document.querySelector('.menu-item.active');
    if (a && LOADERS[a.dataset.page]) LOADERS[a.dataset.page]();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(start, 0); }); else start();
})();
