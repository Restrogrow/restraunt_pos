// Shared helpers for the Playwright suite.
// DEMO_ADMIN and TEST_RESTAURANT_ID point at a dedicated, isolated test
// fixture (restaurant_id TEST01) seeded by scripts/seed_test_restaurant.sql -
// run `mysql -u root menuwebsite_db < scripts/seed_test_restaurant.sql` once
// before running tests on a fresh DB. This is NOT a real restaurant: the
// schema.sql "admin/admin123" demo account does not exist in the live local
// DB (it was overwritten by real usage), and tests must never run against
// real restaurants/credentials.

const DEMO_ADMIN = { username: 'pw_test_admin', password: 'PwTest#2026' };
const TEST_RESTAURANT_ID = 'TEST01';

/**
 * Logs in as the restaurant owner and waits for the dashboard to load.
 * Grants the "notifications" permission first so the post-login push-notification
 * prompt modal (main/admin/login.php -> showNotificationPrompt) auto-skips itself
 * instead of blocking navigation.
 */
async function loginAsOwner(page, context) {
  await context.grantPermissions(['notifications']);
  await page.goto('main/admin/login.php');
  await page.fill('#loginUsername', DEMO_ADMIN.username);
  await page.fill('#loginPassword', DEMO_ADMIN.password);
  await page.click('#loginBtn');
  await page.waitForURL(/dashboard\.php/, { timeout: 15000 });
  await dismissNewOrderOverlay(page);
}

/**
 * The dashboard polls for new orders and shows a blocking #newOrderOverlay
 * card the moment one arrives (main/assets/js/script.js "[Notif] Polling for
 * new orders"). place-order.spec.js creates a real TEST01 order, so later
 * tests that log in as the owner can otherwise get their clicks intercepted
 * by this overlay. Safe no-op if it isn't showing.
 */
async function dismissNewOrderOverlay(page) {
  const overlay = page.locator('#newOrderOverlay.show');
  if (await overlay.isVisible().catch(() => false)) {
    await page.click('#newOrderOverlay .close-overlay');
    await overlay.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
  }
}

/** Generates a unique-enough 10-digit test phone number for customer signup. */
function uniqueTestPhone() {
  // 9 + last 9 digits of the current timestamp - stays within a plausible
  // Indian mobile number shape without colliding across quick repeat runs.
  return '9' + String(Date.now()).slice(-9);
}

module.exports = { DEMO_ADMIN, TEST_RESTAURANT_ID, loginAsOwner, dismissNewOrderOverlay, uniqueTestPhone };
