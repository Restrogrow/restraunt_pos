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
    // Wait for the page to have taken its "nothing changed yet" snapshot (script.js
    // setThemeBaseline). Typing before that happens puts the new value into the baseline itself, so
    // the form never counts as changed and Save stays greyed out reading "Saved". wt-clean is only
    // on the button once a baseline exists, which makes it the signal to wait for.
    await expect(page.locator('#saveWebsiteThemeBtn')).toHaveClass(/wt-clean/, { timeout: 15000 });

    const originalHex = (await page.locator('#primaryRedHex').inputValue()).toLowerCase();
    // Never the colour it is already set to. Save is disabled while nothing has changed
    // ("Nothing to save"), so a test that sets the current value would wait for a button that is
    // never going to be clickable - and the restore in `finally` would hit the same wall and leave
    // the colour where it was, so one failed run used to poison every run after it.
    const testColor = originalHex === '#123abc' ? '#654321' : '#123ABC';
    const saveBtn = page.locator('#saveWebsiteThemeBtn');

    try {
      await page.fill('#primaryRedHex', testColor);
      // The hex field's own input handler syncs it into the <input type=color>
      // and pushes a live update into the preview iframe - blur to fire it reliably.
      await page.locator('#primaryRedHex').blur();
      await expect(page.locator('#primaryRed')).toHaveJSProperty('value', testColor.toLowerCase());
      // the page knows there is something to save - checked on its own so a failure here reads as
      // "Save never came alive" instead of a click that hangs until the test times out
      await expect(saveBtn).toBeEnabled({ timeout: 5000 });

      await dismissNewOrderOverlay(page);
      await saveBtn.click();
      await expect(page.locator('.rgn-card').filter({ hasText: /saved/i }).first()).toBeVisible({ timeout: 10000 });

      // Confirm the API actually persisted it (not just a client-side echo).
      const res = await request.get(
        `main/website/theme_api.php?action=get&restaurant_id=${TEST_RESTAURANT_ID}`
      );
      const body = await res.json();
      expect(body.settings.primary_red.toUpperCase()).toBe(testColor.toUpperCase());

      // Confirm the public site actually renders it server-side (the whole point
      // of moving color rendering into header.php instead of JS-only).
      const siteRes = await request.get(`main/website/index.php?restaurant_id=${TEST_RESTAURANT_ID}`);
      const html = await siteRes.text();
      expect(html.toLowerCase()).toContain(`--primary-red: ${testColor.toLowerCase()}`);
    } finally {
      // Restore the original color so this test is repeatable and doesn't
      // leave the demo restaurant's theme visibly changed.
      await page.fill('#primaryRedHex', originalHex).catch(() => {});
      await page.locator('#primaryRedHex').blur().catch(() => {});
      await dismissNewOrderOverlay(page);
      // already back where it started - Save is disabled and there is nothing left to do
      if (await saveBtn.isEnabled().catch(() => false)) {
        await saveBtn.click().catch(() => {});
        await expect(page.locator('.rgn-card').filter({ hasText: /saved/i }).first())
          .toBeVisible({ timeout: 10000 }).catch(() => {});
      }
    }
  });
});
