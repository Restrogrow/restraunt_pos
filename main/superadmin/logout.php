<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
destroySession();
header('Location: login.php');
exit();
?>


