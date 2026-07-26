<?php
try {
    require_once __DIR__ . '/../db_connection.php';
    $pdo = getConnection();

    $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'address_lat'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN address_lat DECIMAL(10,7) DEFAULT NULL AFTER customer_address");
        echo "Column address_lat added successfully.\n";
    } else {
        echo "Column address_lat already exists.\n";
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'address_lng'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN address_lng DECIMAL(10,7) DEFAULT NULL AFTER address_lat");
        echo "Column address_lng added successfully.\n";
    } else {
        echo "Column address_lng already exists.\n";
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
