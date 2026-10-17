<?php
/**
 * Customer Website › Custom Domain (dashboard). One endpoint for the page:
 *
 *   GET                         settings, the DNS records to add, embed code
 *   GET ?check=1                ...plus a live check: what the domain's DNS points to, and
 *                               does https://<domain>/ show this restaurant's website (= ready)?
 *   POST {action:'save', domain}       save / clear the domain (validated)
 *   POST {action:'switch', on, force}  send customers to the domain. Turning it on is
 *                               refused (409, with the check) until the check passes,
 *                               because once on, the RestroGrow link and every QR code
 *                               redirect there: a domain that isn't ready takes the
 *                               website offline. force=true is the owner's "turn on anyway".
 *
 * Uses the same users.custom_domain / users.embed_enabled columns as before
 * (custom-domain.php, header.php and the QR codes read them).
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
header('Content-Type: application/json; charset=UTF-8');
// The customer website keeps the restaurant it shows in the same session, so restaurant_id alone
// isn't a login. Owner / branch admin only: this redirects the restaurant's link and QR codes.
require_once __DIR__ . '/../config/authorization_config.php';
requirePermission(PERMISSION_MANAGE_SETTINGS);

$restaurant_id = $_SESSION['restaurant_id'] ?? '';
if (!$restaurant_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}
session_write_close(); // the DNS / website check below can be slow; don't hold the session lock

require_once __DIR__ . '/../db_connection.php';

function cdOut($code, $data) { http_response_code($code); echo json_encode($data); exit; }

/** Where RestroGrow itself is served from (restrogrow.com live; also used when testing locally). */
function cdMainHost() {
    $h = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    if ($h === '' || $h === 'localhost' || $h === '127.0.0.1' || strpos($h, 'hstgr.io') !== false || preg_match('/\.ngrok-free\.(app|dev)$/', $h)) return 'restrogrow.com';
    return preg_replace('/^www\./', '', $h);
}

/** IPs a correctly set-up domain may point to: this server and whatever the main host resolves to. */
function cdServerIps() {
    $ips = [];
    $sa = $_SERVER['SERVER_ADDR'] ?? '';
    if ($sa && filter_var($sa, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) $ips[] = $sa;
    foreach ([cdMainHost(), 'www.' . cdMainHost()] as $h) foreach ((@gethostbynamel($h) ?: []) as $ip) $ips[] = $ip;
    return array_values(array_unique($ips));
}

/** "order.spicegarden.in" → ['spicegarden.in', 'order']; "spicegarden.co.in" → ['spicegarden.co.in', '']. */
function cdSplit($domain) {
    $parts = explode('.', $domain);
    $two = ['co.in', 'net.in', 'org.in', 'firm.in', 'gen.in', 'ind.in', 'co.uk', 'org.uk', 'com.au', 'net.au', 'co.nz', 'com.sg', 'com.my', 'co.za', 'com.br', 'co.jp', 'com.np', 'com.bd', 'com.pk', 'co.ae'];
    $n = in_array(implode('.', array_slice($parts, -2)), $two, true) ? 3 : 2;
    if (count($parts) <= $n) return [$domain, ''];
    return [implode('.', array_slice($parts, -$n)), implode('.', array_slice($parts, 0, count($parts) - $n))];
}

function cdNormalize($raw) {
    $d = strtolower(trim((string)$raw));
    $d = preg_replace('#^[a-z]+://#', '', $d);
    $d = explode('/', $d)[0];
    $d = explode('?', $d)[0];
    $d = preg_replace('/:\d+$/', '', $d);
    return rtrim($d, '.');
}

/** DNS records the owner adds at their domain company. */
function cdRecords($domain) {
    if (!$domain) return [];
    list($root, $sub) = cdSplit($domain);
    $ips = cdServerIps();
    $ipv4 = array_values(array_filter($ips, function ($ip) { return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4); }));
    if ($sub !== '') {
        return [['type' => 'CNAME', 'name' => $sub, 'value' => cdMainHost(), 'note' => 'Points ' . $domain . ' to RestroGrow']];
    }
    $rows = [];
    if ($ipv4) $rows[] = ['type' => 'A', 'name' => '@', 'value' => $ipv4[0], 'note' => 'Points ' . $domain . ' to RestroGrow'];
    $rows[] = ['type' => 'CNAME', 'name' => 'www', 'value' => cdMainHost(), 'note' => 'So www.' . $domain . ' works too'];
    return $rows;
}

