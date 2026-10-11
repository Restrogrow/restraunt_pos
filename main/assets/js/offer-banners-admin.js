/*
 * Website Appearance > Offer Banners (admin)
 *
 * Animated banners made in HTML/CSS (no photo design needed): pick a template,
 * type the headline / code / button, pick a colour and optionally a dish
 * photo, and where it shows. Rendered by ../website/rg-banners.js, the same
 * code the customer website uses, so the preview is exactly what customers see.
 * Saved through ../website/theme_api.php (list/save/delete_offer_banner).
 */
(function () {
  'use strict';

  var API = '../website/theme_api.php';
  var IMG_BASE = '../website/image.php?id=';
  var TEMPLATES = [
    ['burst', 'Burst'], ['ticket', 'Coupon ticket'], ['neon', 'Neon'],
    ['confetti', 'Confetti'], ['marquee', 'Ticker'], ['split', 'Split']
  ];
  var PLACES = [
    ['home_top', 'Home page — top'], ['home_menu', 'Home page — above the menu'],
    ['menu_top', 'Menu page — above the dishes'], ['cart', 'Cart page — top']
  ];
  var COLORS = ['#e11d48', '#f97316', '#16a34a', '#2563eb', '#7c3aed', '#0f766e', '#ca8a04', '#111827'];

  var banners = [];
  var items = [];
  var editing = null; // the banner being edited (copy)

  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function notify(msg, type) {
    if (typeof window.showNotification === 'function') window.showNotification(msg, type);
    else if (typeof window.showToastFallback === 'function') window.showToastFallback(msg, type);
  }
  function R() { return window.RGOfferBanner; }
  function itemImage(id) {
    for (var i = 0; i < items.length; i++) if (String(items[i].id) === String(id)) return items[i].item_image || '';
    return '';
  }
  function render(b, live) {
    if (!R()) return '';
    var copy = {};
    for (var k in b) copy[k] = b[k];
    if (b.menu_item_id && !b.image) copy.image = itemImage(b.menu_item_id);
    return R().html(copy, { imgBase: IMG_BASE, live: !!live });
  }

  // ── List ──
  function renderList() {
    var grid = $('offerBannersGrid');
    if (!grid) return;
    if (!banners.length) {
      grid.innerHTML = '<div style="grid-column:1/-1;padding:18px;border:2px dashed #e5e7eb;border-radius:12px;text-align:center;color:#6b7280;font-size:0.9rem;">No offer banners yet. Create one in a minute: pick a style, type your offer, done.</div>';
      return;
    }
    grid.innerHTML = banners.map(function (b) {
      var place = PLACES.filter(function (p) { return p[0] === b.placement; })[0];
      var on = String(b.is_active) === '1';
      return '<div style="border:1.5px solid #e5e7eb;border-radius:14px;overflow:hidden;background:#fff;' + (on ? '' : 'opacity:.6;') + '">' +
        '<div style="border-radius:0;overflow:hidden;">' + render(b, true) + '</div>' +
        '<div style="display:flex;align-items:center;gap:6px;padding:9px 10px;border-top:1px solid #f1f5f9;">' +
          '<span style="flex:1;min-width:0;font-size:0.78rem;color:#6b7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(place ? place[1] : '') + (on ? '' : ' · hidden') + '</span>' +
          '<button type="button" data-ob-edit="' + b.id + '" style="padding:5px 10px;border-radius:7px;border:1px solid #d1d5db;background:#fff;font-size:12px;font-weight:600;cursor:pointer;">Edit</button>' +
          '<button type="button" data-ob-toggle="' + b.id + '" style="padding:5px 10px;border-radius:7px;border:1px solid #d1d5db;background:#fff;font-size:12px;font-weight:600;cursor:pointer;">' + (on ? 'Hide' : 'Show') + '</button>' +
          '<button type="button" data-ob-del="' + b.id + '" title="Delete" style="padding:5px 9px;border-radius:7px;border:1px solid #fecaca;background:#fef2f2;color:#dc2626;font-size:12px;font-weight:700;cursor:pointer;">&times;</button>' +
        '</div></div>';
    }).join('');
  }

  // ── Editor ──
  function openEditor(b) {
    editing = b ? JSON.parse(JSON.stringify(b)) : {
      id: 0, template: 'burst', title: '50% OFF', subtitle: 'On your first order above ₹299', code: 'WELCOME50',
      cta: 'Order now', color: COLORS[0], menu_item_id: '', placement: 'home_top', is_active: 1
    };
    var ed = $('offerBannerEditor');
    var field = 'width:100%;padding:0.55rem 0.7rem;border:2px solid #e5e7eb;border-radius:8px;font-size:0.9rem;background:#fff;';
    var lab = 'display:block;font-size:0.75rem;font-weight:700;color:#6b7280;margin:0 0 4px;text-transform:uppercase;letter-spacing:.04em;';
    ed.innerHTML =
      '<div style="font-weight:700;color:#111827;margin-bottom:10px;">' + (editing.id ? 'Edit offer banner' : 'New offer banner') + '</div>' +
      '<div id="obPreview" style="border-radius:14px;overflow:hidden;box-shadow:0 6px 18px rgba(0,0,0,.08);margin-bottom:14px;"></div>' +
      '<span style="' + lab + '">Style</span>' +
      '<div id="obTemplates" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px;margin-bottom:14px;"></div>' +
      '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:10px;">' +
        '<div><label for="obTitle" style="' + lab + '">Headline</label><input id="obTitle" maxlength="40" placeholder="50% OFF" style="' + field + '"></div>' +
        '<div><label for="obSub" style="' + lab + '">Sub-line</label><input id="obSub" maxlength="90" placeholder="On all biryanis this weekend" style="' + field + '"></div>' +
        '<div><label for="obCode" style="' + lab + '">Coupon code (optional)</label><input id="obCode" maxlength="30" placeholder="WELCOME50" style="' + field + 'text-transform:uppercase;"></div>' +
        '<div><label for="obCta" style="' + lab + '">Button (optional)</label><input id="obCta" maxlength="24" placeholder="Order now" style="' + field + '"></div>' +
        '<div><label for="obItem" style="' + lab + '">Dish photo (optional)</label><select id="obItem" style="' + field + '"><option value="">No photo</option>' +
          items.filter(function (it) { return it.item_image; }).map(function (it) { return '<option value="' + it.id + '">' + esc(it.item_name_en) + '</option>'; }).join('') + '</select></div>' +
        '<div><label for="obPlace" style="' + lab + '">Show on</label><select id="obPlace" style="' + field + '">' +
          PLACES.map(function (p) { return '<option value="' + p[0] + '">' + p[1] + '</option>'; }).join('') + '</select></div>' +
      '</div>' +
      '<span style="' + lab + '">Colour</span>' +
      '<div id="obColors" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-bottom:14px;"></div>' +
      '<label style="display:flex;align-items:center;gap:8px;font-size:0.9rem;margin-bottom:14px;cursor:pointer;"><input type="checkbox" id="obActive"> Show on the website</label>' +
      '<div style="display:flex;gap:8px;justify-content:flex-end;">' +
        '<button type="button" id="obCancel" class="btn" style="background:#f3f4f6;color:#374151;">Cancel</button>' +
        '<button type="button" id="obSave" class="btn btn-primary">Save banner</button>' +
      '</div>';
    ed.style.display = 'block';
    $('obTitle').value = editing.title || '';
    $('obSub').value = editing.subtitle || '';
    $('obCode').value = editing.code || '';
    $('obCta').value = editing.cta || '';
    $('obItem').value = editing.menu_item_id || '';
    $('obPlace').value = editing.placement || 'home_top';
    $('obActive').checked = String(editing.is_active) === '1' || editing.is_active === 1 || editing.is_active === true;

    ['obTitle', 'obSub', 'obCode', 'obCta'].forEach(function (id) { $(id).addEventListener('input', readForm); });
    ['obItem', 'obPlace'].forEach(function (id) { $(id).addEventListener('change', readForm); });
    $('obCancel').addEventListener('click', closeEditor);
    $('obSave').addEventListener('click', save);
    renderTemplates();
    renderColors();
    renderPreview();
    ed.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  function closeEditor() {
    editing = null;
    var ed = $('offerBannerEditor');
    ed.style.display = 'none';
    ed.innerHTML = '';
  }
  function readForm() {
    if (!editing) return;
    editing.title = $('obTitle').value;
    editing.subtitle = $('obSub').value;
    editing.code = $('obCode').value.toUpperCase().replace(/[^A-Z0-9_-]/g, '');
    editing.cta = $('obCta').value;
    editing.menu_item_id = $('obItem').value;
    editing.image = '';
    editing.placement = $('obPlace').value;
    renderPreview();
    renderTemplates();
  }
  function renderPreview() {
    $('obPreview').innerHTML = render(editing.title ? editing : Object.assign({}, editing, { title: 'Your offer' }), true);
  }
  function renderTemplates() {
    var box = $('obTemplates');
    box.innerHTML = TEMPLATES.map(function (t) {
      var sel = editing.template === t[0];
      var sample = Object.assign({}, editing, { template: t[0], title: editing.title || 'Your offer' });
      return '<div role="button" tabindex="0" aria-pressed="' + sel + '" data-ob-tpl="' + t[0] + '" style="display:block;padding:0;border-radius:10px;overflow:hidden;cursor:pointer;background:#fff;text-align:left;border:2px solid ' + (sel ? '#7c3aed' : '#e5e7eb') + ';box-shadow:' + (sel ? '0 0 0 3px rgba(124,58,237,.15)' : 'none') + ';">' +
        '<div style="pointer-events:none;">' + render(sample, false) + '</div>' +
        '<div style="padding:5px 8px;font-size:11.5px;font-weight:700;color:' + (sel ? '#7c3aed' : '#374151') + ';">' + t[1] + '</div></div>';
    }).join('');
  }
  function renderColors() {
    var box = $('obColors');
    box.innerHTML = COLORS.map(function (c) {
      var sel = editing.color.toLowerCase() === c;
      return '<button type="button" data-ob-color="' + c + '" title="' + c + '" style="width:30px;height:30px;border-radius:50%;cursor:pointer;background:' + c + ';border:3px solid #fff;box-shadow:0 0 0 2px ' + (sel ? '#111827' : '#e5e7eb') + ';"></button>';
    }).join('') +
      '<label style="display:flex;align-items:center;gap:6px;font-size:0.8rem;color:#6b7280;cursor:pointer;">Custom <input type="color" id="obColorCustom" value="' + esc(editing.color) + '" style="width:34px !important;height:30px !important;min-height:0 !important;padding:0 !important;border:0;border-radius:6px;background:none;cursor:pointer;flex:none;"></label>';
    $('obColorCustom').addEventListener('input', function (e) { editing.color = e.target.value; renderPreview(); renderTemplates(); });
  }

  // ── Server ──
  function load() {
    fetch(API + '?action=list_offer_banners', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { banners = (d && d.success && d.offer_banners) || []; renderList(); })
      .catch(function () {});
    fetch('../api/get_menu_items.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { items = (d && d.success && d.data) || []; renderList(); })
      .catch(function () {});
  }
  function post(body) {
    return fetch(API + '?action=save_offer_banner', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }
  function save() {
    readForm();
    editing.is_active = $('obActive').checked ? 1 : 0;
    if (!editing.title.trim()) { notify('Add a headline, e.g. 50% OFF', 'error'); $('obTitle').focus(); return; }
    var btn = $('obSave');
    btn.disabled = true;
    post(editing).then(function (d) {
      btn.disabled = false;
      if (!d.success) { notify(d.message || 'Could not save the banner', 'error'); return; }
      notify('Offer banner saved', 'success');
      closeEditor();
      load();
      if (window.refreshPreview) window.refreshPreview();
    }).catch(function () { btn.disabled = false; notify('Network error. Please try again.', 'error'); });
  }
  function find(id) { return banners.filter(function (b) { return String(b.id) === String(id); })[0]; }

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-ob-edit],[data-ob-toggle],[data-ob-del],[data-ob-tpl],[data-ob-color],#offerBannerNew') : null;
    if (!t) return;
    if (t.id === 'offerBannerNew') { openEditor(null); return; }
    if (t.hasAttribute('data-ob-tpl')) { editing.template = t.getAttribute('data-ob-tpl'); renderTemplates(); renderPreview(); return; }
    if (t.hasAttribute('data-ob-color')) { editing.color = t.getAttribute('data-ob-color'); renderColors(); renderTemplates(); renderPreview(); return; }
    if (t.hasAttribute('data-ob-edit')) { openEditor(find(t.getAttribute('data-ob-edit'))); return; }
    if (t.hasAttribute('data-ob-toggle')) {
      var b = Object.assign({}, find(t.getAttribute('data-ob-toggle')));
      b.is_active = String(b.is_active) === '1' ? 0 : 1;
      post(b).then(function (d) {
        if (d.success) { notify(b.is_active ? 'Banner is showing' : 'Banner hidden', 'success'); load(); if (window.refreshPreview) window.refreshPreview(); }
      });
      return;
    }
    if (t.hasAttribute('data-ob-del')) {
      var id = t.getAttribute('data-ob-del');
      var ask = typeof window.showSweetConfirm === 'function' ? window.showSweetConfirm('Delete this offer banner?', 'Delete banner') : Promise.resolve(window.confirm('Delete this offer banner?'));
      Promise.resolve(ask).then(function (ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append('id', id);
        fetch(API + '?action=delete_offer_banner', { method: 'POST', credentials: 'same-origin', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (d.success) { notify('Banner deleted', 'success'); load(); if (window.refreshPreview) window.refreshPreview(); } });
      });
    }
  });

  // Style tiles are divs (so dashboard button styles don't touch the banner inside): Enter/Space picks
  document.addEventListener('keydown', function (e) {
    var t = e.target.closest && e.target.closest('[data-ob-tpl]');
    if (t && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); t.click(); }
  });

  function init() {
    if (!$('offerBannersGrid')) return;
    if (R()) R().ensureCss();
    load();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
