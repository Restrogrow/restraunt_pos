/**
 * "Pay by UPI" for the counter: a QR the customer scans, and a link that opens their UPI app with
 * the bill amount already in it. Mirrors main/config/upi_link.php — keep the two in step.
 *
 *   rgUpiPay.show({ upi: 'spicegarden@okhdfcbank', name: 'Spice Garden',
 *                   amount: 450, note: 'Order ORD-20261109-0042', ref: 'ORD202611090042' });
 *
 * Why it shows the QR, the link and the UPI ID all three:
 *
 *  - The **QR** is the one that always works. The customer scans it with their own phone, which is
 *    the phone that has the UPI app — the counter's screen usually does not.
 *  - The **link** is for when this is already the customer's phone (a waiter's handset, or the
 *    customer tapping it themselves). On a desktop browser `upi://` does nothing at all, so the
 *    button says where it goes rather than pretending.
 *  - The **UPI ID as text** is the fallback for a phone that will not scan and will not follow the
 *    link. It can be copied.
 *
 * Nothing here tells the till that money arrived — UPI has no callback to us. The cashier still
 * marks the bill paid, which is why the reference is in the link and on screen: it reaches the bank
 * statement, and that is what makes the two sides matchable later.
 */
(function () {
  'use strict';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /**
   * A UPI ID, cleaned up, or '' if it is not one. Same rule as rgUpiId() in PHP.
   *
   * Trimmed, never rewritten in the middle: stripping the spaces out of "has space@ybl" would make
   * it a different, possibly real, UPI ID and send money there without a word.
   */
  function cleanId(raw) {
    var v = String(raw == null ? '' : raw).trim();
    return /^[A-Za-z0-9][A-Za-z0-9._-]{0,63}@[A-Za-z][A-Za-z0-9.-]{1,32}$/.test(v) ? v.toLowerCase() : '';
  }

  /** Letters and digits only, 35 at most — all `tr` reliably carries. */
  function cleanRef(raw) {
    return String(raw == null ? '' : raw).replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 35);
  }

  function buildLink(o) {
    var upi = cleanId(o && o.upi);
    if (!upi) return '';
    var q = ['pa=' + encodeURIComponent(upi)];
    var name = String((o && o.name) || '').trim();
    if (name) q.push('pn=' + encodeURIComponent(name.slice(0, 50)));
    var amount = Math.round((parseFloat(o && o.amount) || 0) * 100) / 100;
    if (amount > 0) q.push('am=' + amount.toFixed(2));
    q.push('cu=' + encodeURIComponent(String((o && o.currency) || 'INR').toUpperCase()));
    var note = String((o && o.note) || '').trim();
    if (note) q.push('tn=' + encodeURIComponent(note.slice(0, 50)));
    var ref = cleanRef(o && o.ref);
    if (ref) q.push('tr=' + ref);
    return 'upi://pay?' + q.join('&');
  }

  function qrSvg(text) {
    if (typeof window.qrcode !== 'function') return '';
    try {
      var q = window.qrcode(0, 'M');
      q.addData(text);
      q.make();
      return q.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
    } catch (e) {
      return '';
    }
  }

  /**
   * Shows the dialog. Returns a promise that resolves when it is closed — true if the cashier
   * pressed "Payment received", false if they just closed it, so the caller can decide whether to
   * mark the bill paid. It never decides that itself: nothing here knows whether money arrived.
   */
  function show(o) {
    o = o || {};
    var link = buildLink(o);
    var upi = cleanId(o.upi);
    var currency = o.currency === 'INR' || !o.currency ? '₹' : '';
    var amount = parseFloat(o.amount) || 0;
    var ref = cleanRef(o.ref);

    return new Promise(function (resolve) {
      var old = document.getElementById('rgUpiPayOverlay');
      if (old) old.remove();

      var ov = document.createElement('div');
      ov.id = 'rgUpiPayOverlay';
      ov.className = 'rg-upi-ov';

      if (!upi) {
        // No UPI ID set for this method: say what to do about it rather than showing an empty box.
        ov.innerHTML =
          '<div class="rg-upi-card" role="dialog" aria-label="Pay by UPI">' +
            '<div class="rg-upi-h"><b>No UPI ID yet</b><button type="button" class="rg-upi-x" aria-label="Close">&times;</button></div>' +
            '<p class="rg-upi-note">Add this method’s UPI ID in <b>Settings › Payments</b> and the counter will show a QR code and a pay link with the bill amount already filled in.</p>' +
            '<div class="rg-upi-actions"><button type="button" class="rg-upi-btn rg-upi-ghost rg-upi-close">Close</button></div>' +
          '</div>';
      } else {
        var svg = qrSvg(link);
        ov.innerHTML =
          '<div class="rg-upi-card" role="dialog" aria-label="Pay by UPI">' +
            '<div class="rg-upi-h">' +
              '<b>Pay by UPI' + (amount > 0 ? ' · ' + currency + amount.toFixed(2) : '') + '</b>' +
              '<button type="button" class="rg-upi-x" aria-label="Close">&times;</button>' +
            '</div>' +
            '<div class="rg-upi-qr">' + (svg || '<span class="rg-upi-note">QR code could not be drawn — use the UPI ID below.</span>') + '</div>' +
            '<p class="rg-upi-note">Ask the customer to scan this with GPay, PhonePe, Paytm or any UPI app. The amount is already in it.</p>' +
            '<div class="rg-upi-id">' +
              '<span>' + esc(upi) + '</span>' +
              '<button type="button" class="rg-upi-copy" data-copy="' + esc(upi) + '">Copy</button>' +
            '</div>' +
            (ref ? '<p class="rg-upi-note">Reference <b>' + esc(ref) + '</b> — it shows up in your bank statement.</p>' : '') +
            '<div class="rg-upi-actions">' +
              '<a class="rg-upi-btn rg-upi-ghost" href="' + esc(link) + '">Open a UPI app on this device</a>' +
              '<button type="button" class="rg-upi-btn rg-upi-done">Payment received</button>' +
            '</div>' +
            '<p class="rg-upi-note rg-upi-small">UPI does not tell us when a payment lands, so check your own UPI app or bank message before pressing <b>Payment received</b>.</p>' +
          '</div>';
      }

      document.body.appendChild(ov);

      var done = false;
      function finish(paid) {
        if (done) return;
        done = true;
        document.removeEventListener('keydown', onKey);
        ov.remove();
        resolve(!!paid);
      }
      function onKey(e) { if (e.key === 'Escape') finish(false); }
      document.addEventListener('keydown', onKey);

      ov.addEventListener('click', function (e) {
        if (e.target === ov || e.target.closest('.rg-upi-x, .rg-upi-close')) return finish(false);
        if (e.target.closest('.rg-upi-done')) return finish(true);
        var copy = e.target.closest('.rg-upi-copy');
        if (copy) {
          var val = copy.getAttribute('data-copy') || '';
          var say = function () { copy.textContent = 'Copied'; setTimeout(function () { copy.textContent = 'Copy'; }, 1500); };
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(val).then(say, function () {});
          } else {
            var t = document.createElement('textarea');
            t.value = val; t.style.position = 'fixed'; t.style.opacity = '0';
            document.body.appendChild(t); t.select();
            try { document.execCommand('copy'); say(); } catch (err) {}
            t.remove();
          }
        }
      });
    });
  }

  window.rgUpiPay = { show: show, link: buildLink, cleanId: cleanId, cleanRef: cleanRef, qrSvg: qrSvg };
})();
