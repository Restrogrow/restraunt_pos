<?php
// Suppress error display, log errors instead
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
require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin();

// Require permission to manage orders
requirePermission(PERMISSION_MANAGE_ORDERS);

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found'], JSON_UNESCAPED_UNICODE);
    exit();
}

// Deleting orders is a destructive, audit-worthy action — reserve it for
// Admin and Manager. Waiters can advance orders, and chefs can flip KOT
// statuses, but neither should be able to erase a ticket from the books.
// requireAdmin() alone would lock out Managers who legitimately handle
// voided/mistaken orders in most shops.
function canDeleteOrders() {
    $role = getUserRole();
    return $role === ROLE_ADMIN || $role === ROLE_MANAGER;
}

try {
    if (!canDeleteOrders()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only Admin or Manager can delete orders'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
    if (!$conn) {
        throw new Exception('Database connection not available');
    }

    // Always scope to the authenticated session's own tenant.
    $restaurant_id = $_SESSION['restaurant_id'] ?? null;
    if (!$restaurant_id) {
        throw new Exception('Restaurant ID is required');
    }

    $orderId = (int)($_POST['orderId'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if (!$orderId) {
        echo json_encode(['success' => false, 'message' => 'Order ID is required'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    require_once __DIR__ . '/../config/soft_delete_helpers.php';
    ensureOrderSoftDeleteColumns($conn);

    // Guard order fetch — tenant-scoped.
    $stmt = $conn->prepare("SELECT id, order_number, order_status, payment_status, payment_method, total, deleted_at FROM orders WHERE id = ? AND restaurant_id = ? LIMIT 1");
    $stmt->execute([$orderId, $restaurant_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Already deleted — idempotent success so a double-tap doesn't error.
    if (!empty($order['deleted_at'])) {
        echo json_encode(['success' => true, 'message' => 'Order was already deleted', 'already_deleted' => true], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Paid orders involve real money — deleting them would make collected
    // cash/card revenue vanish from the books while a payment row still
    // exists. Refund/reverse the payment first (payment_status → 'Refunded')
    // if truly needed; only unpaid/completed-but-unpaid orders delete freely.
    // Completed orders are likewise kept: they represent served food, and
    // the Cancel status exists for orders that genuinely fell through.
    $status = $order['order_status'];
    if ($order['payment_status'] === 'Paid') {
        echo json_encode(['success' => false, 'message' => 'Paid orders cannot be deleted. Refund the payment instead.'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    if ($status === 'Completed') {
        echo json_encode(['success' => false, 'message' => 'Completed orders cannot be deleted'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Conditional UPDATE instead of SELECT-then-UPDATE: the WHERE re-checks
    // deleted_at IS NULL so a concurrent double-delete can't overwrite the
    // first auditor's identity (deleted_by/first-writer wins, second request
    // matches zero rows and reports already-deleted).
    $upd = $conn->prepare("
        UPDATE orders
        SET deleted_at = NOW(),
            deleted_by = ?,
            delete_reason = ?
        WHERE id = ? AND restaurant_id = ? AND deleted_at IS NULL
    ");
    $upd->execute([getActorName(), $reason !== '' ? $reason : null, $orderId, $restaurant_id]);

    if ($upd->rowCount() === 0) {
        // Lost a race with a concurrent delete (or vanished mid-request).
        echo json_encode(['success' => true, 'message' => 'Order was already deleted', 'already_deleted' => true], JSON_UNESCAPED_UNICODE);
        exit();
    }

    error_log(sprintf(
        "delete_order.php: order #%s (%s) soft-deleted by %s (restaurant %s)%s",
        $orderId,
        $order['order_number'],
        getActorName(),
        $restaurant_id,
        $reason !== '' ? ' — reason: ' . $reason : ''
    ));

    echo json_encode([
        'success' => true,
        'message' => 'Order deleted. Find it anytime in the Deleted tab.',
        'order_id' => $orderId,
        'order_number' => $order['order_number'],
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("PDO Error in delete_order.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again later.'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Error in delete_order.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred while deleting the order. Please try again.'], JSON_UNESCAPED_UNICODE);
}
