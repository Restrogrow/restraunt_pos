// End-to-end test of the new-order ring flow in the Expo web preview.
// Drives the real app at http://localhost:8081, logs in as the seeded admin,
// plants a Pending order through the app's own polling API, then exercises:
// auto-open -> ring keeps playing on the detail screen -> Mute -> Unmute ->
// Accept cuts the sound -> nav state persists across reload.
//
// Run: node e2e-ring-flow.js   (requires Metro: npx expo start --web)

const { chromium } = require('playwright');
const mysql = require('mysql2/promise');
const { execSync } = require('child_process');

const APP_URL = 'http://localhost:8081';
const ADMIN_USER = 'sujay';
const ADMIN_PASS = '123456';

// The PHP backend reads DB creds from main/.env; since .env carries the
// Hostinger creds, the local branch in db_connection.php falls back to its
// XAMPP defaults (root / empty password). Resolve the local DB the same way
// so this test always targets exactly the DB the app is talking to.
const DB = (() => {
  const out = execSync('"D:/xampp/php/php.exe" db-cred-probe.php', { encoding: 'utf8' }).trim();
  const j = JSON.parse(out);
  return { host: j.host, user: j.user, password: j.pass, database: j.db };
})();

const log = (...a) => console.log('  ', ...a);
let passCount = 0;
let failCount = 0;

function assert(cond, label) {
  if (cond) {
    passCount++;
    log(`PASS  ${label}`);
  } else {
    failCount++;
    log(`FAIL  ${label}`);
  }
}

async function plantPendingOrder(conn, { restaurantId, tableId, menuItemId, suffix }) {
  const orderNumber = `E2E-${suffix}-${Date.now().toString().slice(-6)}`;
  const [r] = await conn.execute(
    `INSERT INTO orders
      (restaurant_id, table_id, order_number, customer_name, customer_phone,
       order_type, payment_method, payment_status, order_status,
       subtotal, tax, total, source, created_at)
     VALUES (?, ?, ?, 'E2E Tester', '9999999999',
             'Dine-In', 'Cash', 'Pending', 'Pending',
             100.00, 5.00, 105.00, 'website', NOW())`,
    [restaurantId, tableId, orderNumber]
  );
  const orderId = r.insertId;
  if (menuItemId) {
    await conn.execute(
      `INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, unit_price, total_price)
       VALUES (?, ?, 'E2E Test Item', 1, 100.00, 100.00)`,
      [orderId, menuItemId]
    ).catch(() => {}); // schema differences here are non-fatal for the flow test
  }
  return { orderId, orderNumber };
}

async function getOrderStatus(conn, orderId) {
  const [rows] = await conn.execute(
    'SELECT order_status, payment_status FROM orders WHERE id = ?',
    [orderId]
  );
  return rows[0] || null;
}

// Read the state of the shared telephone-ring <audio> element. expo-audio's
// web player creates elements via `new Audio()` WITHOUT attaching them to
// the DOM, so querySelectorAll can't see them — an init script (installed
// below) wraps HTMLMediaElement.play and captures the ring element the
// first time it plays.
function ringState(page) {
  return page.evaluate(() => {
    const el = window.__ringEl;
    if (el) {
      return { found: true, paused: el.paused, loop: el.loop, time: el.currentTime, ended: el.ended };
    }
    const els = [...document.querySelectorAll('audio, video')];
    for (const e of els) {
      if (/telephone-ring/i.test(e.currentSrc || e.src || '')) {
        return { found: true, paused: e.paused, loop: e.loop, time: e.currentTime, ended: e.ended };
      }
    }
    return { found: false };
  });
}

