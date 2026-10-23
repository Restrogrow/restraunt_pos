<?php
// Manual payment-status update (e.g. marking a cash order "Paid" once the
// customer hands over cash) — mirrors update_order_status.php's structure,
// but drives orders.payment_status via the state machine's payment-status
// transition guard instead of order_status.
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json');
require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin();

requirePermission(PERMISSION_MANAGE_ORDERS);

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
}

require_once __DIR__ . '/../config/order_state_machine.php';

try {
    $conn = getConnection();
    $restaurant_id = $_SESSION['restaurant_id'] ?? null;

    if (!$restaurant_id && isset($_SESSION['staff_id'])) {
        $staff_stmt = $conn->prepare("SELECT restaurant_id FROM staff WHERE id = ?");
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

    $orderId = (int)($_POST['orderId'] ?? 0);
    $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : null;
    // optional: how it was paid (Update payment window); anything else is ignored
    $method = trim((string)($_POST['payment_method'] ?? ''));
    $methods = ['Cash', 'UPI', 'Card', 'Online', 'Wallet', 'Other'];
    if ($method !== '' && !in_array($method, $methods, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment method']);
        exit();
    }

    if (!$orderId || !$status) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit();
    }

    // Matches orders.payment_status's actual enum — 'Failed' belongs to the
    // separate payments-table/gateway-callback state machine, not this column.
    $validStatuses = ['Pending', 'Paid', 'Partially Paid', 'Refunded'];
    if (!in_array($status, $validStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status: ' . $status . '. Allowed: ' . implode(', ', $validStatuses)]);
        exit();
    }

    // giving money back is for the owner and managers only (a waiter can mark cash as received)
    if ($status === 'Refunded' && !in_array(getUserRole(), [ROLE_ADMIN, ROLE_MANAGER], true)) {
        echo json_encode(['success' => false, 'message' => 'Only the owner or a manager can mark a payment as refunded.']);
        exit();
    }

    $conn->beginTransaction();

    try {
        $result = validateAndUpdatePaymentStatus($conn, $orderId, $status, $restaurant_id);

        if (!$result['success']) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => $result['message']]);
            exit();
        }

        if ($method !== '') {
            $conn->prepare('UPDATE orders SET payment_method = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND restaurant_id = ?')->execute([$method, $orderId, $restaurant_id]);
        }

        // The Payments page counts the payments table, so the money moves there too
        if (!empty($result['transitioned']) && ($status === 'Paid' || $status === 'Refunded')) {
            $ord = $conn->prepare('SELECT total, payment_method, order_type, table_id FROM orders WHERE id = ? AND restaurant_id = ?');
            $ord->execute([$orderId, $restaurant_id]);
            $ordRow = $ord->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($status === 'Paid') {
                // a waiting gateway / cash-on-delivery row is now collected, and whatever is still
                // missing (open bill, rest of a part payment) is recorded with the chosen method
                $conn->prepare("UPDATE payments SET payment_status = 'Success' WHERE order_id = ? AND restaurant_id = ? AND payment_status = 'Pending'")->execute([$orderId, $restaurant_id]);
                $paid = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ? AND restaurant_id = ? AND payment_status = 'Success'");
                $paid->execute([$orderId, $restaurant_id]);
                $rest = round((float)($ordRow['total'] ?? 0) - (float)$paid->fetchColumn(), 2);
                if ($rest > 0) {
                    $payMethod = $method !== '' ? $method : (string)($ordRow['payment_method'] ?? '');
                    if ($payMethod === '' || $payMethod === 'Due') $payMethod = 'Cash';
                    $conn->prepare("INSERT INTO payments (restaurant_id, order_id, amount, payment_method, payment_status) VALUES (?, ?, ?, ?, 'Success')")->execute([$restaurant_id, $orderId, $rest, $payMethod]);
                }
                // a dine-in bill settled: its kitchen tickets are done and the table frees up
                if (($ordRow['order_type'] ?? '') === 'Dine-in' && !empty($ordRow['table_id'])) {
                    require_once __DIR__ . '/../config/table_busy.php';
                    rgCloseOrderKots($conn, (string)$restaurant_id, $orderId);
                }
            } else {
                $conn->prepare("UPDATE payments SET payment_status = 'Refunded' WHERE order_id = ? AND restaurant_id = ? AND payment_status = 'Success'")->execute([$orderId, $restaurant_id]);
            }
        }

        $conn->commit();

        require_once __DIR__ . '/../config/activity_log.php';
        $on = $conn->prepare('SELECT order_number, total FROM orders WHERE id = ?'); $on->execute([$orderId]); $onRow = $on->fetch(PDO::FETCH_ASSOC) ?: [];
        logActivity($conn, $restaurant_id, $status === 'Refunded' ? 'payment.refund' : 'payment.update',
            'Payment of order ' . ($onRow['order_number'] ?? '#' . $orderId) . ' set to ' . $status . ($method !== '' ? ' (' . $method . ')' : ''),
            array_filter(['status' => $status, 'method' => $method, 'total' => $onRow['total'] ?? null]), 'order', $orderId);

        echo json_encode([
            'success' => true,
            'message' => $result['message'],
        ]);
    } catch (Throwable $e) {
        // Guarded rollback — after a successful commit (or an engine-level
        // implicit rollback) there is no active transaction left, and an
        // unguarded rollBack() would throw its own PDOException that masks
        // the real error.
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
} catch (Throwable $e) {
    error_log('update_payment_status.php: ' . $e->getMessage() . ' | orderId=' . ($orderId ?? '?') . ' status=' . ($status ?? '?'));
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating payment status. Please try again.',
    ]);
}
