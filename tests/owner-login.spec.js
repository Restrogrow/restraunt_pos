const { test, expect } = require('@playwright/test');
const { DEMO_ADMIN, loginAsOwner } = require('./helpers');

test.describe('Restaurant owner login', () => {
  test('logs in with valid credentials and reaches the dashboard', async ({ page, context }) => {
    await loginAsOwner(page, context);
    await expect(page).toHaveURL(/dashboard\.php/);
    // Sidebar is a reliable signed-in marker regardless of which tab loads first.
    await expect(page.locator('.nav-link[data-page="websiteThemePage"]')).toBeVisible();
  });

  test('rejects an incorrect password', async ({ page, context }) => {
    await context.grantPermissions(['notifications']);
    await page.goto('main/admin/login.php');
    await page.fill('#loginUsername', DEMO_ADMIN.username);
    await page.fill('#loginPassword', 'definitely-wrong-password');
    await page.click('#loginBtn');

    await expect(page.locator('.message')).toContainText(/incorrect|invalid/i, { timeout: 10000 });
    await expect(page).toHaveURL(/login\.php/);
  });
});
