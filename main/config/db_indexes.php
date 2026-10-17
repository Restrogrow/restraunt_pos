<?php
/**
 * Speed indexes for the busiest lookups (reports, order lists, KOT, payments, growth tools).
 *
 *   dbEnsureIndexes($conn)  adds whichever of them this database is missing, once per INDEX_VERSION.
 *
 * Runs by itself from Super Admin's dashboard and the 15-minute cron (cron/owner_brief.php), so the live
 * database gets them without anyone running SQL by hand. Safe on any database:
 *  - a table or column that isn't there (the live DB lags behind local upgrades) is skipped;
 *  - an index is skipped when an existing one already starts with the same columns;
 *  - indexes are built online (ALGORITHM=INPLACE, LOCK=NONE): orders and bills keep saving meanwhile;
 *  - one run at a time (GET_LOCK); a failure is logged and retried on the next run.
 * To add more later: append to dbIndexList() and raise DB_INDEX_VERSION.
 */
if (!function_exists('dbEnsureIndexes')) {

    define('DB_INDEX_VERSION', '1');

    /** [table, index name, [columns]] */
    function dbIndexList(): array
    {
        return [
            // orders: reports, dashboard, order lists, customer history, coupons
            ['orders', 'idx_rg_orders_rest_created', ['restaurant_id', 'created_at']],
            ['orders', 'idx_rg_orders_rest_status', ['restaurant_id', 'order_status', 'created_at']],
            ['orders', 'idx_rg_orders_rest_source', ['restaurant_id', 'source', 'order_status']],
            ['orders', 'idx_rg_orders_rest_phone', ['restaurant_id', 'customer_phone', 'created_at']],
            ['orders', 'idx_rg_orders_rest_coupon', ['restaurant_id', 'coupon_code']],
            ['orders', 'idx_rg_orders_number', ['order_number']],
            ['order_items', 'idx_rg_oi_order', ['order_id']],
            ['order_items', 'idx_rg_oi_item', ['menu_item_id']],
            // kitchen
            ['kot', 'idx_rg_kot_rest_status', ['restaurant_id', 'kot_status', 'table_id']],
            ['kot', 'idx_rg_kot_rest_created', ['restaurant_id', 'created_at']],
            ['kot', 'idx_rg_kot_order', ['order_id']],
            ['kot_items', 'idx_rg_koti_kot', ['kot_id']],
            // menu
            ['menu_items', 'idx_rg_mi_rest_menu', ['restaurant_id', 'menu_id']],
            ['menu_item_variations', 'idx_rg_miv_item', ['menu_item_id', 'sort_order']],
            // payments
            ['payments', 'idx_rg_pay_rest_created', ['restaurant_id', 'created_at']],
            ['payments', 'idx_rg_pay_txn', ['transaction_id']],
            ['payment_proofs', 'idx_rg_pp_order', ['order_id']],
            ['subscription_payments', 'idx_rg_sp_txn', ['transaction_id']],
            ['subscription_payments', 'idx_rg_sp_user', ['user_id', 'created_at']],
            // customers and growth
            ['customers', 'idx_rg_cust_rest_phone', ['restaurant_id', 'phone']],
            ['loyalty_points_ledger', 'idx_rg_lpl_customer', ['customer_id']],
            ['loyalty_points_ledger', 'idx_rg_lpl_rest_created', ['restaurant_id', 'created_at']],
            ['wallet_ledger', 'idx_rg_wl_rest_type', ['restaurant_id', 'type', 'created_at']],
            ['marketing_order_src', 'idx_rg_mos_src', ['restaurant_id', 'src']],
            ['page_visits', 'idx_rg_pv_rest_time', ['restaurant_id', 'visited_at']],
            // service and delivery
            ['waiter_requests', 'idx_rg_wr_rest_status', ['restaurant_id', 'status', 'created_at']],
            ['delivery_tracking', 'idx_rg_dt_rest_status', ['restaurant_id', 'delivery_status']],
            ['delivery_tracking', 'idx_rg_dt_order', ['order_id']],
            ['delivery_tracking', 'idx_rg_dt_qr', ['qr_token']],
            ['inventory_po_lines', 'idx_rg_pol_po', ['po_id']],
            ['inventory_purchase_orders', 'idx_rg_po_rest_supplier', ['restaurant_id', 'supplier_id']],
            // WhatsApp, AI manager, logs
            ['wa_outbox', 'idx_rg_wao_rest_ref', ['restaurant_id', 'ref_type', 'ref_id', 'status']],
            ['wa_order_log', 'idx_rg_wol_rest_phone', ['restaurant_id', 'phone', 'id']],
            ['om_actions', 'idx_rg_oma_rest_brief', ['restaurant_id', 'brief_id']],
            ['om_briefs', 'idx_rg_omb_rest_id', ['restaurant_id', 'id']],
            ['support_messages', 'idx_rg_sm_ticket', ['ticket_id', 'id']],
            ['push_subscriptions', 'idx_rg_ps_endpoint', ['endpoint']],
            ['login_logs', 'idx_rg_ll_rest_type', ['restaurant_id', 'user_type', 'id']],
            ['activity_log', 'idx_rg_al_rest_staff', ['restaurant_id', 'staff_id', 'created_at']],
            ['error_logs', 'idx_rg_el_rest_created', ['restaurant_id', 'created_at']],
        ];
    }

    /**
     * Adds the missing indexes. Returns [added, skipped, failed] counts, or null when it already ran for
     * this version (or another run holds the lock). $force runs it again regardless of the version.
     */
    function dbEnsureIndexes(PDO $conn, bool $force = false): ?array
    {
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS sa_settings (k VARCHAR(60) PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            if (!$force && (string)$conn->query("SELECT v FROM sa_settings WHERE k = 'db_index_version'")->fetchColumn() === DB_INDEX_VERSION) return null;
            if ((int)$conn->query("SELECT GET_LOCK('rg_db_indexes', 0)")->fetchColumn() !== 1) return null;
        } catch (Throwable $e) {
            error_log('dbEnsureIndexes: ' . $e->getMessage());
            return null;
        }
        @set_time_limit(300);
        ignore_user_abort(true);
        $added = $skipped = $failed = 0;
        try {
            $db = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
            // what this database has: columns (with type) and each index's column order
            $cols = [];
            $st = $conn->prepare('SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?');
            $st->execute([$db]);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$t, $c, $type]) $cols[strtolower($t)][strtolower($c)] = strtolower($type);
            $idx = [];
            $st = $conn->prepare('SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX');
            $st->execute([$db]);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$t, $i, $c]) $idx[strtolower($t)][$i][] = strtolower($c);

            foreach (dbIndexList() as [$table, $name, $want]) {
                $t = strtolower($table);
                $want = array_map('strtolower', $want);
                $missingCol = !isset($cols[$t]) || array_diff($want, array_keys($cols[$t]));
                $covered = false;
                foreach ($idx[$t] ?? [] as $have) if (array_slice($have, 0, count($want)) === $want) { $covered = true; break; }
                if ($missingCol || $covered) { $skipped++; continue; }
                // text columns can only be indexed by a prefix
                $parts = array_map(function ($c) use ($cols, $t) {
                    return '`' . $c . '`' . (in_array($cols[$t][$c], ['text', 'mediumtext', 'longtext', 'blob', 'mediumblob', 'longblob'], true) ? '(191)' : '');
                }, $want);
                $sql = 'ALTER TABLE `' . $table . '` ADD INDEX `' . $name . '` (' . implode(', ', $parts) . ')';
                try {
                    try { $conn->exec($sql . ', ALGORITHM=INPLACE, LOCK=NONE'); }
                    catch (Throwable $e) { $conn->exec($sql); }   // a server that can't build it online
                    $added++;
                } catch (Throwable $e) {
                    $failed++;
                    error_log('dbEnsureIndexes: ' . $table . '.' . $name . ': ' . $e->getMessage());
                }
            }
            if ($failed === 0) $conn->prepare("REPLACE INTO sa_settings (k, v) VALUES ('db_index_version', ?)")->execute([DB_INDEX_VERSION]);
            $conn->prepare("REPLACE INTO sa_settings (k, v) VALUES ('db_index_last_run', ?)")->execute([date('Y-m-d H:i:s') . " added $added, skipped $skipped, failed $failed"]);
        } catch (Throwable $e) {
            error_log('dbEnsureIndexes: ' . $e->getMessage());
        } finally {
            try { $conn->query("SELECT RELEASE_LOCK('rg_db_indexes')"); } catch (Throwable $e) { /* released when the connection closes */ }
        }
        return [$added, $skipped, $failed];
    }
}
