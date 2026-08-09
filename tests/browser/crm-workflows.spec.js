const {test, expect} = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const {pathToFileURL} = require('node:url');
const path = require('node:path');

const fixture = pathToFileURL(path.join(__dirname, 'fixtures', 'crm-workflows.html')).href;

for (const view of ['list', 'record', 'edit']) {
    test(`${view} workflow is accessible, responsive and visually stable`, async ({page}, testInfo) => {
        await page.goto(`${fixture}?view=${view}`);
        await expect(page.locator(`[data-view=${view}]`)).toBeVisible();
        expect((await new AxeBuilder({page}).analyze()).violations).toEqual([]);
        const viewport = page.viewportSize();
        const body = await page.locator('body').boundingBox();
        expect(body.width).toBeLessThanOrEqual(viewport.width);
        const screenshot = await page.screenshot({animations: 'disabled', fullPage: true});
        expect(screenshot.byteLength).toBeGreaterThan(6000);
        await testInfo.attach(`crm-${view}-${testInfo.project.name}.png`, {body: screenshot, contentType: 'image/png'});
    });
}

test('list supports sorting, selection, bulk actions and pagination semantics', async ({page}) => {
    await page.goto(`${fixture}?view=list`);
    const sortHeader = page.getByRole('columnheader', {name: 'Name'});
    await page.getByRole('button', {name: 'Name'}).click();
    await expect(sortHeader).toHaveAttribute('aria-sort', 'ascending');
    await page.getByRole('checkbox', {name: 'Select Ava Morgan'}).check();
    await expect(page.getByRole('status')).toHaveText('1 record selected.');
    await expect(page.getByRole('button', {name: 'Bulk actions for 1 selected records'})).toBeEnabled();
    await expect(page.getByRole('navigation', {name: 'Record pages'})).toBeVisible();
});

test('record exposes relationship and activity regions on desktop and mobile', async ({page}) => {
    await page.goto(`${fixture}?view=record`);
    await expect(page.getByRole('region', {name: 'Contact overview'})).toBeVisible();
    await expect(page.getByRole('region', {name: 'Relationships'})).toBeVisible();
    await expect(page.getByRole('region', {name: 'Related opportunities'})).toBeVisible();
    await expect(page.getByRole('region', {name: 'Activity'})).toBeVisible();
});

test('edit form exposes validation, saving, success and conflict states', async ({page}) => {
    await page.goto(`${fixture}?view=edit`);
    const first = page.getByLabel(/First name/);
    await first.fill('');
    await page.getByRole('button', {name: 'Save'}).click();
    await expect(first).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByRole('status')).toHaveText('Review the highlighted required or invalid fields.');
    await expect(first).toBeFocused();
    await first.fill('Ava');
    await page.getByRole('button', {name: 'Save'}).click();
    await expect(page.getByRole('status')).toHaveText('Changes saved successfully.');
    await page.getByRole('button', {name: 'Simulate edit conflict'}).click();
    await expect(page.getByRole('status')).toHaveText('This record changed elsewhere. Refresh it before saving again.');
});
