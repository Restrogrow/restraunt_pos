<?php
/**
 * Auto-creates the inventory_items, inventory_transactions, and expenses
 * tables on first use, mirroring the "create table if missing" pattern
 * used by coupons/addons (no formal migration system in this codebase).
 */

if (!function_exists('ensureInventoryTables')) {
    function ensureInventoryTables($conn) {
        try { $conn->query("SELECT id FROM inventory_items LIMIT 1"); } catch (PDOException $e) {
            $conn->exec("CREATE TABLE IF NOT EXISTS inventory_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id VARCHAR(10) NOT NULL,
                item_name VARCHAR(255) NOT NULL,
                unit VARCHAR(50) NOT NULL DEFAULT 'unit',
                category VARCHAR(100) DEFAULT '',
                quantity_in_stock DECIMAL(10,2) NOT NULL DEFAULT 0,
                low_stock_threshold DECIMAL(10,2) NOT NULL DEFAULT 0,
                cost_per_unit DECIMAL(10,2) NOT NULL DEFAULT 0,
                notes VARCHAR(255) DEFAULT '',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_inv_restaurant (restaurant_id)
            )");
        }

        try { $conn->query("SELECT id FROM inventory_transactions LIMIT 1"); } catch (PDOException $e) {
            $conn->exec("CREATE TABLE IF NOT EXISTS inventory_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id VARCHAR(10) NOT NULL,
                inventory_item_id INT NOT NULL,
                type ENUM('restock','adjustment','wastage') NOT NULL DEFAULT 'restock',
                quantity DECIMAL(10,2) NOT NULL,
                cost_per_unit DECIMAL(10,2) NOT NULL DEFAULT 0,
                total_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
                notes VARCHAR(255) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_invtx_restaurant (restaurant_id),
                INDEX idx_invtx_item (inventory_item_id)
            )");
        }

        try { $conn->query("SELECT id FROM expenses LIMIT 1"); } catch (PDOException $e) {
            $conn->exec("CREATE TABLE IF NOT EXISTS expenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id VARCHAR(10) NOT NULL,
                category VARCHAR(100) NOT NULL DEFAULT 'General',
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                description VARCHAR(255) DEFAULT '',
                expense_date DATE NOT NULL,
                source VARCHAR(50) NOT NULL DEFAULT 'manual',
                reference_id INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_exp_restaurant (restaurant_id),
                INDEX idx_exp_date (expense_date)
            )");
        }
    }
}