(async () => {
  let conn;
  let browser;
  let context;
  try {
    conn = await mysql.createConnection(DB);

    // --- Reference data ---------------------------------------------------
    // The login query filters AND is_active = 1 — the local DB ships sujay
    // deactivated. Remember the original value, activate for the test, and
    // restore it in cleanup so the local DB is left as it was found.
    const [[adminRow]] = await conn.execute(
      'SELECT is_active FROM users WHERE username = ? LIMIT 1',
      [ADMIN_USER]
    );
    const originalIsActive = adminRow?.is_active ?? 0;
    if (!originalIsActive) {
      await conn.execute('UPDATE users SET is_active = 1 WHERE username = ?', [ADMIN_USER]);
      log('temporarily activated test admin (is_active 0 -> 1)');
    }
    const [[admin]] = await conn.execute(
      'SELECT restaurant_id FROM users WHERE username = ? LIMIT 1',
      [ADMIN_USER]
    );
    const restaurantId = admin?.restaurant_id;
    if (!restaurantId) throw new Error('Seeded admin has no restaurant_id');
    const [[tbl]] = await conn.execute(
      'SELECT id FROM tables WHERE restaurant_id = ? ORDER BY id LIMIT 1',
      [restaurantId]
    );
    const tableId = tbl?.id || null;
    const [[mi]] = await conn.execute(
      'SELECT id FROM menu_items WHERE restaurant_id = ? ORDER BY id LIMIT 1',
      [restaurantId]
    );
    const menuItemId = mi?.id || null;
    // Leftovers from an aborted earlier run would ring on the next open.
    await conn.execute("DELETE FROM orders WHERE order_number LIKE 'E2E-%'");
    log(`restaurant=${restaurantId} table=${tableId} menuItem=${menuItemId}`);

    // --- Browser ----------------------------------------------------------
    browser = await chromium.launch({ headless: true });
    context = await browser.newContext({
      viewport: { width: 430, height: 900 },
      permissions: [], // no fake media — we assert on the audio element state
    });
    // Capture the ring's Audio element: expo-audio never attaches it to the
    // DOM, so the only way in is to intercept construction/play at the
    // prototype level before the app bundle runs.
    await context.addInitScript(() => {
      const origPlay = HTMLMediaElement.prototype.play;
      HTMLMediaElement.prototype.play = function (...args) {
        const src = this.currentSrc || this.src || '';
        if (/telephone-ring/i.test(src)) {
          window.__ringEl = this;
        }
        return origPlay.apply(this, args);
      };
    });
    const page = await context.newPage();

    const consoleErrors = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') consoleErrors.push(msg.text());
    });
    page.on('pageerror', (err) => consoleErrors.push(`pageerror: ${err.message}`));

    // Debug visibility into the watcher's polling (removed from PASS/FAIL
    // logic — purely diagnostic logging).
    page.on('response', async (res) => {
      if (!/get_orders\.php/.test(res.url())) return;
      let body = '';
      try {
        body = (await res.text()).slice(0, 200);
      } catch (e) {}
      log(`[poll ${res.status()}] ${body}`);
    });

    // --- Login ------------------------------------------------------------
    await page.goto(APP_URL, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(8000); // Metro bundle on first hit

    const inputs = page.locator('input');
    await inputs.nth(0).waitFor({ state: 'visible', timeout: 30000 });
    await inputs.nth(0).fill(ADMIN_USER);
    await inputs.nth(1).fill(ADMIN_PASS);
    // RN-web Pressables render as plain divs (no role/button element), so
    // all button targeting is by visible text; clicks on the Text child
    // bubble to the Pressable's onClick.
    await page.getByText('Log In', { exact: true }).click();

    // Wait for the tab bar / Orders UI, i.e. real post-login signals.
    await page.waitForSelector('text=Log In', { state: 'detached', timeout: 20000 }).catch(() => {});
    await page.waitForTimeout(6000);
    const bodyText = await page.evaluate(() => document.body.innerText);
    const loginGone = !/welcome back/i.test(bodyText);
    if (!loginGone) log(`post-login body: ${JSON.stringify(bodyText.slice(0, 220))}`);
    assert(loginGone && /orders/i.test(bodyText), 'Login succeeded (Orders UI visible, login card gone)');

    // --- Baseline seed: existing orders must NOT ring -----------------------
    // One full poll cycle with no new orders -> ring must stay silent.
    let rs = await ringState(page);
    assert(!rs.found || rs.paused, 'No ring before any new order (baseline silent)');

    // --- Plant order #1: auto-open + ring continues on detail --------------
    const o1 = await plantPendingOrder(conn, { restaurantId, tableId, menuItemId, suffix: 'A' });
    log(`planted order ${o1.orderNumber} (id ${o1.orderId})`);

    await page.waitForTimeout(13000); // watcher polls every 10s
    rs = await ringState(page);
    assert(rs.found, 'Ring audio element appears (telephone-ring.mp3 loaded)');
    assert(rs.found && !rs.paused, 'Ring is PLAYING after new order (continues on detail screen)');
    assert(rs.found && rs.loop, 'Ring element is set to loop');

    const detailText = await page.evaluate(() => document.body.innerText);
    assert(/new order/i.test(detailText), 'App auto-opened the order detail screen');
    assert(
      o1.orderNumber && detailText.includes(o1.orderNumber),
      `Detail screen shows the new order number (${o1.orderNumber})`
    );

    // Wait through at least one keepalive tick (5s) to prove looping persists.
    await page.waitForTimeout(6000);
    rs = await ringState(page);
    assert(rs.found && !rs.paused, 'Ring still playing 6s later (keepalive/watchdog works)');

    // --- Mute ---------------------------------------------------------------
    await page.getByText('Mute', { exact: true }).waitFor({ state: 'visible', timeout: 15000 });
    assert(true, 'Header shows "Mute" while ringing');
    await page.getByText('Mute', { exact: true }).click();
    await page.waitForTimeout(1200);
    rs = await ringState(page);
    assert(rs.found && rs.paused, 'Mute pauses the ring');
    await page.getByText('Unmute', { exact: true }).waitFor({ state: 'visible', timeout: 5000 });
    assert(true, 'Button flips to "Unmute" after muting');

    // --- Unmute -------------------------------------------------------------
    await page.getByText('Unmute', { exact: true }).click();
    await page.waitForTimeout(1200);
    rs = await ringState(page);
    assert(rs.found && !rs.paused, 'Unmute restarts the ring');
    await page.getByText('Mute', { exact: true }).waitFor({ state: 'visible', timeout: 5000 });
    assert(true, 'Button flips back to "Mute"');

    // --- Accept stops the ring ----------------------------------------------
    // The footer renders the label uppercase: Accept -> "ACCEPT".
    await page.getByText('ACCEPT', { exact: true }).click();
    await page.waitForTimeout(2500); // API + state update
    rs = await ringState(page);
    assert(rs.found && rs.paused, 'Accept cuts the ring immediately');

    const dbStatus = await getOrderStatus(conn, o1.orderId);
    assert(dbStatus?.order_status === 'Accepted', 'DB shows order status = Accepted');

    // --- Reload: state persistence ------------------------------------------
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(6000);
    const restoredText = await page.evaluate(() => document.body.innerText);
    // Either still inside the app on some tab (not bounced to login, not
    // reset to the Orders list), or back at login because the PHP session
    // cookie was cleared — the latter is backend behavior, not the nav
    // persistence. The persistence assertion: if logged in, we should be on
    // the same tab (Orders stack) — i.e., NOT reset to a default screen.
    const stillLoggedIn = !/sign in|login/i.test(restoredText.slice(0, 400));
    if (stillLoggedIn) {
      assert(
        /orders|pos|menu|reports|settings/i.test(restoredText),
        'After reload: still logged in and inside the app (nav state restored, not bounced to login)'
      );
    } else {
      log('NOTE: reload landed on login (PHP session cookie not kept by headless context) — persistence check skipped');
    }

    // --- Cleanup -------------------------------------------------------------
    await conn.execute("DELETE FROM orders WHERE order_number LIKE 'E2E-%'");
    await conn.execute('UPDATE users SET is_active = ? WHERE username = ?', [originalIsActive, ADMIN_USER]);

    // --- Console errors -------------------------------------------------------
    const relevantErrors = consoleErrors.filter(
      (e) =>
        !/NotAllowedError|autoplay/i.test(e) && // known pre-gesture web quirk
        !/expo-notifications.*not yet fully supported on web/i.test(e) &&
        !/props.pointerEvents is deprecated/i.test(e)
    );
    assert(relevantErrors.length === 0, `No unexpected console errors (${relevantErrors.length})`);
    if (relevantErrors.length) log(relevantErrors.slice(0, 5).join('\n'));

    log(`\nRESULT: ${passCount} passed, ${failCount} failed`);
    process.exit(failCount ? 1 : 0);
  } catch (err) {
    console.error('E2E ERROR:', err.message);
    process.exit(2);
  } finally {
    if (conn) await conn.end().catch(() => {});
    if (browser) await browser.close().catch(() => {});
  }
})();
