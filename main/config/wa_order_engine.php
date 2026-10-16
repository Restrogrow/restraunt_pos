<?php
/**
 * WhatsApp ordering: customers order in a WhatsApp chat; the order lands in Orders / POS / Kitchen
 * like a website order (plan: docs/IMPORTANT_TODO.md, feature 1).
 *
 * Flow: RepeatGrow webhook (api/wa_webhook.php) → woHandle() → replies sent with waSend().
 * The owner tries it without WhatsApp in Settings › WhatsApp ordering (api/wa_order_admin.php, "Try it").
 *
 *  - Understanding the message: Gemini (config/ai_gemini.php) turns words into menu item ids and actions;
 *    without a key, or if it fails, a built-in matcher does the same for plain orders
 *    ("2 butter naan, ek paneer tikka"). Item ids are always checked against the live menu.
 *  - Money: the cart shown in chat uses menu prices + packaging + tax; the order itself goes through
 *    api/process_website_order.php (same price checks, stock, KOT, alerts, loyalty as the website),
 *    called internally with a signed header so its per-IP limits don't count the server's own IP.
 *  - Orders keep source = 'website' (every alert, report and app screen already knows it) and carry
 *    "[WhatsApp order]" in their notes; wa_order_sessions links the chat to the order for status updates.
 *  - Tables: wa_order_settings, wa_order_sessions, wa_order_log, wa_order_routes, wa_webhook_seen.
 */
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/ai_gemini.php';
require_once __DIR__ . '/whatsapp_engine.php';

