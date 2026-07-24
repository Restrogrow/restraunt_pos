<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json');

// Require permission to manage payments
requirePermission(PERMISSION_MANAGE_PAYMENTS);

require_once '../db_connection.php';

try {
    $conn = getConnection();
    $restaurant_id = $_SESSION['restaurant_id'];
    
    $order_id = $_POST['order_id'] ?? null;
    $amount = $_POST['amount'] ?? 0;
    $payment_method = $_POST['payment_method'] ?? 'Cash';
    $transaction_id = $_POST['transaction_id'] ?? null;
    $payment_status = $_POST['payment_status'] ?? 'Success';
    $reference_number = $_POST['reference_number'] ?? null;
    $notes = $_POST['notes'] ?? null;
    
    if (!$order_id) {
        throw new Exception('Order ID is required');
    }
    
    $sql = "INSERT INTO payments (restaurant_id, order_id, transaction_id, amount, payment_method, payment_status, reference_number, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $restaurant_id,
        $order_id,
        $transaction_id,
        $amount,
        $payment_method,
        $payment_status,
        $reference_number,
        $notes
    ]);
    
    $payment_id = $conn->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Payment recorded successfully',
        'payment_id' => $payment_id
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error saving payment: ' . $e->getMessage()
    ]);
}
?>

