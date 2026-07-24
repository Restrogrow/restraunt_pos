<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';

// Require permission to view KOT
requirePermission(PERMISSION_VIEW_KOT);

require_once '../db_connection.php';

try {
    if (!function_exists('getConnection')) {
        throw new Exception('Database connection function not available');
    }
    $conn = getConnection();
    
    $restaurant_id = $_SESSION['restaurant_id'];
    
    // Get KOT orders (orders that are not completed or cancelled)
    $sql = "SELECT 
                o.id,
                o.order_number,
                o.table_id,
                o.order_status,
                o.payment_status,
                o.order_type,
                o.customer_name,
                o.created_at,
                o.subtotal,
                o.tax,
                o.total,
                t.table_number,
                a.area_name,
                COUNT(oi.id) as item_count
            FROM orders o
            LEFT JOIN tables t ON o.table_id = t.id
            LEFT JOIN areas a ON t.area_id = a.id
            LEFT JOIN order_items oi ON o.id = oi.order_id
            WHERE o.restaurant_id = ? 
            AND o.order_status IN ('Pending', 'Preparing', 'Ready')
            GROUP BY o.id
            ORDER BY o.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$restaurant_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $orders = [];
    foreach ($rows as $row) {
        // Get order items
        $items_sql = "SELECT 
                        oi.item_name,
                        oi.quantity,
                        oi.unit_price,
                        oi.total_price
                      FROM order_items oi
                      WHERE oi.order_id = ?
                      ORDER BY oi.id";
        
        $items_stmt = $conn->prepare($items_sql);
        $items_stmt->execute([$row['id']]);
        $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $row['items'] = $items;
        $row['table_name'] = $row['table_number'] ? $row['table_number'] . ' - ' . $row['area_name'] : null;
        
        $orders[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'orders' => $orders
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_kot_orders.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred fetching orders. Please try again.'
    ]);
}
?>
