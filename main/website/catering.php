<?php require_once __DIR__ . '/header.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<base href="<?php echo $website_base_href; ?>">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="restaurant-id" content="<?php echo htmlspecialchars($restaurant_id ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<title>Catering &amp; Bulk Orders - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Poppins', sans-serif; background: #e8ecf2; color: #1a1b1f; min-height: 100vh; }
.phone-frame { max-width: 425px; margin: 0 auto; min-height: 100vh; background: #fff; position: relative; box-shadow: 0 0 40px rgba(0,0,0,0.08); }
@media (min-width: 768px) { .phone-frame { margin: 20px auto; min-height: calc(100vh - 40px); border-radius: 28px; overflow: hidden; } }

.pr-share-header { display: flex; align-items: center; gap: 12px; padding: 16px 12px 12px; border-bottom: 1.5px solid #eee; }
.pr-share-header h1 { font-size: 18px; font-weight: 700; flex: 1; }
.back-btn { display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 8px; background: linear-gradient(135deg, #e17055, #d63031); color: #fff; border: none; cursor: pointer; font-size: 20px; flex-shrink: 0; }

.content { padding: 16px 18px 40px; }
.hero { text-align: center; margin-bottom: 20px; }
.hero h2 { font-size: 19px; font-weight: 700; margin-bottom: 4px; }
.hero p { font-size: 13px; color: #999; }

.tiers-preview { display: flex; gap: 8px; overflow-x: auto; margin-bottom: 20px; padding-bottom: 4px; }
.tier-chip { flex: 0 0 auto; border: 1.5px solid #e8e0d8; border-radius: 12px; padding: 10px 14px; text-align: center; min-width: 90px; }
.tier-chip .tc-label { font-size: 11px; color: #888; }
.tier-chip .tc-price { font-size: 15px; font-weight: 800; color: #d63031; }

.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 6px; font-weight: 500; color: #555; font-size: 12px; }
.form-group input, .form-group textarea { width: 100%; padding: 11px 12px; border: 1.5px solid #ddd; border-radius: 8px; font-size: 13px; font-family: 'Poppins', sans-serif; outline: none; box-sizing: border-box; }
.form-group input:focus, .form-group textarea:focus { border-color: #e17055; }
.phone-input-row { display: flex; align-items: center; gap: 6px; }
.phone-input-row span { color: #666; font-size: 14px; white-space: nowrap; }
.phone-input-row input { flex: 1; }

.quote-box { display: none; background: #f0f7f5; border-radius: 10px; padding: 12px 14px; margin-bottom: 16px; font-size: 13px; color: #1a3934; }
.quote-box b { font-size: 16px; }

.btn-submit { width: 100%; padding: 13px; border: none; border-radius: 8px; background: linear-gradient(135deg, #e17055, #d63031); color: #fff; font-weight: 600; font-size: 14px; font-family: 'Poppins', sans-serif; cursor: pointer; }
.btn-submit:disabled { opacity: 0.6; cursor: not-allowed; }
.form-error { color: #ef4444; font-size: 12px; margin-bottom: 12px; display: none; background: #fef2f2; padding: 10px 12px; border-radius: 8px; }
.success-box { display: none; text-align: center; padding: 30px 10px; }
.success-box i { font-size: 48px; color: #10b981; margin-bottom: 12px; }
.success-box h3 { margin-bottom: 6px; }
.success-box p { color: #777; font-size: 13px; }
</style>
</head>
<body>
<div class="phone-frame">
  <div class="pr-share-header">
    <button class="back-btn" onclick="window.location.href='<?php echo restaurantPageUrl('menu'); ?>'" aria-label="Back"><i class="fa fa-arrow-left"></i></button>
    <h1>Catering &amp; Bulk Orders</h1>
  </div>

  <div class="content">
    <div id="formWrap">
      <div class="hero">
        <h2>Planning an event?</h2>
        <p>Tell us your headcount and date - we'll get back to you with a quote</p>
      </div>

      <div class="tiers-preview" id="tiersPreview"></div>

      <form id="cateringForm">
        <div class="form-error" id="formError"></div>

        <div class="form-group">
          <label>Your Name *</label>
          <input type="text" id="contactName" required placeholder="Your name" value="<?php echo htmlspecialchars($logged_in_customer['customer_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div class="form-group">
          <label>Phone Number *</label>
          <div class="phone-input-row">
            <span><?php echo htmlspecialchars($phone_dial_code ?? '+91'); ?></span>
            <input type="tel" id="contactPhone" required placeholder="Your phone" value="<?php echo htmlspecialchars($logged_in_customer['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
          </div>
        </div>

        <div class="form-group">
          <label>Event Date *</label>
          <input type="date" id="eventDate" required>
        </div>

        <div class="form-group">
          <label>Number of Guests *</label>
          <input type="number" id="guestCount" min="1" required placeholder="e.g. 30" oninput="updateQuote()">
        </div>

        <div class="quote-box" id="quoteBox"></div>

        <div class="form-group">
          <label>Notes (optional)</label>
          <textarea id="notes" rows="3" placeholder="Dietary preferences, venue, timing, anything else..."></textarea>
        </div>

        <button type="submit" class="btn-submit" id="submitBtn">Request a Quote</button>
      </form>
    </div>

    <div class="success-box" id="successBox">
      <i class="fa fa-check-circle"></i>
      <h3>Request sent!</h3>
      <p id="successMsg">We'll contact you shortly to confirm the details.</p>
    </div>
  </div>
</div>

<script>
window.restaurantId = <?php echo json_encode($restaurant_id ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
var CURRENCY = <?php echo json_encode($currency_symbol ?? '₹', JSON_UNESCAPED_UNICODE); ?>;
var tiers = [];
var matchedTierId = null;

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function showFormError(msg) {
  var el = document.getElementById('formError');
  el.textContent = msg;
  el.style.display = 'block';
}
function hideFormError() { document.getElementById('formError').style.display = 'none'; }

function renderTiersPreview() {
  var el = document.getElementById('tiersPreview');
  if (!tiers.length) { el.style.display = 'none'; return; }
  var html = '';
  tiers.forEach(function(t) {
    html += '<div class="tier-chip"><div class="tc-label">' + t.min_guests + '-' + t.max_guests + ' guests</div><div class="tc-price">' + CURRENCY + parseFloat(t.price).toFixed(0) + '</div></div>';
  });
  el.innerHTML = html;
}

function updateQuote() {
  var count = parseInt(document.getElementById('guestCount').value, 10) || 0;
  var quoteBox = document.getElementById('quoteBox');
  matchedTierId = null;
  if (count <= 0) { quoteBox.style.display = 'none'; return; }
  var match = null;
  for (var i = 0; i < tiers.length; i++) {
    if (count >= tiers[i].min_guests && count <= tiers[i].max_guests) { match = tiers[i]; break; }
  }
  if (match) {
    matchedTierId = match.id;
    quoteBox.style.display = 'block';
    quoteBox.innerHTML = 'Estimated price: <b>' + CURRENCY + parseFloat(match.price).toFixed(2) + '</b> (' + escapeHtml(match.tier_label) + ')';
  } else {
    quoteBox.style.display = 'block';
    quoteBox.innerHTML = 'No exact price tier for this headcount - we\'ll send you a custom quote.';
  }
}

fetch('../api/get_catering_tiers.php?restaurant_id=' + encodeURIComponent(window.restaurantId))
  .then(function(r) { return r.json(); })
  .then(function(data) { tiers = data.success ? (data.data || []) : []; renderTiersPreview(); })
  .catch(function() {});

document.getElementById('cateringForm').addEventListener('submit', function(e) {
  e.preventDefault();
  hideFormError();

  var name = document.getElementById('contactName').value.trim();
  var phone = document.getElementById('contactPhone').value.trim();
  var eventDate = document.getElementById('eventDate').value;
  var guestCount = document.getElementById('guestCount').value;
  var notes = document.getElementById('notes').value.trim();

  if (!name || !phone || !eventDate || !guestCount) { showFormError('Please fill in all required fields'); return; }

  var btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.textContent = 'Sending...';

  var fd = new FormData();
  fd.append('action', 'submit_request');
  fd.append('restaurant_id', window.restaurantId);
  fd.append('contactName', name);
  fd.append('contactPhone', phone);
  fd.append('eventDate', eventDate);
  fd.append('guestCount', guestCount);
  fd.append('notes', notes);
  if (matchedTierId) fd.append('tierId', matchedTierId);

  fetch('../controllers/catering_operations.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) {
        document.getElementById('formWrap').style.display = 'none';
        document.getElementById('successBox').style.display = 'block';
        if (data.quoted_price) {
          document.getElementById('successMsg').textContent = 'Estimated price: ' + CURRENCY + parseFloat(data.quoted_price).toFixed(2) + '. We will contact you shortly to confirm.';
        }
      } else {
        showFormError(data.message || 'Failed to submit request');
        btn.disabled = false;
        btn.textContent = 'Request a Quote';
      }
    })
    .catch(function() {
      showFormError('Network error. Please try again.');
      btn.disabled = false;
      btn.textContent = 'Request a Quote';
    });
});
</script>
</body>
</html>
