<?php
/**
 * Run Contact Queries Table Migration
 * Access this file via browser to create the contact_queries table
 * URL: https://restrogrow.com/main/admin/run_contact_migration.php
 */

// Simple security - you can add password protection later
// For now, this will run the migration

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../db_connection.php';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Contact Queries Migration</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        .success { color: #10b981; background: #d1fae5; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .error { color: #ef4444; background: #fee2e2; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .info { color: #3b82f6; background: #dbeafe; padding: 10px; border-radius: 4px; margin: 10px 0; }
        pre { background: #f3f4f6; padding: 15px; border-radius: 4px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f9fafb; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Contact Queries Table Migration</h1>
        
<?php

try {
    echo "<div class='info'>Connecting to database...</div>\n";
    
    $conn = getConnection();
    
    echo "<div class='success'>✓ Database connection successful!</div>\n";
    
    // Check if table exists
    echo "<div class='info'>Checking for contact_queries table...</div>\n";
    
    $checkTable = $conn->query("SHOW TABLES LIKE 'contact_queries'");
    
    if ($checkTable->rowCount() === 0) {
        echo "<div class='info'>Table does not exist. Creating contact_queries table...</div>\n";
        
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
        
        echo "<div class='success'>✓ Table 'contact_queries' created successfully!</div>\n";
    } else {
        echo "<div class='success'>✓ Table 'contact_queries' already exists.</div>\n";
    }
    
    // Verify table structure
    echo "<h2>Table Structure</h2>\n";
    $stmt = $conn->query("DESCRIBE contact_queries");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table>\n";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>\n";
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Default'] ?? 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";
    
    // Check indexes
    echo "<h2>Indexes</h2>\n";
    $stmt = $conn->query("SHOW INDEXES FROM contact_queries");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($indexes) > 0) {
        echo "<table>\n";
        echo "<tr><th>Key Name</th><th>Column</th><th>Non Unique</th></tr>\n";
        foreach ($indexes as $index) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($index['Key_name']) . "</td>";
            echo "<td>" . htmlspecialchars($index['Column_name']) . "</td>";
            echo "<td>" . ($index['Non_unique'] ? 'Yes' : 'No') . "</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
    
    // Check if there are any existing records
    $stmt = $conn->query("SELECT COUNT(*) as count FROM contact_queries");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "<div class='info'>Total records in table: <strong>$count</strong></div>\n";
    
    // Show recent records if any
    if ($count > 0) {
        echo "<h2>Recent Contact Queries (Last 5)</h2>\n";
        $stmt = $conn->query("SELECT id, name, email, phone, status, created_at FROM contact_queries ORDER BY created_at DESC LIMIT 5");
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($records) > 0) {
            echo "<table>\n";
            echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Status</th><th>Created At</th></tr>\n";
            foreach ($records as $record) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($record['id']) . "</td>";
                echo "<td>" . htmlspecialchars($record['name']) . "</td>";
                echo "<td>" . htmlspecialchars($record['email']) . "</td>";
                echo "<td>" . htmlspecialchars($record['phone'] ?? '-') . "</td>";
                echo "<td>" . htmlspecialchars($record['status']) . "</td>";
                echo "<td>" . htmlspecialchars($record['created_at']) . "</td>";
                echo "</tr>\n";
            }
            echo "</table>\n";
        }
    }
    
    echo "<div class='success'><strong>✓ Migration completed successfully!</strong></div>\n";
    
} catch (Exception $e) {
    echo "<div class='error'><strong>✗ Error:</strong> " . htmlspecialchars($e->getMessage()) . "</div>\n";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>\n";
}

?>
    </div>
</body>
</html>

