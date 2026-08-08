<?php
try {
    require_once __DIR__ . '/../db_connection.php';
    $pdo = getConnection();

    // Add enable_km_delivery column
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'enable_km_delivery'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN enable_km_delivery TINYINT(1) DEFAULT 0 AFTER delivery_radius_km");
        echo "Column enable_km_delivery added successfully.\n";
    } else {
        echo "Column enable_km_delivery already exists.\n";
    }

    // Add delivery_rate_per_km column
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'delivery_rate_per_km'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN delivery_rate_per_km DECIMAL(10,2) DEFAULT 0.00 AFTER enable_km_delivery");
        echo "Column delivery_rate_per_km added successfully.\n";
    } else {
        echo "Column delivery_rate_per_km already exists.\n";
    }

} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
