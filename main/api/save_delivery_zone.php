<?php
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
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
        throw new Exception('No restaurant session');
    }

    $action = $_POST['action'] ?? 'create';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete') {
        if (!$id) throw new Exception('Zone ID required');
        $stmt = $conn->prepare("DELETE FROM delivery_zones WHERE id = ? AND restaurant_id = ?");
        $stmt->execute([$id, $restaurant_id]);
        echo json_encode(['success' => true, 'message' => 'Zone deleted']);
        exit;
    }

    if ($action === 'toggle') {
        if (!$id) throw new Exception('Zone ID required');
        $stmt = $conn->prepare("SELECT is_active FROM delivery_zones WHERE id = ? AND restaurant_id = ?");
        $stmt->execute([$id, $restaurant_id]);
        $zone = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$zone) throw new Exception('Zone not found');
        $newStatus = $zone['is_active'] ? 0 : 1;
        $stmt = $conn->prepare("UPDATE delivery_zones SET is_active = ? WHERE id = ? AND restaurant_id = ?");
        $stmt->execute([$newStatus, $id, $restaurant_id]);
        echo json_encode(['success' => true, 'message' => $newStatus ? 'Zone activated' : 'Zone deactivated']);
        exit;
    }

    $pincode = trim($_POST['pincode'] ?? '');
    $zone_name = trim($_POST['zone_name'] ?? '');
    $delivery_charge = (float)($_POST['delivery_charge'] ?? 0);
    $estimated_time = (int)($_POST['estimated_time'] ?? 30);
    $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

    if (empty($pincode)) {
        throw new Exception('Pincode is required');
    }
    if ($delivery_charge < 0) {
        throw new Exception('Delivery charge cannot be negative');
    }

    if ($action === 'update') {
        if (!$id) throw new Exception('Zone ID required for update');
        $stmt = $conn->prepare("UPDATE delivery_zones SET pincode = ?, zone_name = ?, delivery_charge = ?, estimated_time = ?, is_active = ? WHERE id = ? AND restaurant_id = ?");
        $stmt->execute([$pincode, $zone_name, $delivery_charge, $estimated_time, $is_active, $id, $restaurant_id]);
        echo json_encode(['success' => true, 'message' => 'Zone updated']);
    } else {
        $check = $conn->prepare("SELECT id FROM delivery_zones WHERE restaurant_id = ? AND pincode = ?");
        $check->execute([$restaurant_id, $pincode]);
        if ($check->fetch()) {
            throw new Exception('Zone with this pincode already exists');
        }
        $stmt = $conn->prepare("INSERT INTO delivery_zones (restaurant_id, pincode, zone_name, delivery_charge, estimated_time, is_active) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$restaurant_id, $pincode, $zone_name, $delivery_charge, $estimated_time, $is_active]);
        echo json_encode(['success' => true, 'message' => 'Zone created', 'id' => $conn->lastInsertId()]);
    }

} catch (Exception $e) {
    http_response_code(400);
    error_log("Error in save_delivery_zone.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
