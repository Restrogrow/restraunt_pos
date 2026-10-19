/**
 * Navigation style: the normal sidebar (default), a bottom dock, or a round menu.
 * Picked in Settings › Navigation style and kept on this device (localStorage "rgNavStyle").
 *
 * The dock and the round menu are built from the real sidebar, and every button simply clicks the
 * matching sidebar link, so pages open exactly as before, sections Super Admin switched off (already
 * removed from the sidebar) never show, and badges (new orders…) are copied from the sidebar live.
 * Styles: assets/css/nav-style.css. <html> gets rg-nav-dock / rg-nav-radial (set early in dashboard.php).
 */
(function () {
  var KEY = 'rgNavStyle';
  var STYLES = ['sidebar', 'dock', 'radial'];

  function getStyle() {
    try { var v = localStorage.getItem(KEY); return STYLES.indexOf(v) > -1 ? v : 'sidebar'; } catch (e) { return 'sidebar'; }
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function ms(n) { return '<span class="rgnav-ms">' + esc(n) + '</span>'; }

  // ── read the sidebar ──
  // sidebar group / link label (lower case) → where it goes. Anything not listed lands in "Setup".
  var PLACE = {
    'dashboard': 'direct', 'pos': 'direct', 'orders': 'direct', 'waiter requests': 'direct',
    'menu': 'menu', 'subscriptions': 'menu',
    'tables': 'tables', 'reservations': 'tables',
    'customers': 'people', 'staff': 'people', 'feedback': 'people',
    'payments': 'money', 'reports': 'money', 'analytics': 'money',
    'marketing': 'market', 'google business': 'market', 'seo': 'market',
    'offers': 'offers', 'growth': 'offers'
  };
  var FOLDERS = [
    { id: 'menu', t: 'Menu', i: 'restaurant_menu', c: '#10b981' },
    { id: 'tables', t: 'Tables', i: 'table_chart', c: '#8b5cf6' },
    { id: 'people', t: 'People', i: 'groups', c: '#3b82f6' },
    { id: 'money', t: 'Money', i: 'account_balance_wallet', c: '#16a34a' },
    { id: 'market', t: 'Marketing', i: 'campaign', c: '#e11d48' },
    { id: 'offers', t: 'Offers & loyalty', i: 'local_offer', c: '#f59e0b' },
    { id: 'setup', t: 'Setup', i: 'tune', c: '#475569' }
  ];
  var DIRECT_COLORS = { dashboard: '#2563eb', pos: '#0ea5e9', orders: '#f97316', 'waiter requests': '#ef4444' };

  function labelOf(a) {
    var l = a.querySelector('.nav-label');
    if (!l) return (a.textContent || '').trim();
    var t = '';
    l.childNodes.forEach(function (n) { if (n.nodeType === 3) t += n.textContent; });
    return t.trim() || l.textContent.trim();
  }
  function iconOf(a, fallback) { var i = a.querySelector('.nav-icon'); var t = i ? i.textContent.trim() : ''; return /^[a-z0-9_]+$/.test(t) ? t : (fallback || 'circle'); }
  function shown(li) { return li && li.style.display !== 'none' && !li.classList.contains('rg-access-none'); }
  function badgeOf(a) { var b = a.querySelector('.nav-badge'); if (!b || b.style.display === 'none') return 0; var n = parseInt(b.textContent, 10); return n > 0 ? n : 0; }

  // [{ kind:'link', t, i, c, el, page } | { kind:'folder', id, t, i, c, items:[link…] }]
  function readSidebar() {
    var bucket = {}, direct = [];
    FOLDERS.forEach(function (f) { bucket[f.id] = []; });
    document.querySelectorAll('.sidebar .sidebar-nav ul.nav-list > li').forEach(function (li) {
      if (!shown(li)) return;
      var a = li.querySelector(':scope > a.nav-link'); if (!a) return;
      var name = labelOf(a), key = name.toLowerCase(), place = PLACE[key] || 'setup', icon = iconOf(a);
      var links = [];
      if (li.classList.contains('has-submenu')) {
        li.querySelectorAll('.submenu > li').forEach(function (sli) {
          if (!shown(sli)) return;
          var s = sli.querySelector('a.nav-link'); if (!s) return;
          links.push({ kind: 'link', t: labelOf(s), i: iconOf(s, icon), el: s, page: s.getAttribute('data-page') || '', group: name });
        });
        if (!links.length) return;
      } else {
        links.push({ kind: 'link', t: name, i: icon, el: a, page: a.getAttribute('data-page') || '' });
      }
      if (place === 'direct') {
        if (links.length === 1 && !li.classList.contains('has-submenu')) { links[0].c = DIRECT_COLORS[key] || '#2563eb'; direct.push(links[0]); }
        else direct.push({ kind: 'folder', id: 'g-' + key.replace(/\W+/g, '-'), t: name, i: icon, c: DIRECT_COLORS[key] || '#2563eb', items: links, owner: a });
      } else {
        links.forEach(function (l) { bucket[place].push(l); });
      }
    });
    // multi-branch logins: "All branches" next to Setup
    var sw = document.getElementById('restaurantSwitcherWrapper'), allB = sw && sw.style.display !== 'none' ? sw.querySelector('a.branch-switcher-all') : null;
    if (allB) bucket.setup.unshift({ kind: 'link', t: 'All branches', i: 'storefront', el: allB, page: '' });
    var out = direct.slice();
    FOLDERS.forEach(function (f) {
      var items = bucket[f.id];
      if (!items.length) return;
      items.forEach(function (it) { it.c = f.c; });
      out.push({ kind: 'folder', id: f.id, t: f.t, i: f.i, c: f.c, items: items });
    });
    out.forEach(function (it) { if (it.kind === 'folder') it.items.forEach(function (l) { l.c = l.c || it.c; }); });
    return out;
  }
  function badgeTotal(entry) {
    if (entry.kind === 'link') return badgeOf(entry.el);
    var n = entry.owner ? badgeOf(entry.owner) : 0;
    if (!n) entry.items.forEach(function (l) { n += l.kind === 'folder' ? badgeTotal(l) : badgeOf(l.el); });
    return n;
  }
  function activePage() { var p = document.querySelector('.main-content > .page.active'); return p ? p.id : ''; }
  function contains(entry, page) { if (!page) return false; return entry.kind === 'link' ? entry.page === page : entry.items.some(function (l) { return l.page === page; }); }
  function go(link) { if (link && link.el) link.el.click(); }

  var style = 'sidebar', entries = [], root = null;

  // ── bottom dock ──
  function buildDock() {
    var phone = window.innerWidth <= 700;
    var list = entries;
    if (phone) {   // phone: the direct ones, then one "All" folder holding the folders
      var direct = entries.filter(function (e) { return e.kind === 'link' || e.id.indexOf('g-') === 0; });
      var folders = entries.filter(function (e) { return e.kind === 'folder' && e.id.indexOf('g-') !== 0; });
      list = direct.slice(0, 4).concat([{ kind: 'folder', id: 'all', t: 'More', i: 'apps', c: '#111827', items: direct.slice(4).concat(folders), nested: true }]);
    }
    var firstFolder = -1;
    list.forEach(function (e, k) { if (firstFolder < 0 && e.kind === 'folder' && e.id.indexOf('g-') !== 0) firstFolder = k; });
    root = document.createElement('div');
    root.innerHTML = '<div class="rgnav-stack" role="dialog" aria-label="Pages"><h4></h4><div class="rgnav-sg"></div></div>' +
      '<nav class="rgnav-dock" aria-label="Main menu">' + list.map(function (e, k) {
        var sep = !phone && k > 0 && k === firstFolder ? '<span class="rgnav-sep"></span>' : '';
        var isFolder = e.kind === 'folder' && !phone && e.id.indexOf('g-') !== 0;
        var face = isFolder ? '<span class="rgnav-mini">' + e.items.slice(0, 4).map(function (l) { return '<i style="--c:' + l.c + '">' + ms(l.i) + '</i>'; }).join('') + '</span>' : ms(e.i);
        return sep + '<button type="button" class="rgnav-di' + (isFolder ? ' folder' : '') + '" data-k="' + k + '" style="--c:' + e.c + '" aria-label="' + esc(e.t) + '">' + face + '<span class="rgnav-badge" hidden></span><span class="rgnav-tip">' + esc(e.t) + '</span></button>';
      }).join('') + '</nav>';
    document.body.appendChild(root);
    var dock = root.querySelector('.rgnav-dock'), stack = root.querySelector('.rgnav-stack'), btns = Array.prototype.slice.call(dock.querySelectorAll('.rgnav-di'));
    var big = phone ? 48 : 66, base = phone ? 42 : 46, openK = -1, path = [];

    if (!phone && window.matchMedia && window.matchMedia('(hover: hover)').matches) {
      dock.addEventListener('mousemove', function (ev) {
        btns.forEach(function (b) { var r = b.getBoundingClientRect(), d = Math.abs(ev.clientX - (r.left + r.width / 2)); var s = Math.max(base, big - d * .3); b.style.width = b.style.height = s + 'px'; });
      });
      dock.addEventListener('mouseleave', function () { btns.forEach(function (b) { b.style.width = b.style.height = ''; }); });
    }
    function closeStack() { stack.classList.remove('show'); document.documentElement.classList.remove('rgnav-stack-open'); openK = -1; path = []; }
    function drawStack(folder, anchor) {
      var cur = activePage();
      stack.style.setProperty('--c', folder.c);
      stack.querySelector('h4').innerHTML = (path.length > 1 ? '<button type="button" data-back aria-label="Back">' + ms('arrow_back') + '</button>' : '') + ms(folder.i) + esc(folder.t);
      stack.querySelector('.rgnav-sg').innerHTML = folder.items.map(function (l, i) {
        var face = l.kind === 'folder' ? ms(l.i) : ms(l.i);
        var b = l.kind === 'folder' ? badgeTotal(l) : badgeOf(l.el);
        return '<button type="button" data-i="' + i + '" class="' + (contains(l, cur) ? 'on' : '') + '"><i style="--c:' + l.c + '">' + face + (b ? '<span class="rgnav-badge">' + b + '</span>' : '') + '</i>' + esc(l.t) + '</button>';
      }).join('');
      if (anchor) {
        var r = anchor.getBoundingClientRect(), w = Math.min(360, window.innerWidth - 20);
        var left = Math.max(w / 2 + 10, Math.min(window.innerWidth - w / 2 - 10, r.left + r.width / 2));
        stack.style.left = left + 'px';
      }
      stack.classList.add('show');
      document.documentElement.classList.add('rgnav-stack-open');
    }
    dock.addEventListener('click', function (ev) {
      var b = ev.target.closest('.rgnav-di'); if (!b) return;
      var k = +b.getAttribute('data-k'), e = list[k];
      if (e.kind === 'link') { closeStack(); go(e); return; }
      if (openK === k && stack.classList.contains('show')) { closeStack(); return; }
      openK = k; path = [e]; drawStack(e, b);
    });
    stack.addEventListener('click', function (ev) {
      ev.stopPropagation();   // the stack redraws itself, so the clicked button may already be gone from the page
      if (ev.target.closest('[data-back]')) { path.pop(); drawStack(path[path.length - 1]); return; }
      var b = ev.target.closest('button[data-i]'); if (!b) return;
      var cur = path[path.length - 1], it = cur.items[+b.getAttribute('data-i')];
      if (it.kind === 'folder') { path.push(it); drawStack(it); return; }
      closeStack(); go(it);
    });
    document.addEventListener('click', function (ev) { if (stack.classList.contains('show') && ev.target.isConnected && !ev.target.closest('.rgnav-stack') && !ev.target.closest('.rgnav-dock')) closeStack(); });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') closeStack(); });

    root._sync = function () {
      var cur = activePage();
      btns.forEach(function (b) {
        var e = list[+b.getAttribute('data-k')], n = badgeTotal(e), bd = b.querySelector('.rgnav-badge');
        b.classList.toggle('on', !!(contains(e, cur) || (e.nested && e.items.some(function (x) { return contains(x, cur); }))));
        bd.hidden = !n; bd.textContent = n > 99 ? '99+' : n;
      });
    };
  }

  // ── round menu ──
  function buildRadial() {
    root = document.createElement('div');
    var phone = window.innerWidth <= 700, R1 = phone ? 108 : 150, RINGS = phone ? [184, 248, 312] : [236, 316, 396];
    var main = entries;
    // a quarter circle from just above the bottom edge to straight up
    function pos(k, n, R) { var a = (Math.PI / 2) * (n === 1 ? .5 : .1 + .86 * k / (n - 1)); return { x: -Math.round(Math.cos(a) * R), y: -Math.round(Math.sin(a) * R) }; }
    root.innerHTML = '<div class="rgnav-veil"></div><div class="rgnav-orb">' + main.map(function (e, k) {
      var p = pos(k, main.length, R1 + (main.length > 9 ? (k % 2) * 46 : 0));
      return '<button type="button" class="rgnav-oi l1" data-k="' + k + '" style="--c:' + e.c + ';--x:' + p.x + 'px;--y:' + p.y + 'px;transition-delay:' + (k * 20) + 'ms" aria-label="' + esc(e.t) + '">' + ms(e.i) + '<span class="rgnav-badge" hidden></span><span class="rgnav-lab">' + esc(e.t) + '</span></button>';
    }).join('') + '<div class="rgnav-sub"></div></div><button type="button" class="rgnav-fab" aria-label="Open the menu" aria-expanded="false">' + ms('add') + '<span class="rgnav-badge" hidden></span></button>';
    document.body.appendChild(root);
    var html = document.documentElement, orb = root.querySelector('.rgnav-orb'), sub = root.querySelector('.rgnav-sub'), fab = root.querySelector('.rgnav-fab');
    function close() { html.classList.remove('rgnav-r-open', 'rgnav-r-sub'); fab.setAttribute('aria-expanded', 'false'); orb.querySelectorAll('.l1').forEach(function (x) { x.classList.remove('dim', 'sel'); }); }
    fab.addEventListener('click', function () { if (html.classList.contains('rgnav-r-open')) close(); else { html.classList.add('rgnav-r-open'); fab.setAttribute('aria-expanded', 'true'); root._sync(); } });
    root.querySelector('.rgnav-veil').addEventListener('click', close);
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') close(); });
    orb.addEventListener('click', function (ev) {
      var l2 = ev.target.closest('.l2');
      if (l2) { var f = main[+l2.getAttribute('data-f')]; close(); go(f.items[+l2.getAttribute('data-i')]); return; }
      var el = ev.target.closest('.l1'); if (!el) return;
      var e = main[+el.getAttribute('data-k')];
      if (e.kind === 'link') { close(); go(e); return; }
      if (el.classList.contains('sel')) { html.classList.remove('rgnav-r-sub'); orb.querySelectorAll('.l1').forEach(function (x) { x.classList.remove('dim', 'sel'); }); return; }
      orb.querySelectorAll('.l1').forEach(function (x) { x.classList.toggle('dim', x !== el); x.classList.toggle('sel', x === el); });
      var cur = activePage(), per = phone ? 5 : 6;
      sub.innerHTML = e.items.map(function (l, i) {
        var ring = Math.min(Math.floor(i / per), RINGS.length - 1), first = ring * per, inRing = ring === RINGS.length - 1 ? e.items.length - first : Math.min(per, e.items.length - first), p = pos(i - first, inRing, RINGS[ring]);
        return '<button type="button" class="rgnav-oi l2' + (contains(l, cur) ? ' on' : '') + '" data-f="' + el.getAttribute('data-k') + '" data-i="' + i + '" style="--c:' + l.c + ';--x:' + p.x + 'px;--y:' + p.y + 'px;transition-delay:' + (i * 25) + 'ms" aria-label="' + esc(l.t) + '">' + ms(l.i) + '<span class="rgnav-lab">' + esc(l.t) + '</span></button>';
      }).join('');
      html.classList.remove('rgnav-r-sub');
      void sub.offsetWidth;
      requestAnimationFrame(function () { html.classList.add('rgnav-r-sub'); });
    });
    root._sync = function () {
      var cur = activePage(), total = 0;
      orb.querySelectorAll('.l1').forEach(function (b) {
        var e = main[+b.getAttribute('data-k')], n = badgeTotal(e), bd = b.querySelector('.rgnav-badge');
        total += n;
        b.classList.toggle('on', !!contains(e, cur));
        bd.hidden = !n; bd.textContent = n > 99 ? '99+' : n;
      });
      var fb = fab.querySelector('.rgnav-badge'); fb.hidden = !total; fb.textContent = total > 99 ? '99+' : total;
    };
  }

  // ── apply / switch ──
  var observer = null, syncTimer = 0;
  function scheduleSync() { clearTimeout(syncTimer); syncTimer = setTimeout(function () { if (root && root._sync) root._sync(); }, 80); }
  function teardown() {
    if (root) { root.remove(); root = null; }
    if (observer) { observer.disconnect(); observer = null; }
    document.documentElement.classList.remove('rg-nav-dock', 'rg-nav-radial', 'rgnav-r-open', 'rgnav-r-sub', 'rgnav-stack-open');
  }
  function apply(next) {
    style = STYLES.indexOf(next) > -1 ? next : 'sidebar';
    teardown();
    if (style === 'sidebar') return;
    document.documentElement.classList.add(style === 'dock' ? 'rg-nav-dock' : 'rg-nav-radial');
    entries = readSidebar();
    if (style === 'dock') buildDock(); else buildRadial();
    root._sync();
    // badges and the open page follow the sidebar and the pages as they change
    observer = new MutationObserver(scheduleSync);
    var sb = document.querySelector('.sidebar'); if (sb) observer.observe(sb, { subtree: true, attributes: true, attributeFilter: ['style', 'class'], childList: true, characterData: true });
    document.querySelectorAll('.main-content > .page').forEach(function (pg) { observer.observe(pg, { attributes: true, attributeFilter: ['class'] }); });
  }
  window.rgNavStyle = {
    get: getStyle,
    set: function (v) { try { localStorage.setItem(KEY, v); } catch (e) {} apply(v); syncPicker(); }
  };

  // rebuild when crossing the phone / computer width (the dock has a phone layout)
  var wasPhone = window.innerWidth <= 700, rt = 0;
  window.addEventListener('resize', function () {
    clearTimeout(rt);
    rt = setTimeout(function () { var p = window.innerWidth <= 700; if (p !== wasPhone && style !== 'sidebar') { wasPhone = p; apply(style); } wasPhone = p; }, 200);
  });

  // ── Settings › Navigation style ──
  function syncPicker() {
    var cur = getStyle();
    document.querySelectorAll('.rgnav-pick input[name="rgNavStyle"]').forEach(function (r) { r.checked = r.value === cur; var l = r.closest('label'); if (l) l.classList.toggle('on', r.checked); });
  }
  document.addEventListener('change', function (ev) {
    var r = ev.target.closest && ev.target.closest('.rgnav-pick input[name="rgNavStyle"]');
    if (!r) return;
    window.rgNavStyle.set(r.value);
    if (window.RGNotify) { try { RGNotify.success(r.value === 'sidebar' ? 'Sidebar is back' : (r.value === 'dock' ? 'Bottom dock is on' : 'Round menu is on')); } catch (e) {} }
  });

  function start() { apply(getStyle()); syncPicker(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
