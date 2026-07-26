<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (ob_get_level()) {
    ob_clean();
}

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json; charset=UTF-8');

requirePermission(PERMISSION_MANAGE_ORDERS);

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found'], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
        }
    }
    $restaurant_id = $_GET['restaurant_id'] ?? $_SESSION['restaurant_id'] ?? null;

    if (!$restaurant_id) {
        throw new Exception('Restaurant ID is required');
    }

    $statusFilter = $_GET['status'] ?? '';
    $searchTerm = $_GET['search'] ?? '';

    $whereConditions = ['o.restaurant_id = ?', "o.source = 'website'", "(o.payment_method NOT IN ('PhonePe', 'UPI / NetBanking') OR o.payment_status = 'Paid')"];
    $params = [$restaurant_id];

    if ($statusFilter) {
        $whereConditions[] = 'o.order_status = ?';
        $params[] = $statusFilter;
    }

    if ($searchTerm) {
        $whereConditions[] = '(o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)';
        $s = '%' . $searchTerm . '%';
        $params[] = $s;
        $params[] = $s;
        $params[] = $s;
    }

    $whereClause = implode(' AND ', $whereConditions);

    // Get whatsapp_orders and phone for the restaurant
    $waStmt = $conn->prepare("SELECT whatsapp_orders, phone, restaurant_lat, restaurant_lng, address FROM users WHERE restaurant_id = ? LIMIT 1");
    $waStmt->execute([$restaurant_id]);
    $waSettings = $waStmt->fetch(PDO::FETCH_ASSOC);

    $sql = "SELECT o.id, o.order_number, o.order_status, o.payment_status, o.payment_method,
                   o.order_type, o.customer_name, o.customer_phone, o.customer_email,
                   o.customer_address, o.address_lat, o.address_lng, o.created_at, o.subtotal, o.tax, o.total, o.notes,
                   pp.status as payment_proof_status,
                   (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) as item_count
            FROM orders o
            LEFT JOIN payment_proofs pp ON pp.order_id = o.id
            WHERE " . $whereClause . "
            ORDER BY o.created_at DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetchAll();
    } catch (PDOException $e) {
        // Fall back for databases that haven't run the address_lat/address_lng
        // or payment_proofs migrations yet, so a missing migration degrades
        // gracefully instead of 500ing the whole online-orders list.
        $fallbackSql = "SELECT o.id, o.order_number, o.order_status, o.payment_status, o.payment_method,
                       o.order_type, o.customer_name, o.customer_phone, o.customer_email,
                       o.customer_address, o.created_at, o.subtotal, o.tax, o.total, o.notes,
                       (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) as item_count
                FROM orders o
                WHERE " . $whereClause . "
                ORDER BY o.created_at DESC";
        $stmt = $conn->prepare($fallbackSql);
        $stmt->execute($params);
        $result = $stmt->fetchAll();
    }

    $orders = [];
    foreach ($result as $row) {
        $items_sql = "SELECT oi.item_name, oi.quantity, oi.unit_price, oi.total_price, oi.notes
                      FROM order_items oi WHERE oi.order_id = ? ORDER BY oi.id";
        $items_stmt = $conn->prepare($items_sql);
        $items_stmt->execute([$row['id']]);
        $row['items'] = $items_stmt->fetchAll();
        $orders[] = $row;
    }

    // Build restaurant WhatsApp info
    $whatsappEnabled = $waSettings ? (int)$waSettings['whatsapp_orders'] : 0;
    $whatsappPhone = $waSettings ? (string)($waSettings['phone'] ?? '') : '';

    echo json_encode([
        'success' => true,
        'orders' => $orders,
        'count' => count($orders),
        'whatsapp_enabled' => $whatsappEnabled,
        'whatsapp_phone' => $whatsappPhone,
        'restaurant_lat' => $waSettings['restaurant_lat'] ?? null,
        'restaurant_lng' => $waSettings['restaurant_lng'] ?? null,
        'restaurant_address' => $waSettings['address'] ?? ''
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("PDO Error in get_online_orders.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Error in get_online_orders.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.'], JSON_UNESCAPED_UNICODE);
}
