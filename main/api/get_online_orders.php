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
    // Always scope to the authenticated session's own tenant — never trust a
    // client-supplied restaurant_id, or one restaurant's staff could read another's online orders.
    $restaurant_id = $_SESSION['restaurant_id'] ?? null;
    session_write_close(); // read-only endpoint ($_SESSION stays readable)

    if (!$restaurant_id) {
        throw new Exception('Restaurant ID is required');
    }

    $statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
    $searchTerm = is_string($_GET['search'] ?? null) ? $_GET['search'] : '';

    // Soft-deleted orders live only in the app's Deleted tab — never in this
    // live online-orders list.
    require_once __DIR__ . '/../config/soft_delete_helpers.php';
    ensureOrderSoftDeleteColumns($conn);

    $whereConditions = ['o.restaurant_id = ?', waiterOrderFilterSql('o'), "o.source = 'website'", "(o.payment_method NOT IN ('PhonePe', 'UPI / NetBanking') OR o.payment_status = 'Paid')", 'o.deleted_at IS NULL'];
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
    try {
        $waStmt = $conn->prepare("SELECT whatsapp_orders, phone, restaurant_lat, restaurant_lng, address, enable_km_delivery, delivery_rate_per_km FROM users WHERE restaurant_id = ? LIMIT 1");
        $waStmt->execute([$restaurant_id]);
        $waSettings = $waStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fall back for databases that haven't run the KM-delivery migration yet
        $waStmt = $conn->prepare("SELECT whatsapp_orders, phone, restaurant_lat, restaurant_lng, address FROM users WHERE restaurant_id = ? LIMIT 1");
        $waStmt->execute([$restaurant_id]);
        $waSettings = $waStmt->fetch(PDO::FETCH_ASSOC);
    }

    $sql = "SELECT o.id, o.order_number, o.order_status, o.payment_status, o.payment_method,
                   o.order_type, o.customer_name, o.customer_phone, o.customer_email,
                   o.customer_address, o.landmark, o.address_lat, o.address_lng, o.created_at, o.subtotal, o.tax, o.total, o.notes,
                   pp.status as payment_proof_status,
                   (SELECT t.table_number FROM `tables` t WHERE t.id = o.table_id) as table_number,
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
        // Fall back for databases that haven't run the landmark/address_lat/
        // address_lng or payment_proofs migrations yet, so a missing migration
        // degrades gracefully instead of 500ing the whole online-orders list.
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

    // items of all listed orders, fetched in batches instead of one query per order
    $itemsByOrder = [];
    foreach (array_chunk(array_column($result, 'id'), 500) as $idChunk) {
        $in = implode(',', array_fill(0, count($idChunk), '?'));
        // items with the dish photo, size and add-on names (read-only extras for the order cards)
        try {
            $items_stmt = $conn->prepare("SELECT oi.order_id, oi.item_name, oi.quantity, oi.unit_price, oi.total_price, oi.notes, oi.variation_name, oi.addons, m.item_image, m.item_type
                FROM order_items oi LEFT JOIN menu_items m ON m.id = oi.menu_item_id WHERE oi.order_id IN ($in) ORDER BY oi.id");
            $items_stmt->execute($idChunk);
        } catch (PDOException $e) {
            $items_stmt = $conn->prepare("SELECT oi.order_id, oi.item_name, oi.quantity, oi.unit_price, oi.total_price, oi.notes FROM order_items oi WHERE oi.order_id IN ($in) ORDER BY oi.id");
            $items_stmt->execute($idChunk);
        }
        foreach ($items_stmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $oid = $it['order_id'];
            unset($it['order_id']);
            $itemsByOrder[$oid][] = $it;
        }
    }

    $orders = [];
    foreach ($result as $row) {
        $row['items'] = $itemsByOrder[$row['id']] ?? [];
        foreach ($row['items'] as &$it) {
            $names = [];
            $raw = isset($it['addons']) ? json_decode((string)$it['addons'], true) : null;
            if (is_array($raw)) foreach ($raw as $a) { $n = is_array($a) ? ($a['name'] ?? $a['addon_name'] ?? '') : (string)$a; if ($n !== '') $names[] = $n; }
            $it['addon_names'] = $names;
            unset($it['addons']);
        }
        unset($it);
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
        'restaurant_address' => $waSettings['address'] ?? '',
        'enable_km_delivery' => isset($waSettings['enable_km_delivery']) ? (int)$waSettings['enable_km_delivery'] : 0,
        'delivery_rate_per_km' => isset($waSettings['delivery_rate_per_km']) ? (float)$waSettings['delivery_rate_per_km'] : 0
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("PDO Error in get_online_orders.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log("Error in get_online_orders.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.'], JSON_UNESCAPED_UNICODE);
}
