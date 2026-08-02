<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
requirePermission(PERMISSION_MANAGE_INVENTORY);

require_once __DIR__ . '/../db_connection.php';
if (function_exists('getConnection')) { $conn = getConnection(); } else { global $pdo; $conn = $pdo; }
require_once __DIR__ . '/../config/inventory_tables.php';

try {
    $restaurant_id = $_SESSION['restaurant_id'] ?? '';
    if (empty($restaurant_id)) {
        echo json_encode(['success' => false, 'message' => 'No restaurant session']);
        exit;
    }

    ensureInventoryTables($conn);

    $stmt = $conn->prepare("
        SELECT t.*, i.item_name, i.unit
        FROM inventory_transactions t
        JOIN inventory_items i ON t.inventory_item_id = i.id
        WHERE t.restaurant_id = ?
        ORDER BY t.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$restaurant_id]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'transactions' => $transactions]);
} catch (Exception $e) {
    error_log("Error in get_inventory_transactions.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
