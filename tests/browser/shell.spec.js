const {test, expect} = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const {pathToFileURL} = require('node:url');
const path = require('node:path');

const fixture = name => pathToFileURL(path.join(__dirname, 'fixtures', name)).href;

for (const [name, url] of [
    ['login', fixture('login.html')],
    ['tenant-a', `${fixture('shell.html')}?tenant=a`],
    ['tenant-b', `${fixture('shell.html')}?tenant=b`],
    ['components', fixture('components.html')],
]) {
    test(`${name} is accessible and visually stable`, async ({page}, testInfo) => {
        await page.goto(url);
        await expect(page.locator('body')).toBeVisible();
        const results = await new AxeBuilder({page}).analyze();
        expect(results.violations).toEqual([]);
        const screenshot = await page.screenshot({animations: 'disabled', fullPage: true});
        expect(screenshot.byteLength).toBeGreaterThan(5000);
        await testInfo.attach(`${name}-${testInfo.project.name}.png`, {body: screenshot, contentType: 'image/png'});
        const viewport = page.viewportSize();
        const body = await page.locator('body').boundingBox();
        expect(body.width).toBeLessThanOrEqual(viewport.width);
    });
}

test('mobile drawer transfers focus and closes with Escape', async ({page}, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile');
    await page.goto(fixture('shell.html'));
    const menu = page.getByRole('button', {name: 'Open workspace navigation'});
    await menu.click();
    await expect(page.getByRole('link', {name: 'Home', exact: true})).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(menu).toBeFocused();
    await expect(menu).toHaveAttribute('aria-expanded', 'false');
});

test('dialog traps focus, closes with Escape and restores its trigger', async ({page}, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop');
    await page.goto(fixture('dialog.html'));
    const trigger = page.getByRole('button', {name: 'Open account dialog'});
    await trigger.click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Shift+Tab');
    const focusRemainsInDialog = await page.evaluate(() => {
        const dialog = document.querySelector('#dialog');
        return document.activeElement === dialog || dialog.contains(document.activeElement);
    });
    expect(focusRemainsInDialog).toBe(true);
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toBeHidden();
    await expect(trigger).toBeFocused();
});

test('login reflows without horizontal overlap at a 200 percent zoom equivalent', async ({page}, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop');
    await page.setViewportSize({width: 320, height: 720});
    await page.goto(fixture('login.html'));
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
    await expect(page.getByRole('button', {name: 'Sign in'})).toBeVisible();
});

test('premium authenticated header exposes the complete workspace toolset', async ({page}, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop');
    await page.goto(fixture('shell.html'));

    await expect(page.getByRole('link', {name: 'Nexa CRM home'})).toBeVisible();
    await expect(page.getByLabel('Current workspace: Atlas Advisory')).toBeVisible();
    await expect(page.getByRole('searchbox', {name: 'Search across this workspace'})).toHaveAttribute(
        'placeholder',
        'Search customers, deals and more'
    );
    await expect(page.getByRole('button', {name: 'Create a new record'})).toBeVisible();
    await expect(page.getByRole('button', {name: 'Open notifications'})).toBeVisible();
    await expect(page.getByRole('button', {name: 'Open account menu for Demo Admin'})).toBeVisible();
});

test('premium header keeps tenant identity and actions separated on small screens', async ({page}, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile');
    await page.goto(`${fixture('shell.html')}?tenant=b`);

    const tenant = page.getByLabel('Current workspace: Beacon Studio');
    const notifications = page.getByRole('button', {name: 'Open notifications'});
    await expect(tenant).toBeVisible();
    await expect(notifications).toBeVisible();

    const [tenantBox, notificationBox] = await Promise.all([tenant.boundingBox(), notifications.boundingBox()]);
    expect(tenantBox.x + tenantBox.width).toBeLessThanOrEqual(notificationBox.x);
});
