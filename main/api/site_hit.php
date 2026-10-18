<?php
/**
 * One page view on restrogrow.com's own pages (sent by /assets/rg-analytics.js with sendBeacon).
 * Stores path, source and device; the visitor is a daily hash of IP + browser, so nobody is identified
 * and nothing is kept in a cookie. Read in Super Admin › Website analytics (config/site_analytics.php).
 */
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|headless|lighthouse|pingdom|uptime|curl|wget|python/i', $ua)) { http_response_code(204); exit; }

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(204); exit; }
$path = (string)($in['p'] ?? '');
if ($path === '' || $path[0] !== '/' || strlen($path) > 255) { http_response_code(204); exit; }
$path = preg_replace('/[?#].*$/', '', $path);
// only our own marketing pages and the blog; restaurant pages have their own analytics
if (!preg_match('#^/(menuwebsite/)?($|index\.html$|about/?$|faq/?$|app-demo/?$|privacy-policy/?$|terms-of-service/?$|cookie-policy/?$|refund-policy/?$|blog(/.*)?$)#', $path)) { http_response_code(204); exit; }
$path = preg_replace('#^/menuwebsite#', '', $path) ?: '/';

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/site_analytics.php';

try {
    $conn = getConnection();
    siteAnalyticsEnsure($conn);
    $own = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $ref = (string)($in['r'] ?? '');
    $refHost = strtolower((string)(parse_url($ref, PHP_URL_HOST) ?? ''));
    $utmS = mb_substr(trim((string)($in['us'] ?? '')), 0, 80);
    $utmC = mb_substr(trim((string)($in['uc'] ?? '')), 0, 120);
    $device = preg_match('/iPad|Tablet/i', $ua) ? 'tablet' : (preg_match('/Mobi|Android|iPhone/i', $ua) ? 'mobile' : 'desktop');
    $ip = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    $day = date('Y-m-d');
    $salt = (string)saGet($conn, 'site_visit_salt', '');
    if ($salt === '') { $salt = bin2hex(random_bytes(16)); saSet($conn, 'site_visit_salt', $salt); }
    $visitor = substr(hash('sha256', $salt . '|' . $day . '|' . $ip . '|' . $ua), 0, 16);
    $conn->prepare('INSERT INTO site_visits (ts, day, path, title, source, referrer_host, utm_source, utm_campaign, device, visitor) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)')
         ->execute([$day, $path, mb_substr(trim((string)($in['t'] ?? '')), 0, 200) ?: null, siteSourceOf($refHost, $utmS, $own),
                    $refHost !== '' && $refHost !== $own ? mb_substr($refHost, 0, 120) : null, $utmS ?: null, $utmC ?: null, $device, $visitor]);
} catch (Throwable $e) {
    error_log('site_hit: ' . $e->getMessage());
}
http_response_code(204);
