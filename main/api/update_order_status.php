<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json');
require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin();

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
    // Optional: set when accepting a delivery order priced via the KM-based
    // delivery system — the admin confirms/edits the auto-calculated charge
    // at accept time, since it's only an estimate until then.
    $deliveryCharge = (isset($_POST['delivery_charge']) && $_POST['delivery_charge'] !== '') ? (float)$_POST['delivery_charge'] : null;
    // Optional: set when accepting an order — how many minutes the kitchen
    // expects it to take, used to compute estimated_ready_at for the
    // customer-facing order tracking page.
    $prepMinutes = (isset($_POST['prep_minutes']) && $_POST['prep_minutes'] !== '') ? (int)$_POST['prep_minutes'] : null;

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

        // When accepting with a delivery charge, update delivery_charge and
        // fold the difference into total in the same atomic UPDATE — the SET
        // clauses run left-to-right in MySQL, so total's clause still sees
        // the OLD delivery_charge value here, before it gets overwritten by
        // the clause after it.
        $extraSet = [];
        $extraParams = [];
        if ($status === 'Accepted' && $deliveryCharge !== null && $deliveryCharge >= 0) {
            $extraSet = ['total = total - delivery_charge + ?', 'delivery_charge = ?'];
            $extraParams = [$deliveryCharge, $deliveryCharge];
        }

        if ($status === 'Accepted' && $prepMinutes !== null && $prepMinutes > 0) {
            $extraSet[] = 'prep_minutes = ?';
            $extraParams[] = $prepMinutes;
            $extraSet[] = 'estimated_ready_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)';
            $extraParams[] = $prepMinutes;
        }

        // Perform validated atomic update with row-level locking
        // The $appendNotes parameter handles notes appending inside the lock
        $result = validateAndUpdateOrderStatus(
            $conn,
            $orderId,
            $status,
            $extraSet,
            $extraParams,
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

        // Post-commit side effects live OUTSIDE the transaction try/catch —
        // a push-notification failure here must never turn a committed
        // status change into an error response. (Previously the catch below
        // tried to rollBack() an already-committed transaction, which threw
        // its own "There is no active transaction" PDOException — the app
        // showed "Error updating order status" even though the update had
        // actually succeeded.)

        // ── Fake-order auto-block ──
        // When a website order is Rejected or Cancelled while unpaid, record
        // a strike against that customer phone. 3 strikes within the window
        // tracked on the blocklist row auto-blocks the number. Deliberately
        // only for source='website' orders (POS/staff-created orders have a
        // known, logged-in author) and only while payment is still Pending —
        // a paid order being cancelled is a refund situation, not abuse.
        if (($status === 'Rejected' || $status === 'Cancelled') && $result['success']) {
            try {
                $srcStmt = $conn->prepare("SELECT source, payment_status, customer_phone, customer_name FROM orders WHERE id = ? AND restaurant_id = ?");
                $srcStmt->execute([$orderId, $restaurant_id]);
                $orderMeta = $srcStmt->fetch(PDO::FETCH_ASSOC);
                if ($orderMeta && ($orderMeta['source'] ?? '') === 'website'
                    && ($orderMeta['payment_status'] ?? '') === 'Pending'
                    && !empty($orderMeta['customer_phone'])) {
                    require_once __DIR__ . '/../config/order_abuse_guard.php';
                    orderAbuseAddStrike(
                        $conn,
                        $restaurant_id,
                        $orderMeta['customer_phone'],
                        $status . ($reason !== '' ? ': ' . $reason : ''),
                        $orderMeta['customer_name'] ?? ''
                    );
                }
            } catch (Exception $e) {
                error_log('update_order_status.php: abuse strike failed (non-fatal): ' . $e->getMessage());
            }
        }
        if ($status === 'Ready') {
            try {
                require_once __DIR__ . '/../config/push_notification.php';
                notifyWaitersOrderReady($conn, $restaurant_id, $orderId);
            } catch (Exception $e) {
                error_log('update_order_status.php: waiter notification failed (non-fatal): ' . $e->getMessage());
            }
        }

        echo json_encode([
            'success' => true,
            'message' => $result['message']
        ]);
        
    } catch (Exception $e) {
        // Guarded rollback: only roll back if the transaction is actually
        // still active — after a successful commit (or an engine-level        // implicit rollback) there is none, and an unguarded rollBack()        // would throw its own PDOException masking the real error.
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
    
} catch (Exception $e) {
    error_log('update_order_status.php: ' . $e->getMessage() . ' | orderId=' . ($orderId ?? '?') . ' status=' . ($status ?? '?'));
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating order status. Please try again.'
    ]);
}
?>
