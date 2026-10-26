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
<?php include __DIR__ . '/cart_scope.php'; ?>
<title>My Subscription - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" href="<?php echo htmlspecialchars($favicon_href ?? $local_favicon_svg, ENT_QUOTES, 'UTF-8'); ?>">
<?php require_once __DIR__ . '/font_links.php'; echo websiteFontLinks(['poppins', 'fontawesome']); ?>
<?php if (!empty($font_family_google_param)) echo websiteThemeFontLink($font_family_google_param); ?>
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

.info-rows { background: #faf8f6; border-radius: 12px; padding: 10px 12px; margin-bottom: 14px; }
.info-row { display: flex; gap: 10px; font-size: 12.5px; padding: 5px 0; color: #555; align-items: flex-start; }
.info-row i { width: 16px; color: var(--primary-red, #e17055); margin-top: 2px; }
.info-row b { color: #1a1b1f; font-weight: 600; }
.info-row .grow { flex: 1; min-width: 0; word-break: break-word; }
.link-btn { background: none; border: none; color: var(--primary-red, #e17055); font-weight: 600; font-size: 12px; cursor: pointer; font-family: var(--site-font); padding: 0; }
.pay-pill { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; }
.pay-pill.Success { background: #d1fae5; color: #065f46; }
.pay-pill.Pending { background: #fef3c7; color: #92400e; }
.pay-pill.Failed, .pay-pill.Refunded { background: #fee2e2; color: #991b1b; }
.edit-delivery { display: none; border-top: 1px dashed #e5e7eb; margin-top: 8px; padding-top: 10px; }
.edit-delivery.open { display: block; }
.edit-delivery textarea, .edit-delivery input { width: 100%; padding: 9px 10px; border: 1.5px solid #ddd; border-radius: 8px; font-size: 12.5px; font-family: var(--site-font); margin-bottom: 8px; }
.edit-delivery .row { display: flex; gap: 8px; }
.edit-delivery .row button { flex: 1; padding: 9px; border: none; border-radius: 8px; font-weight: 600; font-size: 12.5px; cursor: pointer; font-family: var(--site-font); }
.btn-save { background: #1a3934; color: #fff; }
.btn-cancel-edit { background: #f3f4f6; color: #374151; }
.hist { border-top: 1px solid #f0f0f0; margin-top: 12px; padding-top: 10px; }
.hist summary { font-size: 12.5px; font-weight: 600; color: #555; cursor: pointer; }
.hist-row { display: flex; justify-content: space-between; font-size: 12px; padding: 6px 0; border-bottom: 1px solid #f5f5f5; color: #555; }
.hist-row:last-child { border-bottom: none; }
.hist-st { font-weight: 600; }
.hist-st.done { color: #065f46; } .hist-st.off { color: #991b1b; } .hist-st.wait { color: #92400e; }
.renew-btn { display: block; text-align: center; margin-top: 12px; padding: 11px; border-radius: 10px; background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031)); color: #fff; text-decoration: none; font-weight: 600; font-size: 13px; }

.toast-notification { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%); padding: 14px 24px; border-radius: 14px; font-size: 13px; font-weight: 500; z-index: 99999; box-shadow: 0 8px 30px rgba(0,0,0,0.2); opacity: 0; transition: all 0.35s cubic-bezier(0.4,0,0.2,1); transform: translateX(-50%) translateY(24px); pointer-events: none; max-width: 90%; text-align: center; }
.toast-notification.show { opacity: 1; transform: translateX(-50%) translateY(0); }
.toast-notification.success { background: #10b981; color: #fff; }
.toast-notification.error { background: #ef4444; color: #fff; }
</style>
<script src="rg-notify.js?v=<?php echo @filemtime(__DIR__ . '/rg-notify.js'); ?>" defer></script>
<script>document.documentElement.style.setProperty('--rgn-bottom-m', 'calc(84px + env(safe-area-inset-bottom))'); document.addEventListener('DOMContentLoaded', function () { if (window.RGNotify) RGNotify.setup({ toast: 'web' }); });</script>
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
window.currencySymbol = <?php echo json_encode($currency_symbol ?? '₹', JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
var scopeLabels = { lunch: 'Lunch Only', dinner: 'Dinner Only', both: 'Lunch + Dinner' };
var statusLabels = { active: 'Active', paused: 'Paused', pending_payment: 'Payment Pending', completed: 'Completed', cancelled: 'Cancelled' };

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
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

// the restaurant's today (from the server), so skips and "Starts" follow its clock
var serverToday = '';
function todayStr() {
  if (serverToday) return serverToday;
  var d = new Date();
  return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
}

function niceDate(ymd) {
  if (!ymd) return '';
  var p = String(ymd).split('-');
  var d = new Date(+p[0], +p[1] - 1, +p[2]);
  if (ymd === todayStr()) return 'Today';
  return d.toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' });
}

var payLabels = { Success: 'Paid', Pending: 'Payment pending', Failed: 'Payment failed', Refunded: 'Refunded' };
var mealLabels = { lunch: 'Lunch', dinner: 'Dinner' };

function deliveryState(st) {
  if (st === 'Served' || st === 'Completed') return ['done', 'Delivered'];
  if (st === 'Cancelled' || st === 'Rejected') return ['off', 'Cancelled'];
  if (st === 'Ready') return ['wait', 'Ready / on the way'];
  if (st === 'Preparing') return ['wait', 'Preparing'];
  return ['wait', 'Received'];
}

function loadSubscriptions() {
  fetch('../api/get_my_meal_subscriptions.php?restaurant_id=' + encodeURIComponent(window.restaurantId), { cache: 'no-store' })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.today) serverToday = data.today;
      renderSubscriptions(data.success ? (data.data || []) : []);
    })
    .catch(function() {
      document.getElementById('subList').innerHTML = '<div class="empty-state"><i class="fa fa-exclamation-circle"></i><h3>Failed to load subscriptions</h3></div>';
    });
}

// Changes the restaurant makes (pause, skip, address, payment, credits, a delivery going out) show
// here without a reload: refresh every 30 seconds while the page is open and nothing is being typed.
setInterval(function() {
  if (document.hidden) return;
  if (document.querySelector('.edit-delivery.open')) return;
  var a = document.activeElement;
  if (a && /INPUT|TEXTAREA/.test(a.tagName) && a.closest('#subList')) return;
  loadSubscriptions();
}, 30000);
document.addEventListener('visibilitychange', function() { if (!document.hidden && !document.querySelector('.edit-delivery.open')) loadSubscriptions(); });

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
    var ended = s.status === 'completed' || s.status === 'cancelled';
    html += '<div class="info-rows">';
    if (s.start_date && s.start_date > todayStr()) {
      html += '<div class="info-row"><i class="fa fa-calendar"></i><div class="grow">Starts <b>' + escapeHtml(niceDate(s.start_date)) + '</b></div></div>';
    }
    if (s.status === 'active') {
      html += '<div class="info-row"><i class="fa fa-truck"></i><div class="grow">Next delivery: <b>' +
        (s.next_delivery ? escapeHtml(niceDate(s.next_delivery)) : 'not scheduled yet') + '</b></div></div>';
    } else if (s.status === 'paused') {
      html += '<div class="info-row"><i class="fa fa-pause-circle"></i><div class="grow">Paused: no meals come until you resume. Your meals are kept.</div></div>';
    }
    if (s.payment_status) {
      html += '<div class="info-row"><i class="fa fa-wallet"></i><div class="grow">' + escapeHtml(s.payment_method || '') +
        (+s.amount_paid ? ' &middot; ' + escapeHtml(window.currencySymbol + s.amount_paid) : '') +
        ' <span class="pay-pill ' + escapeHtml(s.payment_status) + '">' + escapeHtml(payLabels[s.payment_status] || s.payment_status) + '</span></div></div>';
    }
    html += '<div class="info-row"><i class="fa fa-location-dot"></i><div class="grow"><b>' + escapeHtml(s.delivery_address || '-') + '</b><br>' + escapeHtml(s.delivery_phone || '') + '</div>';
    if (!ended) html += '<button class="link-btn" onclick="toggleEdit(' + s.id + ')">Change</button>';
    html += '</div>';
    if (!ended) {
      html += '<div class="edit-delivery" id="edit-' + s.id + '">';
      html += '<textarea id="addr-' + s.id + '" rows="3" placeholder="Full delivery address">' + escapeHtml(s.delivery_address || '') + '</textarea>';
      html += '<input type="tel" id="phone-' + s.id + '" placeholder="Phone number" value="' + escapeHtml(s.delivery_phone || '') + '">';
      html += '<div class="row"><button class="btn-cancel-edit" onclick="toggleEdit(' + s.id + ')">Cancel</button><button class="btn-save" onclick="saveDelivery(' + s.id + ')">Save</button></div>';
      html += '</div>';
    }
    html += '</div>';

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
        html += '<span class="skip-chip">' + escapeHtml(niceDate(d)) + ' <span class="remove-skip" onclick="removeSkip(' + s.id + ',\'' + d + '\')">&times;</span></span>';
      });
      html += '</div></div>';
    } else if (s.status === 'pending_payment') {
      html += '<div style="font-size:12.5px;color:#777;">Waiting for payment confirmation. This will activate automatically once payment is verified.</div>';
    }

    if (s.deliveries && s.deliveries.length) {
      html += '<details class="hist"><summary>Recent deliveries (' + s.deliveries.length + ')</summary>';
      s.deliveries.forEach(function(d) {
        var st = deliveryState(d.order_status);
        html += '<div class="hist-row"><span>' + escapeHtml(niceDate(d.delivery_date)) + ' &middot; ' + escapeHtml(mealLabels[d.meal_time] || d.meal_time || '') + '</span><span class="hist-st ' + st[0] + '">' + escapeHtml(st[1]) + '</span></div>';
      });
      html += '</details>';
    }
    if (ended) {
      html += '<a class="renew-btn" href="plans.php"><i class="fa fa-rotate-right"></i> Get a new plan</a>';
    }

    html += '</div>';
  });
  el.innerHTML = html;
}

function toggleEdit(id) {
  var el = document.getElementById('edit-' + id);
  if (el) el.classList.toggle('open');
}

function saveDelivery(id) {
  var addr = document.getElementById('addr-' + id).value.trim();
  var phone = document.getElementById('phone-' + id).value.trim();
  if (!addr) { showToast('Please enter the delivery address', 'error'); return; }
  if (!phone) { showToast('Please enter a phone number', 'error'); return; }
  var fd = new FormData();
  fd.append('action', 'update_delivery');
  fd.append('restaurant_id', window.restaurantId || '');
  fd.append('subscriptionId', id);
  fd.append('deliveryAddress', addr);
  fd.append('deliveryPhone', phone);
  fetch('../controllers/meal_subscription_operations.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) { showToast(data.message, 'success'); loadSubscriptions(); }
      else showToast(data.message || 'Could not save', 'error');
    })
    .catch(function() { showToast('Network error. Please try again.', 'error'); });
}

function pauseSub(id) { setSubAction(id, 'pause_subscription'); }
function resumeSub(id) { setSubAction(id, 'resume_subscription'); }

function setSubAction(id, action) {
  var fd = new FormData();
  fd.append('action', action);
  fd.append('restaurant_id', window.restaurantId || '');
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
  fd.append('restaurant_id', window.restaurantId || '');
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
  fd.append('restaurant_id', window.restaurantId || '');
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
