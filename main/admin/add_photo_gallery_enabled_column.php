<?php
try {
    require_once __DIR__ . '/../db_connection.php';
    $pdo = getConnection();
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'photo_gallery_enabled'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN photo_gallery_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER cod_enabled");
        echo "Column photo_gallery_enabled added successfully.\n";
    } else {
        echo "Column photo_gallery_enabled already exists.\n";
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
