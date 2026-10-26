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
    session_write_close(); // only reads $_SESSION from here on; lets the dashboard's parallel requests run
    
    if (!$restaurant_id) {
        echo json_encode(['success' => false, 'message' => 'Restaurant ID not found']);
        exit();
    }
    
    // Get order ID and new status
    $orderId = (int)($_POST['orderId'] ?? 0);
    $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : null;
    $reason = trim(mb_substr((string)($_POST['reason'] ?? ''), 0, 200));
    // Why an order was rejected / cancelled (assets/js/new-order-popup.js). The code decides what the
    // customer is told on the tracking page and whether it counts as a strike against their number:
    // only "couldn't reach the customer", or a custom reason the owner marks as fake, does.
    $reasonCode = preg_replace('/[^a-z_]/', '', (string)($_POST['reason_code'] ?? ''));
    $customerFault = in_array($reasonCode, ['no_answer'], true) || !empty($_POST['strike']);
    // Optional: set when accepting a delivery order priced via the KM-based
    // delivery system — the admin confirms/edits the auto-calculated charge
    // at accept time, since it's only an estimate until then.
    $deliveryCharge = (isset($_POST['delivery_charge']) && $_POST['delivery_charge'] !== '') ? (float)$_POST['delivery_charge'] : null;
    // Optional: set when accepting an order — how many minutes the kitchen
    // expects it to take, used to compute estimated_ready_at for the
    // customer-facing order tracking page.
    $prepMinutes = (isset($_POST['prep_minutes']) && $_POST['prep_minutes'] !== '') ? (int)$_POST['prep_minutes'] : null;
    // Optional (delivery orders): minutes from ready to the customer's door; with prep_minutes it gives
    // estimated_delivery_at, which the tracking page shows as "Arrives around …".
    $deliveryMinutes = (isset($_POST['delivery_minutes']) && $_POST['delivery_minutes'] !== '') ? max(0, min(300, (int)$_POST['delivery_minutes'])) : null;
    if ($prepMinutes !== null) $prepMinutes = max(0, min(300, $prepMinutes));

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
    
    // delivery time columns are added on first use, outside the transaction (ALTER TABLE would commit it)
    $hasDeliveryCols = false;
    if ($status === 'Accepted' && $deliveryMinutes) {
        try {
            if (!$conn->query("SHOW COLUMNS FROM orders LIKE 'delivery_minutes'")->fetch()) $conn->exec("ALTER TABLE orders ADD COLUMN delivery_minutes INT NULL DEFAULT NULL AFTER estimated_ready_at");
            if (!$conn->query("SHOW COLUMNS FROM orders LIKE 'estimated_delivery_at'")->fetch()) $conn->exec("ALTER TABLE orders ADD COLUMN estimated_delivery_at DATETIME NULL DEFAULT NULL AFTER delivery_minutes");
            $hasDeliveryCols = true;
        } catch (Throwable $e) {
            error_log('[orders] delivery time columns: ' . $e->getMessage());
        }
    }

    // Begin transaction and use atomic state-machine update with row locking
    $conn->beginTransaction();
    
    try {
        // Build cancellation note if reason provided
        $appendNotes = null;
        if (($status === 'Cancelled' || $status === 'Rejected') && $reason !== '') {
            // e.g. "[Rejected (out_of_stock): Items out of stock]"; the code lets the tracking page explain it
            $appendNotes = '[' . $status . ($reasonCode !== '' ? ' (' . $reasonCode . ')' : '') . ': ' . str_replace(['[', ']'], ['(', ')'], $reason) . ']';
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
        if ($status === 'Accepted' && $deliveryMinutes !== null && $deliveryMinutes > 0) {
            if ($hasDeliveryCols) {
                $extraSet[] = 'delivery_minutes = ?';
                $extraParams[] = $deliveryMinutes;
                $extraSet[] = 'estimated_delivery_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)';
                $extraParams[] = (int)$prepMinutes + $deliveryMinutes;
            }
        }

        // A soft-deleted order is a closed book — nobody (not even an
        // accidental waiter tap) may advance its status afterwards.
        // validateAndUpdateOrderStatus would happily update one, so guard
        // here, tenant-scoped, before the state machine runs.
        $deletedStmt = $conn->prepare("SELECT deleted_at, source, order_type FROM orders WHERE id = ? AND restaurant_id = ? FOR UPDATE");
        $deletedStmt->execute([$orderId, $restaurant_id]);
        $deletedCheck = $deletedStmt->fetch(PDO::FETCH_ASSOC);
        if (!$deletedCheck) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit();
        }
        if (isDeliverySession()) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => 'Use Picked up / Delivered on your delivery instead.']);
            exit();
        }
        if (isWaiterSession() && isOnlineOrderRow($deletedCheck)) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => 'Online orders are handled by the owner or a manager.']);
            exit();
        }
        if (!empty($deletedCheck['deleted_at'])) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => 'This order is deleted and can no longer be updated']);
            exit();
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

        // owner's activity log: cancellations and rejections, with the reason
        if ($status === 'Cancelled' || $status === 'Rejected') {
            require_once __DIR__ . '/../config/activity_log.php';
            $on = $conn->prepare('SELECT order_number, total FROM orders WHERE id = ?'); $on->execute([$orderId]); $onRow = $on->fetch(PDO::FETCH_ASSOC) ?: [];
            logActivity($conn, $restaurant_id, $status === 'Cancelled' ? 'order.cancel' : 'order.reject',
                ($status === 'Cancelled' ? 'Cancelled' : 'Rejected') . ' order ' . ($onRow['order_number'] ?? '#' . $orderId) . ($reason !== '' ? ': ' . $reason : ''),
                array_filter(['reason' => $reason, 'total' => $onRow['total'] ?? null]), 'order', $orderId);
        }

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
        // With a reason code (new popup) only a customer-side reason counts; without one (older
        // screens, other apps) every unpaid reject / cancel counts, as before.
        if (($status === 'Rejected' || $status === 'Cancelled') && $result['success'] && ($reasonCode === '' || $customerFault)) {
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
            } catch (Throwable $e) {
                error_log('update_order_status.php: abuse strike failed (non-fatal): ' . $e->getMessage());
            }
        }
        // Shiprocket Quick: book the rider when the restaurant books by itself, or cancel it with the order
        if ($result['success']) {
            require_once __DIR__ . '/../config/quick_delivery.php';
            qdAfterStatus($conn, (string)$restaurant_id, (int)$orderId, $status, (string)($_SESSION['username'] ?? ''));
        }
        if ($status === 'Ready') {
            try {
                require_once __DIR__ . '/../config/push_notification.php';
                notifyWaitersOrderReady($conn, $restaurant_id, $orderId);
            } catch (Throwable $e) {
                error_log('update_order_status.php: waiter notification failed (non-fatal): ' . $e->getMessage());
            }
        }

        echo json_encode([
            'success' => true,
            'message' => $result['message']
        ]);
        
    } catch (Throwable $e) {
        // Guarded rollback: only roll back if the transaction is actually
        // still active — after a successful commit (or an engine-level
        // implicit rollback) there is none, and an unguarded rollBack()
        // would throw its own PDOException masking the real error.
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
    
} catch (Throwable $e) {
    error_log('update_order_status.php: ' . $e->getMessage() . ' | orderId=' . ($orderId ?? '?') . ' status=' . ($status ?? '?'));
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating order status. Please try again.'
    ]);
}
?>
