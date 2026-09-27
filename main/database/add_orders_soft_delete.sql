-- Soft-delete for orders.
--
-- Deleted orders are never removed from the database: they get flagged with
-- deleted_at / deleted_by / delete_reason and disappear from the live order
-- list and every sales figure, but remain visible in the app's "Deleted"
-- tab for audit purposes.
--
-- The app also self-migrates on first use via ensureOrderSoftDeleteColumns()
-- in main/config/soft_delete_helpers.php, so running this file is optional —
-- it just makes the change visible/explicit for DBAs and existing installs.

ALTER TABLE `orders`
    ADD COLUMN `deleted_at` DATETIME DEFAULT NULL AFTER `notes`,
    ADD COLUMN `deleted_by` VARCHAR(100) DEFAULT NULL AFTER `deleted_at`,
    ADD COLUMN `delete_reason` VARCHAR(255) DEFAULT NULL AFTER `deleted_by`;

CREATE INDEX `idx_orders_deleted_at` ON `orders` (`deleted_at`);
