<?php
/**
 * Payment reconciliation safety net.
 *
 * A PhonePe order is normally confirmed by whichever fires first:
 *   (1) PhonePe's webhook (api/phonepe_order_callback.php), or
 *   (2) the customer's own browser polling api/phonepe_order_payment.php
 *       while they're on the redirect-back page.
 *
 * If the customer closes their browser or loses connection before
 * returning, and the webhook is delayed or dropped (both are common with
 * payment gateways), the order can sit at payment_status='Pending' forever
 * even though PhonePe actually captured the money. This script re-checks
 * those stuck orders directly against PhonePe's status API and finalizes
 * them, the same way the webhook/poll paths do.
 *
 * Run this on a schedule (every 5-10 minutes is plenty):
 *   - CLI / cron:
 *       php main/tools/reconcile_pending_payments.php
 *   - HTTP cron (e.g. shared hosting "cron via URL", no shell access):
 *       https://yoursite.com/main/tools/reconcile_pending_payments.php?secret=YOUR_RECONCILE_SECRET
 *     Set RECONCILE_SECRET in your .env first — without a matching secret,
 *     HTTP requests are refused, since this endpoint can change payment state.
 */

require_once __DIR__ . '/../config/env_loader.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header('Content-Type: application/json; charset=UTF-8');
    $secret = env('RECONCILE_SECRET', '');
    $provided = $_GET['secret'] ?? '';
    if (empty($secret) || !hash_equals($secret, (string)$provided)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit();
    }
}

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/order_state_machine.php';
require_once __DIR__ . '/../config/phonepe_verify.php';
require_once __DIR__ . '/../config/order_confirmation.php';

function reconcileLog($msg) {
    error_log('[reconcile_pending_payments] ' . $msg);
}

$conn = getConnection();

// Only orders old enough that the live flows (webhook / customer poll) have
// had a fair chance to confirm them already (avoids racing a payment that's
// still genuinely in progress), and not so old that PhonePe's own checkout
// session has long expired and there's nothing meaningful left to check.
$stmt = $conn->prepare(
    "SELECT p.id AS payment_id, p.transaction_id, p.order_id, o.restaurant_id, o.order_number
     FROM payments p
     JOIN orders o ON o.id = p.order_id
     WHERE p.payment_method = 'Online'
       AND p.payment_status = 'Pending'
       AND o.payment_method IN ('PhonePe', 'UPI / NetBanking')
       AND o.payment_status = 'Pending'
       AND p.created_at <= DATE_SUB(NOW(), INTERVAL 3 MINUTE)
       AND p.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
     ORDER BY p.created_at ASC
     LIMIT 200"
);
$stmt->execute();
$pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = ['checked' => 0, 'confirmed_paid' => 0, 'marked_failed' => 0, 'still_pending' => 0, 'errors' => 0];

foreach ($pending as $row) {
    $results['checked']++;
    try {
        $state = phonepeVerifyOrderState($conn, $row['restaurant_id'], $row['transaction_id']);
        if ($state === null) {
            // Could not verify right now (PhonePe unreachable, creds issue) — retry next run
            $results['still_pending']++;
            continue;
        }

        if (in_array($state, ['COMPLETED', 'SUCCESS', 'PAYMENT_SUCCESS'], true)) {
            $justConfirmedOrderId = null;
            $conn->beginTransaction();
            try {
                $updatePay = $conn->prepare("UPDATE payments SET payment_status = 'Success' WHERE id = ?");
                $updatePay->execute([$row['payment_id']]);
                $result = validateAndUpdatePaymentStatus($conn, (int)$row['order_id'], 'Paid');
                if ($result['success'] && !empty($result['transitioned'])) {
                    $justConfirmedOrderId = (int)$row['order_id'];
                }
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            if ($justConfirmedOrderId !== null) {
                fireOrderConfirmedActions($conn, $justConfirmedOrderId);
                reconcileLog('Order ' . $row['order_number'] . ' (txn ' . $row['transaction_id'] . ') reconciled as PAID — webhook/browser poll never confirmed it.');
            }
            $results['confirmed_paid']++;
        } elseif (in_array($state, ['FAILED', 'REJECTED', 'CANCELLED', 'EXPIRED'], true)) {
            $updatePay = $conn->prepare("UPDATE payments SET payment_status = 'Failed' WHERE id = ?");
            $updatePay->execute([$row['payment_id']]);
            reconcileLog('Order ' . $row['order_number'] . ' (txn ' . $row['transaction_id'] . ') reconciled as FAILED (state=' . $state . ')');
            $results['marked_failed']++;
        } else {
            $results['still_pending']++;
        }
    } catch (Exception $e) {
        $results['errors']++;
        reconcileLog('Error reconciling order ' . ($row['order_number'] ?? $row['order_id']) . ': ' . $e->getMessage());
    }
}

reconcileLog('Run complete: ' . json_encode($results));

if ($isCli) {
    fwrite(STDOUT, json_encode($results, JSON_PRETTY_PRINT) . PHP_EOL);
} else {
    echo json_encode(array_merge(['success' => true], $results));
}
