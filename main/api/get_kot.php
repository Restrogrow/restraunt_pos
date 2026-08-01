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

header('Content-Type: application/json');

// Require permission to view KOT
requirePermission(PERMISSION_VIEW_KOT);

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
    // Always scope to the authenticated session's own tenant — never trust a
    // client-supplied restaurant_id, or one restaurant's staff could read another's KOT tickets.
    $restaurant_id = $_SESSION['restaurant_id'] ?? null;
    
    if (!$restaurant_id) {
        throw new Exception('Restaurant ID is required');
    }
    
    // Check if customer columns exist in kot table
    $checkCols = $conn->query("SHOW COLUMNS FROM kot LIKE 'customer_phone'");
    $hasCustomerCols = $checkCols->rowCount() > 0;
    
    // Get KOT orders (not completed) with optional filters
    $statusFilter = $_GET['status'] ?? '';
    $tableFilter = $_GET['table'] ?? '';
    $kotIdFilter = $_GET['kot_id'] ?? '';
    $customerFields = $hasCustomerCols ? ', k.customer_phone, k.customer_email, k.customer_address' : '';
    
    $whereClause = "k.restaurant_id = ?";
    $params = [$restaurant_id];
    
    if ($kotIdFilter) {
        $whereClause .= " AND k.id = ?";
        $params[] = (int)$kotIdFilter;
    } else {
        $whereClause .= " AND k.kot_status IN ('Pending', 'Preparing', 'Ready')";
    }
    if ($statusFilter && in_array($statusFilter, ['Pending', 'Preparing', 'Ready'])) {
        $whereClause .= " AND k.kot_status = ?";
        $params[] = $statusFilter;
    }
    if ($tableFilter) {
        $whereClause .= " AND k.table_id = ?";
        $params[] = $tableFilter;
    }
    
    $sql = "SELECT 
                k.id,
                k.kot_number,
                k.table_id,
                k.kot_status,
                k.order_type,
                k.customer_name
                $customerFields,
                k.created_at,
                k.subtotal,
                k.tax,
                k.total,
                k.notes,
                t.table_number,
                a.area_name,
                COUNT(ki.id) as item_count
            FROM kot k
            LEFT JOIN tables t ON k.table_id = t.id
            LEFT JOIN areas a ON t.area_id = a.id
            LEFT JOIN kot_items ki ON k.id = ki.kot_id
            WHERE $whereClause
            GROUP BY k.id
            ORDER BY k.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetchAll();
    
    $kots = [];
    foreach ($result as $row) {
        // Get KOT items
        $items_sql = "SELECT 
                        ki.item_name,
                        ki.quantity,
                        ki.unit_price,
                        ki.total_price,
                        ki.notes
                      FROM kot_items ki
                      WHERE ki.kot_id = ?
                      ORDER BY ki.id";
        
        $items_stmt = $conn->prepare($items_sql);
        $items_stmt->execute([$row['id']]);
        $items = $items_stmt->fetchAll();
        
        $row['items'] = $items;
        $row['table_name'] = $row['table_number'] ? $row['table_number'] . ' - ' . $row['area_name'] : null;
        
        $kots[] = $row;
    }
    
    $response = ['success' => true, 'kots' => $kots];
    if ($kotIdFilter && !empty($kots)) {
        $response['kot'] = $kots[0];
    }
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log("PDO Error in get_kot.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.'
    ]);
    exit();
} catch (Exception $e) {
    error_log("Error in get_kot.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred fetching orders. Please try again.'
    ]);
    exit();
}
?>
