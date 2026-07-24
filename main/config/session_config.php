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

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 1 : 0);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_lifetime', 86400); // 24 hours - re-login after inactivity
ini_set('session.gc_maxlifetime', 86400); // 24 hours - clean stale session files
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);

$sessionPath = __DIR__ . '/../sessions';
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0777, true);
}
if (is_dir($sessionPath) && is_writable($sessionPath)) {
    session_save_path($sessionPath);
}

function startSecureSession($skipTimeoutValidation = false) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_start();
}



function isSessionValid() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
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

function regenerateSessionAfterLogin() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}



if (session_status() === PHP_SESSION_NONE) {
    startSecureSession();
}
