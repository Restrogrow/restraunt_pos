<?php
/**
 * Gives a menu item a photo from the images/ library, as 'local:<Folder>/<file>'.
 *
 * How it used to go wrong, and what each part here is for:
 *
 *  - It only looked inside folders whose name resembled the restaurant's own category. A restaurant
 *    that calls its momos "Starters" could therefore never be given a momo photo — "Chicken Momo"
 *    came back with six tandoori chicken plates. Nothing is filtered by folder any more; the folder
 *    only nudges the score (menu_image_folder_score).
 *  - It scored the dish against the whole file name, props and all, so "Tandoori Roti" matched four
 *    tandoori momos and "Chilli Chicken" matched noodle platters that merely had chilli chicken on
 *    the side. Scoring now runs against the dish the photo is of (menu_image_catalogue.php), with the
 *    file name only as a weak last signal.
 *  - Nothing stopped a chicken photo landing on a paneer dish. menu_image_diet_conflict() now drops
 *    those outright, before Gemini ever sees them, and again after it answers.
 *  - A name with no English in it ("मटर पनीर") produced no candidates at all. Words are handled as
 *    text now, not bytes, and the catalogue carries Devanagari and Hinglish names for each photo.
 *  - Both callers then separately asked Gemini to pick, and separately fell back to "best text score
 *    ≥ 0.6" when it didn't answer — which is how a wrong photo got through. match_menu_item_images()
 *    is now the one way in: it shortlists, asks once for the whole menu, re-checks the answer, and
 *    where there is no answer it takes only an exact catalogue hit.
 */

require_once __DIR__ . '/menu_image_catalogue.php';

