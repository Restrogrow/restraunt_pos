/**
 * Order details › Delivery › "Book a delivery partner" (Uber, Rapido, Porter, Borzo, Ola).
 *
 * No partner API: each button copies the drop (customer, phone, address, map pin, cash to collect) and
 * opens the app. Uber's documented link (m.uber.com/ul) also fills pickup and drop by itself; the others
 * only open (Android) or their website, so staff paste the drop there. "Phone QR" shows the same link as a
 * QR so an employee can book from their own phone. Then the driver's name / phone / the app's tracking
 * link are saved (api/delivery_partner.php) and the customer's tracking page shows them; "Send rider page"
 * gives the driver the existing rider page (Picked up → On the way → Delivered) on WhatsApp.
 *
 * script.js puts <div class="rg-book-delivery"> in the Delivery box and calls rgBookDeliveryInit(order).
 */
(function () {
  var APPS = [
    { id: 'uber', t: 'Uber', c: '#000000', pkg: 'com.ubercab', web: 'https://m.uber.com/' },
    { id: 'rapido', t: 'Rapido', c: '#f9c933', ink: '#111', pkg: 'com.rapido.passenger', web: 'https://www.rapido.bike/' },
    { id: 'porter', t: 'Porter', c: '#1b4dd4', pkg: 'com.theporter.android.customerapp', web: 'https://porter.in/' },
    { id: 'borzo', t: 'Borzo', c: '#e5195f', pkg: 'in.wefast', web: 'https://borzodelivery.com/in/' },
    { id: 'ola', t: 'Ola', c: '#1c8c3c', pkg: 'com.olacabs.customer', web: 'https://www.olacabs.com/' }
  ];
  var orders = {};

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function num(v) { var n = parseFloat(v); return isFinite(n) && n !== 0 ? n : null; }
  function isAndroid() { return /Android/i.test(navigator.userAgent || ''); }
  function money(v) { return (window.currencySymbol || '₹') + Math.round(parseFloat(v) || 0); }

  function points(o) {
    return { rLat: num(window.restaurantLat), rLng: num(window.restaurantLng), cLat: num(o.address_lat), cLng: num(o.address_lng) };
  }
  function dropAddress(o) { return [o.customer_address, o.landmark ? 'Landmark: ' + o.landmark : ''].filter(Boolean).join(', '); }
  function pinLink(o) {
    var p = points(o);
    return p.cLat ? 'https://maps.google.com/?q=' + p.cLat + ',' + p.cLng : 'https://maps.google.com/?q=' + encodeURIComponent(o.customer_address || '');
  }
  function cashToCollect(o) {
    return String(o.payment_status || '') !== 'Paid' ? Math.max(0, (parseFloat(o.total) || 0) - (parseFloat(o.wallet_paid) || 0)) : 0;
  }
  // what gets copied before an app opens: paste it into the app's drop / notes
  function dropText(o) {
    var cash = cashToCollect(o);
    return [
      'Order ' + (o.order_number || ''),
      'Pickup: ' + (window.restaurantName || '') + (window.restaurantAddressText ? ', ' + window.restaurantAddressText : ''),
      'Drop: ' + (o.customer_name || 'Customer') + (o.customer_phone ? ' · ' + o.customer_phone : ''),
      dropAddress(o),
      'Map: ' + pinLink(o),
      cash ? 'Cash to collect: ' + money(cash) : 'Paid online — collect nothing'
    ].filter(Boolean).join('\n');
  }

  // Uber's documented universal link: pickup + drop filled in (https://developer.uber.com/docs/deep-linking)
  function uberLink(o) {
    var p = points(o), q = ['action=setPickup'];
    if (p.rLat) q.push('pickup[latitude]=' + p.rLat, 'pickup[longitude]=' + p.rLng, 'pickup[nickname]=' + encodeURIComponent(window.restaurantName || 'Restaurant'));
    else q.push('pickup=my_location');
    if (p.cLat) q.push('dropoff[latitude]=' + p.cLat, 'dropoff[longitude]=' + p.cLng);
    q.push('dropoff[nickname]=' + encodeURIComponent((o.customer_name || 'Customer') + ' ' + (o.order_number || '')));
    if (o.customer_address) q.push('dropoff[formatted_address]=' + encodeURIComponent(dropAddress(o)));
    return 'https://m.uber.com/ul/?' + q.join('&');
  }
  // the others publish no link with addresses: open the Android app (or its website)
  function appLink(app, o) {
    if (app.id === 'uber') return uberLink(o);
    if (isAndroid()) return 'intent://#Intent;package=' + app.pkg + ';S.browser_fallback_url=' + encodeURIComponent(app.web) + ';end';
    return app.web;
  }
  // the QR is scanned by a phone camera, which can't follow intent:// links: Uber gets its link,
  // the others the drop pin so the employee can share it into the app
  function qrLink(app, o) { return app.id === 'uber' ? uberLink(o) : pinLink(o); }

  function copy(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(text).catch(fallbackCopy.bind(null, text));
    fallbackCopy(text);
    return Promise.resolve();
  }
  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    ta.remove();
  }
  function qrSvg(text) {
    if (typeof window.qrcode !== 'function') return '<a href="' + esc(text) + '" target="_blank" rel="noopener">' + esc(text) + '</a>';
    var q = window.qrcode(0, 'M'); q.addData(text); q.make();
    return q.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
  }

  function box(o) { return document.querySelector('.rg-book-delivery[data-order="' + o.id + '"]'); }

  function render(o, saved, partners) {
    var el = box(o);
    if (!el) return;
    var p = points(o);
    var btn = 'display:inline-flex;align-items:center;gap:6px;border:0;border-radius:8px;padding:7px 12px;font:600 0.82rem Poppins,sans-serif;cursor:pointer;';
    var field = 'width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:7px;font:0.85rem Poppins,sans-serif;box-sizing:border-box;';
    var opts = Object.keys(partners).map(function (k) { return '<option value="' + k + '"' + (saved.partner === k ? ' selected' : '') + '>' + esc(partners[k]) + '</option>'; }).join('');
    el.innerHTML =
      '<div style="margin-top:0.75rem;padding:0.75rem;background:#fff;border:1px solid #bbf7d0;border-radius:8px;">' +
        '<strong style="font-size:0.9rem;color:#14532d;">Book a delivery partner</strong>' +
        '<p style="margin:4px 0 8px;font-size:0.78rem;color:#4b5563;">Tap an app: the drop details are copied, then the app opens. ' +
          (p.rLat && p.cLat ? '<b>Uber</b> opens with pickup and drop already filled in.' : '<b>Uber</b> fills the drop' + (p.rLat ? '' : ' (set your location in Settings › Location for the pickup)') + '.') +
          ' In the others, paste the drop.</p>' +
        '<div style="display:flex;flex-wrap:wrap;gap:6px;">' + APPS.map(function (a) {
          return '<button type="button" data-bd-open="' + a.id + '" style="' + btn + 'background:' + a.c + ';color:' + (a.ink || '#fff') + ';">' + esc(a.t) + '</button>';
        }).join('') +
          '<button type="button" data-bd-copy style="' + btn + 'background:#f1f5f9;color:#111827;"><span class="material-symbols-rounded" style="font-size:16px;">content_copy</span>Copy drop</button>' +
        '</div>' +
        '<div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:0.78rem;color:#4b5563;">Phone QR:' + APPS.map(function (a) {
          return '<button type="button" data-bd-qr="' + a.id + '" style="border:1px solid #d1d5db;background:#fff;border-radius:6px;padding:3px 8px;font:500 0.75rem Poppins,sans-serif;cursor:pointer;">' + esc(a.t) + '</button>';
        }).join('') + '</div>' +
        '<div data-bd-qrbox style="display:none;margin-top:8px;text-align:center;"></div>' +
        '<div style="margin-top:10px;padding-top:10px;border-top:1px dashed #d1d5db;">' +
          '<div style="font-size:0.82rem;font-weight:600;color:#14532d;margin-bottom:6px;">After booking: who is delivering?</div>' +
          (saved.own_rider ? '<p style="margin:0;font-size:0.8rem;color:#92400e;">One of your own riders has this order.</p>' :
          '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">' +
            '<select data-bd-f="partner" style="' + field + '"><option value="">— App —</option>' + opts + '</select>' +
            '<input data-bd-f="rider_name" placeholder="Driver name" value="' + esc(saved.partner ? saved.rider_name : '') + '" style="' + field + '">' +
            '<input data-bd-f="rider_phone" placeholder="Driver phone" inputmode="tel" value="' + esc(saved.partner ? saved.rider_phone : '') + '" style="' + field + '">' +
            '<input data-bd-f="track_url" placeholder="Tracking link from the app (optional)" value="' + esc(saved.track_url) + '" style="' + field + '">' +
          '</div>' +
          '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;">' +
            '<button type="button" data-bd-save style="' + btn + 'background:#16a34a;color:#fff;">Save</button>' +
            '<button type="button" data-bd-wa style="' + btn + 'background:#25d366;color:#fff;" title="The driver gets the rider page to tap Picked up → On the way → Delivered">Send rider page on WhatsApp</button>' +
            (saved.partner ? '<button type="button" data-bd-clear style="' + btn + 'background:#fff;color:#b91c1c;border:1px solid #fecaca;">Remove</button>' : '') +
          '</div>') +
          '<p data-bd-msg style="margin:6px 0 0;font-size:0.78rem;color:#166534;">' + (saved.partner ? 'Sent with ' + esc(partners[saved.partner] || saved.partner) + '. The customer sees the driver on their tracking page.' : '') + '</p>' +
        '</div>' +
      '</div>';
  }

  function msg(el, text, bad) { var m = el.querySelector('[data-bd-msg]'); if (m) { m.textContent = text; m.style.color = bad ? '#b91c1c' : '#166534'; } }
  function val(el, k) { var f = el.querySelector('[data-bd-f="' + k + '"]'); return f ? f.value.trim() : ''; }

  function save(o, el, clear) {
    var body = clear ? { order_id: o.id, partner: '' } : { order_id: o.id, partner: val(el, 'partner'), rider_name: val(el, 'rider_name'), rider_phone: val(el, 'rider_phone'), track_url: val(el, 'track_url') };
    if (!clear && !body.partner) { msg(el, 'Pick which app is delivering.', true); return Promise.resolve(false); }
    return fetch('../api/delivery_partner.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) { msg(el, d.message || 'Could not save', true); return false; }
        load(o);
        return true;
      })
      .catch(function () { msg(el, 'Could not save. Check the internet.', true); return false; });
  }

  // the existing rider page (api/generate_delivery_qr.php) on the driver's WhatsApp
  function sendRiderPage(o, el) {
    var phone = val(el, 'rider_phone').replace(/[^\d]/g, '');
    if (!phone) { msg(el, "Type the driver's phone first.", true); return; }
    if (phone.length === 10) phone = '91' + phone;
    var win = window.open('', '_blank');
    save(o, el).then(function (ok) {
      if (!ok) { if (win) win.close(); return; }
      return fetch('../api/generate_delivery_qr.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, credentials: 'same-origin', body: 'order_id=' + o.id })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.success) { if (win) win.close(); msg(el, d.message || 'Could not make the rider page', true); return; }
          var text = 'Order ' + (o.order_number || '') + ' — ' + (window.restaurantName || '') + '\n' + dropText(o).split('\n').slice(2).join('\n') +
            '\n\nTap here at pickup and when delivered:\n' + d.qr_url;
          var url = 'https://wa.me/' + phone + '?text=' + encodeURIComponent(text);
          if (win) win.location = url; else window.open(url, '_blank');
        });
    });
  }

  function wire(o) {
    var el = box(o);
    if (!el || el.dataset.wired) return;
    el.dataset.wired = '1';
    el.addEventListener('click', function (ev) {
      var b = ev.target.closest('button');
      if (!b) return;
      var app = APPS.filter(function (a) { return a.id === (b.getAttribute('data-bd-open') || b.getAttribute('data-bd-qr')); })[0];
      if (b.hasAttribute('data-bd-open') && app) {
        var link = appLink(app, o);
        copy(dropText(o)).then(function () {
          msg(el, 'Drop details copied. ' + (app.id === 'uber' ? 'Check pickup and drop in Uber.' : 'Paste them in ' + app.t + '.'));
          var sel = el.querySelector('[data-bd-f="partner"]'); if (sel && !sel.value) sel.value = app.id;
          if (/^intent:/.test(link)) window.location.href = link; else window.open(link, '_blank', 'noopener');
        });
      } else if (b.hasAttribute('data-bd-copy')) {
        copy(dropText(o)).then(function () { msg(el, 'Drop details copied.'); });
      } else if (b.hasAttribute('data-bd-qr') && app) {
        var qb = el.querySelector('[data-bd-qrbox]');
        if (qb.style.display !== 'none' && qb.dataset.app === app.id) { qb.style.display = 'none'; return; }
        qb.dataset.app = app.id;
        qb.style.display = '';
        qb.innerHTML = '<div style="width:180px;margin:0 auto;">' + qrSvg(qrLink(app, o)) + '</div>' +
          '<p style="margin:4px 0 0;font-size:0.75rem;color:#4b5563;">' + (app.id === 'uber' ? 'Scan with the phone camera: Uber opens with pickup and drop.' : 'Scan to get the drop pin, then share it into ' + esc(app.t) + '.') + '</p>';
      } else if (b.hasAttribute('data-bd-save')) {
        save(o, el);
      } else if (b.hasAttribute('data-bd-clear')) {
        save(o, el, true);
      } else if (b.hasAttribute('data-bd-wa')) {
        sendRiderPage(o, el);
      }
    });
  }

  function load(o) {
    return fetch('../api/delivery_partner.php?order_id=' + encodeURIComponent(o.id), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) { var el = box(o); if (el) el.innerHTML = ''; return; }   // no permission: show nothing
        render(o, d.delivery || {}, d.partners || {});
        wire(o);
      })
      .catch(function () {});
  }

  window.rgBookDeliveryInit = function (order) {
    if (!order || order.order_type !== 'Delivery' || ['Cancelled', 'Rejected'].indexOf(order.order_status) > -1) return;
    orders[order.id] = order;
    load(order);
  };
})();
