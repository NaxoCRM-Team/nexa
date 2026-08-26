const {test, expect} = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const {pathToFileURL} = require('node:url');
const path = require('node:path');

const fixture = pathToFileURL(path.join(__dirname, 'fixtures', 'lead-workflows.html')).href;

for (const view of ['list', 'record', 'edit']) {
    test(`Lead ${view} is responsive and accessible`, async ({page}, testInfo) => {
        await page.goto(`${fixture}?view=${view}`);
        await expect(page.locator(`[data-view=${view}]`)).toBeVisible();
        expect((await new AxeBuilder({page}).analyze()).violations).toEqual([]);
        const body = await page.locator('body').boundingBox();
        expect(body.width).toBeLessThanOrEqual(page.viewportSize().width);
        const screenshot = await page.screenshot({animations: 'disabled', fullPage: true});
        expect(screenshot.byteLength).toBeGreaterThan(5000);
        await testInfo.attach(`lead-${view}-${testInfo.project.name}.png`, {body: screenshot, contentType: 'image/png'});
    });
}

test('Lead conversion action remains prominent and qualification is visible', async ({page}) => {
    await page.goto(`${fixture}?view=record`);
    await expect(page.getByRole('button', {name: 'Convert Lead'})).toBeVisible();
    await expect(page.getByRole('region', {name: 'Conversion readiness'})).toContainText('Marketing Qualified Lead');
});

test('Lead create form reports its successful state', async ({page}) => {
    await page.goto(`${fixture}?view=edit`);
    await page.getByRole('button', {name: 'Save'}).click();
    await expect(page.getByRole('status')).toHaveText('Lead saved successfully.');
});
