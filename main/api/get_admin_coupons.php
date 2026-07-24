<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
requirePermission(PERMISSION_MANAGE_COUPONS);

require_once __DIR__ . '/../db_connection.php';
if (function_exists('getConnection')) { $conn = getConnection(); } else { global $pdo; $conn = $pdo; }

try {
    $restaurant_id = $_SESSION['restaurant_id'] ?? '';
    if (empty($restaurant_id)) {
        echo json_encode(['success' => false, 'message' => 'No restaurant session']);
        exit;
    }

    // Auto-create coupons table if missing
    try { $conn->query("SELECT id FROM coupons LIMIT 1"); } catch (PDOException $e) {
        $conn->exec("CREATE TABLE IF NOT EXISTS coupons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(10) NOT NULL,
            coupon_code VARCHAR(50) NOT NULL,
            discount_type ENUM('percent','flat') NOT NULL DEFAULT 'percent',
            discount_value DECIMAL(10,2) NOT NULL,
            minimum_order_amount DECIMAL(10,2) DEFAULT 0.00,
            max_uses INT DEFAULT 0,
            current_uses INT DEFAULT 0,
            valid_from DATE DEFAULT NULL,
            valid_until DATE DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            description VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_coupon (restaurant_id, coupon_code),
            INDEX idx_restaurant (restaurant_id)
        )");
    }

    $stmt = $conn->prepare("SELECT * FROM coupons WHERE restaurant_id = ? ORDER BY created_at DESC");
    $stmt->execute([$restaurant_id]);
    $coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'coupons' => $coupons]);
} catch (Exception $e) {
    error_log("Error in get_admin_coupons.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