if (!function_exists('find_local_menu_item_image_candidates')) {

    function menu_image_library_root() {
        static $resolved = null;
        static $checked = false;
        if ($checked) return $resolved;
        $checked = true;

        // The standard layout (images/ as a sibling of main/) resolves correctly both locally and on
        // most hosts. Some shared-hosting deployments (e.g. Hostinger, where the document root is
        // public_html and the app may not sit at the same relative depth as it does locally) need
        // extra candidate paths, matching the fallback approach already used for uploads/ elsewhere.
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

    /**
     * Text → words. Unicode-aware, so Devanagari survives; digits and the handful of words that mean
     * nothing on their own ("half", "plate") are dropped.
     */
    function menu_image_tokenize($text, $stripExtension = false) {
        if ($stripExtension) $text = preg_replace('/\.[A-Za-z0-9]+$/', '', (string)$text);
        $text = mb_strtolower(str_replace(['-', '_'], ' ', (string)$text));
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $noise = ['drink' => 1, 'image' => 1, 'photo' => 1, 'pic' => 1, 'half' => 1, 'full' => 1, 'regular' => 1,
            'small' => 1, 'medium' => 1, 'large' => 1, 'special' => 1, 'combo' => 1, 'meal' => 1, 'pcs' => 1,
            'pc' => 1, 'piece' => 1, 'pieces' => 1, 'plate' => 1, 'bowl' => 1, 'with' => 1, 'and' => 1,
            'the' => 1, 'of' => 1, 'in' => 1, 'served' => 1, 'our' => 1, 'new' => 1];
        $words = [];
        foreach (preg_split('/\s+/u', trim($text)) as $w) {
            if ($w === '' || preg_match('/^\d+$/u', $w) || isset($noise[$w])) continue;
            $words[] = $w;
        }
        return $words;
    }

    /**
     * Strips common English plural endings so "momo"/"momos", "roll"/"rolls", "cake"/"cakes",
     * "dosa"/"dosas" etc. compare equal. Deliberately simple (not a full stemmer) — good enough for
     * short food-item nouns, and left alone for anything that isn't plain Latin letters.
     */
    function menu_image_stem($word) {
        if (!preg_match('/^[a-z]+$/', $word)) return $word;
        $len = strlen($word);
        if ($len > 4 && substr($word, -3) === 'ies') return substr($word, 0, -3) . 'y';
        if ($len > 4 && substr($word, -2) === 'es' && in_array(substr($word, -3, 1), ['s', 'x', 'z', 'h'], true)) {
            return substr($word, 0, -2);
        }
        if ($len > 3 && substr($word, -1) === 's' && substr($word, -2) !== 'ss') return substr($word, 0, -1);
        return $word;
    }

    /**
     * The same thing under its everyday name: a photo named "potato patties" has to answer to "aloo
     * tikki", and "vegetable momos" to "Veg Momo". Only pairs that really are the same ingredient or
     * dish word — anything looser belongs in the catalogue's alias list, where Gemini wrote it.
     */
    function menu_image_canon($word) {
        static $map = null;
        if ($map === null) {
            $map = [];
            $same = [
                'veg' => 'vegetable vegetables vegetarian veggie veggies',
                'momo' => 'momos dumpling dumplings',
                'aloo' => 'potato potatoes',
                'gobi' => 'gobhi cauliflower',
                'matar' => 'mutter peas',
                'chole' => 'chhole chana channa chickpea chickpeas',
                'kaju' => 'cashew cashews',
                'dal' => 'daal lentil lentils',
                'bhindi' => 'okra',
                'baingan' => 'eggplant aubergine brinjal',
                'palak' => 'spinach',
                'chai' => 'tea',
                'roti' => 'chapati chapathi phulka',
                'anda' => 'egg eggs',
                'machli' => 'fish',
                'jhinga' => 'prawn prawns shrimp shrimps',
                'murg' => 'chicken murgh',
                'gosht' => 'mutton lamb goat',
                'dahi' => 'curd yogurt yoghurt',
                'barfi' => 'burfi katli',
            ];
            foreach ($same as $canon => $words) {
                foreach (explode(' ', $words) as $w) $map[$w] = $canon;
            }
        }
        return $map[$word] ?? $word;
    }

    /** The one spelling a word is filed under: its everyday name, singular. */
    function menu_image_key($word) {
        return menu_image_canon(menu_image_stem(menu_image_canon($word)));
    }

    /**
     * Two words count as the same word if they're identical, plural/singular or everyday forms of
     * each other, or a typo of each other. Short words (<=4 chars) require an exact match —
     * otherwise pairs like "iced"/"spiced" would count as a match just because they're close in edit
     * distance. Edit distance is only used on plain Latin words: levenshtein() counts bytes, which
     * would make nonsense of Devanagari.
     */
    function menu_image_words_similar($a, $b) {
        if ($a === $b) return true;
        $keyA = menu_image_key($a);
        $keyB = menu_image_key($b);
        if ($keyA === $keyB) return true;
        if (!preg_match('/^[a-z]+$/', $keyA) || !preg_match('/^[a-z]+$/', $keyB)) return false;
        $minLen = min(strlen($keyA), strlen($keyB));
        if ($minLen <= 4) return false;
        $maxDistance = $minLen >= 8 ? 2 : 1;
        return levenshtein($keyA, $keyB) <= $maxDistance;
    }

    /** Fraction of $needleWords that have a similar word somewhere in $haystackWords. */
    function menu_image_word_overlap($needleWords, $haystackWords) {
        if (empty($needleWords)) return 0.0;
        $matched = 0;
        foreach ($needleWords as $nw) {
            foreach ($haystackWords as $hw) {
                if (menu_image_words_similar($nw, $hw)) { $matched++; break; }
            }
        }
        return $matched / count($needleWords);
    }

    /**
     * How much a word is worth as evidence: "bhature" belongs to two photos and all but settles the
     * question, "veg" belongs to hundreds and settles nothing. Without this, "Veg Momo" scored a
     * mixed-vegetable curry exactly as highly as a momo.
     */
    function menu_image_word_weight($word) {
        static $n = 0, $cache = [];
        $key = menu_image_key($word);
        if (isset($cache[$key])) return $cache[$key];
        $index = menu_image_index();
        if (!$n) $n = max(1, count(menu_image_catalogue()));
        return $cache[$key] = log(1.0 + $n / max(1, count($index[$key] ?? [])));
    }

    /**
     * Like menu_image_word_overlap(), but counting each word for what it is worth.
     *
     * The word naming the ingredient — "veg", "chicken", "egg" — counts for a third: that part of the
     * name is already settled, far more strictly, by the diet guard, and at full weight it drowned out
     * the word that says which dish it is ("Veg Momo" scored a veg burger above a momo). It still
     * counts for something, so "Egg Curry" prefers the egg curry over a kebab curry.
     */
    function menu_image_word_overlap_weighted($needleWords, $haystackWords) {
        $total = 0.0;
        $hit = 0.0;
        foreach ($needleWords as $nw) {
            $w = menu_image_word_weight($nw) * (menu_image_diet($nw) !== '' ? 0.35 : 1.0);
            $total += $w;
            foreach ($haystackWords as $hw) {
                if (menu_image_words_similar($nw, $hw)) { $hit += $w; break; }
            }
        }
        return $total > 0 ? $hit / $total : 0.0;
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

    // ── finding candidates ──

    /**
     * word → the photos that word belongs to. Without it every dish would be scored against all
     * 3,000-odd photos; with it, only the handful that share a word with the dish's name.
     */
    function menu_image_index() {
        static $index = null;
        if ($index !== null) return $index;
        $index = [];
        foreach (menu_image_catalogue() as $key => $row) {
            $seen = [];
            foreach (menu_image_row_words($row) as $w) {
                $s = menu_image_key($w);
                if (isset($seen[$s])) continue;
                $seen[$s] = true;
                $index[$s][] = $key;
            }
        }
        return $index;
    }

    /**
     * The index entries a word should look under: its own, plus any spelt a typo away from it.
     * Walking the whole index for that costs real time on a 200-dish import, so each word's answer
     * is worked out once.
     */
    function menu_image_index_words($word) {
        static $cache = [];
        $key = menu_image_key($word);
        if (isset($cache[$key])) return $cache[$key];
        $index = menu_image_index();
        $out = isset($index[$key]) ? [$key] : [];
        if (strlen($key) >= 5 && preg_match('/^[a-z]+$/', $key)) {
            foreach ($index as $iw => $_) {
                if ($iw !== $key && abs(strlen($iw) - strlen($key)) <= 2 && menu_image_words_similar($key, $iw)) $out[] = $iw;
            }
        }
        return $cache[$key] = $out;
    }

    /** Every word that could lead to this photo: its dish, its other names, the end of its file name. */
    function menu_image_row_words(array $row) {
        $out = menu_image_tokenize((string)$row['d']);
        foreach ((array)($row['a'] ?? []) as $a) foreach (menu_image_tokenize($a) as $w) $out[] = $w;
        foreach ((array)($row['tail'] ?? []) as $w) $out[] = $w;
        foreach ((array)($row['w'] ?? []) as $w) $out[] = $w;
        return $out;
    }

    /** How well a photo answers to a dish's name: 0 when it doesn't, 1 for the dish by that exact name. */
    function menu_image_row_score(array $need, array $row) {
        $names = [];
        if (trim((string)$row['d']) !== '') $names[] = (string)$row['d'];
        foreach ((array)($row['a'] ?? []) as $a) if (trim((string)$a) !== '') $names[] = (string)$a;

        $best = 0.0;
        $nameWords = [];
        foreach ($names as $n) {
            $nw = menu_image_tokenize($n);
            foreach ($nw as $w) $nameWords[] = $w;
            $nText = implode(' ', $nw);
            if ($nText === '') continue;
            if ($nText === $need['text']) return 1.0;
            if (strpos($nText, $need['text']) !== false || strpos($need['text'], $nText) !== false) {
                $a = mb_strlen($nText); $b = mb_strlen($need['text']);
                $best = max($best, 0.90 * (min($a, $b) / max($a, $b)) + 0.08);
            }
        }
        if ($nameWords) $best = max($best, menu_image_word_overlap_weighted($need['words'], $nameWords) * 0.82);
        if (!empty($row['tail'])) $best = max($best, menu_image_word_overlap_weighted($need['words'], $row['tail']) * 0.62);
        if (!empty($row['w'])) $best = max($best, menu_image_word_overlap_weighted($need['words'], $row['w']) * 0.42);

        // a photo of more than the dish asked for is a looser fit: "manchurian fried rice" answers to
        // "Gobi Manchurian" less well than plain gobi manchurian does
        $dishWords = menu_image_tokenize((string)$row['d']);
        if ($dishWords && $best < 1.0) $best *= 0.85 + 0.15 * menu_image_word_overlap($dishWords, $need['words']);

        // a photo nobody has catalogued yet is a guess: never let it outrank a known one
        if (!empty($row['guess'])) $best = min($best, 0.80);
        return $best;
    }

    /** The dish being looked for, prepared once: its words, and what it is made of. */
    function menu_image_needle($itemName, $category = '', $type = '') {
        $words = menu_image_tokenize((string)$itemName);
        $diet = menu_image_diet((string)$itemName);
        if ($diet === '' && in_array(mb_strtolower(trim((string)$type)), ['veg', 'vegetarian'], true)) $diet = 'veg';
        return ['text' => implode(' ', $words), 'words' => $words, 'diet' => $diet, 'cat' => (string)$category];
    }

    /**
     * Up to $maxCandidates photos for $itemName, best first, as [['folder','file','score','dish'], …].
     *
     * Deliberately a shortlist, not a decision: it exists so Gemini can be handed a small, varied set
     * (at most two photos of any one dish, so a wrong guess doesn't fill every slot) rather than the
     * whole library. Anything made of the wrong thing is dropped here and never offered.
     */
    function find_local_menu_item_image_candidates($itemName, $category = '', $maxCandidates = 8, $type = '') {
        $need = menu_image_needle($itemName, $category, $type);
        if (empty($need['words'])) return [];

        $catalogue = menu_image_catalogue();
        if (!$catalogue) return [];
        $index = menu_image_index();

        $look = [];
        foreach ($need['words'] as $w) {
            foreach (menu_image_index_words($w) as $iw) {
                foreach ($index[$iw] as $key) $look[$key] = true;
            }
        }
        if (!$look) return [];

        $folderBoost = [];
        $scored = [];
        foreach (array_keys($look) as $key) {
            $row = $catalogue[$key];
            if (menu_image_diet_conflict($need['diet'], (string)$row['t'])) continue;
            $score = menu_image_row_score($need, $row);
            if ($score < 0.30) continue;
            $folder = $row['folder'];
            if (!isset($folderBoost[$folder])) $folderBoost[$folder] = menu_image_folder_score($need['cat'], $folder) * 0.06;
            // 'exact' is the score before the folder nudge: being in a likely-looking folder must never
            // round a near miss up into "this is certainly it"
            $scored[] = ['folder' => $folder, 'file' => $row['file'], 'score' => min(1.0, $score + $folderBoost[$folder]),
                'raw' => $score, 'exact' => $score >= 1.0, 'dish' => (string)$row['d'], 'diet' => (string)$row['t'],
                'guess' => !empty($row['guess'])];
        }
        if (!$scored) return [];

        usort($scored, function ($a, $b) { return $b['score'] <=> $a['score']; });

        // two photos per dish at most, so one wrong dish can't take every slot
        $out = [];
        $perDish = [];
        foreach ($scored as $c) {
            $d = $c['dish'] !== '' ? $c['dish'] : $c['file'];
            if (($perDish[$d] ?? 0) >= 2) continue;
            $perDish[$d] = ($perDish[$d] ?? 0) + 1;
            $out[] = $c;
            if (count($out) >= $maxCandidates) break;
        }
        return $out;
    }

    // ── choosing ──

    /** Already the dish by name, from a catalogued photo: no need to ask. */
    function menu_image_is_certain(array $need, array $candidate) {
        return !empty($candidate['exact']) && $candidate['dish'] !== ''
            && !menu_image_diet_conflict($need['diet'], (string)($candidate['diet'] ?? ''));
    }

    /**
     * Photos for a whole menu at once. $items is [key => ['name' => …, 'category' => …, 'type' => …]];
     * the answer is [key => 'local:<Folder>/<file>'] for the items that got one — items with no honest
     * match are simply left out, because no photo is better than the wrong photo.
     *
     * Gemini makes the call between the shortlisted photos (it is the part that knows a veg momo from
     * a chicken one, and that "kaju katli" is the cashew barfi); the code around it decides nothing by
     * similarity alone unless the catalogue already has the dish under that exact name.
     */
    function match_menu_item_images(array $items, ?PDO $conn = null, string $rid = '', int $perItem = 6) {
        $picked = [];
        $need = [];
        $cands = [];

        foreach ($items as $key => $it) {
            $name = trim((string)($it['name'] ?? ''));
            if ($name === '') continue;
            $n = menu_image_needle($name, (string)($it['category'] ?? ''), (string)($it['type'] ?? ''));
            $list = find_local_menu_item_image_candidates($name, (string)($it['category'] ?? ''), $perItem, (string)($it['type'] ?? ''));
            if (!$list) continue;
            if (menu_image_is_certain($n, $list[0])) {
                $picked[$key] = 'local:' . $list[0]['folder'] . '/' . $list[0]['file'];
                continue;
            }
            $need[$key] = $n;
            $cands[$key] = $list;
        }
        if (!$cands) return $picked;

        $answered = [];
        $mayAsk = aiGeminiAvailable() && aiGeminiAllow($conn, $rid);
        foreach ($mayAsk ? array_chunk($cands, 40, true) : [] as $chunk) {
            $ask = '';
            $keys = array_keys($chunk);
            foreach ($keys as $i => $key) {
                $it = $items[$key];
                $label = trim((string)$it['name']);
                if (trim((string)($it['category'] ?? '')) !== '') $label .= ' — ' . trim((string)$it['category']);
                $ask .= 'Dish ' . ($i + 1) . ': ' . $label . "\n";
                foreach ($chunk[$key] as $ci => $c) {
                    // a catalogued photo is offered as the dish it is; one nobody has catalogued yet is
                    // offered as its file name, which says more than this code's guess at a dish name
                    $label = (!$c['guess'] && $c['dish'] !== '') ? $c['dish'] : str_replace(['-', '_'], ' ', pathinfo($c['file'], PATHINFO_FILENAME));
                    $ask .= '  ' . ($ci + 1) . ') ' . $label . ' [' . $c['folder'] . ']' . "\n";
                }
                $ask .= "\n";
            }
            $r = aiGeminiCall([
                'systemInstruction' => ['parts' => [['text' =>
                    "You are picking the photo for each dish on a restaurant's menu.\n"
                    . "For every dish choose the ONE listed photo that is that same dish, or 0 when none of them is.\n"
                    . "A different main ingredient (veg / paneer / egg / chicken / mutton / buff / pork / fish / prawn) is a "
                    . "different dish — never pick one. A different cooking style (steamed vs fried vs tandoori) or a "
                    . "different dish that merely shares a word is also not a match.\n"
                    . "Prefer 0 over a near miss: a dish with no photo is fine, a dish with the wrong photo is not."]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => mb_substr($ask, 0, 60000)]]]],
                'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 8192, 'responseMimeType' => 'application/json',
                    'thinkingConfig' => ['thinkingBudget' => 0],
                    'responseSchema' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'required' => ['dish', 'photo'],
                        'properties' => ['dish' => ['type' => 'INTEGER'], 'photo' => ['type' => 'INTEGER']]]]],
            ], 90);

            $rows = ($r && $r['text'] !== '') ? json_decode(preg_replace('/^```(?:json)?|```$/m', '', $r['text']), true) : null;
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row)) continue;
                $d = (int)($row['dish'] ?? 0);
                if ($d < 1 || $d > count($keys)) continue;
                $key = $keys[$d - 1];
                $answered[$key] = true;
                $n = (int)($row['photo'] ?? 0);
                if ($n < 1 || !isset($chunk[$key][$n - 1])) continue;
                $c = $chunk[$key][$n - 1];
                // it has been told, but the dish's own name is the final word on this
                if (menu_image_diet_conflict($need[$key]['diet'], (string)($c['diet'] ?? ''))) continue;
                $picked[$key] = 'local:' . $c['folder'] . '/' . $c['file'];
            }
        }

        // Gemini never answered for these (no key, call failed, skipped). Only a catalogued photo of
        // all but the same name is taken on our own — a guessed-from-the-file-name row is capped at
        // 0.80 and can never get here. Picking on a middling similarity score is what used to put
        // momos on tandoori roti.
        foreach ($cands as $key => $list) {
            if (isset($picked[$key]) || isset($answered[$key])) continue;
            if ($list[0]['raw'] >= 0.92 && $list[0]['dish'] !== '' && !menu_image_diet_conflict($need[$key]['diet'], (string)$list[0]['diet'])) {
                $picked[$key] = 'local:' . $list[0]['folder'] . '/' . $list[0]['file'];
            }
        }
        return $picked;
    }
}
