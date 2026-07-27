<?php
// Matches AI-extracted (or hand-typed) menu item names against the local
// images/<Category>/<file> library and returns a 'local:<Category>/<file>'
// reference for item_image, or null when there is no confident match.
// The images/ folder lives at the project root (sibling of main/).

function menu_image_library_root() {
    static $resolved = null;
    static $checked = false;
    if ($checked) return $resolved;
    $checked = true;

    // The standard layout (images/ as a sibling of main/) resolves correctly
    // both locally and on most hosts. Some shared-hosting deployments
    // (e.g. Hostinger, where the document root is public_html and the app
    // may not sit at the same relative depth as it does locally) need extra
    // candidate paths, matching the fallback approach already used for
    // uploads/ elsewhere in this codebase.
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $candidates = [
        __DIR__ . '/../../images',       // main/includes -> main -> project root
        $docRoot . '/images',
        $docRoot . '/../images',
        dirname($docRoot) . '/images',
    ];

    foreach ($candidates as $candidate) {
        $real = realpath($candidate);
        if ($real !== false && is_dir($real)) {
            $resolved = $real;
            return $resolved;
        }
    }
    return null;
}

function menu_image_library_scan() {
    static $library = null;
    if ($library !== null) return $library;
    $library = [];
    $root = menu_image_library_root();
    if (!$root) return $library;

    foreach (scandir($root) as $folder) {
        if ($folder === '.' || $folder === '..') continue;
        $folderPath = $root . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($folderPath)) continue;
        $entries = [];
        foreach (scandir($folderPath) as $file) {
            if (!preg_match('/\.(png|jpe?g|webp|gif)$/i', $file)) continue;
            $entries[] = ['file' => $file, 'keywords' => menu_image_tokenize($file, true)];
        }
        if (!empty($entries)) $library[$folder] = $entries;
    }
    return $library;
}

// Lowercases, strips punctuation/extension, and drops tokens that are pure
// numbers (filename "-1"/"-2" suffixes) or generic filler words that appear
// in almost every filename and would otherwise never discriminate.
function menu_image_tokenize($text, $stripExtension = false) {
    if ($stripExtension) $text = preg_replace('/\.[a-zA-Z0-9]+$/', '', $text);
    $text = strtolower(str_replace(['-', '_'], ' ', $text));
    $text = preg_replace('/[^a-z0-9 ]/', ' ', $text);
    $words = preg_split('/\s+/', trim($text));
    $noise = ['drink' => true, 'image' => true, 'photo' => true, 'pic' => true];
    $words = array_values(array_filter($words, function ($w) use ($noise) {
        return $w !== '' && !ctype_digit($w) && !isset($noise[$w]);
    }));
    return $words;
}

// Two words count as the same word if they're identical, or a typo/plural of
// each other (small Levenshtein distance relative to word length). Short
// words (<=4 chars) require an exact match — otherwise word pairs like
// "iced"/"spiced" would wrongly count as a match just because they're close
// in edit distance.
function menu_image_words_similar($a, $b) {
    if ($a === $b) return true;
    $minLen = min(strlen($a), strlen($b));
    if ($minLen <= 4) return false;
    $maxDistance = $minLen >= 8 ? 2 : 1;
    return levenshtein($a, $b) <= $maxDistance;
}

// Fraction of $needleWords that have a similar word somewhere in $haystackWords.
function menu_image_word_overlap($needleWords, $haystackWords) {
    if (empty($needleWords)) return 0.0;
    $matched = 0;
    foreach ($needleWords as $nw) {
        foreach ($haystackWords as $hw) {
            if (menu_image_words_similar($nw, $hw)) {
                $matched++;
                break;
            }
        }
    }
    return $matched / count($needleWords);
}

function menu_image_folder_score($category, $folder) {
    $catWords = menu_image_tokenize((string)$category);
    $folderWords = menu_image_tokenize($folder);
    if (empty($catWords) || empty($folderWords)) return 0.0;
    $catText = implode(' ', $catWords);
    $folderText = implode(' ', $folderWords);
    if ($catText === $folderText) return 1.0;
    if (strpos($catText, $folderText) !== false || strpos($folderText, $catText) !== false) return 0.85;
    return menu_image_word_overlap($catWords, $folderWords);
}

/**
 * Finds the best local image match for a menu item.
 * Returns 'local:<Folder>/<filename>' or null when nothing matches confidently
 * enough (callers should leave the image blank in that case).
 */
function find_local_menu_item_image($itemName, $category = '') {
    $library = menu_image_library_scan();
    if (empty($library)) return null;

    $nameWords = menu_image_tokenize((string)$itemName);
    if (empty($nameWords)) return null;

    // Prefer folders whose name relates to the item's category (e.g. "Beverages"
    // item under a "Beverages" folder); fall back to searching every folder so
    // new categories still get a chance once matching images exist for them.
    $folderScores = [];
    foreach (array_keys($library) as $folder) {
        $folderScores[$folder] = menu_image_folder_score($category, $folder);
    }
    arsort($folderScores);
    $candidateFolders = array_keys(array_filter($folderScores, function ($s) { return $s >= 0.5; }));
    if (empty($candidateFolders)) $candidateFolders = array_keys($library);

    $best = null;
    $bestScore = 0.0;
    foreach ($candidateFolders as $folder) {
        foreach ($library[$folder] as $entry) {
            $overlap = menu_image_word_overlap($nameWords, $entry['keywords']);
            if ($overlap < 0.75) continue; // require (almost) every item-name word to be present
            $textPct = 0.0;
            similar_text(implode(' ', $nameWords), implode(' ', $entry['keywords']), $textPct);
            $score = ($overlap * 0.7) + (($textPct / 100) * 0.3);
            if ($score > $bestScore + 0.0001) {
                $bestScore = $score;
                $best = ['folder' => $folder, 'file' => $entry['file']];
            }
        }
    }

    if ($best === null || $bestScore < 0.55) return null;
    return 'local:' . $best['folder'] . '/' . $best['file'];
}
