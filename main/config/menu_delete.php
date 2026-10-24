<?php
/**
 * Deleting dishes and menu sections without losing sales history.
 *
 * order_items and kot_items are linked to menu_items with ON DELETE CASCADE, and menu_items to menu the same way, so
 * a plain DELETE of a dish (or of its section) also deleted every past order line for it: old bills lost lines and
 * sales reports dropped the dish. So:
 *  - a dish that was ever ordered is hidden (is_hidden = 1, switched off) instead: gone from the POS, apps and website,
 *    history kept, and it can be shown again; a dish never ordered is deleted for real;
 *  - deleting a section deletes its never-ordered dishes and moves the ordered ones into a hidden, switched-off
 *    "Removed dishes" section, then deletes the section.
 * Used by the dashboard and the Partner app (controllers/menu_items_operations_base64.php, menu_items_operations.php,
 * menu_operations.php) and the API (mcp/tools/menu_extras.php).
 */

if (defined('MENU_DELETE_LOADED')) return;
define('MENU_DELETE_LOADED', true);

const MENU_ARCHIVE_SECTION = 'Removed dishes';

/** How many order and kitchen-ticket lines point at a dish. */
function menuDishUseCount(PDO $conn, int $itemId): int
{
    $n = 0;
    foreach (['order_items', 'kot_items'] as $tbl) {
        try {
            $st = $conn->prepare("SELECT COUNT(*) FROM $tbl WHERE menu_item_id = ?");
            $st->execute([$itemId]);
            $n += (int)$st->fetchColumn();
        } catch (PDOException $e) { /* table missing on this database */ }
    }
    return $n;
}

/** The is_hidden column (the dashboard's Hide button adds it too). */
function menuEnsureHiddenColumn(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (!$conn->query("SHOW COLUMNS FROM menu_items LIKE 'is_hidden'")->fetch()) $conn->exec('ALTER TABLE menu_items ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0');
}

/**
 * Delete one dish of $rid the safe way. $item needs id, item_name_en (and item_image to tidy an old image file).
 * → ['hidden' => bool, 'message' => text for the owner]
 */
function menuSafeDeleteDish(PDO $conn, string $rid, array $item): array
{
    $id = (int)$item['id'];
    $name = (string)$item['item_name_en'];
    if (menuDishUseCount($conn, $id) > 0) {
        menuEnsureHiddenColumn($conn);
        $conn->prepare('UPDATE menu_items SET is_hidden = 1, is_available = 0, updated_at = NOW() WHERE id = ? AND restaurant_id = ?')->execute([$id, $rid]);
        return ['hidden' => true, 'message' => "$name was ordered before, so it is hidden instead of deleted: it is gone from your menu, POS and website, and its past orders and sales stay in your reports. Show it again any time from the menu."];
    }
    $own = !$conn->inTransaction();
    if ($own) $conn->beginTransaction();
    try {
        // rows that point at the dish without a foreign key
        foreach (['bestsellers', 'todays_specials', 'upsell_pairs', 'combo_slot_options'] as $tbl) {
            try { $conn->prepare("DELETE FROM $tbl WHERE menu_item_id = ?")->execute([$id]); } catch (PDOException $e) { /* not on this database */ }
        }
        try { $conn->prepare('DELETE FROM upsell_pairs WHERE suggest_item_id = ?')->execute([$id]); } catch (PDOException $e) {}
        $conn->prepare('DELETE FROM menu_items WHERE id = ? AND restaurant_id = ?')->execute([$id, $rid]);
        if ($own) $conn->commit();
    } catch (Throwable $e) {
        if ($own && $conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    $img = (string)($item['item_image'] ?? '');
    if ($img !== '' && strpos($img, 'db:') !== 0 && strpos($img, 'local:') !== 0 && strpos($img, '://') === false && is_file($img)) @unlink($img);
    return ['hidden' => false, 'message' => "$name deleted."];
}

/**
 * Delete a section of $rid the safe way. → ['deleted' => n dishes deleted, 'kept' => n dishes moved to the hidden
 * "Removed dishes" section, 'message' => text for the owner]
 */
function menuSafeDeleteSection(PDO $conn, string $rid, int $menuId, string $menuName): array
{
    $st = $conn->prepare('SELECT id, item_name_en, item_image FROM menu_items WHERE menu_id = ? AND restaurant_id = ?');
    $st->execute([$menuId, $rid]);
    $dishes = $st->fetchAll(PDO::FETCH_ASSOC);
    $keep = array_values(array_filter($dishes, fn($d) => menuDishUseCount($conn, (int)$d['id']) > 0));
    $drop = array_values(array_filter($dishes, fn($d) => !in_array($d, $keep, true)));
    if ($keep) menuEnsureHiddenColumn($conn);   // ALTER outside the transaction
    $conn->beginTransaction();
    try {
        if ($keep) {
            $archive = menuArchiveSection($conn, $rid);
            $mv = $conn->prepare('UPDATE menu_items SET menu_id = ?, item_category = ?, subcategory_id = NULL, is_hidden = 1, is_available = 0, updated_at = NOW() WHERE id = ? AND restaurant_id = ?');
            foreach ($keep as $d) $mv->execute([$archive, MENU_ARCHIVE_SECTION, $d['id'], $rid]);
        }
        foreach ($drop as $d) menuSafeDeleteDish($conn, $rid, $d);
        try { $conn->prepare('DELETE FROM deals WHERE menu_id = ? AND restaurant_id = ?')->execute([$menuId, $rid]); } catch (PDOException $e) { /* no deals table */ }
        $conn->prepare('DELETE FROM menu WHERE id = ? AND restaurant_id = ?')->execute([$menuId, $rid]);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    $msg = "$menuName deleted.";
    if ($keep) $msg = "$menuName deleted. " . count($keep) . ' of its dishes were ordered before, so they moved to the hidden "' . MENU_ARCHIVE_SECTION . '" section (switched off) to keep your past orders and sales reports complete.';
    return ['deleted' => count($drop), 'kept' => count($keep), 'message' => $msg];
}

/** The restaurant's hidden "Removed dishes" section (made switched off, at the end of the menu, on first use). */
function menuArchiveSection(PDO $conn, string $rid): int
{
    $st = $conn->prepare('SELECT id FROM menu WHERE restaurant_id = ? AND menu_name = ? LIMIT 1');
    $st->execute([$rid, MENU_ARCHIVE_SECTION]);
    if ($id = (int)$st->fetchColumn()) return $id;
    $next = $conn->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM menu WHERE restaurant_id = ?');
    $next->execute([$rid]);
    $conn->prepare('INSERT INTO menu (restaurant_id, menu_name, is_active, sort_order, created_at, updated_at) VALUES (?, ?, 0, ?, NOW(), NOW())')->execute([$rid, MENU_ARCHIVE_SECTION, (int)$next->fetchColumn()]);
    return (int)$conn->lastInsertId();
}
