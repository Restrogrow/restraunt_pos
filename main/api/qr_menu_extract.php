<?php
/**
 * "Make your menu" on restrogrow.com/qr-menu-ordering/: a visitor uploads photos (or a PDF) of their menu and
 * Gemini reads the dishes, so the page can show their own menu working on a phone in seconds.
 *
 *   POST multipart files[] (up to 4: JPG / PNG / WebP / HEIC up to 8 MB, or a PDF up to 15 MB), restaurant
 *   → {success, categories: [{name, items: [{name, price, description, type: veg|nonveg|egg, variants: [{name, price}]}]}]}
 *     (the shape qr-menu-ordering/index.html expects); {success: false, message} otherwise, and the page then
 *     offers WhatsApp instead.
 *
 * Public, so it is rationed: 4 reads per IP an hour and 10 a day, and QR_MENU_AI_DAILY (default 300) a day for the
 * whole site, before Gemini is called. Nothing is kept: the photos are only sent to Gemini (config/ai_gemini.php).
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/ai_gemini.php';
require_once __DIR__ . '/../config/rate_limit.php';

function qmOut(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') qmOut(['success' => false, 'message' => 'Send menu photos.'], 405);
if (!aiGeminiAvailable()) { error_log('qr_menu_extract: GEMINI_API_KEY is not set'); qmOut(['success' => false, 'message' => 'Menu reading is not available right now.'], 503); }

// ── the files ──
$f = $_FILES['files'] ?? null;
if (!$f || empty($f['name'])) qmOut(['success' => false, 'message' => 'Please add a photo of your menu.'], 400);
$names = (array)$f['name']; $tmps = (array)$f['tmp_name']; $errs = (array)$f['error'];
$okMime = ['image/jpeg' => 8, 'image/png' => 8, 'image/webp' => 8, 'image/heic' => 8, 'image/heif' => 8, 'application/pdf' => 15];
$parts = [];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
foreach (array_slice(array_keys($names), 0, 4) as $i) {
    if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($tmps[$i])) continue;
    $mime = (string)finfo_file($finfo, $tmps[$i]);
    if (!isset($okMime[$mime])) qmOut(['success' => false, 'message' => 'Please use photos (JPG, PNG) or a PDF of your menu.'], 400);
    if (filesize($tmps[$i]) > $okMime[$mime] * 1024 * 1024) qmOut(['success' => false, 'message' => 'A file is too big (photos up to 8 MB, PDF up to 15 MB).'], 400);
    $parts[] = ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode((string)file_get_contents($tmps[$i]))]];
}
finfo_close($finfo);
if (!$parts) qmOut(['success' => false, 'message' => 'The photo could not be uploaded. Please try again.'], 400);
$restaurant = mb_substr(trim(strip_tags((string)($_POST['restaurant'] ?? ''))), 0, 80);

// ── ration it (after the cheap checks, before Gemini) ──
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
foreach ([['qrmenu_ip_h_' . $ip, 4, 3600], ['qrmenu_ip_d_' . $ip, 10, 86400], ['qrmenu_all_' . date('Y-m-d'), max(10, (int)env('QR_MENU_AI_DAILY', 300)), 86400]] as [$key, $max, $win]) {
    if (!checkRateLimit($key, $max, $win)['allowed']) qmOut(['success' => false, 'message' => 'Too many menus read for now. Send it on WhatsApp and we\'ll make it for you.'], 429);
}

// ── Gemini reads the menu ──
@set_time_limit(150);
$schema = ['type' => 'OBJECT', 'required' => ['categories'], 'properties' => ['categories' => ['type' => 'ARRAY', 'items' => [
    'type' => 'OBJECT', 'required' => ['name', 'items'], 'properties' => [
        'name' => ['type' => 'STRING'],
        'items' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'required' => ['name', 'price', 'type'], 'properties' => [
            'name' => ['type' => 'STRING'], 'price' => ['type' => 'NUMBER'], 'description' => ['type' => 'STRING'],
            'type' => ['type' => 'STRING', 'enum' => ['veg', 'nonveg', 'egg']],
            'variants' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'required' => ['name', 'price'], 'properties' => ['name' => ['type' => 'STRING'], 'price' => ['type' => 'NUMBER']]]],
        ]]],
    ]]]]];
$prompt = "You read restaurant menus. Look at the attached menu photo(s) or PDF" . ($restaurant !== '' ? " of the restaurant \"$restaurant\"" : '') . " and list every dish exactly as printed.\n"
    . "Rules:\n"
    . "- Categories: the menu's own section headings (Starters, Main Course, Breads, Beverages…), in the menu's order. Make a sensible one if there is none.\n"
    . "- Name: only the dish name as printed, never a column label like Price, Rate, Rs, MRP, Half, Full.\n"
    . "- Price: a number in rupees, no symbols. When a dish has several prices (Half / Full, Small / Large, Veg / Chicken), put each in variants with its name, and price = the lowest one. Unreadable price: 0.\n"
    . "- Type: veg, nonveg or egg. Use the menu's green / red marks when present, otherwise judge from the dish (chicken, mutton, fish, prawn = nonveg; egg dishes = egg; drinks, desserts and the rest = veg).\n"
    . "- Description: the menu's own words when printed, else a short appetising line of 5 to 12 words. Never invent ingredients that change the dish.\n"
    . "- Include every real item (drinks, add-ons, combos too). Never invent items that are not on the menu. Skip addresses, phone numbers, taxes and notes.\n"
    . "- If the files are not a menu, return no categories.";
$r = aiGeminiCall([
    'contents' => [['role' => 'user', 'parts' => array_merge([['text' => $prompt]], $parts)]],
    'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 32768, 'responseMimeType' => 'application/json', 'responseSchema' => $schema,
        'thinkingConfig' => ['thinkingBudget' => 0]],
], 140);
$j = $r ? json_decode(preg_replace('/^```(?:json)?|```$/m', '', $r['text']), true) : null;
if (!is_array($j)) qmOut(['success' => false, 'message' => 'We couldn\'t read this menu. Try a clearer photo, or send it on WhatsApp.'], 502);

// ── clean what came back (it is shown on the page) ──
$cats = [];
$clean = fn($s, $n) => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)$s))), 0, $n);
foreach (array_slice((array)($j['categories'] ?? []), 0, 40) as $c) {
    $items = [];
    foreach (array_slice((array)($c['items'] ?? []), 0, 150) as $it) {
        $name = $clean($it['name'] ?? '', 80);
        if ($name === '') continue;
        $type = in_array($it['type'] ?? '', ['veg', 'nonveg', 'egg'], true) ? $it['type'] : 'veg';
        $variants = [];
        foreach (array_slice((array)($it['variants'] ?? []), 0, 6) as $v) {
            $vn = $clean($v['name'] ?? '', 30);
            if ($vn !== '') $variants[] = ['name' => $vn, 'price' => max(0, min(100000, round((float)($v['price'] ?? 0))))];
        }
        $items[] = ['name' => $name, 'price' => max(0, min(100000, round((float)($it['price'] ?? 0)))), 'description' => $clean($it['description'] ?? '', 140),
            'type' => $type, 'variants' => count($variants) > 1 ? $variants : []];
    }
    if ($items) $cats[] = ['name' => $clean($c['name'] ?? '', 40) ?: 'Menu', 'items' => $items];
}
if (!$cats) qmOut(['success' => false, 'message' => 'We couldn\'t find dishes in this photo. Try a clearer photo of the menu, or send it on WhatsApp.'], 422);

// ── a photo for each dish from the photo library (images/<Folder>/<file> on the server), the way Super Admin's menu
// import does it: a short list by name (includes/menu_image_matcher.php), then Gemini picks the one that really is
// that dish (veg vs chicken, momo vs thukpa), or none. A dish with no good photo just shows without one. ──
try {
    require_once __DIR__ . '/../includes/menu_image_matcher.php';
    $cands = [];
    foreach ($cats as $ci => $c) {
        foreach ($c['items'] as $ii => $it) {
            $list = find_local_menu_item_image_candidates($it['name'], $c['name'], 6);
            if ($list) $cands["$ci.$ii"] = $list;
        }
    }
    $picked = [];
    if ($cands) {
        $ask = "For each restaurant dish below pick the ONE photo (by its number) that really shows that dish, or 0 when none does. "
            . "File names describe the photo. A different main ingredient (veg / paneer / chicken / mutton / egg / fish / prawn) or a different dish is NOT a match.\n\n";
        foreach ($cands as $k => $list) {
            [$ci, $ii] = array_map('intval', explode('.', $k));
            $ask .= "Dish $k: " . $cats[$ci]['items'][$ii]['name'] . ' (' . $cats[$ci]['name'] . ', ' . $cats[$ci]['items'][$ii]['type'] . ")\n";
            foreach ($list as $n => $p) $ask .= '  ' . ($n + 1) . ') ' . $p['folder'] . '/' . $p['file'] . "\n";
        }
        $r2 = aiGeminiCall([
            'contents' => [['role' => 'user', 'parts' => [['text' => mb_substr($ask, 0, 60000)]]]],
            'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 8192, 'responseMimeType' => 'application/json', 'thinkingConfig' => ['thinkingBudget' => 0],
                'responseSchema' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'required' => ['dish', 'photo'], 'properties' => ['dish' => ['type' => 'STRING'], 'photo' => ['type' => 'INTEGER']]]]],
        ], 60);
        $answers = $r2 ? json_decode(preg_replace('/^```(?:json)?|```$/m', '', $r2['text']), true) : null;
        $answered = [];
        foreach (is_array($answers) ? $answers : [] as $a) {
            // "0.3", "Dish 0.3" or "dish 0.3:" all mean dish 0.3
            if (!is_array($a) || !preg_match('/(\d+)\.(\d+)/', (string)($a['dish'] ?? ''), $m)) continue;
            $k = $m[1] . '.' . $m[2]; $n = (int)($a['photo'] ?? 0);
            $answered[$k] = true;
            if ($n > 0 && isset($cands[$k][$n - 1])) $picked[$k] = $cands[$k][$n - 1];
        }
        // dishes Gemini didn't answer for (call failed, or skipped): only a very close name match
        foreach ($cands as $k => $list) if (!isset($answered[$k]) && $list[0]['score'] >= 0.6) $picked[$k] = $list[0];
    }
    foreach ($picked as $k => $p) {
        [$ci, $ii] = array_map('intval', explode('.', $k));
        // relative to qr-menu-ordering/ (the page that shows it); image.php sends a small WebP (&w=200)
        $cats[$ci]['items'][$ii]['image'] = '../main/website/image.php?path=' . rawurlencode('local:' . $p['folder'] . '/' . $p['file']) . '&w=200';
    }
} catch (Throwable $e) {
    error_log('qr_menu_extract photos: ' . $e->getMessage());   // the menu still goes back, without photos
}
qmOut(['success' => true, 'categories' => $cats]);
