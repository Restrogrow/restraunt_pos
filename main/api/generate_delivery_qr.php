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

    // Auto-create delivery_tracking table if missing
    try { $conn->query("SELECT id FROM delivery_tracking LIMIT 1"); } catch (PDOException $e) {
        $conn->exec("CREATE TABLE IF NOT EXISTS delivery_tracking (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(10) NOT NULL,
            order_id INT NOT NULL,
            delivery_zone_id INT NULL,
            delivery_address TEXT NULL,
            delivery_notes TEXT NULL,
            delivery_status ENUM('Pending','Preparing','Assigned','Picked_Up','In_Transit','Delivered') DEFAULT 'Pending',
            qr_token VARCHAR(100) NULL UNIQUE,
            qr_expires_at DATETIME NULL,
            rider_name VARCHAR(100) NULL,
            rider_phone VARCHAR(20) NULL,
            rider_vehicle VARCHAR(50) NULL,
            current_lat DECIMAL(10,8) NULL,
            current_lng DECIMAL(11,8) NULL,
            location_updated_at DATETIME NULL,
            picked_up_at DATETIME NULL,
            in_transit_at DATETIME NULL,
            delivered_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            INDEX idx_restaurant_id (restaurant_id),
            INDEX idx_order_id (order_id),
            INDEX idx_delivery_status (delivery_status),
            INDEX idx_qr_token (qr_token)
        )");
    }

    $restaurant_id = $_SESSION['restaurant_id'] ?? '';
    $order_id = (int)($_POST['order_id'] ?? 0);

    if (empty($restaurant_id) || !$order_id) {
        throw new Exception('Missing parameters');
    }

    // Verify order belongs to this restaurant and is a delivery order
    $stmt = $conn->prepare("SELECT id, order_type, order_status FROM orders WHERE id = ? AND restaurant_id = ?");
    $stmt->execute([$order_id, $restaurant_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception('Order not found');
    }
    if ($order['order_type'] !== 'Delivery') {
        throw new Exception('This is not a delivery order');
    }

    // Generate unique QR token
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

    // Check if tracking record already exists
    $checkStmt = $conn->prepare("SELECT id FROM delivery_tracking WHERE order_id = ? AND restaurant_id = ?");
    $checkStmt->execute([$order_id, $restaurant_id]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Update existing record with new token
        $stmt = $conn->prepare("UPDATE delivery_tracking SET qr_token = ?, qr_expires_at = ?, delivery_status = 'Assigned', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$token, $expiresAt, $existing['id']]);
    } else {
        // Create new tracking record
        $stmt = $conn->prepare("INSERT INTO delivery_tracking (restaurant_id, order_id, delivery_status, qr_token, qr_expires_at) VALUES (?, ?, 'Assigned', ?, ?)");
        $stmt->execute([$restaurant_id, $order_id, $token, $expiresAt]);
    }

    // Build QR URL (use the actual domain)
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    $qrUrl = "$protocol://$host$basePath/delivery/rider-page.php?token=$token";

    echo json_encode([
        'success' => true,
        'message' => 'QR code generated',
        'token' => $token,
        'qr_url' => $qrUrl,
        'expires_at' => $expiresAt
    ]);

} catch (Exception $e) {
    error_log("Error in generate_delivery_qr.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
