<?php
/**
 * Public read of catering price tiers for a restaurant - used by the
 * customer-facing catering.php request form to show a live quote as the
 * guest count is entered. No auth required (same as get_addons.php's
 * anonymous branch).
 */

if (ob_get_level()) {
    ob_clean();
}

require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit();
}

$restaurant_id = $_GET['restaurant_id'] ?? null;
if (!$restaurant_id) {
    echo json_encode(['success' => false, 'message' => 'Restaurant ID required', 'data' => []], JSON_UNESCAPED_UNICODE);
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

    try {
        $checkTable = $conn->query("SHOW TABLES LIKE 'catering_price_tiers'");
        if ($checkTable->rowCount() == 0) {
            echo json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
            exit();
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $stmt = $conn->prepare("SELECT id, tier_label, min_guests, max_guests, price FROM catering_price_tiers WHERE restaurant_id = ? AND is_active = 1 ORDER BY min_guests ASC");
    $stmt->execute([$restaurant_id]);
    $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $tiers], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("PDO Error in get_catering_tiers.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in get_catering_tiers.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred.', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit();
}
