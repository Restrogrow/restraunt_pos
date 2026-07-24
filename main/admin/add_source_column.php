<?php
try {
    require_once __DIR__ . '/../db_connection.php';
    $pdo = getConnection();
    $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'source'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN source ENUM('pos','website') NOT NULL DEFAULT 'pos' AFTER order_status");
        echo "Column source added successfully.\n";
        // Tag existing orders that came from website (table_id IS NULL AND order_status = 'Pending')
        $pdo->exec("UPDATE orders SET source = 'website' WHERE table_id IS NULL AND order_status = 'Pending' AND (order_type = 'Delivery' OR order_type = 'Takeaway')");
        echo "Existing online orders tagged.\n";
    } else {
        echo "Column source already exists.\n";
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
