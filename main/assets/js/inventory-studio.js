/*
 * Menu › Inventory: stock, recipes, purchase orders, suppliers, stock count, activity and reports.
 * Data: api/inventory_studio.php. Styles: iv-* in assets/css/studio-ui.css.
 * Replaces the old table view (script.js loadInventory); the section loader still calls window.loadInventory.
 */
(function () {
  'use strict';
  var API = '../api/inventory_studio.php';
  var esc = function (s) { return SX.esc(s); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var get = function (q) { return fetch(API + '?' + q, { cache: 'no-store' }).then(function (r) { return r.json(); }).catch(function () { return { success: false, message: 'No connection. Please try again.' }; }); };
  var post = function (body) { return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(function (r) { return r.json(); }).catch(function () { return { success: false, message: 'No connection. Please try again.' }; }); };
  var store = { get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} } };

  var TABS = [['overview', 'space_dashboard', 'Overview'], ['stock', 'inventory_2', 'Stock'], ['recipes', 'menu_book', 'Recipes'], ['orders', 'local_shipping', 'Purchase orders'],
    ['suppliers', 'storefront', 'Suppliers'], ['count', 'fact_check', 'Stock count'], ['activity', 'history', 'Activity'], ['reports', 'monitoring', 'Reports']];
  var VIEW_OF = { overview: 'overview', stock: 'items', recipes: 'recipes', orders: 'orders', suppliers: 'suppliers', count: 'items', activity: 'activity', reports: 'reports' };
  var S = { tab: store.get('iv-tab') || 'overview', d: null, cur: '₹', busy: false, q: '', cat: '', st: 'all', rq: '', rf: 'all', po: 'open', act: { type: '', item: '', from: '', to: '' }, days: 30, count: {}, countCat: '' };
  if (!VIEW_OF[S.tab]) S.tab = 'overview';
  var root = function () { return document.getElementById('invRoot'); };

  /* ── helpers ── */
  function money(n) { var v = Math.round((+n || 0) * 100) / 100; return S.cur + (Math.abs(v) >= 100 ? Math.round(v).toLocaleString('en-IN') : v.toLocaleString('en-IN', { maximumFractionDigits: 2 })); }
  function qty(n, u) { var v = Math.round((+n || 0) * 1000) / 1000; return v.toLocaleString('en-IN', { maximumFractionDigits: 3 }) + (u ? ' ' + u : ''); }
  function nice(n, u) {   // 0.15 kg → 150 g for small amounts
    n = +n || 0;
    if (u === 'kg' && Math.abs(n) < 1 && n !== 0) return qty(n * 1000, 'g');
    if (u === 'L' && Math.abs(n) < 1 && n !== 0) return qty(n * 1000, 'ml');
    return qty(n, u);
  }
  function daily(n, u) { n = +n || 0; if ((u === 'kg' || u === 'L') && n < 1) return Math.round(n * 1000) + (u === 'kg' ? ' g' : ' ml'); return (Math.round(n * 10) / 10).toLocaleString('en-IN') + ' ' + u; }
  function when(s) { if (!s) return ''; var d = new Date(String(s).replace(' ', 'T')); if (isNaN(d)) return s; return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) + ', ' + d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' }); }
  function day(s) { if (!s) return ''; var d = new Date(String(s).slice(0, 10) + 'T00:00'); return isNaN(d) ? s : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }); }
  function today() { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  var STATUS = { ok: ['ok', 'check_circle', 'In stock'], low: ['warn', 'trending_down', 'Low'], out: ['bad', 'block', 'Out of stock'], negative: ['bad', 'error', 'Below zero: count it'] };
  function statusPill(s) { var x = STATUS[s] || STATUS.ok; return '<span class="sx-pill ' + x[0] + '">' + ic(x[1]) + x[2] + '</span>'; }
  var TYPES = { restock: ['Purchase', 'ok', 'add_shopping_cart'], opening: ['Opening stock', 'muted', 'flag'], sale: ['Used in orders', 'wait', 'restaurant'], return: ['Back from cancelled order', 'muted', 'undo'],
    wastage: ['Wastage', 'bad', 'delete_sweep'], adjustment: ['Correction', 'warn', 'tune'], count: ['Stock count', 'warn', 'fact_check'] };
  function typePill(t) { var x = TYPES[t] || [t, 'muted', 'circle']; return '<span class="sx-pill ' + x[1] + '">' + ic(x[2]) + x[0] + '</span>'; }
  function compatUnits(u) {
    var f = { kg: ['kg', 'g'], g: ['g', 'kg'], L: ['L', 'ml'], ml: ['ml', 'L'] };
    return f[u] || [u];
  }
  function toStock(q, from, to) { var m = { kg: 1000, g: 1, L: 1000, ml: 1 }; if (from === to || !m[from] || !m[to] || ((from === 'kg' || from === 'g') !== (to === 'kg' || to === 'g'))) return q; return q * m[from] / m[to]; }
  function bar(it) {
    var cap = Math.max(it.par_level || 0, (it.low_stock_threshold || 0) * 2, it.quantity_in_stock || 0, 0.001);
    var pct = Math.max(0, Math.min(100, (it.quantity_in_stock / cap) * 100));
    return '<span class="iv-bar ' + it.status + '"><i style="width:' + pct.toFixed(1) + '%"></i>' + (it.low_stock_threshold > 0 ? '<em style="left:' + Math.min(100, it.low_stock_threshold / cap * 100).toFixed(1) + '%" title="Reorder level"></em>' : '') + '</span>';
  }
  // dishes the stock just switched off / on (POS and website follow)
  function soldNotice(av) {
    if (!av) return;
    if (av.off && av.off.length) SX.notify(av.off.join(', ') + (av.off.length === 1 ? ' is' : ' are') + ' now sold out on the POS and website (an ingredient ran out)', 'info');
    if (av.on && av.on.length) SX.notify(av.on.join(', ') + (av.on.length === 1 ? ' is' : ' are') + ' back on sale', 'success');
  }
  function emptyBox(icon, title, text, btn) { return '<div class="sx-empty">' + ic(icon) + '<b>' + title + '</b><span>' + text + '</span>' + (btn || '') + '</div>'; }
  function seg(name, cur, opts) { return '<div class="sx-seg" role="tablist">' + opts.map(function (o) { return '<button type="button" class="' + (cur === o[0] ? 'on' : '') + '" data-iv-seg="' + name + '" data-v="' + o[0] + '">' + o[1] + (o[2] != null ? ' <em>' + o[2] + '</em>' : '') + '</button>'; }).join('') + '</div>'; }
  function itemById(id) { return ((S.d && S.d.items) || []).filter(function (i) { return i.id === +id; })[0]; }

  /* ── loading ── */
  function load(keep) {
    var r = root(); if (!r) return;
    var view = VIEW_OF[S.tab], q = 'view=' + view;
    if (S.tab === 'activity') q += '&type=' + encodeURIComponent(S.act.type) + '&item=' + encodeURIComponent(S.act.item) + '&from=' + S.act.from + '&to=' + S.act.to;
    if (S.tab === 'reports') q += '&days=' + S.days;
    if (!keep) { S.d = null; paint(); }
    return get(q).then(function (d) {
      if (!d.success) { r.querySelector('.iv-body').innerHTML = emptyBox('cloud_off', 'Could not load inventory', esc(d.message || ''), '<button type="button" class="btn btn-secondary" data-iv-reload>Try again</button>'); return; }
      S.d = d; S.cur = d.currency || '₹';
      soldNotice(d.availability);
      paint();
    });
  }
  window.loadInventory = function () { load(); };
  window.InventoryStudio = { reload: function () { load(true); } };

  function paint() {
    var r = root(); if (!r) return;
    var y = window.scrollY;
    r.innerHTML = '<nav class="iv-tabs" aria-label="Inventory">' + TABS.map(function (t) { return '<button type="button" class="' + (S.tab === t[0] ? 'on' : '') + '" data-iv-tab="' + t[0] + '" title="' + t[2] + '">' + ic(t[1]) + '<span>' + t[2] + '</span></button>'; }).join('') + '</nav>' +
      '<div class="iv-body">' + (S.d ? (VIEWS[S.tab] || VIEWS.overview)() : '<div class="sx-loading"><span></span><span></span><span></span></div>') + '</div>';
    window.scrollTo(0, y);
  }

  /* ── Overview ── */
  var VIEWS = {};
  VIEWS.overview = function () {
    var d = S.d, s = d.stats, set = d.settings;
    if (!s.items) return '<div class="iv-start">' +
      '<div class="iv-start-h"><span class="sx-ic" style="--c:#2563eb">' + ic('inventory_2') + '</span><div><b>Set up your inventory</b><span>Three steps, and stock looks after itself as orders come in.</span></div></div>' +
      '<ol class="iv-steps">' +
        '<li><b>Add what you keep in stock</b><span>Paneer, oil, rice, packaging… with how much you have now.</span><button type="button" class="btn btn-primary btn-sm" data-iv-item-new>' + ic('add') + 'Add stock item</button></li>' +
        '<li><b>Add recipes to your dishes</b><span>How much of each item one plate uses. Every order then takes it out of stock.</span><button type="button" class="btn btn-secondary btn-sm" data-iv-tab="recipes">Open recipes</button></li>' +
        '<li><b>Add your suppliers</b><span>Make purchase orders and send them on WhatsApp; receiving one adds the stock.</span><button type="button" class="btn btn-secondary btn-sm" data-iv-tab="suppliers">Open suppliers</button></li>' +
      '</ol></div>';
    var tiles = [
      ['payments', '#2563eb', 'Stock value', money(s.value), s.items + ' item' + (s.items === 1 ? '' : 's')],
      ['warning', s.low + s.out ? '#dc2626' : '#16a34a', 'Needs reorder', String(s.low + s.out), s.out ? s.out + ' out of stock' : (s.low ? 'running low' : 'all good')],
      ['restaurant', '#7c3aed', 'Used by orders', money(s.used_30), 'last 30 days'],
      ['shopping_cart', '#0891b2', 'Bought', money(s.spend_month), 'this month'],
      ['delete_sweep', '#d97706', 'Wasted', money(s.waste_month), 'this month']];
    var reorder = d.reorder.slice(0, 8);
    return '<div class="lg-stats iv-stats">' + tiles.map(function (t) { return '<div class="lg-stat" style="--c:' + t[1] + '"><span class="sx-ic material-symbols-rounded">' + t[0] + '</span><div><small>' + t[2] + '</small><b>' + t[3] + '</b><span class="iv-sub">' + t[4] + '</span></div></div>'; }).join('') + '</div>' +
      autoCard(set, s) +
      '<div class="iv-grid">' +
        '<section class="iv-card"><header><h3>' + ic('shopping_cart_checkout') + 'Low stock: reorder now</h3>' + (reorder.length ? '<button type="button" class="btn btn-primary btn-sm" data-iv-po-suggest>' + ic('add') + 'Make purchase order</button>' : '') + '</header>' +
          (reorder.length ? '<div class="iv-list">' + reorder.map(function (i) {
            return '<div class="iv-row"><div class="iv-row-main"><b>' + esc(i.item_name) + '</b><small>' + esc(i.supplier_name || 'No supplier') + (i.days_left != null ? ' · about ' + i.days_left + ' day' + (i.days_left === 1 ? '' : 's') + ' left' : '') + '</small></div>' +
              '<div class="iv-row-num"><b>' + nice(i.quantity_in_stock, i.unit) + '</b>' + statusPill(i.status) + '</div>' +
              '<button type="button" class="btn btn-secondary btn-sm" data-iv-restock="' + i.id + '">' + ic('add') + 'Restock</button></div>';
          }).join('') + '</div>' : '<div class="iv-ok">' + ic('task_alt') + 'Everything is above its reorder level.</div>') + '</section>' +
        '<section class="iv-card"><header><h3>' + ic('trending_up') + 'Used most (30 days)</h3><button type="button" class="sx-link" data-iv-tab="reports">Reports' + ic('chevron_right') + '</button></header>' +
          (d.top.length ? '<div class="iv-list">' + d.top.map(function (i) {
            return '<div class="iv-row"><div class="iv-row-main"><b>' + esc(i.item_name) + '</b><small>' + daily(i.daily_use, i.unit) + ' a day</small></div><div class="iv-row-num"><b>' + nice(i.quantity_in_stock, i.unit) + '</b><small>' + (i.days_left != null ? i.days_left + ' days left' : '') + '</small></div></div>';
          }).join('') + '</div>' : '<div class="iv-ok muted">' + ic('menu_book') + 'Add recipes to your dishes to see what orders use.</div>') + '</section>' +
        (d.expiring.length ? '<section class="iv-card"><header><h3>' + ic('event_busy') + 'Use by</h3></header><div class="iv-list">' + d.expiring.map(function (i) {
          return '<div class="iv-row"><div class="iv-row-main"><b>' + esc(i.item_name) + '</b><small>' + nice(i.quantity_in_stock, i.unit) + ' in stock</small></div><span class="sx-pill ' + (i.expiry_state === 'expired' ? 'bad' : 'warn') + '">' + (i.expiry_state === 'expired' ? 'Expired ' : 'Use by ') + day(i.expiry_date) + '</span>' +
            '<button type="button" class="btn btn-secondary btn-sm" data-iv-waste="' + i.id + '">Record waste</button></div>';
        }).join('') + '</div></section>' : '') +
        '<section class="iv-card"><header><h3>' + ic('history') + 'Latest movements</h3><button type="button" class="sx-link" data-iv-tab="activity">All activity' + ic('chevron_right') + '</button></header>' +
          (d.recent.length ? '<div class="iv-list">' + d.recent.map(function (t) {
            return '<div class="iv-row"><div class="iv-row-main"><b>' + esc(t.item_name) + '</b><small>' + when(t.created_at) + (t.notes ? ' · ' + esc(t.notes) : '') + '</small></div><div class="iv-row-num"><b class="' + (+t.quantity < 0 ? 'neg' : 'pos') + '">' + (+t.quantity > 0 ? '+' : '') + nice(t.quantity, t.unit) + '</b>' + typePill(t.type) + '</div></div>';
          }).join('') + '</div>' : '<div class="iv-ok muted">' + ic('history') + 'No stock movements yet.</div>') + '</section>' +
      '</div>';
  };
  // How stock follows orders from the POS, KOT, waiters and the website, sold-out dishes and low-stock notifications
  function autoCard(set, s) {
    var on = !!+set.auto_deduct, placed = set.deduct_on === 'placed';
    var line = !s.recipe_dishes ? 'Add a recipe to a dish and every order of it, from the POS, the kitchen or your website, takes its ingredients out of stock.'
      : (on ? s.recipe_dishes + ' of ' + s.dishes + ' dishes have a recipe. Orders from the POS, waiters and your website take stock out ' + (placed ? 'as soon as they are placed' : 'once the kitchen starts them') + (set.track_from ? ' (counting since ' + day(set.track_from) + ')' : '') + '. Cancelled, rejected or deleted orders put it back.'
        : 'Switched off. Orders don’t change stock; restock and count it by hand.');
    var row = function (key, icon, color, title, text, checked) {
      return '<label class="iv-set-row"><span class="sx-ic" style="--c:' + color + '">' + ic(icon) + '</span><span class="iv-auto-t"><b>' + title + '</b><small>' + text + '</small></span>' +
        '<span class="up-switch"><input type="checkbox" data-iv-set="' + key + '"' + (checked ? ' checked' : '') + '><span></span></span></label>';
    };
    return '<section class="iv-auto' + (on ? ' on' : '') + '">' +
      row('auto_deduct', 'sync', on ? '#16a34a' : '#6b7280', 'Take stock out when orders are made', line, on) +
      (on ? '<div class="iv-when"><span>Take it out when</span>' + seg('deduct', placed ? 'placed' : 'kitchen', [['kitchen', 'The kitchen starts the order'], ['placed', 'The order is placed']]) + '</div>' : '') +
      row('auto_soldout', 'remove_shopping_cart', +set.auto_soldout ? '#dc2626' : '#6b7280', 'Sold out on POS and website when an ingredient runs out',
        'A dish whose recipe can’t be made shows as unavailable on the POS and your website, and comes back by itself when you restock. Switch a dish back on by hand and it stays on.', +set.auto_soldout) +
      row('low_alert', 'notifications_active', +set.low_alert ? '#d97706' : '#6b7280', 'Low-stock notifications',
        'When an item drops to its “Reorder when below” level or runs out, you get a notification on your phone and computer (once, until it’s restocked). Low items also show on the Dashboard.', +set.low_alert) +
    '</section>';
  }

  /* ── Stock ── */
  VIEWS.stock = function () {
    var items = S.d.items;
    if (!items.length) return emptyBox('inventory_2', 'No stock items yet', 'Add the ingredients and supplies you buy, with how much you have now.', '<button type="button" class="btn btn-primary" data-iv-item-new>' + ic('add') + 'Add stock item</button>');
    var cats = {}; items.forEach(function (i) { var c = i.category || 'Other'; cats[c] = (cats[c] || 0) + 1; });
    var n = { all: items.length, low: 0, out: 0, use: 0 };
    items.forEach(function (i) { if (i.status === 'low') n.low++; if (i.status === 'out' || i.status === 'negative') n.out++; if (i.expiry_state === 'soon' || i.expiry_state === 'expired') n.use++; });
    var q = S.q.toLowerCase();
    var list = items.filter(function (i) {
      if (S.cat && (i.category || 'Other') !== S.cat) return false;
      if (S.st === 'low' && i.status !== 'low') return false;
      if (S.st === 'out' && i.status !== 'out' && i.status !== 'negative') return false;
      if (S.st === 'use' && i.expiry_state !== 'soon' && i.expiry_state !== 'expired') return false;
      return !q || (i.item_name + ' ' + i.category + ' ' + i.sku + ' ' + (i.supplier_name || '')).toLowerCase().indexOf(q) > -1;
    });
    return '<div class="sx-toolbar"><div class="sx-search">' + ic('search') + '<input type="text" data-iv-q value="' + esc(S.q) + '" placeholder="Search items, codes or suppliers"></div>' +
        seg('st', S.st, [['all', 'All', n.all], ['low', 'Low', n.low], ['out', 'Out', n.out], ['use', 'Use by', n.use]]) +
        '<div class="sx-toolbar-r"><button type="button" class="btn btn-secondary" data-iv-csv>' + ic('download') + 'Export</button><button type="button" class="btn btn-primary" data-iv-item-new>' + ic('add') + 'Add item</button></div></div>' +
      (Object.keys(cats).length > 1 ? '<div class="sx-chips sx-chips-wrap iv-cats"><button type="button" class="' + (!S.cat ? 'on' : '') + '" data-iv-cat="">All categories</button>' + Object.keys(cats).sort().map(function (c) { return '<button type="button" class="' + (S.cat === c ? 'on' : '') + '" data-iv-cat="' + esc(c) + '">' + esc(c) + ' <em>' + cats[c] + '</em></button>'; }).join('') + '</div>' : '') +
      (list.length ? '<div class="iv-table-wrap"><table class="data-table iv-table"><thead><tr><th>Item</th><th>In stock</th><th>Reorder at</th><th>Value</th><th>Lasts</th><th></th></tr></thead><tbody>' +
        list.map(function (i) {
          return '<tr class="' + (i.status !== 'ok' ? 'iv-' + i.status : '') + '">' +
            '<td data-l="Item"><button type="button" class="iv-name" data-iv-item-edit="' + i.id + '"><b>' + esc(i.item_name) + '</b><small>' + esc([i.category, i.supplier_name, i.sku].filter(Boolean).join(' · ') || 'No category') + (i.dishes.length ? ' · in ' + i.dishes.length + ' dish' + (i.dishes.length === 1 ? '' : 'es') : '') + '</small></button></td>' +
            '<td data-l="In stock"><div class="iv-qty"><b>' + nice(i.quantity_in_stock, i.unit) + '</b>' + bar(i) + '</div>' + (i.status !== 'ok' ? statusPill(i.status) : '') + (i.expiry_state && i.expiry_state !== 'ok' ? ' <span class="sx-pill ' + (i.expiry_state === 'expired' ? 'bad' : 'warn') + '">' + ic('event_busy') + (i.expiry_state === 'expired' ? 'Expired' : 'Use by ' + day(i.expiry_date)) + '</span>' : '') + '</td>' +
            '<td data-l="Reorder at">' + (i.low_stock_threshold > 0 ? nice(i.low_stock_threshold, i.unit) : '<span class="sx-muted">Not set</span>') + '</td>' +
            '<td data-l="Value"><b>' + money(i.stock_value) + '</b><small class="iv-cell-sub">' + money(i.cost_per_unit) + ' / ' + esc(i.unit) + '</small></td>' +
            '<td data-l="Lasts">' + (i.days_left != null ? i.days_left + ' day' + (i.days_left === 1 ? '' : 's') : '<span class="sx-muted">–</span>') + '</td>' +
            '<td class="iv-acts"><button type="button" class="btn btn-secondary btn-sm" data-iv-restock="' + i.id + '">' + ic('add') + 'Restock</button>' +
              '<button type="button" class="sx-icon-btn" data-iv-setstock="' + i.id + '" title="Set stock to…">' + ic('edit_note') + '</button>' +
              '<button type="button" class="sx-icon-btn" data-iv-waste="' + i.id + '" title="Record wastage">' + ic('delete_sweep') + '</button>' +
              '<button type="button" class="sx-icon-btn" data-iv-history="' + i.id + '" title="History">' + ic('history') + '</button>' +
              '<button type="button" class="sx-icon-btn" data-iv-item-edit="' + i.id + '" title="Edit">' + ic('edit') + '</button></td></tr>';
        }).join('') + '</tbody></table></div>' : emptyBox('search_off', 'Nothing matches', 'Try another search or filter.'));
  };

  /* ── Recipes ── */
  VIEWS.recipes = function () {
    var dishes = S.d.dishes, items = S.d.items;
    var withR = dishes.filter(function (x) { return x.lines.length; });
    var pcts = withR.filter(function (x) { return x.food_cost_pct != null; });
    var avg = pcts.length ? pcts.reduce(function (a, x) { return a + x.food_cost_pct; }, 0) / pcts.length : null;
    var high = pcts.filter(function (x) { return x.food_cost_pct > 35; }).length;
    var q = S.rq.toLowerCase();
    var list = dishes.filter(function (x) {
      if (S.rf === 'with' && !x.lines.length) return false;
      if (S.rf === 'without' && x.lines.length) return false;
      if (S.rf === 'high' && !(x.food_cost_pct > 35)) return false;
      return !q || x.name.toLowerCase().indexOf(q) > -1 || x.menu.toLowerCase().indexOf(q) > -1;
    });
    var groups = {}; list.forEach(function (x) { (groups[x.menu] = groups[x.menu] || []).push(x); });
    return (!items.length ? '<div class="sx-note warn">' + ic('info') + '<span>Add your stock items first (Stock tab), then say how much of each one a dish uses.</span></div>' : '') +
      '<div class="lg-stats iv-stats">' +
        '<div class="lg-stat" style="--c:#2563eb"><span class="sx-ic material-symbols-rounded">menu_book</span><div><small>Dishes with a recipe</small><b>' + withR.length + ' / ' + dishes.length + '</b></div></div>' +
        '<div class="lg-stat" style="--c:#7c3aed"><span class="sx-ic material-symbols-rounded">percent</span><div><small>Average food cost</small><b>' + (avg != null ? avg.toFixed(1) + '%' : '–') + '</b><span class="iv-sub">of the price</span></div></div>' +
        '<div class="lg-stat" style="--c:' + (high ? '#dc2626' : '#16a34a') + '"><span class="sx-ic material-symbols-rounded">' + (high ? 'warning' : 'thumb_up') + '</span><div><small>Over 35% food cost</small><b>' + high + '</b><span class="iv-sub">' + (high ? 'check their price' : 'none') + '</span></div></div>' +
      '</div>' +
      '<div class="sx-toolbar"><div class="sx-search">' + ic('search') + '<input type="text" data-iv-rq value="' + esc(S.rq) + '" placeholder="Search dishes"></div>' +
        seg('rf', S.rf, [['all', 'All', dishes.length], ['with', 'With recipe', withR.length], ['without', 'No recipe', dishes.length - withR.length], ['high', 'High cost', high]]) + '</div>' +
      (list.length ? Object.keys(groups).map(function (g) {
        return '<div class="iv-group"><h4>' + esc(g) + '</h4><div class="iv-dishes">' + groups[g].map(function (x) {
          var cls = x.food_cost_pct == null ? '' : (x.food_cost_pct > 40 ? 'bad' : x.food_cost_pct > 30 ? 'warn' : 'ok');
          return '<button type="button" class="iv-dish' + (x.lines.length ? '' : ' none') + '" data-iv-recipe="' + x.id + '">' +
            '<span class="iv-dish-img">' + (x.image ? '<img loading="lazy" alt="" src="../api/image.php?path=' + encodeURIComponent(x.image) + '">' : ic('restaurant')) + '</span>' +
            '<span class="iv-dish-main"><b>' + esc(x.name) + (x.hidden ? ' <span class="sx-pill muted">Hidden</span>' : '') + '</b>' +
              '<small>' + (x.lines.length ? x.lines.map(function (l) { return esc(l.item_name) + ' ' + qty(l.quantity, l.unit); }).join(' · ') : 'No recipe yet: tap to add') + '</small></span>' +
            '<span class="iv-dish-nums">' + (x.lines.length ? '<span><small>Cost</small><b>' + money(x.cost) + '</b></span><span><small>Price</small><b>' + money(x.price) + '</b></span>' +
              (x.food_cost_pct != null ? '<span class="sx-pill ' + cls + '">' + x.food_cost_pct + '%</span>' : '') +
              (S.d.auto_off && S.d.auto_off.indexOf(x.id) > -1 ? '<span class="sx-pill bad">' + ic('remove_shopping_cart') + 'Sold out</span>' : (!x.available ? '<span class="sx-pill muted">Unavailable</span>' : '')) +
              '<span><small>Plates left</small><b' + (x.can_make === 0 ? ' class="neg"' : '') + '>' + (x.can_make != null ? x.can_make : '–') + '</b></span>' : '<span class="sx-link">' + ic('add') + 'Add recipe</span>') + '</span></button>';
        }).join('') + '</div></div>';
      }).join('') : emptyBox('search_off', 'Nothing matches', 'Try another search or filter.'));
  };

  /* ── Purchase orders ── */
  var PO_ST = { draft: ['Draft', 'muted'], sent: ['Ordered', 'wait'], received: ['Received', 'ok'], cancelled: ['Cancelled', 'bad'] };
  VIEWS.orders = function () {
    var all = S.d.orders, open = all.filter(function (p) { return p.status === 'draft' || p.status === 'sent'; });
    var list = S.po === 'open' ? open : S.po === 'received' ? all.filter(function (p) { return p.status === 'received'; }) : all;
    var low = S.d.items.filter(function (i) { return i.status !== 'ok'; });
    return '<div class="sx-toolbar">' + seg('po', S.po, [['open', 'Open', open.length], ['received', 'Received', all.filter(function (p) { return p.status === 'received'; }).length], ['all', 'All', all.length]]) +
        '<div class="sx-toolbar-r"><button type="button" class="btn btn-primary" data-iv-po-new>' + ic('add') + 'New purchase order</button></div></div>' +
      (low.length && S.po === 'open' ? '<div class="iv-suggest">' + ic('lightbulb') + '<span><b>' + low.length + ' item' + (low.length === 1 ? ' is' : 's are') + ' low or out.</b> Make orders for them, one per supplier, with the amounts needed to get back to their full level.</span><button type="button" class="btn btn-secondary btn-sm" data-iv-po-suggest>Make orders</button></div>' : '') +
      (list.length ? '<div class="iv-pos">' + list.map(function (p) {
        var st = PO_ST[p.status] || [p.status, 'muted'], live = p.status === 'draft' || p.status === 'sent';
        return '<article class="iv-po"><header><div><b>' + esc(p.po_number) + '</b><small>' + esc(p.supplier_name || 'No supplier') + ' · ' + day(p.created_at) + (p.expected_date && live ? ' · due ' + day(p.expected_date) : '') + (p.received_at ? ' · received ' + day(p.received_at) : '') + '</small></div><span class="sx-pill ' + st[1] + '">' + st[0] + '</span></header>' +
          '<ul>' + p.lines.map(function (l) { return '<li><span>' + esc(l.item_name) + '</span><span>' + qty(p.status === 'received' ? l.received_qty : l.quantity, l.unit) + '</span><span>' + money((p.status === 'received' ? l.received_qty : l.quantity) * l.cost_per_unit) + '</span></li>'; }).join('') + '</ul>' +
          '<footer><b>' + money(p.total) + '</b><div>' +
            (live ? '<button type="button" class="btn btn-primary btn-sm" data-iv-po-receive="' + p.id + '">' + ic('inventory') + 'Receive</button>' : '') +
            (live && p.supplier_phone ? '<a class="btn btn-secondary btn-sm" target="_blank" rel="noopener" href="' + waLink(p) + '" data-iv-po-wa="' + p.id + '">' + ic('chat') + 'WhatsApp</a>' : '') +
            (p.status === 'draft' ? '<button type="button" class="btn btn-secondary btn-sm" data-iv-po-sent="' + p.id + '">Mark ordered</button>' : '') +
            (live ? '<button type="button" class="sx-icon-btn" data-iv-po-edit="' + p.id + '" title="Edit">' + ic('edit') + '</button><button type="button" class="sx-icon-btn" data-iv-po-cancel="' + p.id + '" title="Cancel order">' + ic('close') + '</button>' : '') +
            '<button type="button" class="sx-icon-btn" data-iv-po-print="' + p.id + '" title="Print">' + ic('print') + '</button>' +
          '</div></footer></article>';
      }).join('') + '</div>' : emptyBox('local_shipping', S.po === 'open' ? 'No open purchase orders' : 'No purchase orders here', 'Make a purchase order for a supplier. When the goods arrive, receive it and the stock goes up.', '<button type="button" class="btn btn-primary" data-iv-po-new>' + ic('add') + 'New purchase order</button>'));
  };
  function poText(p) {
    var name = (document.querySelector('.sidebar-header .restaurant-name, .restaurant-name') || {}).textContent || '';
    return 'Purchase order ' + p.po_number + (name ? ' from ' + name.trim() : '') + '\n\n' + p.lines.map(function (l) { return '• ' + l.item_name + ': ' + qty(l.quantity, l.unit); }).join('\n') +
      (p.expected_date ? '\n\nNeeded by ' + day(p.expected_date) : '') + (p.notes ? '\n' + p.notes : '') + '\n\nPlease confirm. Thank you.';
  }
  function waLink(p) { var ph = String(p.supplier_phone || '').replace(/\D/g, ''); if (ph.length === 10) ph = '91' + ph; return 'https://wa.me/' + ph + '?text=' + encodeURIComponent(poText(p)); }

  /* ── Suppliers ── */
  VIEWS.suppliers = function () {
    var list = S.d.suppliers;
    return '<div class="sx-toolbar"><span class="sx-muted">' + list.length + ' supplier' + (list.length === 1 ? '' : 's') + '</span><div class="sx-toolbar-r"><button type="button" class="btn btn-primary" data-iv-sup-new>' + ic('add') + 'Add supplier</button></div></div>' +
      (list.length ? '<div class="iv-sups">' + list.map(function (s) {
        var names = s.items.map(function (id) { var i = itemById(id); return i ? i.item_name : ''; }).filter(Boolean);
        return '<article class="iv-sup"><header><span class="iv-avatar">' + esc((s.name || '?').charAt(0).toUpperCase()) + '</span><div><b>' + esc(s.name) + '</b><small>' + esc(s.contact_person || 'No contact person') + '</small></div><button type="button" class="sx-icon-btn" data-iv-sup-edit="' + s.id + '" title="Edit">' + ic('edit') + '</button></header>' +
          '<div class="iv-sup-links">' + (s.phone ? '<a href="tel:' + esc(s.phone) + '">' + ic('call') + esc(s.phone) + '</a>' : '') + (s.email ? '<a href="mailto:' + esc(s.email) + '">' + ic('mail') + esc(s.email) + '</a>' : '') + '</div>' +
          '<p>' + (names.length ? ic('inventory_2') + esc(names.slice(0, 6).join(', ')) + (names.length > 6 ? ' +' + (names.length - 6) : '') : '<span class="sx-muted">No items linked yet</span>') + '</p>' +
          '<footer><span><small>Open orders</small><b>' + s.open_po + '</b></span><span><small>Bought (90 days)</small><b>' + money(s.spend_90) + '</b></span><button type="button" class="btn btn-secondary btn-sm" data-iv-po-new-sup="' + s.id + '">' + ic('add') + 'Order</button></footer></article>';
      }).join('') + '</div>' : emptyBox('storefront', 'No suppliers yet', 'Add the people you buy from. Link items to them and purchase orders fill in by themselves.', '<button type="button" class="btn btn-primary" data-iv-sup-new>' + ic('add') + 'Add supplier</button>'));
  };

  /* ── Stock count ── */
  VIEWS.count = function () {
    var items = S.d.items;
    if (!items.length) return emptyBox('fact_check', 'Nothing to count yet', 'Add stock items first.');
    var cats = {}; items.forEach(function (i) { cats[i.category || 'Other'] = 1; });
    var list = items.filter(function (i) { return !S.countCat || (i.category || 'Other') === S.countCat; });
    var sum = countSummary();
    return '<div class="sx-note">' + ic('info') + '<span>Count what is on the shelf and type it in. Leave a box empty to skip an item. Saving makes the counted amount the stock and keeps a record of every difference.</span></div>' +
      (Object.keys(cats).length > 1 ? '<div class="sx-chips sx-chips-wrap iv-cats"><button type="button" class="' + (!S.countCat ? 'on' : '') + '" data-iv-ccat="">All</button>' + Object.keys(cats).sort().map(function (c) { return '<button type="button" class="' + (S.countCat === c ? 'on' : '') + '" data-iv-ccat="' + esc(c) + '">' + esc(c) + '</button>'; }).join('') + '</div>' : '') +
      '<div class="iv-table-wrap"><table class="data-table iv-table iv-count"><thead><tr><th>Item</th><th>System says</th><th>Counted</th><th>Difference</th></tr></thead><tbody>' +
      list.map(function (i) {
        var v = S.count[i.id];
        return '<tr><td data-l="Item"><b>' + esc(i.item_name) + '</b><small class="iv-cell-sub">' + esc(i.category || '') + '</small></td><td data-l="System says">' + qty(i.quantity_in_stock, i.unit) + '</td>' +
          '<td data-l="Counted"><span class="iv-count-in"><input type="number" step="any" min="0" inputmode="decimal" data-iv-count="' + i.id + '" value="' + (v != null ? esc(v) : '') + '" placeholder="–"><em>' + esc(i.unit) + '</em></span></td>' +
          '<td data-l="Difference" data-iv-diff="' + i.id + '">' + diffCell(i, v) + '</td></tr>';
      }).join('') + '</tbody></table></div>' +
      '<div class="sx-bulk iv-countbar"' + (sum.n ? '' : ' hidden') + '><b data-iv-count-sum>' + sumText(sum) + '</b><input type="text" data-iv-count-note placeholder="Note (optional)" maxlength="120"><button type="button" class="btn btn-secondary btn-sm" data-iv-count-clear>Clear</button><button type="button" class="btn btn-primary btn-sm" data-iv-count-save>Save count</button></div>';
  };
  function diffCell(i, v) {
    if (v == null || v === '') return '<span class="sx-muted">–</span>';
    var d = (+v) - i.quantity_in_stock;
    if (Math.abs(d) < 0.0005) return '<span class="sx-pill ok">' + ic('check') + 'Matches</span>';
    return '<b class="' + (d < 0 ? 'neg' : 'pos') + '">' + (d > 0 ? '+' : '') + nice(d, i.unit) + '</b><small class="iv-cell-sub">' + (d > 0 ? '+' : '−') + money(Math.abs(d) * i.cost_per_unit) + '</small>';
  }
  function countSummary() {
    var n = 0, v = 0;
    Object.keys(S.count).forEach(function (id) { var i = itemById(id), c = S.count[id]; if (!i || c === '' || c == null) return; var d = +c - i.quantity_in_stock; if (Math.abs(d) >= 0.0005) { n++; v += d * i.cost_per_unit; } });
    return { n: n, v: v, typed: Object.keys(S.count).filter(function (k) { return S.count[k] !== '' && S.count[k] != null; }).length };
  }
  function sumText(s) { return s.typed + ' counted · ' + s.n + ' different' + (s.n ? ' · ' + (s.v >= 0 ? '+' : '−') + money(Math.abs(s.v)) : ''); }

  /* ── Activity ── */
  VIEWS.activity = function () {
    var rows = S.d.rows, a = S.act;
    var types = [['', 'All'], ['restock,opening', 'Purchases'], ['sale,return', 'Orders'], ['wastage', 'Wastage'], ['adjustment,count', 'Corrections']];
    return '<div class="sx-toolbar">' + seg('act', a.type, types) +
        '<select data-iv-act-item><option value="">All items</option>' + S.d.items.map(function (i) { return '<option value="' + i.id + '"' + (String(a.item) === String(i.id) ? ' selected' : '') + '>' + esc(i.item_name) + '</option>'; }).join('') + '</select>' +
        '<div class="lg-daterange"><input type="date" data-iv-act-from value="' + a.from + '" aria-label="From"><span>to</span><input type="date" data-iv-act-to value="' + a.to + '" aria-label="To"></div>' +
        '<div class="sx-toolbar-r"><button type="button" class="btn btn-secondary" data-iv-act-csv>' + ic('download') + 'Export</button></div></div>' +
      (rows.length ? '<div class="iv-table-wrap"><table class="data-table iv-table"><thead><tr><th>When</th><th>Item</th><th>What</th><th>Change</th><th>Value</th><th>Note</th></tr></thead><tbody>' + rows.map(function (t) {
        return '<tr><td data-l="When">' + when(t.created_at) + '</td><td data-l="Item"><b>' + esc(t.item_name) + '</b></td><td data-l="What">' + typePill(t.type) + '</td>' +
          '<td data-l="Change"><b class="' + (+t.quantity < 0 ? 'neg' : 'pos') + '">' + (+t.quantity > 0 ? '+' : '') + nice(t.quantity, t.unit) + '</b></td><td data-l="Value">' + money(t.total_cost) + '</td>' +
          '<td data-l="Note">' + esc(t.notes || '') + (t.created_by ? '<small class="iv-cell-sub">by ' + esc(t.created_by) + '</small>' : '') + '</td></tr>';
      }).join('') + '</tbody></table></div>' + (rows.length >= 300 ? '<p class="sx-muted">Showing the latest 300. Narrow the dates to see older ones.</p>' : '') : emptyBox('history', 'No movements here', 'Purchases, orders, wastage and counts will show up here.'));
  };

  /* ── Reports ── */
  VIEWS.reports = function () {
    var d = S.d;
    var bought = d.by_day.reduce(function (a, r) { return a + (+r.bought); }, 0), wasted = d.by_day.reduce(function (a, r) { return a + (+r.wasted); }, 0);
    var tiles = [['shopping_cart', '#0891b2', 'Bought', money(bought)], ['restaurant', '#7c3aed', 'Used by orders', money(d.used_value)], ['delete_sweep', '#d97706', 'Wasted', money(wasted)],
      ['fact_check', d.count_diff < 0 ? '#dc2626' : '#16a34a', 'Count differences', (d.count_diff < 0 ? '−' : '+') + money(Math.abs(d.count_diff))],
      ['percent', '#2563eb', 'Food cost', d.food_cost_pct != null ? d.food_cost_pct + '%' : '–', d.food_cost_pct != null ? 'used ÷ sales of ' + money(d.sales) : 'needs recipes and orders']];
    return '<div class="sx-toolbar">' + seg('days', String(S.days), [['7', '7 days'], ['30', '30 days'], ['90', '90 days']]) + '</div>' +
      '<div class="lg-stats iv-stats">' + tiles.map(function (t) { return '<div class="lg-stat" style="--c:' + t[1] + '"><span class="sx-ic material-symbols-rounded">' + t[0] + '</span><div><small>' + t[2] + '</small><b>' + t[3] + '</b>' + (t[4] ? '<span class="iv-sub">' + t[4] + '</span>' : '') + '</div></div>'; }).join('') + '</div>' +
      '<section class="iv-card"><header><h3>' + ic('bar_chart') + 'Stock used by orders, per day</h3></header>' + columns(d.by_day) + '</section>' +
      '<div class="iv-grid">' +
        '<section class="iv-card"><header><h3>' + ic('restaurant') + 'Used most</h3></header>' + bars(d.usage, 'No usage yet. It shows once dishes with recipes are ordered.') + '</section>' +
        '<section class="iv-card"><header><h3>' + ic('delete_sweep') + 'Wasted most</h3></header>' + bars(d.waste, 'No wastage recorded in this period.') + '</section>' +
        '<section class="iv-card"><header><h3>' + ic('storefront') + 'Bought from</h3></header>' + bars(d.by_supplier.map(function (s) { return { item_name: s.name, value: s.value, note: s.n + ' purchase' + (+s.n === 1 ? '' : 's') }; }), 'No purchases in this period.') + '</section>' +
      '</div>';
  };
  function bars(rows, none) {
    if (!rows.length) return '<div class="iv-ok muted">' + ic('info') + none + '</div>';
    var max = Math.max.apply(null, rows.map(function (r) { return +r.value || 0; })) || 1;
    return '<div class="iv-bars">' + rows.slice(0, 10).map(function (r) {
      return '<div class="iv-hbar" title="' + esc(r.item_name) + ': ' + money(r.value) + '"><span class="iv-hbar-l"><b>' + esc(r.item_name) + '</b><small>' + (r.qty != null ? nice(r.qty, r.unit) : esc(r.note || '')) + '</small></span><span class="iv-hbar-t"><i style="width:' + Math.max(2, (+r.value || 0) / max * 100).toFixed(1) + '%"></i></span><b class="iv-hbar-v">' + money(r.value) + '</b></div>';
    }).join('') + '</div>';
  }
  function columns(rows) {
    var map = {}; rows.forEach(function (r) { map[r.d] = r; });
    var days = [], t = new Date(); t.setHours(0, 0, 0, 0);
    for (var i = S.days - 1; i >= 0; i--) { var x = new Date(t); x.setDate(t.getDate() - i); var k = x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0'); days.push({ k: k, d: x, v: map[k] ? +map[k].used : 0 }); }
    var max = Math.max.apply(null, days.map(function (x) { return x.v; }));
    if (!max) return '<div class="iv-ok muted">' + ic('info') + 'Nothing used by orders in this period yet.</div>';
    var step = Math.ceil(days.length / 8);
    return '<div class="iv-cols" style="--n:' + days.length + '">' + days.map(function (x, i) {
      var label = x.d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
      return '<div class="iv-col" tabindex="0" data-tip="' + esc(label + ': ' + money(x.v)) + '"><i style="height:' + (x.v ? Math.max(2, x.v / max * 100) : 0).toFixed(1) + '%"></i>' + (i % step === 0 ? '<span>' + label + '</span>' : '') + '</div>';
    }).join('') + '<div class="iv-cols-max">' + money(max) + '</div></div>';
  }

  /* ── modals ── */
  var M = null;   // the open modal: {kind, ...}
  function modal(html) {
    var host = document.getElementById('ivModal');
    if (!host) { host = document.createElement('div'); host.id = 'ivModal'; document.body.appendChild(host); }
    host.innerHTML = html || '';
    document.body.style.overflow = html ? 'hidden' : '';
    if (html) { var f = host.querySelector('[autofocus]'); if (f) setTimeout(function () { f.focus(); }, 60); }
  }
  function close() { M = null; modal(''); }
  function sheet(title, sub, color, icon, body, acts, wide) {
    return '<div class="sx-modal" data-iv-modal><div class="sx-sheet' + (wide ? ' iv-sheet-wide' : '') + '" role="dialog" aria-label="' + esc(title) + '">' +
      '<div class="sx-sheet-hero" style="--c:' + color + '"><span class="sx-ic">' + ic(icon) + '</span><div><b>' + esc(title) + '</b><small>' + sub + '</small></div><button type="button" class="iv-x" data-iv-close aria-label="Close">' + ic('close') + '</button></div>' +
      '<div class="sx-form">' + body + '</div><div class="sx-sheet-acts">' + acts + '</div></div></div>';
  }
  function unitOpts(cur) { return (S.d.units || ['kg', 'g', 'L', 'ml', 'pcs', 'unit']).map(function (u) { return '<option' + (u === cur ? ' selected' : '') + '>' + u + '</option>'; }).join(''); }
  function supOpts(cur) { return '<option value="">No supplier</option>' + ((S.d && S.d.suppliers) || []).map(function (s) { return '<option value="' + s.id + '"' + (+cur === s.id ? ' selected' : '') + '>' + esc(s.name) + '</option>'; }).join(''); }
  // modals need items + suppliers: fetch them when the current tab didn't
  function need() { if (S.d && S.d.items && S.d.suppliers) return Promise.resolve(); return get('view=items').then(function (d) { if (d.success && S.d) { S.d.items = S.d.items || d.items; S.d.suppliers = S.d.suppliers || d.suppliers; S.d.units = d.units; } }); }

  function itemModal(id) {
    var it = id ? itemById(id) : null, e = it || { unit: 'kg', cost_per_unit: '', low_stock_threshold: '', par_level: '' };
    M = { kind: 'item', id: id || 0 };
    var cats = {}; (S.d.items || []).forEach(function (i) { if (i.category) cats[i.category] = 1; });
    ['Vegetables', 'Dairy', 'Meat', 'Grocery', 'Spices', 'Beverages', 'Packaging', 'Cleaning'].forEach(function (c) { cats[c] = 1; });
    modal(sheet(id ? 'Edit stock item' : 'New stock item', id ? esc(it.item_name) + ' · ' + nice(it.quantity_in_stock, it.unit) + ' in stock' : 'Something you buy and keep in stock', '#2563eb', 'inventory_2',
      '<label class="sx-lbl">Name<input id="ivName" maxlength="255" value="' + esc(e.item_name || '') + '" placeholder="e.g. Paneer" autofocus></label>' +
      '<div class="iv-2"><label class="sx-lbl">Measured in<select id="ivUnit">' + unitOpts(e.unit) + '</select></label>' +
        '<label class="sx-lbl">Category<input id="ivCat" list="ivCats" maxlength="100" value="' + esc(e.category || '') + '" placeholder="e.g. Dairy"><datalist id="ivCats">' + Object.keys(cats).sort().map(function (c) { return '<option value="' + esc(c) + '">'; }).join('') + '</datalist></label></div>' +
      '<div class="iv-2"><label class="sx-lbl"><span>Cost per <span data-iv-u>' + esc(e.unit) + '</span></span><input id="ivCost" type="number" step="any" min="0" value="' + (e.cost_per_unit !== '' ? e.cost_per_unit : '') + '" placeholder="0"></label>' +
        (!id ? '<label class="sx-lbl">In stock now<input id="ivOpen" type="number" step="any" min="0" placeholder="0"></label>' : '<label class="sx-lbl">Supplier<select id="ivSup">' + supOpts(e.supplier_id) + '</select></label>') + '</div>' +
      '<div class="iv-2"><label class="sx-lbl"><span>Reorder when below <small class="iv-hint">low-stock alert</small></span><input id="ivLow" type="number" step="any" min="0" value="' + (e.low_stock_threshold || '') + '" placeholder="e.g. 2"></label>' +
        '<label class="sx-lbl">Order up to<input id="ivPar" type="number" step="any" min="0" value="' + (e.par_level || '') + '" placeholder="e.g. 10"></label></div>' +
      (!id ? '<label class="sx-lbl">Supplier<select id="ivSup">' + supOpts(e.supplier_id) + '</select></label>' : '') +
      '<details class="iv-more"' + (e.sku || e.expiry_date || e.notes ? ' open' : '') + '><summary>More details</summary><div class="iv-2"><label class="sx-lbl">Item code (SKU)<input id="ivSku" maxlength="60" value="' + esc(e.sku || '') + '"></label>' +
        '<label class="sx-lbl">Use by<input id="ivExp" type="date" value="' + esc(e.expiry_date || '') + '"></label></div><label class="sx-lbl">Notes<input id="ivNotes" maxlength="255" value="' + esc(e.notes || '') + '"></label></details>' +
      (id && it.dishes.length ? '<div class="sx-note">' + ic('menu_book') + '<span>Used in ' + esc(it.dishes.join(', ')) + '.</span></div>' : '') +
      (!id ? '<div class="sx-note">' + ic('info') + '<span>“In stock now” is what you already have; it isn’t added as a purchase. Use Restock for new deliveries: they are added to your expenses.</span></div>' : '') +
      (id ? '<div class="sx-danger-zone"><button type="button" class="sx-link sx-link-danger" data-iv-item-del="' + id + '">' + ic('delete') + 'Remove this item</button></div>' : ''),
      '<button type="button" class="btn btn-secondary" data-iv-close>Cancel</button><button type="button" class="btn btn-primary" data-iv-item-save>' + (id ? 'Save changes' : 'Add item') + '</button>'));
  }
  function restockModal(id) {
    var it = itemById(id); if (!it) return;
    M = { kind: 'restock', id: id };
    modal(sheet('Restock ' + it.item_name, 'Now ' + nice(it.quantity_in_stock, it.unit) + (it.supplier_name ? ' · from ' + esc(it.supplier_name) : ''), '#16a34a', 'add_shopping_cart',
      '<div class="iv-2"><label class="sx-lbl">How much came in (' + esc(it.unit) + ')<input id="ivRsQty" type="number" step="any" min="0" autofocus placeholder="0"' + (it.reorder_qty ? ' value="' + it.reorder_qty + '"' : '') + '></label>' +
        '<label class="sx-lbl">Cost per ' + esc(it.unit) + '<input id="ivRsCost" type="number" step="any" min="0" value="' + it.cost_per_unit + '"></label></div>' +
      '<div class="iv-2"><label class="sx-lbl">Date<input id="ivRsDate" type="date" value="' + today() + '" max="' + today() + '"></label><label class="sx-lbl">Use by (optional)<input id="ivRsExp" type="date"></label></div>' +
      '<label class="sx-lbl">Note<input id="ivRsNote" maxlength="200" placeholder="Supplier, bill number…"></label>' +
      '<div class="iv-calc" data-iv-calc></div>',
      '<button type="button" class="btn btn-secondary" data-iv-close>Cancel</button><button type="button" class="btn btn-primary" data-iv-restock-save>Add stock</button>'));
    calc();
  }
  function calc() {
    var box = document.querySelector('[data-iv-calc]'); if (!box || !M) return;
    var it = itemById(M.id), q = +((document.getElementById('ivRsQty') || {}).value || 0), c = +((document.getElementById('ivRsCost') || {}).value || 0);
    box.innerHTML = q > 0 ? '<span>New stock <b>' + nice(it.quantity_in_stock + q, it.unit) + '</b></span><span>Purchase <b>' + money(q * c) + '</b></span>' : '<span class="sx-muted">The purchase is added to your expenses and Reports.</span>';
  }
  var REASONS = ['Spoiled', 'Expired', 'Spilled or dropped', 'Burnt', 'Staff meal', 'Returned to supplier'];
  function wasteModal(id) {
    var it = itemById(id); if (!it) return;
    M = { kind: 'waste', id: id, mode: 'wastage', reason: '' };
    paintWaste();
  }
  function paintWaste() {
    var it = itemById(M.id), w = M.mode === 'wastage', set = M.mode === 'set';
    var label = w ? 'How much was lost (' + esc(it.unit) + ')' : set ? 'How much you have now (' + esc(it.unit) + ')' : 'Change (' + esc(it.unit) + '): use a minus for less, e.g. −2';
    var note = w ? 'Wastage lowers stock and is added to expenses as a loss.' : set ? 'The amount you type becomes the stock, whatever orders took out. The difference is kept in Activity as a correction (not an expense).' : 'A correction adds or removes an amount, e.g. +2 found or −1 miscounted. It isn’t an expense. For the whole shelf use the Stock count tab.';
    modal(sheet(it.item_name, 'Now ' + nice(it.quantity_in_stock, it.unit) + ' in stock', w ? '#d97706' : '#6b7280', w ? 'delete_sweep' : set ? 'edit' : 'tune',
      seg('wmode', M.mode, [['wastage', 'Wastage'], ['set', 'Set stock to'], ['adjustment', 'Add / remove']]) +
      (w ? '<div class="sx-lbl">Why</div><div class="sx-chips sx-chips-wrap">' + REASONS.map(function (r) { return '<button type="button" class="' + (M.reason === r ? 'on' : '') + '" data-iv-reason="' + esc(r) + '">' + esc(r) + '</button>'; }).join('') + '</div>' : '') +
      '<label class="sx-lbl">' + label + '<input id="wsQty" type="number" step="any" ' + (w || set ? 'min="0" ' : '') + 'autofocus placeholder="' + (set ? esc(qty(Math.max(0, it.quantity_in_stock))) : '0') + '"></label>' +
      '<label class="sx-lbl">Note<input id="wsNote" maxlength="200"></label>' +
      '<div class="sx-note' + (w ? ' warn' : '') + '">' + ic('info') + '<span>' + note + '</span></div>',
      '<button type="button" class="btn btn-secondary" data-iv-close>Cancel</button><button type="button" class="btn btn-primary" data-iv-waste-save>' + (w ? 'Record wastage' : set ? 'Set stock' : 'Save') + '</button>'));
  }

  function recipeModal(id) {
    var x = S.d.dishes.filter(function (z) { return z.id === +id; })[0]; if (!x) return;
    M = { kind: 'recipe', id: x.id, lines: x.lines.map(function (l) { return { inventory_item_id: l.inventory_item_id, quantity: l.quantity, unit: l.unit }; }) };
    if (!M.lines.length) M.lines.push({ inventory_item_id: '', quantity: '', unit: '' });
    paintRecipe();
  }
  function paintRecipe() {
    var x = S.d.dishes.filter(function (z) { return z.id === M.id; })[0], items = S.d.items;
    var cost = 0;
    var rows = M.lines.map(function (l, n) {
      var it = itemById(l.inventory_item_id), units = it ? compatUnits(it.unit) : [''];
      if (it && units.indexOf(l.unit) < 0) l.unit = units[units.length > 1 && (it.unit === 'kg' || it.unit === 'L') ? 1 : 0] || it.unit;
      if (it && units.indexOf(l.unit) < 0) l.unit = it.unit;
      var c = it && +l.quantity > 0 ? toStock(+l.quantity, l.unit, it.unit) * it.cost_per_unit : 0; cost += c;
      return '<div class="iv-rline"><select data-iv-rl-item="' + n + '"><option value="">Choose item…</option>' + items.map(function (i) { return '<option value="' + i.id + '"' + (+l.inventory_item_id === i.id ? ' selected' : '') + '>' + esc(i.item_name) + '</option>'; }).join('') + '</select>' +
        '<input type="number" step="any" min="0" data-iv-rl-qty="' + n + '" value="' + esc(l.quantity) + '" placeholder="Amount">' +
        '<select data-iv-rl-unit="' + n + '"' + (it ? '' : ' disabled') + '>' + units.map(function (u) { return '<option' + (u === l.unit ? ' selected' : '') + '>' + esc(u) + '</option>'; }).join('') + '</select>' +
        '<span class="iv-rline-c">' + (c ? money(c) : '') + '</span><button type="button" class="sx-icon-btn" data-iv-rl-del="' + n + '" title="Remove">' + ic('close') + '</button></div>';
    }).join('');
    var pct = x.price > 0 && cost > 0 ? cost / x.price * 100 : null;
    modal(sheet('Recipe: ' + x.name, 'What one plate uses · price ' + money(x.price), '#7c3aed', 'menu_book',
      (items.length ? '<div class="iv-rlines">' + rows + '</div><button type="button" class="sx-link" data-iv-rl-add>' + ic('add') + 'Add ingredient</button>' :
        '<div class="sx-note warn">' + ic('info') + '<span>You have no stock items yet. Add them in the Stock tab first.</span></div>') +
      '<div class="iv-calc"><span>Cost per plate <b>' + money(cost) + '</b></span><span>Food cost <b class="' + (pct == null ? '' : pct > 40 ? 'neg' : pct > 30 ? 'warn' : 'pos') + '">' + (pct != null ? pct.toFixed(1) + '%' : '–') + '</b></span><span>You keep <b>' + money(Math.max(0, x.price - cost)) + '</b></span></div>' +
      '<div class="sx-note">' + ic('info') + '<span>Each order of this dish takes these amounts out of stock once the kitchen starts it. Many restaurants aim for a food cost of 25–35%.</span></div>',
      '<button type="button" class="btn btn-secondary" data-iv-close>Cancel</button><button type="button" class="btn btn-primary" data-iv-recipe-save>Save recipe</button>', true));
  }

  function poModal(po, supplierId, lines) {
    M = { kind: 'po', id: po ? po.id : 0, supplier_id: po ? po.supplier_id : (supplierId || ''), expected: po ? (po.expected_date || '') : '', notes: po ? po.notes : '',
      lines: po ? po.lines.map(function (l) { return { inventory_item_id: l.inventory_item_id, quantity: l.quantity, cost_per_unit: l.cost_per_unit }; }) : (lines || []) };
    if (!M.lines.length) {
      // a supplier's low items first
      S.d.items.filter(function (i) { return supplierId && i.supplier_id === +supplierId && i.status !== 'ok'; }).forEach(function (i) { M.lines.push({ inventory_item_id: i.id, quantity: i.reorder_qty || '', cost_per_unit: i.cost_per_unit }); });
      if (!M.lines.length) M.lines.push({ inventory_item_id: '', quantity: '', cost_per_unit: '' });
    }
    paintPo();
  }
  function paintPo() {
    var items = S.d.items, total = 0;
    var rows = M.lines.map(function (l, n) {
      var it = itemById(l.inventory_item_id), t = (+l.quantity || 0) * (+l.cost_per_unit || 0); total += t;
      return '<div class="iv-rline iv-poline"><select data-iv-pl-item="' + n + '"><option value="">Choose item…</option>' + items.map(function (i) { return '<option value="' + i.id + '"' + (+l.inventory_item_id === i.id ? ' selected' : '') + '>' + esc(i.item_name) + '</option>'; }).join('') + '</select>' +
        '<span class="iv-in-u"><input type="number" step="any" min="0" data-iv-pl-qty="' + n + '" value="' + esc(l.quantity) + '" placeholder="Qty"><em>' + esc(it ? it.unit : '') + '</em></span>' +
        '<span class="iv-in-u"><input type="number" step="any" min="0" data-iv-pl-cost="' + n + '" value="' + esc(l.cost_per_unit) + '" placeholder="Rate"><em>/' + esc(it ? it.unit : '') + '</em></span>' +
        '<span class="iv-rline-c">' + (t ? money(t) : '') + '</span><button type="button" class="sx-icon-btn" data-iv-pl-del="' + n + '" title="Remove">' + ic('close') + '</button></div>';
    }).join('');
    var low = items.filter(function (i) { return i.status !== 'ok' && (!M.supplier_id || i.supplier_id === +M.supplier_id) && !M.lines.some(function (l) { return +l.inventory_item_id === i.id; }); });
    modal(sheet(M.id ? 'Edit purchase order' : 'New purchase order', 'What you’re ordering from a supplier', '#0891b2', 'local_shipping',
      '<div class="iv-2"><label class="sx-lbl">Supplier<select id="poSup" data-iv-po-sup>' + supOpts(M.supplier_id) + '</select></label><label class="sx-lbl">Needed by<input id="poExp" type="date" value="' + esc(M.expected) + '"></label></div>' +
      '<div class="sx-lbl">Items</div><div class="iv-rlines">' + rows + '</div>' +
      '<div class="iv-po-adds"><button type="button" class="sx-link" data-iv-pl-add>' + ic('add') + 'Add item</button>' + (low.length ? '<button type="button" class="sx-link" data-iv-pl-low>' + ic('bolt') + 'Add ' + low.length + ' low item' + (low.length === 1 ? '' : 's') + '</button>' : '') + '</div>' +
      '<label class="sx-lbl">Note for the supplier<input id="poNotes" maxlength="255" value="' + esc(M.notes || '') + '" placeholder="e.g. Deliver before 10 am"></label>' +
      '<div class="iv-calc"><span>Total <b>' + money(total) + '</b></span></div>',
      '<button type="button" class="btn btn-secondary" data-iv-close>Cancel</button><button type="button" class="btn btn-primary" data-iv-po-save>Save order</button>', true));
  }
  function readPo() {
    var sup = document.getElementById('poSup'), exp = document.getElementById('poExp'), notes = document.getElementById('poNotes');
    if (sup) M.supplier_id = sup.value; if (exp) M.expected = exp.value; if (notes) M.notes = notes.value;
  }
  function receiveModal(po) {
    M = { kind: 'receive', id: po.id, po: po };
    modal(sheet('Receive ' + po.po_number, esc(po.supplier_name || 'No supplier') + ' · check what actually came', '#16a34a', 'inventory',
      '<div class="iv-rlines">' + po.lines.map(function (l) {
        return '<div class="iv-rline iv-recline"><span class="iv-rec-name"><b>' + esc(l.item_name) + '</b><small>ordered ' + qty(l.quantity, l.unit) + '</small></span>' +
          '<span class="iv-in-u"><input type="number" step="any" min="0" data-iv-rc-qty="' + l.id + '" value="' + l.quantity + '"><em>' + esc(l.unit) + '</em></span>' +
          '<span class="iv-in-u"><input type="number" step="any" min="0" data-iv-rc-cost="' + l.id + '" value="' + l.cost_per_unit + '"><em>/' + esc(l.unit) + '</em></span></div>';
      }).join('') + '</div>' +
      '<label class="sx-lbl">Received on<input id="rcDate" type="date" value="' + today() + '" max="' + today() + '"></label>' +
      '<div class="sx-note">' + ic('info') + '<span>Each item is added to stock and the bill is added to your expenses. Set an amount to 0 if it didn’t come.</span></div>',
      '<button type="button" class="btn btn-secondary" data-iv-close>Cancel</button><button type="button" class="btn btn-primary" data-iv-rc-save>Add to stock</button>', true));
  }
  function supModal(s) {
    M = { kind: 'sup', id: s ? s.id : 0 };
    var linked = s ? s.items : [];
    modal(sheet(s ? 'Edit supplier' : 'New supplier', 'Someone you buy stock from', '#0891b2', 'storefront',
      '<label class="sx-lbl">Name<input id="spName" maxlength="150" value="' + esc(s ? s.name : '') + '" placeholder="e.g. Fresh Farms Dairy" autofocus></label>' +
      '<div class="iv-2"><label class="sx-lbl">Contact person<input id="spPerson" maxlength="100" value="' + esc(s ? s.contact_person : '') + '"></label><label class="sx-lbl">Phone (WhatsApp)<input id="spPhone" type="tel" maxlength="20" value="' + esc(s ? s.phone : '') + '"></label></div>' +
      '<label class="sx-lbl">Email<input id="spEmail" type="email" maxlength="150" value="' + esc(s ? s.email : '') + '"></label>' +
      '<label class="sx-lbl">Notes<input id="spNotes" maxlength="255" value="' + esc(s ? s.notes : '') + '" placeholder="Delivery days, payment terms…"></label>' +
      (S.d.items.length ? '<div class="sx-lbl">Items you buy from them</div><div class="iv-checks">' + S.d.items.map(function (i) {
        var other = i.supplier_id && (!s || i.supplier_id !== s.id);
        return '<label><input type="checkbox" data-iv-sp-item="' + i.id + '"' + (linked.indexOf(i.id) > -1 ? ' checked' : '') + '><span>' + esc(i.item_name) + (other ? '<small>now ' + esc(i.supplier_name || '') + '</small>' : '') + '</span></label>';
      }).join('') + '</div>' : '') +
      (s ? '<div class="sx-danger-zone"><button type="button" class="sx-link sx-link-danger" data-iv-sup-del="' + s.id + '">' + ic('delete') + 'Remove supplier</button></div>' : ''),
      '<button type="button" class="btn btn-secondary" data-iv-close>Cancel</button><button type="button" class="btn btn-primary" data-iv-sup-save>' + (s ? 'Save changes' : 'Add supplier') + '</button>'));
  }

  function save(body, btn, done) {
    if (btn) { btn.disabled = true; btn.dataset.t = btn.innerHTML; btn.textContent = 'Saving…'; }
    return post(body).then(function (r) {
      if (btn) { btn.disabled = false; btn.innerHTML = btn.dataset.t; }
      if (!r.success) { SX.notify(r.message || 'Could not save', 'error'); return; }
      if (r.message) SX.notify(r.message, 'success');
      if (done) done(r);
      close(); load(true);
    });
  }
  function csv(name, rows) {
    var s = rows.map(function (r) { return r.map(function (c) { c = String(c == null ? '' : c); return /[",\n]/.test(c) ? '"' + c.replace(/"/g, '""') + '"' : c; }).join(','); }).join('\n');
    var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + s], { type: 'text/csv' })); a.download = name; document.body.appendChild(a); a.click(); a.remove();
  }
  function printPo(p) {
    var w = window.open('', '_blank'); if (!w) return;
    w.document.write('<!doctype html><title>' + esc(p.po_number) + '</title><style>body{font:14px system-ui,sans-serif;margin:32px;color:#111}h1{font-size:20px;margin:0 0 4px}table{width:100%;border-collapse:collapse;margin-top:18px}td,th{padding:8px;border-bottom:1px solid #ddd;text-align:left}th:last-child,td:last-child{text-align:right}.t{font-weight:700}</style>' +
      '<h1>Purchase order ' + esc(p.po_number) + '</h1><div>' + esc(p.supplier_name || '') + (p.supplier_phone ? ' · ' + esc(p.supplier_phone) : '') + '</div><div>Date: ' + day(p.created_at) + (p.expected_date ? ' · Needed by ' + day(p.expected_date) : '') + '</div>' +
      '<table><tr><th>Item</th><th>Quantity</th><th>Rate</th><th>Amount</th></tr>' + p.lines.map(function (l) { return '<tr><td>' + esc(l.item_name) + '</td><td>' + qty(l.quantity, l.unit) + '</td><td>' + money(l.cost_per_unit) + '</td><td>' + money(l.quantity * l.cost_per_unit) + '</td></tr>'; }).join('') +
      '<tr class="t"><td colspan="3">Total</td><td>' + money(p.total) + '</td></tr></table>' + (p.notes ? '<p>' + esc(p.notes) + '</p>' : '') + '<script>print()<\/script>');
    w.document.close();
  }
  function suggestOrders() {
    // one draft per supplier for everything low or out
    var low = S.d.items.filter(function (i) { return i.status !== 'ok'; }), by = {};
    low.forEach(function (i) { var k = i.supplier_id || 0; (by[k] = by[k] || []).push(i); });
    var keys = Object.keys(by);
    if (keys.length === 1) { poModal(null, +keys[0] || '', by[keys[0]].map(function (i) { return { inventory_item_id: i.id, quantity: i.reorder_qty || '', cost_per_unit: i.cost_per_unit }; })); return; }
    Promise.all(keys.map(function (k) { return post({ action: 'po_save', supplier_id: +k || 0, lines: by[k].map(function (i) { return { inventory_item_id: i.id, quantity: i.reorder_qty || i.low_stock_threshold || 1, cost_per_unit: i.cost_per_unit }; }) }); }))
      .then(function (rs) { var ok = rs.filter(function (r) { return r.success; }).length; SX.notify(ok + ' draft purchase order' + (ok === 1 ? '' : 's') + ' made. Check the amounts, then send them.', 'success'); S.tab = 'orders'; S.po = 'open'; store.set('iv-tab', S.tab); load(); });
  }

  /* ── events ── */
  document.addEventListener('click', function (e) {
    if (!e.target.closest('#inventoryPage') && !e.target.closest('#ivModal')) return;
    var t = e.target.closest('[data-iv-tab],[data-iv-seg],[data-iv-cat],[data-iv-ccat],[data-iv-reload],[data-iv-close],[data-iv-item-new],[data-iv-item-edit],[data-iv-item-save],[data-iv-item-del],[data-iv-restock],[data-iv-restock-save],[data-iv-waste],[data-iv-setstock],[data-iv-waste-save],[data-iv-reason],[data-iv-history],[data-iv-csv],[data-iv-act-csv],[data-iv-recipe],[data-iv-recipe-save],[data-iv-rl-add],[data-iv-rl-del],[data-iv-po-new],[data-iv-po-new-sup],[data-iv-po-edit],[data-iv-po-save],[data-iv-po-sent],[data-iv-po-cancel],[data-iv-po-receive],[data-iv-po-print],[data-iv-po-suggest],[data-iv-pl-add],[data-iv-pl-del],[data-iv-pl-low],[data-iv-rc-save],[data-iv-sup-new],[data-iv-sup-edit],[data-iv-sup-save],[data-iv-sup-del],[data-iv-count-save],[data-iv-count-clear],[data-iv-modal]');
    if (!t) return;
    var a = t.dataset;
    if (a.ivModal !== undefined) { if (e.target === t) close(); return; }
    if (a.ivTab) { S.tab = a.ivTab; store.set('iv-tab', S.tab); close(); load(); return; }
    if (a.ivReload !== undefined) { load(); return; }
    if (a.ivClose !== undefined) { close(); return; }
    if (a.ivSeg) {
      var v = a.v;
      if (a.ivSeg === 'st') { S.st = v; paint(); }
      else if (a.ivSeg === 'rf') { S.rf = v; paint(); }
      else if (a.ivSeg === 'po') { S.po = v; paint(); }
      else if (a.ivSeg === 'act') { S.act.type = v; load(true); }
      else if (a.ivSeg === 'days') { S.days = +v; load(true); }
      else if (a.ivSeg === 'wmode') { M.mode = v; paintWaste(); }
      else if (a.ivSeg === 'deduct') { post({ action: 'settings_save', deduct_on: v }).then(function (r) { if (r.success) { SX.notify(v === 'placed' ? 'Stock now goes out as soon as an order is placed' : 'Stock now goes out when the kitchen starts an order', 'success'); load(true); } }); }
      return;
    }
    if (a.ivCat !== undefined) { S.cat = a.ivCat; paint(); return; }
    if (a.ivCcat !== undefined) { S.countCat = a.ivCcat; paint(); return; }
    if (a.ivItemNew !== undefined) { need().then(function () { itemModal(0); }); return; }
    if (a.ivItemEdit) { need().then(function () { itemModal(+a.ivItemEdit); }); return; }
    if (a.ivItemSave !== undefined) {
      var g = function (id) { var el = document.getElementById(id); return el ? el.value : ''; };
      save({ action: 'item_save', id: M.id, item_name: g('ivName').trim(), unit: g('ivUnit'), category: g('ivCat'), cost_per_unit: g('ivCost'), opening_stock: g('ivOpen'), supplier_id: g('ivSup'),
        low_stock_threshold: g('ivLow'), par_level: g('ivPar'), sku: g('ivSku'), expiry_date: g('ivExp'), notes: g('ivNotes') }, t);
      return;
    }
    if (a.ivItemDel) {
      // goes at once with Undo for a few seconds (assets/js/rg-undo.js); its history is kept either way
      var delId = +a.ivItemDel, nm = document.getElementById('ivName');
      close();
      rgUndo({
        html: rgUndo.label('Removed', nm ? nm.value.trim() : ''),
        hide: rgUndo.rows('[data-iv-item-edit="' + delId + '"]').map(function (b) { return b.closest('tr, .iv-row') || b; }),
        request: { url: API, json: { action: 'item_delete', id: delId } },
        onDone: function () { load(true); },
        onError: function (msg) { SX.notify(msg, 'error'); load(true); }
      });
      return;
    }
    if (a.ivRestock) { need().then(function () { restockModal(+a.ivRestock); }); return; }
    if (a.ivRestockSave !== undefined) {
      save({ action: 'restock', id: M.id, quantity: document.getElementById('ivRsQty').value, cost_per_unit: document.getElementById('ivRsCost').value, date: document.getElementById('ivRsDate').value,
        expiry_date: document.getElementById('ivRsExp').value, notes: document.getElementById('ivRsNote').value }, t); return;
    }
    if (a.ivWaste) { need().then(function () { wasteModal(+a.ivWaste); }); return; }
    if (a.ivSetstock) { need().then(function () { wasteModal(+a.ivSetstock); M.mode = 'set'; paintWaste(); }); return; }
    if (a.ivReason) { M.reason = M.reason === a.ivReason ? '' : a.ivReason; var keepQ = document.getElementById('wsQty').value, keepN = document.getElementById('wsNote').value; paintWaste(); document.getElementById('wsQty').value = keepQ; document.getElementById('wsNote').value = keepN; return; }
    if (a.ivWasteSave !== undefined) { save({ action: 'adjust', id: M.id, kind: M.mode, quantity: document.getElementById('wsQty').value, reason: M.mode === 'wastage' ? M.reason : '', notes: document.getElementById('wsNote').value }, t); return; }
    if (a.ivHistory) { S.act = { type: '', item: a.ivHistory, from: '', to: '' }; S.tab = 'activity'; store.set('iv-tab', S.tab); load(); return; }
    if (a.ivCsv !== undefined) {
      csv('stock-' + today() + '.csv', [['Item', 'Category', 'Code', 'Unit', 'In stock', 'Reorder below', 'Order up to', 'Cost per unit', 'Stock value', 'Supplier', 'Use by', 'Status']].concat(S.d.items.map(function (i) {
        return [i.item_name, i.category, i.sku, i.unit, i.quantity_in_stock, i.low_stock_threshold, i.par_level, i.cost_per_unit, i.stock_value, i.supplier_name || '', i.expiry_date || '', (STATUS[i.status] || [])[2]];
      }))); return;
    }
    if (a.ivActCsv !== undefined) {
      csv('stock-activity-' + today() + '.csv', [['When', 'Item', 'What', 'Change', 'Unit', 'Value', 'Note', 'By']].concat(S.d.rows.map(function (r) { return [r.created_at, r.item_name, (TYPES[r.type] || [r.type])[0], r.quantity, r.unit, r.total_cost, r.notes, r.created_by]; }))); return;
    }
    if (a.ivRecipe) { recipeModal(+a.ivRecipe); return; }
    if (a.ivRlAdd !== undefined) { M.lines.push({ inventory_item_id: '', quantity: '', unit: '' }); paintRecipe(); return; }
    if (a.ivRlDel !== undefined) { M.lines.splice(+a.ivRlDel, 1); if (!M.lines.length) M.lines.push({ inventory_item_id: '', quantity: '', unit: '' }); paintRecipe(); return; }
    if (a.ivRecipeSave !== undefined) { save({ action: 'recipe_save', menu_item_id: M.id, lines: M.lines.filter(function (l) { return l.inventory_item_id && +l.quantity > 0; }) }, t); return; }
    if (a.ivPoNew !== undefined) { need().then(function () { poModal(null); }); return; }
    if (a.ivPoNewSup) { need().then(function () { poModal(null, +a.ivPoNewSup); }); return; }
    if (a.ivPoEdit) { poModal(S.d.orders.filter(function (p) { return p.id === +a.ivPoEdit; })[0]); return; }
    if (a.ivPoSuggest !== undefined) { need().then(suggestOrders); return; }
    if (a.ivPlAdd !== undefined) { readPo(); M.lines.push({ inventory_item_id: '', quantity: '', cost_per_unit: '' }); paintPo(); return; }
    if (a.ivPlDel !== undefined) { readPo(); M.lines.splice(+a.ivPlDel, 1); if (!M.lines.length) M.lines.push({ inventory_item_id: '', quantity: '', cost_per_unit: '' }); paintPo(); return; }
    if (a.ivPlLow !== undefined) {
      readPo(); M.lines = M.lines.filter(function (l) { return l.inventory_item_id; });
      S.d.items.filter(function (i) { return i.status !== 'ok' && (!M.supplier_id || i.supplier_id === +M.supplier_id) && !M.lines.some(function (l) { return +l.inventory_item_id === i.id; }); })
        .forEach(function (i) { M.lines.push({ inventory_item_id: i.id, quantity: i.reorder_qty || '', cost_per_unit: i.cost_per_unit }); });
      paintPo(); return;
    }
    if (a.ivPoSave !== undefined) { readPo(); save({ action: 'po_save', id: M.id, supplier_id: M.supplier_id, expected_date: M.expected, notes: M.notes, lines: M.lines }, t, function () { S.tab = 'orders'; store.set('iv-tab', S.tab); }); return; }
    if (a.ivPoSent) { save({ action: 'po_status', id: +a.ivPoSent, status: 'sent' }, t); return; }
    if (a.ivPoCancel) { if (t.dataset.ask !== '1') { t.dataset.ask = '1'; t.title = 'Click again to cancel this order'; t.classList.add('iv-ask'); SX.notify('Click the × again to cancel this purchase order', 'info'); return; } save({ action: 'po_status', id: +a.ivPoCancel, status: 'cancelled' }, t); return; }
    if (a.ivPoReceive) { receiveModal(S.d.orders.filter(function (p) { return p.id === +a.ivPoReceive; })[0]); return; }
    if (a.ivPoPrint) { printPo(S.d.orders.filter(function (p) { return p.id === +a.ivPoPrint; })[0]); return; }
    if (a.ivRcSave !== undefined) {
      save({ action: 'po_receive', id: M.id, date: document.getElementById('rcDate').value, lines: M.po.lines.map(function (l) { return { id: l.id, received_qty: document.querySelector('[data-iv-rc-qty="' + l.id + '"]').value, cost_per_unit: document.querySelector('[data-iv-rc-cost="' + l.id + '"]').value }; }) }, t); return;
    }
    if (a.ivSupNew !== undefined) { need().then(function () { supModal(null); }); return; }
    if (a.ivSupEdit) { supModal(S.d.suppliers.filter(function (s) { return s.id === +a.ivSupEdit; })[0]); return; }
    if (a.ivSupSave !== undefined) {
      var ids = [].map.call(document.querySelectorAll('[data-iv-sp-item]:checked'), function (c) { return +c.dataset.ivSpItem; });
      save({ action: 'supplier_save', id: M.id, name: document.getElementById('spName').value.trim(), contact_person: document.getElementById('spPerson').value, phone: document.getElementById('spPhone').value,
        email: document.getElementById('spEmail').value, notes: document.getElementById('spNotes').value, item_ids: document.querySelector('[data-iv-sp-item]') ? ids : undefined }, t); return;
    }
    if (a.ivSupDel) { if (t.dataset.ask !== '1') { t.dataset.ask = '1'; t.innerHTML = ic('delete') + 'Click again to remove'; return; } save({ action: 'supplier_delete', id: +a.ivSupDel }, t); return; }
    if (a.ivCountClear !== undefined) { S.count = {}; paint(); return; }
    if (a.ivCountSave !== undefined) {
      var note = (document.querySelector('[data-iv-count-note]') || {}).value || '';
      save({ action: 'count', note: note, lines: Object.keys(S.count).map(function (k) { return { id: +k, counted: S.count[k] }; }) }, t, function () { S.count = {}; }); return;
    }
  });
  document.addEventListener('input', function (e) {
    var t = e.target;
    if (t.matches('[data-iv-q]')) { S.q = t.value; var pos = t.selectionStart; paint(); var n = document.querySelector('[data-iv-q]'); n.focus(); n.setSelectionRange(pos, pos); return; }
    if (t.matches('[data-iv-rq]')) { S.rq = t.value; var p2 = t.selectionStart; paint(); var n2 = document.querySelector('[data-iv-rq]'); n2.focus(); n2.setSelectionRange(p2, p2); return; }
    if (t.id === 'ivRsQty' || t.id === 'ivRsCost') { calc(); return; }
    if (t.id === 'ivUnit') { var u = document.querySelector('[data-iv-u]'); if (u) u.textContent = t.value; return; }
    if (t.matches('[data-iv-count]')) {
      S.count[t.dataset.ivCount] = t.value;
      var it = itemById(t.dataset.ivCount), cell = document.querySelector('[data-iv-diff="' + t.dataset.ivCount + '"]');
      if (cell && it) cell.innerHTML = diffCell(it, t.value);
      var s = countSummary(), bar2 = document.querySelector('.iv-countbar');
      if (bar2) { bar2.hidden = !s.typed; bar2.querySelector('[data-iv-count-sum]').textContent = sumText(s); }
      return;
    }
    if (M && M.kind === 'recipe' && t.matches('[data-iv-rl-qty]')) { M.lines[+t.dataset.ivRlQty].quantity = t.value; repaintKeep(paintRecipe, t); return; }
    if (M && M.kind === 'po' && (t.matches('[data-iv-pl-qty]') || t.matches('[data-iv-pl-cost]'))) { readPo(); var k = t.matches('[data-iv-pl-qty]') ? 'quantity' : 'cost_per_unit'; M.lines[+(t.dataset.ivPlQty || t.dataset.ivPlCost)][k] = t.value; repaintKeep(paintPo, t); return; }
  });
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.matches('[data-iv-set]')) {
      var key = t.dataset.ivSet, body = { action: 'settings_save' }; body[key] = t.checked ? 1 : 0;
      var said = { auto_deduct: ['Orders now take stock out', 'Orders no longer change stock'], auto_soldout: ['Dishes now show sold out when an ingredient runs out', 'Dishes no longer go sold out by themselves'], low_alert: ['Low-stock notifications on', 'Low-stock notifications off'] }[key];
      post(body).then(function (r) {
        if (!r.success) { SX.notify(r.message || 'Could not save', 'error'); t.checked = !t.checked; return; }
        SX.notify(said[t.checked ? 0 : 1], 'success'); soldNotice(r.availability); load(true);
      });
      return;
    }
    if (t.matches('[data-iv-act-item]')) { S.act.item = t.value; load(true); return; }
    if (t.matches('[data-iv-act-from]')) { S.act.from = t.value; load(true); return; }
    if (t.matches('[data-iv-act-to]')) { S.act.to = t.value; load(true); return; }
    if (!M) return;
    if (M.kind === 'recipe' && t.matches('[data-iv-rl-item]')) { var l = M.lines[+t.dataset.ivRlItem]; l.inventory_item_id = t.value; l.unit = ''; paintRecipe(); return; }
    if (M.kind === 'recipe' && t.matches('[data-iv-rl-unit]')) { M.lines[+t.dataset.ivRlUnit].unit = t.value; paintRecipe(); return; }
    if (M.kind === 'po' && t.matches('[data-iv-pl-item]')) { readPo(); var pl = M.lines[+t.dataset.ivPlItem], it2 = itemById(t.value); pl.inventory_item_id = t.value; if (it2) { pl.cost_per_unit = it2.cost_per_unit; if (!pl.quantity && it2.reorder_qty) pl.quantity = it2.reorder_qty; } paintPo(); return; }
    if (M.kind === 'po' && t.matches('[data-iv-po-sup]')) { readPo(); paintPo(); return; }
  });
  // repaint a modal while typing without losing the caret
  function repaintKeep(fn, t) {
    var attrs = ['data-iv-rl-qty', 'data-iv-pl-qty', 'data-iv-pl-cost'], sel = null;
    attrs.forEach(function (x) { if (t.hasAttribute(x)) sel = '[' + x + '="' + t.getAttribute(x) + '"]'; });
    var pos = t.selectionStart; fn();
    var n = sel && document.querySelector(sel); if (n) { n.focus(); try { n.setSelectionRange(pos, pos); } catch (er) {} }
  }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && M) close(); });
  // leaving Inventory (menu link, tabs above it) closes any open pop-up
  document.addEventListener('click', function (e) { if (M && e.target.closest('.nav-link, [data-ms-go], [data-page]')) close(); }, true);
  // chart tooltip
  document.addEventListener('mouseover', function (e) {
    var c = e.target.closest && e.target.closest('.iv-col'); var tip = document.getElementById('ivTip');
    if (!c) { if (tip) tip.hidden = true; return; }
    if (!tip) { tip = document.createElement('div'); tip.id = 'ivTip'; tip.className = 'iv-tip'; document.body.appendChild(tip); }
    var r = c.getBoundingClientRect(); tip.textContent = c.dataset.tip; tip.hidden = false;
    tip.style.left = Math.min(window.innerWidth - tip.offsetWidth - 8, Math.max(8, r.left + r.width / 2 - tip.offsetWidth / 2)) + 'px'; tip.style.top = (r.top - tip.offsetHeight - 8 + window.scrollY) + 'px';
  });
})();
