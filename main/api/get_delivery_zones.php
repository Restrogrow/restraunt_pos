<?php
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'zones' => []]);
    exit;
}

try {
    require_once __DIR__ . '/../db_connection.php';
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

    $restaurant_id = $_SESSION['restaurant_id'] ?? '';
    if (empty($restaurant_id)) {
        echo json_encode(['success' => false, 'zones' => []]);
        exit;
    }

    $stmt = $conn->prepare("SELECT * FROM delivery_zones WHERE restaurant_id = ? ORDER BY pincode ASC");
    $stmt->execute([$restaurant_id]);
    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'zones' => $zones]);

} catch (Exception $e) {
    error_log("Error in get_delivery_zones.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.', 'zones' => []]);
}
