<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (ob_get_level()) ob_clean();

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database configuration not found']);
    exit();
}

try {
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        global $pdo;
        $conn = $pdo ?? null;
        if (!$conn) throw new Exception('No database connection');
    }

    $restaurant_id = $_GET['restaurant_id'] ?? $_SESSION['restaurant_id'] ?? '';

    if (empty($restaurant_id)) {
        echo json_encode(['success' => false, 'message' => 'No restaurant_id provided']);
        exit();
    }

    $stmt = $conn->prepare("SELECT id, coupon_code, discount_type, discount_value, minimum_order_amount, description, valid_from, valid_until FROM coupons WHERE restaurant_id = :rid AND is_active = 1 AND (valid_from IS NULL OR valid_from <= CURDATE()) AND (valid_until IS NULL OR valid_until >= CURDATE()) AND (max_uses = 0 OR current_uses < max_uses) ORDER BY discount_value DESC");
    $stmt->execute([':rid' => $restaurant_id]);
    $coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'coupons' => $coupons]);
} catch (Exception $e) {
    error_log("Error in get_coupons.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
