<?php
/**
 * PhonePe Debug Endpoint
 * 
 * Usage: 
 *   phonepe_debug.php?order_id=ORD-XXXX
 *   phonepe_debug.php?order_id=ORD-XXXX&confirm=1   (force-confirm a stuck pending payment)
 * 
 * ⚠️  IMPORTANT: DELETE THIS FILE AFTER DEBUGGING!
 * ⚠️  Change the secret key below before using on production!
 */

require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../config/env_loader.php';
startSecureSession(true);

header('Content-Type: text/html; charset=UTF-8');

// ============================================
// ⚠️  SECURITY: CHANGE THIS KEY BEFORE USING!
// ============================================
$secretKey = 'CHANGE_ME_TO_A_RANDOM_STRING';  

$authKey = $_GET['key'] ?? '';
$allowedIPs = ['::1', '127.0.0.1', 'localhost'];

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowedIPs) && $authKey !== $secretKey) {
    die('<h2>🔒 PhonePe Debug Tool</h2><p style="color:#e94560">Access denied.</p><p>Use <code>?key=YOUR_SECRET_KEY</code> or access from localhost.<br><br>If you haven\'t set a secret key yet, edit this file and change <code>$secretKey</code> at the top.</p>');
}

// Double-check: if key is still default, warn but still allow
if ($secretKey === 'CHANGE_ME_TO_A_RANDOM_STRING' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowedIPs)) {
    die('<h2>🔒 Security Error</h2><p style="color:#e94560">You must set a custom secret key in the <code>$secretKey</code> variable at the top of this file before using it on production!</p>');
}

require_once __DIR__ . '/../db_connection.php';

$orderNumber = $_GET['order_id'] ?? '';
$confirm = isset($_GET['confirm']);

echo '<!DOCTYPE html><html><head><title>PhonePe Debug</title>';
echo '<style>body{font-family:monospace;padding:20px;background:#1a1a2e;color:#e0e0e0}';
echo 'h1{color:#e94560}';
echo 'h2{color:#0f3460;background:#16213e;padding:10px;border-radius:5px;margin-top:20px}';
echo 'pre{background:#16213e;padding:15px;border-radius:8px;overflow:auto;font-size:13px}';
echo '.success{color:#4ecca3;font-weight:bold}';
echo '.pending{color:#ffd369;font-weight:bold}';
echo '.error{color:#e94560;font-weight:bold}';
echo 'table{border-collapse:collapse;width:100%}';
echo 'td,th{border:1px solid #333;padding:8px 12px;text-align:left}';
echo 'th{background:#16213e}';
echo 'td{background:#0f3460}';
echo '.btn{display:inline-block;padding:10px 20px;background:#e94560;color:#fff;text-decoration:none;border-radius:5px;margin:10px 0}';
echo '.btn:hover{background:#c73652}';
echo 'input,button{padding:8px 12px;font-size:14px;border-radius:5px;border:none}';
echo 'input{background:#16213e;color:#fff;width:300px}';
echo 'button{background:#4ecca3;color:#1a1a2e;cursor:pointer;font-weight:bold}';
echo '</style></head><body>';

echo '<div style="background:#e94560;color:#fff;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px">⚠️ <strong>SECURITY WARNING:</strong> This is a debug tool. For security reasons, <strong>DELETE this file</strong> from your production server after debugging is complete! Anyone with access to this page can view order and payment data.</div>';
echo '<h1>🔍 PhonePe Payment Debug</h1>';
echo '<form method="get">';
echo '<input type="hidden" name="key" value="' . htmlspecialchars($authKey) . '">';
echo '<input type="text" name="order_id" placeholder="Enter Order Number (e.g. ORD-12345)" value="' . htmlspecialchars($orderNumber) . '" style="margin-right:8px">';
echo '<button type="submit">Check Order</button>';
echo '</form><br>';

if (!$orderNumber) {
    echo '<p>Enter an order number above to check its payment state.</p>';
    echo '</body></html>';
    exit();
}

