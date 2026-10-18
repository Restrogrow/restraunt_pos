<?php
/**
 * Old address of the payment safety net; the work is now config/payment_reconcile.php
 * (cron/payments_reconcile.php is the cron to use).
 *   CLI:  php main/tools/reconcile_pending_payments.php
 *   HTTP: POST with header X-Reconcile-Secret: <RECONCILE_SECRET>  (never in the URL, it would end up in logs)
 */
require_once __DIR__ . '/../config/env_loader.php';
if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=UTF-8');
    $secret = (string)env('RECONCILE_SECRET', '');
    $given = (string)($_SERVER['HTTP_X_RECONCILE_SECRET'] ?? '');
    if ($secret === '' || !hash_equals($secret, $given)) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Forbidden']); exit; }
}
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/payment_reconcile.php';
$r = runPaymentReconcile(getConnection(), true, 50);
echo json_encode(['success' => true] + $r), "\n";
