<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/countries.php';
require_once __DIR__ . '/../config/google_login.php';
startSecureSession();
$googleReady = googleLoginReady();
// one free-trial length everywhere (Super Admin › Settings › trial days; config/onboarding.php)
$trialDays = 7;
try { require_once __DIR__ . '/../db_connection.php'; require_once __DIR__ . '/../config/onboarding.php'; $trialDays = obTrialDays(getConnection()); } catch (Throwable $e) {}
$openSignup = ($_GET['tab'] ?? '') === 'signup';
// a private sign-up link from Super Admin (restrogrow.com/join/<code>, config/signup_invites.php):
// details filled in, no WhatsApp code, maybe its own trial length
$signupInvite = null; $inviteError = '';
if (isset($_GET['invite'])) {
    $openSignup = true;
    try {
        require_once __DIR__ . '/../config/signup_invites.php';
        $inv = inviteFind(getConnection(), strtolower(trim((string)$_GET['invite'])));
        if (!$inv) $inviteError = 'This sign-up link is not valid. You can still sign up below.';
        elseif (($why = inviteProblem($inv)) !== '') $inviteError = $why . ' Ask RestroGrow for a new one, or sign up below.';
        else {
            $signupInvite = ['code' => $inv['code'], 'restaurant_name' => (string)$inv['restaurant_name'], 'phone' => (string)$inv['phone'],
                             'email' => (string)$inv['email'], 'country' => (string)$inv['country'],
                             'business_type' => (string)$inv['business_type'], 'city' => (string)$inv['city']];
            if ((int)$inv['trial_days'] > 0) $trialDays = min(365, (int)$inv['trial_days']);
        }
    } catch (Throwable $e) { error_log('login invite: ' . $e->getMessage()); $inviteError = 'Could not open this sign-up link. Please reload the page.'; }
}
// came back from Google with no account yet (admin/google_finish.php): sign-up without the WhatsApp code
$gp = $_SESSION['google_owner_pending'] ?? null;
$googleSignup = is_array($gp) && (int)($gp['exp'] ?? 0) > time() ? ['email' => $gp['email'], 'name' => $gp['name']] : null;
$googleButton = $googleReady ? '<div class="or-divider"><span>or</span></div>'
    . '<a class="btn-google" href="../api/google_login.php?for=owner">'
    . '<svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 13 4 4 13 4 24s9 20 20 20 20-9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>'
    . '<span>Continue with Google</span></a>' : '';
// logging in to connect ChatGPT / Claude (oauth/authorize.php): back to its "Allow" screen afterwards
$mcpNext = preg_match('/^[a-f0-9]{32}$/', (string)($_GET['oauth'] ?? '')) ? '../oauth/authorize.php?req=' . $_GET['oauth'] : '';
if ($mcpNext !== '') $_SESSION['mcp_next'] = $_GET['oauth'];
else unset($_SESSION['mcp_next']);

