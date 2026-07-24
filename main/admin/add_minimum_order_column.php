<?php
try {
    require_once __DIR__ . '/../db_connection.php';
    $pdo = getConnection();
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'minimum_order_value'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN minimum_order_value DECIMAL(10,2) DEFAULT 0.00 AFTER phonepe_environment");
        echo "Column minimum_order_value added successfully.\n";
    } else {
        echo "Column minimum_order_value already exists.\n";
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
