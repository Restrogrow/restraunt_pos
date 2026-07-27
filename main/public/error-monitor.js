/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Error Monitor — Client-Side Error Interceptor
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * This script intercepts EVERY JavaScript error in the application and
 * sends it to the backend (api/report_error.php) so it shows up in the
 * admin Error Monitor dashboard.
 *
 * It catches:
 *   ✓ window.onerror         — runtime errors, syntax errors
 *   ✓ unhandledrejection     — unhandled Promise rejections
 *   ✓ console.error / warn   — manual console.error() calls
 *   ✓ AJAX/fetch failures    — network errors (logged as warnings)
 *
 * The interceptor is safe — it won't interfere with the normal error flow
 * or cause infinite loops.
 *
 * Usage: Include this script AFTER all other scripts on the page.
 *   <script src="../public/error-monitor.js" defer></script>
 *
 * Or: It's automatically loaded by the dashboard.php page.
 * ═══════════════════════════════════════════════════════════════════════════
 */

(function () {
  'use strict';

  // ── Configuration ──────────────────────────────────────────────────────
  var REPORT_URL = '../api/report_error.php';
  var SESSION_ID = Date.now() + '_' + Math.random().toString(36).slice(2, 8);
  var ERROR_COUNT = 0;
  var MAX_REPORTS = 100;              // Max total reports per page load
  var RATE_LIMIT_MS = 1000;           // Min interval between reports (ms)
  var lastReportTime = 0;
  var suppressedPatterns = [
    /runtime\.lastError/i,
    /Could not establish connection/i,
    /ResizeObserver/i,
    /Script error\.?$/i,              // Cross-origin script errors (no info)
    /chrome-extension:/i,
    /moz-extension:/i,
    /safari-extension:/i,
    /webpack/i,
  ];

  // ── Queue (send in batches) ────────────────────────────────────────────
  var pendingReports = [];
  var flushTimer = null;

  function queueReport(source, severity, message, file, line, trace, context) {
    if (ERROR_COUNT >= MAX_REPORTS) return;

    // Suppress known noise
    for (var i = 0; i < suppressedPatterns.length; i++) {
      if (suppressedPatterns[i].test(message)) return;
    }

    pendingReports.push({
      source: source,
      severity: severity,
      message: String(message).slice(0, 2000),
      file: file || null,
      line: line || null,
      trace: trace || null,
      context: context ? JSON.stringify(context) : null,
      url: window.location.href,
    });

    ERROR_COUNT++;

    // Schedule batch send
    if (!flushTimer) {
      flushTimer = setTimeout(flushReports, 2000);
    }

    // If we have 10+, send immediately
    if (pendingReports.length >= 10) {
      clearTimeout(flushTimer);
      flushReports();
    }
  }

  function flushReports() {
    flushTimer = null;
    if (pendingReports.length === 0) return;

    var batch = pendingReports.slice();
    pendingReports = [];

    // Send individually (simpler, more reliable)
    for (var i = 0; i < batch.length; i++) {
      sendReport(batch[i]);
    }
  }

  function sendReport(data) {
    // Rate limit
    var now = Date.now();
    if (now - lastReportTime < 500) return; // 500ms between sends
    lastReportTime = now;

    try {
      var payload = new URLSearchParams();
      for (var key in data) {
        if (data[key] !== null && data[key] !== undefined) {
          payload.append(key, data[key]);
        }
      }

      // Use sendBeacon if available (doesn't block page unload)
      if (navigator.sendBeacon) {
        navigator.sendBeacon(REPORT_URL, payload);
      } else {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', REPORT_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.send(payload.toString());
      }
    } catch (e) {
      // Silently ignore — don't cause more errors
    }
  }

  // ── 1. Intercept window.onerror (runtime errors) ──────────────────────
  var origOnError = window.onerror;
  window.onerror = function (message, source, lineno, colno, error) {
    var trace = error && error.stack ? error.stack : '';
    queueReport('js', 'error', message, source, lineno, trace, {
      col: colno,
      type: 'runtime',
    });
    if (typeof origOnError === 'function') {
      return origOnError.apply(this, arguments);
    }
    return false;
  };

  // ── 2. Intercept unhandled promise rejections ─────────────────────────
  window.addEventListener('unhandledrejection', function (event) {
    var reason = event.reason;
    var message = '';
    var trace = '';
    if (reason instanceof Error) {
      message = reason.message;
      trace = reason.stack || '';
    } else if (typeof reason === 'string') {
      message = reason;
    } else if (reason && reason.message) {
      message = reason.message;
      trace = reason.stack || '';
    } else {
      try { message = JSON.stringify(reason); } catch (e) { message = String(reason); }
    }
    queueReport('js', 'error', 'Unhandled Promise: ' + message, '', 0, trace, {
      type: 'unhandledrejection',
    });
  });

  // ── 3. Intercept fetch/XMLHttpRequest failures ─────────────────────────
  var origFetch = window.fetch;
  if (origFetch) {
    window.fetch = function () {
      var args = arguments;
      return origFetch.apply(this, args).catch(function (err) {
        var url = '';
        try { url = String(args[0]); } catch (e) {}
        queueReport('js', 'warning', 'Fetch failed: ' + (err.message || 'Network error'), url, 0, err.stack, {
          type: 'fetch',
        });
        throw err; // re-throw so the original caller still sees the error
      });
    };
  }

  // ── 4. Override console.error and console.warn ────────────────────────
  if (window.console) {
    var origError = console.error;
    console.error = function () {
      var msg = '';
      try {
        var parts = [];
        for (var i = 0; i < arguments.length; i++) {
          var a = arguments[i];
          if (a instanceof Error) {
            parts.push(a.message);
            queueReport('js', 'error', a.message, '', 0, a.stack, {
              type: 'console.error',
            });
          } else if (typeof a === 'object') {
            try { parts.push(JSON.stringify(a)); } catch (e) { parts.push(String(a)); }
          } else {
            parts.push(String(a));
          }
        }
        msg = parts.join(' ');
        // Only report if it wasn't already reported as an Error object
        // (the Error case already called queueReport above)
        if (!(arguments.length === 1 && arguments[0] instanceof Error)) {
          queueReport('js', 'error', msg, '', 0, '', { type: 'console.error' });
        }
      } catch (e) {}
      return origError.apply(this, arguments);
    };

    var origWarn = console.warn;
    console.warn = function () {
      var msg = '';
      try {
        var parts = [];
        for (var i = 0; i < arguments.length; i++) {
          var a = arguments[i];
          if (typeof a === 'object') {
            try { parts.push(JSON.stringify(a)); } catch (e) { parts.push(String(a)); }
          } else {
            parts.push(String(a));
          }
        }
        msg = parts.join(' ');
        queueReport('js', 'warning', msg, '', 0, '', { type: 'console.warn' });
      } catch (e) {}
      return origWarn.apply(this, arguments);
    };
  }

  // ── 5. Capture load/parse errors in <script> tags ─────────────────────
  document.addEventListener('error', function (e) {
    var target = e.target;
    if (target && (target.tagName === 'SCRIPT' || target.tagName === 'LINK' || target.tagName === 'IMG')) {
      queueReport('js', 'error', 'Failed to load resource: ' + (target.src || target.href), target.src || target.href || '', 0, '', {
        type: 'resource',
        tag: target.tagName,
      });
    }
  }, true);

  // ── 6. Flush on page unload ──────────────────────────────────────────
  window.addEventListener('beforeunload', function () {
    if (flushTimer) {
      clearTimeout(flushTimer);
      flushReports();
    }
  });

  // ── Log that monitor is active ────────────────────────────────────────
  console.log('%c📊 Error Monitor active', 'color:#10b981;font-weight:bold;font-size:12px;');
})();
