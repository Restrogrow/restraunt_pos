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

// Release the session file lock immediately - this endpoint only reads
// session data and is polled every 10s, so holding the lock for the whole
// request would block other requests (e.g. a login attempt) sharing the
// same session until this one finishes.
session_write_close();

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

    $restaurant_id = $_SESSION['restaurant_id'] ?? null;
    if (!$restaurant_id) {
        throw new Exception('Restaurant ID is required');
    }

    // Get the most recent pending online order (must have at least 1 item)
    $sql = "SELECT o.id, o.order_number, o.order_status, o.payment_status, o.payment_method,
                   o.order_type, o.customer_name, o.customer_phone, o.customer_email,
                   o.customer_address, o.landmark, o.address_lat, o.address_lng, o.created_at, o.subtotal, o.tax, o.total, o.notes,
                   pp.status as payment_proof_status,
                   (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) as item_count
            FROM orders o
            LEFT JOIN payment_proofs pp ON pp.order_id = o.id
            WHERE o.restaurant_id = ? AND o.source = 'website' AND o.order_status = 'Pending'
              AND (o.payment_method NOT IN ('PhonePe', 'UPI / NetBanking') OR o.payment_status = 'Paid')
              AND (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) > 0
            ORDER BY o.created_at DESC
            LIMIT 1";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([$restaurant_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fall back for DBs that haven't run the landmark/address_lat/
        // address_lng or payment_proofs migrations yet.
        $fallbackSql = "SELECT o.id, o.order_number, o.order_status, o.payment_status, o.payment_method,
                       o.order_type, o.customer_name, o.customer_phone, o.customer_email,
                       o.customer_address, o.created_at, o.subtotal, o.tax, o.total, o.notes,
                       (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) as item_count
                FROM orders o
                WHERE o.restaurant_id = ? AND o.source = 'website' AND o.order_status = 'Pending'
                  AND (o.payment_method NOT IN ('PhonePe', 'UPI / NetBanking') OR o.payment_status = 'Paid')
                  AND (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) > 0
                ORDER BY o.created_at DESC
                LIMIT 1";
        $stmt = $conn->prepare($fallbackSql);
        $stmt->execute([$restaurant_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($order) {
        // Get order items
        $items_sql = "SELECT oi.item_name, oi.quantity, oi.unit_price, oi.total_price, oi.notes
                      FROM order_items oi WHERE oi.order_id = ? ORDER BY oi.id";
        $items_stmt = $conn->prepare($items_sql);
        $items_stmt->execute([$order['id']]);
        $order['items'] = $items_stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'order' => $order
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => true,
            'order' => null
        ]);
    }

} catch (PDOException $e) {
    error_log("PDO Error in get_latest_pending_order.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Error in get_latest_pending_order.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.'], JSON_UNESCAPED_UNICODE);
}
