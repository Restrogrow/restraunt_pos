<?php
/**
 * Bestsellers — items an admin picks (Offers > Bestsellers) to feature in a
 * "Customer favourites" carousel on the customer website, optionally with an
 * offer price.
 *
 * The offer price is a real price, not just a label: the website menu API
 * (website/api.php getMenuItems) serves it as the item's base_price (with
 * the regular price as original_price), and process_website_order.php
 * charges it — so the menu, cart and checkout always agree. It only applies
 * to items without variations, and only when it's below the regular price.
 */

function ensureBestsellersTable($conn) {
    static $done = false;
    if ($done) return;
    $conn->exec("
        CREATE TABLE IF NOT EXISTS bestsellers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(10) NOT NULL,
            menu_item_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            offer_price DECIMAL(10,2) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_bestseller (restaurant_id, menu_item_id),
            KEY idx_restaurant_sort (restaurant_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $done = true;
}

/**
 * [menu_item_id => ['rank' => int, 'offer_price' => float|null]] for a restaurant.
 *
 * Read-only on purpose: process_website_order.php calls this inside its
 * order transaction, and any DDL (even CREATE TABLE IF NOT EXISTS) would
 * implicitly commit it in MySQL. A missing table just means no bestsellers.
 */
function getBestsellerMap($conn, $restaurantId) {
    try {
        $stmt = $conn->prepare("SELECT menu_item_id, sort_order, offer_price FROM bestsellers WHERE restaurant_id = ? ORDER BY sort_order, id");
        $stmt->execute([$restaurantId]);
        $map = [];
        $rank = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['menu_item_id']] = [
                'rank' => $rank++,
                'offer_price' => $row['offer_price'] !== null ? (float)$row['offer_price'] : null,
            ];
        }
        return $map;
    } catch (Exception $e) {
        // Table not created yet (no bestsellers saved on this install) — fine
        if (strpos($e->getMessage(), '42S02') === false) error_log('getBestsellerMap: ' . $e->getMessage());
        return [];
    }
}

/**
 * The effective offer price for an item, or null when no valid offer applies.
 */
function bestsellerOfferPrice(array $map, $menuItemId, $basePrice, $hasVariations) {
    $entry = $map[(int)$menuItemId] ?? null;
    if (!$entry || $entry['offer_price'] === null || $hasVariations) return null;
    $offer = (float)$entry['offer_price'];
    return ($offer > 0 && $offer < (float)$basePrice) ? $offer : null;
}

/**
 * Annotate website menu items in place: is_bestseller / bestseller_rank, and
 * for valid offers swap base_price to the offer price (original_price keeps
 * the regular price for the strike-through).
 */
function applyBestsellersToItems($conn, $restaurantId, array &$items) {
    $map = getBestsellerMap($conn, $restaurantId);
    foreach ($items as &$item) {
        $id = (int)($item['id'] ?? 0);
        if (!isset($map[$id])) continue;
        $item['is_bestseller'] = 1;
        $item['bestseller_rank'] = $map[$id]['rank'];
        $hasVariations = !empty($item['has_variations']) && !empty($item['variations']);
        $offer = bestsellerOfferPrice($map, $id, $item['base_price'] ?? 0, $hasVariations);
        if ($offer !== null) {
            $item['original_price'] = $item['base_price'];
            $item['base_price'] = number_format($offer, 2, '.', '');
        }
    }
    unset($item);
}
