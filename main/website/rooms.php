<?php
/**
 * {site}/rooms: the restaurant's rooms (hotel / lodge), and a booking request for them (config/rooms.php,
 * api/rooms_public.php). Pick dates and guests, see what's free, book; the restaurant confirms it.
 * Only while the restaurant has Rooms on (otherwise to the menu, like Reservations).
 * Below the rooms: the owner's check-in / check-out times, cancellation policy and house rules (Rooms › Settings),
 * repeated in short on the booking sheet; an extra bed can be asked for when the owner offers one.
 */
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../config/rooms.php';
if (!roomsFeatureEnabled($conn, $restaurant_id)) {
    header('Location: ' . restaurantPageUrl('menu'));
    exit();
}
$rmTz = $restaurant_tz ?? new DateTimeZone('Asia/Kolkata');
$rmToday = (new DateTime('today', $rmTz))->format('Y-m-d');
$rmTomorrow = (new DateTime('tomorrow', $rmTz))->format('Y-m-d');
$rmMax = (new DateTime('today', $rmTz))->modify('+' . ROOM_BOOK_AHEAD_DAYS . ' days')->format('Y-m-d');
$rmH = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<base href="<?php echo $website_base_href; ?>">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="restaurant-id" content="<?php echo $rmH($restaurant_id ?? ''); ?>">
<?php include __DIR__ . '/cart_scope.php'; ?>
<title>Rooms - <?php echo $rmH($restaurant_name ?? 'Restaurant'); ?></title>
<meta name="description" content="<?php echo $rmH('Book a room at ' . ($restaurant_name ?? 'our place') . '. See rooms, photos and prices, and send a booking request.'); ?>">
<link rel="icon" href="<?php echo $rmH($favicon_href ?? $local_favicon_svg); ?>">
<?php require_once __DIR__ . '/font_links.php'; echo websiteFontLinks(['poppins', 'fontawesome']); ?>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Poppins', sans-serif; background: #e8ecf2; color: #1a1b1f; min-height: 100vh; overflow-x: hidden; }
.phone-frame { max-width: 425px; margin: 0 auto; min-height: 100vh; background: #fff; position: relative; box-shadow: 0 0 40px rgba(0,0,0,0.08); padding-bottom: 24px; }
@media (min-width: 768px) { .phone-frame { margin: 20px auto; min-height: calc(100vh - 40px); border-radius: 28px; overflow: hidden; } }
.pr-share-header { display: flex; align-items: center; gap: 12px; padding: 16px 12px 12px; border-bottom: 1.5px solid #eee; }
.pr-share-header h1 { font-size: 18px; font-weight: 700; flex: 1; }
.back-btn { display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 8px; background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031)); color: #fff; border: none; cursor: pointer; font-size: 20px; flex-shrink: 0; }
.content { padding: 14px; }

.rm-search { background: #f8f9fb; border: 1.5px solid #eef0f4; border-radius: 16px; padding: 12px; margin-bottom: 16px; }
.rm-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.rm-row + .rm-row { margin-top: 10px; }
.rm-search label { display: block; font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 4px; }
.rm-search input, .rm-search select, .rm-sheet input, .rm-sheet textarea, .rm-sheet select {
  width: 100%; padding: 10px 11px; border: 2px solid #e5e7eb; border-radius: 10px; font-size: 14px; font-family: 'Poppins', sans-serif; background: #fff; }
.rm-search input:focus, .rm-sheet input:focus, .rm-sheet textarea:focus { outline: none; border-color: var(--primary-red, #e17055); }
.rm-nights { margin-top: 8px; font-size: 12.5px; color: #6b7280; text-align: center; }

.rm-card { border: 1.5px solid #eef0f4; border-radius: 18px; overflow: hidden; margin-bottom: 16px; background: #fff; }
.rm-photos { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; aspect-ratio: 16 / 10; background: #f1f3f6; scrollbar-width: none; }
.rm-photos::-webkit-scrollbar { display: none; }
.rm-photos img { flex: 0 0 100%; width: 100%; height: 100%; object-fit: cover; scroll-snap-align: start; }
.rm-nophoto { width: 100%; display: flex; align-items: center; justify-content: center; color: #c3c8d1; font-size: 40px; }
.rm-dots { display: flex; gap: 4px; justify-content: center; margin-top: -18px; position: relative; height: 18px; }
.rm-dots i { width: 6px; height: 6px; border-radius: 50%; background: rgba(255,255,255,.6); }
.rm-body { padding: 12px 14px 14px; }
.rm-top { display: flex; justify-content: space-between; gap: 10px; align-items: flex-start; }
.rm-name { font-size: 16px; font-weight: 700; }
.rm-price { text-align: right; white-space: nowrap; }
.rm-price b { font-size: 16px; color: var(--primary-red, #e17055); }
.rm-price small { display: block; font-size: 11px; color: #9ca3af; }
.rm-meta { font-size: 12.5px; color: #6b7280; margin-top: 2px; }
.rm-desc { font-size: 13px; color: #4b5563; margin-top: 8px; line-height: 1.5; }
.rm-amen { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.rm-amen span { font-size: 11.5px; padding: 3px 9px; border-radius: 999px; background: #f3f4f6; color: #374151; }
.rm-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 12px; }
.rm-left { font-size: 12.5px; font-weight: 600; color: #15803d; }
.rm-left.low { color: #c2410c; }
.rm-left.none { color: #b91c1c; }
.rm-btn { border: none; border-radius: 12px; padding: 10px 18px; background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031)); color: #fff; font-weight: 700; font-size: 14px; font-family: 'Poppins', sans-serif; cursor: pointer; }
.rm-btn:disabled { opacity: .45; cursor: not-allowed; }
.rm-empty, .rm-loading { text-align: center; color: #9ca3af; font-size: 14px; padding: 40px 10px; }

.rm-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 1000; display: none; align-items: flex-end; justify-content: center; }
.rm-overlay.on { display: flex; }
.rm-sheet { background: #fff; width: 100%; max-width: 425px; max-height: 92vh; overflow-y: auto; border-radius: 22px 22px 0 0; padding: 18px 16px 22px; }
.rm-sheet h2 { font-size: 17px; margin-bottom: 2px; padding-right: 36px; }
.rm-sheet .rm-sub { font-size: 12.5px; color: #6b7280; margin-bottom: 14px; }
.rm-sheet .fg { margin-bottom: 12px; }
.rm-sheet label { display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 4px; }
.rm-sheet textarea { min-height: 60px; resize: vertical; }
.rm-close { position: absolute; right: 14px; top: 14px; width: 34px; height: 34px; border-radius: 50%; border: none; background: #f3f4f6; font-size: 16px; cursor: pointer; }
.rm-sum { background: #f8f9fb; border-radius: 12px; padding: 10px 12px; font-size: 13px; margin: 4px 0 14px; }
.rm-sum div { display: flex; justify-content: space-between; padding: 2px 0; }
.rm-sum .t { font-weight: 700; font-size: 15px; border-top: 1px dashed #d1d5db; margin-top: 4px; padding-top: 6px; }
.rm-note { font-size: 12px; color: #6b7280; text-align: center; margin-top: 10px; }
.rm-err { display: none; background: #fee2e2; color: #991b1b; border-radius: 10px; padding: 9px 12px; font-size: 13px; margin-bottom: 12px; }
.rm-done { text-align: center; padding: 26px 6px 6px; }
.rm-done i { font-size: 52px; color: #22c55e; display: block; margin-bottom: 12px; }
.rm-done h2 { padding: 0; }
.rm-pol { border: 1.5px solid #eef0f4; border-radius: 18px; padding: 14px; margin-bottom: 16px; }
.rm-pol h2 { font-size: 15px; margin-bottom: 10px; }
.rm-times { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px; }
.rm-times div { background: #f8f9fb; border-radius: 12px; padding: 9px 11px; }
.rm-times small { display: block; font-size: 11.5px; color: #6b7280; }
.rm-times b { font-size: 14px; }
.rm-pol h3 { font-size: 13px; margin: 10px 0 4px; }
.rm-pol p, .rm-pol li { font-size: 12.5px; color: #4b5563; line-height: 1.55; }
.rm-pol ul { padding-left: 18px; }
.rm-agree { font-size: 12px; color: #4b5563; background: #f8f9fb; border-radius: 12px; padding: 9px 11px; margin-bottom: 12px; line-height: 1.5; }
.rm-chk { display: flex; gap: 8px; align-items: center; font-size: 13px; margin-bottom: 12px; }
.rm-chk input { width: auto; }
</style>
</head>
<body>
<div class="phone-frame">
  <div class="pr-share-header">
    <button type="button" class="back-btn" onclick="window.location.href='<?php echo restaurantPageUrl(); ?>'" aria-label="Back"><i class="fa fa-arrow-left"></i></button>
    <h1>Rooms</h1>
  </div>
  <div class="content">
    <div class="rm-search">
      <div class="rm-row">
        <div><label for="rmIn">Check-in</label><input type="date" id="rmIn" value="<?php echo $rmToday; ?>" min="<?php echo $rmToday; ?>" max="<?php echo $rmMax; ?>"></div>
        <div><label for="rmOut">Check-out</label><input type="date" id="rmOut" value="<?php echo $rmTomorrow; ?>" min="<?php echo $rmTomorrow; ?>"></div>
      </div>
      <div class="rm-row">
        <div><label for="rmGuests">Guests</label><select id="rmGuests"><?php for ($i = 1; $i <= 10; $i++) echo '<option value="' . $i . '"' . ($i === 2 ? ' selected' : '') . '>' . $i . '</option>'; ?></select></div>
        <div><label for="rmRooms">Rooms</label><select id="rmRooms"><?php for ($i = 1; $i <= 5; $i++) echo '<option value="' . $i . '">' . $i . '</option>'; ?></select></div>
      </div>
      <div class="rm-nights" id="rmNights"></div>
    </div>
    <div id="rmList"><div class="rm-loading">Loading rooms…</div></div>
  </div>
</div>

<div class="rm-overlay" id="rmOverlay" role="dialog" aria-modal="true">
  <div class="rm-sheet" id="rmSheet" style="position:relative"></div>
</div>

<script>
(function () {
  var RID = <?php echo json_encode($restaurant_id ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  var CUSTOMER = <?php echo !empty($logged_in_customer) ? json_encode($logged_in_customer, JSON_HEX_TAG | JSON_HEX_AMP) : 'null'; ?>;
  var API = '../api/rooms_public.php';
  var $ = function (id) { return document.getElementById(id); };
  var data = { rooms: [], currency: '', nights: 0 };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var money = function (n) { return data.currency + (Math.round(n * 100) / 100).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 }); };
  var fmt = function (d) { var x = new Date(d + 'T00:00:00'); return x.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }); };
  var addDay = function (d, n) { var x = new Date(d + 'T00:00:00'); x.setDate(x.getDate() + n); return x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0'); };

  function load() {
    var ci = $('rmIn').value, co = $('rmOut').value;
    if (co <= ci) { co = addDay(ci, 1); $('rmOut').value = co; }
    $('rmOut').min = addDay(ci, 1);
    $('rmList').innerHTML = '<div class="rm-loading">Checking free rooms…</div>';
    var url = API + '?restaurant_id=' + encodeURIComponent(RID) + '&in=' + ci + '&out=' + co;
    (window.rgFetchJson ? window.rgFetchJson(url) : fetch(url).then(function (r) { return r.json(); })).then(function (d) {
      if (!d.success) { $('rmList').innerHTML = '<div class="rm-empty">' + esc(d.message || 'Couldn\'t load the rooms.') + '</div>'; return; }
      data = d;
      $('rmNights').textContent = d.nights + ' night' + (d.nights === 1 ? '' : 's') + ' · ' + fmt(ci) + ' – ' + fmt(co);
      render();
    }).catch(function () {
      if (window.rgLoadFailed) window.rgLoadFailed($('rmList'), 'the rooms', load);
      else $('rmList').innerHTML = '<div class="rm-empty">Couldn\'t load the rooms. <a href="#" onclick="location.reload();return false">Try again</a></div>';
    });
  }

  function policies() {
    var p = data.policies;
    if (!p) return '';
    return '<div class="rm-pol"><h2>Good to know</h2>' +
      '<div class="rm-times"><div><small>Check-in</small><b>From ' + esc(p.checkin) + '</b></div><div><small>Check-out</small><b>By ' + esc(p.checkout) + '</b></div></div>' +
      (p.cancel ? '<h3>Cancellation</h3><p>' + esc(p.cancel) + '</p>' : '') +
      (p.rules.length ? '<h3>House rules</h3><ul>' + p.rules.map(function (r) { return '<li>' + esc(r) + '</li>'; }).join('') + '</ul>' : '') +
      (p.extra_bed > 0 ? '<h3>Extra bed</h3><p>' + money(p.extra_bed) + ' a night, on request.</p>' : '') + '</div>';
  }

  function render() {
    if (!data.rooms.length) { $('rmList').innerHTML = '<div class="rm-empty">No rooms are listed yet.</div>'; return; }
    var guests = +$('rmGuests').value, rooms = +$('rmRooms').value;
    $('rmList').innerHTML = data.rooms.map(function (r) {
      var photos = r.photos.length
        ? r.photos.map(function (p, i) { return '<img src="../' + esc(p) + '" alt="' + esc(r.name) + '"' + (i ? ' loading="lazy"' : '') + '>'; }).join('')
        : '<div class="rm-nophoto"><i class="fa fa-bed"></i></div>';
      var fits = guests <= r.max_guests * rooms;
      var left = r.free == null ? null : r.free;
      var state = left === 0 ? '<span class="rm-left none">Fully booked</span>'
        : left !== null && left < rooms ? '<span class="rm-left low">Only ' + left + ' left</span>'
        : !fits ? '<span class="rm-left low">Up to ' + r.max_guests + ' guest' + (r.max_guests === 1 ? '' : 's') + ' a room</span>'
        : '<span class="rm-left' + (left !== null && left <= 2 ? ' low' : '') + '">' + (left !== null && left <= 2 ? 'Only ' + left + ' left' : 'Available') + '</span>';
      var can = left !== 0 && (left === null || left >= rooms) && fits;
      return '<div class="rm-card">' +
        '<div class="rm-photos">' + photos + '</div>' +
        (r.photos.length > 1 ? '<div class="rm-dots">' + r.photos.map(function () { return '<i></i>'; }).join('') + '</div>' : '') +
        '<div class="rm-body"><div class="rm-top"><div><div class="rm-name">' + esc(r.name) + '</div>' +
        '<div class="rm-meta"><i class="fa fa-user-group"></i> Up to ' + r.max_guests + ' guest' + (r.max_guests === 1 ? '' : 's') + '</div></div>' +
        '<div class="rm-price"><b>' + money(r.price) + '</b><small>a night</small></div></div>' +
        (r.description ? '<div class="rm-desc">' + esc(r.description) + '</div>' : '') +
        (r.amenities.length ? '<div class="rm-amen">' + r.amenities.map(function (a) { return '<span>' + esc(a) + '</span>'; }).join('') + '</div>' : '') +
        '<div class="rm-foot">' + state + '<button type="button" class="rm-btn" data-book="' + r.id + '"' + (can ? '' : ' disabled') + '>Book</button></div></div></div>';
    }).join('') + policies();
  }

  function close() { $('rmOverlay').classList.remove('on'); }
  function openBook(id) {
    var r = data.rooms.filter(function (x) { return x.id === id; })[0];
    if (!r) return;
    var rooms = +$('rmRooms').value, n = data.nights, total = r.price * n * rooms;
    $('rmSheet').innerHTML = '<button type="button" class="rm-close" aria-label="Close" data-close>✕</button>' +
      '<h2>' + esc(r.name) + '</h2><div class="rm-sub">' + fmt($('rmIn').value) + ' – ' + fmt($('rmOut').value) + ' · ' + $('rmGuests').value + ' guest' + ($('rmGuests').value === '1' ? '' : 's') + '</div>' +
      '<div class="rm-err" id="rmErr"></div>' +
      '<div class="rm-sum"><div><span>' + money(r.price) + ' × ' + n + ' night' + (n === 1 ? '' : 's') + (rooms > 1 ? ' × ' + rooms + ' rooms' : '') + '</span><span>' + money(total) + '</span></div>' +
      '<div class="t"><span>Total</span><span>' + money(total) + '</span></div></div>' +
      '<div class="fg"><label for="rmName">Your name</label><input id="rmName" autocomplete="name" maxlength="120"></div>' +
      '<div class="fg"><label for="rmPhone">Phone</label><input id="rmPhone" type="tel" autocomplete="tel" maxlength="15"></div>' +
      '<div class="fg"><label for="rmEmail">Email (optional)</label><input id="rmEmail" type="email" autocomplete="email" maxlength="190"></div>' +
      '<div class="fg"><label for="rmNotes">Anything we should know? (optional)</label><textarea id="rmNotes" maxlength="400" placeholder="Arrival time, a special request…"></textarea></div>' +
      (data.policies && data.policies.extra_bed > 0 ? '<label class="rm-chk"><input type="checkbox" id="rmBed"> Extra bed (' + money(data.policies.extra_bed) + ' a night, paid at the hotel)</label>' : '') +
      (data.policies ? '<div class="rm-agree">Check-in from ' + esc(data.policies.checkin) + ', check-out by ' + esc(data.policies.checkout) + '. ' + esc(data.policies.cancel || '') +
        (data.policies.rules.length ? ' By booking you agree to the house rules below the rooms.' : '') + '</div>' : '') +
      '<button type="button" class="rm-btn" id="rmSend" style="width:100%;padding:13px">Send booking request</button>' +
      '<div class="rm-note">Pay at the hotel. We\'ll call or message you to confirm.</div>';
    if (CUSTOMER) {
      if (CUSTOMER.customer_name) $('rmName').value = CUSTOMER.customer_name;
      if (CUSTOMER.phone) $('rmPhone').value = CUSTOMER.phone;
      if (CUSTOMER.email) $('rmEmail').value = CUSTOMER.email;
    }
    $('rmOverlay').classList.add('on');
    $('rmSend').onclick = function () { send(r); };
  }

  function send(r) {
    var err = $('rmErr'), btn = $('rmSend');
    var body = { restaurant_id: RID, room_type_id: r.id, in: $('rmIn').value, out: $('rmOut').value, guests: +$('rmGuests').value, rooms: +$('rmRooms').value,
      name: $('rmName').value.trim(), phone: $('rmPhone').value.trim(), email: $('rmEmail').value.trim(),
      notes: ($('rmBed') && $('rmBed').checked ? 'Extra bed needed. ' : '') + $('rmNotes').value.trim() };
    err.style.display = 'none';
    if (!body.name) { err.textContent = 'Please enter your name.'; err.style.display = 'block'; return; }
    if (body.phone.replace(/\D/g, '').length < 7) { err.textContent = 'Please enter a valid phone number.'; err.style.display = 'block'; return; }
    btn.disabled = true; btn.textContent = 'Sending…';
    fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (res) { return res.json(); })
      .then(function (d) {
        if (!d.success) { err.textContent = d.message || 'Could not send the request.'; err.style.display = 'block'; btn.disabled = false; btn.textContent = 'Send booking request'; return; }
        $('rmSheet').innerHTML = '<button type="button" class="rm-close" aria-label="Close" data-close>✕</button><div class="rm-done"><i class="fa fa-circle-check"></i>' +
          '<h2>Booking request sent</h2><div class="rm-sub" style="margin-top:6px">' + esc(d.room) + ' · ' + fmt(body.in) + ' – ' + fmt(body.out) + ' · total ' + money(d.total) + '</div>' +
          '<p class="rm-note" style="font-size:13px">We\'ll call or message you on ' + esc(body.phone) + ' to confirm. Pay at the hotel.</p>' +
          '<button type="button" class="rm-btn" data-close style="margin-top:14px">Done</button></div>';
        load();
      })
      .catch(function () { err.textContent = 'Network error. Please try again.'; err.style.display = 'block'; btn.disabled = false; btn.textContent = 'Send booking request'; });
  }

  ['rmIn', 'rmOut'].forEach(function (id) { $(id).addEventListener('change', load); });
  ['rmGuests', 'rmRooms'].forEach(function (id) { $(id).addEventListener('change', render); });
  $('rmList').addEventListener('click', function (e) { var b = e.target.closest('[data-book]'); if (b && !b.disabled) openBook(+b.dataset.book); });
  $('rmOverlay').addEventListener('click', function (e) { if (e.target === this || e.target.closest('[data-close]')) close(); });
  load();
})();
</script>
<?php include __DIR__ . '/call_waiter.php'; ?>
</body>
</html>
