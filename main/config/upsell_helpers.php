<?php
/**
 * Offers > Complete your meal (cart upsell).
 *
 * Modes: 'auto'   — smart picks from real orders ("often ordered together"),
 *                    the parts missing from the meal (drink, dessert, side) and
 *                    what's popular;
 *        'manual' — only the owner's picks ("always suggest" + per-dish
 *                    "goes well with");
 *        'both'   — the owner's picks first, smart picks fill the rest.
 * The cart ranks them for the dishes in it (website/rg-upsell.js), so the
 * page needs one request. Views / adds are counted per day for the dashboard.
 */
if (!defined('UPSELL_ROLES')) define('UPSELL_ROLES', ['main', 'side', 'drink', 'dessert', 'none']);

if (!function_exists('ensureUpsellTables')) {

    function ensureUpsellTables(PDO $conn): void {
        static $done = false;
        if ($done) return;
        $done = true;
        $conn->exec("CREATE TABLE IF NOT EXISTS upsell_settings (
            restaurant_id VARCHAR(50) PRIMARY KEY,
            enabled TINYINT NOT NULL DEFAULT 1,
            mode VARCHAR(10) NOT NULL DEFAULT 'both',
            title VARCHAR(60) NULL,
            max_items TINYINT NOT NULL DEFAULT 6,
            price_cap_pct TINYINT NOT NULL DEFAULT 50,
            veg_safe TINYINT NOT NULL DEFAULT 1,
            goal_nudge TINYINT NOT NULL DEFAULT 1,
            roles TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        // menu_item_id 0 = "always suggest" (any cart)
        $conn->exec("CREATE TABLE IF NOT EXISTS upsell_pairs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            menu_item_id INT NOT NULL DEFAULT 0,
            suggest_item_id INT NOT NULL,
            position TINYINT NOT NULL DEFAULT 0,
            INDEX idx_upsell_pairs (restaurant_id, menu_item_id)
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS upsell_cache (
            restaurant_id VARCHAR(50) PRIMARY KEY,
            data MEDIUMTEXT NOT NULL,
            built_at DATETIME NOT NULL
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS upsell_stats (
            restaurant_id VARCHAR(50) NOT NULL,
            day DATE NOT NULL,
            views INT NOT NULL DEFAULT 0,
            adds INT NOT NULL DEFAULT 0,
            added_value DECIMAL(12,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (restaurant_id, day)
        )");
    }

    function upsellDefaults(): array {
        return ['enabled' => 1, 'mode' => 'both', 'title' => '', 'max_items' => 6, 'price_cap_pct' => 50,
                'veg_safe' => 1, 'goal_nudge' => 1, 'roles' => []];
    }

    function getUpsellSettings(PDO $conn, string $rid): array {
        ensureUpsellTables($conn);
        $st = $conn->prepare('SELECT enabled, mode, title, max_items, price_cap_pct, veg_safe, goal_nudge, roles FROM upsell_settings WHERE restaurant_id = ?');
        $st->execute([$rid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $s = upsellDefaults();
        if ($r) {
            foreach (['enabled', 'max_items', 'price_cap_pct', 'veg_safe', 'goal_nudge'] as $k) $s[$k] = (int)$r[$k];
            $s['mode'] = in_array($r['mode'], ['auto', 'manual', 'both'], true) ? $r['mode'] : 'both';
            $s['title'] = (string)($r['title'] ?? '');
            $roles = json_decode((string)$r['roles'], true);
            $s['roles'] = is_array($roles) ? $roles : [];
        }
        require_once __DIR__ . '/access_control.php';
        if (!accessPageOn($conn, $rid, 'upsellPage')) $s['enabled'] = 0;   // switched off by Super Admin
        return $s;
    }

    /** A sensible role for a category from its name (the owner can change it). */
    function upsellGuessRole(string $name): string {
        $n = mb_strtolower($name);
        $has = function (array $words) use ($n) { foreach ($words as $w) if (mb_strpos($n, $w) !== false) return true; return false; };
        if ($has(['drink', 'beverage', 'juice', 'shake', 'coffee', 'tea', 'chai', 'mocktail', 'cocktail', 'soda', 'lassi', 'cooler', 'smoothie', 'water'])) return 'drink';
        if ($has(['dessert', 'sweet', 'ice cream', 'icecream', 'cake', 'pastry', 'kulfi', 'mithai', 'brownie'])) return 'dessert';
        if ($has(['side', 'bread', 'roti', 'naan', 'raita', 'salad', 'soup', 'accompaniment', 'dip', 'sauce', 'fries', 'papad', 'extra', 'add on', 'add-on'])) return 'side';
        return 'main';
    }

    /** Category id => role, with the owner's choices over the guesses. */
    function upsellCategoryRoles(PDO $conn, string $rid, array $saved): array {
        $st = $conn->prepare('SELECT id, menu_name FROM menu WHERE restaurant_id = ? ORDER BY sort_order, menu_name');
        $st->execute([$rid]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $role = $saved[(string)$m['id']] ?? '';
            $out[] = ['id' => (int)$m['id'], 'name' => $m['menu_name'], 'guess' => upsellGuessRole((string)$m['menu_name']),
                      'role' => in_array($role, UPSELL_ROLES, true) ? $role : upsellGuessRole((string)$m['menu_name'])];
        }
        return $out;
    }

    /**
     * Learned from the last 90 days of real orders, cached for 6 hours:
     *   together: item => [[other item, % of its orders that had it], ...] (top 6)
     *   popular:  best-selling item ids of the last 30 days
     * A pair counts once it was ordered together at least 3 times, in 15%+ of
     * the item's orders, and more often than the other dish sells anyway.
     */
    function upsellLearned(PDO $conn, string $rid, bool $fresh = false): array {
        ensureUpsellTables($conn);
        if (!$fresh) {
            $st = $conn->prepare('SELECT data FROM upsell_cache WHERE restaurant_id = ? AND built_at > NOW() - INTERVAL 6 HOUR');
            $st->execute([$rid]);
            $d = json_decode((string)$st->fetchColumn(), true);
            if (is_array($d)) return $d;
        }
        $ok = "o.restaurant_id = ? AND o.created_at >= NOW() - INTERVAL 90 DAY AND o.deleted_at IS NULL AND o.order_status NOT IN ('Cancelled', 'Rejected')";
        $st = $conn->prepare("SELECT COUNT(DISTINCT o.id) FROM orders o WHERE $ok");
        $st->execute([$rid]);
        $orders = (int)$st->fetchColumn();

        $st = $conn->prepare("SELECT oi.menu_item_id, COUNT(DISTINCT oi.order_id) n FROM order_items oi JOIN orders o ON o.id = oi.order_id
            WHERE $ok AND oi.menu_item_id IS NOT NULL GROUP BY oi.menu_item_id");
        $st->execute([$rid]);
        $count = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $count[(int)$r['menu_item_id']] = (int)$r['n'];

        $st = $conn->prepare("SELECT a.menu_item_id x, b.menu_item_id y, COUNT(DISTINCT a.order_id) n
            FROM order_items a JOIN order_items b ON b.order_id = a.order_id AND b.menu_item_id <> a.menu_item_id
            JOIN orders o ON o.id = a.order_id
            WHERE $ok AND a.menu_item_id IS NOT NULL AND b.menu_item_id IS NOT NULL
            GROUP BY a.menu_item_id, b.menu_item_id HAVING n >= 3");
        $st->execute([$rid]);
        $together = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $x = (int)$r['x']; $y = (int)$r['y']; $n = (int)$r['n'];
            if (empty($count[$x]) || empty($count[$y]) || !$orders) continue;
            $conf = $n / $count[$x];
            $lift = $conf / ($count[$y] / $orders);
            if ($conf < 0.15 || $lift <= 1.0) continue;
            $together[$x][] = [$y, (int)round($conf * 100)];
        }
        foreach ($together as $x => &$list) {
            usort($list, function ($a, $b) { return $b[1] <=> $a[1]; });
            $list = array_slice($list, 0, 6);
        }
        unset($list);

        $st = $conn->prepare("SELECT oi.menu_item_id, SUM(oi.quantity) q FROM order_items oi JOIN orders o ON o.id = oi.order_id
            WHERE o.restaurant_id = ? AND o.created_at >= NOW() - INTERVAL 30 DAY AND o.deleted_at IS NULL
              AND o.order_status NOT IN ('Cancelled', 'Rejected') AND oi.menu_item_id IS NOT NULL
            GROUP BY oi.menu_item_id ORDER BY q DESC LIMIT 20");
        $st->execute([$rid]);
        $popular = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        $data = ['orders' => $orders, 'together' => $together, 'popular' => $popular];
        $conn->prepare('REPLACE INTO upsell_cache (restaurant_id, data, built_at) VALUES (?, ?, NOW())')
             ->execute([$rid, json_encode($data)]);
        return $data;
    }

    /** The owner's picks: ['always' => [ids], 'pairs' => [item => [ids]]]. */
    function upsellManual(PDO $conn, string $rid): array {
        ensureUpsellTables($conn);
        $st = $conn->prepare('SELECT menu_item_id, suggest_item_id FROM upsell_pairs WHERE restaurant_id = ? ORDER BY menu_item_id, position, id');
        $st->execute([$rid]);
        $always = []; $pairs = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $x = (int)$r['menu_item_id']; $y = (int)$r['suggest_item_id'];
            if ($x === 0) $always[] = $y; else $pairs[$x][] = $y;
        }
        return ['always' => $always, 'pairs' => $pairs];
    }

    /** Everything the cart needs to rank suggestions, in one go. */
    function getUpsellData(PDO $conn, string $rid): ?array {
        $s = getUpsellSettings($conn, $rid);
        if (!$s['enabled']) return null;
        $roles = [];
        foreach (upsellCategoryRoles($conn, $rid, $s['roles']) as $c) $roles[$c['id']] = $c['role'];
        $out = ['mode' => $s['mode'], 'title' => $s['title'], 'max' => $s['max_items'], 'price_cap_pct' => $s['price_cap_pct'],
                'veg_safe' => $s['veg_safe'], 'goal_nudge' => $s['goal_nudge'], 'roles' => (object)$roles,
                'always' => [], 'pairs' => new stdClass(), 'together' => new stdClass(), 'popular' => []];
        if ($s['mode'] !== 'auto') {
            $m = upsellManual($conn, $rid);
            $out['always'] = $m['always'];
            $out['pairs'] = (object)$m['pairs'];
        }
        if ($s['mode'] !== 'manual') {
            $l = upsellLearned($conn, $rid);
            $out['together'] = (object)$l['together'];
            $out['popular'] = $l['popular'];
        }
        return $out;
    }

    /** Count a 'view' or an 'add' (with the dish's real price) for today. */
    function trackUpsell(PDO $conn, string $rid, string $event, int $itemId = 0): void {
        ensureUpsellTables($conn);
        if ($event === 'view') {
            $conn->prepare('INSERT INTO upsell_stats (restaurant_id, day, views) VALUES (?, CURDATE(), 1) ON DUPLICATE KEY UPDATE views = views + 1')->execute([$rid]);
        } elseif ($event === 'add' && $itemId > 0) {
            $st = $conn->prepare('SELECT base_price FROM menu_items WHERE id = ? AND restaurant_id = ?');
            $st->execute([$itemId, $rid]);
            $price = $st->fetchColumn();
            if ($price === false) return;
            $conn->prepare('INSERT INTO upsell_stats (restaurant_id, day, adds, added_value) VALUES (?, CURDATE(), 1, ?)
                ON DUPLICATE KEY UPDATE adds = adds + 1, added_value = added_value + VALUES(added_value)')->execute([$rid, (float)$price]);
        }
    }
}
