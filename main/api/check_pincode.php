<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true);

require_once __DIR__ . '/../db_connection.php';

try {
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        global $pdo;
        $conn = $pdo;
    }

    // Auto-create delivery_zones table if missing
    try { $conn->query("SELECT id FROM delivery_zones LIMIT 1"); } catch (PDOException $e) {
        $conn->exec("CREATE TABLE IF NOT EXISTS delivery_zones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(10) NOT NULL,
            pincode VARCHAR(10) NOT NULL,
            zone_name VARCHAR(100) DEFAULT '',
            delivery_charge DECIMAL(10,2) DEFAULT 0.00,
            estimated_time INT DEFAULT 30,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_pincode_restaurant (restaurant_id, pincode),
            INDEX idx_restaurant_id (restaurant_id),
            INDEX idx_is_active (is_active)
        )");
    }

    $restaurant_id = $_GET['restaurant_id'] ?? $_SESSION['restaurant_id'] ?? '';
    $pincode = trim($_GET['pincode'] ?? '');

    if (empty($restaurant_id) || empty($pincode)) {
        echo json_encode(['success' => false, 'message' => 'Missing parameters']);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, pincode, zone_name, delivery_charge, estimated_time FROM delivery_zones WHERE restaurant_id = ? AND pincode = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$restaurant_id, $pincode]);
    $zone = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($zone) {
        echo json_encode([
            'success' => true,
            'available' => true,
            'zone' => [
                'id' => $zone['id'],
                'pincode' => $zone['pincode'],
                'zone_name' => $zone['zone_name'],
                'delivery_charge' => (float)$zone['delivery_charge'],
                'estimated_time' => (int)$zone['estimated_time']
            ]
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'available' => false,
            'message' => 'We do not deliver to this pincode yet'
        ]);
    }

} catch (Exception $e) {
    error_log("Error in check_pincode.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
