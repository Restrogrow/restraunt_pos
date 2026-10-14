/*
 * POS dishes (#posPage, left side). Selling itself is unchanged in script.js: it renders the
 * .pos-menu-item tiles and adds a dish when a tile is tapped; this file only shapes how they look
 * and adds shortcuts around them.
 *
 *  - Bar: search ("/" focuses it, Enter adds the only match, Esc clears), Grid / List view
 *    (remembered on this device), Veg / Non-veg chips, and one row of category chips.
 *  - "All": dishes grouped under their category; a category chip jumps to its section (no reload)
 *    and the chip of the section in view lights up. A search or type filter shows one flat grid.
 *  - Tiles: photo (or a coloured letter), veg mark, name, price ("From ..." for dishes with sizes),
 *    a big + to add; once in the cart the + becomes − qty + (dishes with sizes show the count and
 *    open the size picker instead).
 * Chips set the existing #posMenuFilter / #posTypeFilter; the search box is the existing
 * #posSearchInput. Styles ps-* in assets/css/studio-ui.css.
 */
(function () {
  'use strict';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var pendingJump = '';
  var counts = {}, total = 0, inCart = {}, grouping = false, gridObs = null, spyTick = 0, activeGroup = '';
  var TYPES = [['', 'All'], ['Veg', 'Veg'], ['Non Veg', 'Non-veg'], ['Egg', 'Egg'], ['Drink', 'Drinks']];
  var TINTS = [['#FFEDD5', '#C2410C'], ['#DCFCE7', '#15803D'], ['#DBEAFE', '#1D4ED8'], ['#FCE7F3', '#BE185D'], ['#EDE9FE', '#6D28D9'], ['#FEF9C3', '#A16207'], ['#CFFAFE', '#0E7490']];
  var view = 'grid';
  try { view = localStorage.getItem('rg_pos_view') === 'list' ? 'list' : 'grid'; } catch (e) {}

  function el(id) { return document.getElementById(id); }
  function setSelect(id, v) { var s = el(id); if (!s) return; s.value = v; s.dispatchEvent(new Event('change', { bubbles: true })); }

  function fetchCounts() {
    var page = function (n) { return fetch('../api/get_menu_items.php?limit=200&page=' + n, { cache: 'no-store' }).then(function (r) { return r.json(); }); };
    return page(1).then(function (d) {
      var all = (d && d.data) || [], more = [];
      for (var n = 2; n <= Math.min(+(d && d.total_pages) || 1, 20); n++) more.push(page(n));
      return Promise.all(more).then(function (rs) { rs.forEach(function (x) { all = all.concat((x && x.data) || []); }); return all; });
    }).then(function (all) {
      counts = {}; total = 0;
      all.forEach(function (i) { if (String(i.is_hidden) === '1') return; total++; counts[i.menu_id] = (counts[i.menu_id] || 0) + 1; });
      paintBar();
    }).catch(function () {});
  }

  /* ── the bar over the dishes ── */
  function paintBar() {
    var page = el('posPage'), grid = el('posMenuItems'); if (!page || !grid) return;
    var bar = page.querySelector('.ps-bar');
    if (!bar) {
      bar = document.createElement('div'); bar.className = 'ps-bar';
      grid.parentNode.insertBefore(bar, grid);
      var row = document.createElement('div'); row.className = 'ps-row';
      // the existing search box, moved into the bar (its listeners come with it)
      var search = el('posSearchInput');
      var wrap = document.createElement('div'); wrap.className = 'sx-search ps-search';
      wrap.innerHTML = ic('search');
      if (search) { search.placeholder = 'Search dishes'; search.className = ''; search.setAttribute('autocomplete', 'off'); wrap.appendChild(search); }
      wrap.insertAdjacentHTML('beforeend', '<kbd class="ps-kbd" title="Press / to search">/</kbd><button type="button" class="ps-clear" data-ps-clear aria-label="Clear search" hidden>' + ic('close') + '</button>');
      row.appendChild(wrap);
      var tv = document.createElement('div'); tv.className = 'ps-types'; row.appendChild(tv);
      var vw = document.createElement('div'); vw.className = 'ps-view'; vw.setAttribute('role', 'group'); vw.setAttribute('aria-label', 'Dish view');
      vw.innerHTML = '<button type="button" data-ps-view="grid" title="Photos">' + ic('grid_view') + '</button><button type="button" data-ps-view="list" title="List">' + ic('list') + '</button>';
      bar.appendChild(row);
      // categories, with the Grid / List switch at the end of the same line
      var row2 = document.createElement('div'); row2.className = 'ps-row2';
      var cats = document.createElement('div'); cats.className = 'ps-cats'; row2.appendChild(cats); row2.appendChild(vw);
      bar.appendChild(row2);
      if (search) {
        search.addEventListener('input', function () { bar.querySelector('.ps-clear').hidden = !search.value; bar.querySelector('.ps-kbd').hidden = !!search.value; });
        search.addEventListener('keydown', function (e) {
          if (e.key === 'Escape') clearSearch();
          if (e.key === 'Enter') {   // the only match goes straight into the cart
            e.preventDefault();
            setTimeout(function () {
              var tiles = grid.querySelectorAll('.pos-menu-item');
              if (tiles.length === 1) { tiles[0].click(); clearSearch(); }
              else if (tiles.length) tiles[0].focus();
            }, 300);
          }
        });
      }
    }
    var menuSel = el('posMenuFilter'), sel = menuSel ? menuSel.value : '';
    var opts = menuSel ? [].slice.call(menuSel.options).filter(function (o) { return o.value; }) : [];
    var on = sel || activeGroup;
    bar.querySelector('.ps-cats').innerHTML = '<button type="button" class="' + (!on ? 'on' : '') + '" data-ps-menu="">' + ic('apps') + 'All' + (total ? ' <em>' + total + '</em>' : '') + '</button>' +
      opts.map(function (o) { return '<button type="button" class="' + (on === o.value ? 'on' : '') + '" data-ps-menu="' + esc(o.value) + '">' + esc(o.textContent) + (counts[o.value] ? ' <em>' + counts[o.value] + '</em>' : '') + '</button>'; }).join('') +
      (window.rgPosAddons && window.rgPosAddons.list().length ? '<button type="button" class="' + (on === '__addons' ? 'on' : '') + '" data-ps-menu="__addons">' + ic('add_circle') + 'Add-ons <em>' + window.rgPosAddons.list().length + '</em></button>' : '');
    var t = (el('posTypeFilter') || {}).value || '';
    bar.querySelector('.ps-types').innerHTML = TYPES.map(function (x) {
      return '<button type="button" class="' + (t === x[0] ? 'on' : '') + '" data-ps-type="' + esc(x[0]) + '">' + (x[0] ? '<i class="ps-dot ' + x[0].toLowerCase().replace(' ', '-') + '"></i>' : '') + x[1] + '</button>';
    }).join('');
    bar.querySelectorAll('[data-ps-view]').forEach(function (b) { var is = b.getAttribute('data-ps-view') === view; b.classList.toggle('on', is); b.setAttribute('aria-pressed', is ? 'true' : 'false'); });
    grid.classList.toggle('ps-list', view === 'list');
  }
  function clearSearch() {
    var s = el('posSearchInput'); if (!s) return;
    s.value = ''; s.dispatchEvent(new Event('input', { bubbles: true }));
    s.focus();
  }

  /* ── tiles ── */
  function tint(name) { var h = 0; for (var i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) | 0; return TINTS[Math.abs(h) % TINTS.length]; }
  function decorate(t) {
    if (t.getAttribute('data-ps')) return;
    t.setAttribute('data-ps', '1');
    t.setAttribute('role', 'button'); t.setAttribute('tabindex', '0');
    var name = t.getAttribute('data-item-name') || '', img = t.querySelector('.item-image');
    if (img && !img.querySelector('img')) {   // no photo: a coloured letter instead of a grey fork
      var c = tint(name);
      img.classList.add('ps-noimg');
      img.style.background = c[0]; img.style.color = c[1];
      img.innerHTML = '<b>' + esc((name.trim().charAt(0) || '?').toUpperCase()) + '</b>';
    }
    var type = t.getAttribute('data-type');
    if (img && type && /^(veg|non veg|egg|drink)$/i.test(type)) img.insertAdjacentHTML('beforeend', '<i class="ps-veg ' + type.toLowerCase().replace(' ', '-') + '" title="' + esc(type) + '"></i>');
    var price = t.querySelector('.item-price'), vars = t.getAttribute('data-has-variations') === '1';
    if (price) {
      var base = parseFloat(t.getAttribute('data-item-price')) || 0;
      if (vars) { var first = (price.textContent.split(/\s+-\s+/)[0] || '').replace('(Variations)', '').trim(); price.innerHTML = '<small>From</small> ' + esc(first); }
      else if (!base) price.innerHTML = '<span class="ps-noprice">Set price</span>';
    }
    var foot = document.createElement('div'); foot.className = 'ps-foot';
    if (price) foot.appendChild(price);
    var act = document.createElement('span'); act.className = 'ps-act';
    foot.appendChild(act);
    t.appendChild(foot);
    if (vars) t.classList.add('ps-vars');
    var old = t.querySelector('.ps-badge'); if (old) old.remove();
  }
  function paintAct(t) {
    var id = t.getAttribute('data-item-id'), c = inCart[id], q = c ? c.q : 0, act = t.querySelector('.ps-act'), vars = t.classList.contains('ps-vars');
    if (!act) return;
    t.classList.toggle('ps-in', q > 0);
    // green "×2" count on the photo
    var img = t.querySelector('.item-image'), badge = img && img.querySelector('.ps-count-badge');
    if (q > 0 && img) { if (!badge) { badge = document.createElement('span'); badge.className = 'ps-count-badge'; img.appendChild(badge); } badge.textContent = '×' + q; }
    else if (badge) badge.remove();
    var key = q > 0 && !vars && c.keys.length === 1 ? c.keys[0] : '';
    var want = q > 0 ? (key ? 'step' + q : 'n' + q) : (vars ? 'opt' : 'add');
    if (act.getAttribute('data-s') === want) return;
    act.setAttribute('data-s', want);
    if (key) act.innerHTML = '<button type="button" class="ps-minus" data-ps-minus="' + esc(key) + '" aria-label="One less" tabindex="-1">' + ic('remove') + '</button><b>' + q + '</b><span class="ps-plus" aria-hidden="true">' + ic('add') + '</span>';
    else if (q > 0) act.innerHTML = '<b class="ps-count">×' + q + '</b><span class="ps-plus" aria-hidden="true">' + ic(vars ? 'tune' : 'add') + '</span>';
    else act.innerHTML = (vars ? '<em>Options</em>' : '') + '<span class="ps-plus" aria-hidden="true">' + ic(vars ? 'tune' : 'add') + '</span>';
  }
  function tilesUp() {
    var grid = el('posMenuItems'); if (!grid) return;
    grid.querySelectorAll('.pos-menu-item').forEach(function (t) { decorate(t); paintAct(t); });
    // nothing matches the search: say so, with a way back
    var q = ((el('posSearchInput') || {}).value || '').trim(), empty = grid.querySelector('.empty-state');
    if (empty && q && !empty.getAttribute('data-ps')) {
      empty.setAttribute('data-ps', '1');
      empty.innerHTML = ic('search_off') + '<h3>No dish matches “' + esc(q) + '”</h3><p>Check the spelling, or pick a category above.</p><button type="button" class="ps-clearbtn" data-ps-clear>' + ic('close') + 'Clear search</button>';
    }
  }

  // "All": dishes under their category; a filter or a search shows one flat grid
  function group() {
    var grid = el('posMenuItems'); if (!grid || grouping) return;
    var tiles = [].slice.call(grid.querySelectorAll('.pos-menu-item'));
    var q = ((el('posSearchInput') || {}).value || '').trim(), menu = (el('posMenuFilter') || {}).value || '';
    grouping = true;
    if (gridObs) gridObs.disconnect();   // moving tiles is itself a change: don't react to our own moves
    grid.querySelectorAll('.ps-group-h, .pa-tile').forEach(function (h) { h.remove(); });
    var grouped = !!(tiles.length && !q && !menu);
    if (grouped) {
      var order = [], by = {};
      tiles.forEach(function (t) { var k = t.getAttribute('data-menu') || ''; if (!by[k]) { by[k] = []; order.push(k); } by[k].push(t); });
      // keep the restaurant's category order (the dropdown's order)
      var sel = el('posMenuFilter'), pos = {};
      if (sel) [].slice.call(sel.options).forEach(function (o, i) { pos[o.value] = i; });
      order.sort(function (a, b) { return (pos[a] != null ? pos[a] : 999) - (pos[b] != null ? pos[b] : 999); });
      order.forEach(function (k) {
        var h = document.createElement('div'); h.className = 'ps-group-h'; h.setAttribute('data-group', k);
        h.innerHTML = '<b>' + esc(by[k][0].getAttribute('data-menu-name') || 'Other') + '</b><small>' + by[k].length + ' dish' + (by[k].length === 1 ? '' : 'es') + '</small>';
        grid.appendChild(h);
        by[k].forEach(function (t) { grid.appendChild(t); });
      });
    }
    grid.classList.toggle('ps-grouped', grouped);
    tilesUp();
    // order add-ons after the dishes (pos-addons.js)
    if (window.rgPosAddons) window.rgPosAddons.renderTiles(grid, q, grouped || (!tiles.length && !menu && !q), (el('posTypeFilter') || {}).value || '');
    if (pendingJump) { var pj = pendingJump; pendingJump = ''; setTimeout(function () { jumpTo(pj); }, 30); }
    if (gridObs) gridObs.takeRecords();
    grouping = false;
    if (gridObs) gridObs.observe(grid, { childList: true });
    if (!grouped && activeGroup) { activeGroup = ''; paintBar(); }
  }

  // the category in view lights up its chip (grouped "All" only)
  function scroller() {
    var sec = document.querySelector('#posPage .pos-menu-section');
    return sec && sec.scrollHeight > sec.clientHeight + 4 && getComputedStyle(sec).overflowY !== 'visible' ? sec : null;
  }
  function spy() {
    var sec = document.querySelector('#posPage .pos-menu-section'), grid = el('posMenuItems');
    if (!sec || !grid || !grid.classList.contains('ps-grouped')) return;
    var bar = sec.querySelector('.ps-bar'), line = (bar ? bar.getBoundingClientRect().bottom : 0) + 16, now = '';
    grid.querySelectorAll('.ps-group-h').forEach(function (h, i) { if (i > 0 && h.getBoundingClientRect().top <= line) now = h.getAttribute('data-group'); });
    if (now !== activeGroup) {
      activeGroup = now;
      var cats = sec.querySelector('.ps-cats');
      if (cats) cats.querySelectorAll('button').forEach(function (b) {
        var hit = b.getAttribute('data-ps-menu') === now;
        b.classList.toggle('on', hit);
        if (hit && cats.scrollWidth > cats.clientWidth + 2) cats.scrollTo({ left: b.offsetLeft - cats.clientWidth / 2 + b.offsetWidth / 2, behavior: 'smooth' });
      });
    }
  }
  function jumpTo(k) {
    var grid = el('posMenuItems'), sec = document.querySelector('#posPage .pos-menu-section');
    var h = grid && [].slice.call(grid.querySelectorAll('.ps-group-h')).filter(function (x) { return x.getAttribute('data-group') === k; })[0];
    if (!h || !sec) return false;
    var bar = sec.querySelector('.ps-bar'), barH = bar ? bar.offsetHeight : 0, sc = scroller();
    if (sc) sc.scrollTo({ top: h.getBoundingClientRect().top - sc.getBoundingClientRect().top + sc.scrollTop - barH - 6, behavior: 'smooth' });
    else window.scrollTo({ top: h.getBoundingClientRect().top + window.scrollY - barH - 70, behavior: 'smooth' });
    return true;
  }

  window.rgPosRegroup = function () { paintBar(); group(); };
  window.addEventListener('rg-pos-cart', function (e) {
    inCart = {};
    (e.detail || []).forEach(function (i) {
      var c = inCart[i.id] || (inCart[i.id] = { q: 0, keys: [] });
      c.q += (+i.q || 0); c.keys.push(i.k || String(i.id));
    });
    tilesUp();
  });
  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('#posPage')) return;
    var m = e.target.closest('[data-ps-minus]');
    if (m) { e.stopPropagation(); if (window.updatePOSCartQty) window.updatePOSCartQty(m.getAttribute('data-ps-minus'), -1); return; }
    if (e.target.closest('[data-ps-clear]')) { clearSearch(); return; }
    var v = e.target.closest('[data-ps-view]');
    if (v) { view = v.getAttribute('data-ps-view'); try { localStorage.setItem('rg_pos_view', view); } catch (er) {} paintBar(); return; }
    var b = e.target.closest('[data-ps-menu],[data-ps-type]');
    if (!b) return;
    if (b.hasAttribute('data-ps-menu')) {
      var k = b.getAttribute('data-ps-menu'), grid = el('posMenuItems'), s = el('posSearchInput');
      var q = ((s || {}).value || '').trim();
      // in "All", a category jumps to its section; otherwise it filters, as before
      if (k && !q && !(el('posMenuFilter') || {}).value && grid && grid.classList.contains('ps-grouped') && jumpTo(k)) {
        activeGroup = k;
        b.parentNode.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
        return;
      }
      if (k === '__addons') {   // not a real category: show All, then jump to the add-ons
        pendingJump = k; activeGroup = k;
        if (q && s) { s.value = ''; s.dispatchEvent(new Event('input', { bubbles: true })); }
        if ((el('posMenuFilter') || {}).value) setSelect('posMenuFilter', ''); else if (!q) { pendingJump = ''; jumpTo(k); }
        paintBar(); return;
      }
      if (q && s) { s.value = ''; s.dispatchEvent(new Event('input', { bubbles: true })); }
      activeGroup = '';
      setSelect('posMenuFilter', k);
      if (!k) { var sc = scroller(); if (sc) sc.scrollTo({ top: 0, behavior: 'smooth' }); }
    } else setSelect('posTypeFilter', b.getAttribute('data-ps-type'));
    paintBar();
  }, true);
  // keyboard: Enter / Space on a tile adds it; "/" goes to the search box
  document.addEventListener('keydown', function (e) {
    var page = el('posPage'); if (!page || !page.classList.contains('active')) return;
    var t = e.target;
    if ((e.key === 'Enter' || e.key === ' ') && t.classList && t.classList.contains('pos-menu-item')) { e.preventDefault(); t.click(); return; }
    if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(t.tagName) && !t.isContentEditable && !document.querySelector('.pc-ov, .rgm-ov')) {
      var s = el('posSearchInput'); if (s) { e.preventDefault(); s.focus(); s.select(); }
    }
  });

  function init() {
    var grid = el('posMenuItems'), page = el('posPage');
    if (!grid || !page) return;
    page.classList.add('ps');   // not 'sx': the POS keeps its own button and input sizes
    gridObs = new MutationObserver(function () { if (!grouping) group(); });
    gridObs.observe(grid, { childList: true });
    // the category list fills in once the POS opens: draw the chips then
    var sel = el('posMenuFilter');
    if (sel) new MutationObserver(paintBar).observe(sel, { childList: true });
    paintBar();
    var sec = page.querySelector('.pos-menu-section');
    var onScroll = function () { if (!spyTick) spyTick = requestAnimationFrame(function () { spyTick = 0; spy(); }); };
    if (sec) sec.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('scroll', onScroll, { passive: true });
    var loaded = false;
    var start = function () { if (!loaded && page.classList.contains('active')) { loaded = true; fetchCounts(); } };
    new MutationObserver(start).observe(page, { attributes: true, attributeFilter: ['class'] });
    start();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
