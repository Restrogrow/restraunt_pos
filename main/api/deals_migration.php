<?php
/**
 * Deals Migration - Creates or migrates the deals table
 * Run this once after deploying.
 */

// Include database connection
require_once __DIR__ . '/../db_connection.php';

try {
    $conn = getConnection();
    
    // Check if table exists
    $check = $conn->query("SHOW TABLES LIKE 'deals'");
    if ($check->rowCount() > 0) {
        // Check if old menu_item_id column exists
        $colCheck = $conn->query("SHOW COLUMNS FROM deals LIKE 'menu_item_id'");
        if ($colCheck->rowCount() > 0) {
            // Migrate from menu_item_id to menu_id
            $conn->exec("ALTER TABLE deals DROP INDEX idx_menu_item_id");
            $conn->exec("ALTER TABLE deals CHANGE COLUMN menu_item_id menu_id INT(11) NOT NULL");
            $conn->exec("ALTER TABLE deals ADD INDEX idx_menu_id (menu_id)");
            echo "✓ deals table migrated (menu_item_id → menu_id).\n";
        } else {
            echo "✓ deals table already exists with correct schema.\n";
        }
        exit(0);
    }
    
    // Create the deals table
    $sql = "CREATE TABLE `deals` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `restaurant_id` varchar(10) NOT NULL,
        `deal_type` enum('combo','new') NOT NULL,
        `menu_id` int(11) NOT NULL,
        `is_active` tinyint(1) DEFAULT 1,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_restaurant_id` (`restaurant_id`),
        KEY `idx_deal_type` (`deal_type`),
        KEY `idx_menu_id` (`menu_id`),
        KEY `idx_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    $conn->exec($sql);
    echo "✓ deals table created successfully.\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
