/**
 * Loaded first in every dashboard <head>.
 * 1. Keeps the browser console quiet on the live site: our own status logs (service worker, polling,
 *    PWA, [Notif] …) only print on localhost or after localStorage.setItem('rg_debug', '1').
 *    Warnings and errors always print.
 * 2. The icon font now loads with display=swap (no Chrome "Slow network is detected" intervention).
 *    Until it is ready, icons stay invisible instead of flashing their names ("dashboard", "receipt_long").
 */
(function () {
  'use strict';
  var local = /^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname) || /\.(localhost|test)$/.test(location.hostname);
  var debug = false;
  try { debug = localStorage.getItem('rg_debug') === '1'; } catch (e) {}
  if (!local && !debug && window.console) {
    var noop = function () {};
    console.log = noop; console.info = noop; console.debug = noop;
  }

  var root = document.documentElement;
  var css = document.createElement('style');
  css.textContent = 'html:not(.ms-ready) .material-symbols-rounded,html:not(.ms-ready) .material-symbols-outlined,html:not(.ms-ready) .material-icons' +
    '{color:transparent!important;overflow:hidden!important;max-width:1.2em;white-space:nowrap}';
  (document.head || root).appendChild(css);
  var ready = function () { root.classList.add('ms-ready'); };

  // Icons not in the trimmed icon font (config/icon_names.txt) render as their name and look wide.
  // window.rgCheckIcons() lists them; on localhost it runs by itself and warns in the console.
  window.rgCheckIcons = function () {
    // measure each name on its own (no padding or layout from the page): an icon is about 1em wide
    var bad = {}, seen = {};
    var probe = document.createElement('span');
    probe.className = 'material-symbols-rounded';
    probe.style.cssText = 'position:absolute;left:-9999px;top:0;display:inline-block;width:auto;max-width:none;padding:0;margin:0;font-size:24px;white-space:nowrap;visibility:hidden';
    document.body.appendChild(probe);
    [].forEach.call(document.querySelectorAll('.material-symbols-rounded'), function (el) {
      var name = (el.textContent || '').trim();
      if (!name || seen[name] || el === probe || /\s/.test(name)) return;
      seen[name] = 1;
      probe.textContent = name;
      if (probe.getBoundingClientRect().width > 30) bad[name] = 1;
    });
    probe.remove();
    return Object.keys(bad);
  };
  if (local) {
    var warned = {};
    var scan = function () { window.rgCheckIcons().forEach(function (n) { if (!warned[n]) { warned[n] = 1; console.warn('[icons] "' + n + '" is not in the icon font. Add it to main/config/icon_names.txt'); } }); };
    window.addEventListener('load', function () { setTimeout(scan, 3000); });
    document.addEventListener('click', function () { setTimeout(scan, 1500); }, true);
  }
  setTimeout(ready, 4000);   // never hide icons for long, even if the font check fails
  // the stylesheet itself loads async, so keep asking until the font face exists and has loaded
  var tries = 0;
  (function check() {
    if (!document.fonts || !document.fonts.load) return ready();
    document.fonts.load('24px "Material Symbols Rounded"').then(function (f) {
      if (f && f.length) ready(); else if (++tries < 20) setTimeout(check, 200);
    }).catch(ready);
  })();
})();
