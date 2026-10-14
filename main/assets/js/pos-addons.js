/*
 * Add-ons in the POS (Menu › Add-ons, table meal_addons), the same two kinds as the website:
 *  - on a dish: "+ Add-on" under a cart line opens a picker; the add-ons are charged once per unit
 *    of that dish. With 2 or more on the line you choose "all of them" or "just one" (the line splits).
 *  - on the order: an "Add-ons" section at the end of the dishes adds them as their own cart lines.
 * script.js totals lines with window.posLineTotal() and sends dishes and order add-ons separately
 * (window.posCartPayload); controllers/pos_operations.php checks every add-on price and the subtotal
 * and saves them on the kitchen ticket, the order and held orders.
 * Styles pa-* in assets/css/studio-ui.css.
 */
(function () {
  'use strict';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var money = function (n) { n = Number(n || 0); return (window.globalCurrencySymbol || '₹') + n.toLocaleString('en-IN', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 }); };
  var list = [], loaded = false, loading = null;

  function api() { return window.rgPosCartApi; }
  function load(force) {
    if (loading && !force) return loading;
    loading = fetch('../api/get_addons.php?available=1', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      list = ((d && d.data) || []).map(function (a) { return { id: +a.id, name: a.addon_name, price: parseFloat(a.addon_price) || 0, image: a.addon_image || '' }; });
      loaded = true;
      if (api()) api().refresh();   // cart lines get their "+ Add-on" button
      if (window.rgPosRegroup) window.rgPosRegroup();
      return list;
    }).catch(function () { return list; });
    return loading;
  }

  function baseKey(item) { return String(item.cartKey || item.id).replace(/_a[\d-]+$/, ''); }
  function keyFor(item) { var ids = (item.addons || []).map(function (a) { return a.id; }).sort(function (x, y) { return x - y; }); return baseKey(item) + (ids.length ? '_a' + ids.join('-') : ''); }
  // after a line's add-ons change: give it its key, and join it with an identical line
  function settle(cart, item) {
    item.cartKey = keyFor(item);
    var twin = cart.filter(function (c) { return c !== item && (c.cartKey || String(c.id)) === item.cartKey; })[0];
    if (twin) { twin.quantity += item.quantity; cart.splice(cart.indexOf(item), 1); }
  }

  /* ── under each cart line ── */
  function lineHtml(item, key) {
    if (item.isAddon) return '';
    var has = (item.addons || []).length;
    if (!has && !list.length) return '';
    return '<div class="pa-line">' + (item.addons || []).map(function (a) {
      return '<span class="pa-chip">+ ' + esc(a.name) + ' <b>' + money(a.price) + '</b><button type="button" data-pa-rm="' + esc(key) + '" data-pa-id="' + a.id + '" aria-label="Remove ' + esc(a.name) + '">' + ic('close') + '</button></span>';
    }).join('') + (list.length ? '<button type="button" class="pa-add" data-pa-add="' + esc(key) + '">' + ic('add') + (has ? 'Change' : 'Add-on') + '</button>' : '') + '</div>';
  }

  function picker(key) {
    var cart = api().get(), item = cart.filter(function (c) { return (c.cartKey || String(c.id)) === key; })[0];
    if (!item) return;
    var on = {}; (item.addons || []).forEach(function (a) { on[a.id] = 1; });
    var html = '<div class="pa-pick">' + list.map(function (a) {
      return '<label class="pa-opt"><input type="checkbox" value="' + a.id + '"' + (on[a.id] ? ' checked' : '') + '><span>' + esc(a.name) + '</span><b>+' + money(a.price) + '</b></label>';
    }).join('') + '</div>' +
      (item.quantity > 1 ? '<div class="pa-scope"><label><input type="radio" name="paScope" value="all" checked> All ' + item.quantity + '</label><label><input type="radio" name="paScope" value="one"> Just one</label></div>' : '') +
      '<p class="pa-note">Charged for each ' + esc(item.originalName || item.name) + ' they go with.</p>';
    window.rgModal({ title: 'Add-ons for ' + (item.originalName || item.name), icon: 'add_circle', html: html, confirmText: 'Save', onConfirm: function (card) {
      var picked = [].slice.call(card.querySelectorAll('.pa-pick input:checked')).map(function (i) { return list.filter(function (a) { return a.id === +i.value; })[0]; }).filter(Boolean)
        .map(function (a) { return { id: a.id, name: a.name, price: a.price }; });
      var scope = (card.querySelector('input[name=paScope]:checked') || {}).value || 'all';
      if (scope === 'one' && item.quantity > 1) {
        item.quantity -= 1;
        var copy = Object.assign({}, item, { quantity: 1, addons: picked });
        cart.push(copy);
        settle(cart, copy);
      } else {
        item.addons = picked;
        settle(cart, item);
      }
      api().refresh();
      return true;
    } });
  }

  /* ── "Add-ons" section in the dishes ── */
  function inCart(id) { var c = (api() ? api().get() : []).filter(function (x) { return x.isAddon && x.addonId === id; })[0]; return c ? c.quantity : 0; }
  function addToOrder(a) {
    var cart = api().get(), line = cart.filter(function (x) { return x.isAddon && x.addonId === a.id; })[0];
    if (line) line.quantity += 1;
    else cart.push({ id: 'addon-' + a.id, isAddon: true, addonId: a.id, cartKey: 'addon_' + a.id, name: a.name, originalName: a.name, price: a.price, image: null, quantity: 1 });
    api().refresh();
  }
  // called by pos-studio.js after it groups the dishes (grouped: the "All" view)
  function renderTiles(grid, q, grouped, typeFilter) {
    if (!list.length || typeFilter) return;
    var show = q ? list.filter(function (a) { return a.name.toLowerCase().indexOf(q.toLowerCase()) > -1; }) : (grouped ? list : []);
    if (!show.length) return;
    if (grouped) {
      var h = document.createElement('div'); h.className = 'ps-group-h'; h.setAttribute('data-group', '__addons');
      h.innerHTML = '<b>Add-ons</b><small>' + show.length + ' extra' + (show.length === 1 ? '' : 's') + ' for the order</small>';
      grid.appendChild(h);
    } else {
      var empty = grid.querySelector('.empty-state'); if (empty) empty.remove();
    }
    show.forEach(function (a) {
      var t = document.createElement('button');
      t.type = 'button'; t.className = 'pa-tile'; t.setAttribute('data-pa-tile', a.id);
      t.innerHTML = '<span class="pa-ic">' + ic('add_circle') + '</span><span class="pa-t"><b>' + esc(a.name) + '</b><small>Add-on</small></span><span class="pa-p">' + money(a.price) + '</span><span class="pa-n"></span>';
      grid.appendChild(t);
    });
    counts();
  }
  function counts() {
    document.querySelectorAll('#posMenuItems .pa-tile').forEach(function (t) {
      var n = inCart(+t.getAttribute('data-pa-tile')), b = t.querySelector('.pa-n');
      t.classList.toggle('on', n > 0); b.textContent = n > 0 ? '×' + n : '';
    });
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('#posPage')) return;
    var b = e.target.closest('[data-pa-add]');
    if (b) { e.preventDefault(); picker(b.getAttribute('data-pa-add')); return; }
    var r = e.target.closest('[data-pa-rm]');
    if (r) {
      var cart = api().get(), item = cart.filter(function (c) { return (c.cartKey || String(c.id)) === r.getAttribute('data-pa-rm'); })[0];
      if (item) { item.addons = (item.addons || []).filter(function (a) { return a.id !== +r.getAttribute('data-pa-id'); }); settle(cart, item); api().refresh(); }
      return;
    }
    var t = e.target.closest('[data-pa-tile]');
    if (t) { var a = list.filter(function (x) { return x.id === +t.getAttribute('data-pa-tile'); })[0]; if (a) addToOrder(a); }
  });
  window.addEventListener('rg-pos-cart', counts);

  window.rgPosAddons = { load: load, list: function () { return list; }, lineHtml: lineHtml, renderTiles: renderTiles };

  // load when the POS opens (and again each time, so new add-ons show up)
  function init() {
    var page = document.getElementById('posPage'); if (!page) return;
    var was = false;
    var check = function () { var now = page.classList.contains('active'); if (now && !was) load(true); was = now; };
    new MutationObserver(check).observe(page, { attributes: true, attributeFilter: ['class'] });
    check();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
