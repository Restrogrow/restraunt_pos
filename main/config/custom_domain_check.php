<?php
/**
 * Custom domain helpers: the DNS records an owner adds at their domain company and the check that the domain shows
 * their website. Used by api/custom_domain.php (one restaurant) and api/brand_admin.php (a brand, config/brand.php).
 */
if (function_exists('cdCheck')) return;

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

