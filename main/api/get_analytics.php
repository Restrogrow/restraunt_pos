<?php
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['restaurant_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$restaurant_id = $_SESSION['restaurant_id'] ?? 'RES001';
$days = isset($_GET['days']) ? max(1, min(90, (int)$_GET['days'])) : 7;

try {
    $conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
    if (!$conn) throw new Exception('No connection');

    // ── Page Visits (gracefully handle missing table) ──
    $totalVisits = 0;
    $uniqueVisitors = 0;
    $pageVisits = [];
    $visitsByDay = [];
    $visitsByHour = [];
    $recentVisits = [];
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM page_visits WHERE restaurant_id = ? AND visited_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$restaurant_id, $days]);
        $totalVisits = (int)$stmt->fetchColumn();

        $stmt = $conn->prepare("SELECT page_name, page_url, COUNT(*) as count FROM page_visits WHERE restaurant_id = ? AND visited_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY page_name ORDER BY count DESC");
        $stmt->execute([$restaurant_id, $days]);
        $pageVisits = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("SELECT DATE(visited_at) as date, COUNT(*) as count FROM page_visits WHERE restaurant_id = ? AND visited_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY DATE(visited_at) ORDER BY date ASC");
        $stmt->execute([$restaurant_id, $days]);
        $visitsByDay = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("SELECT HOUR(visited_at) as hour, COUNT(*) as count FROM page_visits WHERE restaurant_id = ? AND visited_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY HOUR(visited_at) ORDER BY hour ASC");
        $stmt->execute([$restaurant_id, $days]);
        $visitsByHour = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("SELECT page_name, page_url, visitor_ip, referrer_url, visited_at FROM page_visits WHERE restaurant_id = ? AND visited_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY visited_at DESC LIMIT 20");
        $stmt->execute([$restaurant_id, $days]);
        $recentVisits = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("SELECT COUNT(DISTINCT visitor_ip) as unique_visitors FROM page_visits WHERE restaurant_id = ? AND visited_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$restaurant_id, $days]);
        $uniqueVisitors = (int)$stmt->fetchColumn();
    } catch (Exception $pvErr) {
        // page_visits table may not exist yet on production - return empty data
        $totalVisits = 0;
        $uniqueVisitors = 0;
        $pageVisits = [];
        $visitsByDay = [];
        $visitsByHour = [];
        $recentVisits = [];
    }

    // ── Orders & Revenue ──
    $stmt = $conn->prepare("SELECT COUNT(*) as total_orders, COALESCE(SUM(total), 0) as total_revenue FROM orders WHERE restaurant_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$restaurant_id, $days]);
    $orderStats = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("SELECT DATE(created_at) as date, COUNT(*) as orders, COALESCE(SUM(total), 0) as revenue FROM orders WHERE restaurant_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY DATE(created_at) ORDER BY date ASC");
    $stmt->execute([$restaurant_id, $days]);
    $ordersByDay = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("SELECT order_type, COUNT(*) as count FROM orders WHERE restaurant_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY order_type");
    $stmt->execute([$restaurant_id, $days]);
    $orderTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("SELECT order_status, COUNT(*) as count FROM orders WHERE restaurant_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY order_status");
    $stmt->execute([$restaurant_id, $days]);
    $orderStatuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Popular Items ──
    $stmt = $conn->prepare("SELECT oi.item_name, SUM(oi.quantity) as total_qty, SUM(oi.total_price) as total_rev FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE o.restaurant_id = ? AND o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY oi.item_name ORDER BY total_qty DESC LIMIT 10");
    $stmt->execute([$restaurant_id, $days]);
    $popularItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Customers ──
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT customer_phone) as total_customers FROM orders WHERE restaurant_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$restaurant_id, $days]);
    $totalCustomers = (int)$stmt->fetchColumn();

    // ── KOT Stats ──
    $stmt = $conn->prepare("SELECT COUNT(*) as active_kot FROM kot WHERE restaurant_id = ? AND kot_status NOT IN ('Completed', 'Cancelled')");
    $stmt->execute([$restaurant_id]);
    $activeKOT = (int)$stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'period_days' => $days,
        'total_visits' => $totalVisits,
        'unique_visitors' => $uniqueVisitors,
        'page_visits' => $pageVisits,
        'visits_by_day' => $visitsByDay,
        'visits_by_hour' => $visitsByHour,
        'recent_visits' => $recentVisits,
        'total_orders' => (int)$orderStats['total_orders'],
        'total_revenue' => (float)$orderStats['total_revenue'],
        'orders_by_day' => $ordersByDay,
        'order_types' => $orderTypes,
        'order_statuses' => $orderStatuses,
        'popular_items' => $popularItems,
        'total_customers' => $totalCustomers,
        'active_kot' => $activeKOT
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
