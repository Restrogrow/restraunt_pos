const { test, expect } = require('@playwright/test');
const { loginAsOwner, dismissNewOrderOverlay } = require('./helpers');

// Settings › Restaurant profile › Starting rating (config/restaurant_rating.php): the rating a
// restaurant's website shows until three of its own customers have rated an order.
//
// The round-trip is what this guards. The settings form saves through one UPDATE with thirty-odd
// columns in it, and that statement falls back to a shorter column list when any one of them is
// missing - which is how the second phone number once silently saved as NULL. The two rating columns
// are written in their own statement for that reason, and get_session reads them in their own SELECT
// rather than being added to the big one that degrades. Both of those are easy to undo by accident.
test.describe('Starting rating', () => {
  test('saves from Settings, comes back on reload, and is what the website would show', async ({ page, context }) => {
    await loginAsOwner(page, context);

    await page.click('.nav-link[data-page="settingsPage"]');
    // Restaurant profile is the pane that opens first, so the fields are already on screen
    await expect(page.locator('#manualRating')).toBeVisible({ timeout: 15000 });
    // TEST01 has no customer feedback, so the owner's figure is still the one in use and the
    // fields must be editable - they are disabled once real ratings take over
    await expect(page.locator('#manualRating')).toBeEnabled();

    try {
      await page.fill('#manualRating', '4.4');
      await page.fill('#manualRatingCount', '212');

      await dismissNewOrderOverlay(page);
      await page.click('#stSave');
      await expect(page.locator('.rgn-card').filter({ hasText: /updated successfully/i }).first()).toBeVisible({ timeout: 10000 });

      // Read it back through the session endpoint the settings form itself loads from, which also
      // reports which of the two ratings the public site is using.
      const body = await (await page.request.get('main/admin/get_session.php')).json();
      const user = body.data || body;
      expect(Number(user.manual_rating)).toBe(4.4);
      expect(Number(user.manual_rating_count)).toBe(212);
      expect(user.public_rating).toMatchObject({ avg: '4.4', n: 212, source: 'owner' });

      // and that it survives a reload rather than only living in the open page
      await page.reload();
      await dismissNewOrderOverlay(page);
      await page.click('.nav-link[data-page="settingsPage"]');
      await expect(page.locator('#manualRating')).toHaveValue('4.4', { timeout: 15000 });
      await expect(page.locator('#manualRatingCount')).toHaveValue('212');
    } finally {
      // Leave it off, the way the fixture starts, so the website test's restaurant shows no rating.
      await page.fill('#manualRating', '').catch(() => {});
      await page.fill('#manualRatingCount', '').catch(() => {});
      await dismissNewOrderOverlay(page);
      await page.click('#stSave').catch(() => {});
      await expect(page.locator('.rgn-card').filter({ hasText: /updated successfully/i }).first()).toBeVisible({ timeout: 10000 }).catch(() => {});
    }
  });

  test('an empty rating shows nothing at all, rather than a zero', async ({ page, context }) => {
    await loginAsOwner(page, context);
    const body = await (await page.request.get('main/admin/get_session.php')).json();
    const user = body.data || body;
    expect(user.manual_rating).toBeFalsy();
    expect(user.public_rating).toBeFalsy();
  });
});
