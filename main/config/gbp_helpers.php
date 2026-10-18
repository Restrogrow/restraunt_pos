<?php
/**
 * Google Business (sidebar › Google Business): the restaurant's Google Business Profile.
 *
 * Two levels:
 *  - Without connecting: the Google rating and newest reviews from the Places API (New) (API key:
 *    GOOGLE_PLACES_API_KEY, else GOOGLE_MAPS_API_KEY), a review link + QR, and a tracked short
 *    link (website/review.php) that counts scans and taps.
 *  - Connected (OAuth, GOOGLE_GBP_CLIENT_ID / GOOGLE_GBP_CLIENT_SECRET, scope business.manage): every
 *    review with replies from the dashboard, posts (updates, offers, events), business info (phone,
 *    website, hours, description) and insights (views, calls, directions, website clicks).
 *    Google must approve the Business Profile APIs for the project (restrogrow-gmb) first; until then
 *    Google answers 403/429 and the page says so.
 *
 * Tokens are stored encrypted (AES-256-GCM with WA_KEY_SECRET, same as the WhatsApp keys).
 * Used by api/gbp.php, api/google_business_callback.php and website/review.php.
 */

const GBP_SCOPE = 'https://www.googleapis.com/auth/business.manage';

function gbpEnv(string $k, string $d = ''): string { return function_exists('env') ? trim((string)env($k, $d)) : $d; }
function gbpClientId(): string { return gbpEnv('GOOGLE_GBP_CLIENT_ID'); }
function gbpClientSecret(): string { return gbpEnv('GOOGLE_GBP_CLIENT_SECRET'); }
function gbpOAuthReady(): bool { return gbpClientId() !== '' && gbpClientSecret() !== ''; }
function gbpPlacesKey(): string { return gbpEnv('GOOGLE_PLACES_API_KEY') ?: gbpEnv('GOOGLE_MAPS_API_KEY'); }

/** Where Google sends the owner back after "Connect Google" (must be listed on the OAuth client). */
function gbpRedirectUri(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'restrogrow.com';
    $local = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host);
    $base = $local ? 'http://' . $host . '/menuwebsite' : 'https://' . $host;
    return $base . '/main/api/google_business_callback.php';
}

/* ───────── tokens at rest ───────── */
function gbpKey(): ?string
{
    $k = gbpEnv('WA_KEY_SECRET');
    if ($k === '') return null;
    $raw = base64_decode($k, true);
    return ($raw !== false && strlen($raw) >= 32) ? substr($raw, 0, 32) : hash('sha256', $k, true);
}
function gbpSeal(string $plain): string
{
    $k = gbpKey();
    if ($plain === '' || $k === null) return $plain;
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return $ct === false ? $plain : 'enc:v1:' . base64_encode($iv . $tag . $ct);
}
function gbpOpen(?string $stored): string
{
    $stored = (string)$stored;
    if (strpos($stored, 'enc:v1:') !== 0) return $stored;
    $k = gbpKey(); $raw = base64_decode(substr($stored, 7), true);
    $p = ($k !== null && $raw !== false && strlen($raw) > 28) ? openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)) : false;
    return $p === false ? '' : $p;
}

