/**
 * POS debug panel (temporary, to find why Pay doesn't give a bill on the live site).
 * Shows each Pay step, every pos_operations.php request with the server's raw reply, and any
 * JavaScript error on the page. A "POS debug" pill sits bottom-left; the panel opens by itself
 * on an error. "Copy" puts the whole log on the clipboard to send to support.
 * Loaded without defer, before pos-offline.js, so its fetch wrapper sees the POS requests.
 */
(function () {
  var lines = [];
  var panel, list, pill;

  function ts() { var d = new Date(); return d.toTimeString().slice(0, 8) + '.' + String(d.getMilliseconds()).padStart(3, '0'); }
  function str(v) {
    if (v === undefined) return '';
    if (typeof v === 'string') return v;
    if (v instanceof Error) return v.name + ': ' + v.message + (v.stack ? '\n' + v.stack.split('\n').slice(1, 4).join('\n') : '');
    try { return JSON.stringify(v); } catch (e) { return String(v); }
  }

  function build() {
    if (panel || !document.body) return;
    pill = document.createElement('button');
    pill.type = 'button';
    pill.textContent = 'POS debug';
    pill.style.cssText = 'position:fixed;left:8px;bottom:8px;z-index:2147483646;background:#111827;color:#fff;border:0;border-radius:999px;padding:6px 12px;font:600 12px system-ui;opacity:.85;cursor:pointer';
    pill.onclick = function () { panel.style.display = panel.style.display === 'none' ? 'flex' : 'none'; };
    panel = document.createElement('div');
    panel.style.cssText = 'position:fixed;left:8px;bottom:44px;width:min(560px,calc(100vw - 16px));height:min(60vh,460px);z-index:2147483647;background:#0b1020;color:#e5e7eb;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.4);display:none;flex-direction:column;font:12px/1.4 ui-monospace,Consolas,monospace';
    var bar = document.createElement('div');
    bar.style.cssText = 'display:flex;gap:6px;align-items:center;padding:6px 8px;border-bottom:1px solid #1f2937';
    bar.innerHTML = '<strong style="flex:1;font-family:system-ui">POS debug log</strong>';
    [['Copy', function () {
      var t = lines.join('\n');
      (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () { btnFlash(this, 'Copied'); }.bind(this), function () { window.prompt('Copy this', t); });
    }], ['Clear', function () { lines = []; list.innerHTML = ''; }], ['Close', function () { panel.style.display = 'none'; }]].forEach(function (b) {
      var x = document.createElement('button');
      x.type = 'button'; x.textContent = b[0];
      x.style.cssText = 'background:#1f2937;color:#fff;border:0;border-radius:6px;padding:4px 10px;cursor:pointer;font:12px system-ui';
      x.onclick = b[1];
      bar.appendChild(x);
    });
    list = document.createElement('div');
    list.style.cssText = 'flex:1;overflow:auto;padding:6px 8px;white-space:pre-wrap;word-break:break-word';
    panel.appendChild(bar); panel.appendChild(list);
    document.body.appendChild(panel); document.body.appendChild(pill);
    lines.forEach(function (l, i) { paint(l, lines.levels && lines.levels[i]); });
  }
  function btnFlash(b, t) { if (!b || !b.textContent) return; var o = b.textContent; b.textContent = t; setTimeout(function () { b.textContent = o; }, 1200); }
  function paint(text, level) {
    if (!list) return;
    var row = document.createElement('div');
    row.style.cssText = 'padding:2px 0;border-bottom:1px dashed #1f2937;color:' + (level === 'error' ? '#fca5a5' : level === 'ok' ? '#86efac' : '#e5e7eb');
    row.textContent = text;
    list.appendChild(row);
    list.scrollTop = list.scrollHeight;
  }

  window.rgDbg = function (step, data, level) {
    var text = ts() + '  ' + step + (data !== undefined ? '  ' + str(data) : '');
    lines.push(text);
    (lines.levels = lines.levels || [])[lines.length - 1] = level;
    if (lines.length > 400) { lines.shift(); lines.levels.shift(); }
    try { (level === 'error' ? console.error : console.log)('[POS debug]', step, data !== undefined ? data : ''); } catch (e) {}
    build(); paint(text, level);
    if (level === 'error' && panel) panel.style.display = 'flex';
  };

  window.addEventListener('error', function (e) {
    window.rgDbg('JS ERROR', (e.message || '') + ' @ ' + (e.filename || '').split('/').pop().split('?')[0] + ':' + e.lineno + ':' + e.colno + (e.error && e.error.stack ? '\n' + e.error.stack.split('\n').slice(1, 4).join('\n') : ''), 'error');
  });
  window.addEventListener('unhandledrejection', function (e) { window.rgDbg('PROMISE ERROR', e.reason, 'error'); });

  // every request to the POS controller: what was sent and exactly what came back
  var origFetch = window.fetch;
  if (origFetch) window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    if (!/pos_operations\.php|kot_operations\.php|get_order_details/.test(url)) return origFetch.apply(this, arguments);
    var body = init && init.body ? String(init.body) : '';
    var action = (body.match(/(?:^|&)action=([^&]*)/) || [])[1] || (url.match(/action=([^&]*)/) || [])[1] || '';
    var short = body.replace(/cartItems=[^&]{200,}/, function (m) { return m.slice(0, 200) + '…'; });
    window.rgDbg('→ ' + url.split('/').pop().split('?')[0] + ' ' + action, short.slice(0, 600));
    var t0 = Date.now();
    return origFetch.apply(this, arguments).then(function (resp) {
      resp.clone().text().then(function (text) {
        var ok = resp.ok && /"success"\s*:\s*true/.test(text);
        window.rgDbg('← HTTP ' + resp.status + ' in ' + (Date.now() - t0) + 'ms (' + action + ')', text.slice(0, 1500) || '(empty reply)', ok ? 'ok' : (/"success"\s*:\s*false/.test(text) || !resp.ok || text.trim()[0] !== '{' && text.trim()[0] !== '[' ? 'error' : undefined));
      }).catch(function () {});
      return resp;
    }, function (err) {
      window.rgDbg('✗ request failed (' + action + ')', err, 'error');
      throw err;
    });
  };

  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest && e.target.closest('#processPaymentBtn,#mobileProcessPaymentBtn,#sendToKitchenBtn,#mobileSendToKitchenBtn');
    if (b) window.rgDbg('CLICK ' + b.id, { disabled: !!b.disabled, processPayment: typeof window.processPayment, rgPosSend: typeof window.rgPosSend, printer: (function () { try { return window.getPrinterSettings ? window.getPrinterSettings().mode : 'n/a'; } catch (x) { return 'err ' + x.message; } })() });
  }, true);

  // print calls, once the scripts that define them have loaded
  function wrap(name) {
    var f = window[name];
    if (typeof f !== 'function' || f.__rgDbg) return;
    var w = function () {
      window.rgDbg(name + '()', Array.prototype.slice.call(arguments, 0, 1).map(function (a) { return typeof a === 'string' ? a.slice(0, 60) : typeof a; }));
      try {
        var r = f.apply(this, arguments);
        if (r && typeof r.then === 'function') r.then(function (v) { if (name === 'printDirect') window.rgDbg(name + ' result', v); }, function (err) { window.rgDbg(name + ' FAILED', err, 'error'); });
        return r;
      } catch (err) { window.rgDbg(name + ' THREW', err, 'error'); throw err; }
    };
    w.__rgDbg = true;
    window[name] = w;
  }
  window.addEventListener('load', function () {
    ['showPrintPreview', 'printKOT', 'printDirect', 'showSplitPaymentModal'].forEach(wrap);
    window.rgDbg('POS debug ready', { page: location.pathname.split('/').pop(), online: navigator.onLine, sw: !!(navigator.serviceWorker && navigator.serviceWorker.controller), enableGst: window.enableGst, taxPercent: window.taxPercent, printer: (function () { try { return window.getPrinterSettings ? window.getPrinterSettings() : 'n/a'; } catch (x) { return 'err ' + x.message; } })() });
  });
})();
