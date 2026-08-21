<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../config/meal_subscription_schema.php';
if (!mealSubscriptionsFeatureEnabled($conn, $restaurant_id)) {
    header('Location: ' . restaurantPageUrl('menu'));
    exit();
}

if (!$logged_in_customer) {
    $loginBase = restaurantPageUrl('login');
    $sep = strpos($loginBase, '?') !== false ? '&' : '?';
    header('Location: ' . $loginBase . $sep . 'redirect=my-subscription');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<base href="<?php echo $website_base_href; ?>">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="restaurant-id" content="<?php echo htmlspecialchars($restaurant_id ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<title>My Subscription - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" href="<?php echo htmlspecialchars($favicon_href ?? $local_favicon_svg, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --primary-red: <?php echo htmlspecialchars($primary_red, ENT_QUOTES, 'UTF-8'); ?>;
  --dark-red: <?php echo htmlspecialchars($dark_red, ENT_QUOTES, 'UTF-8'); ?>;
  --site-font: <?php echo $font_family_css; ?>;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--site-font); background: #e8ecf2; color: #1a1b1f; min-height: 100vh; }
.phone-frame { max-width: 425px; margin: 0 auto; min-height: 100vh; background: #fff; position: relative; box-shadow: 0 0 40px rgba(0,0,0,0.08); }
@media (min-width: 768px) { .phone-frame { margin: 20px auto; min-height: calc(100vh - 40px); border-radius: 28px; overflow: hidden; } <?php if ($host === 'triposhsymmetry.in'): ?>.phone-frame { max-width: 100%; margin: 0; border-radius: 0; }<?php endif; ?> }

.pr-share-header { display: flex; align-items: center; gap: 12px; padding: 16px 12px 12px; border-bottom: 1.5px solid #eee; }
.pr-share-header h1 { font-size: 18px; font-weight: 700; flex: 1; }
.back-btn { display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 8px; background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031)); color: #fff; border: none; cursor: pointer; font-size: 20px; flex-shrink: 0; }

.content { padding: 14px; }
.loading { text-align: center; padding: 40px; color: #999; }
.empty-state { text-align: center; padding: 50px 20px; color: #999; }
.empty-state i { font-size: 44px; margin-bottom: 14px; opacity: 0.4; }
.empty-state .browse-btn { margin-top: 14px; display: inline-block; padding: 12px 22px; border-radius: 10px; background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031)); color: #fff; text-decoration: none; font-weight: 600; font-size: 13px; }

.sub-card { border: 2px solid #e8e0d8; border-radius: 16px; padding: 18px; margin-bottom: 18px; background: #fff; }
.sub-card .sub-name { font-size: 16px; font-weight: 700; margin-bottom: 6px; }
.sub-status { font-size: 11.5px; font-weight: 600; padding: 3px 10px; border-radius: 20px; display: inline-block; margin-bottom: 12px; }
.sub-status.active { background: #d1fae5; color: #065f46; }
.sub-status.paused { background: #fef3c7; color: #92400e; }
.sub-status.pending_payment { background: #e5e7eb; color: #374151; }
.sub-status.completed { background: #dbeafe; color: #1e40af; }
.sub-status.cancelled { background: #fee2e2; color: #991b1b; }

.credit-bar-wrap { background: #f3f4f6; border-radius: 10px; height: 10px; overflow: hidden; margin: 8px 0 6px; }
.credit-bar { height: 100%; background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031)); }
.credit-label { font-size: 12.5px; color: #666; margin-bottom: 14px; }
.credit-label b { color: #1a1b1f; }

.sub-actions { display: flex; gap: 10px; margin-bottom: 14px; }
.sub-actions button { flex: 1; padding: 10px; border-radius: 8px; border: none; font-weight: 600; font-size: 13px; font-family: var(--site-font); cursor: pointer; }
.btn-pause { background: #fef3c7; color: #92400e; }
.btn-resume { background: #d1fae5; color: #065f46; }
.sub-actions button:disabled { opacity: 0.6; cursor: not-allowed; }

.skip-section { border-top: 1px solid #f0f0f0; padding-top: 12px; margin-top: 6px; }
.skip-section .skip-title { font-size: 12.5px; font-weight: 600; color: #555; margin-bottom: 8px; }
.skip-add-row { display: flex; gap: 8px; margin-bottom: 10px; }
.skip-add-row input { flex: 1; padding: 9px 10px; border: 1.5px solid #ddd; border-radius: 8px; font-size: 12.5px; font-family: var(--site-font); }
.skip-add-row button { padding: 9px 14px; border: none; border-radius: 8px; background: #1a3934; color: #fff; font-size: 12.5px; font-weight: 600; cursor: pointer; }
.skip-list { display: flex; flex-wrap: wrap; gap: 6px; }
.skip-chip { background: #f3f4f6; color: #374151; padding: 5px 10px; border-radius: 16px; font-size: 11.5px; display: flex; align-items: center; gap: 6px; }
.skip-chip .remove-skip { cursor: pointer; color: #ef4444; font-weight: 700; }

.toast-notification { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%); padding: 14px 24px; border-radius: 14px; font-size: 13px; font-weight: 500; z-index: 99999; box-shadow: 0 8px 30px rgba(0,0,0,0.2); opacity: 0; transition: all 0.35s cubic-bezier(0.4,0,0.2,1); transform: translateX(-50%) translateY(24px); pointer-events: none; max-width: 90%; text-align: center; }
.toast-notification.show { opacity: 1; transform: translateX(-50%) translateY(0); }
.toast-notification.success { background: #10b981; color: #fff; }
.toast-notification.error { background: #ef4444; color: #fff; }
</style>
</head>
<body>
<div class="phone-frame">
  <div class="pr-share-header">
    <button class="back-btn" onclick="window.location.href='<?php echo restaurantPageUrl('profile'); ?>'" aria-label="Back"><i class="fa fa-arrow-left"></i></button>
    <h1>My Subscription</h1>
  </div>

  <div class="content">
    <div id="subList"><div class="loading"><i class="fa fa-spinner fa-spin"></i> Loading your subscriptions...</div></div>
  </div>
</div>

<script>
window.restaurantId = <?php echo json_encode($restaurant_id ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
var scopeLabels = { lunch: 'Lunch Only', dinner: 'Dinner Only', both: 'Lunch + Dinner' };
var statusLabels = { active: 'Active', paused: 'Paused', pending_payment: 'Payment Pending', completed: 'Completed', cancelled: 'Cancelled' };

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
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

function todayStr() {
  var d = new Date();
  return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
}

function loadSubscriptions() {
  fetch('../api/get_my_meal_subscriptions.php?restaurant_id=' + encodeURIComponent(window.restaurantId))
    .then(function(r) { return r.json(); })
    .then(function(data) { renderSubscriptions(data.success ? (data.data || []) : []); })
    .catch(function() {
      document.getElementById('subList').innerHTML = '<div class="empty-state"><i class="fa fa-exclamation-circle"></i><h3>Failed to load subscriptions</h3></div>';
    });
}

function renderSubscriptions(subs) {
  var el = document.getElementById('subList');
  if (!subs.length) {
    el.innerHTML = '<div class="empty-state"><i class="fa fa-utensils"></i><h3>No subscriptions yet</h3><p>Subscribe to a tiffin plan and never worry about "what to cook" again.</p><a class="browse-btn" href="plans.php">Browse Plans</a></div>';
    return;
  }

  var html = '';
  subs.forEach(function(s) {
    var pct = s.credits_total > 0 ? Math.round((s.credits_used / s.credits_total) * 100) : 0;
    html += '<div class="sub-card" id="sub-' + s.id + '">';
    html += '<div class="sub-name">' + escapeHtml(s.plan_name_snapshot) + '</div>';
    html += '<span class="sub-status ' + s.status + '">' + (statusLabels[s.status] || s.status) + '</span>';
    html += '<div class="credit-bar-wrap"><div class="credit-bar" style="width:' + pct + '%"></div></div>';
    html += '<div class="credit-label"><b>' + s.credits_remaining + '</b> of ' + s.credits_total + ' meals remaining &middot; ' + (scopeLabels[s.meal_scope_snapshot] || s.meal_scope_snapshot) + '</div>';

    if (s.status === 'active' || s.status === 'paused') {
      html += '<div class="sub-actions">';
      if (s.status === 'active') {
        html += '<button class="btn-pause" onclick="pauseSub(' + s.id + ')"><i class="fa fa-pause"></i> Pause Subscription</button>';
      } else {
        html += '<button class="btn-resume" onclick="resumeSub(' + s.id + ')"><i class="fa fa-play"></i> Resume Subscription</button>';
      }
      html += '</div>';

      html += '<div class="skip-section">';
      html += '<div class="skip-title">Skip individual delivery dates</div>';
      html += '<div class="skip-add-row">';
      html += '<input type="date" id="skipDate-' + s.id + '" min="' + todayStr() + '">';
      html += '<button onclick="addSkip(' + s.id + ')">Skip</button>';
      html += '</div>';
      html += '<div class="skip-list" id="skipList-' + s.id + '">';
      (s.skip_dates || []).forEach(function(d) {
        html += '<span class="skip-chip">' + escapeHtml(d) + ' <span class="remove-skip" onclick="removeSkip(' + s.id + ',\'' + d + '\')">&times;</span></span>';
      });
      html += '</div></div>';
    } else if (s.status === 'pending_payment') {
      html += '<div style="font-size:12.5px;color:#777;">Waiting for payment confirmation. This will activate automatically once payment is verified.</div>';
    }

    html += '</div>';
  });
  el.innerHTML = html;
}

function pauseSub(id) { setSubAction(id, 'pause_subscription'); }
function resumeSub(id) { setSubAction(id, 'resume_subscription'); }

function setSubAction(id, action) {
  var fd = new FormData();
  fd.append('action', action);
  fd.append('subscriptionId', id);
  fetch('../controllers/meal_subscription_operations.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) {
        showToast(data.message, 'success');
        loadSubscriptions();
      } else {
        showToast(data.message || 'Action failed', 'error');
      }
    })
    .catch(function() { showToast('Network error. Please try again.', 'error'); });
}

function addSkip(id) {
  var input = document.getElementById('skipDate-' + id);
  var date = input.value;
  if (!date) { showToast('Please pick a date first', 'error'); return; }

  var fd = new FormData();
  fd.append('action', 'add_skip_date');
  fd.append('subscriptionId', id);
  fd.append('skipDate', date);
  fetch('../controllers/meal_subscription_operations.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) {
        showToast(data.message, 'success');
        loadSubscriptions();
      } else {
        showToast(data.message || 'Failed to skip that date', 'error');
      }
    })
    .catch(function() { showToast('Network error. Please try again.', 'error'); });
}

function removeSkip(id, date) {
  var fd = new FormData();
  fd.append('action', 'remove_skip_date');
  fd.append('subscriptionId', id);
  fd.append('skipDate', date);
  fetch('../controllers/meal_subscription_operations.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) {
        loadSubscriptions();
      } else {
        showToast(data.message || 'Failed to remove skip', 'error');
      }
    })
    .catch(function() { showToast('Network error. Please try again.', 'error'); });
}

loadSubscriptions();

/* ── PhonePe return handling: subscribe.php redirects here after payment,
   either straight back from PhonePe's checkout or (demo mode) with
   phonepe_demo=1. Poll action=status until it resolves, same pattern
   cart.php uses for order payments, then refresh the list. ── */
function checkPhonePeSubscriptionReturn() {
  var params = new URLSearchParams(window.location.search);
  var subscriptionId = params.get('subscription_id');
  var paymentError = params.get('payment_error');
  var isDemo = params.get('phonepe_demo');

  if (paymentError) {
    showToast('Payment could not be started. Please try again.', 'error');
    cleanUrl();
    return;
  }
  if (!subscriptionId) return;

  cleanUrl();
  showToast(isDemo ? 'Confirming demo payment...' : 'Confirming your payment...', 'success');
  pollSubscriptionPaymentStatus(subscriptionId, 24, 5000);
}

function cleanUrl() {
  var url = new URL(window.location.href);
  ['subscription_id', 'phonepe_demo', 'transaction_id', 'payment_error', 'code'].forEach(function(k) { url.searchParams.delete(k); });
  window.history.replaceState({}, document.title, url.pathname + url.search);
}

function pollSubscriptionPaymentStatus(subscriptionId, retries, intervalMs) {
  if (retries <= 0) {
    showToast('Still confirming your payment - check back shortly.', 'error');
    loadSubscriptions();
    return;
  }
  fetch('phonepe_subscription_payment.php?action=status&restaurant_id=' + encodeURIComponent(window.restaurantId) + '&subscription_id=' + encodeURIComponent(subscriptionId))
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data.success) {
        setTimeout(function() { pollSubscriptionPaymentStatus(subscriptionId, retries - 1, intervalMs); }, intervalMs);
        return;
      }
      if (data.payment_status === 'Success') {
        showToast('Payment confirmed! Your subscription is now active.', 'success');
        loadSubscriptions();
      } else if (data.payment_status === 'Failed') {
        showToast('Payment failed. Please try subscribing again.', 'error');
        loadSubscriptions();
      } else {
        setTimeout(function() { pollSubscriptionPaymentStatus(subscriptionId, retries - 1, intervalMs); }, intervalMs);
      }
    })
    .catch(function() {
      setTimeout(function() { pollSubscriptionPaymentStatus(subscriptionId, retries - 1, intervalMs); }, intervalMs);
    });
}

checkPhonePeSubscriptionReturn();
</script>
</body>
</html>
