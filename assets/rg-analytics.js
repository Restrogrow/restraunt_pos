/**
 * Google Analytics 4 for restrogrow.com's own pages (home, about, faq, app demo, legal pages, blog).
 * Change the ID below to switch property; one file, every page. Restaurant pages use their own
 * GA ID from the SEO studio (main/config/seo_helpers.php), not this one.
 * Skipped on localhost so local testing doesn't count as visits.
 */
(function () {
  // GA4 property "RestroGrow Landing", same web stream as demo.restrogrow.com (split by hostname in reports)
  var GA_ID = 'G-H6FKYMRB8X';
  if (!/^G-[A-Z0-9]{4,15}$/.test(GA_ID)) return;
  if (/^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname)) return;
  var s = document.createElement('script');
  s.async = true;
  s.src = 'https://www.googletagmanager.com/gtag/js?id=' + GA_ID;
  document.head.appendChild(s);
  window.dataLayer = window.dataLayer || [];
  window.gtag = function () { window.dataLayer.push(arguments); };
  window.gtag('js', new Date());
  window.gtag('config', GA_ID);
})();
