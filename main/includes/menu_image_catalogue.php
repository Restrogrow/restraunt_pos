<?php
/**
 * What every photo in images/ actually is.
 *
 * The photo library names files after what is in the shot
 * ("boiled-eggs-spicy-tomato-gravy-coriander-brass-kadai-egg-curry-142.png"), which is plenty for a
 * person and useless for a scoring formula: the dish ("egg curry") is buried among the props, and a
 * dish a restaurant actually sells ("Kaju Katli", "मटर पनीर") often shares no word at all with the
 * file that shows it ("milk-barfi-cashew-nuts", "matar-paneer").
 *
 * So Gemini reads the library once and writes down, per photo: the dish, what it is made of, and the
 * other names people use for it — including Hindi and Devanagari. That catalogue is what
 * menu_image_matcher.php searches, instead of raw file names. Photos whose name says nothing
 * (NSS09821.JPG) are looked at, not read: the picture itself goes to Gemini.
 *
 * Build it with:  php main/tools/build_menu_image_catalogue.php
 * It is cached in main/uploads/cache/menu-images/catalogue.json and only ever added to, so a rebuild
 * after dropping in new photos costs only the new ones. Without it everything still works — matching
 * falls back to the file name, which is weaker but no longer wrong in the ways it used to be.
 */

require_once __DIR__ . '/../config/ai_gemini.php';

