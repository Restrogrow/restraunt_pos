<?php
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Auth required', 'feedback' => []]);
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

    // Auto-create table
    $conn->exec("CREATE TABLE IF NOT EXISTS order_feedback (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(10) NOT NULL,
        order_id INT NOT NULL,
        order_number VARCHAR(50) NOT NULL,
        customer_name VARCHAR(100) DEFAULT NULL,
        customer_phone VARCHAR(20) DEFAULT NULL,
        rating TINYINT NOT NULL,
        review TEXT DEFAULT NULL,
        is_approved TINYINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_restaurant (restaurant_id),
        INDEX idx_order (order_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $restaurant_id = $_SESSION['restaurant_id'] ?? '';

    if (empty($restaurant_id)) {
        echo json_encode(['success' => false, 'message' => 'Restaurant ID required', 'feedback' => []]);
        exit;
    }

    $stmt = $conn->prepare("SELECT f.*, o.total as order_total, o.order_type
        FROM order_feedback f
        LEFT JOIN orders o ON f.order_id = o.id
        WHERE f.restaurant_id = ?
        ORDER BY f.created_at DESC
        LIMIT 200");
    $stmt->execute([$restaurant_id]);
    $feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate stats
    $stmt = $conn->prepare("SELECT COUNT(*) as total, AVG(rating) as avg_rating, COUNT(CASE WHEN rating >= 4 THEN 1 END) as positive, COUNT(CASE WHEN rating <= 2 THEN 1 END) as negative FROM order_feedback WHERE restaurant_id = ?");
    $stmt->execute([$restaurant_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'feedback' => $feedback,
        'stats' => $stats,
        'count' => count($feedback)
    ]);

} catch (Exception $e) {
    error_log("Error in get_feedback.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.', 'feedback' => []]);
}
