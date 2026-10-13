/*
 * Marketing studio: the shared engine behind Posters, Videos, Captions, Calendar and My campaigns.
 *
 *  - Brand kit (MK.brand / MK.openBrandKit): logo, two colours, headline font, phone, address,
 *    tagline, hashtags and caption language, set once and used by every design.
 *    Stored by api/marketing_studio.php (?view=brand / action 'brand').
 *  - Campaign (MK.campaign / MK.setCampaign): a name, a link tag (src) and an attached coupon,
 *    shared by the poster, video and caption made for it (sessionStorage), shown in a bar on each page.
 *  - Links and QR codes (MK.link / MK.qr): the menu link with ?src=<tag>&coupon=<code>; the customer
 *    website remembers the tag for the order and applies the coupon (website/header.php, cart.php).
 *    QR codes are drawn here (assets/libs/qrcode), no outside service.
 *  - Offer picker (MK.offerPicker): attach one of the restaurant's coupons, or create one in place
 *    (controllers/coupon_operations.php).
 *  - Dish picker (MK.photoPicker): the chosen dish photo, a searchable "Choose dish" window with
 *    every dish photo and price, or an upload. Picking a dish can fill in its name and price.
 *  - Sizes (MK.FORMATS / MK.compose): Square 1080x1080, Story 1080x1920, Wide 1200x628. Templates
 *    draw the square; Story and Wide are built around it with the logo, offer code and QR.
 *  - Saved designs (MK.saveDesign / initMarketingDesigns): "My campaigns" with each design's
 *    visits, orders and sales.
 * Styles: mk-* in assets/css/studio-ui.css.
 */
