<?php
// order_items.menu_item_id was NOT NULL, but process_website_order.php
// legitimately inserts NULL there for cart-wide "global add-ons" that
// aren't tied to any specific menu item - which made every order
// containing one fail with "Column 'menu_item_id' cannot be null".
try {
    require_once __DIR__ . '/../db_connection.php';
    $pdo = getConnection();

    $stmt = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'menu_item_id'");
    $col = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($col && strtoupper($col['Null']) === 'NO') {
        $pdo->exec("ALTER TABLE order_items MODIFY menu_item_id INT(11) NULL DEFAULT NULL");
        echo "Column order_items.menu_item_id is now nullable.\n";
    } else {
        echo "Column order_items.menu_item_id is already nullable.\n";
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
