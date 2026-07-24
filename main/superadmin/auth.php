<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

function require_superadmin() {
    if (!isSessionValid() || !isset($_SESSION['superadmin_id'])) {
        header('Location: login.php');
        exit();
    }
}
?>


