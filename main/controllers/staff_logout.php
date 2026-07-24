<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Use secure session destruction
destroySession();

// Redirect to login page (correct path from controllers/ folder)
header('Location: ../admin/login.php');
exit();
?>

