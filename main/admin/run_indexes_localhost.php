<?php
/**
 * Run Critical Indexes on Localhost Database
 * This script will automatically add critical indexes to localhost database
 */

// Connect to localhost database
try {
    $dsn = "mysql:host=localhost;dbname=restro2;charset=utf8mb4";
    $pdo = new PDO($dsn, 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    echo "Connected to localhost database successfully.\n\n";
} catch (PDOException $e) {
    die("Error connecting to localhost database: " . $e->getMessage() . "\n");
}

// List of indexes to add
$indexes = [
    ['table' => 'users', 'name' => 'idx_restaurant_id', 'columns' => 'restaurant_id'],
    ['table' => 'users', 'name' => 'idx_username', 'columns' => 'username'],
    ['table' => 'users', 'name' => 'idx_is_active', 'columns' => 'is_active'],
    ['table' => 'menu_items', 'name' => 'idx_restaurant_menu', 'columns' => 'restaurant_id, menu_id'],
    ['table' => 'menu_items', 'name' => 'idx_available_category', 'columns' => 'is_available, item_category'],
    ['table' => 'menu_items', 'name' => 'idx_restaurant_available', 'columns' => 'restaurant_id, is_available'],
    ['table' => 'orders', 'name' => 'idx_restaurant_date', 'columns' => 'restaurant_id, created_at'],
    ['table' => 'orders', 'name' => 'idx_status_date', 'columns' => 'order_status, created_at'],
    ['table' => 'menu', 'name' => 'idx_restaurant_active', 'columns' => 'restaurant_id, is_active'],
    ['table' => 'payments', 'name' => 'idx_order_id', 'columns' => 'order_id'],
    ['table' => 'payments', 'name' => 'idx_restaurant_date', 'columns' => 'restaurant_id, created_at'],
];

$successCount = 0;
$existsCount = 0;
$errorCount = 0;
$errors = [];

echo "Adding critical indexes to localhost database...\n";
echo str_repeat("=", 60) . "\n\n";

foreach ($indexes as $index) {
    $table = $index['table'];
    $indexName = $index['name'];
    $columns = $index['columns'];

    try {
        // Check if index already exists
        $checkStmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND INDEX_NAME = ?
        ");
        $checkStmt->execute([$table, $indexName]);
        $exists = $checkStmt->fetch()['count'] > 0;

        if ($exists) {
            echo "✓ Index '{$indexName}' on table '{$table}' already exists\n";
            $existsCount++;
        } else {
            // Add index
            $sql = "ALTER TABLE `$table` ADD INDEX `$indexName` ($columns)";
            $pdo->exec($sql);
            echo "✓ Created index '{$indexName}' on table '{$table}'\n";
            $successCount++;
        }
    } catch (PDOException $e) {
        $errorMsg = "✗ Error adding index '{$indexName}' on table '{$table}': " . $e->getMessage();
        echo $errorMsg . "\n";
        $errors[] = $errorMsg;
        $errorCount++;
    }
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "SUMMARY:\n";
echo "  Created: {$successCount} indexes\n";
echo "  Already Existed: {$existsCount} indexes\n";
echo "  Errors: {$errorCount} indexes\n";

if (!empty($errors)) {
    echo "\nERRORS:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

echo "\nDone!\n";

