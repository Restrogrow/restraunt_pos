<?php
/**
 * Quick check if variations table exists
 */
require_once __DIR__ . '/../db_connection.php';

header('Content-Type: text/plain; charset=UTF-8');

try {
    global $pdo;
    $conn = $pdo;
    
    // Check if table exists
    $checkTable = $conn->query("SHOW TABLES LIKE 'menu_item_variations'");
    
    if ($checkTable->rowCount() > 0) {
        echo "✅ Table 'menu_item_variations' EXISTS in production database\n\n";
        
        // Show structure
        $descStmt = $conn->query("DESCRIBE menu_item_variations");
        $columns = $descStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Table Structure:\n";
        echo str_repeat('-', 80) . "\n";
        printf("%-20s %-25s %-10s %-10s\n", "Field", "Type", "Null", "Key");
        echo str_repeat('-', 80) . "\n";
        foreach ($columns as $col) {
            printf("%-20s %-25s %-10s %-10s\n", 
                $col['Field'], 
                $col['Type'], 
                $col['Null'], 
                $col['Key']
            );
        }
        
        // Count variations
        $countStmt = $conn->query("SELECT COUNT(*) as count FROM menu_item_variations");
        $count = $countStmt->fetch(PDO::FETCH_ASSOC);
        echo "\nTotal variations: " . $count['count'] . "\n";
    } else {
        echo "❌ Table 'menu_item_variations' DOES NOT EXIST in production database\n";
        echo "Please run: admin/run_variations_table_both_dbs.php\n";
    }
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

