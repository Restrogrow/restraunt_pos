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
    $swept = mailSweep($conn, in_array('--force', $argv ?? [], true));
    [$sent, $failed] = mailDispatch($conn, 100);
    echo date('c') . " emails sent: $sent, failed: $failed, made by the sweep: " . json_encode($swept) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' email dispatch failed: ' . $e->getMessage() . "\n");
    exit(1);
}
