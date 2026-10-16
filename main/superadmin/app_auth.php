<?php
/**
 * JSON sign-in for the Super Admin app (superadmin-app/). Same super_admins table, same
 * session keys and the same rate limit as login.php, which stays the HTML form for the
 * browser panel. Once signed in, the app calls api.php and api_platform.php like the panel.
 *
 *   GET  ?action=session            -> { success, data: { id, username } } or success:false
 *   POST ?action=login  {username, password}
 *   POST ?action=logout
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../db_connection.php';
if (file_exists(__DIR__ . '/../config/rate_limit.php')) {
    require_once __DIR__ . '/../config/rate_limit.php';
}

header('Content-Type: application/json');
header('Cache-Control: no-store');

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: $_POST;

function saOut(array $a, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
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
        $saUser = trim((string)($in['username'] ?? ''));
        $saPass = (string)($in['password'] ?? '');
        if ($saUser === '' || $saPass === '') saOut(['success' => false, 'message' => 'Enter your username and password']);
        $conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
        if (!$conn instanceof PDO) saOut(['success' => false, 'message' => 'Database unavailable'], 500);
        $st = $conn->prepare('SELECT id, username, password_hash FROM super_admins WHERE username = :u AND is_active = 1 LIMIT 1');
        $st->execute([':u' => $saUser]);
        $sa = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sa || !password_verify($saPass, $sa['password_hash'])) {
            if (function_exists('trackFailedAttempt')) trackFailedAttempt(getRateLimitIdentifier());
            saOut(['success' => false, 'message' => 'Incorrect username or password']);
        }
        if (function_exists('clearFailedAttempts')) clearFailedAttempts(getRateLimitIdentifier());
        $_SESSION['superadmin_id'] = $sa['id'];
        $_SESSION['superadmin_username'] = $sa['username'];
        regenerateSessionAfterLogin();
        try {
            require_once __DIR__ . '/../config/platform_engine.php';
            pfEnsure($conn);
            pfAudit($conn, 'app.login', null, ['via' => 'superadmin app']);
        } catch (Throwable $e) {
            error_log('[superadmin app] audit: ' . $e->getMessage());
        }
        saOut(['success' => true, 'data' => ['id' => (int)$sa['id'], 'username' => $sa['username']]]);

    case 'logout':
        destroySession();
        saOut(['success' => true]);

    default:
        saOut(['success' => false, 'message' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    error_log('[superadmin app_auth] ' . $action . ': ' . $e->getMessage());
    saOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
