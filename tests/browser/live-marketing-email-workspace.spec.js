const {test, expect} = require('@playwright/test');

const baseURL = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
const username = process.env.NEXA_LIVE_USERNAME || '';
const password = process.env.NEXA_LIVE_PASSWORD || '';

test('marketing email workspace is governed, responsive and native-backed', async ({page}) => {
    test.setTimeout(60_000);
    test.skip(!baseURL || !username || !password, 'Live Nexa credentials were not provided.');
    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message));

    await page.goto(`${baseURL}/login/`);
    await page.locator('#field-userName').fill(username);
    await page.locator('#field-password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout: 30_000});
    const workspaceBase = page.url().match(/^(.*\/w\/[^/]+)/)?.[1];
    expect(workspaceBase).toBeTruthy();

    await page.goto(`${workspaceBase}/NexaMarketingEmail`);
    await expect(page.getByRole('heading', {name: 'Marketing Email', exact: true})).toBeVisible();
    await expect(page.locator('[data-email-state="ready"]')).toBeVisible();
    await expect(page.getByText('Delivery remains locked during provider setup')).toBeVisible();
    await expect(page.locator('[data-delivery-message]')).toContainText('verified sending domain');
    await expect(page.locator('[data-email-search]')).toBeVisible();

    await page.locator('[data-action="delivery-setup"]').click();
    const delivery = page.locator('[data-delivery-editor]');
    await expect(delivery.getByRole('heading', {name: 'Sending domain and provider'})).toBeVisible();
    await expect(delivery.locator('[name="apiBaseUrl"]')).toBeVisible();
    await expect(delivery.locator('[name="domainName"]')).toBeVisible();
    await delivery.locator('[data-action="close-delivery"]').click();
    await expect(delivery).toBeHidden();

    await page.locator('[data-action="new-email"]').first().click();
    const dialog = page.locator('[data-email-editor]');
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('heading', {name: 'Create marketing email'})).toBeVisible();
    await expect(dialog.locator('[name="campaignId"]')).toBeVisible();
    await expect(dialog.getByRole('button', {name: 'Save draft'})).toBeVisible();
    await dialog.locator('[data-action="close-editor"]').first().click();
    await expect(dialog).toBeHidden();

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    expect(pageErrors).toEqual([]);
});
