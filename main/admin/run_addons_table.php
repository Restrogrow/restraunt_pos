<?php
/**
 * Migration script to create:
 * 1. meal_addons table - stores add-on definitions (name, price, image)
 * 2. order_item_addons table - stores add-ons selected with order items
 * 
 * Run this script once: php run_addons_table.php
 * Or access via browser: http://yourdomain/main/admin/run_addons_table.php
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database connection
require_once __DIR__ . '/../db_connection.php';

echo "<!DOCTYPE html><html><head><title>Add-ons Migration</title>";
echo "<style>body{font-family:Arial,sans-serif;padding:20px;max-width:800px;margin:0 auto}";
echo ".success{color:#155724;background:#d4edda;border:1px solid #c3e6cb;padding:12px;border-radius:6px;margin:8px 0}";
echo ".error{color:#721c24;background:#f8d7da;border:1px solid #f5c6cb;padding:12px;border-radius:6px;margin:8px 0}";
echo ".info{color:#0c5460;background:#d1ecf1;border:1px solid #bee5eb;padding:12px;border-radius:6px;margin:8px 0}";
echo "h2{color:#333;border-bottom:2px solid #ddd;padding-bottom:8px}";
echo "</style></head><body>";
echo "<h1>🍔 Add-ons Feature - Database Migration</h1>";

try {
    $conn = getConnection();
    echo "<div class='info'>✓ Connected to database</div>";
    
    // -------------------------------------------------------
    // 1. Create meal_addons table
    // -------------------------------------------------------
    echo "<h2>1. Meal Add-ons Table</h2>";
    
    $checkAddonsTable = $conn->query("SHOW TABLES LIKE 'meal_addons'");
    if ($checkAddonsTable->rowCount() == 0) {
        $sql = "CREATE TABLE IF NOT EXISTS meal_addons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            addon_name VARCHAR(255) NOT NULL,
            addon_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            addon_image VARCHAR(500) DEFAULT NULL,
            is_available TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_addon_restaurant (restaurant_id),
            INDEX idx_addon_available (restaurant_id, is_available)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $conn->exec($sql);
        echo "<div class='success'>✓ Created 'meal_addons' table successfully</div>";
    } else {
        echo "<div class='info'>• Table 'meal_addons' already exists</div>";
    }
    
    // Check image_data column (for database-stored images)
    try {
        $checkCol = $conn->query("SHOW COLUMNS FROM meal_addons LIKE 'image_data'");
        if ($checkCol->rowCount() == 0) {
            $conn->exec("ALTER TABLE meal_addons ADD COLUMN image_data LONGBLOB NULL AFTER addon_image");
            $conn->exec("ALTER TABLE meal_addons ADD COLUMN image_mime_type VARCHAR(50) NULL AFTER image_data");
            echo "<div class='success'>✓ Added image_data and image_mime_type columns to meal_addons</div>";
        } else {
            echo "<div class='info'>• Image columns already exist in meal_addons</div>";
        }
    } catch (PDOException $e) {
        echo "<div class='error'>Error checking/adding image columns: " . $e->getMessage() . "</div>";
    }
    
    // -------------------------------------------------------
    // 2. Create order_item_addons table
    // -------------------------------------------------------
    echo "<h2>2. Order Item Add-ons Table</h2>";
    
    $checkOrderAddonsTable = $conn->query("SHOW TABLES LIKE 'order_item_addons'");
    if ($checkOrderAddonsTable->rowCount() == 0) {
        $sql = "CREATE TABLE IF NOT EXISTS order_item_addons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_item_id INT NOT NULL,
            addon_id INT DEFAULT NULL,
            addon_name VARCHAR(255) NOT NULL,
            addon_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            quantity INT NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order_item (order_item_id),
            INDEX idx_addon_id (addon_id),
            FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $conn->exec($sql);
        echo "<div class='success'>✓ Created 'order_item_addons' table successfully</div>";
    } else {
        echo "<div class='info'>• Table 'order_item_addons' already exists</div>";
    }
    
    // -------------------------------------------------------
    // 3. Check/add variation_name column in kot_items
    // -------------------------------------------------------
    echo "<h2>3. KOT Items - Addon Support</h2>";
    
    try {
        $checkKotItemAddonCol = $conn->query("SHOW COLUMNS FROM kot_items LIKE 'addon_name'");
        if ($checkKotItemAddonCol->rowCount() == 0) {
            $conn->exec("ALTER TABLE kot_items ADD COLUMN addon_name VARCHAR(500) DEFAULT NULL AFTER item_name");
            echo "<div class='success'>✓ Added 'addon_name' column to kot_items</div>";
        } else {
            echo "<div class='info'>• 'addon_name' column already exists in kot_items</div>";
        }
    } catch (PDOException $e) {
        echo "<div class='error'>Error checking/adding kot_items addon column: " . $e->getMessage() . "</div>";
    }
    
    // -------------------------------------------------------
    // Summary
    // -------------------------------------------------------
    echo "<h2>✅ Migration Complete</h2>";
    echo "<div class='success'>All add-ons tables and columns are set up successfully!</div>";
    echo "<p>The following tables are now available:</p>";
    echo "<ul>";
    echo "<li><strong>meal_addons</strong> - Store add-on definitions (name, price, image, availability)</li>";
    echo "<li><strong>order_item_addons</strong> - Store selected add-ons for each order item</li>";
    echo "</ul>";
    
} catch (PDOException $e) {
    echo "<div class='error'>Database Error: " . $e->getMessage() . "</div>";
} catch (Exception $e) {
    echo "<div class='error'>Error: " . $e->getMessage() . "</div>";
}

echo "</body></html>";
