const {test, expect} = require('@playwright/test');

const baseUrl = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
const userName = process.env.NEXA_LIVE_USERNAME || '';
const password = process.env.NEXA_LIVE_PASSWORD || '';

test('tenant administrator can review properties and is warned before creating a duplicate', async ({page}) => {
    test.setTimeout(60_000);
    test.skip(!baseUrl || !userName || !password, 'Live Nexa credentials were not provided.');

    await page.goto(`${baseUrl}/login/`);
    await page.locator('#field-userName').fill(userName);
    await page.locator('#field-password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout: 30_000});

    const workspacePath = new URL(page.url()).pathname.match(/^(.*\/w\/[^/]+)/)?.[1];
    expect(workspacePath).toBeTruthy();
    await page.goto(`${new URL(baseUrl).origin}${workspacePath}/NexaCustomization`);

    await expect(page.getByRole('heading', {name: 'Objects & properties'})).toBeVisible();
    const refreshedDefinitions = page.waitForResponse(response =>
        response.url().includes('/api/v1/Nexa/customization/definitions') && response.request().method() === 'GET'
    );
    await page.reload();
    await expect(page.getByRole('heading', {name: 'Objects & properties'})).toBeVisible();
    await expect((await refreshedDefinitions).status()).toBe(200);
    await expect(page.getByText('Bad server response', {exact: true})).toHaveCount(0);
    await expect(page.getByRole('button', {name: /Opportunities.*Coming later/})).toBeVisible();
    await page.getByPlaceholder('Search objects').fill('opportunities');
    await expect(page.getByRole('button', {name: /Opportunities/})).toBeVisible();
    await expect(page.getByRole('button', {name: /Contacts/})).toBeHidden();
    await page.getByPlaceholder('Search objects').clear();
    await expect(page.getByRole('button', {name: /Contacts/})).toBeVisible();

    await page.getByRole('button', {name: /Opportunities.*Coming later/}).click();
    await expect(page.getByRole('tab', {name: 'Object settings'})).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByText('This object is reserved for a future product area. Configuration will become available when that workspace is released.')).toBeVisible();
    await page.getByRole('button', {name: 'All objects'}).click();

    await page.getByRole('button', {name: /Contacts/}).click();
    await expect(page.getByText(/standard \| \d+ custom/i)).toBeVisible();
    await expect(page.getByText('Standard property | firstName', {exact: true})).toBeVisible();

    await page.getByRole('button', {name: 'Add property'}).click();
    const dialog = page.getByRole('dialog', {name: /Add information to Contacts/});
    await dialog.getByLabel('Property name').fill('First Name');
    await expect(dialog.getByRole('alert')).toContainText('already exists as a standard property');
    await expect(dialog.getByRole('button', {name: 'Create property'})).toBeDisabled();
    await dialog.getByRole('button', {name: 'Cancel'}).click();

    const customizationRequests = [];
    page.on('request', request => {
        if (request.url().includes('/Nexa/customization/definitions')) customizationRequests.push(request.url());
    });
    await page.goto(`${new URL(baseUrl).origin}${workspacePath}/Contact`);
    await page.reload();
    await page.waitForTimeout(1_000);
    expect(customizationRequests.length).toBeGreaterThan(0);
    expect(customizationRequests.every(url => new URL(url).pathname.includes('/api/v1/Nexa/customization/definitions'))).toBe(true);
    await page.locator('.add-filter-button').click();
    const customPropertyFilter = page.locator('.filter-list li[data-name="nexaCustomPropertyFilter"]');
    await expect(customPropertyFilter).toContainText('Custom properties');
    await customPropertyFilter.getByRole('button').click();
    await expect(page.locator('[data-custom-property]')).toBeVisible();
});
