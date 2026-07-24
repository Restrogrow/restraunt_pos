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

// Require permission to manage orders (waiter requests are part of order management)
requirePermission(PERMISSION_MANAGE_ORDERS);

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
    $restaurant_id = $_GET['restaurant_id'] ?? $_SESSION['restaurant_id'] ?? null;
    
    if (!$restaurant_id) {
        throw new Exception('Restaurant ID is required');
    }
    
    // Get waiter requests with table and area info
    $status_filter = $_GET['status'] ?? 'Pending'; // Allow filtering by status
    
    if ($status_filter === 'All') {
        // Show all requests regardless of status
        $sql = "SELECT 
                    wr.*,
                    t.table_number,
                    a.area_name,
                    TIMESTAMPDIFF(MINUTE, wr.created_at, NOW()) as minutes_ago
                FROM waiter_requests wr
                JOIN tables t ON wr.table_id = t.id
                LEFT JOIN areas a ON t.area_id = a.id
                WHERE wr.restaurant_id = ?
                ORDER BY wr.created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$restaurant_id]);
    } else {
        // Filter by specific status (default: Pending)
        $sql = "SELECT 
                    wr.*,
                    t.table_number,
                    a.area_name,
                    TIMESTAMPDIFF(MINUTE, wr.created_at, NOW()) as minutes_ago
                FROM waiter_requests wr
                JOIN tables t ON wr.table_id = t.id
                LEFT JOIN areas a ON t.area_id = a.id
                WHERE wr.restaurant_id = ? AND wr.status = ?
                ORDER BY wr.created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$restaurant_id, $status_filter]);
    }
    $requests = $stmt->fetchAll();
    
    // Format requests for frontend
    $formatted_requests = [];
    foreach ($requests as $request) {
        $formatted_requests[] = [
            'id' => $request['id'],
            'table_id' => $request['table_id'],
            'table_number' => $request['table_number'],
            'area_name' => $request['area_name'] ?? '',
            'request_type' => $request['request_type'] ?? 'General',
            'notes' => $request['notes'] ?? '',
            'status' => $request['status'] ?? 'Pending',
            'created_at' => $request['created_at'],
            'attended_at' => $request['attended_at'] ?? null,
            'minutes_ago' => $request['minutes_ago'] ?? 0
        ];
    }
    
    // Group by area for advanced display (optional)
    $grouped = [];
    foreach ($formatted_requests as $request) {
        $area = $request['area_name'] ?: 'Unknown';
        if (!isset($grouped[$area])) {
            $grouped[$area] = [];
        }
        $grouped[$area][] = $request;
    }
    
    echo json_encode([
        'success' => true,
        'requests' => $formatted_requests, // Main array for dashboard
        'requests_by_area' => $grouped, // Grouped version for advanced UI
        'total_requests' => count($formatted_requests)
    ]);
    
} catch (PDOException $e) {
    error_log("PDO Error in get_waiter_requests.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.'
    ]);
    exit();
} catch (Exception $e) {
    error_log("Error in get_waiter_requests.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
    exit();
}
?>
