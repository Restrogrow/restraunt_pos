<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Restro Grow | Restaurant Management System</title>
    <meta name="description" content="Reset your Restro Grow account password. Enter your new password to regain access to your restaurant management dashboard.">
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="https://restrogrow.com/main/admin/reset_password.php">
    <link rel="icon" type="image/png" href="../assets/images/logo-transparent.png">
    <link rel="apple-touch-icon" href="../assets/images/logo-transparent.png">
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
            overflow: hidden;
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
            text-align: center;
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
            margin-bottom: 16px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 107, 53, 0.3);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .btn svg {
            width: 18px;
            height: 18px;
        }
        
        .message {
            padding: 12px 15px;
            border-radius: 12px;
            margin-bottom: 16px;
            font-size: 0.85rem;
            position: relative;
            z-index: 1;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .back-to-login {
            text-align: center;
            margin-top: 16px;
            position: relative;
            z-index: 1;
        }
        
        .back-to-login a {
            color: #ff6b35;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            transition: color 0.3s ease;
        }
        
        .back-to-login a:hover {
            color: #f7931e;
        }
        
        @media (min-width: 768px) {
            .auth-container {
                padding: 32px;
                max-width: 420px;
            }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="auth-container">
            <div class="auth-header">
                <h1>Reset Password</h1>
                <p>Enter your new password</p>
            </div>
            
            <form id="resetPasswordForm">
                <input type="hidden" id="resetToken" value="<?php echo isset($_GET['token']) ? htmlspecialchars($_GET['token']) : ''; ?>">
                
                <div class="form-group">
                    <label for="newPassword">New Password</label>
                    <div class="input-wrapper">
                        <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                        <input type="password" id="newPassword" name="newPassword" required placeholder="Enter new password (min 6 characters)" minlength="6">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirmPassword">Confirm Password</label>
                    <div class="input-wrapper">
                        <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                        <input type="password" id="confirmPassword" name="confirmPassword" required placeholder="Confirm new password" minlength="6">
                    </div>
                </div>
                
                <button type="submit" class="btn" id="resetPasswordBtn">
                    Reset Password
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </button>
            </form>
            
            <div class="back-to-login">
                <a href="login.php">Back to Login</a>
            </div>
        </div>
    </div>

    <script>
        const resetToken = document.getElementById('resetToken').value;
        
        if (!resetToken) {
            showMessage('Invalid reset link. Please request a new password reset.', 'error');
            document.getElementById('resetPasswordForm').style.display = 'none';
        }
        
        // Password match validation
        document.getElementById('confirmPassword').addEventListener('input', function() {
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = this.value;
            
            if (confirmPassword && newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
        
        // Form submission
        document.getElementById('resetPasswordForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            const resetPasswordBtn = document.getElementById('resetPasswordBtn');
            
            if (!newPassword || !confirmPassword) {
                showMessage('Please fill in all fields.', 'error');
                return;
            }
            
            if (newPassword.length < 6) {
                showMessage('Password must be at least 6 characters long.', 'error');
                return;
            }
            
            if (newPassword !== confirmPassword) {
                showMessage('Passwords do not match.', 'error');
                return;
            }
            
            resetPasswordBtn.disabled = true;
            resetPasswordBtn.innerHTML = 'Resetting... <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>';
            
            try {
                const response = await fetch('auth.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=resetPassword&token=${encodeURIComponent(resetToken)}&newPassword=${encodeURIComponent(newPassword)}&confirmPassword=${encodeURIComponent(confirmPassword)}`
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showMessage(result.message || 'Password has been reset successfully! Redirecting to login...', 'success');
                    setTimeout(() => {
                        window.location.href = 'login.php';
                    }, 2000);
                } else {
                    showMessage(result.message || 'Failed to reset password. Please try again.', 'error');
                    resetPasswordBtn.disabled = false;
                    resetPasswordBtn.innerHTML = 'Reset Password <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>';
                }
            } catch (error) {
                console.error('Error:', error);
                showMessage('Network error. Please try again.', 'error');
                resetPasswordBtn.disabled = false;
                resetPasswordBtn.textContent = 'Reset Password';
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
            
            const form = document.getElementById('resetPasswordForm');
            form.insertBefore(messageDiv, form.firstChild);
        }
    </script>
</body>
</html>

