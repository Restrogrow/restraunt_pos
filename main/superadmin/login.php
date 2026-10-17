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
        // never show a database error on the login page
        error_log('[superadmin login] ' . $e->getMessage());
        $error = 'Something went wrong. Try again in a minute.';
    }
}
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
<?php require_once __DIR__ . '/../config/sentry_browser.php'; echo rgSentryBrowserTag('superadmin-login'); ?>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Sign in · RestroGrow Super Admin</title>
  <link rel="icon" href="../assets/images/logo-192.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
  <style>
    :root { --ink: #0f172a; --soft: #475569; --muted: #94a3b8; --line: #e2e8f0; --bg: #f5f7fb; --brand: #ff5a1f; --brand-dark: #e64a12; --navy: #0b1220; --danger: #dc2626; }
    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body { margin: 0; font-family: Inter, system-ui, -apple-system, 'Segoe UI', sans-serif; background: var(--bg); color: var(--ink); -webkit-font-smoothing: antialiased; }
    .wrap { min-height: 100vh; display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr); }

    /* left: brand panel */
    .side { position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; padding: 44px 52px; color: #e2e8f0;
      background: radial-gradient(900px 500px at 110% -10%, rgba(255, 90, 31, .28), transparent 60%), radial-gradient(700px 500px at -20% 120%, rgba(59, 130, 246, .18), transparent 60%), linear-gradient(160deg, #111827, var(--navy)); }
    .side::after { content: ''; position: absolute; inset: 0; pointer-events: none; opacity: .5;
      background-image: linear-gradient(rgba(255,255,255,.04) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.04) 1px, transparent 1px); background-size: 44px 44px;
      mask-image: radial-gradient(ellipse at 30% 40%, #000 20%, transparent 75%); -webkit-mask-image: radial-gradient(ellipse at 30% 40%, #000 20%, transparent 75%); }
    .side > * { position: relative; z-index: 1; }
    .tag { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 999px; background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12); font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #fed7aa; }
    .tag i { width: 7px; height: 7px; border-radius: 50%; background: #4ade80; box-shadow: 0 0 0 4px rgba(74,222,128,.18); }
    .side h1 { margin: 26px 0 14px; font-size: clamp(30px, 3.2vw, 44px); line-height: 1.08; letter-spacing: -.02em; font-weight: 800; color: #fff; max-width: 14ch; }
    .side h1 em { font-style: normal; color: var(--brand); }
    .side p.lead { margin: 0; max-width: 42ch; color: #94a3b8; font-size: 15.5px; line-height: 1.6; }
    .points { list-style: none; padding: 0; margin: 34px 0 0; display: grid; gap: 12px; max-width: 420px; }
    .points li { display: flex; gap: 14px; align-items: flex-start; padding: 14px 16px; border-radius: 16px; background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.08); backdrop-filter: blur(4px); }
    .points svg { flex: none; width: 22px; height: 22px; color: var(--brand); margin-top: 1px; }
    .points b { display: block; color: #fff; font-size: 14.5px; font-weight: 700; }
    .points span { display: block; color: #94a3b8; font-size: 13px; margin-top: 2px; line-height: 1.45; }
    .side small { color: #64748b; font-size: 12.5px; }

    /* right: the form */
    .main { display: flex; align-items: center; justify-content: center; padding: 40px 24px; }
    .card { width: 100%; max-width: 410px; }
    .logo { display: block; height: 30px; width: auto; margin-bottom: 34px; }
    .card h2 { margin: 0; font-size: 26px; font-weight: 800; letter-spacing: -.01em; }
    .card .sub { margin: 8px 0 26px; color: var(--soft); font-size: 14.5px; }
    .err { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; margin-bottom: 18px; border-radius: 12px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; font-size: 14px; font-weight: 600; }
    .err svg { flex: none; width: 18px; height: 18px; margin-top: 1px; }
    label { display: block; font-size: 13.5px; font-weight: 600; color: var(--ink); margin-bottom: 7px; }
    .field { position: relative; margin-bottom: 16px; }
    .field > svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; color: var(--muted); pointer-events: none; transition: color .15s; }
    .field input { width: 100%; height: 50px; padding: 0 46px 0 44px; border: 1.5px solid var(--line); border-radius: 12px; background: #fff; font: inherit; font-size: 15px; color: var(--ink); transition: border-color .15s, box-shadow .15s; }
    .field input::placeholder { color: #b6c0ce; }
    .field input:hover { border-color: #cbd5e1; }
    .field input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 4px rgba(255, 90, 31, .14); }
    .field:focus-within > svg { color: var(--brand); }
    .eye { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); width: 38px; height: 38px; border: 0; border-radius: 10px; background: none; color: var(--muted); cursor: pointer; display: grid; place-items: center; }
    .eye:hover { background: #f1f5f9; color: var(--soft); }
    .eye:focus-visible { outline: 2px solid var(--brand); outline-offset: 1px; }
    .eye svg { width: 19px; height: 19px; }
    .eye svg[hidden] { display: none; }
    .caps { display: none; margin: -8px 0 14px; font-size: 12.5px; font-weight: 600; color: #b45309; }
    .caps.on { display: block; }
    .row { display: flex; justify-content: flex-end; margin: -4px 0 22px; }
    .row a { color: var(--soft); font-size: 13.5px; font-weight: 600; text-decoration: none; }
    .row a:hover { color: var(--brand); }
    .btn { width: 100%; height: 50px; border: 0; border-radius: 12px; background: var(--ink); color: #fff; font: inherit; font-size: 15px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 10px;
      box-shadow: 0 12px 24px -14px rgba(15, 23, 42, .8); transition: background .15s, transform .05s; }
    .btn:hover { background: #1e293b; }
    .btn:active { transform: translateY(1px); }
    .btn:focus-visible { outline: 3px solid rgba(255, 90, 31, .45); outline-offset: 2px; }
    .btn svg { width: 18px; height: 18px; }
    .btn .spin { display: none; width: 18px; height: 18px; border: 2.5px solid rgba(255,255,255,.35); border-top-color: #fff; border-radius: 50%; animation: spin .7s linear infinite; }
    .btn.busy { pointer-events: none; opacity: .85; }
    .btn.busy .spin { display: inline-block; }
    .btn.busy .arrow { display: none; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .foot { margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--line); display: flex; align-items: center; gap: 8px; color: var(--muted); font-size: 12.5px; }
    .foot svg { width: 15px; height: 15px; flex: none; }

    @media (max-width: 900px) {
      .wrap { grid-template-columns: 1fr; }
      .side { display: none; }
      .main { min-height: 100vh; padding: 32px 16px; }
      .logo { margin-bottom: 28px; }
    }
    @media (prefers-reduced-motion: reduce) { .btn .spin { animation-duration: 1.5s; } }
  </style>
</head>
<body>
  <div class="wrap">
    <aside class="side" aria-hidden="true">
      <div>
        <span class="tag"><i></i>Team console</span>
        <h1>Every restaurant, <em>one place.</em></h1>
        <p class="lead">Plans, payments, access and support for every RestroGrow restaurant. For the RestroGrow team only.</p>
        <ul class="points">
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l1-5h16l1 5"/><path d="M4 9v11h16V9"/><path d="M3 9h18"/><path d="M9 20v-6h6v6"/></svg>
            <div><b>Restaurants and plans</b><span>Trials, renewals, payment links and invoices.</span></div>
          </li>
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h10"/><path d="M18 6h2"/><circle cx="16" cy="6" r="2"/><path d="M4 12h4"/><path d="M12 12h8"/><circle cx="10" cy="12" r="2"/><path d="M4 18h12"/><path d="M20 18h0"/><circle cx="18" cy="18" r="2"/></svg>
            <div><b>Access per restaurant</b><span>Switch POS, Loyalty &amp; Growth and more on or off.</span></div>
          </li>
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7l8-4z"/><path d="M9 12l2 2 4-4"/></svg>
            <div><b>Security and health</b><span>Logins, activity, errors and backups.</span></div>
          </li>
        </ul>
      </div>
      <small>&copy; <?php echo date('Y'); ?> RestroGrow</small>
    </aside>

    <main class="main">
      <div class="card">
        <img class="logo" src="../assets/images/logo-transparent.png" alt="RestroGrow" width="163" height="30">
        <h2>Sign in</h2>
        <p class="sub">Super Admin · team access only</p>

        <?php if ($error): ?>
        <div class="err" role="alert">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
          <span><?php echo $error === 'Invalid credentials' ? 'Wrong username or password. Try again.' : $h($error); ?></span>
        </div>
        <?php endif; ?>

        <form method="post" id="loginForm" novalidate>
          <label for="username">Username</label>
          <div class="field">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            <input type="text" id="username" name="username" required autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="Your username"
                   value="<?php echo $h($_POST['username'] ?? ''); ?>"<?php echo empty($_POST['username']) ? ' autofocus' : ''; ?>>
          </div>

          <label for="password">Password</label>
          <div class="field">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
            <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="Your password"<?php echo empty($_POST['username']) ? '' : ' autofocus'; ?>>
            <button type="button" class="eye" id="togglePw" aria-label="Show password" aria-pressed="false">
              <svg id="eyeOpen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg id="eyeShut" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" hidden><path d="M3 3l18 18"/><path d="M10.6 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.1M6.2 6.2A17 17 0 0 0 2 12s3.5 7 10 7a9.8 9.8 0 0 0 5.8-1.8"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
            </button>
          </div>
          <div class="caps" id="capsHint" role="status">Caps Lock is on</div>

          <div class="row"><a href="forgot_password.php">Forgot password?</a></div>

          <button class="btn" type="submit" id="submitBtn">
            <span class="spin" aria-hidden="true"></span>
            <span class="label">Sign in</span>
            <svg class="arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>
          </button>
        </form>

        <div class="foot">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
          Private page for the RestroGrow team.
        </div>
      </div>
    </main>
  </div>

  <script>
    (function () {
      var pw = document.getElementById('password'), btn = document.getElementById('togglePw');
      btn.addEventListener('click', function () {
        var show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        document.getElementById('eyeOpen').hidden = show;
        document.getElementById('eyeShut').hidden = !show;
        pw.focus();
      });
      var caps = document.getElementById('capsHint');
      ['keydown', 'keyup'].forEach(function (ev) {
        pw.addEventListener(ev, function (e) { if (e.getModifierState) caps.classList.toggle('on', e.getModifierState('CapsLock')); });
      });
      pw.addEventListener('blur', function () { caps.classList.remove('on'); });
      document.getElementById('loginForm').addEventListener('submit', function (e) {
        var u = document.getElementById('username');
        if (!u.value.trim()) { e.preventDefault(); u.focus(); return; }
        if (!pw.value) { e.preventDefault(); pw.focus(); return; }
        var b = document.getElementById('submitBtn');
        b.classList.add('busy');
        b.querySelector('.label').textContent = 'Signing in…';
      });
    })();
  </script>
</body>
</html>
