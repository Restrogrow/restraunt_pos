<?php
/**
 * Cron test (every minute): proves the server's cron can run PHP and reach the database.
 * Super Admin › Email › Settings & jobs shows when it last ran and how it connected.
 * Safe to keep or remove; it changes nothing but two rows in sa_settings.
 *
 * Hostinger hPanel › Advanced › Cron Jobs, every minute:
 *   /usr/bin/php /home/<user>/public_html/main/cron/heartbeat.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require_once __DIR__ . '/../db_connection.php';

try {
    $conn = getConnection();
    $via = (string)$conn->query('SELECT HOST FROM information_schema.PROCESSLIST WHERE ID = CONNECTION_ID()')->fetchColumn();
    $info = 'PHP ' . PHP_VERSION . ' · database via ' . (strpos($via, ':') !== false ? 'TCP (127.0.0.1)' : 'socket');
    $conn->exec("CREATE TABLE IF NOT EXISTS sa_settings (k VARCHAR(60) PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = $conn->prepare('REPLACE INTO sa_settings (k, v) VALUES (?, ?)');
    $st->execute(['cron_heartbeat_at', date('Y-m-d H:i:s')]);
    $st->execute(['cron_heartbeat_info', $info]);
    echo date('c') . ' ok: ' . $info . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' cron test failed: ' . $e->getMessage() . "\n");
    exit(1);
}
