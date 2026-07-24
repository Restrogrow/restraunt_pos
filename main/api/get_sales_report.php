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
header('Access-Control-Allow-Origin: *');

// Require permission to view reports
requirePermission(PERMISSION_VIEW_REPORTS);

// Include database connection
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found']);
    exit();
}

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
    
    // Validate restaurant_id
    if (!isset($_SESSION['restaurant_id'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Session expired. Please login again.'
        ]);
        exit();
    }
    
    $restaurant_id = $_SESSION['restaurant_id'];
    $period = $_GET['period'] ?? 'today';
    $type = $_GET['type'] ?? 'sales';
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';
    $paymentMethod = $_GET['payment_method'] ?? 'all';
    
    // Build payment method filter
    $paymentFilter = '';
    $paymentFilterParams = [];
    if ($paymentMethod !== 'all' && $type === 'sales') {
        $paymentFilter = " AND payment_method = ?";
        $paymentFilterParams[] = $paymentMethod;
    }
    
    // Calculate date range based on period
    $dateCondition = '';
    $dateConditionNoAlias = '';
    $dateParams = [];
    
    if ($period === 'custom' && !empty($startDate) && !empty($endDate)) {
        $dateCondition = "DATE(o.created_at) BETWEEN ? AND ?";
        $dateConditionNoAlias = "DATE(created_at) BETWEEN ? AND ?";
        $dateParams = [$startDate, $endDate];
    } else {
        switch ($period) {
            case 'today':
                $dateCondition = "DATE(o.created_at) = CURDATE()";
                $dateConditionNoAlias = "DATE(created_at) = CURDATE()";
                break;
            case 'week':
                $dateCondition = "o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                $dateConditionNoAlias = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                break;
            case 'month':
                $dateCondition = "o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                $dateConditionNoAlias = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                break;
            case 'year':
                $dateCondition = "o.created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)";
                $dateConditionNoAlias = "created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)";
                break;
            default:
                $dateCondition = "DATE(o.created_at) = CURDATE()";
                $dateConditionNoAlias = "DATE(created_at) = CURDATE()";
        }
    }
    
    // Get total sales
    $salesParams = array_merge([$restaurant_id], $dateParams, $paymentFilterParams);
    $salesStmt = $conn->prepare("SELECT COALESCE(SUM(total), 0) as total_sales FROM orders WHERE restaurant_id = ? AND " . $dateConditionNoAlias . $paymentFilter);
    $salesStmt->execute($salesParams);
    $sales = $salesStmt->fetch(PDO::FETCH_ASSOC);
    $totalSales = $sales['total_sales'] ?? 0;
    
    // Get total orders
    $ordersParams = array_merge([$restaurant_id], $dateParams, $paymentFilterParams);
    $ordersStmt = $conn->prepare("SELECT COUNT(*) as total_orders FROM orders WHERE restaurant_id = ? AND " . $dateConditionNoAlias . $paymentFilter);
    $ordersStmt->execute($ordersParams);
    $orders = $ordersStmt->fetch(PDO::FETCH_ASSOC);
    $totalOrders = $orders['total_orders'] ?? 0;
    
    // Get total items sold
    $itemsParams = array_merge([$restaurant_id], $dateParams);
    $itemsStmt = $conn->prepare("
        SELECT COALESCE(SUM(oi.quantity), 0) as total_items 
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE o.restaurant_id = ? AND " . $dateCondition . "
    ");
    $itemsStmt->execute($itemsParams);
    $items = $itemsStmt->fetch(PDO::FETCH_ASSOC);
    $totalItems = $items['total_items'] ?? 0;
    
    // Get total customers
    $customersParams = array_merge([$restaurant_id], $dateParams, $paymentFilterParams);
    $customersStmt = $conn->prepare("
        SELECT COUNT(DISTINCT customer_name) as total_customers 
        FROM orders 
        WHERE restaurant_id = ? AND " . $dateConditionNoAlias . $paymentFilter . "
    ");
    $customersStmt->execute($customersParams);
    $customers = $customersStmt->fetch(PDO::FETCH_ASSOC);
    $totalCustomers = $customers['total_customers'] ?? 0;
    
    // Get sales details
    $detailsParams = array_merge([$restaurant_id], $dateParams, $paymentFilterParams);
    $detailsStmt = $conn->prepare("
        SELECT o.id, o.order_number, o.customer_name, o.payment_method, o.total, o.created_at,
               COUNT(oi.id) as item_count
        FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE o.restaurant_id = ? AND " . $dateCondition . $paymentFilter . "
        GROUP BY o.id
        ORDER BY o.created_at DESC
        LIMIT 100
    ");
    $detailsStmt->execute($detailsParams);
    $salesDetails = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get top items
    $topItemsParams = array_merge([$restaurant_id], $dateParams);
    $topItemsStmt = $conn->prepare("
        SELECT oi.item_name, SUM(oi.quantity) as total_quantity, SUM(oi.total_price) as total_revenue
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE o.restaurant_id = ? AND " . $dateCondition . "
        GROUP BY oi.item_name
        ORDER BY total_quantity DESC
        LIMIT 10
    ");
    $topItemsStmt->execute($topItemsParams);
    $topItems = $topItemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get payment methods breakdown
    $paymentMethodsParams = array_merge([$restaurant_id], $dateParams);
    $paymentMethodsStmt = $conn->prepare("
        SELECT payment_method, COUNT(*) as count, SUM(total) as amount
        FROM orders
        WHERE restaurant_id = ? AND " . $dateConditionNoAlias . "
        GROUP BY payment_method
    ");
    $paymentMethodsStmt->execute($paymentMethodsParams);
    $paymentMethods = $paymentMethodsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get top customers (by total spending)
    $topCustomersParams = array_merge([$restaurant_id], $dateParams);
    $topCustomersStmt = $conn->prepare("
        SELECT 
            customer_name,
            customer_phone as phone,
            COUNT(*) as total_orders,
            SUM(total) as total_spent,
            MAX(created_at) as last_order_date
        FROM orders
        WHERE restaurant_id = ? 
        AND " . $dateConditionNoAlias . "
        AND customer_name IS NOT NULL
        AND customer_name != ''
        AND customer_name != 'Table Customer'
        AND customer_name != 'Takeaway'
        GROUP BY customer_name, customer_phone
        ORDER BY total_spent DESC
        LIMIT 20
    ");
    $topCustomersStmt->execute($topCustomersParams);
    $topCustomers = $topCustomersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get hourly sales breakdown (for today only)
    $hourlySales = [];
    if ($period === 'today') {
        $hourlyStmt = $conn->prepare("
            SELECT 
                HOUR(created_at) as hour,
                COUNT(*) as order_count,
                SUM(total) as total_sales
            FROM orders
            WHERE restaurant_id = ? 
            AND DATE(created_at) = CURDATE()
            GROUP BY HOUR(created_at)
            ORDER BY hour ASC
        ");
        $hourlyStmt->execute([$restaurant_id]);
        $hourlySales = $hourlyStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get staff performance (if staff_id is stored in orders)
    $staffPerformance = [];
    try {
        // Check if staff_id column exists in orders table
        $checkColumn = $conn->query("SHOW COLUMNS FROM orders LIKE 'staff_id'");
        if ($checkColumn->rowCount() > 0) {
            $staffStmt = $conn->prepare("
                SELECT 
                    COALESCE(s.member_name, 'Unknown') as staff_name,
                    COUNT(o.id) as total_orders,
                    SUM(o.total) as total_sales
                FROM orders o
                LEFT JOIN staff s ON o.staff_id = s.id AND s.restaurant_id = ?
                WHERE o.restaurant_id = ? 
                AND " . $dateConditionNoAlias . "
                GROUP BY o.staff_id, s.member_name
                ORDER BY total_sales DESC
                LIMIT 20
            ");
            $staffStmt->execute([$restaurant_id, $restaurant_id]);
            $staffPerformance = $staffStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // If staff_id column doesn't exist or query fails, just skip it
        error_log("Staff performance query skipped: " . $e->getMessage());
        $staffPerformance = [];
    }
    
    // Build response based on report type
    $response = [
        'success' => true,
        'summary' => [
            'total_sales' => floatval($totalSales),
            'total_orders' => intval($totalOrders),
            'total_items' => intval($totalItems),
            'total_customers' => intval($totalCustomers)
        ],
        'sales_details' => $salesDetails,
        'top_items' => $topItems,
        'payment_methods' => $paymentMethods,
        'top_customers' => $topCustomers,
        'hourly_sales' => $hourlySales,
        'staff_performance' => $staffPerformance,
        'report_type' => $type,
        'period' => $period
    ];
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    $errorMsg = $e->getMessage();
    $errorCode = $e->getCode();
    error_log("PDO Error in get_sales_report.php: " . $errorMsg);
    error_log("PDO Error code: " . $errorCode);
    error_log("Period: " . ($period ?? 'N/A') . ", Type: " . ($type ?? 'N/A'));
    error_log("Date condition: " . ($dateCondition ?? 'N/A'));
    error_log("Date condition no alias: " . ($dateConditionNoAlias ?? 'N/A'));
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.',
        'debug' => [
            'error_code' => $errorCode,
            'error_message' => $errorMsg
        ]
    ]);
    exit();
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    error_log("Error in get_sales_report.php: " . $errorMsg);
    error_log("Period: " . ($period ?? 'N/A') . ", Type: " . ($type ?? 'N/A'));
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $errorMsg
    ]);
    exit();
}
?>

