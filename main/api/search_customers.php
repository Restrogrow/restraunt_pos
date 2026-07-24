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
try {
requirePermission(PERMISSION_MANAGE_CUSTOMERS);
} catch (Exception $e) {
    error_log("Permission error in search_customers.php: " . $e->getMessage());
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Permission denied. Please login again.'
    ]);
    exit();
}

// Include database connection
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found']);
    exit();
}

// Get restaurant_id from session
if (!isset($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Session expired. Please login again.'
    ]);
    exit();
}

$restaurant_id = $_SESSION['restaurant_id'];
$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$type = isset($_GET['type']) ? trim($_GET['type']) : 'name'; // 'name' or 'phone'

// Normalize phone number (remove spaces, dashes, etc. for better matching)
function normalizePhone($phone) {
    return preg_replace('/[^0-9]/', '', $phone);
}

if (empty($query) || strlen($query) < 1) {
    echo json_encode(['success' => true, 'customers' => []]);
    exit();
}

try {
    // Get database connection using getConnection() for lazy connection support
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        // Fallback to $pdo if getConnection() doesn't exist (backward compatibility)
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
    }
    }
    
    $customers = [];
    
    if ($type === 'phone') {
        // Search by phone number
        // Search in customers table
        $stmt = $conn->prepare("
            SELECT DISTINCT 
                customer_name as name, 
                phone, 
                email, 
                NULL as address,
                'customer' as source
            FROM customers 
            WHERE restaurant_id = ? 
            AND phone LIKE ?
            ORDER BY customer_name ASC
            LIMIT 10
        ");
        $stmt->execute([$restaurant_id, '%' . $query . '%']);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Also search in orders table
        $orderStmt = $conn->prepare("
            SELECT DISTINCT 
                customer_name as name, 
                customer_phone as phone, 
                customer_email as email,
                customer_address as address,
                'order' as source
            FROM orders 
            WHERE restaurant_id = ? 
            AND customer_phone LIKE ?
            AND customer_phone IS NOT NULL
            AND customer_phone != ''
            AND customer_name IS NOT NULL
            AND customer_name != ''
            AND customer_name != 'Table Customer'
            AND customer_name != 'Takeaway'
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $orderStmt->execute([$restaurant_id, '%' . $query . '%']);
        $orderCustomers = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Merge and deduplicate by normalized phone
        $phoneMap = [];
        foreach ($customers as $c) {
            $normalizedPhone = normalizePhone($c['phone'] ?? '');
            if ($normalizedPhone) {
                $phoneMap[$normalizedPhone] = $c;
            }
        }
        foreach ($orderCustomers as $oc) {
            $normalizedPhone = normalizePhone($oc['phone'] ?? '');
            if ($normalizedPhone) {
                if (!isset($phoneMap[$normalizedPhone])) {
                    $phoneMap[$normalizedPhone] = $oc;
            } else {
                // Update with more complete data
                    if (empty($phoneMap[$normalizedPhone]['email']) && !empty($oc['email'])) {
                        $phoneMap[$normalizedPhone]['email'] = $oc['email'];
                }
                    if (empty($phoneMap[$normalizedPhone]['address']) && !empty($oc['address'])) {
                        $phoneMap[$normalizedPhone]['address'] = $oc['address'];
                    }
                    // Keep the most recent name if different
                    if (!empty($oc['name']) && empty($phoneMap[$normalizedPhone]['name'])) {
                        $phoneMap[$normalizedPhone]['name'] = $oc['name'];
                    }
                }
            }
        }
        $customers = array_values($phoneMap);
    } else {
        // Search by name
        // Search in customers table
        $stmt = $conn->prepare("
            SELECT DISTINCT 
                customer_name as name, 
                phone, 
                email, 
                NULL as address,
                'customer' as source
            FROM customers 
            WHERE restaurant_id = ? 
            AND customer_name LIKE ?
            ORDER BY customer_name ASC
            LIMIT 10
        ");
        $stmt->execute([$restaurant_id, '%' . $query . '%']);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Also search in orders table
        $orderStmt = $conn->prepare("
            SELECT DISTINCT 
                customer_name as name, 
                customer_phone as phone, 
                customer_email as email,
                customer_address as address,
                'order' as source
            FROM orders 
            WHERE restaurant_id = ? 
            AND customer_name LIKE ?
            AND customer_name IS NOT NULL
            AND customer_name != ''
            AND customer_name != 'Table Customer'
            AND customer_name != 'Takeaway'
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $orderStmt->execute([$restaurant_id, '%' . $query . '%']);
        $orderCustomers = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Merge and deduplicate by name+normalized phone combination
        $namePhoneMap = [];
        foreach ($customers as $c) {
            $normalizedPhone = normalizePhone($c['phone'] ?? '');
            $key = strtolower(trim($c['name'])) . '|' . $normalizedPhone;
            $namePhoneMap[$key] = $c;
        }
        foreach ($orderCustomers as $oc) {
            $normalizedPhone = normalizePhone($oc['phone'] ?? '');
            $key = strtolower(trim($oc['name'])) . '|' . $normalizedPhone;
            if (!isset($namePhoneMap[$key])) {
                $namePhoneMap[$key] = $oc;
            } else {
                // Update with more complete data
                if (empty($namePhoneMap[$key]['phone']) && !empty($oc['phone'])) {
                    $namePhoneMap[$key]['phone'] = $oc['phone'];
                }
                if (empty($namePhoneMap[$key]['email']) && !empty($oc['email'])) {
                    $namePhoneMap[$key]['email'] = $oc['email'];
                }
                if (empty($namePhoneMap[$key]['address']) && !empty($oc['address'])) {
                    $namePhoneMap[$key]['address'] = $oc['address'];
                }
            }
        }
        $customers = array_values($namePhoneMap);
    }
    
    echo json_encode([
        'success' => true,
        'customers' => $customers
    ]);
} catch (PDOException $e) {
    $errorMsg = $e->getMessage();
    $errorCode = $e->getCode();
    error_log("PDO Error in search_customers.php: " . $errorMsg);
    error_log("PDO Error code: " . $errorCode);
    error_log("PDO Error trace: " . $e->getTraceAsString());
    error_log("Query: " . ($query ?? 'N/A') . ", Type: " . ($type ?? 'N/A') . ", Restaurant ID: " . ($restaurant_id ?? 'N/A'));
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
    error_log("Error in search_customers.php: " . $errorMsg);
    error_log("Error trace: " . $e->getTraceAsString());
    error_log("Query: " . ($query ?? 'N/A') . ", Type: " . ($type ?? 'N/A') . ", Restaurant ID: " . ($restaurant_id ?? 'N/A'));
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $errorMsg
    ]);
    exit();
}