/* ───────── tables ───────── */
function gbpEnsureTables(PDO $conn): void
{
    static $done = false;
    if ($done || $conn->inTransaction()) return;
    $done = true;
    $conn->exec("CREATE TABLE IF NOT EXISTS gbp_connections (
        restaurant_id VARCHAR(50) PRIMARY KEY,
        google_email VARCHAR(190) DEFAULT NULL,
        access_token TEXT DEFAULT NULL,
        refresh_token TEXT DEFAULT NULL,
        token_expires INT DEFAULT NULL,
        account_name VARCHAR(120) DEFAULT NULL,
        location_name VARCHAR(120) DEFAULT NULL,
        location_title VARCHAR(190) DEFAULT NULL,
        place_id VARCHAR(190) DEFAULT NULL,
        place_name VARCHAR(190) DEFAULT NULL,
        rating DECIMAL(3,2) DEFAULT NULL,
        rating_count INT DEFAULT NULL,
        places_json MEDIUMTEXT DEFAULT NULL,
        places_synced_at DATETIME DEFAULT NULL,
        reviews_synced_at DATETIME DEFAULT NULL,
        last_error VARCHAR(400) DEFAULT NULL,
        auto_reply TINYINT(1) NOT NULL DEFAULT 0,
        connected_at DATETIME DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->exec("CREATE TABLE IF NOT EXISTS gbp_reviews (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(50) NOT NULL,
        review_id VARCHAR(190) NOT NULL,
        source VARCHAR(10) NOT NULL DEFAULT 'gbp',
        reviewer_name VARCHAR(190) DEFAULT NULL,
        reviewer_photo VARCHAR(500) DEFAULT NULL,
        rating TINYINT NOT NULL DEFAULT 0,
        comment TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT NULL,
        reply TEXT DEFAULT NULL,
        replied_at DATETIME DEFAULT NULL,
        synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_gbp_review (restaurant_id, review_id),
        INDEX idx_gbp_reviews (restaurant_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->exec("CREATE TABLE IF NOT EXISTS gbp_posts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(50) NOT NULL,
        post_name VARCHAR(255) DEFAULT NULL,
        topic VARCHAR(20) NOT NULL DEFAULT 'STANDARD',
        summary TEXT DEFAULT NULL,
        title VARCHAR(120) DEFAULT NULL,
        cta VARCHAR(30) DEFAULT NULL,
        url VARCHAR(500) DEFAULT NULL,
        image_url VARCHAR(500) DEFAULT NULL,
        state VARCHAR(40) DEFAULT NULL,
        search_url VARCHAR(500) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_gbp_posts (restaurant_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->exec("CREATE TABLE IF NOT EXISTS gbp_link_hits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(50) NOT NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'link',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_gbp_hits (restaurant_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // each post's button opens a tracked link (config/link_tracking.php), so its taps and orders can be shown
    $pc = array_column($conn->query('SHOW COLUMNS FROM gbp_posts')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('link_ref', $pc, true)) $conn->exec('ALTER TABLE gbp_posts ADD COLUMN link_ref VARCHAR(40) NULL');
}

function gbpRow(PDO $conn, string $rid): array
{
    gbpEnsureTables($conn);
    $st = $conn->prepare('SELECT * FROM gbp_connections WHERE restaurant_id = ?');
    $st->execute([$rid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}
function gbpSave(PDO $conn, string $rid, array $f): void
{
    gbpEnsureTables($conn);
    $cols = array_keys($f);
    $sql = 'INSERT INTO gbp_connections (restaurant_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')
        ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(function ($c) { return "$c = VALUES($c)"; }, $cols));
    $conn->prepare($sql)->execute(array_merge([$rid], array_values($f)));
}

/* ───────── HTTP ───────── */
function gbpHttp(string $method, string $url, array $headers = [], $body = null, int $timeout = 20): array
{
    $ch = curl_init($url);
    $h = $headers;
    if ($body !== null && !is_string($body)) { $body = json_encode($body, JSON_UNESCAPED_UNICODE); $h[] = 'Content-Type: application/json'; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => $h]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);   // local XAMPP's CA bundle is old
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = $res === false ? curl_error($ch) : '';
    curl_close($ch);
    $json = is_string($res) && $res !== '' ? json_decode($res, true) : null;
    return ['code' => $code, 'json' => is_array($json) ? $json : [], 'raw' => is_string($res) ? $res : '', 'error' => $err];
}

/** A Google error in words the owner can act on. */
function gbpExplain(array $r): string
{
    $msg = $r['json']['error']['message'] ?? ($r['error'] ?: ('HTTP ' . $r['code']));
    $status = $r['json']['error']['status'] ?? '';
    if ($r['code'] === 429 || stripos($msg, 'quota') !== false) return 'Google hasn’t opened the Business Profile API for RestroGrow yet (quota is 0 until Google approves access). Reviews, posts and insights start working once it’s approved.';
    if ($r['code'] === 403 && (stripos($msg, 'has not been used') !== false || stripos($msg, 'disabled') !== false)) return 'An API is switched off in the Google Cloud project: ' . $msg;
    if ($r['code'] === 403) return 'Google refused this (' . $status . '): ' . $msg;
    if ($r['code'] === 401) return 'Google sign-in expired. Connect Google again.';
    return $msg;
}

/* ───────── OAuth ───────── */
function gbpAuthUrl(string $state): string
{
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => gbpClientId(), 'redirect_uri' => gbpRedirectUri(), 'response_type' => 'code',
        'scope' => GBP_SCOPE . ' openid email', 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state,
    ]);
}
function gbpExchange(string $code): array
{
    return gbpHttp('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'code' => $code, 'client_id' => gbpClientId(), 'client_secret' => gbpClientSecret(), 'redirect_uri' => gbpRedirectUri(), 'grant_type' => 'authorization_code',
    ]));
}

/** A working access token for the restaurant (refreshed when it's about to run out), or null. */
function gbpToken(PDO $conn, string $rid): ?string
{
    $row = gbpRow($conn, $rid);
    if (!$row || empty($row['refresh_token'])) return null;
    if (!empty($row['access_token']) && (int)$row['token_expires'] > time() + 60) return gbpOpen($row['access_token']) ?: null;
    $r = gbpHttp('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'client_id' => gbpClientId(), 'client_secret' => gbpClientSecret(), 'refresh_token' => gbpOpen($row['refresh_token']), 'grant_type' => 'refresh_token',
    ]));
    if ($r['code'] !== 200 || empty($r['json']['access_token'])) {
        gbpSave($conn, $rid, ['last_error' => mb_substr('Google sign-in could not be renewed: ' . gbpExplain($r), 0, 400)]);
        return null;
    }
    gbpSave($conn, $rid, ['access_token' => gbpSeal($r['json']['access_token']), 'token_expires' => time() + (int)($r['json']['expires_in'] ?? 3600)]);
    return $r['json']['access_token'];
}

