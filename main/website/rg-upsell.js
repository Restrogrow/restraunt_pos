/*
 * "Complete your meal": ranks dishes to suggest for what's in the cart.
 * Shared by the cart page and the dashboard preview (Offers > Complete your
 * meal) so the owner sees exactly what customers see.
 *
 *   RGUpsell.rank(data, cartLines, items, opts) -> [{ item, reason, unlock }]
 *     data      getUpsell response (mode, picks, learned pairs, roles, ...)
 *     cartLines [{ id, qty }] dishes in the cart
 *     items     every dish of the menu (getMenuItems)
 *     opts      { total, goal: { gap, label } }   goal = next offer / minimum order
 *
 * Order: owner's "goes well with" > owner's "always suggest" > often ordered
 * together (real orders) > what the meal is missing (drink, dessert, side) >
 * popular. Dishes that take the cart past the next offer get a push.
 */
(function () {
  'use strict';

  var MISSING = [['drink', 'Add a drink'], ['dessert', 'Something sweet to finish'], ['side', 'Make it a meal']];

  function isVeg(it) { var t = String(it.item_type || '').toLowerCase(); return t.indexOf('non') === -1 && t.indexOf('egg') === -1; }
  function name(it) { return it.item_name_translated || it.item_name_en || it.name || ''; }
  function price(it) { return parseFloat(it.base_price || it.price || 0) || 0; }

  function rank(data, cartLines, items, opts) {
    if (!data || !items || !items.length || !cartLines || !cartLines.length) return [];
    opts = opts || {};
    var roles = data.roles || {}, byId = {}, inCart = {}, cartRoles = {}, vegCart = true, best = {};
    items.forEach(function (it) { byId[it.id] = it; });
    function role(it) { return roles[it.menu_id] || 'main'; }
    var lines = cartLines.filter(function (l) { return byId[l.id]; });
    lines.forEach(function (l) { var it = byId[l.id]; inCart[it.id] = 1; cartRoles[role(it)] = 1; if (!isVeg(it)) vegCart = false; });
    if (!lines.length) return [];
    var total = opts.total || 0;
    var cap = data.price_cap_pct > 0 ? Math.max(total * data.price_cap_pct / 100, 80) : Infinity;
    var popRank = {};
    (data.popular || []).forEach(function (id, i) { popRank[id] = i; });

    function offer(id, score, reason, auto) {
      var it = byId[id];
      if (!it || inCart[id] || it.is_available == 0 || price(it) <= 0) return;
      if (data.veg_safe && vegCart && !isVeg(it)) return;            // no non-veg for a veg order
      if (auto && (role(it) === 'none' || price(it) > cap)) return;  // smart picks stay small next to the order
      if (!best[id] || best[id].score < score) best[id] = { item: it, score: score, reason: reason, auto: auto };
    }

    if (data.mode !== 'auto') {
      lines.forEach(function (l) {
        ((data.pairs || {})[l.id] || []).forEach(function (id, k) { offer(id, 1000 - k, 'Goes well with ' + name(byId[l.id]), false); });
      });
      (data.always || []).forEach(function (id, k) { offer(id, 800 - k, 'Chef’s pick', false); });
    }
    if (data.mode !== 'manual') {
      lines.forEach(function (l) {
        ((data.together || {})[l.id] || []).forEach(function (p) { offer(p[0], 500 + p[1], 'Often ordered with ' + name(byId[l.id]), true); });
      });
      // Fill what the meal is missing (only once there's a main or a side in it)
      if (cartRoles.main || cartRoles.side) {
        MISSING.forEach(function (m, mi) {
          if (cartRoles[m[0]]) return;
          items.filter(function (it) { return role(it) === m[0]; })
            .sort(function (a, b) { return (popRank[a.id] == null ? 99 : popRank[a.id]) - (popRank[b.id] == null ? 99 : popRank[b.id]) || price(a) - price(b); })
            .slice(0, 3)
            .forEach(function (it, k) { offer(it.id, 300 - mi * 20 - k * 5, m[1], true); });
        });
      }
      (data.popular || []).forEach(function (id, k) { offer(id, 100 - k, 'Popular here', true); });
    }

    var out = Object.keys(best).map(function (k) { return best[k]; });
    // Bring the cart past the next offer / minimum order without overshooting by much
    var goal = data.goal_nudge && opts.goal && opts.goal.gap > 0 ? opts.goal : null;
    if (goal) out.forEach(function (o) {
      var p = price(o.item);
      if (p >= goal.gap && p <= Math.max(goal.gap * 2.5, goal.gap + 150)) { o.score += 250 - Math.min(100, p - goal.gap); o.unlock = goal.label; }
    });
    out.sort(function (a, b) { return b.score - a.score; });
    // Variety: at most 3 smart picks from one category
    var perCat = {}, picked = [];
    out.forEach(function (o) {
      if (picked.length >= (data.max || 6)) return;
      var c = o.item.menu_id;
      if (o.auto && (perCat[c] || 0) >= 3) return;
      perCat[c] = (perCat[c] || 0) + 1;
      picked.push({ item: o.item, reason: o.reason, unlock: o.unlock || '' });
    });
    return picked;
  }

  window.RGUpsell = { rank: rank, isVeg: isVeg };
})();
