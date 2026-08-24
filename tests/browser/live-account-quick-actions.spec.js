const {test, expect} = require('@playwright/test');

const baseUrl = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
const userName = process.env.NEXA_LIVE_USERNAME || '';
const password = process.env.NEXA_LIVE_PASSWORD || '';

test('Account quick actions are accessible and company scoped', async ({page}) => {
    test.skip(!baseUrl || !userName || !password, 'Live Nexa credentials were not provided.');

    await page.goto(`${baseUrl}/login/`);
    await page.locator('#field-userName').fill(userName);
    await page.locator('#field-password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout: 30_000});

    const tenantKey = new URL(page.url()).pathname.match(/\/w\/([^/]+)/)?.[1];
    const account = await page.evaluate(() => Espo.Ajax.getRequest('Account', {
        select: 'id,name', maxSize: 1, orderBy: 'createdAt', order: 'desc',
    }));
    test.skip(!account?.list?.length, 'The tenant has no Account fixture.');

    await page.goto(`${baseUrl}/w/${tenantKey}/Account/view/${account.list[0].id}`);
    const workspace = page.locator('[data-nexa-company-workspace]');
    await expect(workspace).toBeVisible({timeout: 30_000});
    await expect(workspace.getByRole('toolbar', {name: 'Company actions'})).toBeVisible();
    await expect(workspace.getByRole('button', {name: 'Create a company note'})).toBeVisible();
    await expect(workspace.getByRole('button', {name: 'Create a company task'})).toBeVisible();
    await expect(workspace.getByRole('button', {name: 'Schedule a company meeting'})).toBeVisible();

    await workspace.getByRole('button', {name: 'More company actions'}).click();
    const search = page.getByRole('searchbox', {name: 'Search company actions'});
    await expect(search).toBeVisible();
    await search.fill('whatsapp');
    await expect(page.getByRole('button', {name: 'Log WhatsApp message'})).toBeVisible();
    await expect(page.getByRole('button', {name: 'Log SMS'})).toBeHidden();
    await page.keyboard.press('Escape');

    await workspace.getByRole('button', {name: 'Create a company note'}).click();
    await expect(page.getByRole('heading', {name: 'Add a note'})).toBeVisible();
    await expect(page.locator('[data-nexa-account-note-dialog] .nexa-note-recipient strong'))
        .toHaveText(account.list[0].name);
    await page.getByRole('button', {name: 'Close note'}).click();
    await expect(page.locator('[data-nexa-account-note-dialog]')).toHaveCount(0);
});
