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

test('dashboard workspaces and operational widgets are complete', async ({page}) => {
    await page.goto(fixture);
    await expect(page.getByRole('tab')).toHaveCount(3);
    await expect(page.locator('.dashlet-container')).toHaveCount(4);
    await expect(page.locator('.dashlet-container .menu-button')).toHaveCount(4);
    await expect(page.getByRole('heading', {name: 'My Activities'})).toBeVisible();
    await expect(page.getByRole('heading', {name: 'Sales Pipeline'})).toBeVisible();
    await page.getByRole('tab', {name: 'Sales'}).click();
    await expect(page.getByRole('tab', {name: 'Sales'})).toHaveAttribute('aria-selected', 'true');
});

test('dashboard widgets use aligned rows and stable card dimensions', async ({page}) => {
    await page.goto(fixture);
    const layout = await page.locator('.fixture-widgets').evaluate(element => ({
        columns: getComputedStyle(element).gridTemplateColumns.split(' ').length,
        boxes: [...element.querySelectorAll('.dashlet-container .panel')].map(panel => {
            const box = panel.getBoundingClientRect();

            return {width: Math.round(box.width), height: Math.round(box.height)};
        }),
    }));

    expect(layout.boxes).toHaveLength(4);
    expect(new Set(layout.boxes.map(box => box.width)).size).toBe(1);
    expect(layout.boxes.every(box => box.width > 220 && box.height >= 220)).toBe(true);

    if (layout.columns > 1) {
        for (let index = 0; index < layout.boxes.length; index += layout.columns) {
            const row = layout.boxes.slice(index, index + layout.columns);

            expect(new Set(row.map(box => box.height)).size).toBe(1);
        }
    }
});

test('dashboard fills the available authenticated workspace width', async ({page}) => {
    await page.goto(fixture);

    const widths = await page.locator('.nexa-dashboard').evaluate(element => ({
        dashboard: element.getBoundingClientRect().width,
        parent: element.parentElement.getBoundingClientRect().width,
        left: element.getBoundingClientRect().left,
        parentLeft: element.parentElement.getBoundingClientRect().left,
    }));

    expect(Math.abs(widths.parent - widths.dashboard)).toBeLessThanOrEqual(1);
    expect(Math.abs(widths.parentLeft - widths.left)).toBeLessThanOrEqual(1);
});
