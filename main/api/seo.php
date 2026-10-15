<?php
/**
 * SEO (dashboard › SEO, assets/js/seo-studio.js). Engine: config/seo_helpers.php.
 *
 *   GET                       settings, defaults, the restaurant's details for the previews, health checks + score
 *   POST {action: save}       pages {home|menu|about|contact: {title, description}}, keywords, cuisine, price_range,
 *                             gsc_verification (code or the whole meta tag), ga_id, meta_pixel_id, noindex
 *   POST {action: ai_write}   titles + descriptions written for all pages (Gemini; written from a template without it)
 *   POST {action: remove_image}
 *   POST multipart share_image   the picture shown when the site is shared on WhatsApp / Facebook
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
$canEdit = hasPermission(PERMISSION_MANAGE_SETTINGS);
session_write_close();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/seo_helpers.php';
require_once __DIR__ . '/../config/gbp_helpers.php';

function sOut(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function sFail(string $m, int $code = 400) { sOut(['success' => false, 'message' => $m], $code); }

try {
    $conn = getConnection();
    seoEnsure($conn);
    $st = $conn->prepare('SELECT * FROM users WHERE restaurant_id = ? ORDER BY id LIMIT 1');
    $st->execute([$rid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) sFail('Restaurant not found.', 404);
    $r = ['name' => (string)$u['restaurant_name'], 'address' => (string)$u['address'], 'phone' => (string)$u['phone'], 'description' => trim(strip_tags((string)$u['description']))];
    $s = seoGet($conn, $rid);

    $save = function (array $f) use ($conn, $rid) {
        $cols = array_keys($f);
        $sql = 'INSERT INTO seo_settings (restaurant_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ') ON DUPLICATE KEY UPDATE '
            . implode(', ', array_map(function ($c) { return "$c = VALUES($c)"; }, $cols));
        $conn->prepare($sql)->execute(array_merge([$rid], array_values($f)));
    };

    // ── share image upload ──
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['share_image'])) {
        if (!$canEdit) sFail('You don’t have permission to change SEO.', 403);
        $f = $_FILES['share_image'];
        if ($f['error'] !== UPLOAD_ERR_OK) sFail('Upload failed. Try a smaller picture (up to 3 MB).');
        if ($f['size'] > 3 * 1024 * 1024) sFail('That picture is over 3 MB. Please use a smaller one.');
        $info = @getimagesize($f['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
        if (!$info || !$ext) sFail('Use a JPG, PNG or WebP picture.');
        if ($info[0] < 400 || $info[1] < 200) sFail('That picture is too small. Use one at least 1200 × 630 pixels for a sharp preview.');
        $dir = __DIR__ . '/../uploads/seo';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $name = preg_replace('/[^A-Za-z0-9_]/', '', $rid) . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) sFail('Could not save the picture. Try again.');
        if (!empty($s['share_image']) && strpos($s['share_image'], 'uploads/seo/') === 0) @unlink(__DIR__ . '/../' . $s['share_image']);
        $save(['share_image' => "uploads/seo/$name"]);
        sOut(['success' => true, 'share_image' => "../uploads/seo/$name", 'small' => $info[0] < 1200]);
    }

    // ── the restaurant's site address ──
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($r['name'])), '-');
    $host = $_SERVER['HTTP_HOST'] ?? 'restrogrow.com';
    $scheme = preg_match('/^(localhost|127\.0\.0\.1)/', $host) ? 'http' : 'https';
    $root = rtrim(str_replace('\\', '/', dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])))), '/');
    $ownDomain = !empty($u['custom_domain']) && !empty($u['embed_enabled']) ? preg_replace('#^https?://|/+$#', '', trim($u['custom_domain'])) : '';
    $site = $ownDomain ? "https://$ownDomain" : "$scheme://$host$root/$slug";

    // dishes: photo / description coverage, the popular ones for the AI
    $dq = $conn->prepare("SELECT item_name_en, COALESCE(item_image, '') <> '' AS has_photo, CHAR_LENGTH(TRIM(COALESCE(item_description_en, ''))) >= 20 AS has_desc
        FROM menu_items WHERE restaurant_id = ? AND is_available = 1 AND COALESCE(is_hidden, 0) = 0 ORDER BY sort_order, id");
    $dq->execute([$rid]);
    $dishes = $dq->fetchAll(PDO::FETCH_ASSOC);
    $nd = count($dishes);
    $photos = $nd ? (int)round(100 * count(array_filter($dishes, function ($d) { return $d['has_photo']; })) / $nd) : 0;
    $descs = $nd ? (int)round(100 * count(array_filter($dishes, function ($d) { return $d['has_desc']; })) / $nd) : 0;
    $top = [];
    try {
        $tq = $conn->prepare("SELECT oi.item_name, SUM(oi.quantity) q FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.restaurant_id = ? GROUP BY oi.item_name ORDER BY q DESC LIMIT 6");
        $tq->execute([$rid]);
        $top = $tq->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $top = []; }
    if (!$top) $top = array_slice(array_column($dishes, 'item_name_en'), 0, 6);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $d = seoDefaults($r, $s);
        $eff = function ($p, $k) use ($s, $d) { $v = trim((string)($s['pages'][$p][$k] ?? '')); return $v !== '' ? $v : $d[$p][$k]; };
        $titlesOk = true; $descOk = true;
        foreach (array_keys(SEO_PAGES) as $p) {
            $tl = mb_strlen($eff($p, 'title')); $dl = mb_strlen($eff($p, 'description'));
            if ($tl < 25 || $tl > 65) $titlesOk = false;
            if ($dl < 70 || $dl > 165) $descOk = false;
        }
        $hours = json_decode((string)$u['opening_hours'], true);
        $hasHours = is_array($hours) && array_filter($hours, function ($h) { return is_array($h) && !empty($h['open']); });
        $logoUrl = !empty($u['logo_data']) ? '../website/image.php?type=logo&id=' . urlencode($u['id']) : (!empty($u['restaurant_logo']) ? (preg_match('#^https?://#', $u['restaurant_logo']) ? $u['restaurant_logo'] : '../' . ltrim($u['restaurant_logo'], '/')) : null);
        $g = null;
        try { $gq = $conn->prepare('SELECT place_id, rating, rating_count FROM gbp_connections WHERE restaurant_id = ?'); $gq->execute([$rid]); $g = $gq->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {}

        $checks = [
            ['id' => 'titles', 'w' => 10, 'ok' => $titlesOk, 'label' => 'Page titles are a good length', 'tip' => 'Keep each title between 30 and 60 characters so Google shows all of it.', 'fix' => ['here' => 'pages']],
            ['id' => 'descs', 'w' => 10, 'ok' => $descOk, 'label' => 'Page descriptions are a good length', 'tip' => 'Aim for 120–155 characters: what you serve, where, and a reason to click.', 'fix' => ['here' => 'pages']],
            ['id' => 'about', 'w' => 10, 'ok' => mb_strlen($r['description']) >= 80, 'label' => 'Restaurant description written', 'tip' => 'Two or three lines about your food and place. Google reads it and it fills your About page.', 'fix' => ['page' => 'settingsPage', 'focus' => 'restaurantDescription']],
            ['id' => 'address', 'w' => 10, 'ok' => mb_strlen($r['address']) >= 10, 'label' => 'Full address added', 'tip' => 'Needed to show up in “near me” searches.', 'fix' => ['page' => 'settingsPage', 'focus' => 'restaurantAddress']],
            ['id' => 'phone', 'w' => 5, 'ok' => $r['phone'] !== '', 'label' => 'Phone number added', 'tip' => 'Lets Google show a Call button.', 'fix' => ['page' => 'settingsPage', 'focus' => 'restaurantPhone']],
            ['id' => 'hours', 'w' => 8, 'ok' => (bool)$hasHours, 'label' => 'Opening hours set', 'tip' => 'Google shows “Open now” from these.', 'fix' => ['page' => 'settingsPage', 'focus' => 'day_monday_open']],
            ['id' => 'map', 'w' => 7, 'ok' => ($u['restaurant_lat'] && $u['restaurant_lng']) || !empty($u['google_maps_link']), 'label' => 'Map location added', 'tip' => 'Pick your address from the suggestions or paste your Google Maps link.', 'fix' => ['page' => 'settingsPage', 'focus' => 'restaurantGoogleMapsLink']],
            ['id' => 'logo', 'w' => 6, 'ok' => (bool)$logoUrl, 'label' => 'Logo uploaded', 'tip' => 'Shown in Google results and when your link is shared.', 'fix' => ['page' => 'profilePage']],
            ['id' => 'photos', 'w' => 10, 'ok' => $nd > 0 && $photos >= 60, 'label' => "Dishes with photos ($photos%)", 'tip' => 'Photos of at least 6 in 10 dishes. They show up in Google Images.', 'fix' => ['page' => 'menuItemsPage']],
            ['id' => 'dishdesc', 'w' => 6, 'ok' => $nd > 0 && $descs >= 60, 'label' => "Dishes with descriptions ($descs%)", 'tip' => 'A line about each dish gives Google more words to match searches.', 'fix' => ['page' => 'menuItemsPage']],
            ['id' => 'cuisine', 'w' => 5, 'ok' => !empty($s['cuisine']), 'label' => 'Cuisine chosen', 'tip' => 'e.g. North Indian, Chinese. Used in your titles and in Google’s restaurant info.', 'fix' => ['here' => 'business']],
            ['id' => 'share', 'w' => 3, 'ok' => !empty($s['share_image']), 'label' => 'Share picture added', 'tip' => 'A food photo looks far better than a logo when your link is shared on WhatsApp.', 'fix' => ['here' => 'share']],
            ['id' => 'gsc', 'w' => 5, 'ok' => !empty($s['gsc_verification']) || $ownDomain === '', 'label' => $ownDomain ? 'Google Search Console connected' : 'Google Search Console (needs your own domain)', 'tip' => $ownDomain ? 'Paste your verification code so you can see your searches and send your sitemap.' : 'On restrogrow.com we send your sitemap to Google for you.', 'fix' => ['here' => 'tracking']],
            ['id' => 'domain', 'w' => 5, 'ok' => $ownDomain !== '', 'label' => 'Own domain name', 'tip' => 'yourrestaurant.com builds trust and ranks under your own name.', 'fix' => ['page' => 'embedPage']],
        ];
        $score = 0;
        foreach ($checks as $c) if ($c['ok']) $score += $c['w'];
        sOut(['success' => true, 'can_edit' => $canEdit, 'settings' => [
                'pages' => (object)$s['pages'], 'keywords' => $s['keywords'] ?? '', 'cuisine' => $s['cuisine'] ?? '', 'price_range' => $s['price_range'] ?? '',
                'share_image' => !empty($s['share_image']) ? '../' . $s['share_image'] : null, 'gsc_verification' => $s['gsc_verification'] ?? '', 'ga_id' => $s['ga_id'] ?? '',
                'meta_pixel_id' => $s['meta_pixel_id'] ?? '', 'noindex' => !empty($s['noindex']), 'updated_at' => $s['updated_at'] ?? null],
            'defaults' => $d, 'pages' => SEO_PAGES,
            'restaurant' => ['name' => $r['name'], 'address' => $r['address'], 'phone' => $r['phone'], 'logo' => $logoUrl, 'site' => $site, 'host' => preg_replace('#^https?://#', '', $site), 'own_domain' => $ownDomain,
                'rating' => $g && $g['rating'] ? (float)$g['rating'] : null, 'rating_count' => $g ? (int)$g['rating_count'] : 0, 'dishes' => $nd, 'photos' => $photos],
            'sitemap' => $site . '/sitemap.xml', 'checks' => $checks, 'score' => $score, 'ai' => gbpEnv('GEMINI_API_KEY') !== '',
            'search_console' => ['available' => false]]);
    }

    // ── POST ──
    if (!$canEdit) sFail('You don’t have permission to change SEO.', 403);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $in['action'] ?? '';
    $clean = function ($v, $n) { return mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)$v))), 0, $n); };

    if ($action === 'save') {
        $pages = [];
        foreach (array_keys(SEO_PAGES) as $p) {
            $t = $clean($in['pages'][$p]['title'] ?? '', 90); $ds = $clean($in['pages'][$p]['description'] ?? '', 300);
            if ($t !== '' || $ds !== '') $pages[$p] = ['title' => $t, 'description' => $ds];
        }
        $gsc = trim((string)($in['gsc_verification'] ?? ''));
        if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $gsc, $m)) $gsc = $m[1];   // pasted the whole <meta> tag
        if ($gsc !== '' && !preg_match('/^[A-Za-z0-9_\-]{10,100}$/', $gsc)) sFail('That Search Console code doesn’t look right. Copy the code from the HTML tag option.');
        $ga = strtoupper(trim((string)($in['ga_id'] ?? '')));
        if ($ga !== '' && !preg_match('/^G-[A-Z0-9]{4,15}$/', $ga)) sFail('Google Analytics ID should look like G-XXXXXXXXXX.');
        $px = preg_replace('/\s+/', '', (string)($in['meta_pixel_id'] ?? ''));
        if ($px !== '' && !preg_match('/^\d{8,20}$/', $px)) sFail('Meta Pixel ID is a number, 15–16 digits long.');
        $price = in_array($in['price_range'] ?? '', ['', '₹', '₹₹', '₹₹₹', '₹₹₹₹'], true) ? $in['price_range'] : '';
        $save(['pages_json' => json_encode($pages, JSON_UNESCAPED_UNICODE), 'keywords' => $clean($in['keywords'] ?? '', 300), 'cuisine' => $clean($in['cuisine'] ?? '', 120),
            'price_range' => $price ?: null, 'gsc_verification' => $gsc ?: null, 'ga_id' => $ga ?: null, 'meta_pixel_id' => $px ?: null, 'noindex' => !empty($in['noindex']) ? 1 : 0]);
        sOut(['success' => true]);
    }

    if ($action === 'remove_image') {
        if (!empty($s['share_image']) && strpos($s['share_image'], 'uploads/seo/') === 0) @unlink(__DIR__ . '/../' . $s['share_image']);
        $save(['share_image' => null]);
        sOut(['success' => true]);
    }

    if ($action === 'ai_write') {
        $cuisine = $clean($in['cuisine'] ?? ($s['cuisine'] ?? ''), 120);
        $keywords = $clean($in['keywords'] ?? ($s['keywords'] ?? ''), 300);
        $only = isset(SEO_PAGES[$in['page'] ?? '']) ? $in['page'] : null;
        $city = seoCity($r['address']); $area = seoArea($r['address']);
        $out = null; $source = 'template';
        $key = gbpEnv('GEMINI_API_KEY');
        if ($key !== '') {
            $prompt = "Write Google search titles and meta descriptions for a restaurant website.\n"
                . "Restaurant: {$r['name']}\nArea: " . trim("$area $city") . "\nAddress: {$r['address']}\nCuisine: " . ($cuisine ?: 'not given') . "\n"
                . "About: " . ($r['description'] ?: 'not given') . "\nPopular dishes: " . implode(', ', $top) . "\nKeywords to work in naturally: " . ($keywords ?: 'none') . "\n"
                . "Pages: home, menu, about, contact. For each: title 40-60 characters with the restaurant name and the area, description 130-155 characters, "
                . "specific (cuisine, dishes, area), with a call to action (order online, book a table, see the menu, call or visit). Plain English, no emojis, no ALL CAPS, no quotes, no made-up facts (no awards, no years). "
                . "Reply with JSON only: {\"home\":{\"title\":\"\",\"description\":\"\"},\"menu\":{...},\"about\":{...},\"contact\":{...}}";
            $res = gbpHttp('POST', 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(gbpEnv('GEMINI_MODEL', 'gemini-3.5-flash')) . ':generateContent', ['x-goog-api-key: ' . $key],
                ['contents' => [['parts' => [['text' => $prompt]]]], 'generationConfig' => ['temperature' => 0.6, 'maxOutputTokens' => 4096, 'responseMimeType' => 'application/json', 'thinkingConfig' => ['thinkingBudget' => 0]]], 30);
            $txt = $res['json']['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $j = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $txt)), true);
            if (is_array($j) && isset($j['home']['title'])) {
                $out = [];
                foreach (array_keys(SEO_PAGES) as $p) $out[$p] = ['title' => $clean($j[$p]['title'] ?? '', 70), 'description' => $clean($j[$p]['description'] ?? '', 170)];
                $source = 'ai';
            }
        }
        if (!$out) {   // no AI: a careful template from the details, popular dishes and keywords
            $wcut = function ($t, $n) { return mb_strlen($t) <= $n ? $t : rtrim(preg_replace('/\s+\S*$/u', '', mb_substr($t, 0, $n)), ' ,.;:') . '.'; };
            $out = seoDefaults($r, ['cuisine' => $cuisine]);
            $dishList = implode(', ', array_slice($top, 0, 3));
            $where = trim($area && $city && strcasecmp($area, $city) ? "$area, $city" : ($city ?: $area));
            $kw = trim(explode(',', $keywords)[0] ?? '');
            if ($dishList) $out['menu']['description'] = $wcut("See the menu of {$r['name']}" . ($where ? " in $where" : '') . " with prices: $dishList and more. Order online for delivery or takeaway.", 155);
            if ($kw !== '' && stripos($out['home']['title'], $kw) === false && mb_strlen($r['name'] . " | $kw" . ($city ? " in $city" : '')) <= 60) $out['home']['title'] = $r['name'] . " | $kw" . ($city ? " in $city" : '');
            if ($dishList && $r['description'] === '') $out['home']['description'] = $wcut("{$r['name']} serves " . ($cuisine ? "$cuisine food" : 'great food') . ($where ? " in $where" : '') . ". Try our $dishList. Order online or book a table today.", 155);
        }
        if ($only) $out = [$only => $out[$only]];
        sOut(['success' => true, 'pages' => $out, 'source' => $source]);
    }

    sFail('Unknown action.');
} catch (Throwable $e) {
    error_log('seo api: ' . $e->getMessage());
    sFail('Something went wrong. Please try again.', 500);
}
