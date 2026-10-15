/**
 * SEO (sidebar › SEO): health score with one-tap fixes, a title + description per website page with a live
 * Google result and WhatsApp share preview (AI can write them), cuisine / price range / keywords, the share
 * picture, Search Console / Analytics / Meta Pixel IDs, sitemap and what Google reads from the site.
 * Search performance (needs Google Search Console access) shows as Coming soon.
 * Data: api/seo.php (config/seo_helpers.php puts it all in the website's <head>). Styles: seo-* in studio-ui.css.
 */
(function () {
  'use strict';
  var API = '../api/seo.php';
  var SC_API = '../api/seo_sc.php';   // Google Search Console (Search performance card)
  var S = null;           // GET result
  var F = null;           // the form being edited
  var cur = 'home';       // page being edited
  var dirty = false;

  var esc = function (s) { return window.SX ? SX.esc(s) : String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var notify = function (m, t) { if (window.SX) SX.notify(m, t); };
  var CUISINES = ['North Indian', 'South Indian', 'Chinese', 'Biryani', 'Cafe', 'Bakery', 'Pizza', 'Fast Food', 'Street Food', 'Mughlai', 'Kerala', 'Andhra', 'Desserts', 'Continental', 'Italian', 'Healthy', 'Beverages'];
  var PAGE_PATH = { home: '', menu: 'menu', about: 'about', contact: 'contact' };

  function get() { return fetch(API, { cache: 'no-store' }).then(function (r) { return r.json(); }).catch(function () { return { success: false, message: 'No connection. Please try again.' }; }); }
  function post(body) { return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(function (r) { return r.json(); }).catch(function () { return { success: false, message: 'No connection. Please try again.' }; }); }

  window.loadSeo = function () {
    var root = document.getElementById('seoRoot');
    if (!root) return;
    if (dirty && S) return draw();
    if (!S) root.innerHTML = '<div class="cbx-loading"><span></span><span></span><span></span></div>';
    get().then(function (d) {
      if (!d.success) { root.innerHTML = '<div class="sx-note warn">' + ic('warning') + '<span>' + esc(d.message || 'Could not load') + '</span></div>'; return; }
      S = d;
      var st = d.settings;
      F = { pages: {}, keywords: st.keywords, cuisine: st.cuisine, price_range: st.price_range, gsc_verification: st.gsc_verification, ga_id: st.ga_id, meta_pixel_id: st.meta_pixel_id, noindex: st.noindex };
      Object.keys(d.pages).forEach(function (p) { var v = (st.pages && st.pages[p]) || {}; F.pages[p] = { title: v.title || '', description: v.description || '' }; });
      dirty = false;
      draw();
    });
  };

  /* what's live for a page: the typed text, else the default */
  function eff(p, k) { var v = (F.pages[p] && F.pages[p][k] || '').trim(); return v || S.defaults[p][k]; }
  function pageUrl(p) { return S.restaurant.site + (PAGE_PATH[p] ? '/' + PAGE_PATH[p] : ''); }
  function cut(t, n) { t = String(t || ''); return t.length > n ? t.slice(0, n - 1).replace(/[\s,.;-]+$/, '') + '…' : t; }
  function meter(len, lo, hi, max) {
    var cls = !len ? '' : (len >= lo && len <= hi ? 'ok' : (len > max ? 'bad' : 'warn'));
    var tip = !len ? 'Using the default' : (len < lo ? 'A bit short' : (len > max ? 'Too long, Google will cut it' : (len > hi ? 'Slightly long' : 'Good length')));
    return '<span class="seo-count ' + cls + '"><i style="--p:' + Math.min(100, Math.round(100 * len / max)) + '%"></i>' + len + ' / ' + hi + ' · ' + tip + '</span>';
  }

  function draw() {
    var root = document.getElementById('seoRoot');
    if (!root || !S) return;
    var ro = !S.can_edit;
    root.innerHTML =
      head() +
      '<div class="seo-grid">' +
        pagesCard(ro) +
        health() +
        businessCard(ro) +
        shareCard(ro) +
        trackingCard(ro) +
        googleCard() +
        searchConsoleCard() +
      '</div>' +
      (ro ? '' : '<div class="seo-bar' + (dirty ? ' show' : '') + '" id="seoBar"><span>' + ic('edit_note') + 'You have unsaved changes</span><button type="button" class="btn btn-secondary btn-sm" data-seo-undo>Discard</button><button type="button" class="btn btn-primary btn-sm" data-seo-save>' + ic('check') + 'Save</button></div>');
    paintPreviews();
    // Search performance fills itself in (only when the server has the OAuth client)
    var sc = S.search_console;
    if (sc && sc.available) loadSearchConsole();
  }

  function head() {
    var sc = S.score, tone = sc >= 80 ? 'ok' : (sc >= 50 ? 'warn' : 'bad');
    var left = S.checks.filter(function (c) { return !c.ok; }).length;
    return '<div class="seo-head">' +
      '<div class="seo-ring ' + tone + '" style="--p:' + sc + '"><b>' + sc + '</b><small>/ 100</small></div>' +
      '<div class="seo-head-tx"><h2>' + (sc >= 80 ? 'Your site is in great shape for Google' : (sc >= 50 ? 'Good start. A few fixes will lift you higher' : 'Let’s get your restaurant found on Google')) + '</h2>' +
        '<p>' + (left ? left + ' thing' + (left > 1 ? 's' : '') + ' left to fix below. ' : 'Everything on the checklist is done. ') + 'Changes go live on your website as soon as you save.</p>' +
        '<a class="seo-site" href="' + esc(S.restaurant.site) + '" target="_blank" rel="noopener">' + ic('language') + esc(S.restaurant.host) + ic('open_in_new') + '</a></div>' +
      (S.can_edit ? '<button type="button" class="btn btn-secondary seo-ai-all" data-seo-ai="all">' + ic('auto_awesome') + 'Write all pages for me</button>' : '') +
    '</div>';
  }

  function health() {
    var rows = S.checks.slice().sort(function (a, b) { return (a.ok - b.ok) || (b.w - a.w); }).map(function (c, i) {
      return '<li class="' + (c.ok ? 'ok' : 'todo') + '" style="--d:' + (i * 30) + 'ms"><span class="seo-ck">' + ic(c.ok ? 'check_circle' : 'error') + '</span>' +
        '<span class="seo-ck-tx"><b>' + esc(c.label) + '</b>' + (c.ok ? '' : '<small>' + esc(c.tip) + '</small>') + '</span>' +
        (c.ok ? '<em>+' + c.w + '</em>' : '<button type="button" class="sx-link" data-seo-fix="' + esc(c.id) + '">Fix' + ic('chevron_right') + '</button>') + '</li>';
    }).join('');
    return '<section class="seo-card seo-health"><h3>' + ic('task_alt') + 'SEO checklist</h3><p class="seo-sub">Each tick makes it easier for hungry people nearby to find you.</p><ul class="seo-checks">' + rows + '</ul></section>';
  }

  function pagesCard(ro) {
    var p = cur, v = F.pages[p], d = S.defaults[p];
    var tabs = Object.keys(S.pages).map(function (k) {
      var custom = F.pages[k].title || F.pages[k].description;
      return '<button type="button" class="' + (k === p ? 'on' : '') + '" data-seo-page="' + k + '">' + esc(S.pages[k]) + (custom ? '<em>●</em>' : '') + '</button>';
    }).join('');
    return '<section class="seo-card seo-pages" id="seoPages"><div class="seo-card-h"><h3>' + ic('search') + 'How each page shows on Google</h3><div class="sx-seg">' + tabs + '</div></div>' +
      '<div class="seo-pages-in"><div class="seo-fields">' +
        '<label class="seo-f"><span>Title <small>shown as the blue link</small></span><input type="text" id="seoTitle" maxlength="90" value="' + esc(v.title) + '" placeholder="' + esc(d.title) + '"' + (ro ? ' disabled' : '') + '><span id="seoTitleCount">' + meter(v.title.length, 30, 60, 65) + '</span></label>' +
        '<label class="seo-f"><span>Description <small>the grey text under it</small></span><textarea id="seoDesc" rows="4" maxlength="300" placeholder="' + esc(d.description) + '"' + (ro ? ' disabled' : '') + '>' + esc(v.description) + '</textarea><span id="seoDescCount">' + meter(v.description.length, 120, 155, 165) + '</span></label>' +
        (ro ? '' : '<div class="seo-row"><button type="button" class="btn btn-secondary btn-sm" data-seo-ai="' + p + '">' + ic('auto_awesome') + 'Write it for me</button>' + (v.title || v.description ? '<button type="button" class="sx-link" data-seo-default>' + ic('refresh') + 'Use the default</button>' : '<span class="sx-muted">Empty fields use the grey default.</span>') + '</div>') +
      '</div><div class="seo-previews">' +
        '<div class="seo-pv-l">' + ic('search') + 'Google</div><div class="seo-serp" id="seoSerp"></div>' +
        '<div class="seo-pv-l">' + ic('share') + 'Shared on WhatsApp</div><div class="seo-wa" id="seoWa"></div>' +
      '</div></div></section>';
  }

  function paintPreviews() {
    var serp = document.getElementById('seoSerp'), wa = document.getElementById('seoWa');
    if (!serp || !wa) return;
    var r = S.restaurant, t = eff(cur, 'title'), dsc = eff(cur, 'description'), img = (S.settings.share_image || r.logo);
    var crumbs = r.host.split('/').join(' › ') + (PAGE_PATH[cur] ? ' › ' + PAGE_PATH[cur] : '');
    serp.innerHTML = '<div class="seo-serp-site">' + (r.logo ? '<img src="' + esc(r.logo) + '" alt="">' : '<i>' + esc((r.name || 'R').charAt(0)) + '</i>') + '<span><b>' + esc(r.name) + '</b><small>' + esc(crumbs) + '</small></span></div>' +
      '<a class="seo-serp-t">' + esc(cut(t, 62)) + '</a>' +
      (cur === 'home' && r.rating ? '<div class="seo-serp-rate"><span class="seo-stars" style="--f:' + (r.rating / 5 * 100) + '%"></span>' + r.rating.toFixed(1) + ' (' + r.rating_count.toLocaleString('en-IN') + ') · ' + esc(F.price_range || '₹₹') + ' · ' + esc(F.cuisine || 'Restaurant') + '</div>' : '') +
      '<p>' + esc(cut(dsc, 158)) + '</p>';
    wa.innerHTML = '<div class="seo-wa-b"><div class="seo-wa-card">' + (img ? '<img src="' + esc(img) + '" alt="">' : '') + '<div><b>' + esc(cut(t, 70)) + '</b><small>' + esc(cut(dsc, 90)) + '</small><em>' + esc(r.host.split('/')[0]) + '</em></div></div>' +
      '<span class="seo-wa-link">' + esc(pageUrl(cur)) + '</span><time>' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) + ' ✓✓</time></div>';
  }

  function businessCard(ro) {
    var dis = ro ? ' disabled' : '';
    var chips = CUISINES.map(function (c) { var on = (',' + (F.cuisine || '').toLowerCase().replace(/\s*,\s*/g, ',') + ',').indexOf(',' + c.toLowerCase() + ',') >= 0; return '<button type="button" class="seo-chip' + (on ? ' on' : '') + '" data-seo-cuisine="' + esc(c) + '"' + dis + '>' + esc(c) + '</button>'; }).join('');
    var price = ['₹', '₹₹', '₹₹₹', '₹₹₹₹'].map(function (p, i) { return '<button type="button" class="' + (F.price_range === p ? 'on' : '') + '" data-seo-price="' + p + '"' + dis + '>' + p + '<em>' + ['Budget', 'Moderate', 'Pricey', 'Fine dining'][i] + '</em></button>'; }).join('');
    return '<section class="seo-card" id="seoBusiness"><h3>' + ic('restaurant_menu') + 'What you serve</h3><p class="seo-sub">Google uses this to match searches like “biryani near me”.</p>' +
      '<label class="seo-f"><span>Cuisine</span><input type="text" id="seoCuisine" maxlength="120" value="' + esc(F.cuisine) + '" placeholder="e.g. North Indian, Chinese"' + dis + '></label><div class="seo-chips">' + chips + '</div>' +
      '<div class="seo-f"><span>Price range</span><div class="sx-seg seo-price">' + price + '</div></div>' +
      '<label class="seo-f"><span>Focus keywords <small>what people type to find you, comma-separated</small></span><input type="text" id="seoKeywords" maxlength="300" value="' + esc(F.keywords) + '" placeholder="e.g. best biryani in Koramangala, family restaurant"' + dis + '></label>' +
    '</section>';
  }

  function shareCard(ro) {
    var img = S.settings.share_image;
    return '<section class="seo-card" id="seoShare"><h3>' + ic('image') + 'Share picture</h3><p class="seo-sub">Shown when someone shares your website on WhatsApp, Facebook or Instagram. Best: a bright food photo, 1200 × 630.</p>' +
      '<div class="seo-share">' + (img ? '<img src="' + esc(img) + '" alt="Share picture">' : (S.restaurant.logo ? '<div class="seo-share-fb"><img src="' + esc(S.restaurant.logo) + '" alt=""><small>Using your logo for now</small></div>' : '<div class="seo-share-fb">' + ic('add_photo_alternate') + '<small>No picture yet</small></div>')) + '</div>' +
      (ro ? '' : '<div class="seo-row"><label class="btn btn-secondary btn-sm seo-upload">' + ic('upload') + (img ? 'Change picture' : 'Upload picture') + '<input type="file" id="seoShareFile" accept="image/jpeg,image/png,image/webp" hidden></label>' + (img ? '<button type="button" class="sx-link sx-link-danger" data-seo-rmimg>' + ic('delete') + 'Remove</button>' : '') + '</div>') +
    '</section>';
  }

  function trackingCard(ro) {
    var dis = ro ? ' disabled' : '', own = S.restaurant.own_domain;
    return '<section class="seo-card" id="seoTracking"><h3>' + ic('analytics') + 'Google &amp; tracking</h3><p class="seo-sub">Paste the IDs and we add the code to every page of your website.</p>' +
      '<label class="seo-f"><span>Google Search Console code <small>' + (own ? 'Settings › Ownership verification › HTML tag' : 'only needed with your own domain') + '</small></span><input type="text" id="seoGsc" value="' + esc(F.gsc_verification) + '" placeholder="Paste the code or the whole &lt;meta&gt; tag"' + dis + '></label>' +
      '<label class="seo-f"><span>Google Analytics 4 ID <small>see visitors and where they came from</small></span><input type="text" id="seoGa" value="' + esc(F.ga_id) + '" placeholder="G-XXXXXXXXXX"' + dis + '></label>' +
      '<label class="seo-f"><span>Meta Pixel ID <small>for Facebook / Instagram ads</small></span><input type="text" id="seoPixel" inputmode="numeric" value="' + esc(F.meta_pixel_id) + '" placeholder="e.g. 1234567890123456"' + dis + '></label>' +
      '<label class="seo-hide"><input type="checkbox" id="seoNoindex"' + (F.noindex ? ' checked' : '') + dis + '><span><b>Hide my website from Google</b><small>Only while you are still setting up. Leave this off to be found.</small></span></label>' +
      (F.noindex ? '<div class="sx-note warn">' + ic('visibility_off') + '<span>Your website is hidden from Google. Turn this off when you are ready.</span></div>' : '') +
    '</section>';
  }

  function googleCard() {
    var r = S.restaurant, url = S.restaurant.site;
    var items = [
      ['storefront', 'Restaurant details', 'Name, address, phone, map point'],
      ['schedule', 'Opening hours', 'So Google can show “Open now”'],
      ['restaurant_menu', 'Menu with prices', r.dishes + ' dishes'],
      ['photo_camera', 'Dish photos', r.photos + '% of dishes, listed in your sitemap'],
      ['star', 'Google rating', r.rating ? r.rating.toFixed(1) + ' from ' + r.rating_count.toLocaleString('en-IN') + ' reviews' : 'Find your restaurant in Google Business'],
      ['share', 'Share preview', 'Title, description and picture']
    ];
    return '<section class="seo-card" id="seoGoogle"><h3>' + ic('code') + 'What Google reads from your site</h3><p class="seo-sub">Added for you automatically on every page. Nothing to set up.</p>' +
      '<ul class="seo-reads">' + items.map(function (it) { return '<li>' + ic(it[0]) + '<span><b>' + esc(it[1]) + '</b><small>' + esc(it[2]) + '</small></span></li>'; }).join('') + '</ul>' +
      '<div class="seo-f"><span>Sitemap <small>' + (r.own_domain ? 'submit this in Search Console' : 'we tell Google about it for you') + '</small></span><div class="seo-copy"><code>' + esc(S.sitemap) + '</code><button type="button" class="sx-icon-btn" data-seo-copy="' + esc(S.sitemap) + '" title="Copy">' + ic('content_copy') + '</button></div></div>' +
      '<div class="seo-row"><a class="btn btn-secondary btn-sm" target="_blank" rel="noopener" href="https://search.google.com/test/rich-results?url=' + encodeURIComponent(url) + '">' + ic('verified') + 'Test with Google</a><a class="sx-link" target="_blank" rel="noopener" href="' + esc(S.sitemap) + '">View sitemap' + ic('open_in_new') + '</a></div>' +
    '</section>';
  }

  /* ───────── Search performance (Google Search Console) ───────── */
  function soonCard() {
    // Server has no OAuth client yet — keep the placeholder
    return '<section class="seo-card seo-soon"><span class="gbx-soon-tag">' + ic('bolt') + 'Coming soon</span><h3>' + ic('trending_up') + 'Search performance</h3>' +
      '<p class="seo-sub">See how many people found you on Google, what they searched, and which dishes bring the most clicks, straight from Google Search Console.</p>' +
      '<div class="seo-soon-demo" aria-hidden="true"><div><b>—</b><small>Searches showing you</small></div><div><b>—</b><small>Clicks to your site</small></div><div><b>—</b><small>Average position</small></div></div>' +
      '<div class="seo-soon-q" aria-hidden="true"><span>biryani near me</span><span>' + esc((S.restaurant.name || '').toLowerCase()) + '</span><span>best restaurant ' + esc((S.restaurant.address.split(',').slice(-2, -1)[0] || '').trim().toLowerCase() || 'nearby') + '</span></div>' +
    '</section>';
  }

  function searchConsoleCard() {
    var sc = S.search_console || { available: false };
    if (!sc.available) return soonCard();
    if (!sc.connected) {
      return '<section class="seo-card seo-sc" id="seoSc"><span class="gbx-soon-tag">' + ic('bolt') + 'New</span>' +
        '<h3>' + ic('trending_up') + 'Search performance</h3>' +
        '<p class="seo-sub">See how many people found you on Google, what they searched, and which searches bring the most clicks. Connect your Google account — we only read your Search Console, nothing is ever changed.</p>' +
        (S.can_edit ? '<div class="seo-row"><button type="button" class="btn btn-primary" data-seo-sc="connect">' + ic('link') + 'Connect Google</button></div>' : '') +
      '</section>';
    }
    return '<section class="seo-card seo-sc" id="seoSc">' +
      '<div class="seo-sc-h"><div><h3>' + ic('trending_up') + 'Search performance</h3>' +
      '<small>' + ic('account_circle') + esc(sc.google_email || 'Google connected') + '</small></div>' +
      (S.can_edit ? '<div class="seo-sc-acts">' +
        '<button type="button" class="btn btn-secondary btn-sm" data-seo-sc="refresh">' + ic('refresh') + 'Refresh</button>' +
        '<button type="button" class="btn btn-secondary btn-sm" data-seo-sc="site">' + ic('language') + 'Change site</button>' +
        '<button type="button" class="sx-link sx-link-danger" data-seo-sc="disconnect">' + ic('link_off') + 'Disconnect</button></div>' : '') +
      '</div><div id="seoScBody" class="seo-sc-body"><div class="cbx-loading"><span></span><span></span><span></span></div></div></section>';
  }

  function scStats(m) {
    var t = m.totals || {};
    var tile = function (label, val, sub) { return '<div class="seo-sc-t"><b>' + val + '</b><small>' + label + '</small>' + (sub ? '<em>' + sub + '</em>' : '') + '</div>'; };
    return '<div class="seo-sc-stats">' +
      tile('Clicks to your site', (t.clicks || 0).toLocaleString('en-IN')) +
      tile('Searches showing you', (t.impressions || 0).toLocaleString('en-IN')) +
      tile('Click-through rate', (t.ctr || 0) + '%') +
      tile('Average position', t.position != null ? '#' + t.position : '—') +
    '</div>';
  }

  function scQueries(m) {
    var rows = (m.queries || []).map(function (q) {
      return '<tr><td>' + esc(q.query) + '</td><td class="num">' + (q.clicks || 0).toLocaleString('en-IN') + '</td><td class="num">' + (q.impressions || 0).toLocaleString('en-IN') + '</td><td class="num">' + (q.ctr || 0) + '%</td><td class="num">' + (q.position != null ? '#' + q.position : '—') + '</td></tr>';
    }).join('');
    if (!rows) return '<p class="seo-sub">No searches recorded yet. Google usually has data from the last ' + (m.days || 28) + ' days — check back after a few days.</p>';
    return '<table class="seo-sc-q"><thead><tr><th>What people searched</th><th class="num">Clicks</th><th class="num">Searches</th><th class="num">CTR</th><th class="num">Position</th></tr></thead><tbody>' + rows + '</tbody></table>';
  }

  function scBody(d) {
    if (d.last_error) {
      return '<div class="sx-note warn">' + ic('warning') + '<span>' + esc(d.last_error) + '</span></div>';
    }
    if (!d.metrics) {
      // connected, but no site picked yet
      return '<p class="seo-sub">Pick the website you want to see search data for.</p><div class="seo-sc-sites" id="seoScSites"><div class="cbx-loading"><span></span><span></span><span></span></div></div>';
    }
    var m = d.metrics;
    return scStats(m) +
      '<p class="seo-sc-note">' + ic('info') + 'Last ' + m.days + ' days · ' + esc(m.start) + ' to ' + esc(m.end) + ' · ' + esc(m.site) + '. Google’s data is about 2 days behind.</p>' +
      '<h4 class="seo-sc-h4">' + ic('manage_search') + 'Top searches</h4>' + scQueries(m);
  }

  function loadSearchConsole() {
    var body = document.getElementById('seoScBody');
    if (!body) return;
    body.innerHTML = '<div class="cbx-loading"><span></span><span></span><span></span></div>';
    fetch(SC_API + '?view=status', { cache: 'no-store' }).then(function (r) { return r.json(); }).catch(function () { return { success: false, message: 'No connection. Please try again.' }; }).then(function (d) {
      if (!body.isConnected) return;
      if (!d.success) { body.innerHTML = '<div class="sx-note warn">' + ic('warning') + '<span>' + esc(d.message || 'Could not load') + '</span></div>'; return; }
      body.innerHTML = scBody(d);
      if (d.metrics === null && !d.last_error) loadScSites();
    });
  }

  function loadScSites() {
    var box = document.getElementById('seoScSites');
    if (!box) return;
    fetch(SC_API + '?view=sites', { cache: 'no-store' }).then(function (r) { return r.json(); }).catch(function () { return { success: false, message: 'Could not load your sites.' }; }).then(function (d) {
      if (!box.isConnected) return;
      if (!d.success) { box.innerHTML = '<div class="sx-note warn">' + ic('warning') + '<span>' + esc(d.message || 'Could not load your sites.') + '</span></div>'; return; }
      if (!d.sites.length) { box.innerHTML = '<p class="seo-sub">No websites found. Verify your site in Google Search Console first, then refresh.</p>'; return; }
      box.innerHTML = d.sites.map(function (s) {
        return '<button type="button" class="seo-sc-site" data-seo-sc-site="' + esc(s.url) + '">' + ic('language') + '<span>' + esc(s.url) + '</span><em>' + esc(s.permission || '') + '</em></button>';
      }).join('');
    });
  }

  function scPost(body, then) {
    return fetch(SC_API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); }).catch(function () { return { success: false, message: 'No connection. Please try again.' }; }).then(then);
  }

  /* ───────── events ───────── */
  function markDirty() { dirty = true; var b = document.getElementById('seoBar'); if (b) b.classList.add('show'); }
  function readInputs() {
    var g = function (id) { var el = document.getElementById(id); return el ? el.value : null; };
    var t = g('seoTitle'), d = g('seoDesc');
    if (t !== null) F.pages[cur].title = t;
    if (d !== null) F.pages[cur].description = d;
    ['seoCuisine:cuisine', 'seoKeywords:keywords', 'seoGsc:gsc_verification', 'seoGa:ga_id', 'seoPixel:meta_pixel_id'].forEach(function (x) { var p = x.split(':'), v = g(p[0]); if (v !== null) F[p[1]] = v; });
    var n = document.getElementById('seoNoindex'); if (n) F.noindex = n.checked;
  }

  function save() {
    readInputs();
    var btn = document.querySelector('[data-seo-save]'); if (btn) btn.disabled = true;
    post({ action: 'save', pages: F.pages, keywords: F.keywords, cuisine: F.cuisine, price_range: F.price_range, gsc_verification: F.gsc_verification, ga_id: F.ga_id, meta_pixel_id: F.meta_pixel_id, noindex: F.noindex }).then(function (d) {
      if (btn) btn.disabled = false;
      if (!d.success) return notify(d.message || 'Could not save', 'error');
      notify('SEO saved. It’s live on your website.', 'success');
      dirty = false; S = null; window.loadSeo();
    });
  }

  function aiWrite(which, btn) {
    readInputs();
    if (btn) { btn.disabled = true; btn.classList.add('seo-busy'); }
    post({ action: 'ai_write', page: which === 'all' ? '' : which, cuisine: F.cuisine, keywords: F.keywords }).then(function (d) {
      if (btn) { btn.disabled = false; btn.classList.remove('seo-busy'); }
      if (!d.success) return notify(d.message || 'Could not write it right now', 'error');
      Object.keys(d.pages).forEach(function (p) { F.pages[p] = { title: d.pages[p].title, description: d.pages[p].description }; });
      markDirty(); draw();
      notify((d.source === 'ai' ? 'Written for you. ' : 'Drafted from your details. ') + 'Check it, then press Save.', 'success');
    });
  }

  function fix(id) {
    var c = S.checks.filter(function (x) { return x.id === id; })[0];
    if (!c || !c.fix) return;
    if (c.fix.here) {
      var target = { pages: 'seoPages', business: 'seoBusiness', share: 'seoShare', tracking: 'seoTracking' }[c.fix.here];
      var el = document.getElementById(target);
      if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); el.classList.remove('seo-flash'); void el.offsetWidth; el.classList.add('seo-flash'); }
      return;
    }
    if (window.SX) SX.goTo(c.fix.page);
    if (c.fix.focus) setTimeout(function () {
      var f = document.getElementById(c.fix.focus); if (!f) return;
      // Settings is split into panes: open the one holding the field first
      var pane = f.closest('[data-st-pane]'), go = pane && document.querySelector('[data-st-go="' + pane.getAttribute('data-st-pane') + '"]');
      if (go) go.click();
      setTimeout(function () { f.scrollIntoView({ behavior: 'smooth', block: 'center' }); try { f.focus({ preventScroll: true }); } catch (e) {} }, 250);
    }, 450);
  }

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t.closest || !t.closest('#seoRoot')) return;
    if (t.id === 'seoTitle' || t.id === 'seoDesc') {
      readInputs();
      document.getElementById(t.id === 'seoTitle' ? 'seoTitleCount' : 'seoDescCount').innerHTML = t.id === 'seoTitle' ? meter(t.value.length, 30, 60, 65) : meter(t.value.length, 120, 155, 165);
      paintPreviews();
    } else if (t.id === 'seoCuisine') { readInputs(); document.querySelectorAll('[data-seo-cuisine]').forEach(function (b) { b.classList.toggle('on', (',' + F.cuisine.toLowerCase().replace(/\s*,\s*/g, ',') + ',').indexOf(',' + b.getAttribute('data-seo-cuisine').toLowerCase() + ',') >= 0); }); }
    markDirty();
  });
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t.closest || !t.closest('#seoRoot')) return;
    if (t.id === 'seoNoindex') { readInputs(); markDirty(); draw(); }
    if (t.id === 'seoShareFile' && t.files && t.files[0]) {
      var fd = new FormData(); fd.append('share_image', t.files[0]);
      var lbl = t.closest('label'); if (lbl) lbl.classList.add('seo-busy');
      fetch(API, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).catch(function () { return { success: false, message: 'Upload failed' }; }).then(function (d) {
        if (lbl) lbl.classList.remove('seo-busy');
        if (!d.success) return notify(d.message || 'Upload failed', 'error');
        S.settings.share_image = d.share_image + '?v=' + Date.now();
        S.checks.forEach(function (c) { if (c.id === 'share' && !c.ok) { c.ok = true; S.score += c.w; } });
        readInputs(); draw();
        notify(d.small ? 'Saved. Tip: a picture 1200 px wide looks sharper.' : 'Share picture saved.', 'success');
      });
    }
  });
  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('#seoRoot [data-seo-page], #seoRoot [data-seo-ai], #seoRoot [data-seo-default], #seoRoot [data-seo-save], #seoRoot [data-seo-undo], #seoRoot [data-seo-fix], #seoRoot [data-seo-cuisine], #seoRoot [data-seo-price], #seoRoot [data-seo-copy], #seoRoot [data-seo-rmimg], #seoRoot [data-seo-sc], #seoRoot [data-seo-sc-site]');
    if (!t) { if (e.target.closest && e.target.closest('.nav-link[data-page="seoPage"]')) setTimeout(window.loadSeo, 30); return; }
    e.preventDefault();
    if (t.hasAttribute('data-seo-page')) { readInputs(); cur = t.getAttribute('data-seo-page'); draw(); return; }
    if (t.hasAttribute('data-seo-ai')) return aiWrite(t.getAttribute('data-seo-ai'), t);
    if (t.hasAttribute('data-seo-default')) { F.pages[cur] = { title: '', description: '' }; markDirty(); draw(); return; }
    if (t.hasAttribute('data-seo-save')) return save();
    if (t.hasAttribute('data-seo-undo')) { dirty = false; S = null; window.loadSeo(); return; }
    if (t.hasAttribute('data-seo-fix')) return fix(t.getAttribute('data-seo-fix'));
    if (t.hasAttribute('data-seo-cuisine')) {
      readInputs();
      var c = t.getAttribute('data-seo-cuisine'), list = (F.cuisine || '').split(',').map(function (x) { return x.trim(); }).filter(Boolean);
      var i = list.map(function (x) { return x.toLowerCase(); }).indexOf(c.toLowerCase());
      if (i >= 0) list.splice(i, 1); else list.push(c);
      F.cuisine = list.join(', '); markDirty(); draw(); return;
    }
    if (t.hasAttribute('data-seo-price')) { readInputs(); var p = t.getAttribute('data-seo-price'); F.price_range = F.price_range === p ? '' : p; markDirty(); draw(); return; }
    if (t.hasAttribute('data-seo-copy')) {
      var v = t.getAttribute('data-seo-copy');
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(v).then(function () { t.innerHTML = ic('check'); setTimeout(function () { t.innerHTML = ic('content_copy'); }, 1200); });
      else notify(v, 'info');
      return;
    }
    if (t.hasAttribute('data-seo-rmimg')) {
      post({ action: 'remove_image' }).then(function (d) {
        if (!d.success) return notify(d.message || 'Could not remove', 'error');
        S.settings.share_image = null;
        S.checks.forEach(function (c) { if (c.id === 'share' && c.ok) { c.ok = false; S.score -= c.w; } });
        readInputs(); draw();
      });
      return;
    }
    // ── Search performance (Google Search Console) ──
    if (t.hasAttribute('data-seo-sc-site')) {
      var site = t.getAttribute('data-seo-sc-site');
      t.disabled = true;
      scPost({ action: 'set_site', site: site }, function (d) {
        if (!d.success) return notify(d.message || 'Could not save', 'error');
        S.search_console.site = site;
        notify('Saved. Showing search data for ' + site, 'success');
        var body = document.getElementById('seoScBody'); if (body) body.innerHTML = scBody({ metrics: d.metrics, last_error: null });
      });
      return;
    }
    if (t.hasAttribute('data-seo-sc')) {
      var act = t.getAttribute('data-seo-sc');
      if (act === 'connect') {
        fetch(SC_API + '?view=connect', { cache: 'no-store' }).then(function (r) { return r.json(); }).catch(function () { return { success: false, message: 'Could not start sign-in.' }; }).then(function (d) {
          if (!d.success) return notify(d.message || 'Could not start sign-in', 'error');
          window.location.href = d.url;
        });
        return;
      }
      if (act === 'refresh') { loadSearchConsole(); return; }
      if (act === 'site') {
        var b2 = document.getElementById('seoScBody');
        if (b2) { b2.innerHTML = '<p class="seo-sub">Pick the website you want to see search data for.</p><div class="seo-sc-sites" id="seoScSites"><div class="cbx-loading"><span></span><span></span><span></span></div></div>'; loadScSites(); }
        return;
      }
      if (act === 'disconnect') {
        scPost({ action: 'disconnect' }, function (d) {
          if (!d.success) return notify(d.message || 'Could not disconnect', 'error');
          S.search_console.connected = false; S.search_console.site = null;
          notify('Disconnected from Google Search Console', 'success');
          draw();
        });
        return;
      }
    }
  });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  document.addEventListener('DOMContentLoaded', function () { if (window.SX && SX.isActive('seoPage')) window.loadSeo(); });
})();
