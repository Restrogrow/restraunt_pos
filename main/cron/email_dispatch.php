<?php
/**
 * Emails (config/mail_engine.php): sends anything still waiting in the outbox (failed tries are
 * retried here) and, once an hour, the date-based emails: trial ending, renewal reminders,
 * payment receipts, loyalty gift emails and today's booking reminders.
 *
 * Most emails go out by themselves seconds after they are made, and an open dashboard also runs
 * this every few minutes (api/wa_dispatch.php), so this cron is the safety net.
 *
 * Hostinger hPanel › Advanced › Cron Jobs, every 10 minutes:
 *   /usr/bin/php /home/<user>/public_html/main/cron/email_dispatch.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/mail_engine.php';

try {
    $conn = getConnection();
    // so Super Admin › Email › Settings & jobs can show the job is running
    try { $conn->exec("CREATE TABLE IF NOT EXISTS sa_settings (k VARCHAR(60) PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); $conn->prepare("REPLACE INTO sa_settings (k, v) VALUES ('cron_email_dispatch_at', ?)")->execute([date('Y-m-d H:i:s')]); } catch (Throwable $e) { /* not important */ }
    // online payments nobody came back for (also its own cron: cron/payments_reconcile.php)
    try { require_once __DIR__ . '/../config/payment_reconcile.php'; runPaymentReconcile($conn, false, 30); } catch (Throwable $e) { error_log('email_dispatch payments: ' . $e->getMessage()); }
    $swept = mailSweep($conn, in_array('--force', $argv ?? [], true));
    [$sent, $failed] = mailDispatch($conn, 100);
    echo date('c') . " emails sent: $sent, failed: $failed, made by the sweep: " . json_encode($swept) . "\n";
    // the website's location database (config/geo_ip.php): a fresh copy once a month, after the emails
    try { require_once __DIR__ . '/../config/geo_ip.php'; if ($g = geoUpdateIfOld()) echo date('c') . ' location database: ' . $g[1] . "\n"; } catch (Throwable $e) { error_log('email_dispatch geo: ' . $e->getMessage()); }
    // visitors' IP addresses are kept 12 months (privacy policy); city / country stay for the reports
    try {
        $conn->exec('UPDATE site_visits SET ip = NULL WHERE ip IS NOT NULL AND day < CURDATE() - INTERVAL 365 DAY LIMIT 5000');
        $conn->exec('UPDATE site_visitors SET ip = NULL WHERE ip IS NOT NULL AND last_seen < NOW() - INTERVAL 365 DAY LIMIT 5000');
        $conn->exec('UPDATE lead_attribution SET ip = NULL WHERE ip IS NOT NULL AND created_at < NOW() - INTERVAL 365 DAY LIMIT 5000');
        $conn->exec('DELETE FROM ip_geo WHERE looked_at < NOW() - INTERVAL 365 DAY LIMIT 5000');
    } catch (Throwable $e) { /* tables made on the first website visit */ }
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' email dispatch failed: ' . $e->getMessage() . "\n");
    exit(1);
}
