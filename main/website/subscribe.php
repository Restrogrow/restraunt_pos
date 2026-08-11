<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../config/meal_subscription_schema.php';
if (!mealSubscriptionsFeatureEnabled($conn, $restaurant_id)) {
    header('Location: ' . restaurantPageUrl('menu'));
    exit();
}

// Subscriptions need a real account (to track credits/pause across visits),
// unlike cart.php's checkout which allows a guest choice - so this is a hard
// PHP-level redirect rather than the client-side gate cart.php uses.
// Redirects to plans.php (not back to this page) because login.php's
// `redirect` param only accepts a bare page name from a fixed allow-list -
// it can't carry the plan_id along too. The customer just taps the plan
// again once logged in, which lands straight back here.
if (!$logged_in_customer) {
    $loginBase = restaurantPageUrl('login');
    $sep = strpos($loginBase, '?') !== false ? '&' : '?';
    header('Location: ' . $loginBase . $sep . 'redirect=plans');
    exit();
}

$plan_id = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;

// Payment methods available - same gateway lookup cart.php uses, trimmed to
// what a subscription purchase needs (Cash + Business QR proof always;
// PhonePe only once main/api/phonepe_subscription_payment.php - a later
// piece of this same feature - is wired up).
$business_qr_available = false;
$business_qr_image_url = '';
if (!empty($restaurant_id) && function_exists('getConnection')) {
    try {
        $gwConn = getConnection();
        $gwStmt = $gwConn->prepare("SELECT id, (business_qr_code_data IS NOT NULL) AS has_business_qr FROM users WHERE restaurant_id = ? LIMIT 1");
        $gwStmt->execute([$restaurant_id]);
        $gwRow = $gwStmt->fetch(PDO::FETCH_ASSOC);
        if ($gwRow && (int)$gwRow['has_business_qr'] === 1) {
            $business_qr_available = true;
            $business_qr_image_url = '../api/image.php?type=business_qr&id=' . urlencode($gwRow['id']);
        }
    } catch (Exception $e) {
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<base href="<?php echo $website_base_href; ?>">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="restaurant-id" content="<?php echo htmlspecialchars($restaurant_id ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<title>Subscribe - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<?php $googleMapsApiKey = function_exists('env') ? env('GOOGLE_MAPS_API_KEY', '') : ''; ?>
<?php if ($googleMapsApiKey): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo urlencode($googleMapsApiKey); ?>&libraries=places&loading=async" async defer></script>
<?php endif; ?>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Poppins', sans-serif; background: #e8ecf2; color: #1a1b1f; min-height: 100vh; }
.phone-frame { max-width: 425px; margin: 0 auto; min-height: 100vh; background: #fff; position: relative; box-shadow: 0 0 40px rgba(0,0,0,0.08); }
@media (min-width: 768px) { .phone-frame { margin: 20px auto; min-height: calc(100vh - 40px); border-radius: 28px; overflow: hidden; } }

.pr-share-header { display: flex; align-items: center; gap: 12px; padding: 16px 12px 12px; border-bottom: 1.5px solid #eee; }
.pr-share-header h1 { font-size: 18px; font-weight: 700; flex: 1; }
.back-btn { display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 8px; background: linear-gradient(135deg, #e17055, #d63031); color: #fff; border: none; cursor: pointer; font-size: 20px; flex-shrink: 0; }

.content { padding: 16px 18px 40px; }
.plan-summary { border: 2px solid #e8e0d8; border-radius: 14px; padding: 16px; margin-bottom: 20px; background: #faf8f6; }
.plan-summary .plan-name { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
.plan-summary .plan-price { font-size: 22px; font-weight: 800; color: #d63031; margin: 6px 0; }
.plan-summary .plan-tags { display: flex; gap: 6px; flex-wrap: wrap; }
.plan-tag { background: #f0ebe5; color: #8a4a2f; padding: 4px 12px; border-radius: 20px; font-size: 11.5px; font-weight: 600; }

.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 6px; font-weight: 500; color: #555; font-size: 12px; }
.form-group input, .form-group textarea, .form-group select {
  width: 100%; padding: 11px 12px; border: 1.5px solid #ddd; border-radius: 8px;
  font-size: 13px; font-family: 'Poppins', sans-serif; outline: none; box-sizing: border-box;
}
.form-group input:focus, .form-group textarea:focus, .form-group select:focus { border-color: #e17055; }
.phone-input-row { display: flex; align-items: center; gap: 6px; }
.phone-input-row span { color: #666; font-size: 14px; white-space: nowrap; }
.phone-input-row input { flex: 1; }

.qr-block { display: none; text-align: center; border: 1px solid #e0e0e0; border-radius: 10px; padding: 14px; margin-bottom: 16px; background: #fafafa; }
.qr-block img { width: 160px; height: 160px; object-fit: contain; border: 1px solid #ddd; border-radius: 8px; background: #fff; }
.qr-block input[type=file] { margin-top: 10px; width: 100%; font-size: 12px; }

.loc-btn { width: 100%; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 12px 14px; border: none; border-radius: 10px; background: #1a3934; color: #fff; font-size: 14px; font-weight: 600; font-family: 'Poppins', sans-serif; cursor: pointer; }
.loc-or { text-align: center; margin: 8px 0; font-size: 11px; color: #999; }
.loc-map-btn { margin-top: 8px; width: 100%; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 11px 14px; border: 2px solid #1a3934; border-radius: 10px; background: #fff; color: #1a3934; font-size: 13px; font-weight: 600; font-family: 'Poppins', sans-serif; cursor: pointer; }
.loc-map-preview { display: none; margin-top: 10px; width: 100%; height: 160px; border-radius: 10px; overflow: hidden; border: 2px solid #e0e0e0; }
.loc-info { display: none; margin-top: 8px; padding: 10px; background: #f0f7f5; border-radius: 8px; font-size: 13px; }
.loc-info a { color: #1a3934; font-weight: 600; text-decoration: none; }
.loc-info a:hover { text-decoration: underline; }
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10050; align-items: center; justify-content: center; padding: 20px; }
.modal-overlay.active, .modal-overlay.show { display: flex; }
.modal-box { background: #fff; border-radius: 16px; max-width: 500px; width: 100%; max-height: 90vh; overflow-y: auto; }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 18px 20px; border-bottom: 1px solid #eee; }
.modal-header h2 { font-size: 16px; font-weight: 700; margin: 0; }
.modal-close { width: 30px; height: 30px; border-radius: 8px; border: none; background: #f5f5f5; cursor: pointer; font-size: 18px; color: #666; }
.modal-body { padding: 18px 20px; }
.btn-group { display: flex; gap: 10px; }
.btn-secondary { flex: 1; padding: 11px; border: 1.5px solid #ddd; border-radius: 8px; background: #fff; color: #333; font-weight: 600; font-size: 13px; font-family: 'Poppins', sans-serif; cursor: pointer; }
.btn-primary { flex: 1; padding: 11px; border: none; border-radius: 8px; background: linear-gradient(135deg, #e17055, #d63031); color: #fff; font-weight: 600; font-size: 13px; font-family: 'Poppins', sans-serif; cursor: pointer; }

.btn-submit {
  width: 100%; padding: 13px; border: none; border-radius: 8px;
  background: linear-gradient(135deg, #e17055, #d63031); color: #fff;
  font-weight: 600; font-size: 14px; font-family: 'Poppins', sans-serif; cursor: pointer;
}
.btn-submit:disabled { opacity: 0.6; cursor: not-allowed; }
.form-error { color: #ef4444; font-size: 12px; margin-bottom: 12px; display: none; background: #fef2f2; padding: 10px 12px; border-radius: 8px; }
.loading { text-align: center; padding: 40px; color: #999; }
</style>
</head>
<body>
<div class="phone-frame">
  <div class="pr-share-header">
    <button class="back-btn" onclick="window.location.href='plans.php'" aria-label="Back"><i class="fa fa-arrow-left"></i></button>
    <h1>Subscribe</h1>
  </div>

  <div class="content">
    <div id="planSummary"><div class="loading"><i class="fa fa-spinner fa-spin"></i> Loading plan...</div></div>

    <form id="subscribeForm" style="display:none;">
      <div class="form-error" id="formError"></div>

      <div class="form-group">
        <label>Full Name</label>
        <input type="text" id="custName" value="<?php echo htmlspecialchars($logged_in_customer['customer_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled>
      </div>

      <div class="form-group">
        <label>Delivery Phone *</label>
        <div class="phone-input-row">
          <span><?php echo htmlspecialchars($phone_dial_code ?? '+91'); ?></span>
          <input type="tel" id="deliveryPhone" required value="<?php echo htmlspecialchars($logged_in_customer['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Phone for deliveries">
        </div>
      </div>

      <div class="form-group">
        <label>Delivery Address *</label>
        <button type="button" id="useCurrentLocationBtn" class="loc-btn" onclick="useCurrentLocationForSub()">📍 Use My Current Location</button>
        <div class="loc-or">— or —</div>
        <input type="text" id="google-places-autocomplete" autocomplete="off" placeholder="Search your delivery address...">
        <button type="button" class="loc-map-btn" onclick="openMapPickerSub()">🗺️ Pick Location on Map</button>
        <div id="deliveryMapPreview" class="loc-map-preview"></div>
        <div id="deliveryInfo" class="loc-info"></div>
        <input type="hidden" id="deliveryAddressLat" value="">
        <input type="hidden" id="deliveryAddressLng" value="">
        <input type="hidden" id="deliveryAddressFormatted" value="">
      </div>

      <div class="form-group">
        <label>Payment Method</label>
        <select id="paymentMethod" onchange="onPaymentMethodChange()">
          <option value="Cash">Cash</option>
          <?php if ($business_qr_available): ?><option value="QR Payment">Pay Online (Scan QR)</option><?php endif; ?>
        </select>
      </div>

      <?php if ($business_qr_available): ?>
      <div class="qr-block" id="qrBlock">
        <img src="<?php echo htmlspecialchars($business_qr_image_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Payment QR">
        <div style="font-size:12px;color:#777;margin-top:8px;">Scan, pay, then upload a screenshot. The restaurant will confirm it.</div>
        <input type="file" id="paymentProofInput" accept="image/*">
      </div>
      <?php endif; ?>

      <button type="submit" class="btn-submit" id="submitBtn">Subscribe &amp; Pay</button>
    </form>
  </div>
</div>

<script>
window.restaurantId = <?php echo json_encode($restaurant_id ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.googleMapsApiKey = <?php echo json_encode($googleMapsApiKey, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
var PLAN_ID = <?php echo json_encode($plan_id, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
var CURRENCY = <?php echo json_encode($currency_symbol ?? '₹', JSON_UNESCAPED_UNICODE); ?>;
var scopeLabels = { lunch: 'Lunch Only', dinner: 'Dinner Only', both: 'Lunch + Dinner' };
var __paymentProofBase64 = '';

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

function showFormError(msg) {
  var el = document.getElementById('formError');
  el.textContent = msg;
  el.style.display = 'block';
}
function hideFormError() {
  document.getElementById('formError').style.display = 'none';
}

function onPaymentMethodChange() {
  var qrBlock = document.getElementById('qrBlock');
  if (!qrBlock) return;
  qrBlock.style.display = document.getElementById('paymentMethod').value === 'QR Payment' ? 'block' : 'none';
}

var proofInputEl = document.getElementById('paymentProofInput');
if (proofInputEl) {
  proofInputEl.addEventListener('change', function() {
    __paymentProofBase64 = '';
    var file = proofInputEl.files && proofInputEl.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function(e) { __paymentProofBase64 = e.target.result; };
    reader.readAsDataURL(file);
  });
}

/* ── Delivery address: Google Places autocomplete + current location + map picker ── */
var googlePlacesAutocompleteSub = null;

function applySelectedAddressSub(lat, lng, formatted) {
  var addrInput = document.getElementById('google-places-autocomplete');
  if (addrInput) addrInput.value = formatted || addrInput.value;
  document.getElementById('deliveryAddressFormatted').value = formatted || '';
  document.getElementById('deliveryAddressLat').value = lat;
  document.getElementById('deliveryAddressLng').value = lng;
  updateDeliveryMapPreviewSub(lat, lng);

  var infoEl = document.getElementById('deliveryInfo');
  if (infoEl) {
    infoEl.style.display = 'block';
    infoEl.innerHTML = '<strong>✓ Address selected</strong><br>' + escapeHtml(formatted || '') +
      '<br><a href="https://www.google.com/maps?q=' + lat + ',' + lng + '" target="_blank" rel="noopener"><i class="fa fa-map-marker-alt"></i> Open in Google Maps</a>';
  }
}

var deliveryPreviewMapSub = null;
var deliveryPreviewMarkerSub = null;
function updateDeliveryMapPreviewSub(lat, lng) {
  var container = document.getElementById('deliveryMapPreview');
  if (!container || typeof google === 'undefined' || !google.maps) return;
  container.style.display = 'block';
  var pos = { lat: parseFloat(lat), lng: parseFloat(lng) };
  if (!deliveryPreviewMapSub) {
    deliveryPreviewMapSub = new google.maps.Map(container, { center: pos, zoom: 16, streetViewControl: false, mapTypeControl: false, fullscreenControl: false });
    deliveryPreviewMarkerSub = new google.maps.Marker({ position: pos, map: deliveryPreviewMapSub, draggable: true });
    deliveryPreviewMarkerSub.addListener('dragend', function() {
      var p = deliveryPreviewMarkerSub.getPosition();
      reverseGeocodeSub(p.lat(), p.lng(), function(formatted) { applySelectedAddressSub(p.lat(), p.lng(), formatted); });
    });
  } else {
    google.maps.event.trigger(deliveryPreviewMapSub, 'resize');
    deliveryPreviewMapSub.setCenter(pos);
    deliveryPreviewMarkerSub.setPosition(pos);
  }
}

function reverseGeocodeSub(lat, lng, callback) {
  var apiKey = window.googleMapsApiKey;
  if (!apiKey) { callback(''); return; }
  fetch('https://maps.googleapis.com/maps/api/geocode/json?latlng=' + lat + ',' + lng + '&key=' + apiKey)
    .then(function(r) { return r.json(); })
    .then(function(data) {
      callback(data && data.status === 'OK' && data.results && data.results.length > 0 ? data.results[0].formatted_address : '');
    })
    .catch(function() { callback(''); });
}

function useCurrentLocationForSub() {
  var btn = document.getElementById('useCurrentLocationBtn');
  if (!navigator.geolocation) {
    showFormError('Location access is not available on this device. Please search your address or pick it on the map instead.');
    return;
  }
  if (btn) { btn.disabled = true; btn.innerHTML = '📍 Getting your location...'; }
  navigator.geolocation.getCurrentPosition(function(pos) {
    var lat = pos.coords.latitude, lng = pos.coords.longitude;
    reverseGeocodeSub(lat, lng, function(formatted) { applySelectedAddressSub(lat, lng, formatted); });
    if (btn) { btn.disabled = false; btn.innerHTML = '📍 Use My Current Location'; }
  }, function(err) {
    if (btn) { btn.disabled = false; btn.innerHTML = '📍 Use My Current Location'; }
    showFormError(err && err.code === 1
      ? 'Location access was blocked. Please allow location access for this site, or search/pick your address on the map.'
      : 'Could not get your location. Please search your address or pick it on the map instead.');
  }, { enableHighAccuracy: true, timeout: 10000 });
}

function initGeoAutocompleteSub(retries) {
  retries = retries || 0;
  var input = document.getElementById('google-places-autocomplete');
  if (!input) return;
  if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
    if (retries < 20) { setTimeout(function() { initGeoAutocompleteSub(retries + 1); }, 250); }
    return;
  }
  if (googlePlacesAutocompleteSub) {
    try { google.maps.event.clearInstanceListeners(input); } catch (e) {}
  }
  googlePlacesAutocompleteSub = new google.maps.places.Autocomplete(input, { fields: ['formatted_address', 'geometry'], types: ['geocode'] });
  googlePlacesAutocompleteSub.addListener('place_changed', function() {
    var place = googlePlacesAutocompleteSub.getPlace();
    if (!place || !place.geometry || !place.geometry.location) return;
    applySelectedAddressSub(place.geometry.location.lat(), place.geometry.location.lng(), place.formatted_address || input.value);
  });
}
setTimeout(initGeoAutocompleteSub, 200);

/* ── Map picker modal ── */
var mapPickerMapSub = null;
var mapPickerMarkerSub = null;

function openMapPickerSub() {
  if (typeof google === 'undefined' || !google.maps) {
    showFormError('Map is still loading, please try again in a moment');
    return;
  }
  if (document.getElementById('mapPickerOverlaySub')) return;

  var existingLat = parseFloat(document.getElementById('deliveryAddressLat').value);
  var existingLng = parseFloat(document.getElementById('deliveryAddressLng').value);
  var startCenter = (!isNaN(existingLat) && !isNaN(existingLng)) ? { lat: existingLat, lng: existingLng } : { lat: 20.5937, lng: 78.9629 };

  var overlay = document.createElement('div');
  overlay.id = 'mapPickerOverlaySub';
  overlay.className = 'modal-overlay show';
  overlay.innerHTML =
    '<div class="modal-box">' +
      '<div class="modal-header"><h2>Select Your Location</h2><button class="modal-close" type="button" onclick="closeMapPickerSub()">&times;</button></div>' +
      '<div class="modal-body">' +
        '<p style="font-size:12px;color:#666;margin-bottom:8px;">Drag the pin, tap the map, or use your current location.</p>' +
        '<div id="mapPickerCanvasSub" style="width:100%;height:280px;border-radius:10px;overflow:hidden;background:#eee;"></div>' +
        '<div id="mapPickerAddressSub" style="margin-top:10px;font-size:13px;color:#333;min-height:18px;">Locating...</div>' +
        '<div class="btn-group" style="margin-top:14px;">' +
          '<button type="button" class="btn-secondary" onclick="useCurrentLocationOnMapSub()">📍 Current Location</button>' +
          '<button type="button" class="btn-primary" onclick="confirmMapLocationSub()">Use This Location</button>' +
        '</div>' +
      '</div>' +
    '</div>';
  document.body.appendChild(overlay);

  mapPickerMapSub = new google.maps.Map(document.getElementById('mapPickerCanvasSub'), { center: startCenter, zoom: 16, streetViewControl: false, mapTypeControl: false, fullscreenControl: false });
  mapPickerMarkerSub = new google.maps.Marker({ position: startCenter, map: mapPickerMapSub, draggable: true });
  mapPickerMapSub.addListener('click', function(e) { mapPickerMarkerSub.setPosition(e.latLng); reverseGeocodeForPickerSub(e.latLng.lat(), e.latLng.lng()); });
  mapPickerMarkerSub.addListener('dragend', function() { var p = mapPickerMarkerSub.getPosition(); reverseGeocodeForPickerSub(p.lat(), p.lng()); });
  reverseGeocodeForPickerSub(startCenter.lat, startCenter.lng);
}

function closeMapPickerSub() {
  var overlay = document.getElementById('mapPickerOverlaySub');
  if (overlay) overlay.remove();
  mapPickerMapSub = null;
  mapPickerMarkerSub = null;
}

function useCurrentLocationOnMapSub() {
  if (!navigator.geolocation) { document.getElementById('mapPickerAddressSub').textContent = 'Location access is not available on this device.'; return; }
  document.getElementById('mapPickerAddressSub').textContent = 'Getting your current location...';
  navigator.geolocation.getCurrentPosition(function(pos) {
    var latLng = { lat: pos.coords.latitude, lng: pos.coords.longitude };
    if (mapPickerMapSub && mapPickerMarkerSub) { mapPickerMapSub.setCenter(latLng); mapPickerMapSub.setZoom(17); mapPickerMarkerSub.setPosition(latLng); }
    reverseGeocodeForPickerSub(latLng.lat, latLng.lng);
  }, function() {
    document.getElementById('mapPickerAddressSub').textContent = 'Could not get your location. Please allow location access, or drag the pin manually.';
  }, { enableHighAccuracy: true, timeout: 10000 });
}

function reverseGeocodeForPickerSub(lat, lng) {
  var addrEl = document.getElementById('mapPickerAddressSub');
  if (addrEl) addrEl.textContent = 'Looking up address...';
  reverseGeocodeSub(lat, lng, function(formatted) {
    if (!addrEl) return;
    addrEl.dataset.formatted = formatted;
    addrEl.textContent = formatted || 'Could not resolve an address for this spot, but you can still use it.';
  });
}

function confirmMapLocationSub() {
  if (!mapPickerMarkerSub) { closeMapPickerSub(); return; }
  var pos = mapPickerMarkerSub.getPosition();
  var addrEl = document.getElementById('mapPickerAddressSub');
  var formatted = (addrEl && addrEl.dataset.formatted) ? addrEl.dataset.formatted : (addrEl ? addrEl.textContent : '');
  applySelectedAddressSub(pos.lat(), pos.lng(), formatted);
  closeMapPickerSub();
}

if (!PLAN_ID) {
  document.getElementById('planSummary').innerHTML = '<div class="form-error" style="display:block;">No plan selected. Please go back and choose a plan.</div>';
} else {
  fetch('../api/get_meal_plans.php?restaurant_id=' + encodeURIComponent(window.restaurantId))
    .then(function(r) { return r.json(); })
    .then(function(data) {
      var plans = data.success ? (data.data || []) : [];
      var plan = null;
      for (var i = 0; i < plans.length; i++) { if (plans[i].id == PLAN_ID) { plan = plans[i]; break; } }
      if (!plan) {
        document.getElementById('planSummary').innerHTML = '<div class="form-error" style="display:block;">This plan is no longer available.</div>';
        return;
      }
      var html = '<div class="plan-summary">';
      html += '<div class="plan-name">' + escapeHtml(plan.plan_name) + '</div>';
      html += '<div class="plan-price">' + CURRENCY + parseFloat(plan.price).toFixed(2) + '</div>';
      html += '<div class="plan-tags">';
      html += '<span class="plan-tag">' + plan.total_meal_credits + ' meals</span>';
      html += '<span class="plan-tag">' + (scopeLabels[plan.meal_scope] || plan.meal_scope) + '</span>';
      if (plan.bonus_credits > 0) html += '<span class="plan-tag">+' + plan.bonus_credits + ' bonus</span>';
      html += '</div></div>';
      document.getElementById('planSummary').innerHTML = html;
      document.getElementById('subscribeForm').style.display = 'block';
    })
    .catch(function() {
      document.getElementById('planSummary').innerHTML = '<div class="form-error" style="display:block;">Failed to load plan details.</div>';
    });
}

document.getElementById('subscribeForm').addEventListener('submit', function(e) {
  e.preventDefault();
  hideFormError();

  var phone = document.getElementById('deliveryPhone').value.trim();
  var address = document.getElementById('deliveryAddressFormatted').value.trim() || document.getElementById('google-places-autocomplete').value.trim();
  var addressLat = document.getElementById('deliveryAddressLat').value;
  var addressLng = document.getElementById('deliveryAddressLng').value;
  var paymentMethod = document.getElementById('paymentMethod').value;

  if (!phone) { showFormError('Please enter a delivery phone number'); return; }
  if (!address) { showFormError('Please enter your delivery address (search it, use current location, or pick it on the map)'); return; }
  if (paymentMethod === 'QR Payment' && !__paymentProofBase64) { showFormError('Please upload a screenshot of your payment'); return; }

  var btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.textContent = 'Subscribing...';

  var fd = new FormData();
  fd.append('action', 'create_subscription');
  fd.append('mealPlanId', PLAN_ID);
  fd.append('deliveryPhone', phone);
  fd.append('deliveryAddress', address);
  fd.append('deliveryLat', addressLat);
  fd.append('deliveryLng', addressLng);
  fd.append('paymentMethod', paymentMethod);
  if (__paymentProofBase64) fd.append('paymentProofBase64', __paymentProofBase64);

  fetch('../controllers/meal_subscription_operations.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) {
        if (data.phonepe_required) {
          // Wired up once phonepe_subscription_payment.php exists; until
          // then this subscription sits as pending_payment for staff to
          // reconcile manually, same safety net Cash orders already lean on.
          window.location.href = 'phonepe_subscription_payment.php?action=initiate&subscription_id=' + data.subscription_id;
        } else {
          window.location.href = 'my-subscription.php';
        }
      } else {
        showFormError(data.message || 'Failed to subscribe. Please try again.');
        btn.disabled = false;
        btn.textContent = 'Subscribe & Pay';
      }
    })
    .catch(function() {
      showFormError('Network error. Please try again.');
      btn.disabled = false;
      btn.textContent = 'Subscribe & Pay';
    });
});
</script>
</body>
</html>
