<?php
/**
 * Telling search engines about a page the moment it goes live, with IndexNow.
 *
 * Google stopped accepting sitemap pings in 2023 and its Indexing API only takes job postings and
 * live videos, so for Google a new post is found by the sitemap on its next crawl, or by asking for
 * indexing by hand in Search Console. IndexNow is the part that can be automatic: one POST and Bing,
 * Yandex, Seznam and Naver are told at once, and they share the submission between them.
 *
 *   rgIndexNowSubmit(['https://restrogrow.com/blog/some-post']);
 *
 * The key is a random string that also has to be readable at https://restrogrow.com/<key>.txt — that
 * is how the engines check the submission came from someone who controls the site. rgIndexNowKey()
 * makes one on first use and keeps it in main/uploads/cache/indexnow/, and indexnow.php at the site
 * root serves it. Nothing here throws and nothing here blocks: a failed submission is logged and the
 * page is still found by the sitemap.
 */
require_once __DIR__ . '/env_loader.php';

if (!function_exists('rgIndexNowSubmit')) {

    define('RG_INDEXNOW_DIR', __DIR__ . '/../uploads/cache/indexnow');

    /** The site's IndexNow key, made once and kept. Null when it cannot be stored. */
    function rgIndexNowKey(): ?string
    {
        $fromEnv = trim((string)env('INDEXNOW_KEY', ''));
        if (preg_match('/^[A-Za-z0-9-]{8,128}$/', $fromEnv)) return $fromEnv;

        $file = RG_INDEXNOW_DIR . '/key.txt';
        if (is_file($file)) {
            $k = trim((string)@file_get_contents($file));
            if (preg_match('/^[A-Za-z0-9-]{8,128}$/', $k)) return $k;
        }
        if (!is_dir(RG_INDEXNOW_DIR) && !@mkdir(RG_INDEXNOW_DIR, 0775, true)) return null;
        try { $k = bin2hex(random_bytes(16)); } catch (Throwable $e) { return null; }
        return @file_put_contents($file, $k) !== false ? $k : null;
    }

    /**
     * Tells the IndexNow engines that these pages are new or changed. Returns a short status for the
     * log or the screen; never throws. Only our own https pages are sent, and at most 100 at a time
     * (their limit is higher, but a long list usually means a bug).
     */
    function rgIndexNowSubmit(array $urls, string $host = ''): array
    {
        $key = rgIndexNowKey();
        if (!$key) return ['ok' => false, 'message' => 'No IndexNow key could be stored'];
        if (!function_exists('curl_init')) return ['ok' => false, 'message' => 'curl is not available'];

        $host = $host !== '' ? $host : (string)parse_url(trim((string)env('APP_URL', 'https://restrogrow.com')), PHP_URL_HOST);
        if ($host === '') $host = 'restrogrow.com';

        $clean = [];
        foreach ($urls as $u) {
            $u = trim((string)$u);
            if ($u === '' || !preg_match('#^https://#i', $u)) continue;
            if (strcasecmp((string)parse_url($u, PHP_URL_HOST), $host) !== 0) continue;   // only our own pages
            if (!in_array($u, $clean, true)) $clean[] = $u;
            if (count($clean) >= 100) break;
        }
        if (!$clean) return ['ok' => false, 'message' => 'Nothing to submit'];

        $body = json_encode([
            'host' => $host,
            'key' => $key,
            'keyLocation' => 'https://' . $host . '/' . $key . '.txt',
            'urlList' => $clean,
        ], JSON_UNESCAPED_SLASHES);

        $c = curl_init('https://api.indexnow.org/indexnow');
        curl_setopt_array($c, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            // kept short on purpose: this runs while someone is waiting for "Published" to appear,
            // and a server with no outbound access must not hold the save open
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        ]);
        $ca = trim((string)env('AI_CAINFO', ''));
        if ($ca !== '' && is_file($ca)) curl_setopt($c, CURLOPT_CAINFO, $ca);
        $res = curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
        $err = curl_error($c);
        curl_close($c);

        // 200 accepted, 202 accepted but the key is still being checked
        $ok = $code === 200 || $code === 202;
        if (!$ok) error_log('IndexNow: HTTP ' . $code . ' ' . $err . ' ' . substr((string)$res, 0, 200));
        return ['ok' => $ok, 'code' => $code, 'count' => count($clean),
            'message' => $ok ? ('Sent ' . count($clean) . ' page(s) to IndexNow') : ('IndexNow replied ' . $code)];
    }
}
