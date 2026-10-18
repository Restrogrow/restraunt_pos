<?php
/**
 * Database indexes the customer website relies on, added where missing (the live database may lack
 * upgrades made on a local copy). Runs once per deploy from website/header.php through
 * rgEnsureOnce('ensureWebsiteIndexes', $conn) (config/schema_cache.php). An index is skipped when its
 * table or columns don't exist, or when an index on the same columns is already there.
 */
if (!function_exists('ensureWebsiteIndexes')) {
    function ensureWebsiteIndexes(PDO $conn): void {
        if ($conn->inTransaction()) return;
        // [table, index name, columns (prefix lengths for long text)]
        $want = [
            ['orders', 'idx_rg_orders_rest_created', ['restaurant_id', 'created_at']],
            ['orders', 'idx_rg_orders_rest_phone', ['restaurant_id', 'customer_phone', 'created_at']],
            ['orders', 'idx_order_number', ['order_number']],
            ['order_items', 'idx_order_id', ['order_id']],
            ['menu_items', 'idx_rg_mi_rest_menu', ['restaurant_id', 'menu_id']],
            ['menu_items', 'idx_rg_mi_image', ['item_image(64)']],            // image.php looks photos up by this
            ['menu_item_variations', 'idx_rg_miv_item', ['menu_item_id', 'sort_order']],
            ['menu', 'idx_rg_menu_rest_active', ['restaurant_id', 'is_active', 'sort_order']],
            ['menu', 'idx_rg_menu_image', ['menu_image(64)']],
            ['meal_addons', 'idx_rg_addon_image', ['addon_image(64)']],
            ['coupons', 'idx_rg_coupons_rest_active', ['restaurant_id', 'is_active']],
            ['deals', 'idx_rg_deals_rest_active', ['restaurant_id', 'is_active', 'created_at']],
            ['order_feedback', 'idx_rg_feedback_rest_rating', ['restaurant_id', 'rating']],
            ['reservations', 'idx_rg_res_rest_date', ['restaurant_id', 'reservation_date']],
            ['users', 'idx_rg_users_custom_domain', ['custom_domain(191)']],
            ['loyalty_points_ledger', 'idx_customer_recent', ['restaurant_id', 'customer_id', 'id']],
        ];
        foreach ($want as [$table, $name, $cols]) {
            try {
                $have = [];
                foreach ($conn->query('SHOW INDEX FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $ix) {
                    $have[$ix['Key_name']][(int)$ix['Seq_in_index']] = strtolower($ix['Column_name']);
                }
                if (isset($have[$name])) continue;
                $plain = array_map(fn($c) => strtolower(preg_replace('/\(\d+\)$/', '', $c)), $cols);
                foreach ($have as $ixCols) { ksort($ixCols); if (array_slice(array_values($ixCols), 0, count($plain)) === $plain) continue 2; }
                $colsInTable = array_map('strtolower', array_column($conn->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC), 'Field'));
                if (array_diff($plain, $colsInTable)) continue;
                $conn->exec('ALTER TABLE `' . $table . '` ADD INDEX `' . $name . '` (' . implode(', ', array_map(fn($c) => preg_replace('/^(\w+)/', '`$1`', $c), $cols)) . ')');
            } catch (Throwable $e) {
                error_log('ensureWebsiteIndexes ' . $table . '.' . $name . ': ' . $e->getMessage());   // a missing table etc.: next one
            }
        }
    }
}
