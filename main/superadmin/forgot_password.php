<?php
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/email_config.php';

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

// Load rate limiter for auth endpoints
if (file_exists(__DIR__ . '/../config/rate_limit.php')) {
    require_once __DIR__ . '/../config/rate_limit.php';
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Apply rate limiting: 3 forgot password attempts per 5 minutes
    if (function_exists('applyAuthRateLimit')) {
        applyAuthRateLimit('forgotPwd');
    }
    
    $username = trim($_POST['username'] ?? '');
    
    if (empty($username)) {
        $error = 'Username is required';
    } else {
        try {
            // Find superadmin by username
            $stmt = $conn->prepare('SELECT * FROM super_admins WHERE username = :u AND is_active = 1 LIMIT 1');
            $stmt->execute([':u' => $username]);
            $superadmin = $stmt->fetch();
            
            if ($superadmin) {
                // Generate reset token
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes')); // Token expires in 5 minutes
                
                // Store token in database (we'll use a simple approach - store in super_admins table temporarily)
                // Or create a separate table for superadmin reset tokens
                // For now, let's create a simple reset_token column if it doesn't exist
                try {
                    $conn->exec("ALTER TABLE super_admins ADD COLUMN reset_token VARCHAR(64) NULL");
                    $conn->exec("ALTER TABLE super_admins ADD COLUMN reset_token_expires DATETIME NULL");
                } catch (PDOException $e) {
                    // Columns might already exist, ignore
                }
                
                // Store token
                $updateStmt = $conn->prepare('UPDATE super_admins SET reset_token = :token, reset_token_expires = :expires WHERE id = :id');
                $updateStmt->execute([
                    ':token' => $token,
                    ':expires' => $expiresAt,
                    ':id' => $superadmin['id']
                ]);
                
                // Generate reset link
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $resetLink = $protocol . '://' . $host . dirname($_SERVER['PHP_SELF']) . '/reset_password.php?token=' . $token;
                
                // Send email to sujaysarraf55@gmail.com
                $to = 'sujaysarraf55@gmail.com';
                $subject = 'Superadmin Password Reset Request - RestroGrow';
                $emailMessage = "
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #151A2D; color: white; padding: 20px; text-align: center; }
                        .content { padding: 20px; background: #f9f9f9; }
                        .button { display: inline-block; padding: 12px 24px; background: #151A2D; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                        .footer { padding: 20px; text-align: center; color: #666; font-size: 12px; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h2>Superadmin Password Reset Request</h2>
                        </div>
                        <div class='content'>
                            <p>Hello,</p>
                            <p>A password reset request has been made for the superadmin account: <strong>" . htmlspecialchars($superadmin['username']) . "</strong></p>
                            <p>Click the button below to reset your password:</p>
                            <p style='text-align: center;'>
                                <a href='" . $resetLink . "' class='button'>Reset Password</a>
                            </p>
                            <p>Or copy and paste this link into your browser:</p>
                            <p style='word-break: break-all; color: #666;'>" . $resetLink . "</p>
                            <p><strong>This link will expire in 5 minutes.</strong></p>
                            <p>If you did not request a password reset, please ignore this email. Your password will remain unchanged.</p>
                        </div>
                        <div class='footer'>
                            <p>This is an automated message from RestroGrow POS System.</p>
                        </div>
                    </div>
                </body>
                </html>
                ";
                
                // Send email
                $emailResult = sendEmail($to, $subject, $emailMessage);
                
                if (is_array($emailResult) && $emailResult['success']) {
                    $message = 'Password reset link has been sent to sujaysarraf55@gmail.com. Please check your email.';
                } else {
                    $error = 'Failed to send email. Please try again later or contact support.';
                    error_log('Superadmin password reset email failed: ' . ($emailResult['error'] ?? 'Unknown error'));
                }
            } else {
                // Don't reveal if username exists (security)
                $message = 'If the username exists, a password reset link has been sent to sujaysarraf55@gmail.com.';
            }
        } catch (Exception $e) {
            $error = 'An error occurred. Please try again later.';
            error_log('Superadmin password reset error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password - Superadmin</title>
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
    <h1>Forgot Password</h1>
    <?php if($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if($message): ?><div class="success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <form method="post">
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required autofocus>
      </div>
      <button class="btn" type="submit">Send Reset Link</button>
    </form>
    <div class="back-link">
      <a href="login.php">← Back to Login</a>
    </div>
  </div>
</body>
</html>

