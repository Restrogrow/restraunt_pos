<?php
/**
 * Admin read endpoint: every customer subscription for the logged-in
 * restaurant staff's own restaurant_id (never client-supplied - same trust
 * rule as get_addons.php). Used by main/admin/meal_subscriptions.php.
 */

if (ob_get_level()) {
    ob_clean();
}

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

if (!isSessionValid() || !isset($_SESSION['user_id']) || !isset($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit();
}

require_once __DIR__ . '/../config/authorization_config.php';
requirePermission(PERMISSION_MANAGE_ORDERS);

header('Content-Type: application/json; charset=UTF-8');

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit();
}

$restaurant_id = $_SESSION['restaurant_id'];
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

try {
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
        }
    }

    try {
        $checkTable = $conn->query("SHOW TABLES LIKE 'customer_meal_subscriptions'");
        if ($checkTable->rowCount() == 0) {
            echo json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
            exit();
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $sql = "SELECT s.id, s.customer_id, c.customer_name, c.phone, s.plan_name_snapshot, s.meal_scope_snapshot,
                   s.credits_total, s.credits_used, s.amount_paid, s.delivery_address, s.delivery_phone,
                   s.status, s.paused_at, s.created_at
            FROM customer_meal_subscriptions s
            JOIN customers c ON c.id = s.customer_id
            WHERE s.restaurant_id = ?";
    $params = [$restaurant_id];

    if ($search !== '') {
        $sql .= " AND (c.customer_name LIKE ? OR c.phone LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    if ($statusFilter !== '' && in_array($statusFilter, ['pending_payment', 'active', 'paused', 'completed', 'cancelled'], true)) {
        $sql .= " AND s.status = ?";
        $params[] = $statusFilter;
    }
    $sql .= " ORDER BY s.created_at DESC LIMIT 500";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($subscriptions) {
        $ids = array_column($subscriptions, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $skipStmt = $conn->prepare("SELECT subscription_id, skip_date FROM meal_subscription_skip_dates WHERE subscription_id IN ($placeholders) AND skip_date >= CURDATE() ORDER BY skip_date ASC");
        $skipStmt->execute($ids);
        $skipsBySub = [];
        foreach ($skipStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $skipsBySub[$row['subscription_id']][] = $row['skip_date'];
        }
        foreach ($subscriptions as &$sub) {
            $sub['credits_remaining'] = max(0, (int)$sub['credits_total'] - (int)$sub['credits_used']);
            $sub['skip_dates'] = $skipsBySub[$sub['id']] ?? [];
        }
        unset($sub);
    }

    echo json_encode(['success' => true, 'data' => $subscriptions, 'count' => count($subscriptions)], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("PDO Error in get_meal_subscriptions.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in get_meal_subscriptions.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred.', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit();
}
