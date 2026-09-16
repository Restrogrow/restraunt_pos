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

    if (!$restaurant_id) {
        echo json_encode(['success' => false, 'message' => 'Restaurant ID not found']);
        exit();
    }

    $orderId = (int)($_POST['orderId'] ?? 0);
    $status = $_POST['status'] ?? null;

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

    $conn->beginTransaction();

    try {
        $result = validateAndUpdatePaymentStatus($conn, $orderId, $status, $restaurant_id);

        if (!$result['success']) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => $result['message']]);
            exit();
        }

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => $result['message'],
        ]);
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
} catch (Exception $e) {
    error_log('update_payment_status.php: ' . $e->getMessage() . ' | orderId=' . ($orderId ?? '?') . ' status=' . ($status ?? '?'));
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating payment status: ' . $e->getMessage(),
    ]);
}
