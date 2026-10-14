/**
 * Google Business (sidebar › Google Business): rating, reviews with replies (AI drafts), posts,
 * profile info and hours, insights, and review links / QR. Data: api/gbp.php (config/gbp_helpers.php).
 * Styles: gbx-* in studio-ui.css.
 */
(function () {
  'use strict';
  var API = '../api/gbp.php';
  var S = null;            // status
  var tab = 'overview';
  var reviews = { filter: 'all', list: null, source: '', canReply: false };
  var post = { topic: 'STANDARD' };
  var combos = null;

  var esc = function (s) { return window.SX ? SX.esc(s) : String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var notify = function (m, t) { if (window.SX) SX.notify(m, t); };
  var GLOGO = '<svg class="gbx-g" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>';

  function get(qs) { return fetch(API + (qs ? '?' + qs : ''), { cache: 'no-store' }).then(function (r) { return r.json(); }).catch(function () { return { success: false, message: 'No connection. Please try again.' }; }); }
  function post_(body) { return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(function (r) { return r.json(); }).catch(function () { return { success: false, message: 'No connection. Please try again.' }; }); }
  function stars(n, big) {
    var h = '';
    for (var i = 1; i <= 5; i++) { var f = Math.max(0, Math.min(1, n - i + 1)); h += '<i class="gbx-st' + (big ? ' big' : '') + '" style="--f:' + (f * 100).toFixed(0) + '%"></i>'; }
    return '<span class="gbx-stars">' + h + '</span>';
  }
  function ago(d) {
    if (!d) return '';
    var t = new Date(String(d).replace(' ', 'T')).getTime(), s = (Date.now() - t) / 1000;
    if (s < 3600) return Math.max(1, Math.round(s / 60)) + ' min ago';
    if (s < 86400) return Math.round(s / 3600) + ' h ago';
    if (s < 86400 * 30) { var dd = Math.round(s / 86400); return dd + (dd === 1 ? ' day ago' : ' days ago'); }
    return new Date(t).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
  }

  /* ───────── load ───────── */
  window.loadGoogleBusiness = function () {
    var root = document.getElementById('gbpRoot');
    if (!root) return;
    if (!S) root.innerHTML = '<div class="cbx-loading"><span></span><span></span><span></span></div>';
    get('').then(function (d) {
      if (!d.success) { root.innerHTML = '<div class="sx-note warn">' + ic('warning') + '<span>' + esc(d.message || 'Could not load') + '</span></div>'; return; }
      S = d; draw();
      // straight back from Google sign-in
      var m = /[?&]gbp=([a-z_]+)/.exec(location.search);
      if (m) {
        var msg = { connected: ['Google connected. Pick your restaurant’s location.', 'success'], cancelled: ['Google sign-in was cancelled.', 'info'], expired: ['That sign-in took too long. Press Connect Google again.', 'error'],
          no_refresh: ['Google didn’t give lasting access. Remove RestroGrow at myaccount.google.com/permissions, then connect again.', 'error'], error: ['Google sign-in failed. Try again.', 'error'] }[m[1]];
        if (msg) notify(msg[0], msg[1]);
        history.replaceState(null, '', location.pathname);
        if (m[1] === 'connected' && !S.location) pickLocation();
      }
    });
  };

  function draw() {
    var root = document.getElementById('gbpRoot');
    if (!root || !S) return;
    // Google keys not on the server yet (docs/GOOGLE_BUSINESS_TODO.md): show what's coming;
    // everything below switches on by itself once the keys are in .env
    if (!S.setup.oauth && !S.setup.places) { root.innerHTML = comingSoon(); return; }
    var has = S.rating != null;
    root.innerHTML =
      '<div class="gbx-hero"><div class="gbx-hero-l">' + GLOGO + '<div><h2>' + esc(S.location ? S.location.title : (S.place ? S.place.name : S.restaurant.name)) + '</h2>' +
        '<p>' + (S.connected ? '<span class="sx-pill ok">' + ic('check_circle') + 'Connected' + (S.google_email ? ' · ' + esc(S.google_email) : '') + '</span>' : S.place ? '<span class="sx-pill wait">' + ic('star') + 'Rating only</span>' : '<span class="sx-pill muted">Not set up</span>') +
        (S.synced.reviews || S.synced.places ? '<small>Updated ' + ago(S.synced.reviews || S.synced.places) + '</small>' : '') + '</p></div></div>' +
        '<div class="gbx-hero-r">' + (S.connected || S.place ? '<button type="button" class="btn btn-secondary btn-sm" data-gbx-refresh>' + ic('refresh') + 'Refresh</button>' : '') +
        (has ? '<div class="gbx-score"><b>' + S.rating.toFixed(1) + '</b>' + stars(S.rating, true) + '<small>' + (S.rating_count || 0).toLocaleString('en-IN') + ' reviews</small></div>' : '') + '</div></div>' +
      (S.last_error ? '<div class="sx-note warn">' + ic('warning') + '<span>' + esc(S.last_error) + '</span></div>' : '') +
      setupCard() +
      '<div class="gbx-tabs" role="tablist">' + [['overview', 'insights', 'Overview'], ['reviews', 'reviews', 'Reviews' + (S.unanswered ? ' <em>' + S.unanswered + '</em>' : '')], ['posts', 'campaign', 'Posts'], ['profile', 'storefront', 'Profile'], ['insights', 'insights', 'Insights'], ['get', 'qr_code_2', 'Get reviews']].map(function (t) {
        return '<button type="button" role="tab" class="' + (tab === t[0] ? 'on' : '') + '" data-gbx-tab="' + t[0] + '">' + ic(t[1]) + t[2] + '</button>';
      }).join('') + '</div><div class="gbx-body" id="gbxBody"></div>';
    drawTab();
  }

  function comingSoon() {
    var f = [['star', 'Your Google rating', 'Your stars and newest reviews, right on your dashboard.'], ['reviews', 'Reply to reviews', 'Answer every Google review from here, with AI-written replies.'],
      ['campaign', 'Post to Google', 'Share offers, combos and updates on your Google listing.'], ['schedule', 'Hours and info', 'Keep your phone, website and opening hours right on Google.'],
      ['insights', 'Insights', 'See how many people view, call and get directions to you.'], ['qr_code_2', 'Get more reviews', 'Review QR for tables, WhatsApp links and printable cards.']];
    return '<div class="gbx-soon"><div class="gbx-soon-h">' + GLOGO + '<span class="gbx-soon-tag">' + ic('bolt') + 'Coming soon</span><h2>Google Business, right inside RestroGrow</h2>' +
      '<p>We’re finishing the connection with Google. Soon you’ll manage your Google listing and reviews from this page.</p></div>' +
      '<div class="gbx-soon-grid">' + f.map(function (x, i) { return '<div style="--d:' + i * 70 + 'ms">' + ic(x[0]) + '<b>' + x[1] + '</b><small>' + x[2] + '</small></div>'; }).join('') + '</div></div>';
  }

  // what's left to do: find the place (rating), connect Google (everything else), pick the location
  function setupCard() {
    if (S.connected && S.location && S.place) return '';
    var steps = [];
    steps.push('<div class="gbx-step' + (S.place ? ' done' : '') + '"><span class="gbx-num">' + (S.place ? ic('check') : '1') + '</span><div><b>Show your Google rating</b><small>Find your restaurant on Google. Works right away: rating, newest reviews and a review link.</small>' +
      (S.place ? '<small class="ok">' + esc(S.place.name) + ' · <a href="#" data-gbx-findplace>change</a></small>' : (S.setup.places ? '<div class="gbx-find"><input type="search" id="gbxPlaceQ" value="' + esc((S.restaurant.name + ' ' + S.restaurant.address).trim()) + '" placeholder="Restaurant name and area"><button type="button" class="btn btn-primary btn-sm" data-gbx-search>' + ic('search') + 'Find</button></div><div id="gbxPlaces"></div>' : '<small class="bad">Coming soon.</small>')) + '</div></div>');
    steps.push('<div class="gbx-step' + (S.connected ? ' done' : '') + '"><span class="gbx-num">' + (S.connected ? ic('check') : '2') + '</span><div><b>Connect Google Business Profile</b><small>Reply to every review, post offers, keep hours and phone right on Google, and see views and calls.</small>' +
      (S.connected ? '' : (S.setup.oauth ? '<button type="button" class="gbx-connect" data-gbx-connect>' + GLOGO + 'Connect Google</button>' : '<small class="bad">Coming soon.</small>')) + '</div></div>');
    if (S.connected) steps.push('<div class="gbx-step' + (S.location ? ' done' : '') + '"><span class="gbx-num">' + (S.location ? ic('check') : '3') + '</span><div><b>Pick your location</b><small>The Google listing for this restaurant.</small>' +
      (S.location ? '<small class="ok">' + esc(S.location.title) + '</small>' : '<button type="button" class="btn btn-primary btn-sm" data-gbx-locations>' + ic('location_on') + 'Choose location</button>') + '</div></div>');
    return '<div class="gbx-setup">' + steps.join('') + '</div>';
  }

  function drawTab() {
    var box = document.getElementById('gbxBody');
    if (!box) return;
    ({ overview: tOverview, reviews: tReviews, posts: tPosts, profile: tProfile, insights: tInsights, get: tGet })[tab](box);
  }

  /* ───────── Overview ───────── */
  function tOverview(box) {
    var d = S.distribution, total = Object.keys(d).reduce(function (t, k) { return t + d[k]; }, 0) || 1;
    var hits = S.hits || {}, scans = Object.keys(hits).reduce(function (t, k) { return t + hits[k]; }, 0);
    box.innerHTML = '<div class="gbx-grid">' +
      '<section class="gbx-card"><h4>' + ic('star') + 'Ratings</h4>' + (S.rating == null ? '<p class="sx-muted">No rating yet. Find your restaurant on Google above.</p>' :
        '<div class="gbx-dist"><div class="gbx-big"><b>' + S.rating.toFixed(1) + '</b>' + stars(S.rating) + '<small>' + (S.rating_count || 0).toLocaleString('en-IN') + ' on Google</small></div><div class="gbx-bars">' +
        [5, 4, 3, 2, 1].map(function (n, i) { var p = Math.round(d[n] / total * 100); return '<div><span>' + n + '</span><i><b style="--w:' + p + '%;--d:' + (i * 80) + 'ms"></b></i><em>' + d[n] + '</em></div>'; }).join('') + '</div></div>' +
        (S.source === 'places' ? '<p class="sx-muted gbx-mini">Bars show the newest reviews Google shares' + (S.setup.oauth ? ' without connecting. Connect Google to see all of them.' : '. All reviews: coming soon.') + '</p>' : '')) + '</section>' +
      '<section class="gbx-card"><h4>' + ic('reviews') + 'To do</h4><div class="gbx-todo">' +
        todo(S.unanswered, 'reviews waiting for a reply', 'Reply now', 'reviews', S.unanswered ? 'warn' : 'ok', S.connected ? null : (S.setup.oauth ? 'Connect Google to reply from here' : 'Replying from here: coming soon')) +
        todo(scans, 'review link scans in 30 days', 'Get more reviews', 'get', scans ? 'ok' : 'muted') +
        todo(null, 'Post an offer or a combo on Google', 'New post', 'posts', 'muted') + '</div></section>' +
      '<section class="gbx-card gbx-span"><h4>' + ic('auto_awesome') + 'Tips to rank higher on Google Maps</h4><ul class="gbx-tips">' +
        '<li>' + ic(S.unanswered ? 'reviews' : 'check_circle') + '<span>Reply to every review within a day. Google shows owners who reply higher.</span></li>' +
        '<li>' + ic('qr_code_2') + '<span>Put the review QR on tables and the bill. Steady new reviews matter more than the total.</span></li>' +
        '<li>' + ic('campaign') + '<span>Post once a week: a combo, today’s special or an offer. Posts keep your listing fresh.</span></li>' +
        '<li>' + ic('schedule') + '<span>Keep hours and phone right on Google, especially on festival days.</span></li></ul></section></div>';
  }
  function todo(n, label, btn, go, cls, note) {
    return '<div class="gbx-todo-r ' + cls + '">' + (n != null ? '<b>' + n + '</b>' : ic('campaign')) + '<span>' + label + (note ? '<small>' + note + '</small>' : '') + '</span><button type="button" class="btn btn-secondary btn-sm" data-gbx-tab="' + go + '">' + btn + '</button></div>';
  }

  /* ───────── Reviews ───────── */
  function tReviews(box) {
    box.innerHTML = '<div class="gbx-rhead"><div class="cbx-chips">' + [['all', 'All'], ['unanswered', 'Needs reply'], ['low', '1–3 stars'], ['high', '4–5 stars']].map(function (f) {
      return '<button type="button" class="cbx-chip' + (reviews.filter === f[0] ? ' on' : '') + '" data-gbx-filter="' + f[0] + '">' + f[1] + '</button>'; }).join('') + '</div></div><div id="gbxRevs"><div class="cbx-loading"><span></span><span></span><span></span></div></div>';
    get('view=reviews&filter=' + reviews.filter).then(function (d) {
      reviews.list = d.reviews || []; reviews.source = d.source; reviews.canReply = d.can_reply;
      drawReviews();
    });
  }
  function drawReviews() {
    var box = document.getElementById('gbxRevs');
    if (!box) return;
    if (!reviews.list.length) { box.innerHTML = '<div class="cbx-empty"><div class="cbx-empty-art">' + ic('reviews') + '</div><h3>' + (reviews.filter === 'all' ? 'No reviews here yet' : 'Nothing in this list') + '</h3><p>' + (S.place || S.connected ? 'Share your review link to get more.' : 'Find your restaurant on Google above to see its reviews.') + '</p></div>'; return; }
    box.innerHTML = (reviews.source === 'places' ? '<div class="sx-note">' + ic('info') + '<span>These are the newest reviews Google shares publicly.' + (S.setup.oauth ? ' <b>Connect Google</b> to see all reviews and reply from here.' : ' Seeing every review and replying from here: <b>coming soon</b>.') + '</span></div>' : '') +
      reviews.list.map(function (r, i) {
        var bad = r.rating && r.rating <= 3;
        return '<article class="gbx-rev' + (bad ? ' bad' : '') + (!r.reply && reviews.source === 'gbp' ? ' open' : '') + '" style="--d:' + Math.min(i, 12) * 40 + 'ms" data-rid="' + esc(r.review_id) + '">' +
          '<div class="gbx-rev-h">' + '<span class="gbx-av">' + esc((r.reviewer_name || '?').charAt(0)) + (r.reviewer_photo ? '<img src="' + esc(r.reviewer_photo) + '" alt="" referrerpolicy="no-referrer" loading="lazy" onerror="this.remove()">' : '') + '</span>' +
            '<div><b>' + esc(r.reviewer_name || 'Google user') + '</b><span>' + stars(r.rating) + '<small>' + ago(r.created_at) + '</small></span></div>' + (bad ? '<span class="sx-pill bad">Needs care</span>' : '') + '</div>' +
          (r.comment ? '<p class="gbx-rev-t">' + esc(r.comment) + '</p>' + (r.comment.length > 360 ? '<button type="button" class="gbx-more" data-gbx-more>Read more</button>' : '') : '<p class="gbx-rev-t sx-muted">Rating only, no text.</p>') +
          (r.reply ? '<div class="gbx-reply"><b>' + ic('reply') + 'Your reply</b><p>' + esc(r.reply) + '</p>' + (reviews.canReply ? '<div><button type="button" class="btn btn-secondary btn-sm" data-gbx-edit="' + i + '">' + ic('edit') + 'Edit</button><button type="button" class="btn btn-secondary btn-sm" data-gbx-unreply="' + i + '">' + ic('delete') + 'Remove</button></div>' : '') + '</div>' :
            (reviews.canReply ? replyBox(i, '') : '')) +
        '</article>';
      }).join('');
  }
  function replyBox(i, text) {
    return '<div class="gbx-rbox"><textarea rows="3" maxlength="4000" placeholder="Write a reply… (shown publicly on Google)" data-gbx-text="' + i + '">' + esc(text) + '</textarea><div class="gbx-racts">' +
      (S.setup.ai ? '<select data-gbx-tone="' + i + '" title="Tone"><option value="warm">Warm</option><option value="short">Short</option><option value="formal">Formal</option><option value="playful">Playful</option></select><button type="button" class="btn btn-secondary btn-sm gbx-ai" data-gbx-ai="' + i + '">' + ic('auto_awesome') + 'Write with AI</button>' : '') +
      '<span class="cbx-grow"></span><button type="button" class="btn btn-primary btn-sm" data-gbx-send="' + i + '">' + ic('send') + 'Reply on Google</button></div></div>';
  }

  /* ───────── Posts ───────── */
  function tPosts(box) {
    if (!S.location) { box.innerHTML = need('Post updates, offers and events to Google', 'Connect Google and pick your location to post from here.'); return; }
    box.innerHTML = '<div class="gbx-grid"><section class="gbx-card"><h4>' + ic('campaign') + 'New post</h4>' +
      '<div class="cbx-chips">' + [['STANDARD', 'Update'], ['OFFER', 'Offer'], ['EVENT', 'Event']].map(function (t) { return '<button type="button" class="cbx-chip' + (post.topic === t[0] ? ' on' : '') + '" data-gbx-topic="' + t[0] + '">' + t[1] + '</button>'; }).join('') + '</div>' +
      '<div id="gbxCombosPick"></div>' +
      (post.topic !== 'STANDARD' ? '<label class="cbx-l">Title<input type="text" maxlength="58" data-gbx-p="title" value="' + esc(post.title || '') + '" placeholder="e.g. Weekday lunch combo ₹299"></label>' : '') +
      '<label class="cbx-l">What it says <small data-gbx-count>' + (post.summary || '').length + '/1500</small><textarea rows="5" maxlength="1500" data-gbx-p="summary" placeholder="Tell customers what’s new, what’s on offer, or what’s happening.">' + esc(post.summary || '') + '</textarea></label>' +
      (post.topic !== 'STANDARD' ? '<div class="cbx-row"><label class="cbx-l">Starts<input type="date" data-gbx-p="start" value="' + esc(post.start || new Date().toISOString().slice(0, 10)) + '"></label><label class="cbx-l">Ends<input type="date" data-gbx-p="end" value="' + esc(post.end || new Date(Date.now() + 7 * 864e5).toISOString().slice(0, 10)) + '"></label></div>' : '') +
      (post.topic === 'OFFER' ? '<div class="cbx-row"><label class="cbx-l">Coupon code <small>optional</small><input type="text" maxlength="58" data-gbx-p="coupon" value="' + esc(post.coupon || '') + '"></label><label class="cbx-l">Terms <small>optional</small><input type="text" maxlength="500" data-gbx-p="terms" value="' + esc(post.terms || '') + '"></label></div>' :
        '<label class="cbx-l">Button<select data-gbx-p="cta"><option value="">No button</option>' + [['ORDER', 'Order online'], ['BOOK', 'Book'], ['LEARN_MORE', 'Learn more'], ['CALL', 'Call now']].map(function (c) { return '<option value="' + c[0] + '"' + (post.cta === c[0] ? ' selected' : '') + '>' + c[1] + '</option>'; }).join('') + '</select></label>') +
      '<label class="cbx-l">Link <small>your website menu by default</small><input type="url" data-gbx-p="url" value="' + esc(post.url || webUrl()) + '"></label>' +
      '<label class="cbx-l">Photo address <small>optional, a public https:// image (720×540 or bigger)</small><input type="url" data-gbx-p="image_url" value="' + esc(post.image_url || '') + '" placeholder="https://…"></label>' +
      '<button type="button" class="btn btn-primary" data-gbx-post>' + ic('send') + 'Post to Google</button></section>' +
      '<section class="gbx-card"><h4>' + ic('visibility') + 'Preview</h4><div class="gbx-gpost">' + (post.image_url ? '<img src="' + esc(post.image_url) + '" alt="">' : '') +
        '<div><small>' + esc(S.location.title) + ' · just now</small>' + (post.topic !== 'STANDARD' && post.title ? '<b>' + esc(post.title) + '</b>' : '') + '<p>' + esc(post.summary || 'Your post text appears here.') + '</p>' +
        (post.topic === 'OFFER' && post.coupon ? '<span class="gbx-coupon">' + esc(post.coupon) + '</span>' : '') + (post.cta || post.topic === 'OFFER' ? '<span class="gbx-gbtn">' + esc({ ORDER: 'Order online', BOOK: 'Book', LEARN_MORE: 'Learn more', CALL: 'Call now' }[post.cta] || 'View offer') + '</span>' : '') + '</div></div>' +
        '<h4 class="gbx-sub">' + ic('history') + 'Posted from here</h4><div id="gbxPosts"><div class="cbx-loading"><span></span><span></span><span></span></div></div></section></div>';
    get('view=posts').then(function (d) {
      var el = document.getElementById('gbxPosts'); if (!el) return;
      el.innerHTML = (d.posts || []).length ? d.posts.map(function (p) {
        return '<div class="gbx-prow"><span class="sx-pill ' + (p.state === 'LIVE' ? 'ok' : p.state === 'REJECTED' ? 'bad' : 'wait') + '">' + esc({ STANDARD: 'Update', OFFER: 'Offer', EVENT: 'Event' }[p.topic] || p.topic) + '</span><span><b>' + esc(p.title || p.summary.slice(0, 60)) + '</b><small>' + ago(p.created_at) + ' · ' + esc((p.state || '').toLowerCase()) + '</small></span>' +
          (p.search_url ? '<a class="sx-icon-btn" href="' + esc(p.search_url) + '" target="_blank" rel="noopener" title="See on Google">' + ic('open_in_new') + '</a>' : '') + '<button type="button" class="sx-icon-btn" data-gbx-delpost="' + p.id + '" title="Delete from Google">' + ic('delete') + '</button></div>';
      }).join('') : '<p class="sx-muted">Nothing posted from here yet.</p>';
    });
    // combos make ready-made posts
    if (combos === null) fetch('../api/combos_admin.php?view=pos', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) { combos = (d && d.combos) || []; drawComboPick(); }).catch(function () { combos = []; });
    else drawComboPick();
  }
  function drawComboPick() {
    var el = document.getElementById('gbxCombosPick');
    if (!el || !combos || !combos.length) return;
    el.innerHTML = '<div class="gbx-cpick"><small>' + ic('fastfood') + 'Post a combo:</small>' + combos.map(function (c) { return '<button type="button" class="cbx-chip" data-gbx-combo="' + c.id + '">' + esc(c.name) + '</button>'; }).join('') + '</div>';
  }
  function webUrl() { return location.origin + location.pathname.replace(/\/views\/dashboard\.php.*$/, '') + '/website/index.php?restaurant_id=' + encodeURIComponent(((document.getElementById('restaurantId') || {}).textContent || '').trim()); }

  /* ───────── Profile ───────── */
  var DAYS = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];
  function tProfile(box) {
    if (!S.location) { box.innerHTML = need('Keep your Google listing right', 'Connect Google and pick your location to edit phone, website, description and hours from here.'); return; }
    box.innerHTML = '<div class="cbx-loading"><span></span><span></span><span></span></div>';
    get('view=info').then(function (d) {
      if (!d.success) { box.innerHTML = '<div class="sx-note warn">' + ic('warning') + '<span>' + esc(d.message) + '</span></div>'; return; }
      var inf = d.info, ours = d.ours, hrs = {};
      (inf.hours || []).forEach(function (p) { hrs[p.openDay] = { open: pad(p.openTime), close: pad(p.closeTime) }; });
      box.innerHTML = '<div class="gbx-grid"><section class="gbx-card"><h4>' + ic('storefront') + 'On Google</h4>' +
        '<p class="gbx-cat">' + esc(inf.title) + (inf.category ? ' · ' + esc(inf.category) : '') + '</p>' +
        '<label class="cbx-l">Phone' + diff(inf.phone, ours.phone) + '<input type="tel" id="gbxPhone" value="' + esc(inf.phone) + '"></label>' +
        '<label class="cbx-l">Website' + (inf.website !== ours.website ? '<button type="button" class="gbx-use" data-gbx-useweb>Use my RestroGrow website</button>' : '') + '<input type="url" id="gbxWeb" value="' + esc(inf.website) + '" data-ours="' + esc(ours.website) + '"></label>' +
        '<label class="cbx-l">Description <small data-gbx-dcount>' + (inf.description || '').length + '/750</small><textarea id="gbxDesc" rows="5" maxlength="750">' + esc(inf.description) + '</textarea></label>' +
        '<button type="button" class="btn btn-primary" data-gbx-saveinfo>' + ic('check') + 'Save to Google</button></section>' +
        '<section class="gbx-card"><h4>' + ic('schedule') + 'Opening hours on Google</h4><div class="gbx-hours">' + DAYS.map(function (day) {
          var h = hrs[day];
          return '<div class="gbx-hr" data-day="' + day + '"><b>' + day.charAt(0) + day.slice(1, 3).toLowerCase() + '</b><label class="cbx-sw"><input type="checkbox" data-gbx-open' + (h ? ' checked' : '') + '><i></i></label>' +
            '<input type="time" data-gbx-o value="' + (h ? h.open : '11:00') + '"' + (h ? '' : ' disabled') + '><span>–</span><input type="time" data-gbx-c value="' + (h ? h.close : '23:00') + '"' + (h ? '' : ' disabled') + '></div>';
        }).join('') + '</div><button type="button" class="btn btn-secondary btn-sm" data-gbx-copymon>' + ic('content_copy') + 'Copy Monday to every day</button>' +
        '<button type="button" class="btn btn-primary" data-gbx-savehours>' + ic('check') + 'Save hours to Google</button></section></div>';
    });
  }
  function pad(t) { t = t || {}; return String(t.hours || 0).padStart(2, '0') + ':' + String(t.minutes || 0).padStart(2, '0'); }
  function diff(g, o) { return o && g && g.replace(/\D/g, '').slice(-10) !== o.replace(/\D/g, '').slice(-10) ? ' <small class="bad">RestroGrow has ' + esc(o) + '</small>' : ''; }

  /* ───────── Insights ───────── */
  var days = 30;
  function tInsights(box) {
    if (!S.location) { box.innerHTML = need('See how people find you on Google', 'Connect Google and pick your location to see views, calls, direction requests and website clicks.'); return; }
    box.innerHTML = '<div class="cbx-chips">' + [7, 30, 90].map(function (n) { return '<button type="button" class="cbx-chip' + (days === n ? ' on' : '') + '" data-gbx-days="' + n + '">' + n + ' days</button>'; }).join('') + '</div><div id="gbxIns"><div class="cbx-loading"><span></span><span></span><span></span></div></div>';
    get('view=insights&days=' + days).then(function (d) {
      var el = document.getElementById('gbxIns'); if (!el) return;
      if (!d.success) { el.innerHTML = '<div class="sx-note warn">' + ic('warning') + '<span>' + esc(d.message) + '</span></div>'; return; }
      var t = d.totals || {}, keys = Object.keys(d.series || {}), max = Math.max.apply(null, keys.map(function (k) { return d.series[k].views || 0; }).concat([1]));
      el.innerHTML = '<div class="sx-stats gbx-ins">' + [['views', 'visibility', 'Views on Search & Maps'], ['calls', 'call', 'Calls'], ['directions', 'map', 'Direction requests'], ['website', 'language', 'Website clicks'], ['menu', 'restaurant_menu', 'Menu clicks'], ['orders', 'shopping_bag', 'Food orders']].map(function (m, i) {
        return '<div style="animation-delay:' + i * 50 + 'ms">' + ic(m[1]) + '<b>' + (t[m[0]] || 0).toLocaleString('en-IN') + '</b><small>' + m[2] + '</small></div>'; }).join('') + '</div>' +
        '<section class="gbx-card"><h4>' + ic('insights') + 'Views per day</h4><div class="gbx-chart">' + keys.map(function (k, i) {
          var v = d.series[k].views || 0;
          return '<i style="--h:' + (v / max * 100).toFixed(1) + '%;--d:' + i * 12 + 'ms" title="' + k + ': ' + v + ' views"></i>'; }).join('') + '</div><div class="gbx-axis"><span>' + (keys[0] || '') + '</span><span>' + (keys[keys.length - 1] || '') + '</span></div></section>';
    });
  }

  /* ───────── Get reviews ───────── */
  function tGet(box) {
    var link = S.short_url, hits = S.hits || {};
    var wa = 'Thank you for dining with us at ' + (S.restaurant.name || 'our restaurant') + '! If you enjoyed it, a quick Google review would mean a lot: ' + link + '&s=wa';
    box.innerHTML = '<div class="gbx-grid"><section class="gbx-card"><h4>' + ic('link') + 'Your review link</h4>' +
      (S.review_url ? '<p class="sx-muted">Opens Google’s review form for your restaurant. Each scan or tap is counted here.</p>' : '<div class="sx-note warn">' + ic('warning') + '<span>Find your restaurant on Google above (or add your Google Maps link in Settings) so the link knows where to go.</span></div>') +
      '<div class="gbx-link"><input type="text" readonly value="' + esc(link) + '"><button type="button" class="btn btn-secondary btn-sm" data-gbx-copy="' + esc(link) + '">' + ic('content_copy') + 'Copy</button></div>' +
      '<a class="btn btn-secondary btn-sm gbx-wa" href="https://wa.me/?text=' + encodeURIComponent(wa) + '" target="_blank" rel="noopener">' + ic('share') + 'Share on WhatsApp</a>' +
      '<h4 class="gbx-sub">' + ic('insights') + 'Scans and taps · 30 days</h4><div class="gbx-hits">' + [['qr', 'Table QR'], ['link', 'Link'], ['wa', 'WhatsApp'], ['table', 'Table card'], ['site', 'Website']].map(function (s) {
        return '<div><b>' + (hits[s[0]] || 0) + '</b><small>' + s[1] + '</small></div>'; }).join('') + '</div></section>' +
      '<section class="gbx-card gbx-qrcard"><h4>' + ic('qr_code_2') + 'Table QR</h4><div class="gbx-print" id="gbxPrint"><div class="gbx-pc-top">' + GLOGO + '<b>Loved your meal?</b><span>Scan to review us on Google</span></div><div id="gbxQr"></div><small>' + esc(S.restaurant.name) + '</small></div>' +
      '<div class="gbx-qacts"><button type="button" class="btn btn-secondary btn-sm" data-gbx-qrpng>' + ic('download') + 'Download QR</button><button type="button" class="btn btn-primary btn-sm" data-gbx-print>' + ic('print') + 'Print table card</button></div></section></div>';
    var el = document.getElementById('gbxQr');
    if (el && typeof window.qrcode === 'function') {
      var qr = window.qrcode(0, 'M'); qr.addData(link + '&s=qr'); qr.make();
      el.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
    }
  }

  function need(t, s) {
    // without the sign-in keys on the server, this part isn't available yet: say so plainly
    if (!S.setup.oauth) return '<div class="cbx-empty"><div class="cbx-empty-art">' + GLOGO + '</div><span class="gbx-soon-tag">' + ic('bolt') + 'Coming soon</span><h3>' + t + '</h3><p>This part is on its way. Your rating, reviews and review QR already work.</p></div>';
    return '<div class="cbx-empty"><div class="cbx-empty-art">' + GLOGO + '</div><h3>' + t + '</h3><p>' + s + '</p>' + (!S.connected ? '<button type="button" class="gbx-connect" data-gbx-connect>' + GLOGO + 'Connect Google</button>' : '') + '</div>';
  }

  function pickLocation() {
    var ov = document.createElement('div'); ov.className = 'cbx-ov in'; ov.id = 'gbxLoc';
    ov.innerHTML = '<div class="cbx-sheet gbx-locsheet"><div class="cbx-sheet-h"><b>Choose your Google location</b><button type="button" class="sx-icon-btn" data-gbx-closeloc>' + ic('close') + '</button></div><div class="gbx-loclist"><div class="cbx-loading"><span></span><span></span><span></span></div></div></div>';
    document.body.appendChild(ov);
    get('view=locations').then(function (d) {
      var l = ov.querySelector('.gbx-loclist');
      if (!d.success) { l.innerHTML = '<div class="sx-note warn">' + ic('warning') + '<span>' + esc(d.message) + '</span></div>'; return; }
      if (!d.locations.length) { l.innerHTML = '<p class="sx-muted" style="padding:16px">This Google account has no business locations. Sign in with the account that manages your listing.</p>'; return; }
      l.innerHTML = d.locations.map(function (x, i) { return '<button type="button" class="gbx-loc" data-gbx-loc="' + i + '">' + ic('storefront') + '<span><b>' + esc(x.title) + '</b><small>' + esc(x.address || x.account_name) + '</small></span>' + ic('chevron_right') + '</button>'; }).join('');
      l._locs = d.locations;
    });
  }

  /* ───────── events ───────── */
  document.addEventListener('click', function (e) {
    var more = e.target.closest('[data-gbx-more]');
    if (more) { var p = more.previousElementSibling; var open = p.classList.toggle('full'); more.textContent = open ? 'Show less' : 'Read more'; return; }
    var t = e.target.closest('[data-gbx-tab],[data-gbx-refresh],[data-gbx-connect],[data-gbx-search],[data-gbx-place],[data-gbx-findplace],[data-gbx-locations],[data-gbx-loc],[data-gbx-closeloc],[data-gbx-filter],[data-gbx-ai],[data-gbx-send],[data-gbx-edit],[data-gbx-unreply],[data-gbx-topic],[data-gbx-post],[data-gbx-delpost],[data-gbx-combo],[data-gbx-useweb],[data-gbx-saveinfo],[data-gbx-savehours],[data-gbx-copymon],[data-gbx-days],[data-gbx-copy],[data-gbx-qrpng],[data-gbx-print],[data-gbx-disconnect]');
    if (!t) return;
    var a = function (n) { return t.getAttribute(n); };
    if (t.hasAttribute('data-gbx-tab')) { tab = a('data-gbx-tab'); draw(); return; }
    if (t.hasAttribute('data-gbx-refresh')) {
      t.disabled = true; t.classList.add('spin');
      post_({ action: 'refresh' }).then(function (d) { notify(d.success ? 'Up to date' : d.message, d.success ? 'success' : 'error'); S = null; window.loadGoogleBusiness(); });
      return;
    }
    if (t.hasAttribute('data-gbx-connect')) { get('view=connect').then(function (d) { if (d.success) location.href = d.url; else notify(d.message, 'error'); }); return; }
    if (t.hasAttribute('data-gbx-findplace')) { e.preventDefault(); S.place = null; draw(); return; }
    if (t.hasAttribute('data-gbx-search')) {
      var box = document.getElementById('gbxPlaces'); box.innerHTML = '<div class="cbx-loading"><span></span><span></span><span></span></div>';
      post_({ action: 'places_search', q: document.getElementById('gbxPlaceQ').value }).then(function (d) {
        if (!d.success) { box.innerHTML = '<div class="sx-note warn">' + ic('warning') + '<span>' + esc(d.message) + '</span></div>'; return; }
        box.innerHTML = d.places.length ? d.places.map(function (p) { return '<button type="button" class="gbx-loc" data-gbx-place="' + esc(p.id) + '">' + ic('location_on') + '<span><b>' + esc(p.name) + '</b><small>' + esc(p.address) + '</small></span>' + (p.rating ? '<em>' + p.rating.toFixed(1) + ' ★ · ' + p.count + '</em>' : '') + '</button>'; }).join('') : '<p class="sx-muted">No match. Try the name with the area.</p>';
      });
      return;
    }
    if (t.hasAttribute('data-gbx-place')) { t.disabled = true; post_({ action: 'set_place', place_id: a('data-gbx-place') }).then(function (d) { notify(d.success ? 'Google rating connected' : d.message, d.success ? 'success' : 'error'); S = null; window.loadGoogleBusiness(); }); return; }
    if (t.hasAttribute('data-gbx-locations')) { pickLocation(); return; }
    if (t.hasAttribute('data-gbx-closeloc')) { var o = document.getElementById('gbxLoc'); if (o) o.remove(); return; }
    if (t.hasAttribute('data-gbx-loc')) {
      var l = t.closest('.gbx-loclist')._locs[+a('data-gbx-loc')];
      t.disabled = true;
      post_({ action: 'select_location', account: l.account, location: l.location, title: l.title, place_id: l.place_id }).then(function (d) {
        var o = document.getElementById('gbxLoc'); if (o) o.remove();
        notify(d.success ? (d.sync && d.sync.ok ? d.sync.count + ' reviews loaded from Google' : 'Location saved. ' + ((d.sync && d.sync.error) || '')) : d.message, d.success ? 'success' : 'error');
        S = null; window.loadGoogleBusiness();
      });
      return;
    }
    if (t.hasAttribute('data-gbx-filter')) { reviews.filter = a('data-gbx-filter'); drawTab(); return; }
    if (t.hasAttribute('data-gbx-ai')) {
      var i = +a('data-gbx-ai'), r = reviews.list[i], ta = document.querySelector('[data-gbx-text="' + i + '"]'), tone = (document.querySelector('[data-gbx-tone="' + i + '"]') || {}).value || 'warm';
      t.disabled = true; t.classList.add('spin'); ta.classList.add('gbx-typing');
      post_({ action: 'ai_reply', review_id: r.review_id, tone: tone }).then(function (d) {
        t.disabled = false; t.classList.remove('spin'); ta.classList.remove('gbx-typing');
        if (!d.success) { notify(d.message, 'error'); return; }
        typeInto(ta, d.text);
      });
      return;
    }
    if (t.hasAttribute('data-gbx-send')) {
      var si = +a('data-gbx-send'), sr = reviews.list[si], txt = (document.querySelector('[data-gbx-text="' + si + '"]') || {}).value || '';
      if (!txt.trim()) { notify('Write a reply first.', 'error'); return; }
      t.disabled = true;
      post_({ action: 'reply', review_id: sr.review_id, comment: txt }).then(function (d) {
        t.disabled = false;
        if (!d.success) { notify(d.message, 'error'); return; }
        sr.reply = txt.trim(); notify('Reply posted on Google', 'success');
        if (S.unanswered) S.unanswered--;
        drawReviews();
      });
      return;
    }
    if (t.hasAttribute('data-gbx-edit')) { var ei = +a('data-gbx-edit'); var art = t.closest('.gbx-rev'); art.querySelector('.gbx-reply').outerHTML = replyBox(ei, reviews.list[ei].reply); return; }
    if (t.hasAttribute('data-gbx-unreply')) {
      var ui = +a('data-gbx-unreply');
      if (!t.classList.contains('ask')) { t.classList.add('ask'); t.innerHTML = 'Remove reply?'; setTimeout(function () { t.classList.remove('ask'); t.innerHTML = ic('delete') + 'Remove'; }, 3500); return; }
      post_({ action: 'delete_reply', review_id: reviews.list[ui].review_id }).then(function (d) { if (d.success) { reviews.list[ui].reply = null; drawReviews(); } notify(d.success ? 'Reply removed' : d.message, d.success ? 'success' : 'error'); });
      return;
    }
    if (t.hasAttribute('data-gbx-topic')) { post.topic = a('data-gbx-topic'); drawTab(); return; }
    if (t.hasAttribute('data-gbx-combo')) {
      var c = (combos || []).filter(function (x) { return x.id === +a('data-gbx-combo'); })[0];
      if (!c) return;
      post.topic = 'OFFER'; post.title = c.name.slice(0, 58);
      post.summary = c.name + ' for just ' + (window.globalCurrencySymbol || '₹') + c.price + (c.save > 0 ? ' (save ' + (window.globalCurrencySymbol || '₹') + c.save + ')' : '') + '! ' +
        c.slots.map(function (s) { return (s.qty > 1 ? s.qty + ' × ' : '') + (s.choices.length > 1 ? s.title.toLowerCase() : s.choices[0].name); }).join(', ') + '. Order online or visit us today.';
      post.url = webUrl(); drawTab(); return;
    }
    if (t.hasAttribute('data-gbx-post')) {
      t.disabled = true;
      post_(Object.assign({ action: 'create_post' }, post)).then(function (d) {
        t.disabled = false;
        if (!d.success) { notify(d.message, 'error'); return; }
        notify('Posted to Google' + (d.state && d.state !== 'LIVE' ? ' (' + d.state.toLowerCase() + ')' : ''), 'success');
        post = { topic: post.topic }; drawTab();
      });
      return;
    }
    if (t.hasAttribute('data-gbx-delpost')) {
      if (!t.classList.contains('ask')) { t.classList.add('ask'); setTimeout(function () { t.classList.remove('ask'); }, 3500); notify('Tap delete again to remove it from Google', 'info'); return; }
      post_({ action: 'delete_post', id: +a('data-gbx-delpost') }).then(function (d) { notify(d.success ? 'Post deleted' : d.message, d.success ? 'success' : 'error'); drawTab(); });
      return;
    }
    if (t.hasAttribute('data-gbx-useweb')) { var w = document.getElementById('gbxWeb'); w.value = w.getAttribute('data-ours'); return; }
    if (t.hasAttribute('data-gbx-saveinfo')) {
      t.disabled = true;
      post_({ action: 'info_update', phone: document.getElementById('gbxPhone').value, website: document.getElementById('gbxWeb').value, description: document.getElementById('gbxDesc').value })
        .then(function (d) { t.disabled = false; notify(d.success ? 'Saved on Google (Google may review changes before they show)' : d.message, d.success ? 'success' : 'error'); });
      return;
    }
    if (t.hasAttribute('data-gbx-copymon')) {
      var rows = document.querySelectorAll('.gbx-hr'), m = rows[0];
      rows.forEach(function (r, k) { if (!k) return; r.querySelector('[data-gbx-open]').checked = m.querySelector('[data-gbx-open]').checked; r.querySelector('[data-gbx-o]').value = m.querySelector('[data-gbx-o]').value; r.querySelector('[data-gbx-c]').value = m.querySelector('[data-gbx-c]').value; syncRow(r); });
      return;
    }
    if (t.hasAttribute('data-gbx-savehours')) {
      var hours = [];
      document.querySelectorAll('.gbx-hr').forEach(function (r) { if (r.querySelector('[data-gbx-open]').checked) hours.push({ day: r.getAttribute('data-day'), open: r.querySelector('[data-gbx-o]').value, close: r.querySelector('[data-gbx-c]').value }); });
      t.disabled = true;
      post_({ action: 'info_update', hours: hours }).then(function (d) { t.disabled = false; notify(d.success ? 'Hours saved on Google' : d.message, d.success ? 'success' : 'error'); });
      return;
    }
    if (t.hasAttribute('data-gbx-days')) { days = +a('data-gbx-days'); drawTab(); return; }
    if (t.hasAttribute('data-gbx-copy')) {
      var v = a('data-gbx-copy');
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(v).then(function () { notify('Link copied', 'success'); }); else notify(v, 'info');
      return;
    }
    if (t.hasAttribute('data-gbx-qrpng')) {
      var qr = window.qrcode(0, 'M'); qr.addData(S.short_url + '&s=qr'); qr.make();
      var link = document.createElement('a'); link.href = qr.createDataURL(12, 4); link.download = 'google-review-qr.gif'; link.click();
      return;
    }
    if (t.hasAttribute('data-gbx-print')) {
      var card = document.getElementById('gbxPrint').outerHTML.replace(/&s=qr/g, '&s=table');
      var w2 = window.open('', '_blank', 'width=480,height=640');
      if (!w2) { notify('Allow pop-ups to print the card.', 'error'); return; }
      var qr2 = window.qrcode(0, 'M'); qr2.addData(S.short_url + '&s=table'); qr2.make();
      w2.document.write('<!doctype html><title>Review card</title><style>body{font-family:system-ui,sans-serif;display:flex;justify-content:center;padding:24px}.c{width:300px;border:2px solid #e5e7eb;border-radius:22px;padding:22px;text-align:center}.c svg.g{width:42px;height:42px}.c b{display:block;font-size:22px;margin:8px 0 2px}.c span{color:#6b7280}.q svg{width:220px;height:220px;margin:14px auto}.c small{display:block;font-weight:700;color:#374151}@media print{body{padding:0}}</style>' +
        '<div class="c">' + GLOGO.replace('gbx-g', 'g') + '<b>Loved your meal?</b><span>Scan to review us on Google</span><div class="q">' + qr2.createSvgTag({ cellSize: 6, margin: 2, scalable: true }) + '</div><small>' + esc(S.restaurant.name) + '</small></div><script>setTimeout(function(){print()},300)<\/script>');
      w2.document.close();
      return;
    }
  });
  document.addEventListener('input', function (e) {
    var t = e.target;
    if (t.hasAttribute('data-gbx-p')) {
      post[t.getAttribute('data-gbx-p')] = t.value;
      var c = document.querySelector('[data-gbx-count]'); if (c && t.getAttribute('data-gbx-p') === 'summary') c.textContent = t.value.length + '/1500';
      return;
    }
    if (t.id === 'gbxDesc') { var dc = document.querySelector('[data-gbx-dcount]'); if (dc) dc.textContent = t.value.length + '/750'; }
  });
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.hasAttribute('data-gbx-p') && /cta|image_url|start|end/.test(t.getAttribute('data-gbx-p'))) { post[t.getAttribute('data-gbx-p')] = t.value; drawTab(); return; }
    if (t.hasAttribute('data-gbx-open')) syncRow(t.closest('.gbx-hr'));
  });
  function syncRow(r) { var on = r.querySelector('[data-gbx-open]').checked; r.querySelectorAll('input[type=time]').forEach(function (x) { x.disabled = !on; }); r.classList.toggle('off', !on); }
  // the AI's reply types itself out
  function typeInto(ta, text) {
    ta.value = ''; var k = 0;
    (function step() { k = Math.min(text.length, k + 3); ta.value = text.slice(0, k); if (k < text.length) setTimeout(step, 12); else ta.focus(); })();
  }

  // the page loader: script.js shows pages by id; load when ours opens (and when the URL asks for it)
  document.addEventListener('click', function (e) { if (e.target.closest('.nav-link[data-page="googleBusinessPage"]')) setTimeout(window.loadGoogleBusiness, 50); });
  document.addEventListener('DOMContentLoaded', function () {
    if (/[?&]gbp=/.test(location.search) || location.hash === '#googleBusinessPage') setTimeout(function () { if (window.SX) SX.goTo('googleBusinessPage'); window.loadGoogleBusiness(); }, 400);
  });
})();
