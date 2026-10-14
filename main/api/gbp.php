<?php
/**
 * Google Business (dashboard › Google Business, assets/js/gbp-studio.js). Engine: config/gbp_helpers.php.
 *
 *   GET  ?view=status            connection, rating, stars spread, links and their scans, what to set up
 *   GET  ?view=reviews&filter=   all | unanswered | low | high
 *   GET  ?view=locations         the Google account's locations (after Connect)
 *   GET  ?view=posts             posts made from here (and their state on Google)
 *   GET  ?view=info              the location's name, phone, website, description, hours on Google
 *   GET  ?view=insights&days=    views, calls, directions, website clicks per day
 *   GET  ?view=connect           the Google sign-in address (Connect Google)
 *   POST {action: places_search | set_place | refresh | sync | select_location | disconnect |
 *         reply | delete_reply | ai_reply | create_post | delete_post | info_update | auto_reply}
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}
$rid = (string)$_SESSION['restaurant_id'];
$canEdit = hasPermission(PERMISSION_MANAGE_SETTINGS) || hasPermission(PERMISSION_MANAGE_CUSTOMERS);
$view = $_GET['view'] ?? '';
if ($view === 'connect' && $canEdit) {   // the sign-in round trip is tied to this login
    $_SESSION['gbp_state'] = bin2hex(random_bytes(16));
    $_SESSION['gbp_state_rid'] = $rid;
}
$state = $_SESSION['gbp_state'] ?? '';
session_write_close();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/gbp_helpers.php';

function gOut(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function gFail(string $m, int $code = 400) { gOut(['success' => false, 'message' => $m], $code); }

try {
    $conn = getConnection();
    gbpEnsureTables($conn);
    $q = function (string $sql, array $a = []) use ($conn) { $st = $conn->prepare($sql); $st->execute($a); return $st; };
    $user = $q('SELECT restaurant_name, address, phone, google_maps_link FROM users WHERE restaurant_id = ? LIMIT 1', [$rid])->fetch(PDO::FETCH_ASSOC) ?: [];
    $row = gbpRow($conn, $rid);
    $connected = !empty($row['refresh_token']);
    $base = (preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $_SERVER['HTTP_HOST'] ?? '') ? 'http://' . $_SERVER['HTTP_HOST'] . '/menuwebsite' : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'restrogrow.com'));
    $shortUrl = $base . '/main/website/review.php?r=' . rawurlencode($rid);
    $source = $connected && !empty($row['reviews_synced_at']) ? 'gbp' : 'places';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if ($view === 'connect') {
            if (!$canEdit) gFail('You don’t have permission to connect Google.', 403);
            if (!gbpOAuthReady()) gFail('Google sign-in isn’t set up on the server yet (GOOGLE_GBP_CLIENT_ID / GOOGLE_GBP_CLIENT_SECRET).');
            gOut(['success' => true, 'url' => gbpAuthUrl($state)]);
        }
        if ($view === 'locations') {
            if (!$connected) gFail('Connect Google first.');
            $acc = gbpApi($conn, $rid, 'GET', 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts?pageSize=20');
            if (!$acc['ok']) gFail($acc['error']);
            $out = [];
            foreach ($acc['json']['accounts'] ?? [] as $a) {
                $l = gbpApi($conn, $rid, 'GET', 'https://mybusinessbusinessinformation.googleapis.com/v1/' . $a['name'] . '/locations?pageSize=100&readMask=name,title,storefrontAddress,metadata');
                if (!$l['ok']) gFail($l['error']);
                foreach ($l['json']['locations'] ?? [] as $loc) {
                    $addr = $loc['storefrontAddress'] ?? [];
                    $out[] = ['account' => $a['name'], 'account_name' => $a['accountName'] ?? '', 'location' => $loc['name'], 'title' => $loc['title'] ?? '',
                        'address' => trim(implode(', ', array_filter(array_merge($addr['addressLines'] ?? [], [$addr['locality'] ?? '']))), ', '),
                        'place_id' => $loc['metadata']['placeId'] ?? null, 'maps' => $loc['metadata']['mapsUri'] ?? null];
                }
            }
            gOut(['success' => true, 'locations' => $out]);
        }
        if ($view === 'reviews') {
            $f = $_GET['filter'] ?? 'all';
            $where = 'restaurant_id = ? AND source = ?';
            if ($f === 'unanswered') $where .= " AND (reply IS NULL OR reply = '')";
            elseif ($f === 'low') $where .= ' AND rating <= 3';
            elseif ($f === 'high') $where .= ' AND rating >= 4';
            $list = $q("SELECT review_id, reviewer_name, reviewer_photo, rating, comment, created_at, reply, replied_at FROM gbp_reviews WHERE $where ORDER BY created_at DESC LIMIT 200", [$rid, $source])->fetchAll(PDO::FETCH_ASSOC);
            foreach ($list as &$r) $r['rating'] = (int)$r['rating'];
            unset($r);
            gOut(['success' => true, 'source' => $source, 'can_reply' => $source === 'gbp' && $canEdit, 'reviews' => $list]);
        }
        if ($view === 'posts') {
            gOut(['success' => true, 'posts' => $q('SELECT id, topic, title, summary, cta, url, image_url, state, search_url, created_at FROM gbp_posts WHERE restaurant_id = ? ORDER BY id DESC LIMIT 50', [$rid])->fetchAll(PDO::FETCH_ASSOC)]);
        }
        if ($view === 'info') {
            if (empty($row['location_name'])) gFail('Connect Google and pick your location first.');
            $r = gbpApi($conn, $rid, 'GET', 'https://mybusinessbusinessinformation.googleapis.com/v1/' . $row['location_name'] . '?readMask=title,phoneNumbers,websiteUri,profile,regularHours,storefrontAddress,categories');
            if (!$r['ok']) gFail($r['error']);
            $j = $r['json'];
            gOut(['success' => true, 'info' => ['title' => $j['title'] ?? '', 'phone' => $j['phoneNumbers']['primaryPhone'] ?? '', 'website' => $j['websiteUri'] ?? '',
                'description' => $j['profile']['description'] ?? '', 'category' => $j['categories']['primaryCategory']['displayName'] ?? '', 'hours' => $j['regularHours']['periods'] ?? []],
                'ours' => ['title' => $user['restaurant_name'] ?? '', 'phone' => $user['phone'] ?? '', 'website' => $base . '/main/website/index.php?restaurant_id=' . rawurlencode($rid)]]);
        }
        if ($view === 'insights') {
            if (empty($row['location_name'])) gFail('Connect Google and pick your location first.');
            $days = max(7, min(90, (int)($_GET['days'] ?? 30)));
            $end = new DateTime('yesterday'); $start = (clone $end)->modify('-' . ($days - 1) . ' days');
            $metrics = ['BUSINESS_IMPRESSIONS_DESKTOP_MAPS', 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
                'CALL_CLICKS', 'BUSINESS_DIRECTION_REQUESTS', 'WEBSITE_CLICKS', 'BUSINESS_FOOD_ORDERS', 'BUSINESS_FOOD_MENU_CLICKS'];
            $qs = implode('&', array_map(function ($m) { return 'dailyMetrics=' . $m; }, $metrics)) .
                '&dailyRange.startDate.year=' . $start->format('Y') . '&dailyRange.startDate.month=' . (int)$start->format('m') . '&dailyRange.startDate.day=' . (int)$start->format('d') .
                '&dailyRange.endDate.year=' . $end->format('Y') . '&dailyRange.endDate.month=' . (int)$end->format('m') . '&dailyRange.endDate.day=' . (int)$end->format('d');
            $r = gbpApi($conn, $rid, 'GET', 'https://businessprofileperformance.googleapis.com/v1/' . $row['location_name'] . ':fetchMultiDailyMetricsTimeSeries?' . $qs);
            if (!$r['ok']) gFail($r['error']);
            $series = []; $totals = [];
            foreach ($r['json']['multiDailyMetricTimeSeries'] ?? [] as $grp) foreach ($grp['dailyMetricTimeSeries'] ?? [] as $s) {
                $m = $s['dailyMetric'];
                foreach ($s['timeSeries']['datedValues'] ?? [] as $dv) {
                    $d = sprintf('%04d-%02d-%02d', $dv['date']['year'], $dv['date']['month'], $dv['date']['day']);
                    $v = (int)($dv['value'] ?? 0);
                    $key = strpos($m, 'IMPRESSIONS') !== false ? 'views' : ['CALL_CLICKS' => 'calls', 'BUSINESS_DIRECTION_REQUESTS' => 'directions', 'WEBSITE_CLICKS' => 'website', 'BUSINESS_FOOD_ORDERS' => 'orders', 'BUSINESS_FOOD_MENU_CLICKS' => 'menu'][$m];
                    $series[$d][$key] = ($series[$d][$key] ?? 0) + $v;
                    $totals[$key] = ($totals[$key] ?? 0) + $v;
                }
            }
            ksort($series);
            gOut(['success' => true, 'days' => $days, 'totals' => $totals, 'series' => $series]);
        }

        // status (default)
        $dist = array_fill(1, 5, 0);
        foreach ($q('SELECT rating, COUNT(*) n FROM gbp_reviews WHERE restaurant_id = ? AND source = ? GROUP BY rating', [$rid, $source])->fetchAll(PDO::FETCH_ASSOC) as $d) if ($d['rating'] >= 1) $dist[(int)$d['rating']] = (int)$d['n'];
        $unanswered = (int)$q("SELECT COUNT(*) FROM gbp_reviews WHERE restaurant_id = ? AND source = 'gbp' AND (reply IS NULL OR reply = '')", [$rid])->fetchColumn();
        $hits = [];
        foreach ($q('SELECT source, COUNT(*) n FROM gbp_link_hits WHERE restaurant_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY source', [$rid])->fetchAll(PDO::FETCH_ASSOC) as $h) $hits[$h['source']] = (int)$h['n'];
        gOut(['success' => true, 'can_edit' => $canEdit,
            'setup' => ['oauth' => gbpOAuthReady(), 'places' => gbpEnv('GOOGLE_PLACES_API_KEY') !== '', /* the old Maps key alone doesn't switch this on */ 'ai' => gbpEnv('GEMINI_API_KEY') !== '', 'redirect_uri' => gbpRedirectUri()],
            'connected' => $connected, 'google_email' => $row['google_email'] ?? null, 'location' => !empty($row['location_name']) ? ['name' => $row['location_name'], 'title' => $row['location_title']] : null,
            'place' => !empty($row['place_id']) ? ['id' => $row['place_id'], 'name' => $row['place_name']] : null,
            'rating' => isset($row['rating']) ? (float)$row['rating'] : null, 'rating_count' => isset($row['rating_count']) ? (int)$row['rating_count'] : null,
            'source' => $source, 'distribution' => $dist, 'unanswered' => $unanswered, 'auto_reply' => (int)($row['auto_reply'] ?? 0),
            'review_url' => gbpReviewUrl($row, $user['google_maps_link'] ?? null), 'short_url' => $shortUrl, 'hits' => $hits,
            'synced' => ['reviews' => $row['reviews_synced_at'] ?? null, 'places' => $row['places_synced_at'] ?? null], 'last_error' => $row['last_error'] ?? null,
            'restaurant' => ['name' => $user['restaurant_name'] ?? '', 'address' => $user['address'] ?? '', 'phone' => $user['phone'] ?? '']]);
    }

    // ── POST ──
    if (!$canEdit) gFail('You don’t have permission to change Google Business.', 403);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $in['action'] ?? '';

    if ($action === 'places_search') {
        $text = trim((string)($in['q'] ?? '')) ?: trim(($user['restaurant_name'] ?? '') . ' ' . ($user['address'] ?? ''));
        $r = gbpPlacesSearch(mb_substr($text, 0, 200));
        if (!$r['ok']) gFail($r['error']);
        gOut(['success' => true, 'places' => $r['places']]);
    }
    if ($action === 'set_place') {
        $pid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($in['place_id'] ?? ''));
        if ($pid === '') gFail('Pick your restaurant from the list.');
        gbpSave($conn, $rid, ['place_id' => $pid, 'places_synced_at' => null]);
        $conn->prepare("DELETE FROM gbp_reviews WHERE restaurant_id = ? AND source = 'places'")->execute([$rid]);
        $r = gbpPlacesRefresh($conn, $rid, true);
        gOut(['success' => $r['ok'], 'message' => $r['ok'] ? 'Saved' : $r['error']]);
    }
    if ($action === 'refresh') {
        $out = ['places' => null, 'reviews' => null];
        if (!empty($row['place_id'])) $out['places'] = gbpPlacesRefresh($conn, $rid, true);
        if ($connected && !empty($row['location_name'])) $out['reviews'] = gbpSyncReviews($conn, $rid);
        $err = ($out['reviews'] && !$out['reviews']['ok']) ? $out['reviews']['error'] : (($out['places'] && !$out['places']['ok']) ? $out['places']['error'] : null);
        gOut(['success' => $err === null, 'message' => $err ?: 'Up to date', 'result' => $out]);
    }
    if ($action === 'select_location') {
        if (!$connected) gFail('Connect Google first.');
        $acc = (string)($in['account'] ?? ''); $loc = (string)($in['location'] ?? '');
        if (!preg_match('#^accounts/\d+$#', $acc) || !preg_match('#^locations/\d+$#', $loc)) gFail('Pick a location from the list.');
        $f = ['account_name' => $acc, 'location_name' => $loc, 'location_title' => mb_substr((string)($in['title'] ?? ''), 0, 190), 'last_error' => null];
        if (!empty($in['place_id'])) $f['place_id'] = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$in['place_id']);
        gbpSave($conn, $rid, $f);
        $s = gbpSyncReviews($conn, $rid);
        gOut(['success' => true, 'sync' => $s]);
    }
    if ($action === 'disconnect') {
        if ($connected) gbpHttp('POST', 'https://oauth2.googleapis.com/revoke?token=' . urlencode(gbpOpen($row['refresh_token'])), ['Content-Type: application/x-www-form-urlencoded']);
        gbpSave($conn, $rid, ['access_token' => null, 'refresh_token' => null, 'token_expires' => null, 'account_name' => null, 'location_name' => null, 'location_title' => null, 'google_email' => null, 'reviews_synced_at' => null, 'last_error' => null]);
        $conn->prepare("DELETE FROM gbp_reviews WHERE restaurant_id = ? AND source = 'gbp'")->execute([$rid]);
        gOut(['success' => true]);
    }
    if ($action === 'auto_reply') {
        gbpSave($conn, $rid, ['auto_reply' => !empty($in['on']) ? 1 : 0]);
        gOut(['success' => true]);
    }
    if ($action === 'reply' || $action === 'delete_reply' || $action === 'ai_reply') {
        $rev = $q("SELECT * FROM gbp_reviews WHERE restaurant_id = ? AND review_id = ?", [$rid, (string)($in['review_id'] ?? '')])->fetch(PDO::FETCH_ASSOC);
        if (!$rev) gFail('That review was not found. Refresh and try again.');
        if ($action === 'ai_reply') {
            $txt = gbpAiReply($user['restaurant_name'] ?? 'our restaurant', (int)$rev['rating'], (string)$rev['reviewer_name'], (string)$rev['comment'], in_array($in['tone'] ?? '', ['warm', 'short', 'formal', 'playful'], true) ? $in['tone'] : 'warm');
            if ($txt === null) gFail('The AI couldn’t write a reply right now. Write one yourself or try again.');
            gOut(['success' => true, 'text' => $txt]);
        }
        $path = gbpV4Path($row);
        if ($rev['source'] !== 'gbp' || !$path) gFail('Connect Google to reply from here.');
        $url = 'https://mybusiness.googleapis.com/v4/' . $path . '/reviews/' . rawurlencode($rev['review_id']) . '/reply';
        if ($action === 'delete_reply') {
            $r = gbpApi($conn, $rid, 'DELETE', $url);
            if (!$r['ok']) gFail($r['error']);
            $q('UPDATE gbp_reviews SET reply = NULL, replied_at = NULL WHERE id = ?', [$rev['id']]);
            gOut(['success' => true]);
        }
        $text = trim(mb_substr(strip_tags((string)($in['comment'] ?? '')), 0, 4000));
        if ($text === '') gFail('Write a reply first.');
        $r = gbpApi($conn, $rid, 'PUT', $url, ['comment' => $text]);
        if (!$r['ok']) gFail($r['error']);
        $q('UPDATE gbp_reviews SET reply = ?, replied_at = NOW() WHERE id = ?', [$text, $rev['id']]);
        gOut(['success' => true]);
    }
    if ($action === 'create_post') {
        $path = gbpV4Path($row);
        if (!$path) gFail('Connect Google and pick your location first.');
        $topic = in_array($in['topic'] ?? '', ['STANDARD', 'OFFER', 'EVENT'], true) ? $in['topic'] : 'STANDARD';
        $summary = trim(mb_substr(strip_tags((string)($in['summary'] ?? '')), 0, 1500));
        if ($summary === '') gFail('Write what the post says.');
        $post = ['languageCode' => 'en', 'summary' => $summary, 'topicType' => $topic];
        $cta = in_array($in['cta'] ?? '', ['ORDER', 'BOOK', 'LEARN_MORE', 'CALL', 'SHOP', 'SIGN_UP'], true) ? $in['cta'] : '';
        $url = trim((string)($in['url'] ?? ''));
        if ($cta === 'CALL') $post['callToAction'] = ['actionType' => 'CALL'];
        elseif ($cta && filter_var($url, FILTER_VALIDATE_URL)) $post['callToAction'] = ['actionType' => $cta, 'url' => $url];
        $img = trim((string)($in['image_url'] ?? ''));
        if ($img !== '' && filter_var($img, FILTER_VALIDATE_URL)) $post['media'] = [['mediaFormat' => 'PHOTO', 'sourceUrl' => $img]];
        $title = trim(mb_substr(strip_tags((string)($in['title'] ?? '')), 0, 58));
        if ($topic !== 'STANDARD') {
            if ($title === '') gFail('Give the ' . strtolower($topic) . ' a title.');
            $sd = strtotime((string)($in['start'] ?? 'today')); $ed = strtotime((string)($in['end'] ?? '+7 days'));
            if (!$sd || !$ed || $ed < $sd) gFail('Check the start and end dates.');
            $dt = function ($t) { return ['year' => (int)date('Y', $t), 'month' => (int)date('n', $t), 'day' => (int)date('j', $t)]; };
            $post['event'] = ['title' => $title, 'schedule' => ['startDate' => $dt($sd), 'endDate' => $dt($ed)]];
            if ($topic === 'OFFER') {
                $post['offer'] = array_filter(['couponCode' => mb_substr(trim((string)($in['coupon'] ?? '')), 0, 58), 'redeemOnlineUrl' => filter_var($url, FILTER_VALIDATE_URL) ? $url : null, 'termsConditions' => mb_substr(trim((string)($in['terms'] ?? '')), 0, 500)]);
                unset($post['callToAction']);   // offers carry their own button on Google
            }
        }
        $r = gbpApi($conn, $rid, 'POST', 'https://mybusiness.googleapis.com/v4/' . $path . '/localPosts', $post);
        if (!$r['ok']) gFail($r['error']);
        $q('INSERT INTO gbp_posts (restaurant_id, post_name, topic, summary, title, cta, url, image_url, state, search_url) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$rid, $r['json']['name'] ?? null, $topic, $summary, $title ?: null, $cta ?: null, $url ?: null, $img ?: null, $r['json']['state'] ?? 'LIVE', $r['json']['searchUrl'] ?? null]);
        gOut(['success' => true, 'state' => $r['json']['state'] ?? null, 'search_url' => $r['json']['searchUrl'] ?? null]);
    }
    if ($action === 'delete_post') {
        $p = $q('SELECT * FROM gbp_posts WHERE id = ? AND restaurant_id = ?', [(int)($in['id'] ?? 0), $rid])->fetch(PDO::FETCH_ASSOC);
        if (!$p) gFail('That post was not found.');
        if (!empty($p['post_name']) && $connected) {
            $r = gbpApi($conn, $rid, 'DELETE', 'https://mybusiness.googleapis.com/v4/' . $p['post_name']);
            if (!$r['ok'] && $r['code'] !== 404) gFail($r['error']);
        }
        $q('DELETE FROM gbp_posts WHERE id = ?', [$p['id']]);
        gOut(['success' => true]);
    }
    if ($action === 'info_update') {
        if (empty($row['location_name'])) gFail('Connect Google and pick your location first.');
        $body = []; $mask = [];
        if (isset($in['phone'])) { $body['phoneNumbers'] = ['primaryPhone' => mb_substr(trim((string)$in['phone']), 0, 30)]; $mask[] = 'phoneNumbers'; }
        if (isset($in['website'])) { $w = trim((string)$in['website']); if ($w !== '' && !filter_var($w, FILTER_VALIDATE_URL)) gFail('The website must be a full address (https://…).'); $body['websiteUri'] = $w; $mask[] = 'websiteUri'; }
        if (isset($in['description'])) { $body['profile'] = ['description' => mb_substr(trim(strip_tags((string)$in['description'])), 0, 750)]; $mask[] = 'profile.description'; }
        if (isset($in['hours']) && is_array($in['hours'])) {
            $days = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];
            $periods = [];
            foreach ($in['hours'] as $h) {
                if (!in_array($h['day'] ?? '', $days, true) || !preg_match('/^(\d{2}):(\d{2})$/', (string)($h['open'] ?? ''), $o) || !preg_match('/^(\d{2}):(\d{2})$/', (string)($h['close'] ?? ''), $c)) continue;
                $close = ['hours' => (int)$c[1], 'minutes' => (int)$c[2]];
                $closeDay = ((int)$c[1] * 60 + (int)$c[2]) <= ((int)$o[1] * 60 + (int)$o[2]) ? $days[(array_search($h['day'], $days) + 1) % 7] : $h['day'];   // past midnight
                $periods[] = ['openDay' => $h['day'], 'openTime' => ['hours' => (int)$o[1], 'minutes' => (int)$o[2]], 'closeDay' => $closeDay, 'closeTime' => $close];
            }
            $body['regularHours'] = ['periods' => $periods]; $mask[] = 'regularHours';
        }
        if (!$mask) gFail('Nothing to change.');
        $r = gbpApi($conn, $rid, 'PATCH', 'https://mybusinessbusinessinformation.googleapis.com/v1/' . $row['location_name'] . '?updateMask=' . implode(',', $mask), $body);
        if (!$r['ok']) gFail($r['error']);
        gOut(['success' => true]);
    }
    gFail('Unknown action');
} catch (Throwable $e) {
    error_log('gbp api: ' . $e->getMessage());
    gFail('Something went wrong. Please try again.', 500);
}
