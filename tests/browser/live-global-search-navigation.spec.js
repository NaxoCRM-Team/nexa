const {test, expect} = require('@playwright/test');

const baseURL = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
const username = process.env.NEXA_LIVE_USERNAME || '';
const password = process.env.NEXA_LIVE_PASSWORD;

test('global search results navigate to the selected record', async ({page}) => {
    test.setTimeout(60_000);
    test.skip(!baseURL || !username || !password, 'Live Nexa credentials were not provided.');

    await page.goto(`${baseURL}/login/`);
    await page.locator('#field-userName').fill(username);
    await page.locator('#field-password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout: 30_000});

    const input = page.locator('input.global-search-input');
    await input.fill('Freya');
    await input.press('Enter');

    const result = page.locator('#global-search-panel .list-container a[href]').first();
    await expect(result).toBeVisible({timeout: 20_000});
    const href = await result.getAttribute('href');
    expect(href).toMatch(/^#[A-Za-z][A-Za-z0-9]*\/view\/[A-Za-z0-9]+$/);

    await result.click();
    const route = href.slice(1).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    await expect(page).toHaveURL(new RegExp(`/w/[^/]+/${route}$`));
    await expect(page.locator('#global-search-panel')).toHaveCount(0);
});

test('global search module suggestions navigate to the Nexa workspace', async ({page}) => {
    test.setTimeout(60_000);
    test.skip(!baseURL || !username || !password, 'Live Nexa credentials were not provided.');

    await page.goto(`${baseURL}/login/`);
    await page.locator('#field-userName').fill(username);
    await page.locator('#field-password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout: 30_000});

    const input = page.locator('input.global-search-input');
    await input.fill('camp');

    const campaignSuggestion = page.locator('.nexa-search-suggestion').filter({hasText: 'Campaigns'}).first();
    await expect(campaignSuggestion).toBeVisible();
    await campaignSuggestion.click();

    await expect(page).toHaveURL(/\/w\/[^/]+\/NexaCampaigns$/);
    await expect(page.getByRole('heading', {name: 'Campaigns'})).toBeVisible();
});

