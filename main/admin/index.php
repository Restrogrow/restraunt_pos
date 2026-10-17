<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Check if user is logged in and session is valid
if (!isSessionValid() || !isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['restaurant_id'])) {
    header('Location: login.php');
    exit();
}

// Get the requested page
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// Define allowed pages
$allowedPages = ['dashboard', 'menus', 'menu-items', 'addons', 'settings'];

if (!in_array($page, $allowedPages)) {
    $page = 'dashboard';
}

// the old single-page app (index.html) is gone: the dashboard is views/dashboard.php
header('Location: ../views/dashboard.php');
exit();
?>
