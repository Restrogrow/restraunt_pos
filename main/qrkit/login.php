<?php
/**
 * Sign in to the QR kit orders dashboard (main/qrkit/index.php). Its own logins (qr_kit_users, made in
 * Super Admin › QR kit orders): they open only this dashboard, nothing else in RestroGrow.
 * 5 wrong tries per IP + username in 15 minutes, then a wait.
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/qr_kit.php';
require_once __DIR__ . '/../config/rate_limit.php';
header('X-Robots-Tag: noindex');
header('X-Frame-Options: DENY');

$conn = getConnection();
if (qkSessionUser($conn)) { header('Location: index.php'); exit; }
if (empty($_SESSION['qrkit_csrf'])) $_SESSION['qrkit_csrf'] = bin2hex(random_bytes(16));
$error = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $key = 'qrkit_login_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '_' . $username;
    if (!hash_equals($_SESSION['qrkit_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'The page was open too long. Please try again.';
    } elseif (!checkRateLimit($key, 5, 900)['allowed']) {
        $error = 'Too many tries. Please wait 15 minutes.';
    } elseif ($u = qkUserCheck($conn, $username, (string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['qrkit_user_id'] = (int)$u['id'];
        $_SESSION['qrkit_at'] = time();
        header('Location: index.php');
        exit;
    } else {
        $error = 'Wrong username or password.';
    }
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Sign in · QR kit orders</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; background: #fbf5ee; color: #24130d; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; }
  .card { width: 100%; max-width: 380px; background: #fff; border: 1px solid #eadfd2; border-radius: 18px; padding: 28px 24px; box-shadow: 0 10px 30px rgba(58,22,16,.06); }
  .brand { font-weight: 800; color: #ff5a1f; font-size: 14px; letter-spacing: .02em; }
  h1 { font-size: 22px; margin: 6px 0 4px; } p { color: #8f7f73; font-size: 14px; margin: 0 0 20px; }
  label { display: block; font-size: 13px; font-weight: 600; margin: 0 0 6px; }
  input { width: 100%; padding: 12px 13px; border: 1.5px solid #eadfd2; border-radius: 11px; font-size: 15px; font-family: inherit; margin-bottom: 14px; background: #fff; color: inherit; }
  input:focus { outline: none; border-color: #ff5a1f; }
  button { width: 100%; border: 0; border-radius: 11px; padding: 13px; font-size: 15px; font-weight: 700; background: #24130d; color: #fff; cursor: pointer; font-family: inherit; }
  .err { background: #fdecea; color: #a1271b; border-radius: 10px; padding: 10px 12px; font-size: 14px; margin-bottom: 14px; }
</style>
</head>
<body>
<form class="card" method="post" autocomplete="on">
  <div class="brand">RestroGrow</div>
  <h1>QR kit orders</h1>
  <p>Sign in to see and handle Smart QR Menu orders.</p>
  <?php if ($error): ?><div class="err" role="alert"><?php echo $h($error); ?></div><?php endif; ?>
  <input type="hidden" name="csrf" value="<?php echo $h($_SESSION['qrkit_csrf']); ?>">
  <label for="u">Username</label>
  <input id="u" name="username" autocomplete="username" required value="<?php echo $h($username); ?>" autofocus>
  <label for="p">Password</label>
  <input id="p" name="password" type="password" autocomplete="current-password" required>
  <button type="submit">Sign in</button>
</form>
</body>
</html>
