<?php
/**
 * Add-ons Migration Script
 * Creates the meal_addons table and adds addons JSON column to order_items
 * Run this script once to set up the database structure for the add-ons feature
 */

// Suppress error display
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Add-ons Database Migration</h2>";
echo "<pre>";

try {
    require_once __DIR__ . '/../db_connection.php';
    $conn = getConnection();
    
    echo "[1/3] Creating meal_addons table...\n";
    
    $conn->exec("CREATE TABLE IF NOT EXISTS meal_addons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(50) NOT NULL,
        addon_name VARCHAR(255) NOT NULL,
        addon_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        addon_image VARCHAR(500) DEFAULT NULL,
        image_data LONGBLOB DEFAULT NULL,
        image_mime_type VARCHAR(50) DEFAULT NULL,
        is_available TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_addon_restaurant (restaurant_id),
        INDEX idx_addon_available (restaurant_id, is_available)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    echo "✓ meal_addons table created successfully\n";
    
    echo "[2/3] Adding addons JSON column to order_items table...\n";
    try {
        $checkCol = $conn->query("SHOW COLUMNS FROM order_items LIKE 'addons'");
        if ($checkCol->rowCount() == 0) {
            $conn->exec("ALTER TABLE order_items ADD COLUMN addons JSON DEFAULT NULL AFTER total_price");
            echo "✓ 'addons' column added to order_items\n";
        } else {
            echo "→ 'addons' column already exists in order_items\n";
        }
    } catch (PDOException $e) {
        echo "⚠ Could not add column: " . $e->getMessage() . "\n";
    }
    
    echo "[3/3] Creating order_item_addons table for backward compatibility...\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS order_item_addons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_item_id INT NOT NULL,
        addon_id INT DEFAULT NULL,
        addon_name VARCHAR(255) NOT NULL,
        addon_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        quantity INT NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_order_item (order_item_id),
        FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    echo "✓ order_item_addons table created successfully\n";
    
    echo "\n✅ Migration completed successfully!\n";
    echo "Total tables: meal_addons, order_item_addons\n";
    
} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
echo "<p><a href='index.php' style='display:inline-block;padding:10px 20px;background:#e17055;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;'>← Back to Dashboard</a></p>";
