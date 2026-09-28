<?php
// One-time DB maintenance script — CLI or logged-in admin only. Anonymous
// visitors used to be able to run DDL against the live DB by just opening
// this URL.
require_once __DIR__ . '/../config/maintenance_guard.php';
enforceMaintenanceAccess();

try {
    require_once __DIR__ . '/../db_connection.php';
    $pdo = getConnection();
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'cod_enabled'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN cod_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER enable_dinein");
        echo "Column cod_enabled added successfully.\n";
    } else {
        echo "Column cod_enabled already exists.\n";
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
