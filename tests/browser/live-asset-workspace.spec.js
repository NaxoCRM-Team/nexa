const {test, expect} = require('@playwright/test');

const baseUrl = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
const userName = process.env.NEXA_LIVE_EMAIL || process.env.NEXA_LIVE_USERNAME || '';
const password = process.env.NEXA_LIVE_PASSWORD || '';

test('Assets workspace uses native tenant files with responsive table controls', async ({page}) => {
    test.setTimeout(60_000);
    test.skip(!baseUrl || !userName || !password, 'Live Nexa credentials were not provided.');
    const pageErrors = [];
    const apiErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message));
    page.on('response', async response => {
        if (response.url().includes('/Nexa/assets') && response.status() >= 400) {
            apiErrors.push(`${response.status()} ${await response.text()}`);
        }
    });
    await page.goto(`${baseUrl}/login/`);
    await page.locator('#field-userName').fill(userName);
    await page.locator('#field-password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout: 30_000});
    const workspaceBase = page.url().match(/^(.*\/w\/[^/]+)/)?.[1];
    expect(workspaceBase).toBeTruthy();
    await page.goto(`${workspaceBase}/NexaAssets`);
    await expect(page.getByRole('heading', {name: 'Content & Assets'})).toBeVisible();
    await expect(page.locator('[data-asset-state="ready"]'), apiErrors.join('\n')).toBeVisible();
    await expect(page.locator('[data-workspace-table="assets"]')).toBeVisible();
    await expect(page.locator('[data-workspace-table="assets"] thead th[data-column="name"]')).toHaveAttribute('draggable', 'true');
    await expect(page.locator('[data-workspace-table="assets"] .nexa-col-resizer').first()).toBeAttached();
    await expect(page.locator('[data-asset-search]')).toBeVisible();
    await expect(page.locator('.nexa-asset-overlay:visible')).toHaveCount(0);
    await page.getByRole('button', {name: 'Upload asset'}).click();
    const dialog = page.getByRole('dialog', {name: 'Upload asset'});
    await expect(dialog).toBeVisible();
    const box = await dialog.boundingBox();
    const viewport = page.viewportSize();
    expect(Math.abs((box.x + box.width / 2) - viewport.width / 2)).toBeLessThan(4);
    expect(Math.abs((box.y + box.height / 2) - viewport.height / 2)).toBeLessThan(4);
    await expect(dialog.locator('[name="file"]')).toBeFocused();
    await expect(dialog.locator('[name="accessScope"]')).toHaveValue('internal');
    await dialog.getByRole('button', {name: 'Cancel'}).click();
    await expect(dialog).toBeHidden();
    const statusButton = page.locator('[data-action="toggle-archive"]').first();
    if (await statusButton.count()) {
        await statusButton.click();
        const statusDialog = page.locator('[data-asset-confirm-dialog]');
        await expect(statusDialog).toBeVisible();
        await statusDialog.getByRole('button', {name: 'Cancel'}).click();
        await expect(statusDialog).toBeHidden();
    }
    expect(pageErrors).toEqual([]);
});
