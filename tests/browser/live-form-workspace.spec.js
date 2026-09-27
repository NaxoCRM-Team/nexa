const {test, expect} = require('@playwright/test');

const baseUrl = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
const userName = process.env.NEXA_LIVE_USERNAME || '';
const password = process.env.NEXA_LIVE_PASSWORD || '';

test('Forms workspace supports responsive form building', async ({page}) => {
        test.setTimeout(60_000);
        test.skip(!baseUrl || !userName || !password, 'Live Nexa credentials were not provided.');
        const pageErrors = [];
        page.on('pageerror', error => pageErrors.push(error.message));
        await page.goto(`${baseUrl}/login/`);
        await page.locator('#field-userName').fill(userName);
        await page.locator('#field-password').fill(password);
        await page.locator('#login-form button[type="submit"]').click();
        await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout: 30_000});
        const workspaceBase = page.url().match(/^(.*\/w\/[^/]+)/)?.[1];
        expect(workspaceBase).toBeTruthy();
        await page.goto(`${workspaceBase}/NexaForms`);
        await expect(page.getByRole('heading', {name: 'Forms', exact: true})).toBeVisible();
        await expect(page.locator('[data-form-state="ready"]')).toBeVisible();
        await expect(page.locator('[data-form-search]')).toBeVisible();
        await expect(page.locator('[data-workspace-table="forms"]')).toBeVisible();
        await expect(page.locator('[data-workspace-table="forms"] thead th[data-column="form"]')).toHaveAttribute('draggable', 'true');
        await expect(page.locator('[data-workspace-table="forms"] .nexa-col-resizer').first()).toBeAttached();
        await page.locator('[data-action="switch-tab"][data-tab="submissions"]').click();
        await expect(page.locator('[data-workspace-table="submissions"]')).toBeVisible();
        await expect(page.locator('[data-workspace-table="submissions"] [data-table-sort="createdAt"]')).toBeVisible();
        const submissionsResponse = await page.evaluate(() => Espo.Ajax.getRequest('Nexa/forms/submissions', {
            offset: 0, limit: 10, orderBy: 'createdAt', direction: 'desc',
        }));
        expect(submissionsResponse.list).toBeInstanceOf(Array);
        await page.locator('[data-action="switch-tab"][data-tab="forms"]').click();
        await page.getByRole('button', {name: 'New form'}).first().click();
        expect(pageErrors).toEqual([]);
        const dialog = page.getByRole('dialog', {name: 'New form'});
        await expect(dialog).toBeVisible();
        await expect(dialog.locator('[name="name"]')).toBeFocused();
        await expect(dialog.locator('[data-selected-fields] article')).toHaveCount(3);
        await expect(dialog.locator('[name="formTheme"] option')).toHaveCount(9);
        await expect(dialog.getByText('Text displayed on the form', {exact: true})).toBeVisible();
        await expect(dialog.getByText('Text displayed after submission', {exact: true})).toBeVisible();
        await expect(dialog.locator('[name="redirectDelaySeconds"]')).toHaveValue('4');
        await dialog.locator('[data-field-search]').fill('phone');
        await expect(dialog.locator('[data-field-catalog]')).toContainText(/Phone/i);
        await dialog.getByRole('button', {name: 'Cancel'}).click();
        await expect(dialog).toBeHidden();
});
