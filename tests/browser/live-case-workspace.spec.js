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
    if (testInfo.project.name !== 'mobile') {
        await page.getByRole('button', {name: /Service/}).click();
        await expect(page.getByRole('link', {name: /Cases/}).first()).toBeVisible();
    }
    await page.goto(`${baseUrl}/w/isolation-alpha/Case`);
    await expect(page.locator('.nexa-case-list-page')).toBeVisible({timeout:30_000});
    await expect(page.locator('.nexa-case-live-search input[data-name="textFilter"]')).toBeVisible();
    const list = page.locator('.nexa-case-scroll-list');
    await expect(list).toBeVisible();
    await expect(page.locator('.nexa-case-table thead th[data-name="number"]')).toContainText('Case ID');
    await expect(page.locator('.nexa-case-table .nexa-col-resizer').first()).toBeAttached();
    await expect(page.locator('.nexa-case-list-page .pagination')).toBeHidden();
    const scrolling = await list.evaluate(element => ({
        overflowX: getComputedStyle(element).overflowX,
        overflowY: getComputedStyle(element).overflowY,
        horizontal: element.scrollWidth > element.clientWidth,
    }));
    expect(scrolling.overflowX).toBe('auto');
    expect(scrolling.overflowY).toBe('auto');
    expect(scrolling.horizontal).toBeTruthy();
    if (testInfo.project.name !== 'mobile') {
        const settingsBox = await page.locator('.nexa-list-toolbar .settings-container').boundingBox();
        const totalBox = await page.locator('.nexa-list-toolbar .total-count').boundingBox();
        expect(settingsBox).toBeTruthy();
        expect(totalBox).toBeTruthy();
        expect(Math.abs(totalBox.x - (settingsBox.x + settingsBox.width))).toBeLessThanOrEqual(12);
        const subjectHeader = page.locator('.nexa-case-table thead th[data-name="name"]');
        const resizeHandle = subjectHeader.locator('.nexa-col-resizer');
        const widthBefore = await subjectHeader.evaluate(element => element.getBoundingClientRect().width);
        const handleBox = await resizeHandle.boundingBox();
        expect(handleBox).toBeTruthy();
        await page.mouse.move(handleBox.x + handleBox.width / 2, handleBox.y + handleBox.height / 2);
        await page.mouse.down();
        await page.mouse.move(handleBox.x + 80, handleBox.y + handleBox.height / 2, {steps: 5});
        await page.mouse.up();
        const widthAfter = await subjectHeader.evaluate(element => element.getBoundingClientRect().width);
        expect(widthAfter).toBeGreaterThan(widthBefore + 50);
    }
    await list.evaluate(element => { element.scrollLeft = 120; });
    expect(await list.evaluate(element => element.scrollLeft)).toBeGreaterThan(0);
    await page.screenshot({path:testInfo.outputPath(`case-list-${testInfo.project.name}.png`),animations:'disabled',fullPage:true});
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
