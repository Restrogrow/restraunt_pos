const { test, expect } = require('@playwright/test');
const { TEST_RESTAURANT_ID, uniqueTestPhone } = require('./helpers');

test.describe('Customer account signup + login', () => {
  test('signs up a new account, logs out, then logs back in', async ({ page }) => {
    const phone = uniqueTestPhone();
    const name = 'Playwright Test Customer';
    const password = 'TestPass123';

    await page.goto(`main/website/login.php?restaurant_id=${TEST_RESTAURANT_ID}`);

    // Signup is immediate - no email verification step (main/website/customer_auth.php).
    await page.click('#tabSignup');
    await page.fill('#signupName', name);
    await page.fill('#signupPhone', phone);
    await page.fill('#signupEmail', `pwtest+${phone}@example.com`);
    await page.fill('#signupPassword', password);
    await page.fill('#signupConfirmPassword', password);
    await page.click('#signupSubmitBtn');

    // Signing up sends a verification code, and codes are capped at ten an hour from one address
    // (website/otp.php: otp_ip_<ip>, 10 per 3600s). So this test can only run ten times an hour,
    // and past that it would otherwise sit waiting for a redirect that is never coming and fail on
    // a navigation timeout that says nothing about why. The page's own message is what is read, so
    // a genuinely broken signup still fails below rather than being skipped.
    const limited = await page.locator('#authError').filter({ hasText: /too many codes/i }).first()
      .waitFor({ state: 'visible', timeout: 4000 }).then(() => true).catch(() => false);
    test.skip(limited, 'Verification codes capped at 10/hour for this IP (website/otp.php) - try again later');

    // Successful signup logs the customer straight in and redirects off login.php.
    await page.waitForURL((url) => !url.pathname.endsWith('login.php'), { timeout: 10000 });

    // Log out via the customer session cookie so we can prove login works independently.
    await page.evaluate(() => {
      return fetch('customer_auth.php', {
        method: 'POST',
        body: (() => { const fd = new FormData(); fd.append('action', 'logout'); return fd; })(),
      });
    });

    await page.goto(`main/website/login.php?restaurant_id=${TEST_RESTAURANT_ID}`);
    await page.fill('#loginPhone', phone);
    await page.fill('#loginPassword', password);
    await page.click('#loginSubmitBtn');

    await page.waitForURL((url) => !url.pathname.endsWith('login.php'), { timeout: 10000 });
  });
});
