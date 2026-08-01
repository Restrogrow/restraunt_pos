<?php
/**
 * 🚀 Super Simple Debug Page - NO database, NO session, NO dependencies
 * Loads instantly even when MySQL is down
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 0);

// ── Access control ──
// Discloses server software, document root, and whether MySQL is reachable —
// must not be open to anonymous visitors. Deliberately avoids a DB-backed
// login (this page exists to work even when the DB is down), so it's gated on
// localhost or a secret key from .env (HEALTH_CHECK_KEY) instead. No
// hardcoded default: if the key isn't configured, only localhost can use it.
if (file_exists(__DIR__ . '/../config/env_loader.php')) {
    require_once __DIR__ . '/../config/env_loader.php';
}
$__allowedIPs = ['127.0.0.1', '::1'];
$__isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', $__allowedIPs, true);
$__configuredKey = function_exists('env') ? env('HEALTH_CHECK_KEY', '') : '';
$__providedKey = $_GET['key'] ?? '';
$__keyOk = !empty($__configuredKey) && hash_equals((string)$__configuredKey, (string)$__providedKey);
if (!$__isLocal && !$__keyOk) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    die('Access denied.');
}

$checks = [];

function p($label, $status, $detail = '') {
    global $checks;
    $checks[] = ['label' => $label, 'status' => $status, 'detail' => $detail];
}

// PHP Version
p('PHP Version', phpversion() >= '8.0' ? '✅' : '❌', phpversion());

// Extensions
foreach (['pdo', 'pdo_mysql', 'mbstring', 'json', 'session', 'fileinfo'] as $ext) {
    p("Extension: $ext", extension_loaded($ext) ? '✅' : '❌', extension_loaded($ext) ? 'loaded' : 'MISSING');
}

// Server info
p('Server Software', $_SERVER['SERVER_SOFTWARE'] ?? '✅', $_SERVER['SERVER_SOFTWARE'] ?? 'unknown');
p('Server Name', '✅', $_SERVER['SERVER_NAME'] ?? 'unknown');
p('Document Root', '✅', $_SERVER['DOCUMENT_ROOT'] ?? 'unknown');

// Check if MySQL port is open
$fp = @fsockopen('127.0.0.1', 3306, $errno, $errstr, 2);
if ($fp) {
    p('MySQL port 3306', '✅', 'Open and accepting connections');
    fclose($fp);
} else {
    p('MySQL port 3306', '❌', "REFUSED — MySQL is NOT running! ($errstr)");
}

// Check .env file
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    p('.env file', '✅', filesize($envFile) . ' bytes');
} else {
    p('.env file', '❌', 'MISSING');
}

// Check sessions directory
$sessDir = __DIR__ . '/../sessions';
if (is_dir($sessDir) && is_writable($sessDir)) {
    p('Session directory', '✅', 'Exists & writable');
} else {
    p('Session directory', is_dir($sessDir) ? '❌' : '❌', is_dir($sessDir) ? 'Not writable' : 'Missing');
}

// PHP config
p('max_execution_time', '✅', ini_get('max_execution_time') . 's');
p('memory_limit', '✅', ini_get('memory_limit'));
p('display_errors', '✅', ini_get('display_errors'));

// Date & Time
p('Date/Time', '✅', date('Y-m-d H:i:s T'));

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>🔍 Quick Debug</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f3f4f6; padding: 24px; }
        h1 { font-size: 1.5rem; margin-bottom: 16px; }
        table { width: 100%; max-width: 700px; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
        th, td { padding: 10px 14px; text-align: left; font-size: 0.85rem; border-bottom: 1px solid #f0f0f0; }
        th { background: #f9fafb; font-weight: 600; width: 200px; }
        td:last-child { font-family: monospace; color: #6b7280; }
        .s { color: #059669; font-weight: bold; }
        .f { color: #dc2626; font-weight: bold; }
        .critical { background: #fef2f2; border: 2px solid #fecaca; padding: 16px; border-radius: 12px; margin-bottom: 16px; max-width: 700px; }
        .critical h2 { color: #991b1b; font-size: 1.1rem; margin-bottom: 8px; }
        .critical p { color: #b91c1c; font-size: 0.9rem; line-height: 1.5; }
    </style>
</head>
<body>
    <h1>🔍 Quick Debug — RestroGrow</h1>

    <?php
    $mysqlDown = false;
    foreach ($checks as $c) {
        if (strpos($c['label'], 'MySQL port') !== false && strpos($c['detail'], 'REFUSED') !== false) {
            $mysqlDown = true;
        }
    }
    if ($mysqlDown): ?>
    <div class="critical">
        <h2>🚨 MySQL is NOT running!</h2>
        <p>This is why everything hangs. Open <strong>XAMPP Control Panel</strong> and click <strong>Start</strong> on MySQL.<br>
        If it keeps stopping, open XAMPP as <strong>Administrator</strong> (right-click → Run as administrator).</p>
    </div>
    <?php endif; ?>

    <table>
        <?php foreach ($checks as $c): ?>
        <tr>
            <td><?= htmlspecialchars($c['label']) ?></td>
            <td class="<?= $c['status'] === '✅' ? 's' : 'f' ?>"><?= $c['status'] ?></td>
            <td><?= htmlspecialchars($c['detail']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p style="margin-top: 16px; color: #9ca3af; font-size: 0.8rem;">Generated: <?= date('Y-m-d H:i:s T') ?></p>
</body>
</html>
