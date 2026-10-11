/*
 * Website banners (dashboard: Website Appearance > Banner Images).
 *
 * Every <div class="rg-banners" data-placement="…"> on the page becomes a
 * slideshow of the banners the restaurant set to show there:
 *   home_top  — Home page, under the search bar
 *   home_menu — Home page, above the dish list
 *   menu_top  — Menu page, above the dishes
 *   cart      — Cart page, at the top
 * Auto-advances every 3 s, swipeable, with dots. Hidden when there's nothing to show.
 */
(function () {
  'use strict';
  var mounts = document.querySelectorAll('.rg-banners[data-placement]');
  if (!mounts.length) return;

  var css = '' +
    '.rg-banners{display:none;padding:10px 16px 4px}' +
    '.rg-banners.on{display:block}' +
    '.rg-bn-view{position:relative;border-radius:16px;overflow:hidden;box-shadow:0 6px 18px rgba(0,0,0,.08);background:#f3f4f6}' +
    '.rg-bn-track{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;scrollbar-width:none}' +
    '.rg-bn-track::-webkit-scrollbar{display:none}' +
    '.rg-bn-slide{flex:0 0 100%;scroll-snap-align:center;aspect-ratio:16/7}' +
    '.rg-bn-slide img{width:100%;height:100%;object-fit:cover;display:block}' +
    '.rg-bn-dots{position:absolute;left:0;right:0;bottom:8px;display:flex;justify-content:center;gap:5px;pointer-events:none}' +
    '.rg-bn-dots i{width:6px;height:6px;border-radius:999px;background:rgba(255,255,255,.6);box-shadow:0 0 0 1px rgba(0,0,0,.08);transition:width .3s,background .3s}' +
    '.rg-bn-dots i.on{width:18px;background:#fff}';
  var st = document.createElement('style');
  st.textContent = css;
  document.head.appendChild(st);

  var meta = document.querySelector('meta[name=restaurant-id]');
  var rid = (meta && meta.content) || window.websiteRestaurantId || '';
  if (!rid) return;

  function src(b) {
    var p = String(b.banner_path || '');
    if (/^https?:\/\//.test(p)) return p;
    if (p.indexOf('db:') === 0) return 'image.php?type=banner&id=' + encodeURIComponent(b.id);
    return '../' + p;
  }

  function build(mount, list) {
    mount.innerHTML = '<div class="rg-bn-view"><div class="rg-bn-track">' +
      list.map(function (b, i) { return '<div class="rg-bn-slide"><img src="' + src(b) + '" alt="" loading="' + (i ? 'lazy' : 'eager') + '"></div>'; }).join('') +
      '</div>' + (list.length > 1 ? '<div class="rg-bn-dots">' + list.map(function (b, i) { return '<i' + (i ? '' : ' class="on"') + '></i>'; }).join('') + '</div>' : '') + '</div>';
    mount.classList.add('on');
    if (list.length < 2) return;
    var track = mount.querySelector('.rg-bn-track'), dots = mount.querySelectorAll('.rg-bn-dots i');
    var idx = 0, paused = false, timer;
    function at() { return Math.round(track.scrollLeft / Math.max(1, track.clientWidth)); }
    function mark(n) { dots.forEach(function (d, k) { d.classList.toggle('on', k === n); }); }
    track.addEventListener('scroll', function () { idx = at(); mark(idx); }, { passive: true });
    track.addEventListener('touchstart', function () { paused = true; }, { passive: true });
    track.addEventListener('touchend', function () { setTimeout(function () { paused = false; }, 2500); }, { passive: true });
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!reduce) timer = setInterval(function () {
      if (paused || document.hidden) return;
      idx = (at() + 1) % list.length;
      track.scrollTo({ left: idx * track.clientWidth, behavior: 'smooth' });
    }, 3000);
  }

  fetch('theme_api.php?action=get_banners&restaurant_id=' + encodeURIComponent(rid))
    .then(function (r) { return r.json(); })
    .then(function (d) {
      var all = (d && d.success && d.banners) || [];
      mounts.forEach(function (m) {
        var place = m.getAttribute('data-placement');
        var list = all.filter(function (b) { return (b.placement || 'home_top') === place; });
        if (list.length) build(m, list);
      });
    })
    .catch(function () {});
})();
