<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../config/reservation_helpers.php';
if (!reservationsFeatureEnabled($conn, $restaurant_id)) {
    header('Location: ' . restaurantPageUrl('menu'));
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
<title>Book a Table - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" href="<?php echo htmlspecialchars($favicon_href ?? $local_favicon_svg, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Poppins', sans-serif; background: #e8ecf2; color: #1a1b1f; min-height: 100vh; overflow-x: hidden; }
.phone-frame { max-width: 425px; margin: 0 auto; min-height: 100vh; background: #fff; position: relative; box-shadow: 0 0 40px rgba(0,0,0,0.08); }
@media (min-width: 768px) { .phone-frame { margin: 20px auto; min-height: calc(100vh - 40px); border-radius: 28px; overflow: hidden; } <?php if ($host === 'triposhsymmetry.in'): ?>.phone-frame { max-width: 720px; }<?php endif; ?> }

.pr-share-header { display: flex; align-items: center; gap: 12px; padding: 16px 12px 12px; border-bottom: 1.5px solid #eee; }
.pr-share-header h1 { font-size: 18px; font-weight: 700; flex: 1; }
.back-btn { display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 8px; background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031)); color: #fff; border: none; cursor: pointer; font-size: 20px; flex-shrink: 0; }

.content { padding: 14px; }
.hero { text-align: center; margin-bottom: 18px; }
.hero h2 { font-size: 19px; font-weight: 700; margin-bottom: 4px; }
.hero p { font-size: 13px; color: #999; }

.form-group { margin-bottom: 16px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
label .req { color: #d63031; }
input[type=text], input[type=tel], input[type=email], input[type=date], input[type=number], input[type=time], select, textarea {
  width: 100%; padding: 11px 12px; border: 2px solid #e5e7eb; border-radius: 10px; font-size: 14.5px; font-family: 'Poppins', sans-serif; background: #fafafa; transition: all 0.2s;
}
input:focus, select:focus, textarea:focus { outline: none; border-color: var(--primary-red, #e17055); background: #fff; }
textarea { resize: vertical; min-height: 70px; }
.field-error { display: none; color: #d63031; font-size: 12px; margin-top: 4px; }

.time-slots { display: flex; flex-wrap: wrap; gap: 8px; }
.time-slot-btn { padding: 9px 14px; border: 2px solid #e5e7eb; border-radius: 20px; background: #fff; font-size: 13px; font-weight: 600; font-family: 'Poppins', sans-serif; cursor: pointer; color: #374151; transition: all 0.15s; }
.time-slot-btn.active { border-color: var(--primary-red, #e17055); background: var(--primary-red, #e17055); color: #fff; }
.time-slot-btn.custom-time-btn { background: #f3f4f6; border-style: dashed; }

.book-btn { width: 100%; margin-top: 8px; padding: 14px; border: none; border-radius: 12px; background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031)); color: #fff; font-weight: 700; font-size: 15px; font-family: 'Poppins', sans-serif; cursor: pointer; }
.book-btn:hover { opacity: 0.92; }
.book-btn:disabled { opacity: 0.6; cursor: not-allowed; }

.alert-box { border-radius: 10px; padding: 12px 14px; font-size: 13.5px; margin-bottom: 16px; display: none; }
.alert-box.error { background: #fee; border: 1.5px solid #fcc; color: #c33; }
.alert-box.success { background: #eafaf0; border: 1.5px solid #b7ebc9; color: #1b7a3a; }

.confirm-panel { text-align: center; padding: 40px 16px; }
.confirm-panel i { font-size: 56px; color: #22c55e; margin-bottom: 16px; display: block; }
.confirm-panel h2 { font-size: 20px; margin-bottom: 8px; }
.confirm-panel p { font-size: 14px; color: #666; margin-bottom: 24px; }
.confirm-summary { text-align: left; background: #f9fafb; border-radius: 12px; padding: 16px; margin-bottom: 20px; font-size: 13.5px; }
.confirm-summary div { display: flex; justify-content: space-between; padding: 5px 0; }
.confirm-summary div span:first-child { color: #888; }
.confirm-summary div span:last-child { font-weight: 600; }
</style>
</head>
<body>
<div class="phone-frame">
  <div class="pr-share-header">
    <button class="back-btn" onclick="window.location.href='<?php echo restaurantPageUrl('menu'); ?>'" aria-label="Back"><i class="fa fa-arrow-left"></i></button>
    <h1>Book a Table</h1>
  </div>

  <div class="content">
    <div id="bookingView">
      <div class="hero">
        <h2>Reserve Your Table</h2>
        <p>Pick a date &amp; time and we'll hold a table for you</p>
      </div>

      <div id="resAlertError" class="alert-box error"></div>

      <form id="reservationForm">
        <div class="form-row">
          <div class="form-group">
            <label for="resDate">Date <span class="req">*</span></label>
            <input type="date" id="resDate" required>
            <span class="field-error" id="resDateError"></span>
          </div>
          <div class="form-group">
            <label for="resGuests">Guests <span class="req">*</span></label>
            <input type="number" id="resGuests" min="1" value="2" required>
            <span class="field-error" id="resGuestsError"></span>
          </div>
        </div>

        <div class="form-group">
          <label for="resMealType">Meal Type</label>
          <select id="resMealType">
            <option value="Breakfast">Breakfast</option>
            <option value="Lunch" selected>Lunch</option>
            <option value="Dinner">Dinner</option>
            <option value="Snacks">Snacks</option>
          </select>
        </div>

        <div class="form-group">
          <label>Time Slot <span class="req">*</span></label>
          <div id="resTimeSlots" class="time-slots"></div>
          <div id="resCustomTimeWrap" style="display:none;margin-top:10px;">
            <input type="time" id="resCustomTime">
          </div>
          <span class="field-error" id="resTimeSlotError"></span>
        </div>

        <div class="form-group">
          <label for="resTable">Table <span class="req">*</span></label>
          <select id="resTable" required>
            <option value="">-- Select a table --</option>
          </select>
          <span class="field-error" id="resTableError"></span>
        </div>

        <div class="form-group">
          <label for="resName">Your Name <span class="req">*</span></label>
          <input type="text" id="resName" required autocomplete="off">
          <span class="field-error" id="resNameError"></span>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="resPhone">Phone <span class="req">*</span></label>
            <input type="tel" id="resPhone" required autocomplete="off">
            <span class="field-error" id="resPhoneError"></span>
          </div>
          <div class="form-group">
            <label for="resEmail">Email</label>
            <input type="email" id="resEmail" autocomplete="off">
          </div>
        </div>

        <div class="form-group">
          <label for="resNotes">Special Request</label>
          <textarea id="resNotes" placeholder="Anniversary, high chair needed, window seat..."></textarea>
        </div>

        <button type="submit" class="book-btn" id="resSubmitBtn">Reserve Now</button>
      </form>
    </div>

    <div id="confirmView" class="confirm-panel" style="display:none;">
      <i class="fa fa-circle-check"></i>
      <h2>Reservation Requested!</h2>
      <p>We'll confirm your table shortly. Hang tight!</p>
      <div class="confirm-summary" id="confirmSummary"></div>
      <button type="button" class="book-btn" onclick="window.location.href='<?php echo restaurantPageUrl('menu'); ?>'">Back to Menu</button>
    </div>
  </div>
</div>

<script>
var RESTAURANT_ID = <?php echo json_encode($restaurant_id ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
var LOGGED_IN_CUSTOMER = <?php echo $logged_in_customer ? json_encode($logged_in_customer, JSON_HEX_TAG | JSON_HEX_AMP) : 'null'; ?>;
var selectedResTimeSlot = '';

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

function clearResFieldError(id) {
  var el = document.getElementById(id + 'Error');
  if (el) { el.style.display = 'none'; el.textContent = ''; }
}
function showResFieldError(id, msg) {
  var el = document.getElementById(id + 'Error');
  if (el) { el.style.display = 'block'; el.textContent = msg; }
}

// Minimum bookable date is today (restaurant's own local date, not the visitor's)
document.getElementById('resDate').min = new Date().toISOString().split('T')[0];
document.getElementById('resDate').value = new Date().toISOString().split('T')[0];

if (LOGGED_IN_CUSTOMER) {
  if (LOGGED_IN_CUSTOMER.customer_name) document.getElementById('resName').value = LOGGED_IN_CUSTOMER.customer_name;
  if (LOGGED_IN_CUSTOMER.phone) document.getElementById('resPhone').value = LOGGED_IN_CUSTOMER.phone;
  if (LOGGED_IN_CUSTOMER.email) document.getElementById('resEmail').value = LOGGED_IN_CUSTOMER.email;
}

function renderResTimeSlots() {
  var slots = ['12:00 PM', '01:00 PM', '02:00 PM', '03:00 PM', '04:00 PM', '05:00 PM', '06:00 PM', '07:00 PM', '08:00 PM', '09:00 PM', '10:00 PM'];
  var wrap = document.getElementById('resTimeSlots');
  wrap.innerHTML = slots.map(function(s) {
    return '<button type="button" class="time-slot-btn" data-slot="' + s + '">' + s + '</button>';
  }).join('') + '<button type="button" class="time-slot-btn custom-time-btn" data-slot="custom"><i class="fa fa-clock"></i> Custom</button>';

  wrap.querySelectorAll('.time-slot-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      wrap.querySelectorAll('.time-slot-btn').forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      clearResFieldError('resTimeSlot');
      var customWrap = document.getElementById('resCustomTimeWrap');
      if (this.dataset.slot === 'custom') {
        customWrap.style.display = 'block';
        selectedResTimeSlot = '';
      } else {
        customWrap.style.display = 'none';
        selectedResTimeSlot = this.dataset.slot;
      }
    });
  });
}
renderResTimeSlots();

document.getElementById('resCustomTime').addEventListener('input', function() {
  if (!this.value) { selectedResTimeSlot = ''; return; }
  var parts = this.value.split(':');
  var h = parseInt(parts[0], 10);
  var m = parts[1];
  var ampm = h >= 12 ? 'PM' : 'AM';
  var h12 = h % 12; if (h12 === 0) h12 = 12;
  selectedResTimeSlot = (h12 < 10 ? '0' + h12 : h12) + ':' + m + ' ' + ampm;
});

function loadTablesForReservation() {
  var sel = document.getElementById('resTable');
  fetch('../api/get_tables.php?restaurant_id=' + encodeURIComponent(RESTAURANT_ID))
    .then(function(r) { return r.json(); })
    .then(function(result) {
      if (result.success && Array.isArray(result.data)) {
        result.data.forEach(function(t) {
          var opt = document.createElement('option');
          opt.value = t.id;
          opt.textContent = t.table_number + ' - ' + t.area_name + ' (' + t.capacity + ' seats)';
          sel.appendChild(opt);
        });
      }
    })
    .catch(function() {});
}
loadTablesForReservation();

document.getElementById('reservationForm').addEventListener('submit', function(e) {
  e.preventDefault();

  ['resDate', 'resGuests', 'resTimeSlot', 'resTable', 'resName', 'resPhone'].forEach(function(id) { clearResFieldError(id); });
  var errorBox = document.getElementById('resAlertError');
  errorBox.style.display = 'none';

  var date = document.getElementById('resDate').value;
  var guests = parseInt(document.getElementById('resGuests').value, 10) || 0;
  var mealType = document.getElementById('resMealType').value;
  var tableId = document.getElementById('resTable').value;
  var name = document.getElementById('resName').value.trim();
  var phone = document.getElementById('resPhone').value.trim();
  var email = document.getElementById('resEmail').value.trim();
  var notes = document.getElementById('resNotes').value.trim();

  var hasError = false;
  if (!date) { showResFieldError('resDate', 'Please pick a date'); hasError = true; }
  if (!guests || guests < 1) { showResFieldError('resGuests', 'At least 1 guest'); hasError = true; }
  if (!selectedResTimeSlot) { showResFieldError('resTimeSlot', 'Please pick a time'); hasError = true; }
  if (!tableId) { showResFieldError('resTable', 'Please pick a table'); hasError = true; }
  if (!name) { showResFieldError('resName', 'Name is required'); hasError = true; }
  if (!phone) { showResFieldError('resPhone', 'Phone is required'); hasError = true; }
  if (hasError) return;

  var btn = document.getElementById('resSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Booking...';

  fetch('../api/create_reservation.php?restaurant_id=' + encodeURIComponent(RESTAURANT_ID), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      table_id: tableId,
      reservation_date: date,
      time_slot: selectedResTimeSlot,
      no_of_guests: guests,
      meal_type: mealType,
      customer_name: name,
      phone: phone,
      email: email,
      special_request: notes
    })
  })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      btn.disabled = false;
      btn.innerHTML = 'Reserve Now';
      if (data.success) {
        document.getElementById('bookingView').style.display = 'none';
        var summary = document.getElementById('confirmSummary');
        summary.innerHTML =
          '<div><span>Date</span><span>' + escapeHtml(date) + '</span></div>' +
          '<div><span>Time</span><span>' + escapeHtml(selectedResTimeSlot) + '</span></div>' +
          '<div><span>Guests</span><span>' + guests + '</span></div>' +
          '<div><span>Name</span><span>' + escapeHtml(name) + '</span></div>';
        document.getElementById('confirmView').style.display = 'block';
      } else {
        errorBox.textContent = data.message || 'Could not create reservation. Please try a different table or time.';
        errorBox.style.display = 'block';
        errorBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    })
    .catch(function() {
      btn.disabled = false;
      btn.innerHTML = 'Reserve Now';
      errorBox.textContent = 'Network error. Please try again.';
      errorBox.style.display = 'block';
    });
});
</script>
</body>
</html>
