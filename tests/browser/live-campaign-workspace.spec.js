const {test, expect} = require('@playwright/test');
const baseURL = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
const username = process.env.NEXA_LIVE_USERNAME || '';
const password = process.env.NEXA_LIVE_PASSWORD;
test('campaign workspace renders its governed audience boundary', async ({page}) => {
    test.setTimeout(60_000);
    test.skip(!baseURL || !username || !password, 'Live Nexa credentials were not provided.');
    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message));
    await page.goto(`${baseURL}/login/`);
    await page.locator('#field-userName').fill(username);
    await page.locator('#field-password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout:30_000});
    const workspaceBase = page.url().match(/^(.*\/w\/[^/]+)/)?.[1];
    expect(workspaceBase).toBeTruthy();
    await page.goto(`${workspaceBase}/NexaCampaigns`);
    await expect(page.getByRole('heading', {name:'Campaigns'})).toBeVisible();
    await expect(page.locator('[data-campaign-state="ready"]')).toBeVisible();
    await expect(page.getByText('Consent is checked before activation')).toBeVisible();
    await expect(page.getByRole('button', {name:/create campaign/i}).first()).toBeVisible();

    if ((page.viewportSize()?.width || 0) <= 767) {
        await page.locator('.navbar-toggle').click();
        await expect(page.locator('#nexa-workspace-navigation')).toHaveClass(/\bin\b/);
    }

    const marketing = page.locator('.nexa-workspace-group-link').filter({hasText: 'Marketing'}).first();
    await marketing.click();
    const menu = marketing.locator('xpath=..').locator('.dropdown-menu');
    await expect(menu).toBeVisible();
    await expect(menu.locator('a[href="#NexaCampaigns"]')).toHaveCount(1);
    await expect(page.locator('#navbar a[href="#Campaign"]')).toHaveCount(0);
    await expect(menu.locator('a[href="#NexaCampaigns"]')).toContainText('Campaigns');
    expect(await menu.evaluate(element => getComputedStyle(element).backgroundColor)).not.toBe('rgb(0, 0, 0)');
    expect(pageErrors).toEqual([]);
});
