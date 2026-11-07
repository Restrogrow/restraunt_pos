const { test, expect } = require('@playwright/test');
const { loginAsOwner } = require('./helpers');

// Adds a stock item through the real UI, confirms it appears, then deletes it again so the test is
// repeatable without leaving rows behind.
//
// Written against the Inventory studio (assets/js/inventory-studio.js), which renders into #invRoot
// and drives everything from data-iv-* attributes. The previous version of this test still expected
// #inventoryTbody, #btnNewInventoryItem and #inventoryModal — markup from before that rewrite, none
// of which exists any more, so it could never pass.
test.describe('Inventory (extracted feature)', () => {
  test('add and delete a stock item', async ({ page, context }) => {
    await loginAsOwner(page, context);

    // Inventory lives in the collapsible "Menu" submenu - expand it first,
    // otherwise the submenu-toggle link intercepts the click.
    await page.click('.nav-item.has-submenu .submenu-toggle');
    await page.click('.nav-link[data-page="inventoryPage"]');
    // the studio opens on Overview; Add item lives on the Stock tab
    await page.click('#invRoot [data-iv-tab="stock"]');
    await expect(page.locator('#invRoot [data-iv-item-new]')).toBeVisible({ timeout: 15000 });

    const itemName = `Playwright Test Item ${Date.now()}`;

    await page.click('[data-iv-item-new]');
    await expect(page.locator('#ivName')).toBeVisible();
    await page.fill('#ivName', itemName);
    await page.click('[data-iv-item-save]');

    const row = page.locator('#invRoot tr', { hasText: itemName });
    await expect(row).toBeVisible({ timeout: 15000 });

    // Clean up: open it again and delete from inside the editor.
    await row.locator('[data-iv-item-edit]').first().click();
    await expect(page.locator('#ivName')).toBeVisible();
    await page.click('[data-iv-item-del]');

    // The delete goes through rgUndo: the row disappears at once and the request is sent when the
    // undo window closes, so wait for the row to go rather than for a confirm dialog.
    await expect(row).toBeHidden({ timeout: 15000 });
  });
});
