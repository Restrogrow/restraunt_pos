<?php
/**
 * Dish ingredients (Menu > dish form > Ingredients).
 *
 * Stored as a cleaned, comma-separated list in menu_items.ingredients and
 * shown on the website's dish details as an "Ingredients" button.
 */
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

    /** "Paneer,  curd\nLemon, paneer" → "Paneer, curd, Lemon" (max 30 × 40 chars). */
    function cleanIngredients($raw) {
        $out = [];
        $seen = [];
        foreach (preg_split('/[,\n\r;]+/u', strip_tags((string)$raw)) as $part) {
            $part = trim(preg_replace('/\s+/u', ' ', $part));
            if ($part === '') continue;
            $part = mb_substr($part, 0, 40);
            $k = mb_strtolower($part);
            if (isset($seen[$k])) continue;
            $seen[$k] = 1;
            $out[] = $part;
            if (count($out) >= 30) break;
        }
        return $out ? implode(', ', $out) : null;
    }

    /** Saves the dish's ingredients when the form sent the field. */
    function saveMenuItemIngredients($conn, $menuItemId, $restaurantId) {
        if (!isset($_POST['ingredients']) || !ensureMenuIngredientsColumn($conn)) return;
        $conn->prepare("UPDATE menu_items SET ingredients = ? WHERE id = ? AND restaurant_id = ?")
             ->execute([cleanIngredients($_POST['ingredients']), (int)$menuItemId, $restaurantId]);
    }
}
