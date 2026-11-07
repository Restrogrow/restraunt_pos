/**
 * The floating AI manager button. Markup and look: views/dashboard.php (.rg-aim).
 *
 * Drag it anywhere — it snaps to the nearer side and stays there on this device. The × on it puts it
 * away, and Settings › Navigation style switches it back on.
 *
 * Where it sits when nobody has moved it: the bottom-right corner, unless something already owns that
 * corner — POS's Pay / Hold / KOT, the Add item button, the bottom dock, the round menu, a save bar —
 * in which case it takes the bottom-left corner, clear of the sidebar, and rises above anything that
 * runs the whole way across the bottom. That is why it no longer lands on the KOT button.
 *
 * Kept on this device only (localStorage), like the navigation style: rgAiFabOff, rgAiFabPos.
 */
(function () {
  'use strict';

  var K_OFF = 'rgAiFabOff', K_POS = 'rgAiFabPos';
  var fab = document.getElementById('rgAiManagerFab');
  if (!fab) return;

  var hideBtn = document.getElementById('rgAiManagerHide');
  var aiPage = document.getElementById('ownerManagerPage');

  // already owns a corner, or a strip along the bottom
  var BLOCK = [
    '.mobile-pos-bottom-actions',          // POS on a phone: Pay / Hold / KOT across the bottom
    '.mobile-pos-bill-summary',            //   and the bill total card just above them
    '.mobile-add-item-btn',                // POS on a phone: Add item, the very same corner
    '#posPage.active .pos-cart-actions',   // POS on a computer: the three buttons under the bill
    '.rgnav-dock', '.rgnav-fab',           // bottom dock / round menu (Settings › Navigation style)
    '.st-savebar',                         // Settings: the Save changes bar
    '.rg-alert-chip'
  ];
  var HIDE_FOR = ['.sort-bar'];            // the menu's reorder bar is fixed across the same corner
  var BARS = ['.mobile-pos-bottom-actions', '.rgnav-dock'];   // full-width: never park under these

  function read(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function store(k, v) { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) {} }

  function vis(el) {
    if (!el) return null;
    var r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) return null;
    var cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0) return null;
    return r;
  }
  // a graze at the very corner (an alert chip reaching past the middle, say) is not worth moving for
  function hits(a, b) {
    return Math.min(a.right, b.right) - Math.max(a.left, b.left) > 24 &&
           Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 12;
  }

  // ── where it sits ──

  function moved() {
    try { var p = JSON.parse(read(K_POS) || 'null'); return p && (p.side === 'left' || p.side === 'right') ? p : null; } catch (e) { return null; }
  }

  // left edge of the free space: past the sidebar when one is actually on screen
  function gutter() {
    var r = vis(document.querySelector('.sidebar'));
    return (r && r.left >= 0 && r.right < window.innerWidth / 2) ? Math.round(r.right) + 16 : 16;
  }

  // how far anything across the bottom reaches up
  function barTop() {
    var up = 0;
    BARS.forEach(function (sel) {
      document.querySelectorAll(sel).forEach(function (el) {
        var r = vis(el);
        if (r && r.width > window.innerWidth / 2) up = Math.max(up, Math.round(window.innerHeight - r.top) + 8);
      });
    });
    return up;
  }

  // how far it must rise from where it is now to clear what is already there
  function lift() {
    var me = fab.getBoundingClientRect(), up = 0;
    BLOCK.forEach(function (sel) {
      document.querySelectorAll(sel).forEach(function (el) {
        if (el === fab || fab.contains(el)) return;
        var r = vis(el);
        if (r && hits(me, r)) up = Math.max(up, Math.round(me.bottom - r.top) + 12);
      });
    });
    return up;
  }

  // rising clear of one thing can land it on the next (POS's bill total sits right on top of its
  // Pay / Hold / KOT bar), so keep going until nothing is in the way
  function liftClear() {
    var up = 0, step;
    for (var i = 0; i < 4; i++) {
      step = lift();
      if (!step) break;
      up += step;
      fab.style.setProperty('--rg-aim-lift', up + 'px');
    }
  }

  function place() {
    var p = moved();
    fab.classList.toggle('rg-aim-free', !!p);
    if (p) {
      var w = fab.offsetWidth, h = fab.offsetHeight;
      // left means the left of the room it has: dropping it on the sidebar would bury the nav links
      fab.style.left = (p.side === 'left' ? gutter() : Math.max(16, window.innerWidth - w - 16)) + 'px';
      fab.style.top = Math.min(Math.max(8, p.top), Math.max(8, window.innerHeight - h - 8 - barTop())) + 'px';
      return;
    }
    fab.style.left = fab.style.top = '';
    fab.style.setProperty('--rg-aim-gut', gutter() + 'px');
    fab.classList.remove('rg-aim-left');
    fab.style.setProperty('--rg-aim-lift', '0px');
    if (!lift()) return;                 // the usual corner is free
    fab.classList.add('rg-aim-left');    // busy: take the other one
    liftClear();                         // and rise above anything running across the bottom
  }

  // ── on and off ──

  function isOn() { return read(K_OFF) !== '1'; }

  function setOn(on) {
    store(K_OFF, on ? null : '1');
    document.documentElement.classList.toggle('rg-aim-off', !on);
    syncSettings();
    if (on) place();
  }

  // ── dragging ──

  var grab = null, dragged = false;

  fab.addEventListener('pointerdown', function (e) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    if (hideBtn && hideBtn.contains(e.target)) return;
    var r = fab.getBoundingClientRect();
    grab = { id: e.pointerId, x: e.clientX, y: e.clientY, dx: e.clientX - r.left, dy: e.clientY - r.top, w: r.width, h: r.height };
    dragged = false;
    // the pointer has to be captured right away, or the moves stop arriving the moment it leaves the
    // button. That sends the click here too, which is why the click is handled on this same element.
    try { fab.setPointerCapture(e.pointerId); } catch (err) {}
  });

  fab.addEventListener('pointermove', function (e) {
    if (!grab) return;
    if (!dragged && Math.abs(e.clientX - grab.x) < 6 && Math.abs(e.clientY - grab.y) < 6) return;
    if (!dragged) { dragged = true; fab.classList.add('rg-aim-free', 'rg-aim-drag'); }
    e.preventDefault();
    fab.style.left = Math.min(Math.max(8, e.clientX - grab.dx), window.innerWidth - grab.w - 8) + 'px';
    fab.style.top = Math.min(Math.max(8, e.clientY - grab.dy), window.innerHeight - grab.h - 8 - barTop()) + 'px';
  });

  function drop(e) {
    if (!grab) return;
    grab = null;
    fab.classList.remove('rg-aim-drag');
    try { fab.releasePointerCapture(e.pointerId); } catch (err) {}
    if (!dragged) return;                                     // a plain tap: the click below opens it
    var r = fab.getBoundingClientRect();
    store(K_POS, JSON.stringify({ side: r.left + r.width / 2 < window.innerWidth / 2 ? 'left' : 'right', top: Math.round(r.top) }));
    place();
    syncSettings();
    setTimeout(function () { dragged = false; }, 0);          // after the click this pointerup fires
  }
  fab.addEventListener('pointerup', drop);
  fab.addEventListener('pointercancel', drop);

  // ── opening, hiding ──

  // on the wrapper, so a click still lands even if the pointer was captured mid-drag
  fab.addEventListener('click', function (e) {
    if (dragged) { e.preventDefault(); return; }
    if (hideBtn && hideBtn.contains(e.target)) return;
    if (window.showPage) { window.showPage('ownerManagerPage'); return; }
    // script.js is deferred; before it lands, the sidebar's own link still works
    var link = document.querySelector('.sidebar [data-page="ownerManagerPage"]');
    if (link) link.click();
  });

  if (hideBtn) hideBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    setOn(false);
    if (window.RGNotify) try { RGNotify.info('Button hidden. Settings › Navigation style brings it back.'); } catch (err) {}
  });

  // ── Settings › Navigation style ──

  function syncSettings() {
    var t = document.getElementById('rgAiFabToggle');
    if (t) t.checked = isOn();
    var r = document.getElementById('rgAiFabReset');
    if (r) r.disabled = !moved();
  }

  document.addEventListener('change', function (e) {
    if (!e.target || e.target.id !== 'rgAiFabToggle') return;
    var on = e.target.checked;
    setOn(on);
    if (window.RGNotify) try { RGNotify[on ? 'success' : 'info'](on ? 'The AI manager button is back' : 'The AI manager button is off on this device'); } catch (err) {}
  });

  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('#rgAiFabReset')) return;
    store(K_POS, null);
    place();
    syncSettings();
    if (window.RGNotify) try { RGNotify.success('Back in its own corner'); } catch (err) {}
  });

  // ── keeping up with the page ──

  var watch = new MutationObserver(function () { clearTimeout(timer); timer = setTimeout(sync, 60); });
  var timer = 0;

  function observe() {
    // the dock, the round menu and the alert chip are built later, so this is called on every sync;
    // observing a node twice with the same options only replaces its registration
    var nodes = [].slice.call(document.querySelectorAll('.main-content > .page'))
      .concat([].slice.call(document.querySelectorAll(HIDE_FOR.concat(BLOCK).join(','))))
      .concat([document.documentElement, document.querySelector('.sidebar')]);
    nodes.forEach(function (el) { if (el) watch.observe(el, { attributes: true, attributeFilter: ['class', 'style', 'hidden'] }); });
    watch.observe(document.body, { childList: true });   // chips and bars appended straight to the body
  }

  function sync() {
    observe();
    var busy = !!(aiPage && aiPage.classList.contains('active'));
    if (!busy) busy = HIDE_FOR.some(function (sel) {
      return [].some.call(document.querySelectorAll(sel), function (el) { return !!vis(el); });
    });
    fab.classList.toggle('rg-aim-hide', busy);
    if (!busy) place();
  }

  window.addEventListener('resize', function () { clearTimeout(timer); timer = setTimeout(sync, 120); });
  // a last resort: POS shows its bottom bar from script, and pages here grow new overlays all the
  // time. Measuring eight selectors costs nothing, and this is what keeps it off the KOT button.
  setInterval(function () { if (!grab) sync(); }, 1200);

  function start() {
    document.documentElement.classList.toggle('rg-aim-off', !isOn());
    syncSettings();
    sync();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();

  window.rgAiFab = {
    show: function () { setOn(true); },
    hide: function () { setOn(false); },
    reset: function () { store(K_POS, null); place(); syncSettings(); },
    isOn: isOn
  };
})();
