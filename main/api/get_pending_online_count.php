<?php
if (ob_get_level()) ob_clean();

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
header('Content-Type: application/json; charset=UTF-8');
requirePermission(PERMISSION_MANAGE_ORDERS);

// Release the session file lock immediately - this endpoint only reads
// session data and is polled every 30s, so holding the lock for the whole
// request would block other requests (e.g. a login attempt) sharing the
// same session until this one finishes.
session_write_close();

if (!file_exists(__DIR__ . '/../db_connection.php')) {
    echo json_encode(['success' => false, 'count' => 0]);
    exit();
}
require_once __DIR__ . '/../db_connection.php';

try {
    $conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
    if (!$conn) throw new Exception('No DB connection');

    $restaurant_id = $_SESSION['restaurant_id'] ?? null;
    if (!$restaurant_id) throw new Exception('No restaurant ID');

    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM orders WHERE restaurant_id = ? AND source = 'website' AND order_status = 'Pending' AND (payment_method NOT IN ('PhonePe', 'UPI / NetBanking') OR payment_status = 'Paid')");
    $stmt->execute([$restaurant_id]);
    $cnt = (int)$stmt->fetchColumn();

    echo json_encode(['success' => true, 'count' => $cnt]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'count' => 0]);
}
