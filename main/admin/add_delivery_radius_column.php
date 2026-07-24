<?php
try {
    require_once __DIR__ . '/../db_connection.php';
    $pdo = getConnection();
    
    // Add delivery_radius_km column
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'delivery_radius_km'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN delivery_radius_km DECIMAL(10,2) DEFAULT 0.00 AFTER packaging_charge");
        echo "Column delivery_radius_km added successfully.\n";
    } else {
        echo "Column delivery_radius_km already exists.\n";
    }
    
    // Add restaurant_lat column
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'restaurant_lat'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN restaurant_lat DECIMAL(10,7) DEFAULT NULL AFTER delivery_radius_km");
        echo "Column restaurant_lat added successfully.\n";
    } else {
        echo "Column restaurant_lat already exists.\n";
    }
    
    // Add restaurant_lng column
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'restaurant_lng'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN restaurant_lng DECIMAL(10,7) DEFAULT NULL AFTER restaurant_lat");
        echo "Column restaurant_lng added successfully.\n";
    } else {
        echo "Column restaurant_lng already exists.\n";
    }
    
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
