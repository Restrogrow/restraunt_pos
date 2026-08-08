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

// Strips common English plural endings so "momo"/"momos", "roll"/"rolls",
// "cake"/"cakes", "dosa"/"dosas" etc. compare equal. Deliberately simple
// (not a full stemmer) — good enough for short food-item nouns.
function menu_image_stem($word) {
    $len = strlen($word);
    if ($len > 4 && substr($word, -3) === 'ies') return substr($word, 0, -3) . 'y';
    if ($len > 4 && substr($word, -2) === 'es' && in_array(substr($word, -3, 1), ['s', 'x', 'z', 'h'], true)) {
        return substr($word, 0, -2);
    }
    if ($len > 3 && substr($word, -1) === 's' && substr($word, -2) !== 'ss') return substr($word, 0, -1);
    return $word;
}

// Two words count as the same word if they're identical, plural/singular
// forms of each other, or a typo of each other (small Levenshtein distance
// relative to word length). Short words (<=4 chars) require an exact/plural
// match — otherwise word pairs like "iced"/"spiced" would wrongly count as a
// match just because they're close in edit distance.
function menu_image_words_similar($a, $b) {
    if ($a === $b) return true;
    $stemA = menu_image_stem($a);
    $stemB = menu_image_stem($b);
    if ($stemA === $stemB) return true;
    $minLen = min(strlen($stemA), strlen($stemB));
    if ($minLen <= 4) return false;
    $maxDistance = $minLen >= 8 ? 2 : 1;
    return levenshtein($stemA, $stemB) <= $maxDistance;
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

// Looser than menu_image_words_similar(): also counts a prefix relationship
// ("veg" / "vegetable", "buff" / "buffalo") as related. Used only to build the
// candidate shortlist handed to the LLM — the LLM does the real precision
// judgement, so this side deliberately favors not missing a real match over
// avoiding the occasional loose one.
function menu_image_words_related($a, $b) {
    if (menu_image_words_similar($a, $b)) return true;
    if (min(strlen($a), strlen($b)) < 3) return false;
    return strpos($b, $a) === 0 || strpos($a, $b) === 0;
}

function menu_image_word_overlap_lenient($needleWords, $haystackWords) {
    if (empty($needleWords)) return 0.0;
    $matched = 0;
    foreach ($needleWords as $nw) {
        foreach ($haystackWords as $hw) {
            if (menu_image_words_related($nw, $hw)) {
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
 * Returns up to $maxCandidates local images ranked by rough text similarity
 * to $itemName, as [['folder'=>, 'file'=>, 'score'=>], ...] sorted best-first.
 * This is deliberately a loose shortlist, not a final decision — it exists to
 * keep the list small enough to hand to an LLM (which makes the real pick)
 * without spending tokens on the entire images/ library.
 */
function find_local_menu_item_image_candidates($itemName, $category = '', $maxCandidates = 8) {
    $library = menu_image_library_scan();
    if (empty($library)) return [];

    $nameWords = menu_image_tokenize((string)$itemName);
    if (empty($nameWords)) return [];

    $folderScores = [];
    foreach (array_keys($library) as $folder) {
        $folderScores[$folder] = menu_image_folder_score($category, $folder);
    }
    arsort($folderScores);
    $candidateFolders = array_keys(array_filter($folderScores, function ($s) { return $s >= 0.5; }));
    if (empty($candidateFolders)) $candidateFolders = array_keys($folderScores);

    $scored = [];
    foreach ($candidateFolders as $folder) {
        foreach ($library[$folder] as $entry) {
            $overlap = menu_image_word_overlap_lenient($nameWords, $entry['keywords']);
            if ($overlap <= 0) continue;
            $textPct = 0.0;
            similar_text(implode(' ', $nameWords), implode(' ', $entry['keywords']), $textPct);
            $score = ($overlap * 0.7) + (($textPct / 100) * 0.3);
            $scored[] = ['folder' => $folder, 'file' => $entry['file'], 'score' => $score];
        }
    }

    // A short/generic item name (e.g. "Veg" under a "Momo" category) can share
    // no word at all with any filename. Rather than leaving the LLM with
    // nothing to choose from, fall back to the best category-matched folder so
    // it can still reason from the item name + category together.
    if (empty($scored) && !empty($candidateFolders) && $folderScores[$candidateFolders[0]] >= 0.5) {
        $folder = $candidateFolders[0];
        foreach ($library[$folder] as $entry) {
            $textPct = 0.0;
            similar_text(implode(' ', $nameWords), implode(' ', $entry['keywords']), $textPct);
            $scored[] = ['folder' => $folder, 'file' => $entry['file'], 'score' => $textPct / 100];
        }
    }

    usort($scored, function ($a, $b) { return $b['score'] <=> $a['score']; });
    return array_slice($scored, 0, $maxCandidates);
}
