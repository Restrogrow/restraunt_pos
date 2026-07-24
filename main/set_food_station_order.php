<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/db_connection.php';
header('Content-Type: text/plain; charset=utf-8');

try {
    $conn = getConnection();
    echo "✅ Connected\n\n";
} catch (Exception $e) {
    die("❌ Connection failed: " . $e->getMessage());
}

// Find user with ID 21
$stmt = $conn->prepare("SELECT id, restaurant_id, restaurant_name, username FROM users WHERE id = ?");
$stmt->execute([21]);
$restaurant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$restaurant) {
    echo "❌ No user with ID 21 found.\n";
    
    // Show some users
    $users = $conn->query("SELECT id, restaurant_id, restaurant_name, username FROM users ORDER BY id LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    echo "Users in database:\n";
    foreach ($users as $u) {
        echo "  ID:{$u['id']} | restaurant_id:{$u['restaurant_id']} | name:{$u['restaurant_name']} | username:{$u['username']}\n";
    }
    exit;
}

echo "Found: " . json_encode($restaurant, JSON_UNESCAPED_UNICODE) . "\n\n";
$rid = $restaurant['restaurant_id'];

// Get all menus for this restaurant
$stmt = $conn->prepare("SELECT id, menu_name, sort_order FROM menu WHERE restaurant_id = ? ORDER BY sort_order ASC, id ASC");
$stmt->execute([$rid]);
$menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($menus)) {
    echo "❌ No menus found for restaurant_id = '$rid'.\n";
    
    // Check what restaurant_ids exist in menu
    $ids = $conn->query("SELECT DISTINCT restaurant_id FROM menu ORDER BY restaurant_id")->fetchAll(PDO::FETCH_COLUMN);
    echo "Available restaurant_ids in menu table: " . implode(', ', $ids) . "\n";
    exit;
}

echo "Current menus for '{$restaurant['restaurant_name']}' (restaurant_id: $rid):\n";
foreach ($menus as $m) {
    echo "  ID:{$m['id']} | sort_order:{$m['sort_order']} | \"{$m['menu_name']}\"\n";
}
echo "\n";

// Find "Food' Station Special" - exact name
$targetName = "Food' Station Special";
$targetMenu = null;

foreach ($menus as $m) {
    if ($m['menu_name'] === $targetName || strcasecmp($m['menu_name'], $targetName) === 0) {
        $targetMenu = $m;
        break;
    }
}

if (!$targetMenu) {
    echo "❌ Exact name '$targetName' not found for this restaurant.\n";
    echo "Available menu names:\n";
    foreach ($menus as $m) {
        echo "  - \"{$m['menu_name']}\"\n";
    }
    exit;
}

echo "Found target: \"{$targetMenu['menu_name']}\" (ID:{$targetMenu['id']}, current sort_order:{$targetMenu['sort_order']})\n\n";

if ($targetMenu['sort_order'] == 1) {
    echo "✅ This category is already first (sort_order=1). No changes needed.\n";
} else {
    $oldOrder = $targetMenu['sort_order'];
    
    $conn->beginTransaction();
    
    // Shift other menus with same or lower sort_order down
    $stmt = $conn->prepare("UPDATE menu SET sort_order = sort_order + 1 WHERE restaurant_id = ? AND id != ? AND sort_order > 0 AND sort_order <= ?");
    $stmt->execute([$rid, $targetMenu['id'], $oldOrder]);
    
    // Set target menu to first
    $stmt = $conn->prepare("UPDATE menu SET sort_order = 1 WHERE id = ? AND restaurant_id = ?");
    $stmt->execute([$targetMenu['id'], $rid]);
    
    $conn->commit();
    
    echo "✅ Updated! \"{$targetMenu['menu_name']}\" is now sort_order=1 (was $oldOrder)\n\n";
}

// Show updated order
echo "=== UPDATED MENU ORDER ===\n";
$stmt = $conn->prepare("SELECT id, menu_name, sort_order FROM menu WHERE restaurant_id = ? ORDER BY sort_order ASC, id ASC");
$stmt->execute([$rid]);
$menus = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($menus as $m) {
    $arrow = ($m['sort_order'] == 1) ? " ← FIRST" : "";
    echo "  {$m['sort_order']}. \"{$m['menu_name']}\" (ID: {$m['id']})$arrow\n";
}
