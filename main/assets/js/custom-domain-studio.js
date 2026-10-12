/*
 * Customer Website › Custom Domain. Two ways to reach customers on your own web address:
 *  1. Own domain: save it, add one DNS record, check it really shows your website, then
 *     switch it on (RestroGrow link + every table QR code then open the domain).
 *  2. Add to your website: copy the embed code, with a live phone / desktop preview.
 * Server: api/custom_domain.php. Styles: cd-* in assets/css/studio-ui.css.
 */
(function () {
  'use strict';
  var API = '../api/custom_domain.php';
  var S = { data: null, tab: 'domain', check: null, checking: false, editing: false, saving: false, switching: false, confirmOn: false, device: 'phone', previewLoaded: false };
  var root = null;
  var esc = function (s) { return window.SX ? SX.esc(s) : String(s == null ? '' : s); };
  var notify = function (m, t) { if (window.SX) SX.notify(m, t); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };

  function api(method, body, query) {
    return fetch(API + (query || ''), method === 'GET' ? { cache: 'no-store' } : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { success: false, message: 'Unexpected reply from the server' }; }); })
      .catch(function () { return { success: false, message: 'No connection. Please try again.' }; });
  }

  function rgLink() {
    var p = window.location.pathname, base = window.location.origin + p.substring(0, p.indexOf('/main/'));
    if (window.restaurantWebsiteSlug) return base + '/' + encodeURIComponent(window.restaurantWebsiteSlug);
    return base + '/main/website/index.php?restaurant_id=' + encodeURIComponent(S.data ? S.data.restaurant_id : '');
  }
  function domainUrl() { return 'https://' + S.data.domain; }

  // keep the rest of the dashboard (QR codes, "Customer Website" link) in step with the switch
  function syncGlobals() {
    var d = S.data;
    window.restaurantCustomDomain = d.domain || '';
    window.restaurantEmbedEnabled = !!d.enabled;
    var link = document.querySelector('.nav-link[target="_blank"] .nav-label');
    link = link && /Customer Website/.test(link.textContent) ? link.parentNode : null;
    if (link) link.href = d.live ? domainUrl() + '/' : rgLink();
  }

  function copy(text, btn) {
    var done = function () {
      if (!btn) return notify('Copied', 'success');
      var old = btn.innerHTML; btn.innerHTML = ic('check') + 'Copied'; btn.classList.add('cd-copied');
      setTimeout(function () { btn.innerHTML = old; btn.classList.remove('cd-copied'); }, 1600);
    };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, function () { fallback(); });
    else fallback();
    function fallback() { var t = document.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); done(); } catch (e) { notify('Could not copy. Select the text and copy it.', 'error'); } t.remove(); }
  }

  // ── sections ──
  function hero() {
    var d = S.data, live = d.live;
    var url = live ? domainUrl() : rgLink();
    var shown = url.replace(/^https?:\/\//, '');
    return '<section class="cd-hero' + (live ? ' live' : '') + '">' +
      '<span class="cd-hero-ic">' + ic(live ? 'verified' : 'language') + '</span>' +
      '<div class="cd-hero-main"><small>' + (live ? 'Customers are on your own domain' : 'Your website address') + '</small>' +
      '<a class="cd-url" href="' + esc(url) + '" target="_blank" rel="noopener" title="' + esc(url) + '">' + esc(shown) + '</a>' +
      '<span class="cd-hero-sub">' + (live ? 'Your RestroGrow link and every table QR code open this address.' : d.domain ? 'Your own domain ' + esc(d.domain) + ' is saved but not switched on yet.' : 'This is your RestroGrow link. You can also use your own domain, like order.yourrestaurant.com.') + '</span></div>' +
      '<div class="cd-hero-acts"><button type="button" class="btn btn-secondary" data-cd-copy="' + esc(url) + '">' + ic('content_copy') + 'Copy link</button>' +
      '<a class="btn btn-primary" href="' + esc(url) + '" target="_blank" rel="noopener">' + ic('open_in_new') + 'Open</a></div></section>';
  }

  function tabs() {
    return '<div class="sx-seg cd-tabs" role="tablist">' +
      '<button type="button" role="tab" data-cd-tab="domain" class="' + (S.tab === 'domain' ? 'on' : '') + '">' + ic('dns') + 'Own domain</button>' +
      '<button type="button" role="tab" data-cd-tab="embed" class="' + (S.tab === 'embed' ? 'on' : '') + '">' + ic('code') + 'Add to your website</button></div>';
  }

  function stepState(n) {
    var d = S.data, c = S.check;
    if (n === 1) return d.domain ? 'done' : 'now';
    if (n === 2) return !d.domain ? 'todo' : (c && c.ready) || d.live ? 'done' : 'now';
    return !d.domain ? 'todo' : d.live ? 'done' : 'now';
  }
  function step(n, title, sub, body) {
    var st = stepState(n);
    return '<li class="cd-step ' + st + '"><span class="cd-num">' + (st === 'done' ? ic('check') : n) + '</span>' +
      '<div class="cd-step-main"><div class="cd-step-h"><b>' + title + '</b>' + (sub ? '<small>' + sub + '</small>' : '') + '</div>' + (st === 'todo' ? '' : body) + '</div></li>';
  }

  function domainStep() {
    var d = S.data;
    if (d.domain && !S.editing) {
      return '<div class="cd-saved"><span class="cd-saved-ic">' + ic('public') + '</span><b>' + esc(d.domain) + '</b>' +
        '<button type="button" class="sx-link" data-cd-edit>' + ic('edit') + 'Change</button>' +
        '<button type="button" class="sx-link sx-link-danger" data-cd-remove>' + ic('delete') + (S.removeAsk ? 'Click again to remove' : 'Remove') + '</button></div>';
    }
    return '<form class="cd-form" data-cd-form><div class="cd-input"><span class="cd-https">https://</span><input type="text" id="cdDomain" inputmode="url" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="order.yourrestaurant.com" value="' + esc(d.domain) + '"></div>' +
      '<button type="submit" class="btn btn-primary"' + (S.saving ? ' disabled' : '') + '>' + (S.saving ? 'Saving…' : 'Save domain') + '</button>' +
      (d.domain ? '<button type="button" class="btn btn-secondary" data-cd-cancel>Cancel</button>' : '') + '</form>' +
      '<p class="sx-muted cd-tip">' + ic('lightbulb') + '<span>Tip: use a sub-domain such as <b>order.</b>yourrestaurant.com, so your main website stays as it is.</span></p>';
  }

  function recordsStep() {
    var d = S.data;
    var rows = d.records.map(function (r) {
      return '<tr><td data-l="Type"><span class="cd-type">' + esc(r.type) + '</span></td>' +
        '<td data-l="Name / Host"><code>' + esc(r.name) + '</code><button type="button" class="sx-icon-btn" title="Copy" data-cd-copy="' + esc(r.name) + '">' + ic('content_copy') + '</button></td>' +
        '<td data-l="Points to / Value"><code>' + esc(r.value) + '</code><button type="button" class="sx-icon-btn" title="Copy" data-cd-copy="' + esc(r.value) + '">' + ic('content_copy') + '</button></td>' +
        '<td data-l="TTL" class="cd-ttl">Automatic</td></tr>' +
        '<tr class="cd-rec-note"><td colspan="4">' + esc(r.note) + '</td></tr>';
    }).join('');
    return '<div class="cd-table-wrap"><table class="cd-table"><thead><tr><th>Type</th><th>Name / Host</th><th>Points to / Value</th><th>TTL</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
      '<ul class="cd-how">' +
      '<li>' + ic('login') + '<span>Log in where you bought <b>' + esc(d.root || d.domain) + '</b> (GoDaddy, Hostinger, Namecheap, BigRock…) and open its <b>DNS settings</b>.</span></li>' +
      '<li>' + ic('swap_horiz') + '<span>If a record with the same name is already there, edit it instead of adding a second one.</span></li>' +
      '<li>' + ic('schedule') + '<span>It usually starts working within an hour, but can take up to 48 hours.</span></li></ul>';
  }

  function checkRow(state, icon, title, text) {
    return '<div class="cd-check-row ' + state + '"><span class="cd-check-ic">' + ic(icon) + '</span><div><b>' + title + '</b><small>' + text + '</small></div></div>';
  }
  function checkBox() {
    var c = S.check, d = S.data;
    if (S.checking) return '<div class="cd-checks"><div class="sx-loading"><span></span><span></span><span></span></div><p class="sx-muted cd-center">Looking up ' + esc(d.domain) + '…</p></div>';
    if (!c) return '<div class="cd-checks cd-checks-empty"><span class="sx-muted">Added the record? Check whether your domain is ready.</span></div>';
    var dns = c.dns || {}, site = c.site || {}, found = (dns.found || []).map(function (f) { return f.replace(/^A /, '').replace(/^CNAME /, ''); }).join(', ');
    var rowsHtml = '';
    if (dns.status === 'missing') rowsHtml += checkRow('bad', 'dns', 'No DNS record found yet', 'Add the record above. If you just did, wait a little and check again.');
    else rowsHtml += checkRow(c.ready || dns.status === 'ok' ? 'ok' : 'warn', 'dns', 'Domain found', 'Points to ' + esc(found) + '.');
    var siteMap = {
      ok: ['ok', 'storefront', 'Your website shows here', 'https://' + esc(d.domain) + ' opens ' + esc(d.restaurant_name) + ' with a secure padlock.'],
      no_ssl: ['warn', 'lock_open', 'Secure padlock (SSL) is missing', 'The domain reaches a server, but without a valid certificate browsers will warn your customers. It is usually added automatically within a few hours of the record working.'],
      other_site: ['bad', 'web_asset_off', 'A different website shows here', 'The domain doesn’t show your restaurant yet. Check that the record value is exactly as above.'],
      unreachable: ['bad', 'cloud_off', 'Can’t be reached yet', 'The record may still be spreading. Try again in a while.'],
      skipped: ['muted', 'storefront', 'Website not checked', 'This is checked once the domain is found.']
    };
    var m = siteMap[site.status] || siteMap.skipped;
    rowsHtml += checkRow(m[0], m[1], m[2], m[3]);
    var when = c.checked_at ? new Date(c.checked_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
    return '<div class="cd-checks">' + rowsHtml + '<div class="cd-checks-foot">' + (c.ready ? '<span class="sx-pill ok">' + ic('check_circle') + 'Ready</span>' : '<span class="sx-pill wait">' + ic('hourglass_top') + 'Not ready yet</span>') + '<span class="sx-muted">Checked ' + esc(when) + '</span></div></div>';
  }

  function switchStep() {
    var d = S.data, c = S.check;
    var html = '<div class="cd-check-acts"><button type="button" class="btn btn-secondary" data-cd-check' + (S.checking ? ' disabled' : '') + '>' + ic('refresh') + (c ? 'Check again' : 'Check my domain') + '</button></div>' + checkBox();
    html += '<label class="sx-switch-row cd-switch' + (d.enabled ? ' on' : '') + '"><span><b>Send customers to ' + esc(d.domain) + '</b>' +
      '<small>When on, your RestroGrow link and every table QR code open your domain. Printed QR codes keep working.</small></span>' +
      '<span class="up-switch"><input type="checkbox" id="cdSwitch"' + (d.enabled ? ' checked' : '') + (S.switching ? ' disabled' : '') + '><span></span></span></label>';
    if (S.confirmOn) {
      html += '<div class="sx-note warn cd-confirm">' + ic('warning') + '<div><b>Your domain isn’t showing your website yet.</b> If you switch on now, customers who open your link or scan a QR code may see an error page until it is ready.' +
        '<div class="cd-confirm-acts"><button type="button" class="btn btn-secondary btn-sm" data-cd-confirm="no">Wait until it’s ready</button><button type="button" class="btn btn-sm sx-danger" data-cd-confirm="yes">Switch on anyway</button></div></div></div>';
    }
    return html;
  }

  function domainPanel() {
    var d = S.data;
    return '<div class="cd-panel"><ol class="cd-steps">' +
      step(1, 'Your domain', 'The web address customers will type', domainStep()) +
      step(2, 'Add this record where you bought the domain', d.domain ? 'One-time setup at your domain company' : 'Save your domain first', d.domain ? recordsStep() : '') +
      step(3, 'Check and switch on', 'We open your domain to make sure it shows your website', d.domain ? switchStep() : '') +
      '</ol></div>';
  }

  function embedPanel() {
    var d = S.data;
    return '<div class="cd-panel cd-embed">' +
      '<div class="cd-embed-l">' +
        '<div class="cd-code-h"><span class="sx-ic" style="--c:#2563eb">' + ic('code') + '</span><div><b>Your embed code</b><small>Shows your full menu, cart and ordering inside any website.</small></div></div>' +
        '<div class="cd-code"><pre>' + esc(d.embed_code) + '</pre><button type="button" class="btn btn-primary btn-sm" data-cd-copy-code>' + ic('content_copy') + 'Copy code</button></div>' +
        '<b class="cd-sub">Where to paste it</b>' +
        '<ul class="cd-how">' +
          '<li>' + ic('web') + '<span><b>WordPress:</b> edit the page, add a <b>Custom HTML</b> block and paste.</span></li>' +
          '<li>' + ic('dashboard_customize') + '<span><b>Wix:</b> Add › Embed code › <b>Embed HTML</b>, then paste.</span></li>' +
          '<li>' + ic('html') + '<span><b>Your own HTML site:</b> paste it where the menu should appear.</span></li>' +
        '</ul>' +
        '<div class="sx-note">' + ic('autorenew') + '<span>The menu loads from RestroGrow, so changes to items, prices and timings show up there by themselves. Nothing to update on your website.</span></div>' +
      '</div>' +
      '<div class="cd-embed-r">' +
        '<div class="cd-prev-h"><b>Preview</b><div class="sx-seg sx-seg-icons"><button type="button" data-cd-device="phone" class="' + (S.device === 'phone' ? 'on' : '') + '" title="Phone">' + ic('smartphone') + '</button><button type="button" data-cd-device="desktop" class="' + (S.device === 'desktop' ? 'on' : '') + '" title="Desktop">' + ic('desktop_windows') + '</button></div></div>' +
        '<div class="cd-device ' + S.device + '"><div class="cd-device-bar"><i></i><i></i><i></i><span>yourwebsite.com</span></div><iframe id="cdPreview" title="Menu preview" loading="lazy"></iframe></div>' +
      '</div></div>';
  }

  function render() {
    if (!root) return;
    if (!S.data) { root.innerHTML = '<div class="sx-loading"><span></span><span></span><span></span></div>'; return; }
    root.innerHTML = hero() + tabs() + (S.tab === 'domain' ? domainPanel() : embedPanel());
    if (S.tab === 'embed') {
      var f = document.getElementById('cdPreview');
      if (f) f.src = S.data.preview_url;
    }
  }

  // ── actions ──
  function load(withCheck) {
    if (withCheck) { S.checking = true; render(); }
    return api('GET', null, withCheck ? '?check=1' : '').then(function (r) {
      S.checking = false;
      if (!r.success) { root.innerHTML = '<div class="sx-empty">' + ic('error') + '<b>Couldn’t load your domain settings</b><span>' + esc(r.message || 'Please refresh and try again.') + '</span><button type="button" class="btn btn-secondary" data-cd-retry>Try again</button></div>'; return; }
      S.data = r.data;
      if (r.data.check) S.check = r.data.check;
      syncGlobals();
      render();
    });
  }

  function save(value) {
    S.saving = true; render();
    api('POST', { action: 'save', domain: value }).then(function (r) {
      S.saving = false;
      if (!r.success) { render(); notify(r.message || 'Could not save', 'error'); var i = document.getElementById('cdDomain'); if (i) { i.value = value; i.focus(); } return; }
      S.editing = false; S.check = null; S.confirmOn = false; S.removeAsk = false;
      notify(r.domain ? (r.switched_off ? 'Domain changed. Customers use your RestroGrow link until the new one is checked.' : 'Domain saved. Now add the DNS record.') : 'Domain removed', r.switched_off ? 'warning' : 'success');
      load(!!r.domain);
    });
  }

  function setSwitch(on, force) {
    S.switching = true; S.confirmOn = false; render();
    api('POST', { action: 'switch', on: on, force: !!force }).then(function (r) {
      S.switching = false;
      if (r.code === 'not_ready') { S.check = r.check; S.confirmOn = true; render(); return; }
      if (!r.success) { render(); notify(r.message || 'Could not change this', 'error'); return; }
      notify(on ? 'Customers now go to ' + S.data.domain : 'Customers use your RestroGrow link again', 'success');
      load(false);
    });
  }

  function bind() {
    root.addEventListener('click', function (e) {
      var t = e.target.closest('button, [data-cd-tab]');
      if (!t || !root.contains(t)) return;
      if (t.hasAttribute('data-cd-tab')) { S.tab = t.getAttribute('data-cd-tab'); render(); return; }
      if (t.hasAttribute('data-cd-copy')) { copy(t.getAttribute('data-cd-copy'), t.classList.contains('btn') ? t : null); return; }
      if (t.hasAttribute('data-cd-copy-code')) { copy(S.data.embed_code, t); return; }
      if (t.hasAttribute('data-cd-edit')) { S.editing = true; S.removeAsk = false; render(); var i = document.getElementById('cdDomain'); if (i) { i.focus(); i.select(); } return; }
      if (t.hasAttribute('data-cd-cancel')) { S.editing = false; render(); return; }
      if (t.hasAttribute('data-cd-remove')) {
        if (!S.removeAsk) { S.removeAsk = true; render(); setTimeout(function () { if (S.removeAsk) { S.removeAsk = false; render(); } }, 4000); return; }
        save(''); return;
      }
      if (t.hasAttribute('data-cd-check')) { load(true); return; }
      if (t.hasAttribute('data-cd-confirm')) { if (t.getAttribute('data-cd-confirm') === 'yes') setSwitch(true, true); else { S.confirmOn = false; render(); } return; }
      if (t.hasAttribute('data-cd-device')) { S.device = t.getAttribute('data-cd-device'); var dev = root.querySelector('.cd-device'); if (dev) dev.className = 'cd-device ' + S.device; root.querySelectorAll('[data-cd-device]').forEach(function (b) { b.classList.toggle('on', b === t); }); return; }
      if (t.hasAttribute('data-cd-retry')) { load(false); }
    });
    root.addEventListener('submit', function (e) {
      if (!e.target.matches('[data-cd-form]')) return;
      e.preventDefault();
      var v = (document.getElementById('cdDomain') || {}).value || '';
      if (!v.trim()) { notify('Type your domain first, like order.yourrestaurant.com', 'error'); return; }
      save(v.trim());
    });
    root.addEventListener('change', function (e) {
      if (e.target.id === 'cdSwitch') { var on = e.target.checked; e.target.checked = !on; setSwitch(on, false); }
    });
  }

  window.loadEmbedSettings = function () {
    root = document.getElementById('cdRoot');
    if (!root) return;
    if (!root._cdBound) { root._cdBound = true; bind(); }
    S.confirmOn = false; S.removeAsk = false;
    if (!S.data) render();
    // check automatically when a domain is saved but not switched on yet
    load(false).then(function () { if (S.data && S.data.domain && !S.data.live && !S.check) load(true); });
  };
})();
