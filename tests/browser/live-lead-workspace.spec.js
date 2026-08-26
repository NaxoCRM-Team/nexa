const {test, expect} = require('@playwright/test');

const baseUrl = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
const userName = process.env.NEXA_LIVE_USERNAME || '';
const password = process.env.NEXA_LIVE_PASSWORD || '';

test('authenticated Lead list uses the Nexa live-search workspace', async ({page}, testInfo) => {
    test.setTimeout(60_000);
    test.skip(!baseUrl || !userName || !password, 'Live Nexa credentials were not provided.');

    await page.goto(`${baseUrl}/login/`);
    await page.locator('#field-userName').fill(userName);
    await page.locator('#field-password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout: 30_000});

    await page.evaluate(() => { window.location.hash = '#Lead'; });
    await expect(page.locator('.nexa-lead-list-page')).toBeVisible({timeout: 20_000});
    await expect(page.locator('.nexa-lead-live-search input[data-name="textFilter"]')).toBeVisible();
    await expect(page.getByRole('link', {name: 'My Leads'})).toBeVisible();
    await expect(page.getByRole('link', {name: 'All Leads'})).toBeVisible();
    await expect(page.locator('.nexa-lead-column-selector')).toContainText('Columns');
    await expect(page.locator('.nexa-lead-scroll-list')).toBeVisible();
    await expect(page.locator('.nexa-lead-list-page .pagination')).toBeHidden();

    const search = page.locator('.nexa-lead-live-search input[data-name="textFilter"]');
    await search.fill('qualification');
    await page.waitForTimeout(450);
    await expect(search).toHaveValue('qualification');

    const screenshot = await page.screenshot({animations: 'disabled', fullPage: true});
    await testInfo.attach(`live-lead-${testInfo.project.name}.png`, {body: screenshot, contentType: 'image/png'});
});
