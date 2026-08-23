const {test, expect} = require('@playwright/test');

const baseUrl = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
const userName = process.env.NEXA_LIVE_USERNAME || '';
const password = process.env.NEXA_LIVE_PASSWORD || '';

test('authenticated workspace links retain their route in a new tab', async ({page, context}) => {
    test.setTimeout(60_000);
    test.skip(!baseUrl || !userName || !password, 'Live Nexa credentials were not provided.');

    await page.goto(`${baseUrl}/login/`);
    await page.locator('#field-userName').fill(userName);
    await page.locator('#field-password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout: 30_000});

    // DOM activation avoids viewport chrome intercepting the synthetic click
    // while still exercising the same delegated route-qualification handler.
    await page.locator('li[data-name="nexa-crm"] > a').evaluate(element => element.click());
    const accountLink = page.locator('li[data-name="Account"] > a:visible').first();
    await accountLink.evaluate(element => element.dispatchEvent(new PointerEvent('pointerover', {bubbles: true})));
    const href = await accountLink.getAttribute('href');
    expect(href).toMatch(/\/w\/[^/]+\/Account$/);

    const secondTab = await context.newPage();
    await secondTab.goto(new URL(href, `${baseUrl}/`).href);
    await secondTab.waitForURL(/\/w\/[^/]+\/Account$/, {timeout: 30_000});
    await expect(secondTab.locator('body')).toHaveClass(/has-navbar/);
    await expect(secondTab.locator('.landing-hero')).toHaveCount(0);
});
