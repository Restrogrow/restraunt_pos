/*
 * First day in the dashboard: the "Get your restaurant ready" guide on the home page and the product tour.
 * Data and saved progress: main/api/onboarding.php (config/onboarding.php). Owner / branch admin only.
 *
 * - Guide: steps tick themselves off from the restaurant's own data (dishes, tables, logo + hours, staff,
 *   first order); "Share your website" is ticked when they copy or share the link. Hidden for good with ×,
 *   gone by itself when everything is done or after the first 45 days.
 * - Tour: animated cards with a small real screenshot of each section, the menu item lit up beside it.
 *   Starts by itself once for a new restaurant (or after sign-up, ?welcome=1); Skip / finish is saved on the
 *   server, so it never asks again on any device. window.rgStartTour() replays it (Help page, guide card).
 * Sections Super Admin switched off (window.RG_ACCESS_OFF) are left out of both.
 */
(function () {
  'use strict';
  var API = '../api/onboarding.php', IMG = '../assets/images/tour/';
  var D = null, tourOn = false;

  function off(page) { return !!page && Array.isArray(window.RG_ACCESS_OFF) && window.RG_ACCESS_OFF.indexOf(page) > -1; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function ic(n) { return '<span class="material-symbols-rounded" aria-hidden="true">' + n + '</span>'; }
  function post(body) {
    return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(body) })
      .then(function (r) { return r.json(); }).catch(function () { return { success: false, message: 'No connection. Please try again.' }; });
  }
  function note(msg, type) { if (window.showNotification) window.showNotification(msg, type || 'success'); }
  function go(page) { if (page && typeof window.showPage === 'function') { window.showPage(page); window.scrollTo(0, 0); } }
  function websiteUrl() {
    var a = document.querySelector('#dashboardPage .dh-head-r a[target="_blank"]');
    return a ? a.href : location.origin;
  }
  function restaurantName() { var h = document.getElementById('dhName'); return h ? h.textContent.trim() : 'your restaurant'; }

  /* ───────── styles ───────── */
  var CSS = [
    '#obGuide{margin:0 0 18px}',
    '.obg{position:relative;overflow:hidden;border-radius:20px;background:linear-gradient(135deg,#fff7ed,#ffffff 55%);border:1px solid #fed7aa;box-shadow:0 10px 30px -18px rgba(194,65,12,.45);padding:20px 22px;animation:obUp .45s cubic-bezier(.2,.9,.3,1.1) both}',
    '.obg::after{content:"";position:absolute;right:-60px;top:-60px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle,rgba(251,146,60,.18),transparent 70%);pointer-events:none}',
    '.obg-top{display:flex;align-items:center;gap:16px;flex-wrap:wrap}',
    '.obg-ring{position:relative;width:62px;height:62px;flex:none}',
    '.obg-ring svg{width:62px;height:62px;transform:rotate(-90deg)}',
    '.obg-ring circle{fill:none;stroke-width:6}',
    '.obg-ring .bg{stroke:#ffedd5}.obg-ring .fg{stroke:#ea580c;stroke-linecap:round;transition:stroke-dashoffset .8s cubic-bezier(.2,.9,.3,1)}',
    '.obg-ring b{position:absolute;inset:0;display:grid;place-items:center;font-size:14px;font-weight:800;color:#9a3412}',
    '.obg-tt{flex:1;min-width:200px}',
    '.obg-tt h2{margin:0;font-size:19px;font-weight:800;color:#1f2937}',
    '.obg-tt p{margin:4px 0 0;color:#6b7280;font-size:13.5px}',
    '.obg-acts{display:flex;gap:8px;align-items:center}',
    '.obg-btn{display:inline-flex;align-items:center;gap:6px;height:38px;padding:0 14px;border-radius:11px;border:1.5px solid #fed7aa;background:#fff;color:#9a3412;font:inherit;font-size:13.5px;font-weight:700;cursor:pointer;transition:background .15s,transform .1s;white-space:nowrap}',
    '.obg-btn:hover{background:#fff7ed}.obg-btn:active{transform:scale(.97)}',
    '.obg-btn.pri{background:linear-gradient(135deg,#fb923c,#ea580c);border-color:transparent;color:#fff;box-shadow:0 8px 16px -10px rgba(234,88,12,.9)}',
    '.obg-btn.pri:hover{filter:brightness(1.05)}',
    '.obg-btn .material-symbols-rounded{font-size:18px}',
    '.obg-x{width:34px;height:34px;border:0;border-radius:10px;background:none;color:#9ca3af;cursor:pointer;display:grid;place-items:center}',
    '.obg-x:hover{background:#fff1e6;color:#9a3412}',
    '.obg-list{list-style:none;margin:16px 0 0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,330px),1fr));gap:10px}',
    '.obg-s{display:flex;flex-wrap:wrap;align-items:center;gap:10px 12px;padding:12px 14px;border-radius:14px;background:#fff;border:1px solid #f1e4d6;transition:transform .15s,box-shadow .15s,opacity .3s;animation:obUp .4s cubic-bezier(.2,.9,.3,1.1) both}',
    '.obg-s:hover{transform:translateY(-1px);box-shadow:0 8px 18px -14px rgba(0,0,0,.4)}',
    '.obg-s .obg-i{width:38px;height:38px;flex:none;border-radius:12px;display:grid;place-items:center;background:#fff7ed;color:#ea580c}',
    '.obg-s .obg-i .material-symbols-rounded{font-size:20px}',
    '.obg-s.done .obg-i{background:#dcfce7;color:#16a34a}',
    '.obg-s.done{opacity:.75}',
    '.obg-s b{display:block;font-size:14px;color:#1f2937}',
    '.obg-s.done b{text-decoration:line-through;text-decoration-color:#86efac}',
    '.obg-s small{display:block;color:#6b7280;font-size:12.5px;margin-top:2px}',
    '.obg-s .obg-m{flex:1;min-width:0}',
    '.obg-s .obg-r{display:flex;gap:6px;flex-wrap:wrap;margin-left:50px;width:calc(100% - 50px)}',
    '.obg-s.done .obg-r{display:none}',
    '.obg-s .obg-r .obg-btn{height:32px;padding:0 10px;font-size:12.5px}',
    '.obg-opt{font-size:11px;font-weight:700;color:#9ca3af;margin-left:6px}',
    '@keyframes obUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}',

    /* tour */
    '.obt-ov{position:fixed;inset:0;z-index:2147482000;background:rgba(15,23,42,.55);backdrop-filter:blur(2px);display:flex;align-items:center;justify-content:center;padding:16px;animation:obFade .25s both}',
    '@keyframes obFade{from{opacity:0}to{opacity:1}}',
    '.obt{position:relative;width:min(560px,100%);max-height:calc(100vh - 32px);overflow:auto;border-radius:24px;background:#fff;box-shadow:0 40px 80px -30px rgba(0,0,0,.6);animation:obPop .4s cubic-bezier(.2,.9,.3,1.15) both}',
    '@keyframes obPop{from{opacity:0;transform:translateY(20px) scale(.96)}to{opacity:1;transform:none}}',
    '.obt-bar{position:absolute;left:0;top:0;height:4px;background:linear-gradient(90deg,#fb923c,#ea580c);transition:width .4s ease;border-radius:0 4px 4px 0;z-index:2}',
    '.obt-shot{position:relative;height:250px;background:linear-gradient(160deg,#fff7ed,#eef2ff);overflow:hidden;display:flex;align-items:flex-end;justify-content:center}',
    '.obt-shot img{width:88%;border-radius:14px 14px 0 0;box-shadow:0 -10px 40px -10px rgba(15,23,42,.35);border:1px solid rgba(15,23,42,.08);border-bottom:0;object-fit:cover;object-position:top;height:225px;animation:obShot .55s cubic-bezier(.2,.9,.3,1.1) both}',
    '.obt-shot img.phone{width:auto;height:235px;border-radius:22px 22px 0 0;border:6px solid #111827;border-bottom:0}',
    '@keyframes obShot{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:none}}',
    '.obt-hero{display:grid;place-items:center;width:100%;height:100%;align-self:stretch}',
    '.obt-hero .material-symbols-rounded{font-size:84px;color:#ea580c;animation:obFloat 2.4s ease-in-out infinite}',
    '@keyframes obFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}',
    '.obt-tag{position:absolute;z-index:2;left:16px;top:16px;display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:999px;background:#fff;color:#9a3412;font-size:12px;font-weight:800;box-shadow:0 4px 12px -6px rgba(0,0,0,.3)}',
    '.obt-tag .material-symbols-rounded{font-size:16px}',
    '.obt-skip{position:absolute;right:12px;top:12px;z-index:3;border:0;background:rgba(255,255,255,.9);color:#6b7280;font:inherit;font-size:13px;font-weight:700;padding:7px 12px;border-radius:999px;cursor:pointer}',
    '.obt-skip:hover{color:#111827}',
    '.obt-body{padding:22px 24px 20px}',
    '.obt-body h3{margin:0;font-size:21px;font-weight:800;color:#111827;animation:obUp .35s both}',
    '.obt-body p{margin:8px 0 0;color:#4b5563;font-size:14.5px;line-height:1.6;animation:obUp .35s .05s both}',
    '.obt-body ul{margin:10px 0 0;padding:0;list-style:none;display:grid;gap:6px;animation:obUp .35s .1s both}',
    '.obt-body li{display:flex;gap:8px;font-size:13.5px;color:#374151}',
    '.obt-body li::before{content:"✓";color:#16a34a;font-weight:800}',
    '.obt-foot{display:flex;align-items:center;gap:10px;margin-top:20px}',
    '.obt-dots{display:flex;gap:5px;flex:1}',
    '.obt-dots i{width:7px;height:7px;border-radius:999px;background:#e5e7eb;transition:all .3s}',
    '.obt-dots i.on{width:22px;background:#ea580c}',
    '.obt-dots i.done{background:#fdba74}',
    '.obt-back{border:0;background:none;color:#6b7280;font:inherit;font-size:14px;font-weight:700;cursor:pointer;padding:10px 8px}',
    '.obt-back:disabled{opacity:0;pointer-events:none}',
    '.obt-next{display:inline-flex;align-items:center;gap:6px;height:44px;padding:0 20px;border:0;border-radius:12px;background:linear-gradient(135deg,#fb923c,#ea580c);color:#fff;font:inherit;font-size:14.5px;font-weight:800;cursor:pointer;box-shadow:0 10px 20px -12px rgba(234,88,12,.9)}',
    '.obt-next:active{transform:scale(.97)}',
    '.obt-count{font-size:12px;color:#9ca3af;font-weight:700}',
    '.ob-spot{position:relative;z-index:2147482001!important;border-radius:12px;box-shadow:0 0 0 3px #fb923c,0 0 0 9px rgba(251,146,60,.28)!important;background:rgba(255,255,255,.08)!important;animation:obSpot 1.4s ease-in-out infinite}',
    '@keyframes obSpot{0%,100%{box-shadow:0 0 0 3px #fb923c,0 0 0 6px rgba(251,146,60,.3)}50%{box-shadow:0 0 0 3px #fb923c,0 0 0 13px rgba(251,146,60,0)}}',
    '.obt-confetti{position:absolute;inset:0;pointer-events:none;overflow:hidden}',
    '.obt-confetti i{position:absolute;top:-12px;width:8px;height:12px;border-radius:2px;animation:obFall 1.8s ease-in forwards}',
    '@keyframes obFall{to{transform:translateY(300px) rotate(540deg);opacity:0}}',
    '@media (max-width:640px){.obt-ov{align-items:flex-end;padding:0}.obt{border-radius:24px 24px 0 0;max-height:92vh}.obt-shot{height:200px}.obt-shot img{height:180px;width:94%}.obt-shot img.phone{height:190px}.obg{padding:16px}.obt-count{display:none}.obg-s .obg-r{margin-left:0;width:100%}.obg-acts .obg-btn span.t{display:none}}',
    '@media (prefers-reduced-motion:reduce){.obg,.obg-s,.obt,.obt-shot img,.obt-hero .material-symbols-rounded,.ob-spot{animation:none!important}}'
  ].join('\n');
  function ensureCss() {
    if (document.getElementById('obCss')) return;
    var s = document.createElement('style'); s.id = 'obCss'; s.textContent = CSS; document.head.appendChild(s);
  }

  /* ───────── the guide ───────── */
  function stepButtons(st) {
    if (st.done) return '<span class="material-symbols-rounded" style="color:#16a34a">check</span>';
    if (st.key === 'menu') return '<button type="button" class="obg-btn pri" data-ob-go="menuItemsPage">Add dishes</button><button type="button" class="obg-btn" data-ob-photo>' + ic('photo_camera') + 'Send a photo</button>';
    if (st.key === 'tables') return '<button type="button" class="obg-btn pri" data-ob-tables>Make T1–T10</button><button type="button" class="obg-btn" data-ob-go="tablesPage">Myself</button>';
    if (st.key === 'share') return '<button type="button" class="obg-btn pri" data-ob-share="wa">' + ic('chat') + 'WhatsApp</button><button type="button" class="obg-btn" data-ob-share="copy">' + ic('content_copy') + 'Copy link</button>';
    return '<button type="button" class="obg-btn pri" data-ob-go="' + esc(st.page) + '">Start' + ic('arrow_forward') + '</button>';
  }
  function drawGuide() {
    var page = document.getElementById('dashboardPage');
    if (!page) return;
    var box = document.getElementById('obGuide');
    if (!D || !D.show_guide) { if (box) box.remove(); return; }
    var steps = D.steps.filter(function (s) { return !off(s.page); });
    var done = steps.filter(function (s) { return s.done; }).length, total = steps.length;
    if (!box) {
      box = document.createElement('section');
      box.id = 'obGuide';
      box.setAttribute('aria-label', 'Get your restaurant ready');
      var head = page.querySelector('.dh-head');
      if (head && head.nextSibling) page.insertBefore(box, head.nextSibling); else page.insertBefore(box, page.firstChild);
    }
    var C = 2 * Math.PI * 25, pct = total ? done / total : 0;
    box.innerHTML =
      '<div class="obg">' +
        '<div class="obg-top">' +
          '<div class="obg-ring" title="' + done + ' of ' + total + ' done"><svg viewBox="0 0 62 62"><circle class="bg" cx="31" cy="31" r="25"/><circle class="fg" cx="31" cy="31" r="25" stroke-dasharray="' + C.toFixed(1) + '" stroke-dashoffset="' + C.toFixed(1) + '"/></svg><b>' + done + '/' + total + '</b></div>' +
          '<div class="obg-tt"><h2>Get your restaurant ready</h2><p>' + (done === 0 ? 'About 10 minutes. Each step ticks itself off when it’s done.' : done + ' of ' + total + ' done. Keep going, you’re close!') + '</p></div>' +
          '<div class="obg-acts"><button type="button" class="obg-btn" data-ob-tour>' + ic('rocket_launch') + '<span class="t">Take the tour</span></button>' +
          '<button type="button" class="obg-x" data-ob-hide title="Hide this (it stays in Help)" aria-label="Hide the guide">' + ic('close') + '</button></div>' +
        '</div>' +
        '<ul class="obg-list">' + steps.map(function (st, i) {
          return '<li class="obg-s' + (st.done ? ' done' : '') + '" style="animation-delay:' + (i * 60) + 'ms"><span class="obg-i">' + ic(st.done ? 'check' : st.icon) + '</span>' +
            '<span class="obg-m"><b>' + esc(st.title) + (st.optional && !st.done ? '<span class="obg-opt">OPTIONAL</span>' : '') + '</b><small>' + esc(st.sub) + '</small></span>' +
            '<span class="obg-r">' + stepButtons(st) + '</span></li>';
        }).join('') + '</ul>' +
      '</div>';
    // the ring fills after it is drawn, so it animates
    requestAnimationFrame(function () { requestAnimationFrame(function () { var fg = box.querySelector('.fg'); if (fg) fg.style.strokeDashoffset = (C * (1 - pct)).toFixed(1); }); });
  }

  function load() {
    return fetch(API, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d || !d.success) return null;
      D = d; drawGuide(); return d;
    }).catch(function () { return null; });
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-ob-go],[data-ob-tour],[data-ob-hide],[data-ob-tables],[data-ob-share],[data-ob-photo]');
    if (!t || !t.closest('#obGuide')) return;
    if (t.hasAttribute('data-ob-go')) { go(t.getAttribute('data-ob-go')); return; }
    if (t.hasAttribute('data-ob-tour')) { startTour(true); return; }
    if (t.hasAttribute('data-ob-hide')) {
      D.show_guide = false; drawGuide();
      post({ action: 'guide_hidden' });
      note('Guide hidden. Open it again from Help → Setup guide.', 'info');
      return;
    }
    if (t.hasAttribute('data-ob-tables')) {
      t.disabled = true; t.textContent = 'Making…';
      post({ action: 'quick_tables' }).then(function (d) { note(d.message || (d.success ? 'Done' : 'Could not make the tables'), d.success ? 'success' : 'error'); load(); });
      return;
    }
    if (t.hasAttribute('data-ob-photo')) {
      var msg = 'Hi RestroGrow, please add my menu for ' + restaurantName() + '. I am sending photos of it here.';
      window.open('https://wa.me/' + (D.support_whatsapp || '') + '?text=' + encodeURIComponent(msg), '_blank', 'noopener');
      post({ action: 'mark', key: 'menu_photo' });
      note('Send the photos in WhatsApp. We type your menu in and you will see the dishes here.', 'info');
      return;
    }
    if (t.hasAttribute('data-ob-share')) {
      var url = websiteUrl(), how = t.getAttribute('data-ob-share');
      if (how === 'wa') window.open('https://wa.me/?text=' + encodeURIComponent('Order from ' + restaurantName() + ' online: ' + url), '_blank', 'noopener');
      else if (navigator.clipboard) navigator.clipboard.writeText(url).then(function () { note('Link copied: ' + url); }, function () { note(url, 'info'); });
      post({ action: 'mark', key: 'share' }).then(load);
    }
  });

  /* ───────── the tour ───────── */
  function tourSteps() {
    var type = D && D.type;
    var all = [
      { hero: 'waving_hand', tag: 'Welcome', title: 'Welcome to RestroGrow, ' + esc(restaurantName()) + '!', text: 'A one-minute look at where everything is. Use the arrows or your keyboard, or skip any time.' },
      { img: 'home', page: 'dashboardPage', tag: 'Home', title: 'Your day at a glance', text: 'Sales, orders in progress and what needs your attention right now, the moment you open the dashboard.', list: ['Today’s sales and orders, live', 'New orders to accept, tables calling'] },
      { img: 'pos', page: 'posPage', tag: 'POS', title: 'Bill in seconds', text: 'Tap dishes, pick a table or takeaway, then Pay or send a KOT to the kitchen.', list: ['Discounts, split bills, held orders', 'Prints to your Bluetooth or Wi-Fi printer'] },
      { img: 'kot', page: 'kotPage', tag: 'Kitchen', title: 'Kitchen tickets (KOT)', text: 'Every order lands here for the kitchen: New → In kitchen → Ready, with timers so nothing waits too long.' },
      { img: 'menu', page: 'menuItemsPage', tag: 'Menu', title: 'Your menu', text: 'Dishes with photos, prices, veg / non-veg, variations and add-ons. Mark something out of stock in one tap.' },
      type === 'cloud_kitchen' ? null : { img: 'tables', page: 'tablesPage', tag: 'Tables', title: 'Tables and QR codes', text: 'See which tables are free or busy. Each table gets a QR code: guests scan, order and call a waiter.' },
      { img: 'website', phone: true, tag: 'Website', title: 'Your own ordering website', text: 'Customers order online from your link with no app and no commission. Share it on WhatsApp and Instagram.' },
      { img: 'customers', page: 'customersPage', tag: 'Customers', title: 'Know your regulars', text: 'Every customer with their visits and spend, built up automatically from bills and online orders.' },
      { img: 'reports', page: 'reportsPage', tag: 'Reports', title: 'Reports that make sense', text: 'Sales by day, best-selling dishes, payment methods and GST, ready to download.' },
      { img: 'staff', page: 'staffPage', tag: 'Team', title: 'Give your team their own login', text: 'Waiters, chefs and cashiers each get the screens they need on the Staff app, nothing more.' },
      { hero: 'celebration', tag: 'Ready', title: 'You’re all set!', text: 'The “Get your restaurant ready” guide on your home page takes you through the first steps. Need help? Tap Help any time.', finish: true }
    ];
    return all.filter(function (s) { return s && !off(s.page); });
  }

  var T = null;
  function spot(page) {
    document.querySelectorAll('.ob-spot').forEach(function (e) { e.classList.remove('ob-spot'); });
    if (!page || window.innerWidth < 900) return;
    var a = document.querySelector('.sidebar [data-page="' + page + '"]');
    if (!a) return;
    // open its menu group so it can be seen
    var grp = a.closest('.has-submenu');
    if (grp && !grp.classList.contains('open')) { var tg = grp.querySelector('.submenu-toggle'); if (tg) tg.click(); }
    a.classList.add('ob-spot');
    try { a.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) {}
  }
  function confetti(el) {
    var box = document.createElement('div'); box.className = 'obt-confetti';
    var colors = ['#fb923c', '#ea580c', '#22c55e', '#3b82f6', '#eab308', '#ec4899'];
    for (var i = 0; i < 40; i++) {
      var c = document.createElement('i');
      c.style.left = Math.random() * 100 + '%';
      c.style.background = colors[i % colors.length];
      c.style.animationDelay = (Math.random() * .5) + 's';
      c.style.transform = 'rotate(' + (Math.random() * 360) + 'deg)';
      box.appendChild(c);
    }
    el.appendChild(box);
    setTimeout(function () { box.remove(); }, 2600);
  }
  function drawTour() {
    var s = T.steps[T.i], n = T.steps.length;
    var shot = s.img ? '<img src="' + IMG + s.img + '.jpg" alt="' + esc(s.tag) + ' screen"' + (s.phone ? ' class="phone"' : '') + ' loading="eager">'
                     : '<div class="obt-hero">' + ic(s.hero) + '</div>';
    T.box.innerHTML =
      '<div class="obt-bar" style="width:' + ((T.i + 1) / n * 100) + '%"></div>' +
      '<button type="button" class="obt-skip" data-obt-skip>' + (s.finish ? 'Close' : 'Skip tour') + '</button>' +
      '<div class="obt-shot"><span class="obt-tag">' + ic(s.finish ? 'celebration' : 'bolt') + esc(s.tag) + '</span>' + shot + '</div>' +
      '<div class="obt-body" id="obtBody">' +
        '<h3 id="obtTitle">' + s.title + '</h3><p>' + s.text + '</p>' +
        (s.list ? '<ul>' + s.list.map(function (l) { return '<li>' + esc(l) + '</li>'; }).join('') + '</ul>' : '') +
        '<div class="obt-foot"><div class="obt-dots" aria-hidden="true">' + T.steps.map(function (x, k) { return '<i class="' + (k === T.i ? 'on' : k < T.i ? 'done' : '') + '"></i>'; }).join('') + '</div>' +
          '<span class="obt-count">' + (T.i + 1) + ' / ' + n + '</span>' +
          '<button type="button" class="obt-back" data-obt-back' + (T.i === 0 ? ' disabled' : '') + '>Back</button>' +
          '<button type="button" class="obt-next" data-obt-next>' + (s.finish ? 'Start setting up' + ic('arrow_forward') : (T.i === 0 ? 'Show me' : 'Next') + ic('arrow_forward')) + '</button></div>' +
      '</div>';
    spot(s.page);
    if (s.finish) confetti(T.box);
    // the next screenshot loads in the background so it shows at once
    var nx = T.steps[T.i + 1]; if (nx && nx.img) { var im = new Image(); im.src = IMG + nx.img + '.jpg'; }
    var btn = T.box.querySelector('[data-obt-next]'); if (btn) btn.focus();
  }
  function endTour(how) {
    if (!T) return;
    document.removeEventListener('keydown', T.keys);
    spot(null);
    T.ov.remove();
    T = null; tourOn = false;
    post({ action: how === 'skip' ? 'tour_skipped' : 'tour_done' });
    if (D) D.state.tour_done = true;
    if (/[?&]welcome=1/.test(location.search)) history.replaceState(null, '', location.pathname + location.hash);
    go('dashboardPage');
    if (how !== 'skip' && D && !D.show_guide && !D.complete) { D.show_guide = true; post({ action: 'guide_shown' }); drawGuide(); }
    var g = document.getElementById('obGuide'); if (g) try { g.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
  }
  function startTour(manual) {
    if (tourOn) return;
    ensureCss();
    tourOn = true;
    if (manual && D && D.state.tour_done) post({ action: 'tour_again' });
    var ov = document.createElement('div');
    ov.className = 'obt-ov';
    ov.innerHTML = '<div class="obt" role="dialog" aria-modal="true" aria-labelledby="obtTitle"></div>';
    document.body.appendChild(ov);
    T = { ov: ov, box: ov.querySelector('.obt'), i: 0, steps: tourSteps() };
    T.keys = function (e) {
      if (!T) return;
      if (e.key === 'ArrowRight') { e.preventDefault(); next(); }
      else if (e.key === 'ArrowLeft') { e.preventDefault(); back(); }
      else if (e.key === 'Escape') { e.preventDefault(); endTour('skip'); }
    };
    document.addEventListener('keydown', T.keys);
    ov.addEventListener('click', function (e) {
      if (e.target.closest('[data-obt-skip]')) endTour(T.steps[T.i].finish ? 'done' : 'skip');
      else if (e.target.closest('[data-obt-next]')) next();
      else if (e.target.closest('[data-obt-back]')) back();
    });
    drawTour();
  }
  function next() { if (T.i >= T.steps.length - 1) return endTour('done'); T.i++; drawTour(); }
  function back() { if (T.i > 0) { T.i--; drawTour(); } }
  window.rgStartTour = function () { (D ? Promise.resolve(D) : load()).then(function () { startTour(true); }); };
  window.rgShowSetupGuide = function () {
    go('dashboardPage');
    (D ? Promise.resolve(D) : load()).then(function () {
      if (!D || !D.is_owner) return;
      D.show_guide = true; drawGuide(); post({ action: 'guide_shown' });
      var g = document.getElementById('obGuide'); if (g) try { g.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
    });
  };

  /* ───────── start ───────── */
  function init() {
    ensureCss();
    load().then(function (d) {
      if (!d || !d.is_owner) return;
      var welcome = /[?&]welcome=1/.test(location.search);
      if (welcome || d.auto_tour) setTimeout(function () { startTour(false); }, welcome ? 700 : 1500);
    });
    // back on the home page: the ticks may have changed (a dish added, tables made)
    var page = document.getElementById('dashboardPage');
    if (page && window.MutationObserver) {
      var was = page.classList.contains('active');
      new MutationObserver(function () {
        var now = page.classList.contains('active');
        if (now && !was && D && D.show_guide) load();
        was = now;
      }).observe(page, { attributes: true, attributeFilter: ['class'] });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
