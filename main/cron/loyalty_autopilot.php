<?php
/**
 * Cron backstop for Loyalty Studio > Autopilot: birthday, anniversary and
 * win-back gifts. The rewards page and the Studio already run the checks
 * (at most once an hour per restaurant), so this only matters for restaurants
 * whose pages nobody opens on a given day. Run it once or twice a day.
 *
 * CLI:  php /path/to/main/cron/loyalty_autopilot.php
 * URL:  https://restrogrow.com/main/cron/loyalty_autopilot.php?key=YOUR_SECRET
 *
 * Safe to run as often as you like: each gift has a unique key per customer
 * and occasion (birthday 2026, quiet since 2026-09-01...), so nothing is
 * issued twice.
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    require_once __DIR__ . '/../config/env_loader.php';
    $cronKey = env('CRON_SECRET_KEY') ?: null;
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
require_once __DIR__ . '/../config/loyalty_autopilot.php';

header('Content-Type: text/plain');

try {
    $conn = getConnection();
    ensureLoyaltyAutopilotSchema($conn);
    $rids = $conn->query("SELECT DISTINCT a.restaurant_id FROM loyalty_automations a JOIN growth_settings g ON g.restaurant_id = a.restaurant_id
                          WHERE a.enabled = 1 AND a.type IN ('birthday', 'anniversary', 'winback') AND g.loyalty_enabled = 1")->fetchAll(PDO::FETCH_COLUMN);
    $total = 0;
    foreach ($rids as $rid) {
        try { $total += loyaltyRunSweep($conn, $rid); } catch (Throwable $e) { error_log("loyalty_autopilot cron $rid: " . $e->getMessage()); }
    }
    echo 'Checked ' . count($rids) . " restaurant(s), issued {$total} gift(s) at " . date('Y-m-d H:i:s') . "\n";
} catch (Exception $e) {
    error_log('loyalty_autopilot.php: ' . $e->getMessage());
    http_response_code(500);
    echo "Error: " . $e->getMessage() . "\n";
}
