<?php
/**
 * Who visits restrogrow.com, from where, and which visits become leads and restaurants.
 *
 *  - /assets/rg-analytics.js gives each browser a random visitor id (first-party cookie rg_vid, a year), keeps
 *    how they first found us (utm tags, ad click ids, referrer, landing page) and sends page views and key
 *    actions (saw pricing, clicked Start free trial / WhatsApp / call, opened the chat) to api/site_hit.php.
 *  - Each hit stores the IP with its country / state / city (config/geo_ip.php) and updates one row per
 *    visitor (site_visitors): first and last source, visits, pages, location, a lead score.
 *  - A lead or sign-up (contact form, website chat, sign-up, unfinished sign-up) calls siteAttribute(): it
 *    reads the same cookie, so Super Admin sees "Instagram · Diwali campaign · Bengaluru · 6 pages before"
 *    on every lead, and Website analytics shows which sources bring leads and restaurants, not just visits.
 *  - Our own visits can be left out: Website analytics › Settings › "Don't count this browser" (cookie rg_ignore).
 *
 * Tables: site_visits (extra columns), site_visitors, site_events, lead_attribution. Super Admin › Website analytics.
 */
require_once __DIR__ . '/site_analytics.php';
require_once __DIR__ . '/geo_ip.php';

if (!function_exists('siteVid')) {

    function siteVisitorsEnsure(PDO $conn): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        siteAnalyticsEnsure($conn);
        $have = array_flip($conn->query('SHOW COLUMNS FROM site_visits')->fetchAll(PDO::FETCH_COLUMN));
        foreach ([
            'vid' => 'CHAR(20) NULL', 'ip' => 'VARCHAR(45) NULL', 'country_code' => 'CHAR(2) NULL', 'country' => 'VARCHAR(80) NULL',
            'region' => 'VARCHAR(100) NULL', 'city' => 'VARCHAR(100) NULL', 'utm_medium' => 'VARCHAR(80) NULL', 'utm_content' => 'VARCHAR(120) NULL',
            'utm_term' => 'VARCHAR(120) NULL', 'click_id' => 'VARCHAR(20) NULL', 'browser' => 'VARCHAR(30) NULL', 'os' => 'VARCHAR(30) NULL',
            'lang' => 'VARCHAR(20) NULL', 'new_session' => 'TINYINT NOT NULL DEFAULT 0',
        ] as $c => $def) if (!isset($have[$c])) $conn->exec("ALTER TABLE site_visits ADD COLUMN `$c` $def");
        try { $conn->exec('ALTER TABLE site_visits ADD INDEX idx_vid (vid)'); } catch (Throwable $e) { /* there already */ }
        $conn->exec("CREATE TABLE IF NOT EXISTS site_visitors (
            vid CHAR(20) PRIMARY KEY,
            first_seen DATETIME NOT NULL, last_seen DATETIME NOT NULL,
            sessions INT NOT NULL DEFAULT 1, pageviews INT NOT NULL DEFAULT 0,
            first_source VARCHAR(40) NULL, first_medium VARCHAR(80) NULL, first_campaign VARCHAR(120) NULL, first_content VARCHAR(120) NULL,
            first_referrer VARCHAR(120) NULL, first_landing VARCHAR(255) NULL, first_click_id VARCHAR(20) NULL,
            last_source VARCHAR(40) NULL, last_campaign VARCHAR(120) NULL,
            ip VARCHAR(45) NULL, country_code CHAR(2) NULL, country VARCHAR(80) NULL, region VARCHAR(100) NULL, city VARCHAR(100) NULL,
            device VARCHAR(10) NULL, browser VARCHAR(30) NULL, os VARCHAR(30) NULL, lang VARCHAR(20) NULL,
            saw_pricing TINYINT NOT NULL DEFAULT 0, clicks INT NOT NULL DEFAULT 0,
            lead_kind VARCHAR(20) NULL, lead_ref VARCHAR(40) NULL, restaurant_id VARCHAR(20) NULL, converted_at DATETIME NULL,
            INDEX (last_seen), INDEX (first_seen), INDEX (first_source)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->exec("CREATE TABLE IF NOT EXISTS site_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ts DATETIME NOT NULL, day DATE NOT NULL, vid CHAR(20) NULL,
            name VARCHAR(40) NOT NULL, path VARCHAR(255) NULL, label VARCHAR(120) NULL,
            INDEX (day, name), INDEX (vid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->exec("CREATE TABLE IF NOT EXISTS lead_attribution (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(20) NOT NULL, ref_id VARCHAR(40) NOT NULL,
            vid CHAR(20) NULL, source VARCHAR(40) NULL, medium VARCHAR(80) NULL, campaign VARCHAR(120) NULL, content VARCHAR(120) NULL,
            referrer VARCHAR(120) NULL, landing VARCHAR(255) NULL, click_id VARCHAR(20) NULL, last_source VARCHAR(40) NULL,
            ip VARCHAR(45) NULL, country_code CHAR(2) NULL, country VARCHAR(80) NULL, region VARCHAR(100) NULL, city VARCHAR(100) NULL,
            device VARCHAR(10) NULL, pages_before INT NOT NULL DEFAULT 0, sessions_before INT NOT NULL DEFAULT 0, days_to_convert INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ref (kind, ref_id), INDEX (source), INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** This browser's visitor id (cookie set by rg-analytics.js), or ''. */
    function siteVid(): string
    {
        $v = (string)($_COOKIE['rg_vid'] ?? '');
        return preg_match('/^[a-z0-9]{12,20}$/', $v) ? $v : '';
    }

    /** Browser and system names from the user agent, roughly. */
    function siteUaParts(string $ua): array
    {
        $b = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\/|Opera/', $ua) ? 'Opera' : (preg_match('/SamsungBrowser/', $ua) ? 'Samsung Internet'
            : (preg_match('/Instagram/', $ua) ? 'Instagram app' : (preg_match('/FBAN|FBAV/', $ua) ? 'Facebook app' : (preg_match('/Chrome\//', $ua) ? 'Chrome'
            : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Other')))))));
        $o = preg_match('/Android/', $ua) ? 'Android' : (preg_match('/iPhone|iPad|iPod/', $ua) ? 'iOS' : (preg_match('/Windows/', $ua) ? 'Windows'
            : (preg_match('/Mac OS X|Macintosh/', $ua) ? 'macOS' : (preg_match('/Linux/', $ua) ? 'Linux' : 'Other'))));
        return [$b, $o];
    }

    /** Ad click ids say where a visit came from even without utm tags. */
    function siteClickSource(string $clickId): string
    {
        return ['gclid' => 'Google Ads', 'gbraid' => 'Google Ads', 'wbraid' => 'Google Ads', 'fbclid' => 'Facebook', 'igshid' => 'Instagram',
            'ttclid' => 'TikTok', 'msclkid' => 'Bing Ads', 'li_fat_id' => 'LinkedIn'][$clickId] ?? '';
    }

    /**
     * Record one page view or action from rg-analytics.js. $in: p path, t title, r referrer, us/um/uc/uct/ut utm tags,
     * k click id name, v visitor id, n new session, lg language, e event name (an action instead of a page view), el label.
     */
    function siteTrackHit(PDO $conn, array $in, string $path): void
    {
        siteVisitorsEnsure($conn);
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $vid = preg_match('/^[a-z0-9]{12,20}$/', (string)($in['v'] ?? '')) ? (string)$in['v'] : siteVid();
        $ip = geoClientIp();
        $now = date('Y-m-d H:i:s');
        $day = date('Y-m-d');
        $s = fn($k, $n) => mb_substr(trim((string)($in[$k] ?? '')), 0, $n);

        // an action (pricing seen, a button clicked): only the event, and the visitor's interest
        $event = preg_replace('/[^a-z_]/', '', (string)($in['e'] ?? ''));
        if ($event !== '') {
            $conn->prepare('INSERT INTO site_events (ts, day, vid, name, path, label) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$now, $day, $vid ?: null, substr($event, 0, 40), $path, $s('el', 120) ?: null]);
            if ($vid !== '') {
                $conn->prepare('UPDATE site_visitors SET clicks = clicks + ?, saw_pricing = GREATEST(saw_pricing, ?), last_seen = ? WHERE vid = ?')
                    ->execute([$event === 'pricing_view' ? 0 : 1, $event === 'pricing_view' ? 1 : 0, $now, $vid]);
            }
            return;
        }

        $own = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $refHost = strtolower((string)(parse_url((string)($in['r'] ?? ''), PHP_URL_HOST) ?? ''));
        $utmS = $s('us', 80);
        $click = preg_match('/^[a-z_]{3,12}$/', (string)($in['k'] ?? '')) ? (string)$in['k'] : '';
        $source = siteSourceOf($refHost, $utmS, $own);
        // an ad click id names the ad network: "Google" + gclid = Google Ads, a bare fbclid = Facebook
        $clickSrc = $click !== '' ? siteClickSource($click) : '';
        if ($clickSrc !== '' && ($source === 'Direct' || strpos($clickSrc, $source) === 0)) $source = $clickSrc;
        // the next page of the same visit has no outside referrer: it still came from where the visit came from
        $newSession = !empty($in['n']) ? 1 : 0;
        if ($source === 'Direct' && !$newSession && $vid !== '') {
            $ls = $conn->prepare('SELECT last_source FROM site_visitors WHERE vid = ?');
            $ls->execute([$vid]);
            $source = (string)$ls->fetchColumn() ?: 'Direct';
        }
        $device = preg_match('/iPad|Tablet/i', $ua) ? 'tablet' : (preg_match('/Mobi|Android|iPhone/i', $ua) ? 'mobile' : 'desktop');
        [$browser, $os] = siteUaParts($ua);
        $geo = geoLookup($conn, $ip);
        $salt = (string)saGet($conn, 'site_visit_salt', '');
        if ($salt === '') { $salt = bin2hex(random_bytes(16)); saSet($conn, 'site_visit_salt', $salt); }
        // the daily count of visitors keeps its old meaning (one browser, one day)
        $visitor = substr(hash('sha256', $salt . '|' . $day . '|' . ($vid ?: $ip . '|' . $ua)), 0, 16);
        $conn->prepare('INSERT INTO site_visits (ts, day, path, title, source, referrer_host, utm_source, utm_campaign, device, visitor,
                vid, ip, country_code, country, region, city, utm_medium, utm_content, utm_term, click_id, browser, os, lang, new_session)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$now, $day, $path, $s('t', 200) ?: null, $source, $refHost !== '' && $refHost !== $own ? mb_substr($refHost, 0, 120) : null,
                $utmS ?: null, $s('uc', 120) ?: null, $device, $visitor, $vid ?: null, $ip ?: null,
                $geo['country_code'] ?: null, $geo['country'] ?: null, $geo['region'] ?: null, $geo['city'] ?: null,
                $s('um', 80) ?: null, $s('uct', 120) ?: null, $s('ut', 120) ?: null, $click ?: null, $browser, $os, $s('lg', 20) ?: null, $newSession]);
        if ($vid === '') return;

        // one row per visitor: the first source stays, the latest is kept too ("came from Google, came back from Instagram")
        $sourceIsReal = $source !== 'Direct' || $newSession;
        $conn->prepare('INSERT INTO site_visitors (vid, first_seen, last_seen, sessions, pageviews, first_source, first_medium, first_campaign, first_content, first_referrer,
                first_landing, first_click_id, last_source, last_campaign, ip, country_code, country, region, city, device, browser, os, lang)
                VALUES (?, ?, ?, 1, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen), pageviews = pageviews + 1, sessions = sessions + ?,
                    last_source = IF(?, VALUES(last_source), last_source), last_campaign = IF(? AND VALUES(last_campaign) IS NOT NULL, VALUES(last_campaign), last_campaign),
                    ip = VALUES(ip), country_code = COALESCE(VALUES(country_code), country_code), country = COALESCE(VALUES(country), country),
                    region = COALESCE(VALUES(region), region), city = COALESCE(VALUES(city), city), device = VALUES(device), browser = VALUES(browser), os = VALUES(os)')
            ->execute([$vid, $now, $now, $source, $s('um', 80) ?: null, $s('uc', 120) ?: null, $s('uct', 120) ?: null,
                $refHost !== '' && $refHost !== $own ? mb_substr($refHost, 0, 120) : null, $path, $click ?: null, $source, $s('uc', 120) ?: null,
                $ip ?: null, $geo['country_code'] ?: null, $geo['country'] ?: null, $geo['region'] ?: null, $geo['city'] ?: null, $device, $browser, $os, $s('lg', 20) ?: null,
                $newSession, $sourceIsReal ? 1 : 0, $sourceIsReal ? 1 : 0]);
    }

    /**
     * A lead or sign-up happened: remember where this person came from. $kind: contact, chat, signup, signup_started.
     * $vid / $ip default to the current request (a cron passes the ones saved earlier). Never throws.
     */
    function siteAttribute(PDO $conn, string $kind, string $refId, ?string $vid = null, ?string $ip = null): void
    {
        try {
            siteVisitorsEnsure($conn);
            $vid = $vid ?? siteVid();
            $ip = $ip ?? geoClientIp();
            $v = null;
            if ($vid !== '') {
                $st = $conn->prepare('SELECT * FROM site_visitors WHERE vid = ?');
                $st->execute([$vid]);
                $v = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            $geo = $v && $v['city'] ? ['country_code' => $v['country_code'], 'country' => $v['country'], 'region' => $v['region'], 'city' => $v['city']] : geoLookup($conn, $ip);
            $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
            $device = $v['device'] ?? ($ua === '' ? null : (preg_match('/iPad|Tablet/i', $ua) ? 'tablet' : (preg_match('/Mobi|Android|iPhone/i', $ua) ? 'mobile' : 'desktop')));
            $conn->prepare('INSERT IGNORE INTO lead_attribution (kind, ref_id, vid, source, medium, campaign, content, referrer, landing, click_id, last_source,
                    ip, country_code, country, region, city, device, pages_before, sessions_before, days_to_convert)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$kind, mb_substr($refId, 0, 40), $vid ?: null, $v['first_source'] ?? 'Unknown', $v['first_medium'] ?? null, $v['first_campaign'] ?? null,
                    $v['first_content'] ?? null, $v['first_referrer'] ?? null, $v['first_landing'] ?? null, $v['first_click_id'] ?? null, $v['last_source'] ?? null,
                    $ip ?: null, ($geo['country_code'] ?? '') ?: null, ($geo['country'] ?? '') ?: null, ($geo['region'] ?? '') ?: null, ($geo['city'] ?? '') ?: null,
                    $device, (int)($v['pageviews'] ?? 0), (int)($v['sessions'] ?? 0),
                    $v ? (int)floor((time() - strtotime($v['first_seen'])) / 86400) : null]);
            if ($vid !== '') {
                // lead_ref before lead_kind: MySQL applies SET left to right, and both test lead_kind's old value
                $conn->prepare('UPDATE site_visitors SET lead_ref = IF(lead_kind IS NULL OR ? = \'signup\', ?, lead_ref), lead_kind = IF(lead_kind IS NULL OR ? = \'signup\', ?, lead_kind),
                        restaurant_id = IF(? = \'signup\', ?, restaurant_id), converted_at = COALESCE(converted_at, NOW()) WHERE vid = ?')
                    ->execute([$kind, $refId, $kind, $kind, $kind, $refId, $vid]);
            }
        } catch (Throwable $e) {
            error_log('siteAttribute: ' . $e->getMessage());
        }
    }

    /** kind → [ref_id => attribution row], for lists of leads. */
    function siteAttributionsFor(PDO $conn, string $kind, array $refIds): array
    {
        $refIds = array_values(array_unique(array_map('strval', $refIds)));
        if (!$refIds) return [];
        try {
            siteVisitorsEnsure($conn);
            $st = $conn->prepare('SELECT ref_id, source, medium, campaign, referrer, landing, last_source, country_code, country, region, city, device, pages_before, sessions_before, days_to_convert
                FROM lead_attribution WHERE kind = ? AND ref_id IN (' . implode(',', array_fill(0, count($refIds), '?')) . ')');
            $st->execute(array_merge([$kind], $refIds));
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['ref_id']] = $r;
            return $out;
        } catch (Throwable $e) {
            error_log('siteAttributionsFor: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * How warm a visitor is, 0–100: came back, read a lot, saw pricing, clicked a call-to-action, from a
     * search or ad (they were looking), in India (our market).
     */
    function siteScore(array $v): int
    {
        $s = min(25, ((int)$v['sessions'] - 1) * 10) + min(25, (int)$v['pageviews'] * 4) + ((int)$v['saw_pricing'] ? 20 : 0) + min(20, (int)$v['clicks'] * 10);
        if (in_array($v['first_source'], ['Google', 'Google Ads', 'Bing', 'ChatGPT', 'Perplexity'], true)) $s += 5;
        if (($v['country_code'] ?? '') === 'IN') $s += 5;
        return min(100, $s);
    }

    /** Website analytics: locations, sources that bring leads, recent visitors, actions. */
    function siteVisitorReport(PDO $conn, int $days): array
    {
        siteVisitorsEnsure($conn);
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $fromTs = $from . ' 00:00:00';
        $q = function (string $sql, array $p = []) use ($conn) { $st = $conn->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); };
        // visitors, leads and restaurants per first source: what actually brings business
        $bySource = [];
        // converted = people who became a lead or restaurant (one person can make a chat lead and then sign up)
        foreach ($q('SELECT COALESCE(first_source, \'Direct\') s, COUNT(*) visitors, SUM(lead_kind IS NOT NULL) converted FROM site_visitors WHERE first_seen >= ? GROUP BY s', [$fromTs]) as $r)
            $bySource[$r['s']] = ['source' => $r['s'], 'visitors' => (int)$r['visitors'], 'converted' => (int)$r['converted'], 'leads' => 0, 'signups' => 0];
        foreach ($q("SELECT COALESCE(source, 'Unknown') s, SUM(kind IN ('contact','chat','signup_started')) leads, SUM(kind = 'signup') signups FROM lead_attribution WHERE created_at >= ? GROUP BY s", [$fromTs]) as $r) {
            $bySource[$r['s']] = ($bySource[$r['s']] ?? ['source' => $r['s'], 'visitors' => 0, 'converted' => 0, 'leads' => 0, 'signups' => 0]);
            $bySource[$r['s']]['leads'] = (int)$r['leads'];
            $bySource[$r['s']]['signups'] = (int)$r['signups'];
        }
        usort($bySource, fn($a, $b) => [$b['signups'], $b['leads'], $b['visitors']] <=> [$a['signups'], $a['leads'], $a['visitors']]);
        $recent = $q('SELECT * FROM site_visitors WHERE last_seen >= ? ORDER BY last_seen DESC LIMIT 60', [$fromTs]);
        foreach ($recent as &$v) $v['score'] = siteScore($v);
        unset($v);
        $hot = array_values(array_filter($recent, fn($v) => $v['score'] >= 50 && !$v['lead_kind']));
        usort($hot, fn($a, $b) => $b['score'] <=> $a['score']);
        return [
            'live' => (int)($q('SELECT COUNT(DISTINCT COALESCE(vid, visitor)) n FROM site_visits WHERE ts >= NOW() - INTERVAL 5 MINUTE')[0]['n'] ?? 0),
            'countries' => $q("SELECT COALESCE(country, 'Unknown') country, MAX(country_code) code, COUNT(DISTINCT COALESCE(vid, visitor)) visitors FROM site_visits WHERE day >= ? GROUP BY country ORDER BY visitors DESC LIMIT 12", [$from]),
            'cities' => $q("SELECT city, MAX(region) region, MAX(country_code) code, COUNT(DISTINCT COALESCE(vid, visitor)) visitors FROM site_visits WHERE day >= ? AND city IS NOT NULL GROUP BY city ORDER BY visitors DESC LIMIT 15", [$from]),
            'by_source' => array_slice(array_values($bySource), 0, 15),
            'campaigns' => $q("SELECT CONCAT(COALESCE(first_source, 'Direct'), ' · ', first_campaign) campaign, COUNT(*) visitors, SUM(lead_kind IS NOT NULL) leads, SUM(restaurant_id IS NOT NULL) signups
                FROM site_visitors WHERE first_seen >= ? AND first_campaign IS NOT NULL GROUP BY campaign ORDER BY visitors DESC LIMIT 10", [$fromTs]),
            'landing' => $q("SELECT first_landing page, COUNT(*) visitors, SUM(lead_kind IS NOT NULL) leads FROM site_visitors WHERE first_seen >= ? GROUP BY first_landing ORDER BY visitors DESC LIMIT 10", [$fromTs]),
            'events' => $q('SELECT name, COUNT(*) n, COUNT(DISTINCT vid) people FROM site_events WHERE day >= ? GROUP BY name ORDER BY n DESC', [$from]),
            'browsers' => $q("SELECT browser, COUNT(DISTINCT COALESCE(vid, visitor)) visitors FROM site_visits WHERE day >= ? AND browser IS NOT NULL GROUP BY browser ORDER BY visitors DESC LIMIT 6", [$from]),
            'recent' => $recent,
            'hot' => array_slice($hot, 0, 10),
            'returning' => (int)($q('SELECT COUNT(*) n FROM site_visitors WHERE last_seen >= ? AND sessions > 1', [$fromTs])[0]['n'] ?? 0),
            'new' => (int)($q('SELECT COUNT(*) n FROM site_visitors WHERE first_seen >= ?', [$fromTs])[0]['n'] ?? 0),
            'converted' => (int)($q('SELECT COUNT(*) n FROM site_visitors WHERE first_seen >= ? AND lead_kind IS NOT NULL', [$fromTs])[0]['n'] ?? 0),
            'leads' => (int)($q("SELECT COUNT(*) n FROM lead_attribution WHERE created_at >= ? AND kind <> 'signup'", [$fromTs])[0]['n'] ?? 0),
            'signups' => (int)($q("SELECT COUNT(*) n FROM lead_attribution WHERE created_at >= ? AND kind = 'signup'", [$fromTs])[0]['n'] ?? 0),
        ];
    }
}
