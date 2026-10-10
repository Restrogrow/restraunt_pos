/*
 * Offers > Bestsellers (admin)
 *
 * Pick menu items to feature in the website's "Bestsellers" carousel,
 * reorder them, and optionally give each an offer price. Saved through
 * ../api/bestsellers.php, which replaces the whole list.
 */
(function () {
  'use strict';

  var allItems = [];       // every menu item of this restaurant
  var selected = [];       // [{ menu_item_id, offer_price }] in display order
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
      var offerDisabled = it.has_variations;
      return '<li class="bs-row" data-id="' + it.id + '">' +
        '<span class="bs-rank">' + (i + 1) + '</span>' +
        (img ? '<img class="bs-thumb" src="' + esc(img) + '" alt="" loading="lazy">' : '<span class="bs-thumb bs-thumb-empty material-symbols-rounded">restaurant</span>') +
        '<div class="bs-info">' +
          '<div class="bs-name">' + vegDot(it.type) + esc(it.name) + (it.available ? '' : ' <span class="bs-badge">Unavailable</span>') + '</div>' +
          '<div class="bs-meta">' + esc(it.category) + ' · ' + money(it.price) + '</div>' +
        '</div>' +
        '<label class="bs-offer" title="' + (offerDisabled ? 'Items with variations keep their regular prices' : 'Leave empty for no discount') + '">' +
          '<span>Offer price</span>' +
          '<input type="number" min="1" step="1" inputmode="decimal" data-act="offer" placeholder="—" ' +
            (offerDisabled ? 'disabled ' : '') + 'value="' + (s.offer_price != null ? s.offer_price : '') + '">' +
        '</label>' +
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
      if (s.offer_price != null && it && !(s.offer_price > 0 && s.offer_price < it.price)) {
        notify('Offer price for “' + it.name + '” must be less than ' + money(it.price) + '.', 'error');
        return;
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
      else if (selected.length < maxItems) selected.push({ menu_item_id: id, offer_price: null });
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
  });

  window.addEventListener('beforeunload', function (e) {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });

  window.loadBestsellersAdmin = load;

  // ── Website Appearance > Bestsellers Section (saved with the theme) ──
  var STYLE_DEFAULTS = {
    show_section: true, title: 'Bestsellers', eyebrow: 'Customer favourites',
    title_color: '#1f2a44', badge_color: '#1f7a3a', add_color: '#e53935',
    add_animation: 'pulse', show_rating: true, show_offer: true
  };

  function updateStylePreview() {
    var card = document.querySelector('.bs-style-card');
    var preview = $('bsPreview');
    if (!card || !preview) return;
    card.style.setProperty('--bsp-badge', $('bsBadgeColor').value);
    card.style.setProperty('--bsp-add', $('bsAddColor').value);
    preview.setAttribute('data-anim', $('bsAddAnimation').value);
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