/** Calls a Business Profile API with the restaurant's token. ['ok', 'json', 'error', 'code']. */
function gbpApi(PDO $conn, string $rid, string $method, string $url, $body = null): array
{
    $t = gbpToken($conn, $rid);
    if (!$t) return ['ok' => false, 'json' => [], 'code' => 401, 'error' => 'Connect Google first.'];
    $r = gbpHttp($method, $url, ['Authorization: Bearer ' . $t], $body);
    $ok = $r['code'] >= 200 && $r['code'] < 300;
    return ['ok' => $ok, 'json' => $r['json'], 'code' => $r['code'], 'error' => $ok ? '' : gbpExplain($r)];
}

/** The v4 path of the connected location: accounts/x/locations/y (reviews and posts live there). */
function gbpV4Path(array $row): ?string
{
    if (empty($row['account_name']) || empty($row['location_name'])) return null;
    return $row['account_name'] . '/' . $row['location_name'];
}

/* ───────── reviews ───────── */
function gbpStarNumber($s): int { return is_numeric($s) ? (int)$s : (['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5][$s] ?? 0); }

/** Pulls every review of the connected location into gbp_reviews. Returns ['ok', 'count', 'error', 'average', 'total']. */
function gbpSyncReviews(PDO $conn, string $rid): array
{
    $row = gbpRow($conn, $rid);
    $path = gbpV4Path($row);
    if (!$path) return ['ok' => false, 'error' => 'Pick your Google location first.'];
    $up = $conn->prepare('INSERT INTO gbp_reviews (restaurant_id, review_id, source, reviewer_name, reviewer_photo, rating, comment, created_at, reply, replied_at)
        VALUES (?, ?, \'gbp\', ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE reviewer_name = VALUES(reviewer_name), reviewer_photo = VALUES(reviewer_photo), rating = VALUES(rating),
        comment = VALUES(comment), created_at = VALUES(created_at), reply = VALUES(reply), replied_at = VALUES(replied_at)');
    $token = ''; $n = 0; $avg = null; $total = null;
    for ($page = 0; $page < 10; $page++) {
        $r = gbpApi($conn, $rid, 'GET', 'https://mybusiness.googleapis.com/v4/' . $path . '/reviews?pageSize=50&orderBy=updateTime%20desc' . ($token ? '&pageToken=' . urlencode($token) : ''));
        if (!$r['ok']) { gbpSave($conn, $rid, ['last_error' => mb_substr($r['error'], 0, 400)]); return ['ok' => false, 'error' => $r['error'], 'count' => $n]; }
        $avg = $r['json']['averageRating'] ?? $avg; $total = $r['json']['totalReviewCount'] ?? $total;
        foreach ($r['json']['reviews'] ?? [] as $v) {
            $up->execute([$rid, (string)($v['reviewId'] ?? $v['name']), mb_substr((string)($v['reviewer']['displayName'] ?? 'Google user'), 0, 190), $v['reviewer']['profilePhotoUrl'] ?? null,
                gbpStarNumber($v['starRating'] ?? 0), $v['comment'] ?? null, isset($v['createTime']) ? date('Y-m-d H:i:s', strtotime($v['createTime'])) : null,
                $v['reviewReply']['comment'] ?? null, isset($v['reviewReply']['updateTime']) ? date('Y-m-d H:i:s', strtotime($v['reviewReply']['updateTime'])) : null]);
            $n++;
        }
        $token = $r['json']['nextPageToken'] ?? '';
        if (!$token) break;
    }
    $f = ['reviews_synced_at' => date('Y-m-d H:i:s'), 'last_error' => null];
    if ($avg !== null) { $f['rating'] = round((float)$avg, 2); $f['rating_count'] = (int)$total; }
    gbpSave($conn, $rid, $f);
    return ['ok' => true, 'count' => $n, 'average' => $avg, 'total' => $total];
}

/* ───────── Places API (New): rating without connecting ───────── */
function gbpPlacesSearch(string $text): array
{
    $key = gbpPlacesKey();
    if ($key === '') return ['ok' => false, 'error' => 'No Google API key (GOOGLE_PLACES_API_KEY) on the server.'];
    $r = gbpHttp('POST', 'https://places.googleapis.com/v1/places:searchText', ['X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: places.id,places.displayName,places.formattedAddress,places.rating,places.userRatingCount,places.googleMapsUri'],
        ['textQuery' => $text, 'maxResultCount' => 6]);
    if ($r['code'] !== 200) return ['ok' => false, 'error' => gbpExplain($r)];
    return ['ok' => true, 'places' => array_map(function ($p) {
        return ['id' => $p['id'], 'name' => $p['displayName']['text'] ?? '', 'address' => $p['formattedAddress'] ?? '', 'rating' => $p['rating'] ?? null, 'count' => $p['userRatingCount'] ?? 0, 'maps' => $p['googleMapsUri'] ?? ''];
    }, $r['json']['places'] ?? [])];
}

/** Rating, count and the newest reviews of the saved place (cached 6 hours). */
function gbpPlacesRefresh(PDO $conn, string $rid, bool $force = false): array
{
    $row = gbpRow($conn, $rid);
    if (empty($row['place_id'])) return ['ok' => false, 'error' => 'Find your restaurant on Google first.'];
    if (!$force && !empty($row['places_synced_at']) && strtotime($row['places_synced_at']) > time() - 6 * 3600 && $row['places_json']) return ['ok' => true, 'cached' => true];
    $key = gbpPlacesKey();
    if ($key === '') return ['ok' => false, 'error' => 'No Google API key (GOOGLE_PLACES_API_KEY) on the server.'];
    $r = gbpHttp('GET', 'https://places.googleapis.com/v1/places/' . rawurlencode($row['place_id']), ['X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: id,displayName,rating,userRatingCount,googleMapsUri,reviews']);
    if ($r['code'] !== 200) { gbpSave($conn, $rid, ['last_error' => mb_substr(gbpExplain($r), 0, 400)]); return ['ok' => false, 'error' => gbpExplain($r)]; }
    $p = $r['json'];
    $f = ['place_name' => $p['displayName']['text'] ?? null, 'places_json' => json_encode(['maps' => $p['googleMapsUri'] ?? null], JSON_UNESCAPED_UNICODE), 'places_synced_at' => date('Y-m-d H:i:s')];
    // a connected location's own numbers (all reviews) win over the Places summary
    if (empty($row['reviews_synced_at'])) { $f['rating'] = $p['rating'] ?? null; $f['rating_count'] = $p['userRatingCount'] ?? null; }
    gbpSave($conn, $rid, $f);
    $up = $conn->prepare('INSERT INTO gbp_reviews (restaurant_id, review_id, source, reviewer_name, reviewer_photo, rating, comment, created_at)
        VALUES (?, ?, \'places\', ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment)');
    foreach ($p['reviews'] ?? [] as $v) {
        $up->execute([$rid, 'places:' . md5(($v['name'] ?? '') . ($v['publishTime'] ?? '')), mb_substr((string)($v['authorAttribution']['displayName'] ?? 'Google user'), 0, 190),
            $v['authorAttribution']['photoUri'] ?? null, (int)($v['rating'] ?? 0), $v['originalText']['text'] ?? ($v['text']['text'] ?? null),
            isset($v['publishTime']) ? date('Y-m-d H:i:s', strtotime($v['publishTime'])) : null]);
    }
    return ['ok' => true];
}

/** Where a customer leaves a review: Google's own form for the place, else the Maps link. */
function gbpReviewUrl(array $row, ?string $mapsLink = null): string
{
    if (!empty($row['place_id'])) return 'https://search.google.com/local/writereview?placeid=' . rawurlencode($row['place_id']);
    return (string)$mapsLink;
}

/* ───────── AI reply (Gemini) ───────── */
function gbpAiReply(string $restaurant, int $stars, string $name, string $comment, string $tone = 'warm'): ?string
{
    $key = gbpEnv('GEMINI_API_KEY');
    if ($key === '') return null;
    $model = gbpEnv('GEMINI_MODEL', 'gemini-3.5-flash');
    $first = trim(explode(' ', trim($name))[0] ?? '');
    $prompt = "You are the owner of the restaurant \"$restaurant\" replying publicly to a Google review.\n"
        . "Review: $stars star(s) by $first. Text: \"" . ($comment !== '' ? $comment : '(no text, rating only)') . "\"\n"
        . "Write the reply: " . ($tone === 'short' ? '1 sentence' : '2-3 sentences') . ", $tone and human, thank them by first name, mention one specific thing they said if any. "
        . ($stars <= 3 ? 'Apologise sincerely without excuses, and invite them to contact the restaurant so you can make it right. ' : 'Invite them back. ')
        . "No hashtags, no emojis, no placeholders, no quotes around the reply. Reply in the same language as the review.";
    $r = gbpHttp('POST', 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent', ['x-goog-api-key: ' . $key],
        ['contents' => [['parts' => [['text' => $prompt]]]], 'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 1024, 'thinkingConfig' => ['thinkingBudget' => 0]]], 30);
    $txt = $r['json']['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $txt = trim(trim($txt), "\"“” \n");
    return $txt !== '' ? mb_substr($txt, 0, 1000) : null;
}
