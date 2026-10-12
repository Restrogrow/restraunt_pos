/*
 * Marketing studio: shared pieces for Posters, Videos, Social Captions and the Campaign Calendar.
 *
 *  - Tabs at the top of all four pages, so they work as one studio.
 *  - MK.dishes(): the restaurant's dishes that have a photo (cached).
 *  - MK.photoPicker({label, onImage, onUseText}): pick a dish photo from the menu (with
 *    "Use its name and price") or upload your own. Used by poster-generator.js and
 *    video-generator.js in place of a bare file input.
 *  - MK.firstPhoto(): the first dish photo, so a new poster / video starts with a real picture.
 * Photos come from ../api/image.php on this site, so canvases stay exportable.
 * Styles: mk-* in assets/css/studio-ui.css.
 */
(function () {
  'use strict';
  var esc = function (s) { return window.SX ? SX.esc(s) : String(s == null ? '' : s); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var cache = null;

  function imgUrl(ref) {
    if (!ref) return '';
    if (/^https?:/i.test(ref)) return ref;
    return '../api/image.php?path=' + encodeURIComponent(ref);
  }
  function load(src) {
    return new Promise(function (ok, no) {
      var im = new Image();
      if (/^https?:/i.test(src) && src.indexOf(location.origin) !== 0) im.crossOrigin = 'anonymous';
      im.onload = function () { ok(im); };
      im.onerror = function () { no(new Error('Photo did not load')); };
      im.src = src;
    });
  }

  var MK = {
    lastPicked: {},
    dishes: function () {
      if (cache) return cache;
      cache = fetch('../api/get_menu_items.php', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
        var list = (d && (d.data || d.items)) || [];
        return list.filter(function (x) { return x.item_image && String(x.is_hidden) !== '1'; }).map(function (x) {
          return { id: +x.id, name: x.item_name_en || x.item_name || 'Dish', price: parseFloat(x.base_price) || 0, src: imgUrl(x.item_image) };
        });
      }).catch(function () { return []; });
      return cache;
    },
    firstPhoto: function () {
      return MK.dishes().then(function (list) { return list.length ? load(list[0].src).then(function (im) { return { img: im, dish: list[0] }; }) : null; }).catch(function () { return null; });
    },
    photoPicker: function (opt) {
      var wrap = document.createElement('div');
      wrap.className = 'poster-field mk-photo';
      wrap.innerHTML = '<label>' + esc(opt.label || 'Photo') + '</label>' +
        '<div class="mk-strip" aria-label="Dishes from your menu"><span class="mk-strip-load">Loading your dishes…</span></div>' +
        '<div class="mk-photo-acts"><label class="mk-upload">' + ic('upload') + 'Upload your own<input type="file" accept="image/png,image/jpeg,image/webp"></label>' +
        '<button type="button" class="mk-usetext" hidden></button></div>';
      var strip = wrap.querySelector('.mk-strip'), use = wrap.querySelector('.mk-usetext'), picked = null;
      var cur = window.globalCurrencySymbol || '₹';
      function choose(btn, dish) {
        strip.querySelectorAll('button').forEach(function (b) { b.classList.toggle('on', b === btn); });
        load(dish.src).then(function (im) { opt.onImage(im); }).catch(function () { if (window.SX) SX.notify('That photo could not be used. Try another one.', 'error'); });
        picked = dish;
        MK.lastPicked[opt.label || 'Photo'] = dish.name;   // keeps the highlight when the fields are redrawn
        if (opt.onUseText) {
          use.hidden = false;
          use.innerHTML = ic('text_fields') + 'Use “' + esc(dish.name) + (dish.price ? ' · ' + cur + Math.round(dish.price) : '') + '” as the text';
        }
      }
      MK.dishes().then(function (list) {
        if (!list.length) { strip.innerHTML = '<span class="mk-strip-load">No dish photos yet. Add photos in Menu › Menu items, or upload one below.</span>'; return; }
        strip.innerHTML = '';
        list.forEach(function (d) {
          var b = document.createElement('button');
          b.type = 'button';
          b.title = d.name;
          b.innerHTML = '<img loading="lazy" alt="" src="' + esc(d.src) + '"><span>' + esc(d.name) + '</span>';
          b.addEventListener('click', function () { choose(b, d); });
          strip.appendChild(b);
        });
        var sel = opt.selectedName || MK.lastPicked[opt.label || 'Photo'];
        if (sel) { var hit = list.filter(function (d) { return d.name === sel; })[0]; var i = hit ? list.indexOf(hit) : -1; if (i > -1) strip.children[i].classList.add('on'); }
      });
      use.addEventListener('click', function () { if (picked && opt.onUseText) opt.onUseText(picked); });
      wrap.querySelector('input[type=file]').addEventListener('change', function (e) {
        var f = e.target.files && e.target.files[0];
        if (!f) return;
        var rd = new FileReader();
        rd.onload = function (ev) { load(ev.target.result).then(function (im) { strip.querySelectorAll('button').forEach(function (b) { b.classList.remove('on'); }); use.hidden = true; delete MK.lastPicked[opt.label || 'Photo']; opt.onImage(im); }); };
        rd.readAsDataURL(f);
      });
      return wrap;
    },
  };
  window.MK = MK;

  /* ── tabs across the four marketing pages ── */
  var TABS = [['marketingPostersPage', 'imagesmode', 'Posters', 'For WhatsApp, Instagram and Facebook'],
    ['marketingVideosPage', 'movie', 'Videos', 'Short animated promos for Status and Reels'],
    ['marketingCaptionsPage', 'chat', 'Captions', 'Ready-to-post text with hashtags'],
    ['marketingCalendarPage', 'calendar_month', 'Calendar', 'Occasions coming up, ready to promote']];
  function addTabs() {
    TABS.forEach(function (t) {
      var page = document.getElementById(t[0]);
      if (!page || page.querySelector('.mk-tabs')) return;
      var nav = document.createElement('nav');
      nav.className = 'mk-tabs';
      nav.setAttribute('aria-label', 'Marketing tools');
      nav.innerHTML = TABS.map(function (x) {
        return '<button type="button" data-mk-go="' + x[0] + '" class="' + (x[0] === t[0] ? 'on' : '') + '"' + (x[0] === t[0] ? ' aria-current="page"' : '') + '>' + ic(x[1]) + '<span><b>' + x[2] + '</b><small>' + x[3] + '</small></span></button>';
      }).join('');
      var content = page.querySelector('.page-content');
      if (content) content.insertBefore(nav, content.firstChild);
    });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-mk-go]');
    if (!b) return;
    var link = document.querySelector('.nav-link[data-page="' + b.getAttribute('data-mk-go') + '"]');
    if (link) link.click();
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addTabs); else addTabs();
})();
