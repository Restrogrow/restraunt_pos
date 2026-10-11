/*
 * Website banners (dashboard: Website Appearance > Banner Images / Offer Banners).
 *
 * Every <div class="rg-banners" data-placement="…"> on the page becomes a
 * slideshow of the banners the restaurant set to show there:
 *   home_top  — Home page, under the search bar
 *   home_menu — Home page, above the dish list
 *   menu_top  — Menu page, above the dishes
 *   cart      — Cart page, at the top
 * Two kinds: photo banners (uploaded images) and offer banners (made here in
 * HTML/CSS from a template + headline/code/colour, animated). Offer banners
 * come first. Auto-advances every 4 s, swipeable, with dots.
 *
 * window.RGOfferBanner.html(banner, {imgBase}) is also used by the dashboard
 * to preview offer banners exactly as customers see them.
 */
(function () {
  'use strict';

  var CSS = '' +
    '.rg-banners{display:none;padding:10px 16px 4px}' +
    '.rg-banners.on{display:block}' +
    '.rg-bn-view{position:relative;border-radius:16px;overflow:hidden;box-shadow:0 6px 18px rgba(0,0,0,.08);background:#f3f4f6}' +
    '.rg-bn-track{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;scrollbar-width:none}' +
    '.rg-bn-track::-webkit-scrollbar{display:none}' +
    '.rg-bn-slide{flex:0 0 100%;scroll-snap-align:center;aspect-ratio:16/7;position:relative}' +
    '.rg-bn-slide>img{width:100%;height:100%;object-fit:cover;display:block}' +
    '.rg-bn-dots{position:absolute;left:0;right:0;bottom:8px;display:flex;justify-content:center;gap:5px;pointer-events:none;z-index:3}' +
    '.rg-bn-dots i{width:6px;height:6px;border-radius:999px;background:rgba(255,255,255,.6);box-shadow:0 0 0 1px rgba(0,0,0,.08);transition:width .3s,background .3s}' +
    '.rg-bn-dots i.on{width:18px;background:#fff}' +
    '.rg-bn-toast{position:fixed;left:50%;bottom:96px;transform:translate(-50%,20px);opacity:0;z-index:99999;padding:10px 16px;border-radius:999px;background:#111827;color:#fff;font:600 13px/1.2 Poppins,system-ui,sans-serif;transition:opacity .25s,transform .25s;pointer-events:none}' +
    '.rg-bn-toast.show{opacity:1;transform:translate(-50%,0)}' +

    /* ── Offer banner base ── */
    '.rg-ob{--c:#e11d48;--c2:color-mix(in srgb,var(--c) 62%,#000);--c3:color-mix(in srgb,var(--c) 14%,#fff);' +
    'position:relative;width:100%;height:100%;aspect-ratio:16/7;overflow:hidden;container-type:inline-size;isolation:isolate;' +
    'font-family:Poppins,system-ui,sans-serif;color:#fff;text-align:left;user-select:none}' +
    '.rg-ob *{box-sizing:border-box}' +
    '.rg-ob-copy{position:absolute;z-index:2;left:6cqw;top:50%;transform:translateY(-50%);width:56cqw;display:flex;flex-direction:column;align-items:flex-start;gap:1.6cqw}' +
    '.rg-ob-kick{font-size:2.9cqw;font-weight:700;letter-spacing:.18em;text-transform:uppercase;opacity:.9}' +
    '.rg-ob-title{display:block;font-size:10.5cqw;line-height:.95;font-weight:900;letter-spacing:-.02em;text-transform:uppercase}' +
    '.rg-ob-title.long{font-size:7cqw}' +
    '.rg-ob-title.xlong{font-size:5.2cqw;line-height:1.05}' +
    '.rg-ob-sub{font-size:3.6cqw;font-weight:500;line-height:1.25;opacity:.95;max-width:100%}' +
    '.rg-ob-row{display:flex;align-items:center;gap:2cqw;margin-top:.6cqw;flex-wrap:wrap}' +
    '.rg-ob-code{display:inline-flex;align-items:center;gap:1.2cqw;padding:1.1cqw 2.4cqw;border:.35cqw dashed currentColor;border-radius:1.6cqw;font-size:3.1cqw;font-weight:800;letter-spacing:.08em;cursor:pointer;background:rgba(255,255,255,.12)}' +
    '.rg-ob-code small{font-size:2.3cqw;font-weight:600;letter-spacing:.04em;opacity:.8}' +
    '.rg-ob-cta{display:inline-flex;align-items:center;gap:1cqw;padding:1.5cqw 3.4cqw;border-radius:999px;border:0;background:#fff;color:var(--c2);font:800 3.2cqw/1 Poppins,system-ui,sans-serif;cursor:pointer;box-shadow:0 1cqw 2.6cqw rgba(0,0,0,.18)}' +
    '.rg-ob-cta::after{content:"";width:1.4cqw;height:1.4cqw;border-top:.45cqw solid currentColor;border-right:.45cqw solid currentColor;transform:rotate(45deg)}' +
    '.rg-ob-dish{position:absolute;z-index:1;object-fit:cover}' +

    /* Burst: rotating rays + dish spinning in */
    '.rg-ob-burst{background:radial-gradient(circle at 78% 50%,var(--c) 0,var(--c2) 75%)}' +
    '.rg-ob-burst::before{content:"";position:absolute;left:78%;top:50%;width:160cqw;height:160cqw;margin:-80cqw 0 0 -80cqw;z-index:0;' +
    'background:repeating-conic-gradient(rgba(255,255,255,.10) 0 9deg,transparent 9deg 18deg);animation:rgObSpin 28s linear infinite}' +
    '.rg-ob-burst .rg-ob-dish{right:5cqw;top:50%;width:34cqw;height:34cqw;margin-top:-17cqw;border-radius:50%;border:1.2cqw solid rgba(255,255,255,.9);box-shadow:0 2cqw 5cqw rgba(0,0,0,.35);animation:rgObFloat 4s ease-in-out infinite}' +
    '.is-on .rg-ob-burst .rg-ob-dish,.rg-ob-burst.rg-ob-live .rg-ob-dish{animation:rgObSpinIn .9s cubic-bezier(.2,1.2,.4,1) both,rgObFloat 4s ease-in-out .9s infinite}' +
    '.rg-ob-burst .rg-ob-title{animation:rgObPulse 2.4s ease-in-out infinite;transform-origin:left center}' +
    '.rg-ob-burst .rg-ob-cta{color:var(--c2)}' +

    /* Ticket: coupon ticket with notches + shine sweep */
    '.rg-ob-ticket{background:var(--c3);color:#111827}' +
    '.rg-ob-ticket .rg-ob-card{position:absolute;inset:6cqw 5cqw;border-radius:3cqw;background:#fff;box-shadow:0 1.4cqw 4cqw rgba(0,0,0,.10);overflow:hidden;display:flex}' +
    '.rg-ob-ticket .rg-ob-card::before,.rg-ob-ticket .rg-ob-card::after{content:"";position:absolute;top:50%;width:5cqw;height:5cqw;margin-top:-2.5cqw;border-radius:50%;background:var(--c3);z-index:3}' +
    '.rg-ob-ticket .rg-ob-card::before{left:-2.5cqw}.rg-ob-ticket .rg-ob-card::after{right:-2.5cqw}' +
    '.rg-ob-ticket .rg-ob-copy{position:relative;left:auto;top:auto;transform:none;flex:1;width:auto;padding:0 3cqw 0 6cqw;justify-content:center}' +
    '.rg-ob-ticket .rg-ob-title{color:var(--c)}' +
    '.rg-ob-ticket .rg-ob-kick{color:var(--c2)}' +
    '.rg-ob-ticket .rg-ob-sub{color:#4b5563}' +
    '.rg-ob-ticket .rg-ob-cta{background:var(--c);color:#fff}' +
    '.rg-ob-ticket .rg-ob-stub{position:relative;width:30cqw;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1cqw;border-left:.5cqw dashed #e5e7eb;background:linear-gradient(160deg,var(--c),var(--c2));color:#fff;text-align:center;overflow:hidden}' +
    '.rg-ob-ticket .rg-ob-stub img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.28;mix-blend-mode:luminosity}' +
    '.rg-ob-ticket .rg-ob-stub small{position:relative;font-size:2.1cqw;font-weight:700;letter-spacing:.14em;text-transform:uppercase;opacity:.9}' +
    '.rg-ob-ticket .rg-ob-stub b{position:relative;font-size:3.6cqw;font-weight:900;letter-spacing:.06em;word-break:break-all;padding:0 1.5cqw}' +
    '.rg-ob-ticket .rg-ob-card i.rg-ob-shine{position:absolute;top:0;bottom:0;left:-40%;width:25%;z-index:4;background:linear-gradient(100deg,transparent,rgba(255,255,255,.65),transparent);transform:skewX(-18deg);animation:rgObShine 3.2s ease-in-out infinite}' +

    /* Neon: dark board, glowing flickering headline */
    '.rg-ob-neon{background:radial-gradient(ellipse at 30% 40%,#1f1633 0,#0a0a12 70%)}' +
    '.rg-ob-neon::before{content:"";position:absolute;inset:3cqw;border-radius:3cqw;border:.4cqw solid var(--c);box-shadow:0 0 2cqw var(--c),inset 0 0 2cqw var(--c);opacity:.85;z-index:0;animation:rgObGlow 2.8s ease-in-out infinite}' +
    '.rg-ob-neon .rg-ob-title{color:#fff;text-shadow:0 0 .6cqw #fff,0 0 2cqw var(--c),0 0 5cqw var(--c);animation:rgObFlicker 4s linear infinite}' +
    '.rg-ob-neon .rg-ob-kick{color:var(--c);text-shadow:0 0 1.5cqw var(--c)}' +
    '.rg-ob-neon .rg-ob-sub{color:#d1d5db}' +
    '.rg-ob-neon .rg-ob-code{color:#fff;border-color:var(--c);box-shadow:0 0 1.5cqw var(--c);background:transparent}' +
    '.rg-ob-neon .rg-ob-cta{background:var(--c);color:#fff;box-shadow:0 0 2.4cqw var(--c)}' +
    '.rg-ob-neon .rg-ob-dish{right:7cqw;top:50%;width:28cqw;height:28cqw;margin-top:-14cqw;border-radius:50%;box-shadow:0 0 0 .5cqw var(--c),0 0 4cqw var(--c);animation:rgObFloat 5s ease-in-out infinite}' +

    /* Confetti: falling colour squares, headline pops */
    '.rg-ob-confetti{background:linear-gradient(135deg,var(--c),var(--c2))}' +
    '.rg-ob-confetti .rg-ob-fx i{position:absolute;top:-6cqw;width:2.2cqw;height:2.2cqw;border-radius:.4cqw;z-index:1;animation:rgObFall linear infinite;opacity:.9}' +
    '.rg-ob-confetti .rg-ob-title{animation:rgObPop .7s cubic-bezier(.2,1.6,.4,1) both}' +
    '.rg-ob-confetti .rg-ob-dish{right:5cqw;top:50%;width:32cqw;height:32cqw;margin-top:-16cqw;border-radius:4cqw;transform:rotate(6deg);border:1cqw solid #fff;box-shadow:0 2cqw 5cqw rgba(0,0,0,.3);animation:rgObSway 5s ease-in-out infinite}' +

    /* Marquee: scrolling top strip + zooming dish */
    '.rg-ob-marquee{background:#fff;color:#111827}' +
    '.rg-ob-marquee .rg-ob-strip{position:absolute;top:0;left:0;right:0;height:8cqw;z-index:3;background:var(--c);color:#fff;display:flex;align-items:center;overflow:hidden;white-space:nowrap;font-size:2.8cqw;font-weight:800;letter-spacing:.12em}' +
    '.rg-ob-marquee .rg-ob-strip span{display:inline-block;padding-left:100%;animation:rgObTicker 12s linear -5s infinite}' +
    '.rg-ob-marquee .rg-ob-copy{top:calc(50% + 4cqw)}' +
    '.rg-ob-marquee .rg-ob-title{color:var(--c)}' +
    '.rg-ob-marquee .rg-ob-kick{color:var(--c2)}' +
    '.rg-ob-marquee .rg-ob-sub{color:#4b5563}' +
    '.rg-ob-marquee .rg-ob-cta{background:var(--c);color:#fff}' +
    '.rg-ob-marquee .rg-ob-code{color:var(--c2)}' +
    '.rg-ob-marquee .rg-ob-dish{right:0;top:8cqw;bottom:0;width:38cqw;height:auto;-webkit-mask:linear-gradient(90deg,transparent,#000 35%);mask:linear-gradient(90deg,transparent,#000 35%);animation:rgObZoom 9s ease-in-out infinite alternate}' +
    '.rg-ob-marquee .rg-ob-nodish{position:absolute;right:-8cqw;top:8cqw;bottom:-10cqw;width:44cqw;border-radius:50%;background:var(--c3)}' +

    /* Split: diagonal colour panel slides over the dish */
    '.rg-ob-split{background:var(--c3)}' +
    '.rg-ob-split .rg-ob-dish{inset:0 0 0 auto;width:62%;height:100%;animation:rgObZoom 10s ease-in-out infinite alternate}' +
    '.rg-ob-split .rg-ob-panel{position:absolute;inset:0;z-index:1;background:linear-gradient(135deg,var(--c),var(--c2));clip-path:polygon(0 0,66% 0,52% 100%,0 100%)}' +
    '.is-on .rg-ob-split .rg-ob-panel,.rg-ob-split.rg-ob-live .rg-ob-panel{animation:rgObWipe .8s cubic-bezier(.2,.9,.3,1) both}' +
    '.is-on .rg-ob-split .rg-ob-copy>*,.rg-ob-split.rg-ob-live .rg-ob-copy>*{animation:rgObSlideIn .6s ease both}' +
    '.rg-ob-split .rg-ob-copy>*:nth-child(2){animation-delay:.12s!important}.rg-ob-split .rg-ob-copy>*:nth-child(3){animation-delay:.24s!important}.rg-ob-split .rg-ob-copy>*:nth-child(4){animation-delay:.36s!important}' +
    '.rg-ob-split .rg-ob-copy{width:46cqw}' +

    '@keyframes rgObSpin{to{transform:rotate(360deg)}}' +
    '@keyframes rgObFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-1.6cqw)}}' +
    '@keyframes rgObSpinIn{from{transform:rotate(-120deg) scale(.4);opacity:0}to{transform:none;opacity:1}}' +
    '@keyframes rgObPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.05)}}' +
    '@keyframes rgObShine{0%,55%{left:-40%}100%{left:130%}}' +
    '@keyframes rgObGlow{0%,100%{opacity:.85}50%{opacity:.45}}' +
    '@keyframes rgObFlicker{0%,18%,22%,62%,64%,100%{opacity:1}20%,63%{opacity:.35}}' +
    '@keyframes rgObFall{0%{transform:translateY(0) rotate(0)}100%{transform:translateY(60cqw) rotate(540deg)}}' +
    '@keyframes rgObPop{from{transform:scale(.3);opacity:0}to{transform:none;opacity:1}}' +
    '@keyframes rgObSway{0%,100%{transform:rotate(6deg)}50%{transform:rotate(-2deg) translateY(-1cqw)}}' +
    '@keyframes rgObTicker{to{transform:translateX(-100%)}}' +
    '@keyframes rgObZoom{from{transform:scale(1)}to{transform:scale(1.12)}}' +
    '@keyframes rgObWipe{from{clip-path:polygon(0 0,0 0,0 100%,0 100%)}}' +
    '@keyframes rgObSlideIn{from{transform:translateX(-6cqw);opacity:0}to{transform:none;opacity:1}}' +
    '@media (prefers-reduced-motion:reduce){.rg-ob *,.rg-ob::before{animation:none!important}}';

  function ensureCss() {
    if (document.getElementById('rgBannersCss')) return;
    var st = document.createElement('style');
    st.id = 'rgBannersCss';
    st.textContent = CSS;
    document.head.appendChild(st);
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }

  var CONFETTI = ['#ffffff', '#fde047', '#22d3ee', '#a3e635', '#f472b6', '#fb923c'];

  /* One offer banner's HTML. b: {template,title,subtitle,code,cta,color,image}; opts.imgBase for the dish photo URL. */
  function offerHtml(b, opts) {
    opts = opts || {};
    var tpl = ['burst', 'ticket', 'neon', 'confetti', 'marquee', 'split'].indexOf(b.template) > -1 ? b.template : 'burst';
    var color = /^#[0-9a-fA-F]{6}$/.test(b.color || '') ? b.color : '#e11d48';
    var title = String(b.title || '');
    var tCls = title.length > 22 ? ' xlong' : (title.length > 11 ? ' long' : '');
    var img = '';
    if (b.image) {
      var ref = String(b.image);
      img = /^(https?:|data:)/.test(ref) ? ref : (opts.imgBase || 'image.php?id=') + encodeURIComponent(ref.replace(/^db:/, ''));
    }
    var dish = img ? '<img class="rg-ob-dish" src="' + esc(img) + '" alt="" loading="lazy">' : '';
    var kick = tpl === 'marquee' ? '' : '<span class="rg-ob-kick">' + (b.code ? 'Limited time' : 'Today only') + '</span>';
    var code = b.code ? '<span class="rg-ob-code" data-rg-code="' + esc(b.code) + '" title="Tap to copy"><small>CODE</small>' + esc(b.code) + '</span>' : '';
    var cta = b.cta ? '<span class="rg-ob-cta" role="button" tabindex="0" data-rg-cta>' + esc(b.cta) + '</span>' : '';
    var copy = '<div class="rg-ob-copy">' + kick + '<b class="rg-ob-title' + tCls + '">' + esc(title) + '</b>' +
      (b.subtitle ? '<span class="rg-ob-sub">' + esc(b.subtitle) + '</span>' : '') +
      (code || cta ? '<div class="rg-ob-row">' + (tpl === 'ticket' ? '' : code) + cta + '</div>' : '') + '</div>';
    var inner = '';
    if (tpl === 'ticket') {
      inner = '<div class="rg-ob-card">' + copy +
        '<div class="rg-ob-stub">' + (img ? '<img src="' + esc(img) + '" alt="">' : '') +
        (b.code ? '<small>Use code</small><b data-rg-code="' + esc(b.code) + '" style="cursor:pointer">' + esc(b.code) + '</b>' : '<small>Offer</small><b>Today</b>') +
        '</div><i class="rg-ob-shine"></i></div>';
    } else if (tpl === 'confetti') {
      var fx = '';
      for (var i = 0; i < 16; i++) {
        fx += '<i style="left:' + ((i * 37) % 100) + '%;background:' + CONFETTI[i % CONFETTI.length] + ';animation-duration:' + (2.6 + (i % 5) * 0.5) + 's;animation-delay:-' + ((i * 0.43) % 3).toFixed(2) + 's"></i>';
      }
      inner = '<div class="rg-ob-fx">' + fx + '</div>' + dish + copy;
    } else if (tpl === 'marquee') {
      var line = ('LIMITED TIME OFFER  ·  ' + (b.code ? 'USE CODE ' + b.code + '  ·  ' : '') + title.toUpperCase() + '  ·  ');
      inner = '<div class="rg-ob-strip"><span>' + esc(line + line + line) + '</span></div>' + (dish || '<div class="rg-ob-nodish"></div>') + copy;
    } else if (tpl === 'split') {
      inner = dish + '<div class="rg-ob-panel"></div>' + copy;
    } else {
      inner = dish + copy;
    }
    return '<div class="rg-ob rg-ob-' + tpl + (opts.live ? ' rg-ob-live' : '') + '" style="--c:' + color + '">' + inner + '</div>';
  }

  function toast(msg) {
    var t = document.createElement('div');
    t.className = 'rg-bn-toast';
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('show'); });
    setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, 1800);
  }

  function menuUrl() {
    if (window.RG_MENU_URL) return window.RG_MENU_URL; // set by the page (works with custom domains)
    return location.pathname.replace(/\/(menu|cart|profile|social|plans|reservations)\/?$/, '').replace(/\/$/, '') + '/menu';
  }

  window.RGOfferBanner = { html: offerHtml, ensureCss: ensureCss, templates: ['burst', 'ticket', 'neon', 'confetti', 'marquee', 'split'] };

  var mounts = document.querySelectorAll('.rg-banners[data-placement]');
  if (!mounts.length) return;
  ensureCss();

  var meta = document.querySelector('meta[name=restaurant-id]');
  var rid = (meta && meta.content) || window.websiteRestaurantId || '';
  if (!rid) return;

  function photoSrc(b) {
    var p = String(b.banner_path || '');
    if (/^https?:\/\//.test(p)) return p;
    if (p.indexOf('db:') === 0) return 'image.php?type=banner&id=' + encodeURIComponent(b.id) + '&p=' + encodeURIComponent(p.slice(3)); // new upload = new path, so no stale cache
    return '../' + p;
  }

  // Code tap: copy it. CTA tap: go to the menu.
  document.addEventListener('click', function (e) {
    var codeEl = e.target.closest && e.target.closest('.rg-banners [data-rg-code]');
    if (codeEl) {
      var code = codeEl.getAttribute('data-rg-code');
      var done = function () { toast('Code ' + code + ' copied'); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(code).then(done, done); else done();
      return;
    }
    if (e.target.closest && e.target.closest('.rg-banners [data-rg-cta]')) location.href = menuUrl();
  });

  document.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.closest && e.target.closest('.rg-banners [data-rg-cta]')) { e.preventDefault(); location.href = menuUrl(); }
  });

  function build(mount, slides) {
    mount.innerHTML = '<div class="rg-bn-view"><div class="rg-bn-track">' +
      slides.map(function (s, i) { return '<div class="rg-bn-slide' + (i ? '' : ' is-on') + '">' + s + '</div>'; }).join('') +
      '</div>' + (slides.length > 1 ? '<div class="rg-bn-dots">' + slides.map(function (s, i) { return '<i' + (i ? '' : ' class="on"') + '></i>'; }).join('') + '</div>' : '') + '</div>';
    mount.classList.add('on');
    if (slides.length < 2) return;
    var track = mount.querySelector('.rg-bn-track'), dots = mount.querySelectorAll('.rg-bn-dots i'), els = mount.querySelectorAll('.rg-bn-slide');
    var idx = 0, paused = false;
    function at() { return Math.round(track.scrollLeft / Math.max(1, track.clientWidth)); }
    function mark(n) {
      dots.forEach(function (d, k) { d.classList.toggle('on', k === n); });
      els.forEach(function (el, k) { el.classList.toggle('is-on', k === n); }); // replays entrance animations
    }
    track.addEventListener('scroll', function () { var n = at(); if (n !== idx) { idx = n; mark(idx); } }, { passive: true });
    track.addEventListener('touchstart', function () { paused = true; }, { passive: true });
    track.addEventListener('touchend', function () { setTimeout(function () { paused = false; }, 2500); }, { passive: true });
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!reduce) setInterval(function () {
      if (paused || document.hidden) return;
      track.scrollTo({ left: ((at() + 1) % slides.length) * track.clientWidth, behavior: 'smooth' });
    }, 4000);
  }

  fetch('theme_api.php?action=get_banners&restaurant_id=' + encodeURIComponent(rid))
    .then(function (r) { return r.json(); })
    .then(function (d) {
      var photos = (d && d.success && d.banners) || [];
      var offers = (d && d.success && d.offer_banners) || [];
      mounts.forEach(function (m) {
        var place = m.getAttribute('data-placement');
        var slides = offers.filter(function (b) { return (b.placement || 'home_top') === place; }).map(function (b) { return offerHtml(b); })
          .concat(photos.filter(function (b) { return (b.placement || 'home_top') === place; }).map(function (b, i) {
            return '<img src="' + photoSrc(b) + '" alt="" loading="' + (i ? 'lazy' : 'eager') + '">';
          }));
        if (slides.length) build(m, slides);
      });
    })
    .catch(function () {});
})();
