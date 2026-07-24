<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Check if user is logged in and session is valid
if (isSessionValid() && isset($_SESSION['user_id']) && isset($_SESSION['username']) && isset($_SESSION['restaurant_id'])) {
    // User is logged in, redirect to main dashboard
    header('Location: dashboard.php');
    exit();
} else {
    // User is not logged in, redirect to login page
    header('Location: ../admin/login.php');
    exit();
}
?>
