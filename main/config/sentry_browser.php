<?php
/**
 * Sentry in the browser: JS errors on the dashboards go to sentry.io (the server side is config/sentry.php).
 * Sentry's loader script is ~3 KB and fetches the full SDK only when an error happens.
 *
 *   <?php require_once __DIR__ . '/../config/sentry_browser.php'; echo rgSentryBrowserTag('dashboard'); ?>
 */
require_once __DIR__ . '/sentry.php';

function rgSentryBrowserTag(string $area): string
{
    $dsn = rgSentryDsn();
    if (!$dsn) return '';
    $host = parse_url($dsn['dsn'], PHP_URL_HOST) ?: '';
    $cdn = str_contains($host, '.de.sentry.io') ? 'js-de.sentry-cdn.com' : 'js.sentry-cdn.com';   // the loader lives in the project's region
    $cfg = [
        'environment' => rgSentryEnvironment(),
        'tags' => ['area' => $area, 'restaurant_id' => (string)($_SESSION['restaurant_id'] ?? '')],
    ];
    // Session Replay and tracing are switched off here, whatever the project's loader settings say.
    // With them on the dashboard loads the full tracing+replay bundle and then records the DOM as it
    // changes — and this DOM is all sixty-odd pages at once, so a snapshot froze the screen for
    // seconds at a time on a slow machine. Errors, which is what this is here for, still arrive.
    return '<script>window.sentryOnLoad = function () { var c = ' . json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP) . ';'
        . ' Sentry.init({ environment: c.environment, sendDefaultPii: false,'
        . ' tracesSampleRate: 0, replaysSessionSampleRate: 0, replaysOnErrorSampleRate: 0 });'
        . ' Object.keys(c.tags).forEach(function (k) { if (c.tags[k]) Sentry.setTag(k, c.tags[k]); }); };</script>' . "\n"
        . '  <script src="https://' . $cdn . '/' . htmlspecialchars($dsn['key'], ENT_QUOTES) . '.min.js" crossorigin="anonymous"></script>';
}
