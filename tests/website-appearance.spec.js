const { test, expect } = require('@playwright/test');
const { loginAsOwner, dismissNewOrderOverlay, TEST_RESTAURANT_ID } = require('./helpers');

// Regression test for the Website Appearance rework: colors used to be almost
// entirely disconnected from the customer site (most buttons were hardcoded),
// and Save used to be the only way to see a change. This proves both the save
// round-trip AND that the saved value is actually the one the public site reads.
test.describe('Website Appearance', () => {
  test('saving a new Primary Color persists and is served to the public site', async ({ page, context, request }) => {
    await loginAsOwner(page, context);

    await page.click('.nav-link[data-page="websiteThemePage"]');
    await expect(page.locator('#primaryRedHex')).toBeVisible({ timeout: 10000 });

    const originalHex = await page.locator('#primaryRedHex').inputValue();
    const testColor = '#123ABC';

    try {
      await page.fill('#primaryRedHex', testColor);
      // The hex field's own input handler syncs it into the <input type=color>
      // and pushes a live update into the preview iframe - blur to fire it reliably.
      await page.locator('#primaryRedHex').blur();
      await expect(page.locator('#primaryRed')).toHaveJSProperty('value', testColor.toLowerCase());

      await dismissNewOrderOverlay(page);
      await page.click('#saveWebsiteThemeBtn');
      await expect(page.locator('.notification.success')).toContainText(/saved/i, { timeout: 10000 });

      // Confirm the API actually persisted it (not just a client-side echo).
      const res = await request.get(
        `main/website/theme_api.php?action=get&restaurant_id=${TEST_RESTAURANT_ID}`
      );
      const body = await res.json();
      expect(body.settings.primary_red.toUpperCase()).toBe(testColor);

      // Confirm the public site actually renders it server-side (the whole point
      // of moving color rendering into header.php instead of JS-only).
      const siteRes = await request.get(`main/website/index.php?restaurant_id=${TEST_RESTAURANT_ID}`);
      const html = await siteRes.text();
      expect(html.toLowerCase()).toContain(`--primary-red: ${testColor.toLowerCase()}`);
    } finally {
      // Restore the original color so this test is repeatable and doesn't
      // leave the demo restaurant's theme visibly changed.
      await page.fill('#primaryRedHex', originalHex);
      await page.locator('#primaryRedHex').blur();
      await dismissNewOrderOverlay(page);
      await page.click('#saveWebsiteThemeBtn');
      await expect(page.locator('.notification.success')).toContainText(/saved/i, { timeout: 10000 });
    }
  });
});
