<?php
/**
 * Run Menu Item Variations Table Migration on Both Production and Localhost
 * This script will create the menu_item_variations table on both databases
 */

session_start();
if (!isset($_SESSION['superadmin_id']) && !isset($_SESSION['user_id'])) {
    die('Unauthorized. Please login first.');
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Run Variations Table Migration</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 1200px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #333; margin-bottom: 10px; }
        .subtitle { color: #666; margin-bottom: 30px; }
        .db-section {
            margin: 30px 0;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 8px;
            border-left: 4px solid #2196F3;
        }
        .db-section h2 { margin-top: 0; color: #2196F3; }
        .success { color: #4CAF50; font-weight: bold; }
        .error { color: #f44336; font-weight: bold; }
        .info { color: #2196F3; }
        .warning { color: #FF9800; }
        pre {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            border: 1px solid #ddd;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #2196F3;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 10px 5px;
            border: none;
            cursor: pointer;
        }
        .btn:hover { background: #1976D2; }
        .btn-success { background: #4CAF50; }
        .btn-success:hover { background: #45a049; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Menu Item Variations Table Migration</h1>
        <p class="subtitle">Create menu_item_variations table on both production and localhost databases</p>

        <?php
        require_once __DIR__ . '/../db_connection.php';

        // Read the SQL file
        $sqlFile = __DIR__ . '/../database/add_menu_item_variations_table.sql';
        if (!file_exists($sqlFile)) {
            die('<div class="error">❌ SQL file not found: ' . $sqlFile . '</div>');
        }

        $sql = file_get_contents($sqlFile);

        // Production Database (main)
        echo '<div class="db-section">';
        echo '<h2>🌐 Production Database (Main)</h2>';
        
        try {
            global $pdo;
            $conn = $pdo;
            
            // Check if table already exists
            $checkTable = $conn->query("SHOW TABLES LIKE 'menu_item_variations'");
            if ($checkTable->rowCount() > 0) {
                echo '<div class="warning">⚠️ Table "menu_item_variations" already exists in production database.</div>';
                echo '<div class="info">ℹ️ Skipping creation. Table is ready to use.</div>';
            } else {
                // Execute the SQL
                $conn->exec($sql);
                echo '<div class="success">✅ Successfully created "menu_item_variations" table in production database!</div>';
            }
            
            // Show table structure
            try {
                $descStmt = $conn->query("DESCRIBE menu_item_variations");
                $columns = $descStmt->fetchAll(PDO::FETCH_ASSOC);
                echo '<h3>Table Structure:</h3>';
                echo '<pre>';
                echo "Table: menu_item_variations\n";
                echo str_repeat('-', 80) . "\n";
                printf("%-20s %-20s %-10s %-10s %-10s\n", "Field", "Type", "Null", "Key", "Default");
                echo str_repeat('-', 80) . "\n";
                foreach ($columns as $col) {
                    printf("%-20s %-20s %-10s %-10s %-10s\n", 
                        $col['Field'], 
                        $col['Type'], 
                        $col['Null'], 
                        $col['Key'], 
                        $col['Default'] ?? 'NULL'
                    );
                }
                echo '</pre>';
            } catch (PDOException $e) {
                echo '<div class="error">Error describing table: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            
        } catch (PDOException $e) {
            echo '<div class="error">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        
        echo '</div>';

        // Localhost Database
        echo '<div class="db-section">';
        echo '<h2>💻 Localhost Database</h2>';
        
        try {
            // Localhost connection
            $localhostConfig = [
                'host' => 'localhost',
                'dbname' => 'restro2',
                'username' => 'root',
                'password' => ''
            ];
            
            $localhostPdo = new PDO(
                "mysql:host={$localhostConfig['host']};dbname={$localhostConfig['dbname']};charset=utf8mb4",
                $localhostConfig['username'],
                $localhostConfig['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_PERSISTENT => true
                ]
            );
            
            // Check if table already exists
            $checkTable = $localhostPdo->query("SHOW TABLES LIKE 'menu_item_variations'");
            if ($checkTable->rowCount() > 0) {
                echo '<div class="warning">⚠️ Table "menu_item_variations" already exists in localhost database.</div>';
                echo '<div class="info">ℹ️ Skipping creation. Table is ready to use.</div>';
            } else {
                // Execute the SQL
                $localhostPdo->exec($sql);
                echo '<div class="success">✅ Successfully created "menu_item_variations" table in localhost database!</div>';
            }
            
            // Show table structure
            try {
                $descStmt = $localhostPdo->query("DESCRIBE menu_item_variations");
                $columns = $descStmt->fetchAll(PDO::FETCH_ASSOC);
                echo '<h3>Table Structure:</h3>';
                echo '<pre>';
                echo "Table: menu_item_variations\n";
                echo str_repeat('-', 80) . "\n";
                printf("%-20s %-20s %-10s %-10s %-10s\n", "Field", "Type", "Null", "Key", "Default");
                echo str_repeat('-', 80) . "\n";
                foreach ($columns as $col) {
                    printf("%-20s %-20s %-10s %-10s %-10s\n", 
                        $col['Field'], 
                        $col['Type'], 
                        $col['Null'], 
                        $col['Key'], 
                        $col['Default'] ?? 'NULL'
                    );
                }
                echo '</pre>';
            } catch (PDOException $e) {
                echo '<div class="error">Error describing table: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'No connection could be made') !== false || 
                strpos($e->getMessage(), 'Access denied') !== false) {
                echo '<div class="warning">⚠️ Could not connect to localhost database. Make sure MySQL is running.</div>';
                echo '<div class="info">ℹ️ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            } else {
                echo '<div class="error">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
        
        echo '</div>';

        echo '<div style="margin-top: 30px; padding: 20px; background: #e3f2fd; border-radius: 8px;">';
        echo '<h3>✅ Migration Complete!</h3>';
        echo '<p>The <code>menu_item_variations</code> table has been created (or already exists) on both databases.</p>';
        echo '<p>You can now use the variations feature in menu items.</p>';
        echo '<p><a href="../views/dashboard.php" class="btn btn-success">Go to Dashboard</a></p>';
        echo '</div>';
        ?>
    </div>
</body>
</html>

