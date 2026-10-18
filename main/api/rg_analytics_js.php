<?php
/**
 * Served as /assets/rg-analytics.js (rewrite in the root .htaccess) on restrogrow.com's own pages.
 * 1) our own page-view beacon (main/api/site_hit.php), 2) Google Analytics 4 with the Measurement ID
 * saved in Super Admin › Website analytics. GA is skipped on localhost so testing doesn't count.
 */
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: public, max-age=300');

$gaId = 'G-H6FKYMRB8X';
try {
    require_once __DIR__ . '/../db_connection.php';
    require_once __DIR__ . '/../config/site_analytics.php';
    $gaId = siteGaMeasurementId(getConnection());
} catch (Throwable $e) { /* keep the default ID */ }
?>
(function () {
  var GA_ID = <?php echo json_encode($gaId); ?>;
  var me = document.currentScript && document.currentScript.src;
  // our own tracking
  try {
    var q = new URLSearchParams(location.search);
    var hit = JSON.stringify({ p: location.pathname, t: document.title, r: document.referrer, us: q.get('utm_source') || '', uc: q.get('utm_campaign') || '' });
    var url = me ? new URL('../main/api/site_hit.php', me).href : '/main/api/site_hit.php';
    if (!(navigator.sendBeacon && navigator.sendBeacon(url, new Blob([hit], { type: 'application/json' })))) {
      fetch(url, { method: 'POST', body: hit, keepalive: true, headers: { 'Content-Type': 'application/json' } });
    }
  } catch (e) {}
  // Google Analytics 4
  if (!GA_ID || /^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname)) return;
  var s = document.createElement('script');
  s.async = true;
  s.src = 'https://www.googletagmanager.com/gtag/js?id=' + GA_ID;
  document.head.appendChild(s);
  window.dataLayer = window.dataLayer || [];
  window.gtag = function () { window.dataLayer.push(arguments); };
  window.gtag('js', new Date());
  window.gtag('config', GA_ID);
})();
