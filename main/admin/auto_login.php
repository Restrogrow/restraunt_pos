<?php
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Super Admin › Branch admins › Log in: sign in as that multi-branch owner (superadmin/api.php branchAdminLogin)
$branchLogin = $_SESSION['auto_login_branch_admin'] ?? null;
unset($_SESSION['auto_login_branch_admin']);
if (is_array($branchLogin) && !empty($_SESSION['superadmin_id']) && (int)($branchLogin['expires'] ?? 0) > time()) {
    try {
        require_once __DIR__ . '/../db_connection.php';
        $conn = getConnection();
        $st = $conn->prepare("SELECT id, username FROM branch_admins WHERE id = ? AND is_active = 1 LIMIT 1");
        $st->execute([(int)$branchLogin['id']]);
        $ba = $st->fetch(PDO::FETCH_ASSOC);
        $st = $conn->prepare("SELECT brl.restaurant_id, brl.label, u.restaurant_name, u.id AS user_id
            FROM branch_restaurant_links brl JOIN users u ON u.restaurant_id = brl.restaurant_id AND u.is_active = 1
            WHERE brl.branch_admin_id = ?");
        $st->execute([(int)$branchLogin['id']]);
        $linked = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$ba || !$linked) { header('Location: login.php?error=invalid_token'); exit(); }
        if (function_exists('resetLoginIdentity')) resetLoginIdentity();
        $_SESSION['branch_admin_id'] = $ba['id'];
        $_SESSION['username'] = $ba['username'];
        $_SESSION['user_type'] = 'branch_admin';
        $_SESSION['role'] = 'Branch Admin';
        $_SESSION['user_id'] = (int)$linked[0]['user_id'];
        $_SESSION['restaurant_id'] = $linked[0]['restaurant_id'];
        $_SESSION['restaurant_name'] = $linked[0]['restaurant_name'];
        foreach ($linked as &$l) unset($l['user_id']);
        unset($l);
        $_SESSION['linked_restaurants'] = $linked;
        if (function_exists('regenerateSessionAfterLogin')) regenerateSessionAfterLogin();
        header('Location: ../views/branches.php');
        exit();
    } catch (Exception $e) {
        header('Location: login.php?error=server_error');
        exit();
    }
}

// Read token from session instead of URL to prevent token leakage in server logs
$token = $_SESSION['auto_login_token'] ?? '';
$restaurant_id = $_SESSION['auto_login_restaurant_id'] ?? '';

// Clear session tokens immediately (single-use)
unset($_SESSION['auto_login_token']);
unset($_SESSION['auto_login_restaurant_id']);

if (!$token || !$restaurant_id) {
    header('Location: login.php?error=invalid_token');
    exit();
}

try {
    if (file_exists(__DIR__ . '/../db_connection.php')) {
        require_once __DIR__ . '/../db_connection.php';
    }
    $conn = getConnection();

    $stmt = $conn->prepare("SELECT id, username, restaurant_id, restaurant_name FROM users WHERE restaurant_id = ? AND auto_login_token = ? AND auto_login_expires > NOW() LIMIT 1");
    $stmt->execute([$restaurant_id, $token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $clear = $conn->prepare("UPDATE users SET auto_login_token = NULL, auto_login_expires = NULL WHERE id = ?");
        $clear->execute([$user['id']]);

        if (function_exists('resetLoginIdentity')) resetLoginIdentity();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['restaurant_id'] = $user['restaurant_id'];
        $_SESSION['restaurant_name'] = $user['restaurant_name'];
        $_SESSION['user_type'] = 'admin';
        $_SESSION['role'] = 'Admin';

        if (function_exists('regenerateSessionAfterLogin')) {
            regenerateSessionAfterLogin();
        }

        header('Location: ../views/dashboard.php');
        exit();
    }

    header('Location: login.php?error=expired_token');
    exit();
} catch (Exception $e) {
    header('Location: login.php?error=server_error');
    exit();
}
