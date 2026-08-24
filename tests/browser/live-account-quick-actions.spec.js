const {test, expect} = require('@playwright/test');

const baseUrl = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
const userName = process.env.NEXA_LIVE_USERNAME || '';
const password = process.env.NEXA_LIVE_PASSWORD || '';
let temporaryAccountIdForCleanup = null;

test.afterEach(async ({page}) => {
    if (!temporaryAccountIdForCleanup) return;
    try {
        await page.evaluate(id => Espo.Ajax.deleteRequest(`Account/${id}`), temporaryAccountIdForCleanup);
    } finally {
        temporaryAccountIdForCleanup = null;
    }
});

test('Account quick actions are accessible and company scoped', async ({page}) => {
    test.skip(!baseUrl || !userName || !password, 'Live Nexa credentials were not provided.');

    await page.goto(`${baseUrl}/login/`);
    await page.locator('#field-userName').fill(userName);
    await page.locator('#field-password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout: 30_000});

    const tenantKey = new URL(page.url()).pathname.match(/\/w\/([^/]+)/)?.[1];
    let account = await page.evaluate(() => Espo.Ajax.getRequest('Account', {
        select: 'id,name', maxSize: 1, orderBy: 'createdAt', order: 'desc',
    }));
    let temporaryAccountId = null;
    if (!account?.list?.length) {
        const temporaryAccount = await page.evaluate(() => Espo.Ajax.postRequest('Account', {
            name: `Filter contract ${Date.now()}`,
        }));
        temporaryAccountId = temporaryAccount.id;
        temporaryAccountIdForCleanup = temporaryAccount.id;
        account = {list: [temporaryAccount]};
    }

    await page.goto(`${baseUrl}/w/${tenantKey}/Account/view/${account.list[0].id}`);
    const workspace = page.locator('[data-nexa-company-workspace]');
    await expect(workspace).toBeVisible({timeout: 30_000});
    await expect(workspace.getByRole('toolbar', {name: 'Company actions'})).toBeVisible();
    await expect(workspace.getByRole('button', {name: 'Create a company note'})).toBeVisible();
    await expect(workspace.getByRole('button', {name: 'Create a company task'})).toBeVisible();
    await expect(workspace.getByRole('button', {name: 'Schedule a company meeting'})).toBeVisible();

    await workspace.getByRole('tab', {name: 'Meetings'}).click();
    const meetings = workspace.locator('[data-nexa-company-panel="meetings"]');
    await meetings.getByRole('button', {name: 'Filters'}).click();
    await meetings.locator('[data-nexa-engagement-choice-toggle="meetings:meetingOutcome"]').click();
    await meetings.getByRole('checkbox', {name: 'Scheduled', exact: true}).check();
    await meetings.getByRole('checkbox', {name: 'Completed', exact: true}).check();
    await expect(meetings.locator('[data-nexa-engagement-choice-summary]')).toHaveText('Meeting outcome: 2 selected');

    await workspace.getByRole('tab', {name: 'Calls'}).click();
    const calls = workspace.locator('[data-nexa-company-panel="calls"]');
    await calls.getByRole('button', {name: 'Filters'}).click();
    await calls.locator('[data-nexa-engagement-choice-toggle="calls:callOutcome"]').click();
    await expect(calls.getByRole('checkbox', {name: 'Left voicemail', exact: true})).toBeVisible();
    await calls.locator('[data-nexa-engagement-choice-toggle="calls:callDirection"]').click();
    await calls.getByRole('checkbox', {name: 'Inbound', exact: true}).check();

    await workspace.getByRole('tab', {name: 'Email'}).click();
    const emails = workspace.locator('[data-nexa-company-panel="emails"]');
    await emails.getByRole('button', {name: 'Filters'}).click();
    await emails.locator('[data-nexa-engagement-choice-toggle="emails:emailDirection"]').click();
    await expect(emails.getByRole('checkbox', {name: 'Forwarded', exact: true})).toBeVisible();
    await expect(emails.getByRole('checkbox', {name: 'Unassigned', exact: true})).toBeVisible();

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

    if (temporaryAccountId) {
        await page.evaluate(id => Espo.Ajax.deleteRequest(`Account/${id}`), temporaryAccountId);
        temporaryAccountIdForCleanup = null;
    }
});
