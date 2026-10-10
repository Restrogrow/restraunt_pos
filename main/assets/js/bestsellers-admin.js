/*
 * Offers > Bestsellers (admin)
 *
 * Pick menu items to feature in the website's "Bestsellers" carousel,
 * reorder them, and optionally give each an offer price (dishes with sizes:
 * one offer price per size). Saved through
 * ../api/bestsellers.php, which replaces the whole list.
 */
(function () {
  'use strict';

  var allItems = [];       // every menu item of this restaurant
  var selected = [];       // [{ menu_item_id, offer_price, variation_offers: {size: price} }] in display order
  var maxItems = 20;
  var dirty = false;

  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function currency() {
    // (window.currencySymbol is a form input with that id, not the symbol)
    var s = typeof window.globalCurrencySymbol === 'string' ? window.globalCurrencySymbol : '';
    return s || '₹';
  }
  function money(n) {
    n = Number(n);
    return currency() + (n % 1 === 0 ? n.toFixed(0) : n.toFixed(2));
  }
  function imgUrl(img) {
    if (!img) return '';
    if (/^https?:\/\//.test(img)) return img;
    return '../api/image.php?path=' + encodeURIComponent(img);
  }
  function itemById(id) {
    for (var i = 0; i < allItems.length; i++) if (allItems[i].id === id) return allItems[i];
    return null;
  }
  function isSelected(id) {
    for (var i = 0; i < selected.length; i++) if (selected[i].menu_item_id === id) return true;
    return false;
  }
  function vegDot(type) {
    var cls = { 'Veg': 'is-veg', 'Non Veg': 'is-nonveg', 'Egg': 'is-egg' }[type];
    return cls ? '<span class="bs-veg ' + cls + '" title="' + esc(type) + '"></span>' : '';
  }
  function notify(msg, type) {
    if (typeof window.showMessage === 'function') window.showMessage(msg, type || 'success');
    else if (typeof window.showNotification === 'function') window.showNotification(msg, type || 'success');
  }

  function setDirty(v) {
    dirty = v;
    var b = $('bsSaveBtn');
    if (b) b.disabled = !v;
  }

  function renderSelected() {
    var ol = $('bsSelected');
    if (!ol) return;
    $('bsCount').textContent = selected.length ? '(' + selected.length + '/' + maxItems + ')' : '';
    if (!selected.length) {
      ol.innerHTML = '<li class="bs-empty">No bestsellers yet — add dishes from the list on the right.</li>';
      return;
    }
    ol.innerHTML = selected.map(function (s, i) {
      var it = itemById(s.menu_item_id);
      if (!it) return '';
      var img = imgUrl(it.image);
      var sizes = it.has_variations ? (it.variations || []) : [];
      var vo = s.variation_offers || {};
      // Dishes with sizes: choose one discount for every size, or a price per
      // size; others: one offer-price box
      var allMode = s.offer_mode === 'all' || (s.offer_mode == null && vo['*'] != null);
      var modeHtml = '<label class="bs-offer bs-offer-mode"><span>Offer type</span>' +
        '<select data-act="vmode">' +
          '<option value="each"' + (allMode ? '' : ' selected') + '>Price for each size</option>' +
          '<option value="all"' + (allMode ? ' selected' : '') + '>Same discount, all sizes</option>' +
        '</select></label>';
      var offerHtml = sizes.length && allMode
        ? '<div class="bs-offer-sizes">' + modeHtml +
            '<label class="bs-offer" title="Taken off the price of every size. Leave empty for no discount">' +
              '<span>' + esc(currency()) + ' off each size</span>' +
              '<input type="number" min="1" step="1" inputmode="decimal" data-act="voff-all" placeholder="—" value="' + (vo['*'] != null ? vo['*'] : '') + '">' +
            '</label>' +
          '</div>'
        : sizes.length
        ? '<div class="bs-offer-sizes">' + modeHtml + sizes.map(function (sz) {
            return '<label class="bs-offer" title="Regular ' + esc(money(sz.price)) + '. Leave empty for no discount">' +
              '<span>' + esc(sz.name) + ' <small>' + esc(money(sz.price)) + '</small></span>' +
              '<input type="number" min="1" step="1" inputmode="decimal" data-act="voffer" data-size="' + esc(sz.name) + '" placeholder="—" value="' + (vo[sz.name] != null ? vo[sz.name] : '') + '">' +
            '</label>';
          }).join('') + '</div>'
        : '<label class="bs-offer" title="Leave empty for no discount">' +
            '<span>Offer price</span>' +
            '<input type="number" min="1" step="1" inputmode="decimal" data-act="offer" placeholder="—" value="' + (s.offer_price != null ? s.offer_price : '') + '">' +
          '</label>';
      return '<li class="bs-row" data-id="' + it.id + '">' +
        '<span class="bs-rank">' + (i + 1) + '</span>' +
        (img ? '<img class="bs-thumb" src="' + esc(img) + '" alt="" loading="lazy">' : '<span class="bs-thumb bs-thumb-empty material-symbols-rounded">restaurant</span>') +
        '<div class="bs-info">' +
          '<div class="bs-name">' + vegDot(it.type) + esc(it.name) + (it.available ? '' : ' <span class="bs-badge">Unavailable</span>') + '</div>' +
          '<div class="bs-meta">' + esc(it.category) + ' · ' + money(it.price) + '</div>' +
        '</div>' +
        offerHtml +
        '<div class="bs-actions">' +
          '<button type="button" class="bs-icon-btn" data-act="up" aria-label="Move up"' + (i === 0 ? ' disabled' : '') + '><span class="material-symbols-rounded">arrow_upward</span></button>' +
          '<button type="button" class="bs-icon-btn" data-act="down" aria-label="Move down"' + (i === selected.length - 1 ? ' disabled' : '') + '><span class="material-symbols-rounded">arrow_downward</span></button>' +
          '<button type="button" class="bs-icon-btn bs-remove" data-act="remove" aria-label="Remove"><span class="material-symbols-rounded">close</span></button>' +
        '</div>' +
      '</li>';
    }).join('');
  }

  function renderPicker() {
    var box = $('bsPicker');
    if (!box) return;
    var q = ($('bsSearch').value || '').trim().toLowerCase();
    var groups = {}, order = [];
    allItems.forEach(function (it) {
      if (q && it.name.toLowerCase().indexOf(q) === -1 && (it.category || '').toLowerCase().indexOf(q) === -1) return;
      if (!groups[it.category]) { groups[it.category] = []; order.push(it.category); }
      groups[it.category].push(it);
    });
    if (!order.length) {
      box.innerHTML = '<div class="bs-empty">' + (allItems.length ? 'No dishes match your search.' : 'Your menu has no items yet.') + '</div>';
      return;
    }
    var full = selected.length >= maxItems;
    box.innerHTML = order.map(function (cat) {
      return '<div class="bs-cat">' + esc(cat) + '</div>' + groups[cat].map(function (it) {
        var on = isSelected(it.id);
        return '<button type="button" class="bs-pick' + (on ? ' is-on' : '') + '" data-id="' + it.id + '"' + (!on && full ? ' disabled' : '') + '>' +
          vegDot(it.type) +
          '<span class="bs-pick-name">' + esc(it.name) + '</span>' +
          '<span class="bs-pick-price">' + money(it.price) + '</span>' +
          '<span class="material-symbols-rounded bs-pick-icon">' + (on ? 'check_circle' : 'add_circle') + '</span>' +
        '</button>';
      }).join('');
    }).join('');
  }

  function render() { renderSelected(); renderPicker(); }

  function load() {
    var ol = $('bsSelected');
    if (ol) ol.innerHTML = '<li class="bs-empty">Loading…</li>';
    fetch('../api/bestsellers.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) throw new Error(d.message || 'Failed to load');
        allItems = d.items || [];
        selected = (d.bestsellers || []).filter(function (s) { return itemById(s.menu_item_id); });
        selected.forEach(function (s) { if (!s.variation_offers || Array.isArray(s.variation_offers)) s.variation_offers = {}; });
        maxItems = d.max || 20;
        setDirty(false);
        render();
      })
      .catch(function (e) {
        if (ol) ol.innerHTML = '<li class="bs-empty">Could not load bestsellers. ' + esc(e.message) + '</li>';
      });
  }

  function save() {
    var btn = $('bsSaveBtn');
    // Validate offer prices before sending
    for (var i = 0; i < selected.length; i++) {
      var s = selected[i], it = itemById(s.menu_item_id);
      if (s.offer_price != null && it && !it.has_variations && !(s.offer_price > 0 && s.offer_price < it.price)) {
        notify('Offer price for “' + it.name + '” must be less than ' + money(it.price) + '.', 'error');
        return;
      }
      if (it && it.has_variations) {
        var vo = s.variation_offers || {};
        if (vo['*'] != null) {
          var cheapest = Math.min.apply(null, (it.variations || []).map(function (z) { return z.price; }));
          if (!(vo['*'] > 0 && vo['*'] < cheapest)) {
            notify('Discount for “' + it.name + '” must be less than its cheapest size (' + money(cheapest) + ').', 'error');
            return;
          }
          continue;
        }
        for (var k = 0; k < (it.variations || []).length; k++) {
          var sz = it.variations[k], p = vo[sz.name];
          if (p != null && !(p > 0 && p < sz.price)) {
            notify('Offer price for “' + it.name + ' (' + sz.name + ')” must be less than ' + money(sz.price) + '.', 'error');
            return;
          }
        }
      }
    }
    btn.disabled = true;
    fetch('../api/bestsellers.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ items: selected })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) throw new Error(d.message || 'Save failed');
        setDirty(false);
        notify('Bestsellers saved — they now show on your website.', 'success');
      })
      .catch(function (e) {
        btn.disabled = false;
        notify(e.message, 'error');
      });
  }

  document.addEventListener('click', function (e) {
    var pick = e.target.closest && e.target.closest('#bsPicker .bs-pick');
    if (pick) {
      var id = parseInt(pick.getAttribute('data-id'), 10);
      if (isSelected(id)) selected = selected.filter(function (s) { return s.menu_item_id !== id; });
      else if (selected.length < maxItems) selected.push({ menu_item_id: id, offer_price: null, variation_offers: {} });
      setDirty(true);
      render();
      return;
    }
    var act = e.target.closest && e.target.closest('#bsSelected [data-act]');
    if (act && act.tagName === 'BUTTON') {
      var row = act.closest('.bs-row');
      var rid = parseInt(row.getAttribute('data-id'), 10);
      var idx = -1;
      for (var i = 0; i < selected.length; i++) if (selected[i].menu_item_id === rid) idx = i;
      if (idx < 0) return;
      var a = act.getAttribute('data-act');
      if (a === 'remove') selected.splice(idx, 1);
      else if (a === 'up' && idx > 0) selected.splice(idx - 1, 0, selected.splice(idx, 1)[0]);
      else if (a === 'down' && idx < selected.length - 1) selected.splice(idx + 1, 0, selected.splice(idx, 1)[0]);
      setDirty(true);
      render();
      return;
    }
    if (e.target.closest && e.target.closest('#bsSaveBtn')) save();
  });

  document.addEventListener('input', function (e) {
    if (e.target.id === 'bsSearch') { renderPicker(); return; }
    if (e.target.getAttribute && e.target.getAttribute('data-act') === 'offer') {
      var rid = parseInt(e.target.closest('.bs-row').getAttribute('data-id'), 10);
      var v = e.target.value.trim();
      for (var i = 0; i < selected.length; i++) {
        if (selected[i].menu_item_id === rid) selected[i].offer_price = v === '' ? null : parseFloat(v);
      }
      setDirty(true);
    }
    if (e.target.getAttribute && e.target.getAttribute('data-act') === 'voff-all') {
      var aid = parseInt(e.target.closest('.bs-row').getAttribute('data-id'), 10), av = e.target.value.trim();
      for (var a = 0; a < selected.length; a++) {
        if (selected[a].menu_item_id === aid) selected[a].variation_offers = av === '' ? {} : { '*': parseFloat(av) };
      }
      setDirty(true);
    }
    if (e.target.getAttribute && e.target.getAttribute('data-act') === 'voffer') {
      var vid = parseInt(e.target.closest('.bs-row').getAttribute('data-id'), 10);
      var size = e.target.getAttribute('data-size'), val = e.target.value.trim();
      for (var j = 0; j < selected.length; j++) {
        if (selected[j].menu_item_id !== vid) continue;
        var map = selected[j].variation_offers || (selected[j].variation_offers = {});
        if (val === '') delete map[size]; else map[size] = parseFloat(val);
      }
      setDirty(true);
    }
  });

  // Switching a dish between "same discount" and "price per size" starts it fresh
  document.addEventListener('change', function (e) {
    if (!(e.target.getAttribute && e.target.getAttribute('data-act') === 'vmode')) return;
    var mid = parseInt(e.target.closest('.bs-row').getAttribute('data-id'), 10);
    for (var m = 0; m < selected.length; m++) {
      if (selected[m].menu_item_id === mid) { selected[m].offer_mode = e.target.value; selected[m].variation_offers = {}; }
    }
    setDirty(true);
    renderSelected();
  });

  window.addEventListener('beforeunload', function (e) {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });

  window.loadBestsellersAdmin = load;

  // ── Website Appearance > Bestsellers Section (saved with the theme) ──
  var STYLE_DEFAULTS = {
    show_section: true, title: 'Bestsellers', eyebrow: 'Customer favourites',
    title_color: '#1f2a44', badge_color: '#1f7a3a', add_color: '#e53935',
    add_animation: 'glow', add_style: 'outline', add_shape: 'rounded', add_label: 'ADD', add_plus: false, price_color: '#ea580c', price_style: 'filled',
    show_rating: true, show_offer: true
  };
  var SHAPE_RADIUS = { rounded: '10px', pill: '999px', square: '4px' };
  // Same whitelist as getBestsellerStyle() on the server
  function cleanLabel(s) { return (s || '').replace(/[^\p{L}\p{M}\p{N} +&!.\-]/gu, '').trim().slice(0, 12); }

  function updateStylePreview() {
    var card = document.querySelector('.bs-style-card');
    var preview = $('bsPreview');
    if (!card || !preview) return;
    card.style.setProperty('--bsp-badge', $('bsBadgeColor').value);
    card.style.setProperty('--bsp-add', $('bsAddColor').value);
    card.style.setProperty('--bsp-price', $('bsPriceColor').value);
    preview.setAttribute('data-price-style', $('bsPriceStyle').value);
    card.style.setProperty('--bsp-add-radius', SHAPE_RADIUS[$('bsAddShape').value] || '10px');
    preview.setAttribute('data-anim', $('bsAddAnimation').value);
    preview.setAttribute('data-style', $('bsAddStyle').value);
    preview.setAttribute('data-plus', $('bsAddPlus').checked ? '1' : '0');
    $('bsPreviewAddText').textContent = cleanLabel($('bsAddLabel').value) || STYLE_DEFAULTS.add_label;
    $('bsPreviewRate').style.display = $('bsShowRating').checked ? '' : 'none';
    $('bsPreviewOff').style.display = $('bsShowOffer').checked ? '' : 'none';
    card.classList.toggle('is-off', !$('bsShowSection').checked);
  }

  window.fillBestsellerStyleForm = function (s) {
    if (!$('bsShowSection')) return;
    s = s || {};
    var v = function (k) { return s[k] !== undefined && s[k] !== null ? s[k] : STYLE_DEFAULTS[k]; };
    $('bsShowSection').checked = !!v('show_section');
    $('bsTitle').value = v('title');
    $('bsEyebrow').value = v('eyebrow');
    $('bsTitleColor').value = v('title_color');
    $('bsBadgeColor').value = v('badge_color');
    $('bsAddColor').value = v('add_color');
    $('bsAddAnimation').value = v('add_animation');
    $('bsAddStyle').value = v('add_style');
    $('bsAddShape').value = v('add_shape');
    $('bsAddLabel').value = v('add_label');
    $('bsAddPlus').checked = !!v('add_plus');
    $('bsPriceColor').value = v('price_color');
    $('bsPriceStyle').value = v('price_style');
    $('bsShowRating').checked = !!v('show_rating');
    $('bsShowOffer').checked = !!v('show_offer');
    updateStylePreview();
  };

  window.readBestsellerStyleForm = function () {
    if (!$('bsShowSection')) return undefined;
    return {
      show_section: $('bsShowSection').checked,
      title: $('bsTitle').value.trim() || STYLE_DEFAULTS.title,
      eyebrow: $('bsEyebrow').value.trim() || STYLE_DEFAULTS.eyebrow,
      title_color: $('bsTitleColor').value,
      badge_color: $('bsBadgeColor').value,
      add_color: $('bsAddColor').value,
      add_animation: $('bsAddAnimation').value,
      add_style: $('bsAddStyle').value,
      add_shape: $('bsAddShape').value,
      add_label: cleanLabel($('bsAddLabel').value) || STYLE_DEFAULTS.add_label,
      add_plus: $('bsAddPlus').checked,
      price_color: $('bsPriceColor').value,
      price_style: $('bsPriceStyle').value,
      show_rating: $('bsShowRating').checked,
      show_offer: $('bsShowOffer').checked
    };
  };

  ['input', 'change'].forEach(function (ev) {
    document.addEventListener(ev, function (e) {
      if (e.target && e.target.closest && e.target.closest('.bs-style-card')) updateStylePreview();
    });
  });
  document.addEventListener('DOMContentLoaded', updateStylePreview);
})();
