/* Rooms page (dashboard #roomsPage, api/rooms_admin.php, config/rooms.php).
   Today's numbers, booking requests to confirm, check-in / check-out, paid; room types with photos.
   Also the Settings › Ordering "Rooms" switch (#enableRoomsToggle), which saves straight away and shows
   the sidebar item. window.RGRooms.load() runs when the page opens (script.js showPage). */
(function () {
  var API = '../api/rooms_admin.php';
  var S = { data: null, tab: 'bookings', filter: 'upcoming' };
  var AMENITIES = ['WiFi', 'AC', 'TV', 'Hot water', 'Attached bathroom', 'Balcony', 'Breakfast', 'Parking', 'Room service', 'Mountain view'];
  var NEXT = {
    'Pending': [['Confirmed', 'Confirm', 'primary'], ['Declined', 'Decline', 'off']],
    'Confirmed': [['Checked in', 'Check in', 'primary'], ['Cancelled', 'Cancel', 'off']],
    'Checked in': [['Checked out', 'Check out', 'primary']],
    'Checked out': [], 'Cancelled': [['Confirmed', 'Take back', 'plain']], 'Declined': [['Confirmed', 'Take back', 'plain']]
  };
  var TONE = { 'Pending': 'amber', 'Confirmed': 'blue', 'Checked in': 'green', 'Checked out': 'grey', 'Cancelled': 'red', 'Declined': 'red' };

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function notify(m, t) { if (typeof window.showNotification === 'function') window.showNotification(m, t); }
  function money(n) { return (S.data ? S.data.currency : '') + (+n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }); }
  function day(d) { return new Date(d + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' }); }
  function post(body) {
    return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); });
  }

  function css() {
    if ($('rmStyles')) return;
    var s = document.createElement('style');
    s.id = 'rmStyles';
    s.textContent =
      '.rm-off{display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap;background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;padding:14px 16px;margin-bottom:16px;font-size:14px;color:#7c2d12}' +
      '.rm-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}' +
      '.rm-stat{background:#fff;border:1px solid #eef0f4;border-radius:14px;padding:12px 14px}.rm-stat small{display:block;color:#6b7280;font-size:12px}.rm-stat b{font-size:22px}' +
      '.rm-tabs{display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap}.rm-tabs button,.rm-chips button{border:1px solid #e5e7eb;background:#fff;border-radius:999px;padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer;color:#374151}' +
      '.rm-tabs button.on,.rm-chips button.on{background:#111827;color:#fff;border-color:#111827}.rm-chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}' +
      '.rm-list{display:grid;gap:10px}.rm-bk{background:#fff;border:1px solid #eef0f4;border-radius:14px;padding:12px 14px;display:grid;gap:8px}' +
      '.rm-bk-top{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}.rm-bk-top b{font-size:15px}.rm-bk small{color:#6b7280}' +
      '.rm-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:600}.rm-amber{background:#fef3c7;color:#92400e}.rm-blue{background:#dbeafe;color:#1e40af}.rm-green{background:#dcfce7;color:#166534}.rm-grey{background:#f3f4f6;color:#4b5563}.rm-red{background:#fee2e2;color:#991b1b}' +
      '.rm-acts{display:flex;gap:6px;flex-wrap:wrap}.rm-b{border:0;border-radius:9px;padding:7px 12px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:4px}' +
      '.rm-b.primary{background:#7c3aed;color:#fff}.rm-b.plain{background:#f3f4f6;color:#111827}.rm-b.off{background:#fff;color:#b91c1c;border:1.5px solid #fca5a5}.rm-b .material-symbols-rounded{font-size:17px}' +
      '.rm-types{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}.rm-type{background:#fff;border:1px solid #eef0f4;border-radius:14px;overflow:hidden}' +
      '.rm-type img,.rm-type .ph{width:100%;aspect-ratio:16/10;object-fit:cover;background:#f3f4f6;display:flex;align-items:center;justify-content:center;color:#9ca3af}.rm-type .bd{padding:12px 14px;display:grid;gap:6px}' +
      '.rm-empty{background:#fff;border:1px dashed #d1d5db;border-radius:14px;padding:30px;text-align:center;color:#6b7280}' +
      '.rm-ov{position:fixed;inset:0;background:rgba(17,24,39,.5);z-index:10000;display:flex;align-items:center;justify-content:center;padding:16px}' +
      '.rm-md{background:#fff;border-radius:16px;width:100%;max-width:560px;max-height:92vh;overflow:auto;padding:20px;position:relative}.rm-md h3{margin:0 0 14px;font-size:18px}' +
      '.rm-md label{display:block;font-size:13px;font-weight:600;color:#374151;margin:0 0 4px}.rm-md input,.rm-md select,.rm-md textarea{width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #d1d5db;border-radius:9px;font-size:14px;font-family:inherit}' +
      '.rm-md .g{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}.rm-md .f{margin-bottom:12px}.rm-md .x{position:absolute;right:14px;top:14px;border:0;background:#f3f4f6;border-radius:50%;width:32px;height:32px;cursor:pointer}' +
      '.rm-am{display:flex;flex-wrap:wrap;gap:6px}.rm-am label{display:inline-flex;align-items:center;gap:5px;margin:0;font-weight:500;border:1px solid #e5e7eb;border-radius:999px;padding:4px 10px;cursor:pointer}.rm-am input{width:auto}' +
      '.rm-ph{display:flex;gap:8px;flex-wrap:wrap}.rm-ph div{position:relative;width:86px;height:64px;border-radius:8px;overflow:hidden;background:#f3f4f6}.rm-ph img{width:100%;height:100%;object-fit:cover}.rm-ph button{position:absolute;right:2px;top:2px;border:0;background:rgba(0,0,0,.6);color:#fff;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:12px;line-height:20px;padding:0}' +
      '.rm-ph .add{display:flex;align-items:center;justify-content:center;border:1.5px dashed #c4b5fd;color:#7c3aed;cursor:pointer;font-size:12px;text-align:center}' +
      '.rm-md .ft{display:flex;gap:8px;justify-content:flex-end;margin-top:16px}.rm-hint{font-size:12px;color:#6b7280;margin-top:4px}' +
      '@media(max-width:600px){.rm-md .g{grid-template-columns:1fr}}';
    document.head.appendChild(s);
  }

  function load() {
    css();
    var root = $('roomsRoot');
    if (!root) return;
    fetch(API, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.success) { root.innerHTML = '<div class="rm-empty">' + esc(d.message || 'Couldn\'t load rooms.') + '</div>'; return; }
      S.data = d;
      render();
    }).catch(function () { root.innerHTML = '<div class="rm-empty">Couldn\'t load rooms. <a href="#" onclick="RGRooms.load();return false">Try again</a></div>'; });
  }

  function render() {
    var d = S.data, root = $('roomsRoot');
    var h = '';
    if (!d.enabled) {
      h += '<div class="rm-off"><span><b>Rooms is off on your website.</b> Add your room types, then switch it on so guests can book.</span>' +
        (d.can_edit ? '<button type="button" class="rm-b primary" data-rm="enable">Switch on</button>' : '') + '</div>';
    }
    h += '<div class="rm-stats">' +
      '<div class="rm-stat"><small>Arriving today</small><b>' + d.stats.arrivals + '</b></div>' +
      '<div class="rm-stat"><small>Leaving today</small><b>' + d.stats.departures + '</b></div>' +
      '<div class="rm-stat"><small>Requests to confirm</small><b>' + d.stats.pending + '</b></div>' +
      '<div class="rm-stat"><small>Rooms taken tonight</small><b>' + d.stats.occupied + ' / ' + d.stats.rooms + '</b></div></div>';
    h += '<div class="rm-tabs"><button type="button" data-tab="bookings" class="' + (S.tab === 'bookings' ? 'on' : '') + '">Bookings</button>' +
      '<button type="button" data-tab="types" class="' + (S.tab === 'types' ? 'on' : '') + '">Room types (' + d.types.length + ')</button></div>';
    h += S.tab === 'types' ? renderTypes() : renderBookings();
    root.innerHTML = h;
  }

  function renderBookings() {
    var d = S.data, t = d.today;
    var groups = {
      upcoming: function (b) { return ['Pending', 'Confirmed', 'Checked in'].indexOf(b.status) !== -1; },
      pending: function (b) { return b.status === 'Pending'; },
      house: function (b) { return b.status === 'Checked in'; },
      today: function (b) { return (b.in === t && ['Pending', 'Confirmed'].indexOf(b.status) !== -1) || (b.out === t && b.status === 'Checked in'); },
      done: function (b) { return ['Checked out', 'Cancelled', 'Declined'].indexOf(b.status) !== -1; }
    };
    var names = [['upcoming', 'Upcoming'], ['pending', 'Requests'], ['today', 'Today'], ['house', 'In house'], ['done', 'Past & cancelled']];
    var list = d.bookings.filter(groups[S.filter]);
    if (S.filter === 'done') list = list.slice().reverse();
    var h = '<div class="rm-chips">' + names.map(function (n) {
      var c = d.bookings.filter(groups[n[0]]).length;
      return '<button type="button" data-filter="' + n[0] + '" class="' + (S.filter === n[0] ? 'on' : '') + '">' + n[1] + (c ? ' · ' + c : '') + '</button>';
    }).join('') + '</div>';
    if (!d.types.length) return h + '<div class="rm-empty">Add your first room type (for example "Deluxe Double, 4 rooms") to start taking bookings.<br><br><button type="button" class="rm-b primary" data-rm="add-type">Add room type</button></div>';
    if (!list.length) return h + '<div class="rm-empty">No bookings here.</div>';
    return h + '<div class="rm-list">' + list.map(function (b) {
      var acts = d.can_edit ? (NEXT[b.status] || []).map(function (a) {
        return '<button type="button" class="rm-b ' + a[2] + '" data-status="' + esc(a[0]) + '" data-id="' + b.id + '">' + a[1] + '</button>';
      }).join('') : '';
      if (d.can_edit && ['Confirmed', 'Checked in', 'Checked out'].indexOf(b.status) !== -1) {
        acts += '<button type="button" class="rm-b plain" data-paid="' + (b.paid ? 0 : 1) + '" data-id="' + b.id + '"><span class="material-symbols-rounded">' + (b.paid ? 'undo' : 'payments') + '</span>' + (b.paid ? 'Mark unpaid' : 'Mark paid') + '</button>';
      }
      var wa = b.phone.replace(/\D/g, '');
      acts += '<a class="rm-b plain" href="tel:' + esc(b.phone) + '"><span class="material-symbols-rounded">call</span>Call</a>' +
        '<a class="rm-b plain" href="https://wa.me/' + esc(wa) + '" target="_blank" rel="noopener"><span class="material-symbols-rounded">chat</span>WhatsApp</a>';
      return '<div class="rm-bk"><div class="rm-bk-top"><div><b>' + esc(b.name) + '</b> <small>· ' + esc(b.phone) + (b.source === 'website' ? ' · from website' : '') + '</small></div>' +
        '<div><span class="rm-pill rm-' + (TONE[b.status] || 'grey') + '">' + esc(b.status) + '</span> ' +
        (b.paid ? '<span class="rm-pill rm-green">Paid</span>' : (b.status !== 'Cancelled' && b.status !== 'Declined' ? '<span class="rm-pill rm-grey">Not paid</span>' : '')) + '</div></div>' +
        '<div>' + esc(b.type) + (b.rooms > 1 ? ' × ' + b.rooms + ' rooms' : '') + ' · ' + b.guests + ' guest' + (b.guests === 1 ? '' : 's') +
        (b.room_numbers ? ' · Room ' + esc(b.room_numbers) : '') + '</div>' +
        '<div><b>' + day(b.in) + '</b> → <b>' + day(b.out) + '</b> <small>· ' + b.nights + ' night' + (b.nights === 1 ? '' : 's') + ' · ' + money(b.total) + '</small></div>' +
        (b.notes ? '<small>' + (b.source === 'website' ? 'Guest note: ' : 'Note: ') + esc(b.notes) + '</small>' : '') +
        (b.owner_note ? '<small>Your note: ' + esc(b.owner_note) + '</small>' : '') +
        '<div class="rm-acts">' + acts + '</div></div>';
    }).join('') + '</div>';
  }

  function renderTypes() {
    var d = S.data;
    if (!d.types.length) return '<div class="rm-empty">No room types yet.<br><br><button type="button" class="rm-b primary" data-rm="add-type">Add room type</button></div>';
    return '<div class="rm-types">' + d.types.map(function (t) {
      return '<div class="rm-type">' + (t.photos[0] ? '<img src="../' + esc(t.photos[0]) + '" alt="" loading="lazy">' : '<div class="ph"><span class="material-symbols-rounded">meeting_room</span></div>') +
        '<div class="bd"><div style="display:flex;justify-content:space-between;gap:8px"><b>' + esc(t.name) + '</b><b>' + money(t.price) + '<small style="font-weight:400;color:#6b7280"> / night</small></b></div>' +
        '<small>' + t.room_count + ' room' + (t.room_count === 1 ? '' : 's') + ' · up to ' + t.max_guests + ' guest' + (t.max_guests === 1 ? '' : 's') + (t.room_numbers ? ' · ' + esc(t.room_numbers) : '') + '</small>' +
        (t.amenities.length ? '<small>' + esc(t.amenities.join(' · ')) + '</small>' : '') +
        (t.active ? '' : '<span class="rm-pill rm-grey" style="justify-self:start">Hidden from website</span>') +
        (d.can_edit ? '<div class="rm-acts"><button type="button" class="rm-b plain" data-edit="' + t.id + '"><span class="material-symbols-rounded">edit</span>Edit</button>' +
          '<button type="button" class="rm-b off" data-del="' + t.id + '">Delete</button></div>' : '') + '</div></div>';
    }).join('') + '</div>';
  }

  function modal(html) {
    var ov = document.createElement('div');
    ov.className = 'rm-ov';
    ov.innerHTML = '<div class="rm-md" role="dialog" aria-modal="true"><button type="button" class="x" aria-label="Close">✕</button>' + html + '</div>';
    document.body.appendChild(ov);
    ov.addEventListener('click', function (e) { if (e.target === ov || e.target.closest('.x') || e.target.closest('[data-close]')) ov.remove(); });
    return ov;
  }

  function editType(t) {
    t = t || { name: '', price: '', max_guests: 2, room_count: 1, room_numbers: '', description: '', amenities: [], photos: [], active: true };
    var photos = t.photos.slice();
    var ov = modal('<h3>' + (t.id ? 'Edit room type' : 'Add room type') + '</h3>' +
      '<div class="f"><label>Name</label><input id="rmtName" maxlength="100" placeholder="e.g. Deluxe Double" value="' + esc(t.name) + '"></div>' +
      '<div class="g"><div><label>Price per night (' + esc(S.data.currency) + ')</label><input id="rmtPrice" type="number" min="0" step="1" value="' + esc(t.price) + '"></div>' +
      '<div><label>Guests per room</label><input id="rmtGuests" type="number" min="1" max="20" value="' + esc(t.max_guests) + '"></div></div>' +
      '<div class="g"><div><label>How many rooms of this type</label><input id="rmtCount" type="number" min="1" max="500" value="' + esc(t.room_count) + '"></div>' +
      '<div><label>Room numbers (optional)</label><input id="rmtNumbers" placeholder="101, 102, 103" value="' + esc(t.room_numbers) + '"></div></div>' +
      '<div class="f"><label>Description</label><textarea id="rmtDesc" rows="3" maxlength="1000" placeholder="Bed, size, view…">' + esc(t.description) + '</textarea></div>' +
      '<div class="f"><label>Amenities</label><div class="rm-am">' + AMENITIES.concat(t.amenities.filter(function (a) { return AMENITIES.indexOf(a) === -1; })).map(function (a) {
        return '<label><input type="checkbox" value="' + esc(a) + '"' + (t.amenities.indexOf(a) !== -1 ? ' checked' : '') + '>' + esc(a) + '</label>';
      }).join('') + '</div><input id="rmtMore" placeholder="Other amenities, separated by commas" style="margin-top:8px"></div>' +
      '<div class="f"><label>Photos (up to 6, the first is the cover)</label><div class="rm-ph" id="rmtPhotos"></div><input type="file" id="rmtFile" accept="image/jpeg,image/png,image/webp" multiple hidden></div>' +
      '<div class="f"><label style="display:flex;gap:8px;align-items:center;font-weight:500"><input type="checkbox" id="rmtActive" style="width:auto"' + (t.active ? ' checked' : '') + '>Show on the website</label></div>' +
      '<div class="ft"><button type="button" class="rm-b plain" data-close>Cancel</button><button type="button" class="rm-b primary" id="rmtSave">Save</button></div>');
    function drawPhotos() {
      $('rmtPhotos').innerHTML = photos.map(function (p, i) { return '<div><img src="../' + esc(p) + '" alt=""><button type="button" data-rmph="' + i + '" aria-label="Remove photo">✕</button></div>'; }).join('') +
        (photos.length < 6 ? '<div class="add" id="rmtAdd">+ Add<br>photos</div>' : '');
    }
    drawPhotos();
    ov.addEventListener('click', function (e) {
      var rm = e.target.closest('[data-rmph]');
      if (rm) { photos.splice(+rm.dataset.rmph, 1); drawPhotos(); }
      if (e.target.closest('#rmtAdd')) $('rmtFile').click();
    });
    $('rmtFile').addEventListener('change', function () {
      var files = Array.prototype.slice.call(this.files, 0, 6 - photos.length);
      this.value = '';
      files.reduce(function (p, f) {
        return p.then(function () {
          var fd = new FormData(); fd.append('action', 'upload'); fd.append('photo', f);
          return fetch(API, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.success) { photos.push(d.path); drawPhotos(); } else notify(d.message || 'Could not upload the photo', 'error');
          });
        });
      }, Promise.resolve());
    });
    $('rmtSave').addEventListener('click', function () {
      var amen = Array.prototype.map.call(ov.querySelectorAll('.rm-am input:checked'), function (c) { return c.value; })
        .concat($('rmtMore').value.split(',').map(function (s) { return s.trim(); }).filter(Boolean));
      var btn = this; btn.disabled = true;
      post({ action: 'save_type', type: { id: t.id || 0, name: $('rmtName').value, price: $('rmtPrice').value, max_guests: $('rmtGuests').value, room_count: $('rmtCount').value,
        room_numbers: $('rmtNumbers').value, description: $('rmtDesc').value, amenities: amen, photos: photos, active: $('rmtActive').checked ? 1 : 0 } }).then(function (d) {
        btn.disabled = false;
        if (!d.success) { notify(d.message || 'Could not save', 'error'); return; }
        ov.remove(); notify('Room type saved', 'success'); S.tab = 'types'; load();
      }).catch(function () { btn.disabled = false; notify('Network error. Please try again.', 'error'); });
    });
  }

  function addBooking() {
    var d = S.data;
    if (!d.types.length) { notify('Add a room type first', 'error'); editType(); return; }
    var tomorrow = new Date(d.today + 'T00:00:00'); tomorrow.setDate(tomorrow.getDate() + 1);
    var tm = tomorrow.getFullYear() + '-' + String(tomorrow.getMonth() + 1).padStart(2, '0') + '-' + String(tomorrow.getDate()).padStart(2, '0');
    var ov = modal('<h3>Add booking</h3>' +
      '<div class="f"><label>Room type</label><select id="rmbType">' + d.types.map(function (t) { return '<option value="' + t.id + '" data-price="' + t.price + '">' + esc(t.name) + ' · ' + money(t.price) + '/night</option>'; }).join('') + '</select><div class="rm-hint" id="rmbFree"></div></div>' +
      '<div class="g"><div><label>Check-in</label><input id="rmbIn" type="date" value="' + d.today + '"></div><div><label>Check-out</label><input id="rmbOut" type="date" value="' + tm + '"></div></div>' +
      '<div class="g"><div><label>Guests</label><input id="rmbGuests" type="number" min="1" value="2"></div><div><label>Rooms</label><input id="rmbRooms" type="number" min="1" value="1"></div></div>' +
      '<div class="g"><div><label>Guest name</label><input id="rmbName" maxlength="120"></div><div><label>Phone</label><input id="rmbPhone" type="tel" maxlength="15"></div></div>' +
      '<div class="g"><div><label>Price per night</label><input id="rmbPrice" type="number" min="0"></div><div><label>Room number (optional)</label><input id="rmbNumbers" placeholder="101"></div></div>' +
      '<div class="f"><label>Note (optional)</label><input id="rmbNotes" maxlength="500"></div>' +
      '<div class="ft"><button type="button" class="rm-b plain" data-close>Cancel</button><button type="button" class="rm-b primary" id="rmbSave">Save booking</button></div>');
    function free() {
      var o = $('rmbType').selectedOptions[0];
      $('rmbPrice').placeholder = o.dataset.price;
      fetch(API + '?free=1&in=' + $('rmbIn').value + '&out=' + $('rmbOut').value, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
        $('rmbFree').textContent = r.success ? (r.free[o.value] || 0) + ' room(s) of this type free for those nights' : (r.message || '');
      }).catch(function () {});
    }
    ['rmbType', 'rmbIn', 'rmbOut'].forEach(function (id) { $(id).addEventListener('change', free); });
    free();
    $('rmbSave').addEventListener('click', function () {
      var btn = this; btn.disabled = true;
      post({ action: 'add_booking', booking: { room_type_id: $('rmbType').value, in: $('rmbIn').value, out: $('rmbOut').value, guests: $('rmbGuests').value, rooms: $('rmbRooms').value,
        name: $('rmbName').value, phone: $('rmbPhone').value, price: $('rmbPrice').value, room_numbers: $('rmbNumbers').value, notes: $('rmbNotes').value } }).then(function (r) {
        btn.disabled = false;
        if (!r.success) { notify(r.message || 'Could not save', 'error'); return; }
        ov.remove(); notify('Booking added', 'success'); S.tab = 'bookings'; S.filter = 'upcoming'; load();
      }).catch(function () { btn.disabled = false; notify('Network error. Please try again.', 'error'); });
    });
  }

  function setStatus(id, status) {
    var extra = {};
    if (status === 'Checked in') {
      var b = S.data.bookings.filter(function (x) { return x.id === id; })[0];
      var n = window.prompt('Room number(s) for ' + (b ? b.name : 'the guest') + ' (optional)', b && b.room_numbers ? b.room_numbers : '');
      if (n === null) return;
      extra.room_numbers = n;
    }
    if ((status === 'Cancelled' || status === 'Declined') && !window.confirm(status === 'Declined' ? 'Decline this booking request?' : 'Cancel this booking?')) return;
    post(Object.assign({ action: 'status', id: id, status: status }, extra)).then(function (d) {
      if (!d.success) { notify(d.message || 'Could not update', 'error'); return; }
      notify('Booking ' + status.toLowerCase(), 'success'); load();
    });
  }

  function setEnabled(on, fromToggle) {
    return post({ action: 'enable', on: on ? 1 : 0 }).then(function (d) {
      if (!d.success) { notify(d.message || 'Could not save', 'error'); if (fromToggle) fromToggle.checked = !on; return; }
      var li = $('roomsNavItem'); if (li) li.style.display = on ? '' : 'none';
      var tg = $('enableRoomsToggle'); if (tg) tg.checked = on;
      notify(on ? 'Rooms is on. Guests can book on your website.' : 'Rooms is off on your website.', 'success');
      if (S.data) { S.data.enabled = on; if ($('roomsRoot') && $('roomsPage') && $('roomsPage').classList.contains('active')) render(); }
    }).catch(function () { if (fromToggle) fromToggle.checked = !on; notify('Network error. Please try again.', 'error'); });
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest('#roomsPage')) return;
    var el;
    if ((el = e.target.closest('[data-tab]'))) { S.tab = el.dataset.tab; render(); }
    else if ((el = e.target.closest('[data-filter]'))) { S.filter = el.dataset.filter; render(); }
    else if ((el = e.target.closest('[data-status]'))) setStatus(+el.dataset.id, el.dataset.status);
    else if ((el = e.target.closest('[data-paid]'))) post({ action: 'paid', id: +el.dataset.id, paid: +el.dataset.paid }).then(function (d) { if (d.success) load(); else notify(d.message || 'Could not update', 'error'); });
    else if ((el = e.target.closest('[data-edit]'))) editType(S.data.types.filter(function (t) { return t.id === +el.dataset.edit; })[0]);
    else if ((el = e.target.closest('[data-del]'))) {
      if (!window.confirm('Delete this room type? Its photos are removed too.')) return;
      post({ action: 'delete_type', id: +el.dataset.del }).then(function (d) { if (d.success) { notify('Room type deleted', 'success'); load(); } else notify(d.message || 'Could not delete', 'error'); });
    }
    else if (e.target.closest('[data-rm="enable"]')) setEnabled(true);
    else if (e.target.closest('[data-rm="add-type"]') || e.target.closest('#rmAddTypeBtn')) { if (S.data) editType(); }
    else if (e.target.closest('#rmAddBookingBtn')) { if (S.data) addBooking(); }
  });
  document.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'enableRoomsToggle') setEnabled(e.target.checked, e.target);
  });

  window.RGRooms = { load: load };
})();
