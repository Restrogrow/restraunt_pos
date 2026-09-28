<?php
// One-time DB maintenance script — CLI or logged-in admin only. Anonymous
// visitors used to be able to run DDL against the live DB by just opening
// this URL.
require_once __DIR__ . '/../config/maintenance_guard.php';
enforceMaintenanceAccess();

try {
    require_once __DIR__ . '/../db_connection.php';
    $pdo = getConnection();
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'opening_hours'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN opening_hours TEXT NULL DEFAULT NULL COMMENT 'JSON: per-day opening/closing times' AFTER address");
        echo "Column opening_hours added successfully.\n";
    } else {
        echo "Column opening_hours already exists.\n";
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
