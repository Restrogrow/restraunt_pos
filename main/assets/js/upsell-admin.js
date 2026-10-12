/*
 * Offers > Complete your meal (admin)
 *
 * Suggestions on the cart page to raise the order value: smart picks from real
 * orders and the parts missing from the meal (Auto), the owner's own picks
 * (Manual), or both. A test cart shows exactly what customers would see
 * (same ranking as the cart: ../website/rg-upsell.js). Saved through
 * ../api/upsell.php.
 */
(function () {
  'use strict';

  var API = '../api/upsell.php';
  var ROLES = [['main', 'Main'], ['side', 'Side'], ['drink', 'Drink'], ['dessert', 'Dessert'], ['none', 'Never suggest']];
  var st = null;            // everything loaded from the API, plus edits
  var testCart = [];        // dish ids in the preview cart

  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function notify(msg, type) {
    if (typeof window.showNotification === 'function') window.showNotification(msg, type);
  }
  function cur() { return window.globalCurrencySymbol || '₹'; }
  function item(id) { for (var i = 0; i < st.items.length; i++) if (st.items[i].id === +id) return st.items[i]; return null; }
  function imgUrl(img) {
    if (!img || img === 'no-image') return '';
    if (/^https?:\/\//.test(img)) return img;
    return '../website/image.php?id=' + encodeURIComponent(img.indexOf('db:') === 0 ? img.substring(3) : img);
  }
  function dishSelect(cls, placeholder, skip) {
    var html = '<select class="' + cls + '"><option value="">' + placeholder + '</option>', cat = null;
    st.items.forEach(function (it) {
      if (skip && skip.indexOf(it.id) !== -1) return;
      if (it.category !== cat) { if (cat !== null) html += '</optgroup>'; cat = it.category; html += '<optgroup label="' + esc(cat) + '">'; }
      html += '<option value="' + it.id + '">' + esc(it.item_name_en) + ' · ' + cur() + Math.round(it.base_price) + '</option>';
    });
    return html + (cat !== null ? '</optgroup>' : '') + '</select>';
  }
  function chips(ids, list) {
    return ids.map(function (id, k) {
      var it = item(id);
      return it ? '<span class="up-chip">' + esc(it.item_name_en) + '<button type="button" class="up-chip-x" data-list="' + list + '" data-k="' + k + '" aria-label="Remove">&times;</button></span>' : '';
    }).join('');
  }

  function render() {
    var box = $('upBody');
    if (!box || !st) return;
    var s = st.settings, m = st.manual, mode = s.mode;
    var stats = st.stats, rate = stats.views ? Math.round(stats.adds / stats.views * 100) : 0;
    var pairs = Object.keys(m.pairs).map(Number);

    // Each pair once (A + B and B + A), with the stronger of the two numbers
    var learned = [], seenPair = {};
    Object.keys(st.learned.together || {}).forEach(function (x) {
      st.learned.together[x].forEach(function (p) {
        var a = item(x), b = item(p[0]), key = Math.min(+x, p[0]) + '-' + Math.max(+x, p[0]);
        if (!a || !b) return;
        if (seenPair[key]) { if (seenPair[key].pct < p[1]) { seenPair[key].a = a; seenPair[key].b = b; seenPair[key].pct = p[1]; } return; }
        learned.push(seenPair[key] = { a: a, b: b, pct: p[1] });
      });
    });
    learned.sort(function (p, q) { return q.pct - p.pct; });

    box.innerHTML =
      '<div class="up-stats">' +
        '<div><b>' + stats.views + '</b><span>Times shown</span></div>' +
        '<div><b>' + stats.adds + '</b><span>Dishes added</span></div>' +
        '<div><b>' + rate + '%</b><span>Add rate</span></div>' +
        '<div class="hi"><b>' + cur() + Math.round(stats.added).toLocaleString() + '</b><span>Extra sales</span></div>' +
        '<small>Last 30 days</small>' +
      '</div>' +

      '<div class="up-card">' +
        '<label class="up-switch"><input type="checkbox" id="upEnabled"' + (s.enabled ? ' checked' : '') + '><span></span> Show “Complete your meal” on the cart page</label>' +
        '<div class="up-modes">' +
          [['auto', 'auto_awesome', 'Auto', 'Smart picks from real orders, plus a drink, dessert or side the meal is missing'],
           ['manual', 'touch_app', 'Manual', 'Only the dishes you choose'],
           ['both', 'join_inner', 'Both', 'Your picks first, smart picks fill the rest']].map(function (o) {
            return '<button type="button" class="up-mode' + (mode === o[0] ? ' on' : '') + '" data-mode="' + o[0] + '">' +
              '<span class="material-symbols-rounded">' + o[1] + '</span><b>' + o[2] + (o[0] === 'both' ? ' <em>Recommended</em>' : '') + '</b><small>' + o[3] + '</small></button>';
          }).join('') +
        '</div>' +
        '<div class="up-grid">' +
          '<label>Heading<input id="upTitle" maxlength="60" placeholder="Complete your meal" value="' + esc(s.title) + '"></label>' +
          '<label>Dishes to show<select id="upMax">' + [3, 4, 5, 6, 8, 10].map(function (n) { return '<option' + (s.max_items === n ? ' selected' : '') + '>' + n + '</option>'; }).join('') + '</select></label>' +
          '<label>Smart picks up to<select id="upCap">' + [[25, '25% of the cart'], [40, '40% of the cart'], [50, '50% of the cart'], [75, '75% of the cart'], [0, 'Any price']].map(function (o) { return '<option value="' + o[0] + '"' + (s.price_cap_pct === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></label>' +
        '</div>' +
        '<label class="up-check"><input type="checkbox" id="upVeg"' + (s.veg_safe ? ' checked' : '') + '> Veg orders only get veg suggestions</label>' +
        '<label class="up-check"><input type="checkbox" id="upGoal"' + (s.goal_nudge ? ' checked' : '') + '> Push dishes that unlock the next offer or the minimum order (“Unlocks 10% OFF”)</label>' +
      '</div>' +

      '<div class="up-card' + (mode === 'auto' ? ' off' : '') + '">' +
        '<h3><span class="material-symbols-rounded">touch_app</span> Your picks</h3>' +
        '<div class="up-sub">Always suggest <small>shown for any cart</small></div>' +
        '<div class="up-chips">' + chips(m.always, 'always') + dishSelect('up-add-always', '+ Add a dish', m.always) + '</div>' +
        '<div class="up-sub" style="margin-top:14px">Goes well with <small>when this dish is in the cart, suggest these</small></div>' +
        pairs.map(function (x) {
          var it = item(x);
          if (!it) return '';
          return '<div class="up-pair"><b>' + esc(it.item_name_en) + '</b><span class="material-symbols-rounded">arrow_forward</span>' +
            '<div class="up-chips">' + chips(m.pairs[x], 'p' + x) + dishSelect('up-add-pair', '+ Add', m.pairs[x].concat([x])).replace('<select', '<select data-x="' + x + '"') + '</div>' +
            '<button type="button" class="up-pair-x" data-x="' + x + '" title="Remove">&times;</button></div>';
        }).join('') +
        dishSelect('up-new-pair', '+ Pick a dish to pair', pairs) +
      '</div>' +

      '<div class="up-card' + (mode === 'manual' ? ' off' : '') + '">' +
        '<h3><span class="material-symbols-rounded">auto_awesome</span> Smart picks</h3>' +
        '<div class="up-sub">What each category is <small>so the cart can offer what the meal is missing</small></div>' +
        '<div class="up-roles">' + st.categories.map(function (c) {
          return '<label>' + esc(c.name) + '<select data-cat="' + c.id + '">' + ROLES.map(function (r) { return '<option value="' + r[0] + '"' + (c.role === r[0] ? ' selected' : '') + '>' + r[1] + '</option>'; }).join('') + '</select></label>';
        }).join('') + '</div>' +
        '<div class="up-sub" style="margin-top:14px">Often ordered together <small>from your last 90 days (' + st.learned.orders + ' orders)</small>' +
          '<button type="button" class="up-link" id="upRelearn"><span class="material-symbols-rounded">refresh</span> Update now</button></div>' +
        (learned.length
          ? '<div class="up-learned">' + learned.slice(0, 12).map(function (p) {
              return '<div><span>' + esc(p.a.item_name_en) + '</span><span class="material-symbols-rounded">add</span><span>' + esc(p.b.item_name_en) + '</span><em>' + p.pct + '% of its orders</em></div>';
            }).join('') + '</div>'
          : '<div class="up-empty">Not enough orders together yet. Until then the cart offers what the meal is missing and your popular dishes.</div>') +
      '</div>' +

      '<div class="up-card up-preview">' +
        '<h3><span class="material-symbols-rounded">visibility</span> Try it</h3>' +
        '<div class="up-sub">Test cart <small>what a customer with these dishes sees (unsaved changes included)</small></div>' +
        '<div class="up-chips">' + chips(testCart, 'test') + dishSelect('up-add-test', '+ Add to test cart', testCart) + '</div>' +
        '<div id="upPreview"></div>' +
      '</div>';
    preview();
  }

  // The same data the cart gets, built from what's on screen
  function formData() {
    var s = st.settings, roles = {};
    st.categories.forEach(function (c) { roles[c.id] = c.role; });
    var useManual = s.mode !== 'auto', useAuto = s.mode !== 'manual';
    return { mode: s.mode, title: s.title, max: s.max_items, price_cap_pct: s.price_cap_pct, veg_safe: s.veg_safe, goal_nudge: s.goal_nudge, roles: roles,
             always: useManual ? st.manual.always : [], pairs: useManual ? st.manual.pairs : {},
             together: useAuto ? st.learned.together : {}, popular: useAuto ? st.learned.popular : [] };
  }

  function preview() {
    var box = $('upPreview');
    if (!box) return;
    if (!testCart.length) { box.innerHTML = '<div class="up-empty">Add a dish or two above to see the suggestions.</div>'; return; }
    var total = testCart.reduce(function (t, id) { var it = item(id); return t + (it ? it.base_price : 0); }, 0);
    var list = window.RGUpsell ? window.RGUpsell.rank(formData(), testCart.map(function (id) { return { id: id, qty: 1 }; }), st.items, { total: total }) : [];
    if (!st.settings.enabled) { box.innerHTML = '<div class="up-empty">Turned off: customers don’t see this section.</div>'; return; }
    box.innerHTML = '<div class="up-pv-head">' + esc(st.settings.title || 'Complete your meal') + ' <small>cart ' + cur() + Math.round(total) + '</small></div>' +
      (list.length ? '<div class="up-pv">' + list.map(function (o) {
        var src = imgUrl(o.item.item_image);
        return '<div class="up-pv-card">' + (src ? '<img src="' + esc(src) + '" alt="">' : '<div class="ph"></div>') +
          '<b>' + esc(o.item.item_name_en) + '</b><span class="why">' + esc(o.reason) + '</span><span class="pr">' + cur() + Math.round(o.item.base_price) + '</span></div>';
      }).join('') + '</div>' : '<div class="up-empty">No suggestions for this cart. Add picks or check the category types.</div>');
  }

  function load(relearn) {
    var box = $('upBody');
    if (box && !st) box.innerHTML = '<div class="up-empty">Loading…</div>';
    fetch(API + (relearn ? '?relearn=1' : ''), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) { if (box) box.innerHTML = '<div class="up-empty">' + esc(d.message || 'Could not load') + '</div>'; return; }
        d.manual.pairs = Array.isArray(d.manual.pairs) ? {} : d.manual.pairs;
        if (!d.learned.together || Array.isArray(d.learned.together)) d.learned.together = {};
        st = d;
        if (relearn) notify('Updated from your latest orders', 'success');
        render();
      })
      .catch(function () { if (box) box.innerHTML = '<div class="up-empty">Could not load. Please try again.</div>'; });
  }

  function save() {
    var btn = $('upSave'), roles = {};
    st.categories.forEach(function (c) { roles[c.id] = c.role; });
    btn.disabled = true;
    fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ settings: st.settings, roles: roles, always: st.manual.always, pairs: st.manual.pairs }) })
      .then(function (r) { return r.json(); })
      .then(function (d) { btn.disabled = false; notify(d.success ? 'Complete your meal saved' : (d.message || 'Could not save'), d.success ? 'success' : 'error'); })
      .catch(function () { btn.disabled = false; notify('Network error. Please try again.', 'error'); });
  }

  function listFor(name) {
    if (name === 'always') return st.manual.always;
    if (name === 'test') return testCart;
    return st.manual.pairs[name.substring(1)];
  }

  document.addEventListener('click', function (e) {
    if (!st || !e.target.closest) return;
    var page = e.target.closest('#upsellPage');
    if (!page) return;
    var t;
    if ((t = e.target.closest('#upSave'))) { save(); return; }
    if ((t = e.target.closest('#upRelearn'))) { load(true); return; }
    if ((t = e.target.closest('.up-mode'))) { st.settings.mode = t.getAttribute('data-mode'); render(); return; }
    if ((t = e.target.closest('.up-chip-x'))) { listFor(t.getAttribute('data-list')).splice(+t.getAttribute('data-k'), 1); render(); return; }
    if ((t = e.target.closest('.up-pair-x'))) { delete st.manual.pairs[t.getAttribute('data-x')]; render(); }
  });
  document.addEventListener('change', function (e) {
    if (!st || !e.target.closest || !e.target.closest('#upsellPage')) return;
    var t = e.target, s = st.settings, v = t.value;
    if (t.id === 'upEnabled') s.enabled = t.checked ? 1 : 0;
    else if (t.id === 'upVeg') s.veg_safe = t.checked ? 1 : 0;
    else if (t.id === 'upGoal') s.goal_nudge = t.checked ? 1 : 0;
    else if (t.id === 'upMax') s.max_items = +v;
    else if (t.id === 'upCap') s.price_cap_pct = +v;
    else if (t.hasAttribute('data-cat')) { st.categories.forEach(function (c) { if (c.id === +t.getAttribute('data-cat')) c.role = v; }); }
    else if (v && t.classList.contains('up-add-always')) st.manual.always.push(+v);
    else if (v && t.classList.contains('up-add-test')) testCart.push(+v);
    else if (v && t.classList.contains('up-add-pair')) st.manual.pairs[t.getAttribute('data-x')].push(+v);
    else if (v && t.classList.contains('up-new-pair')) st.manual.pairs[v] = [];
    else return;
    render();
  });
  document.addEventListener('input', function (e) {
    if (st && e.target.id === 'upTitle') { st.settings.title = e.target.value; preview(); }
  });

  window.loadUpsellAdmin = function () { load(false); };
})();
