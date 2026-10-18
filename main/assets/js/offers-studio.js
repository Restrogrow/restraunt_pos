/*
 * Offers: the Offers home (every offer tool, where customers see it, on or off),
 * Coupons (cards with how each coupon did) and Combos & New (which category the Menu
 * page's "Combos" and "New" filters show).
 *
 * Server: api/offers_studio.php. Coupon create / edit still use the existing form
 * (openCouponModal / saveCoupon in script.js) and controllers/coupon_operations.php.
 * Styles: ofx-* in assets/css/studio-ui.css.
 */
(function () {
  'use strict';
  var API = '../api/offers_studio.php';
  var esc = function (s) { return window.SX ? SX.esc(s) : String(s == null ? '' : s); };
  var notify = function (m, t) { if (window.SX) SX.notify(m, t); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var get = function (q) { return fetch(API + '?' + q, { cache: 'no-store' }).then(function (r) { return r.json(); }).catch(function () { return { success: false, message: 'No connection. Please try again.' }; }); };
  var post = function (body) { return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(function (r) { return r.json(); }).catch(function () { return { success: false, message: 'No connection' }; }); };
  var CUR = '₹';
  var money = function (n) { return CUR + Math.round(n || 0).toLocaleString('en-IN'); };
  var loading = '<div class="sx-loading"><span></span><span></span><span></span></div>';
  var errorBox = function (m) { return '<div class="sx-empty">' + ic('error') + '<b>Couldn’t load this</b><span>' + esc(m || 'Please try again.') + '</span></div>'; };

  /* ───────────── Offers home ───────────── */
  var TOOLS = [
    ['Discounts customers use', 'Money off, so more people order.', [
      ['coupons', 'couponsPage', 'confirmation_number', '#2563eb', 'Coupons', 'Codes customers type at checkout, like WELCOME50.', 'Checkout'],
      ['filters', 'offerFiltersPage', 'filter_alt', '#7c3aed', 'Offer filters', 'Quick chips like “Under ₹99” or “50% off” in the offers bubble.', 'Menu page'],
      ['tags', 'dealsPage', 'sell', '#0891b2', 'Combos & Deals', 'Meal deals at one price, with choices. They show at the top of your website.', 'Website'],
    ]],
    ['Put dishes in the spotlight', 'Show the dishes you most want to sell.', [
      ['bestsellers', 'bestsellersPage', 'star', '#f59e0b', 'Bestsellers', 'A row of your best dishes, with an offer price if you like.', 'Home page'],
      ['special', 'todaysSpecialPage', 'local_fire_department', '#ea580c', 'Today’s special', 'A dish for each day of the week, with a countdown and plates left.', 'Home page'],
    ]],
    ['Raise the order value', 'Bigger bills and more repeat orders.', [
      ['upsell', 'upsellPage', 'add_shopping_cart', '#16a34a', 'Complete your meal', 'Suggests a drink or dessert that goes with what’s in the cart.', 'Cart'],
      ['scratch', 'scratchPage', 'redeem', '#db2777', 'Scratch card', 'After paying, customers scratch to win money off their next order.', 'After checkout'],
      ['wallet', 'walletPage', 'account_balance_wallet', '#0d9488', 'Wallet', 'Customers add money and pay from it; you can give bonus and cashback.', 'Checkout'],
    ]],
    ['Bulk orders', 'Parties and events.', [
      ['catering', 'cateringPage', 'celebration', '#9333ea', 'Catering', 'Price per guest for events, and catering requests from customers.', 'Catering page'],
    ]],
  ];

  window.loadOffersHome = function () {
    var root = document.getElementById('offersHomeRoot');
    if (!root) return;
    if (!root.children.length) root.innerHTML = loading;
    get('view=overview').then(function (d) {
      if (!d.success) { root.innerHTML = errorBox(d.message); return; }
      CUR = d.currency || CUR;
      var t = d.tools, on = 0, total = 0, notes = [];
      TOOLS.forEach(function (g) { g[2].forEach(function (x) { total++; if (t[x[0]] && t[x[0]].on) on++; if (t[x[0]] && t[x[0]].attention) notes.push([x, t[x[0]].attention]); }); });
      var html = '<div class="sx-stats ofx-stats">' +
        '<div class="ok">' + ic('toggle_on') + '<b>' + on + ' of ' + total + '</b><small>Offer tools on</small></div>' +
        '<div>' + ic('receipt_long') + '<b>' + d.coupon_orders_30d + '</b><small>Orders with a coupon (30 days)</small></div>' +
        '<div>' + ic('payments') + '<b>' + money(d.coupon_sales_30d) + '</b><small>Sales from those orders</small></div></div>';
      if (notes.length) html += '<div class="ofx-notes">' + notes.map(function (n) { return '<button type="button" class="ofx-note" data-of-go="' + n[0][1] + '">' + ic('priority_high') + '<span><b>' + esc(n[0][4]) + ':</b> ' + esc(n[1]) + '</span>' + ic('chevron_right') + '</button>'; }).join('') + '</div>';
      html += TOOLS.map(function (g, gi) {
        return '<section class="ofx-group" style="animation-delay:' + gi * 50 + 'ms"><div class="ofx-group-h"><b>' + esc(g[0]) + '</b><small>' + esc(g[1]) + '</small></div><div class="ofx-grid">' +
          g[2].map(function (x) {
            var s = t[x[0]] || { on: false, summary: '' };
            return '<button type="button" class="ofx-tool' + (s.on ? ' on' : '') + '" data-of-go="' + x[1] + '" style="--c:' + x[3] + '">' +
              '<span class="ofx-tool-top"><span class="sx-ic">' + ic(x[2]) + '</span><span class="sx-pill ' + (s.on ? 'ok' : 'muted') + '">' + (s.on ? 'On' : 'Off') + '</span></span>' +
              '<b>' + esc(x[4]) + '</b><span class="ofx-what">' + esc(x[5]) + '</span>' +
              '<span class="ofx-where">' + ic('visibility') + 'Shows on: ' + esc(x[6]) + '</span>' +
              '<span class="ofx-sum">' + esc(s.summary) + '</span>' +
              '<span class="ofx-open">Manage' + ic('arrow_forward') + '</span></button>';
          }).join('') + '</div></section>';
      }).join('');
      root.innerHTML = html;
    });
  };

  /* ───────────── Coupons ───────────── */
  var C = { list: [], filter: 'all', q: '', delAsk: 0 };
  var STATE = { active: ['ok', 'Live'], paused: ['muted', 'Paused'], expired: ['bad', 'Ended'], used_up: ['warn', 'Used up'], scheduled: ['wait', 'Starts later'] };
  var fmtDate = function (d) { if (!d) return ''; var x = new Date(d + 'T00:00:00'); return x.toLocaleDateString([], { day: 'numeric', month: 'short', year: x.getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined }); };

  function couponCard(c) {
    var st = STATE[c.state] || ['muted', c.state];
    var val = c.type === 'percent' ? (+c.value) + '% off' : money(c.value) + ' off';
    var min = c.min_order > 0 ? 'on orders above ' + money(c.min_order) : 'on any order';
    var valid = c.state === 'expired' ? 'Ended ' + fmtDate(c.valid_until) : c.state === 'scheduled' ? 'Starts ' + fmtDate(c.valid_from) : c.valid_until ? 'Until ' + fmtDate(c.valid_until) : 'No end date';
    var uses = c.max_uses > 0 ? c.uses + ' of ' + c.max_uses + ' used' : 'Used ' + c.uses + (c.uses === 1 ? ' time' : ' times');
    var pct = c.max_uses > 0 ? Math.min(100, Math.round(c.uses * 100 / c.max_uses)) : 0;
    return '<article class="ofx-coupon' + (c.state === 'active' ? '' : ' dim') + '">' +
      '<div class="ofx-c-top"><button type="button" class="ofx-code" data-cp-copy="' + esc(c.code) + '" title="Copy code">' + esc(c.code) + ic('content_copy') + '</button>' +
        '<span class="sx-pill ' + st[0] + '">' + st[1] + '</span></div>' +
      '<div class="ofx-c-val"><b>' + esc(val) + '</b><span>' + esc(min) + '</span></div>' +
      (c.description ? '<p class="ofx-c-desc">' + esc(c.description) + '</p>' : '') +
      (c.milestone ? '<span class="ofx-c-tag" title="Shown in the cart as “add ₹X more to unlock”">' + ic('flag') + 'Milestone offer</span>' : '') +
      '<div class="ofx-c-meta"><span>' + ic('event') + esc(valid) + '</span><span>' + ic('group') + esc(uses) + '</span></div>' +
      (c.max_uses > 0 ? '<div class="ofx-bar"><i style="width:' + pct + '%"></i></div>' : '') +
      (window.RGStats ? RGStats.funnel([
          { label: 'Link taps', value: c.link ? c.link.people : 0, icon: 'touch_app', muted: !c.link, hint: c.link ? c.link.clicks + ' taps in all' : 'Share the coupon link to count taps' },
          { label: 'Orders (30 days)', value: c.orders_30d, icon: 'shopping_bag', strong: true, noPct: true },
          { label: 'Discount given', text: money(c.given_30d), value: null, icon: 'sell', noPct: true }], { money: CUR, revenue: c.sales_30d, compact: true })
        : '<div class="ofx-c-res">' + (c.orders_30d ? '<b>' + c.orders_30d + ' order' + (c.orders_30d > 1 ? 's' : '') + '</b> · ' + money(c.sales_30d) + ' in sales · ' + money(c.given_30d) + ' discount (30 days)' : '<span class="sx-muted">Not used in the last 30 days</span>') + '</div>') +
      (c.link ? '<div class="rgs-link"><span class="material-symbols-rounded">link</span><code>' + esc(c.link.short.replace(/^https?:\/\//, '')) + '</code><button type="button" data-cp-share="' + esc(c.code) + '">Copy</button></div>' : '') +
      '<div class="ofx-c-acts">' +
        '<label class="st-switch" title="' + (c.is_active ? 'Pause this coupon' : 'Turn it back on') + '"><input type="checkbox" data-cp-toggle="' + c.id + '"' + (c.is_active ? ' checked' : '') + '><i></i></label><span class="ofx-c-sw">' + (c.is_active ? 'On' : 'Paused') + '</span>' +
        '<span class="ofx-spacer"></span>' +
        (c.link ? '' : '<button type="button" class="btn btn-secondary btn-sm" data-cp-share="' + esc(c.code) + '" title="A link that opens your menu with this coupon applied">' + ic('share') + 'Share link</button>') +
        '<button type="button" class="btn btn-secondary btn-sm" data-cp-edit="' + c.id + '">' + ic('edit') + 'Edit</button>' +
        '<button type="button" class="btn btn-secondary btn-sm sx-danger-ghost" data-cp-del="' + c.id + '">' + ic('delete') + (C.delAsk === c.id ? 'Sure?' : '') + '</button>' +
      '</div></article>';
  }

  function drawCoupons() {
    var root = document.getElementById('couponsRoot');
    if (!root) return;
    var L = C.list;
    var count = function (f) { return L.filter(function (c) { return f === 'all' || (f === 'ended' ? (c.state === 'expired' || c.state === 'used_up') : f === 'live' ? c.state === 'active' : c.state === f); }).length; };
    var shown = L.filter(function (c) {
      if (C.filter === 'live' && c.state !== 'active') return false;
      if (C.filter === 'paused' && c.state !== 'paused') return false;
      if (C.filter === 'ended' && c.state !== 'expired' && c.state !== 'used_up') return false;
      if (C.q) { var q = C.q.toLowerCase(); return c.code.toLowerCase().indexOf(q) > -1 || (c.description || '').toLowerCase().indexOf(q) > -1; }
      return true;
    });
    var o = 0, s = 0, g = 0; L.forEach(function (c) { o += c.orders_30d; s += c.sales_30d; g += c.given_30d; });
    var html = '<div class="sx-stats ofx-stats">' +
      '<div class="ok">' + ic('confirmation_number') + '<b>' + count('live') + '</b><small>Live coupons</small></div>' +
      '<div>' + ic('receipt_long') + '<b>' + o + '</b><small>Orders with a coupon (30 days)</small></div>' +
      '<div>' + ic('payments') + '<b>' + money(s) + '</b><small>Sales from them</small></div>' +
      '<div>' + ic('sell') + '<b>' + money(g) + '</b><small>Discount given</small></div></div>';
    html += '<div class="sx-toolbar"><label class="sx-search">' + ic('search') + '<input type="search" id="cpSearch" placeholder="Find a code" value="' + esc(C.q) + '"></label>' +
      '<div class="sx-toolbar-r"><button type="button" class="btn btn-primary" data-cp-new>' + ic('add') + 'New coupon</button></div></div>' +
      '<div class="sx-filters"><div class="sx-seg">' + [['all', 'All'], ['live', 'Live'], ['paused', 'Paused'], ['ended', 'Ended']].map(function (f) {
        return '<button type="button" data-cp-filter="' + f[0] + '" class="' + (C.filter === f[0] ? 'on' : '') + '">' + f[1] + ' <em>' + count(f[0]) + '</em></button>'; }).join('') + '</div></div>';
    if (!L.length) {
      html += '<div class="sx-empty">' + ic('confirmation_number') + '<b>No coupons yet</b><span>Start with one of these. You can change anything before saving.</span>' +
        '<div class="ofx-ideas">' + IDEAS.map(function (x, i) { var lbl = (x[1] === 'flat' ? money(x[2]) + ' off' : x[2] + '% off') + (x[3] ? ' above ' + money(x[3]) : ' everything'); return '<button type="button" class="btn btn-secondary" data-cp-idea="' + i + '"><b>' + x[0] + '</b>&nbsp;' + esc(lbl) + '</button>'; }).join('') + '</div></div>';
    } else if (!shown.length) {
      html += '<div class="sx-empty">' + ic('search_off') + '<b>Nothing here</b><span>No coupon matches this filter.</span></div>';
    } else {
      html += '<div class="ofx-coupons">' + shown.map(couponCard).join('') + '</div>';
    }
    root.innerHTML = html;
  }
  // ready-made ideas for the first coupon: [code, type, value, min order, description]
  var IDEAS = [
    ['WELCOME50', 'flat', 50, 299, 'For new customers', '₹50 off above ₹299'],
    ['SAVE10', 'percent', 10, 0, '10% off any order', '10% off everything'],
    ['WEEKEND15', 'percent', 15, 499, 'Weekend treat', '15% off above ₹499'],
  ];

  window.loadAdminCoupons = function () {
    var root = document.getElementById('couponsRoot');
    if (!root) return;
    if (!C.list.length) root.innerHTML = loading;
    return get('view=coupons').then(function (d) {
      if (!d.success) { root.innerHTML = errorBox(d.message); return; }
      CUR = d.currency || CUR;
      C.list = d.coupons || []; C.delAsk = 0;
      drawCoupons();
    });
  };

  function couponOp(fields, okMsg) {
    var fd = new FormData();
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch('../controllers/coupon_operations.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); })
      .then(function (d) { notify(d.success ? okMsg : (d.message || 'Could not save'), d.success ? 'success' : 'error'); window.loadAdminCoupons(); })
      .catch(function () { notify('No connection. Please try again.', 'error'); window.loadAdminCoupons(); });
  }

  /* ───────────── Combos & New ───────────── */
  var T = null;
  window.loadDealMenus = function () {};   // the Combos & New page loads everything in loadDeals
  window.loadDeals = function () {
    var root = document.getElementById('dealsRoot');
    if (!root) return;
    if (!T) root.innerHTML = loading;
    get('view=tags').then(function (d) {
      if (!d.success) { root.innerHTML = errorBox(d.message); return; }
      T = d; drawTags();
    });
  };
  function drawTags() {
    var root = document.getElementById('dealsRoot');
    if (!root || !T) return;
    var slot = function (type, icon, title, help) {
      var cur = T[type], cat = T.categories.filter(function (c) { return c.id === cur; })[0];
      return '<div class="ofx-tagcard' + (cat ? ' on' : '') + '"><div class="ofx-tag-h"><span class="sx-ic" style="--c:' + (type === 'combo' ? '#0891b2' : '#16a34a') + '">' + ic(icon) + '</span><div><b>' + title + '</b><small>' + help + '</small></div>' +
        '<span class="sx-pill ' + (cat ? 'ok' : 'muted') + '">' + (cat ? 'Showing' : 'Off') + '</span></div>' +
        '<label class="ofx-lbl" for="tag_' + type + '">Category</label>' +
        '<select id="tag_' + type + '" data-tag="' + type + '"><option value="0">None (hide this filter)</option>' + T.categories.map(function (c) {
          return '<option value="' + c.id + '"' + (c.id === cur ? ' selected' : '') + '>' + esc(c.name) + ' (' + c.items + ' dish' + (c.items === 1 ? '' : 'es') + ')</option>'; }).join('') + '</select>' +
        (cat ? '<p class="sx-muted ofx-tag-note">' + ic('check_circle') + '<span>Customers who tap <b>' + title + '</b> on your Menu page see the ' + cat.items + ' dish' + (cat.items === 1 ? '' : 'es') + ' in ' + esc(cat.name) + '.</span></p>' : '') + '</div>';
    };
    var combo = T.categories.filter(function (c) { return c.id === T.combo; })[0], neu = T.categories.filter(function (c) { return c.id === T['new']; })[0];
    root.innerHTML = '<div class="sx-note">' + ic('info') + '<span>These add filter buttons to your <b>Menu page</b>. Pick one category for each, or None to hide the button. They don’t change prices; for money off use <a href="#" data-of-go="couponsPage">Coupons</a> or <a href="#" data-of-go="offerFiltersPage">Offer filters</a>.</span></div>' +
      '<div class="ofx-tags">' + slot('combo', 'fastfood', 'Combos', 'For your meal deals and combos.') + slot('new', 'fiber_new', 'New', 'For dishes you’ve just added.') + '</div>' +
      '<div class="ofx-tagprev"><span class="sx-muted">How it looks on your Menu page</span><div class="ofx-chips"><span class="ofx-chip on">All</span>' + (combo ? '<span class="ofx-chip">' + ic('fastfood') + 'Combos</span>' : '') + (neu ? '<span class="ofx-chip">' + ic('fiber_new') + 'New</span>' : '') +
        (!combo && !neu ? '<em class="sx-muted">No extra filters</em>' : '') + '</div></div>';
  }

  /* ───────────── events ───────────── */
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-of-go], [data-cp-new], [data-cp-idea], [data-cp-edit], [data-cp-del], [data-cp-filter], [data-cp-copy], [data-cp-share]');
    if (!t) return;
    if (t.hasAttribute('data-cp-share')) {
      t.disabled = true;
      fetch('../api/offers_studio.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'coupon_link', code: t.getAttribute('data-cp-share') }) })
        .then(function (r) { return r.json(); }).then(function (d) {
          t.disabled = false;
          if (!d.success) { notify(d.message || 'Could not make the link', 'error'); return; }
          var done = function () { notify('Link copied. It opens your menu with the coupon applied, and every tap is counted.', 'success'); if (window.loadAdminCoupons) window.loadAdminCoupons(); };
          if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(d.short).then(done, function () { notify(d.short, 'info'); });
          else { notify(d.short, 'info'); if (window.loadAdminCoupons) window.loadAdminCoupons(); }
        }).catch(function () { t.disabled = false; notify('No connection. Please try again.', 'error'); });
      return;
    }
    if (t.hasAttribute('data-of-go')) { e.preventDefault(); if (window.SX) SX.goTo(t.getAttribute('data-of-go')); return; }
    if (t.hasAttribute('data-cp-new')) { if (window.openCouponModal) openCouponModal(); return; }
    if (t.hasAttribute('data-cp-idea')) {
      var x = IDEAS[+t.getAttribute('data-cp-idea')];
      if (!window.openCouponModal) return;
      openCouponModal();
      var set = function (id, v) { var el = document.getElementById(id); if (el) el.value = v; };
      set('cpnCode', x[0]); set('cpnType', x[1]); set('cpnValue', x[2]); set('cpnMinOrder', x[3]); set('cpnDesc', x[4]);
      return;
    }
    if (t.hasAttribute('data-cp-edit')) { var c = C.list.filter(function (k) { return k.id === +t.getAttribute('data-cp-edit'); })[0]; if (c && window.openCouponModal) openCouponModal(c.raw); return; }
    if (t.hasAttribute('data-cp-del')) {
      var id = +t.getAttribute('data-cp-del');
      // goes at once with Undo for a few seconds (assets/js/rg-undo.js)
      var cp = C.list.filter(function (k) { return k.id === id; })[0];
      rgUndo({
        html: rgUndo.label('Deleted coupon', cp ? (cp.code || cp.coupon_code || '') : ''),
        hide: t.closest('.ofx-coupon, tr'),
        request: { url: '../controllers/coupon_operations.php', body: { action: 'delete', id: id } },
        onDone: function () { window.loadAdminCoupons(); },
        onError: function (msg) { notify(msg, 'error'); window.loadAdminCoupons(); }
      });
      return;
    }
    if (t.hasAttribute('data-cp-filter')) { C.filter = t.getAttribute('data-cp-filter'); drawCoupons(); return; }
    if (t.hasAttribute('data-cp-copy')) {
      var code = t.getAttribute('data-cp-copy');
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(code).then(function () { notify(code + ' copied', 'success'); });
      else notify(code, 'info');
    }
  });
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.hasAttribute && t.hasAttribute('data-cp-toggle')) {
      couponOp({ action: 'toggle', id: t.getAttribute('data-cp-toggle'), is_active: t.checked ? 1 : 0 }, t.checked ? 'Coupon is on' : 'Coupon paused');
    }
    if (t.hasAttribute && t.hasAttribute('data-tag')) {
      var type = t.getAttribute('data-tag');
      t.disabled = true;
      post({ action: 'tag', type: type, menu_id: +t.value }).then(function (d) {
        notify(d.success ? (+t.value ? 'Saved: the ' + (type === 'combo' ? 'Combos' : 'New') + ' filter shows that category' : 'That filter is hidden now') : (d.message || 'Could not save'), d.success ? 'success' : 'error');
        window.loadDeals();
      });
    }
  });
  document.addEventListener('input', function (e) {
    if (e.target.id === 'cpSearch') {
      C.q = e.target.value;
      var pos = e.target.selectionStart;
      drawCoupons();
      var s = document.getElementById('cpSearch'); if (s) { s.focus(); try { s.setSelectionRange(pos, pos); } catch (err) {} }
    }
  });
})();
