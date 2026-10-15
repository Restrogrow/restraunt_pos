/*
 * RestroGrow notifications: one file for the dashboard, the waiter / chef screens and the customer website.
 * (The dashboard loads it as ../website/rg-notify.js, like rg-upsell.js.)
 *
 *  RGNotify.toast(msg, type, opts)    corner toast. Look set by RGNotify.setup({ toast }):
 *                                       'card'  accent card + countdown bar (dashboard)
 *                                       'web'   success/info -> Dynamic Island, warning/error -> frosted glass (customer website)
 *                                     opts: { title, duration, html, style: 'card'|'glass'|'island' }
 *  RGNotify.island(msg, type, opts)   Dynamic Island pill at the top, for short confirmations
 *  RGNotify.snack(msg, opts)          snackbar with an action: { action: 'Undo', onAction, duration }
 *  RGNotify.loading(msg)              "Saving…" pill -> handle.done(msg, type) / .success(msg) / .error(msg) / .close()
 *  RGNotify.promise(p, { loading, success, error })
 *  RGNotify.alert(msg, type, opts)    branded popup (bottom sheet on phones) -> Promise
 *  RGNotify.confirm(msg, opts)        { title, confirmText, cancelText, danger } -> Promise<boolean>
 *  RGNotify.watchSaves()              dashboard: a save / delete the user started that takes a moment shows
 *                                     "Saving…", and the toast the page shows next turns that pill into ✓ / ✕
 * type: success | error | warning | info
 */
