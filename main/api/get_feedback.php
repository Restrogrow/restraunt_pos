<?php
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Auth required', 'feedback' => []]);
    exit;
}
session_write_close(); // read-only endpoint ($_SESSION stays readable)

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

    // insights: last 30 days vs the 30 before, how many orders get rated, 6-month trend, unhappy customers to call back
    $q = function ($sql, $a) use ($conn) { $st = $conn->prepare($sql); $st->execute($a); return $st; };
    $p = $q("SELECT SUM(created_at >= NOW() - INTERVAL 30 DAY) n30, AVG(CASE WHEN created_at >= NOW() - INTERVAL 30 DAY THEN rating END) avg30,
            SUM(created_at < NOW() - INTERVAL 30 DAY AND created_at >= NOW() - INTERVAL 60 DAY) nprev, AVG(CASE WHEN created_at < NOW() - INTERVAL 30 DAY AND created_at >= NOW() - INTERVAL 60 DAY THEN rating END) avgprev,
            SUM(created_at >= NOW() - INTERVAL 30 DAY AND rating >= 4) pos30, SUM(created_at >= NOW() - INTERVAL 30 DAY AND rating <= 2) neg30
        FROM order_feedback WHERE restaurant_id = ?", [$restaurant_id])->fetch(PDO::FETCH_ASSOC);
    $orders30 = 0;
    try { $orders30 = (int)$q("SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND order_status = 'Completed' AND created_at >= NOW() - INTERVAL 30 DAY AND deleted_at IS NULL", [$restaurant_id])->fetchColumn(); } catch (Throwable $e) {}
    $months = [];
    foreach ($q("SELECT DATE_FORMAT(created_at, '%Y-%m') m, COUNT(*) n, AVG(rating) a FROM order_feedback WHERE restaurant_id = ? AND created_at >= DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL 5 MONTH) GROUP BY m", [$restaurant_id])->fetchAll(PDO::FETCH_ASSOC) as $m) $months[$m['m']] = $m;
    $trend = [];
    for ($i = 5; $i >= 0; $i--) { $k = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); $trend[] = ['month' => $k, 'n' => (int)($months[$k]['n'] ?? 0), 'avg' => isset($months[$k]) ? round((float)$months[$k]['a'], 2) : null]; }
    $unhappy = $q("SELECT customer_name, customer_phone, rating, review, order_number, created_at FROM order_feedback WHERE restaurant_id = ? AND rating <= 2 AND created_at >= NOW() - INTERVAL 30 DAY ORDER BY created_at DESC LIMIT 8", [$restaurant_id])->fetchAll(PDO::FETCH_ASSOC);
    $insights = ['n30' => (int)$p['n30'], 'nprev' => (int)$p['nprev'], 'avg30' => $p['avg30'] !== null ? round((float)$p['avg30'], 2) : null, 'avgprev' => $p['avgprev'] !== null ? round((float)$p['avgprev'], 2) : null,
        'pos30' => (int)$p['pos30'], 'neg30' => (int)$p['neg30'], 'orders30' => $orders30, 'trend' => $trend, 'unhappy' => $unhappy];

    echo json_encode([
        'success' => true,
        'feedback' => $feedback,
        'stats' => $stats,
        'insights' => $insights,
        'count' => count($feedback)
    ]);

} catch (Throwable $e) {
    error_log("Error in get_feedback.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.', 'feedback' => []]);
}
