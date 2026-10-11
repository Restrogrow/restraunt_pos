<?php
/**
 * Offer filters (Offers > Offer Filters)
 *
 * Quick filters the restaurant sets up for the customer Menu page, shown in a
 * floating offers bubble: "Under ₹99", "50% OFF & more", "Bestsellers",
 * "Veg only", or a hand-picked list of dishes. They only filter the real menu
 * — prices and discounts always come from the dishes themselves.
 */

const OFFER_FILTER_MAX = 12;
// rule_type => needs a number?
const OFFER_FILTER_RULES = [
    'price_under'  => true,   // price (after any offer) at or below rule_value
    'discount_min' => true,   // real offer of at least rule_value % off
    'any_offer'    => false,  // any dish with an offer price
    'bestsellers'  => false,  // dishes featured in Offers > Bestsellers
    'veg'          => false,  // vegetarian dishes
    'handpicked'   => false,  // the dishes listed in item_ids
];
const OFFER_FILTER_ICONS = ['🏷️', '🔥', '💸', '⭐', '🥗', '🎉', '⚡', '🍕', '🍛', '🍰', '☕', '🎁'];

function ensureOfferFiltersTable($conn) {
    static $done = false;
    if ($done) return;
    $conn->exec("
        CREATE TABLE IF NOT EXISTS offer_filters (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(10) NOT NULL,
            title VARCHAR(60) NOT NULL,
            icon VARCHAR(16) NOT NULL DEFAULT '🏷️',
            rule_type VARCHAR(20) NOT NULL,
            rule_value DECIMAL(10,2) DEFAULT NULL,
            item_ids TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_restaurant_sort (restaurant_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $done = true;
}

/** Rows for a restaurant as API-ready arrays (optionally only the active ones). */
function getOfferFilters($conn, $restaurantId, $activeOnly = false) {
    $sql = "SELECT id, title, icon, rule_type, rule_value, item_ids, is_active
            FROM offer_filters WHERE restaurant_id = ?" . ($activeOnly ? " AND is_active = 1" : "") . "
            ORDER BY sort_order, id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$restaurantId]);
    return array_map(function ($r) {
        $ids = json_decode((string)$r['item_ids'], true);
        return [
            'id' => (int)$r['id'],
            'title' => $r['title'],
            'icon' => $r['icon'],
            'rule_type' => $r['rule_type'],
            'rule_value' => $r['rule_value'] !== null ? (float)$r['rule_value'] : null,
            'item_ids' => is_array($ids) ? array_values(array_map('intval', $ids)) : [],
            'is_active' => (int)$r['is_active'] === 1,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}
