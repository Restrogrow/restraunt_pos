<?php
/**
 * The customer website's network safety net, in every page's <head> (header.php puts it there, so a new page
 * gets it without doing anything; about 2 KB):
 *
 *  - fetch() of this site's own GET / HEAD URLs tries again on a dropped connection or a busy server (502, 503,
 *    504, 429): after 0.6 s, then 1.5 s, and while the phone is offline it first waits for the connection.
 *    POST and the rest (placing an order, paying) are never sent twice. {rgNoRetry: true} in the options
 *    turns it off for one call.
 *  - rgFetchJson(url, options, tries): fetch + JSON that also tries again on no answer within 12 s and on an
 *    answer that isn't JSON (a host error page). A JSON answer, even {success:false}, is the server's real
 *    answer and is returned as it is.
 *  - rgLoadFailed(el, 'the menu', retry): "Couldn't load …" with a Try again button in el, which also tries
 *    again by itself when the connection comes back.
 *  - A small "You're offline" bar while the phone has no connection.
 *  - Phone boxes (type="tel") get the number keypad and keep only digits.
 */
if (!function_exists('rgNetScript')) {
    function rgNetScript(): string {
        return <<<'JS'
<script>
(function () {
  if (window.rgFetchJson) return;
  var nativeFetch = window.fetch ? window.fetch.bind(window) : null;
  function sleep(ms) { return new Promise(function (ok) { setTimeout(ok, ms); }); }
  function online() {
    if (navigator.onLine !== false) return Promise.resolve();
    return new Promise(function (ok) {
      function back() { window.removeEventListener('online', back); ok(); }
      window.addEventListener('online', back);
      setTimeout(back, 15000);   // onLine is not always right: try anyway after a while
    });
  }
  function wait(n) { return sleep(n === 1 ? 600 : 1500).then(online); }

  if (nativeFetch) window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url) || String(input);
    var method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
    var same = false;
    try { same = new URL(url, location.href).origin === location.origin; } catch (e) {}
    if (!same || (method !== 'GET' && method !== 'HEAD') || (init && init.rgNoRetry) || (typeof Request !== 'undefined' && input instanceof Request)) {
      return nativeFetch(input, init);
    }
    var n = 0;
    function go() {
      n++;
      return nativeFetch(input, init).then(function (r) {
        if ((r.status === 502 || r.status === 503 || r.status === 504 || r.status === 429) && n < 3) return wait(n).then(go);
        return r;
      }, function (err) {
        if ((err && err.name === 'AbortError') || n >= 3) throw err;
        return wait(n).then(go);
      });
    }
    return go();
  };

  window.rgFetchJson = function (url, options, tries) {
    tries = tries || 3;
    var attempt = 0;
    function once() {
      attempt++;
      var ctl = window.AbortController ? new AbortController() : null;
      var timer = ctl ? setTimeout(function () { ctl.abort(); }, 12000) : null;
      var opts = Object.assign({}, options || {});
      if (ctl && !opts.signal) opts.signal = ctl.signal;
      return online().then(function () { return (nativeFetch || fetch)(url, opts); }).then(function (r) {
        if (timer) clearTimeout(timer);
        if (r.status >= 500 || r.status === 429) throw new Error('Server busy (' + r.status + ')');
        return r.text().then(function (t) {
          try { return JSON.parse(t); } catch (e) { throw new Error('Unexpected answer from the server'); }
        });
      }).catch(function (err) {
        if (timer) clearTimeout(timer);
        if (attempt >= tries) throw err;
        return wait(attempt).then(once);
      });
    }
    return once();
  };

  window.rgLoadFailed = function (el, what, retry) {
    if (!el) return;
    el.innerHTML = '<div style="text-align:center;padding:36px 20px;color:#6b7280;font-size:14px;line-height:1.5">'
      + '<div style="font-size:28px;margin-bottom:6px">&#128246;</div>'
      + 'Couldn\'t load ' + what + '. Please check your internet connection.'
      + '<br><button type="button" style="margin-top:12px;padding:9px 20px;border:0;border-radius:10px;background:var(--primary-red,#111);color:#fff;font-weight:600;font-size:14px;cursor:pointer">Try again</button></div>';
    var done = false;
    function again() {
      if (done) return; done = true;
      window.removeEventListener('online', again);
      el.innerHTML = '<div style="text-align:center;padding:36px;color:#9ca3af;font-size:14px">Loading…</div>';
      retry();
    }
    el.querySelector('button').onclick = again;
    window.addEventListener('online', again);
  };

  var bar = null, hideTimer = null;
  function note(off) {
    if (!document.body) return;
    if (!bar) {
      bar = document.createElement('div');
      bar.setAttribute('role', 'status');
      bar.style.cssText = 'position:fixed;left:50%;top:10px;transform:translateX(-50%);z-index:2147483000;padding:8px 16px;border-radius:999px;'
        + 'font:600 13px/1.3 Poppins,system-ui,sans-serif;color:#fff;box-shadow:0 4px 14px rgba(0,0,0,.18);transition:opacity .3s;pointer-events:none';
      document.body.appendChild(bar);
    }
    clearTimeout(hideTimer);
    bar.style.background = off ? '#374151' : '#15803d';
    bar.textContent = off ? 'You’re offline — reconnecting…' : 'Back online';
    bar.style.opacity = '1';
    if (!off) hideTimer = setTimeout(function () { bar.style.opacity = '0'; }, 2000);
  }
  window.addEventListener('offline', function () { note(true); });
  window.addEventListener('online', function () { if (bar) note(false); });
  document.addEventListener('DOMContentLoaded', function () { if (navigator.onLine === false) note(true); });
})();
// Phone boxes (type="tel") on every page: the number keypad, and digits only, so a browser's saved username
// can't end up as the phone number
document.addEventListener('focusin', function (e) { var t = e.target; if (t && t.type === 'tel' && !t.inputMode) t.inputMode = 'numeric'; });
document.addEventListener('input', function (e) {
  var t = e.target;
  if (!t || t.type !== 'tel' || t.hasAttribute('data-any-text')) return;
  var v = t.value, d = v.replace(/\D/g, '');
  if (d !== v) t.value = d;
}, true);
// Esc closes the popup on top (dish sheet, checkout, offer and other pop-ups), through its own close button
document.addEventListener('keydown', function (e) {
  if (e.key !== 'Escape' || e.defaultPrevented) return;
  var open = Array.prototype.filter.call(document.querySelectorAll('.product-sheet-overlay.active, .modal-overlay, .coupon-overlay, .feedback-modal-overlay'), function (el) {
    var cs = getComputedStyle(el);
    return cs.display !== 'none' && cs.visibility !== 'hidden' && parseFloat(cs.opacity) > 0.05 && el.getClientRects().length > 0;
  });
  var top = open[open.length - 1];
  if (!top) return;
  var x = top.querySelector('.product-sheet-close, .modal-close, .close-btn, .btn-close, [aria-label="Close"]');
  if (x) x.click();
  else if (top.classList.contains('product-sheet-overlay') && typeof window.closeProductSheet === 'function') window.closeProductSheet();
  else if (top.classList.contains('modal-overlay') && !top.id) top.remove();   // the pop-ups made on the fly close by removing them
  else return;
  e.preventDefault();
});
</script>
JS;
    }
}
