<?php
/**
 * Last step of "Continue with Google" on the Super Admin login (config/google_login.php).
 * Only the team emails in googleLoginSuperadminEmails() get in. Each email signs in as its own
 * super admin (the row with that email; made on first sign-in), so the audit log and "created by"
 * show who did what. A super admin switched off in the database stays out.
 */
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/google_login.php';
require_once __DIR__ . '/../config/platform_engine.php';
if (file_exists(__DIR__ . '/../config/rate_limit.php')) require_once __DIR__ . '/../config/rate_limit.php';
startSecureSession();

// the message goes through the session, never the URL, so nobody can put their own text on this page
function googleSaBack(string $msg): void
{
    $_SESSION['sa_login_error'] = $msg;
    header('Location: login.php');
    exit;
}

if (function_exists('applyAuthRateLimit')) applyAuthRateLimit('login');

try {
    $conn = getConnection();
    $p = googleLoginConsumeToken($conn, (string)($_GET['t'] ?? ''), 'superadmin');
    if (!$p) googleSaBack('That sign-in link expired. Please try again.');
    // only the browser that pressed "Continue with Google" (its rg_google_sa cookie, set by api/google_login.php)
    $key = (string)($_COOKIE['rg_google_sa'] ?? '');
    if (($p['k'] ?? '') === '' || !hash_equals((string)$p['k'], $key)) googleSaBack('Please start the Google sign-in again from this browser.');
    setcookie('rg_google_sa', '', ['expires' => 1, 'path' => '/', 'secure' => !googleLoginIsLocal(), 'httponly' => true, 'samesite' => 'Lax']);
    if (!in_array($p['email'], googleLoginSuperadminEmails(), true)) {
        if (function_exists('trackFailedAttempt')) trackFailedAttempt(getRateLimitIdentifier());
        pfAudit($conn, 'login.google_refused', $p['email']);
        googleSaBack($p['email'] . ' does not have Super Admin access.');
    }

    $st = $conn->prepare('SELECT * FROM super_admins WHERE LOWER(email) = ? ORDER BY id LIMIT 1');
    $st->execute([$p['email']]);
    $sa = $st->fetch(PDO::FETCH_ASSOC);
    if ($sa && (int)$sa['is_active'] !== 1) {
        pfAudit($conn, 'login.google_refused', $p['email'], ['reason' => 'super admin switched off']);
        googleSaBack('This Super Admin account is switched off.');
    }
    if (!$sa) {
        // first Google sign-in for this email: its own super admin (password login stays off: random hash)
        $base = preg_replace('/[^a-z0-9._-]/', '', strtolower(strstr($p['email'], '@', true))) ?: 'admin';
        $username = $base;
        for ($i = 2; ; $i++) {
            $taken = $conn->prepare('SELECT 1 FROM super_admins WHERE username = ?');
            $taken->execute([$username]);
            if (!$taken->fetchColumn()) break;
            $username = $base . $i;
        }
        $conn->prepare('INSERT INTO super_admins (username, email, password_hash, is_active) VALUES (?, ?, ?, 1)')
            ->execute([$username, $p['email'], password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
        $sa = ['id' => (int)$conn->lastInsertId(), 'username' => $username];
    }

    if (function_exists('clearFailedAttempts')) clearFailedAttempts(getRateLimitIdentifier());
    $_SESSION['superadmin_id'] = $sa['id'];
    $_SESSION['superadmin_username'] = $sa['username'];
    regenerateSessionAfterLogin();
    pfAudit($conn, 'login.google', $p['email']);
    header('Location: dashboard.php');
    exit;
} catch (Throwable $e) {
    error_log('[superadmin google] ' . $e->getMessage());
    googleSaBack($e instanceof RuntimeException ? $e->getMessage() : 'Google sign-in failed. Please try again.');
}
