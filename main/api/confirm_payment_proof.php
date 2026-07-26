<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json; charset=UTF-8');

requirePermission(PERMISSION_MANAGE_ORDERS);

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/order_state_machine.php';

$restaurant_id = $_SESSION['restaurant_id'] ?? null;
if (!$restaurant_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.'], JSON_UNESCAPED_UNICODE);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$orderId = (int)($input['order_id'] ?? 0);
$action = $input['action'] ?? '';

if (!$orderId || !in_array($action, ['confirm', 'reject'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request'], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $conn = function_exists('getConnection') ? getConnection() : $pdo;

    $stmt = $conn->prepare("SELECT id, status FROM payment_proofs WHERE order_id = ? AND restaurant_id = ? LIMIT 1");
    $stmt->execute([$orderId, $restaurant_id]);
    $proof = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$proof) {
        echo json_encode(['success' => false, 'message' => 'Payment proof not found for this order'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    if ($proof['status'] !== 'Pending') {
        echo json_encode(['success' => false, 'message' => "This payment proof was already {$proof['status']}"], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $conn->beginTransaction();

    $newProofStatus = $action === 'confirm' ? 'Confirmed' : 'Rejected';
    $reviewer = $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? null;
    $upd = $conn->prepare("UPDATE payment_proofs SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
    $upd->execute([$newProofStatus, $reviewer !== null ? (string)$reviewer : null, $proof['id']]);

    if ($action === 'confirm') {
        // Only a confirmed screenshot ever flips the order to Paid — this is
        // what keeps it out of sales/revenue figures until the restaurant
        // has actually verified the money landed.
        $result = validateAndUpdatePaymentStatus($conn, $orderId, 'Paid', $restaurant_id);
        if (!$result['success']) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => $result['message']], JSON_UNESCAPED_UNICODE);
            exit();
        }
        // Keep the payments log (used by the dashboard revenue widget) in sync.
        $conn->prepare("UPDATE payments SET payment_status = 'Success' WHERE order_id = ? AND payment_status = 'Pending'")
             ->execute([$orderId]);
    }

    $conn->commit();
    echo json_encode([
        'success' => true,
        'message' => $action === 'confirm' ? 'Payment confirmed and marked as Paid.' : 'Payment proof rejected. Order remains unpaid.'
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('confirm_payment_proof error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error'], JSON_UNESCAPED_UNICODE);
}
