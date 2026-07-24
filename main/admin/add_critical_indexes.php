<?php
/**
 * Add Critical Database Indexes
 * Run this to add all critical indexes for performance
 * Safe to run multiple times - checks if indexes exist first
 */

require_once __DIR__ . '/../db_connection.php';

// Only allow superadmin or admin
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
    <title>Add Critical Database Indexes</title>
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
        h1 {
            color: #333;
            margin-bottom: 10px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
        }
        .index-item {
            background: #f9f9f9;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
            border-left: 4px solid #4CAF50;
        }
        .index-item.error {
            border-left-color: #f44336;
        }
        .index-item.exists {
            border-left-color: #ff9800;
        }
        .index-name {
            font-weight: 600;
            color: #333;
        }
        .index-table {
            color: #666;
            font-size: 0.9em;
        }
        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 3px;
            font-size: 0.85em;
            font-weight: 600;
            margin-top: 5px;
        }
        .status.success {
            background: #4CAF50;
            color: white;
        }
        .status.error {
            background: #f44336;
            color: white;
        }
        .status.exists {
            background: #ff9800;
            color: white;
        }
        .summary {
            margin-top: 30px;
            padding: 20px;
            background: #e3f2fd;
            border-radius: 5px;
        }
        .summary h2 {
            margin-top: 0;
            color: #1976d2;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #2196F3;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }
        .btn:hover {
            background: #1976d2;
        }
        pre {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Add Critical Database Indexes</h1>
        <p class="subtitle">This will add all critical indexes to improve database performance. Safe to run multiple times.</p>

        <?php
        $results = [];
        $successCount = 0;
        $existsCount = 0;
        $errorCount = 0;

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

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_indexes'])) {
            echo '<h2>Adding Indexes...</h2>';

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
                        $results[] = [
                            'table' => $table,
                            'name' => $indexName,
                            'status' => 'exists',
                            'message' => 'Index already exists'
                        ];
                        $existsCount++;
                    } else {
                        // Add index
                        $sql = "ALTER TABLE `$table` ADD INDEX `$indexName` ($columns)";
                        $pdo->exec($sql);
                        
                        $results[] = [
                            'table' => $table,
                            'name' => $indexName,
                            'status' => 'success',
                            'message' => 'Index created successfully'
                        ];
                        $successCount++;
                    }
                } catch (PDOException $e) {
                    $results[] = [
                        'table' => $table,
                        'name' => $indexName,
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ];
                    $errorCount++;
                }
            }

            // Display results
            echo '<div class="summary">';
            echo '<h2>Summary</h2>';
            echo "<p><strong>Created:</strong> $successCount indexes</p>";
            echo "<p><strong>Already Existed:</strong> $existsCount indexes</p>";
            echo "<p><strong>Errors:</strong> $errorCount indexes</p>";
            echo '</div>';

            echo '<h2>Detailed Results</h2>';
            foreach ($results as $result) {
                $statusClass = $result['status'];
                echo '<div class="index-item ' . $statusClass . '">';
                echo '<div class="index-name">' . htmlspecialchars($result['name']) . '</div>';
                echo '<div class="index-table">Table: ' . htmlspecialchars($result['table']) . '</div>';
                echo '<span class="status ' . $statusClass . '">' . ucfirst($result['status']) . '</span>';
                echo '<div style="margin-top: 5px; color: #666; font-size: 0.9em;">' . htmlspecialchars($result['message']) . '</div>';
                echo '</div>';
            }
        } else {
            // Show form
            echo '<h2>Indexes to be Added</h2>';
            echo '<ul>';
            foreach ($indexes as $index) {
                echo '<li><strong>' . htmlspecialchars($index['name']) . '</strong> on table <code>' . htmlspecialchars($index['table']) . '</code> (' . htmlspecialchars($index['columns']) . ')</li>';
            }
            echo '</ul>';

            echo '<form method="POST">';
            echo '<button type="submit" name="add_indexes" class="btn">Add All Indexes</button>';
            echo '</form>';
        }

        // Show current indexes
        echo '<h2>Current Indexes</h2>';
        try {
            $stmt = $pdo->query("
                SELECT 
                    TABLE_NAME,
                    INDEX_NAME,
                    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS COLUMNS
                FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME IN ('users', 'menu_items', 'orders', 'menu', 'payments')
                    AND INDEX_NAME LIKE 'idx_%'
                GROUP BY TABLE_NAME, INDEX_NAME
                ORDER BY TABLE_NAME, INDEX_NAME
            ");
            $currentIndexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($currentIndexes)) {
                echo '<p>No indexes found. Click the button above to add them.</p>';
            } else {
                echo '<pre>';
                foreach ($currentIndexes as $idx) {
                    echo $idx['TABLE_NAME'] . '.' . $idx['INDEX_NAME'] . ' (' . $idx['COLUMNS'] . ")\n";
                }
                echo '</pre>';
            }
        } catch (PDOException $e) {
            echo '<p style="color: red;">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        ?>

        <div style="margin-top: 30px; padding: 15px; background: #fff3cd; border-radius: 5px;">
            <strong>Note:</strong> This script is safe to run multiple times. It checks if indexes exist before creating them.
        </div>
    </div>
</body>
</html>

