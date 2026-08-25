const {test, expect} = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const {pathToFileURL} = require('node:url');
const path = require('node:path');
const fixture = pathToFileURL(path.join(__dirname, 'fixtures', 'customization.html')).href;

test('tenant customization administration is responsive and accessible', async ({page}, testInfo) => {
    await page.goto(fixture);
    await expect(page.getByRole('heading', {name: 'Objects & properties'})).toBeVisible();
    await expect(page.getByRole('button', {name: /Contacts/})).toBeVisible();
    expect((await new AxeBuilder({page}).analyze()).violations).toEqual([]);
    const viewport = page.viewportSize();
    const body = await page.locator('body').boundingBox();
    expect(body.width).toBeLessThanOrEqual(viewport.width);
    const screenshot = await page.screenshot({animations:'disabled',fullPage:true});
    expect(screenshot.byteLength).toBeGreaterThan(5000);
    await testInfo.attach(`customization-${testInfo.project.name}.png`, {body:screenshot,contentType:'image/png'});
});

test('all visual builder areas are keyboard reachable', async ({page}) => {
    await page.goto(fixture);
    await page.getByRole('button', {name: /Contacts/}).focus();
    await page.keyboard.press('Enter');
    for (const name of ['Record layout','Associations','Properties']) {
        const tab = page.getByRole('tab', {name});
        await tab.focus(); await page.keyboard.press('Enter');
        await expect(tab).toHaveAttribute('aria-selected','true');
        await expect(page.getByRole('tabpanel', {name})).toBeVisible();
    }
});

test('property creation explains and generates the internal name', async ({page}) => {
    await page.goto(fixture);
    await page.getByRole('button', {name: /Contacts/}).click();
    await page.getByRole('button', {name: 'Add property'}).click();
    const dialog = page.getByRole('dialog', {name: /Add information to Contacts/});
    await expect(dialog).toBeVisible();
    await dialog.getByLabel('Property name').fill('Customer membership number');
    await dialog.getByText('Advanced settings').click();
    await expect(dialog.getByLabel('Internal name')).toHaveValue('customer_membership_number');
});

test('custom records expose an accessible association picker', async ({page}) => {
    await page.goto(fixture);
    await page.getByRole('button', {name: /Service Contracts/}).click();
    await page.getByRole('tab', {name: 'Records'}).click();
    await page.getByRole('button', {name: 'Manage associations'}).click();
    const dialog = page.getByRole('dialog', {name: /Connect Northbridge Growth Agreement/});
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel('Search accounts')).toBeFocused();
    await expect(dialog.getByRole('button', {name: /Northbridge Solutions Ltd/})).toBeVisible();
    expect((await new AxeBuilder({page}).include('[data-record-association-dialog]').analyze()).violations).toEqual([]);
});
