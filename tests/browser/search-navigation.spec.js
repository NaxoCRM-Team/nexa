const {test, expect} = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const {pathToFileURL} = require('node:url');
const path = require('node:path');

const fixture = pathToFileURL(path.join(__dirname, 'fixtures', 'search-navigation.html')).href;

test('search suggestions support keyboard selection and tenant-scoped recent history', async ({page}) => {
    await page.goto(`${fixture}?tenant=tenant-alpha&user=member-7`);
    const search = page.getByRole('combobox', {name: 'Search across this workspace'});
    await search.fill('Acme');
    await expect(page.getByRole('option', {name: 'Search workspace for Acme'})).toBeVisible();
    await search.press('ArrowDown');
    await expect(search).toHaveAttribute('aria-activedescendant', 'option-0');
    await search.press('Enter');
    await expect(page.getByRole('status')).toHaveText('Results for Acme');

    await page.goto(`${fixture}?tenant=tenant-beta&user=member-7`);
    await search.focus();
    await expect(page.getByRole('option', {name: 'Acme'})).toHaveCount(0);

    await page.goto(`${fixture}?tenant=tenant-alpha&user=member-7`);
    await search.focus();
    await expect(page.getByRole('option', {name: 'Acme'})).toBeVisible();
});

test('module suggestions and global shortcut remain keyboard operable', async ({page}) => {
    await page.goto(fixture);
    await page.keyboard.press('Control+K');
    const search = page.getByRole('combobox', {name: 'Search across this workspace'});
    await expect(search).toBeFocused();
    await search.fill('cont');
    await expect(page.getByRole('option', {name: 'Contacts'})).toBeVisible();
    await search.press('Escape');
    await expect(search).toHaveAttribute('aria-expanded', 'false');
});

test('search and scrollable navigation are accessible and responsive', async ({page}, testInfo) => {
    await page.goto(fixture);
    expect((await new AxeBuilder({page}).analyze()).violations).toEqual([]);
    const viewport = page.viewportSize();
    const body = await page.locator('body').boundingBox();
    expect(body.width).toBeLessThanOrEqual(viewport.width);

    if (testInfo.project.name === 'mobile') {
        const menu = page.getByRole('button', {name: 'Open workspace navigation'});
        await menu.click();
        await expect(page.getByRole('link', {name: 'Home'})).toBeFocused();
        await page.keyboard.press('ArrowDown');
        await expect(page.getByRole('link', {name: 'Accounts'})).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(menu).toBeFocused();
    }

    const screenshot = await page.screenshot({animations: 'disabled', fullPage: true});
    expect(screenshot.byteLength).toBeGreaterThan(5000);
});
