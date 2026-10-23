<?php
/**
 * The owners' morning report on WhatsApp (config/owner_manager.php): sends each restaurant's report once
 * a day, after the time the owner picked (in the restaurant's timezone).
 *
 * Hostinger hPanel › Advanced › Cron Jobs, every 15 minutes:
 *   /usr/bin/php /home/<user>/public_html/main/cron/owner_brief.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/owner_manager.php';

try {
    $conn = getConnection();
    ownerMgrEnsure($conn);
    // so Super Admin › Email › Settings & jobs can show the job is running
    try { $conn->exec("CREATE TABLE IF NOT EXISTS sa_settings (k VARCHAR(60) PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); $conn->prepare("REPLACE INTO sa_settings (k, v) VALUES ('cron_owner_brief_at', ?)")->execute([date('Y-m-d H:i:s')]); } catch (Throwable $e) { /* not important */ }
    // speed indexes the database is missing (once; config/db_indexes.php)
    try { require_once __DIR__ . '/../config/db_indexes.php'; dbEnsureIndexes($conn); } catch (Throwable $e) { error_log('owner_brief indexes: ' . $e->getMessage()); }
    $sent = 0;
    // not for restaurants whose plan ended or was switched off (filtered here: the tables' collations differ)
    $live = array_flip($conn->query("SELECT restaurant_id FROM users WHERE is_active = 1 AND COALESCE(subscription_status, '') NOT IN ('expired', 'disabled')")->fetchAll(PDO::FETCH_COLUMN));
    foreach ($conn->query('SELECT restaurant_id FROM om_settings WHERE enabled = 1')->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        if (!isset($live[$rid])) continue;
        try {
            if (ownerMgrSendIfDue($conn, (string)$rid)) $sent++;
        } catch (Throwable $e) {
            fwrite(STDERR, date('c') . " owner brief $rid failed: " . $e->getMessage() . "\n");
        }
    }
    echo date('c') . " owner briefs sent: $sent\n";
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' owner briefs failed: ' . $e->getMessage() . "\n");
    exit(1);
}
