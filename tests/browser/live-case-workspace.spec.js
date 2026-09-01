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

test('native Case list, create form and service workspace render', async ({page}, testInfo) => {
    test.setTimeout(90_000);
    test.skip(!baseUrl || !userName || !password, 'Live Nexa credentials were not provided.');
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await login(page);
    await page.getByRole('button', {name: /Service/}).click();
    await expect(page.getByRole('link', {name: /Cases/}).first()).toBeVisible();
    await page.goto(`${baseUrl}/w/isolation-alpha/Case`);
    await expect(page.locator('.nexa-case-list-page')).toBeVisible({timeout:30_000});
    await expect(page.locator('.nexa-case-live-search input[data-name="textFilter"]')).toBeVisible();
    await page.goto(`${baseUrl}/w/isolation-alpha/Case/create`);
    await expect(page.locator('body.nexa-case-record-page')).toBeVisible({timeout:30_000});
    const result = await page.evaluate(async () => {
        const record = await Espo.Ajax.postRequest('Case', {name:`Case browser verification ${Date.now()}`,priority:'Normal',category:'Technical',status:'New'});
        return {id:record.id,slaStatus:record.slaStatus,resolutionDueAt:record.resolutionDueAt};
    });
    expect(result.slaStatus).toBe('Running'); expect(result.resolutionDueAt).toBeTruthy();
    await page.goto(`${baseUrl}/w/isolation-alpha/Case/view/${result.id}`);
    await expect(page.locator('.nexa-case-workspace')).toBeVisible({timeout:30_000});
    await expect(page.locator('[data-case-metric="slaStatus"]')).toHaveText('Running');
    await page.screenshot({path:testInfo.outputPath(`case-workspace-${testInfo.project.name}.png`),animations:'disabled',fullPage:true});
    await page.evaluate(async id => Espo.Ajax.deleteRequest(`Case/${id}`), result.id);
    expect(errors).toEqual([]);
});