function cdCheck($domain, $restaurantName) {
    $out = ['domain' => $domain, 'checked_at' => date('c')];
    // 1. DNS
    $found = [];
    $cname = '';
    foreach ((@dns_get_record($domain, DNS_CNAME) ?: []) as $r) { if (!empty($r['target'])) { $cname = strtolower(rtrim($r['target'], '.')); $found[] = 'CNAME ' . $cname; } }
    $ips = @gethostbynamel($domain) ?: [];
    foreach ($ips as $ip) $found[] = 'A ' . $ip;
    $ours = cdServerIps();
    $main = cdMainHost();
    if (!$found) $dns = 'missing';
    elseif (($cname && ($cname === $main || $cname === 'www.' . $main)) || array_intersect($ips, $ours)) $dns = 'ok';
    else $dns = 'elsewhere';
    $out['dns'] = ['status' => $dns, 'found' => array_values(array_unique($found))];

    // 2. the website itself, over https
    $site = ['status' => 'skipped'];
    if ($dns !== 'missing' && function_exists('curl_init')) {
        $get = function ($url, $verify) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0, CURLOPT_USERAGENT => 'RestroGrow domain check', CURLOPT_RANGE => '0-200000']);
            // use the system's certificate store too (Windows PHP often has no CA bundle configured)
            if ($verify && defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
            $body = curl_exec($ch);
            $r = ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'errno' => curl_errno($ch), 'body' => is_string($body) ? $body : ''];
            curl_close($ch);
            return $r;
        };
        $r = $get('https://' . $domain . '/', true);
        $ssl = in_array($r['errno'], [35, 51, 58, 60, 77, 83], true);
        if ($ssl) $r = $get('https://' . $domain . '/', false);
        if ($r['code'] === 0) $site = ['status' => 'unreachable'];
        else {
            $name = html_entity_decode($restaurantName, ENT_QUOTES, 'UTF-8');
            $shows = $name !== '' && (stripos(html_entity_decode($r['body'], ENT_QUOTES, 'UTF-8'), $name) !== false);
            $site = ['status' => $ssl ? 'no_ssl' : ($shows ? 'ok' : 'other_site'), 'code' => $r['code']];
        }
    }
    $out['site'] = $site;
    // the real test is the page itself: RestroGrow sits behind a CDN whose IPs change, so an A record
    // can be right without matching the IPs we see from here
    $out['ready'] = $site['status'] === 'ok';
    return $out;
}

try {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT restaurant_name, custom_domain, embed_enabled FROM users WHERE restaurant_id = ? LIMIT 1");
    $stmt->execute([$restaurant_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) cdOut(404, ['success' => false, 'message' => 'Restaurant not found']);
    $domain = (string)($row['custom_domain'] ?? '');
    $enabled = !empty($row['embed_enabled']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $in['action'] ?? '';

        if ($action === 'save') {
            $d = cdNormalize($in['domain'] ?? '');
            if ($d !== '') {
                if (strlen($d) > 190 || !preg_match('/^(?=.{4,190}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $d)) {
                    cdOut(400, ['success' => false, 'message' => 'That doesn’t look like a domain. Type it like order.yourrestaurant.com']);
                }
                $main = cdMainHost();
                if ($d === $main || substr($d, -strlen('.' . $main)) === '.' . $main || in_array($d, ['localhost'], true)) {
                    cdOut(400, ['success' => false, 'message' => 'Use a domain you own, not a RestroGrow address.']);
                }
            }
            $changed = $d !== $domain;
            try {
                // a new domain hasn't been checked yet, so stop sending customers to it until it is
                $conn->prepare("UPDATE users SET custom_domain = ?" . ($changed ? ", embed_enabled = 0" : "") . " WHERE restaurant_id = ?")->execute([$d !== '' ? $d : null, $restaurant_id]);
            } catch (PDOException $e) {
                if ((int)$e->getCode() === 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    cdOut(409, ['success' => false, 'message' => 'That domain is already connected to another restaurant. Please use a different one.']);
                }
                throw $e;
            }
            cdOut(200, ['success' => true, 'domain' => $d, 'switched_off' => $changed && $enabled]);
        }

        if ($action === 'switch') {
            $on = !empty($in['on']);
            if ($on) {
                if ($domain === '') cdOut(400, ['success' => false, 'message' => 'Add your domain first.']);
                $check = cdCheck($domain, (string)$row['restaurant_name']);
                if (!$check['ready'] && empty($in['force'])) {
                    cdOut(409, ['success' => false, 'code' => 'not_ready', 'message' => 'Your domain isn’t showing your website yet.', 'check' => $check]);
                }
            }
            $conn->prepare("UPDATE users SET embed_enabled = ? WHERE restaurant_id = ?")->execute([$on ? 1 : 0, $restaurant_id]);
            cdOut(200, ['success' => true, 'enabled' => $on]);
        }
        cdOut(400, ['success' => false, 'message' => 'Unknown action']);
    }

    // GET
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $basePath = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($basePath === '/' || $basePath === '\\') $basePath = '';
    $baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'restrogrow.com') . $basePath;
    $data = [
        'restaurant_id' => $restaurant_id,
        'restaurant_name' => $row['restaurant_name'],
        'domain' => $domain,
        'enabled' => $enabled,
        'live' => $enabled && $domain !== '',
        'main_host' => cdMainHost(),
        'records' => cdRecords($domain),
        'root' => $domain ? cdSplit($domain)[0] : '',
        'embed_code' => '<div id="rg-restaurant-menu"></div>' . "\n" . '<script src="' . $baseUrl . '/main/embed/embed.js?restaurant=' . urlencode($restaurant_id) . '"></script>',
        'preview_url' => $baseUrl . '/main/website/index.php?restaurant_id=' . urlencode($restaurant_id),
    ];
    if (!empty($_GET['check']) && $domain !== '') $data['check'] = cdCheck($domain, (string)$row['restaurant_name']);
    cdOut(200, ['success' => true, 'data' => $data]);
} catch (Throwable $e) {
    error_log('custom_domain.php: ' . $e->getMessage());
    cdOut(500, ['success' => false, 'message' => 'Something went wrong. Please try again.']);
}
