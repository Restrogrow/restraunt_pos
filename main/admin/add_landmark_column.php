<?php
try {
    require_once __DIR__ . '/../db_connection.php';
    $pdo = getConnection();

    $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'landmark'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN landmark VARCHAR(255) DEFAULT NULL AFTER customer_address");
        echo "Column landmark added successfully.\n";
    } else {
        echo "Column landmark already exists.\n";
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
