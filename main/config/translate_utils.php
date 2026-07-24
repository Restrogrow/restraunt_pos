<?php

function ensureLanguageColumns($conn, $restaurant_id = null) {
    return false;
}

function autoTranslateText($text, $targetLang, $sourceLang = 'en') {
    if (empty(trim($text)) || $targetLang === $sourceLang) return $text;
    $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=' . urlencode($sourceLang) . '&tl=' . urlencode($targetLang) . '&dt=t&q=' . urlencode($text);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200 || !$response) return $text;
    $decoded = json_decode($response, true);
    if (is_array($decoded) && isset($decoded[0])) {
        $translated = '';
        foreach ($decoded[0] as $segment) {
            if (is_array($segment) && isset($segment[0])) $translated .= $segment[0];
        }
        return $translated ?: $text;
    }
    return $text;
}

function autoTranslateMenuItems($conn, $restaurant_id, $targetLang) {
    if ($targetLang === 'en') return ['items' => 0, 'menus' => 0, 'subcategories' => 0];
    $counts = ['items' => 0, 'menus' => 0, 'subcategories' => 0];

    $items = $conn->prepare("SELECT id, item_name_en, item_description_en, translations FROM menu_items WHERE restaurant_id = ?");
    $items->execute([$restaurant_id]);
    $menuIds = $conn->prepare("SELECT id, menu_name, translations FROM menu WHERE restaurant_id = ?");
    $menuIds->execute([$restaurant_id]);

    $updateItem = $conn->prepare("UPDATE menu_items SET translations = ? WHERE id = ?");
    $updateMenu = $conn->prepare("UPDATE menu SET translations = ? WHERE id = ?");

    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existing = json_decode($row['translations'] ?: '{}', true);
        if (isset($existing[$targetLang]['name']) && isset($existing[$targetLang]['description'])) continue;
        if (!isset($existing[$targetLang])) $existing[$targetLang] = [];
        if (empty($existing[$targetLang]['name'])) {
            $existing[$targetLang]['name'] = autoTranslateText($row['item_name_en'], $targetLang);
        }
        if (empty($existing[$targetLang]['description']) && !empty($row['item_description_en'])) {
            $existing[$targetLang]['description'] = autoTranslateText($row['item_description_en'], $targetLang);
        }
        $updateItem->execute([json_encode($existing, JSON_UNESCAPED_UNICODE), $row['id']]);
        $counts['items']++;
    }

    foreach ($menuIds->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existing = json_decode($row['translations'] ?: '{}', true);
        if (isset($existing[$targetLang]['name'])) continue;
        if (!isset($existing[$targetLang])) $existing[$targetLang] = [];
        $existing[$targetLang]['name'] = autoTranslateText($row['menu_name'], $targetLang);
        $updateMenu->execute([json_encode($existing, JSON_UNESCAPED_UNICODE), $row['id']]);
        $counts['menus']++;
    }

    try {
        $subHasCol = $conn->query("SHOW COLUMNS FROM subcategories LIKE 'translations'")->rowCount() > 0;
        if ($subHasCol) {
            $subs = $conn->prepare("SELECT id, subcategory_name, translations FROM subcategories WHERE menu_id IN (SELECT id FROM menu WHERE restaurant_id = ?)");
            $subs->execute([$restaurant_id]);
            $updateSub = $conn->prepare("UPDATE subcategories SET translations = ? WHERE id = ?");
            foreach ($subs->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $existing = json_decode($row['translations'] ?: '{}', true);
                if (isset($existing[$targetLang]['name'])) continue;
                if (!isset($existing[$targetLang])) $existing[$targetLang] = [];
                $existing[$targetLang]['name'] = autoTranslateText($row['subcategory_name'], $targetLang);
                $updateSub->execute([json_encode($existing, JSON_UNESCAPED_UNICODE), $row['id']]);
                $counts['subcategories']++;
            }
        }
    } catch (Exception $e) {}

    return $counts;
}

function translateSingleMenuItem($conn, $id, $restaurant_id, $targetLang, $nameEn, $descEn) {
    if ($targetLang === 'en') return;
    $stmt = $conn->prepare("SELECT translations FROM menu_items WHERE id = ? AND restaurant_id = ?");
    $stmt->execute([$id, $restaurant_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;
    $existing = json_decode($row['translations'] ?: '{}', true);
    if (!isset($existing[$targetLang])) $existing[$targetLang] = [];
    $existing[$targetLang]['name'] = autoTranslateText($nameEn, $targetLang);
    if (!empty($descEn)) {
        $existing[$targetLang]['description'] = autoTranslateText($descEn, $targetLang);
    }
    $upd = $conn->prepare("UPDATE menu_items SET translations = ? WHERE id = ? AND restaurant_id = ?");
    $upd->execute([json_encode($existing, JSON_UNESCAPED_UNICODE), $id, $restaurant_id]);
}
