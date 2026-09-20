<?php
try {
    require_once __DIR__ . '/../db_connection.php';
    $pdo = getConnection();

    $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'prep_minutes'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN prep_minutes INT DEFAULT NULL AFTER order_status");
        echo "Column prep_minutes added successfully.\n";
    } else {
        echo "Column prep_minutes already exists.\n";
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'estimated_ready_at'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN estimated_ready_at DATETIME DEFAULT NULL AFTER prep_minutes");
        echo "Column estimated_ready_at added successfully.\n";
    } else {
        echo "Column estimated_ready_at already exists.\n";
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
