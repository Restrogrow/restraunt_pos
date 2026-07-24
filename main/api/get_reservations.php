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
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Require permission to manage reservations
requirePermission(PERMISSION_MANAGE_RESERVATIONS);

// Include database connection
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection file not found',
        'data' => []
    ]);
    exit();
}

// Include validation and rate limiting
if (file_exists(__DIR__ . '/../config/validation.php')) {
    require_once __DIR__ . '/../config/validation.php';
}
if (file_exists(__DIR__ . '/../config/rate_limit.php')) {
    require_once __DIR__ . '/../config/rate_limit.php';
    // Apply rate limiting: 60 requests per minute for GET requests
    applyRateLimit(60, 60);
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
    
    // Get and validate filter parameters
    $dateFilter = isset($_GET['date']) ? trim($_GET['date']) : '';
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
    $dateRange = isset($_GET['date_range']) ? trim($_GET['date_range']) : '';
    
    // Validate date filter if provided
    if (!empty($dateFilter)) {
        $dateValidation = validateDate($dateFilter, 'Y-m-d');
        if (!$dateValidation['valid']) {
            throw new Exception($dateValidation['message']);
        }
        $dateFilter = $dateValidation['value'];
    }
    
    // Validate date range if provided (format: "start_date,end_date")
    if (!empty($dateRange)) {
        $dates = explode(',', $dateRange);
        if (count($dates) === 2) {
            $rangeValidation = validateDateRange(trim($dates[0]), trim($dates[1]), 'Y-m-d');
            if (!$rangeValidation['valid']) {
                throw new Exception($rangeValidation['message']);
            }
        }
    }
    
    // Sanitize search input
    if (!empty($search)) {
        $search = sanitizeString($search);
        if (strlen($search) > 100) {
            throw new Exception('Search term is too long');
        }
    }
    
    // Build the query
    $sql = "SELECT r.*, t.table_number, a.area_name 
            FROM reservations r 
            LEFT JOIN tables t ON r.table_id = t.id 
            LEFT JOIN areas a ON t.area_id = a.id 
            WHERE r.restaurant_id = ?";
    $params = [$restaurant_id];
    
    // Add date filter if provided
    if (!empty($dateFilter)) {
        $sql .= " AND r.reservation_date = ?";
        $params[] = $dateFilter;
    }
    
    // Add date range filter if provided
    if (!empty($dateRange)) {
        $dates = explode(',', $dateRange);
        if (count($dates) === 2) {
            $sql .= " AND r.reservation_date BETWEEN ? AND ?";
            $params[] = trim($dates[0]);
            $params[] = trim($dates[1]);
        }
    }
    
    // Add status filter if provided
    if (!empty($statusFilter) && in_array($statusFilter, ['Pending', 'Confirmed', 'Cancelled', 'Completed', 'No Show'])) {
        $sql .= " AND r.status = ?";
        $params[] = $statusFilter;
    }
    
    // Add search filter if provided
    if (!empty($search)) {
        $sql .= " AND (r.customer_name LIKE ? OR r.phone LIKE ? OR r.email LIKE ?)";
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    $sql .= " ORDER BY r.reservation_date DESC, r.time_slot ASC";
    
    // Execute query
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return success response
    echo json_encode([
        'success' => true,
        'data' => $reservations,
        'count' => count($reservations)
    ]);
    
} catch (PDOException $e) {
    error_log("PDO Error in get_reservations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.',
        'data' => []
    ]);
    exit();
} catch (Exception $e) {
    error_log("Error in get_reservations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again.',
        'data' => []
    ]);
    exit();
}
?>

