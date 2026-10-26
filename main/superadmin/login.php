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

require_once __DIR__ . '/../config/google_login.php';
$googleReady = googleLoginReady();

// came back from "Continue with Google" with a problem: google_finish.php leaves it in the session;
// the Google callback puts it in the URL, so only its own fixed messages are shown (no text from a link)
$error = (string)($_SESSION['sa_login_error'] ?? '');
// a wrong password comes back here with a GET (below), keeping the username typed
$lastUser = (string)($_SESSION['sa_login_user'] ?? '');
unset($_SESSION['sa_login_error'], $_SESSION['sa_login_user']);
if ($error === '' && isset($_GET['google_error'])) {
    $known = ['That sign-in link expired. Please try again.', 'Google sign-in did not finish. Please try again.', 'Google sign-in failed. Please try again.',
        'Google could not confirm the sign-in', 'Google sign-in was for a different app', 'Unexpected sign-in issuer', 'Google sign-in expired, please try again',
        'Google sign-in could not be matched, please try again', 'Your Google account has no verified email'];
    $error = in_array($_GET['google_error'], $known, true) ? $_GET['google_error'] : 'Google sign-in did not work. Please try again.';
}
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
                clearFailedAttempts(getAuthRateLimitIdentifier(), $username);
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
                trackFailedAttempt(getAuthRateLimitIdentifier(), $username);
            }
            $error = 'Invalid credentials';
        }
    } catch (Exception $e) {
        // never show a database error on the login page
        error_log('[superadmin login] ' . $e->getMessage());
        $error = 'Something went wrong. Try again in a minute.';
    }
    // back to a GET, so a refresh doesn't send the password again
    $_SESSION['sa_login_error'] = $error;
    $_SESSION['sa_login_user'] = mb_substr($username, 0, 100);
    header('Location: login.php', true, 303);
    exit();
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
    .side { position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; gap: 32px; padding: 40px clamp(32px, 4.2vw, 64px); color: #e2e8f0;
      background: radial-gradient(640px 420px at 100% 0, rgba(255, 90, 31, .22), transparent 65%), radial-gradient(560px 460px at 0 100%, rgba(99, 102, 241, .14), transparent 65%), #0a0e18; }
    .side::after { content: ''; position: absolute; inset: 0; pointer-events: none;
      background-image: radial-gradient(rgba(255,255,255,.09) 1px, transparent 1px); background-size: 22px 22px;
      mask-image: radial-gradient(ellipse 70% 60% at 70% 60%, #000, transparent 75%); -webkit-mask-image: radial-gradient(ellipse 70% 60% at 70% 60%, #000, transparent 75%); }
    .side > * { position: relative; z-index: 1; }
    .top { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
    .brand { position: relative; display: block; width: 168px; aspect-ratio: 600 / 106; }
    .brand i { position: absolute; inset: 0; }
    .brand .ink { -webkit-mask: url("../assets/loaders/logo-ink.png") center / 100% 100% no-repeat; mask: url("../assets/loaders/logo-ink.png") center / 100% 100% no-repeat; background: #fff; }
    .brand .g { -webkit-mask: url("../assets/loaders/logo-g.png") center / 100% 100% no-repeat; mask: url("../assets/loaders/logo-g.png") center / 100% 100% no-repeat; background: var(--brand); }
    .tag { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 999px; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1); font-size: 11.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #cbd5e1; }
    .tag i { width: 7px; height: 7px; border-radius: 50%; background: #4ade80; box-shadow: 0 0 0 4px rgba(74,222,128,.18); }
    .side h1 { margin: 0 0 14px; font-size: clamp(32px, 3.3vw, 48px); line-height: 1.04; letter-spacing: -.03em; font-weight: 800; color: #fff; }
    .side h1 em { display: block; font-style: normal; background: linear-gradient(90deg, #ff5a1f, #ff9a5c); -webkit-background-clip: text; background-clip: text; color: transparent; }
    .side p.lead { margin: 0; max-width: 44ch; color: #94a3b8; font-size: 15.5px; line-height: 1.6; text-wrap: pretty; }

    /* console preview: three small UI cards (illustration, no real data) */
    .mid { display: flex; flex-direction: column; gap: 40px; }
    .bento { display: grid; grid-template-columns: 1.1fr 1fr; gap: 14px; max-width: 580px; }
    .ui { padding: 16px; border-radius: 18px; background: linear-gradient(180deg, rgba(255,255,255,.065), rgba(255,255,255,.025)); border: 1px solid rgba(255,255,255,.09);
      box-shadow: 0 30px 60px -30px rgba(0,0,0,.9), inset 0 1px 0 rgba(255,255,255,.06); backdrop-filter: blur(6px); animation: rise .6s cubic-bezier(.2,.8,.2,1) both; }
    .ui.access { grid-row: span 2; display: flex; flex-direction: column; }
    .ui.plans { animation-delay: .08s; }
    .ui.health { animation-delay: .16s; }
    @keyframes rise { from { opacity: 0; transform: translateY(14px); } }
    .ui-h { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; color: #94a3b8; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
    .ui-h svg { width: 15px; height: 15px; color: var(--brand); }
    .shop { display: flex; align-items: center; gap: 11px; padding-bottom: 12px; margin-bottom: 4px; border-bottom: 1px solid rgba(255,255,255,.07); }
    .shop .av { flex: none; width: 36px; height: 36px; border-radius: 10px; display: grid; place-items: center; font-size: 13px; font-weight: 800; color: #fff; background: linear-gradient(135deg, #ff5a1f, #f59e0b); }
    .shop b { display: block; color: #fff; font-size: 14px; }
    .shop small { color: #64748b; font-size: 12px; }
    .sw { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 0; color: #cbd5e1; font-size: 13px; font-weight: 500; }
    .sw i { flex: none; position: relative; width: 32px; height: 18px; border-radius: 99px; background: rgba(255,255,255,.12); }
    .sw i::after { content: ''; position: absolute; top: 2px; left: 2px; width: 14px; height: 14px; border-radius: 50%; background: #94a3b8; }
    .sw.on i { background: var(--brand); }
    .sw.on i::after { left: 16px; background: #fff; }
    .sw.off { color: #64748b; }
    .ui-note { margin-top: auto; padding-top: 10px; color: #64748b; font-size: 11.5px; }
    .pl { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 6px 0; color: #cbd5e1; font-size: 13px; }
    .pill { padding: 3px 9px; border-radius: 99px; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .pill.g { color: #4ade80; background: rgba(74,222,128,.12); }
    .pill.a { color: #fbbf24; background: rgba(251,191,36,.12); }
    .pill.b { color: #93c5fd; background: rgba(147,197,253,.12); }
    .ok { display: flex; align-items: center; gap: 8px; color: #e2e8f0; font-size: 13px; font-weight: 600; }
    .ok::before { content: ''; width: 8px; height: 8px; border-radius: 50%; background: #4ade80; box-shadow: 0 0 0 4px rgba(74,222,128,.15); }
    .bars { display: flex; align-items: flex-end; gap: 3px; height: 26px; margin-top: 12px; }
    .bars i { flex: 1; height: 100%; border-radius: 2px; background: #22c55e; opacity: .75; }
    .bars i.w { background: #f59e0b; height: 70%; }
    .bottom { display: flex; justify-content: space-between; gap: 16px; color: #64748b; font-size: 12.5px; }
    @media (max-height: 760px) { .mid { gap: 28px; } .bento { align-items: start; } .ui.health { display: none; } .ui.access { grid-row: auto; } .ui.access .x, .ui-note { display: none; } }
    @media (prefers-reduced-motion: reduce) { .ui { animation: none; } }

    /* right: the form */
    .main { display: flex; align-items: center; justify-content: center; padding: 40px 24px; }
    .card { width: 100%; max-width: 410px; }
    .logo { display: none; height: 30px; width: auto; margin-bottom: 34px; }
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
    .or { display: flex; align-items: center; gap: 12px; margin: 20px 0; color: var(--muted); font-size: 12.5px; font-weight: 600; }
    .or::before, .or::after { content: ''; flex: 1; height: 1px; background: var(--line); }
    .btn-google { display: flex; align-items: center; justify-content: center; gap: 12px; height: 50px; border: 1.5px solid var(--line); border-radius: 12px; background: #fff; color: var(--ink); font-size: 15px; font-weight: 600; text-decoration: none; transition: background .15s, border-color .15s, box-shadow .15s; }
    .btn-google:hover { background: #f8fafc; border-color: #cbd5e1; box-shadow: 0 2px 8px rgba(15, 23, 42, .06); }
    .btn-google:focus-visible { outline: 3px solid rgba(255, 90, 31, .45); outline-offset: 2px; }
    .btn-google svg { width: 20px; height: 20px; flex: none; }
    .foot { margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--line); display: flex; align-items: center; gap: 8px; color: var(--muted); font-size: 12.5px; }
    .foot svg { width: 15px; height: 15px; flex: none; }

    @media (max-width: 900px) {
      .wrap { grid-template-columns: 1fr; }
      .side { display: none; }
      .main { min-height: 100vh; padding: 32px 16px; }
      .logo { display: block; margin-bottom: 28px; }
    }
    @media (prefers-reduced-motion: reduce) { .btn .spin { animation-duration: 1.5s; } }
  </style>
</head>
<body>
  <div class="wrap">
    <aside class="side" aria-hidden="true">
      <div class="top">
        <span class="brand"><i class="ink"></i><i class="g"></i></span>
        <span class="tag"><i></i>Team console</span>
      </div>
      <div class="mid">
      <div class="hero">
        <h1>Every restaurant, <em>one place.</em></h1>
        <p class="lead">Plans, payments, access and support for every RestroGrow restaurant. For the RestroGrow team only.</p>
      </div>

      <div class="bento">
        <div class="ui access">
          <div class="ui-h"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h10"/><path d="M18 6h2"/><circle cx="16" cy="6" r="2"/><path d="M4 12h4"/><path d="M12 12h8"/><circle cx="10" cy="12" r="2"/><path d="M4 18h12"/><circle cx="18" cy="18" r="2"/></svg>Access</div>
          <div class="shop"><span class="av">SG</span><div><b>Spice Garden</b><small>Indiranagar</small></div></div>
          <div class="sw on">POS<i></i></div>
          <div class="sw on">Loyalty &amp; Growth<i></i></div>
          <div class="sw on">Online orders<i></i></div>
          <div class="sw off x">WhatsApp AI<i></i></div>
          <div class="ui-note">Switch features per restaurant.</div>
        </div>
        <div class="ui plans">
          <div class="ui-h"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/></svg>Plans</div>
          <div class="pl">Free trial<span class="pill a">6 days left</span></div>
          <div class="pl">Renewal<span class="pill b">Link sent</span></div>
          <div class="pl">Payment<span class="pill g">Paid</span></div>
        </div>
        <div class="ui health">
          <div class="ui-h"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7l8-4z"/><path d="M9 12l2 2 4-4"/></svg>Health</div>
          <div class="ok">All systems normal</div>
          <div class="bars"><?php for ($i = 0; $i < 24; $i++) echo $i === 17 ? '<i class="w"></i>' : '<i></i>'; ?></div>
        </div>
      </div>
      </div>
      <div class="bottom"><span>&copy; <?php echo date('Y'); ?> RestroGrow</span><span>Team access only</span></div>
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
                   value="<?php echo $h($lastUser); ?>"<?php echo $lastUser === '' ? ' autofocus' : ''; ?>>
          </div>

          <label for="password">Password</label>
          <div class="field">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
            <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="Your password"<?php echo $lastUser === '' ? '' : ' autofocus'; ?>>
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

        <?php if ($googleReady): ?>
        <div class="or"><span>or</span></div>
        <a class="btn-google" href="../api/google_login.php?for=superadmin">
          <svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 13 4 4 13 4 24s9 20 20 20 20-9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
          <span>Continue with Google</span>
        </a>
        <?php endif; ?>

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
