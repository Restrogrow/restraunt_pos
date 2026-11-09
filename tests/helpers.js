// Shared helpers for the Playwright suite.
// DEMO_ADMIN and TEST_RESTAURANT_ID point at a dedicated, isolated test
// fixture (restaurant_id TEST01) seeded by scripts/seed_test_restaurant.sql -
// run `mysql -u root menuwebsite_db < scripts/seed_test_restaurant.sql` once
// before running tests on a fresh DB. This is NOT a real restaurant: the
// schema.sql "admin/admin123" demo account does not exist in the live local
// DB (it was overwritten by real usage), and tests must never run against
// real restaurants/credentials.

const fs = require('fs');
const path = require('path');

const DEMO_ADMIN = { username: 'pw_test_admin', password: 'PwTest#2026' };
const TEST_RESTAURANT_ID = 'TEST01';

// Where the owner's signed-in cookies are kept between tests. See loginAsOwner.
const OWNER_STATE = path.join(__dirname, '.auth', 'owner.json');

/**
 * Puts the test in the restaurant owner's dashboard, signed in.
 *
 * It signs in for real once and then reuses the cookies, because the auth endpoints share an
 * IP-based rate limit of five logins a minute. Every spec calling this used to mean a fresh login,
 * and the suite quietly grew past five: the sixth login was refused, the test sat waiting for a
 * dashboard that was never coming, and it failed on a timeout a long way from the real cause. One
 * login for the whole suite also takes a good minute off it.
 *
 * A saved session that has since expired just falls through to signing in again, so there is no
 * state to clear by hand. Delete tests/.auth to force a fresh login.
 *
 * Grants the "notifications" permission first so the post-login push-notification prompt modal
 * (main/admin/login.php -> showNotificationPrompt) auto-skips itself instead of blocking navigation.
 */
async function loginAsOwner(page, context) {
  await context.grantPermissions(['notifications']);

  if (fs.existsSync(OWNER_STATE)) {
    try {
      const saved = JSON.parse(fs.readFileSync(OWNER_STATE, 'utf8'));
      await context.addCookies(saved.cookies || []);
      await page.goto('main/views/dashboard.php');
      if (/dashboard\.php/.test(page.url())) {
        await dismissTour(page);
        await dismissNewOrderOverlay(page);
        return;
      }
    } catch (e) {
      // unreadable or no longer valid - sign in below
    }
  }

  await page.goto('main/admin/login.php');
  await page.fill('#loginUsername', DEMO_ADMIN.username);
  await page.fill('#loginPassword', DEMO_ADMIN.password);
  await page.click('#loginBtn');
  await page.waitForURL(/dashboard\.php/, { timeout: 15000 });

  try {
    fs.mkdirSync(path.dirname(OWNER_STATE), { recursive: true });
    fs.writeFileSync(OWNER_STATE, JSON.stringify({ cookies: await context.cookies() }));
  } catch (e) {
    // worth carrying on without the saving; the next test just logs in again
  }

  await dismissTour(page);
  await dismissNewOrderOverlay(page);
}

/**
 * The first-run onboarding tour (assets/js/onboarding.js) covers the screen with .obt-ov and
 * swallows every click behind it, so a test that signs in as a restaurant which has not finished
 * setting up — which the test fixture never will — cannot reach the sidebar at all. Pressing Skip
 * records it against the restaurant, so it does not come back on the next test either. Safe no-op
 * when the tour isn't showing.
 */
async function dismissTour(page) {
  const skip = page.locator('[data-obt-skip]').first();
  // it is drawn a moment after the dashboard loads, not with it, so wait for it rather than
  // looking once and finding nothing
  await skip.waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
  for (let i = 0; i < 4; i++) {
    if (!(await skip.isVisible().catch(() => false))) break;
    await skip.click({ timeout: 3000, force: true }).catch(() => {});
    await page.waitForTimeout(500);
  }
  // and make sure nothing of it is left lying over the page
  await page.locator('.obt-ov').first().waitFor({ state: 'detached', timeout: 4000 }).catch(() => {});
  await page.evaluate(() => document.querySelectorAll('.obt-ov, .obg').forEach(e => e.remove())).catch(() => {});
}

/**
 * The dashboard polls for new orders and shows a blocking #newOrderOverlay card the moment one
 * arrives (main/assets/js/script.js "[Notif] Polling for new orders"). place-order.spec.js creates
 * a real TEST01 order, so later tests that log in as the owner can otherwise get their clicks
 * intercepted by this overlay. Safe no-op if it isn't showing.
 *
 * Every wait here is bounded, and if the card will not close on its own it is taken off the page.
 * The close button used to be clicked with no timeout at all, which was invisible for as long as
 * website orders were failing and no card ever appeared - and became a test that hung for its whole
 * 90s the moment orders started being created again.
 */
async function dismissNewOrderOverlay(page) {
  const overlay = page.locator('#newOrderOverlay.show');
  for (let i = 0; i < 3; i++) {
    if (!(await overlay.isVisible().catch(() => false))) return;
    await page.click('#newOrderOverlay .close-overlay', { timeout: 3000 }).catch(() => {});
    await overlay.waitFor({ state: 'hidden', timeout: 3000 }).catch(() => {});
  }
  // still there: it is only in the way, so stop asking politely
  await page.evaluate(() => {
    document.querySelectorAll('#newOrderOverlay').forEach((e) => e.classList.remove('show'));
  }).catch(() => {});
}

/** Generates a unique-enough 10-digit test phone number for customer signup. */
function uniqueTestPhone() {
  // 9 + last 9 digits of the current timestamp - stays within a plausible
  // Indian mobile number shape without colliding across quick repeat runs.
  return '9' + String(Date.now()).slice(-9);
}

module.exports = { DEMO_ADMIN, TEST_RESTAURANT_ID, loginAsOwner, dismissTour, dismissNewOrderOverlay, uniqueTestPhone };
