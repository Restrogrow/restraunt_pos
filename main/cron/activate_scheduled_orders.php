<?php
/**
 * Cron backstop for "schedule for later" website orders. get_orders.php
 * already activates due scheduled orders on every admin panel poll (see
 * scheduled_order_helpers.php), so this only matters when nobody has an
 * admin/kitchen screen open at the moment an order's scheduled_at arrives.
 * Meant to be run every few minutes by a real server cron - Hostinger's
 * cPanel supports scheduling either a CLI command or a URL hit.
 *
 * CLI:  php /path/to/main/cron/activate_scheduled_orders.php
 * URL:  https://yourdomain.com/main/cron/activate_scheduled_orders.php?key=YOUR_SECRET
 *
 * Safe to run as often as you like — activateDueScheduledOrders() only acts
 * on orders whose scheduled_at has actually arrived, and each one is moved
 * out of 'Scheduled' the first time it runs, so there's nothing to
 * double-process on a later run.
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    $cronKey = getenv('CRON_SECRET_KEY') ?: null;
    if (!$cronKey || !isset($_GET['key']) || !hash_equals($cronKey, (string)$_GET['key'])) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo "Forbidden. Set CRON_SECRET_KEY in .env and pass it as ?key=...\n";
        exit();
    }
}

require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true);
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/scheduled_order_helpers.php';

header('Content-Type: text/plain');

try {
    $conn = getConnection();
    $activated = activateDueScheduledOrders($conn);
    echo "Activated {$activated} scheduled order(s) at " . date('Y-m-d H:i:s') . "\n";
} catch (Exception $e) {
    error_log('activate_scheduled_orders.php: ' . $e->getMessage());
    http_response_code(500);
    echo "Error: " . $e->getMessage() . "\n";
}