try {
    $conn = getConnection();
    
    // 1. Get order info
    $stmt = $conn->prepare("SELECT * FROM orders WHERE order_number = ? LIMIT 1");
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo '<h2>📋 Order Info</h2>';
    if ($order) {
        echo '<pre>';
        foreach ($order as $key => $val) {
            echo htmlspecialchars($key) . ': ' . htmlspecialchars($val ?? 'NULL') . "\n";
        }
        echo '</pre>';
    } else {
        echo '<p class="error">❌ Order not found!</p>';
    }
    
    // 2. Get all payments for this order
    echo '<h2>💳 Payment Records</h2>';
    if ($order) {
        $stmt = $conn->prepare("SELECT p.*, o.order_number FROM payments p JOIN orders o ON p.order_id = o.id WHERE o.order_number = ? ORDER BY p.id DESC");
        $stmt->execute([$orderNumber]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($payments) > 0) {
            echo '<table>';
            echo '<tr><th>ID</th><th>TX ID</th><th>Method</th><th>Status</th><th>Amount</th><th>Created</th><th>Updated</th></tr>';
            foreach ($payments as $p) {
            $ps = $p['payment_status'] ?? '';
            $statusClass = ($ps === 'Success' || $ps === 'Paid') ? 'success' : (($ps === 'Failed') ? 'error' : 'pending');
                echo '<tr>';
                echo '<td>' . htmlspecialchars($p['id'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($p['transaction_id'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($p['payment_method'] ?? '') . '</td>';
                echo '<td class="' . $statusClass . '">' . htmlspecialchars($p['payment_status'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($p['amount'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($p['created_at'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($p['updated_at'] ?? '') . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        } else {
            echo '<p class="pending">⚠️ No payment records found for this order</p>';
        }
    }
    
    // 3. PhonePe specific payment check
    echo '<h2>📡 PhonePe Status API Check</h2>';
    if ($order && count($payments ?? []) > 0) {
        $ppPayment = null;
        foreach ($payments as $p) {
            $txnId = $p['transaction_id'] ?? '';
            if (strpos($txnId, 'PP_') === 0) {
                $ppPayment = $p;
                break;
            }
        }
        
        if ($ppPayment && $ppPayment['payment_status'] === 'Pending') {
            echo '<p>Pending PhonePe payment found. <a href="phonepe_order_payment.php?action=status&order_id=' . urlencode($orderNumber) . '&code=PAYMENT_SUCCESS" target="_blank" class="btn">▶️ Test Status API (with code=PAYMENT_SUCCESS)</a></p>';
            echo '<p><a href="phonepe_order_payment.php?action=status&order_id=' . urlencode($orderNumber) . '" target="_blank" class="btn">▶️ Test Status API (without code)</a></p>';
            
            if ($confirm) {
                echo '<h2>🔧 Force Confirm</h2>';
                error_log('[PhonePe Debug] Force confirm triggered for payment #' . $ppPayment['id'] . ' (txn: ' . $ppPayment['transaction_id'] . ') by IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
                try {
                    $conn->beginTransaction();
                    $stmt = $conn->prepare("UPDATE payments SET payment_status = 'Success', updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$ppPayment['id']]);
                    // Use state machine for order payment status
                    require_once __DIR__ . '/../config/order_state_machine.php';
                    $payResult = validateAndUpdatePaymentStatus($conn, (int)($ppPayment['order_id'] ?? 0), 'Paid');
                    if (!$payResult['success']) {
                        $stmt = $conn->prepare("UPDATE orders SET payment_status = 'Paid' WHERE id = ?");
                        $stmt->execute([$ppPayment['order_id']]);
                    }
                    $conn->commit();
                    echo '<p class="success">✅ Payment #' . $ppPayment['id'] . ' (txn: ' . $ppPayment['transaction_id'] . ') has been manually confirmed as Success!</p>';
                } catch (Exception $e) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    echo '<p class="error">❌ Failed to confirm: ' . htmlspecialchars($e->getMessage()) . '</p>';
                }
            } else {
                echo '<p><a href="?order_id=' . urlencode($orderNumber) . '&key=' . urlencode($authKey) . '&confirm=1" class="btn" style="background:#ffd369;color:#1a1a2e" onclick="return confirm(\'Are you sure? This will mark the payment as Success.\')">⚠️ Force Confirm Payment</a></p>';
                echo '<p style="color:#ffd369;font-size:12px">Use this ONLY if the payment actually went through on PhonePe but got stuck.</p>';
            }
        } elseif ($ppPayment) {
            echo '<p class="success">✅ PhonePe payment already in state: ' . $ppPayment['payment_status'] . '</p>';
        } else {
            echo '<p class="pending">⚠️ No PhonePe payment (PP_ prefix) found for this order</p>';
        }
    } else {
        echo '<p>Cannot check PhonePe status - order or payments not found.</p>';
    }
    
    // 4. Recent error logs (last 50 lines)
    echo '<h2>📝 Recent PhonePe Error Logs</h2>';
    $logFile = ini_get('error_log');
    if ($logFile && file_exists($logFile)) {
        $lines = file($logFile);
        $ppLines = array_filter($lines, function($l) { return str_contains($l, 'PP-DEBUG') || str_contains($l, 'PhonePe'); });
        $ppLines = array_slice(array_values($ppLines), -30);
        echo '<pre style="max-height:400px">';
        if (count($ppLines) > 0) {
            foreach ($ppLines as $line) {
                $color = 'inherit';
                if (str_contains($line, 'CONFIRMED')) $color = '#4ecca3';
                elseif (str_contains($line, 'error') || str_contains($line, 'Error')) $color = '#e94560';
                elseif (str_contains($line, 'AUTO-CONFIRM')) $color = '#ffd369';
                echo '<span style="color:' . $color . '">' . htmlspecialchars($line) . '</span>';
            }
        } else {
            echo '<span style="color:#888">No PhonePe-related log entries found.</span>';
        }
        echo '</pre>';
    } else {
        echo '<p class="pending">Error log file not found at: ' . htmlspecialchars($logFile ?? '(not set)') . '</p>';
        echo '<p>Check your PHP error_log setting in php.ini or cPanel.</p>';
    }
    
    // 5. Recommendations
    echo '<h2>💡 Recommendations</h2>';
    echo '<pre>';
    if ($order && count($payments ?? []) > 0) {
        foreach ($payments as $p) {
            if ($p['payment_status'] === 'Pending' && strpos($p['transaction_id'] ?? '', 'PP_') === 0) {
                echo '1. The PhonePe payment is stuck in Pending state.\n';
                echo '2. The front-end now passes code=PAYMENT_SUCCESS to the status API.\n';
                echo '3. Check the PHP error log above to see if the status API is being called.\n';
                echo '4. If the auto-confirm is not triggering, check:\n';
                echo '   - Is the order_id reaching the server? Check GET params in logs.\n';
                echo '   - Is the payment record found? Check the SQL query in logs.\n';
                echo '   - Is the PhonePe API check failing? Check auth token errors.\n';
                echo '   - Is $phonepeRedirectCode === \'PAYMENT_SUCCESS\'? Check the logged GET params.\n';
                echo '5. Use the "Force Confirm" button above if the payment is truly successful.\n';
            }
        }
    }
    echo '</pre>';
    
} catch (Exception $e) {
    echo '<h2 class="error">❌ Error</h2>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
}

echo '</body></html>';