// Check if user is already logged in and session is valid
if (isSessionValid() && (isset($_SESSION['user_id']) || isset($_SESSION['staff_id']) || isset($_SESSION['branch_admin_id'])) && isset($_SESSION['username']) && isset($_SESSION['restaurant_id'])) {
    // User is already logged in, redirect to appropriate dashboard
    if (isset($_SESSION['staff_id']) && isset($_SESSION['role'])) {
        $role = $_SESSION['role'];
        switch ($role) {
            case 'Waiter':
                header('Location: ../views/waiter_dashboard.php');
                exit();
            case 'Chef':
            case 'Bartender':
                header('Location: ../views/chef_dashboard.php');
                exit();
            case 'Manager':
                header('Location: ../views/manager_dashboard.php');
                exit();
            default:
                header('Location: ../views/dashboard.php');
                exit();
        }
    } else {
        // Admin user
        header('Location: ' . ($mcpNext !== '' && ($_SESSION['user_type'] ?? '') === 'admin' ? $mcpNext : '../views/dashboard.php'));
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
  <script src="../assets/js/quiet-console.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/quiet-console.js'); ?>"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Restro Grow | Restaurant Management System</title>
    <meta name="description" content="Login to your Restro Grow admin dashboard. Access your restaurant POS system, manage orders, staff, tables, and view real-time analytics.">
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="https://restrogrow.com/main/admin/login.php">
    <link rel="icon" type="image/png" href="../assets/images/logo-192.png">
    <link rel="apple-touch-icon" href="../assets/images/logo-192.png">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#ff6b35">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="<?php require_once __DIR__ . '/../config/icon_font.php'; echo htmlspecialchars(rgIconFontUrl(), ENT_QUOTES); ?>">
    <style>
        * {
            box-sizing: border-box;
        }
        
        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            font-family: "Poppins", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f5f5f0;
            position: relative;
        }

        .login-wrapper {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            width: 100%;
            max-width: 1200px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 32px;
            align-items: center;
        }
        
        /* Left side - Logo and heading */
        .login-left {
            text-align: center;
            padding-top: 40px;
        }
        
        .logo-section {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 24px;
        }
        
        .logo-img {
            height: 36px;
            width: auto;
            object-fit: contain;
            filter: none;
        }
        
        .logo-text {
            color: #1f2937;
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        
        .login-heading {
            color: #1f2937;
            font-size: 2rem;
            font-weight: 700;
            margin: 0 0 10px 0;
            line-height: 1.3;
        }
        
        .login-tagline {
            color: #6b7280;
            font-size: 0.95rem;
            margin: 0;
            font-weight: 400;
        }
        
        /* Right side - Form card */
        .login-right {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100%;
        }
        
        .auth-container {
            background: white;
            border-radius: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            padding: 28px;
            width: 100%;
            max-width: 420px;
            position: relative;
            overflow: hidden;
        }
        
        /* Decorative gradient element */
        .auth-container::before {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 200px;
            height: 200px;
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
            border-radius: 50%;
            opacity: 0.08;
            z-index: 0;
        }
        
        .auth-header {
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }
        
        .auth-header h1 {
            color: #1f2937;
            margin: 0 0 4px 0;
            font-size: 1.85rem;
            font-weight: 700;
        }
        
        .auth-header p {
            color: #6b7280;
            margin: 0;
            font-size: 0.9rem;
            font-weight: 400;
        }
        
        .form-group {
            margin-bottom: 16px;
            position: relative;
            z-index: 1;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #9ca3af;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .input-icon {
            position: absolute;
            left: 16px;
            width: 20px;
            height: 20px;
            color: #9ca3af;
            z-index: 2;
            pointer-events: none;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 14px 12px 48px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: white;
            color: #1f2937;
            appearance: none;
            -webkit-appearance: none;
        }

        .form-group input.pw-field {
            padding-right: 44px;
        }

        .password-toggle {
            position: absolute;
            right: 14px;
            width: 20px;
            height: 20px;
            padding: 0;
            border: none;
            background: none;
            color: #9ca3af;
            cursor: pointer;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .password-toggle:hover {
            color: #6b7280;
        }

        .password-toggle svg {
            width: 20px;
            height: 20px;
        }

        .form-group select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            background-size: 18px;
            padding-right: 40px;
        }

        .form-group input::placeholder {
            color: #9ca3af;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #ff6b35;
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1);
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            margin-bottom: 16px;
            position: relative;
            z-index: 1;
        }
        
        .forgot-password-link {
            color: #ff6b35;
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: color 0.3s ease;
        }
        
        .forgot-password-link:hover {
            color: #f7931e;
        }
        
        .btn {
            width: 100%;
            padding: 14px 24px;
            border: none;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            position: relative;
            z-index: 1;
        }
        
        .btn:hover {
            background: linear-gradient(135deg, #f7931e 0%, #ff6b35 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4);
        }
        
        .btn-icon {
            width: 20px;
            height: 20px;
            fill: white;
        }
        
        .btn:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
        }
        
        .signup-link {
            text-align: center;
            margin-top: 16px;
            color: #6b7280;
            font-size: 0.85rem;
            position: relative;
            z-index: 1;
        }
        
        .signup-link a {
            color: #ff6b35;
            text-decoration: none;
            font-weight: 600;
            margin-left: 4px;
        }
        
        .signup-link a:hover {
            color: #f7931e;
        }
        
        .message {
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        
        .message.success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .message.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .message.info {
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }
        
        .demo-credentials {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            padding: 12px;
            margin-top: 20px;
            font-size: 0.8rem;
        }
        
        .demo-credentials h4 {
            margin: 0 0 8px 0;
            color: #1e40af;
            font-size: 0.9rem;
        }
        
        .demo-credentials p {
            margin: 4px 0;
            color: #1e40af;
        }
        
        /* Signup form styling */
        .auth-form {
            display: none;
        }
        
        .auth-form.active {
            display: block;
        }
        
        /* Forgot Password Modal */
        .forgot-password-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        
        .forgot-password-modal.active {
            display: flex;
        }
        
        .forgot-password-content {
            background: white;
            border-radius: 24px;
            padding: 32px;
            width: 90%;
            max-width: 450px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            position: relative;
        }
        
        .forgot-password-content h2 {
            margin: 0 0 12px 0;
            color: #151A2D;
            font-size: 1.5rem;
        }
        
        .forgot-password-content p {
            color: #666;
            margin: 0 0 24px 0;
            font-size: 0.95rem;
        }
        
        .close-modal {
            position: absolute;
            top: 20px;
            right: 20px;
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .close-modal:hover {
            color: #151A2D;
            background: #f3f4f6;
        }
        
        .or-divider { display: flex; align-items: center; gap: 12px; margin: 18px 0 14px; color: #9ca3af; font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; }
        .or-divider::before, .or-divider::after { content: ""; flex: 1; height: 1px; background: #e5e7eb; }
        .btn-google { display: flex; align-items: center; justify-content: center; gap: 12px; width: 100%; padding: 13px 16px; border: 1.5px solid #dadce0; border-radius: 12px; background: #fff; color: #1f2937; font-weight: 600; font-size: 15px; text-decoration: none; transition: background .15s, box-shadow .15s, border-color .15s; }
        .btn-google:hover { background: #f8fafc; border-color: #c4c7cc; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
        .btn-google svg { width: 20px; height: 20px; flex: none; }
        .btn-outline {
            background: #f5f5f5;
            color: #333;
            border: 2px solid #e0e0e0;
            margin-top: 12px;
        }
        
        .btn-outline:hover {
            background: #e5e7eb;
        }
        
        /* Desktop layout */
        @media (min-width: 768px) {
            .login-wrapper {
                padding: 32px;
            }
            
            .login-container {
                grid-template-columns: 1fr 1fr;
                gap: 48px;
            }
            
            .login-left {
                text-align: left;
                padding-top: 0;
            }
            
            .logo-section {
                justify-content: flex-start;
                margin-bottom: 16px;
            }
            
            .login-heading {
                font-size: 2rem;
                margin-bottom: 6px;
            }
            
            .login-tagline {
                font-size: 0.95rem;
            }
            
            .login-right {
                padding-top: 0;
            }
            
            .auth-container {
                padding: 32px;
                max-width: 420px;
            }
        }
        
        @media (min-width: 1024px) {
            .login-heading {
                font-size: 2.15rem;
            }
        }
        
        /* Footer */
        .login-footer {
            position: fixed;
            bottom: 20px;
            left: 24px;
            color: #9ca3af;
            font-size: 0.85rem;
            z-index: 1;
        }
        
        @media (max-width: 767px) {
            .login-footer {
                display: none;
            }
            
            .login-wrapper {
                padding: 16px;
                align-items: center;
            }
            
            .login-container {
                gap: 0;
                width: 100%;
            }
            
            .login-left {
                display: none;
            }
            
            .login-right {
                width: 100%;
            }
            
            .auth-container {
                padding: 32px 24px;
                max-width: 100%;
                width: 100%;
                border-radius: 20px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            }
            
            .auth-container::before {
                width: 150px;
                height: 150px;
                top: -30px;
                right: -30px;
            }
            
            .auth-header {
                margin-bottom: 24px;
                text-align: center;
            }
            
            .auth-header h1 {
                font-size: 1.75rem;
                margin-bottom: 6px;
            }
            
            .auth-header p {
                font-size: 0.9rem;
            }
            
            .form-group {
                margin-bottom: 18px;
            }
            
            .form-group label {
                font-size: 0.9rem;
                margin-bottom: 6px;
            }
            
            .form-group input,
            .form-group select {
                padding: 13px 16px 13px 48px;
                font-size: 1rem;
            }

            .form-group select {
                padding-right: 40px;
            }

            .form-group input.pw-field {
                padding-right: 46px;
            }

            .input-icon {
                left: 16px;
                width: 18px;
                height: 18px;
            }
            
            .form-actions {
                margin-bottom: 20px;
            }
            
            .signup-link {
                margin-top: 20px;
                font-size: 0.9rem;
            }
            
            .demo-credentials {
                display: none;
            }
        }

        /* Notification prompt modal */
        .notif-prompt-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        .notif-prompt-modal.active {
            display: flex;
        }
        .notif-prompt-content {
            background: white;
            border-radius: 24px;
            padding: 32px 28px;
            width: 90%;
            max-width: 380px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            position: relative;
            text-align: center;
        }
        .notif-icon {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 36px;
            color: white;
        }
        .notif-prompt-content h2 {
            margin: 0 0 8px 0;
            color: #1f2937;
            font-size: 1.35rem;
            font-weight: 700;
        }
        .notif-prompt-content p {
            color: #6b7280;
            margin: 0 0 24px 0;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        .notif-btn-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .notif-btn {
            width: 100%;
            padding: 14px 24px;
            border: none;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .notif-btn-primary {
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
            color: white;
        }
        .notif-btn-primary:hover {
            background: linear-gradient(135deg, #f7931e 0%, #ff6b35 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4);
        }
        .notif-btn-primary:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .notif-btn-skip {
            background: #f5f5f5;
            color: #6b7280;
        }
        .notif-btn-skip:hover {
            background: #e5e7eb;
        }
        .notif-features {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 20px;
            text-align: left;
        }
        .notif-feature {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            color: #4b5563;
        }
        .notif-feature i {
            color: #10b981;
            font-size: 1rem;
            width: 20px;
            text-align: center;
        }
        .notif-learn-more {
            font-size: 0.75rem;
            color: #9ca3af;
            margin-top: 4px;
        }
        /* 3-step sign-up */
        .form-group label { text-transform: none; letter-spacing: 0; font-size: 13.5px; }
        .su-steps { display: flex; list-style: none; padding: 0; margin: 0 0 22px; gap: 6px; counter-reset: s; }
        .su-steps li { flex: 1; display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 12px; background: #f6f6f4; color: #9ca3af; font-size: 13px; font-weight: 600; transition: background .2s, color .2s; }
        .su-steps li i { width: 24px; height: 24px; flex: none; border-radius: 50%; display: grid; place-items: center; font-style: normal; font-size: 12px; font-weight: 700; background: #e5e7eb; color: #6b7280; transition: background .2s, color .2s; }
        .su-steps li.on { background: #fff3ec; color: #c2410c; }
        .su-steps li.on i { background: linear-gradient(135deg, #ff6b35, #f7931e); color: #fff; }
        .su-steps li.done { color: #15803d; }
        .su-steps li.done i { background: #dcfce7; color: #15803d; font-size: 0; }
        .su-steps li.done i::before { content: '✓'; font-size: 13px; }
        .su-step { display: none; }
        .su-step.on { display: block; animation: suIn .28s ease both; }
        @keyframes suIn { from { opacity: 0; transform: translateX(12px); } to { opacity: 1; transform: none; } }
        .su-note { margin: 12px 0 0; font-size: 13px; color: #6b7280; text-align: center; }
        .su-note a { color: #ea580c; font-weight: 600; }
        .su-hint { display: block; margin-top: 6px; color: #9ca3af; font-size: 12.5px; }
        .su-chips { display: flex; flex-wrap: wrap; gap: 8px; }
        .su-chip { position: relative; cursor: pointer; }
        .su-chip input { position: absolute; opacity: 0; pointer-events: none; }
        .su-chip span { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: 999px; border: 1.5px solid #e5e7eb; background: #fff; font-size: 13.5px; font-weight: 600; color: #374151; transition: all .15s; }
        .su-chip:hover span { border-color: #fdba74; }
        .su-chip input:checked + span { border-color: #ff6b35; background: #fff3ec; color: #c2410c; }
        .su-chip input:focus-visible + span { outline: 3px solid #fdba74; outline-offset: 2px; }
        .su-row { display: flex; align-items: center; gap: 12px; margin-top: 4px; }
        .su-row .btn-primary { flex: 1; margin: 0; }
        .su-back { flex: none; border: 0; background: none; color: #6b7280; font: inherit; font-size: 14px; font-weight: 600; cursor: pointer; padding: 12px 6px; }
        .su-back:hover { color: #ea580c; }
        .su-terms { margin: 14px 0 0; font-size: 12px; color: #9ca3af; text-align: center; }
        .su-terms a { color: #6b7280; }
        .su-demo { display: block; margin-top: 8px; }
        .su-demo a { color: #6b7280; font-weight: 500; }
        .su-perks { list-style: none; padding: 0; margin: 22px 0 0; display: grid; gap: 10px; }
        .su-perks li { display: flex; gap: 10px; align-items: flex-start; font-size: 15px; color: #374151; }
        .su-perks b { width: 22px; height: 22px; flex: none; border-radius: 50%; display: grid; place-items: center; background: #dcfce7; color: #15803d; font-size: 12px; }
        @media (max-width: 480px) { .su-steps li span { display: none; } .su-steps li { justify-content: center; } }
    </style>
<?php if ($openSignup || $googleSignup): ?>
    <!-- the sign-up page counts in Website analytics, and links the new restaurant to where it came from (config/site_visitors.php) -->
    <script src="../../assets/rg-analytics.js" defer></script>
<?php endif; ?>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-container">
            <!-- Left Side - Logo and Heading -->
            <div class="login-left">
                <div class="logo-section">
                    <img src="../assets/images/logo-transparent.png" alt="Restro Grow Logo" class="logo-img">
                </div>
                <h1 class="login-heading" id="leftHeading">Log in to your account</h1>
                <p class="login-tagline" id="leftTagline">Let us make your restaurant grow!</p>
                <ul class="su-perks" id="suPerks" hidden>
                    <li><b>✓</b> <?php echo (int)$trialDays; ?> days free, no card needed</li>
                    <li><b>✓</b> POS, kitchen tickets, online orders and your own website</li>
                    <li><b>✓</b> Ready in about 10 minutes, we guide you step by step</li>
                </ul>
            </div>
            
            <!-- Right Side - Form Card -->
            <div class="login-right">
                <div class="auth-container">
                    <div class="auth-header">
                        <h1 id="authHeaderTitle">Login</h1>
                        <p id="authHeaderSubtitle">Please sign in to continue.</p>
                    </div>
                    
                    <!-- Login Form -->
                    <form id="loginForm" class="auth-form active">
                        <div class="form-group">
                            <label for="loginUsername">Username, email or phone</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                </svg>
                                <input type="text" id="loginUsername" name="username" required autocomplete="username" placeholder="Username, email or phone number">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="loginPassword">Password</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                </svg>
                                <input type="password" id="loginPassword" name="password" required autocomplete="current-password" placeholder="Your password" class="pw-field">
                                <button type="button" class="password-toggle" aria-label="Show password" onclick="togglePasswordVisibility('loginPassword', this)">
                                    <svg class="pw-icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    <svg class="pw-icon-eye-off" style="display:none;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-7-11-7a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                                </button>
                            </div>
                        </div>
                        <div class="form-group" id="loginOtpGroup" style="display:none;">
                            <label for="loginOtpCode">Code from WhatsApp</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <input type="text" id="loginOtpCode" name="otp_code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="6-digit code from WhatsApp">
                            </div>
                            <small id="loginOtpHint" style="color:#9ca3af;display:block;margin-top:6px;">
                                New device detected. We sent a code via WhatsApp.
                                <a href="#" id="loginOtpResend" onclick="event.preventDefault();">Resend code</a>
                            </small>
                        </div>
                        <div class="form-actions">
                            <a href="#" id="forgotPasswordLink" class="forgot-password-link">FORGOT</a>
                        </div>
                        <button type="submit" class="btn btn-primary" id="loginBtn">
                            <span id="loginBtnText">LOGIN</span>
                            <svg class="btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </button>
                        <?php echo $googleButton; ?>
                        <div class="signup-link">
                            Don't have an account? <a href="#" onclick="event.preventDefault(); switchTab('signup');">Sign up</a>
                        </div>
                    </form>
                    
                    <!-- Signup Form -->
                    <form id="signupForm" class="auth-form" novalidate>
                        <!-- 3 steps: phone + WhatsApp code, the restaurant, the login. Old fields keep their ids. -->
                        <ol class="su-steps" aria-label="Sign-up steps">
                            <li class="on" data-s="1"><i>1</i><span>Phone</span></li>
                            <li data-s="2"><i>2</i><span>Restaurant</span></li>
                            <li data-s="3"><i>3</i><span>Login</span></li>
                        </ol>

                        <div class="su-step on" data-step="1">
<div class="form-group">
                            <label for="signupCountry">Country</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <select id="signupCountry" name="country" required>
                                    <option value="">Select your country</option>
                                    <?php foreach (getCountryData() as $iso2 => $c): ?>
                                    <option value="<?php echo htmlspecialchars($c['name']); ?>" data-dial-code="<?php echo htmlspecialchars($c['dial_code']); ?>" data-phone-min="<?php echo (int)$c['phone_min']; ?>" data-phone-max="<?php echo (int)$c['phone_max']; ?>" <?php echo $iso2 === 'IN' ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?> (<?php echo htmlspecialchars($c['dial_code']); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
<div class="form-group">
                            <label for="signupPhone">Phone number</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                </svg>
                                <input type="tel" id="signupPhone" name="phone" required autocomplete="tel" placeholder="Enter your phone number">
                            </div>
                            <small id="signupPhoneHint" style="color:#9ca3af;display:block;margin-top:6px;"></small>
                        </div>
<div class="form-group" id="signupOtpGroup" style="display:none;">
                            <label for="signupOtpCode">Code from WhatsApp</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <input type="text" id="signupOtpCode" name="otp_code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="6-digit code from WhatsApp">
                            </div>
                            <small id="signupOtpHint" style="color:#9ca3af;display:block;margin-top:6px;">
                                We sent a 6-digit code to your phone on WhatsApp.
                                <a href="#" id="signupOtpResend" onclick="event.preventDefault();">Resend code</a>
                            </small>
                        </div>
                            <button type="button" class="btn btn-primary" id="suNext1">
                                <span id="suNext1Text">Send code on WhatsApp</span>
                                <svg class="btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </button>
                            <p class="su-note" id="suNote1">We send a 6-digit code on WhatsApp to check the number is yours.</p>
                            <?php echo $googleButton; ?>
                        </div>

                        <div class="su-step" data-step="2">
<div class="form-group">
                            <label for="restaurantName">Restaurant name</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                </svg>
                                <input type="text" id="restaurantName" name="restaurant_name" required placeholder="Enter your restaurant name">
                            </div>
                        </div>
                            <div class="form-group">
                                <label>Type of place</label>
                                <div class="su-chips"><label class="su-chip"><input type="radio" name="business_type" value="restaurant" checked><span>🍽️ Restaurant</span></label><label class="su-chip"><input type="radio" name="business_type" value="cafe"><span>☕ Café</span></label><label class="su-chip"><input type="radio" name="business_type" value="dhaba"><span>🛖 Dhaba</span></label><label class="su-chip"><input type="radio" name="business_type" value="cloud_kitchen"><span>🛵 Cloud kitchen</span></label><label class="su-chip"><input type="radio" name="business_type" value="bakery"><span>🥐 Bakery / sweets</span></label><label class="su-chip"><input type="radio" name="business_type" value="bar"><span>🍸 Bar / lounge</span></label><label class="su-chip"><input type="radio" name="business_type" value="qsr"><span>🍔 Fast food</span></label><label class="su-chip"><input type="radio" name="business_type" value="other"><span>✨ Something else</span></label></div>
                                <small class="su-hint">We set up your dashboard to suit it. You can change everything later.</small>
                            </div>
                            <div class="form-group">
                                <label for="signupCity">City</label>
                                <div class="input-wrapper">
                                    <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.66 16.66L13.41 20.9a2 2 0 01-2.83 0l-4.24-4.24a8 8 0 1111.32 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                    <input type="text" id="signupCity" name="city" autocomplete="address-level2" placeholder="e.g. Bengaluru" maxlength="80">
                                </div>
                            </div>
                            <div class="su-row">
                                <button type="button" class="su-back" data-su-back="1">← Back</button>
                                <button type="button" class="btn btn-primary" id="suNext2"><span>Continue</span><svg class="btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></button>
                            </div>
                        </div>

                        <div class="su-step" data-step="3">
<div class="form-group">
                            <label for="signupEmail">Email</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                </svg>
                                <input type="email" id="signupEmail" name="email" required autocomplete="email" placeholder="you@example.com">
                            </div>
                            <small class="su-hint">For your bills, receipts and password reset.</small>
                        </div>
<div class="form-group">
                            <label for="signupPassword">Password</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                </svg>
                                <input type="password" id="signupPassword" name="password" required minlength="6" autocomplete="new-password" placeholder="At least 6 characters" class="pw-field">
                                <button type="button" class="password-toggle" aria-label="Show password" onclick="togglePasswordVisibility('signupPassword', this)">
                                    <svg class="pw-icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    <svg class="pw-icon-eye-off" style="display:none;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-7-11-7a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                                </button>
                            </div>
                        </div>
                            <p class="su-note">You log in with this email or your phone number and the password.</p>
                            <div class="su-row">
                                <button type="button" class="su-back" data-su-back="2">← Back</button>
                                <button type="submit" class="btn btn-primary" id="signupBtn"><span id="signupBtnText">Create my restaurant</span><svg class="btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></button>
                            </div>
                            <p class="su-terms">By creating an account you agree to the <a href="../../terms-of-service/" target="_blank" rel="noopener">terms</a> and <a href="../../privacy-policy/" target="_blank" rel="noopener">privacy policy</a>.</p>
                        </div>

                        <div class="signup-link">
                            Already have an account? <a href="#" onclick="event.preventDefault(); switchTab('login');">Sign in</a>
                            <span class="su-demo"><a href="../../demo/">Not ready? Try the demo first</a></span>
                        </div>
                    </form>
                    
                    <div class="demo-credentials" id="loginHelpBox">
                        <h4>How to log in</h4>
                        <p><strong>Owner:</strong> your username, email or phone number</p>
                        <p><strong>Staff:</strong> Use your email or phone number</p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="login-footer">
            © <?php echo date('Y'); ?> RestroGrow. All rights reserved.
        </div>
    </div>
    
    <!-- Notification Prompt Modal -->
    <div id="notifPromptModal" class="notif-prompt-modal">
        <div class="notif-prompt-content">
            <div class="notif-icon">🔔</div>
            <h2>Stay Updated!</h2>
            <p>Get instant notifications when new orders arrive, order status changes, and more.</p>
            <div class="notif-features">
                <div class="notif-feature"><span>📦</span> New order alerts</div>
                <div class="notif-feature"><span>✅</span> Order status updates</div>
                <div class="notif-feature"><span>⚡</span> Real-time KOT alerts</div>
            </div>
            <div class="notif-btn-group">
                <button class="notif-btn notif-btn-primary" id="enableNotifBtn" onclick="enableNotifications()">
                    🔔 Enable Notifications
                </button>
                <button class="notif-btn notif-btn-skip" id="skipNotifBtn" onclick="skipNotifications()">
                    Not Now
                </button>
            </div>
            <div class="notif-learn-more">You can always enable later from your browser settings.</div>
        </div>
    </div>

    <!-- Forgot Password Modal -->
    <div id="forgotPasswordModal" class="forgot-password-modal">
        <div class="forgot-password-content">
            <button class="close-modal" onclick="closeForgotPasswordModal()">&times;</button>
            <h2>Forgot Password</h2>
            <p id="forgotModalDesc">Enter your restaurant email address and we'll send you a password reset link.</p>
            <form id="forgotPasswordForm">
                <div class="form-group">
                    <label for="forgotEmail">Email Address:</label>
                    <input type="email" id="forgotEmail" name="email" required placeholder="Enter your restaurant email">
                </div>
                <div class="form-group" id="forgotOtpGroup" style="display:none;">
                    <label for="forgotOtpCode">Verification Code:</label>
                    <input type="text" id="forgotOtpCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="6-digit code from WhatsApp">
                    <small style="color:#9ca3af;display:block;margin-top:6px;">
                        <a href="#" id="forgotOtpResend" onclick="event.preventDefault();">Resend code</a>
                    </small>
                </div>
                <div class="form-group" id="forgotNewPasswordGroup" style="display:none;">
                    <label for="forgotNewPassword">New Password:</label>
                    <input type="password" id="forgotNewPassword" placeholder="At least 6 characters">
                </div>
                <div class="form-group" id="forgotConfirmPasswordGroup" style="display:none;">
                    <label for="forgotConfirmPassword">Confirm New Password:</label>
                    <input type="password" id="forgotConfirmPassword" placeholder="Re-enter new password">
                </div>
                <button type="submit" class="btn btn-primary" id="forgotPasswordBtn">Send Reset Link</button>
                <button type="button" class="btn btn-outline" onclick="closeForgotPasswordModal()" style="background: #f5f5f5; color: #333; border: 2px solid #e0e0e0;">Cancel</button>
            </form>
            <div style="text-align:center;margin-top:14px;">
                <a href="#" id="forgotModeToggle" onclick="event.preventDefault(); toggleForgotMode();" style="color:#ff6b35;font-weight:600;font-size:0.85rem;text-decoration:none;">Reset via WhatsApp instead</a>
            </div>
        </div>
    </div>

    <script>
        // Register PWA service worker for TWA support
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js').catch(function(err) {
                console.warn('[PWA] SW registration failed:', err);
            });
        }

        // Subscribe to push notifications
        async function subscribeToPush() {
            try {
                var registration = await navigator.serviceWorker.ready;
                var subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(((await (await fetch('../api/get_vapid_key.php', { cache: 'no-store' })).json()).publicKey || ''))
                });
                // Send subscription to server
                await fetch('../api/save_push_subscription.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(subscription.toJSON ? subscription.toJSON() : subscription)
                });
            } catch(e) {
                // User denied permission or push not supported
                console.log('[PWA] Push subscription skipped:', e.message);
            }
        }

        function urlBase64ToUint8Array(base64String) {
            var padding = '='.repeat((4 - base64String.length % 4) % 4);
            var base64 = (base64String + padding).replace(/\\-/g, '+').replace(/_/g, '/');
            var rawData = window.atob(base64);
            var outputArray = new Uint8Array(rawData.length);
            for (var i = 0; i < rawData.length; ++i) {
                outputArray[i] = rawData.charCodeAt(i);
            }
            return outputArray;
        }

        // Notification prompt after login
        var _pendingRedirectUrl = '';

        // Once the user has answered the prompt (Enable or Not Now) on this
        // device, never show it again — remembered per browser in localStorage.
        var NOTIF_PROMPT_KEY = 'rg_notif_prompt_answered';
        function notifPromptAnswered() {
            try { return localStorage.getItem(NOTIF_PROMPT_KEY) === '1'; } catch (e) { return false; }
        }
        function markNotifPromptAnswered() {
            try { localStorage.setItem(NOTIF_PROMPT_KEY, '1'); } catch (e) {}
        }

        function showNotificationPrompt(redirectUrl) {
            _pendingRedirectUrl = redirectUrl;
            // Check if PushManager is supported and permission not already granted
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                // Push not supported, just redirect
                doRedirect(redirectUrl);
                return;
            }
            // If already granted, subscribe silently and redirect
            if (Notification.permission === 'granted') {
                subscribeToPush().then(function() {
                    doRedirect(redirectUrl);
                }).catch(function() {
                    doRedirect(redirectUrl);
                });
                return;
            }
            // If previously denied, skip the prompt (can't re-ask via browser)
            if (Notification.permission === 'denied') {
                doRedirect(redirectUrl);
                return;
            }
            // Already answered on this device — don't ask again
            if (notifPromptAnswered()) {
                doRedirect(redirectUrl);
                return;
            }
            // Show the prompt modal
            document.getElementById('notifPromptModal').classList.add('active');
        }

        function enableNotifications() {
            var btn = document.getElementById('enableNotifBtn');
            btn.disabled = true;
            btn.textContent = 'Setting up...';
            markNotifPromptAnswered();
            // Ask for the browser permission explicitly first, so it gets
            // recorded even if the push subscription itself fails (e.g. no
            // VAPID key configured).
            Promise.resolve(Notification.requestPermission()).then(function(perm) {
                return perm === 'granted' ? subscribeToPush() : null;
            }).then(function() {
                doRedirect(_pendingRedirectUrl);
            }).catch(function() {
                // Even if push fails, still redirect
                doRedirect(_pendingRedirectUrl);
            });
        }

        function skipNotifications() {
            markNotifPromptAnswered();
            doRedirect(_pendingRedirectUrl);
        }

        function doRedirect(url) {
            document.getElementById('notifPromptModal').classList.remove('active');
            window.location.href = url;
        }

        // Show/hide password toggle (login, signup password, confirm password)
        function togglePasswordVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            btn.querySelector('.pw-icon-eye').style.display = showing ? '' : 'none';
            btn.querySelector('.pw-icon-eye-off').style.display = showing ? 'none' : '';
            btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        }

        function switchTab(tab) {
            // Update form visibility
            document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
            document.getElementById(tab + 'Form').classList.add('active');

            // Update the shared header — it previously stayed stuck on
            // "Login / Please sign in to continue." even while the signup
            // form was showing.
            if (tab === 'signup') {
                document.getElementById('authHeaderTitle').textContent = 'Start your free trial';
                document.getElementById('authHeaderSubtitle').textContent = TRIAL_DAYS + ' days free · no card needed · takes a minute';
                document.getElementById('leftHeading').textContent = 'Start your free ' + TRIAL_DAYS + '-day trial';
                document.getElementById('leftTagline').textContent = 'Everything your restaurant needs, in one place.';
            } else {
                document.getElementById('authHeaderTitle').textContent = 'Login';
                document.getElementById('authHeaderSubtitle').textContent = 'Please sign in to continue.';
                document.getElementById('leftHeading').textContent = 'Log in to your account';
                document.getElementById('leftTagline').textContent = 'Let us make your restaurant grow!';
            }
            document.getElementById('suPerks').hidden = tab !== 'signup';
            var helpBox = document.getElementById('loginHelpBox');
            if (helpBox) helpBox.style.display = tab === 'signup' ? 'none' : '';
            if (tab === 'signup' && typeof suTrack === 'function') suTrack('opened');

            // Clear messages
            const messages = document.querySelectorAll('.message');
            messages.forEach(msg => msg.remove());

            // Abandoning the signup form (its OTP, if any, expires server-side
            // in 5 minutes anyway) — reset so a later visit starts clean.
            if (tab === 'login' && typeof resetSignupOtpState === 'function') {
                resetSignupOtpState();
            }
            if (tab === 'signup' && typeof resetLoginOtpState === 'function') {
                resetLoginOtpState();
            }
        }
        
        // A random id generated once per browser and kept in localStorage —
        // this is what the server's new-device WhatsApp OTP gate
        // (device_trust_helpers.php) keys trust to. Clearing site data or
        // logging in from a different browser/device means a new id, so the
        // OTP step runs again exactly once there.
        function getAdminDeviceId() {
            var KEY = 'rg_admin_device_id';
            try {
                var id = localStorage.getItem(KEY);
                if (!id) {
                    id = 'dev_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10) + Math.random().toString(36).slice(2, 10);
                    localStorage.setItem(KEY, id);
                }
                return id;
            } catch (e) {
                return 'dev_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
            }
        }

        // Login is normally one step. If this device hasn't logged into
        // this account before, auth.php instead responds with
        // requires_otp=true (and sends a WhatsApp code) — the form then
        // locks username/password and asks for that code, resubmitting the
        // same credentials plus otp_code. Once verified, this device is
        // trusted forever for that account.
        let loginOtpSent = false;
        let loginResendTimer = null;

        function resetLoginOtpState() {
            loginOtpSent = false;
            document.getElementById('loginOtpGroup').style.display = 'none';
            document.getElementById('loginOtpCode').value = '';
            document.getElementById('loginUsername').disabled = false;
            document.getElementById('loginPassword').disabled = false;
            document.getElementById('loginBtnText').textContent = 'LOGIN';
            if (loginResendTimer) { clearInterval(loginResendTimer); loginResendTimer = null; }
            const resend = document.getElementById('loginOtpResend');
            resend.style.pointerEvents = 'auto';
            resend.textContent = 'Resend code';
        }

        function startLoginResendCooldown(seconds) {
            const resend = document.getElementById('loginOtpResend');
            let remaining = seconds;
            resend.style.pointerEvents = 'none';
            resend.textContent = `Resend code (${remaining}s)`;
            if (loginResendTimer) clearInterval(loginResendTimer);
            loginResendTimer = setInterval(() => {
                remaining -= 1;
                if (remaining <= 0) {
                    clearInterval(loginResendTimer);
                    loginResendTimer = null;
                    resend.style.pointerEvents = 'auto';
                    resend.textContent = 'Resend code';
                } else {
                    resend.textContent = `Resend code (${remaining}s)`;
                }
            }, 1000);
        }

        async function submitLogin(username, password, otpCode) {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 30000);
            let body = `action=login&username=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}&device_id=${encodeURIComponent(getAdminDeviceId())}`;
            if (otpCode) body += `&otp_code=${encodeURIComponent(otpCode)}`;

            const response = await fetch('auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body,
                signal: controller.signal
            });
            clearTimeout(timeoutId);

            const responseText = await response.text();
            if (!responseText || responseText.trim() === '') {
                throw new Error('Incorrect username or password');
            }
            if (responseText.trim().startsWith('<!DOCTYPE') || responseText.trim().startsWith('<html')) {
                console.error('Server returned HTML instead of JSON:', responseText.substring(0, 500));
                throw new Error('Incorrect username or password');
            }
            try {
                return JSON.parse(responseText);
            } catch (parseError) {
                console.error('JSON Parse Error:', parseError, responseText.substring(0, 500));
                throw new Error('Incorrect username or password');
            }
        }

        // Local copy (XAMPP): WhatsApp isn't used, so the server returns the code —
        // show it on screen and fill it in.
        function showLocalOtp(result, inputId) {
            if (!result || !result.local_code) return false;
            const input = document.getElementById(inputId);
            if (!input) return false;
            input.value = result.local_code;
            let note = document.getElementById(inputId + 'LocalNote');
            if (!note) {
                note = document.createElement('div');
                note.id = inputId + 'LocalNote';
                note.style.cssText = 'margin:8px 0 4px;padding:10px 12px;border-radius:12px;background:#ecfdf5;border:1.5px dashed #10b981;color:#047857;font-size:12.5px;font-weight:600;line-height:1.4;';
                input.closest('.form-group').appendChild(note);
            }
            note.textContent = 'Local mode: WhatsApp is not used on this computer, so the code is shown here and already filled in.';
            const big = document.createElement('div');
            big.style.cssText = 'margin-top:6px;font-size:22px;font-weight:800;letter-spacing:6px;color:#065f46;font-family:monospace;';
            big.textContent = result.local_code;
            note.appendChild(big);
            return true;
        }

        document.getElementById('loginOtpResend').addEventListener('click', async () => {
            const username = document.getElementById('loginUsername').value.trim();
            const password = document.getElementById('loginPassword').value;
            try {
                const result = await submitLogin(username, password, '');
                if (result.requires_otp) {
                    showMessage(result.message || 'A new code has been sent via WhatsApp.', 'success');
                    startLoginResendCooldown(60);
                    showLocalOtp(result, 'loginOtpCode');
                } else if (!result.success) {
                    showMessage(result.message || 'Could not resend the code.', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showMessage('Network error. Please try again.', 'error');
            }
        });

        // Login form submission
        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();

            const username = document.getElementById('loginUsername').value.trim();
            const password = document.getElementById('loginPassword').value;
            const loginBtn = document.getElementById('loginBtn');
            const loginBtnText = document.getElementById('loginBtnText');

            if (!username || !password) {
                showMessage('Please fill in all fields.', 'error');
                return;
            }

            if (loginOtpSent && !document.getElementById('loginOtpCode').value.trim()) {
                showMessage('Enter the code sent to your WhatsApp.', 'error');
                return;
            }

            loginBtn.disabled = true;
            loginBtnText.textContent = loginOtpSent ? 'VERIFYING...' : 'LOGGING IN...';

            try {
                const otpCode = loginOtpSent ? document.getElementById('loginOtpCode').value.trim() : '';
                const result = await submitLogin(username, password, otpCode);

                if (result.requires_otp) {
                    loginOtpSent = true;
                    document.getElementById('loginOtpGroup').style.display = 'block';
                    document.getElementById('loginUsername').disabled = true;
                    document.getElementById('loginPassword').disabled = true;
                    loginBtnText.textContent = 'VERIFY & LOG IN';
                    showMessage(result.message || 'A verification code has been sent via WhatsApp.', 'success');
                    startLoginResendCooldown(60);
                    document.getElementById('loginOtpCode').focus();
                    showLocalOtp(result, 'loginOtpCode');
                    return;
                }

                if (result.success) {
                    showMessage('Login successful!', 'success');
                    var redirectUrl = result.redirect || '../views/dashboard.php';
                    var mcpNext = <?php echo json_encode($mcpNext); ?>;
                    if (mcpNext && result.data && result.data.user_type === 'admin') { window.location.href = mcpNext; return; }
                    try {
                        sessionStorage.setItem('forceDashboard', '1');
                        localStorage.removeItem('admin_active_page');
                    } catch (storageErr) {
                        console.warn('Unable to set dashboard preference', storageErr);
                    }
                    resetLoginOtpState();
                    // Show notification prompt instead of subscribing silently
                    showNotificationPrompt(redirectUrl);
                } else {
                    showMessage(result.message || 'Incorrect username or password', 'error');
                }
            } catch (error) {
                console.error('Login Error:', error);
                let errorMessage = error.message || 'Incorrect username or password';
                if (error.name === 'AbortError') {
                    errorMessage = 'Request timeout. Please try again.';
                } else if (error.message && (error.message.includes('Failed to fetch') || error.message.includes('NetworkError'))) {
                    errorMessage = 'Network error. Please check your internet connection and try again.';
                }
                showMessage(errorMessage, 'error');
            } finally {
                loginBtn.disabled = false;
                loginBtnText.textContent = loginOtpSent ? 'VERIFY & LOG IN' : 'LOGIN';
            }
        });
        
        const TRIAL_DAYS = <?php echo (int)$trialDays; ?>;

        // Show the expected phone digit count for the selected country, and
        // keep it updated as the user changes country.
        function updateSignupPhoneHint() {
            const select = document.getElementById('signupCountry');
            const opt = select.options[select.selectedIndex];
            const hint = document.getElementById('signupPhoneHint');
            if (!opt || !opt.value) { hint.textContent = ''; return; }
            const min = parseInt(opt.dataset.phoneMin, 10);
            const max = parseInt(opt.dataset.phoneMax, 10);
            const digits = min === max ? `${min} digits` : `${min}-${max} digits`;
            hint.textContent = `Your number without ${opt.dataset.dialCode} (${digits}).`;
        }
        document.getElementById('signupCountry').addEventListener('change', updateSignupPhoneHint);
        updateSignupPhoneHint();

        /* ── Sign-up in 3 steps ─────────────────────────────────────────────
           1 phone + WhatsApp code (otp.php, scope 'SIGNUP' / 'owner_signup'; auth.php's handleSignup() checks the
             verified row), or no code when they came back from "Continue with Google"
           2 restaurant name, type of place, city
           3 email + password → account made and signed in → dashboard with the welcome tour
           Each step reached is reported to the Super Admin sign-up funnel (auth.php signupStep). */
        const SU = {
            step: 1, codeSent: false, verified: false, timer: null,
            token: (window.crypto && crypto.randomUUID) ? crypto.randomUUID().replace(/-/g, '') : (Date.now().toString(36) + Math.random().toString(36).slice(2)),
        };
        const suEl = (id) => document.getElementById(id);
        function suTrack(step, extra) {
            if (suTrack.sent && suTrack.sent[step]) return;
            suTrack.sent = suTrack.sent || {}; suTrack.sent[step] = 1;
            const body = new URLSearchParams(Object.assign({ action: 'signupStep', token: SU.token, step: step, source: window.googleSignup ? 'google' : (window.signupInvite ? 'invite' : 'phone') }, extra || {}));
            fetch('auth.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body, keepalive: true }).catch(() => {});
        }
        function suGo(n) {
            SU.step = n;
            document.querySelectorAll('#signupForm .su-step').forEach((el) => el.classList.toggle('on', +el.dataset.step === n));
            document.querySelectorAll('#signupForm .su-steps li').forEach((li) => {
                const k = +li.dataset.s;
                li.classList.toggle('on', k === n);
                li.classList.toggle('done', k < n);
            });
            document.querySelectorAll('.message').forEach((m) => m.remove());
            const first = document.querySelector('#signupForm .su-step.on input:not([type=radio]):not([readonly]):not([disabled])');
            if (first && window.innerWidth > 600) setTimeout(() => first.focus(), 60);
        }
        function suPhone() {
            const sel = suEl('signupCountry'), opt = sel.options[sel.selectedIndex];
            const digits = suEl('signupPhone').value.replace(/\D/g, '');
            const min = parseInt(opt ? opt.dataset.phoneMin : 6, 10), max = parseInt(opt ? opt.dataset.phoneMax : 15, 10);
            return { country: sel.value, digits: digits, ok: !!sel.value && digits.length >= min && digits.length <= max,
                     need: min === max ? `${min} digits` : `${min}-${max} digits` };
        }
        function suLockPhone(lock) {
            suEl('signupCountry').disabled = lock;
            suEl('signupPhone').readOnly = lock;
        }
        function startSignupResendCooldown(seconds) {
            const resend = suEl('signupOtpResend');
            let remaining = seconds;
            resend.style.pointerEvents = 'none';
            resend.textContent = `Resend in ${remaining}s`;
            if (SU.timer) clearInterval(SU.timer);
            SU.timer = setInterval(() => {
                remaining -= 1;
                if (remaining <= 0) { clearInterval(SU.timer); SU.timer = null; resend.style.pointerEvents = 'auto'; resend.textContent = 'Resend code'; }
                else resend.textContent = `Resend in ${remaining}s`;
            }, 1000);
        }
        async function sendSignupOtp(phoneDigits) {
            const response = await fetch('../website/otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                // the country picks the code the WhatsApp goes to (+977 for Nepal, …)
                body: `action=send&restaurant_id=SIGNUP&purpose=owner_signup&phone=${encodeURIComponent(phoneDigits)}&country=${encodeURIComponent(suEl('signupCountry').value)}`,
            });
            return response.json();
        }
        // going back to the login tab: start the code part again next time (the code expires in 5 minutes anyway)
        function resetSignupOtpState() {
            SU.codeSent = false; SU.verified = false;
            suEl('signupOtpGroup').style.display = 'none';
            suEl('signupOtpCode').value = '';
            suEl('suNext1Text').textContent = window.googleSignup || window.signupInvite ? 'Continue' : 'Send code on WhatsApp';
            suLockPhone(false);
            if (SU.timer) { clearInterval(SU.timer); SU.timer = null; }
            const resend = suEl('signupOtpResend');
            resend.style.pointerEvents = 'auto';
            resend.textContent = 'Resend code';
            suGo(1);
        }
        suEl('signupOtpResend').addEventListener('click', async () => {
            try {
                const result = await sendSignupOtp(suPhone().digits);
                if (result.success) { showMessage('A new code has been sent on WhatsApp.', 'success'); startSignupResendCooldown(60); }
                else showMessage(result.message || 'Could not resend the code.', 'error');
            } catch (error) { showMessage('Network error. Please try again.', 'error'); }
        });

        async function suStep1() {
            const ph = suPhone(), btn = suEl('suNext1'), txt = suEl('suNext1Text');
            if (!ph.ok) { showMessage(ph.country ? `Please enter a valid phone number for ${ph.country} (${ph.need}).` : 'Please choose your country.', 'error'); suEl('signupPhone').focus(); return; }
            // came back from Google: Google replaces the WhatsApp code
            // came back from Google, or a private sign-up link: they replace the WhatsApp code
            if (window.googleSignup || window.signupInvite) { SU.verified = true; suTrack('verified', { phone: ph.digits, country: ph.country }); suGo(2); return; }
            if (SU.verified) { suGo(2); return; }
            btn.disabled = true;
            try {
                if (!SU.codeSent) {
                    txt.textContent = 'Sending code…';
                    const result = await sendSignupOtp(ph.digits);
                    if (!result.success) { txt.textContent = 'Send code on WhatsApp'; showMessage(result.message || 'Could not send the verification code.', 'error'); return; }
                    SU.codeSent = true;
                    suLockPhone(true);
                    suEl('signupOtpGroup').style.display = 'block';
                    suEl('suNote1').innerHTML = 'Wrong number? <a href="#" id="suChangeNo">Change it</a>';
                    startSignupResendCooldown(60);
                    txt.textContent = 'Verify code';
                    suTrack('code_sent', { phone: ph.digits, country: ph.country });
                    // this computer (XAMPP): no WhatsApp, the server hands the code back and it's filled in
                    if (result.local_code) {
                        suEl('signupOtpCode').value = result.local_code;
                        showMessage(result.local_note || 'Local mode: the code was filled in for you.', 'info');
                        btn.disabled = false;
                        return suStep1();
                    }
                    showMessage('We sent a code to your WhatsApp.', 'success');
                    suEl('signupOtpCode').focus();
                    return;
                }
                const code = suEl('signupOtpCode').value.trim();
                if (!/^\d{4,8}$/.test(code)) { showMessage('Please enter the code from WhatsApp.', 'error'); suEl('signupOtpCode').focus(); return; }
                txt.textContent = 'Checking…';
                const r = await fetch('../website/otp.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=verify&restaurant_id=SIGNUP&purpose=owner_signup&phone=${encodeURIComponent(ph.digits)}&code=${encodeURIComponent(code)}`,
                });
                const v = await r.json();
                if (!v.success) { txt.textContent = 'Verify code'; showMessage(v.message || 'That code is not right. Please try again.', 'error'); return; }
                SU.verified = true;
                if (SU.timer) { clearInterval(SU.timer); SU.timer = null; }
                suEl('signupOtpGroup').style.display = 'none';
                suEl('suNote1').innerHTML = '✓ Number verified. <a href="#" id="suChangeNo">Use a different number</a>';
                txt.textContent = 'Continue';
                suTrack('verified', { phone: ph.digits, country: ph.country });
                suGo(2);
            } catch (error) {
                showMessage('Network error. Please try again.', 'error');
            } finally {
                btn.disabled = false;
            }
        }
        suEl('suNext1').addEventListener('click', suStep1);
        // the code fills itself in from WhatsApp on many phones: go on as soon as it's complete
        suEl('signupOtpCode').addEventListener('input', (e) => { if (/^\d{6}$/.test(e.target.value.trim()) && SU.codeSent && !SU.verified) suStep1(); });
        document.addEventListener('click', (e) => {
            if (e.target && e.target.id === 'suChangeNo') {
                e.preventDefault();
                SU.codeSent = false; SU.verified = false;
                suLockPhone(false);
                suEl('signupOtpGroup').style.display = 'none';
                suEl('signupOtpCode').value = '';
                suEl('suNext1Text').textContent = 'Send code on WhatsApp';
                suEl('suNote1').textContent = 'We send a 6-digit code on WhatsApp to check the number is yours.';
                suEl('signupPhone').focus();
            }
            const back = e.target.closest && e.target.closest('[data-su-back]');
            if (back) { e.preventDefault(); suGo(+back.dataset.suBack); }
        });

        function suStep2() {
            const name = suEl('restaurantName').value.trim();
            if (name.length < 2) { showMessage('Please enter your restaurant name.', 'error'); suEl('restaurantName').focus(); return; }
            suTrack('details', { restaurant_name: name });
            suGo(3);
        }
        suEl('suNext2').addEventListener('click', suStep2);

        // Enter key: the button of the step that is showing
        document.getElementById('signupForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            if (SU.step === 1) return suStep1();
            if (SU.step === 2) return suStep2();
            if (!SU.verified) { suGo(1); showMessage('Please verify your phone number first.', 'error'); return; }

            const email = suEl('signupEmail').value.trim();
            const password = suEl('signupPassword').value;
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showMessage('Please enter a valid email address.', 'error'); suEl('signupEmail').focus(); return; }
            if (password.length < 6) { showMessage('Your password needs at least 6 characters.', 'error'); suEl('signupPassword').focus(); return; }

            const ph = suPhone();
            const type = document.querySelector('#signupForm input[name=business_type]:checked');
            const btn = suEl('signupBtn'), txt = suEl('signupBtnText');
            btn.disabled = true; txt.textContent = 'Creating your restaurant…';
            try {
                const body = new URLSearchParams({
                    action: 'signup', username: '', email: email, password: password,
                    restaurant_name: suEl('restaurantName').value.trim(), country: ph.country, phone: suEl('signupPhone').value.trim(),
                    business_type: type ? type.value : '', city: suEl('signupCity').value.trim(), signup_token: SU.token,
                    invite_code: window.signupInvite ? window.signupInvite.code : '',
                });
                const response = await fetch('auth.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body });
                const result = await response.json();
                if (result.success) {
                    showMessage('Your restaurant is ready! Opening your dashboard…', 'success');
                    if (result.signed_in === false) { setTimeout(() => { switchTab('login'); suEl('loginUsername').value = email; }, 1200); return; }
                    const to = result.redirect || '../views/dashboard.php';
                    const mcpNext = <?php echo json_encode($mcpNext); ?>;
                    if (mcpNext) { window.location.href = mcpNext; return; }
                    window.location.href = to + (to.indexOf('?') > -1 ? '&' : '?') + 'welcome=1';
                    return;
                }
                showMessage(result.message || 'Sign-up failed. Please try again.', 'error');
                if (/phone|verify/i.test(result.message || '')) { SU.verified = false; resetSignupOtpState(); }
            } catch (error) {
                showMessage('Network error. Please try again.', 'error');
            } finally {
                btn.disabled = false; txt.textContent = 'Create my restaurant';
            }
        });

        function showMessage(message, type) {
            const existingMessage = document.querySelector('.message');
            if (existingMessage) {
                existingMessage.remove();
            }
            
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${type}`;
            messageDiv.textContent = message;
            
            const activeForm = document.querySelector('.auth-form.active');
            if (activeForm) {
                activeForm.insertBefore(messageDiv, activeForm.firstChild);
            }
        }
        
        // Forgot Password functionality
        document.getElementById('forgotPasswordLink').addEventListener('click', (e) => {
            e.preventDefault();
            document.getElementById('forgotPasswordModal').classList.add('active');
        });
        
        // Forgot-password has two independent modes: the original
        // email-link flow (unchanged), and a WhatsApp-OTP flow that resets
        // the password right in this modal (no email round-trip). The OTP
        // flow is itself two steps — send code, then verify code + set new
        // password — same shape as the signup/login OTP steps elsewhere on
        // this page.
        let forgotMode = 'email'; // 'email' | 'otp'
        let forgotOtpSent = false;
        let forgotResendTimer = null;

        function resetForgotModalState() {
            forgotMode = 'email';
            forgotOtpSent = false;
            document.getElementById('forgotModalDesc').textContent = "Enter your restaurant email address and we'll send you a password reset link.";
            document.getElementById('forgotOtpGroup').style.display = 'none';
            document.getElementById('forgotNewPasswordGroup').style.display = 'none';
            document.getElementById('forgotConfirmPasswordGroup').style.display = 'none';
            document.getElementById('forgotEmail').disabled = false;
            document.getElementById('forgotPasswordBtn').textContent = 'Send Reset Link';
            document.getElementById('forgotModeToggle').textContent = 'Reset via WhatsApp instead';
            document.getElementById('forgotModeToggle').style.display = '';
            if (forgotResendTimer) { clearInterval(forgotResendTimer); forgotResendTimer = null; }
            const resend = document.getElementById('forgotOtpResend');
            resend.style.pointerEvents = 'auto';
            resend.textContent = 'Resend code';
        }

        function toggleForgotMode() {
            const messages = document.querySelectorAll('#forgotPasswordForm .message');
            messages.forEach(msg => msg.remove());
            if (forgotMode === 'email') {
                forgotMode = 'otp';
                document.getElementById('forgotModalDesc').textContent = "Enter your restaurant email address and we'll send a verification code via WhatsApp to the phone on file.";
                document.getElementById('forgotPasswordBtn').textContent = 'Send Code';
                document.getElementById('forgotModeToggle').textContent = 'Reset via email link instead';
            } else {
                resetForgotModalState();
            }
        }

        function startForgotResendCooldown(seconds) {
            const resend = document.getElementById('forgotOtpResend');
            let remaining = seconds;
            resend.style.pointerEvents = 'none';
            resend.textContent = `Resend code (${remaining}s)`;
            if (forgotResendTimer) clearInterval(forgotResendTimer);
            forgotResendTimer = setInterval(() => {
                remaining -= 1;
                if (remaining <= 0) {
                    clearInterval(forgotResendTimer);
                    forgotResendTimer = null;
                    resend.style.pointerEvents = 'auto';
                    resend.textContent = 'Resend code';
                } else {
                    resend.textContent = `Resend code (${remaining}s)`;
                }
            }, 1000);
        }

        async function sendForgotOtp(email) {
            const response = await fetch('auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=forgotPasswordOtp&email=${encodeURIComponent(email)}`
            });
            return response.json();
        }

        document.getElementById('forgotOtpResend').addEventListener('click', async () => {
            const email = document.getElementById('forgotEmail').value.trim();
            try {
                const result = await sendForgotOtp(email);
                showForgotPasswordMessage(result.message || 'A new code has been sent via WhatsApp.', 'success');
                startForgotResendCooldown(60);
                showLocalOtp(result, 'forgotOtpCode');
            } catch (error) {
                console.error('Error:', error);
                showForgotPasswordMessage('Network error. Please try again.', 'error');
            }
        });

        function closeForgotPasswordModal() {
            document.getElementById('forgotPasswordModal').classList.remove('active');
            document.getElementById('forgotPasswordForm').reset();
            resetForgotModalState();
            const messages = document.querySelectorAll('#forgotPasswordForm .message');
            messages.forEach(msg => msg.remove());
        }

        // Close modal when clicking outside
        document.getElementById('forgotPasswordModal').addEventListener('click', (e) => {
            if (e.target.id === 'forgotPasswordModal') {
                closeForgotPasswordModal();
            }
        });

        // Forgot Password Form Submission
        document.getElementById('forgotPasswordForm').addEventListener('submit', async (e) => {
            e.preventDefault();

            const email = document.getElementById('forgotEmail').value.trim();
            const forgotPasswordBtn = document.getElementById('forgotPasswordBtn');

            if (!email) {
                showForgotPasswordMessage('Please enter your email address.', 'error');
                return;
            }

            if (!email.includes('@')) {
                showForgotPasswordMessage('Please enter a valid email address.', 'error');
                return;
            }

            if (forgotMode === 'email') {
                forgotPasswordBtn.disabled = true;
                forgotPasswordBtn.textContent = 'Sending...';
                try {
                    const response = await fetch('auth.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=forgotPassword&email=${encodeURIComponent(email)}`
                    });
                    const result = await response.json();
                    if (result.success) {
                        showForgotPasswordMessage(result.message || 'Password reset link has been sent to your email. Please check your inbox.', 'success');
                    } else if (result.cooldown_seconds) {
                        const minutes = Math.floor(result.cooldown_seconds / 60);
                        const seconds = result.cooldown_seconds % 60;
                        const timeStr = minutes > 0 ? (minutes + ' minute(s) and ' + seconds + ' second(s)') : (seconds + ' second(s)');
                        showForgotPasswordMessage(result.message || 'Please wait ' + timeStr + ' before requesting another password reset.', 'error');
                    } else {
                        showForgotPasswordMessage(result.message || 'Email not found. Please check your email address.', 'error');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showForgotPasswordMessage('Network error. Please try again.', 'error');
                } finally {
                    forgotPasswordBtn.disabled = false;
                    forgotPasswordBtn.textContent = 'Send Reset Link';
                }
                return;
            }

            // OTP mode, step 1: send the code and reveal the rest of the form.
            if (!forgotOtpSent) {
                forgotPasswordBtn.disabled = true;
                forgotPasswordBtn.textContent = 'Sending...';
                try {
                    const result = await sendForgotOtp(email);
                    forgotOtpSent = true;
                    document.getElementById('forgotEmail').disabled = true;
                    document.getElementById('forgotOtpGroup').style.display = 'block';
                    document.getElementById('forgotNewPasswordGroup').style.display = 'block';
                    document.getElementById('forgotConfirmPasswordGroup').style.display = 'block';
                    document.getElementById('forgotModeToggle').style.display = 'none';
                    forgotPasswordBtn.textContent = 'Verify & Reset Password';
                    showForgotPasswordMessage(result.message || 'If an account exists for that email, a code has been sent via WhatsApp.', 'success');
                    startForgotResendCooldown(60);
                    if (showLocalOtp(result, 'forgotOtpCode')) document.getElementById('forgotNewPassword').focus();
                    else document.getElementById('forgotOtpCode').focus();
                } catch (error) {
                    console.error('Error:', error);
                    showForgotPasswordMessage('Network error. Please try again.', 'error');
                } finally {
                    forgotPasswordBtn.disabled = false;
                }
                return;
            }

            // OTP mode, step 2: verify the code and set the new password.
            const code = document.getElementById('forgotOtpCode').value.trim();
            const newPassword = document.getElementById('forgotNewPassword').value;
            const confirmPassword = document.getElementById('forgotConfirmPassword').value;

            if (!code) { showForgotPasswordMessage('Please enter the verification code.', 'error'); return; }
            if (newPassword.length < 6) { showForgotPasswordMessage('New password must be at least 6 characters long.', 'error'); return; }
            if (newPassword !== confirmPassword) { showForgotPasswordMessage('New passwords do not match.', 'error'); return; }

            forgotPasswordBtn.disabled = true;
            forgotPasswordBtn.textContent = 'Verifying...';
            try {
                const response = await fetch('auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=resetPasswordOtp&email=${encodeURIComponent(email)}&otp_code=${encodeURIComponent(code)}&newPassword=${encodeURIComponent(newPassword)}&confirmPassword=${encodeURIComponent(confirmPassword)}`
                });
                const result = await response.json();
                if (result.success) {
                    showForgotPasswordMessage(result.message || 'Password reset successfully. You can now log in.', 'success');
                    setTimeout(() => { closeForgotPasswordModal(); }, 2000);
                } else {
                    showForgotPasswordMessage(result.message || 'Invalid or expired code.', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showForgotPasswordMessage('Network error. Please try again.', 'error');
            } finally {
                forgotPasswordBtn.disabled = false;
                forgotPasswordBtn.textContent = 'Verify & Reset Password';
            }
        });
        
        function showForgotPasswordMessage(message, type) {
            const form = document.getElementById('forgotPasswordForm');
            const existingMessage = form.querySelector('.message');
            if (existingMessage) {
                existingMessage.remove();
            }
            
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${type}`;
            messageDiv.textContent = message;
            form.insertBefore(messageDiv, form.firstChild);
        }
        
        // Back from "Continue with Google": an error, or a new owner to sign up (name + email filled in)
        window.googleSignup = <?php echo json_encode($googleSignup, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        window.signupInvite = <?php echo json_encode($googleSignup ? null : $signupInvite, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        (function () {
            var q = new URLSearchParams(location.search);
            var inviteError = <?php echo json_encode($inviteError, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
            if (q.get('google_error')) {
                showMessage(q.get('google_error'), 'error');
            } else if (window.signupInvite) {
                // private sign-up link: what RestroGrow already typed is filled in, and no WhatsApp code
                switchTab('signup');
                var inv = window.signupInvite, set = function (id, v) { var el = document.getElementById(id); if (el && v) el.value = v; };
                if (inv.country) {
                    var sel = document.getElementById('signupCountry');
                    if ([].some.call(sel.options, function (o) { return o.value === inv.country; })) { sel.value = inv.country; sel.dispatchEvent(new Event('change')); }
                }
                set('signupPhone', inv.phone); set('restaurantName', inv.restaurant_name); set('signupEmail', inv.email); set('signupCity', inv.city);
                var bt = inv.business_type && document.querySelector('#signupForm input[name=business_type][value="' + inv.business_type + '"]'); if (bt) bt.checked = true;
                document.getElementById('suNext1Text').textContent = 'Continue';
                document.getElementById('suNote1').textContent = 'Your private sign-up link from RestroGrow, so no WhatsApp code is needed.';
                var ig = document.querySelector('#signupForm .su-step[data-step="1"] .btn-google'); if (ig) ig.style.display = 'none';
                var io = document.querySelector('#signupForm .su-step[data-step="1"] .or-divider'); if (io) io.style.display = 'none';
                showMessage('Welcome! Fill in your details and set a password to open your restaurant.', 'info');
            } else if (inviteError) {
                switchTab('signup');
                showMessage(inviteError, 'error');
            } else if (window.googleSignup) {
                switchTab('signup');
                var email = window.googleSignup.email;
                var em = document.getElementById('signupEmail');
                if (em) { em.value = email; em.readOnly = true; }
                document.getElementById('suNext1Text').textContent = 'Continue';
                document.getElementById('suNote1').textContent = 'Signed in with Google, so no WhatsApp code is needed.';
                var g = document.querySelector('#signupForm .su-step[data-step="1"] .btn-google'); if (g) g.style.display = 'none';
                var od = document.querySelector('#signupForm .su-step[data-step="1"] .or-divider'); if (od) od.style.display = 'none';
                showMessage('Signed in with Google as ' + email + '. Add your phone and restaurant to start your free trial.', 'info');
            } else if (<?php echo $openSignup ? 'true' : 'false'; ?> || q.get('tab') === 'signup' || location.hash === '#signup') {
                switchTab('signup');
            }
            if (q.get('google_error') || q.get('google_signup') || q.get('google')) history.replaceState(null, '', location.pathname);
        })();

        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                // Show temporary feedback
                const feedback = document.createElement('div');
                feedback.textContent = 'Copied!';
                feedback.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #10b981; color: white; padding: 10px 20px; border-radius: 5px; z-index: 10000;';
                document.body.appendChild(feedback);
                setTimeout(() => feedback.remove(), 2000);
            }).catch(err => {
                console.error('Failed to copy:', err);
                var fb = document.createElement('div'); fb.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #ef4444; color: white; padding: 10px 20px; border-radius: 5px; z-index: 10000;'; fb.textContent = 'Failed to copy. Please select and copy manually.'; document.body.appendChild(fb); setTimeout(function() { fb.remove(); }, 3000);
            });
        }
    </script>
</body>
</html>
