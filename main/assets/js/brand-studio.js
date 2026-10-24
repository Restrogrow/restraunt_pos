/*
 * Branches page › Brand website (data: api/brand_admin.php; the brand: config/brand.php).
 *
 * For a multi-branch owner whose outlets Super Admin grouped into a brand: the brand's address(es), each outlet's
 * name and web address, order, "show in picker", brand / own payment gateway, the brand's own domain (DNS records,
 * check, switch on), the brand's PhonePe keys and webhook, online payments by outlet (with a CSV download) and the
 * tracking codes for the brand pages. Renders into #brandBox; nothing shows for owners without a brand.
 */
(function () {
  'use strict';
  var API = '../api/brand_admin.php';
  var box = document.getElementById('brandBox');
  if (!box) return;
  var B = null, busy = false;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ic(n) { return '<span class="material-symbols-rounded">' + n + '</span>'; }
  function money(n) { return '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN'); }
  function toast(msg, bad) {
    var t = document.createElement('div');
    t.className = 'bs-toast' + (bad ? ' bad' : '');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('out'); }, 3200);
    setTimeout(function () { t.remove(); }, 3700);
  }

  var CSS = [
    '#brandBox{margin:0 0 28px}',
    '.bs-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;margin-bottom:14px}',
    '.bs-card h3{margin:0 0 4px;font-size:16px;display:flex;align-items:center;gap:8px}.bs-card h3 .material-symbols-rounded{color:var(--brand);font-size:20px}',
    '.bs-card p.bs-sub{margin:0 0 14px;color:var(--muted);font-size:13px;line-height:1.5}',
    '.bs-hero{background:linear-gradient(135deg,#1f2937,#111827);color:#fff;border:0}',
    '.bs-hero h2{margin:0 0 6px;font-size:20px}.bs-hero p{margin:0;opacity:.85;font-size:13px}',
    '.bs-links{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}',
    '.bs-link{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;text-decoration:none;padding:7px 12px;border-radius:10px;font-size:13px}',
    '.bs-table{width:100%;border-collapse:collapse;font-size:13px}.bs-table th{text-align:left;font-weight:600;color:var(--muted);font-size:12px;padding:6px}.bs-table td{padding:6px;border-top:1px solid var(--line);vertical-align:middle}',
    '.bs-table input[type=text]{width:100%;min-width:110px;padding:7px 9px;border:1px solid var(--line);border-radius:8px;font:inherit;font-size:13px}',
    '.bs-table select{padding:7px;border:1px solid var(--line);border-radius:8px;font:inherit;font-size:13px}',
    '.bs-key{display:flex;align-items:center;gap:2px;color:var(--muted)}',
    '.bs-warn{font-size:11px;color:var(--warn);display:block;margin-top:2px}',
    '.bs-row{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:10px}.bs-row label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:var(--ink);flex:1;min-width:180px}',
    '.bs-row input,.bs-row select{padding:9px 11px;border:1px solid var(--line);border-radius:10px;font:inherit;font-size:14px}',
    '.bs-rec{font-family:ui-monospace,Consolas,monospace;font-size:12px;background:var(--bg);border-radius:10px;padding:10px 12px;margin:6px 0;overflow-wrap:anywhere}',
    '.bs-ok{color:var(--ok);font-weight:600}.bs-bad{color:#b91c1c;font-weight:600}',
    '.bs-mini{border:0;background:var(--bg);border-radius:8px;width:28px;height:28px;cursor:pointer;display:inline-grid;place-items:center}.bs-mini .material-symbols-rounded{font-size:18px}',
    '.bs-toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#111827;color:#fff;padding:10px 16px;border-radius:12px;font-size:14px;z-index:99;transition:opacity .4s}.bs-toast.bad{background:#b91c1c}.bs-toast.out{opacity:0}',
    '.bs-grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}',
    '@media (max-width:640px){.bs-table .bs-hide{display:none}}'
  ].join('');

  function hero() {
    var d = B.domain, dom = d.domain && d.enabled;
    return '<div class="bs-card bs-hero"><h2>' + esc(B.name) + ' · one website for all outlets</h2>' +
      '<p>Customers open your brand address, pick their outlet (the nearest is highlighted) and order. Each outlet keeps its own menu, orders, staff and customers.</p>' +
      '<div class="bs-links"><a class="bs-link" href="' + esc(B.address) + '" target="_blank" rel="noopener">' + ic('open_in_new') + esc(B.address.replace(/^https?:\/\//, '')) + '</a>' +
      (dom ? '' : '<span class="bs-link" style="opacity:.8">' + ic('info') + 'Works without your own domain; add one below any time</span>') + '</div></div>';
  }

  function outletsCard() {
    var det = {};
    (B.outlet_details || []).forEach(function (o) { det[o.restaurant_id] = o; });
    var rows = B.outlets.map(function (o, i) {
      var x = det[o.restaurant_id] || {};
      var warn = (!x.has_location ? '<span class="bs-warn">No map location saved: "nearest" can\'t use it (outlet › Settings › Address).</span>' : '') +
        (!x.online_payments ? '<span class="bs-warn">Online payments are off for this outlet (ask RestroGrow support).</span>' : '');
      return '<tr data-rid="' + esc(o.restaurant_id) + '">' +
        '<td style="white-space:nowrap"><button class="bs-mini" type="button" data-bs-up="' + i + '" title="Move up"' + (i ? '' : ' disabled') + '>' + ic('arrow_upward') + '</button></td>' +
        '<td><input type="text" class="bs-label" value="' + esc(o.label) + '"><span class="bs-warn" style="color:var(--muted)">' + esc(x.restaurant_name || '') + '</span>' + warn + '</td>' +
        '<td><div class="bs-key">/<input type="text" class="bs-urlkey" value="' + esc(o.url_key) + '"></div></td>' +
        '<td class="bs-hide"><label><input type="checkbox" class="bs-listed"' + (o.is_listed ? ' checked' : '') + '> Show</label></td>' +
        '<td><select class="bs-gw"><option value="brand"' + (o.gateway === 'brand' ? ' selected' : '') + '>Brand gateway</option><option value="own"' + (o.gateway === 'own' ? ' selected' : '') + '>Its own setting</option></select></td>' +
        '<td class="bs-hide"><input type="radio" name="bsMain" class="bs-main"' + (B.default_restaurant_id === o.restaurant_id ? ' checked' : '') + ' title="Main outlet: its logo and colours are used on the outlet picker"></td></tr>';
    }).join('');
    return '<div class="bs-card"><h3>' + ic('storefront') + 'Outlets</h3><p class="bs-sub">The name customers see in the picker, and its address: ' + esc(B.address.replace(/^https?:\/\//, '')) + '<b>indiranagar</b>/menu. Changing an address part makes old links of that outlet stop working, so print new QR codes after a change.</p>' +
      '<div style="overflow-x:auto"><table class="bs-table"><thead><tr><th></th><th>Name</th><th>Address part</th><th class="bs-hide">Picker</th><th>Payments</th><th class="bs-hide" title="Main outlet">Main</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
      '<div class="bs-row" style="margin-top:12px"><label>Line under the brand name on the picker<input type="text" id="bsTagline" maxlength="160" value="' + esc(B.tagline || '') + '" placeholder="Choose your outlet to see its menu and order"></label></div>' +
      '<button class="btn btn-primary" type="button" data-bs-save-outlets>' + ic('save') + 'Save outlets</button></div>';
  }

  function domainCard() {
    var d = B.domain, c = d.check;
    var recs = (d.records || []).map(function (r) { return '<div class="bs-rec">' + esc(r.type) + ' · name <b>' + esc(r.name) + '</b> · value <b>' + esc(r.value) + '</b><br><span style="color:var(--muted)">' + esc(r.note) + '</span></div>'; }).join('');
    var state = !d.domain ? '' : d.enabled ? '<p class="bs-ok">' + ic('check_circle') + ' Live: customers are sent to ' + esc(d.domain) + '</p>'
      : '<p class="bs-bad">Saved, not switched on yet.</p>';
    var check = c ? '<p class="' + (c.ready ? 'bs-ok' : 'bs-bad') + '">' + (c.ready ? 'Your domain shows your brand website. Switch it on.' : 'Not ready yet: DNS ' + esc(c.dns.status) + ', website ' + esc(c.site.status) + '. DNS changes can take a few hours.') + '</p>' : '';
    return '<div class="bs-card"><h3>' + ic('language') + 'Your own domain</h3><p class="bs-sub">Optional. Without it everything works at ' + esc(B.address.replace(/^https?:\/\//, '')) + '. With it, e.g. <b>spicegarden.in</b>, the picker opens there and each outlet is at spicegarden.in/<b>indiranagar</b>.</p>' +
      '<div class="bs-row"><label>Domain<input type="text" id="bsDomain" value="' + esc(d.domain) + '" placeholder="spicegarden.in or order.spicegarden.in"></label>' +
      '<button class="btn" type="button" data-bs-domain-save>' + ic('save') + 'Save</button></div>' + state +
      (d.domain ? '<p class="bs-sub" style="margin:8px 0 4px">At your domain company, add:</p>' + recs +
        '<p class="bs-sub">RestroGrow support also adds the domain on our server (with its free SSL), once, after you save it here.</p>' + check +
        '<div class="bs-row"><button class="btn" type="button" data-bs-domain-check>' + ic('refresh') + 'Check now</button>' +
        (d.enabled ? '<button class="btn" type="button" data-bs-domain-switch="0">' + ic('toggle_off') + 'Switch off</button>' : '<button class="btn btn-primary" type="button" data-bs-domain-switch="1">' + ic('toggle_on') + 'Switch on</button>') + '</div>' : '') + '</div>';
  }

  function gatewayCard() {
    var g = B.gateway, w = g.webhook;
    return '<div class="bs-card"><h3>' + ic('payments') + 'One payment gateway for all outlets</h3><p class="bs-sub">Your PhonePe business account. Every outlet set to <b>Brand gateway</b> takes online payments into this account; each payment still belongs to its outlet\'s order. Outlets set to <b>Its own setting</b> keep their own keys (e.g. a franchise with its own bank account). Without keys here, every outlet stays on its own setting.</p>' +
      (g.set ? '<p class="bs-ok">' + ic('check_circle') + ' Saved: Client ID ' + esc(g.client_id) + ', secret ' + esc(g.secret_hint) + ' (' + esc(g.environment === 'SANDBOX' ? 'test mode' : 'live') + ')</p>' : '') +
      '<div class="bs-row"><label>Client ID<input type="text" id="bsClientId" value="' + esc(g.client_id) + '" autocomplete="off"></label>' +
      '<label>Client Secret<input type="password" id="bsSecret" placeholder="' + (g.set ? 'Leave empty to keep the saved one' : '') + '" autocomplete="new-password"></label>' +
      '<label style="max-width:180px">Mode<select id="bsEnv"><option value="PRODUCTION"' + (g.environment !== 'SANDBOX' ? ' selected' : '') + '>Live</option><option value="SANDBOX"' + (g.environment === 'SANDBOX' ? ' selected' : '') + '>Test (sandbox)</option></select></label></div>' +
      (g.can_save ? '' : '<p class="bs-bad">Keys can’t be saved safely on this server yet. Ask RestroGrow support.</p>') +
      '<div class="bs-row"><button class="btn btn-primary" type="button" data-bs-gw-save>' + ic('save') + 'Save keys</button>' + (g.set ? '<button class="btn" type="button" data-bs-gw-clear>' + ic('delete') + 'Remove keys</button>' : '') + '</div>' +
      (w ? '<p class="bs-sub" style="margin:10px 0 4px">In PhonePe › Developer settings › Webhooks, add this once (so payments are confirmed even if a customer closes the page):</p>' +
        '<div class="bs-rec">URL: <b>' + esc(w.url) + '</b><br>Username: <b>' + esc(w.username) + '</b><br>Password: <b>' + esc(w.password) + '</b><br>Events: checkout.order.completed, checkout.order.failed</div>' : '') + '</div>';
  }

  function paymentsCard() {
    var rows = B.payments.map(function (p) {
      var cell = function (x) { return money(x.amount) + ' <span style="color:var(--muted)">(' + x.count + ')</span>'; };
      return '<tr><td>' + esc(p.label) + (p.gateway === 'own' ? ' <span class="bs-warn" style="display:inline;color:var(--muted)">own gateway</span>' : '') + '</td><td>' + cell(p.today) + '</td><td>' + cell(p.last_7_days) + '</td><td>' + cell(p.this_month) + '</td></tr>';
    }).join('');
    var t = new Date(), iso = function (d) { return d.toISOString().slice(0, 10); };
    return '<div class="bs-card"><h3>' + ic('account_balance') + 'Online payments by outlet</h3><p class="bs-sub">Paid online through the brand gateway (orders and wallet top-ups), so you can settle with each outlet. Outlets on their own gateway show 0 here.</p>' +
      '<div style="overflow-x:auto"><table class="bs-table"><thead><tr><th>Outlet</th><th>Today</th><th>Last 7 days</th><th>This month</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
      '<div class="bs-row" style="margin-top:12px"><label style="max-width:170px">From<input type="date" id="bsFrom" value="' + iso(new Date(t.getFullYear(), t.getMonth(), 1)) + '"></label><label style="max-width:170px">To<input type="date" id="bsTo" value="' + iso(t) + '"></label>' +
      '<button class="btn" type="button" data-bs-export>' + ic('download') + 'Download CSV</button></div></div>';
  }

  function trackingCard() {
    return '<div class="bs-card"><h3>' + ic('bar_chart') + 'Tracking on the brand pages</h3><p class="bs-sub">For the outlet picker. Each outlet\'s own pages keep the codes set in that outlet\'s SEO settings.</p>' +
      '<div class="bs-row"><label>Google Analytics ID<input type="text" id="bsGa" value="' + esc(B.ga_id || '') + '" placeholder="G-XXXXXXXXXX"></label>' +
      '<label>Meta Pixel ID<input type="text" id="bsPx" value="' + esc(B.meta_pixel_id || '') + '"></label>' +
      '<label>Search Console code<input type="text" id="bsGsc" value="' + esc(B.gsc_verification || '') + '" placeholder="the HTML tag code"></label></div>' +
      '<button class="btn" type="button" data-bs-track-save>' + ic('save') + 'Save</button></div>';
  }

  function draw() {
    box.innerHTML = '<style>' + CSS + '</style>' + hero() + outletsCard() + '<div class="bs-grid2">' + domainCard() + gatewayCard() + '</div>' + paymentsCard() + trackingCard();
  }

  function load(query) {
    return fetch(API + (query || ''), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.success) { box.innerHTML = '<div class="error">' + esc(d.message || 'Could not load the brand.') + '</div>'; return; }
      if (!d.brand) { box.innerHTML = ''; return; }
      B = d.brand;
      draw();
    }).catch(function () { box.innerHTML = '<div class="error">Could not load the brand settings. Check your internet.</div>'; });
  }

  function post(body, btn, okMsg) {
    if (busy) return Promise.resolve(null);
    busy = true;
    if (btn) btn.disabled = true;
    return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); }).then(function (d) {
        busy = false;
        if (btn) btn.disabled = false;
        if (!d.success) { if (d.code !== 'not_ready') toast(d.message || 'Could not save', true); return d; }
        B = d.brand; draw(); if (okMsg) toast(okMsg);
        return d;
      }).catch(function () { busy = false; if (btn) btn.disabled = false; toast('Could not reach RestroGrow. Check your internet.', true); return null; });
  }

  function outletRows() {
    return Array.prototype.map.call(box.querySelectorAll('tr[data-rid]'), function (tr) {
      return { restaurant_id: tr.dataset.rid, label: tr.querySelector('.bs-label').value, url_key: tr.querySelector('.bs-urlkey').value,
        is_listed: tr.querySelector('.bs-listed').checked, gateway: tr.querySelector('.bs-gw').value, main: tr.querySelector('.bs-main').checked };
    });
  }

  box.addEventListener('click', function (e) {
    var t = e.target.closest('button');
    if (!t || !B) return;
    if (t.hasAttribute('data-bs-up')) {
      var i = +t.getAttribute('data-bs-up'), rows = outletRows();
      B.outlets = rows.map(function (r) { var o = B.outlets.filter(function (x) { return x.restaurant_id === r.restaurant_id; })[0]; return Object.assign({}, o, r); });
      var tmp = B.outlets[i - 1]; B.outlets[i - 1] = B.outlets[i]; B.outlets[i] = tmp;
      draw(); return;
    }
    if (t.hasAttribute('data-bs-save-outlets')) {
      var rs = outletRows(), main = (rs.filter(function (r) { return r.main; })[0] || rs[0] || {}).restaurant_id;
      post({ action: 'outlets', outlets: rs }, t).then(function (d) {
        if (d && d.success) post({ action: 'details', tagline: (document.getElementById('bsTagline') || {}).value || '', default_restaurant_id: main }, null, 'Outlets saved');
      });
      return;
    }
    if (t.hasAttribute('data-bs-domain-save')) { post({ action: 'domain', domain: document.getElementById('bsDomain').value }, t, 'Domain saved. Now add the DNS records.'); return; }
    if (t.hasAttribute('data-bs-domain-check')) { t.disabled = true; load('?check=1').then(function () { toast('Checked'); }); return; }
    if (t.hasAttribute('data-bs-domain-switch')) {
      var on = t.getAttribute('data-bs-domain-switch') === '1';
      post({ action: 'domain_switch', on: on }, t, on ? 'Your domain is live' : 'Switched off').then(function (d) {
        if (d && d.code === 'not_ready' && window.confirm('Your domain isn’t showing your brand website yet (' + d.check.dns.status + ' / ' + d.check.site.status + '). Switch it on anyway?')) post({ action: 'domain_switch', on: true, force: true }, t, 'Your domain is live');
      });
      return;
    }
    if (t.hasAttribute('data-bs-gw-save')) { post({ action: 'gateway', client_id: document.getElementById('bsClientId').value, client_secret: document.getElementById('bsSecret').value, environment: document.getElementById('bsEnv').value }, t, 'Payment keys saved'); return; }
    if (t.hasAttribute('data-bs-gw-clear')) { if (window.confirm('Remove the brand payment keys? Every outlet goes back to its own payment setting.')) post({ action: 'gateway', client_id: '' }, t, 'Keys removed'); return; }
    if (t.hasAttribute('data-bs-track-save')) { post({ action: 'tracking', ga_id: document.getElementById('bsGa').value, meta_pixel_id: document.getElementById('bsPx').value, gsc_verification: document.getElementById('bsGsc').value }, t, 'Saved'); return; }
    if (t.hasAttribute('data-bs-export')) { location.href = API + '?export=csv&from=' + encodeURIComponent(document.getElementById('bsFrom').value) + '&to=' + encodeURIComponent(document.getElementById('bsTo').value); }
  });

  load();
})();
