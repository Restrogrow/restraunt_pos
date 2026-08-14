<?php
// Get Contact Queries API
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

// contact_queries holds every "Contact Us" lead submitted platform-wide with
// no restaurant_id/tenant scoping — it's superadmin's own sales-lead inbox,
// not per-restaurant data. Any logged-in restaurant admin/staff previously
// passed this check and could read (and modify, see update_query_status.php)
// every other tenant's leads.
if (!isset($_SESSION['superadmin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
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
    
    // Check if table exists
    $checkTable = $conn->query("SHOW TABLES LIKE 'contact_queries'");
    if ($checkTable->rowCount() === 0) {
        echo json_encode([
            'success' => true,
            'queries' => [],
            'total' => 0
        ]);
        exit();
    }
    
    // Get filter parameters
    $status = $_GET['status'] ?? 'all';
    $limit = intval($_GET['limit'] ?? 100);
    $offset = intval($_GET['offset'] ?? 0);
    
    // Build query
    $whereClause = '';
    if ($status !== 'all') {
        $whereClause = "WHERE status = " . $conn->quote($status);
    }
    
    // Get total count
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM contact_queries " . $whereClause);
    $countStmt->execute();
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Get queries
    $stmt = $conn->prepare("
        SELECT id, name, email, phone, message, status, created_at, updated_at
        FROM contact_queries 
        " . $whereClause . "
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$limit, $offset]);
    $queries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get status counts
    $statusCountsStmt = $conn->query("
        SELECT status, COUNT(*) as count 
        FROM contact_queries 
        GROUP BY status
    ");
    $statusCounts = $statusCountsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $statusCountsMap = [
        'new' => 0,
        'read' => 0,
        'replied' => 0,
        'closed' => 0
    ];
    
    foreach ($statusCounts as $sc) {
        $statusCountsMap[$sc['status']] = intval($sc['count']);
    }
    
    echo json_encode([
        'success' => true,
        'queries' => $queries,
        'total' => intval($total),
        'status_counts' => $statusCountsMap
    ]);
    
} catch (PDOException $e) {
    error_log("PDO Error in get_contact_queries.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.'
    ]);
    exit();
} catch (Exception $e) {
    error_log("Error in get_contact_queries.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again.'
    ]);
    exit();
}
?>

