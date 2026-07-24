<?php
/**
 * Migration Script: Add Subcategories Support
 * 
 * This script:
 * 1. Creates the `subcategories` table
 * 2. Adds `subcategory_id` column to `menu_items` table
 * 3. Ensures proper indexes are in place
 * 
 * Run this script once to set up the database for subcategories.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database connection directly (skip session check for CLI migration)
$dbConfigPath = __DIR__ . '/../config/db_cache.php';
$dbConnPath = __DIR__ . '/../db_connection.php';

if (file_exists($dbConnPath)) {
    require_once $dbConnPath;
} elseif (file_exists(__DIR__ . '/../config/env_loader.php')) {
    // Try env loader + direct PDO connection
    require_once __DIR__ . '/../config/env_loader.php';
    try {
        $env = loadEnv();
        $host = $env['DB_HOST'] ?? 'localhost';
        $dbname = $env['DB_NAME'] ?? 'restro_menu';
        $username = $env['DB_USER'] ?? 'root';
        $password = $env['DB_PASS'] ?? '';
        $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (Exception $e) {
        die('Database connection failed: ' . $e->getMessage() . "\n");
    }
} else {
    die('Database connection file not found');
}

try {
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
        }
    }

    echo "=== Adding Subcategories Support ===\n\n";

    // 1. Create subcategories table
    echo "1. Creating `subcategories` table...\n";
    $conn->exec("
        CREATE TABLE IF NOT EXISTS subcategories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            menu_id INT NOT NULL,
            subcategory_name VARCHAR(100) NOT NULL,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (menu_id) REFERENCES menu(id) ON DELETE CASCADE,
            INDEX idx_menu_id (menu_id),
            INDEX idx_sort_order (sort_order),
            UNIQUE KEY unique_subcategory_per_menu (menu_id, subcategory_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "   ✅ `subcategories` table created successfully.\n\n";

    // 2. Add subcategory_id column to menu_items
    echo "2. Adding `subcategory_id` column to `menu_items` table...\n";
    $checkCol = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'subcategory_id'");
    if ($checkCol->rowCount() == 0) {
        $conn->exec("
            ALTER TABLE menu_items 
            ADD COLUMN subcategory_id INT DEFAULT NULL AFTER item_category,
            ADD INDEX idx_subcategory_id (subcategory_id)
        ");
        echo "   ✅ `subcategory_id` column added successfully.\n";
        
        // Add foreign key if possible
        try {
            $conn->exec("
                ALTER TABLE menu_items 
                ADD CONSTRAINT fk_menu_items_subcategory 
                FOREIGN KEY (subcategory_id) REFERENCES subcategories(id) ON DELETE SET NULL
            ");
            echo "   ✅ Foreign key constraint added.\n";
        } catch (PDOException $e) {
            echo "   ⚠️  Note: Foreign key could not be added (table engine may not support it). Column works without FK.\n";
        }
    } else {
        echo "   ✅ `subcategory_id` column already exists.\n";
    }

    echo "\n=== Migration Complete! ===\n";
    echo "Subcategories feature is now ready to use.\n";

} catch (PDOException $e) {
    echo "❌ Database Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