(function () {
  'use strict';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var notify = function (m, t) { if (window.SX && SX.notify) SX.notify(m, t); };
  var API = '../api/marketing_studio.php';
  var cache = null;

  function imgUrl(ref) {
    if (!ref) return '';
    if (/^(https?:|data:)/i.test(ref)) return ref;
    return '../api/image.php?path=' + encodeURIComponent(ref);
  }
  function load(src) {
    return new Promise(function (ok, no) {
      var im = new Image();
      if (/^https?:/i.test(src) && src.indexOf(location.origin) !== 0) im.crossOrigin = 'anonymous';
      im.onload = function () { ok(im); };
      im.onerror = function () { no(new Error('Photo did not load')); };
      im.src = src;
    });
  }
  function rr(ctx, x, y, w, h, r) {
    r = Math.min(r, w / 2, h / 2);
    ctx.beginPath(); ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
  }
  function cover(ctx, img, x, y, w, h) {
    var ir = img.width / img.height, br = w / h, sx, sy, sw, sh;
    if (ir > br) { sh = img.height; sw = sh * br; sx = (img.width - sw) / 2; sy = 0; } else { sw = img.width; sh = sw / br; sx = 0; sy = (img.height - sh) / 2; }
    ctx.drawImage(img, sx, sy, sw, sh, x, y, w, h);
  }
  function wrap(ctx, text, maxW, maxLines) {
    var words = String(text || '').split(/\s+/).filter(Boolean), lines = [], line = '';
    for (var i = 0; i < words.length; i++) {
      var t = line ? line + ' ' + words[i] : words[i];
      if (ctx.measureText(t).width > maxW && line) { lines.push(line); line = words[i]; if (lines.length >= maxLines) break; } else line = t;
    }
    if (line && lines.length < maxLines) lines.push(line);
    return lines;
  }
  function shade(hex, amt) {   // amt -1..1: towards black / white
    var n = parseInt(String(hex || '#111827').slice(1), 16), r = n >> 16 & 255, g = n >> 8 & 255, b = n & 255, t = amt < 0 ? 0 : 255, p = Math.abs(amt);
    var h = function (v) { return Math.round(v + (t - v) * p).toString(16).padStart(2, '0'); };
    return '#' + h(r) + h(g) + h(b);
  }

  var MK = { lastPicked: {}, load: load, imgUrl: imgUrl, rr: rr, cover: cover, wrap: wrap, shade: shade, esc: esc };
  MK.cur = function () { return window.globalCurrencySymbol || '₹'; };
  MK.slug = function (s) { return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40); };

  /* ── dishes from the menu ── */
  MK.dishes = function () {
    if (cache) return cache;
    cache = fetch('../api/get_menu_items.php', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      var list = (d && (d.data || d.items)) || [];
      return list.filter(function (x) { return String(x.is_hidden) !== '1'; }).map(function (x) {
        return { id: +x.id, name: x.item_name_en || x.item_name || 'Dish', price: parseFloat(x.base_price) || 0, src: x.item_image ? imgUrl(x.item_image) : '', cat: x.menu_name || x.category_name || '' };
      });
    }).catch(function () { return []; });
    return cache;
  };
  MK.photoDishes = function () { return MK.dishes().then(function (l) { return l.filter(function (d) { return d.src; }); }); };
  MK.firstPhoto = function () {
    return MK.photoDishes().then(function (list) { return list.length ? load(list[0].src).then(function (im) { im._dish = list[0]; return { img: im, dish: list[0] }; }) : null; }).catch(function () { return null; });
  };

  /* ── brand kit ── */
  var brand = null, brandP = null, logoImg = null;
  function defaults() {
    return { saved: false, logo: '', color1: '#F97316', color2: '#111827', font: 'modern', phone: window.restaurantPhone || '', address: '', tagline: '', hashtags: '', language: 'en' };
  }
  function loadLogo() {
    var src = (brand && brand.logo) || window.restaurantLogoUrl || '';
    if (!src || /logo-192\.png/.test(src)) { logoImg = null; return Promise.resolve(); }   // RestroGrow's own logo is only a placeholder
    return load(src).then(function (im) { logoImg = im; }).catch(function () { logoImg = null; });
  }
  MK.brand = function () {
    if (!brandP) brandP = fetch(API + '?view=brand', { cache: 'no-store' }).then(function (r) { return r.json(); })
      .then(function (d) { brand = (d && d.brand) || defaults(); }).catch(function () { brand = defaults(); })
      .then(loadLogo).then(function () { return brand; });
    return brandP;
  };
  MK.brandNow = function () { return brand || defaults(); };
  MK.logo = function () { return logoImg; };
  MK.FONTS = { modern: "'Poppins'", elegant: "'Playfair Display'", bold: "'Bebas Neue'" };
  // Headlines in the brand font: big bold Poppins text on this canvas is drawn in the chosen font instead.
  var fontDesc = window.CanvasRenderingContext2D && Object.getOwnPropertyDescriptor(CanvasRenderingContext2D.prototype, 'font');
  MK.useBrandFont = function (ctx) {
    var fam = MK.FONTS[MK.brandNow().font];
    if (!fontDesc || !fam || fam === MK.FONTS.modern) { delete ctx.font; return; }
    Object.defineProperty(ctx, 'font', { configurable: true, get: function () { return fontDesc.get.call(this); }, set: function (v) {
      var m = /^(800|700) (\d+)px 'Poppins'/.exec(v);
      if (m && +m[2] >= 46) v = (fam === MK.FONTS.bold ? '' : '700 ') + Math.round(+m[2] * (fam === MK.FONTS.bold ? 1.25 : 1)) + 'px ' + fam;
      fontDesc.set.call(this, v);
    } });
  };

  /* ── links and QR codes ── */
  MK.link = function (o) {
    o = o || {};
    var base = window.getMarketingMenuUrl ? window.getMarketingMenuUrl() : location.origin;
    var qs = [];
    if (o.src) qs.push('src=' + encodeURIComponent(o.src));
    if (o.coupon) qs.push('coupon=' + encodeURIComponent(o.coupon));
    return qs.length ? base + (base.indexOf('?') > -1 ? '&' : '?') + qs.join('&') : base;
  };
  var qrCache = {};
  MK.qr = function (text) {
    if (qrCache[text]) return qrCache[text];
    if (typeof window.qrcode !== 'function') return null;
    var q = window.qrcode(0, 'M'); q.addData(text); q.make();
    var n = q.getModuleCount(), cell = Math.max(4, Math.floor(600 / (n + 4))), size = cell * (n + 4);
    var c = document.createElement('canvas'); c.width = c.height = size;
    var x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, size, size); x.fillStyle = '#111';
    for (var r = 0; r < n; r++) for (var k = 0; k < n; k++) if (q.isDark(r, k)) x.fillRect((k + 2) * cell, (r + 2) * cell, cell, cell);
    qrCache[text] = c;
    return c;
  };

  /* ── the campaign this design belongs to ── */
  var camp = null;
  try { camp = JSON.parse(sessionStorage.getItem('mk_campaign') || 'null'); } catch (e) {}
  MK.campaign = function () { return camp; };
  MK.setCampaign = function (c) {
    camp = c && c.name ? { name: c.name, src: c.src || MK.slug(c.name), coupon: c.coupon || null, idea: c.idea || '' } : null;
    try { if (camp) sessionStorage.setItem('mk_campaign', JSON.stringify(camp)); else sessionStorage.removeItem('mk_campaign'); } catch (e) {}
    drawCampaignBars();
    document.dispatchEvent(new CustomEvent('mk:campaign', { detail: camp }));
  };
  MK.setCampaignCoupon = function (cp) { if (camp) { camp.coupon = cp || null; MK.setCampaign(camp); } };
  // the link tag for a design: the campaign's, or one made from its headline and the month
  MK.srcFor = function (title) {
    if (camp && camp.src) return camp.src;
    var d = new Date();
    return (MK.slug(title) || 'post') + '-' + String(d.getFullYear()).slice(2) + String(d.getMonth() + 1).padStart(2, '0');
  };
  function drawCampaignBars() {
    ['marketingPostersPage', 'marketingVideosPage', 'marketingCaptionsPage'].forEach(function (id) {
      var page = document.getElementById(id); if (!page) return;
      var bar = page.querySelector('.mk-camp');
      if (!bar) { bar = document.createElement('div'); bar.className = 'mk-camp'; var tabs = page.querySelector('.mk-tabs'); if (tabs) tabs.after(bar); else return; }
      if (!camp) {
        bar.className = 'mk-camp off';
        bar.innerHTML = ic('campaign') + '<span><b>Making a one-off post.</b> Start a campaign to keep the poster, video, caption and offer together and see what they bring in.</span>' +
          '<button type="button" class="mk-btn sm" data-mk-camp="start">' + ic('add') + 'Start a campaign</button>';
        return;
      }
      bar.className = 'mk-camp';
      bar.innerHTML = ic('campaign') + '<span><b>' + esc(camp.name) + '</b>' +
        (camp.coupon ? '<em class="mk-code">' + ic('sell') + esc(camp.coupon.code) + (camp.coupon.label ? ' · ' + esc(camp.coupon.label) : '') + '</em>' : '<em class="mk-muted">No offer attached yet</em>') +
        '<small>Link tag: ' + esc(camp.src) + '</small></span>' +
        '<button type="button" class="mk-btn sm ghost" data-mk-camp="copy">' + ic('link') + 'Copy link</button>' +
        '<button type="button" class="mk-btn sm ghost" data-mk-camp="end">' + ic('close') + 'End</button>';
    });
  }
  MK.startCampaignPrompt = function (suggest) {
    var html = '<div class="mk-form"><label>Campaign name<input id="mkCampName" maxlength="60" value="' + esc(suggest || '') + '" placeholder="e.g. Diwali 2026, Monsoon combos"></label>' +
      '<p class="mk-hint">Everything you make next (poster, video, caption) gets the same link and offer, and its results show together in My campaigns.</p></div>';
    return window.rgModal({ title: 'Start a campaign', icon: 'campaign', html: html, confirmText: 'Start', onConfirm: function () {
      var v = (document.getElementById('mkCampName') || {}).value || '';
      if (!v.trim()) throw new Error('Give the campaign a name.');
      return v.trim();
    } }).then(function (r) { if (r && r.ok && r.value) MK.setCampaign({ name: r.value }); return r; });
  };

  /* ── coupons ── */
  var coupP = null;
  MK.coupons = function (fresh) {
    if (!coupP || fresh) coupP = fetch('../api/get_admin_coupons.php', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      var today = new Date().toISOString().slice(0, 10);
      return ((d && d.coupons) || []).filter(function (c) { return String(c.is_active) === '1' && (!c.valid_until || c.valid_until >= today) && (!(+c.max_uses) || +c.current_uses < +c.max_uses); });
    }).catch(function () { return []; });
    return coupP;
  };
  MK.couponLabel = function (c) {
    var v = parseFloat(c.discount_value) || 0, min = parseFloat(c.minimum_order_amount) || 0;
    return (c.discount_type === 'flat' ? MK.cur() + Math.round(v) : Math.round(v) + '%') + ' OFF' + (min > 0 ? ' above ' + MK.cur() + Math.round(min) : '');
  };
  // shorter, for a badge
  MK.couponBadge = function (c) { var v = parseFloat(c.discount_value) || 0; return (c.discount_type === 'flat' ? MK.cur() + Math.round(v) : Math.round(v) + '%') + ' OFF'; };
  MK.createCoupon = function (o) {
    var fd = new FormData();
    fd.append('action', 'add'); fd.append('coupon_code', o.code); fd.append('discount_type', o.type); fd.append('discount_value', o.value);
    fd.append('description', o.desc || ''); fd.append('minimum_order_amount', o.min || 0); fd.append('max_uses', 0);
    fd.append('valid_from', ''); fd.append('valid_until', o.until || ''); fd.append('is_milestone', '0');
    return fetch('../controllers/coupon_operations.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  };
  /**
   * Attach an offer: one of the restaurant's live coupons, or a new one made right here.
   * opt: { current: {code,label,badge}|null, suggestCode, desc, onChange(coupon|null) }
   */
  MK.offerPicker = function (opt) {
    var el = document.createElement('div');
    el.className = 'mk-offer';
    var until = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
    el.innerHTML = '<label class="mk-lbl">Coupon customers can use</label><select class="mk-offer-sel"><option value="">No coupon (just a poster)</option></select>' +
      '<div class="mk-offer-new" hidden><div class="mk-row"><label>Code<input class="mk-c-code" maxlength="20" value="' + esc(opt.suggestCode || '') + '" placeholder="e.g. DIWALI20"></label>' +
      '<label>Discount<span class="mk-inline"><input class="mk-c-val" type="number" min="1" value="20"><select class="mk-c-type"><option value="percent">% off</option><option value="flat">' + esc(MK.cur()) + ' off</option></select></span></label></div>' +
      '<div class="mk-row"><label>Minimum order<input class="mk-c-min" type="number" min="0" value="0"></label><label>Valid until<input class="mk-c-until" type="date" value="' + until + '"></label></div>' +
      '<button type="button" class="mk-btn sm mk-c-make">' + ic('add') + 'Create coupon</button><p class="mk-hint">It goes live on your website right away and shows in Offers › Coupons.</p></div>' +
      '<p class="mk-hint mk-offer-note"></p>';
    var sel = el.querySelector('.mk-offer-sel'), box = el.querySelector('.mk-offer-new'), note = el.querySelector('.mk-offer-note'), rows = {};
    function setNote(cp) { note.innerHTML = cp ? ic('qr_code_2') + 'The QR code and link open your menu with <b>' + esc(cp.code) + '</b> already applied.' : ''; }
    function fill(selected) {
      return MK.coupons(true).then(function (list) {
        rows = {};
        sel.innerHTML = '<option value="">No coupon (just a poster)</option>' + list.map(function (c) { rows[c.coupon_code] = c; return '<option value="' + esc(c.coupon_code) + '">' + esc(c.coupon_code) + ' · ' + esc(MK.couponLabel(c)) + '</option>'; }).join('') +
          '<option value="__new">+ Create a new coupon</option>';
        if (selected && !rows[selected.code]) sel.insertAdjacentHTML('afterbegin', '<option value="' + esc(selected.code) + '">' + esc(selected.code) + (selected.label ? ' · ' + esc(selected.label) : '') + '</option>');
        sel.value = selected ? selected.code : '';
        setNote(selected);
      });
    }
    sel.addEventListener('change', function () {
      box.hidden = sel.value !== '__new';
      if (sel.value === '__new') { el.querySelector('.mk-c-code').focus(); return; }
      var c = rows[sel.value];
      var cp = c ? { code: c.coupon_code, label: MK.couponLabel(c), badge: MK.couponBadge(c) } : null;
      setNote(cp); opt.onChange(cp);
    });
    el.querySelector('.mk-c-make').addEventListener('click', function () {
      var code = el.querySelector('.mk-c-code').value.trim().toUpperCase().replace(/[^A-Z0-9_-]/g, ''), val = el.querySelector('.mk-c-val').value;
      if (code.length < 3) { notify('Use a code of at least 3 letters or numbers.', 'error'); return; }
      if (!(parseFloat(val) > 0)) { notify('Enter the discount.', 'error'); return; }
      var btn = this; btn.disabled = true;
      MK.createCoupon({ code: code, type: el.querySelector('.mk-c-type').value, value: val, min: el.querySelector('.mk-c-min').value, until: el.querySelector('.mk-c-until').value, desc: opt.desc || '' }).then(function (d) {
        btn.disabled = false;
        if (!d || !d.success) { notify((d && d.message) || 'Could not create the coupon.', 'error'); return; }
        notify('Coupon ' + code + ' created', 'success');
        box.hidden = true;
        fill({ code: code }).then(function () { sel.value = code; sel.dispatchEvent(new Event('change')); });
      }).catch(function () { btn.disabled = false; notify('Could not create the coupon.', 'error'); });
    });
    fill(opt.current);
    return el;
  };

  /* ── simple window for pickers and forms ── */
  MK.sheet = function (title, body, wide) {
    var ov = document.createElement('div');
    ov.className = 'mk-ov';
    ov.innerHTML = '<div class="mk-sheet' + (wide ? ' wide' : '') + '" role="dialog" aria-modal="true"><div class="mk-sheet-h"><b>' + esc(title) + '</b><button type="button" class="mk-x" aria-label="Close">' + ic('close') + '</button></div><div class="mk-sheet-b"></div></div>';
    ov.querySelector('.mk-sheet-b').appendChild(body);
    function close() { ov.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') close(); }
    ov.addEventListener('click', function (e) { if (e.target === ov || e.target.closest('.mk-x')) close(); });
    document.addEventListener('keydown', onKey);
    document.body.appendChild(ov);
    return { el: ov, close: close };
  };

  /* ── dish photo picker ── */
  MK.photoPicker = function (opt) {
    var key = opt.label || 'Photo';
    var wrapEl = document.createElement('div');
    wrapEl.className = 'poster-field mk-photo';
    wrapEl.innerHTML = '<label>' + esc(key) + '</label><div class="mk-pick"><span class="mk-pick-img">' + ic('restaurant') + '</span><span class="mk-pick-t"><b>' + esc(MK.lastPicked[key] || opt.selectedName || 'No photo yet') + '</b><small>From your menu or your own photo</small></span>' +
      '<button type="button" class="mk-btn sm mk-pick-go">' + ic('grid_view') + 'Choose dish</button><label class="mk-btn sm ghost mk-up">' + ic('upload') + 'Upload<input type="file" accept="image/png,image/jpeg,image/webp" hidden></label></div>' +
      (opt.onUseText ? '<button type="button" class="mk-usetext" hidden></button>' : '');
    var imgBox = wrapEl.querySelector('.mk-pick-img'), title = wrapEl.querySelector('.mk-pick-t b'), use = wrapEl.querySelector('.mk-usetext'), picked = null;
    function show(src, name) { imgBox.innerHTML = src ? '<img alt="" src="' + esc(src) + '">' : ic('restaurant'); title.textContent = name; }
    if (opt.currentSrc) show(opt.currentSrc, MK.lastPicked[key] || opt.selectedName || 'Your photo');
    function choose(dish) {
      load(dish.src).then(function (im) { im._dish = dish; opt.onImage(im, dish); }).catch(function () { notify('That photo could not be used. Try another one.', 'error'); });
      picked = dish; MK.lastPicked[key] = dish.name; show(dish.src, dish.name);
      if (use) { use.hidden = false; use.innerHTML = ic('text_fields') + 'Use “' + esc(dish.name) + (dish.price ? ' · ' + MK.cur() + Math.round(dish.price) : '') + '” as the text'; }
    }
    wrapEl.querySelector('.mk-pick-go').addEventListener('click', function () {
      var body = document.createElement('div');
      body.innerHTML = '<div class="mk-search">' + ic('search') + '<input type="search" placeholder="Search your dishes"></div><div class="mk-dish-grid"><p class="mk-hint">Loading your dishes…</p></div>';
      var sh = MK.sheet('Choose a dish', body, true), grid = body.querySelector('.mk-dish-grid'), q = body.querySelector('input');
      MK.photoDishes().then(function (list) {
        function draw() {
          var t = q.value.trim().toLowerCase();
          var l = list.filter(function (d) { return !t || d.name.toLowerCase().indexOf(t) > -1 || d.cat.toLowerCase().indexOf(t) > -1; });
          grid.innerHTML = l.length ? l.map(function (d) {
            return '<button type="button" data-i="' + list.indexOf(d) + '"' + (d.name === MK.lastPicked[key] ? ' class="on"' : '') + '><img loading="lazy" alt="" src="' + esc(d.src) + '"><b>' + esc(d.name) + '</b>' + (d.price ? '<small>' + esc(MK.cur()) + Math.round(d.price) + '</small>' : '') + '</button>';
          }).join('') : '<p class="mk-hint">' + (list.length ? 'No dish matches “' + esc(q.value) + '”.' : 'No dish photos yet. Add photos in Menu › Menu items, or upload a photo.') + '</p>';
        }
        draw(); q.addEventListener('input', draw); setTimeout(function () { q.focus(); }, 30);
        grid.addEventListener('click', function (e) { var b = e.target.closest('button[data-i]'); if (!b) return; choose(list[+b.dataset.i]); sh.close(); });
      });
    });
    if (use) use.addEventListener('click', function () { if (picked) opt.onUseText(picked); });
    wrapEl.querySelector('input[type=file]').addEventListener('change', function (e) {
      var f = e.target.files && e.target.files[0]; if (!f) return;
      var rd = new FileReader();
      rd.onload = function (ev) { load(ev.target.result).then(function (im) { delete MK.lastPicked[key]; if (use) use.hidden = true; show(ev.target.result, f.name.replace(/\.[a-z]+$/i, '')); opt.onImage(im, null); }); };
      rd.readAsDataURL(f);
    });
    return wrapEl;
  };
  // A photo as something we can save: a menu photo by its address, an upload as a small JPEG.
  MK.imgRef = function (img) {
    if (!img) return null;
    if (img.src && img.src.indexOf('data:') !== 0) return img.src;
    var max = 1080, s = Math.min(1, max / Math.max(img.width, img.height));
    var c = document.createElement('canvas'); c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
    return c.toDataURL('image/jpeg', 0.82);
  };
  MK.imgFromRef = function (ref) { return ref ? load(ref).catch(function () { return null; }) : Promise.resolve(null); };

  /* ── sizes ── */
  MK.FORMATS = {
    square: { w: 1080, h: 1080, label: 'Square', hint: 'Instagram and Facebook posts' },
    story: { w: 1080, h: 1920, label: 'Story', hint: 'WhatsApp Status, Reels and Stories' },
    wide: { w: 1200, h: 628, label: 'Wide', hint: 'Facebook, Google and your website' }
  };
  var bgCache = {};
  function storyBg(photo, c1, c2, w, h) {
    var k = (photo ? (photo.src || '').slice(-60) + photo.width : 'none') + c1 + c2 + w + 'x' + h;
    if (bgCache[k]) return bgCache[k];
    var c = document.createElement('canvas'); c.width = w; c.height = h;
    var x = c.getContext('2d');
    var g = x.createLinearGradient(0, 0, w, h); g.addColorStop(0, shade(c1, -0.15)); g.addColorStop(1, shade(c2, -0.2));
    x.fillStyle = g; x.fillRect(0, 0, w, h);
    if (photo) {
      x.save(); x.filter = 'blur(36px) saturate(1.2)'; x.globalAlpha = 0.55; cover(x, photo, -80, -80, w + 160, h + 160); x.restore();
      x.fillStyle = 'rgba(0,0,0,0.38)'; x.fillRect(0, 0, w, h);
    }
    bgCache = {}; bgCache[k] = c;
    return c;
  }
  function brandRow(ctx, x, y, color, maxW, size) {
    var logo = MK.logo(), b = MK.brandNow(), s = size || 92, tx = x;
    if (logo) {
      ctx.save(); ctx.beginPath(); ctx.arc(x + s / 2, y + s / 2, s / 2, 0, Math.PI * 2); ctx.fillStyle = '#fff'; ctx.fill(); ctx.clip();
      cover(ctx, logo, x + 4, y + 4, s - 8, s - 8); ctx.restore(); tx = x + s + 22;
    }
    ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic'; ctx.fillStyle = color;
    ctx.font = "700 " + Math.round(s * 0.44) + "px 'Poppins'";
    var nm = window.restaurantName || 'Your Restaurant';
    while (ctx.measureText(nm).width > maxW - (tx - x) && nm.length > 4) nm = nm.slice(0, -2);
    ctx.fillText(nm, tx, y + s * (b.tagline ? 0.48 : 0.62));
    if (b.tagline) { ctx.font = "500 " + Math.round(s * 0.27) + "px 'Poppins'"; ctx.globalAlpha = 0.8; ctx.fillText(b.tagline, tx, y + s * 0.86); ctx.globalAlpha = 1; }
  }
  function codeBox(ctx, cx, y, code, label, c1, w) {
    rr(ctx, cx - w / 2, y, w, 150, 28); ctx.fillStyle = '#fff'; ctx.fill();
    ctx.setLineDash([14, 10]); ctx.lineWidth = 4; ctx.strokeStyle = c1; rr(ctx, cx - w / 2 + 12, y + 12, w - 24, 126, 20); ctx.stroke(); ctx.setLineDash([]);
    ctx.textAlign = 'center'; ctx.fillStyle = '#6B7280'; ctx.font = "600 26px 'Poppins'";
    ctx.fillText(label ? 'USE CODE · ' + label.toUpperCase() : 'USE CODE', cx, y + 56);
    ctx.fillStyle = shade(c1, -0.25); ctx.font = "84px 'Bebas Neue'"; ctx.fillText(code, cx, y + 128);
  }
  /**
   * Draw the design in a size. sq: the 1080 square design (canvas). info: { photo, headline, coupon:{code,label,badge}, link, color }
   */
  MK.compose = function (ctx, sq, fmt, info) {
    var F = MK.FORMATS[fmt] || MK.FORMATS.square, b = MK.brandNow(), c1 = info.color || b.color1, c2 = b.color2;
    ctx.save();
    ctx.clearRect(0, 0, F.w, F.h);
    if (fmt === 'square' || !MK.FORMATS[fmt]) { ctx.drawImage(sq, 0, 0, F.w, F.h); ctx.restore(); return; }
    var qr = info.link ? MK.qr(info.link) : null;
    if (fmt === 'story') {
      ctx.drawImage(storyBg(info.photo, c1, c2, F.w, F.h), 0, 0);
      brandRow(ctx, 70, 92, '#fff', 940, 104);
      ctx.save(); ctx.shadowColor = 'rgba(0,0,0,.45)'; ctx.shadowBlur = 50; ctx.shadowOffsetY = 20; rr(ctx, 60, 250, 960, 960, 40); ctx.fillStyle = '#fff'; ctx.fill(); ctx.restore();
      ctx.save(); rr(ctx, 60, 250, 960, 960, 40); ctx.clip(); ctx.drawImage(sq, 60, 250, 960, 960); ctx.restore();
      var y = 1262;
      if (info.coupon && info.coupon.code) { codeBox(ctx, 540, y, info.coupon.code, info.coupon.badge || '', c1, 760); y += 196; }
      if (qr) {
        rr(ctx, 70, y, 300, 300, 28); ctx.fillStyle = '#fff'; ctx.fill(); ctx.drawImage(qr, 88, y + 18, 264, 264);
        ctx.textAlign = 'left'; ctx.fillStyle = '#fff'; ctx.font = "800 58px 'Poppins'"; ctx.fillText('Scan to order', 410, y + 92);
        ctx.font = "500 32px 'Poppins'"; ctx.globalAlpha = 0.88;
        wrap(ctx, info.coupon && info.coupon.code ? 'Your offer is applied automatically' : 'See the menu and order online', 600, 2).forEach(function (l, i) { ctx.fillText(l, 410, y + 150 + i * 42); });
        ctx.globalAlpha = 1;
        if (b.phone) { ctx.font = "700 36px 'Poppins'"; ctx.fillText('Call ' + b.phone, 410, y + 262); }
        y += 340;
      }
      if (b.address) { ctx.textAlign = 'center'; ctx.font = "500 28px 'Poppins'"; ctx.fillStyle = 'rgba(255,255,255,.8)'; wrap(ctx, b.address, 940, 2).forEach(function (l, i) { ctx.fillText(l, 540, Math.min(y + 30, 1830) + i * 38); }); }
    } else {   // wide
      var g = ctx.createLinearGradient(F.h, 0, F.w, F.h); g.addColorStop(0, shade(c2, -0.1)); g.addColorStop(1, shade(c1, -0.35));
      ctx.fillStyle = g; ctx.fillRect(0, 0, F.w, F.h);
      ctx.drawImage(sq, 0, 0, F.h, F.h);
      var x0 = F.h + 44, w = F.w - x0 - 40;
      brandRow(ctx, x0, 40, '#fff', w, 62);
      ctx.textAlign = 'left'; ctx.fillStyle = '#fff'; ctx.font = "800 40px 'Poppins'";
      var lines = wrap(ctx, info.headline || '', w, 3); lines.forEach(function (l, i) { ctx.fillText(l, x0, 170 + i * 48); });
      var yy = 170 + lines.length * 48 + 10;
      if (info.coupon && info.coupon.code) {
        rr(ctx, x0, yy, w, 92, 18); ctx.fillStyle = '#fff'; ctx.fill();
        ctx.fillStyle = '#6B7280'; ctx.font = "600 18px 'Poppins'"; ctx.fillText('USE CODE' + (info.coupon.badge ? ' · ' + info.coupon.badge.toUpperCase() : ''), x0 + 20, yy + 32);
        ctx.fillStyle = shade(c1, -0.25); ctx.font = "50px 'Bebas Neue'"; ctx.fillText(info.coupon.code, x0 + 20, yy + 78);
        yy += 112;
      }
      if (qr) {
        var qs = Math.min(150, F.h - yy - 30);
        if (qs > 90) {
          rr(ctx, x0, yy, qs, qs, 14); ctx.fillStyle = '#fff'; ctx.fill(); ctx.drawImage(qr, x0 + 8, yy + 8, qs - 16, qs - 16);
          ctx.fillStyle = '#fff'; ctx.font = "800 28px 'Poppins'"; ctx.fillText('Scan to order', x0 + qs + 20, yy + qs / 2 - 4);
          if (b.phone) { ctx.font = "500 22px 'Poppins'"; ctx.globalAlpha = .85; ctx.fillText('Call ' + b.phone, x0 + qs + 20, yy + qs / 2 + 30); ctx.globalAlpha = 1; }
        }
      }
    }
    ctx.restore();
  };

  /* ── downloads and saving ── */
  MK.fileName = function (s) { return (String(s || 'design').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'design').slice(0, 60); };
  MK.download = function (canvas, name) {
    return new Promise(function (ok) {
      canvas.toBlob(function (blob) {
        if (!blob) { notify('Could not export the image. Try again.', 'error'); ok(); return; }
        var a = document.createElement('a'), u = URL.createObjectURL(blob);
        a.href = u; a.download = name + '.png'; document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(u); ok(); }, 600);
      }, 'image/png');
    });
  };
  MK.thumb = function (canvas) {
    var s = 360 / Math.max(canvas.width, canvas.height), c = document.createElement('canvas');
    c.width = Math.round(canvas.width * s); c.height = Math.round(canvas.height * s);
    c.getContext('2d').drawImage(canvas, 0, 0, c.width, c.height);
    return c.toDataURL('image/jpeg', 0.75);
  };
  MK.saveDesign = function (d) {
    return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ action: 'save' }, d)) })
      .then(function (r) { return r.json(); }).then(function (r) {
        if (r && r.success) { notify('Saved to My campaigns', 'success'); return r.id; }
        notify((r && r.message) || 'Could not save.', 'error'); return null;
      }).catch(function () { notify('Could not save. Check your connection.', 'error'); return null; });
  };
  MK.copy = function (text, btn) {
    return navigator.clipboard.writeText(text).then(function () {
      if (btn) { var o = btn.innerHTML; btn.innerHTML = ic('check') + 'Copied'; setTimeout(function () { btn.innerHTML = o; }, 1400); }
    }).catch(function () { notify('Could not copy. Select the text and copy it.', 'error'); });
  };
  MK.whatsapp = function (text) { window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank', 'noopener'); };

  /* ── brand kit window ── */
  MK.openBrandKit = function () {
    MK.brand().then(function (b) {
      var body = document.createElement('div');
      body.className = 'mk-form mk-kit';
      body.innerHTML = '<p class="mk-hint">Set these once. Every poster, video and caption uses them.</p>' +
        '<div class="mk-kit-logo"><span class="mk-kit-prev"></span><span><b>Logo</b><small>Square PNG or JPG works best</small></span><label class="mk-btn sm ghost">' + ic('upload') + 'Change<input type="file" accept="image/png,image/jpeg,image/webp" hidden></label><button type="button" class="mk-btn sm ghost mk-kit-nologo">Use my profile logo</button></div>' +
        '<div class="mk-row"><label>Main colour<span class="mk-inline"><input type="color" name="color1" value="' + esc(b.color1) + '"><input name="color1t" value="' + esc(b.color1) + '" maxlength="7"></span></label>' +
        '<label>Second colour<span class="mk-inline"><input type="color" name="color2" value="' + esc(b.color2) + '"><input name="color2t" value="' + esc(b.color2) + '" maxlength="7"></span></label></div>' +
        '<label>Headline style</label><div class="mk-fontpick">' + [['modern', 'Modern', "'Poppins'"], ['elegant', 'Elegant', "'Playfair Display'"], ['bold', 'Bold', "'Bebas Neue'"]].map(function (f) {
          return '<button type="button" data-font="' + f[0] + '"' + (b.font === f[0] ? ' class="on"' : '') + '><span style="font-family:' + f[2] + '">Aa</span>' + f[1] + '</button>';
        }).join('') + '</div>' +
        '<div class="mk-row"><label>Phone on posters<input name="phone" maxlength="30" value="' + esc(b.phone) + '"></label><label>Tagline<input name="tagline" maxlength="80" value="' + esc(b.tagline) + '" placeholder="e.g. Since 1998 · Pure veg"></label></div>' +
        '<label>Address<input name="address" maxlength="160" value="' + esc(b.address) + '"></label>' +
        '<label>Your hashtags (added to every caption)<input name="hashtags" maxlength="255" value="' + esc(b.hashtags) + '" placeholder="#SpiceGarden #IndiranagarEats"></label>' +
        '<label>Caption language<select name="language"><option value="en">English</option><option value="hinglish">Hinglish</option><option value="hi">हिन्दी (Hindi)</option></select></label>';
      body.querySelector('[name=language]').value = b.language || 'en';
      var logo = b.logo || '', prev = body.querySelector('.mk-kit-prev');
      function showLogo() { var s = logo || (window.restaurantLogoUrl && !/logo-192/.test(window.restaurantLogoUrl) ? window.restaurantLogoUrl : ''); prev.innerHTML = s ? '<img alt="" src="' + esc(s) + '">' : ic('storefront'); }
      showLogo();
      body.querySelector('input[type=file]').addEventListener('change', function (e) {
        var f = e.target.files && e.target.files[0]; if (!f) return;
        var rd = new FileReader();
        rd.onload = function (ev) { load(ev.target.result).then(function (im) {
          var s = Math.min(1, 400 / Math.max(im.width, im.height)), c = document.createElement('canvas'); c.width = Math.round(im.width * s); c.height = Math.round(im.height * s);
          c.getContext('2d').drawImage(im, 0, 0, c.width, c.height); logo = c.toDataURL('image/png'); if (logo.length > 380000) logo = c.toDataURL('image/jpeg', 0.85); showLogo();
        }); };
        rd.readAsDataURL(f);
      });
      body.querySelector('.mk-kit-nologo').addEventListener('click', function () { logo = ''; showLogo(); });
      ['color1', 'color2'].forEach(function (n) {
        var a = body.querySelector('[name=' + n + ']'), t = body.querySelector('[name=' + n + 't]');
        a.addEventListener('input', function () { t.value = a.value.toUpperCase(); });
        t.addEventListener('input', function () { if (/^#[0-9a-f]{6}$/i.test(t.value)) a.value = t.value; });
      });
      var font = b.font || 'modern';
      body.querySelector('.mk-fontpick').addEventListener('click', function (e) { var x = e.target.closest('[data-font]'); if (!x) return; font = x.dataset.font; this.querySelectorAll('button').forEach(function (y) { y.classList.toggle('on', y === x); }); });
      var foot = document.createElement('div'); foot.className = 'mk-sheet-f';
      foot.innerHTML = '<button type="button" class="mk-btn ghost mk-cancel">Cancel</button><button type="button" class="mk-btn mk-save">' + ic('check') + 'Save brand kit</button>';
      body.appendChild(foot);
      var sh = MK.sheet('Brand kit', body);
      foot.querySelector('.mk-cancel').addEventListener('click', sh.close);
      foot.querySelector('.mk-save').addEventListener('click', function () {
        var v = function (n) { return body.querySelector('[name=' + n + ']').value; };
        var kit = { action: 'brand', logo: logo, color1: v('color1'), color2: v('color2'), font: font, phone: v('phone'), tagline: v('tagline'), address: v('address'), hashtags: v('hashtags'), language: v('language') };
        var btn = this; btn.disabled = true;
        fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(kit) }).then(function (r) { return r.json(); }).then(function (d) {
          btn.disabled = false;
          if (!d.success) { notify(d.message || 'Could not save.', 'error'); return; }
          brand = d.brand; brandP = Promise.resolve(brand);
          loadLogo().then(function () { document.dispatchEvent(new CustomEvent('mk:brand', { detail: brand })); });
          notify('Brand kit saved', 'success'); sh.close();
        }).catch(function () { btn.disabled = false; notify('Could not save.', 'error'); });
      });
    });
  };

  /* ── tabs across the marketing pages ── */
  var TABS = [['marketingPostersPage', 'imagesmode', 'Posters'], ['marketingVideosPage', 'movie', 'Videos'], ['marketingCaptionsPage', 'chat', 'Captions'],
    ['marketingCalendarPage', 'calendar_month', 'Calendar'], ['marketingDesignsPage', 'folder', 'My campaigns']];
  function addTabs() {
    TABS.forEach(function (t) {
      var page = document.getElementById(t[0]);
      if (!page || page.querySelector('.mk-tabs')) return;
      var nav = document.createElement('nav');
      nav.className = 'mk-tabs';
      nav.setAttribute('aria-label', 'Marketing tools');
      nav.innerHTML = '<div class="mk-tabs-l">' + TABS.map(function (x) {
        return '<button type="button" data-mk-go="' + x[0] + '" class="' + (x[0] === t[0] ? 'on' : '') + '"' + (x[0] === t[0] ? ' aria-current="page"' : '') + '>' + ic(x[1]) + x[2] + '</button>';
      }).join('') + '</div><button type="button" class="mk-kit-btn" data-mk-kit>' + ic('palette') + 'Brand kit</button>';
      var content = page.querySelector('.page-content');
      if (content) content.insertBefore(nav, content.firstChild);
    });
    drawCampaignBars();
  }
  MK.go = function (page) {
    var link = document.querySelector('.nav-link[data-page="' + page + '"]');
    if (link) link.click(); else if (window.showPage) window.showPage(page);
  };
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-mk-go]');
    if (b) { MK.go(b.getAttribute('data-mk-go')); return; }
    if (e.target.closest('[data-mk-kit]')) { MK.openBrandKit(); return; }
    var c = e.target.closest('[data-mk-camp]');
    if (c) {
      var a = c.getAttribute('data-mk-camp');
      if (a === 'start') MK.startCampaignPrompt('');
      else if (a === 'end') MK.setCampaign(null);
      else if (a === 'copy' && camp) MK.copy(MK.link({ src: camp.src, coupon: camp.coupon && camp.coupon.code }), c);
    }
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addTabs); else addTabs();

  /* ── My campaigns: saved designs and what they brought in ── */
  var KIND = { poster: ['imagesmode', 'Poster', 'marketingPostersPage', 'openPosterDesign'], video: ['movie', 'Video', 'marketingVideosPage', 'openVideoDesign'], caption: ['chat', 'Caption', 'marketingCaptionsPage', 'openCaptionDesign'] };
  var designs = [], dCur = '₹', dFilter = 'all';
  function money(n) { return dCur + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 }); }
  function drawDesigns() {
    var root = document.getElementById('mkDesignsRoot');
    if (!root) return;
    var tot = { visits: 0, orders: 0, sales: 0 }, seen = {};
    designs.forEach(function (d) {
      var r = d.results || {}, k = (d.src || '') + '|' + (d.coupon_code || '');
      if (seen[k]) return; seen[k] = 1;   // designs of one campaign share their results
      tot.visits += r.visits || 0; tot.orders += r.orders || 0; tot.sales += r.sales || 0;
    });
    var list = designs.filter(function (d) { return dFilter === 'all' || d.kind === dFilter; });
    root.innerHTML =
      '<div class="mk-stats"><div><small>People who opened your links</small><b>' + tot.visits.toLocaleString('en-IN') + '</b></div><div><small>Orders from your marketing</small><b>' + tot.orders.toLocaleString('en-IN') + '</b></div><div><small>Sales from your marketing</small><b>' + money(tot.sales) + '</b></div></div>' +
      '<div class="mk-dhead"><div class="campaign-seg">' + [['all', 'All'], ['poster', 'Posters'], ['video', 'Videos'], ['caption', 'Captions']].map(function (f) { return '<button type="button" data-mk-df="' + f[0] + '"' + (dFilter === f[0] ? ' class="on"' : '') + '>' + f[1] + '</button>'; }).join('') + '</div>' +
      '<button type="button" class="mk-btn" data-mk-go="marketingPostersPage">' + ic('add') + 'New poster</button></div>' +
      (list.length ? '<div class="mk-dgrid">' + list.map(function (d) {
        var K = KIND[d.kind] || KIND.poster, r = d.results || {};
        return '<article class="mk-dcard"><div class="mk-dthumb">' + (d.thumb ? '<img alt="" src="' + esc(d.thumb) + '">' : ic(K[0])) + '<span class="mk-kind">' + ic(K[0]) + K[1] + '</span></div>' +
          '<div class="mk-dbody"><b>' + esc(d.name) + '</b><small>' + esc(new Date(String(d.updated_at).replace(' ', 'T')).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })) +
          (d.coupon_code ? ' · <span class="mk-code">' + esc(d.coupon_code) + '</span>' : '') + '</small>' +
          '<div class="mk-dres"><span><b>' + (r.visits || 0) + '</b>visits</span><span><b>' + (r.orders || 0) + '</b>orders</span><span><b>' + money(r.sales) + '</b>sales</span></div>' +
          '<div class="mk-dacts"><button type="button" class="mk-btn sm" data-mk-open="' + d.id + '">' + ic('edit') + 'Open</button><button type="button" class="mk-btn sm ghost" data-mk-dup="' + d.id + '">' + ic('content_copy') + 'Duplicate</button><button type="button" class="mk-btn sm ghost icon" data-mk-del="' + d.id + '" aria-label="Delete">' + ic('delete') + '</button></div></div></article>';
      }).join('') + '</div>'
        : '<div class="mk-empty">' + ic('folder') + '<b>' + (designs.length ? 'Nothing here yet' : 'No saved designs yet') + '</b><span>Make a poster, video or caption and press <b>Save</b>. It shows here with how many people opened it and the orders it brought in.</span></div>') +
      '<p class="mk-hint mk-foot">' + ic('info') + 'Visits count people who opened the link or scanned the QR code. Orders count website orders placed within 7 days of opening it, plus every order that used the attached coupon.</p>';
  }
  function getDesign(id) { return fetch(API + '?view=design&id=' + id, { cache: 'no-store' }).then(function (r) { return r.json(); }); }
  window.initMarketingDesigns = function () {
    var root = document.getElementById('mkDesignsRoot');
    if (root && !designs.length) root.innerHTML = '<div class="mk-empty">' + ic('hourglass_top') + '<span>Loading your designs…</span></div>';
    return fetch(API + '?view=designs', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.success) { if (root) root.innerHTML = '<div class="mk-empty">' + ic('error') + '<b>Could not load</b><span>' + esc(d.message || '') + '</span></div>'; return; }
      designs = d.designs || []; dCur = d.currency || '₹'; drawDesigns();
    }).catch(function () {});
  };
  MK.openDesign = function (id) {
    return getDesign(id).then(function (d) {
      if (!d.success) { notify(d.message || 'Could not open.', 'error'); return; }
      var K = KIND[d.design.kind] || KIND.poster;
      MK.go(K[2]);
      setTimeout(function () { if (window[K[3]]) window[K[3]](d.design); }, 120);
    });
  };
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-mk-df],[data-mk-open],[data-mk-dup],[data-mk-del]');
    if (!t || !t.closest('#marketingDesignsPage')) return;
    if (t.dataset.mkDf) { dFilter = t.dataset.mkDf; drawDesigns(); return; }
    if (t.dataset.mkOpen) { MK.openDesign(+t.dataset.mkOpen); return; }
    if (t.dataset.mkDup) {
      getDesign(+t.dataset.mkDup).then(function (d) {
        if (!d.success) return;
        var src = designs.filter(function (x) { return x.id === +t.dataset.mkDup; })[0] || {};
        MK.saveDesign({ kind: d.design.kind, name: d.design.name + ' (copy)', src: d.design.src, coupon_code: d.design.coupon_code, state: d.design.state, thumb: src.thumb || '' }).then(function (id) { if (id) window.initMarketingDesigns(); });
      });
      return;
    }
    if (t.dataset.mkDel) {
      window.rgConfirm('Delete this saved design? The coupon and its orders are not affected.', { title: 'Delete design', confirmText: 'Delete', danger: true }).then(function (ok) {
        if (!ok) return;
        fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id: +t.dataset.mkDel }) }).then(function (r) { return r.json(); }).then(function () {
          designs = designs.filter(function (x) { return x.id !== +t.dataset.mkDel; }); drawDesigns(); notify('Design deleted', 'success');
        });
      });
    }
  });

  window.MK = MK;
  MK.brand();
})();
