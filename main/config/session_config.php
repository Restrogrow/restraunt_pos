<?php
if (defined('SESSION_CONFIG_LOADED')) {
    return;
}
define('SESSION_CONFIG_LOADED', true);

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');

if (!function_exists('isSecureRequest')) {
    /**
     * $_SERVER['HTTPS'] alone misses the common case of a TLS-terminating
     * reverse proxy in front of the app (real HTTPS browser<->proxy, plain
     * HTTP proxy<->app) — PHP sees 'off' and the session/remember-me cookies
     * get issued without Secure, letting them be sent over a subsequently
     * downgraded plain-HTTP connection to the same host. Also honoring
     * X-Forwarded-Proto only ever makes the cookie MORE restrictive (Secure
     * gets set, never unset), so it's safe to trust unconditionally here —
     * unlike trusting a proxy header for rate-limiting/IP identification,
     * there's no way for a client claiming a stronger transport than it
     * actually has to weaken cookie security.
     */
    function isSecureRequest(): bool {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            return true;
        }
        return strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}

// Session ini settings can only be changed before any output is sent. Some
// entry points (an API that has already echoed, an include after a redirect)
// hit this file with headers already sent — guard so those don't flood the
// error monitor with warnings that can't be acted on.
// Logins stay signed in: the owner asked for no "session expired" (Oct 2026). A login lasts 400 days
// (the most a browser keeps a cookie) and is renewed once a day while it is used, so someone who opens
// the dashboard now and then is never logged out. Logout still ends it at once.
if (!defined('SESSION_KEEP_SECONDS')) define('SESSION_KEEP_SECONDS', 400 * 86400);

if (!headers_sent()) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isSecureRequest() ? 1 : 0);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_lifetime', SESSION_KEEP_SECONDS);
    ini_set('session.gc_maxlifetime', SESSION_KEEP_SECONDS); // session files unused for 400 days are cleaned up
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 100);
}

$sessionPath = __DIR__ . '/../sessions';
if (!is_dir($sessionPath)) {
    // 0700 (owner-only), not 0777 — this folder holds active session data
    // (who's logged in as whom); the web server user is the only one that
    // ever needs to read/write it.
    @mkdir($sessionPath, 0700, true);
}
if (is_dir($sessionPath) && is_writable($sessionPath) && !headers_sent()) {
    session_save_path($sessionPath);
}

function startSecureSession($skipTimeoutValidation = false) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Output already started (an API echoed first, an include after a redirect):
    // session_start() would only warn. Skip it rather than log a warning the
    // caller can't act on.
    if (headers_sent()) {
        return;
    }
    session_start();
    keepSessionCookieFresh();
}

/**
 * PHP only sends the session cookie when a session starts, so its 400 days would count from the login.
 * Once a day while someone is signed in, send it again with a fresh 400 days: in use = never expires.
 */
function keepSessionCookieFresh(): void {
    if (headers_sent() || session_status() !== PHP_SESSION_ACTIVE) return;
    if (!isset($_SESSION['restaurant_id']) && !isset($_SESSION['superadmin_id']) && !isset($_SESSION['branch_admin_id'])) return;
    $today = date('Y-m-d');
    if (($_SESSION['_cookie_renewed'] ?? '') === $today) return;
    $_SESSION['_cookie_renewed'] = $today;
    $p = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires' => time() + SESSION_KEEP_SECONDS,
        'path' => $p['path'] ?: '/',
        'domain' => $p['domain'],
        'secure' => $p['secure'],
        'httponly' => true,
        'samesite' => $p['samesite'] ?: 'Lax',
    ]);
}



// Reads $_SESSION, not session_status(): APIs call session_write_close() early (so requests don't queue
// on the session lock) and still check the role afterwards — the data stays loaded after the close.
function isSessionValid() {
    return isset($_SESSION['restaurant_id']) || isset($_SESSION['superadmin_id']) || isset($_SESSION['branch_admin_id']);
}

