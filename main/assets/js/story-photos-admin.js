/*
 * Website Appearance > Header Style > Story photos (admin)
 *
 * Shown only while the Story header is selected. Up to 10 photos that play
 * one after another at the top of the home page. Stored as banners with the
 * hidden placement 'story' (theme_api.php upload_banner / delete_banner).
 */
(function () {
  'use strict';

  var API = '../website/theme_api.php';
  var MAX = 10;
  var photos = [];

  function $(id) { return document.getElementById(id); }
  function notify(msg, type) {
    if (typeof window.showNotification === 'function') window.showNotification(msg, type);
    else if (typeof window.showToastFallback === 'function') window.showToastFallback(msg, type);
  }
  function src(p) {
    var path = String(p.banner_path || '');
    if (/^https?:\/\//.test(path)) return path;
    if (path.indexOf('db:') === 0) return '../website/image.php?type=banner&id=' + encodeURIComponent(p.id) + '&p=' + encodeURIComponent(path.slice(3));
    return '../' + path;
  }

  // Show the panel only while Story is the chosen header
  function sync() {
    var panel = $('storyPhotosPanel');
    if (!panel) return;
    var r = document.querySelector('input[name="headerStyle"]:checked');
    var on = !!(r && r.value === 'story');
    if ((panel.style.display !== 'none') !== on) {
      panel.style.display = on ? 'block' : 'none';
      if (on) load();
    }
  }

  function render() {
    var grid = $('storyPhotosGrid');
    $('storyPhotosCount').textContent = '(' + photos.length + ' of ' + MAX + ')';
    if (!photos.length) {
      grid.innerHTML = '<div style="grid-column:1/-1;padding:12px;text-align:center;color:#9ca3af;font-size:0.85rem;">No story photos yet</div>';
      return;
    }
    grid.innerHTML = photos.map(function (p, i) {
      return '<div style="position:relative;aspect-ratio:9/14;border-radius:10px;overflow:hidden;background:#e5e7eb;">' +
        '<img src="' + src(p) + '" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block;">' +
        '<span style="position:absolute;left:5px;top:5px;min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:rgba(0,0,0,.6);color:#fff;font-size:11px;font-weight:700;display:grid;place-items:center;">' + (i + 1) + '</span>' +
        '<button type="button" data-story-del="' + p.id + '" title="Remove" aria-label="Remove photo ' + (i + 1) + '" style="position:absolute;right:4px;top:4px;width:24px;height:24px;border-radius:50%;border:0;background:rgba(220,38,38,.92);color:#fff;font-size:15px;line-height:1;cursor:pointer;">&times;</button>' +
      '</div>';
    }).join('');
  }

  function load() {
    fetch(API + '?action=get_banners', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        photos = ((d && d.banners) || []).filter(function (b) { return b.placement === 'story'; });
        render();
      })
      .catch(function () {});
  }

  function upload(files) {
    files = Array.prototype.slice.call(files || []);
    if (!files.length) return;
    if (photos.length + files.length > MAX) { notify('Story can have up to ' + MAX + ' photos. You can add ' + Math.max(0, MAX - photos.length) + ' more.', 'error'); return; }
    var big = files.filter(function (f) { return f.size > 5 * 1024 * 1024; });
    if (big.length) { notify('Each photo must be under 5MB.', 'error'); return; }
    var fd = new FormData();
    files.forEach(function (f) { fd.append('banners[]', f); });
    fd.append('placement', 'story');
    $('storyPhotosCount').textContent = '(uploading…)';
    fetch(API + '?action=upload_banner', { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success) { notify(files.length > 1 ? files.length + ' story photos added' : 'Story photo added', 'success'); if (window.refreshPreview) window.refreshPreview(); }
        else notify(d.message || 'Upload failed', 'error');
        load();
      })
      .catch(function () { notify('Network error. Please try again.', 'error'); load(); });
  }

  document.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'storyPhotosInput') { upload(e.target.files); e.target.value = ''; }
  });
  document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('.header-style-opt')) setTimeout(sync, 0);
    var del = e.target.closest && e.target.closest('[data-story-del]');
    if (!del) return;
    var fd = new FormData();
    fd.append('banner_id', del.getAttribute('data-story-del'));
    del.disabled = true;
    fetch(API + '?action=delete_banner', { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success) { notify('Story photo removed', 'success'); if (window.refreshPreview) window.refreshPreview(); }
        else notify(d.message || 'Could not remove the photo', 'error');
        load();
      })
      .catch(function () { del.disabled = false; });
  });
  // The saved header style is filled in after the page loads: keep the panel in step
  setInterval(sync, 800);
})();
