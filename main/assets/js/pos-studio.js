/*
 * POS look (#posPage): category chips with counts, Veg / Non-veg chips, a big search, dishes grouped by
 * category, and "×2" on dishes already in the cart. Selling itself is unchanged in script.js:
 * chips just set the existing #posMenuFilter / #posTypeFilter and fire their change (loadPOSMenuItems);
 * the search box is the existing #posSearchInput. Styles ps-* in assets/css/studio-ui.css.
 */
(function () {
  'use strict';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var counts = {}, total = 0, inCart = {}, grouping = false, gridObs = null;
  var TYPES = [['', 'All'], ['Veg', 'Veg'], ['Non Veg', 'Non-veg'], ['Egg', 'Egg'], ['Drink', 'Drinks']];

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

  function paintBar() {
    var page = el('posPage'), grid = el('posMenuItems'); if (!page || !grid) return;
    var bar = page.querySelector('.ps-bar');
    if (!bar) {
      bar = document.createElement('div'); bar.className = 'ps-bar';
      grid.parentNode.insertBefore(bar, grid);
      // the existing search box, moved into the bar (its listeners come with it)
      var search = el('posSearchInput');
      var wrap = document.createElement('div'); wrap.className = 'sx-search ps-search';
      wrap.innerHTML = ic('search');
      if (search) { search.placeholder = 'Search dishes'; search.className = ''; search.setAttribute('autocomplete', 'off'); wrap.appendChild(search); }
      bar.appendChild(wrap);
      var cats = document.createElement('div'); cats.className = 'ps-cats'; bar.appendChild(cats);
      var types = document.createElement('div'); types.className = 'ps-types'; bar.appendChild(types);
    }
    var menuSel = el('posMenuFilter'), cur = menuSel ? menuSel.value : '';
    var opts = menuSel ? [].slice.call(menuSel.options).filter(function (o) { return o.value; }) : [];
    bar.querySelector('.ps-cats').innerHTML = '<button type="button" class="' + (!cur ? 'on' : '') + '" data-ps-menu="">' + ic('apps') + 'All' + (total ? ' <em>' + total + '</em>' : '') + '</button>' +
      opts.map(function (o) { return '<button type="button" class="' + (cur === o.value ? 'on' : '') + '" data-ps-menu="' + esc(o.value) + '">' + esc(o.textContent) + (counts[o.value] ? ' <em>' + counts[o.value] + '</em>' : '') + '</button>'; }).join('');
    var t = (el('posTypeFilter') || {}).value || '';
    bar.querySelector('.ps-types').innerHTML = TYPES.map(function (x) {
      return '<button type="button" class="' + (t === x[0] ? 'on' : '') + '" data-ps-type="' + esc(x[0]) + '">' + (x[0] ? '<i class="ps-dot ' + x[0].toLowerCase().replace(' ', '-') + '"></i>' : '') + x[1] + '</button>';
    }).join('');
  }

  // "All": dishes under their category; a filter or a search shows one flat grid
  function group() {
    var grid = el('posMenuItems'); if (!grid || grouping) return;
    var tiles = [].slice.call(grid.querySelectorAll('.pos-menu-item'));
    var q = ((el('posSearchInput') || {}).value || '').trim(), menu = (el('posMenuFilter') || {}).value || '';
    grouping = true;
    if (gridObs) gridObs.disconnect();   // moving tiles is itself a change: don't react to our own moves
    grid.querySelectorAll('.ps-group-h').forEach(function (h) { h.remove(); });
    if (tiles.length && !q && !menu) {
      var order = [], by = {};
      tiles.forEach(function (t) { var k = t.getAttribute('data-menu') || ''; if (!by[k]) { by[k] = []; order.push(k); } by[k].push(t); });
      // keep the restaurant's category order (the dropdown's order)
      var sel = el('posMenuFilter'), pos = {};
      if (sel) [].slice.call(sel.options).forEach(function (o, i) { pos[o.value] = i; });
      order.sort(function (a, b) { return (pos[a] != null ? pos[a] : 999) - (pos[b] != null ? pos[b] : 999); });
      order.forEach(function (k) {
        var h = document.createElement('div'); h.className = 'ps-group-h';
        h.innerHTML = '<b>' + esc(by[k][0].getAttribute('data-menu-name') || 'Other') + '</b><small>' + by[k].length + '</small>';
        grid.appendChild(h);
        by[k].forEach(function (t) { grid.appendChild(t); });
      });
    }
    grid.classList.toggle('ps-grouped', !!(tiles.length && !q && !menu));
    badges();
    if (gridObs) gridObs.takeRecords();
    grouping = false;
    if (gridObs) gridObs.observe(grid, { childList: true });
  }
  function badges() {
    var grid = el('posMenuItems'); if (!grid) return;
    grid.querySelectorAll('.pos-menu-item').forEach(function (t) {
      var q = inCart[t.getAttribute('data-item-id')] || 0, b = t.querySelector('.ps-badge');
      t.classList.toggle('ps-in', q > 0);
      if (q > 0) { if (!b) { b = document.createElement('span'); b.className = 'ps-badge'; t.appendChild(b); } b.textContent = '×' + q; }
      else if (b) b.remove();
      if (!t.querySelector('.ps-veg') && t.getAttribute('data-type')) {
        var d = document.createElement('i'); d.className = 'ps-veg ' + t.getAttribute('data-type').toLowerCase().replace(' ', '-');
        var n = t.querySelector('.item-name'); if (n) n.insertBefore(d, n.firstChild);
      }
    });
  }

  window.addEventListener('rg-pos-cart', function (e) {
    inCart = {};
    (e.detail || []).forEach(function (i) { inCart[i.id] = (inCart[i.id] || 0) + (+i.q || 0); });
    badges();
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-ps-menu],[data-ps-type]');
    if (!b || !e.target.closest('#posPage')) return;
    if (b.hasAttribute('data-ps-menu')) setSelect('posMenuFilter', b.getAttribute('data-ps-menu'));
    else setSelect('posTypeFilter', b.getAttribute('data-ps-type'));
    paintBar();
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
    var loaded = false;
    var start = function () { if (!loaded && page.classList.contains('active')) { loaded = true; fetchCounts(); } };
    new MutationObserver(start).observe(page, { attributes: true, attributeFilter: ['class'] });
    start();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
