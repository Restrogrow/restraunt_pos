<?php
/**
 * Dish ingredients (Menu > dish form > Ingredients).
 *
 * Stored cleaned in menu_items.ingredients (one per line with optional notes,
 * or a comma list) and shown in the website's dish details under "What's
 * inside", with protein / fat / carbs / fibre under "Nutritional info".
 */
// Health / diet tags a dish can carry (Menu > dish form > Health tags); shown only when ticked
if (!defined('MENU_HEALTH_TAGS')) define('MENU_HEALTH_TAGS', [
    'high_protein' => 'High protein', 'low_carb' => 'Low carb', 'low_fat' => 'Low fat', 'high_fibre' => 'High fibre',
    'low_calorie' => 'Low calorie', 'low_sugar' => 'Low sugar', 'keto' => 'Keto friendly', 'vegan' => 'Vegan',
    'gluten_free' => 'Gluten free', 'dairy_free' => 'Dairy free', 'jain' => 'Jain',
]);

if (!function_exists('ensureMenuIngredientsColumn')) {
    /** Adds menu_items.ingredients once; returns whether the column exists. */
    function ensureMenuIngredientsColumn($conn) {
        static $has = null;
        if ($has !== null) return $has;
        try {
            $col = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'ingredients'");
            if ($col && $col->fetch()) return $has = true;
            $conn->exec("ALTER TABLE menu_items ADD COLUMN ingredients TEXT DEFAULT NULL");
            return $has = true;
        } catch (PDOException $e) {
            error_log('ingredient_helpers.php: ' . $e->getMessage());
            return $has = false;
        }
    }

    /**
     * Two ways to write ingredients:
     *   one per line, optionally with a note: "Paneer - excellent source of protein"
     *   or the older comma-separated list:   "Paneer, Curd, Lemon"
     * One-per-line is kept one per line (max 30 lines; name 40, note 100 chars);
     * a comma list is cleaned as before.
     */
    function cleanIngredients($raw) {
        $raw = strip_tags((string)$raw);
        $lines = preg_match('/[\r\n]/', trim($raw));
        $out = [];
        $seen = [];
        foreach (preg_split($lines ? '/[\r\n]+/u' : '/[,;]+/u', $raw) as $part) {
            $part = trim(preg_replace('/[ \t]+/u', ' ', $part));
            if ($part === '') continue;
            $note = '';
            if (preg_match('/^(.+?)\s+[-\x{2013}\x{2014}]\s+(.+)$/u', $part, $mm) || preg_match('/^([^:]+):\s*(.+)$/u', $part, $mm)) {
                $part = trim($mm[1]);
                $note = mb_substr(trim($mm[2]), 0, 100);
            }
            $part = mb_substr($part, 0, 40);
            $k = mb_strtolower($part);
            if (isset($seen[$k])) continue;
            $seen[$k] = 1;
            $out[] = $note !== '' ? $part . ' - ' . $note : $part;
            if (count($out) >= 30) break;
        }
        if (!$out) return null;
        return $lines ? implode("\n", $out) : implode(', ', $out);
    }

    /** menu_items.protein_g / fat_g / carbs_g / fibre_g (grams per serving), added once. */
    function ensureMenuNutritionColumns($conn) {
        static $has = null;
        if ($has !== null) return $has;
        try {
            foreach (['protein_g', 'fat_g', 'carbs_g', 'fibre_g'] as $col) {
                $q = $conn->query("SHOW COLUMNS FROM menu_items LIKE '$col'");
                if (!($q && $q->fetch())) $conn->exec("ALTER TABLE menu_items ADD COLUMN $col DECIMAL(6,1) DEFAULT NULL");
            }
            $q = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'health_tags'");
            if (!($q && $q->fetch())) $conn->exec("ALTER TABLE menu_items ADD COLUMN health_tags VARCHAR(255) DEFAULT NULL");
            return $has = true;
        } catch (PDOException $e) {
            error_log('ingredient_helpers.php nutrition: ' . $e->getMessage());
            return $has = false;
        }
    }

    /** Saves protein / fat / carbs / fibre when the form sent them (blank = not shown). */
    function saveMenuItemNutrition($conn, $menuItemId, $restaurantId) {
        if (!isset($_POST['protein_g']) || !ensureMenuNutritionColumns($conn)) return;
        $v = function ($k) {
            $x = trim((string)($_POST[$k] ?? ''));
            return ($x === '' || !is_numeric($x) || (float)$x <= 0) ? null : min(9999.9, round((float)$x, 1));
        };
        $conn->prepare("UPDATE menu_items SET protein_g = ?, fat_g = ?, carbs_g = ?, fibre_g = ? WHERE id = ? AND restaurant_id = ?")
             ->execute([$v('protein_g'), $v('fat_g'), $v('carbs_g'), $v('fibre_g'), (int)$menuItemId, $restaurantId]);
        // Health tags: only the known ones, in a fixed order (none ticked = none shown)
        if (isset($_POST['health_tags_sent'])) {
            $picked = array_values(array_intersect(array_keys(MENU_HEALTH_TAGS), (array)($_POST['health_tags'] ?? [])));
            $conn->prepare("UPDATE menu_items SET health_tags = ? WHERE id = ? AND restaurant_id = ?")
                 ->execute([$picked ? implode(',', $picked) : null, (int)$menuItemId, $restaurantId]);
        }
    }

    /** Saves the dish's ingredients when the form sent the field. */
    function saveMenuItemIngredients($conn, $menuItemId, $restaurantId) {
        if (!isset($_POST['ingredients']) || !ensureMenuIngredientsColumn($conn)) return;
        $conn->prepare("UPDATE menu_items SET ingredients = ? WHERE id = ? AND restaurant_id = ?")
             ->execute([cleanIngredients($_POST['ingredients']), (int)$menuItemId, $restaurantId]);
    }
}
