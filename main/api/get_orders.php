<?php
// Suppress error display, log errors instead
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Ensure no output before headers
if (ob_get_level()) {
    ob_clean();
}

// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json; charset=UTF-8');

// Require permission to manage orders
requirePermission(PERMISSION_MANAGE_ORDERS);

// Include database connection
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found'], JSON_UNESCAPED_UNICODE);
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
    // Always scope to the authenticated session's own tenant — never trust a
    // client-supplied restaurant_id, or one restaurant's staff could read another's orders.
    $restaurant_id = $_SESSION['restaurant_id'] ?? null;

    if (!$restaurant_id) {
        throw new Exception('Restaurant ID is required');
    }

    // Every admin poll of this endpoint doubles as the trigger that "goes
    // live" any scheduled order whose time has arrived — see
    // scheduled_order_helpers.php. This means the feature works with zero
    // cron setup as long as someone has an admin/kitchen screen open (which,
    // for an active restaurant, is the normal case); main/cron/
    // activate_scheduled_orders.php exists as a backstop for when nobody does.
    require_once __DIR__ . '/../config/scheduled_order_helpers.php';
    activateDueScheduledOrders($conn, $restaurant_id);

    // Get filter parameters
    $statusFilter = $_GET['status'] ?? '';
    $paymentFilter = $_GET['payment_status'] ?? '';
    $typeFilter = $_GET['order_type'] ?? '';
    $searchTerm = $_GET['search'] ?? '';
    $dateFilter = $_GET['date'] ?? '';

    // Build WHERE clause with filters
    $whereConditions = ['o.restaurant_id = ?'];
    $params = [$restaurant_id];

    // Date filter - default to today if not specified. Scheduled orders are
    // exempt: they're filtered/sorted by scheduled_at instead (see below), so
    // a "today" created_at filter would hide an order placed yesterday for a
    // slot three days from now.
    if ($statusFilter === 'Scheduled') {
        // no date constraint — show every still-scheduled order
    } elseif ($dateFilter) {
        // Filter by specific date
        $whereConditions[] = 'DATE(o.created_at) = ?';
        $params[] = $dateFilter;
    } else {
        // Default to today's orders
        $today = date('Y-m-d');
        $whereConditions[] = 'DATE(o.created_at) = ?';
        $params[] = $today;
    }

    if ($statusFilter) {
        $whereConditions[] = 'o.order_status = ?';
        $params[] = $statusFilter;
    }
    
    if ($paymentFilter) {
        $whereConditions[] = 'o.payment_status = ?';
        $params[] = $paymentFilter;
    }
    
    if ($typeFilter) {
        $whereConditions[] = 'o.order_type = ?';
        $params[] = $typeFilter;
    }
    
    if ($searchTerm) {
        $whereConditions[] = '(o.order_number LIKE ? OR o.customer_name LIKE ? OR t.table_number LIKE ?)';
        $searchPattern = '%' . $searchTerm . '%';
        $params[] = $searchPattern;
        $params[] = $searchPattern;
        $params[] = $searchPattern;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Pagination — get page and limit from query params
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;
    
    // First, get total count for pagination
    $countSql = "SELECT COUNT(*) FROM orders o
                 LEFT JOIN tables t ON o.table_id = t.id
                 LEFT JOIN areas a ON t.area_id = a.id
                 WHERE " . $whereClause;
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($totalCount / $limit);
    
    // Get paginated orders
    $sql = "SELECT 
                o.id,
                o.order_number,
                o.table_id,
                o.order_status,
                o.payment_status,
                o.order_type,
                o.payment_method,
                o.customer_name,
                o.customer_phone,
                o.customer_email,
                o.customer_address,
                o.created_at,
                o.scheduled_at,
                o.is_scheduled,
                o.subtotal,
                o.tax,
                o.total,
                o.notes,
                t.table_number,
                a.area_name,
                (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) as item_count
            FROM orders o
            LEFT JOIN tables t ON o.table_id = t.id
            LEFT JOIN areas a ON t.area_id = a.id
            WHERE " . $whereClause . "
            ORDER BY " . ($statusFilter === 'Scheduled' ? 'o.scheduled_at ASC' : 'o.created_at DESC') . "
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $result = $stmt->fetchAll();
    
    $orders = [];
    foreach ($result as $row) {
        // Get order items
        $items_sql = "SELECT 
                        oi.item_name,
                        oi.quantity,
                        oi.unit_price,
                        oi.total_price,
                        oi.notes
                      FROM order_items oi
                      WHERE oi.order_id = ?
                      ORDER BY oi.id";
        
        $items_stmt = $conn->prepare($items_sql);
        $items_stmt->execute([$row['id']]);
        $items = $items_stmt->fetchAll();
        
        $row['items'] = $items;
        $row['table_name'] = $row['table_number'] ? $row['table_number'] . ' - ' . $row['area_name'] : null;
        
        $orders[] = $row;
    }
    
    // Return orders with pagination info
    echo json_encode([
        'success' => true,
        'orders' => $orders,
        'count' => count($orders),
        'total_count' => $totalCount,
        'total_pages' => $totalPages,
        'page' => $page,
        'limit' => $limit,
        'filters_applied' => [
            'status' => $statusFilter,
            'payment_status' => $paymentFilter,
            'order_type' => $typeFilter,
            'date' => $dateFilter ?: date('Y-m-d')
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log("PDO Error in get_orders.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in get_orders.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,        'message' => 'An error occurred fetching orders. Please try again.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}


?>
