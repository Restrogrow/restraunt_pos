<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Check if superadmin is already logged in
if (isSessionValid() && isset($_SESSION['superadmin_id']) && isset($_SESSION['superadmin_username'])) {
    // Superadmin is already logged in, redirect to dashboard
    header('Location: dashboard.php');
    exit();
}

require_once __DIR__ . '/../db_connection.php';

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

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Apply rate limiting: 5 login attempts per 60 seconds
    if (function_exists('applyAuthRateLimit')) {
        applyAuthRateLimit('login');
    }
    
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    try {
        $stmt = $conn->prepare('SELECT * FROM super_admins WHERE username = :u AND is_active = 1 LIMIT 1');
        $stmt->execute([':u' => $username]);
        $sa = $stmt->fetch();
        if ($sa && password_verify($password, $sa['password_hash'])) {
            // Clear failed attempts on success
            if (function_exists('clearFailedAttempts')) {
                clearFailedAttempts(getRateLimitIdentifier());
            }
            $_SESSION['superadmin_id'] = $sa['id'];
            $_SESSION['superadmin_username'] = $sa['username'];
            // Regenerate session ID after successful login for security
            regenerateSessionAfterLogin();
            header('Location: dashboard.php');
            exit();
        } else {
            // Track failed login attempt
            if (function_exists('trackFailedAttempt')) {
                trackFailedAttempt(getRateLimitIdentifier());
            }
            $error = 'Invalid credentials';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Superadmin Login</title>
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
  </style>
  </head>
  <body>
    <div class="card">
      <h1>Superadmin Login</h1>
      <?php if($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
      <form method="post">
        <div class="form-group">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" required>
        </div>
        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required>
        </div>
        <button class="btn" type="submit">Sign In</button>
      </form>
      <div style="margin-top:16px;text-align:center;">
        <a href="forgot_password.php" style="color:#3b82f6;text-decoration:none;font-size:0.9rem;">Forgot Password?</a>
      </div>
    </div>
  </body>
  </html>


