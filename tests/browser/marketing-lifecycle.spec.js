const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const source = name => fs.readFileSync(path.join(root, 'espocrm/client/custom/src/views', name, 'record/detail-workspace.js'), 'utf8');

test('Marketing workspace reports usage and delivery outcomes with keyboard dismissal', async ({page}, testInfo) => {
    const template = fs.readFileSync(path.join(root, 'espocrm/client/custom/res/templates/marketing-email/workspace.tpl'), 'utf8');
    const css = fs.readFileSync(path.join(root, 'espocrm/client/custom/css/marketing-email-workspace.css'), 'utf8');
    await page.setContent(`<meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:16px;font:14px system-ui}.btn{padding:8px;color:#17211f;border:1px solid #8ca098;background:#fff}.btn-primary{background:#087763;color:#fff}.form-control{box-sizing:border-box;padding:8px;width:100%}table{border-collapse:collapse;width:100%}td,th{text-align:left;padding:10px}[hidden]{display:none!important}${css}</style>${template}`);
    await page.evaluate(() => { window.define = (name, deps, factory) => { window.MarketingWorkspace = factory(class {}); }; });
    await page.addScriptTag({content: fs.readFileSync(path.join(root, 'espocrm/client/custom/src/views/marketing-email/workspace.js'), 'utf8')});
    await page.evaluate(() => {
        const view = new window.MarketingWorkspace();
        window.reportView = view;
        view.element = document.querySelector('main');
        view.workspace = {
            summary: {total: 1}, deliveryEnabled: true, permissions: {edit: true},
            usage: {month: '2026-10-01', limit: 100, reserved: 10, remaining: 90},
            emails: [{id: 'report', name: 'Customer update', subject: 'October news', lifecycleStatus: 'completed', campaignName: 'Customer campaign', deliveryMetrics: {submitted: 10, delivered: 8, bounced: 1, failed: 1, opened: 5, clicked: 3, unsubscribed: 1}}],
        };
        view.element.querySelectorAll('[data-email-state]').forEach(node => node.hidden = node.dataset.emailState !== 'ready');
        view.element.querySelectorAll('.nexa-marketing-email-editor').forEach(node => node.style.display = 'none');
        view.renderSummary(); view.renderEmails();
        const button = view.element.querySelector('[data-action="email-results"]');
        button.addEventListener('click', () => view.openResults({currentTarget: button}));
        view.element.querySelector('[data-action="close-results"]').addEventListener('click', () => view.closeResults());
    });
    await expect(page.locator('[data-usage-remaining]')).toHaveText('90');
    await expect(page.locator('[data-delivery-title]')).toHaveText('Delivery is ready');
    const trigger = page.getByRole('button', {name: 'Delivery results', exact: true});
    await trigger.click();
    const dialog = page.getByRole('dialog', {name: 'Customer update'});
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-result-delivered]')).toHaveText('8');
    await expect(dialog.locator('[data-result-opened]')).toHaveText('5');
    await expect(dialog.locator('[data-result-unsubscribed]')).toHaveText('1');
    const viewport = page.viewportSize();
    const bounds = await dialog.boundingBox();
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(viewport.width + 1);
    await testInfo.attach('marketing-delivery-results.png', {body: await page.screenshot(), contentType: 'image/png'});
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
});

test('Contact activity displays marketing delivery and unsubscribe history', async ({page}) => {
    await page.setContent('<main></main>');
    await page.evaluate(() => {
        window.define = (name, deps, factory) => {
            window.ContactWorkspace = factory(class {}, ...deps.slice(1).map(() => ({})));
        };
        window.Espo = {Ajax: {getRequest: async () => ({timeline: [
            {id: 'delivered', source_entity_type: 'MarketingDeliveryEvent', event_type: 'email.delivered', source_occurred_at: '2026-10-05 10:00:00', metadata: {eventCategory: 'email'}},
            {id: 'unsubscribed', source_entity_type: 'MarketingDeliveryEvent', event_type: 'email.unsubscribed', source_occurred_at: '2026-10-05 10:01:00', metadata: {eventCategory: 'email'}},
        ]})}};
    });
    await page.addScriptTag({content: source('contact')});
    const activities = await page.evaluate(async () => {
        const view = new window.ContactWorkspace();
        view.element = document.querySelector('main');
        view.model = {id: 'contact'};
        view.contactNoteDate = value => new Date(value.replace(' ', 'T') + 'Z');
        view.renderContactActivities = () => {};
        await view.loadContactBehaviorTimeline(view.element);
        return view.contactBehaviorActivities;
    });
    expect(activities.map(item => item.title)).toEqual(['Marketing email delivered', 'Unsubscribed from marketing email']);
    expect(activities.every(item => item.type === 'marketing')).toBe(true);
});

test('Account marketing activity expands without editable record actions', async ({page}, testInfo) => {
    await page.setContent('<main style="max-width:700px;padding:16px;font:16px system-ui"></main>');
    await page.evaluate(() => {
        window.define = (name, deps, factory) => {
            window.AccountWorkspace = factory(class {}, {}, {hydrate() {}}, {}, {});
        };
    });
    await page.addScriptTag({content: source('account')});
    await page.evaluate(async () => {
        const view = new window.AccountWorkspace();
        view.model = {id: 'account'};
        view.getHelper = () => ({sanitizeHtml: value => value});
        view.getDateTime = () => ({toDisplay: value => value});
        view.getModelFactory = () => ({create() {throw new Error('Audit events must not create editable entity models.');}});
        view.collapsedAccountEngagementIds = new Set();
        const record = {id: 'delivery', _entityType: 'MarketingDeliveryEvent', name: 'Marketing email delivered', status: 'email.delivered', createdAt: '2026-10-05 10:00:00'};
        await view.prepareEngagementPermissions([record], record._entityType);
        document.querySelector('main').append(view.engagementRecord(record));
    });
    await expect(page.getByText('Marketing email delivered', {exact: true}).first()).toBeVisible();
    await expect(page.getByRole('button', {name: /Actions/})).toHaveCount(0);
    await expect(page.getByText('View record')).toHaveCount(0);
    await expect(page.getByRole('button', {name: 'Add comment'})).toHaveCount(0);
    await expect(page.locator('time')).toContainText('2026');
    const width = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(width).toBeLessThanOrEqual(2);
    await testInfo.attach('marketing-activity.png', {body: await page.screenshot(), contentType: 'image/png'});
});
