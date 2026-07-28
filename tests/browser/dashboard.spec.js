const {test, expect} = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const {pathToFileURL} = require('node:url');
const path = require('node:path');

const fixture = pathToFileURL(path.join(__dirname, 'fixtures', 'dashboard.html')).href;

for (const state of ['ready', 'loading', 'empty', 'error', 'denied']) {
    test(`tenant dashboard ${state} state is accessible and responsive`, async ({page}, testInfo) => {
        await page.goto(`${fixture}?state=${state}`);
        await expect(page.getByRole('heading', {name: 'Good to see you, Demo'})).toBeVisible();
        expect((await new AxeBuilder({page}).analyze()).violations).toEqual([]);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow).toBeLessThanOrEqual(1);
        const screenshot = await page.screenshot({animations: 'disabled', fullPage: true});
        expect(screenshot.byteLength).toBeGreaterThan(5000);
        await testInfo.attach(`dashboard-${state}-${testInfo.project.name}.png`, {body: screenshot, contentType: 'image/png'});
    });
}

test('dashboard period filter and tenant identity are keyboard accessible', async ({page}) => {
    await page.goto(fixture);
    const period = page.getByLabel('Reporting period');
    await period.focus();
    await period.selectOption({label: 'Last 90 days'});
    await expect(period).toHaveValue('Last 90 days');
    await expect(page.getByText('Atlas Advisory workspace')).toBeVisible();
    await expect(page.locator('.nexa-metric-grid')).toBeVisible();
});
