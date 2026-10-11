/*
 * Offers > Offer Filters (admin)
 *
 * Quick filters for the customer Menu page's floating offers bubble:
 * "Under ₹99", "50% OFF & more", "Bestsellers", "Veg only" or hand-picked
 * dishes. Saved through ../api/offer_filters.php, which replaces the list.
 */
(function () {
  'use strict';

  var filters = [];   // [{ title, icon, rule_type, rule_value, item_ids, is_active, _open }]
  var items = [];     // every menu item, for hand-picking
  var icons = ['🏷️'];
  var max = 12;
  var dirty = false;

  var RULES = [
    ['price_under', 'Dishes priced up to'],
    ['discount_min', 'Dishes with at least'],
    ['any_offer', 'All dishes with an offer'],
    ['bestsellers', 'Bestsellers'],
    ['veg', 'Veg dishes'],
    ['handpicked', 'Dishes I pick']
  ];

  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function currency() {
    var s = typeof window.globalCurrencySymbol === 'string' ? window.globalCurrencySymbol : '';
    return s || '₹';
  }
  function notify(msg, type) {
    if (typeof window.showMessage === 'function') window.showMessage(msg, type || 'success');
    else if (typeof window.showNotification === 'function') window.showNotification(msg, type || 'success');
  }
  function setDirty(v) {
    dirty = v;
    var b = $('ofSaveBtn');
    if (b) b.disabled = !v;
  }

  function presets() {
    var c = currency();
    return [
      { icon: '💸', title: 'Under ' + c + '99', rule_type: 'price_under', rule_value: 99 },
      { icon: '💸', title: 'Under ' + c + '199', rule_type: 'price_under', rule_value: 199 },
      { icon: '🔥', title: '50% OFF & more', rule_type: 'discount_min', rule_value: 50 },
      { icon: '🏷️', title: 'All offers', rule_type: 'any_offer', rule_value: null },
      { icon: '⭐', title: 'Bestsellers', rule_type: 'bestsellers', rule_value: null },
      { icon: '🥗', title: 'Veg only', rule_type: 'veg', rule_value: null },
      { icon: '🎁', title: 'Chef’s picks', rule_type: 'handpicked', rule_value: null }
    ];
  }

  function renderPresets() {
    var box = $('ofPresets');
    if (!box) return;
    box.innerHTML = presets().map(function (p, i) {
      return '<button type="button" class="of-preset" data-preset="' + i + '"><span>' + p.icon + '</span>' + esc(p.title) + '</button>';
    }).join('') +
      '<button type="button" class="of-preset" data-preset="blank"><span class="material-symbols-rounded" style="font-size:1rem">add</span>Custom</button>';
  }

  function ruleControls(f, i) {
    var sel = '<select data-act="rule" data-i="' + i + '">' + RULES.map(function (r) {
      return '<option value="' + r[0] + '"' + (f.rule_type === r[0] ? ' selected' : '') + '>' + r[1] + '</option>';
    }).join('') + '</select>';
    var val = '';
    if (f.rule_type === 'price_under') {
      val = '<span>' + esc(currency()) + '</span><input type="number" min="1" step="1" data-act="value" data-i="' + i + '" value="' + (f.rule_value != null ? f.rule_value : '') + '" placeholder="99">';
    } else if (f.rule_type === 'discount_min') {
      val = '<input type="number" min="1" max="99" step="1" data-act="value" data-i="' + i + '" value="' + (f.rule_value != null ? f.rule_value : '') + '" placeholder="50"><span>% off</span>';
    } else if (f.rule_type === 'handpicked') {
      val = '<button type="button" class="of-preset" data-act="toggle-pick" data-i="' + i + '" style="border-style:solid">' +
        (f.item_ids.length ? f.item_ids.length + ' dish' + (f.item_ids.length === 1 ? '' : 'es') + ' picked' : 'Pick dishes') + '</button>';
    }
    var sw = '<label class="of-switch"><input type="checkbox" data-act="active" data-i="' + i + '"' + (f.is_active ? ' checked' : '') + '> Show</label>';
    return sel + val + sw;
  }

  function pickList(f, i) {
    if (f.rule_type !== 'handpicked' || !f._open) return '';
    var q = (f._q || '').toLowerCase();
    var rows = items.filter(function (it) { return !q || it.name.toLowerCase().indexOf(q) !== -1; }).map(function (it) {
      return '<label><input type="checkbox" data-act="pick" data-i="' + i + '" value="' + it.id + '"' + (f.item_ids.indexOf(it.id) !== -1 ? ' checked' : '') + '> ' +
        esc(it.name) + ' <small>' + esc(it.category || '') + '</small></label>';
    }).join('');
    return '<div class="of-pick"><input type="search" placeholder="Search dishes…" data-act="pick-q" data-i="' + i + '" value="' + esc(f._q || '') + '">' +
      '<div class="of-pick-list">' + (rows || '<small>No dishes match.</small>') + '</div></div>';
  }

  function renderList() {
    var list = $('ofList');
    if (!list) return;
    $('ofCount').textContent = filters.length ? '(' + filters.length + '/' + max + ')' : '';
    if (!filters.length) {
      list.innerHTML = '<div class="of-empty">No filters yet. Tap a suggestion above to add one.</div>';
      return;
    }
    list.innerHTML = filters.map(function (f, i) {
      var iconSel = '<select class="of-icon" data-act="icon" data-i="' + i + '" aria-label="Icon">' + icons.map(function (ic) {
        return '<option' + (ic === f.icon ? ' selected' : '') + '>' + ic + '</option>';
      }).join('') + '</select>';
      return '<div class="of-card' + (f.is_active ? '' : ' off') + '">' +
        '<div class="of-top">' + iconSel +
          '<input class="of-title" maxlength="40" data-act="title" data-i="' + i + '" value="' + esc(f.title) + '" placeholder="Name customers see">' +
          '<div class="of-actions">' +
            '<button type="button" data-act="up" data-i="' + i + '" title="Move up"><span class="material-symbols-rounded">arrow_upward</span></button>' +
            '<button type="button" data-act="down" data-i="' + i + '" title="Move down"><span class="material-symbols-rounded">arrow_downward</span></button>' +
            '<button type="button" data-act="remove" data-i="' + i + '" title="Remove"><span class="material-symbols-rounded">delete</span></button>' +
          '</div>' +
        '</div>' +
        '<div class="of-rule">' + ruleControls(f, i) + '</div>' +
        pickList(f, i) +
      '</div>';
    }).join('');
  }

  function renderPreview() {
    var box = $('ofPreview');
    if (!box) return;
    var on = filters.filter(function (f) { return f.is_active && f.title.trim(); });
    box.innerHTML = on.length ? on.map(function (f) {
      return '<div class="of-chip"><span>' + f.icon + '</span><span>' + esc(f.title) + '</span></div>';
    }).join('') : '<div style="grid-column:1/-1;color:#9ca3af;font-size:0.85rem;text-align:center;padding:10px">Turned-on filters appear here.</div>';
  }

  function render() { renderList(); renderPreview(); }

  function add(p) {
    if (filters.length >= max) { notify('You can add up to ' + max + ' filters.', 'error'); return; }
    filters.push({ title: p.title, icon: p.icon, rule_type: p.rule_type, rule_value: p.rule_value, item_ids: [], is_active: true, _open: p.rule_type === 'handpicked' });
    setDirty(true);
    render();
  }

  function load() {
    var list = $('ofList');
    if (list) list.innerHTML = '<div class="of-empty">Loading…</div>';
    renderPresets();
    fetch('../api/offer_filters.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) throw new Error(d.message || 'Failed to load');
        filters = (d.filters || []).map(function (f) { f.item_ids = f.item_ids || []; return f; });
        items = d.items || [];
        icons = d.icons && d.icons.length ? d.icons : icons;
        max = d.max || max;
        setDirty(false);
        render();
      })
      .catch(function (e) {
        if (list) list.innerHTML = '<div class="of-empty">Could not load offer filters. ' + esc(e.message) + '</div>';
      });
  }

  function save() {
    for (var i = 0; i < filters.length; i++) {
      var f = filters[i];
      if (!f.title.trim()) { notify('Every filter needs a name.', 'error'); return; }
      if (f.rule_type === 'price_under' && !(f.rule_value > 0)) { notify('“' + f.title + '”: enter the price limit.', 'error'); return; }
      if (f.rule_type === 'discount_min' && !(f.rule_value > 0 && f.rule_value < 100)) { notify('“' + f.title + '”: discount must be between 1 and 99 %.', 'error'); return; }
      if (f.rule_type === 'handpicked' && !f.item_ids.length) { notify('“' + f.title + '”: pick at least one dish.', 'error'); return; }
    }
    var btn = $('ofSaveBtn');
    btn.disabled = true;
    fetch('../api/offer_filters.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ filters: filters.map(function (f) {
        return { title: f.title.trim(), icon: f.icon, rule_type: f.rule_type, rule_value: f.rule_value, item_ids: f.item_ids, is_active: f.is_active };
      }) })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) throw new Error(d.message || 'Save failed');
        setDirty(false);
        notify('Offer filters saved — they now show on your website menu.', 'success');
      })
      .catch(function (e) {
        btn.disabled = false;
        notify(e.message, 'error');
      });
  }

  function idx(el) { return parseInt(el.getAttribute('data-i'), 10); }

  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('#offerFiltersPage [data-preset], #offerFiltersPage button[data-act], #ofSaveBtn');
    if (!t) return;
    if (t.id === 'ofSaveBtn') { save(); return; }
    var p = t.getAttribute('data-preset');
    if (p !== null) {
      add(p === 'blank' ? { icon: icons[0], title: '', rule_type: 'any_offer', rule_value: null } : presets()[+p]);
      return;
    }
    var i = idx(t), a = t.getAttribute('data-act');
    if (a === 'remove') filters.splice(i, 1);
    else if (a === 'up' && i > 0) filters.splice(i - 1, 0, filters.splice(i, 1)[0]);
    else if (a === 'down' && i < filters.length - 1) filters.splice(i + 1, 0, filters.splice(i, 1)[0]);
    else if (a === 'toggle-pick') { filters[i]._open = !filters[i]._open; render(); return; }
    else return;
    setDirty(true);
    render();
  });

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t.closest || !t.closest('#offerFiltersPage') || !t.getAttribute('data-act')) return;
    var i = idx(t), a = t.getAttribute('data-act');
    if (a === 'title') { filters[i].title = t.value; setDirty(true); renderPreview(); }
    else if (a === 'value') { filters[i].rule_value = t.value === '' ? null : parseFloat(t.value); setDirty(true); }
    else if (a === 'pick-q') {
      filters[i]._q = t.value;
      renderList();
      var again = document.querySelector('#ofList [data-act="pick-q"][data-i="' + i + '"]');
      if (again) { again.focus(); again.setSelectionRange(t.value.length, t.value.length); }
    }
  });

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t.closest || !t.closest('#offerFiltersPage') || !t.getAttribute('data-act')) return;
    var i = idx(t), a = t.getAttribute('data-act');
    if (a === 'icon') filters[i].icon = t.value;
    else if (a === 'active') filters[i].is_active = t.checked;
    else if (a === 'rule') {
      filters[i].rule_type = t.value;
      filters[i].rule_value = t.value === 'price_under' ? 99 : (t.value === 'discount_min' ? 50 : null);
      filters[i]._open = t.value === 'handpicked';
    } else if (a === 'pick') {
      var id = parseInt(t.value, 10), ids = filters[i].item_ids;
      if (t.checked && ids.indexOf(id) === -1) ids.push(id);
      if (!t.checked) filters[i].item_ids = ids.filter(function (x) { return x !== id; });
    } else return;
    setDirty(true);
    render();
  });

  window.addEventListener('beforeunload', function (e) {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });

  window.loadOfferFiltersAdmin = load;
})();
