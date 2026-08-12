<?php
require_once __DIR__ . '/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<base href="<?php echo $website_base_href; ?>">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="restaurant-id" content="<?php echo htmlspecialchars($restaurant_id ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<title>Loyalty & Rewards - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Poppins', sans-serif; background: #e8ecf2; color: #1a1b1f; min-height: 100vh; overflow-x: hidden; }
.phone-frame { max-width: 425px; margin: 0 auto; min-height: 100vh; background: #fff; position: relative; box-shadow: 0 0 40px rgba(0,0,0,0.08); }
@media (min-width: 768px) { .phone-frame { margin: 20px auto; min-height: calc(100vh - 40px); border-radius: 28px; overflow: hidden; } }
.bg-wrapper { background: #fff; display: flex; flex-direction: column; min-height: 100vh; }
.pr-share-header { display: flex; align-items: center; gap: 12px; padding: 16px 12px 12px; border-bottom: 1.5px solid #ccc; }
.pr-share-header h1 { font-size: 18px; font-weight: 700; color: #1a1b1f; flex: 1; }
.back-btn { display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 8px; background: linear-gradient(135deg, #e17055, #d63031); color: #fff; border: none; cursor: pointer; font-size: 20px; flex-shrink: 0; }
.back-btn:active { transform: scale(0.93); }
.content { padding: 12px; flex: 1; max-width: 100%; }
@keyframes fadeSlideUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
.fade-in { animation: fadeSlideUp 0.3s ease; }

.loyalty-hero { background: linear-gradient(135deg, #f57f17 0%, #ff9800 50%, #e65100 100%); border-radius: 16px; padding: 22px 18px; margin-bottom: 12px; position: relative; overflow: hidden; text-align: center; }
.loyalty-hero::before { content: ''; position: absolute; top: -40%; right: -20%; width: 200px; height: 200px; background: rgba(255,255,255,0.08); border-radius: 50%; }
.loyalty-points { font-size: 40px; font-weight: 800; color: #fff; position: relative; z-index: 1; }
.loyalty-points-label { font-size: 12px; color: rgba(255,255,255,0.85); text-transform: uppercase; letter-spacing: 1px; margin-top: 2px; position: relative; z-index: 1; }
.loyalty-tier-badge { display: inline-flex; align-items: center; gap: 6px; margin-top: 12px; padding: 6px 14px; background: rgba(255,255,255,0.18); border-radius: 20px; color: #fff; font-size: 12px; font-weight: 600; position: relative; z-index: 1; }
.loyalty-worth { margin-top: 6px; font-size: 12px; color: rgba(255,255,255,0.85); position: relative; z-index: 1; }

.section-card { background: #fff; border-radius: 14px; padding: 16px 14px; margin-bottom: 12px; border: 1.5px solid #000; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.section-title { font-size: 15px; font-weight: 600; color: #1a1b1f; display: flex; align-items: center; gap: 8px; }
.section-title i { color: #e17055; font-size: 14px; }
.section-sub { font-size: 11px; color: #999; margin-bottom: 12px; margin-top: -6px; }

.referral-code-box { display: flex; align-items: center; justify-content: space-between; background: #fdf0ed; border: 1.5px dashed #e17055; border-radius: 10px; padding: 12px 14px; margin-bottom: 12px; }
.referral-code-text { font-size: 18px; font-weight: 700; letter-spacing: 2px; color: #d63031; }
.referral-actions { display: flex; gap: 8px; }
.btn-loyalty { flex: 1; padding: 10px; border-radius: 10px; border: none; font-family: 'Poppins', sans-serif; font-size: 12px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; }
.btn-loyalty.primary { background: linear-gradient(135deg, #e17055, #d63031); color: #fff; }
.btn-loyalty.secondary { background: #f5f5f5; color: #333; border: 1.5px solid #ddd; }
.btn-loyalty:active { transform: scale(0.97); }
.referral-qr { text-align: center; margin-top: 12px; }
.referral-qr img { border-radius: 10px; border: 1px solid #eee; }

.redeem-row { display: flex; gap: 8px; }
.redeem-row input { flex: 1; padding: 10px 12px; border: 1.5px solid #ddd; border-radius: 10px; font-family: 'Poppins', sans-serif; font-size: 13px; }

.ledger-item { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
.ledger-item:last-child { border-bottom: none; }
.ledger-note { font-size: 12px; color: #1a1b1f; font-weight: 500; }
.ledger-date { font-size: 10px; color: #999; margin-top: 2px; }
.ledger-points { font-size: 13px; font-weight: 700; }
.ledger-points.positive { color: #2e7d32; }
.ledger-points.negative { color: #c62828; }

.empty-state { text-align: center; padding: 28px 16px; }
.empty-state-icon { font-size: 40px; display: block; margin-bottom: 10px; }
.empty-state-title { font-size: 14px; font-weight: 600; color: #1a1b1f; margin-bottom: 4px; }
.empty-state-desc { font-size: 12px; color: #999; line-height: 1.5; }

.toast-notification { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(20px); padding: 14px 24px; border-radius: 14px; font-size: 13px; font-weight: 500; z-index: 99999; font-family: 'Poppins', sans-serif; box-shadow: 0 8px 30px rgba(0,0,0,0.2); opacity: 0; transition: all 0.3s; display: flex; align-items: center; gap: 8px; }
.toast-notification.show { opacity: 1; transform: translateX(-50%) translateY(0); }
.toast-notification.success { background: #10b981; color: #fff; }
.toast-notification.error { background: #ef4444; color: #fff; }
.skeleton-block { background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%); background-size: 200% 100%; animation: shimmer 1.5s infinite; border-radius: 10px; height: 80px; }
@keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
</style>
</head>
<body>
<div class="phone-frame">
  <div class="bg-wrapper">
    <div class="pr-share-header">
      <button class="back-btn" onclick="goBack()" aria-label="Back"><i class="fa fa-arrow-left"></i></button>
      <h1>Loyalty & Rewards</h1>
    </div>

    <div class="content">
      <div id="loyaltyLoading" class="skeleton-block fade-in"></div>

      <div id="loyaltyBody" style="display:none;">
        <div class="loyalty-hero fade-in">
          <div class="loyalty-points" id="pointsBalance">0</div>
          <div class="loyalty-points-label">Points Balance</div>
          <div class="loyalty-worth" id="pointsWorth"></div>
          <div class="loyalty-tier-badge" id="tierBadge" style="display:none;"><i class="fa fa-crown"></i> <span id="tierName"></span></div>
        </div>

        <div class="section-card fade-in" id="referralSection" style="display:none;">
          <div class="section-header">
            <div class="section-title"><i class="fa fa-user-plus"></i> Refer & Earn</div>
          </div>
          <div class="section-sub">Share your code — you both earn points when your friend orders.</div>
          <div class="referral-code-box">
            <span class="referral-code-text" id="referralCode">------</span>
          </div>
          <div class="referral-actions">
            <button class="btn-loyalty primary" onclick="shareReferral()"><i class="fa fa-share-nodes"></i> Share</button>
            <button class="btn-loyalty secondary" onclick="copyReferralLink()"><i class="fa fa-copy"></i> Copy Link</button>
          </div>
          <div class="referral-qr">
            <img id="referralQr" src="" alt="Referral QR code" width="140" height="140">
          </div>
        </div>

        <div class="section-card fade-in" id="redeemSection" style="display:none;">
          <div class="section-header">
            <div class="section-title"><i class="fa fa-gift"></i> Redeem Points</div>
          </div>
          <div class="section-sub" id="redeemHint"></div>
          <div class="redeem-row">
            <input type="number" id="redeemPointsInput" placeholder="Points to redeem" min="1">
            <button class="btn-loyalty primary" style="flex:0 0 auto; padding:10px 16px;" onclick="redeemPoints()">Redeem</button>
          </div>
        </div>

        <div class="section-card fade-in">
          <div class="section-header">
            <div class="section-title"><i class="fa fa-clock-rotate-left"></i> Recent Activity</div>
          </div>
          <div id="ledgerList"></div>
        </div>
      </div>

      <div id="loyaltyNotEnrolled" class="section-card fade-in" style="display:none;">
        <div class="empty-state">
          <span class="empty-state-icon">🎁</span>
          <div class="empty-state-title">No Rewards Yet</div>
          <div class="empty-state-desc">Place your first order to start earning loyalty points!</div>
        </div>
      </div>

      <div id="loyaltyDisabled" class="section-card fade-in" style="display:none;">
        <div class="empty-state">
          <span class="empty-state-icon">🚧</span>
          <div class="empty-state-title">Loyalty Program Coming Soon</div>
          <div class="empty-state-desc">This restaurant hasn't turned on rewards yet. Check back later!</div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
window.restaurantId = <?php echo json_encode($restaurant_id ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.currencySymbol = <?php echo json_encode($currency_symbol ?? '₹', JSON_HEX_TAG); ?>;
window.loggedInCustomer = <?php echo $logged_in_customer ? json_encode([
    'name' => $logged_in_customer['customer_name'],
    'phone' => $logged_in_customer['phone'],
], JSON_HEX_TAG | JSON_HEX_AMP) : 'null'; ?>;

function goBack() {
  window.location.href = '<?php echo restaurantPageUrl('profile'); ?>';
}
function loadCustomerFromStorage() {
  try { var s = localStorage.getItem('customerDetails'); return s ? JSON.parse(s) : null; } catch(e) { return null; }
}
function showToast(msg, type) {
  type = type || 'success';
  var existing = document.querySelector('.toast-notification');
  if (existing) existing.remove();
  var toast = document.createElement('div');
  toast.className = 'toast-notification ' + type;
  toast.innerHTML = (type === 'success' ? '✅ ' : '❌ ') + msg;
  document.body.appendChild(toast);
  requestAnimationFrame(function() { setTimeout(function() { toast.classList.add('show'); }, 10); });
  setTimeout(function() { toast.classList.remove('show'); setTimeout(function(){ toast.remove(); }, 300); }, 2800);
}

var loyaltyState = null;

function getCustomerPhone() {
  var c = window.loggedInCustomer || loadCustomerFromStorage();
  return c ? (c.phone || '') : '';
}

function buildReferralLink(code) {
  var base = '<?php echo restaurantPageUrl('menu'); ?>';
  var sep = base.indexOf('?') !== -1 ? '&' : '?';
  return window.location.origin + base + sep + 'ref=' + encodeURIComponent(code);
}

function loadLoyaltySummary() {
  var phone = getCustomerPhone();
  if (!phone) {
    document.getElementById('loyaltyLoading').style.display = 'none';
    document.getElementById('loyaltyNotEnrolled').style.display = 'block';
    document.getElementById('loyaltyNotEnrolled').querySelector('.empty-state-title').textContent = 'Add Your Phone Number';
    document.getElementById('loyaltyNotEnrolled').querySelector('.empty-state-desc').textContent = 'Save your phone number in your profile to see your rewards.';
    return;
  }

  var url = 'loyalty_actions.php?action=summary&restaurant_id=' + encodeURIComponent(window.restaurantId) + '&phone=' + encodeURIComponent(phone);
  fetch(url).then(function(r){ return r.json(); }).then(function(res) {
    document.getElementById('loyaltyLoading').style.display = 'none';
    if (!res.success) { showToast(res.message || 'Could not load rewards', 'error'); return; }

    if (!res.enrolled) {
      document.getElementById('loyaltyNotEnrolled').style.display = 'block';
      return;
    }
    if (!res.loyalty_enabled) {
      document.getElementById('loyaltyDisabled').style.display = 'block';
      return;
    }

    loyaltyState = res;
    document.getElementById('loyaltyBody').style.display = 'block';
    document.getElementById('pointsBalance').textContent = res.points_balance;
    document.getElementById('pointsWorth').textContent = 'Worth ' + window.currencySymbol + (res.points_balance * res.redeem_value_per_point).toFixed(2);

    if (res.tier) {
      document.getElementById('tierBadge').style.display = 'inline-flex';
      document.getElementById('tierName').textContent = res.tier.tier_name;
    }

    if (res.referral_enabled && res.referral_code) {
      document.getElementById('referralSection').style.display = 'block';
      document.getElementById('referralCode').textContent = res.referral_code;
      var link = buildReferralLink(res.referral_code);
      var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=' + encodeURIComponent(link);
      document.getElementById('referralQr').src = qrUrl;
    }

    document.getElementById('redeemSection').style.display = 'block';
    document.getElementById('redeemHint').textContent = 'Minimum ' + res.min_redeem_points + ' points · ' + window.currencySymbol + res.redeem_value_per_point + ' per point';

    var ledgerEl = document.getElementById('ledgerList');
    if (!res.ledger.length) {
      ledgerEl.innerHTML = '<div class="empty-state"><span class="empty-state-icon">📋</span><div class="empty-state-desc">No activity yet</div></div>';
    } else {
      ledgerEl.innerHTML = res.ledger.map(function(l) {
        var positive = l.points_change > 0;
        var date = new Date(l.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        return '<div class="ledger-item"><div><div class="ledger-note">' + (l.note || l.type) + '</div><div class="ledger-date">' + date + '</div></div>' +
               '<div class="ledger-points ' + (positive ? 'positive' : 'negative') + '">' + (positive ? '+' : '') + l.points_change + '</div></div>';
      }).join('');
    }
  }).catch(function() {
    document.getElementById('loyaltyLoading').style.display = 'none';
    showToast('Could not load rewards right now', 'error');
  });
}

function shareReferral() {
  if (!loyaltyState || !loyaltyState.referral_code) return;
  var link = buildReferralLink(loyaltyState.referral_code);
  var text = 'Use my code ' + loyaltyState.referral_code + ' and we both earn rewards!';
  if (navigator.share) {
    navigator.share({ title: 'Join me!', text: text, url: link }).catch(function() {});
  } else {
    copyReferralLink();
  }
}

function copyReferralLink() {
  if (!loyaltyState || !loyaltyState.referral_code) return;
  var link = buildReferralLink(loyaltyState.referral_code);
  navigator.clipboard.writeText(link).then(function() {
    showToast('Referral link copied!');
  }).catch(function() {
    showToast('Could not copy link', 'error');
  });
}

function redeemPoints() {
  var input = document.getElementById('redeemPointsInput');
  var points = parseInt(input.value, 10);
  if (!points || points <= 0) { showToast('Enter a valid number of points', 'error'); return; }

  var phone = getCustomerPhone();
  var fd = new FormData();
  fd.append('action', 'redeem');
  fd.append('restaurant_id', window.restaurantId);
  fd.append('phone', phone);
  fd.append('points', points);

  fetch('loyalty_actions.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.success) {
        showToast(res.message || 'Points redeemed!');
        input.value = '';
        loadLoyaltySummary();
      } else {
        showToast(res.message || 'Could not redeem points', 'error');
      }
    })
    .catch(function() { showToast('Something went wrong', 'error'); });
}

document.addEventListener('DOMContentLoaded', loadLoyaltySummary);
</script>
<div style="height:24px"></div>
<?php require_once __DIR__ . '/bottom_nav.php'; ?>
</body>
</html>
