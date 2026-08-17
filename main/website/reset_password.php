<?php require_once __DIR__ . '/header.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<base href="<?php echo $website_base_href; ?>">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Reset Password - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" href="<?php echo htmlspecialchars($favicon_href ?? $local_favicon_svg, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Poppins', sans-serif; background: #e8ecf2; color: #1a1b1f; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
.reset-card {
  background: #fff; border-radius: 20px; padding: 32px 26px; max-width: 380px; width: 90%;
  box-shadow: 0 8px 30px rgba(0,0,0,0.08); margin: 20px;
}
.reset-icon {
  width: 60px; height: 60px; margin: 0 auto 16px; border-radius: 50%;
  background: linear-gradient(135deg, #e17055, #d63031);
  display: flex; align-items: center; justify-content: center; color: #fff; font-size: 24px;
}
.reset-card h2 { font-size: 19px; font-weight: 700; text-align: center; margin-bottom: 6px; }
.reset-card p.sub { font-size: 13px; color: #999; text-align: center; margin-bottom: 22px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 6px; font-weight: 500; color: #555; font-size: 12px; }
.form-group input {
  width: 100%; padding: 11px 12px; border: 1.5px solid #ddd; border-radius: 8px;
  font-size: 13px; font-family: 'Poppins', sans-serif;
}
.form-group input:focus { outline: none; border-color: #e17055; box-shadow: 0 0 0 3px rgba(225,112,85,0.1); }
.btn-reset-submit {
  width: 100%; padding: 13px; border: none; border-radius: 8px;
  background: linear-gradient(135deg, #e17055, #d63031); color: #fff; font-weight: 600; font-size: 14px;
  font-family: 'Poppins', sans-serif; cursor: pointer; margin-top: 6px;
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-reset-submit:disabled { opacity: 0.6; cursor: not-allowed; }
.reset-error {
  color: #ef4444; font-size: 12px; margin-bottom: 12px; display: none;
  background: #fef2f2; padding: 10px 12px; border-radius: 8px;
}
.reset-success { text-align: center; }
.reset-success .reset-icon { background: #10b981; }
.reset-link { display: inline-block; margin-top: 16px; color: #d63031; font-weight: 600; text-decoration: none; font-size: 13px; }
.hidden { display: none !important; }
</style>
</head>
<body>
<div class="reset-card" id="resetCard">
  <div class="reset-icon"><i class="fa fa-lock"></i></div>
  <h2>Reset your password</h2>
  <p class="sub">Choose a new password for your account</p>

  <div class="reset-error" id="resetError"></div>

  <form id="resetForm">
    <div class="form-group">
      <label>New Password</label>
      <input type="password" id="newPassword" placeholder="At least 6 characters" required>
    </div>
    <div class="form-group">
      <label>Confirm New Password</label>
      <input type="password" id="confirmPassword" placeholder="Re-enter your new password" required>
    </div>
    <button type="submit" class="btn-reset-submit" id="resetSubmitBtn"><i class="fa fa-check"></i> Reset Password</button>
  </form>
</div>

<script>
var token = new URLSearchParams(window.location.search).get('token') || '';
window.loginUrl = <?php echo json_encode(restaurantPageUrl('login'), JSON_HEX_TAG | JSON_HEX_AMP); ?>;

if (!token) {
  document.getElementById('resetCard').innerHTML =
    '<div class="reset-icon" style="background:#ef4444;"><i class="fa fa-triangle-exclamation"></i></div>' +
    '<h2>Invalid link</h2><p class="sub">This password reset link is missing or malformed. Please request a new one from the login page.</p>' +
    '<a class="reset-link" href="' + window.loginUrl + '">Back to Login</a>';
}

document.getElementById('resetForm')?.addEventListener('submit', function(e) {
  e.preventDefault();
  var errEl = document.getElementById('resetError');
  errEl.style.display = 'none';

  var newPassword = document.getElementById('newPassword').value;
  var confirmPassword = document.getElementById('confirmPassword').value;

  if (newPassword.length < 6) { errEl.textContent = 'Password must be at least 6 characters'; errEl.style.display = 'block'; return; }
  if (newPassword !== confirmPassword) { errEl.textContent = 'Passwords do not match'; errEl.style.display = 'block'; return; }

  var btn = document.getElementById('resetSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Resetting...';

  var fd = new FormData();
  fd.append('action', 'resetPassword');
  fd.append('token', token);
  fd.append('newPassword', newPassword);
  fd.append('confirmPassword', confirmPassword);

  fetch('customer_auth.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.success) {
        document.getElementById('resetCard').innerHTML =
          '<div class="reset-success">' +
          '<div class="reset-icon"><i class="fa fa-check"></i></div>' +
          '<h2>Password reset!</h2><p class="sub">' + res.message + '</p>' +
          '<a class="reset-link" href="' + window.loginUrl + '">Go to Login</a>' +
          '</div>';
      } else {
        errEl.textContent = res.message || 'Something went wrong';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-check"></i> Reset Password';
      }
    })
    .catch(function() {
      errEl.textContent = 'Network error. Please try again.';
      errEl.style.display = 'block';
      btn.disabled = false;
      btn.innerHTML = '<i class="fa fa-check"></i> Reset Password';
    });
});
</script>
</body>
</html>
