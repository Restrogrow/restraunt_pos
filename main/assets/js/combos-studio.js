/**
 * Offers › Combos & Deals (dashboard): build real combos that sell on the website.
 * Data: api/combos_admin.php (config/combo_helpers.php). Styles: cbx-* in studio-ui.css.
 *
 * A combo is parts ("Pick your main", "2 x Naan"...). A part with one dish is always included; with
 * more, the customer picks one, and a choice can cost extra. The builder shows the customer's card live.
 */
(function () {
  'use strict';
  var API = '../api/combos_admin.php';
  var D = null;            // last GET: combos, dishes, totals, currency
  var draft = null;        // the combo in the builder
  var delAsk = 0;
  var pickerFor = -1;      // part index the dish picker adds to

  var esc = function (s) { return window.SX ? SX.esc(s) : String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var notify = function (m, t) { if (window.SX) SX.notify(m, t); };
  var cur = function () { return (D && D.currency) || '₹'; };
  var money = function (n) { n = Math.round((+n || 0) * 100) / 100; return cur() + (n % 1 ? n.toFixed(2) : n.toLocaleString('en-IN')); };
  var img = function (p) { return p ? '../api/image.php?path=' + encodeURIComponent(p) : ''; };
  var dishById = function (id) { return ((D && D.dishes) || []).filter(function (d) { return d.id === +id; })[0] || null; };
  var BADGES = ['Best value', 'Bestseller', 'Limited time', 'New', 'Family pack', 'Chef’s pick'];
  var DAYS = [['1', 'Mon'], ['2', 'Tue'], ['3', 'Wed'], ['4', 'Thu'], ['5', 'Fri'], ['6', 'Sat'], ['7', 'Sun']];

  function api(body) {
    return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); }).catch(function () { return { success: false, message: 'No connection. Please try again.' }; });
  }

  /* ───────── page ───────── */
  window.loadCombosStudio = function () {
    var root = document.getElementById('combosRoot');
    if (!root) return;
    if (!D) root.innerHTML = '<div class="cbx-loading"><span></span><span></span><span></span></div>';
    fetch(API, { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.success) { root.innerHTML = '<div class="sx-note warn">' + ic('warning') + '<span>' + esc(d.message || 'Could not load combos') + '</span></div>'; return; }
      D = d; draw();
    }).catch(function () { root.innerHTML = '<div class="sx-note warn">' + ic('warning') + '<span>No connection. Please try again.</span></div>'; });
  };
  // the page loader (script.js) calls loadDeals(): load combos too, then the old filter buttons
  var oldLoad = window.loadDeals;
  window.loadDeals = function () { window.loadCombosStudio(); if (typeof oldLoad === 'function') oldLoad(); };

  function collage(c, big) {
    var pics = [];
    (c.slots || []).forEach(function (s) { var ch = s.choices && s.choices[0]; if (ch && ch.image && pics.length < 3) pics.push(ch.image); });
    if (c.image) return '<div class="cbx-photo' + (big ? ' big' : '') + '"><img src="' + img(c.image) + '" alt=""></div>';
    if (!pics.length) {
      var names = (c.slots || []).map(function (s) { return s.choices && s.choices[0] ? s.choices[0].name : ''; }).filter(Boolean).slice(0, 3);
      return '<div class="cbx-photo cbx-noimg' + (big ? ' big' : '') + '">' + names.map(function (n, i) { return '<span style="--i:' + i + '">' + esc(n.charAt(0)) + '</span>'; }).join('') + ic('fastfood') + '</div>';
    }
    return '<div class="cbx-photo cbx-stack' + (big ? ' big' : '') + '">' + pics.map(function (p, i) { return '<img style="--i:' + i + ';--n:' + pics.length + '" src="' + img(p) + '" alt="">'; }).join('') + '</div>';
  }
  function partsLine(c) {
    return (c.slots || []).map(function (s) {
      var n = s.choices.length > 1 ? s.title : (s.choices[0] ? s.choices[0].name : s.title);
      return (s.qty > 1 ? s.qty + ' × ' : '') + n + (s.choices.length > 1 ? ' (' + s.choices.length + ' choices)' : '');
    }).join(' · ');
  }
  function when(c) {
    var bits = [];
    if (c.days && c.days !== '1234567') bits.push(DAYS.filter(function (d) { return c.days.indexOf(d[0]) !== -1; }).map(function (d) { return d[1]; }).join(', '));
    if (c.time_from && c.time_to) bits.push(fmtTime(c.time_from) + '–' + fmtTime(c.time_to));
    if (c.valid_until) bits.push('until ' + new Date(c.valid_until + 'T00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }));
    return bits.join(' · ');
  }
  function fmtTime(t) { var p = String(t).split(':'), h = +p[0]; return (h % 12 || 12) + (p[1] !== '00' ? ':' + p[1] : '') + (h < 12 ? 'am' : 'pm'); }

  function draw() {
    var root = document.getElementById('combosRoot');
    if (!root || !D) return;
    var t = D.totals, list = D.combos;
    var html = '<div class="cbx-hero"><p>' + ic('lightbulb') + '<span><b>Tip:</b> combos that save customers 15–20% sell best. A choice (“pick your main”) makes one combo work for veg and non-veg.</span></p>' +
      (D.can_edit ? '<button type="button" class="btn btn-primary" data-cbx-new>' + ic('add') + 'New combo</button>' : '') + '</div>' +
      '<div class="sx-stats cbx-stats">' +
        '<div class="ok">' + ic('bolt') + '<b>' + t.live + '<small> / ' + t.count + '</small></b><small>Live now</small></div>' +
        '<div>' + ic('shopping_bag') + '<b>' + t.sold + '</b><small>Sold · 30 days</small></div>' +
        '<div>' + ic('payments') + '<b>' + money(t.revenue) + '</b><small>Combo sales · 30 days</small></div>' +
        '<div>' + ic('savings') + '<b>' + t.avg_save + '%</b><small>Average saving</small></div>' +
      '</div>';
    if (D.can_edit) {
      html += '<div class="cbx-sec-h"><b>' + ic('auto_awesome') + 'Start from an idea</b><small>Fills the builder with dishes from your menu. Change anything.</small></div><div class="cbx-ideas">' +
        IDEAS.map(function (x, i) { return '<button type="button" class="cbx-idea" data-cbx-idea="' + i + '" style="--c:' + x.color + '">' + ic(x.icon) + '<b>' + x.name + '</b><small>' + x.help + '</small></button>'; }).join('') + '</div>';
    }
    html += '<div class="cbx-sec-h"><b>' + ic('local_offer') + 'Your combos</b>' + (list.length > 1 ? '<small>Use the arrows to set the order customers see.</small>' : '') + '</div>';
    if (!list.length) {
      html += '<div class="cbx-empty"><div class="cbx-empty-art">' + ic('fastfood') + ic('local_offer') + ic('celebration') + '</div><h3>No combos yet</h3><p>Pick an idea above or build your own. Combos raise the bill size: customers add more when they save.</p>' +
        (D.can_edit ? '<button type="button" class="btn btn-primary" data-cbx-new>' + ic('add') + 'Build a combo</button>' : '') + '</div>';
    } else {
      html += '<div class="cbx-grid">' + list.map(function (c, i) {
        var pct = c.worth > 0 ? Math.round(c.save / c.worth * 100) : 0;
        return '<article class="cbx-card' + (c.live ? '' : ' off') + '" style="--d:' + (i * 60) + 'ms">' +
          '<div class="cbx-card-top">' + collage(c) + (c.badge ? '<span class="cbx-ribbon">' + esc(c.badge) + '</span>' : '') +
            (pct > 0 ? '<span class="cbx-save">Save ' + pct + '%</span>' : '') + '</div>' +
          '<div class="cbx-card-body"><div class="cbx-card-h"><h3>' + esc(c.name) + '</h3><span class="sx-pill ' + (c.live ? 'ok' : c.is_active ? 'wait' : 'muted') + '">' + (c.live ? ic('bolt') + 'Live' : esc(c.state)) + '</span></div>' +
            '<p class="cbx-parts">' + esc(partsLine(c)) + '</p>' +
            (when(c) ? '<p class="cbx-when">' + ic('schedule') + esc(when(c)) + '</p>' : '') +
            '<div class="cbx-price"><b>' + money(c.price) + '</b>' + (c.save > 0 ? '<s>' + money(c.worth) + '</s>' : '') + '<span class="cbx-sold">' + ic('shopping_bag') + c.sold + ' sold</span></div>' +
          '</div>' +
          (D.can_edit ? '<div class="cbx-card-acts"><label class="cbx-sw" title="' + (c.is_active ? 'On: tap to pause' : 'Off: tap to turn on') + '"><input type="checkbox" data-cbx-toggle="' + c.id + '"' + (c.is_active ? ' checked' : '') + '><i></i></label>' +
            '<button type="button" class="sx-icon-btn" data-cbx-move="' + c.id + ':-1" title="Move up"' + (i === 0 ? ' disabled' : '') + '>' + ic('arrow_upward') + '</button>' +
            '<button type="button" class="sx-icon-btn" data-cbx-move="' + c.id + ':1" title="Move down"' + (i === list.length - 1 ? ' disabled' : '') + '>' + ic('arrow_downward') + '</button>' +
            '<span class="cbx-grow"></span>' +
            '<button type="button" class="sx-icon-btn" data-cbx-dup="' + c.id + '" title="Duplicate">' + ic('content_copy') + '</button>' +
            '<button type="button" class="sx-icon-btn cbx-del' + (delAsk === c.id ? ' ask' : '') + '" data-cbx-del="' + c.id + '" title="Delete">' + (delAsk === c.id ? 'Delete?' : ic('delete')) + '</button>' +
            '<button type="button" class="btn btn-secondary btn-sm" data-cbx-edit="' + c.id + '">' + ic('edit') + 'Edit</button></div>' : '') +
        '</article>';
      }).join('') + '</div>';
    }
    root.innerHTML = html;
  }

  /* ───────── ideas: a starting combo from the menu ───────── */
  var IDEAS = [
    { name: 'Meal for one', icon: 'lunch_dining', color: '#ea580c', help: 'Main + 2 breads + drink', badge: 'Best value', off: 0.15,
      parts: [['Pick your main', 1, /main|curry|biryani|rice|thali/i, 3], ['Bread', 2, /bread|naan|roti|paratha/i, 2], ['Your drink', 1, /bever|drink|lassi|juice|shake|chai|tea|coffee/i, 3]] },
    { name: 'Family feast', icon: 'family_restroom', color: '#7c3aed', help: '2 starters + 2 mains + breads + dessert', badge: 'Family pack', off: 0.18,
      parts: [['Starter 1', 1, /starter|appet|snack|tikka|kebab/i, 3], ['Starter 2', 1, /starter|appet|snack|tikka|kebab/i, 3], ['Main 1', 1, /main|curry|biryani/i, 3], ['Main 2', 1, /main|curry|biryani/i, 3], ['Breads', 4, /bread|naan|roti|paratha/i, 2], ['Dessert', 2, /dessert|sweet|ice/i, 3]] },
    { name: 'Lunch deal', icon: 'schedule', color: '#0891b2', help: 'Weekdays 12–3 pm', badge: 'Limited time', off: 0.12, days: '12345', from: '12:00', to: '15:00',
      parts: [['Pick your main', 1, /main|biryani|rice|thali|meal/i, 4], ['Drink', 1, /bever|drink|lassi|chai|tea|soda/i, 3]] },
    { name: 'Snack + drink', icon: 'coffee', color: '#16a34a', help: 'An evening pair', badge: 'New', off: 0.1, from: '16:00', to: '19:00',
      parts: [['Snack', 1, /starter|snack|roll|fries|sandwich|burger/i, 4], ['Drink', 1, /bever|drink|coffee|tea|chai|shake|soda/i, 4]] }
  ];
  function fromIdea(x) {
    var dishes = (D.dishes || []).filter(function (d) { return d.is_available; });
    var used = {};
    var slots = x.parts.map(function (p) {
      var pick = dishes.filter(function (d) { return p[2].test(d.category || '') && !used[d.id]; });
      if (!pick.length) pick = dishes.filter(function (d) { return p[2].test(d.name || '') && !used[d.id]; });
      pick = pick.slice(0, p[3]);
      if (p[1] === 1 && /1$/.test(p[0])) pick.forEach(function (d) { used[d.id] = 1; });   // "Starter 1" and "Starter 2" don't repeat
      return { title: p[0], qty: p[1], choices: pick.map(function (d) { return { menu_item_id: d.id, variation: null, extra: 0 }; }) };
    }).filter(function (s) { return s.choices.length; });
    var c = { id: 0, name: x.name, description: '', badge: x.badge, image: null, is_active: 1, days: x.days || '1234567', time_from: x.from || null, time_to: x.to || null, valid_from: null, valid_until: null, slots: slots };
    c.price = niceDown(worthOf(c) * (1 - x.off));
    return c;
  }
  function niceDown(n) { n = Math.max(1, Math.round(n)); return n > 50 ? Math.max(9, Math.floor(n / 10) * 10 - 1) : n; }   // 474 -> 469

  /* ───────── builder ───────── */
  function choicePrice(o) {
    var d = dishById(o.menu_item_id);
    if (!d) return 0;
    if (o.variation) { var s = d.sizes.filter(function (z) { return z.name === o.variation; })[0]; if (s) return s.price; }
    return d.price;
  }
  function worthOf(c) { return (c.slots || []).reduce(function (t, s) { return t + (s.choices[0] ? choicePrice(s.choices[0]) * (s.qty || 1) : 0); }, 0); }

  function openBuilder(c) {
    draft = JSON.parse(JSON.stringify(c));
    draft.slots = (draft.slots || []).map(function (s) {
      return { title: s.title, qty: s.qty || 1, choices: (s.choices || []).map(function (o) { return { menu_item_id: o.menu_item_id, variation: o.variation || null, extra: +o.extra || 0 }; }) };
    });
    if (!draft.slots.length) draft.slots.push({ title: 'Pick your main', qty: 1, choices: [] });
    var ov = document.createElement('div');
    ov.className = 'cbx-ov'; ov.id = 'cbxBuilder';
    ov.innerHTML = '<div class="cbx-sheet" role="dialog" aria-modal="true" aria-label="Combo builder"><div class="cbx-sheet-h"><b>' + (draft.id ? 'Edit combo' : 'New combo') + '</b>' +
      '<button type="button" class="sx-icon-btn" data-cbx-close aria-label="Close">' + ic('close') + '</button></div><div class="cbx-sheet-b"><div class="cbx-form"></div><aside class="cbx-prev"></aside></div>' +
      '<div class="cbx-sheet-f"><span class="cbx-f-note"></span><button type="button" class="btn btn-secondary" data-cbx-close>Cancel</button><button type="button" class="btn btn-primary" data-cbx-save>' + ic('check') + 'Save combo</button></div></div>';
    document.body.appendChild(ov);
    requestAnimationFrame(function () { ov.classList.add('in'); });
    drawForm(); drawPreview();
  }
  function closeBuilder() {
    var ov = document.getElementById('cbxBuilder');
    closePicker();
    if (!ov) return;
    ov.classList.remove('in');
    setTimeout(function () { ov.remove(); }, 220);
    draft = null;
  }

  function drawForm() {
    var box = document.querySelector('#cbxBuilder .cbx-form');
    if (!box || !draft) return;
    var worth = worthOf(draft), save = worth - (+draft.price || 0), pct = worth > 0 ? Math.round(save / worth * 100) : 0;
    var badgeOn = function (b) { return (draft.badge || '') === b; };
    box.innerHTML =
      '<section class="cbx-f"><h4>' + ic('edit') + 'Name and look</h4>' +
        '<label class="cbx-l">Combo name<input type="text" maxlength="120" data-cbx-f="name" value="' + esc(draft.name || '') + '" placeholder="e.g. Royal Thali for 1"></label>' +
        '<label class="cbx-l">Short line for customers <small>optional</small><input type="text" maxlength="300" data-cbx-f="description" value="' + esc(draft.description || '') + '" placeholder="e.g. A main, 2 naans, a drink and dessert"></label>' +
        '<div class="cbx-l">Badge<div class="cbx-chips">' + BADGES.map(function (b) { return '<button type="button" class="cbx-chip' + (badgeOn(b) ? ' on' : '') + '" data-cbx-badge="' + esc(b) + '">' + esc(b) + '</button>'; }).join('') +
          '<button type="button" class="cbx-chip' + (!draft.badge ? ' on' : '') + '" data-cbx-badge="">None</button></div></div>' +
        '<div class="cbx-l">Photo<div class="cbx-photo-row">' + collage(viewCombo(), false) +
          '<div><label class="btn btn-secondary btn-sm cbx-up">' + ic('add_photo_alternate') + (draft.image ? 'Change photo' : 'Upload a photo') + '<input type="file" accept="image/jpeg,image/png,image/webp" data-cbx-upload hidden></label>' +
          (draft.image ? '<button type="button" class="btn btn-secondary btn-sm" data-cbx-noimg>Use dish photos</button>' : '') +
          '<small class="sx-muted">No photo? The dish photos make a stack.</small></div></div></div>' +
      '</section>' +
      '<section class="cbx-f"><h4>' + ic('restaurant_menu') + 'What’s inside</h4><p class="sx-muted cbx-tip">One dish in a part = always included. Two or more = the customer picks one. Add an extra charge to a bigger choice.</p>' +
        draft.slots.map(function (s, si) {
          return '<div class="cbx-slot" style="--d:' + (si * 40) + 'ms"><div class="cbx-slot-h"><span class="cbx-num">' + (si + 1) + '</span>' +
            '<input type="text" maxlength="80" data-cbx-slot-title="' + si + '" value="' + esc(s.title) + '" placeholder="' + (s.choices.length > 1 ? 'Pick one' : 'Included') + '">' +
            '<div class="cbx-qty" title="How many of this part"><button type="button" data-cbx-qty="' + si + ':-1">−</button><b>' + s.qty + '×</b><button type="button" data-cbx-qty="' + si + ':1">+</button></div>' +
            '<button type="button" class="sx-icon-btn" data-cbx-slot-up="' + si + '" title="Move up"' + (si === 0 ? ' disabled' : '') + '>' + ic('arrow_upward') + '</button>' +
            '<button type="button" class="sx-icon-btn" data-cbx-slot-del="' + si + '" title="Remove this part">' + ic('delete') + '</button></div>' +
            '<div class="cbx-choices">' + s.choices.map(function (o, oi) {
              var d = dishById(o.menu_item_id);
              if (!d) return '';
              return '<div class="cbx-choice' + (d.is_available ? '' : ' na') + '">' + (d.image ? '<img src="' + img(d.image) + '" alt="">' : '<span class="cbx-ph">' + esc(d.name.charAt(0)) + '</span>') +
                '<div class="cbx-c-main"><b>' + esc(d.name) + (oi === 0 && s.choices.length > 1 ? ' <em>default</em>' : '') + '</b><small>' + money(choicePrice(o)) + ' on the menu' + (d.is_available ? '' : ' · sold out') + '</small>' +
                  (d.sizes.length ? '<select data-cbx-size="' + si + ':' + oi + '"><option value="">Regular</option>' + d.sizes.map(function (z) { return '<option value="' + esc(z.name) + '"' + (z.name === o.variation ? ' selected' : '') + '>' + esc(z.name) + ' · ' + money(z.price) + '</option>'; }).join('') + '</select>' : '') + '</div>' +
                (s.choices.length > 1 ? '<label class="cbx-extra" title="Extra the customer pays for this choice">+' + cur() + '<input type="number" min="0" step="1" data-cbx-extra="' + si + ':' + oi + '" value="' + (o.extra || '') + '" placeholder="0"></label>' : '') +
                '<button type="button" class="sx-icon-btn" data-cbx-rm="' + si + ':' + oi + '" title="Remove">' + ic('close') + '</button></div>';
            }).join('') +
            '<button type="button" class="cbx-add-dish" data-cbx-pick="' + si + '">' + ic('add') + (s.choices.length ? 'Add a choice' : 'Add a dish') + '</button></div></div>';
        }).join('') +
        '<button type="button" class="btn btn-secondary btn-sm cbx-add-part" data-cbx-addpart>' + ic('add') + 'Add a part</button>' +
      '</section>' +
      '<section class="cbx-f"><h4>' + ic('sell') + 'Price</h4><div class="cbx-pricebox">' +
        '<label class="cbx-l">Combo price<span class="cbx-money"><i>' + esc(cur()) + '</i><input type="number" min="1" step="1" data-cbx-f="price" value="' + (draft.price || '') + '"></span></label>' +
        '<div class="cbx-worth"><small>Worth on your menu</small><b>' + money(worth) + '</b></div>' +
        '<div class="cbx-saving ' + (save > 0 ? 'ok' : 'bad') + '">' + (save > 0 ? ic('savings') + '<span>Customers save <b>' + money(save) + '</b> (' + pct + '%)</span>' : ic('warning') + '<span>' + (worth ? 'Price is not below the menu price: customers save nothing.' : 'Add dishes to see the saving.') + '</span>') + '</div></div>' +
        (worth ? '<div class="cbx-chips cbx-quick"><small>Quick price</small>' + [10, 15, 20, 25].map(function (p) { return '<button type="button" class="cbx-chip" data-cbx-off="' + p + '">' + p + '% off · ' + money(niceDown(worth * (1 - p / 100))) + '</button>'; }).join('') + '</div>' : '') +
      '</section>' +
      '<section class="cbx-f"><h4>' + ic('schedule') + 'When it’s on sale</h4>' +
        '<div class="cbx-l">Days<div class="cbx-chips">' + DAYS.map(function (d) { return '<button type="button" class="cbx-chip day' + (String(draft.days || '1234567').indexOf(d[0]) !== -1 ? ' on' : '') + '" data-cbx-day="' + d[0] + '">' + d[1] + '</button>'; }).join('') + '</div></div>' +
        '<div class="cbx-row"><label class="cbx-l">From<input type="time" data-cbx-f="time_from" value="' + esc(draft.time_from || '') + '"></label><label class="cbx-l">To<input type="time" data-cbx-f="time_to" value="' + esc(draft.time_to || '') + '"></label>' +
          '<button type="button" class="cbx-chip' + (!draft.time_from ? ' on' : '') + '" data-cbx-allday>All day</button></div>' +
        '<div class="cbx-row"><label class="cbx-l">Start date <small>optional</small><input type="date" data-cbx-f="valid_from" value="' + esc(draft.valid_from || '') + '"></label><label class="cbx-l">End date <small>optional</small><input type="date" data-cbx-f="valid_until" value="' + esc(draft.valid_until || '') + '"></label></div>' +
        '<label class="cbx-on"><span class="cbx-sw"><input type="checkbox" data-cbx-f="is_active"' + (draft.is_active ? ' checked' : '') + '><i></i></span><span><b>On</b><small>Off keeps it saved but hidden from customers.</small></span></label>' +
      '</section>';
    var note = document.querySelector('#cbxBuilder .cbx-f-note');
    if (note) note.innerHTML = save > 0 ? ic('savings') + 'Save ' + pct + '% · ' + money(draft.price) : '';
  }

  // the draft shaped like a combo from the server (for the collage and the preview)
  function viewCombo() {
    var slots = draft.slots.map(function (s) {
      return { title: s.title || (s.choices.length > 1 ? 'Pick one' : 'Included'), qty: s.qty, choices: s.choices.map(function (o) { var d = dishById(o.menu_item_id) || {}; return { name: d.name || '', image: d.image || null, type: d.type, price: choicePrice(o), extra: o.extra, variation: o.variation }; }) };
    });
    var worth = worthOf(draft);
    return { name: draft.name, description: draft.description, badge: draft.badge, image: draft.image, price: +draft.price || 0, worth: worth, save: Math.max(0, worth - (+draft.price || 0)), slots: slots };
  }

  function drawPreview() {
    var box = document.querySelector('#cbxBuilder .cbx-prev');
    if (!box || !draft) return;
    var c = viewCombo(), pct = c.worth > 0 ? Math.round(c.save / c.worth * 100) : 0;
    box.innerHTML = '<small class="cbx-prev-h">' + ic('visibility') + 'What customers see</small>' +
      '<div class="cbx-pcard">' + collage(c, true) + (c.badge ? '<span class="cbx-ribbon">' + esc(c.badge) + '</span>' : '') +
        (c.save > 0 ? '<span class="cbx-save shine">Save ' + money(c.save) + '</span>' : '') +
        '<div class="cbx-pcard-b"><b>' + esc(c.name || 'Combo name') + '</b><p>' + esc(c.description || partsLine(c)) + '</p>' +
        '<div class="cbx-price"><b>' + money(c.price) + '</b>' + (c.save > 0 ? '<s>' + money(c.worth) + '</s><span class="cbx-pct">' + pct + '% off</span>' : '') + '</div>' +
        '<span class="cbx-pbtn">' + (c.slots.some(function (s) { return s.choices.length > 1; }) ? 'Customise' : 'Add') + ic('add') + '</span></div></div>' +
      '<div class="cbx-psheet">' + c.slots.map(function (s) {
        return '<div><small>' + esc((s.qty > 1 ? s.qty + ' × ' : '') + s.title) + (s.choices.length > 1 ? ' · pick 1' : '') + '</small><div class="cbx-pch">' +
          s.choices.slice(0, 4).map(function (o, i) { return '<span class="' + (i === 0 ? 'on' : '') + '">' + esc(o.name) + (o.extra ? ' <em>+' + money(o.extra) + '</em>' : '') + '</span>'; }).join('') + (s.choices.length > 4 ? '<span>+' + (s.choices.length - 4) + '</span>' : '') + '</div></div>';
      }).join('') + '</div>';
  }
  function redraw() { drawForm(); drawPreview(); }

  /* ───────── dish picker ───────── */
  function openPicker(si, anchor) {
    closePicker();
    pickerFor = si;
    var p = document.createElement('div');
    p.className = 'cbx-picker'; p.id = 'cbxPicker';
    p.innerHTML = '<div class="cbx-pk-h">' + ic('search') + '<input type="search" placeholder="Search your menu…" data-cbx-psearch autocomplete="off"><button type="button" class="sx-icon-btn" data-cbx-pclose>' + ic('close') + '</button></div><div class="cbx-pk-list"></div>' +
      '<div class="cbx-pk-f"><small>Tap dishes to add them to “' + esc(draft.slots[si].title || 'this part') + '”</small><button type="button" class="btn btn-primary btn-sm" data-cbx-pclose>Done</button></div>';
    document.getElementById('cbxBuilder').appendChild(p);
    var r = anchor.getBoundingClientRect(), sh = document.querySelector('#cbxBuilder .cbx-sheet').getBoundingClientRect();
    p.style.top = Math.min(r.bottom - sh.top + 6, sh.height - 380) + 'px';
    p.style.left = Math.max(12, Math.min(r.left - sh.left, sh.width - 372)) + 'px';
    drawPicker('');
    setTimeout(function () { var s = p.querySelector('input'); if (s) s.focus(); }, 30);
  }
  function closePicker() { var p = document.getElementById('cbxPicker'); if (p) p.remove(); pickerFor = -1; }
  function drawPicker(q) {
    var list = document.querySelector('#cbxPicker .cbx-pk-list');
    if (!list || pickerFor < 0) return;
    q = String(q || '').toLowerCase().trim();
    var inSlot = {};
    draft.slots[pickerFor].choices.forEach(function (o) { inSlot[o.menu_item_id] = 1; });
    var lastCat = null, html = '';
    D.dishes.filter(function (d) { return !q || d.name.toLowerCase().indexOf(q) !== -1 || String(d.category || '').toLowerCase().indexOf(q) !== -1; }).forEach(function (d) {
      if (d.category !== lastCat) { lastCat = d.category; html += '<div class="cbx-pk-cat">' + esc(d.category || 'Other') + '</div>'; }
      html += '<button type="button" class="cbx-pk-dish' + (inSlot[d.id] ? ' on' : '') + '" data-cbx-pdish="' + d.id + '">' + (d.image ? '<img src="' + img(d.image) + '" alt="">' : '<span class="cbx-ph">' + esc(d.name.charAt(0)) + '</span>') +
        '<span><b>' + esc(d.name) + '</b><small>' + money(d.price) + (d.sizes.length ? ' · ' + d.sizes.length + ' sizes' : '') + (d.is_available ? '' : ' · sold out') + '</small></span>' + ic(inSlot[d.id] ? 'check_circle' : 'add') + '</button>';
    });
    list.innerHTML = html || '<p class="sx-muted" style="padding:14px;">No dishes match.</p>';
  }

  /* ───────── save ───────── */
  function save(btn) {
    var c = JSON.parse(JSON.stringify(draft));
    c.slots = c.slots.filter(function (s) { return s.choices.length; });
    if (!String(c.name || '').trim()) { notify('Give the combo a name.', 'error'); return; }
    if (!c.slots.length) { notify('Add at least one dish.', 'error'); return; }
    if (!(+c.price > 0)) { notify('Set the combo price.', 'error'); return; }
    if ((c.time_from && !c.time_to) || (!c.time_from && c.time_to)) { notify('Set both From and To, or tap All day.', 'error'); return; }
    btn.disabled = true;
    api({ action: 'save', combo: c }).then(function (d) {
      btn.disabled = false;
      if (!d.success) { notify(d.message || 'Could not save', 'error'); return; }
      notify(c.id ? 'Combo saved' : 'Combo created. It’s on your website now' + (c.is_active ? '' : ' (when you turn it on)') + '.', 'success');
      closeBuilder(); window.loadCombosStudio();
    });
  }

  /* ───────── events ───────── */
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-cbx-new],[data-cbx-idea],[data-cbx-edit],[data-cbx-dup],[data-cbx-del],[data-cbx-move],[data-cbx-close],[data-cbx-save],[data-cbx-badge],[data-cbx-noimg],[data-cbx-qty],[data-cbx-slot-up],[data-cbx-slot-del],[data-cbx-rm],[data-cbx-pick],[data-cbx-addpart],[data-cbx-off],[data-cbx-day],[data-cbx-allday],[data-cbx-pdish],[data-cbx-pclose]');
    if (!t) {
      if (document.getElementById('cbxPicker') && !e.target.closest('#cbxPicker')) closePicker();
      if (e.target.classList && e.target.classList.contains('cbx-ov')) closeBuilder();
      return;
    }
    var a = function (n) { return t.getAttribute(n); };
    var pair = function (n) { var p = a(n).split(':'); return [+p[0], +p[1]]; };
    if (t.hasAttribute('data-cbx-new')) { openBuilder({ id: 0, name: '', description: '', badge: '', image: null, price: '', is_active: 1, days: '1234567', time_from: null, time_to: null, slots: [] }); return; }
    if (t.hasAttribute('data-cbx-idea')) { openBuilder(fromIdea(IDEAS[+a('data-cbx-idea')])); return; }
    if (t.hasAttribute('data-cbx-edit')) { var c = D.combos.filter(function (k) { return k.id === +a('data-cbx-edit'); })[0]; if (c) openBuilder(c); return; }
    if (t.hasAttribute('data-cbx-dup')) { api({ action: 'duplicate', id: +a('data-cbx-dup') }).then(function (d) { notify(d.success ? 'Copy made (off until you turn it on)' : (d.message || 'Could not copy'), d.success ? 'success' : 'error'); window.loadCombosStudio(); }); return; }
    if (t.hasAttribute('data-cbx-del')) {
      var id = +a('data-cbx-del');
      // goes at once with Undo for a few seconds (assets/js/rg-undo.js)
      var cb = D.combos.filter(function (k) { return k.id === id; })[0];
      rgUndo({
        html: rgUndo.label('Deleted combo', cb ? cb.name : ''),
        hide: t.closest('.cbx-card'),
        request: { url: API, json: { action: 'delete', id: id } },
        onDone: function () { window.loadCombosStudio(); },
        onError: function (msg) { notify(msg, 'error'); window.loadCombosStudio(); }
      });
      return;
    }
    if (t.hasAttribute('data-cbx-move')) {
      var mv = pair('data-cbx-move'), ids = D.combos.map(function (k) { return k.id; }), i = ids.indexOf(mv[0]), j = i + mv[1];
      if (i < 0 || j < 0 || j >= ids.length) return;
      ids.splice(j, 0, ids.splice(i, 1)[0]);
      D.combos.sort(function (x, y) { return ids.indexOf(x.id) - ids.indexOf(y.id); }); draw();
      api({ action: 'order', ids: ids });
      return;
    }
    if (t.hasAttribute('data-cbx-close')) { closeBuilder(); return; }
    if (t.hasAttribute('data-cbx-save')) { save(t); return; }
    if (!draft) return;
    if (t.hasAttribute('data-cbx-badge')) { draft.badge = a('data-cbx-badge'); redraw(); return; }
    if (t.hasAttribute('data-cbx-noimg')) { draft.image = null; redraw(); return; }
    if (t.hasAttribute('data-cbx-qty')) { var q = pair('data-cbx-qty'); var s = draft.slots[q[0]]; s.qty = Math.max(1, Math.min(20, s.qty + q[1])); redraw(); return; }
    if (t.hasAttribute('data-cbx-slot-up')) { var u = +a('data-cbx-slot-up'); if (u > 0) draft.slots.splice(u - 1, 0, draft.slots.splice(u, 1)[0]); redraw(); return; }
    if (t.hasAttribute('data-cbx-slot-del')) { draft.slots.splice(+a('data-cbx-slot-del'), 1); redraw(); return; }
    if (t.hasAttribute('data-cbx-rm')) { var r = pair('data-cbx-rm'); draft.slots[r[0]].choices.splice(r[1], 1); redraw(); return; }
    if (t.hasAttribute('data-cbx-pick')) { openPicker(+a('data-cbx-pick'), t); return; }
    if (t.hasAttribute('data-cbx-addpart')) { draft.slots.push({ title: '', qty: 1, choices: [] }); redraw(); var parts = document.querySelectorAll('#cbxBuilder .cbx-slot'); if (parts.length) parts[parts.length - 1].scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
    if (t.hasAttribute('data-cbx-off')) { draft.price = niceDown(worthOf(draft) * (1 - (+a('data-cbx-off')) / 100)); redraw(); return; }
    if (t.hasAttribute('data-cbx-day')) {
      var dd = a('data-cbx-day'), cur2 = String(draft.days || '1234567');
      cur2 = cur2.indexOf(dd) !== -1 ? cur2.replace(dd, '') : cur2 + dd;
      draft.days = cur2.split('').sort().join('') || dd; redraw(); return;
    }
    if (t.hasAttribute('data-cbx-allday')) { draft.time_from = null; draft.time_to = null; redraw(); return; }
    if (t.hasAttribute('data-cbx-pdish')) {
      var did = +a('data-cbx-pdish'), slot = draft.slots[pickerFor], at = slot.choices.map(function (o) { return o.menu_item_id; }).indexOf(did);
      if (at === -1) slot.choices.push({ menu_item_id: did, variation: null, extra: 0 }); else slot.choices.splice(at, 1);
      if (!slot.title) slot.title = slot.choices.length > 1 ? 'Pick one' : '';
      var qv = (document.querySelector('#cbxPicker [data-cbx-psearch]') || {}).value || '';
      var keep = pickerFor;
      drawForm(); drawPreview(); pickerFor = keep; drawPicker(qv);
      return;
    }
    if (t.hasAttribute('data-cbx-pclose')) { closePicker(); return; }
  });

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (t.hasAttribute('data-cbx-psearch')) { drawPicker(t.value); return; }
    if (!draft) return;
    if (t.hasAttribute('data-cbx-f') && t.type !== 'checkbox') {
      var f = t.getAttribute('data-cbx-f');
      draft[f] = t.value || (f === 'price' ? '' : null);
      if (f === 'price' || f === 'name' || f === 'description') { drawPreview(); refreshSaving(); }
      return;
    }
    if (t.hasAttribute('data-cbx-slot-title')) { draft.slots[+t.getAttribute('data-cbx-slot-title')].title = t.value; drawPreview(); return; }
    if (t.hasAttribute('data-cbx-extra')) { var p = t.getAttribute('data-cbx-extra').split(':'); draft.slots[+p[0]].choices[+p[1]].extra = Math.max(0, +t.value || 0); drawPreview(); return; }
  });
  // typing in the price keeps the field; only the saving line and the preview change
  function refreshSaving() {
    var worth = worthOf(draft), save = worth - (+draft.price || 0), pct = worth > 0 ? Math.round(save / worth * 100) : 0;
    var box = document.querySelector('#cbxBuilder .cbx-saving');
    if (box) {
      box.className = 'cbx-saving ' + (save > 0 ? 'ok' : 'bad');
      box.innerHTML = save > 0 ? ic('savings') + '<span>Customers save <b>' + money(save) + '</b> (' + pct + '%)</span>' : ic('warning') + '<span>' + (worth ? 'Price is not below the menu price: customers save nothing.' : 'Add dishes to see the saving.') + '</span>';
    }
    var note = document.querySelector('#cbxBuilder .cbx-f-note');
    if (note) note.innerHTML = save > 0 ? ic('savings') + 'Save ' + pct + '% · ' + money(draft.price) : '';
  }

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.hasAttribute('data-cbx-toggle')) {
      var on = t.checked;
      api({ action: 'toggle', id: +t.getAttribute('data-cbx-toggle'), is_active: on ? 1 : 0 }).then(function (d) {
        notify(d.success ? (on ? 'Combo is on' : 'Combo paused') : (d.message || 'Could not save'), d.success ? 'success' : 'error'); window.loadCombosStudio();
      });
      return;
    }
    if (!draft) return;
    if (t.getAttribute('data-cbx-f') === 'is_active') { draft.is_active = t.checked ? 1 : 0; return; }
    if (t.hasAttribute('data-cbx-f') && /time_|valid_/.test(t.getAttribute('data-cbx-f'))) { draft[t.getAttribute('data-cbx-f')] = t.value || null; redraw(); return; }
    if (t.hasAttribute('data-cbx-size')) { var p = t.getAttribute('data-cbx-size').split(':'); draft.slots[+p[0]].choices[+p[1]].variation = t.value || null; redraw(); return; }
    if (t.hasAttribute('data-cbx-upload') && t.files && t.files[0]) {
      var fd = new FormData(); fd.append('action', 'upload'); fd.append('image', t.files[0]);
      notify('Uploading photo…', 'info');
      fetch(API, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d.success) { notify(d.message || 'Upload failed', 'error'); return; }
        if (draft) { draft.image = d.path; redraw(); }
      }).catch(function () { notify('Upload failed. Try again.', 'error'); });
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (document.getElementById('cbxPicker')) { closePicker(); return; }
    if (document.getElementById('cbxBuilder')) closeBuilder();
  });
})();
