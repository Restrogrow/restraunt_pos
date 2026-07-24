<?php
/**
 * Migration Script: Create contact_queries table
 * Run this script to ensure the contact_queries table exists in the database
 */

require_once __DIR__ . '/../db_connection.php';

try {
    $conn = getConnection();
    
    echo "Checking for contact_queries table...\n";
    
    // Check if table exists
    $checkTable = $conn->query("SHOW TABLES LIKE 'contact_queries'");
    
    if ($checkTable->rowCount() === 0) {
        echo "Table does not exist. Creating contact_queries table...\n";
        
        $sql = "
            CREATE TABLE IF NOT EXISTS contact_queries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                phone VARCHAR(50),
                message TEXT NOT NULL,
                status ENUM('new', 'read', 'replied', 'closed') DEFAULT 'new',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_created_at (created_at),
                INDEX idx_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $conn->exec($sql);
        
        echo "✓ Table 'contact_queries' created successfully!\n";
    } else {
        echo "✓ Table 'contact_queries' already exists.\n";
    }
    
    // Verify table structure
    $stmt = $conn->query("DESCRIBE contact_queries");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nTable structure:\n";
    echo str_repeat("-", 60) . "\n";
    printf("%-20s %-20s %-10s\n", "Field", "Type", "Null");
    echo str_repeat("-", 60) . "\n";
    foreach ($columns as $column) {
        printf("%-20s %-20s %-10s\n", 
            $column['Field'], 
            $column['Type'], 
            $column['Null']
        );
    }
    
    // Check if there are any existing records
    $stmt = $conn->query("SELECT COUNT(*) as count FROM contact_queries");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "\n✓ Total records in table: $count\n";
    
    echo "\nMigration completed successfully!\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

