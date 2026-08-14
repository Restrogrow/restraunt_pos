<?php
// Update Query Status API
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

// contact_queries is superadmin's own platform-wide sales-lead inbox, not
// per-restaurant data — see get_contact_queries.php for the same fix.
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
    
    $queryId = intval($_POST['query_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    
    if ($queryId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid query ID']);
        exit();
    }
    
    $validStatuses = ['new', 'read', 'replied', 'closed'];
    if (!in_array($status, $validStatuses)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit();
    }
    
    $stmt = $conn->prepare("UPDATE contact_queries SET status = ? WHERE id = ?");
    $stmt->execute([$status, $queryId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Status updated successfully'
    ]);
    
} catch (PDOException $e) {
    error_log("PDO Error in update_query_status.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.'
    ]);
    exit();
} catch (Exception $e) {
    error_log("Error in update_query_status.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again.'
    ]);
    exit();
}
?>

