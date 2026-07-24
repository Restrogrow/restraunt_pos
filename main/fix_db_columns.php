<?php
// Fix database columns for BLOB image storage
// Run once by visiting: http://localhost/menuwebsite/main/fix_db_columns.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/db_connection.php';

$conn = getConnection();
$results = [];

// Fix users table
$tables = [
    'users' => [
        ['logo_data', 'LONGBLOB NULL', 'restaurant_logo'],
        ['logo_mime_type', 'VARCHAR(50) NULL', 'logo_data'],
    ],
    'menu_items' => [
        ['image_data', 'LONGBLOB NULL', 'item_image'],
        ['image_mime_type', 'VARCHAR(50) NULL', 'image_data'],
    ],
    'website_banners' => [
        ['banner_data', 'LONGBLOB NULL', 'banner_path'],
        ['banner_mime_type', 'VARCHAR(50) NULL', 'banner_data'],
    ],
    'menu' => [
        ['menu_image_data', 'LONGBLOB NULL', 'menu_image'],
        ['menu_image_mime_type', 'VARCHAR(50) NULL', 'menu_image_data'],
    ],
];

foreach ($tables as $table => $columns) {
    foreach ($columns as [$col, $def, $after]) {
        try {
            $conn->exec("ALTER TABLE $table ADD COLUMN $col $def AFTER $after");
            $results[] = "<span style='color:green'>✓</span> Added `$col` to `$table`";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                $results[] = "<span style='color:gray'>–</span> `$col` already exists in `$table`";
            } else {
                $results[] = "<span style='color:red'>✗</span> Failed to add `$col` to `$table`: " . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// Add 'Dessert' to item_type ENUM
try {
    $origMode = $conn->getAttribute(PDO::ATTR_ERRMODE);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
    $conn->exec("ALTER TABLE menu_items MODIFY COLUMN item_type ENUM('Veg', 'Non Veg', 'Egg', 'Drink', 'Dessert', 'Other') DEFAULT 'Veg'");
    $err = $conn->errorInfo();
    $conn->setAttribute(PDO::ATTR_ERRMODE, $origMode);
    if ($err[0] === '00000') {
        $results[] = "<span style='color:green'>✓</span> Added 'Dessert' to `item_type` ENUM in `menu_items`";
    } elseif (strpos($err[2], 'Data truncated') !== false) {
        $results[] = "<span style='color:green'>✓</span> Added 'Dessert' to `item_type` ENUM in `menu_items` (with data conversion warnings)";
    } else {
        $results[] = "<span style='color:orange'>⚠</span> ENUM update: " . htmlspecialchars($err[2]);
    }
} catch (Exception $e) {
    $results[] = "<span style='color:red'>✗</span> Failed to update item_type ENUM: " . htmlspecialchars($e->getMessage());
}

// For users that have restaurant_logo = 'db:XXX' but no logo_data, log a warning
try {
    $stmt = $conn->query("SELECT id, restaurant_name, restaurant_logo FROM users WHERE restaurant_logo LIKE 'db:%' AND (logo_data IS NULL OR logo_data = '')");
    $missing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($missing as $row) {
        $results[] = "<span style='color:orange'>⚠</span> User #{$row['id']} ({$row['restaurant_name']}) has db: reference but no BLOB data — re-upload the logo in admin dashboard";
    }
} catch (PDOException $e) {
    // Column might not exist yet
}

?>
<!DOCTYPE html>
<html><head><title>DB Column Fix</title>
<style>body{font-family:sans-serif;padding:20px;max-width:700px;margin:0 auto}
h1{color:#333}.ok{color:green}.warn{color:orange}.err{color:red}
li{padding:4px 0}</style></head>
<body>
<h1>Database Column Migration</h1>
<ul>
<?php foreach ($results as $r): ?>
  <li><?= $r ?></li>
<?php endforeach; ?>
</ul>
<?php if (!empty($missing)): ?>
  <p style="color:orange"><strong>Action needed:</strong> Re-upload your logo in the admin dashboard for the BLOB data to be stored.</p>
<?php endif; ?>
<p><a href="javascript:location.reload()">Run again</a></p>
</body>
</html>
