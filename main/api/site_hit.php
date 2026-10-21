<?php
/**
 * One page view or action on restrogrow.com's own pages (sent by /assets/rg-analytics.js with sendBeacon):
 * path, source, utm tags, device, IP with country / state / city, and the visitor id cookie that later links
 * the visitor to a lead or sign-up (config/site_visitors.php). Read in Super Admin › Website analytics.
 * GET ?whoami=1: the IP and place this server sees for you (to check the CDN passes the real address).
 */
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['whoami'])) {
    require_once __DIR__ . '/../db_connection.php';
    require_once __DIR__ . '/../config/geo_ip.php';
    header('Content-Type: application/json; charset=UTF-8');
    $ip = geoClientIp();
    $g = geoLookup(getConnection(), $ip);
    echo json_encode(['ip' => $ip, 'place' => geoLabel($g), 'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'database' => geoStatus()['ready']]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
if (!empty($_COOKIE['rg_ignore'])) { http_response_code(204); exit; }   // Super Admin › Website analytics › "Don't count this browser"

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|headless|lighthouse|pingdom|uptime|curl|wget|python/i', $ua)) { http_response_code(204); exit; }

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(204); exit; }
$path = (string)($in['p'] ?? '');
if ($path === '' || $path[0] !== '/' || strlen($path) > 255) { http_response_code(204); exit; }
$path = preg_replace('/[?#].*$/', '', $path);
// only our own marketing pages, the blog and the sign-up page; restaurant pages have their own analytics
if (preg_match('#^/(menuwebsite/)?main/admin/login\.php$#', $path)) $path = '/signup';
elseif (!preg_match('#^/(menuwebsite/)?($|index\.html$|about/?$|faq/?$|app-demo/?$|privacy-policy/?$|terms-of-service/?$|cookie-policy/?$|refund-policy/?$|blog(/.*)?$)#', $path)) { http_response_code(204); exit; }
$path = preg_replace('#^/menuwebsite#', '', $path) ?: '/';

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/site_visitors.php';

// answer first: the location lookup and database writes don't hold up the visitor's browser
ignore_user_abort(true);
http_response_code(204);
header('Content-Length: 0');
header('Connection: close');
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
else { while (ob_get_level() > 0) @ob_end_flush(); @flush(); }

try {
    siteTrackHit(getConnection(), $in, $path);
} catch (Throwable $e) {
    error_log('site_hit: ' . $e->getMessage());
}
