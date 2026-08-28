const {test, expect} = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const {pathToFileURL} = require('node:url');
const path = require('node:path');
const fixture = pathToFileURL(path.join(__dirname, 'fixtures', 'sales-workspace.html')).href;

test('sales pipeline is responsive, readable and keyboard accessible', async ({page}, testInfo) => {
    await page.goto(fixture);
    await page.locator('.nexa-pipeline-board').evaluate(node => node.setAttribute('tabindex', '0'));
    await expect(page.getByRole('heading', {name: 'Pipelines', exact: true})).toBeVisible();
    await expect(page.getByRole('region', {name: 'Opportunity stages'})).toBeVisible();
    const viewport = page.viewportSize();
    const body = await page.locator('body').boundingBox();
    expect(body.width).toBeLessThanOrEqual(viewport.width);
    expect((await new AxeBuilder({page}).analyze()).violations).toEqual([]);
    const screenshot = await page.screenshot({animations: 'disabled', fullPage: true});
    expect(screenshot.byteLength).toBeGreaterThan(5000);
    await testInfo.attach(`sales-${testInfo.project.name}.png`, {body: screenshot, contentType: 'image/png'});
});

test('sales tabs and actions remain reachable on compact layouts', async ({page}) => {
    await page.goto(fixture);
    for (const label of ['Pipelines', 'Forecasts', 'Products & Quotes']) {
        const link = page.getByRole('link', {name: label});
        await link.focus();
        await expect(link).toBeFocused();
    }
    await page.getByLabel('New pipeline name').focus();
    await expect(page.getByLabel('New pipeline name')).toBeFocused();
});
