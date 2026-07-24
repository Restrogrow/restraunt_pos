<?php
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

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
