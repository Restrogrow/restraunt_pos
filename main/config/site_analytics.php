<?php
/**
 * Website analytics for restrogrow.com's own pages (not restaurant pages).
 *
 * 1. Our own tracking: /assets/rg-analytics.js (served by main/api/rg_analytics_js.php) sends one
 *    beacon per page view to main/api/site_hit.php → table site_visits. Visitors are counted with a
 *    daily hash of IP + browser (no cookies, no personal data kept).
 * 2. Google Analytics 4: the same script loads gtag with the Measurement ID saved in Super Admin
 *    (sa_settings 'ga_measurement_id'). Super Admin › Website analytics reads GA's real numbers through
 *    the GA Data API with a service-account key uploaded there (stored as main/config/.ga-service-account.json,
 *    a dotfile, so Apache never serves it; see main/.htaccess). Property ID: sa_settings 'ga_property_id'.
 */
if (!function_exists('saGet')) {
    function saGet(PDO $conn, string $k, $default = null) {
        $conn->exec("CREATE TABLE IF NOT EXISTS sa_settings (k VARCHAR(60) PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $st = $conn->prepare('SELECT v FROM sa_settings WHERE k = ?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? $default : $v;
    }
    function saSet(PDO $conn, string $k, ?string $v): void {
        $conn->exec("CREATE TABLE IF NOT EXISTS sa_settings (k VARCHAR(60) PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if ($v === null) { $conn->prepare('DELETE FROM sa_settings WHERE k = ?')->execute([$k]); return; }
        $conn->prepare('REPLACE INTO sa_settings (k, v) VALUES (?, ?)')->execute([$k, $v]);
    }
}

const SA_GA_DEFAULT_ID = 'G-H6FKYMRB8X';      // "RestroGrow Landing" web stream
const SA_GA_DEFAULT_PROPERTY = '559286209';

function siteAnalyticsEnsure(PDO $conn): void {
    $conn->exec("CREATE TABLE IF NOT EXISTS site_visits (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ts DATETIME NOT NULL,
        day DATE NOT NULL,
        path VARCHAR(255) NOT NULL,
        title VARCHAR(200) NULL,
        source VARCHAR(40) NOT NULL DEFAULT 'Direct',
        referrer_host VARCHAR(120) NULL,
        utm_source VARCHAR(80) NULL,
        utm_campaign VARCHAR(120) NULL,
        device VARCHAR(10) NOT NULL DEFAULT 'desktop',
        visitor CHAR(16) NOT NULL,
        INDEX (day), INDEX (day, visitor), INDEX (path(100))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function siteGaMeasurementId(PDO $conn): string {
    $id = (string)saGet($conn, 'ga_measurement_id', SA_GA_DEFAULT_ID);
    return preg_match('/^G-[A-Z0-9]{4,15}$/', $id) ? $id : '';
}

/** Where a visit came from, in words people recognise. */
function siteSourceOf(string $refHost, string $utm, string $ownHost): string {
    $u = strtolower($utm);
    $h = strtolower($refHost);
    if ($u !== '') {
        foreach (['google' => 'Google', 'instagram' => 'Instagram', 'ig' => 'Instagram', 'facebook' => 'Facebook', 'fb' => 'Facebook', 'whatsapp' => 'WhatsApp', 'linkedin' => 'LinkedIn', 'producthunt' => 'Product Hunt', 'medium' => 'Medium', 'youtube' => 'YouTube', 'email' => 'Email'] as $k => $n)
            if (strpos($u, $k) !== false) return $n;
        return mb_substr(ucfirst($u), 0, 40);
    }
    if ($h === '' || $h === $ownHost || preg_replace('/^www\./', '', $h) === preg_replace('/^www\./', '', $ownHost)) return 'Direct';
    $map = ['google.' => 'Google', 'bing.' => 'Bing', 'duckduckgo' => 'DuckDuckGo', 'yahoo.' => 'Yahoo', 'chatgpt' => 'ChatGPT', 'openai' => 'ChatGPT', 'perplexity' => 'Perplexity',
        'instagram' => 'Instagram', 'facebook' => 'Facebook', 'fb.' => 'Facebook', 'whatsapp' => 'WhatsApp', 'wa.me' => 'WhatsApp', 'linkedin' => 'LinkedIn', 'lnkd.in' => 'LinkedIn',
        't.co' => 'X (Twitter)', 'twitter' => 'X (Twitter)', 'x.com' => 'X (Twitter)', 'youtube' => 'YouTube', 'reddit' => 'Reddit', 'quora' => 'Quora',
        'producthunt' => 'Product Hunt', 'medium.com' => 'Medium', 'softwaresuggest' => 'SoftwareSuggest', 'alternativeto' => 'AlternativeTo', 'trustpilot' => 'Trustpilot'];
    foreach ($map as $k => $n) if (strpos($h, $k) !== false) return $n;
    return mb_substr(preg_replace('/^www\./', '', $h), 0, 40);
}

/** Our own numbers for the last $days days (today included). */
function siteOwnReport(PDO $conn, int $days): array {
    siteAnalyticsEnsure($conn);
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $q = function (string $sql, array $p = []) use ($conn) { $st = $conn->prepare($sql); $st->execute($p); return $st; };
    $tot = $q('SELECT COUNT(*) views, COUNT(DISTINCT CONCAT(day, visitor)) visitors FROM site_visits WHERE day >= ?', [$from])->fetch(PDO::FETCH_ASSOC);
    $prevFrom = date('Y-m-d', strtotime('-' . (2 * $days - 1) . ' days'));
    $prev = $q('SELECT COUNT(*) views, COUNT(DISTINCT CONCAT(day, visitor)) visitors FROM site_visits WHERE day >= ? AND day < ?', [$prevFrom, $from])->fetch(PDO::FETCH_ASSOC);
    $daily = [];
    foreach ($q('SELECT day, COUNT(*) views, COUNT(DISTINCT visitor) visitors FROM site_visits WHERE day >= ? GROUP BY day', [$from])->fetchAll(PDO::FETCH_ASSOC) as $r) $daily[$r['day']] = $r;
    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $series[] = ['day' => $d, 'views' => (int)($daily[$d]['views'] ?? 0), 'visitors' => (int)($daily[$d]['visitors'] ?? 0)];
    }
    return [
        'views' => (int)$tot['views'], 'visitors' => (int)$tot['visitors'],
        'prev_views' => (int)$prev['views'], 'prev_visitors' => (int)$prev['visitors'],
        'series' => $series,
        'pages' => $q('SELECT path, MAX(title) title, COUNT(*) views, COUNT(DISTINCT CONCAT(day, visitor)) visitors FROM site_visits WHERE day >= ? GROUP BY path ORDER BY views DESC LIMIT 12', [$from])->fetchAll(PDO::FETCH_ASSOC),
        'sources' => $q('SELECT source, COUNT(DISTINCT CONCAT(day, visitor)) visitors FROM site_visits WHERE day >= ? GROUP BY source ORDER BY visitors DESC LIMIT 10', [$from])->fetchAll(PDO::FETCH_ASSOC),
        'devices' => $q('SELECT device, COUNT(DISTINCT CONCAT(day, visitor)) visitors FROM site_visits WHERE day >= ? GROUP BY device ORDER BY visitors DESC', [$from])->fetchAll(PDO::FETCH_ASSOC),
        'campaigns' => $q("SELECT utm_campaign campaign, COUNT(DISTINCT CONCAT(day, visitor)) visitors FROM site_visits WHERE day >= ? AND utm_campaign IS NOT NULL AND utm_campaign <> '' GROUP BY utm_campaign ORDER BY visitors DESC LIMIT 8", [$from])->fetchAll(PDO::FETCH_ASSOC),
    ];
}

/* ───────────── Google Analytics Data API ───────────── */

function gaKeyFile(): string { return __DIR__ . '/.ga-service-account.json'; }
function gaServiceAccount(): ?array {
    $f = gaKeyFile();
    $j = is_readable($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($j) && !empty($j['client_email']) && !empty($j['private_key']) ? $j : null;
}
function gaB64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

/** Short-lived access token (cached in sa_settings for ~55 minutes). */
function gaAccessToken(PDO $conn, array $sa): string {
    $cached = json_decode((string)saGet($conn, 'ga_token_cache', ''), true);
    if (is_array($cached) && ($cached['exp'] ?? 0) > time() + 60 && ($cached['who'] ?? '') === $sa['client_email']) return $cached['token'];
    $now = time();
    $h = gaB64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $p = gaB64(json_encode(['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
        'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
    if (!openssl_sign($h . '.' . $p, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('The key file could not sign a request. Upload the JSON key again.');
    $res = gaHttp('https://oauth2.googleapis.com/token', http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $h . '.' . $p . '.' . gaB64($sig)]), ['Content-Type: application/x-www-form-urlencoded']);
    if (empty($res['access_token'])) throw new RuntimeException('Google did not accept the key: ' . ($res['error_description'] ?? $res['error'] ?? 'unknown error') . '.');
    saSet($conn, 'ga_token_cache', json_encode(['token' => $res['access_token'], 'exp' => $now + (int)($res['expires_in'] ?? 3600), 'who' => $sa['client_email']]));
    return $res['access_token'];
}

function gaHttp(string $url, string $body, array $headers): array {
    $ch = curl_init($url);
    $opts = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20];
    $ca = function_exists('env') ? (string)env('AI_CAINFO', '') : '';
    if ($ca !== '' && is_readable($ca)) $opts[CURLOPT_CAINFO] = $ca;   // local XAMPP has no CA bundle (same as the Gemini calls)
    curl_setopt_array($ch, $opts);
    $out = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($out === false) throw new RuntimeException('Could not reach Google: ' . $err);
    return json_decode((string)$out, true) ?: [];
}

function gaRunReport(PDO $conn, string $property, array $req): array {
    $sa = gaServiceAccount();
    if (!$sa) throw new RuntimeException('No Google key uploaded yet.');
    $tok = gaAccessToken($conn, $sa);
    $res = gaHttp('https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode($property) . ':runReport', json_encode($req), ['Content-Type: application/json', 'Authorization: Bearer ' . $tok]);
    if (!empty($res['error'])) {
        $m = (string)($res['error']['message'] ?? 'Google Analytics refused the request');
        if (($res['error']['code'] ?? 0) == 403) $m = 'The service account has no access to this property. In GA › Admin › Property access management, add ' . $sa['client_email'] . ' as Viewer. (' . $m . ')';
        throw new RuntimeException($m);
    }
    return $res;
}

/** GA numbers for the page: totals, daily users, top pages, channels, countries. Cached 15 minutes. */
function gaReport(PDO $conn, int $days, string $host): array {
    $property = preg_replace('/\D/', '', (string)saGet($conn, 'ga_property_id', SA_GA_DEFAULT_PROPERTY));
    $ck = 'ga_cache_' . substr(md5($property . '|' . $days . '|' . $host), 0, 12);
    $c = json_decode((string)saGet($conn, $ck, ''), true);
    if (is_array($c) && ($c['at'] ?? 0) > time() - 900) return $c['data'] + ['cached_at' => $c['at']];
    $range = [['startDate' => ($days - 1) . 'daysAgo', 'endDate' => 'today']];
    $filter = $host !== '' ? ['dimensionFilter' => ['filter' => ['fieldName' => 'hostName', 'stringFilter' => ['matchType' => 'EXACT', 'value' => $host]]]] : [];
    $rows = function (array $r, array $dims, array $mets) {
        $out = [];
        foreach ($r['rows'] ?? [] as $row) {
            $o = [];
            foreach ($dims as $i => $d) $o[$d] = $row['dimensionValues'][$i]['value'] ?? '';
            foreach ($mets as $i => $m) $o[$m] = (float)($row['metricValues'][$i]['value'] ?? 0);
            $out[] = $o;
        }
        return $out;
    };
    $tot = gaRunReport($conn, $property, ['dateRanges' => $range, 'metrics' => [['name' => 'activeUsers'], ['name' => 'sessions'], ['name' => 'screenPageViews'], ['name' => 'averageSessionDuration'], ['name' => 'engagementRate']]] + $filter);
    $tv = $tot['rows'][0]['metricValues'] ?? [];
    $data = [
        'property' => $property,
        'users' => (int)($tv[0]['value'] ?? 0), 'sessions' => (int)($tv[1]['value'] ?? 0), 'views' => (int)($tv[2]['value'] ?? 0),
        'avg_duration' => (float)($tv[3]['value'] ?? 0), 'engagement' => (float)($tv[4]['value'] ?? 0),
        'daily' => $rows(gaRunReport($conn, $property, ['dateRanges' => $range, 'dimensions' => [['name' => 'date']], 'metrics' => [['name' => 'activeUsers']], 'orderBys' => [['dimension' => ['dimensionName' => 'date']]]] + $filter), ['date'], ['activeUsers']),
        'pages' => $rows(gaRunReport($conn, $property, ['dateRanges' => $range, 'dimensions' => [['name' => 'hostName'], ['name' => 'pagePath']], 'metrics' => [['name' => 'screenPageViews'], ['name' => 'activeUsers']], 'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]], 'limit' => 12] + $filter), ['hostName', 'pagePath'], ['screenPageViews', 'activeUsers']),
        'channels' => $rows(gaRunReport($conn, $property, ['dateRanges' => $range, 'dimensions' => [['name' => 'sessionDefaultChannelGroup']], 'metrics' => [['name' => 'sessions']], 'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]], 'limit' => 8] + $filter), ['sessionDefaultChannelGroup'], ['sessions']),
        'countries' => $rows(gaRunReport($conn, $property, ['dateRanges' => $range, 'dimensions' => [['name' => 'country']], 'metrics' => [['name' => 'activeUsers']], 'orderBys' => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]], 'limit' => 8] + $filter), ['country'], ['activeUsers']),
        'hosts' => $rows(gaRunReport($conn, $property, ['dateRanges' => $range, 'dimensions' => [['name' => 'hostName']], 'metrics' => [['name' => 'activeUsers']], 'orderBys' => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]], 'limit' => 6]), ['hostName'], ['activeUsers']),
    ];
    saSet($conn, $ck, json_encode(['at' => time(), 'data' => $data]));
    return $data + ['cached_at' => time()];
}
