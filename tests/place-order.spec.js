const { test, expect } = require('@playwright/test');
const { TEST_RESTAURANT_ID } = require('./helpers');

// TEST01 (scripts/seed_test_restaurant.sql) is a dedicated, isolated test
// restaurant: cash_only, ₹0 minimum order, with two simple has_variations=0
// items - "Test Burger" and "Test Fries" - so this test never depends on real
// restaurant data and never needs to handle variant-chip selection.
const ITEM_NAMES = ['Test Burger', 'Test Fries'];

test.describe('Guest checkout - place an order end-to-end', () => {
  test('adds items, checks out with Cash, and gets an order confirmation', async ({ page }) => {
    await page.goto(`main/website/menu.php?restaurant_id=${TEST_RESTAURANT_ID}`);

    for (const itemName of ITEM_NAMES) {
      const card = page.locator('.card', { has: page.locator('.card-name', { hasText: itemName }) });
      await card.scrollIntoViewIfNeeded();
      await card.click();

      // Item detail sheet opens; these items have no real variants, so it's a
      // direct add at base price.
      await expect(page.locator('#productSheetOverlay')).toHaveClass(/active/);
      await page.click('.sheet-add-btn');
      await expect(page.locator('#productSheetOverlay')).not.toHaveClass(/active/);
    }

    // The checkout bar (#checkoutBar) is hidden until the cart has items,
    // then gets a `.show` class - not a disabled attribute on this page.
    await expect(page.locator('#checkoutBar')).toHaveClass(/show/);
    await page.click('#checkoutBtn');

    await page.waitForURL(/cart\.php/, { timeout: 10000 });
    // Here #checkoutBtn *does* start disabled and only enables once cart.php
    // finishes loading/validating the cart against the server.
    const cartCheckoutBtn = page.locator('#checkoutBtn');
    await expect(cartCheckoutBtn).toBeEnabled({ timeout: 10000 });
    await cartCheckoutBtn.click(); // proceedCheckout() - opens the checkout form modal

    const form = page.locator('#checkoutForm');
    await expect(form).toBeVisible();

    // Takeaway avoids the delivery-address/geolocation flow and the dine-in
    // table picker, keeping this test focused on the order-creation path itself.
    await page.click('label.order-type-option[data-ot="takeaway"]');
    await form.locator('#chkName').fill('Playwright Test Customer');
    await form.locator('#chkPhone').fill('9' + String(Date.now()).slice(-9));

    // TEST01 is cash_only, so "Cash" (shown as "Pay at Counter" for Takeaway)
    // is the only payment option - no gateway redirect to deal with.
    await form.locator('button[type="submit"]', { hasText: 'Place Order' }).click();

    // process_website_order.php returns {success, order_id, order_number, ...}
    // and cart.php shows a success modal with the order number.
    await expect(page.locator('#successModal, .modal-overlay')).toContainText(/order/i, { timeout: 15000 });
  });
});
