<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/countries.php';
startSecureSession();

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
        header('Location: ../views/dashboard.php');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Restro Grow | Restaurant Management System</title>
    <meta name="description" content="Login to your Restro Grow admin dashboard. Access your restaurant POS system, manage orders, staff, tables, and view real-time analytics.">
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="https://restrogrow.com/main/admin/login.php">
    <link rel="icon" type="image/png" href="../assets/images/logo-transparent.png">
    <link rel="apple-touch-icon" href="../assets/images/logo-transparent.png">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#ff6b35">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0">
    <style>
        * {
            box-sizing: border-box;
        }
        
        body {
            margin: 0;
            padding: 0;
            height: 100vh;
            font-family: "Poppins", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f5f5f0;
            position: relative;
        }
        
        .login-wrapper {
            position: relative;
            z-index: 1;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow: hidden;
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
            width: 36px;
            height: 36px;
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
        
        .form-group input {
            width: 100%;
            padding: 12px 14px 12px 48px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: white;
            color: #1f2937;
        }
        
        .form-group input::placeholder {
            color: #9ca3af;
        }
        
        .form-group input:focus {
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
            
            .form-group input {
                padding: 13px 16px 13px 48px;
                font-size: 1rem;
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
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-container">
            <!-- Left Side - Logo and Heading -->
            <div class="login-left">
                <div class="logo-section">
                    <img src="../assets/images/logo-transparent.png" alt="Restro Grow Logo" class="logo-img">
                    <span class="logo-text">Restro Grow</span>
                </div>
                <h1 class="login-heading">Login into your account</h1>
                <p class="login-tagline">Let us make your restaurant grow!</p>
            </div>
            
            <!-- Right Side - Form Card -->
            <div class="login-right">
                <div class="auth-container">
                    <div class="auth-header">
                        <h1>Login</h1>
                        <p>Please sign in to continue.</p>
                    </div>
                    
                    <!-- Login Form -->
                    <form id="loginForm" class="auth-form active">
                        <div class="form-group">
                            <label for="loginUsername">EMAIL</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                </svg>
                                <input type="text" id="loginUsername" name="username" required placeholder="user123@email.com">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="loginPassword">PASSWORD</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                </svg>
                                <input type="password" id="loginPassword" name="password" required placeholder="Your password">
                            </div>
                        </div>
                        <div class="form-actions">
                            <a href="#" id="forgotPasswordLink" class="forgot-password-link">FORGOT</a>
                        </div>
                        <button type="submit" class="btn btn-primary" id="loginBtn">
                            LOGIN
                            <svg class="btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </button>
                        <div class="signup-link">
                            Don't have an account? <a href="#" onclick="event.preventDefault(); switchTab('signup');">Sign up</a>
                        </div>
                    </form>
                    
                    <!-- Signup Form -->
                    <form id="signupForm" class="auth-form">
                        <div class="form-group">
                            <label for="signupUsername">USERNAME</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                                <input type="text" id="signupUsername" name="username" required placeholder="Choose a username">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="signupPassword">PASSWORD</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                </svg>
                                <input type="password" id="signupPassword" name="password" required placeholder="Choose a password">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="restaurantName">RESTAURANT NAME</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                </svg>
                                <input type="text" id="restaurantName" name="restaurant_name" required placeholder="Enter your restaurant name">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="signupCountry">COUNTRY</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <select id="signupCountry" name="country" required>
                                    <option value="">Select your country</option>
                                    <?php foreach (getCountryData() as $iso2 => $c): ?>
                                    <option value="<?php echo htmlspecialchars($c['name']); ?>" <?php echo $iso2 === 'IN' ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="signupPhone">PHONE NUMBER</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                </svg>
                                <input type="tel" id="signupPhone" name="phone" required placeholder="Enter your phone number">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary" id="signupBtn">
                            SIGN UP
                            <svg class="btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </button>
                        <div class="signup-link">
                            Already have an account? <a href="#" onclick="event.preventDefault(); switchTab('login');">Sign in</a>
                        </div>
                    </form>
                    
                    <div class="demo-credentials">
                        <h4>Login Credentials:</h4>
                        <p><strong>Admin:</strong> Use your username</p>
                        <p><strong>Staff:</strong> Use your email or phone number</p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="login-footer">
            © 2024 Restro Grow. All Rights Reserved.
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
            <p>Enter your restaurant email address and we'll send you a password reset link.</p>
            <form id="forgotPasswordForm">
                <div class="form-group">
                    <label for="forgotEmail">Email Address:</label>
                    <input type="email" id="forgotEmail" name="email" required placeholder="Enter your restaurant email">
                </div>
                <button type="submit" class="btn btn-primary" id="forgotPasswordBtn">Send Reset Link</button>
                <button type="button" class="btn btn-outline" onclick="closeForgotPasswordModal()" style="background: #f5f5f5; color: #333; border: 2px solid #e0e0e0;">Cancel</button>
            </form>
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
                    applicationServerKey: urlBase64ToUint8Array('<?php echo env('VAPID_PUBLIC_KEY', ''); ?>')
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
            // Show the prompt modal
            document.getElementById('notifPromptModal').classList.add('active');
        }

        function enableNotifications() {
            var btn = document.getElementById('enableNotifBtn');
            btn.disabled = true;
            btn.textContent = 'Setting up...';
            subscribeToPush().then(function() {
                doRedirect(_pendingRedirectUrl);
            }).catch(function() {
                // Even if push fails, still redirect
                doRedirect(_pendingRedirectUrl);
            });
        }

        function skipNotifications() {
            doRedirect(_pendingRedirectUrl);
        }

        function doRedirect(url) {
            document.getElementById('notifPromptModal').classList.remove('active');
            window.location.href = url;
        }

        function switchTab(tab) {
            // Update form visibility
            document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
            document.getElementById(tab + 'Form').classList.add('active');
            
            // Clear messages
            const messages = document.querySelectorAll('.message');
            messages.forEach(msg => msg.remove());
        }
        
        // Login form submission
        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const username = document.getElementById('loginUsername').value.trim();
            const password = document.getElementById('loginPassword').value;
            const loginBtn = document.getElementById('loginBtn');
            
            if (!username || !password) {
                showMessage('Please fill in all fields.', 'error');
                return;
            }
            
            loginBtn.disabled = true;
            loginBtn.textContent = 'Logging in...';
            
            try {
                // Create AbortController for timeout
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 30000); // 30 second timeout
                
                const response = await fetch('auth.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=login&username=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}`,
                    signal: controller.signal
                });
                
                clearTimeout(timeoutId);
                
                // Get response text first to check if it's valid JSON
                const responseText = await response.text();
                
                // Log full response to console for debugging
                console.log('Login response status:', response.status);
                if (!response.ok) {
                    console.error('Server error response:', responseText);
                }
                
                if (!responseText || responseText.trim() === '') {
                    console.error('Server returned empty response');
                    showMessage('Incorrect username or password', 'error');
                    return;
                }
                
                // Check if response is HTML (likely a PHP error page)
                if (responseText.trim().startsWith('<!DOCTYPE') || responseText.trim().startsWith('<html')) {
                    console.error('Server returned HTML instead of JSON:', responseText.substring(0, 500));
                    showMessage('Incorrect username or password', 'error');
                    return;
                }
                
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('JSON Parse Error:', parseError);
                    console.error('Response Text:', responseText.substring(0, 500));
                    showMessage('Incorrect username or password', 'error');
                    return;
                }
                
                // Check if response is ok
                if (!response.ok) {
                    // Log error details to console
                    console.error('Server error:', result);
                    // Show user-friendly message
                    showMessage(result.message || 'Incorrect username or password', 'error');
                    return;
                }
                
                if (result.success) {
                    showMessage('Login successful!', 'success');
                    // Store redirect info
                    var redirectUrl = result.redirect || '../views/dashboard.php';
                    try {
                        sessionStorage.setItem('forceDashboard', '1');
                        localStorage.removeItem('admin_active_page');
                    } catch (storageErr) {
                        console.warn('Unable to set dashboard preference', storageErr);
                    }
                    // Show notification prompt instead of subscribing silently
                    showNotificationPrompt(redirectUrl);
                } else {
                    // Show user-friendly message (backend should already return friendly message)
                    showMessage(result.message || 'Incorrect username or password', 'error');
                }
            } catch (error) {
                // Log full error to console for debugging
                console.error('Login Error:', error);
                console.error('Error details:', {
                    name: error.name,
                    message: error.message,
                    stack: error.stack
                });
                
                // Show only user-friendly message
                let errorMessage = 'Incorrect username or password';
                
                // Only show network errors if it's clearly a network issue
                if (error.name === 'AbortError') {
                    errorMessage = 'Request timeout. Please try again.';
                } else if (error.message && (error.message.includes('Failed to fetch') || error.message.includes('NetworkError'))) {
                    errorMessage = 'Network error. Please check your internet connection and try again.';
                }
                
                showMessage(errorMessage, 'error');
            } finally {
                loginBtn.disabled = false;
                loginBtn.textContent = 'Login';
            }
        });
        
        // Signup form submission
        document.getElementById('signupForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const username = document.getElementById('signupUsername').value.trim();
            const password = document.getElementById('signupPassword').value;
            const restaurantName = document.getElementById('restaurantName').value.trim();
            const country = document.getElementById('signupCountry').value;
            const phone = document.getElementById('signupPhone').value.trim();
            const signupBtn = document.getElementById('signupBtn');

            if (!username || !password || !restaurantName || !country || !phone) {
                showMessage('Please fill in all fields.', 'error');
                return;
            }
            
            if (password.length < 6) {
                showMessage('Password must be at least 6 characters long.', 'error');
                return;
            }
            
            signupBtn.disabled = true;
            signupBtn.textContent = 'Creating Account...';
            
            try {
                const response = await fetch('auth.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=signup&username=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}&restaurant_name=${encodeURIComponent(restaurantName)}&country=${encodeURIComponent(country)}&phone=${encodeURIComponent(phone)}`
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showMessage('Account created successfully! Please sign in.', 'success');
                    setTimeout(() => {
                        switchTab('login');
                        document.getElementById('loginUsername').value = username;
                    }, 1500);
                } else {
                    showMessage(result.message || 'Signup failed. Please try again.', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showMessage('Network error. Please try again.', 'error');
            } finally {
                signupBtn.disabled = false;
                signupBtn.textContent = 'Sign Up';
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
        
        function closeForgotPasswordModal() {
            document.getElementById('forgotPasswordModal').classList.remove('active');
            document.getElementById('forgotPasswordForm').reset();
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
            
            forgotPasswordBtn.disabled = true;
            forgotPasswordBtn.textContent = 'Sending...';
            
            try {
                const response = await fetch('auth.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=forgotPassword&email=${encodeURIComponent(email)}`
                });
                
                const result = await response.json();
                
                if (result.success) {
                    let message = result.message || 'Password reset link has been sent to your email. Please check your inbox.';
                    
                    // Don't show reset link in UI for security reasons
                    // Link is only sent via email
                    
                    showForgotPasswordMessage(message, 'success');
                    
                    // Don't auto-close - let user close manually
                } else {
                    // Check if there's a cooldown
                    if (result.cooldown_seconds) {
                        const minutes = Math.floor(result.cooldown_seconds / 60);
                        const seconds = result.cooldown_seconds % 60;
                        const timeStr = minutes > 0 ? (minutes + ' minute(s) and ' + seconds + ' second(s)') : (seconds + ' second(s)');
                        showForgotPasswordMessage(result.message || 'Please wait ' + timeStr + ' before requesting another password reset.', 'error');
                    } else {
                        showForgotPasswordMessage(result.message || 'Email not found. Please check your email address.', 'error');
                    }
                }
            } catch (error) {
                console.error('Error:', error);
                showForgotPasswordMessage('Network error. Please try again.', 'error');
            } finally {
                forgotPasswordBtn.disabled = false;
                forgotPasswordBtn.textContent = 'Send Reset Link';
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
