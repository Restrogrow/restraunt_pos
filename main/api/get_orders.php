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
require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin();

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
    session_write_close(); // polled endpoint, read-only ($_SESSION stays readable)

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

    require_once __DIR__ . '/../config/soft_delete_helpers.php';
    ensureOrderSoftDeleteColumns($conn);

    // Get filter parameters
    $str = function ($k) { return is_string($_GET[$k] ?? null) ? $_GET[$k] : ''; };
    $statusFilter = $str('status');
    $paymentFilter = $str('payment_status');
    $typeFilter = $str('order_type');
    $searchTerm = $str('search');
    $dateFilter = $str('date');
    // Soft-deleted orders: include_deleted=1 flips the list into the
    // "Deleted" view (deleted orders ONLY) — the live list never mixes them
    // in, they're a separate audit view.
    $includeDeleted = ($_GET['include_deleted'] ?? '') === '1';

    // Build WHERE clause with filters
    $whereConditions = ['o.restaurant_id = ?', waiterOrderFilterSql('o')];
    $params = [$restaurant_id];

    // Deleted filter — first so the deleted-only branch below stays readable.
    $whereConditions[] = $includeDeleted ? 'o.deleted_at IS NOT NULL' : 'o.deleted_at IS NULL';

    // Date filter - default to today if not specified. Scheduled orders are
    // exempt: they're filtered/sorted by scheduled_at instead (see below), so
    // a "today" created_at filter would hide an order placed yesterday for a
    // slot three days from now.
    // active=1 (app Orders / alert poll): every order still being worked on, whatever day it
    // was placed (a website order from 11:58 pm must not vanish at midnight). The last 7 days
    // only, so long-forgotten open orders don't flood the list.
    $activeOnly = ($_GET['active'] ?? '') === '1';
    // any_date=1 (app order search): look across all days instead of one.
    $anyDate = ($_GET['any_date'] ?? '') === '1';
    if ($activeOnly) {
        $whereConditions[] = "o.order_status IN ('Scheduled','Pending','Accepted','Preparing','Ready','Served')";
        $whereConditions[] = 'o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
    } elseif ($statusFilter === 'Scheduled' || $anyDate) {
        // no date constraint — show every still-scheduled order / search all days
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
        $whereConditions[] = '(o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ? OR t.table_number LIKE ?)';
        $searchPattern = '%' . $searchTerm . '%';
        $params[] = $searchPattern;
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

    // How many orders the restaurant has soft-deleted in total — the app
    // shows this as the badge count on the Deleted tab.
    $deletedCountStmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND deleted_at IS NOT NULL");
    $deletedCountStmt->execute([$restaurant_id]);
    $deletedCount = (int)$deletedCountStmt->fetchColumn();
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
                o.prep_minutes,
                o.estimated_ready_at,
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
                o.discount_amount,
                o.coupon_code,
                o.source,
                o.notes,
                o.deleted_at,
                o.deleted_by,
                o.delete_reason,
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
    
    // Get the items of every order on this page in one query instead of one per order
    $itemsByOrder = [];
    if ($result) {
        $ids = array_column($result, 'id');
        $items_sql = "SELECT
                        oi.order_id,
                        oi.item_name,
                        oi.variation_name,
                        oi.quantity,
                        oi.unit_price,
                        oi.total_price,
                        oi.notes,
                        mi.item_type
                      FROM order_items oi
                      LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
                      WHERE oi.order_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                      ORDER BY oi.id";
        $items_stmt = $conn->prepare($items_sql);
        $items_stmt->execute($ids);
        foreach ($items_stmt->fetchAll() as $it) {
            $oid = $it['order_id'];
            unset($it['order_id']);
            $itemsByOrder[$oid][] = $it;
        }
    }

    $orders = [];
    foreach ($result as $row) {
        $row['items'] = $itemsByOrder[$row['id']] ?? [];
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
            'date' => $dateFilter ?: date('Y-m-d'),
            'include_deleted' => $includeDeleted
        ],
        'deleted_count' => $deletedCount
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log("PDO Error in get_orders.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Throwable $e) {
    error_log("Error in get_orders.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,        'message' => 'An error occurred fetching orders. Please try again.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}


?>
