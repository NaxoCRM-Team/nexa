const {test, expect} = require('@playwright/test');

const baseUrl = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
const userName = process.env.NEXA_LIVE_USERNAME || '';
const password = process.env.NEXA_LIVE_PASSWORD || '';

test('tenant administrator can review responsive event retention controls', async ({page}) => {
    test.setTimeout(60_000);
    test.skip(!baseUrl || !userName || !password, 'Live Nexa credentials were not provided.');

    await page.goto(`${baseUrl}/login/`);
    await page.locator('#field-userName').fill(userName);
    await page.locator('#field-password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout: 30_000});
    const workspaceBase = page.url().match(/^(.*\/w\/[^/]+)/)?.[1];
    expect(workspaceBase).toBeTruthy();

    await page.goto(`${workspaceBase}/NexaTracking`);
    await expect(page.getByRole('heading', {name: 'Tracking & Events'})).toBeVisible();
    await expect(page.locator('[data-tracking-state="ready"]')).toBeVisible();
    await expect(page.getByRole('heading', {name: 'Event retention'})).toBeVisible();
    const form = page.locator('[data-retention-form]');
    await expect(form.locator('[name="identifiedDays"]')).toHaveValue(/\d+/);
    await expect(form.locator('[name="anonymousDays"]')).toHaveValue(/\d+/);
    await expect(form.locator('[name="replayDays"]')).toHaveValue(/\d+/);
    await form.locator('[name="legalHold"]').check();
    await expect(page.locator('[data-retention-reason]')).toBeVisible();
    await expect(form.locator('[name="legalHoldReason"]')).toHaveAttribute('required', '');
    await form.locator('[name="legalHold"]').uncheck();
    await expect(page.locator('[data-retention-reason]')).toBeHidden();

    const overflow = await page.locator('.nexa-tracking-workspace').evaluate(node => ({
        scrollWidth: node.scrollWidth,
        clientWidth: node.clientWidth,
    }));
    expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth + 1);
});
