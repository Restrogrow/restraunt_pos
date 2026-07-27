<?php
/**
 * 🔍 RestroGrow Health Check
 * 
 * A diagnostic page to test the environment:
 * - PHP version & extensions
 * - Session directory
 * - Database connection
 * - .env file loading
 * - Rate limit directory
 * 
 * Access: http://localhost/menuwebsite/main/admin/health_check.php
 */

// ── Show ALL errors for this diagnostic page ──
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 0);

// ── Results array ──
$results = [];
$allPass = true;

function pass($check, $detail = '') { global $results, $allPass; $results[] = ['check' => $check, 'status' => '✅ PASS', 'detail' => $detail]; }
function fail($check, $detail = '') { global $results, $allPass; $results[] = ['check' => $check, 'status' => '❌ FAIL', 'detail' => $detail]; $allPass = false; }
function warn($check, $detail = '') { global $results; $results[] = ['check' => $check, 'status' => '⚠️ WARN', 'detail' => $detail]; }

// ============================================================
// 1. PHP Version
// ============================================================
$phpVersion = phpversion();
if (version_compare($phpVersion, '8.0', '>=')) {
    pass('PHP Version', "v{$phpVersion} (8.0+ required)");
} else {
    fail('PHP Version', "v{$phpVersion} (8.0+ required)");
}

// ============================================================
// 2. Required PHP Extensions
// ============================================================
$requiredExts = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'session', 'fileinfo', 'curl', 'openssl', 'hash'];
foreach ($requiredExts as $ext) {
    if (extension_loaded($ext)) {
        pass("Extension: {$ext}", 'loaded');
    } else {
        fail("Extension: {$ext}", 'NOT LOADED — enable in php.ini');
    }
}

// ============================================================
// 3. Session Configuration
// ============================================================
$sessionPath = __DIR__ . '/../sessions';
if (is_dir($sessionPath)) {
    if (is_writable($sessionPath)) {
        $fileCount = count(glob($sessionPath . '/sess_*'));
        pass('Session directory', "Exists & writable ({$fileCount} session files)");
    } else {
        fail('Session directory', 'Exists but NOT WRITABLE');
    }
} else {
    $created = @mkdir($sessionPath, 0777, true);
    if ($created) {
        pass('Session directory', 'Created successfully');
    } else {
        fail('Session directory', "Does not exist and could not be created — try: mkdir '{$sessionPath}'");
    }
}

// Show current session save path
$actualPath = session_save_path();
if ($actualPath) {
    if ($actualPath === $sessionPath || realpath($actualPath) === realpath($sessionPath)) {
        pass('Session save path', "Correctly set to: {$sessionPath}");
    } else {
        warn('Session save path', "PHP using: {$actualPath} (expected: {$sessionPath})");
    }
} else {
    warn('Session save path', 'Using default PHP path (session_save_path empty)');
}

// Test session start
$sessionTest = false;
if (session_status() === PHP_SESSION_ACTIVE) {
    $sessionTest = true;
    pass('Session status', 'Already active');
} else {
    @session_start();
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sessionTest = true;
        pass('Session status', 'Started successfully');
        session_write_close();
    } else {
        fail('Session status', 'Could not start session');
    }
}

// ============================================================
// 4. Environment File (.env)
// ============================================================
$envFile = __DIR__ . '/../.env';
$envLocalFile = __DIR__ . '/../.env.local';

if (file_exists($envFile)) {
    $envSize = filesize($envFile);
    if ($envSize > 0) {
        pass('.env file', "Exists ({$envSize} bytes)");
    } else {
        fail('.env file', 'Exists but is EMPTY (0 bytes)');
    }
} else {
    fail('.env file', 'MISSING — copy .env.example to .env and fill in credentials');
}

if (file_exists($envLocalFile)) {
    pass('.env.local file', 'Exists (local overrides active)');
} else {
    warn('.env.local file', 'Not present (optional — used for local dev overrides)');
}

// Load env and test key vars
require_once __DIR__ . '/../config/env_loader.php';
$dbName = env('DB_NAME', '');
$dbUser = env('DB_USER', '');
$dbHost = env('DB_HOST_LOCAL', 'localhost');

if (!empty($dbName)) {
    pass('DB_NAME from .env', $dbName);
} else {
    fail('DB_NAME from .env', 'Not set in .env file');
}

if (!empty($dbUser)) {
    pass('DB_USER from .env', $dbUser);
} else {
    fail('DB_USER from .env', 'Not set in .env file');
}

// ============================================================
// 5. Database Connection
// ============================================================
try {
    require_once __DIR__ . '/../db_connection.php';
    
    // Try to get connection — use a short timeout
    $conn = getConnection();
    
    if ($conn && $conn instanceof PDO) {
        pass('Database connection', 'Connected successfully');
        
        // Test a simple query
        $stmt = $conn->query("SELECT 1 as test");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['test'] == 1) {
            pass('Database query', 'SELECT 1 returned: ' . $row['test']);
        } else {
            fail('Database query', 'SELECT 1 failed');
        }
        
        // Check if users table exists and has data
        try {
            $tablesStmt = $conn->query("SHOW TABLES");
            $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
            pass('Database tables', count($tables) . ' tables found: ' . implode(', ', array_slice($tables, 0, 10)) . (count($tables) > 10 ? '...' : ''));
            
            if (in_array('users', $tables)) {
                $userCount = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
                pass('Users table', "{$userCount} users found");
            } else {
                fail('Users table', 'NOT FOUND — did you import the database?');
            }
        } catch (Exception $e) {
            fail('Database tables check', $e->getMessage());
        }
        
        // Check connection stats
        $stats = function_exists('getConnectionStats') ? getConnectionStats() : [];
        if (!empty($stats)) {
            pass('Connection stats', json_encode($stats));
        }
    } else {
        fail('Database connection', 'getConnection() returned no PDO object');
    }
} catch (Exception $e) {
    fail('Database connection', $e->getMessage());
}

