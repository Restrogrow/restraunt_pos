const { test, expect } = require('@playwright/test');
const { loginAsOwner } = require('./helpers');

// Direct regression test for the Inventory extraction (script.js -> inventory.js).
// Adds a stock item through the real UI, confirms it appears, then deletes it
// again so the test is repeatable without accumulating leftover rows.
test.describe('Inventory (extracted feature)', () => {
  test('add and delete a stock item', async ({ page, context }) => {
    await loginAsOwner(page, context);

    // Inventory lives in the collapsible "Menu" submenu - expand it first,
    // otherwise the submenu-toggle link intercepts the click.
    await page.click('.nav-item.has-submenu .submenu-toggle');
    await page.click('.nav-link[data-page="inventoryPage"]');
    await expect(page.locator('#inventoryTbody')).toBeVisible({ timeout: 10000 });

    const itemName = `Playwright Test Item ${Date.now()}`;

    await page.click('#btnNewInventoryItem');
    await expect(page.locator('#inventoryModal')).toBeVisible();
    await page.fill('#invName', itemName);
    await page.click('#invSaveBtn');

    const row = page.locator('#inventoryTbody tr', { hasText: itemName });
    await expect(row).toBeVisible({ timeout: 10000 });

    // Clean up: delete via the row's trash button, confirm the SweetAlert2 dialog.
    await row.locator('button[title="Delete"]').click();
    await page.click('button:has-text("Delete")'); // SweetAlert2 confirm button
    await expect(row).not.toBeVisible({ timeout: 10000 });
  });
});
