<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json');

// Require permission to manage orders
requirePermission(PERMISSION_MANAGE_ORDERS);

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
}

// Load Order State Machine
require_once __DIR__ . '/../config/order_state_machine.php';

try {
    $conn = getConnection();
    $restaurant_id = $_SESSION['restaurant_id'] ?? null;
    
    // If restaurant_id not in session, get from staff
    if (!$restaurant_id && isset($_SESSION['staff_id'])) {
        $staff_sql = "SELECT restaurant_id FROM staff WHERE id = ?";
        $staff_stmt = $conn->prepare($staff_sql);
        $staff_stmt->execute([$_SESSION['staff_id']]);
        $staff = $staff_stmt->fetch();
        if ($staff) {
            $restaurant_id = $staff['restaurant_id'];
        }
    }
    
    if (!$restaurant_id) {
        echo json_encode(['success' => false, 'message' => 'Restaurant ID not found']);
        exit();
    }
    
    // Get order ID and new status
    $orderId = (int)($_POST['orderId'] ?? 0);
    $status = $_POST['status'] ?? null;
    $reason = $_POST['reason'] ?? '';
    
    if (!$orderId || !$status) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit();
    }
    
    // Validate status against DB ENUM values
    $validStatuses = ['Pending', 'Accepted', 'Preparing', 'Ready', 'Served', 'Completed', 'Cancelled', 'Rejected'];
    if (!in_array($status, $validStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status: ' . $status . '. Allowed: ' . implode(', ', $validStatuses)]);
        exit();
    }
    
    // Begin transaction and use atomic state-machine update with row locking
    $conn->beginTransaction();
    
    try {
        // Build cancellation note if reason provided
        $appendNotes = null;
        if ($status === 'Cancelled' && $reason) {
            $appendNotes = '[Cancelled: ' . $reason . ']';
        }
        
        // Perform validated atomic update with row-level locking
        // The $appendNotes parameter handles notes appending inside the lock
        $result = validateAndUpdateOrderStatus(
            $conn, 
            $orderId, 
            $status, 
            [],       // extraSet 
            [],       // extraParams
            $restaurant_id,
            $appendNotes
        );
        
        if (!$result['success']) {
            $conn->rollBack();
            echo json_encode([
                'success' => false,
                'message' => $result['message']
            ]);
            exit();
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => $result['message']
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating order status: ' . $e->getMessage()
    ]);
}
?>
