const {test, expect} = require('@playwright/test');

const baseUrl = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
const userName = process.env.NEXA_LIVE_USERNAME || '';
const password = process.env.NEXA_LIVE_PASSWORD || '';

test('guided Account import renders and rejects unsupported controlled values', async ({page}) => {
    test.skip(!baseUrl || !userName || !password, 'Live Nexa credentials were not provided.');

    await page.goto(`${baseUrl}/login/`);
    await page.locator('#field-userName').fill(userName);
    await page.locator('#field-password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout: 30_000});

    const tenantKey = new URL(page.url()).pathname.match(/\/w\/([^/]+)/)?.[1];
    expect(tenantKey).toBeTruthy();
    await page.goto(`${baseUrl}/w/${tenantKey}/Account/import`);

    await expect(page.getByRole('heading', {name: 'Import Accounts'})).toBeVisible();
    await expect(page.getByRole('button', {name: 'Download template'})).toBeVisible();
    await expect(page.getByText('Maximum rows for this import')).toBeVisible();
    await expect(page.getByText('Check controlled values before uploading')).toBeVisible();

    const template = await page.evaluate(() => Espo.Ajax.getRequest(
        'Nexa/account-import/template', null, {dataType: 'text'}
    ));
    expect(template).toContain('company_name,website,phone,email,industry,account_type');

    const csv = [
        'company_name,website,phone,email,industry,account_type,annual_revenue,currency,employees,address_street,address_city,address_state,address_postal_code,address_country,lifecycle_stage,lead_status,description',
        'Example Import Company,example.test,,info@example.test,Software,Unsupported Type,1000,GBP,5,,,,,United Kingdom,Lead,New,Validation test',
    ].join('\r\n');
    const validation = await page.evaluate(body => Espo.Ajax.request(
        'Nexa/account-import/preview?rowLimit=10',
        'POST',
        body,
        {
            contentType: 'text/csv',
            processData: false,
            headers: {'X-Nexa-File-Name': encodeURIComponent('accounts.csv')},
        }
    ), csv);

    expect(validation.valid).toBe(false);
    expect(validation.errors).toEqual(expect.arrayContaining([
        expect.objectContaining({field: 'account_type'}),
    ]));
    await expect(page.locator('.nexa-account-import')).toHaveScreenshot('account-import-guided.png');
});
