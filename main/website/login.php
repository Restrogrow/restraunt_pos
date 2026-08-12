<?php require_once __DIR__ . '/header.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<base href="<?php echo $website_base_href; ?>">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="restaurant-id" content="<?php echo htmlspecialchars($restaurant_id ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<title>Login - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: 'Poppins', sans-serif;
  background: #e8ecf2;
  color: #1a1b1f;
  min-height: 100vh;
}
.phone-frame {
  max-width: 425px;
  margin: 0 auto;
  min-height: 100vh;
  background: #fff;
  position: relative;
  box-shadow: 0 0 40px rgba(0,0,0,0.08);
}
@media (min-width: 768px) {
  .phone-frame { margin: 20px auto; min-height: calc(100vh - 40px); border-radius: 28px; overflow: hidden; }
}
.auth-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px 12px 12px;
  border-bottom: 1.5px solid #eee;
}
.auth-header h1 { font-size: 18px; font-weight: 700; flex: 1; }
.back-btn {
  display: flex; align-items: center; justify-content: center;
  width: 40px; height: 40px; border-radius: 8px;
  background: linear-gradient(135deg, #e17055, #d63031);
  color: #fff; border: none; cursor: pointer; font-size: 20px;
  flex-shrink: 0;
}
.auth-content { padding: 20px 18px 40px; }
.auth-hero { text-align: center; margin-bottom: 24px; }
.auth-hero-icon {
  width: 64px; height: 64px; margin: 0 auto 12px;
  border-radius: 50%;
  background: linear-gradient(135deg, #e17055, #d63031);
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-size: 26px;
}
.auth-hero h2 { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
.auth-hero p { font-size: 13px; color: #999; }

.auth-tabs {
  display: flex;
  background: #f3f4f6;
  border-radius: 12px;
  padding: 4px;
  margin-bottom: 20px;
}
.auth-tab {
  flex: 1;
  text-align: center;
  padding: 10px;
  border-radius: 9px;
  font-size: 13px;
  font-weight: 600;
  color: #6b7280;
  cursor: pointer;
  transition: all 0.2s;
  font-family: 'Poppins', sans-serif;
  border: none;
  background: none;
}
.auth-tab.active { background: #fff; color: #d63031; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }

.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 6px; font-weight: 500; color: #555; font-size: 12px; }
.form-group input {
  width: 100%;
  padding: 11px 12px;
  border: 1.5px solid #ddd;
  border-radius: 8px;
  font-size: 13px;
  font-family: 'Poppins', sans-serif;
  transition: all 0.2s;
}
.form-group input:focus { outline: none; border-color: #e17055; box-shadow: 0 0 0 3px rgba(225,112,85,0.1); }
.form-group input.error { border-color: #ef4444; background: #fef2f2; }
.phone-input-row { display: flex; align-items: center; gap: 6px; }
.phone-input-row span { color: #666; font-size: 14px; white-space: nowrap; }
.phone-input-row input { flex: 1; }

.remember-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 18px;
  font-size: 12px;
}
.remember-row label { display: flex; align-items: center; gap: 6px; color: #555; cursor: pointer; }
.remember-row a { color: #d63031; font-weight: 600; text-decoration: none; }
.remember-row a:hover { text-decoration: underline; }

.btn-auth-submit {
  width: 100%;
  padding: 13px;
  border: none;
  border-radius: 8px;
  background: linear-gradient(135deg, #e17055, #d63031);
  color: #fff;
  font-weight: 600;
  font-size: 14px;
  font-family: 'Poppins', sans-serif;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  transition: all 0.2s;
}
.btn-auth-submit:hover { opacity: 0.9; box-shadow: 0 4px 12px rgba(214,48,49,0.3); }
.btn-auth-submit:disabled { opacity: 0.6; cursor: not-allowed; }

.auth-divider { display: flex; align-items: center; gap: 10px; margin: 20px 0; color: #bbb; font-size: 11px; text-transform: uppercase; }
.auth-divider::before, .auth-divider::after { content: ''; flex: 1; height: 1px; background: #eee; }

.btn-guest {
  width: 100%;
  padding: 13px;
  border: 1.5px solid #ddd;
  border-radius: 8px;
  background: #fff;
  color: #555;
  font-weight: 600;
  font-size: 14px;
  font-family: 'Poppins', sans-serif;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-guest:hover { background: #f9f9f9; border-color: #ccc; }

.auth-form-error {
  color: #ef4444;
  font-size: 12px;
  margin-bottom: 12px;
  display: none;
  background: #fef2f2;
  padding: 10px 12px;
  border-radius: 8px;
}

/* ── Forgot password modal ── */
.modal-overlay {
  position: fixed; top:0; left:0; right:0; bottom:0;
  background: rgba(0,0,0,0.5);
  display: flex; align-items: center; justify-content: center;
  z-index: 10000;
}
.modal-box {
  background: #fff; border-radius: 20px; padding: 26px 22px;
  max-width: 360px; width: 90%;
  position: relative;
}
.modal-close {
  position: absolute; top: 12px; right: 14px;
  background: none; border: none; font-size: 20px; color: #9ca3af; cursor: pointer;
}
.modal-box h3 { font-size: 16px; font-weight: 700; margin-bottom: 6px; }
.modal-box p { font-size: 12px; color: #999; margin-bottom: 16px; }

.toast-notification {
  position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%);
  padding: 14px 24px; border-radius: 14px; font-size: 13px; font-weight: 500;
  z-index: 99999; box-shadow: 0 8px 30px rgba(0,0,0,0.2);
  opacity: 0; transition: all 0.35s cubic-bezier(0.4,0,0.2,1);
  transform: translateX(-50%) translateY(24px); pointer-events: none;
  max-width: 90%; text-align: center;
}
.toast-notification.show { opacity: 1; transform: translateX(-50%) translateY(0); }
.toast-notification.success { background: #10b981; color: #fff; }
.toast-notification.error { background: #ef4444; color: #fff; }
.hidden { display: none !important; }
</style>
</head>
<body>
<div class="phone-frame">
  <div class="auth-header">
    <button class="back-btn" onclick="window.location.href='<?php echo restaurantPageUrl('menu'); ?>'" aria-label="Back"><i class="fa fa-arrow-left"></i></button>
    <h1><?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></h1>
  </div>

  <div class="auth-content">
    <div class="auth-hero">
      <div class="auth-hero-icon"><i class="fa fa-user"></i></div>
      <h2>Welcome</h2>
      <p>Log in to track your orders and check out faster</p>
    </div>

    <div class="auth-tabs">
      <button class="auth-tab active" id="tabLogin" onclick="switchAuthTab('login')">Login</button>
      <button class="auth-tab" id="tabSignup" onclick="switchAuthTab('signup')">Sign Up</button>
    </div>

    <div class="auth-form-error" id="authError"></div>

    <!-- Login Form -->
    <form id="loginForm">
      <div class="form-group">
        <label>Phone Number</label>
        <div class="phone-input-row">
          <span><?php echo htmlspecialchars($phone_dial_code ?? '+91'); ?></span>
          <input type="tel" id="loginPhone" placeholder="Enter your phone number" required>
        </div>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" id="loginPassword" placeholder="Enter your password" required>
      </div>
      <div class="remember-row">
        <label><input type="checkbox" id="loginRemember" checked> Remember me</label>
        <a href="#" onclick="openForgotModal();return false;">Forgot password?</a>
      </div>
      <button type="submit" class="btn-auth-submit" id="loginSubmitBtn"><i class="fa fa-right-to-bracket"></i> Login</button>
    </form>

    <!-- Signup Form -->
    <form id="signupForm" class="hidden">
      <div class="form-group">
        <label>Full Name</label>
        <input type="text" id="signupName" placeholder="Enter your full name" required>
      </div>
      <div class="form-group">
        <label>Phone Number</label>
        <div class="phone-input-row">
          <span><?php echo htmlspecialchars($phone_dial_code ?? '+91'); ?></span>
          <input type="tel" id="signupPhone" placeholder="Enter your phone number" required>
        </div>
      </div>
      <div class="form-group">
        <label>Email Address</label>
        <input type="email" id="signupEmail" placeholder="your@email.com" required>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" id="signupPassword" placeholder="At least 6 characters" required>
      </div>
      <div class="form-group">
        <label>Confirm Password</label>
        <input type="password" id="signupConfirmPassword" placeholder="Re-enter your password" required>
      </div>
      <div class="remember-row">
        <label><input type="checkbox" id="signupRemember" checked> Remember me</label>
      </div>
      <button type="submit" class="btn-auth-submit" id="signupSubmitBtn"><i class="fa fa-user-plus"></i> Create Account</button>
    </form>

    <div class="auth-divider">or</div>
    <button type="button" class="btn-guest" onclick="continueAsGuest()"><i class="fa fa-arrow-right"></i> Continue as Guest</button>
  </div>
</div>

<!-- Forgot Password Modal -->
<div id="forgotModalContainer"></div>

<script>
window.restaurantId = <?php echo json_encode($restaurant_id ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.referralCode = <?php echo json_encode($referral_code ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
<?php
$redirectTargetPage = isset($_GET['redirect']) ? trim($_GET['redirect']) : 'menu';
$allowedRedirectPages = ['menu', 'cart', 'profile', 'about', 'contact', 'plans', 'subscribe', 'my-subscription', 'catering'];
if (!in_array($redirectTargetPage, $allowedRedirectPages, true)) $redirectTargetPage = 'profile';
$redirectTargetUrl = restaurantPageUrl($redirectTargetPage);
// A dine-in QR scan puts ?table=X on the cart URL that sent the customer here
// (see cart.php's checkout gate) - carry it through so choosing login/signup/
// guest doesn't drop them back into the delivery/takeaway flow instead.
if (!empty($_GET['table'])) {
    $redirectTargetUrl .= (strpos($redirectTargetUrl, '?') !== false ? '&' : '?') . 'table=' . urlencode($_GET['table']);
}
?>
window.redirectUrl = <?php echo json_encode($redirectTargetUrl, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.alreadyLoggedIn = <?php echo $logged_in_customer ? 'true' : 'false'; ?>;

if (window.alreadyLoggedIn) {
  window.location.href = window.redirectUrl;
}

function switchAuthTab(tab) {
  document.getElementById('tabLogin').classList.toggle('active', tab === 'login');
  document.getElementById('tabSignup').classList.toggle('active', tab === 'signup');
  document.getElementById('loginForm').classList.toggle('hidden', tab !== 'login');
  document.getElementById('signupForm').classList.toggle('hidden', tab !== 'signup');
  hideAuthError();
}

function showAuthError(msg) {
  var el = document.getElementById('authError');
  el.textContent = msg;
  el.style.display = 'block';
}
function hideAuthError() {
  document.getElementById('authError').style.display = 'none';
}

function showToast(msg, type) {
  var existing = document.querySelector('.toast-notification');
  if (existing) existing.remove();
  var toast = document.createElement('div');
  toast.className = 'toast-notification ' + (type || 'success');
  toast.textContent = msg;
  document.body.appendChild(toast);
  requestAnimationFrame(function() { setTimeout(function() { toast.classList.add('show'); }, 10); });
  setTimeout(function() {
    toast.classList.remove('show');
    setTimeout(function() { toast.remove(); }, 350);
  }, 2600);
}

function continueAsGuest() {
  try { sessionStorage.setItem('guestSessionActive', '1'); } catch(e) {}
  window.location.href = window.redirectUrl;
}

document.getElementById('loginForm').addEventListener('submit', function(e) {
  e.preventDefault();
  hideAuthError();
  var phone = document.getElementById('loginPhone').value.trim();
  var password = document.getElementById('loginPassword').value;
  if (!phone || !password) { showAuthError('Please enter your phone number and password'); return; }

  var btn = document.getElementById('loginSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Logging in...';

  var fd = new FormData();
  fd.append('action', 'login');
  fd.append('restaurant_id', window.restaurantId);
  fd.append('phone', phone);
  fd.append('password', password);
  fd.append('remember', document.getElementById('loginRemember').checked ? '1' : '');

  fetch('customer_auth.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.success) {
        try { localStorage.setItem('customerDetails', JSON.stringify(res.customer)); } catch(e) {}
        showToast(res.message, 'success');
        setTimeout(function() { window.location.href = window.redirectUrl; }, 500);
      } else {
        showAuthError(res.message || 'Login failed');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-right-to-bracket"></i> Login';
      }
    })
    .catch(function() {
      showAuthError('Network error. Please try again.');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa fa-right-to-bracket"></i> Login';
    });
});

document.getElementById('signupForm').addEventListener('submit', function(e) {
  e.preventDefault();
  hideAuthError();
  var name = document.getElementById('signupName').value.trim();
  var phone = document.getElementById('signupPhone').value.trim();
  var email = document.getElementById('signupEmail').value.trim();
  var password = document.getElementById('signupPassword').value;
  var confirmPassword = document.getElementById('signupConfirmPassword').value;

  if (!name || !phone || !email || !password) { showAuthError('Please fill in all fields'); return; }
  if (password.length < 6) { showAuthError('Password must be at least 6 characters'); return; }
  if (password !== confirmPassword) { showAuthError('Passwords do not match'); return; }

  var btn = document.getElementById('signupSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Creating account...';

  var fd = new FormData();
  fd.append('action', 'signup');
  fd.append('restaurant_id', window.restaurantId);
  fd.append('name', name);
  fd.append('phone', phone);
  fd.append('email', email);
  fd.append('password', password);
  fd.append('confirmPassword', confirmPassword);
  fd.append('remember', document.getElementById('signupRemember').checked ? '1' : '');
  if (window.referralCode) fd.append('ref', window.referralCode);

  fetch('customer_auth.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.success) {
        try { localStorage.setItem('customerDetails', JSON.stringify(res.customer)); } catch(e) {}
        showToast(res.message, 'success');
        setTimeout(function() { window.location.href = window.redirectUrl; }, 500);
      } else {
        showAuthError(res.message || 'Sign up failed');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-user-plus"></i> Create Account';
      }
    })
    .catch(function() {
      showAuthError('Network error. Please try again.');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa fa-user-plus"></i> Create Account';
    });
});

/* ── Forgot Password ── */
function openForgotModal() {
  var container = document.getElementById('forgotModalContainer');
  container.innerHTML =
    '<div class="modal-overlay" onclick="if(event.target===this)closeForgotModal()">' +
    '<div class="modal-box">' +
    '<button class="modal-close" onclick="closeForgotModal()">&times;</button>' +
    '<h3>Reset your password</h3>' +
    '<p>Enter the email you signed up with and we\'ll send you a reset link.</p>' +
    '<div class="form-group"><input type="email" id="forgotEmail" placeholder="your@email.com"></div>' +
    '<div class="auth-form-error" id="forgotError"></div>' +
    '<button type="button" class="btn-auth-submit" id="forgotSubmitBtn" onclick="submitForgotPassword()"><i class="fa fa-paper-plane"></i> Send Reset Link</button>' +
    '</div></div>';
}
function closeForgotModal() {
  document.getElementById('forgotModalContainer').innerHTML = '';
}
function submitForgotPassword() {
  var email = document.getElementById('forgotEmail').value.trim();
  var errEl = document.getElementById('forgotError');
  errEl.style.display = 'none';
  if (!email) { errEl.textContent = 'Please enter your email'; errEl.style.display = 'block'; return; }

  var btn = document.getElementById('forgotSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Sending...';

  var fd = new FormData();
  fd.append('action', 'forgotPassword');
  fd.append('restaurant_id', window.restaurantId);
  fd.append('email', email);

  fetch('customer_auth.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.success) {
        document.querySelector('.modal-box').innerHTML =
          '<button class="modal-close" onclick="closeForgotModal()">&times;</button>' +
          '<h3>Check your email</h3><p>' + res.message + '</p>' +
          '<button type="button" class="btn-auth-submit" onclick="closeForgotModal()"><i class="fa fa-check"></i> Done</button>';
      } else {
        errEl.textContent = res.message || 'Something went wrong';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-paper-plane"></i> Send Reset Link';
      }
    })
    .catch(function() {
      errEl.textContent = 'Network error. Please try again.';
      errEl.style.display = 'block';
      btn.disabled = false;
      btn.innerHTML = '<i class="fa fa-paper-plane"></i> Send Reset Link';
    });
}
</script>
</body>
</html>