if (!function_exists('woHandle')) {

    define('WO_SESSION_IDLE_HOURS', 3);

    function woEnsure(PDO $conn): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $conn->exec("CREATE TABLE IF NOT EXISTS wa_order_settings (
            restaurant_id VARCHAR(50) PRIMARY KEY,
            enabled TINYINT NOT NULL DEFAULT 0,
            order_types VARCHAR(40) NOT NULL DEFAULT 'dinein,takeaway,delivery',
            greeting VARCHAR(500) NULL,
            suggest_extras TINYINT NOT NULL DEFAULT 1,
            webhook_id VARCHAR(80) NULL,
            webhook_secret VARCHAR(300) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->exec("CREATE TABLE IF NOT EXISTS wa_order_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            name VARCHAR(100) NULL,
            state VARCHAR(20) NOT NULL DEFAULT 'browsing',
            cart TEXT NULL,
            order_type VARCHAR(20) NULL,
            table_id INT NULL,
            address VARCHAR(300) NULL,
            notes VARCHAR(300) NULL,
            order_id INT NULL,
            order_number VARCHAR(40) NULL,
            notified_status VARCHAR(30) NULL,
            handover_until DATETIME NULL,
            last_in_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_chat (restaurant_id, phone), INDEX (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->exec("CREATE TABLE IF NOT EXISTS wa_order_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            direction VARCHAR(3) NOT NULL,
            body TEXT NOT NULL,
            meta VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (restaurant_id, created_at), INDEX (restaurant_id, phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // the shared RestroGrow number: which restaurant each customer is ordering from (set by the QR's "ORDER RES… T4")
        $conn->exec("CREATE TABLE IF NOT EXISTS wa_order_routes (
            phone VARCHAR(20) PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            table_id INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->exec("CREATE TABLE IF NOT EXISTS wa_webhook_seen (
            id VARCHAR(80) PRIMARY KEY,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->exec("CREATE TABLE IF NOT EXISTS wa_contact_phones (
            contact_id VARCHAR(80) PRIMARY KEY,
            phone VARCHAR(20) NOT NULL,
            name VARCHAR(100) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    function woSettings(PDO $conn, string $rid): array
    {
        woEnsure($conn);
        $st = $conn->prepare('SELECT * FROM wa_order_settings WHERE restaurant_id = ?');
        $st->execute([$rid]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $u = $conn->prepare('SELECT restaurant_name, username, phone, enable_dinein, enable_takeaway, enable_delivery, enable_gst, tax_name, tax_percent,
                packaging_charge, minimum_order_value, currency_symbol, custom_domain FROM users WHERE restaurant_id = ? LIMIT 1');
        $u->execute([$rid]);
        $rest = $u->fetch(PDO::FETCH_ASSOC) ?: [];
        $types = array_values(array_filter(explode(',', (string)($r['order_types'] ?? 'dinein,takeaway,delivery'))));
        $allowed = [];
        foreach (['dinein' => 'enable_dinein', 'takeaway' => 'enable_takeaway', 'delivery' => 'enable_delivery'] as $t => $col) {
            if (in_array($t, $types, true) && (int)($rest[$col] ?? 1) === 1) $allowed[] = $t;
        }
        return [
            'enabled' => (int)($r['enabled'] ?? 0) === 1,
            'order_types' => $types,
            'allowed_types' => $allowed,
            'greeting' => (string)($r['greeting'] ?? ''),
            'suggest_extras' => (int)($r['suggest_extras'] ?? 1) === 1,
            'webhook_id' => (string)($r['webhook_id'] ?? ''),
            'webhook_secret' => isset($r['webhook_secret']) && $r['webhook_secret'] !== '' ? waKeyOpen((string)$r['webhook_secret']) : '',
            'restaurant' => $rest,
            'currency' => (string)($rest['currency_symbol'] ?? '₹') ?: '₹',
        ];
    }

    function woSaveSettings(PDO $conn, string $rid, array $in): void
    {
        woEnsure($conn);
        $types = array_values(array_intersect(['dinein', 'takeaway', 'delivery'], (array)($in['order_types'] ?? [])));
        $conn->prepare('INSERT INTO wa_order_settings (restaurant_id, enabled, order_types, greeting, suggest_extras) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), order_types = VALUES(order_types), greeting = VALUES(greeting), suggest_extras = VALUES(suggest_extras)')
            ->execute([$rid, empty($in['enabled']) ? 0 : 1, implode(',', $types ?: ['takeaway']), mb_substr(trim((string)($in['greeting'] ?? '')), 0, 500) ?: null,
                empty($in['suggest_extras']) ? 0 : 1]);
    }

    // ── the menu, as the chat sees it ──
    function woMenu(PDO $conn, string $rid): array
    {
        static $cache = [];
        if (isset($cache[$rid])) return $cache[$rid];
        // categories with serving hours (e.g. breakfast 8–11:30) are left out while they are closed, like on the website
        $closed = [];
        try { require_once __DIR__ . '/menu_hours.php'; $closed = menuHoursClosedMap($conn, $rid); } catch (Throwable $e) { error_log('woMenu hours: ' . $e->getMessage()); }
        $st = $conn->prepare("SELECT mi.id, mi.menu_id, mi.item_name_en name, mi.base_price price, mi.item_type type, COALESCE(m.menu_name, mi.item_category, '') category, mi.has_variations
            FROM menu_items mi LEFT JOIN menu m ON m.id = mi.menu_id
            WHERE mi.restaurant_id = ? AND mi.is_available = 1 AND COALESCE(mi.is_hidden, 0) = 0
            ORDER BY m.sort_order, m.menu_name, mi.sort_order, mi.item_name_en");
        $st->execute([$rid]);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($closed[(int)$r['menu_id']])) continue;
            $items[(int)$r['id']] = ['id' => (int)$r['id'], 'name' => trim((string)$r['name']), 'price' => (float)$r['price'], 'type' => (string)$r['type'],
                'category' => trim((string)$r['category']), 'variations' => []];
        }
        if ($items) {
            $ids = implode(',', array_keys($items));
            foreach ($conn->query("SELECT menu_item_id, variation_name, price FROM menu_item_variations WHERE menu_item_id IN ($ids) ORDER BY price") as $v) {
                $items[(int)$v['menu_item_id']]['variations'][] = ['name' => (string)$v['variation_name'], 'price' => (float)$v['price']];
            }
            // Offers › Bestsellers: the same offer prices the website order code charges
            try {
                require_once __DIR__ . '/bestseller_helpers.php';
                $map = getBestsellerMap($conn, $rid);
                if ($map) {
                    foreach ($items as $id => &$it) {
                        $o = bestsellerOfferPrice($map, $id, $it['price'], !empty($it['variations']));
                        if ($o !== null && $o < $it['price']) { $it['was'] = $it['price']; $it['price'] = (float)$o; }
                        foreach ($it['variations'] as &$v) {
                            $vo = bestsellerVariationOfferPrice($map, $id, $v['name'], $v['price']);
                            if ($vo !== null && $vo < $v['price']) { $v['was'] = $v['price']; $v['price'] = (float)$vo; }
                        }
                        unset($v);
                    }
                    unset($it);
                }
            } catch (Throwable $e) { error_log('woMenu offers: ' . $e->getMessage()); }
        }
        return $cache[$rid] = $items;
    }

    function woNorm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    define('WO_NUMBER_WORDS', ['ek' => 1, 'one' => 1, 'a' => 1, 'an' => 1, 'do' => 2, 'two' => 2, 'teen' => 3, 'three' => 3, 'char' => 4, 'chaar' => 4, 'four' => 4,
        'paanch' => 5, 'panch' => 5, 'five' => 5, 'che' => 6, 'chhe' => 6, 'six' => 6, 'saat' => 7, 'seven' => 7, 'aath' => 8, 'eight' => 8, 'nau' => 9, 'nine' => 9,
        'das' => 10, 'ten' => 10, 'एक' => 1, 'दो' => 2, 'तीन' => 3, 'चार' => 4, 'पांच' => 5, 'पाँच' => 5]);

    /** How well a typed phrase matches a dish name (0–1). */
    function woScore(string $phrase, string $name): float
    {
        $p = woNorm($phrase);
        $n = woNorm($name);
        if ($p === '' || $n === '') return 0;
        if ($p === $n) return 1;
        if (strpos($n, $p) !== false || strpos($p, $n) !== false) return 0.92 * min(mb_strlen($p), mb_strlen($n)) / max(mb_strlen($p), mb_strlen($n)) + 0.08;
        $pw = explode(' ', $p);
        $nw = explode(' ', $n);
        $hit = 0;
        foreach ($pw as $w) {
            if (mb_strlen($w) < 2) continue;
            foreach ($nw as $x) {
                if ($w === $x || (mb_strlen($w) >= 4 && levenshtein($w, $x) <= (mb_strlen($w) >= 7 ? 2 : 1))) { $hit++; break; }
            }
        }
        $wordScore = $hit / max(count($nw), count($pw));
        similar_text($p, $n, $pct);
        return max($wordScore * 0.95, $pct / 100 * 0.85);
    }

    /** Best matches for a phrase: [[item, variation|null, score], …] */
    function woMatch(array $menu, string $phrase, int $limit = 3): array
    {
        $out = [];
        foreach ($menu as $it) {
            $s = woScore($phrase, $it['name']);
            $var = null;
            foreach ($it['variations'] as $v) {
                $vs = woScore($phrase, $it['name'] . ' ' . $v['name']);
                if ($vs > $s + 0.02) { $s = $vs; $var = $v['name']; }
            }
            if ($s >= 0.35) $out[] = [$it, $var, $s];
        }
        usort($out, fn($a, $b) => $b[2] <=> $a[2]);
        return array_slice($out, 0, $limit);
    }

    /** The built-in understanding: "2 butter naan, ek paneer tikka aur 1 lassi" → add actions (+ phrases it couldn't place). */
    function woRuleParse(array $menu, string $text): array
    {
        $t = mb_strtolower($text);
        $t = preg_replace('/\b(please|pls|plz|bhai|bhaiya|i want|i would like|give me|send|chahiye|de do|dena|order|mujhe|mere liye|also|and also)\b/u', ' ', $t);
        $chunks = preg_split('/\s*(?:,|\n|;|\+|\band\b|\baur\b|\bor\b|&|और)\s*/u', $t);
        $actions = [];
        $unknown = [];
        foreach ($chunks as $c) {
            $c = trim($c);
            if ($c === '') continue;
            $qty = 1;
            if (preg_match('/^(\d{1,2})\s*(?:x|×|pcs?|plates?|nos?)?\s+(.+)$/u', $c, $m)) { $qty = (int)$m[1]; $c = $m[2]; }
            elseif (preg_match('/^(.+?)\s*(?:x|×)\s*(\d{1,2})$/u', $c, $m)) { $c = $m[1]; $qty = (int)$m[2]; }
            elseif (preg_match('/^(.+?)\s+(\d{1,2})$/u', $c, $m)) { $c = $m[1]; $qty = (int)$m[2]; }
            else {
                $w = explode(' ', $c, 2);
                if (isset(WO_NUMBER_WORDS[$w[0]]) && isset($w[1])) { $qty = WO_NUMBER_WORDS[$w[0]]; $c = $w[1]; }
            }
            $c = trim(preg_replace('/\b(plate|plates|pcs|piece|pieces)\b/u', '', $c));
            $best = woMatch($menu, $c, 1);
            if ($best && $best[0][2] >= 0.55) $actions[] = ['type' => 'add', 'item_id' => $best[0][0]['id'], 'variation' => $best[0][1], 'qty' => max(1, min(50, $qty))];
            elseif (mb_strlen($c) >= 3) $unknown[] = $c;
        }
        return [$actions, $unknown];
    }

    /** Gemini: the message → actions. Returns null when the AI isn't available (caller falls back). */
    function woAiParse(PDO $conn, string $rid, array $menu, array $sess, string $text, array $history): ?array
    {
        if (!aiGeminiAvailable() || !$menu) return null;
        $lines = [];
        foreach ($menu as $it) {
            $v = $it['variations'] ? ' | sizes: ' . implode(', ', array_map(fn($x) => $x['name'] . ' ' . $x['price'], $it['variations'])) : '';
            $lines[] = $it['id'] . ' | ' . $it['name'] . ' | ' . $it['price'] . ' | ' . $it['category'] . $v;
        }
        $cart = array_map(fn($l) => $l['qty'] . ' x ' . $l['name'] . ($l['variation'] ? ' (' . $l['variation'] . ')' : '') . ' [id ' . $l['id'] . ']', $sess['cart']);
        $system = "You take restaurant orders on WhatsApp. Customers write in English, Hindi or Hinglish (e.g. 'do butter naan aur ek paneer tikka', 'kam spicy').\n"
            . "Return ONLY actions. Use item_id values that exist in MENU (id | name | price | category | sizes). Never invent dishes or prices.\n"
            . "Actions: add (item_id, qty, variation = exact size name or empty), remove (item_id), set_qty (item_id, qty), clear (empty the cart), "
            . "note (value = cooking instruction like 'less spicy', attach to item_id if it is about one dish), show_menu (value = category or empty), show_cart, "
            . "checkout (customer is done / wants to place the order / says confirm, yes, haan, done, bas), order_type (value = dinein|takeaway|delivery), "
            . "table (value = table number), name (value = the customer's name), address (value = delivery address), status (asks where their order is), "
            . "human (wants to talk to a person / complaint), greeting (just hello), unknown (value = the words you could not match to the menu).\n"
            . "If a dish has sizes and the customer did not say one, use variation = '' (the app will ask). If unsure between dishes, use unknown with the phrase.\n"
            . "Also write a short friendly reply_hint (one sentence, same language as the customer) only for questions about the food (spicy? veg?), else empty.";
        $user = "MENU:\n" . implode("\n", $lines) . "\n\nCART NOW:\n" . ($cart ? implode("\n", $cart) : '(empty)') . "\n\nCUSTOMER SAYS:\n" . $text;
        $schema = ['type' => 'OBJECT', 'properties' => [
            'actions' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => [
                'type' => ['type' => 'STRING', 'enum' => ['add', 'remove', 'set_qty', 'clear', 'note', 'show_menu', 'show_cart', 'checkout', 'order_type', 'table', 'name', 'address', 'status', 'human', 'greeting', 'unknown']],
                'item_id' => ['type' => 'INTEGER'], 'qty' => ['type' => 'INTEGER'], 'variation' => ['type' => 'STRING'], 'value' => ['type' => 'STRING'],
            ], 'required' => ['type']]],
            'reply_hint' => ['type' => 'STRING'],
        ], 'required' => ['actions']];
        $r = aiGeminiJson($system, $user, $schema, $history, $conn, $rid);
        if (!$r || !isset($r['actions']) || !is_array($r['actions'])) return null;
        $actions = [];
        foreach ($r['actions'] as $a) {
            $type = (string)($a['type'] ?? '');
            if (in_array($type, ['add', 'remove', 'set_qty'], true)) {
                $id = (int)($a['item_id'] ?? 0);
                if (!isset($menu[$id])) { $actions[] = ['type' => 'unknown', 'value' => (string)($a['value'] ?? '')]; continue; }   // never trust an id the menu doesn't have
                $var = trim((string)($a['variation'] ?? ''));
                if ($var !== '' && !in_array($var, array_column($menu[$id]['variations'], 'name'), true)) {
                    $m = woMatch([$menu[$id]], $menu[$id]['name'] . ' ' . $var, 1);
                    $var = $m && $m[0][1] ? $m[0][1] : '';
                }
                $actions[] = ['type' => $type, 'item_id' => $id, 'variation' => $var !== '' ? $var : null, 'qty' => max(0, min(50, (int)($a['qty'] ?? 1)))];
            } else {
                $actions[] = ['type' => $type, 'item_id' => (int)($a['item_id'] ?? 0), 'value' => trim((string)($a['value'] ?? ''))];
            }
        }
        return ['actions' => $actions, 'hint' => trim((string)($r['reply_hint'] ?? ''))];
    }

    // ── session ──
    function woSession(PDO $conn, string $rid, string $phone): array
    {
        woEnsure($conn);
        $st = $conn->prepare('SELECT * FROM wa_order_sessions WHERE restaurant_id = ? AND phone = ?');
        $st->execute([$rid, $phone]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            $conn->prepare('INSERT INTO wa_order_sessions (restaurant_id, phone, cart) VALUES (?, ?, ?)')->execute([$rid, $phone, '[]']);
            $st->execute([$rid, $phone]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            $r['_new'] = true;
        }
        $r['cart'] = json_decode((string)$r['cart'], true) ?: [];
        // a placed order, or a chat quiet for a few hours: the next message starts a new order
        $idle = $r['last_in_at'] && strtotime($r['last_in_at']) < time() - WO_SESSION_IDLE_HOURS * 3600;
        if ($idle && $r['state'] !== 'placed') { $r['cart'] = []; $r['state'] = 'browsing'; $r['order_type'] = null; $r['_new'] = true; }
        return $r;
    }

    function woSave(PDO $conn, array $s): void
    {
        $conn->prepare('UPDATE wa_order_sessions SET name = ?, state = ?, cart = ?, order_type = ?, table_id = ?, address = ?, notes = ?, order_id = ?, order_number = ?,
                handover_until = ?, last_in_at = NOW() WHERE id = ?')
            ->execute([$s['name'] ?: null, $s['state'], json_encode(array_values($s['cart']), JSON_UNESCAPED_UNICODE), $s['order_type'] ?: null, $s['table_id'] ?: null,
                $s['address'] ?: null, $s['notes'] ?: null, $s['order_id'] ?: null, $s['order_number'] ?: null, $s['handover_until'] ?: null, $s['id']]);
    }

    function woLog(PDO $conn, string $rid, string $phone, string $dir, string $body, string $meta = ''): void
    {
        try {
            $conn->prepare('INSERT INTO wa_order_log (restaurant_id, phone, direction, body, meta) VALUES (?, ?, ?, ?, ?)')
                ->execute([$rid, $phone, $dir, mb_substr($body, 0, 4000), $meta !== '' ? mb_substr($meta, 0, 500) : null]);
        } catch (Throwable $e) { error_log('woLog: ' . $e->getMessage()); }
    }

    function woHistory(PDO $conn, string $rid, string $phone): array
    {
        $st = $conn->prepare('SELECT direction, body FROM wa_order_log WHERE restaurant_id = ? AND phone = ? AND created_at > NOW() - INTERVAL 3 HOUR ORDER BY id DESC LIMIT 8');
        $st->execute([$rid, $phone]);
        return array_map(fn($r) => ['role' => $r['direction'] === 'in' ? 'user' : 'model', 'text' => $r['body']], array_reverse($st->fetchAll(PDO::FETCH_ASSOC)));
    }

    // ── cart ──
    function woUnitPrice(array $item, ?string $var): float
    {
        if ($var) foreach ($item['variations'] as $v) if ($v['name'] === $var) return (float)$v['price'];
        return (float)$item['price'];
    }

    function woAdd(array &$cart, array $item, ?string $var, int $qty): void
    {
        foreach ($cart as &$l) {
            if ($l['id'] === $item['id'] && ($l['variation'] ?? null) === $var) { $l['qty'] = min(50, $l['qty'] + $qty); return; }
        }
        unset($l);
        $cart[] = ['id' => $item['id'], 'name' => $item['name'], 'variation' => $var, 'price' => woUnitPrice($item, $var), 'qty' => $qty, 'note' => ''];
    }

    function woTotals(array $cfg, array $cart, string $orderType = ''): array
    {
        $r = $cfg['restaurant'];
        $sub = 0;
        foreach ($cart as $l) $sub += $l['price'] * $l['qty'];
        $sub = round($sub, 2);
        // same as api/process_website_order.php: packing on every order, tax on the dishes only
        $pack = (float)($r['packaging_charge'] ?? 0);
        $rate = (int)($r['enable_gst'] ?? 0) === 1 ? (float)($r['tax_percent'] ?? 5) : 0;
        $tax = round($sub * $rate / 100, 2);
        return ['subtotal' => $sub, 'packaging' => $pack, 'tax' => $tax, 'tax_name' => (string)($r['tax_name'] ?: 'GST'), 'tax_rate' => $rate, 'total' => round($sub + $pack + $tax, 2)];
    }

    function woMoney(array $cfg, float $n): string
    {
        return $cfg['currency'] . (fmod($n, 1) ? number_format($n, 2) : number_format($n));
    }

    function woCartText(array $cfg, array $s): string
    {
        if (!$s['cart']) return 'Your order is empty.';
        $t = woTotals($cfg, $s['cart'], (string)$s['order_type']);
        $lines = ['🧾 *Your order*'];
        foreach ($s['cart'] as $l) {
            $lines[] = $l['qty'] . ' × ' . $l['name'] . ($l['variation'] ? ' (' . $l['variation'] . ')' : '') . ' — ' . woMoney($cfg, $l['price'] * $l['qty']) . ($l['note'] ? "\n   _" . $l['note'] . '_' : '');
        }
        $lines[] = '';
        if ($t['packaging'] > 0 || $t['tax'] > 0) $lines[] = 'Items: ' . woMoney($cfg, $t['subtotal']);
        if ($t['packaging'] > 0) $lines[] = 'Packing: ' . woMoney($cfg, $t['packaging']);
        if ($t['tax'] > 0) $lines[] = $t['tax_name'] . ' ' . rtrim(rtrim(number_format($t['tax_rate'], 2), '0'), '.') . '%: ' . woMoney($cfg, $t['tax']);
        $lines[] = '*Total: ' . woMoney($cfg, $t['total']) . '*';
        return implode("\n", $lines);
    }

    /** One extra worth offering: what is most often ordered with the cart's dishes (else a popular drink or dessert). */
    function woSuggest(PDO $conn, string $rid, array $menu, array $cart): ?array
    {
        if (!$cart) return null;
        $in = array_map(fn($l) => (int)$l['id'], $cart);
        try {
            $ph = implode(',', array_fill(0, count($in), '?'));
            $st = $conn->prepare("SELECT b.menu_item_id id, COUNT(*) n FROM order_items a JOIN order_items b ON b.order_id = a.order_id AND b.menu_item_id <> a.menu_item_id
                JOIN orders o ON o.id = a.order_id WHERE o.restaurant_id = ? AND o.created_at > NOW() - INTERVAL 60 DAY AND a.menu_item_id IN ($ph) AND b.menu_item_id NOT IN ($ph)
                GROUP BY b.menu_item_id ORDER BY n DESC LIMIT 5");
            $st->execute(array_merge([$rid], $in, $in));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($menu[(int)$r['id']])) return $menu[(int)$r['id']];
        } catch (Throwable $e) { error_log('woSuggest: ' . $e->getMessage()); }
        foreach ($menu as $it) {
            if (!in_array($it['id'], $in, true) && preg_match('/drink|beverage|dessert|sweet|lassi|chaas|shake|juice/i', $it['category'] . ' ' . $it['name'])) return $it;
        }
        return null;
    }

    function woMenuText(array $cfg, array $menu, string $cat = ''): string
    {
        $by = [];
        foreach ($menu as $it) $by[$it['category'] ?: 'Menu'][] = $it;
        $catKey = '';
        if ($cat !== '') foreach (array_keys($by) as $k) if (woScore($cat, $k) >= 0.6) { $catKey = $k; break; }
        if ($catKey !== '') {
            $lines = ['📋 *' . $catKey . '*'];
            foreach (array_slice($by[$catKey], 0, 25) as $it) {
                $p = $it['variations'] ? $it['variations'][0] : $it;
                $lines[] = '• ' . $it['name'] . ' — ' . woMoney($cfg, $p['price']) . (isset($p['was']) ? ' ~' . woMoney($cfg, $p['was']) . '~ 🔥' : '');
            }
            $lines[] = "\nSend the dish names to order, e.g. *2 " . $by[$catKey][0]['name'] . '*';
            return implode("\n", $lines);
        }
        $lines = ['📋 *Menu*'];
        foreach ($by as $k => $items) {
            $lines[] = '*' . $k . '*: ' . implode(', ', array_map(fn($i) => $i['name'], array_slice($items, 0, 4))) . (count($items) > 4 ? ' +' . (count($items) - 4) . ' more' : '');
        }
        $lines[] = "\nSend a category for prices (e.g. *" . array_key_first($by) . "*), or just type what you want.";
        $url = woMenuUrl($cfg);
        if ($url) $lines[] = 'Full menu with photos: ' . $url;
        return implode("\n", $lines);
    }

    function woMenuUrl(array $cfg): string
    {
        $r = $cfg['restaurant'];
        if (!empty($r['custom_domain'])) return 'https://' . $r['custom_domain'];
        return !empty($r['username']) ? 'https://restrogrow.com/' . rawurlencode((string)$r['username']) : '';
    }

    function woTypeLabel(string $t): string
    {
        return ['dinein' => 'Dine-in', 'takeaway' => 'Takeaway', 'delivery' => 'Delivery'][$t] ?? 'Takeaway';
    }

    function woTableId(PDO $conn, string $rid, string $value): ?int
    {
        $v = trim($value);
        if ($v === '') return null;
        $st = $conn->prepare("SELECT id FROM tables WHERE restaurant_id = ? AND (LOWER(table_number) = LOWER(?) OR LOWER(table_number) = LOWER(CONCAT('T', ?))) LIMIT 1");
        $st->execute([$rid, $v, $v]);
        $id = $st->fetchColumn();
        return $id ? (int)$id : null;
    }

    function woTableName(PDO $conn, int $tableId): string
    {
        $st = $conn->prepare('SELECT table_number FROM tables WHERE id = ?');
        $st->execute([$tableId]);
        return (string)$st->fetchColumn();
    }

    function woIsYes(string $t): bool
    {
        return (bool)preg_match('/^\s*(1|yes|y|ok|okay|confirm|confirmed|place|place order|done|haan|han|ha|haa|ji|ji haan|theek hai|thik hai|sahi hai|bas|that\'?s all|go ahead|हाँ|हां)\s*[.!]*\s*$/iu', $t);
    }

    /** Customer's name: from the chat, RepeatGrow, or an earlier order. */
    function woKnownName(PDO $conn, string $rid, string $phone): string
    {
        $st = $conn->prepare("SELECT customer_name FROM customers WHERE restaurant_id = ? AND phone = ? AND customer_name <> '' LIMIT 1");
        $st->execute([$rid, $phone]);
        return trim((string)$st->fetchColumn());
    }

    /**
     * One incoming message → the replies to send (strings, in order). Side effects: the chat session,
     * the log, and on confirm a real order (unless $opt['simulate']: then the order is only described).
     * $opt: name (from WhatsApp), simulate (bool), table (from a QR route), force (the owner's test chat, even when switched off).
     */
    function woHandle(PDO $conn, string $rid, string $phone, string $text, array $opt = []): array
    {
        $cfg = woSettings($conn, $rid);
        if (!$cfg['enabled'] && empty($opt['simulate']) && empty($opt['force'])) return [];   // force: the owner's test chat
        $text = trim(mb_substr($text, 0, 1000));
        $s = woSession($conn, $rid, $phone);
        $menu = woMenu($conn, $rid);
        $rest = $cfg['restaurant'];
        $history = woHistory($conn, $rid, $phone);
        woLog($conn, $rid, $phone, 'in', $text);
        if (!$s['name']) $s['name'] = trim((string)($opt['name'] ?? '')) ?: woKnownName($conn, $rid, $phone);
        if (!empty($opt['table'])) { $s['table_id'] = (int)$opt['table']; $s['order_type'] = 'dinein'; }
        $out = [];
        $say = function (string $m) use (&$out) { if ($m !== '') $out[] = $m; };

        // talking to a person: stay quiet until they say "order" or the time runs out
        if ($s['handover_until'] && strtotime($s['handover_until']) > time() && !preg_match('/\b(order|menu|bot)\b/i', $text)) {
            woSave($conn, $s);
            return [];
        }
        $s['handover_until'] = null;

        // after an order: a new message starts the next one (status questions are answered first)
        if ($s['state'] === 'placed') {
            if (preg_match('/\b(status|where|kahan|kab|kitna time|how long|ready)\b/iu', $text) && $s['order_id']) {
                $say(woStatusText($conn, (int)$s['order_id'], $cfg));
                woSave($conn, $s);
                return woOut($conn, $rid, $phone, $out);
            }
            $s['state'] = 'browsing'; $s['cart'] = []; $s['order_type'] = $s['table_id'] ? 'dinein' : null; $s['_new'] = true;
        }

        $norm = woNorm($text);
        // table from the message ("table 4", "T4")
        if (preg_match('/\b(?:table|tbl|टेबल)\s*(?:no\.?|number)?\s*#?\s*([a-z]?\d{1,3})\b/iu', $text, $tm) && ($tid = woTableId($conn, $rid, $tm[1]))) {
            $s['table_id'] = $tid; $s['order_type'] = 'dinein';
        }

        // ── waiting for something specific ──
        if ($s['state'] === 'ask_type') {
            $pick = null;
            if (preg_match('/^\s*(t|take ?away|pick ?up|parcel|1)\s*$/iu', $text)) $pick = 'takeaway';
            elseif (preg_match('/^\s*(d|delivery|deliver|home|ghar|2)\s*$/iu', $text)) $pick = 'delivery';
            elseif (preg_match('/^\s*(dine|dine ?in|here|table|3)\s*$/iu', $text)) $pick = 'dinein';
            if ($pick && in_array($pick, $cfg['allowed_types'], true)) {
                $s['order_type'] = $pick; $s['state'] = 'browsing';
                return woCheckout($conn, $rid, $phone, $s, $cfg, $menu, $opt, $out);
            }
            $s['state'] = 'browsing';   // something else: treat it as a normal message
        } elseif ($s['state'] === 'ask_address') {
            if (mb_strlen($text) >= 10 && !woIsYes($text)) {
                $s['address'] = mb_substr($text, 0, 300); $s['state'] = 'browsing';
                return woCheckout($conn, $rid, $phone, $s, $cfg, $menu, $opt, $out);
            }
            $say('Please send your full delivery address (house, street, area, landmark).');
            woSave($conn, $s);
            return woOut($conn, $rid, $phone, $out);
        } elseif ($s['state'] === 'ask_name') {
            if (mb_strlen($text) >= 2 && mb_strlen($text) <= 60 && !preg_match('/\d{4,}/', $text)) {
                $s['name'] = mb_convert_case(trim(preg_replace('/^(my name is|i am|main|mera naam|naam)\s+/iu', '', $text)), MB_CASE_TITLE); $s['state'] = 'browsing';
                return woCheckout($conn, $rid, $phone, $s, $cfg, $menu, $opt, $out);
            }
            $say('What name should we put on the order?');
            woSave($conn, $s);
            return woOut($conn, $rid, $phone, $out);
        } elseif ($s['state'] === 'confirm') {
            if (woIsYes($text)) return woPlace($conn, $rid, $phone, $s, $cfg, $opt, $out);
            if (preg_match('/^\s*(2|change|edit|badlo|modify|no|nahi|nahin)\s*$/iu', $text)) {
                $s['state'] = 'browsing';
                $say(woCartText($cfg, $s) . "\n\nTell me what to change, e.g. *remove lassi* or *3 naan*.");
                woSave($conn, $s);
                return woOut($conn, $rid, $phone, $out);
            }
            $s['state'] = 'browsing';
        }

        // ── quick words that need no AI ──
        if (preg_match('/^(hi|hii+|hello|hey|namaste|namaskar|hlo|start|order)\b[\s!.]*$/iu', $text) || ($s['_new'] ?? false) && $norm === '') {
            $say(woGreeting($conn, $cfg, $s));
            woSave($conn, $s);
            return woOut($conn, $rid, $phone, $out);
        }
        if (preg_match('/^(menu|card|list|what do you have|kya hai|kya kya hai)\s*$/iu', $text)) {
            $say(woMenuText($cfg, $menu));
            woSave($conn, $s);
            return woOut($conn, $rid, $phone, $out);
        }
        if (preg_match('/^(cart|bill|my order|order dikhao|total)\s*$/iu', $text)) {
            $say(woCartText($cfg, $s) . ($s['cart'] ? "\n\nReply *1* to place the order, or send more dishes." : "\n\nSend a dish name to start, or *menu*."));
            woSave($conn, $s);
            return woOut($conn, $rid, $phone, $out);
        }
        if (preg_match('/^(cancel|clear|reset|start over|empty|cancel order)\s*$/iu', $text)) {
            $s['cart'] = []; $s['state'] = 'browsing';
            $say('Cleared. Send dish names whenever you are ready, or *menu* to see the menu.');
            woSave($conn, $s);
            return woOut($conn, $rid, $phone, $out);
        }
        if (preg_match('/\b(human|person|manager|owner|staff|call me|talk to|baat karni|complaint|shikayat)\b/iu', $text)) {
            $s['handover_until'] = date('Y-m-d H:i:s', time() + 2 * 3600);
            $say('Sure, someone from ' . ($rest['restaurant_name'] ?? 'the restaurant') . ' will reply here shortly.' . (!empty($rest['phone']) ? ' You can also call ' . $rest['phone'] . '.' : '') . "\n(Type *order* any time to order again.)");
            woSave($conn, $s);
            woLog($conn, $rid, $phone, 'sys', 'Handed over to the restaurant', 'handover');
            return woOut($conn, $rid, $phone, $out);
        }
        if (woIsYes($text) && $s['cart']) return woCheckout($conn, $rid, $phone, $s, $cfg, $menu, $opt, $out);

        // ── understand the message ──
        $ai = woAiParse($conn, $rid, $menu, $s, $text, $history);
        $unknown = [];
        if ($ai) {
            $actions = $ai['actions'];
            foreach ($actions as $a) if ($a['type'] === 'unknown' && ($a['value'] ?? '') !== '') $unknown[] = $a['value'];
        } else {
            [$actions, $unknown] = woRuleParse($menu, $text);
            if (!$actions && preg_match('/^(remove|delete|hatao|nikalo|no)\s+(.+)$/iu', $text, $rm)) {
                $m = woMatch($menu, $rm[2], 1);
                if ($m) { $actions[] = ['type' => 'remove', 'item_id' => $m[0][0]['id']]; $unknown = []; }
            }
        }

        $changed = false;
        $needSize = [];
        $wantCheckout = false;
        foreach ($actions as $a) {
            $item = isset($a['item_id']) ? ($menu[$a['item_id']] ?? null) : null;
            switch ($a['type']) {
                case 'add':
                    if (!$item) break;
                    if ($item['variations'] && empty($a['variation'])) { $needSize[] = [$item, max(1, (int)$a['qty'])]; break; }
                    woAdd($s['cart'], $item, $a['variation'] ?? null, max(1, (int)$a['qty']));
                    $changed = true;
                    break;
                case 'remove':
                    $before = count($s['cart']);
                    $s['cart'] = array_values(array_filter($s['cart'], fn($l) => $l['id'] !== ($item['id'] ?? -1)));
                    $changed = $changed || count($s['cart']) !== $before;
                    break;
                case 'set_qty':
                    foreach ($s['cart'] as $k => $l) if ($item && $l['id'] === $item['id']) { if ($a['qty'] <= 0) unset($s['cart'][$k]); else $s['cart'][$k]['qty'] = $a['qty']; $changed = true; }
                    $s['cart'] = array_values($s['cart']);
                    if ($item && !$changed && $a['qty'] > 0) { woAdd($s['cart'], $item, $a['variation'] ?? null, $a['qty']); $changed = true; }
                    break;
                case 'clear':
                    $s['cart'] = []; $changed = true;
                    break;
                case 'note':
                    $done = false;
                    if ($item) foreach ($s['cart'] as &$l) if ($l['id'] === $item['id']) { $l['note'] = mb_substr(trim($l['note'] . ' ' . $a['value']), 0, 120); $done = true; }
                    unset($l);
                    if (!$done) $s['notes'] = mb_substr(trim(($s['notes'] ?? '') . ' ' . $a['value']), 0, 300);
                    $changed = true;
                    break;
                case 'show_menu':
                    $say(woMenuText($cfg, $menu, (string)($a['value'] ?? '')));
                    break;
                case 'show_cart':
                    $say(woCartText($cfg, $s));
                    break;
                case 'checkout':
                    $wantCheckout = true;
                    break;
                case 'order_type':
                    if (in_array($a['value'], $cfg['allowed_types'], true)) $s['order_type'] = $a['value'];
                    break;
                case 'table':
                    if ($tid = woTableId($conn, $rid, (string)$a['value'])) { $s['table_id'] = $tid; $s['order_type'] = 'dinein'; }
                    break;
                case 'name':
                    if (mb_strlen($a['value']) >= 2) $s['name'] = mb_substr($a['value'], 0, 60);
                    break;
                case 'address':
                    if (mb_strlen($a['value']) >= 10) { $s['address'] = mb_substr($a['value'], 0, 300); $s['order_type'] = $s['order_type'] ?: 'delivery'; }
                    break;
                case 'status':
                    $last = $conn->prepare('SELECT order_id FROM wa_order_sessions WHERE restaurant_id = ? AND phone = ? AND order_id IS NOT NULL');
                    $last->execute([$rid, $phone]);
                    $oid = (int)$last->fetchColumn();
                    $say($oid ? woStatusText($conn, $oid, $cfg) : "I don't see an order from this number yet.");
                    break;
                case 'human':
                    $s['handover_until'] = date('Y-m-d H:i:s', time() + 2 * 3600);
                    $say('Sure, someone from the restaurant will reply here shortly.' . (!empty($rest['phone']) ? ' You can also call ' . $rest['phone'] . '.' : ''));
                    woLog($conn, $rid, $phone, 'sys', 'Handed over to the restaurant', 'handover');
                    break;
                case 'greeting':
                    if (!$s['cart']) $say(woGreeting($conn, $cfg, $s));
                    break;
            }
        }
        if ($ai && $ai['hint'] !== '') $say($ai['hint']);

        foreach ($needSize as [$item, $qty]) {
            $opts = array_map(fn($v) => $v['name'] . ' ' . woMoney($cfg, $v['price']), $item['variations']);
            $say('Which size for *' . $item['name'] . '*' . ($qty > 1 ? ' (×' . $qty . ')' : '') . '? ' . implode(' / ', $opts) . "\nReply e.g. *" . $qty . ' ' . $item['name'] . ' ' . $item['variations'][0]['name'] . '*');
        }
        if ($unknown) {
            $tips = [];
            foreach (array_slice($unknown, 0, 3) as $u) {
                $m = woMatch($menu, $u, 3);
                $tips[] = '“' . $u . '”' . ($m ? ': did you mean ' . implode(', ', array_map(fn($x) => '*' . $x[0]['name'] . '*', $m)) . '?' : ' is not on the menu.');
            }
            $say("I couldn't find " . implode("\n", $tips) . "\nType *menu* to see everything.");
        }

        if ($wantCheckout && $s['cart'] && !$needSize) return woCheckout($conn, $rid, $phone, $s, $cfg, $menu, $opt, $out);

        if ($changed) {
            $msg = woCartText($cfg, $s);
            if ($s['cart']) {
                $extra = $cfg['suggest_extras'] ? woSuggest($conn, $rid, $menu, $s['cart']) : null;
                if ($extra) $msg .= "\n\n💡 Add *" . $extra['name'] . '* for ' . woMoney($cfg, $extra['variations'] ? $extra['variations'][0]['price'] : $extra['price']) . '? Just reply *add ' . $extra['name'] . '*';
                $msg .= "\n\nReply *1* to place the order, or send more dishes.";
            }
            $say($msg);
        } elseif (!$out) {
            $say($s['cart'] ? "Sorry, I didn't get that. Send dish names to add, *bill* to see your order, or *1* to place it."
                : woGreeting($conn, $cfg, $s));
        }
        woSave($conn, $s);
        return woOut($conn, $rid, $phone, $out);
    }

    function woGreeting(PDO $conn, array $cfg, array $s): string
    {
        $r = $cfg['restaurant'];
        $name = $s['name'] ? ' ' . explode(' ', $s['name'])[0] : '';
        $g = $cfg['greeting'] !== '' ? $cfg['greeting'] : 'Welcome to *' . ($r['restaurant_name'] ?? 'our restaurant') . '*!';
        $t = $s['table_id'] ? "\nYou're at table *" . woTableName($conn, (int)$s['table_id']) . '*.' : '';
        return '👋 Hi' . $name . '! ' . $g . $t . "\n\nJust type what you'd like, e.g. *2 butter naan and 1 paneer tikka* (Hindi or English is fine).\n"
            . "• *menu* to see the menu\n• *bill* to see your order\n• *1* to place it";
    }

    /** Before placing: order type, address, name; then the bill with "reply 1". */
    function woCheckout(PDO $conn, string $rid, string $phone, array $s, array $cfg, array $menu, array $opt, array $out): array
    {
        if (!$s['cart']) {
            $out[] = 'Your order is empty. Send dish names to add them.';
            woSave($conn, $s);
            return woOut($conn, $rid, $phone, $out);
        }
        $allowed = $cfg['allowed_types'];
        if ($s['table_id'] && in_array('dinein', $allowed, true)) $s['order_type'] = 'dinein';
        if (!$s['order_type'] || !in_array($s['order_type'], $allowed, true)) {
            $choices = array_values(array_diff($allowed, ['dinein']));
            if (count($choices) === 1) $s['order_type'] = $choices[0];
            elseif (!$choices) {
                $out[] = in_array('dinein', $allowed, true) ? 'Which table are you at? Reply e.g. *table 4*.' : 'Sorry, WhatsApp orders are not open right now.';
                woSave($conn, $s);
                return woOut($conn, $rid, $phone, $out);
            } else {
                $s['state'] = 'ask_type';
                $out[] = 'Takeaway or delivery? Reply *T* for takeaway or *D* for delivery.';
                woSave($conn, $s);
                return woOut($conn, $rid, $phone, $out);
            }
        }
        if ($s['order_type'] === 'delivery' && !$s['address']) {
            $s['state'] = 'ask_address';
            $out[] = '📍 Please send your full delivery address (house, street, area, landmark).';
            woSave($conn, $s);
            return woOut($conn, $rid, $phone, $out);
        }
        if (!$s['name']) {
            $s['state'] = 'ask_name';
            $out[] = 'What name should we put on the order?';
            woSave($conn, $s);
            return woOut($conn, $rid, $phone, $out);
        }
        $t = woTotals($cfg, $s['cart'], $s['order_type']);
        $min = (float)($cfg['restaurant']['minimum_order_value'] ?? 0);
        if ($s['order_type'] !== 'dinein' && $min > 0 && $t['subtotal'] < $min) {
            $out[] = 'The minimum order is ' . woMoney($cfg, $min) . '. Add ' . woMoney($cfg, $min - $t['subtotal']) . ' more to place it.';
            woSave($conn, $s);
            return woOut($conn, $rid, $phone, $out);
        }
        $where = $s['order_type'] === 'dinein' ? 'Dine-in, table *' . woTableName($conn, (int)$s['table_id']) . '*'
            : ($s['order_type'] === 'delivery' ? 'Delivery to: ' . $s['address'] : 'Takeaway');
        $pay = $s['order_type'] === 'dinein' ? 'Pay at the table when you are done.' : ($s['order_type'] === 'delivery' ? 'Pay on delivery.' : 'Pay when you pick it up.');
        $s['state'] = 'confirm';
        $out[] = woCartText($cfg, $s) . "\n\n" . $where . "\nName: " . $s['name'] . "\n" . $pay . "\n\nReply *1* to confirm ✅ or *2* to change.";
        woSave($conn, $s);
        return woOut($conn, $rid, $phone, $out);
    }

    /** Confirmed: the order goes through the website's order code (same checks, KOT, alerts, loyalty). */
    function woPlace(PDO $conn, string $rid, string $phone, array $s, array $cfg, array $opt, array $out): array
    {
        $t = woTotals($cfg, $s['cart'], (string)$s['order_type']);
        if (!empty($opt['simulate'])) {
            $s['state'] = 'browsing';
            $s['cart'] = [];
            $out[] = "🧪 *Test chat:* here the order would be placed (" . woMoney($cfg, $t['total']) . ") and show in Orders with a KOT. Real WhatsApp orders do that.";
            woSave($conn, $s);
            return woOut($conn, $rid, $phone, $out);
        }
        $notes = '[WhatsApp order]' . ($s['notes'] ? ' ' . $s['notes'] : '');
        $items = [];
        foreach ($s['cart'] as $l) {
            $items[] = ['id' => $l['id'], 'name' => $l['name'], 'price' => $l['price'], 'quantity' => $l['qty'], 'variation_name' => $l['variation'] ?: null,
                'addons' => [], 'notes' => $l['note'] ?: ''];
        }
        $payload = [
            'customer_name' => $s['name'], 'customer_phone' => $phone, 'customer_email' => '',
            'customer_address' => $s['order_type'] === 'delivery' ? (string)$s['address'] : '',
            'order_type' => woTypeLabel((string)$s['order_type']), 'table_id' => $s['order_type'] === 'dinein' ? (int)$s['table_id'] : null,
            'items' => $items, 'total' => $t['total'], 'total_payable' => $t['total'], 'payment_method' => 'Cash', 'notes' => $notes, 'whatsapp_optin' => 1,
        ];
        [$ok, $res] = woInternalPost('process_website_order.php?restaurant_id=' . rawurlencode($rid), $payload);
        if ($ok && !empty($res['success'])) {
            $s['state'] = 'placed';
            $s['order_id'] = (int)($res['order_id'] ?? 0);
            $s['order_number'] = (string)($res['order_number'] ?? '');
            $s['notified_status'] = null;
            $s['cart'] = [];
            $total = isset($res['total_with_tax']) ? (float)$res['total_with_tax'] : $t['total'];
            $out[] = '✅ *Order placed!* ' . $s['order_number'] . "\nTotal: *" . woMoney($cfg, $total) . "*\n\nWe'll message you here when it's being made and when it's ready. Reply *status* any time.";
            $conn->prepare('UPDATE wa_order_sessions SET notified_status = NULL WHERE id = ?')->execute([$s['id']]);
            woSave($conn, $s);
            woLog($conn, $rid, $phone, 'sys', 'Order ' . $s['order_number'] . ' placed', 'order:' . $s['order_id']);
            return woOut($conn, $rid, $phone, $out);
        }
        $msg = (string)($res['message'] ?? 'The order could not be placed.');
        $url = woMenuUrl($cfg);
        $s['state'] = 'browsing';
        $out[] = '⚠️ ' . $msg . ($url && preg_match('/online payment|cash on delivery/i', $msg) ? "\nYou can finish this order on our website: " . $url : '') . "\nReply *bill* to see your order.";
        woSave($conn, $s);
        woLog($conn, $rid, $phone, 'sys', 'Order failed: ' . $msg, 'error');
        return woOut($conn, $rid, $phone, $out);
    }

    function woOut(PDO $conn, string $rid, string $phone, array $out): array
    {
        foreach ($out as $m) woLog($conn, $rid, $phone, 'out', $m);
        return $out;
    }

    // ── internal call to the website's order endpoint ──
    function woInternalSecret(): string
    {
        return hash('sha256', 'rg-internal|' . env('DB_PASS', '') . '|' . env('DB_NAME', '') . '|' . env('WA_KEY_SECRET', ''));
    }

    /** True when this request was signed by woInternalPost() within the last 2 minutes. */
    function woVerifyInternal(string $rawBody): bool
    {
        $h = (string)($_SERVER['HTTP_X_RG_INTERNAL'] ?? '');
        if (!preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $h, $m)) return false;
        if (abs(time() - (int)$m[1]) > 120) return false;
        return hash_equals(hash_hmac('sha256', $m[1] . '.' . $rawBody, woInternalSecret()), $m[2]);
    }

    function woApiBase(): string
    {
        $env = rtrim((string)env('APP_API_BASE', ''), '/');
        if ($env !== '') return $env;
        if (!empty($_SERVER['HTTP_HOST'])) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/main/api/x.php'))), '/');
            if (!preg_match('#/api$#', $dir)) $dir = preg_replace('#/[^/]+$#', '', $dir) . '/api';
            return ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $dir;
        }
        return '';   // no web request to go by (command line) and no APP_API_BASE: never guess, it could be the live site
    }

    function woInternalPost(string $path, array $payload): array
    {
        $base = woApiBase();
        if ($base === '') return [false, ['message' => 'Orders cannot be placed from here (no server address).']];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $t = (string)time();
        $c = curl_init($base . '/' . ltrim($path, '/'));
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-RG-Internal: t=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, woInternalSecret())]]);
        $res = curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
        $err = curl_error($c);
        curl_close($c);
        if ($res === false) { error_log('woInternalPost: ' . $err); return [false, ['message' => 'The restaurant system is busy. Please try again in a minute.']]; }
        $d = json_decode((string)$res, true);
        if (!is_array($d)) { error_log('woInternalPost: HTTP ' . $code . ' ' . substr((string)$res, 0, 300)); return [false, ['message' => 'The restaurant system is busy. Please try again in a minute.']]; }
        return [true, $d];
    }

    // ── order status in the chat ──
    function woStatusText(PDO $conn, int $orderId, array $cfg): string
    {
        $st = $conn->prepare('SELECT order_number, order_status, order_type, total FROM orders WHERE id = ?');
        $st->execute([$orderId]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) return "I couldn't find your order.";
        $map = ['Pending' => 'received, waiting for the restaurant to accept', 'Accepted' => 'accepted ✅', 'Preparing' => 'being made 👨‍🍳', 'Ready' => 'ready 🎉',
            'Out for Delivery' => 'on the way 🛵', 'Served' => 'served', 'Completed' => 'completed', 'Cancelled' => 'cancelled', 'Rejected' => 'not accepted'];
        return 'Order ' . $o['order_number'] . ' is ' . ($map[$o['order_status']] ?? strtolower((string)$o['order_status'])) . '. Total ' . woMoney($cfg, (float)$o['total']) . '.';
    }

    /** Call after an order's status is saved: tells the WhatsApp customer, once per status. Never throws. */
    function woNotifyStatus(PDO $conn, int $orderId): void
    {
        try {
            woEnsure($conn);
            $st = $conn->prepare('SELECT s.*, o.order_status, o.order_type, o.restaurant_id rid FROM wa_order_sessions s JOIN orders o ON o.id = s.order_id WHERE s.order_id = ? LIMIT 1');
            $st->execute([$orderId]);
            $s = $st->fetch(PDO::FETCH_ASSOC);
            if (!$s || $s['notified_status'] === $s['order_status']) return;
            $msgs = [
                'Accepted' => '✅ Your order %s is accepted.',
                'Preparing' => '👨‍🍳 Your order %s is being made.',
                'Ready' => $s['order_type'] === 'Takeaway' ? '🎉 Your order %s is ready for pickup!' : ($s['order_type'] === 'Dine-in' ? '🎉 Your order %s is ready, coming to your table.' : '🎉 Your order %s is ready.'),
                'Out for Delivery' => '🛵 Your order %s is on the way!',
                'Completed' => '🙏 Thank you! Order %s is complete. Hope you enjoyed it, reply any time to order again.',
                'Cancelled' => 'Sorry, your order %s was cancelled. Reply to talk to us.',
                'Rejected' => 'Sorry, the restaurant could not take order %s right now. Reply to talk to us.',
            ];
            $conn->prepare('UPDATE wa_order_sessions SET notified_status = ? WHERE id = ?')->execute([$s['order_status'], $s['id']]);
            if (!isset($msgs[$s['order_status']])) return;
            $text = sprintf($msgs[$s['order_status']], $s['order_number']);
            woSend($conn, (string)$s['rid'], (string)$s['phone'], $text);
        } catch (Throwable $e) {
            error_log('woNotifyStatus: ' . $e->getMessage());
        }
    }

    /** Send one chat message to a customer through the restaurant's WhatsApp (RepeatGrow), and log it. */
    function woSend(PDO $conn, string $rid, string $phone, string $text, bool $log = true): bool
    {
        ensureWhatsappSchema($conn);
        $s = waSettings($conn, $rid);
        [$ok, , $err] = waSend($s, $phone, null, '', [], $text);
        // replies from woHandle() are logged already; only a failure is added then
        if ($log) woLog($conn, $rid, $phone, 'out', $text, $ok ? '' : 'send failed: ' . (string)$err);
        elseif (!$ok) woLog($conn, $rid, $phone, 'sys', 'Could not send the reply above: ' . (string)$err, 'error');
        return $ok;
    }

    /** The "Order on WhatsApp" link for the restaurant (and a table): its own number, or the shared RestroGrow number with a code. */
    function woOrderLink(PDO $conn, string $rid, ?string $table = null): string
    {
        ensureWhatsappSchema($conn);
        $wa = waSettings($conn, $rid);
        $own = preg_replace('/\D/', '', (string)$wa['rg_phone']);
        if ($own !== '') return 'https://wa.me/' . $own . '?text=' . rawurlencode('Hi' . ($table ? ', table ' . $table : ''));
        $shared = preg_replace('/\D/', '', (string)env('WA_ORDER_SHARED_NUMBER', ''));
        if ($shared === '') return '';
        return 'https://wa.me/' . $shared . '?text=' . rawurlencode('ORDER ' . $rid . ($table ? ' ' . $table : ''));
    }
}
