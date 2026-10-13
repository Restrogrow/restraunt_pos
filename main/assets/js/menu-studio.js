/*
 * Menu section look (UI only; menus, dishes and their saving are unchanged in script.js):
 *  - tabs across Category, Menu items, Add-ons and Inventory
 *  - Category cards show how many dishes each has
 *  - Menu items: numbers at the top (dishes, in stock, out of stock, hidden) and a search box
 * Styles: ms-* in assets/css/studio-ui.css.
 */
(function () {
  'use strict';
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var TABS = [['menuPage', 'category', 'Categories', 'Groups on your menu'], ['menuItemsPage', 'restaurant_menu', 'Menu items', 'Dishes, prices and photos'],
    ['addonsPage', 'playlist_add', 'Add-ons', 'Extra cheese, dips and more'], ['inventoryPage', 'inventory_2', 'Inventory', 'Stock, recipes and suppliers']];
  var items = null, loading = null;

  function addTabs() {
    TABS.forEach(function (t) {
      var page = document.getElementById(t[0]);
      if (!page || page.querySelector('.ms-tabs')) return;
      var nav = document.createElement('nav');
      nav.className = 'ms-tabs';
      nav.setAttribute('aria-label', 'Menu sections');
      nav.innerHTML = TABS.map(function (x) {
        return '<button type="button" data-ms-go="' + x[0] + '" class="' + (x[0] === t[0] ? 'on' : '') + '"' + (x[0] === t[0] ? ' aria-current="page"' : '') + '>' + ic(x[1]) + '<span><b>' + x[2] + '</b><small>' + x[3] + '</small></span></button>';
      }).join('');
      var content = page.querySelector('.page-content');
      if (content) content.parentNode.insertBefore(nav, content);
    });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-ms-go]');
    if (!b) return;
    var link = document.querySelector('.nav-link[data-page="' + b.getAttribute('data-ms-go') + '"]');
    if (link) link.click();
  });

  function fetchItems(force) {
    if (items && !force) return Promise.resolve(items);
    if (loading && !force) return loading;
    var page = function (n) { return fetch('../api/get_menu_items.php?limit=200&page=' + n, { cache: 'no-store' }).then(function (r) { return r.json(); }); };
    // every page (the API returns at most 200 dishes at a time)
    loading = page(1).then(function (d) {
      var all = (d && (d.data || d.items)) || [], more = [];
      for (var n = 2; n <= Math.min(+(d && d.total_pages) || 1, 20); n++) more.push(page(n));
      return Promise.all(more).then(function (rs) { rs.forEach(function (x) { all = all.concat((x && (x.data || x.items)) || []); }); return all; });
    }).then(function (all) { items = all; loading = null; return items; }).catch(function () { loading = null; return items || []; });
    return loading;
  }

  /* Category cards: dish count */
  function decorateCategories() {
    var list = document.getElementById('menuList');
    if (!list || !list.querySelector('.menu-card[data-menu-id]')) return;
    fetchItems().then(function (all) {
      var n = {};
      all.forEach(function (i) { n[i.menu_id] = (n[i.menu_id] || 0) + 1; });
      list.querySelectorAll('.menu-card[data-menu-id]').forEach(function (c) {
        if (c.querySelector('.ms-count')) return;
        var k = n[c.getAttribute('data-menu-id')] || 0, h = c.querySelector('h3');
        if (!h) return;
        var s = document.createElement('span');
        s.className = 'ms-count' + (k ? '' : ' none');
        s.textContent = k ? k + (k === 1 ? ' dish' : ' dishes') : 'No dishes yet';
        h.insertAdjacentElement('afterend', s);
      });
    });
  }

  /* Menu items: numbers + search */
  function itemsBar() {
    var page = document.getElementById('menuItemsPage'), acts = page && page.querySelector('.menu-items-actions');
    if (!acts || page.querySelector('.ms-stats')) return;
    var stats = document.createElement('div');
    stats.className = 'ms-stats';
    acts.parentNode.insertBefore(stats, acts);
    var search = document.createElement('div');
    search.className = 'sx-search ms-search';
    search.innerHTML = ic('search') + '<input type="text" id="msItemSearch" placeholder="Search dishes" autocomplete="off">';
    var filters = acts.querySelector('.filters');
    acts.insertBefore(search, filters || null);
  }
  function paintStats() {
    var box = document.querySelector('#menuItemsPage .ms-stats');
    if (!box) return;
    fetchItems().then(function (all) {
      var on = 0, off = 0, hid = 0;
      all.forEach(function (i) { if (String(i.is_hidden) === '1') hid++; else if (String(i.is_available) === '0') off++; else on++; });
      var t = [['restaurant_menu', '#2563eb', 'Dishes', all.length], ['check_circle', '#16a34a', 'In stock', on], ['remove_shopping_cart', '#dc2626', 'Out of stock', off], ['visibility_off', '#6b7280', 'Hidden', hid]];
      box.innerHTML = t.map(function (x) { return '<div class="lg-stat" style="--c:' + x[1] + '"><span class="sx-ic material-symbols-rounded">' + x[0] + '</span><div><small>' + x[2] + '</small><b>' + x[3] + '</b></div></div>'; }).join('');
    });
  }
  function applySearch() {
    var inp = document.getElementById('msItemSearch'), list = document.getElementById('menuItemsList');
    if (!inp || !list) return;
    var q = inp.value.trim().toLowerCase(), shown = 0;
    list.querySelectorAll('.menu-item-card').forEach(function (c) {
      var h = c.querySelector('h3'), hit = !q || (h && h.textContent.toLowerCase().indexOf(q) > -1) || c.textContent.toLowerCase().indexOf(q) > -1;
      c.classList.toggle('ms-off', !hit);
      if (hit) shown++;
    });
    var none = list.querySelector('.ms-none');
    if (q && !shown) { if (!none) { none = document.createElement('div'); none.className = 'ms-none sx-empty'; list.appendChild(none); } none.innerHTML = ic('search_off') + '<b>No dish matches “' + SX.esc(inp.value.trim()) + '”</b><span>Check the spelling, or clear the filters above.</span>'; }
    else if (none) none.remove();
  }
  document.addEventListener('input', function (e) { if (e.target.id === 'msItemSearch') applySearch(); });

  function watch(id, fn) {
    var el = document.getElementById(id);
    if (!el) return;
    var t = null;
    new MutationObserver(function (m) {
      if (m.every(function (x) { return [].every.call(x.addedNodes, function (n) { return n.nodeType === 1 && (n.classList.contains('ms-count') || n.classList.contains('ms-none')); }); })) return;
      clearTimeout(t); t = setTimeout(fn, 60);
    }).observe(el, { childList: true });
  }

  function init() {
    addTabs();
    itemsBar();
    // the lists re-render after any add / edit / delete: refresh the counts with them
    watch('menuList', function () { fetchItems(true).then(decorateCategories); });
    watch('menuItemsList', function () { fetchItems(true).then(paintStats); applySearch(); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
