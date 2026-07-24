<?php
// Public API: Submit feedback/rating for an order
// Used by customers after their order is delivered
require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true);
require_once __DIR__ . '/../db_connection.php';

header('Content-Type: application/json');

try {
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        global $pdo;
        $conn = $pdo;
    }

    // Auto-create feedback table if not exists
    $conn->exec("CREATE TABLE IF NOT EXISTS order_feedback (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(10) NOT NULL,
        order_id INT NOT NULL,
        order_number VARCHAR(50) NOT NULL,
        customer_name VARCHAR(100) DEFAULT NULL,
        customer_phone VARCHAR(20) DEFAULT NULL,
        rating TINYINT NOT NULL CHECK (rating >= 1 AND rating <= 5),
        review TEXT DEFAULT NULL,
        is_approved TINYINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_restaurant (restaurant_id),
        INDEX idx_order (order_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'POST required']);
        exit;
    }

    $order_number = trim($_POST['order_number'] ?? '');
    $customer_phone = trim($_POST['customer_phone'] ?? '');
    $rating = (int)($_POST['rating'] ?? 0);
    $review = trim($_POST['review'] ?? '');
    $customer_name = trim($_POST['customer_name'] ?? '');

    if (empty($order_number) || empty($customer_phone)) {
        echo json_encode(['success' => false, 'message' => 'Order number and phone are required']);
        exit;
    }

    if ($rating < 1 || $rating > 5) {
        echo json_encode(['success' => false, 'message' => 'Rating must be between 1 and 5']);
        exit;
    }

    // Verify the order exists and belongs to this phone
    $stmt = $conn->prepare("SELECT id, order_number, customer_name, restaurant_id, order_status FROM orders WHERE order_number = ? AND customer_phone = ? LIMIT 1");
    $stmt->execute([$order_number, $customer_phone]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found or phone does not match']);
        exit;
    }

    // Check if feedback already exists for this order
    $stmt = $conn->prepare("SELECT id FROM order_feedback WHERE order_id = ? AND restaurant_id = ?");
    $stmt->execute([$order['id'], $order['restaurant_id']]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Feedback already submitted for this order']);
        exit;
    }

    // Insert feedback
    $stmt = $conn->prepare("INSERT INTO order_feedback (restaurant_id, order_id, order_number, customer_name, customer_phone, rating, review) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $order['restaurant_id'],
        $order['id'],
        $order['order_number'],
        $customer_name ?: $order['customer_name'],
        $customer_phone,
        $rating,
        $review ?: null
    ]);

    echo json_encode(['success' => true, 'message' => 'Thank you for your feedback!']);

} catch (Exception $e) {
    error_log("Error in submit_feedback.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
