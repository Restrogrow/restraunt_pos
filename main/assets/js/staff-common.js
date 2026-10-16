/*
 * Shared helpers for the staff screens (views/waiter_dashboard.php, chef_dashboard.php,
 * manager_dashboard.php). Needs window.STAFF = { restaurantId, serverNow, currency } from the page.
 *
 *  StaffUI.toast(msg, type, opts)   RGNotify toast (same look as the owner dashboard), fallback built in
 *  StaffUI.confirm(msg, opts)       Promise<boolean>
 *  StaffUI.esc(str)                 HTML-escape anything that came from a customer / the API
 *  StaffUI.since(ts) / fmtAge(sec)  live timers that use the server clock, not the device clock
 *  StaffUI.sound.play()             new call / new ticket chime (+ vibrate), on/off toggle remembered
 *  StaffUI.push.enable(btn)         web-push sign up for this device (the old "Enable Alerts" button)
 *  StaffUI.every(fn, ms)            polling that pauses while the tab is hidden
 */
(function () {
  'use strict';
  var S = window.StaffUI = window.StaffUI || {};
  var C = window.STAFF || {};
  S.cfg = C;
  S.rid = C.restaurantId ? encodeURIComponent(C.restaurantId) : '';

  /* ── text ── */
  S.esc = function (v) {
    if (v === null || v === undefined) return '';
    return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  };
  S.money = function (n) {
    var v = parseFloat(n || 0);
    var cur = C.currency || window.globalCurrencySymbol || '₹';
    return cur + v.toLocaleString(undefined, { minimumFractionDigits: v % 1 ? 2 : 0, maximumFractionDigits: 2 });
  };
  S.initials = function (name) {
    var p = String(name || '').trim().split(/\s+/);
    return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase();
  };

  /* ── toasts / confirm ── */
  S.toast = function (msg, type, o) {
    o = o || {};
    if (window.RGNotify) return window.RGNotify.toast(msg, type || 'info', o);
    var box = document.getElementById('stToastFallback');
    if (!box) {
      box = document.createElement('div'); box.id = 'stToastFallback';
      box.style.cssText = 'position:fixed;left:50%;bottom:90px;transform:translateX(-50%);z-index:100050;display:flex;flex-direction:column;gap:8px;width:min(420px,calc(100vw - 24px))';
      document.body.appendChild(box);
    }
    var n = document.createElement('div');
    var col = { success: '#16a34a', error: '#dc2626', warning: '#d97706' }[type] || '#2563eb';
    n.style.cssText = 'padding:12px 16px;border-radius:14px;background:#111827;color:#fff;font-weight:600;font-size:.9rem;box-shadow:0 16px 30px -12px rgba(0,0,0,.5);border-left:5px solid ' + col;
    n.setAttribute('role', 'status');
    if (o.html) n.innerHTML = msg; else n.textContent = msg;
    box.appendChild(n);
    setTimeout(function () { n.remove(); }, o.duration || 3800);
  };
  S.confirm = function (msg, o) {
    o = o || {};
    if (window.RGNotify) return window.RGNotify.confirm(msg, o);
    return new Promise(function (res) {
      var m = document.createElement('div');
      m.className = 'st-modal';
      m.innerHTML = '<div class="st-sheet" role="dialog" aria-modal="true" style="padding:20px">' +
        '<b style="display:block;font-size:1.1rem;margin-bottom:6px">' + S.esc(o.title || 'Are you sure?') + '</b>' +
        '<p style="margin:0 0 18px;color:#4b5563">' + (o.html ? msg : S.esc(msg)) + '</p>' +
        '<div style="display:flex;gap:8px;justify-content:flex-end"><button class="st-btn ghost" data-v="0">' + S.esc(o.cancelText || 'Cancel') + '</button>' +
        '<button class="st-btn" data-v="1">' + S.esc(o.confirmText || 'Yes') + '</button></div></div>';
      function done(v) { m.remove(); document.removeEventListener('keydown', key); res(v); }
      function key(e) { if (e.key === 'Escape') done(false); }
      m.addEventListener('click', function (e) { var b = e.target.closest('[data-v]'); if (b) done(b.dataset.v === '1'); else if (e.target === m) done(false); });
      document.addEventListener('keydown', key);
      document.body.appendChild(m);
      m.querySelector('[data-v="1"]').focus();
    });
  };

  /* ── time: everything is measured against the server's clock (restaurant timezone) ── */
  function parseLocal(s) {
    if (!s) return NaN;
    if (typeof s === 'number') return s;
    var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
    if (!m || /[zZ]|[+-]\d{2}:?\d{2}$/.test(s)) return Date.parse(s);
    return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)).getTime();
  }
  var skew = 0;
  if (C.serverNow) { var sn = parseLocal(C.serverNow); if (!isNaN(sn)) skew = Date.now() - sn; }
  S.parseTime = parseLocal;
  S.now = function () { return Date.now() - skew; };
  S.since = function (ts) { var t = parseLocal(ts); return isNaN(t) ? 0 : Math.max(0, Math.floor((S.now() - t) / 1000)); };
  S.fmtAge = function (sec) {
    if (sec < 60) return sec + 's';
    var m = Math.floor(sec / 60);
    if (m < 60) return m + ' min';
    var h = Math.floor(m / 60); m = m % 60;
    if (h < 24) return h + 'h ' + (m < 10 ? '0' : '') + m + 'm';
    return Math.floor(h / 24) + 'd ' + (h % 24) + 'h';
  };
  S.clock = function (ts) {
    var t = parseLocal(ts); if (isNaN(t)) return '';
    return new Date(t).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  };
  S.ageClass = function (sec, warnMin, lateMin) { return sec >= lateMin * 60 ? 'late' : (sec >= warnMin * 60 ? 'warn' : 'ok'); };
  // <span class="st-age" data-since="..." data-warn="10" data-late="20" data-host=".kds-t"> -> text + colour kept live
  S.ageHtml = function (ts, warnMin, lateMin, hostSel, label) {
    var sec = S.since(ts), cls = S.ageClass(sec, warnMin, lateMin);
    return '<span class="st-age ' + cls + '" data-since="' + S.esc(ts) + '" data-warn="' + warnMin + '" data-late="' + lateMin + '"' + (hostSel ? ' data-host="' + hostSel + '"' : '') +
      ' title="Waiting time"><span class="material-symbols-rounded" aria-hidden="true">timer</span><span class="st-age-t">' + (label || '') + S.fmtAge(sec) + '</span></span>';
  };
  S.tick = function (root) {
    (root || document).querySelectorAll('.st-age[data-since]').forEach(function (el) {
      var sec = S.since(el.getAttribute('data-since'));
      var cls = S.ageClass(sec, +el.getAttribute('data-warn') || 10, +el.getAttribute('data-late') || 20);
      var t = el.querySelector('.st-age-t'); if (t) t.textContent = S.fmtAge(sec);
      el.classList.remove('ok', 'warn', 'late'); el.classList.add(cls);
      var hs = el.getAttribute('data-host'), host = hs && el.closest(hs);
      if (host) { host.classList.toggle('warn', cls === 'warn'); host.classList.toggle('late', cls === 'late'); }
    });
  };
  setInterval(function () { if (!document.hidden) S.tick(); }, 5000);

  /* ── "Live · updated 8:41 pm" pill in the top bar ── */
  S.setLive = function (ok) {
    var el = document.getElementById('stLive'); if (!el) return;
    el.classList.toggle('off', ok === false);
    var t = el.querySelector('span');
    if (t) t.textContent = ok === false ? 'Offline – retrying' : 'Live · ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  };

  /* ── fetch JSON ── */
  S.getJSON = function (url) {
    return fetch(url, { cache: 'no-store', credentials: 'same-origin' }).then(function (r) {
      if (r.status === 401 || r.status === 403) { S.setLive(false); }
      return r.json();
    }).then(function (j) { S.setLive(true); return j; }, function (e) { S.setLive(false); throw e; });
  };
  S.postForm = function (url, data) {
    var body = Object.keys(data).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }).join('&');
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body }).then(function (r) { return r.json(); });
  };

  /* ── polling that sleeps while the tab is hidden ── */
  S.every = function (fn, ms) {
    var timer = null, cur = ms;
    function run() { if (document.hidden) return; try { fn(); } catch (e) { console.error(e); } }
    function arm() { clearInterval(timer); timer = setInterval(run, cur); }
    document.addEventListener('visibilitychange', function () { if (document.hidden) { clearInterval(timer); timer = null; } else { run(); arm(); } });
    arm();
    return { setMs: function (v) { if (v !== cur) { cur = v; arm(); } }, now: run };
  };

  /* ── sound: chime for a new call / ticket. Browsers only allow audio after the first tap,
     so the element is "unlocked" on the first touch anywhere on the page. ── */
  var SOUND_KEY = 'staffSound';
  var audio = null, unlocked = false;
  function soundOn() { try { return localStorage.getItem(SOUND_KEY) !== '0'; } catch (e) { return true; } }
  function getAudio() { if (!audio) { audio = new Audio(C.soundUrl || '../../assets/sounds/notification.wav'); audio.preload = 'auto'; audio.volume = 0.8; } return audio; }
  function unlock() {
    if (unlocked) return; unlocked = true;
    try { var a = getAudio(); a.muted = true; var p = a.play(); if (p && p.then) p.then(function () { a.pause(); a.currentTime = 0; a.muted = false; }, function () { a.muted = false; unlocked = false; }); } catch (e) { unlocked = false; }
  }
  ['pointerdown', 'keydown', 'touchstart'].forEach(function (ev) { document.addEventListener(ev, unlock, { passive: true }); });
  function beep() {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext; if (!Ctx) return;
      var ctx = S._ctx || (S._ctx = new Ctx());
      [0, 0.18].forEach(function (d, i) {
        var o = ctx.createOscillator(), g = ctx.createGain();
        o.type = 'sine'; o.frequency.value = i ? 1175 : 880;
        g.gain.setValueAtTime(0.0001, ctx.currentTime + d); g.gain.exponentialRampToValueAtTime(0.35, ctx.currentTime + d + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + d + 0.3);
        o.connect(g); g.connect(ctx.destination); o.start(ctx.currentTime + d); o.stop(ctx.currentTime + d + 0.32);
      });
    } catch (e) { }
  }
  S.sound = {
    enabled: soundOn,
    play: function () {
      if (navigator.vibrate) { try { navigator.vibrate([180, 80, 180]); } catch (e) { } }
      if (!soundOn()) return;
      try {
        var a = getAudio(); a.currentTime = 0;
        var p = a.play(); if (p && p.catch) p.catch(beep);
        setTimeout(function () { try { a.pause(); a.currentTime = 0; } catch (e) { } }, 2600);
      } catch (e) { beep(); }
    },
    toggle: function () {
      var on = !soundOn();
      try { localStorage.setItem(SOUND_KEY, on ? '1' : '0'); } catch (e) { }
      S.sound.paint();
      S.toast(on ? 'Sound on – you will hear new calls and tickets' : 'Sound off', on ? 'success' : 'info');
      if (on) S.sound.play();
    },
    paint: function () {
      var b = document.getElementById('stSoundBtn'); if (!b) return;
      var on = soundOn();
      b.classList.toggle('on', on); b.classList.toggle('muted', !on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.setAttribute('aria-label', on ? 'Sound on – tap to mute' : 'Sound off – tap to turn on');
      b.title = on ? 'Sound on' : 'Sound off';
      b.querySelector('.material-symbols-rounded').textContent = on ? 'campaign' : 'notifications_off';
    }
  };

  /* ── tab title: "(3) Waiter …" while something is waiting ── */
  var baseTitle = document.title;
  S.titleCount = function (n) { document.title = (n > 0 ? '(' + n + ') ' : '') + baseTitle; };

  /* ── web push for this device (same flow as before: sw.php, VAPID key, save subscription) ── */
  function vapidToUint8(b64) {
    var pad = '='.repeat((4 - b64.length % 4) % 4), raw = window.atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    var out = new Uint8Array(raw.length); for (var i = 0; i < raw.length; ++i) out[i] = raw.charCodeAt(i); return out;
  }
  function paintAlerts(btn, state) {
    if (!btn) return;
    var label = btn.querySelector('.st-alerts-l'), icon = btn.querySelector('.material-symbols-rounded');
    btn.disabled = state === 'busy';
    btn.classList.toggle('on', state === 'on');
    if (label) label.textContent = state === 'on' ? 'Alerts on' : (state === 'busy' ? 'Turning on…' : 'Turn on alerts');
    if (icon) icon.textContent = state === 'on' ? 'notifications_active' : 'notifications';
    btn.setAttribute('aria-label', state === 'on' ? 'Phone alerts are on' : 'Turn on phone alerts');
  }
  S.push = {
    enable: async function (btn, silent) {
      if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        if (!silent) S.toast('This browser can’t show alerts. On iPhone, add this page to the Home Screen first.', 'warning', { duration: 6000 });
        return;
      }
      paintAlerts(btn, 'busy');
      try {
        var perm = Notification.permission;
        if (perm !== 'granted') perm = await Notification.requestPermission();
        if (perm !== 'granted') { paintAlerts(btn, 'off'); if (!silent) S.toast('Alerts are blocked. Allow notifications for this site in your browser settings.', 'warning', { duration: 6000 }); return; }
        var reg = await navigator.serviceWorker.register('../website/sw.php?v=11', { scope: '../' });
        reg = await navigator.serviceWorker.ready;
        var sub = await reg.pushManager.getSubscription();
        if (!sub) {
          var key = ((await (await fetch('../api/get_vapid_key.php', { cache: 'no-store' })).json()).publicKey || '');
          if (!key) { paintAlerts(btn, 'off'); if (!silent) S.toast('Phone alerts are not set up on the server yet.', 'warning'); return; }
          sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: vapidToUint8(key) });
        }
        var res = await fetch('../api/save_push_subscription.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(sub.toJSON ? sub.toJSON() : sub) });
        var data = await res.json();
        if (data.success) {
          try { localStorage.setItem('staffPushEnabled', '1'); } catch (e) { }
          paintAlerts(btn, 'on');
          if (!silent) S.toast('Alerts on for this device', 'success');
        } else {
          paintAlerts(btn, 'off');
          if (!silent) S.toast('Could not save alerts: ' + (data.message || 'unknown error'), 'error');
        }
      } catch (e) {
        console.error('Push subscribe error', e);
        paintAlerts(btn, 'off');
        if (!silent) S.toast('Could not turn on alerts: ' + e.message, 'error');
      }
    },
    // later visits: quietly re-subscribe once permission was given before
    resume: function (btn) {
      var was = false; try { was = localStorage.getItem('staffPushEnabled') === '1'; } catch (e) { }
      if ('Notification' in window && Notification.permission === 'granted' && was) S.push.enable(btn, true);
    }
  };
  // old inline name, kept for anything that still calls it
  window.enableStaffPushAlerts = function (btn) { return S.push.enable(btn); };

  /* ── wire the shared top bar ── */
  function initTop() {
    S.sound.paint();
    var sb = document.getElementById('stSoundBtn'); if (sb) sb.addEventListener('click', S.sound.toggle);
    var ab = document.getElementById('enableAlertsBtn');
    if (ab) { ab.addEventListener('click', function () { if (ab.classList.contains('on')) { S.toast('Alerts are already on for this device', 'info'); return; } S.push.enable(ab); }); S.push.resume(ab); }
    var lo = document.getElementById('stLogout');
    if (lo) lo.addEventListener('click', function (e) {
      e.preventDefault();
      S.confirm('You will need your password to sign in again.', { title: 'Log out?', confirmText: 'Log out', cancelText: 'Stay' }).then(function (ok) { if (ok) location.href = lo.href; });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initTop); else initTop();

  /* ── tabs: desktop segmented bar + phone bottom bar share data-tab ── */
  S.tabs = function (opts) {
    var cur = null;
    function go(name, quiet) {
      if (!document.getElementById(opts.prefix + name)) name = opts.initial;
      cur = name;
      document.querySelectorAll('[data-tab]').forEach(function (b) {
        var on = b.getAttribute('data-tab') === name; b.classList.toggle('on', on);
        if (b.getAttribute('role') === 'tab') b.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      document.querySelectorAll('.st-view').forEach(function (v) { v.classList.toggle('on', v.id === opts.prefix + name); });
      try { history.replaceState(null, '', '#' + name); } catch (e) { }
      try { localStorage.setItem(opts.key, name); } catch (e) { }
      if (!quiet) window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
      if (opts.onChange) opts.onChange(name);
    }
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-tab]'); if (!b) return;
      e.preventDefault(); go(b.getAttribute('data-tab'));
    });
    var start = (location.hash || '').slice(1);
    if (!start) { try { start = localStorage.getItem(opts.key) || ''; } catch (e) { } }
    go(start || opts.initial, true);
    return { go: go, current: function () { return cur; } };
  };
  S.badge = function (sel, n, hot) {
    document.querySelectorAll(sel).forEach(function (el) { el.textContent = n > 0 ? (n > 99 ? '99+' : String(n)) : ''; el.classList.toggle('hot', !!hot && n > 0); });
  };
  S.skeleton = function (kind, n) {
    var s = ''; for (var i = 0; i < (n || 3); i++) s += '<div class="st-skel h-' + kind + '"></div>'; return s;
  };
})();
