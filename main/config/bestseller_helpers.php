<?php
/**
 * Bestsellers — items an admin picks (Offers > Bestsellers) to feature in a
 * "Customer favourites" carousel on the customer website, optionally with an
 * offer price.
 *
 * The offer price is a real price, not just a label: the website menu API
 * (website/api.php getMenuItems) serves it as the item's base_price (with
 * the regular price as original_price), and process_website_order.php
 * charges it — so the menu, cart and checkout always agree. Items with
 * variations (sizes) get one offer price per size instead (variation_offers,
 * keyed by the size name). An offer only applies when it's below the regular price.
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
    // Per-size offer prices for items with variations: {"Half": 279, "Full": 499}
    try {
        if (!$conn->query("SHOW COLUMNS FROM bestsellers LIKE 'variation_offers'")->fetch()) {
            $conn->exec("ALTER TABLE bestsellers ADD COLUMN variation_offers TEXT DEFAULT NULL");
        }
    } catch (PDOException $e) { error_log('ensureBestsellersTable: ' . $e->getMessage()); }
    $done = true;
}

/**
 * [menu_item_id => ['rank' => int, 'offer_price' => float|null,
 *                   'variation_offers' => [size name => float]]] for a restaurant.
 *
 * Read-only on purpose: process_website_order.php calls this inside its
 * order transaction, and any DDL (even CREATE TABLE IF NOT EXISTS) would
 * implicitly commit it in MySQL. A missing table just means no bestsellers.
 */
function getBestsellerMap($conn, $restaurantId) {
    try {
        try {
            $stmt = $conn->prepare("SELECT menu_item_id, sort_order, offer_price, variation_offers FROM bestsellers WHERE restaurant_id = ? ORDER BY sort_order, id");
            $stmt->execute([$restaurantId]);
        } catch (PDOException $e) {
            // variation_offers not added yet on this install — plain offers still work
            if (strpos($e->getMessage(), '42S22') === false) throw $e;
            $stmt = $conn->prepare("SELECT menu_item_id, sort_order, offer_price FROM bestsellers WHERE restaurant_id = ? ORDER BY sort_order, id");
            $stmt->execute([$restaurantId]);
        }
        $map = [];
        $rank = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['menu_item_id']] = [
                'rank' => $rank++,
                'offer_price' => $row['offer_price'] !== null ? (float)$row['offer_price'] : null,
                'variation_offers' => decodeVariationOffers($row['variation_offers'] ?? null),
            ];
        }
        return $map;
    } catch (Exception $e) {
        // Table not created yet (no bestsellers saved on this install) — fine
        if (strpos($e->getMessage(), '42S02') === false) error_log('getBestsellerMap: ' . $e->getMessage());
        return [];
    }
}

/** [size name => offer price] from the stored JSON (invalid entries dropped). */
function decodeVariationOffers($json) {
    $out = [];
    $data = is_string($json) ? json_decode($json, true) : null;
    if (is_array($data)) {
        foreach ($data as $name => $price) {
            if (is_string($name) && $name !== '' && is_numeric($price) && (float)$price > 0) $out[$name] = round((float)$price, 2);
        }
    }
    return $out;
}

/**
 * The effective offer price for one size of an item, or null when none applies.
 */
function bestsellerVariationOfferPrice(array $map, $menuItemId, $variationName, $regularPrice) {
    $offer = $map[(int)$menuItemId]['variation_offers'][(string)$variationName] ?? null;
    if ($offer === null) return null;
    return ($offer > 0 && $offer < (float)$regularPrice) ? (float)$offer : null;
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

// A dish needs at least this many rated orders before its rating is shown
const BESTSELLER_MIN_RATINGS = 3;

/**
 * Genuine per-dish ratings derived from customer order feedback
 * (order_feedback is one 1–5 rating per verified order): the average rating
 * of rated orders that contained the dish. Dishes with fewer than
 * BESTSELLER_MIN_RATINGS rated orders get no rating rather than a
 * misleading one. Returns [menu_item_id => ['rating' => float, 'count' => int]].
 */
function getItemRatings($conn, $restaurantId, array $menuItemIds) {
    $menuItemIds = array_values(array_unique(array_map('intval', $menuItemIds)));
    if (!$menuItemIds) return [];
    try {
        $ph = implode(',', array_fill(0, count($menuItemIds), '?'));
        $stmt = $conn->prepare("
            SELECT r.menu_item_id, AVG(r.rating) AS avg_rating, COUNT(*) AS rating_count
            FROM (
                SELECT DISTINCT f.id, f.rating, oi.menu_item_id
                FROM order_feedback f
                JOIN order_items oi ON oi.order_id = f.order_id
                WHERE f.restaurant_id = ? AND oi.menu_item_id IN ($ph)
            ) r
            GROUP BY r.menu_item_id
            HAVING COUNT(*) >= " . (int)BESTSELLER_MIN_RATINGS);
        $stmt->execute(array_merge([$restaurantId], $menuItemIds));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['menu_item_id']] = ['rating' => round((float)$row['avg_rating'], 1), 'count' => (int)$row['rating_count']];
        }
        return $out;
    } catch (Exception $e) {
        // No feedback table yet (nobody has rated an order) — no ratings
        if (strpos($e->getMessage(), '42S02') === false) error_log('getItemRatings: ' . $e->getMessage());
        return [];
    }
}

/**
 * Annotate website menu items in place: rating / rating_count on any item
 * with genuine ratings; is_bestseller / bestseller_rank on featured items;
 * and for valid offers swap base_price to the offer price (original_price
 * keeps the regular price for the strike-through).
 */
function applyBestsellersToItems($conn, $restaurantId, array &$items) {
    $map = getBestsellerMap($conn, $restaurantId);
    $ratings = getItemRatings($conn, $restaurantId, array_map(function ($i) { return (int)($i['id'] ?? 0); }, $items));
    foreach ($items as &$item) {
        $id = (int)($item['id'] ?? 0);
        if (isset($ratings[$id])) {
            $item['rating'] = $ratings[$id]['rating'];
            $item['rating_count'] = $ratings[$id]['count'];
        }
        if (!isset($map[$id])) continue;
        $item['is_bestseller'] = 1;
        $item['bestseller_rank'] = $map[$id]['rank'];
        $hasVariations = !empty($item['has_variations']) && !empty($item['variations']);
        if ($hasVariations) {
            // Per-size offers: each size's price becomes its offer price, with
            // original_price kept for the strike-through
            foreach ($item['variations'] as &$v) {
                $vOffer = bestsellerVariationOfferPrice($map, $id, $v['variation_name'] ?? '', $v['price'] ?? 0);
                if ($vOffer !== null) {
                    $v['original_price'] = $v['price'];
                    $v['price'] = number_format($vOffer, 2, '.', '');
                }
            }
            unset($v);
        }
        $offer = bestsellerOfferPrice($map, $id, $item['base_price'] ?? 0, $hasVariations);
        if ($offer !== null) {
            $item['original_price'] = $item['base_price'];
            $item['base_price'] = number_format($offer, 2, '.', '');
        }
    }
    unset($item);
}
