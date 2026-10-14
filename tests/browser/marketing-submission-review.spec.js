const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');

test('Held submission review validates evidence, stays in the modal on error and restores focus', async ({page}, testInfo) => {
    const css = fs.readFileSync(path.join(root, 'espocrm/client/custom/css/marketing-email-workspace.css'), 'utf8');
    await page.setContent(`<meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;font:14px Arial}.form-control{box-sizing:border-box;width:100%;padding:8px}.btn{padding:8px;border:1px solid #789;background:#fff}.btn-primary{background:#087763;color:#fff}${css}</style><main><button id="review" data-id="email">Review held submissions</button></main>`);
    await page.evaluate(() => { window.define = (name, deps, factory) => { window.ReviewView = factory(class {once() {}}); }; });
    await page.addScriptTag({content: fs.readFileSync(path.join(root, 'espocrm/client/custom/src/views/marketing-email/workspace.js'), 'utf8')});
    await page.evaluate(async () => {
        window.calls = [];
        window.Espo = {Ui: {success() {}, notify() {}, error(message) {throw new Error(message);}}, Ajax: {
            getRequest: async () => ({items: [{id: 'held', recipient: 'customer@example.test', createdAt: '2026-10-05 12:00:00'}]}),
            postRequest: async (url, input) => { calls.push({url, input}); if (calls.length === 1) throw new Error('Provider message ID conflicts with existing evidence.'); },
        }};
        window.view = new ReviewView(); view.element = document.querySelector('main');
        view.load = async () => {};
        await view.openSubmissionReview({currentTarget: document.querySelector('#review')});
        const form = document.querySelector('form');
        form.addEventListener('change', () => view.updateReviewDecision());
        form.addEventListener('submit', event => view.resolveSubmission(event));
        document.querySelectorAll('[data-action="close-submission-review"]').forEach(button => button.addEventListener('click', () => view.closeSubmissionReview()));
    });
    const dialog = page.getByRole('dialog', {name: 'Review held submission'});
    await expect(dialog).toBeVisible();
    await expect(page.getByLabel('Confirmed Postal message ID')).toBeDisabled();
    await page.getByLabel('Provider evidence outcome').selectOption('accepted');
    await expect(page.getByLabel('Confirmed Postal message ID')).toBeEnabled();
    await page.getByRole('button', {name: 'Record resolution'}).click();
    expect(await page.evaluate(() => calls.length)).toBe(0);
    await page.getByLabel('Confirmed Postal message ID').fill('postal-123');
    await page.getByLabel('Provider evidence note').fill('Postal logs confirm this exact recipient and queue item.');
    await page.getByRole('button', {name: 'Record resolution'}).click();
    await expect(page.getByRole('alert')).toHaveText('Provider message ID conflicts with existing evidence.');
    await expect(dialog).toBeVisible();
    await expect(page.getByRole('button', {name: 'Record resolution'})).toBeEnabled();
    const bounds = await dialog.boundingBox();
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(page.viewportSize().width + 1);
    const screenshot = testInfo.outputPath('submission-review.png');
    await page.screenshot({path: screenshot});
    await testInfo.attach('submission-review.png', {path: screenshot, contentType: 'image/png'});
    await page.getByRole('button', {name: 'Record resolution'}).click();
    await expect(dialog).toHaveCount(0);
    await expect(page.locator('#review')).toBeFocused();
    const request = await page.evaluate(() => calls[1]);
    expect(request.url).toBe('Nexa/marketing-emails/email/submission-review');
    expect(request.input).toMatchObject({messageId: 'held', decision: 'accepted', providerMessageId: 'postal-123'});
});