(function () {
  'use strict';
  if (window.RGNotify) return;

  var CSS = [
    '.rgn-stack,.rgn-stack *,.rgn-island,.rgn-island *,.rgn-snack,.rgn-snack *,.rgn-load,.rgn-load *,.rgn-ov,.rgn-ov *{box-sizing:border-box}',
    '.rgn-stack{position:fixed;top:16px;right:16px;z-index:100050;display:flex;flex-direction:column;gap:10px;width:370px;max-width:calc(100vw - 24px);pointer-events:none;font-family:inherit}',
    '.rgn-stack>*{pointer-events:auto}',
    '.rgn-stack{transition:top .3s}.rgn-island-on .rgn-stack{top:70px}',
    '.rgn-c-success{--rgn:#10b981}.rgn-c-error{--rgn:#ef4444}.rgn-c-warning{--rgn:#f59e0b}.rgn-c-info{--rgn:#3b82f6}',
    '.rgn-ic{width:22px;height:22px;flex:none;display:block}',
    '.rgn-bar{position:absolute;left:0;bottom:0;height:3px;width:100%;background:var(--rgn);transform-origin:left;animation:rgnBar var(--rgn-d,4s) linear forwards}',
    '.rgn-hold .rgn-bar,.rgn-hold .rgn-bar-x{animation-play-state:paused}',
    '@keyframes rgnBar{to{transform:scaleX(0)}}',
    '.rgn-x{position:absolute;top:8px;right:8px;width:26px;height:26px;border:0;border-radius:8px;background:none;color:#9ca3af;font-size:18px;line-height:1;cursor:pointer;display:grid;place-items:center}',
    '.rgn-x:hover{background:#f3f4f6;color:#111827}',
    /* accent card */
    '.rgn-card{position:relative;display:flex;gap:12px;padding:14px 38px 17px 14px;border-radius:14px;border-left:5px solid var(--rgn);background:#fff;color:#111827;box-shadow:0 12px 32px -8px rgba(17,24,39,.22),0 2px 6px rgba(17,24,39,.06);overflow:hidden;transform:translateX(calc(100% + 24px));transition:transform .38s cubic-bezier(.2,.9,.3,1.15),opacity .25s}',
    '.rgn-card.rgn-in{transform:none}',
    '.rgn-card .rgn-i{width:34px;height:34px;flex:none;display:grid;place-items:center;border-radius:50%;background:color-mix(in srgb,var(--rgn) 14%,#fff);color:var(--rgn)}',
    '.rgn-txt{min-width:0;flex:1;display:flex;flex-direction:column;gap:2px;justify-content:center}',
    '.rgn-txt b{font-size:14px;font-weight:700;line-height:1.3}',
    '.rgn-txt span{font-size:13px;line-height:1.45;color:#4b5563;overflow-wrap:anywhere}',
    '.rgn-txt b:only-child,.rgn-txt span:only-child{color:#111827;font-size:14px;font-weight:500}',
    /* frosted glass */
    '.rgn-glass{position:relative;display:flex;gap:12px;align-items:center;padding:13px 38px 13px 13px;border-radius:18px;background:rgba(255,255,255,.72);-webkit-backdrop-filter:blur(18px) saturate(1.7);backdrop-filter:blur(18px) saturate(1.7);border:1px solid rgba(255,255,255,.8);color:#111827;box-shadow:0 12px 40px -6px rgba(17,24,39,.25);overflow:hidden;opacity:0;transform:translateY(-14px) scale(.97);transition:opacity .3s,transform .35s cubic-bezier(.2,.9,.3,1.15)}',
    '.rgn-glass.rgn-in{opacity:1;transform:none}',
    '.rgn-glass .rgn-i{width:38px;height:38px;flex:none;display:grid;place-items:center;border-radius:12px;background:var(--rgn);color:#fff;box-shadow:0 6px 14px -6px var(--rgn)}',
    '.rgn-glass .rgn-x{color:#6b7280}',
    '.rgn-bar-x{position:absolute;width:0;height:0;animation:rgnBar var(--rgn-d,4s) linear forwards}',
    '.rgn-out{opacity:0!important;transition:opacity .25s,transform .3s!important}',
    '.rgn-card.rgn-out{transform:translateX(calc(100% + 24px))!important}',
    /* Dynamic Island */
    '.rgn-island{position:fixed;top:12px;left:50%;z-index:100060;display:flex;align-items:center;gap:10px;height:40px;width:40px;max-width:calc(100vw - 24px);padding:0 9px;border-radius:999px;background:#0b0b0c;color:#fff;font-family:inherit;font-size:14px;font-weight:500;white-space:nowrap;overflow:hidden;box-shadow:0 10px 30px -6px rgba(0,0,0,.45);transform:translate(-50%,-70px);transition:transform .32s cubic-bezier(.2,.9,.3,1.2),width .5s cubic-bezier(.3,1.35,.5,1),height .4s,padding .4s}',
    '.rgn-island.rgn-in{transform:translate(-50%,0);height:48px;padding:0 18px 0 12px}',
    '.rgn-island .rgn-i{flex:none;display:flex;color:var(--rgn)}',
    '.rgn-island .rgn-m{overflow:hidden;text-overflow:ellipsis;opacity:0;transition:opacity .25s .2s}',
    '.rgn-island.rgn-in .rgn-m{opacity:1}',
    '.rgn-island.rgn-bump{animation:rgnBump .4s}',
    '@keyframes rgnBump{40%{transform:translate(-50%,0) scale(1.06)}}',
    /* snackbar */
    '.rgn-snack{position:fixed;left:16px;bottom:var(--rgn-bottom,24px);z-index:100050;display:flex;align-items:center;gap:18px;min-width:300px;max-width:calc(100vw - 32px);padding:12px 8px 12px 16px;border-radius:10px;background:#1f2937;color:#fff;font-family:inherit;font-size:14px;box-shadow:0 10px 28px -6px rgba(0,0,0,.45);opacity:0;transform:translateY(24px);transition:opacity .25s,transform .3s cubic-bezier(.2,.9,.3,1.1)}',
    '.rgn-snack.rgn-in{opacity:1;transform:none}',
    '.rgn-snack .rgn-m{flex:1;min-width:0}',
    '.rgn-snack button{flex:none;border:0;border-radius:8px;padding:8px 12px;background:none;color:#fdba74;font:inherit;font-weight:700;letter-spacing:.04em;text-transform:uppercase;cursor:pointer}',
    '.rgn-snack button:hover{background:rgba(255,255,255,.08)}',
    /* loading -> done */
    '.rgn-load{position:fixed;top:14px;left:50%;z-index:100055;display:flex;align-items:center;gap:10px;max-width:calc(100vw - 24px);padding:11px 18px 11px 14px;border:1px solid #e5e7eb;border-radius:999px;background:#fff;color:#111827;font-family:inherit;font-size:14px;font-weight:500;box-shadow:0 10px 30px -8px rgba(17,24,39,.25);opacity:0;transform:translate(-50%,-12px);transition:opacity .25s,transform .3s}',
    '.rgn-load.rgn-in{opacity:1;transform:translate(-50%,0)}',
    '.rgn-load.rgn-out{transform:translate(-50%,-12px)!important}',
    '.rgn-spin{width:18px;height:18px;flex:none;border:2.5px solid #e5e7eb;border-top-color:#f97316;border-radius:50%;animation:rgnSpin .7s linear infinite}',
    '@keyframes rgnSpin{to{transform:rotate(360deg)}}',
    '.rgn-load .rgn-i{display:flex;color:var(--rgn);animation:rgnPop .4s both}',
    '@keyframes rgnPop{from{transform:scale(0)}70%{transform:scale(1.15)}to{transform:none}}',
    /* popup / bottom sheet */
    '.rgn-ov{position:fixed;inset:0;z-index:100100;display:grid;place-items:center;padding:16px;background:rgba(15,23,42,.5);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px);opacity:0;transition:opacity .2s;font-family:inherit}',
    '.rgn-ov.rgn-in{opacity:1}',
    '.rgn-modal{position:relative;width:100%;max-width:400px;padding:30px 24px 22px;border-radius:22px;background:#fff;color:#111827;text-align:center;box-shadow:0 30px 70px -24px rgba(15,23,42,.55);transform:scale(.88);transition:transform .3s cubic-bezier(.2,.9,.3,1.3)}',
    '.rgn-ov.rgn-in .rgn-modal{transform:none}',
    '.rgn-handle{display:none;width:42px;height:5px;margin:-14px auto 16px;border-radius:9px;background:#d1d5db}',
    '.rgn-big{width:68px;height:68px;margin:0 auto 14px;display:grid;place-items:center;border-radius:50%;background:color-mix(in srgb,var(--rgn) 14%,#fff);color:var(--rgn);box-shadow:0 0 0 7px color-mix(in srgb,var(--rgn) 6%,#fff);animation:rgnPop .5s .08s both}',
    '.rgn-big .rgn-ic{width:34px;height:34px}',
    '.rgn-modal h4{margin:0 0 6px;font-size:19px;font-weight:700;line-height:1.3}',
    '.rgn-modal p{margin:0 auto;max-width:330px;color:#4b5563;font-size:14px;line-height:1.55;white-space:pre-line;overflow-wrap:anywhere}',
    '.rgn-row{display:flex;gap:10px;margin-top:22px}',
    '.rgn-btn{flex:1;height:46px;border:0;border-radius:12px;font:inherit;font-size:15px;font-weight:700;cursor:pointer;background:#f97316;color:#fff;box-shadow:0 10px 20px -12px rgba(249,115,22,.9)}',
    '.rgn-btn:hover{background:#ea580c}',
    '.rgn-btn.rgn-sec{background:#f3f4f6;color:#374151;box-shadow:none}.rgn-btn.rgn-sec:hover{background:#e5e7eb}',
    '.rgn-btn.rgn-danger{background:#dc2626;box-shadow:0 10px 20px -12px rgba(220,38,38,.8)}.rgn-btn.rgn-danger:hover{background:#b91c1c}',
    '@media (max-width:600px){',
    '.rgn-stack{top:10px;right:12px;left:12px;width:auto;max-width:none}',
    '.rgn-card{transform:translateY(-130%)}.rgn-card.rgn-out{transform:translateY(-130%)!important}',
    '.rgn-snack{left:12px;right:12px;min-width:0;bottom:var(--rgn-bottom-m,var(--rgn-bottom,16px))}',
    '.rgn-ov{place-items:end center;padding:0}',
    '.rgn-modal{max-width:none;border-radius:24px 24px 0 0;padding:26px 20px calc(20px + env(safe-area-inset-bottom));transform:translateY(100%);transition:transform .35s cubic-bezier(.2,.9,.3,1)}',
    '.rgn-handle{display:block}',
    '.rgn-row{flex-direction:column-reverse}',
    '.rgn-btn{flex:none;height:50px}',
    '}',
    '@media (prefers-reduced-motion:reduce){.rgn-card,.rgn-glass,.rgn-island,.rgn-snack,.rgn-load,.rgn-modal{transition:opacity .2s!important}.rgn-big,.rgn-load .rgn-i{animation:none}}'
  ].join('\n');

  var PATH = {
    success: '<circle cx="12" cy="12" r="10"/><path d="m8 12.5 2.7 2.7L16 9.5"/>',
    error: '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/>',
    warning: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
    info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
    question: '<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"/>'
  };
  var TITLES = { success: 'Done', error: 'Something went wrong', warning: 'Please check', info: 'Note' };
  var LIFE = { success: 3500, info: 4500, warning: 5500, error: 6500 };
  var cfg = { toast: 'card' };
  var pending = null;   // a "Saving…" pill waiting for the page's result toast

  function icon(t) { return '<svg class="rgn-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (PATH[t] || PATH.info) + '</svg>'; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function body(msg, o) { return o && o.html ? String(msg == null ? '' : msg) : esc(msg); }
  function kind(t, msg) {
    t = String(t || '').toLowerCase();
    if (t === 'danger' || t === 'fail' || t === 'failed' || t === 'err') return 'error';
    if (t === 'warn') return 'warning';
    if (TYPES_OK[t]) return t;
    var m = String(msg || '');
    return /error|fail|could ?n.t|cannot|invalid|not found|denied/i.test(m) ? 'error' : 'info';
  }
  var TYPES_OK = { success: 1, error: 1, warning: 1, info: 1 };
  function life(t, msg, o) {
    if (o && o.duration) return o.duration;
    var n = String(msg || '').length;
    return LIFE[t] + Math.max(0, n - 70) * 35;
  }
  function phone() { return window.matchMedia && window.matchMedia('(max-width: 600px)').matches; }
  function ready(fn) { if (document.body) fn(); else document.addEventListener('DOMContentLoaded', fn); }
  function inject() {
    if (document.getElementById('rgn-css')) return;
    var s = document.createElement('style'); s.id = 'rgn-css'; s.textContent = CSS;
    (document.head || document.documentElement).appendChild(s);
  }
  function enter(n) { requestAnimationFrame(function () { requestAnimationFrame(function () { n.classList.add('rgn-in'); }); }); }
  function leave(n, ms) {
    if (!n || n._gone) return; n._gone = true;
    n.classList.add('rgn-out'); n.classList.remove('rgn-in');
    setTimeout(function () { if (n.parentNode) n.parentNode.removeChild(n); }, ms || 320);
  }
  function el(cls, html) { var d = document.createElement('div'); d.className = cls; d.innerHTML = html; return d; }
  function stack() {
    var s = document.querySelector('.rgn-stack');
    if (!s) { s = el('rgn-stack', ''); s.setAttribute('aria-live', 'polite'); document.body.appendChild(s); }
    return s;
  }
  // close when the countdown animation ends; hovering / touching holds it
  function timed(n, ms, onEnd) {
    n.style.setProperty('--rgn-d', ms + 'ms');
    var bar = n.querySelector('.rgn-bar,.rgn-bar-x');
    bar.addEventListener('animationend', function () { onEnd(); });
    n.addEventListener('mouseenter', function () { n.classList.add('rgn-hold'); });
    n.addEventListener('mouseleave', function () { n.classList.remove('rgn-hold'); });
  }
  function restart(n) { var b = n.querySelector('.rgn-bar,.rgn-bar-x'); if (!b) return; b.style.animation = 'none'; void b.offsetWidth; b.style.animation = ''; }
  // swipe sideways (or up on phones) to dismiss
  function swipe(n, close) {
    var x0 = null, y0 = 0;
    n.addEventListener('pointerdown', function (e) { if (e.target.closest('button')) return; x0 = e.clientX; y0 = e.clientY; n.classList.add('rgn-hold'); });
    n.addEventListener('pointerup', function (e) {
      n.classList.remove('rgn-hold');
      if (x0 == null) return;
      var dx = e.clientX - x0, dy = e.clientY - y0; x0 = null;
      if (Math.abs(dx) > 70 || dy < -40) close();
    });
    n.addEventListener('pointercancel', function () { x0 = null; n.classList.remove('rgn-hold'); });
  }

  /* ── toasts ── */
  function card(msg, t, o, look) {
    var s = stack(), key = look + t + '|' + msg;
    var same = Array.prototype.filter.call(s.children, function (c) { return c._key === key && !c._gone; })[0];
    if (same) { restart(same); return same; }   // the same message again: keep one, restart its timer
    var title = o.title || (o.withTitle ? TITLES[t] : '');
    var n = el('rgn-' + look + ' rgn-c-' + t,
      '<div class="rgn-i">' + icon(t) + '</div>' +
      '<div class="rgn-txt">' + (title ? '<b>' + esc(title) + '</b>' : '') + '<span>' + body(msg, o) + '</span></div>' +
      '<button type="button" class="rgn-x" aria-label="Close">×</button>' +
      '<div class="' + (look === 'card' ? 'rgn-bar' : 'rgn-bar-x') + '"></div>');
    n._key = key;
    if (t === 'error') n.setAttribute('role', 'alert');
    var close = function () { leave(n, 400); };
    n.querySelector('.rgn-x').onclick = close;
    timed(n, life(t, msg, o), close);
    swipe(n, close);
    s.appendChild(n);
    var live = Array.prototype.filter.call(s.children, function (c) { return !c._gone; });
    for (var i = 0; i < live.length - 4; i++) leave(live[i]);   // keep the 4 newest
    enter(n);
    return n;
  }

  var islandNode = null, islandTimer = null;
  function island(msg, type, o) {
    o = o || {}; var t = kind(type, msg);
    if (!document.body) { ready(function () { island(msg, type, o); }); return; }
    inject();
    var n = islandNode;
    var html = '<span class="rgn-i">' + icon(t) + '</span><span class="rgn-m">' + body(msg, o) + '</span>';
    var fit = function () { n.style.width = Math.min(window.innerWidth - 24, n.querySelector('.rgn-m').scrollWidth + 62) + 'px'; };
    if (n && !n._gone) {   // already open: swap the text, resize and give it a little bounce
      n.className = 'rgn-island rgn-in rgn-c-' + t; n.innerHTML = html;
      fit(); void n.offsetWidth; n.classList.add('rgn-bump');
    } else {
      n = islandNode = el('rgn-island rgn-c-' + t, html);
      n.setAttribute('role', 'status'); n.setAttribute('aria-live', 'polite');
      n.addEventListener('click', function () { leave(n); document.documentElement.classList.remove('rgn-island-on'); });
      document.body.appendChild(n);
      document.documentElement.classList.add('rgn-island-on');   // corner toasts step down below it
      // drop in as a dot, then grow to fit the text (width can't animate to auto)
      requestAnimationFrame(function () { requestAnimationFrame(function () { n.classList.add('rgn-in'); fit(); }); });
    }
    var cur = n;
    clearTimeout(islandTimer);
    islandTimer = setTimeout(function () { leave(cur, 450); document.documentElement.classList.remove('rgn-island-on'); }, o.duration || Math.min(LIFE[t], 3200));
    return n;
  }

  function toast(msg, type, o) {
    o = o || {}; var t = kind(type, msg);
    if (!document.body) { ready(function () { toast(msg, type, o); }); return; }
    inject();
    if (pending && !o.style) { var p = pending; pending = null; p.done(o.html ? stripTags(msg) : msg, t); return; }
    var look = o.style || cfg.toast;
    if (look === 'web') look = (t === 'success' || t === 'info') && String(msg).length <= 70 ? 'island' : 'glass';
    if (look === 'card' && phone() && t === 'success' && String(msg).length <= 50 && !o.title) look = 'island';
    if (look === 'island') return island(msg, t, o);
    return card(msg, t, o, look === 'glass' ? 'glass' : 'card');
  }
  function stripTags(s) { var d = document.createElement('div'); d.innerHTML = String(s); return d.textContent; }

  /* ── snackbar ── */
  var snackNode = null;
  function snack(msg, o) {
    o = o || {};
    if (!document.body) { ready(function () { snack(msg, o); }); return; }
    inject();
    if (snackNode) leave(snackNode, 10);
    var n = snackNode = el('rgn-snack', '<span class="rgn-m">' + body(msg, o) + '</span>' + (o.action ? '<button type="button">' + esc(o.action) + '</button>' : '') + '<div class="rgn-bar-x"></div>');
    n.setAttribute('role', 'status');
    var close = function () { leave(n); if (snackNode === n) snackNode = null; };
    if (o.action) n.querySelector('button').onclick = function () { close(); if (o.onAction) o.onAction(); };
    timed(n, o.duration || 5000, function () { close(); if (o.onTimeout) o.onTimeout(); });
    swipe(n, close);
    document.body.appendChild(n);
    enter(n);
    return { close: close };
  }

  /* ── loading -> done ── */
  function loading(msg) {
    inject();
    var n = el('rgn-load', '<span class="rgn-spin"></span><span class="rgn-m">' + esc(msg || 'Saving…') + '</span>');
    n.setAttribute('role', 'status'); n.setAttribute('aria-live', 'polite');
    ready(function () { document.body.appendChild(n); enter(n); });
    var h = {
      done: function (m, type) {
        if (h.finished) return; h.finished = true;
        if (pending === h) pending = null;
        clearTimeout(h._settle);
        var t = kind(type, m);
        n.className = 'rgn-load rgn-in rgn-c-' + t;
        if (t === 'error') n.setAttribute('role', 'alert');
        n.innerHTML = '<span class="rgn-i">' + icon(t) + '</span><span class="rgn-m">' + esc(m) + '</span>';
        setTimeout(function () { leave(n); }, life(t, m) - 800);
      },
      success: function (m) { h.done(m || 'Saved', 'success'); },
      error: function (m) { h.done(m || 'Something went wrong', 'error'); },
      info: function (m) { h.done(m, 'info'); },
      close: function () { h.finished = true; if (pending === h) pending = null; leave(n); }
    };
    return h;
  }
  function promise(p, o) {
    o = o || {};
    var h = loading(o.loading);
    return Promise.resolve(p).then(function (v) {
      h.success(typeof o.success === 'function' ? o.success(v) : o.success); return v;
    }, function (e) {
      h.error(typeof o.error === 'function' ? o.error(e) : (o.error || (e && e.message))); throw e;
    });
  }

  // fetch() wrapper for the dashboard: only saves the user just started (a click / key in the last 1.5 s),
  // only when they take longer than 450 ms, never GETs or background polling.
  function watchSaves(opts) {
    if (watchSaves.on || !window.fetch) return; watchSaves.on = true;
    opts = opts || {};
    var skip = opts.skip || /get_|load|fetch|list|search|poll|latest|check|heartbeat|ping|track|status_check|count|stats|log_|preview|suggest/i;
    var act = 0;
    ['pointerdown', 'keydown'].forEach(function (e) { document.addEventListener(e, function () { act = Date.now(); }, true); });
    var orig = window.fetch;
    window.fetch = function (input, init) {
      var p = orig.apply(this, arguments);
      try {
        var method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
        var url = typeof input === 'string' ? input : (input && input.url) || String(input);
        if (method === 'GET' || Date.now() - act > 1500 || skip.test(url) || document.querySelector('.rgn-load')) return p;
        var word = method === 'DELETE' || /delete|remove/i.test(url) ? 'Deleting…' : /upload|image|photo|logo/i.test(url) ? 'Uploading…' : 'Saving…';
        var h = null;
        var t = setTimeout(function () { h = loading(word); pending = h; }, 450);
        var settle = function () {
          clearTimeout(t);
          if (!h || h.finished) return;
          // give the page a moment to show its own result toast, which then lands in this pill
          h._settle = setTimeout(function () { if (!h.finished) h.close(); }, 1200);
        };
        p.then(settle, settle);
      } catch (e) {}
      return p;
    };
  }

  /* ── popup / bottom sheet ── */
  var openModals = [];
  function modal(o) {
    return new Promise(function (resolve) {
      ready(function () {
        inject();
        var t = o.tone || 'info';
        var ov = el('rgn-ov', '');
        var m = el('rgn-modal rgn-c-' + (t === 'question' ? 'warning' : t),
          '<div class="rgn-handle"></div>' +
          '<div class="rgn-big">' + icon(t) + '</div>' +
          '<h4>' + esc(o.title) + '</h4>' +
          (o.text ? '<p>' + (o.html ? o.text : esc(o.text)) + '</p>' : '') +
          '<div class="rgn-row">' + (o.cancelText ? '<button type="button" class="rgn-btn rgn-sec" data-v="0">' + esc(o.cancelText) + '</button>' : '') +
          '<button type="button" class="rgn-btn' + (o.danger ? ' rgn-danger' : '') + '" data-v="1">' + esc(o.confirmText || 'OK') + '</button></div>');
        m.setAttribute('role', o.cancelText ? 'alertdialog' : 'dialog');
        m.setAttribute('aria-modal', 'true');
        ov.appendChild(m);
        document.body.appendChild(ov);
        openModals.push(ov);
        var prev = document.activeElement;
        var done = false;
        var close = function (v) {
          if (done) return; done = true;
          openModals.splice(openModals.indexOf(ov), 1);
          document.removeEventListener('keydown', key, true);
          leave(ov, 300);
          if (prev && prev.focus) try { prev.focus(); } catch (e) {}
          resolve(v);
        };
        var key = function (e) {
          if (openModals[openModals.length - 1] !== ov) return;
          if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(false); }
        };
        document.addEventListener('keydown', key, true);
        ov.addEventListener('click', function (e) {
          if (e.target === ov) return close(false);
          var b = e.target.closest('[data-v]'); if (b) close(b.getAttribute('data-v') === '1');
        });
        enter(ov);
        setTimeout(function () { var ok = m.querySelector('[data-v="1"]'); if (ok) ok.focus(); }, 60);
      });
    });
  }
  function alertBox(msg, type, o) {
    o = o || {}; var t = kind(type, msg);
    if (!type && t === 'info' && /saved|success|updated|done|created|added|sent/i.test(String(msg))) t = 'success';
    return modal({ tone: t, title: o.title || TITLES[t], text: String(msg == null ? '' : msg), html: o.html, confirmText: o.confirmText || 'OK' });
  }
  function confirmBox(msg, o) {
    o = typeof o === 'string' ? { title: o } : (o || {});
    var danger = o.danger != null ? o.danger : /cancel|delete|discard|remove|reject|refund|clear|log ?out/i.test((o.title || '') + ' ' + msg);
    return modal({
      tone: danger ? 'error' : 'question', danger: danger, title: o.title || 'Are you sure?', text: msg, html: o.html,
      confirmText: o.confirmText || (danger ? 'Yes, continue' : 'Yes'), cancelText: o.cancelText || 'No, go back'
    });
  }

  window.RGNotify = {
    setup: function (o) { for (var k in o) cfg[k] = o[k]; if (o && o.watchSaves) watchSaves(); return this; },
    toast: toast, island: island, snack: snack, loading: loading, promise: promise,
    alert: alertBox, confirm: confirmBox, watchSaves: watchSaves,
    success: function (m, o) { return toast(m, 'success', o); },
    error: function (m, o) { return toast(m, 'error', o); },
    warning: function (m, o) { return toast(m, 'warning', o); },
    info: function (m, o) { return toast(m, 'info', o); }
  };
  inject();
})();
