<?php
/**
 * Public health check for uptime monitors (UptimeRobot, Better Stack, cron pings...).
 *
 *   GET/HEAD https://restrogrow.com/main/api/health.php
 *
 * 200 + {"status":"ok", ...}       everything the site needs is working
 * 503 + {"status":"down", ...}     something critical failed (database, sessions, storage)
 *
 * It never shows passwords, host names, versions or paths, so it is safe to leave public.
 * Checks are tiny (a SELECT 1 and a few file-system checks), so polling every minute is fine.
 * Optional: ?check=orders also reads one row from the orders table (proves the app's own data
 * is readable, not just that MySQL answers).
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex');
ini_set('display_errors', '0');

$started = microtime(true);
$checks = [];
$critical = false;
$ok = function (string $name, array $extra = []) use (&$checks) { $checks[$name] = ['status' => 'ok'] + $extra; };
$bad = function (string $name, string $why, bool $isCritical = true) use (&$checks, &$critical) {
    $checks[$name] = ['status' => $isCritical ? 'down' : 'warn', 'reason' => $why];
    if ($isCritical) $critical = true;
};

// 1. Settings file (.env) and the database login it holds
$envOk = false;
try {
    require_once __DIR__ . '/../config/env_loader.php';
    $envOk = function_exists('env');
} catch (Throwable $e) {}
$envOk ? $ok('config') : $bad('config', 'settings could not be loaded');

// 2. Database: a fresh, short connection (does not use getConnection(), which prints details on failure)
if ($envOk) {
    $isLive = isset($_SERVER['HTTP_HOST']) && stripos((string)$_SERVER['HTTP_HOST'], 'restrogrow.com') !== false;
    $dbHost = env('DB_HOST_LOCAL', 'localhost');
    $dbName = env('DB_NAME', $isLive ? 'u509616587_restrogrow' : 'menuwebsite_db');
    $dbUser = env('DB_USER', $isLive ? 'u509616587_restrogrow' : 'root');
    $dbPass = env('DB_PASS', '');
    $t = microtime(true);
    try {
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 4,
            PDO::ATTR_PERSISTENT => false,
        ]);
        $pdo->query('SELECT 1')->fetchColumn();
        if (($_GET['check'] ?? '') === 'orders') {
            $pdo->query('SELECT id FROM orders ORDER BY id DESC LIMIT 1')->fetchColumn();
        }
        $ok('database', ['ms' => (int)round((microtime(true) - $t) * 1000)]);
        $pdo = null;
    } catch (Throwable $e) {
        error_log('[health] database: ' . $e->getMessage());
        $msg = $e->getMessage();
        $why = (stripos($msg, 'Too many connections') !== false || stripos($msg, 'max_connections') !== false) ? 'too many connections'
            : ((stripos($msg, 'Access denied') !== false) ? 'login refused' : 'not reachable');
        $bad('database', $why);
    }
}

// 3. Sessions folder (logins stop working if it is not writable)
$sessDir = __DIR__ . '/../sessions';
if (!is_dir($sessDir)) @mkdir($sessDir, 0700, true);
(is_dir($sessDir) && is_writable($sessDir)) ? $ok('sessions') : $bad('sessions', 'session folder not writable');

// 4. Uploads (menu photos, logos): not critical for orders, so only a warning
$upDir = __DIR__ . '/../uploads';
(is_dir($upDir) && is_writable($upDir)) ? $ok('uploads') : $bad('uploads', 'uploads folder not writable', false);

// 5. Disk space: under 200 MB free is critical, under 1 GB a warning
$free = @disk_free_space(__DIR__);
if ($free === false) {
    $ok('disk', ['note' => 'not reported by host']);
} elseif ($free < 200 * 1048576) {
    $bad('disk', 'almost full');
} elseif ($free < 1024 * 1048576) {
    $bad('disk', 'running low', false);
} else {
    $ok('disk');
}

$status = $critical ? 'down' : 'ok';
http_response_code($critical ? 503 : 200);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
echo json_encode([
    'status' => $status,
    'service' => 'RestroGrow',
    'checks' => $checks,
    'time' => gmdate('c'),
    'ms' => (int)round((microtime(true) - $started) * 1000),
], JSON_UNESCAPED_SLASHES);
