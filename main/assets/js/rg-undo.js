/*
 * Undo for important deletes (menu items, categories, staff, customers, bookings, tables, coupons, combos,
 * stock items, payment methods).
 *
 *   rgUndo({ message: 'Deleted “Paneer Tikka”', request: { url, body }, hide: rowElement,
 *            onDone: function (result) { reload the list }, onError: function (msg) {} })
 *
 * The item disappears at once and a floating box offers Undo for a few seconds. The real request is sent only
 * when the time is up, so Undo needs no server support. If the page is closed first, the request is sent
 * right away (keepalive). If the server refuses, the item comes back and the error is shown. Ctrl+Z undoes
 * the newest one.
 *
 * The look is picked in Super Admin › Tools › Undo design (sa_settings 'undo_design'), passed in as
 * window.RG_UNDO_DESIGN: pill | ring | snack | glass. rgUndo.preview(design) shows a demo without sending.
 */
(function () {
  'use strict';
  if (window.rgUndo) return;
  var MS = 6000, MAX = 3, DESIGNS = ['pill', 'ring', 'snack', 'glass'];
  var pending = [], seq = 0;

  function designNow() { return DESIGNS.indexOf(window.RG_UNDO_DESIGN) >= 0 ? window.RG_UNDO_DESIGN : 'pill'; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  var CSS = [
    '.rgu-host{position:fixed;z-index:2147483000;display:flex;flex-direction:column;gap:10px;pointer-events:none;font-family:Inter,Poppins,system-ui,-apple-system,"Segoe UI",sans-serif}',
    '.rgu-host *{box-sizing:border-box}',
    '.rgu{pointer-events:auto;position:relative;display:flex;align-items:center;gap:12px;overflow:hidden;animation:rguIn .32s cubic-bezier(.2,.9,.3,1.15) both}',
    '.rgu.out{animation:rguOut .22s ease-in both}',
    '.rgu-msg{flex:1;min-width:0;font-size:14px;line-height:1.35}',
    '.rgu-msg b{font-weight:700}',
    '.rgu-btn{flex:none;border:0;cursor:pointer;font:inherit;font-weight:800;display:inline-flex;align-items:center;gap:6px;transition:transform .1s,background .15s,color .15s}',
    '.rgu-btn:active{transform:scale(.95)}',
    '.rgu-btn svg{width:16px;height:16px}',
    '.rgu-x{flex:none;width:28px;height:28px;border:0;border-radius:8px;background:none;cursor:pointer;display:grid;place-items:center;opacity:.6}',
    '.rgu-x:hover{opacity:1}',
    '.rgu-x svg{width:14px;height:14px}',
    '.rgu-ic,.rgu-ring,.rgu-bar,.rgu-sec{display:none}',
    '.rgu-host button:focus-visible{outline:3px solid #fb923c;outline-offset:2px}',
    '@keyframes rguIn{from{opacity:0;transform:translateY(16px) scale(.96)}to{opacity:1;transform:none}}',
    '@keyframes rguOut{to{opacity:0;transform:translateY(10px) scale(.97)}}',
    '@keyframes rguBar{from{transform:scaleX(1)}to{transform:scaleX(0)}}',
    '@keyframes rguRing{from{stroke-dashoffset:0}to{stroke-dashoffset:94.25}}',
    '.rgu-gone{transition:opacity .2s,transform .2s;opacity:0!important;transform:scale(.98);pointer-events:none}',

    /* 1 pill: dark rounded pill, bottom centre, orange Undo, a thin line running out */
    '.rgu-host.d-pill{left:50%;bottom:24px;transform:translateX(-50%);align-items:center}',
    '.d-pill .rgu{min-width:300px;max-width:min(520px,calc(100vw - 24px));padding:10px 10px 12px 20px;border-radius:999px;background:#111827;color:#f9fafb;box-shadow:0 18px 40px -12px rgba(0,0,0,.55)}',
    '.d-pill .rgu-btn{padding:9px 16px;border-radius:999px;background:rgba(255,255,255,.08);color:#fb923c}',
    '.d-pill .rgu-btn:hover{background:rgba(251,146,60,.18)}',
    '.d-pill .rgu-x{color:#fff}',
    '.d-pill .rgu-bar{display:block;position:absolute;left:22px;right:22px;bottom:5px;height:2px;border-radius:2px;background:#fb923c;transform-origin:left}',

    /* 2 ring: white card, bottom left, a countdown ring with the seconds */
    '.rgu-host.d-ring{left:20px;bottom:20px;align-items:flex-start}',
    '.d-ring .rgu{width:min(380px,calc(100vw - 24px));padding:12px 12px 12px 14px;border-radius:16px;background:#fff;color:#111827;border:1px solid #e5e7eb;box-shadow:0 20px 44px -16px rgba(15,23,42,.35)}',
    '.d-ring .rgu-ring{display:grid;place-items:center;position:relative;flex:none;width:38px;height:38px}',
    '.d-ring .rgu-ring svg{position:absolute;inset:0;width:38px;height:38px;transform:rotate(-90deg)}',
    '.d-ring .rgu-ring circle{fill:none;stroke-width:3}',
    '.d-ring .rgu-ring .bg{stroke:#f1f5f9}',
    '.d-ring .rgu-ring .fg{stroke:#f97316;stroke-linecap:round;stroke-dasharray:94.25}',
    '.d-ring .rgu-ring b{font-size:13px;font-weight:800;color:#c2410c}',
    '.d-ring .rgu-msg small{display:block;color:#64748b;font-size:12px;margin-top:2px}',
    '.d-ring .rgu-btn{padding:9px 14px;border-radius:11px;background:#fff7ed;color:#c2410c;border:1.5px solid #fed7aa}',
    '.d-ring .rgu-btn:hover{background:#ffedd5}',
    '.d-ring .rgu-x{color:#64748b}',

    /* 3 snack: classic snackbar, wide, bottom centre, UNDO in capitals, line on top */
    '.rgu-host.d-snack{left:50%;bottom:0;transform:translateX(-50%);width:min(600px,100vw);padding:0 12px 16px}',
    '.d-snack .rgu{width:100%;padding:14px 8px 14px 18px;border-radius:10px;background:#2b2f36;color:#fff;box-shadow:0 10px 30px -8px rgba(0,0,0,.5)}',
    '.d-snack .rgu-ic{display:grid;place-items:center;flex:none;width:22px;height:22px;color:#cbd5e1}',
    '.d-snack .rgu-ic svg{width:20px;height:20px}',
    '.d-snack .rgu-btn{padding:9px 14px;border-radius:8px;background:none;color:#fbbf24;letter-spacing:.08em;text-transform:uppercase;font-size:13px}',
    '.d-snack .rgu-btn:hover{background:rgba(251,191,36,.12)}',
    '.d-snack .rgu-btn svg{display:none}',
    '.d-snack .rgu-x{color:#fff}',
    '.d-snack .rgu-bar{display:block;position:absolute;left:0;right:0;top:0;height:3px;background:#fbbf24;transform-origin:left}',

    /* 4 glass: frosted card at the top, red bin icon, big orange Undo with the seconds */
    '.rgu-host.d-glass{left:50%;top:18px;transform:translateX(-50%);align-items:center}',
    '.d-glass .rgu{width:min(460px,calc(100vw - 24px));padding:10px 10px 10px 12px;border-radius:18px;background:rgba(255,255,255,.82);-webkit-backdrop-filter:blur(14px) saturate(1.4);backdrop-filter:blur(14px) saturate(1.4);color:#0f172a;border:1px solid rgba(255,255,255,.9);box-shadow:0 24px 50px -18px rgba(15,23,42,.45),inset 0 1px 0 #fff;animation-name:rguDrop}',
    '@keyframes rguDrop{from{opacity:0;transform:translateY(-18px) scale(.96)}to{opacity:1;transform:none}}',
    '.d-glass .rgu.out{animation:rguUp .22s ease-in both}',
    '@keyframes rguUp{to{opacity:0;transform:translateY(-12px) scale(.97)}}',
    '.d-glass .rgu-ic{display:grid;place-items:center;flex:none;width:40px;height:40px;border-radius:12px;background:#fee2e2;color:#dc2626}',
    '.d-glass .rgu-ic svg{width:20px;height:20px}',
    '.d-glass .rgu-btn{padding:10px 16px;border-radius:12px;background:linear-gradient(135deg,#fb923c,#ea580c);color:#fff;box-shadow:0 8px 16px -8px rgba(234,88,12,.8)}',
    '.d-glass .rgu-btn:hover{filter:brightness(1.05)}',
    '.d-glass .rgu-sec{display:inline;font-weight:700;opacity:.85}',
    '.d-glass .rgu-x{color:#475569}',

    '@media (max-width:560px){.rgu-host.d-ring{left:12px;right:12px;bottom:12px}.rgu-host.d-pill{bottom:16px}.d-pill .rgu{min-width:0;width:calc(100vw - 24px);border-radius:18px}}',
    '@media (prefers-reduced-motion:reduce){.rgu,.rgu.out{animation:none!important}.rgu-bar,.rgu-ring .fg{animation:none!important}}'
  ].join('\n');

  var BIN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>';
  var BACK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14L4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg>';
  var X = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';

  function ensureCss() {
    if (document.getElementById('rguCss')) return;
    var st = document.createElement('style');
    st.id = 'rguCss';
    st.textContent = CSS;
    document.head.appendChild(st);
  }

  function hostFor(design) {
    ensureCss();
    var h = document.getElementById('rguHost');
    if (!h) {
      h = document.createElement('div');
      h.id = 'rguHost';
      h.setAttribute('role', 'status');
      h.setAttribute('aria-live', 'polite');
      document.body.appendChild(h);
    }
    h.className = 'rgu-host d-' + design;
    // sit above the "Turn on order alerts" chip (order-alerts.js) instead of on top of it
    var lift = 0, chip = document.querySelector('.rg-alert-chip');
    if (chip && design !== 'glass' && h.parentNode === document.body) {
      var r = chip.getBoundingClientRect();
      if (r.height && r.bottom > window.innerHeight - 160) lift = Math.round(window.innerHeight - r.top + 10);
    }
    h.style.bottom = lift ? lift + 'px' : '';
    return h;
  }

  function toElements(hide) {
    if (!hide) return [];
    if (typeof hide === 'string') return Array.prototype.slice.call(document.querySelectorAll(hide));
    if (hide.nodeType === 1) return [hide];
    return Array.prototype.slice.call(hide).filter(function (e) { return e && e.nodeType === 1; });
  }

  function hideEls(els) {
    els.forEach(function (e) {
      e.classList.add('rgu-gone');
      e._rguTimer = setTimeout(function () { e._rguDisplay = e.style.display; e.style.display = 'none'; }, 200);
    });
  }
  function showEls(els) {
    els.forEach(function (e) {
      clearTimeout(e._rguTimer);
      if (e._rguDisplay !== undefined) e.style.display = e._rguDisplay;
      requestAnimationFrame(function () { e.classList.remove('rgu-gone'); });
    });
  }

  function bodyOf(b) {
    if (!b) return { body: undefined, headers: {} };
    if (b instanceof FormData || b instanceof URLSearchParams || typeof b === 'string') return { body: b, headers: {} };
    var u = new URLSearchParams();
    Object.keys(b).forEach(function (k) { u.append(k, Array.isArray(b[k]) ? JSON.stringify(b[k]) : b[k]); });
    return { body: u, headers: {} };
  }

  function send(p, keepalive) {
    var r = p.request, b = r.json ? { body: JSON.stringify(r.json), headers: { 'Content-Type': 'application/json' } } : bodyOf(r.body);
    return fetch(r.url, { method: r.method || 'POST', credentials: 'same-origin', headers: b.headers, body: b.body, keepalive: !!keepalive })
      .then(function (res) { return res.text().then(function (t) { try { return JSON.parse(t); } catch (e) { return { success: res.ok }; } }); });
  }

  function removeToast(p) {
    if (!p.toast) return;
    var t = p.toast;
    p.toast = null;
    t.classList.add('out');
    setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 230);
  }

  function settle(p) {
    var i = pending.indexOf(p);
    if (i >= 0) pending.splice(i, 1);
  }

  function commit(p, keepalive) {
    if (p.state !== 'waiting') return;
    p.state = 'sending';
    clearTimeout(p.timer);
    clearInterval(p.tick);
    removeToast(p);
    settle(p);
    if (p.preview) { p.state = 'done'; return; }
    var ok = function (d) { return d && (d.success === true || d.ok === true || d.status === 'success'); };
    send(p, keepalive).then(function (d) {
      p.state = 'done';
      if (ok(d)) { if (p.onDone) p.onDone(d); return; }
      showEls(p.els);
      var msg = (d && (d.message || d.error)) || 'Could not delete it. It is back.';
      if (p.onError) p.onError(msg); else note(msg);
    }).catch(function () {
      p.state = 'done';
      if (keepalive) return;   // page is closing: nothing to show
      showEls(p.els);
      var msg = 'No connection, so nothing was deleted.';
      if (p.onError) p.onError(msg); else note(msg);
    });
  }

  function undo(p) {
    if (p.state !== 'waiting') return;
    p.state = 'undone';
    clearTimeout(p.timer);
    clearInterval(p.tick);
    removeToast(p);
    settle(p);
    showEls(p.els);
    if (p.onUndo) p.onUndo();
  }

  function note(msg) {
    if (typeof window.showNotification === 'function') window.showNotification(msg, 'error');
    else if (typeof window.showMessage === 'function') window.showMessage(msg, 'error');
  }

  function build(p, design) {
    var host = hostFor(design);
    var secs = Math.ceil(p.ms / 1000);
    var t = document.createElement('div');
    t.className = 'rgu';
    t.innerHTML =
      '<span class="rgu-ic" aria-hidden="true">' + BIN + '</span>' +
      '<span class="rgu-ring" aria-hidden="true"><svg viewBox="0 0 38 38"><circle class="bg" cx="19" cy="19" r="15"/><circle class="fg" cx="19" cy="19" r="15" style="animation:rguRing ' + p.ms + 'ms linear forwards"/></svg><b>' + secs + '</b></span>' +
      '<span class="rgu-msg">' + p.html + (design === 'ring' ? '<small>Undo within ' + secs + ' seconds</small>' : '') + '</span>' +
      '<button type="button" class="rgu-btn">' + BACK + 'Undo<span class="rgu-sec"> · ' + secs + 's</span></button>' +
      '<button type="button" class="rgu-x" aria-label="Hide">' + X + '</button>' +
      '<span class="rgu-bar" style="animation:rguBar ' + p.ms + 'ms linear forwards"></span>';
    t.querySelector('.rgu-btn').addEventListener('click', function () { undo(p); });
    // hiding the box doesn't cancel anything: the delete goes ahead now
    t.querySelector('.rgu-x').addEventListener('click', function () { commit(p, false); });
    host.appendChild(t);
    // keep at most MAX boxes: the oldest one is done
    var waiting = pending.filter(function (q) { return q.toast && q !== p && q.toast.parentNode === host; });
    while (waiting.length >= MAX) commit(waiting.shift(), false);
    p.toast = t;
    var left = secs;
    p.tick = setInterval(function () {
      left = Math.max(0, left - 1);
      var b = t.querySelector('.rgu-ring b'), s = t.querySelector('.rgu-sec');
      if (b) b.textContent = left;
      if (s) s.textContent = ' · ' + left + 's';
    }, 1000);
  }

  function start(opt, preview, design) {
    var p = {
      id: ++seq, state: 'waiting', preview: !!preview, ms: opt.ms || MS,
      request: opt.request, onDone: opt.onDone, onError: opt.onError, onUndo: opt.onUndo,
      html: opt.html || esc(opt.message || 'Deleted'),
      els: toElements(opt.hide)
    };
    if (!preview && (!p.request || !p.request.url)) throw new Error('rgUndo: request.url is required');
    hideEls(p.els);
    pending.push(p);
    build(p, design || designNow());
    p.timer = setTimeout(function () { commit(p, false); }, p.ms);
    return { undo: function () { undo(p); }, commitNow: function () { commit(p, false); } };
  }

  window.rgUndo = function (opt) { return start(opt || {}, false); };
  /** A demo box in the given design (nothing is sent). Used by Super Admin › Undo design. */
  window.rgUndo.preview = function (design, opt) {
    opt = opt || {};
    return start({ html: opt.html || 'Deleted <b>Paneer Tikka</b>', hide: opt.hide, ms: opt.ms, onUndo: opt.onUndo }, true, DESIGNS.indexOf(design) >= 0 ? design : 'pill');
  };
  window.rgUndo.designs = DESIGNS.slice();
  /** "Deleted “Name”" with the name in bold, escaped. */
  window.rgUndo.label = function (what, name) { return what + (name ? ' <b>“' + esc(name) + '”</b>' : ''); };
  /** Every element matching a selector, as an array (what to hide). */
  window.rgUndo.rows = function (sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); };
  /** The row / card the clicked button sits in (for inline onclick handlers). */
  window.rgUndo.rowOf = function (ev, sel) {
    var t = ev && ev.target;
    return t && t.closest ? t.closest(sel || 'tr, li, .card, [data-row], .sx-card, .menu-item-card, .item-card') : null;
  };

  // Ctrl+Z undoes the newest one (not while typing)
  document.addEventListener('keydown', function (e) {
    if (!(e.ctrlKey || e.metaKey) || e.shiftKey || String(e.key).toLowerCase() !== 'z' || !pending.length) return;
    var a = document.activeElement;
    if (a && (a.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName))) return;
    e.preventDefault();
    undo(pending[pending.length - 1]);
  });

  // leaving the page: send what's waiting now, so a delete is never lost
  function flush() { pending.slice().forEach(function (p) { commit(p, true); }); }
  window.addEventListener('pagehide', flush);
  window.addEventListener('beforeunload', flush);
})();
