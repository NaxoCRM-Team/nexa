const {test, expect} = require('@playwright/test');

const baseUrl = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
const userName = process.env.NEXA_LIVE_USERNAME || '';
const password = process.env.NEXA_LIVE_PASSWORD || '';

test('tenant custom object is available from normal CRM navigation', async ({page}) => {
    test.setTimeout(60_000);
    test.skip(!baseUrl || !userName || !password, 'Live Nexa credentials were not provided.');

    await page.goto(`${baseUrl}/login/`);
    await page.locator('#field-userName').fill(userName);
    await page.locator('#field-password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout: 30_000});

    await page.locator('li[data-name="nexa-crm"] > a').evaluate(element => element.click());
    const serviceContracts = page.getByRole('link', {name: /Services? Contracts/});
    await expect(serviceContracts).toBeVisible();
    await serviceContracts.click();

    await expect(page.getByRole('heading', {name: /Services? Contracts/, exact: true})).toBeVisible();
    await expect(page.getByRole('button', {name: /New Services? Contract/})).toBeVisible();
    await expect(page.locator('[data-record-search]')).toBeVisible();
    await expect(page.locator('[data-record-list]')).not.toContainText('Workspace unavailable');

    await page.getByRole('button', {name: /New Services? Contract/}).click();
    await expect(page.locator('[data-record-form]')).toBeVisible();
    await expect(page.locator('[data-record-form] [name="displayName"]')).toBeFocused();
    await expect(page.getByRole('button', {name: 'Save record'})).toBeVisible();

    const marker = Date.now();
    await page.locator('[data-record-form] [name="displayName"]').fill(`Browser contract ${marker}`);
    for (const input of await page.locator('[data-record-form] [required]').all()) {
        const name = await input.getAttribute('name');
        if (name === 'displayName') continue;
        const tag = await input.evaluate(element => element.tagName);
        const type = await input.getAttribute('type');
        if (tag === 'SELECT') {
            const options = await input.locator('option').evaluateAll(items => items.map(item => item.value).filter(Boolean));
            if (options.length) await input.selectOption(options[0]);
        } else if (type === 'checkbox') await input.check();
        else if (type === 'date') await input.fill('2026-08-25');
        else if (type === 'datetime-local') await input.fill('2026-08-25T12:00');
        else if (type === 'number') await input.fill('1');
        else if (type === 'email') await input.fill(`contract-${marker}@example.test`);
        else if (type === 'url') await input.fill('https://example.test');
        else await input.fill(`TEST-${marker}`);
    }
    await page.getByRole('button', {name: 'Save record'}).click();
    await expect(page.getByRole('heading', {name: `Browser contract ${marker}`, exact: true})).toBeVisible();

    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('button', {name: 'Delete'}).click();
    await expect(page.locator('[data-record-search]')).toBeVisible();
});
