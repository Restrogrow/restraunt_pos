<?php
/**
 * rgFetchJson(url, options, tries): fetch + JSON for the customer website that rides out a bad connection.
 *
 * Tries again (0.6 s, then 1.5 s) on a network error, no answer within 12 s, a 5xx / 429, or an answer that
 * isn't JSON (a host error page); while the phone is offline it waits for the connection to come back first.
 * A JSON answer, even {success:false}, is returned as it is: that is the server's real answer, and asking
 * again would not change it. Rejects with the last error once every try failed.
 *
 * Inline in <head> (about 1 KB), so a page's own scripts can use it straight away:
 *   <?php require_once __DIR__ . '/rg_net.php'; echo rgNetScript(); ?>
 */
if (!function_exists('rgNetScript')) {
    function rgNetScript(): string {
        return <<<'JS'
<script>
window.rgFetchJson = window.rgFetchJson || function (url, options, tries) {
  tries = tries || 3;
  var attempt = 0;
  function online() {
    if (navigator.onLine !== false) return Promise.resolve();
    return new Promise(function (ok) {
      function back() { window.removeEventListener('online', back); ok(); }
      window.addEventListener('online', back);
      setTimeout(back, 15000);   // onLine is not always right: try anyway after a while
    });
  }
  function once() {
    attempt++;
    var ctl = window.AbortController ? new AbortController() : null;
    var timer = ctl ? setTimeout(function () { ctl.abort(); }, 12000) : null;
    var opts = Object.assign({}, options || {});
    if (ctl && !opts.signal) opts.signal = ctl.signal;
    return online().then(function () { return fetch(url, opts); }).then(function (r) {
      if (timer) clearTimeout(timer);
      if (r.status >= 500 || r.status === 429) throw new Error('Server busy (' + r.status + ')');
      return r.text().then(function (t) {
        try { return JSON.parse(t); } catch (e) { throw new Error('Unexpected answer from the server'); }
      });
    }).catch(function (err) {
      if (timer) clearTimeout(timer);
      if (attempt >= tries) throw err;
      return new Promise(function (ok) { setTimeout(ok, attempt === 1 ? 600 : 1500); }).then(once);
    });
  }
  return once();
};
// "Couldn't load" box with a Try again button; also tries again by itself when the connection comes back
window.rgLoadFailed = window.rgLoadFailed || function (el, what, retry) {
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
</script>
JS;
    }
}
