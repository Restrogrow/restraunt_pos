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

// Require permission to manage customers
requirePermission(PERMISSION_MANAGE_CUSTOMERS);

// Include database connection
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found']);
    exit();
}

$restaurant_id = $_SESSION['restaurant_id'];

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
    
    // Helper function to normalize phone number (remove spaces, dashes, etc.)
    function normalizePhone($phone) {
        return preg_replace('/[^0-9]/', '', $phone ?? '');
    }
    
    // Helper function to create unique key from name and phone
    function createCustomerKey($name, $phone) {
        $normalizedPhone = normalizePhone($phone);
        return strtolower(trim($name)) . '|' . $normalizedPhone;
    }
    
    // Get customers from customers table
    $stmt = $conn->prepare("SELECT * FROM customers WHERE restaurant_id = ? ORDER BY customer_name ASC");
    $stmt->execute([$restaurant_id]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all orders and group by normalized name+phone in PHP for accurate aggregation
    // This handles phone number format variations (spaces, dashes, etc.)
    $orderCustomersStmt = $conn->prepare("
        SELECT 
            customer_name, 
            COALESCE(customer_phone, '') as phone, 
            customer_email as email,
            customer_address as address,
            total,
            created_at
        FROM orders 
        WHERE restaurant_id = ? 
        AND customer_name IS NOT NULL 
        AND customer_name != '' 
        AND customer_name != 'Table Customer'
        AND customer_name != 'Takeaway'
        AND created_at != '0000-00-00 00:00:00'
        ORDER BY created_at DESC
    ");
    $orderCustomersStmt->execute([$restaurant_id]);
    $allOrderRows = $orderCustomersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group orders by normalized name+phone combination
    $orderCustomers = [];
    foreach ($allOrderRows as $row) {
        // Skip orders with invalid zero-date created_at to prevent SQL errors
        if ($row['created_at'] == '0000-00-00 00:00:00' || $row['created_at'] == '0000-00-00') continue;
        $key = createCustomerKey($row['customer_name'], $row['phone']);
        
        if (!isset($orderCustomers[$key])) {
            $orderCustomers[$key] = [
                'customer_name' => $row['customer_name'],
                'phone' => $row['phone'],
                'email' => $row['email'] ?? '',
                'address' => $row['address'] ?? '',
                'total_spent' => 0,
                'total_visits' => 0,
                'last_visit_date' => null
            ];
        }
        
        // Aggregate totals
        $orderCustomers[$key]['total_spent'] += (float)$row['total'];
        $orderCustomers[$key]['total_visits']++;
        
        // Keep most recent email, address, and visit date
        if (!empty($row['email']) && empty($orderCustomers[$key]['email'])) {
            $orderCustomers[$key]['email'] = $row['email'];
        }
        if (!empty($row['address']) && empty($orderCustomers[$key]['address'])) {
            $orderCustomers[$key]['address'] = $row['address'];
        }
        if (!$orderCustomers[$key]['last_visit_date'] || $row['created_at'] > $orderCustomers[$key]['last_visit_date']) {
            $orderCustomers[$key]['last_visit_date'] = $row['created_at'];
        }
    }
    $orderCustomers = array_values($orderCustomers);
    
    // Merge customers - use customers table as base, update with order data
    $customerMap = [];
    foreach ($customers as $c) {
        $key = createCustomerKey($c['customer_name'], $c['phone'] ?? '');
        $customerMap[$key] = $c;
    }
    
    // Add/update customers from orders
    foreach ($orderCustomers as $oc) {
        $key = createCustomerKey($oc['customer_name'], $oc['phone'] ?? '');
        if (isset($customerMap[$key])) {
            // Update existing customer with order data if missing
            if (empty($customerMap[$key]['phone']) && !empty($oc['phone'])) {
                $customerMap[$key]['phone'] = $oc['phone'];
            }
            if (empty($customerMap[$key]['email']) && !empty($oc['email'])) {
                $customerMap[$key]['email'] = $oc['email'];
            }
            if (empty($customerMap[$key]['address']) && !empty($oc['address'])) {
                $customerMap[$key]['address'] = $oc['address'];
            }
            // Update spending/visits from orders (use the aggregated values from orders)
            $customerMap[$key]['total_spent'] = (float)$oc['total_spent'];
            $customerMap[$key]['total_visits'] = (int)$oc['total_visits'];
            $customerMap[$key]['last_visit_date'] = $oc['last_visit_date'] ? substr($oc['last_visit_date'], 0, 10) : null;
        } else {
            // Add new customer from orders
            $customerMap[$key] = [
                'id' => null,
                'restaurant_id' => $restaurant_id,
                'customer_name' => $oc['customer_name'],
                'phone' => $oc['phone'] ?? '',
                'email' => $oc['email'] ?? '',
                'address' => $oc['address'] ?? '',
                'total_spent' => (float)$oc['total_spent'],
                'total_visits' => (int)$oc['total_visits'],
                'last_visit_date' => $oc['last_visit_date'] ? substr($oc['last_visit_date'], 0, 10) : null,
                'created_at' => null,
                'updated_at' => null
            ];
        }
    }
    
    // Convert back to array and sort
    $allCustomers = array_values($customerMap);
    usort($allCustomers, function($a, $b) {
        return strcasecmp($a['customer_name'], $b['customer_name']);
    });

    // Pagination — get page and limit from query params
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;
    $totalCount = count($allCustomers);
    $totalPages = (int)ceil($totalCount / $limit);
    $allCustomers = array_slice($allCustomers, $offset, $limit);

    // Recalculate spending and visits from orders table for accuracy (for customers table entries)
    // Fetch all orders and group by normalized name+phone in PHP for accurate matching
    $allOrdersStmt = $conn->prepare("SELECT customer_name, customer_phone, total, created_at
                                     FROM orders 
                                     WHERE restaurant_id = ? 
                                     AND customer_name IS NOT NULL 
                                     AND customer_name != '' 
                                     AND customer_name != 'Table Customer'
                                     AND customer_name != 'Takeaway'
                                     AND created_at != '0000-00-00 00:00:00'");
    $allOrdersStmt->execute([$restaurant_id]);
    $allOrders = $allOrdersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group orders by normalized name+phone
    $orderTotals = [];
    foreach ($allOrders as $order) {
        // Skip orders with invalid zero-date created_at to prevent SQL errors
        if ($order['created_at'] == '0000-00-00 00:00:00' || $order['created_at'] == '0000-00-00') continue;
        $key = createCustomerKey($order['customer_name'], $order['customer_phone'] ?? '');
        if (!isset($orderTotals[$key])) {
            $orderTotals[$key] = [
                'spent' => 0,
                'visits' => 0,
                'last_order' => null
            ];
        }
        $orderTotals[$key]['spent'] += (float)$order['total'];
        $orderTotals[$key]['visits']++;
        if (!$orderTotals[$key]['last_order'] || $order['created_at'] > $orderTotals[$key]['last_order']) {
            $orderTotals[$key]['last_order'] = $order['created_at'];
        }
    }
    
    $updStmt = $conn->prepare("UPDATE customers SET total_spent = ?, total_visits = ?, last_visit_date = DATE(?) WHERE id = ?");

    foreach ($allCustomers as &$c) {
        if ($c['id']) { // Only update if exists in customers table
            $key = createCustomerKey($c['customer_name'], $c['phone'] ?? '');
            
            if (isset($orderTotals[$key])) {
                $calcSpent = (float)$orderTotals[$key]['spent'];
                $calcVisits = (int)$orderTotals[$key]['visits'];
                $lastOrder = $orderTotals[$key]['last_order'];

                // If values differ, update DB so future fetches are fast
                if ((float)$c['total_spent'] !== $calcSpent || (int)$c['total_visits'] !== $calcVisits || ($lastOrder && $c['last_visit_date'] != substr($lastOrder,0,10))) {
                    $updStmt->execute([$calcSpent, $calcVisits, $lastOrder, $c['id']]);
                    $c['total_spent'] = $calcSpent;
                    $c['total_visits'] = $calcVisits;
                    $c['last_visit_date'] = $lastOrder ? substr($lastOrder,0,10) : $c['last_visit_date'];
                }
            }
        }
    }

    echo json_encode(['success' => true, 'data' => $allCustomers, 'total_count' => $totalCount, 'total_pages' => $totalPages, 'page' => $page, 'limit' => $limit]);
} catch (PDOException $e) {
    error_log("PDO Error in get_customers.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.'
    ]);
    exit();
} catch (Exception $e) {
    error_log("Error in get_customers.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
    exit();
}

