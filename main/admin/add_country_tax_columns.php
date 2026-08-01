<?php
// One-time migration: adds the country/tax_name/tax_percent columns that
// signup, restaurant settings, and the dashboard all already assume exist.
// Run once against a database that predates the international/GST-naming
// work, then delete this file.
try {
    require_once __DIR__ . '/../db_connection.php';
    $pdo = getConnection();

    $columns = [
        'country' => "ALTER TABLE users ADD COLUMN country VARCHAR(100) DEFAULT 'India'",
        'tax_name' => "ALTER TABLE users ADD COLUMN tax_name VARCHAR(50) DEFAULT 'GST'",
        'tax_percent' => "ALTER TABLE users ADD COLUMN tax_percent DECIMAL(5,2) DEFAULT 5.00",
    ];

    foreach ($columns as $column => $sql) {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE '$column'");
        if (!$stmt->fetch()) {
            $pdo->exec($sql);
            echo "Column $column added successfully.\n";
        } else {
            echo "Column $column already exists.\n";
        }
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
