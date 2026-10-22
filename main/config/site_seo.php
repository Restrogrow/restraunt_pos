<?php
/**
 * SEO dashboard data (Super Admin › SEO), for RestroGrow's own pages only (home, about, FAQ, app demo,
 * blog, legal pages); restaurant websites on restrogrow.com are left out.
 *
 *  - Google Search Console: clicks, impressions, CTR and position, keywords and their pages, sitemaps,
 *    and each page's index status (URL Inspection API). Access, either:
 *      a) Connect Google (Super Admin › SEO › Setup): sign in with an account that owns the property; uses the
 *         same OAuth client as the restaurants' SEO page (GOOGLE_SEO_CLIENT_ID), tokens kept as "__superadmin__"
 *         in seo_search_console; or
 *      b) the service-account key uploaded in Website analytics (config/.ga-service-account.json), with its
 *         email added as a user on the Search Console property ("Restricted" is enough).
 *  - Our own page views per page (site_visits, the same tracking as Website analytics).
 *  - An on-page check of every page (title, description, canonical, noindex, H1, image alt text, length).
 *
 * Google answers are cached (seo_cache, 6 hours); index status and page checks are kept per URL for a day
 * and refreshed a few at a time so the page stays fast.
 */
require_once __DIR__ . '/site_analytics.php';
require_once __DIR__ . '/seo_search_console.php';

const SEO_SA_RID = '__superadmin__';   // Super Admin's own Connect Google tokens in seo_search_console

const SEO_GSC_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
// RestroGrow's own pages (Search Console page filter, RE2 syntax)
const SEO_OWN_RE = '^https://(www\.)?restrogrow\.com/(|about/?|faq/?|app-demo/?|blog(/.*)?|privacy-policy/?|terms-of-service/?|refund-policy/?|cookie-policy/?)(\?.*)?$';

function seoSite(PDO $c): string { return (string)saGet($c, 'gsc_site', 'sc-domain:restrogrow.com'); }
function seoPathOf(string $url): string { $p = (string)parse_url($url, PHP_URL_PATH); return $p === '' ? '/' : $p; }
function seoOwnPath(string $path): bool
{
    return (bool)preg_match('#^/(|about/?|faq/?|app-demo/?|blog(/.*)?|privacy-policy/?|terms-of-service/?|refund-policy/?|cookie-policy/?)$#', $path);
}

