const {test, expect} = require('@playwright/test');

const baseUrl = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
const userName = process.env.NEXA_LIVE_EMAIL || process.env.NEXA_LIVE_USERNAME || '';
const password = process.env.NEXA_LIVE_PASSWORD || '';

async function login(page) {
    await page.goto(`${baseUrl}/login/`);
    await page.locator('#field-userName').fill(userName);
    await page.locator('#field-password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout: 30_000});
}

test('live Opportunity routes and sales workspace load for a tenant administrator', async ({page}, testInfo) => {
    test.setTimeout(90_000);
    test.skip(!baseUrl || !userName || !password, 'Live Nexa credentials were not provided.');

    const clientErrors = [];
    page.on('pageerror', error => clientErrors.push(error.message));
    await login(page);

    await page.goto(`${baseUrl}/w/isolation-alpha/Opportunity`);
    await expect(page.locator('.nexa-opportunity-list-page')).toBeVisible({timeout: 30_000});
    await expect(page.locator('.nexa-opportunity-live-search input[data-name="textFilter"]:visible')).toHaveCount(1);
    await expect(page.getByRole('button', {name: 'Choose Opportunity filters'})).toContainText('Filters');
    await expect(page.locator('.nexa-opportunity-create-button')).toHaveCSS('color', 'rgb(255, 255, 255)');
    await page.waitForTimeout(1_000);
    await expect(page.locator('.nexa-opportunity-create-button')).toBeEnabled();
    await page.locator('.nexa-opportunity-create-button').click();
    await expect(page).toHaveURL(/\/Opportunity\/create$/);
    await expect(page.getByRole('region', {name: 'Deal'})).toBeVisible({timeout: 30_000});

    for (const item of [
        ['Pipelines', 'fa-project-diagram'],
        ['Forecasts', 'fa-chart-bar'],
        ['Products & Quotes', 'fa-box-open'],
    ]) {
        const link = page.locator('.nexa-active-module-link').filter({hasText: item[0]});
        await expect(link.locator(`.${item[1]}`)).toHaveCount(1);
    }

    await expect(page.getByRole('button', {name: 'Save', exact: true})).toBeVisible();

    for (const section of ['pipelines', 'products']) {
        await page.goto(`${baseUrl}/w/isolation-alpha/NexaSales/${section}`);
        await expect(page.locator('.nexa-sales-workspace')).toBeVisible({timeout: 30_000});
        await expect(page.locator('[data-sales-state="ready"]')).toBeVisible({timeout: 30_000});
        await expect(page.locator('[data-sales-state="error"]')).toBeHidden();
    }

    await page.goto(`${baseUrl}/w/isolation-alpha/NexaCurrency`);
    await expect(page.locator('.nexa-currency-admin')).toBeVisible({timeout: 30_000});
    await expect(page.locator('[data-currency-state="ready"]')).toBeVisible({timeout: 30_000});
    await expect(page.locator('[name="baseCurrency"]')).toHaveValue(/^[A-Z]{3}$/);
    await expect(page.locator('[name="defaultCurrency"]')).toHaveValue(/^[A-Z]{3}$/);
    await page.locator('[name="rateMode"]').selectOption('automatic');
    if (await page.locator('[name="baseCurrency"] option[value="GBP"]').count() === 0) {
        await page.locator('[data-currency-search]').fill('GBP');
        await expect(page.locator('[data-currency-results]')).toContainText('GBP');
        await page.locator('[data-currency-results] [data-code="GBP"]').click();
    }
    await expect(page.locator('[name="baseCurrency"] option[value="GBP"]')).toHaveCount(1);
    await page.locator('[name="baseCurrency"]').selectOption('GBP');
    await expect(page.locator('[name="baseCurrency"]')).toHaveValue('GBP');
    await expect(page.locator('[name="defaultCurrency"]')).toHaveValue('GBP');
    await expect(page.locator('[name="rate-USD"]')).not.toHaveValue('1');
    await expect(page.locator('[data-rate-status-copy]')).toContainText('Frankfurter');
    const removeUsd = page.locator('[data-currency-code="USD"] [data-action="remove-currency"]');
    await expect(removeUsd).toBeEnabled();
    await removeUsd.click();
    await expect(page.locator('[data-currency-code="USD"]')).toHaveCount(0);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({path: testInfo.outputPath('currency-admin.png'), animations: 'disabled'});

    expect(clientErrors).toEqual([]);
});
