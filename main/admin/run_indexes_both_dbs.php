<?php
/**
 * Run Critical Indexes on Both Production and Localhost
 * This script will add critical indexes to both databases
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
    <title>Run Indexes on Both Databases</title>
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
        .index-item {
            background: white;
            padding: 10px 15px;
            margin: 5px 0;
            border-radius: 5px;
            border-left: 3px solid #4CAF50;
        }
        .index-item.error { border-left-color: #f44336; }
        .index-item.exists { border-left-color: #ff9800; }
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
        .status.exists { background: #ff9800; color: white; }
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
    </style>
</head>
<body>
    <div class="container">
        <h1>Run Critical Indexes on Both Databases</h1>
        <p class="subtitle">This will add critical indexes to improve database performance on both production and localhost.</p>

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

        function addIndexes($pdo, $indexes, $dbName) {
            $results = [];
            $successCount = 0;
            $existsCount = 0;
            $errorCount = 0;

            foreach ($indexes as $index) {
                $table = $index['table'];
                $indexName = $index['name'];
                $columns = $index['columns'];

                try {
                    // Check if index exists
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
                        $results[] = ['table' => $table, 'name' => $indexName, 'status' => 'exists', 'message' => 'Already exists'];
                        $existsCount++;
                    } else {
                        // Add index
                        $sql = "ALTER TABLE `$table` ADD INDEX `$indexName` ($columns)";
                        $pdo->exec($sql);
                        $results[] = ['table' => $table, 'name' => $indexName, 'status' => 'success', 'message' => 'Created'];
                        $successCount++;
                    }
                } catch (PDOException $e) {
                    $results[] = ['table' => $table, 'name' => $indexName, 'status' => 'error', 'message' => $e->getMessage()];
                    $errorCount++;
                }
            }

            return ['results' => $results, 'success' => $successCount, 'exists' => $existsCount, 'errors' => $errorCount];
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $prodResults = null;
            $localResults = null;

            if (isset($_POST['run_production'])) {
                echo '<div class="db-section">';
                echo '<h2>Production Database Results</h2>';
                $prodResults = addIndexes($prodPdo, $indexes, 'Production');
                echo "<p><strong>Created:</strong> {$prodResults['success']} | <strong>Already Existed:</strong> {$prodResults['exists']} | <strong>Errors:</strong> {$prodResults['errors']}</p>";
                foreach ($prodResults['results'] as $result) {
                    echo '<div class="index-item ' . $result['status'] . '">';
                    echo htmlspecialchars($result['name']) . ' on ' . htmlspecialchars($result['table']);
                    echo '<span class="status ' . $result['status'] . '">' . ucfirst($result['status']) . '</span>';
                    echo '</div>';
                }
                echo '</div>';
            }

            if (isset($_POST['run_localhost']) && $localPdo) {
                echo '<div class="db-section">';
                echo '<h2>Localhost Database Results</h2>';
                $localResults = addIndexes($localPdo, $indexes, 'Localhost');
                echo "<p><strong>Created:</strong> {$localResults['success']} | <strong>Already Existed:</strong> {$localResults['exists']} | <strong>Errors:</strong> {$localResults['errors']}</p>";
                foreach ($localResults['results'] as $result) {
                    echo '<div class="index-item ' . $result['status'] . '">';
                    echo htmlspecialchars($result['name']) . ' on ' . htmlspecialchars($result['table']);
                    echo '<span class="status ' . $result['status'] . '">' . ucfirst($result['status']) . '</span>';
                    echo '</div>';
                }
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
                <p>Add indexes to the production database on Hostinger.</p>
                <button type="submit" name="run_production" class="btn">Run on Production</button>
            </div>

            <div class="db-section">
                <h2>Localhost Database</h2>
                <?php if ($localPdo): ?>
                    <p>Add indexes to the localhost database.</p>
                    <button type="submit" name="run_localhost" class="btn local">Run on Localhost</button>
                <?php else: ?>
                    <p style="color: #f44336;">Could not connect to localhost database. Make sure XAMPP is running and database 'restro2' exists.</p>
                <?php endif; ?>
            </div>
        </form>

        <div style="margin-top: 30px; padding: 15px; background: #fff3cd; border-radius: 5px;">
            <strong>Note:</strong> This script is safe to run multiple times. It checks if indexes exist before creating them.
        </div>
    </div>
</body>
</html>

