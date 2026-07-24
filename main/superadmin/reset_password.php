<?php
require_once __DIR__ . '/../db_connection.php';

// Load rate limiter for auth endpoints
if (file_exists(__DIR__ . '/../config/rate_limit.php')) {
    require_once __DIR__ . '/../config/rate_limit.php';
}

// Get connection using getConnection() for lazy connection support
if (function_exists('getConnection')) {
    $conn = getConnection();
} else {
    // Fallback to $pdo if getConnection() doesn't exist (backward compatibility)
    global $pdo;
    $conn = $pdo ?? null;
    if (!$conn) {
        die('Database connection not available');
    }
}

$error = '';
$success = false;
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $error = 'Invalid reset token';
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Apply rate limiting: 5 reset password attempts per 15 minutes
        if (function_exists('applyAuthRateLimit')) {
            applyAuthRateLimit('resetPwd');
        }
        
        $newPassword = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($newPassword)) {
            $error = 'Password is required';
        } elseif (strlen($newPassword) < 6) {
            $error = 'Password must be at least 6 characters long';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match';
        } else {
            try {
                // Verify token
                $stmt = $conn->prepare('SELECT * FROM super_admins WHERE reset_token = :token AND reset_token_expires > NOW() AND is_active = 1 LIMIT 1');
                $stmt->execute([':token' => $token]);
                $superadmin = $stmt->fetch();
                
                if ($superadmin) {
                    // Update password
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $updateStmt = $conn->prepare('UPDATE super_admins SET password_hash = :hash, reset_token = NULL, reset_token_expires = NULL WHERE id = :id');
                    $updateStmt->execute([
                        ':hash' => $hashedPassword,
                        ':id' => $superadmin['id']
                    ]);
                    
                    $success = true;
                } else {
                    $error = 'Invalid or expired reset token. Please request a new password reset.';
                }
            } catch (Exception $e) {
                $error = 'An error occurred. Please try again later.';
                error_log('Superadmin password reset error: ' . $e->getMessage());
            }
        }
    } else {
        // Verify token exists and is valid (GET request)
        try {
            $stmt = $conn->prepare('SELECT * FROM super_admins WHERE reset_token = :token AND reset_token_expires > NOW() AND is_active = 1 LIMIT 1');
            $stmt->execute([':token' => $token]);
            $superadmin = $stmt->fetch();
            
            if (!$superadmin) {
                $error = 'Invalid or expired reset token. Please request a new password reset.';
            }
        } catch (Exception $e) {
            $error = 'An error occurred. Please try again later.';
            error_log('Superadmin password reset token verification error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password - Superadmin</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap">
  <style>
    body { margin:0; font-family: Inter, system-ui, Arial; background:#f4f6fb; min-height:100vh; display:grid; place-items:center; }
    .card { background:#fff; width:100%; max-width:400px; padding:24px; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.08); }
    h1 { margin:0 0 16px; font-size:1.4rem; }
    .form-group { margin-bottom:12px; }
    label { font-weight:600; display:block; margin-bottom:6px; }
    input { width:100%; padding:12px; border:2px solid #e5e7eb; border-radius:8px; font-size:1rem; }
    input:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.15); }
    .btn { width:100%; padding:12px; border:none; background:#151A2D; color:#fff; border-radius:8px; font-weight:700; cursor:pointer; }
    .error { color:#dc2626; margin:8px 0; font-weight:600; }
    .success { color:#10b981; margin:8px 0; font-weight:600; }
    .back-link { margin-top:16px;text-align:center; }
    .back-link a { color:#3b82f6;text-decoration:none;font-size:0.9rem; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Reset Password</h1>
    <?php if($error): ?>
      <div class="error"><?php echo htmlspecialchars($error); ?></div>
      <div class="back-link">
        <a href="forgot_password.php">Request New Reset Link</a>
      </div>
    <?php elseif($success): ?>
      <div class="success">Password has been reset successfully! You can now login with your new password.</div>
      <div class="back-link">
        <a href="login.php">Go to Login</a>
      </div>
    <?php else: ?>
      <form method="post">
        <div class="form-group">
          <label for="password">New Password</label>
          <input type="password" id="password" name="password" required minlength="6" autofocus>
        </div>
        <div class="form-group">
          <label for="confirm_password">Confirm New Password</label>
          <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
        </div>
        <button class="btn" type="submit">Reset Password</button>
      </form>
      <div class="back-link">
        <a href="login.php">← Back to Login</a>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>

