<?php
/**
 * JSON sign-in for the Super Admin app (superadmin-app/). Same super_admins table, same
 * session keys and the same rate limit as login.php, which stays the HTML form for the
 * browser panel. Once signed in, the app calls api.php and api_platform.php like the panel.
 *
 *   GET  ?action=session            -> { success, data: { id, username } } or success:false
 *   POST ?action=login  {username, password, device?}   -> also returns device_token
 *   POST ?action=resume {device_token}                    -> new session from a saved device
 *   POST ?action=logout {device_token?}                   -> ends the session, forgets the device
 *
 * "Stay signed in": the PHP session lasts 24 hours, so on sign-in the app gets a random device
 * token (only its SHA-256 is stored, in sa_app_tokens) and quietly trades it for a new session
 * when the old one runs out. Tokens last 90 days from their last use and die on sign-out, when
 * the super admin is deactivated, or when their password changes (password_hash no longer matches).
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../db_connection.php';
if (file_exists(__DIR__ . '/../config/rate_limit.php')) {
    require_once __DIR__ . '/../config/rate_limit.php';
}

header('Content-Type: application/json');
header('Cache-Control: no-store');

const SA_TOKEN_DAYS = 90;

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: $_POST;

function saOut(array $a, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

function saConn(): PDO
{
    $c = function_exists('getConnection') ? getConnection() : ($GLOBALS['pdo'] ?? null);
    if (!$c instanceof PDO) saOut(['success' => false, 'message' => 'Database unavailable'], 500);
    $c->exec("CREATE TABLE IF NOT EXISTS sa_app_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        superadmin_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        pw_fingerprint CHAR(64) NOT NULL,
        device VARCHAR(120) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (superadmin_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return $c;
}

// Ties a device token to the password it was issued under: changing the password signs every device out.
function saPwFingerprint(string $passwordHash): string
{
    return hash('sha256', 'sa-app|' . $passwordHash);
}

function saStartSession(array $sa): void
{
    $_SESSION['superadmin_id'] = $sa['id'];
    $_SESSION['superadmin_username'] = $sa['username'];
    regenerateSessionAfterLogin();
}

function saAudit(PDO $conn, string $what, array $details): void
{
    try {
        require_once __DIR__ . '/../config/platform_engine.php';
        pfAudit($conn, $what, null, $details);
    } catch (Throwable $e) {
        error_log('[superadmin app] audit: ' . $e->getMessage());
    }
}

try {
    switch ($action) {
    case 'session':
        if (isSessionValid() && isset($_SESSION['superadmin_id'])) {
            saOut(['success' => true, 'data' => ['id' => (int)$_SESSION['superadmin_id'], 'username' => $_SESSION['superadmin_username'] ?? '']]);
        }
        saOut(['success' => false, 'message' => 'Not signed in']);

    case 'login':
        if (!$isPost) saOut(['success' => false, 'message' => 'POST required'], 405);
        if (function_exists('applyAuthRateLimit')) applyAuthRateLimit('login');
        // not $username / $password: db_connection.php reads globals with those names
        $saUser = trim((string)($in['username'] ?? ''));
        $saPass = (string)($in['password'] ?? '');
        if ($saUser === '' || $saPass === '') saOut(['success' => false, 'message' => 'Enter your username and password']);
        $conn = saConn();
        $st = $conn->prepare('SELECT id, username, password_hash FROM super_admins WHERE username = :u AND is_active = 1 LIMIT 1');
        $st->execute([':u' => $saUser]);
        $sa = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sa || !password_verify($saPass, $sa['password_hash'])) {
            if (function_exists('trackFailedAttempt')) trackFailedAttempt(getAuthRateLimitIdentifier(), $saUser);
            saOut(['success' => false, 'message' => 'Incorrect username or password']);
        }
        if (function_exists('clearFailedAttempts')) clearFailedAttempts(getAuthRateLimitIdentifier(), $saUser);
        saStartSession($sa);
        $token = bin2hex(random_bytes(32));
        $conn->prepare('INSERT INTO sa_app_tokens (superadmin_id, token_hash, pw_fingerprint, device) VALUES (?, ?, ?, ?)')
            ->execute([$sa['id'], hash('sha256', $token), saPwFingerprint($sa['password_hash']), mb_substr(trim((string)($in['device'] ?? '')), 0, 120) ?: null]);
        saAudit($conn, 'app.login', ['via' => 'superadmin app', 'device' => $in['device'] ?? null]);
        saOut(['success' => true, 'data' => ['id' => (int)$sa['id'], 'username' => $sa['username']], 'device_token' => $token]);

    case 'resume':
        if (!$isPost) saOut(['success' => false, 'message' => 'POST required'], 405);
        $token = (string)($in['device_token'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) saOut(['success' => false, 'message' => 'Please sign in again']);
        $conn = saConn();
        $st = $conn->prepare("SELECT t.id tid, t.pw_fingerprint, t.last_used_at, s.id, s.username, s.password_hash FROM sa_app_tokens t
            JOIN super_admins s ON s.id = t.superadmin_id AND s.is_active = 1 WHERE t.token_hash = ? LIMIT 1");
        $st->execute([hash('sha256', $token)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $fresh = $row && strtotime($row['last_used_at']) > time() - SA_TOKEN_DAYS * 86400;
        if (!$row || !$fresh || !hash_equals($row['pw_fingerprint'], saPwFingerprint($row['password_hash']))) {
            if ($row) $conn->prepare('DELETE FROM sa_app_tokens WHERE id = ?')->execute([$row['tid']]);
            if (function_exists('trackFailedAttempt')) trackFailedAttempt(getAuthRateLimitIdentifier());
            saOut(['success' => false, 'message' => 'Please sign in again']);
        }
        $conn->prepare('UPDATE sa_app_tokens SET last_used_at = NOW() WHERE id = ?')->execute([$row['tid']]);
        saStartSession($row);
        saOut(['success' => true, 'data' => ['id' => (int)$row['id'], 'username' => $row['username']]]);

    case 'logout':
        $token = (string)($in['device_token'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $token)) {
            try { saConn()->prepare('DELETE FROM sa_app_tokens WHERE token_hash = ?')->execute([hash('sha256', $token)]); } catch (Throwable $e) {}
        }
        destroySession();
        saOut(['success' => true]);

    default:
        saOut(['success' => false, 'message' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    error_log('[superadmin app_auth] ' . $action . ': ' . $e->getMessage());
    saOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
