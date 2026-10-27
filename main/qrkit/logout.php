<?php
/** Sign out of the QR kit orders dashboard (only its own session keys; other RestroGrow logins in this browser stay). */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
unset($_SESSION['qrkit_user_id'], $_SESSION['qrkit_at']);
header('Location: login.php');
