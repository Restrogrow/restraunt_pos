<?php
/**
 * Run password_reset_tokens table migration on both production and localhost databases
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
    <title>Password Reset Tokens Table Migration</title>
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
        .status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.85em;
            font-weight: 600;
            margin-left: 10px;
        }
        .status.success { background: #4CAF50; color: white; }
        .status.error { background: #f44336; color: white; }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #2196F3;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 10px 5px;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }
        .btn:hover { background: #1976d2; }
        .btn.local { background: #4CAF50; }
        .btn.local:hover { background: #45a049; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Password Reset Tokens Table Migration</h1>
        <p class="subtitle">This will create the password_reset_tokens table on both production and localhost databases.</p>

        <?php
        // Include main database connection (production)
        require_once __DIR__ . '/../db_connection.php';
        $prodPdo = $pdo;

        // Localhost connection
        $localPdo = null;
        try {
            $localDsn = "mysql:host=localhost;dbname=restro2;charset=utf8mb4";
            $localPdo = new PDO($localDsn, 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (PDOException $e) {
            $localError = "Could not connect to localhost: " . $e->getMessage();
        }

        $migrationFile = __DIR__ . '/../database/add_password_reset_tokens_table.sql';
        $sql = file_get_contents($migrationFile);

        function runMigration($pdo, $name) {
            global $sql;
            
            try {
                echo "<h3>$name Database</h3>";
                
                // Check if table exists
                $checkStmt = $pdo->query("SHOW TABLES LIKE 'password_reset_tokens'");
                $tableExists = $checkStmt->rowCount() > 0;
                
                if ($tableExists) {
                    echo "<p>✓ Table 'password_reset_tokens' already exists.</p>";
                    
                    // Check if idx_created_at index exists
                    $indexStmt = $pdo->query("
                        SELECT COUNT(*) as count
                        FROM INFORMATION_SCHEMA.STATISTICS
                        WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'password_reset_tokens'
                        AND INDEX_NAME = 'idx_created_at'
                    ");
                    $indexExists = $indexStmt->fetch()['count'] > 0;
                    
                    if (!$indexExists) {
                        echo "<p>Adding missing idx_created_at index...</p>";
                        $pdo->exec("CREATE INDEX idx_created_at ON password_reset_tokens(created_at)");
                        echo "<p>✓ Index 'idx_created_at' added successfully.</p>";
                    } else {
                        echo "<p>✓ Index 'idx_created_at' already exists.</p>";
                    }
                } else {
                    echo "<p>Creating table 'password_reset_tokens'...</p>";
                    
                    // Split SQL by semicolons and execute each statement
                    $statements = array_filter(array_map('trim', explode(';', $sql)));
                    
                    foreach ($statements as $statement) {
                        if (!empty($statement) && !preg_match('/^\s*--/', $statement)) {
                            $pdo->exec($statement);
                        }
                    }
                    
                    echo "<p>✓ Table 'password_reset_tokens' created successfully.</p>";
                }
                
                // Show table structure
                $stmt = $pdo->query("DESCRIBE password_reset_tokens");
                $columns = $stmt->fetchAll();
                echo "<p><strong>Table structure:</strong></p><pre>";
                foreach ($columns as $col) {
                    echo "{$col['Field']} - {$col['Type']}\n";
                }
                echo "</pre>";
                
                // Show indexes
                $indexStmt = $pdo->query("SHOW INDEXES FROM password_reset_tokens");
                $indexes = $indexStmt->fetchAll();
                echo "<p><strong>Indexes:</strong></p><pre>";
                foreach ($indexes as $idx) {
                    echo "{$idx['Key_name']} on {$idx['Column_name']}\n";
                }
                echo "</pre>";
                
                return true;
            } catch (PDOException $e) {
                echo "<p style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
                return false;
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_POST['run_production'])) {
                echo '<div class="db-section">';
                $productionSuccess = runMigration($prodPdo, 'PRODUCTION');
                echo '</div>';
            }

            if (isset($_POST['run_localhost']) && $localPdo) {
                echo '<div class="db-section">';
                $localhostSuccess = runMigration($localPdo, 'LOCALHOST');
                echo '</div>';
            } elseif (isset($_POST['run_localhost']) && !$localPdo) {
                echo '<div class="db-section">';
                echo '<h2>Localhost Database</h2>';
                echo '<p style="color: red;">' . htmlspecialchars($localError ?? 'Could not connect to localhost database') . '</p>';
                echo '</div>';
            }
        }
        ?>

        <form method="POST">
            <div class="db-section">
                <h2>Production Database (Hostinger)</h2>
                <p>Create password_reset_tokens table on the production database.</p>
                <button type="submit" name="run_production" class="btn">Run on Production</button>
            </div>

            <div class="db-section">
                <h2>Localhost Database</h2>
                <?php if ($localPdo): ?>
                    <p>Create password_reset_tokens table on the localhost database.</p>
                    <button type="submit" name="run_localhost" class="btn local">Run on Localhost</button>
                <?php else: ?>
                    <p style="color: #f44336;">Could not connect to localhost database. Make sure XAMPP is running and database 'restro2' exists.</p>
                <?php endif; ?>
            </div>
        </form>

        <div style="margin-top: 30px; padding: 15px; background: #fff3cd; border-radius: 5px;">
            <strong>Note:</strong> This script is safe to run multiple times. It checks if the table exists before creating it.
        </div>
    </div>
</body>
</html>

