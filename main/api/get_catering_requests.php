<?php
/**
 * Admin read of catering requests for the logged-in staff's own restaurant.
 * Mirrors get_meal_subscriptions.php.
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
        $checkTable = $conn->query("SHOW TABLES LIKE 'catering_requests'");
        if ($checkTable->rowCount() == 0) {
            echo json_encode(['success' => true, 'data' => [], 'tiers' => []], JSON_UNESCAPED_UNICODE);
            exit();
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => true, 'data' => [], 'tiers' => []], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $sql = "SELECT r.*, t.tier_label FROM catering_requests r LEFT JOIN catering_price_tiers t ON t.id = r.tier_id WHERE r.restaurant_id = ?";
    $params = [$restaurant_id];
    if ($statusFilter !== '' && in_array($statusFilter, ['new', 'contacted', 'confirmed', 'completed', 'cancelled'], true)) {
        $sql .= " AND r.status = ?";
        $params[] = $statusFilter;
    }
    $sql .= " ORDER BY r.event_date ASC, r.created_at DESC LIMIT 500";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tierStmt = $conn->prepare("SELECT id, tier_label, min_guests, max_guests, price, is_active FROM catering_price_tiers WHERE restaurant_id = ? ORDER BY min_guests ASC");
    $tierStmt->execute([$restaurant_id]);
    $tiers = $tierStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $requests, 'tiers' => $tiers], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("PDO Error in get_catering_requests.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in get_catering_requests.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred.', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit();
}
