<?php
// Suppress error display, log errors instead
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';

// Ensure no output before headers
if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json');

// Require login and permission to view dashboard
requirePermission(PERMISSION_VIEW_DASHBOARD);

// Include database connection
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/db_cache.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found']);
    exit();
}
// Ensure consistent local time (IST) for date filters
date_default_timezone_set('Asia/Kolkata');

try {
    // Get connection using getConnection() for lazy connection support
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        // Fallback to $pdo if getConnection() doesn't exist (backward compatibility)
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
        }
    }
    $restaurant_id = $_SESSION['restaurant_id'];
    
    // Get today's date
    $today = date('Y-m-d');
    
    // Total Revenue (Today) - prefer payments table; fallback to orders
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as revenue
                            FROM payments
                            WHERE restaurant_id = ?
                            AND payment_status = 'Success'
                            AND DATE(created_at) = ?");
    $stmt->execute([$restaurant_id, $today]);
    $revenueData = $stmt->fetch();
    $todayRevenue = $revenueData['revenue'] ?? 0;
    if (!$todayRevenue || $todayRevenue == 0) {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(total), 0) as revenue 
                                FROM orders 
                                WHERE restaurant_id = ? 
                                AND payment_status = 'Paid'
                                AND DATE(created_at) = ?");
        $stmt->execute([$restaurant_id, $today]);
        $fallback = $stmt->fetch();
        $todayRevenue = $fallback['revenue'] ?? 0;
    }
    
    // Total Orders (Today)
    $stmt = $conn->prepare("SELECT COUNT(*) as count 
                           FROM orders 
                           WHERE restaurant_id = ? 
                           AND DATE(created_at) = ?");
    $stmt->execute([$restaurant_id, $today]);
    $ordersData = $stmt->fetch();
    $todayOrders = $ordersData['count'] ?? 0;
    
    // Active KOT Count
    $stmt = $conn->prepare("SELECT COUNT(*) as count 
                           FROM kot 
                           WHERE restaurant_id = ? 
                           AND kot_status IN ('Pending', 'Preparing', 'Ready')");
    $stmt->execute([$restaurant_id]);
    $kotData = $stmt->fetch();
    $activeKOT = $kotData['count'] ?? 0;
    
    // Total Customers - Count distinct customers from orders (more accurate)
    // This counts unique customers who have actually placed orders
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT CONCAT(COALESCE(customer_name, ''), '|', COALESCE(customer_phone, ''))) as count 
                           FROM orders 
                           WHERE restaurant_id = ? 
                           AND customer_name IS NOT NULL 
                           AND customer_name != '' 
                           AND customer_name != 'Table Customer'
                           AND customer_name != 'Takeaway'");
    $stmt->execute([$restaurant_id]);
    $customersData = $stmt->fetch();
    $totalCustomers = $customersData['count'] ?? 0;
    
    // Fallback: If no orders yet, count from customers table
    if ($totalCustomers == 0) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count 
                               FROM customers 
                               WHERE restaurant_id = ?");
        $stmt->execute([$restaurant_id]);
        $customersData = $stmt->fetch();
        $totalCustomers = $customersData['count'] ?? 0;
    }
    
    // Available Tables
    $stmt = $conn->prepare("SELECT COUNT(*) as count 
                           FROM tables 
                           WHERE restaurant_id = ? 
                           AND is_available = 1");
    $stmt->execute([$restaurant_id]);
    $tablesData = $stmt->fetch();
    $availableTables = $tablesData['count'] ?? 0;
    
    // Total Tables
    $stmt = $conn->prepare("SELECT COUNT(*) as count 
                           FROM tables 
                           WHERE restaurant_id = ?");
    $stmt->execute([$restaurant_id]);
    $totalTablesData = $stmt->fetch();
    $totalTables = $totalTablesData['count'] ?? 0;
    
    // Recent Orders (Today, latest 5)
    $stmt = $conn->prepare("SELECT o.*, t.table_number, a.area_name 
                           FROM orders o
                           LEFT JOIN tables t ON o.table_id = t.id
                           LEFT JOIN areas a ON t.area_id = a.id
                           WHERE o.restaurant_id = ?
                           AND DATE(o.created_at) = ?
                           ORDER BY o.created_at DESC
                           LIMIT 5");
    $stmt->execute([$restaurant_id, $today]);
    $recentOrders = $stmt->fetchAll();
    
    // Popular Items (Top 5)
    $stmt = $conn->prepare("SELECT oi.item_name, SUM(oi.quantity) as total_qty
                           FROM order_items oi
                           JOIN orders o ON oi.order_id = o.id
                           WHERE o.restaurant_id = ?
                           AND DATE(o.created_at) = ?
                           GROUP BY oi.item_name
                           ORDER BY total_qty DESC
                           LIMIT 5");
    $stmt->execute([$restaurant_id, $today]);
    $popularItems = $stmt->fetchAll();
    
    // Pending Orders Count
    $stmt = $conn->prepare("SELECT COUNT(*) as count 
                           FROM orders 
                           WHERE restaurant_id = ? 
                           AND order_status IN ('Pending', 'Preparing', 'Ready')");
    $stmt->execute([$restaurant_id]);
    $pendingData = $stmt->fetch();
    $pendingOrders = $pendingData['count'] ?? 0;
    
    // Total Menu Items
    $stmt = $conn->prepare("SELECT COUNT(*) as count 
                           FROM menu_items 
                           WHERE restaurant_id = ?");
    $stmt->execute([$restaurant_id]);
    $itemsData = $stmt->fetch();
    $totalItems = $itemsData['count'] ?? 0;
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'todayRevenue' => (float)$todayRevenue,
            'todayOrders' => (int)$todayOrders,
            'activeKOT' => (int)$activeKOT,
            'totalCustomers' => (int)$totalCustomers,
            'availableTables' => (int)$availableTables,
            'totalTables' => (int)$totalTables,
            'pendingOrders' => (int)$pendingOrders,
            'totalItems' => (int)$totalItems
        ],
        'recentOrders' => $recentOrders,
        'popularItems' => $popularItems
    ]);
    
} catch (PDOException $e) {
    error_log("PDO Error in get_dashboard_stats.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.'
    ]);
    exit();
} catch (Exception $e) {
    error_log("Error in get_dashboard_stats.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again.'
    ]);
    exit();
}
?>

