<?php
/**
 * Shiprocket Quick (config/quick_delivery.php): books the riders that are due (a restaurant that books
 * by itself, when an order was accepted or got ready on a screen that didn't book it) and follows live
 * rides (picked up, on the way, delivered). Every 2 minutes:
 *   /usr/bin/php <HOME>/main/cron/quick_delivery.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/quick_delivery.php';

try {
    $conn = getConnection();
    $n = qdSweep($conn);
    $conn->prepare("REPLACE INTO sa_settings (k, v) VALUES ('qd_cron_at', ?)")->execute([date('Y-m-d H:i:s')]);
    echo date('Y-m-d H:i:s') . " quick_delivery: booked {$n['booked']}, refreshed {$n['refreshed']}\n";
} catch (Throwable $e) {
    error_log('cron quick_delivery: ' . $e->getMessage());
    echo 'quick_delivery failed: ' . $e->getMessage() . "\n";
    exit(1);
}
