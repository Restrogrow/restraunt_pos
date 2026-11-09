const { test, expect } = require('@playwright/test');
const { loginAsOwner } = require('./helpers');

// Pay by UPI (config/upi_link.php, assets/js/upi-pay.js): the restaurant saves the UPI ID behind a
// payment method, and the counter then shows a QR and a link with the bill amount already in them.
//
// What this guards is the part that handles money. A UPI ID that is quietly altered on its way to
// the database - a stray space removed, a character dropped - sends a customer's payment to someone
// else's account, and nothing in the app would ever notice. So the round-trip is checked exactly,
// and a malformed ID has to be refused rather than cleaned up into something that might be real.
test.describe('Pay by UPI', () => {
  const UPI = 'pwtest.counter@okhdfcbank';

  test('a UPI ID saves exactly as typed and comes back on the method', async ({ page, context }) => {
    await loginAsOwner(page, context);   // the dashboard is where assets/js/upi-pay.js is loaded

    const methods = await (await page.request.get('main/api/get_payment_methods.php')).json();
    expect(methods.success).toBe(true);
    const upiMethod = (methods.data || []).find((m) => /upi/i.test(m.method_name));
    test.skip(!upiMethod, 'this restaurant has no UPI method (Nepal gets eSewa / Khalti instead)');

    const form = new URLSearchParams({
      action: 'update', id: String(upiMethod.id), method_name: upiMethod.method_name,
      emoji: upiMethod.emoji || '', is_active: '1', upi_id: UPI,
    });
    const saved = await (await page.request.post('main/api/save_payment_method.php', {
      data: form.toString(),
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    })).json();
    expect(saved.success).toBe(true);

    try {
      const after = await (await page.request.get('main/api/get_payment_methods.php')).json();
      const m = (after.data || []).find((x) => x.id === upiMethod.id);
      expect(m.upi_id).toBe(UPI);          // exactly, not merely similar
      expect(m.payee_name).toBeTruthy();   // the name the customer sees in their UPI app

      // and the link the counter would build from it
      const link = await page.evaluate((mm) => window.rgUpiPay.link({
        upi: mm.upi_id, name: mm.payee_name, amount: 450.5, note: 'Table 5', ref: 'ORD-1',
      }), m);
      const q = new URLSearchParams(link.replace(/^upi:\/\/pay\?/, ''));
      expect(link.startsWith('upi://pay?')).toBe(true);
      expect(q.get('pa')).toBe(UPI);
      expect(q.get('am')).toBe('450.50');
      expect(q.get('cu')).toBe('INR');
      expect(q.get('tn')).toBe('Table 5');
      expect(q.get('tr')).toBe('ORD1');    // letters and digits only
    } finally {
      await page.request.post('main/api/save_payment_method.php', {
        data: new URLSearchParams({
          action: 'update', id: String(upiMethod.id), method_name: upiMethod.method_name,
          emoji: upiMethod.emoji || '', is_active: String(upiMethod.is_active), upi_id: '',
        }).toString(),
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      }).catch(() => {});
    }
  });

  test('a UPI ID that is not one is refused, not tidied up', async ({ page, context }) => {
    await loginAsOwner(page, context);

    const methods = await (await page.request.get('main/api/get_payment_methods.php')).json();
    const m = (methods.data || [])[0];
    test.skip(!m, 'no payment methods to test against');

    // "has space@ybl" is the one that matters: stripping the space would make it a different,
    // possibly real, UPI ID. The server must refuse it instead of storing the stripped version.
    for (const bad of ['has space@ybl', 'nobank', '@ybl', 'a@b']) {
      const res = await page.request.post('main/api/save_payment_method.php', {
        data: new URLSearchParams({
          action: 'update', id: String(m.id), method_name: m.method_name,
          emoji: m.emoji || '', is_active: String(m.is_active), upi_id: bad,
        }).toString(),
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      });
      const body = await res.json();
      expect(body.success, `"${bad}" should have been refused`).toBe(false);
    }

    // nothing was stored by any of those attempts
    const after = await (await page.request.get('main/api/get_payment_methods.php')).json();
    expect((after.data || []).find((x) => x.id === m.id).upi_id).toBe('');
  });
});