// ============================================================
// 6. Rate Limit Directory
// ============================================================
$rateLimitDir = __DIR__ . '/../tmp/rate_limits';
if (is_dir($rateLimitDir)) {
    if (is_writable($rateLimitDir)) {
        pass('Rate limit directory', 'Exists & writable');
    } else {
        fail('Rate limit directory', 'Exists but NOT WRITABLE');
    }
} else {
    $created = @mkdir($rateLimitDir, 0755, true);
    if ($created) {
        pass('Rate limit directory', 'Created successfully');
    } else {
        $parentDir = __DIR__ . '/../tmp';
        if (!is_dir($parentDir)) {
            $parentCreated = @mkdir($parentDir, 0755, true);
            if ($parentCreated) {
                $created2 = @mkdir($rateLimitDir, 0755, true);
                if ($created2) {
                    pass('Rate limit directory', 'Created successfully (including parent)');
                } else {
                    fail('Rate limit directory', "Could not create — mkdir failed");
                }
            } else {
                fail('Rate limit directory', "Parent tmp/ directory does not exist and could not be created");
            }
        } else {
            fail('Rate limit directory', "Could not create — check permissions on tmp/");
        }
    }
}

// ============================================================
// 7. PHP Configuration
// ============================================================
$configChecks = [
    'display_errors' => ini_get('display_errors'),
    'error_reporting' => ini_get('error_reporting'),
    'max_execution_time' => ini_get('max_execution_time') . 's',
    'memory_limit' => ini_get('memory_limit'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'session.gc_maxlifetime' => ini_get('session.gc_maxlifetime') . 's',
    'session.cookie_lifetime' => ini_get('session.cookie_lifetime') . 's',
    'date.timezone' => ini_get('date.timezone') ?: date_default_timezone_get(),
];
pass('PHP Configuration', json_encode($configChecks));

// ============================================================
// 8. Server Info
// ============================================================
$serverInfo = [
    'SERVER_SOFTWARE' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
    'SERVER_NAME' => $_SERVER['SERVER_NAME'] ?? 'unknown',
    'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'unknown',
    'DOCUMENT_ROOT' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
    'REQUEST_SCHEME' => $_SERVER['REQUEST_SCHEME'] ?? 'http',
    'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
];
pass('Server info', json_encode($serverInfo));

// ============================================================
// Summary
// ============================================================
$passCount = count(array_filter($results, fn($r) => $r['status'] === '✅ PASS'));
$warnCount = count(array_filter($results, fn($r) => $r['status'] === '⚠️ WARN'));
$failCount = count(array_filter($results, fn($r) => $r['status'] === '❌ FAIL'));

// ============================================================
// Render HTML
// ============================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔍 Health Check — RestroGrow</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f3f4f6;
            padding: 32px 16px;
            color: #1f2937;
        }
        .container { max-width: 900px; margin: 0 auto; }
        h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .subtitle {
            color: #6b7280;
            margin-bottom: 24px;
            font-size: 0.9rem;
        }
        .summary-bar {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .summary-chip {
            padding: 8px 16px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .summary-chip.pass { background: #d1fae5; color: #065f46; }
        .summary-chip.warn { background: #fef3c7; color: #92400e; }
        .summary-chip.fail { background: #fee2e2; color: #991b1b; }
        .results { display: flex; flex-direction: column; gap: 6px; }
        .result-row {
            background: white;
            border-radius: 10px;
            padding: 12px 16px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            font-size: 0.9rem;
            line-height: 1.5;
        }
        .result-row .status { font-weight: 600; white-space: nowrap; min-width: 80px; }
        .result-row .check { font-weight: 600; min-width: 200px; color: #374151; }
        .result-row .detail { color: #6b7280; word-break: break-all; font-family: monospace; font-size: 0.8rem; }
        .result-row.fail { border-left: 4px solid #ef4444; }
        .result-row.warn { border-left: 4px solid #f59e0b; }
        .result-row.pass { border-left: 4px solid #10b981; }
        .timestamp {
            text-align: center;
            margin-top: 24px;
            color: #9ca3af;
            font-size: 0.8rem;
        }
        @media (max-width: 640px) {
            .result-row { flex-wrap: wrap; gap: 4px; }
            .result-row .check { min-width: auto; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Health Check</h1>
        <p class="subtitle">RestroGrow environment diagnostics — <?= date('Y-m-d H:i:s') ?></p>

        <div class="summary-bar">
            <span class="summary-chip pass">✅ <?= $passCount ?> passed</span>
            <?php if ($warnCount > 0): ?>
                <span class="summary-chip warn">⚠️ <?= $warnCount ?> warnings</span>
            <?php endif; ?>
            <?php if ($failCount > 0): ?>
                <span class="summary-chip fail">❌ <?= $failCount ?> failures</span>
            <?php endif; ?>
        </div>

        <div class="results">
            <?php foreach ($results as $r): 
                $cls = $r['status'] === '✅ PASS' ? 'pass' : ($r['status'] === '⚠️ WARN' ? 'warn' : 'fail');
            ?>
                <div class="result-row <?= $cls ?>">
                    <span class="status"><?= $r['status'] ?></span>
                    <span class="check"><?= htmlspecialchars($r['check']) ?></span>
                    <span class="detail"><?= htmlspecialchars($r['detail']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="timestamp">
            Generated at <?= date('Y-m-d H:i:s T') ?> | PHP <?= phpversion() ?>
        </div>
    </div>
</body>
</html>
