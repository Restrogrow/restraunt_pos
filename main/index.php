<?php
// Include secure session configuration
require_once __DIR__ . '/config/session_config.php';
startSecureSession();

// Check if user is logged in (admin has user_id, staff has staff_id) and session is valid
if (isSessionValid() && (isset($_SESSION['user_id']) || isset($_SESSION['staff_id']) || isset($_SESSION['branch_admin_id'])) && isset($_SESSION['username']) && isset($_SESSION['restaurant_id'])) {
    // User is logged in, redirect to appropriate dashboard based on role
    $role = $_SESSION['role'] ?? 'Admin';
    
    // If staff member, check their role
    if (isset($_SESSION['staff_id']) && isset($_SESSION['role'])) {
        switch ($_SESSION['role']) {
            case 'Waiter':
                header('Location: views/waiter_dashboard.php');
                exit();
            case 'Chef':
                header('Location: views/chef_dashboard.php');
                exit();
            case 'Manager':
                header('Location: views/manager_dashboard.php');
                exit();
            case 'Admin':
            default:
                header('Location: views/dashboard.php');
                exit();
        }
    } else {
        // Admin user (from users table)
        header('Location: views/dashboard.php');
        exit();
    }
} else {
    // User is not logged in, redirect to frontend landing page
    header('Location: ../index.html');
    exit();
}
?>

