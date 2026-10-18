<?php
/**
 * Every 5 minutes (hPanel › Cron Jobs):  /usr/bin/php <HOME>/main/cron/payments_reconcile.php
 * Confirms online payments PhonePe finished but nobody came back for, fails the ones that expired, and cancels
 * website orders whose payment never completed (giving back coupon, points, wallet money and stock).
 * Without this cron the same check still runs from the dashboard heartbeat while a dashboard is open.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/payment_reconcile.php';
$r = runPaymentReconcile(getConnection(), true, 50);
echo date('c') . ' ' . json_encode($r) . "\n";
