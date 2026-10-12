<?php
/**
 * Cron for Growth › WhatsApp: queues new gift codes, starts due campaigns and
 * sends what is waiting, for every restaurant with WhatsApp on. The dashboard
 * already does this every minute while it is open; run this every 5 minutes so
 * messages also go out when nobody is logged in.
 *
 * CLI:  php /path/to/main/cron/whatsapp_dispatch.php
 * URL:  https://restrogrow.com/main/cron/whatsapp_dispatch.php?key=YOUR_SECRET
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
require_once __DIR__ . '/../config/whatsapp_engine.php';

header('Content-Type: text/plain');

try {
    $conn = getConnection();
    ensureWhatsappSchema($conn);
    $rids = $conn->query('SELECT restaurant_id FROM wa_settings WHERE enabled = 1')->fetchAll(PDO::FETCH_COLUMN);
    $sent = 0; $failed = 0;
    foreach ($rids as $rid) {
        try {
            try { loyaltyRunSweep($conn, $rid); } catch (Throwable $e) { error_log("whatsapp cron sweep $rid: " . $e->getMessage()); }
            $r = waDispatch($conn, $rid, 50);
            $sent += $r['sent']; $failed += $r['failed'];
        } catch (Throwable $e) { error_log("whatsapp cron $rid: " . $e->getMessage()); }
    }
    // Phone wallet cards: push balance changes for every restaurant with the card on
    require_once __DIR__ . '/../config/wallet_card.php';
    require_once __DIR__ . '/../config/vip_membership.php';
    ensureWalletCardSchema($conn);
    $cards = 0;
    foreach ($conn->query('SELECT restaurant_id FROM growth_settings WHERE card_enabled = 1')->fetchAll(PDO::FETCH_COLUMN) as $crid) {
        try { $cards += walletCardsSync($conn, $crid, 100); } catch (Throwable $e) { error_log("wallet cards cron $crid: " . $e->getMessage()); }
    }
    echo "Wallet cards updated: {$cards}
";
    echo 'Checked ' . count($rids) . " restaurant(s): {$sent} sent, {$failed} failed" . (waDryRun() ? ' (test run, nothing sent)' : '') . ' at ' . date('Y-m-d H:i:s') . "\n";
} catch (Exception $e) {
    error_log('whatsapp_dispatch.php: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error: ' . $e->getMessage() . "\n";
}