function seoEnsure(PDO $c): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    siteAnalyticsEnsure($c);
    $c->exec("CREATE TABLE IF NOT EXISTS seo_cache (k VARCHAR(120) NOT NULL PRIMARY KEY, v MEDIUMTEXT NULL, at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $c->exec("CREATE TABLE IF NOT EXISTS seo_url_status (
        url VARCHAR(255) NOT NULL PRIMARY KEY,
        verdict VARCHAR(30) NULL, coverage VARCHAR(200) NULL, indexing VARCHAR(60) NULL, fetch_state VARCHAR(60) NULL,
        last_crawl DATETIME NULL, google_canonical VARCHAR(255) NULL, error VARCHAR(300) NULL, checked_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $c->exec("CREATE TABLE IF NOT EXISTS seo_page_audit (
        url VARCHAR(255) NOT NULL PRIMARY KEY,
        http INT NOT NULL DEFAULT 0, title VARCHAR(300) NULL, description VARCHAR(500) NULL, canonical VARCHAR(255) NULL,
        robots VARCHAR(120) NULL, h1 INT NOT NULL DEFAULT 0, images INT NOT NULL DEFAULT 0, images_no_alt INT NOT NULL DEFAULT 0,
        words INT NOT NULL DEFAULT 0, og_image VARCHAR(255) NULL, issues TEXT NULL, checked_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ───────── Google access ───────── */

function seoOauthConnected(PDO $c): bool { return !empty(seoScRow($c, SEO_SA_RID)['refresh_token']); }
/** True when Search Console can be read: Connect Google done, or a service-account key uploaded. */
function seoGoogleReady(PDO $c): bool { return seoOauthConnected($c) || gaServiceAccount() !== null; }

function seoToken(PDO $c, array $sa): string
{
    $cached = json_decode((string)saGet($c, 'gsc_token_cache', ''), true);
    if (is_array($cached) && ($cached['exp'] ?? 0) > time() + 60 && ($cached['who'] ?? '') === $sa['client_email']) return $cached['token'];
    $now = time();
    $h = gaB64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $p = gaB64(json_encode(['iss' => $sa['client_email'], 'scope' => SEO_GSC_SCOPE, 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
    if (!openssl_sign($h . '.' . $p, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('The Google key could not sign a request. Upload the JSON key again in Website analytics › Settings.');
    $res = gaHttp('https://oauth2.googleapis.com/token', http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $h . '.' . $p . '.' . gaB64($sig)]), ['Content-Type: application/x-www-form-urlencoded']);
    if (empty($res['access_token'])) throw new RuntimeException('Google did not accept the key: ' . ($res['error_description'] ?? $res['error'] ?? 'unknown error') . '.');
    saSet($c, 'gsc_token_cache', json_encode(['token' => $res['access_token'], 'exp' => $now + (int)($res['expires_in'] ?? 3600), 'who' => $sa['client_email']]));
    return $res['access_token'];
}

function seoApi(PDO $c, string $method, string $url, ?array $body = null): array
{
    $oauth = seoOauthConnected($c);
    $sa = $oauth ? null : gaServiceAccount();
    if (!$oauth && !$sa) throw new RuntimeException('Search Console is not connected. Open Setup and click Connect Google.');
    $token = $oauth ? seoScToken($c, SEO_SA_RID) : seoToken($c, $sa);
    if (!$token) throw new RuntimeException('The Google sign-in has expired. Open Setup and click Connect Google again.');
    $ch = curl_init($url);
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token]];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    $ca = function_exists('env') ? (string)env('AI_CAINFO', '') : '';
    if ($ca !== '' && is_readable($ca)) $opts[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($ch, $opts);
    $out = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($out === false) throw new RuntimeException('Could not reach Google: ' . $err);
    $res = json_decode((string)$out, true) ?: [];
    if (!empty($res['error'])) {
        $m = (string)($res['error']['message'] ?? 'Google refused the request');
        if (in_array((int)($res['error']['code'] ?? 0), [401, 403], true)) {
            $who = $oauth ? ((seoScRow($c, SEO_SA_RID)['google_email'] ?? '') ?: 'the connected Google account') : $sa['client_email'];
            $m = $who . ' has no access to ' . seoSite($c) . '. ' . ($oauth ? 'Connect Google again with an account that owns the property (teamrestrogrow@gmail.com).' : 'In Search Console › Settings › Users and permissions, add it (Restricted is enough).') . ' (' . $m . ')';
        }
        throw new RuntimeException($m);
    }
    return $res;
}

/** A cached answer: runs $fn only when the stored copy is older than $ttl seconds. */
function seoCached(PDO $c, string $key, int $ttl, callable $fn)
{
    $st = $c->prepare('SELECT v, at FROM seo_cache WHERE k = ?');
    $st->execute([$key]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && strtotime((string)$row['at']) > time() - $ttl) return json_decode((string)$row['v'], true);
    $v = $fn();
    $c->prepare('REPLACE INTO seo_cache (k, v, at) VALUES (?, ?, NOW())')->execute([$key, json_encode($v)]);
    return $v;
}

function seoClearCache(PDO $c): void { $c->exec('DELETE FROM seo_cache'); }

/** Search Console rows; $ownOnly limits them to RestroGrow's own pages. */
function seoQuery(PDO $c, string $start, string $end, array $dims, bool $ownOnly = true, int $limit = 1000): array
{
    $req = ['startDate' => $start, 'endDate' => $end, 'dimensions' => $dims, 'rowLimit' => $limit, 'dataState' => 'all'];
    if ($ownOnly) $req['dimensionFilterGroups'] = [['filters' => [['dimension' => 'page', 'operator' => 'includingRegex', 'expression' => SEO_OWN_RE]]]];
    $res = seoApi($c, 'POST', 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode(seoSite($c)) . '/searchAnalytics/query', $req);
    return $res['rows'] ?? [];
}

/** [current start, current end, previous start, previous end]; Search Console data is about 2 days behind. */
function seoPeriods(int $days): array
{
    $end = strtotime('-2 days');
    $start = strtotime('-' . ($days - 1) . ' days', $end);
    $pEnd = strtotime('-1 day', $start);
    $pStart = strtotime('-' . ($days - 1) . ' days', $pEnd);
    return [date('Y-m-d', $start), date('Y-m-d', $end), date('Y-m-d', $pStart), date('Y-m-d', $pEnd)];
}

function seoTotals(array $rows): array
{
    $t = ['clicks' => 0, 'impressions' => 0, 'pos_w' => 0.0];
    foreach ($rows as $r) { $t['clicks'] += $r['clicks']; $t['impressions'] += $r['impressions']; $t['pos_w'] += $r['position'] * $r['impressions']; }
    $t['ctr'] = $t['impressions'] ? $t['clicks'] / $t['impressions'] : 0;
    $t['position'] = $t['impressions'] ? $t['pos_w'] / $t['impressions'] : 0;
    unset($t['pos_w']);
    return $t;
}

/** Everything the dashboard shows from Search Console for a period (cached 6 hours). */
function seoReport(PDO $c, int $days): array
{
    return seoCached($c, 'report_' . $days . '_' . md5(seoSite($c)), 6 * 3600, function () use ($c, $days) {
        [$s, $e, $ps, $pe] = seoPeriods($days);
        $own = seoTotals(seoQuery($c, $s, $e, ['page']));
        $ownPrev = seoTotals(seoQuery($c, $ps, $pe, ['page']));
        $site = seoTotals(seoQuery($c, $s, $e, ['page'], false, 5000));
        $daily = array_map(fn($r) => ['date' => $r['keys'][0], 'clicks' => $r['clicks'], 'impressions' => $r['impressions']], seoQuery($c, $s, $e, ['date']));

        // keywords: per query, with the page that shows most and the change in position
        $agg = function (array $rows) {
            $q = [];
            foreach ($rows as $r) {
                [$query, $page] = $r['keys'];
                $x = &$q[$query];
                $x['query'] = $query;
                $x['clicks'] = ($x['clicks'] ?? 0) + $r['clicks'];
                $x['impressions'] = ($x['impressions'] ?? 0) + $r['impressions'];
                $x['pos_w'] = ($x['pos_w'] ?? 0) + $r['position'] * $r['impressions'];
                if ($r['impressions'] > ($x['top_impr'] ?? -1)) { $x['top_impr'] = $r['impressions']; $x['page'] = seoPathOf($page); }
                unset($x);
            }
            foreach ($q as &$x) { $x['position'] = $x['impressions'] ? $x['pos_w'] / $x['impressions'] : 0; $x['ctr'] = $x['impressions'] ? $x['clicks'] / $x['impressions'] : 0; unset($x['pos_w'], $x['top_impr']); }
            return $q;
        };
        $kw = $agg(seoQuery($c, $s, $e, ['query', 'page'], true, 5000));
        $kwPrev = $agg(seoQuery($c, $ps, $pe, ['query', 'page'], true, 5000));
        foreach ($kw as $k => &$x) $x['prev_position'] = isset($kwPrev[$k]) ? $kwPrev[$k]['position'] : null;
        unset($x);
        $kw = array_values($kw);
        usort($kw, fn($a, $b) => [$b['clicks'], $b['impressions']] <=> [$a['clicks'], $a['impressions']]);

        $pages = [];
        foreach (seoQuery($c, $s, $e, ['page']) as $r) {
            $path = seoPathOf($r['keys'][0]);
            $p = &$pages[$path];
            $p['clicks'] = ($p['clicks'] ?? 0) + $r['clicks'];
            $p['impressions'] = ($p['impressions'] ?? 0) + $r['impressions'];
            $p['pos_w'] = ($p['pos_w'] ?? 0) + $r['position'] * $r['impressions'];
            unset($p);
        }
        foreach ($pages as &$p) { $p['position'] = $p['impressions'] ? $p['pos_w'] / $p['impressions'] : 0; unset($p['pos_w']); }
        unset($p);
        return ['period' => [$s, $e, $ps, $pe], 'own' => $own, 'own_prev' => $ownPrev, 'site' => $site, 'daily' => $daily, 'keywords' => $kw, 'pages' => $pages];
    });
}

function seoSitemaps(PDO $c): array
{
    return seoCached($c, 'sitemaps_' . md5(seoSite($c)), 6 * 3600, function () use ($c) {
        $res = seoApi($c, 'GET', 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode(seoSite($c)) . '/sitemaps');
        return $res['sitemap'] ?? [];
    });
}

/* ───────── RestroGrow's own URLs ───────── */

function seoOwnUrls(PDO $c): array
{
    $urls = [];
    $xml = @file_get_contents(dirname(__DIR__, 2) . '/sitemap.xml');
    if ($xml && preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#', $xml, $m)) $urls = $m[1];
    try {
        require_once __DIR__ . '/blog_engine.php';
        blogEnsure($c);
        foreach ($c->query("SELECT slug FROM blog_posts WHERE status = 'published' AND published_at <= NOW() AND noindex = 0 ORDER BY published_at DESC")->fetchAll(PDO::FETCH_COLUMN) as $slug) {
            $urls[] = 'https://restrogrow.com/blog/' . $slug;
        }
    } catch (Throwable $e) { /* blog tables missing: just the sitemap */ }
    return array_values(array_unique($urls));
}

/* ───────── index status (URL Inspection API, about 2,000 a day allowed) ───────── */

function seoInspect(PDO $c, string $url): void
{
    $row = ['url' => $url, 'verdict' => null, 'coverage' => null, 'indexing' => null, 'fetch_state' => null, 'last_crawl' => null, 'google_canonical' => null, 'error' => null];
    try {
        $res = seoApi($c, 'POST', 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', ['inspectionUrl' => $url, 'siteUrl' => seoSite($c)]);
        $r = $res['inspectionResult']['indexStatusResult'] ?? [];
        $row['verdict'] = $r['verdict'] ?? null;
        $row['coverage'] = $r['coverageState'] ?? null;
        $row['indexing'] = $r['indexingState'] ?? null;
        $row['fetch_state'] = $r['pageFetchState'] ?? null;
        $row['last_crawl'] = !empty($r['lastCrawlTime']) ? date('Y-m-d H:i:s', strtotime($r['lastCrawlTime'])) : null;
        $row['google_canonical'] = $r['googleCanonical'] ?? null;
    } catch (Throwable $e) {
        $row['error'] = mb_substr($e->getMessage(), 0, 300);
    }
    $c->prepare('REPLACE INTO seo_url_status (url, verdict, coverage, indexing, fetch_state, last_crawl, google_canonical, error, checked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute(array_values($row));
}

/** Checks up to $max pages whose index status is missing or older than a day. Returns how many it checked. */
function seoInspectStale(PDO $c, int $max = 5): int
{
    $have = $c->query('SELECT url, checked_at FROM seo_url_status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $n = 0;
    foreach (seoOwnUrls($c) as $u) {
        if ($n >= $max) break;
        if (isset($have[$u]) && strtotime($have[$u]) > time() - 86400) continue;
        seoInspect($c, $u);
        $n++;
    }
    return $n;
}

/* ───────── on-page check ───────── */

function seoAudit(PDO $c, string $url): void
{
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'RestroGrow-SEO-check/1.0'];
    $ca = function_exists('env') ? (string)env('AI_CAINFO', '') : '';
    if ($ca !== '' && is_readable($ca)) $opts[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($ch, $opts);
    $html = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $a = ['http' => $code, 'title' => '', 'description' => '', 'canonical' => '', 'robots' => '', 'h1' => 0, 'images' => 0, 'images_no_alt' => 0, 'words' => 0, 'og_image' => ''];
    $issues = [];
    if ($code !== 200) {
        $issues[] = ['high', 'The page answers with HTTP ' . ($code ?: 'no response') . ' instead of 200'];
    } else {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);
        $a['title'] = trim((string)($xp->query('//title')->item(0)->textContent ?? ''));
        $meta = function (string $q) use ($xp) { $n = $xp->query($q)->item(0); return $n ? trim((string)$n->getAttribute('content')) : ''; };
        $a['description'] = $meta('//meta[@name="description"]');
        $a['robots'] = $meta('//meta[@name="robots"]');
        $a['og_image'] = $meta('//meta[@property="og:image"]');
        $can = $xp->query('//link[@rel="canonical"]')->item(0);
        $a['canonical'] = $can ? trim((string)$can->getAttribute('href')) : '';
        $a['h1'] = $xp->query('//h1')->length;
        foreach ($xp->query('//body//img') as $img) {
            $a['images']++;
            if (trim((string)$img->getAttribute('alt')) === '' && $img->getAttribute('role') !== 'presentation' && $img->getAttribute('aria-hidden') !== 'true') $a['images_no_alt']++;
        }
        foreach ($xp->query('//script|//style|//noscript') as $n) $n->parentNode->removeChild($n);
        $body = $xp->query('//body')->item(0);
        $a['words'] = $body ? str_word_count(preg_replace('/\s+/', ' ', (string)$body->textContent)) : 0;

        $tl = mb_strlen($a['title']); $dl = mb_strlen($a['description']);
        if ($tl === 0) $issues[] = ['high', 'No page title'];
        elseif ($tl > 65) $issues[] = ['medium', "Title is $tl characters; Google shows about 60"];
        elseif ($tl < 25) $issues[] = ['low', "Title is only $tl characters"];
        if ($dl === 0) $issues[] = ['medium', 'No meta description'];
        elseif ($dl > 165) $issues[] = ['low', "Description is $dl characters; Google shows about 155"];
        elseif ($dl < 70) $issues[] = ['low', "Description is only $dl characters"];
        if ($a['canonical'] === '') $issues[] = ['medium', 'No canonical link'];
        elseif (rtrim($a['canonical'], '/') !== rtrim($url, '/')) $issues[] = ['medium', 'Canonical points to another address: ' . $a['canonical']];
        if (stripos($a['robots'], 'noindex') !== false) $issues[] = ['high', 'The page tells Google not to index it (noindex)'];
        if ($a['h1'] === 0) $issues[] = ['medium', 'No H1 heading'];
        elseif ($a['h1'] > 1) $issues[] = ['low', $a['h1'] . ' H1 headings (one is best)'];
        if ($a['images_no_alt'] > 0) $issues[] = ['medium', $a['images_no_alt'] . ' of ' . $a['images'] . ' images have no alt text'];
        if ($a['og_image'] === '' || stripos($a['og_image'], 'logo') !== false) $issues[] = ['low', 'Share image (og:image) is missing or only the logo'];
        if (strpos(seoPathOf($url), '/blog/') === 0 && $a['words'] < 600) $issues[] = ['medium', 'Only about ' . $a['words'] . ' words; ranking posts are usually 900+'];
    }
    $c->prepare('REPLACE INTO seo_page_audit (url, http, title, description, canonical, robots, h1, images, images_no_alt, words, og_image, issues, checked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$url, $a['http'], mb_substr($a['title'], 0, 300), mb_substr($a['description'], 0, 500), mb_substr($a['canonical'], 0, 255), mb_substr($a['robots'], 0, 120),
            $a['h1'], $a['images'], $a['images_no_alt'], $a['words'], mb_substr($a['og_image'], 0, 255), json_encode($issues)]);
}

/** Checks up to $max pages whose on-page check is missing or older than a day. */
function seoAuditStale(PDO $c, int $max = 6): int
{
    $have = $c->query('SELECT url, checked_at FROM seo_page_audit')->fetchAll(PDO::FETCH_KEY_PAIR);
    $n = 0;
    foreach (seoOwnUrls($c) as $u) {
        if ($n >= $max) break;
        if (isset($have[$u]) && strtotime($have[$u]) > time() - 86400) continue;
        seoAudit($c, $u);
        $n++;
    }
    return $n;
}

/* ───────── our own page views ───────── */

/** path => [views, visitors] for the last $days days (site_visits, the Website analytics tracking). */
function seoViews(PDO $c, int $days): array
{
    $st = $c->prepare('SELECT path, COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors FROM site_visits WHERE day >= ? GROUP BY path');
    $st->execute([date('Y-m-d', strtotime('-' . ($days - 1) . ' days'))]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $p = strtok((string)$r['path'], '?');
        if (!seoOwnPath($p) && !seoOwnPath(rtrim($p, '/') . '/')) continue;
        $key = rtrim($p, '/') === '' ? '/' : rtrim($p, '/');
        $out[$key]['views'] = ($out[$key]['views'] ?? 0) + (int)$r['views'];
        $out[$key]['visitors'] = ($out[$key]['visitors'] ?? 0) + (int)$r['visitors'];
    }
    return $out;
}