function destroySession() {
    $_SESSION = array();
    if (isset($_COOKIE[session_name()])) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 3600,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Call right before a login writes its account into the session. Forgets the account signed in before
 * in this browser: without it, a waiter logging in where the owner had been logged in kept the owner's
 * user_id (and role), so the server treated the waiter as the owner. Super Admin, the website's
 * customer login and half-finished Google sign-ups are separate and stay.
 */
function resetLoginIdentity() {
    foreach (['user_id', 'staff_id', 'branch_admin_id', 'username', 'email', 'restaurant_id', 'restaurant_name', 'user_type', 'role',
              'linked_restaurants', 'currency_symbol', 'timezone', 'tax_percent', 'tax_name', 'enable_gst', 'country', 'phonepe_mode',
              'seo_sc_state', 'seo_sc_state_rid', 'gbp_state', 'gbp_state_rid', 'auto_login_token', 'auto_login_restaurant_id',
              'pending_payment_link_id', '_acct_checked_at', '_acct_pw'] as $k) {
        unset($_SESSION[$k]);
    }
}

function regenerateSessionAfterLogin() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}



if (session_status() === PHP_SESSION_NONE) {
    startSecureSession();
}

/**
 * A login lasts 400 days, so about once a minute check the account behind it is still allowed in:
 * a staff member paused, deleted or moved, a restaurant or branch admin switched off, or a password
 * changed (on another device, or by a reset) ends this login. A changed staff role is picked up
 * instead. Only the login keys go (resetLoginIdentity); Super Admin and the website's customer login
 * stay. The demo restaurant is skipped (its visitors switch roles all the time), and a database
 * error leaves the session as it is.
 */
function sessionAccountStillValid(PDO $conn): bool {
    if (!empty($_SESSION['staff_id'])) {
        $st = $conn->prepare('SELECT s.is_active, s.role, s.restaurant_id, s.password, u.is_active AS rest_active
            FROM staff s LEFT JOIN users u ON u.restaurant_id = s.restaurant_id WHERE s.id = ? LIMIT 1');
        $st->execute([(int)$_SESSION['staff_id']]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || !(int)$r['is_active'] || (string)$r['restaurant_id'] !== (string)($_SESSION['restaurant_id'] ?? '')
            || ($r['rest_active'] !== null && !(int)$r['rest_active'])) return false;
        if ((string)$r['role'] !== '' && ($_SESSION['role'] ?? '') !== $r['role']) $_SESSION['role'] = $r['role'];
        $pw = (string)$r['password'];
    } elseif (!empty($_SESSION['user_id'])) {
        // the owner's own account (the session's restaurant can be a linked branch they switched to)
        $st = $conn->prepare('SELECT is_active, password FROM users WHERE id = ? LIMIT 1');
        $st->execute([(int)$_SESSION['user_id']]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || !(int)$r['is_active']) return false;
        $pw = (string)$r['password'];
    } elseif (!empty($_SESSION['branch_admin_id'])) {
        $st = $conn->prepare('SELECT is_active, password_hash FROM branch_admins WHERE id = ? LIMIT 1');
        $st->execute([(int)$_SESSION['branch_admin_id']]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || !(int)$r['is_active']) return false;
        $pw = (string)$r['password_hash'];
    } else {
        return true;
    }
    // a short fingerprint of the password hash, never the hash itself
    $print = substr(hash('sha256', $pw), 0, 16);
    if (!isset($_SESSION['_acct_pw'])) $_SESSION['_acct_pw'] = $print;
    return hash_equals((string)$_SESSION['_acct_pw'], $print);
}

if (session_status() === PHP_SESSION_ACTIVE
    && (!empty($_SESSION['staff_id']) || !empty($_SESSION['user_id']) || !empty($_SESSION['branch_admin_id']))
    && ($_SESSION['restaurant_id'] ?? '') !== 'RESSGI002'
    && time() - (int)($_SESSION['_acct_checked_at'] ?? 0) >= 60) {
    require_once __DIR__ . '/../db_connection.php';   // file scope: db_connection.php's globals stay global
    try {
        $_SESSION['_acct_checked_at'] = time();
        if (!sessionAccountStillValid(getConnection())) resetLoginIdentity();
    } catch (Throwable $e) {
        error_log('session account check: ' . $e->getMessage());
    }
}

// Sections Super Admin switched off for this restaurant (config/access_control.php): their own APIs answer 403.
// The database is loaded here only for those few files, at file scope so db_connection.php's globals stay global.
require_once __DIR__ . '/access_control.php';
if (!empty($_SESSION['restaurant_id']) && accessPageForApi(basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) !== '') {
    require_once __DIR__ . '/../db_connection.php';
    accessGuardRequest();
}