if (!function_exists('menu_image_catalogue')) {

    define('MENU_IMAGE_CATALOGUE_DIR', __DIR__ . '/../uploads/cache/menu-images');
    define('MENU_IMAGE_CATALOGUE_FILE', MENU_IMAGE_CATALOGUE_DIR . '/catalogue.json');

    /**
     * What a dish is made of, from its name. Used on both sides: a "Veg Momo" must never be given a
     * chicken momo's photo, however well the rest of the words line up.
     *
     * Checked meat-first: "Chicken Seekh Kebab" is chicken, not "kebab".
     */
    function menu_image_diet(string $text): string
    {
        $t = ' ' . preg_replace('/[^a-z\p{Devanagari}\s]+/u', ' ', mb_strtolower($text)) . ' ';
        $groups = [
            'chicken' => ['chicken', 'murg', 'murgh', 'kukhura', 'tangdi', 'tangri', 'चिकन', 'मुर्ग'],
            'mutton'  => ['mutton', 'lamb', 'gosht', 'goat', 'khasi', 'keema', 'मटन', 'गोश्त'],
            'buff'    => ['buff', 'buffalo', 'bhainsi'],
            'pork'    => ['pork', 'bacon', 'ham'],
            'prawn'   => ['prawn', 'shrimp', 'jhinga', 'jheenga'],
            'fish'    => ['fish', 'machli', 'machhli', 'macher', 'pomfret', 'surmai', 'bhetki', 'tuna', 'salmon', 'basa', 'मछली'],
            'egg'     => ['egg', 'eggs', 'anda', 'ande', 'omelette', 'omelet', 'अंडा'],
            'paneer'  => ['paneer', 'cottage cheese', 'पनीर'],
            'veg'     => ['veg', 'vegetable', 'vegetarian', 'aloo', 'potato', 'gobi', 'cauliflower', 'mushroom', 'soya', 'soy', 'tofu',
                          'dal', 'daal', 'lentil', 'chana', 'chole', 'chhole', 'rajma', 'palak', 'spinach', 'bhindi', 'okra',
                          'baingan', 'eggplant', 'aubergine', 'matar', 'peas', 'corn', 'kaju', 'cashew', 'malai kofta', 'सब्जी', 'आलू', 'मटर'],
        ];
        foreach ($groups as $diet => $words) {
            foreach ($words as $w) {
                if (strpos($t, ' ' . $w . ' ') !== false) return $diet;
            }
        }
        return '';
    }

    /** Two things that cannot be the same dish. An unknown side never conflicts; veg and paneer sit together. */
    function menu_image_diet_conflict(string $a, string $b): bool
    {
        if ($a === '' || $b === '' || $a === $b) return false;
        $soft = ['veg' => true, 'paneer' => true];
        return !(isset($soft[$a]) && isset($soft[$b]));
    }

    /** Words that describe the shot, not the dish: they must never decide a match. */
    function menu_image_prop_words(): array
    {
        static $p = null;
        if ($p !== null) return $p;
        $list = 'plate platter bowl bowls dish tray basket kadai pot pan skillet wok glass mug cup jar board slate
            brass silver copper steel ceramic clay stone wooden wood marble rustic ornate footed oval square round patterned dark light
            black white red green blue brown cream golden yellow grey gray
            overhead closeup close top side angle shot photo image pic drink food
            garnish garnished coriander cilantro mint parsley lemon lime onion rings chutney dip dips sauce mayo pickle salad raita papad
            fresh crispy creamy steaming charred grilled roasted fried pan sliced diced chopped assorted spicy thick rich hot cold
            swirl drizzle slivers petal petals wedge wedges julienne leaf leaves sprig cube cubes piece pieces slice
            pistachio almond saffron sesame scallion spring chilli chili
            hand hands chopsticks fork spoon knife napkin cloth table candlelight vase teapot lantern background';
        $p = array_fill_keys(preg_split('/\s+/', trim($list)), true);
        return $p;
    }

    /** File name → words, lower case, no extension, no numbers. */
    function menu_image_file_words(string $file): array
    {
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $file);
        $base = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', str_replace(['-', '_'], ' ', $base)));
        $out = [];
        foreach (preg_split('/\s+/u', trim($base)) as $w) {
            if ($w === '' || preg_match('/^\d+$/', $w)) continue;
            $out[] = $w;
        }
        return $out;
    }

    /**
     * What we can tell about a photo from its name alone — the fallback when the catalogue has not
     * been built yet, and the starting point for the entries Gemini fills in.
     *
     * "the dish" is read off the end of the name, which is where this library puts it, with the prop
     * words dropped first so "masala-chai-mint-drink-tea" ends at "chai tea", not "drink tea".
     */
    function menu_image_entry_from_file(string $folder, string $file): array
    {
        $words = menu_image_file_words($file);
        $props = menu_image_prop_words();
        $solid = array_values(array_filter($words, function ($w) use ($props) { return !isset($props[$w]); }));
        if (!$solid) $solid = $words;
        $tail = array_slice($solid, -3);
        return [
            'd' => implode(' ', array_slice($solid, -2)),   // a guess, good enough to group photos by
            't' => menu_image_diet(implode(' ', $words) . ' ' . $folder),
            'a' => [],
            'tail' => $tail,
            'w' => $words,
            'guess' => true,
        ];
    }

    /** Every photo in the library with whatever we know about it: the catalogue over the file names. */
    function menu_image_catalogue(): array
    {
        static $cat = null;
        if ($cat !== null) return $cat;
        require_once __DIR__ . '/menu_image_matcher.php';

        $saved = [];
        if (is_file(MENU_IMAGE_CATALOGUE_FILE)) {
            $j = json_decode((string)@file_get_contents(MENU_IMAGE_CATALOGUE_FILE), true);
            if (is_array($j) && isset($j['files']) && is_array($j['files'])) $saved = $j['files'];
        }

        $cat = [];
        foreach (menu_image_library_scan() as $folder => $entries) {
            foreach ($entries as $e) {
                $key = $folder . '/' . $e['file'];
                $row = menu_image_entry_from_file($folder, $e['file']);
                if (isset($saved[$key]) && is_array($saved[$key]) && trim((string)($saved[$key]['d'] ?? '')) !== '') {
                    $s = $saved[$key];
                    $row['d'] = mb_strtolower(trim((string)$s['d']));
                    $row['t'] = (string)($s['t'] ?? $row['t']);
                    $row['a'] = array_values(array_filter(array_map(function ($x) {
                        return mb_strtolower(trim((string)$x));
                    }, (array)($s['a'] ?? []))));
                    $row['guess'] = false;
                }
                $row['folder'] = $folder;
                $row['file'] = $e['file'];
                $cat[$key] = $row;
            }
        }
        return $cat;
    }

    /** How many photos the catalogue really knows (as opposed to guessed from the file name). */
    function menu_image_catalogue_stats(): array
    {
        $known = 0;
        $cat = menu_image_catalogue();
        foreach ($cat as $row) if (empty($row['guess'])) $known++;
        return ['total' => count($cat), 'known' => $known, 'file' => MENU_IMAGE_CATALOGUE_FILE,
            'built' => is_file(MENU_IMAGE_CATALOGUE_FILE) ? date('Y-m-d H:i', (int)filemtime(MENU_IMAGE_CATALOGUE_FILE)) : null];
    }

    // ── building it ──

    function menu_image_catalogue_load_raw(): array
    {
        if (!is_file(MENU_IMAGE_CATALOGUE_FILE)) return [];
        $j = json_decode((string)@file_get_contents(MENU_IMAGE_CATALOGUE_FILE), true);
        return (is_array($j) && isset($j['files']) && is_array($j['files'])) ? $j['files'] : [];
    }

    function menu_image_catalogue_save(array $files): bool
    {
        if (!is_dir(MENU_IMAGE_CATALOGUE_DIR) && !@mkdir(MENU_IMAGE_CATALOGUE_DIR, 0775, true)) return false;
        $tmp = MENU_IMAGE_CATALOGUE_FILE . '.' . getmypid() . '.tmp';
        $ok = @file_put_contents($tmp, json_encode(['version' => 1, 'built' => date('c'), 'files' => $files], JSON_UNESCAPED_UNICODE)) !== false;
        return $ok && @rename($tmp, MENU_IMAGE_CATALOGUE_FILE);
    }

    /** The answer shape asked of Gemini, both for file names and for pictures. */
    function menu_image_catalogue_schema(): array
    {
        return ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'required' => ['n', 'd', 't'], 'properties' => [
            'n' => ['type' => 'INTEGER'],
            'd' => ['type' => 'STRING'],
            't' => ['type' => 'STRING', 'enum' => ['veg', 'paneer', 'egg', 'chicken', 'mutton', 'buff', 'pork', 'fish', 'prawn', 'other']],
            'a' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        ]]];
    }

    function menu_image_catalogue_prompt(): string
    {
        return "You are cataloguing a restaurant stock-photo library so a menu can be matched to it.\n"
            . "For each numbered entry answer:\n"
            . "  n = the entry number\n"
            . "  d = the dish shown, as a restaurant would print it on a menu (\"Paneer Butter Masala\", \"Chicken Momo\", "
            . "\"Masala Chai\"). Lower case is fine. If it is a combination plate, name the main dish.\n"
            . "  t = what it is made of: veg, paneer, egg, chicken, mutton, buff, pork, fish, prawn, or other.\n"
            . "  a = 4 to 8 other names the same dish is sold under, including Hindi in Devanagari and the usual "
            . "Hinglish/regional spellings, and the English description someone might type instead "
            . "(e.g. for kaju katli: \"kaju barfi\", \"cashew barfi\", \"काजू कतली\", \"cashew fudge\"). No duplicates of d.\n"
            . "Answer for every entry, in order. Nothing else.";
    }

    /**
     * Reads the library with Gemini and writes the catalogue. Returns a short report.
     * $log is called with progress lines. $only limits it to files not catalogued yet (the default).
     */
    function menu_image_catalogue_build(?callable $log = null, bool $onlyMissing = true, bool $vision = true, int $batch = 60): array
    {
        require_once __DIR__ . '/menu_image_matcher.php';
        $say = function ($m) use ($log) { if ($log) $log($m); };

        if (!aiGeminiAvailable()) return ['ok' => false, 'message' => 'No GEMINI_API_KEY: nothing to build with.'];

        $saved = menu_image_catalogue_load_raw();
        $named = [];      // file names that say something
        $blind = [];      // file names that say nothing: the picture has to be looked at
        $props = menu_image_prop_words();

        foreach (menu_image_library_scan() as $folder => $entries) {
            foreach ($entries as $e) {
                $key = $folder . '/' . $e['file'];
                if ($onlyMissing && isset($saved[$key]) && trim((string)($saved[$key]['d'] ?? '')) !== '') continue;
                $words = menu_image_file_words($e['file']);
                $solid = array_filter($words, function ($w) use ($props) { return !isset($props[$w]) && mb_strlen($w) > 2 && !preg_match('/^[a-z]{0,3}\d/u', $w); });
                if (count($solid) >= 2) $named[$key] = ['folder' => $folder, 'file' => $e['file']];
                else $blind[$key] = ['folder' => $folder, 'file' => $e['file']];
            }
        }
        $say('to do: ' . count($named) . ' by name, ' . count($blind) . ' by picture, ' . count($saved) . ' already done');

        $done = 0;
        foreach (array_chunk($named, $batch, true) as $chunk) {
            $ask = '';
            $keys = array_keys($chunk);
            foreach (array_values($chunk) as $i => $c) {
                $ask .= ($i + 1) . ') ' . $c['folder'] . ' / ' . preg_replace('/\.[A-Za-z0-9]+$/', '', $c['file']) . "\n";
            }
            $r = aiGeminiCall([
                'systemInstruction' => ['parts' => [['text' => menu_image_catalogue_prompt()]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => "Each entry is a folder and a photo's file name.\n\n" . $ask]]]],
                'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 16384, 'responseMimeType' => 'application/json',
                    'responseSchema' => menu_image_catalogue_schema(), 'thinkingConfig' => ['thinkingBudget' => 0]],
            ], 120);
            $got = menu_image_catalogue_absorb($r, $keys, $saved);
            $done += $got;
            $say('  named: ' . $done . '/' . count($named) . ' (+' . $got . ')');
            menu_image_catalogue_save($saved);
        }

        if ($vision && $blind) {
            $done = 0;
            foreach (array_chunk($blind, 6, true) as $chunk) {
                $parts = [['text' => "Each photo below is a dish from a restaurant stock library. They are numbered in order.\n"]];
                $keys = [];
                $n = 0;
                foreach ($chunk as $key => $c) {
                    $small = menu_image_small_jpeg(menu_image_library_root() . '/' . $key);
                    if ($small === null) continue;
                    $n++;
                    $keys[] = $key;
                    $parts[] = ['text' => "Photo $n (folder: " . $c['folder'] . "):"];
                    $parts[] = ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode($small)]];
                }
                if (!$keys) continue;
                $r = aiGeminiCall([
                    'systemInstruction' => ['parts' => [['text' => menu_image_catalogue_prompt()]]],
                    'contents' => [['role' => 'user', 'parts' => $parts]],
                    'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 4096, 'responseMimeType' => 'application/json',
                        'responseSchema' => menu_image_catalogue_schema(), 'thinkingConfig' => ['thinkingBudget' => 0]],
                ], 180);
                $got = menu_image_catalogue_absorb($r, $keys, $saved);
                $done += $got;
                $say('  pictures: ' . $done . '/' . count($blind) . ' (+' . $got . ')');
                menu_image_catalogue_save($saved);
            }
        }

        menu_image_catalogue_save($saved);
        return ['ok' => true, 'catalogued' => count($saved), 'file' => MENU_IMAGE_CATALOGUE_FILE];
    }

    /** Folds one Gemini answer into $saved, where entry n is $keys[n - 1]. Returns how many it added. */
    function menu_image_catalogue_absorb(?array $r, array $keys, array &$saved): int
    {
        if (!$r || $r['text'] === '') return 0;
        $rows = json_decode(preg_replace('/^```(?:json)?|```$/m', '', $r['text']), true);
        if (!is_array($rows)) return 0;
        $added = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $n = (int)($row['n'] ?? 0);
            if ($n < 1 || $n > count($keys)) continue;
            $dish = mb_strtolower(trim((string)($row['d'] ?? '')));
            if ($dish === '') continue;
            $key = $keys[$n - 1];
            $alias = [];
            foreach ((array)($row['a'] ?? []) as $a) {
                $a = mb_strtolower(trim((string)$a));
                if ($a !== '' && $a !== $dish && mb_strlen($a) <= 60) $alias[] = $a;
            }
            $t = (string)($row['t'] ?? '');
            $saved[$key] = ['d' => $dish, 't' => $t === 'other' ? '' : $t, 'a' => array_values(array_unique(array_slice($alias, 0, 10)))];
            $added++;
        }
        return $added;
    }

    /** A small JPEG of a library photo, for sending to Gemini. Null when GD can't read it. */
    function menu_image_small_jpeg(string $path, int $max = 512): ?string
    {
        if (!is_file($path) || !function_exists('imagecreatefromstring')) return null;
        $raw = @file_get_contents($path, false, null, 0, 24 * 1024 * 1024);
        if ($raw === false) return null;
        $im = @imagecreatefromstring($raw);
        if (!$im) return null;
        $w = imagesx($im); $h = imagesy($im);
        $scale = min(1.0, $max / max($w, $h));
        if ($scale < 1.0) {
            $small = imagescale($im, (int)round($w * $scale), (int)round($h * $scale));
            if ($small) { imagedestroy($im); $im = $small; }
        }
        ob_start();
        imagejpeg($im, null, 72);
        $out = ob_get_clean();
        imagedestroy($im);
        return $out !== false && $out !== '' ? $out : null;
    }
}
