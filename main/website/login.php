<?php require_once __DIR__ . '/header.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<base href="<?php echo $website_base_href; ?>">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="restaurant-id" content="<?php echo htmlspecialchars($restaurant_id ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<?php include __DIR__ . '/cart_scope.php'; ?>
<title>Login - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" href="<?php echo htmlspecialchars($favicon_href ?? $local_favicon_svg, ENT_QUOTES, 'UTF-8'); ?>">
<?php require_once __DIR__ . '/font_links.php'; echo websiteFontLinks(['poppins', 'fontawesome']); ?>
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
<?php if ($host === 'triposhsymmetry.in'): ?>
  /* TEMPORARY (PhonePe approval, added 2026-08-18 — remove in ~2 days) */
  .phone-frame { max-width: 100%; margin: 0; border-radius: 0; }
<?php endif; ?>
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
/* ── Redesign: brand header, logo, sliding tabs, icon fields ── */
:root {
  --primary-red: <?php echo htmlspecialchars($primary_red ?? '#e53935', ENT_QUOTES, 'UTF-8'); ?>;
  --dark-red: <?php echo htmlspecialchars($dark_red ?? '#c62828', ENT_QUOTES, 'UTF-8'); ?>;
}
body { background: #eef0f4; }
.auth-band { position: relative; padding: 14px 14px 64px; background: linear-gradient(135deg, var(--primary-red), var(--dark-red)); color: #fff; overflow: hidden; }
.auth-band::before, .auth-band::after { content: ""; position: absolute; border-radius: 50%; background: rgba(255,255,255,0.08); }
.auth-band::before { width: 220px; height: 220px; right: -70px; top: -90px; }
.auth-band::after { width: 140px; height: 140px; left: -50px; bottom: -40px; }
.auth-band .back-btn { position: relative; z-index: 1; width: 38px; height: 38px; border-radius: 12px; background: rgba(255,255,255,0.18); font-size: 16px; backdrop-filter: blur(4px); }
.auth-band .band-name { position: relative; z-index: 1; margin-top: 18px; font-size: 13px; font-weight: 600; opacity: 0.9; letter-spacing: 0.02em; }
.auth-band h2 { position: relative; z-index: 1; margin-top: 2px; font-size: 24px; font-weight: 800; line-height: 1.2; }
.auth-band p { position: relative; z-index: 1; margin-top: 4px; font-size: 12.5px; opacity: 0.85; }
.auth-band h2, .auth-band p { padding-right: 82px; }
.auth-logo { position: absolute; z-index: 1; right: 18px; bottom: 26px; width: 64px; height: 64px; border-radius: 20px; background: #fff; display: flex; align-items: center; justify-content: center; overflow: hidden; box-shadow: 0 10px 24px rgba(0,0,0,0.18); animation: authFloat 3.2s ease-in-out infinite; }
.auth-logo img { width: 100%; height: 100%; object-fit: cover; }
.auth-logo span { font-size: 26px; font-weight: 800; color: var(--primary-red); }
.auth-sheet { position: relative; margin-top: -40px; background: #fff; border-radius: 26px 26px 0 0; padding: 22px 18px 36px; animation: authUp 0.5s cubic-bezier(.22,1,.36,1); }
.auth-tabs { position: relative; background: #f3f4f6; border-radius: 14px; padding: 4px; margin-bottom: 22px; }
.auth-ind { position: absolute; top: 4px; bottom: 4px; left: 4px; width: calc(50% - 4px); border-radius: 11px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: transform 0.35s cubic-bezier(.22,1,.36,1); }
.auth-tabs[data-tab="signup"] .auth-ind { transform: translateX(100%); }
.auth-tab { position: relative; z-index: 1; padding: 10px; font-size: 13.5px; transition: color 0.25s; }
.auth-tab.active { background: none; box-shadow: none; color: var(--primary-red); }
.form-group label { font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 7px; }
.field { position: relative; display: flex; align-items: center; height: 48px; border: 1.5px solid #e5e7eb; border-radius: 14px; background: #fafafa; transition: border-color 0.2s, background 0.2s, box-shadow 0.2s; }
.field:focus-within { border-color: var(--primary-red); background: #fff; box-shadow: 0 0 0 4px color-mix(in srgb, var(--primary-red) 12%, transparent); }
.field > i { width: 42px; text-align: center; color: #9ca3af; font-size: 14px; flex-shrink: 0; transition: color 0.2s; }
.field:focus-within > i { color: var(--primary-red); }
.field .dial { padding-right: 10px; margin-right: 10px; border-right: 1px solid #e5e7eb; font-size: 13.5px; font-weight: 600; color: #374151; }
.form-group .field input { flex: 1; min-width: 0; height: 100%; padding: 0 12px 0 0; border: 0; border-radius: 0; background: transparent; box-shadow: none; font-size: 14px; }
.form-group .field input:focus { box-shadow: none; }
.field.has-error, .field:has(input.error) { border-color: #ef4444; background: #fef2f2; }
.field .eye { width: 44px; height: 100%; border: 0; background: none; color: #9ca3af; font-size: 14px; cursor: pointer; flex-shrink: 0; }
.remember-row label input { accent-color: var(--primary-red); width: 15px; height: 15px; }
.remember-row a { color: var(--primary-red); }
.btn-auth-submit { height: 50px; padding: 0; border-radius: 14px; background: linear-gradient(135deg, var(--primary-red), var(--dark-red)); font-size: 15px; font-weight: 700; box-shadow: 0 10px 22px color-mix(in srgb, var(--primary-red) 30%, transparent); transition: transform 0.15s ease, box-shadow 0.2s; }
.btn-auth-submit:active { transform: scale(0.98); }
.auth-divider { margin: 22px 0 16px; }
.btn-guest { height: 48px; padding: 0; border-radius: 14px; border: 1.5px solid #e5e7eb; color: #374151; transition: background 0.2s, transform 0.15s; }
.btn-guest:active { transform: scale(0.98); }
.auth-perks { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 22px; }
.auth-perk { padding: 12px 6px; border-radius: 14px; background: #f9fafb; text-align: center; font-size: 11px; font-weight: 600; color: #4b5563; line-height: 1.3; }
.auth-perk i { display: block; margin-bottom: 6px; font-size: 16px; color: var(--primary-red); }
.auth-form-error { border-radius: 12px; }
#loginForm, #signupForm, #claimForm { animation: authFade 0.35s ease; }
.claim-card { margin-bottom: 18px; padding: 16px; border-radius: 16px; background: #fff7ed; border: 1px solid #fed7aa; color: #7c2d12; font-size: 14px; line-height: 1.5; }
.claim-card b { color: #111827; }
.claim-card .claim-pts { display: inline-flex; align-items: center; gap: 6px; margin-top: 8px; padding: 6px 12px; border-radius: 999px; background: #fff; color: #c2410c; font-weight: 700; font-size: 13px; }
.claim-card.bad { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
.claim-note { margin-top: 14px; color: #6b7280; font-size: 12px; text-align: center; line-height: 1.5; }
body.claim-mode #authTabs, body.claim-mode #loginForm, body.claim-mode #signupForm, body.claim-mode .auth-divider, body.claim-mode .btn-guest, body.claim-mode .auth-perks { display: none !important; }
@keyframes authUp { from { transform: translateY(24px); opacity: 0; } to { transform: none; opacity: 1; } }
@keyframes authFade { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
@keyframes authFloat { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
@media (prefers-reduced-motion: reduce) { .auth-sheet, .auth-logo, #loginForm, #signupForm { animation: none; } .auth-ind { transition: none; } }
</style>
<script src="rg-notify.js?v=<?php echo @filemtime(__DIR__ . '/rg-notify.js'); ?>"></script>
<script>RGNotify.setup({ toast: 'web' }); document.documentElement.style.setProperty('--rgn-bottom-m', 'calc(84px + env(safe-area-inset-bottom))');</script>
</head>
<body>
<div class="phone-frame">
  <div class="auth-band">
    <button class="back-btn" onclick="window.location.href='<?php echo restaurantPageUrl('menu'); ?>'" aria-label="Back"><i class="fa fa-arrow-left"></i></button>
    <div class="band-name"><?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></div>
    <h2 id="authTitle">Welcome back</h2>
    <p id="authSub">Log in to track your orders and check out faster</p>
    <div class="auth-logo"><?php if (!empty($restaurant_logo)): ?><img src="<?php echo htmlspecialchars($restaurant_logo, ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php else: ?><span><?php echo htmlspecialchars(mb_strtoupper(mb_substr($restaurant_name ?? 'R', 0, 1)), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?></div>
  </div>

  <div class="auth-sheet">
    <div class="auth-tabs" id="authTabs" data-tab="login">
      <span class="auth-ind"></span>
      <button class="auth-tab active" id="tabLogin" onclick="switchAuthTab('login')">Login</button>
      <button class="auth-tab" id="tabSignup" onclick="switchAuthTab('signup')">Sign Up</button>
    </div>

    <div class="auth-form-error" id="authError"></div>

    <!-- Complete your account (QR from the restaurant: ?claim=CODE) -->
    <form id="claimForm" class="hidden">
      <div class="claim-card" id="claimCard"><i class="fa-solid fa-spinner fa-spin"></i> Checking your link…</div>
      <div id="claimFields" class="hidden">
        <div class="form-group">
          <label for="claimEmail">Email address</label>
          <div class="field"><i class="fa-regular fa-envelope"></i>
            <input type="email" id="claimEmail" placeholder="your@email.com" autocomplete="email" required></div>
        </div>
        <div class="form-group">
          <label for="claimPassword">Choose a password</label>
          <div class="field"><i class="fa-solid fa-lock"></i>
            <input type="password" id="claimPassword" placeholder="At least 6 characters" autocomplete="new-password" required>
            <button type="button" class="eye" onclick="togglePw('claimPassword', this)" aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div>
        </div>
        <div class="form-group">
          <label for="claimConfirm">Confirm password</label>
          <div class="field"><i class="fa-solid fa-lock"></i>
            <input type="password" id="claimConfirm" placeholder="Re-enter your password" autocomplete="new-password" required></div>
        </div>
        <div class="remember-row"><label><input type="checkbox" id="claimRemember" checked> Remember me</label></div>
        <button type="submit" class="btn-auth-submit" id="claimSubmitBtn">Finish my account <i class="fa fa-arrow-right"></i></button>
      </div>
      <p class="claim-note">You’ll log in with your phone number and this password. Your points and order history stay with you.</p>
    </form>

    <!-- Login Form -->
    <form id="loginForm">
      <div class="form-group">
        <label for="loginPhone">Phone number</label>
        <div class="field"><i class="fa-solid fa-phone"></i><span class="dial"><?php echo htmlspecialchars($phone_dial_code ?? '+91'); ?></span>
          <input type="tel" id="loginPhone" placeholder="Enter your phone number" autocomplete="tel-national" required></div>
      </div>
      <div class="form-group">
        <label for="loginPassword">Password</label>
        <div class="field"><i class="fa-solid fa-lock"></i>
          <input type="password" id="loginPassword" placeholder="Enter your password" autocomplete="current-password" required>
          <button type="button" class="eye" onclick="togglePw('loginPassword', this)" aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div>
      </div>
      <div class="remember-row">
        <label><input type="checkbox" id="loginRemember" checked> Remember me</label>
        <a href="#" onclick="openForgotModal();return false;">Forgot password?</a>
      </div>
      <div class="form-group hidden" id="loginOtpGroup">
        <label for="loginOtpCode">Verification code</label>
        <div class="field"><i class="fa-solid fa-shield-halved"></i>
          <input type="text" id="loginOtpCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="6-digit code from WhatsApp"></div>
        <div class="remember-row" style="margin-top:8px;margin-bottom:0;">
          <span style="color:#999;">Sent via WhatsApp</span>
          <a href="#" id="loginOtpResend" onclick="event.preventDefault();">Resend code</a>
        </div>
      </div>
      <button type="submit" class="btn-auth-submit" id="loginSubmitBtn"><span id="loginSubmitBtnText">Login</span> <i class="fa fa-arrow-right"></i></button>
    </form>

    <!-- Signup Form -->
    <form id="signupForm" class="hidden">
      <div class="form-group">
        <label for="signupName">Full name</label>
        <div class="field"><i class="fa-regular fa-user"></i>
          <input type="text" id="signupName" placeholder="Enter your full name" autocomplete="name" required></div>
      </div>
      <div class="form-group">
        <label for="signupPhone">Phone number</label>
        <div class="field"><i class="fa-solid fa-phone"></i><span class="dial"><?php echo htmlspecialchars($phone_dial_code ?? '+91'); ?></span>
          <input type="tel" id="signupPhone" placeholder="Enter your phone number" autocomplete="tel-national" required></div>
      </div>
      <div class="form-group">
        <label for="signupEmail">Email address</label>
        <div class="field"><i class="fa-regular fa-envelope"></i>
          <input type="email" id="signupEmail" placeholder="your@email.com" autocomplete="email" required></div>
      </div>
      <div class="form-group">
        <label for="signupPassword">Password</label>
        <div class="field"><i class="fa-solid fa-lock"></i>
          <input type="password" id="signupPassword" placeholder="At least 6 characters" autocomplete="new-password" required>
          <button type="button" class="eye" onclick="togglePw('signupPassword', this)" aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div>
      </div>
      <div class="form-group">
        <label for="signupConfirmPassword">Confirm password</label>
        <div class="field"><i class="fa-solid fa-lock"></i>
          <input type="password" id="signupConfirmPassword" placeholder="Re-enter your password" autocomplete="new-password" required></div>
      </div>
      <div class="form-group">
        <label for="signupBirthday">Birthday <span style="font-weight:400;color:#9ca3af">(optional, for a birthday treat)</span></label>
        <div class="field"><i class="fa-solid fa-cake-candles"></i>
          <input type="date" id="signupBirthday" max="<?php echo date('Y-m-d'); ?>"></div>
      </div>
      <label class="optin-row" style="display:flex;gap:10px;align-items:flex-start;margin:4px 0 12px;padding:10px 12px;border-radius:12px;background:#f0fdf4;border:1px solid #bbf7d0;font-size:13px;color:#374151;cursor:pointer;">
        <input type="checkbox" id="signupOptin" style="width:17px;height:17px;margin-top:2px;accent-color:#16a34a;flex-shrink:0;">
        <span><b style="display:block;color:#111827">Send my rewards and offers on WhatsApp</b>Gift codes, birthday treats and the odd special. Stop anytime.</span>
      </label>
      <div class="remember-row">
        <label><input type="checkbox" id="signupRemember" checked> Remember me</label>
      </div>
      <div class="form-group hidden" id="signupOtpGroup">
        <label for="signupOtpCode">Verification code</label>
        <div class="field"><i class="fa-solid fa-shield-halved"></i>
          <input type="text" id="signupOtpCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="6-digit code from WhatsApp"></div>
        <div class="remember-row" style="margin-top:8px;margin-bottom:0;">
          <span style="color:#999;">Sent via WhatsApp</span>
          <a href="#" id="signupOtpResend" onclick="event.preventDefault();">Resend code</a>
        </div>
      </div>
      <button type="submit" class="btn-auth-submit" id="signupSubmitBtn">Create account <i class="fa fa-arrow-right"></i></button>
    </form>

    <div class="auth-divider">or</div>
    <button type="button" class="btn-guest" onclick="continueAsGuest()">Continue as guest</button>

    <div class="auth-perks">
      <div class="auth-perk"><i class="fa-solid fa-location-crosshairs"></i>Track your order</div>
      <div class="auth-perk"><i class="fa-solid fa-bolt"></i>Faster checkout</div>
      <div class="auth-perk"><i class="fa-solid fa-clock-rotate-left"></i>Order history</div>
    </div>
  </div>
</div>
<!-- Forgot Password Modal -->
<div id="forgotModalContainer"></div>

<script>
window.restaurantId = <?php echo json_encode($restaurant_id ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.referralCode = <?php echo json_encode($referral_code ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
<?php
$redirectTargetPage = isset($_GET['redirect']) ? trim($_GET['redirect']) : 'menu';
$allowedRedirectPages = ['menu', 'cart', 'profile', 'about', 'contact', 'plans', 'subscribe', 'my-subscription', 'catering', 'loyalty'];
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
window.claimCode = <?php echo json_encode(preg_match('/^[a-f0-9]{32}$/', strtolower((string)($_GET['claim'] ?? ''))) ? strtolower($_GET['claim']) : '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.claimRedirect = <?php echo json_encode(restaurantPageUrl('loyalty'), JSON_HEX_TAG | JSON_HEX_AMP); ?>;

// The restaurant's QR code: show who this is, then set an email and password
if (window.claimCode && !window.alreadyLoggedIn) (function () {
  document.body.classList.add('claim-mode');
  document.getElementById('claimForm').classList.remove('hidden');
  document.getElementById('authTitle').textContent = 'Finish your account';
  document.getElementById('authSub').textContent = 'Your points and visits are already saved. Add an email and password to use them online.';
  var card = document.getElementById('claimCard');
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var fd = new FormData(); fd.append('action', 'claimInfo'); fd.append('restaurant_id', window.restaurantId); fd.append('code', window.claimCode);
  fetch('customer_auth.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
    if (!d.success) { card.className = 'claim-card bad'; card.textContent = d.message || 'This link does not work any more.'; return; }
    if (d.has_account) { card.innerHTML = 'You already have an account, <b>' + esc(d.name) + '</b>. Log in with your phone number.'; document.body.classList.remove('claim-mode'); return; }
    var perk = d.program === 'points' ? d.points.toLocaleString() + ' points waiting' : d.program === 'stamps' ? d.stamps + ' stamps on your card' : d.visits + ' visit' + (d.visits === 1 ? '' : 's') + ' saved';
    card.innerHTML = 'Hi <b>' + esc(d.name) + '</b>! This account is for <b>' + esc(d.phone) + '</b>.<br><span class="claim-pts"><i class="fa-solid fa-gift"></i>' + esc(perk) + '</span>';
    if (d.email) document.getElementById('claimEmail').value = d.email;
    document.getElementById('claimFields').classList.remove('hidden');
  }).catch(function () { card.className = 'claim-card bad'; card.textContent = 'Could not check the link. Please try again.'; });
  document.getElementById('claimForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = document.getElementById('claimSubmitBtn'), pw = document.getElementById('claimPassword').value;
    if (pw.length < 6) { showAuthError('Password must be at least 6 characters'); return; }
    if (pw !== document.getElementById('claimConfirm').value) { showAuthError('Passwords do not match'); return; }
    var f = new FormData(); f.append('action', 'claim'); f.append('restaurant_id', window.restaurantId); f.append('code', window.claimCode);
    f.append('email', document.getElementById('claimEmail').value.trim()); f.append('password', pw); f.append('confirmPassword', document.getElementById('claimConfirm').value);
    if (document.getElementById('claimRemember').checked) f.append('remember', '1');
    btn.disabled = true;
    fetch('customer_auth.php', { method: 'POST', body: f }).then(function (r) { return r.json(); }).then(function (d) {
      btn.disabled = false;
      if (!d.success) { showAuthError(d.message || 'Could not finish the account.'); return; }
      try { if (d.customer) localStorage.setItem('customerDetails', JSON.stringify({ name: d.customer.name, phone: d.customer.phone, email: d.customer.email })); } catch (er) {}
      card.className = 'claim-card'; card.innerHTML = '<b>All set!</b> Taking you to your rewards…';
      document.getElementById('claimFields').classList.add('hidden');
      setTimeout(function () { window.location.href = window.claimRedirect; }, 700);
    }).catch(function () { btn.disabled = false; showAuthError('Network error. Please try again.'); });
  });
})();

if (window.alreadyLoggedIn) {
  window.location.href = window.redirectUrl;
}

// Show / hide a password field
function togglePw(id, btn) {
  var inp = document.getElementById(id);
  if (!inp) return;
  var show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  btn.innerHTML = show ? '<i class="fa-regular fa-eye-slash"></i>' : '<i class="fa-regular fa-eye"></i>';
  btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
}

function switchAuthTab(tab) {
  var tabs = document.getElementById('authTabs');
  if (tabs) tabs.setAttribute('data-tab', tab);
  var title = document.getElementById('authTitle'), sub = document.getElementById('authSub');
  if (title) title.textContent = tab === 'signup' ? 'Create your account' : 'Welcome back';
  if (sub) sub.textContent = tab === 'signup' ? 'It takes a minute — then reorder in a tap' : 'Log in to track your orders and check out faster';
  document.getElementById('tabLogin').classList.toggle('active', tab === 'login');
  document.getElementById('tabSignup').classList.toggle('active', tab === 'signup');
  document.getElementById('loginForm').classList.toggle('hidden', tab !== 'login');
  document.getElementById('signupForm').classList.toggle('hidden', tab !== 'signup');
  hideAuthError();
  // Abandoning signup (its OTP, if any, expires server-side in 5 minutes
  // anyway) — reset so a later visit starts clean.
  if (tab === 'login' && typeof resetSignupOtpState === 'function') {
    resetSignupOtpState();
  }
  if (tab === 'signup' && typeof resetLoginOtpState === 'function') {
    resetLoginOtpState();
  }
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
  if (window.RGNotify) return RGNotify.toast(msg, type || 'success', { html: true });
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

// A random id generated once per browser and kept in localStorage — this is
// what the server's new-device WhatsApp OTP gate (device_trust_helpers.php)
// keys trust to. Clearing site data or logging in from a different
// browser/device means a new id, so the OTP step runs again exactly once
// there.
function getCustomerDeviceId() {
  var KEY = 'rg_customer_device_id';
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

// Login is normally one step. If this device hasn't logged into this
// account before, customer_auth.php instead responds with
// requires_otp=true (and sends a WhatsApp code) — the form then locks
// phone/password and asks for that code, resubmitting the same credentials
// plus otp_code. Once verified, this device is trusted forever for that
// customer.
var loginOtpSent = false;
var loginResendTimer = null;
var loginFieldIds = ['loginPhone', 'loginPassword'];

function resetLoginOtpState() {
  loginOtpSent = false;
  document.getElementById('loginOtpGroup').classList.add('hidden');
  document.getElementById('loginOtpCode').value = '';
  loginFieldIds.forEach(function(id) { document.getElementById(id).disabled = false; });
  document.getElementById('loginSubmitBtnText').textContent = 'Login';
  if (loginResendTimer) { clearInterval(loginResendTimer); loginResendTimer = null; }
  var resend = document.getElementById('loginOtpResend');
  resend.style.pointerEvents = 'auto';
  resend.textContent = 'Resend code';
}

function startLoginResendCooldown(seconds) {
  var resend = document.getElementById('loginOtpResend');
  var remaining = seconds;
  resend.style.pointerEvents = 'none';
  resend.textContent = 'Resend code (' + remaining + 's)';
  if (loginResendTimer) clearInterval(loginResendTimer);
  loginResendTimer = setInterval(function() {
    remaining -= 1;
    if (remaining <= 0) {
      clearInterval(loginResendTimer);
      loginResendTimer = null;
      resend.style.pointerEvents = 'auto';
      resend.textContent = 'Resend code';
    } else {
      resend.textContent = 'Resend code (' + remaining + 's)';
    }
  }, 1000);
}

function submitCustomerLogin(phone, password, otpCode) {
  var fd = new FormData();
  fd.append('action', 'login');
  fd.append('restaurant_id', window.restaurantId);
  fd.append('phone', phone);
  fd.append('password', password);
  fd.append('remember', document.getElementById('loginRemember').checked ? '1' : '');
  fd.append('device_id', getCustomerDeviceId());
  if (otpCode) fd.append('otp_code', otpCode);
  return fetch('customer_auth.php', { method: 'POST', body: fd }).then(function(r) { return r.json(); });
}

document.getElementById('loginOtpResend').addEventListener('click', function() {
  var phone = document.getElementById('loginPhone').value.trim();
  var password = document.getElementById('loginPassword').value;
  submitCustomerLogin(phone, password, '').then(function(res) {
    if (res.requires_otp) {
      showToast(res.message || 'A new code has been sent via WhatsApp.', 'success');
      startLoginResendCooldown(60);
      useLocalOtp(res, 'loginOtpCode');
    } else if (!res.success) {
      showAuthError(res.message || 'Could not resend the code.');
    }
  }).catch(function() {
    showAuthError('Network error. Please try again.');
  });
});

document.getElementById('loginForm').addEventListener('submit', function(e) {
  e.preventDefault();
  hideAuthError();
  var phone = document.getElementById('loginPhone').value.trim();
  var password = document.getElementById('loginPassword').value;
  if (!phone || !password) { showAuthError('Please enter your phone number and password'); return; }

  if (loginOtpSent && !document.getElementById('loginOtpCode').value.trim()) {
    showAuthError('Please enter the verification code.');
    return;
  }

  var btn = document.getElementById('loginSubmitBtn');
  var btnText = document.getElementById('loginSubmitBtnText');
  btn.disabled = true;
  btnText.textContent = loginOtpSent ? 'Verifying...' : 'Logging in...';

  var otpCode = loginOtpSent ? document.getElementById('loginOtpCode').value.trim() : '';

  submitCustomerLogin(phone, password, otpCode)
    .then(function(res) {
      if (res.requires_otp) {
        loginOtpSent = true;
        document.getElementById('loginOtpGroup').classList.remove('hidden');
        loginFieldIds.forEach(function(id) { document.getElementById(id).disabled = true; });
        btn.disabled = false;
        btnText.textContent = 'Verify & Login';
        showToast(res.message || 'A verification code has been sent via WhatsApp.', 'success');
        startLoginResendCooldown(60);
        document.getElementById('loginOtpCode').focus();
        useLocalOtp(res, 'loginOtpCode');
        return;
      }

      if (res.success) {
        try { localStorage.setItem('customerDetails', JSON.stringify(res.customer)); } catch(e) {}
        showToast(res.message, 'success');
        resetLoginOtpState();
        setTimeout(function() { window.location.href = window.redirectUrl; }, 500);
      } else {
        showAuthError(res.message || 'Login failed');
        btn.disabled = false;
        btnText.textContent = loginOtpSent ? 'Verify & Login' : 'Login';
      }
    })
    .catch(function() {
      showAuthError('Network error. Please try again.');
      btn.disabled = false;
      btnText.textContent = loginOtpSent ? 'Verify & Login' : 'Login';
    });
});

// Signup is two steps: (1) validate the form and send a WhatsApp OTP to
// the phone number, (2) verify that code, then actually create the
// account. Scoped under this restaurant's own id + 'customer_signup'
// purpose — customer_auth.php's handleCustomerSignup() checks for a
// matching verified row under that same scope before inserting.
// Local copy (XAMPP): no WhatsApp — the server hands back the code (local_code).
// Fill it in, say so under the field, and return true so the caller can carry on.
function useLocalOtp(res, inputId) {
  if (!res || !res.local_code) return false;
  var input = document.getElementById(inputId);
  if (!input) return false;
  input.value = res.local_code;
  var noteId = inputId + 'LocalNote';
  var p = document.getElementById(noteId);
  if (!p) {
    p = document.createElement('div');
    p.id = noteId;
    p.style.cssText = 'margin:8px 0 4px;padding:10px 12px;border-radius:12px;background:#ecfdf5;border:1.5px dashed #10b981;color:#047857;font-size:12.5px;font-weight:600;line-height:1.4;';
    input.parentNode.parentNode.insertBefore(p, input.parentNode.nextSibling);
  }
  p.textContent = 'Local mode: WhatsApp is not used on this computer, so the code is shown here and already filled in.';
  var big = document.createElement('div');
  big.style.cssText = 'margin-top:6px;font-size:22px;font-weight:800;letter-spacing:6px;color:#065f46;font-family:monospace;';
  big.textContent = res.local_code;
  p.appendChild(big);
  return true;
}

var signupOtpSent = false;
var signupResendTimer = null;
var signupFieldIds = ['signupName', 'signupPhone', 'signupEmail', 'signupPassword', 'signupConfirmPassword'];

function resetSignupOtpState() {
  signupOtpSent = false;
  document.getElementById('signupOtpGroup').classList.add('hidden');
  document.getElementById('signupOtpCode').value = '';
  signupFieldIds.forEach(function(id) { document.getElementById(id).disabled = false; });
  if (signupResendTimer) { clearInterval(signupResendTimer); signupResendTimer = null; }
  var resend = document.getElementById('signupOtpResend');
  resend.style.pointerEvents = 'auto';
  resend.textContent = 'Resend code';
}

function startSignupResendCooldown(seconds) {
  var resend = document.getElementById('signupOtpResend');
  var remaining = seconds;
  resend.style.pointerEvents = 'none';
  resend.textContent = 'Resend code (' + remaining + 's)';
  if (signupResendTimer) clearInterval(signupResendTimer);
  signupResendTimer = setInterval(function() {
    remaining -= 1;
    if (remaining <= 0) {
      clearInterval(signupResendTimer);
      signupResendTimer = null;
      resend.style.pointerEvents = 'auto';
      resend.textContent = 'Resend code';
    } else {
      resend.textContent = 'Resend code (' + remaining + 's)';
    }
  }, 1000);
}

function sendSignupOtp(phoneDigits) {
  var fd = new FormData();
  fd.append('action', 'send');
  fd.append('restaurant_id', window.restaurantId);
  fd.append('purpose', 'customer_signup');
  fd.append('phone', phoneDigits);
  return fetch('otp.php', { method: 'POST', body: fd }).then(function(r) { return r.json(); });
}

document.getElementById('signupOtpResend').addEventListener('click', function() {
  var phoneDigits = document.getElementById('signupPhone').value.replace(/\D/g, '');
  sendSignupOtp(phoneDigits).then(function(res) {
    if (res.success) {
      showToast('A new code has been sent via WhatsApp.', 'success');
      startSignupResendCooldown(60);
      useLocalOtp(res, 'signupOtpCode');
    } else {
      showAuthError(res.message || 'Could not resend the code.');
    }
  }).catch(function() {
    showAuthError('Network error. Please try again.');
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
  var phoneDigits = phone.replace(/\D/g, '');

  if (!name || !phone || !email || !password) { showAuthError('Please fill in all fields'); return; }
  if (password.length < 6) { showAuthError('Password must be at least 6 characters'); return; }
  if (password !== confirmPassword) { showAuthError('Passwords do not match'); return; }

  var btn = document.getElementById('signupSubmitBtn');

  // Step 1: form is valid but no code sent yet — send the OTP and stop
  // here. Fields are locked so the code that's about to be verified
  // matches what gets submitted.
  if (!signupOtpSent) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Sending code...';
    sendSignupOtp(phoneDigits).then(function(res) {
      btn.disabled = false;
      if (res.success) {
        signupOtpSent = true;
        document.getElementById('signupOtpGroup').classList.remove('hidden');
        signupFieldIds.forEach(function(id) { document.getElementById(id).disabled = true; });
        startSignupResendCooldown(60);
        btn.innerHTML = '<i class="fa fa-shield-halved"></i> Verify & Create Account';
        if (useLocalOtp(res, 'signupOtpCode')) {
          // Local mode: no code to wait for — verify and create the account straight away
          document.getElementById('signupForm').requestSubmit();
          return;
        }
        showToast('A verification code has been sent via WhatsApp.', 'success');
        document.getElementById('signupOtpCode').focus();
      } else {
        btn.innerHTML = 'Create account <i class="fa fa-arrow-right"></i>';
        showAuthError(res.message || 'Could not send the verification code.');
      }
    }).catch(function() {
      btn.disabled = false;
      btn.innerHTML = 'Create account <i class="fa fa-arrow-right"></i>';
      showAuthError('Network error. Please try again.');
    });
    return;
  }

  // Step 2: OTP already sent — verify the code, then create the account.
  var code = document.getElementById('signupOtpCode').value.trim();
  if (!code) { showAuthError('Please enter the verification code.'); return; }

  btn.disabled = true;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Verifying...';

  var verifyFd = new FormData();
  verifyFd.append('action', 'verify');
  verifyFd.append('restaurant_id', window.restaurantId);
  verifyFd.append('purpose', 'customer_signup');
  verifyFd.append('phone', phoneDigits);
  verifyFd.append('code', code);

  fetch('otp.php', { method: 'POST', body: verifyFd })
    .then(function(r) { return r.json(); })
    .then(function(verifyRes) {
      if (!verifyRes.success) {
        showAuthError(verifyRes.message || 'Invalid code. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-shield-halved"></i> Verify & Create Account';
        return;
      }

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
      fd.append('birthday', (document.getElementById('signupBirthday') || {}).value || '');
      if ((document.getElementById('signupOptin') || {}).checked) fd.append('whatsapp_optin', '1');
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
            btn.innerHTML = '<i class="fa fa-shield-halved"></i> Verify & Create Account';
          }
        })
        .catch(function() {
          showAuthError('Network error. Please try again.');
          btn.disabled = false;
          btn.innerHTML = '<i class="fa fa-shield-halved"></i> Verify & Create Account';
        });
    })
    .catch(function() {
      showAuthError('Network error. Please try again.');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa fa-shield-halved"></i> Verify & Create Account';
    });
});

/* ── Forgot Password ── */
// Two independent reset paths share this modal: the original email-link
// flow, and a WhatsApp-OTP flow that resets the password right here (no
// email round-trip, send code -> enter code + new password). Rebuilt via
// innerHTML like the rest of this modal, so each step is its own render
// function rather than toggling hidden fields.
var forgotResendTimer = null;

function openForgotModal() {
  renderForgotEmailStep();
}
function closeForgotModal() {
  document.getElementById('forgotModalContainer').innerHTML = '';
  if (forgotResendTimer) { clearInterval(forgotResendTimer); forgotResendTimer = null; }
}

function renderForgotEmailStep() {
  var container = document.getElementById('forgotModalContainer');
  container.innerHTML =
    '<div class="modal-overlay" onclick="if(event.target===this)closeForgotModal()">' +
    '<div class="modal-box">' +
    '<button class="modal-close" onclick="closeForgotModal()">&times;</button>' +
    '<h3>Reset your password</h3>' +
    '<p>Enter the email you signed up with and we\'ll send you a reset link.</p>' +
    '<div class="form-group"><input type="email" id="forgotEmail" placeholder="your@email.com"></div>' +
    '<div class="auth-form-error" id="forgotError"></div>' +
    '<button type="button" class="btn-auth-submit" id="forgotSubmitBtn" onclick="submitForgotEmailLink()"><i class="fa fa-paper-plane"></i> Send Reset Link</button>' +
    '<div style="text-align:center;margin-top:14px;"><a href="#" onclick="event.preventDefault();renderForgotOtpStep1();" style="color:#d63031;font-weight:600;font-size:12px;text-decoration:none;">Reset via WhatsApp instead</a></div>' +
    '</div></div>';
}

function renderForgotOtpStep1(prefillEmail) {
  var container = document.getElementById('forgotModalContainer');
  container.innerHTML =
    '<div class="modal-overlay" onclick="if(event.target===this)closeForgotModal()">' +
    '<div class="modal-box">' +
    '<button class="modal-close" onclick="closeForgotModal()">&times;</button>' +
    '<h3>Reset via WhatsApp</h3>' +
    '<p>Enter the email on your account — we\'ll send a verification code via WhatsApp to the phone on file.</p>' +
    '<div class="form-group"><input type="email" id="forgotEmail" placeholder="your@email.com" value="' + (prefillEmail ? prefillEmail.replace(/"/g, '&quot;') : '') + '"></div>' +
    '<div class="auth-form-error" id="forgotError"></div>' +
    '<button type="button" class="btn-auth-submit" id="forgotSubmitBtn" onclick="submitForgotOtpSend()"><i class="fa fa-shield-halved"></i> Send Code</button>' +
    '<div style="text-align:center;margin-top:14px;"><a href="#" onclick="event.preventDefault();renderForgotEmailStep();" style="color:#d63031;font-weight:600;font-size:12px;text-decoration:none;">Reset via email link instead</a></div>' +
    '</div></div>';
}

function renderForgotOtpStep2(email, message) {
  var container = document.getElementById('forgotModalContainer');
  container.innerHTML =
    '<div class="modal-overlay" onclick="if(event.target===this)closeForgotModal()">' +
    '<div class="modal-box">' +
    '<button class="modal-close" onclick="closeForgotModal()">&times;</button>' +
    '<h3>Enter verification code</h3>' +
    '<p>' + (message || 'A code was sent via WhatsApp.') + '</p>' +
    '<div class="form-group"><input type="text" id="forgotOtpCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="6-digit code"></div>' +
    '<div class="form-group"><input type="password" id="forgotNewPassword" placeholder="New password (min 6 characters)"></div>' +
    '<div class="form-group"><input type="password" id="forgotConfirmPassword" placeholder="Confirm new password"></div>' +
    '<div class="auth-form-error" id="forgotError"></div>' +
    '<button type="button" class="btn-auth-submit" id="forgotSubmitBtn" onclick="submitForgotOtpVerify(\'' + email.replace(/'/g, "\\'") + '\')"><i class="fa fa-shield-halved"></i> Verify &amp; Reset Password</button>' +
    '<div style="text-align:center;margin-top:14px;"><a href="#" id="forgotOtpResend" onclick="event.preventDefault();resendForgotOtp(\'' + email.replace(/'/g, "\\'") + '\');" style="color:#d63031;font-weight:600;font-size:12px;text-decoration:none;">Resend code</a></div>' +
    '</div></div>';
  startForgotResendCooldown(60);
}

function startForgotResendCooldown(seconds) {
  var resend = document.getElementById('forgotOtpResend');
  if (!resend) return;
  var remaining = seconds;
  resend.style.pointerEvents = 'none';
  resend.textContent = 'Resend code (' + remaining + 's)';
  if (forgotResendTimer) clearInterval(forgotResendTimer);
  forgotResendTimer = setInterval(function() {
    remaining -= 1;
    var el = document.getElementById('forgotOtpResend');
    if (!el) { clearInterval(forgotResendTimer); forgotResendTimer = null; return; }
    if (remaining <= 0) {
      clearInterval(forgotResendTimer);
      forgotResendTimer = null;
      el.style.pointerEvents = 'auto';
      el.textContent = 'Resend code';
    } else {
      el.textContent = 'Resend code (' + remaining + 's)';
    }
  }, 1000);
}

function submitForgotEmailLink() {
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

function submitForgotOtpSend() {
  var email = document.getElementById('forgotEmail').value.trim();
  var errEl = document.getElementById('forgotError');
  errEl.style.display = 'none';
  if (!email) { errEl.textContent = 'Please enter your email'; errEl.style.display = 'block'; return; }

  var btn = document.getElementById('forgotSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Sending...';

  var fd = new FormData();
  fd.append('action', 'forgotPasswordOtp');
  fd.append('restaurant_id', window.restaurantId);
  fd.append('email', email);

  fetch('customer_auth.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      renderForgotOtpStep2(email, res.message || 'If an account exists for that email, a code has been sent via WhatsApp.');
      useLocalOtp(res, 'forgotOtpCode');
    })
    .catch(function() {
      errEl.textContent = 'Network error. Please try again.';
      errEl.style.display = 'block';
      btn.disabled = false;
      btn.innerHTML = '<i class="fa fa-shield-halved"></i> Send Code';
    });
}

function resendForgotOtp(email) {
  var fd = new FormData();
  fd.append('action', 'forgotPasswordOtp');
  fd.append('restaurant_id', window.restaurantId);
  fd.append('email', email);
  fetch('customer_auth.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      startForgotResendCooldown(60);
      useLocalOtp(res, 'forgotOtpCode');
    })
    .catch(function() {});
}

function submitForgotOtpVerify(email) {
  var code = document.getElementById('forgotOtpCode').value.trim();
  var newPassword = document.getElementById('forgotNewPassword').value;
  var confirmPassword = document.getElementById('forgotConfirmPassword').value;
  var errEl = document.getElementById('forgotError');
  errEl.style.display = 'none';

  if (!code) { errEl.textContent = 'Please enter the verification code'; errEl.style.display = 'block'; return; }
  if (newPassword.length < 6) { errEl.textContent = 'New password must be at least 6 characters long'; errEl.style.display = 'block'; return; }
  if (newPassword !== confirmPassword) { errEl.textContent = 'New passwords do not match'; errEl.style.display = 'block'; return; }

  var btn = document.getElementById('forgotSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Verifying...';

  var fd = new FormData();
  fd.append('action', 'resetPasswordOtp');
  fd.append('restaurant_id', window.restaurantId);
  fd.append('email', email);
  fd.append('otp_code', code);
  fd.append('newPassword', newPassword);
  fd.append('confirmPassword', confirmPassword);

  fetch('customer_auth.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.success) {
        document.querySelector('.modal-box').innerHTML =
          '<button class="modal-close" onclick="closeForgotModal()">&times;</button>' +
          '<h3>Password reset</h3><p>' + res.message + '</p>' +
          '<button type="button" class="btn-auth-submit" onclick="closeForgotModal()"><i class="fa fa-check"></i> Done</button>';
      } else {
        errEl.textContent = res.message || 'Invalid or expired code';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-shield-halved"></i> Verify &amp; Reset Password';
      }
    })
    .catch(function() {
      errEl.textContent = 'Network error. Please try again.';
      errEl.style.display = 'block';
      btn.disabled = false;
      btn.innerHTML = '<i class="fa fa-shield-halved"></i> Verify &amp; Reset Password';
    });
}
</script>
</body>
</html>
