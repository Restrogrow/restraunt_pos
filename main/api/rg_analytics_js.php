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
  // our own tracking (config/site_visitors.php): page views, a visitor id that later links a lead or
  // sign-up to where they came from, and a few key actions (pricing seen, trial / WhatsApp / call clicks)
  try {
    var url = me ? new URL('../main/api/site_hit.php', me).href : '/main/api/site_hit.php';
    var send = function (o) {
      var b = JSON.stringify(o);
      if (!(navigator.sendBeacon && navigator.sendBeacon(url, new Blob([b], { type: 'application/json' })))) {
        fetch(url, { method: 'POST', body: b, keepalive: true, headers: { 'Content-Type': 'application/json' } });
      }
    };
    var store = function (k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } };
    var cookie = function (k) { var m = document.cookie.match(new RegExp('(?:^|; )' + k + '=([^;]*)')); return m ? decodeURIComponent(m[1]) : ''; };
    // the visitor id: a random name for this browser (no personal data); a first-party cookie so the
    // contact form, chat and sign-up on this site can say which visitor sent them
    var vid = cookie('rg_vid') || store('rg_vid') || '';
    if (!/^[a-z0-9]{12,20}$/.test(vid)) { vid = ''; var a = 'abcdefghijklmnopqrstuvwxyz0123456789'; for (var i = 0; i < 20; i++) vid += a.charAt(Math.floor(Math.random() * 36)); }
    store('rg_vid', vid);
    document.cookie = 'rg_vid=' + vid + '; path=/; max-age=31536000; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    // a new visit after 30 minutes away
    var last = +(store('rg_last') || 0), now = Date.now(), fresh = !last || now - last > 1800000;
    store('rg_last', String(now));
    var q = new URLSearchParams(location.search), click = '';
    ['gclid', 'gbraid', 'wbraid', 'fbclid', 'igshid', 'ttclid', 'msclkid', 'li_fat_id'].some(function (k) { if (q.get(k)) { click = k; return true; } return false; });
    send({ p: location.pathname, t: document.title, r: document.referrer, v: vid, n: fresh ? 1 : 0, k: click, lg: (navigator.language || '').slice(0, 20),
      us: q.get('utm_source') || '', um: q.get('utm_medium') || '', uc: q.get('utm_campaign') || '', uct: q.get('utm_content') || '', ut: q.get('utm_term') || '' });
    // key actions: window.rgTrack('name', 'label') from any page; clicks on these links are counted by themselves
    window.rgTrack = function (name, label) { send({ p: location.pathname, v: vid, e: String(name).slice(0, 40), el: String(label || '').slice(0, 120) }); };
    document.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a,button');
      if (!a) return;
      var h = a.getAttribute('href') || '', txt = (a.textContent || '').trim().slice(0, 60);
      if (/tab=signup/.test(h)) window.rgTrack('trial_click', txt);
      else if (/wa\.me|api\.whatsapp\.com/.test(h)) window.rgTrack('whatsapp_click', txt);
      else if (/^tel:/.test(h)) window.rgTrack('call_click', txt);
      else if (/admin\/login\.php/.test(h)) window.rgTrack('login_click', txt);
      else if (/app-demo|\/demo/.test(h)) window.rgTrack('demo_click', txt);
      else if (a.id === 'rgcFab') window.rgTrack('chat_open', '');
    }, true);
    // pricing seen: half the pricing section on screen
    var pr = document.getElementById('pricing');
    if (pr && 'IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (es) { if (es[0].isIntersecting) { window.rgTrack('pricing_view', ''); io.disconnect(); } }, { threshold: 0.5 });
      io.observe(pr);
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
