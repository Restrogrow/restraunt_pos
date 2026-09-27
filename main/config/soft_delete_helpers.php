<?php
/**
 * Order soft-delete support.
 *
 * Deleted orders are never removed from the database — they're flagged with
 * deleted_at/deleted_by/delete_reason so they disappear from the live order
 * list (and from every sales figure) but remain visible in the app's
 * "Deleted" tab for audit purposes. Mirrors the "create table/columns if
 * missing" pattern used by inventory_tables.php / coupons — no formal
 * migration system in this codebase.
 */

if (!function_exists('ensureOrderSoftDeleteColumns')) {
    /**
     * Adds the soft-delete columns to `orders` on first use. Cheap after the
     * first call (one information_schema query), safe to call on every request.
     */
    function ensureOrderSoftDeleteColumns($conn) {
        try {
            $check = $conn->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'orders'
                   AND COLUMN_NAME = 'deleted_at'"
            );
            if ((int)$check->fetchColumn() > 0) {
                return; // already migrated
            }

            $conn->exec("
                ALTER TABLE orders
                    ADD COLUMN deleted_at DATETIME DEFAULT NULL,
                    ADD COLUMN deleted_by VARCHAR(100) DEFAULT NULL,
                    ADD COLUMN delete_reason VARCHAR(255) DEFAULT NULL
            ");
            // Partial index — soft-deleted rows are a tiny fraction of the
            // table, and every live query filters on deleted_at IS NULL.
            try {
                $conn->exec("CREATE INDEX idx_orders_deleted_at ON orders (deleted_at)");
            } catch (PDOException $e) {
                // Index is an optimization, not a requirement.
            }
            error_log("soft_delete_helpers.php: added deleted_at/deleted_by/delete_reason columns to orders");
        } catch (PDOException $e) {
            // A user without ALTER privilege (or a locked table) must not take
            // down the whole endpoint — but log loudly, because every query
            // below will reference these columns and fail without them.
            error_log("soft_delete_helpers.php: could not ensure soft-delete columns: " . $e->getMessage());
        }
    }
}

if (!function_exists('getActorName')) {
    /**
     * Best human-readable identity of whoever is acting, for audit columns.
     * Admins/branch admins land in $_SESSION['username'] via auth.php's login
     * handlers; staff logins land there too (member_name), so this is always
     * populated for an authorized session.
     */
    function getActorName() {
        if (!empty($_SESSION['username'])) {
            return $_SESSION['username'];
        }
        if (!empty($_SESSION['user_id'])) {
            return 'admin#' . $_SESSION['user_id'];
        }
        if (!empty($_SESSION['staff_id'])) {
            return 'staff#' . $_SESSION['staff_id'];
        }
        return 'unknown';
    }
}
