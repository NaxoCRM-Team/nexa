const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const source = name => fs.readFileSync(path.join(root, 'espocrm/client/custom/src/views', name, 'record/detail-workspace.js'), 'utf8');

test('Email preview updates content without executing scripts or requesting remote assets', async ({page}, testInfo) => {
    const template = fs.readFileSync(path.join(root, 'espocrm/client/custom/res/templates/marketing-email/workspace.tpl'), 'utf8');
    const css = fs.readFileSync(path.join(root, 'espocrm/client/custom/css/marketing-email-workspace.css'), 'utf8');
    let remoteRequests = 0;
    await page.route('https://preview-attacker.example/**', route => { remoteRequests++; return route.abort(); });
    await page.setContent(`<meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;font:14px Arial}.form-control{box-sizing:border-box;width:100%}${css}</style>${template}`);
    await page.evaluate(() => { window.define = (name, deps, factory) => { window.MarketingWorkspace = factory(class {}); }; });
    await page.addScriptTag({content: fs.readFileSync(path.join(root, 'espocrm/client/custom/src/views/marketing-email/workspace.js'), 'utf8')});
    await page.evaluate(() => {
        window.previewView = new window.MarketingWorkspace();
        previewView.element = document.querySelector('main');
        const form = previewView.element.querySelector('[data-email-form]');
        form.elements.subject.value = 'Customer newsletter';
        form.elements.preheader.value = 'Your monthly update';
        form.elements.bodyHtml.value = '<h1>Customer news</h1><p>Welcome back.</p><script>parent.previewCompromised=true</script><img src="https://preview-attacker.example/tracker"><form action="https://preview-attacker.example/submit"><button>Submit</button></form>';
        const editor = document.querySelector('[data-email-editor]'); editor.hidden = false; editor.style.display = 'grid';
        previewView.renderContentPreview();
        form.addEventListener('input', () => previewView.renderContentPreview());
        form.addEventListener('change', () => previewView.renderContentPreview());
    });
    const frame = page.frameLocator('iframe[title="Email content preview"]');
    await expect(frame.getByRole('heading', {name: 'Customer news'})).toBeVisible();
    await expect(page.locator('[data-preview-subject]')).toHaveText('Customer newsletter');
    expect(await page.evaluate(() => window.previewCompromised)).toBeUndefined();
    await page.locator('[name="bodyHtml"]').fill('<h2>Updated newsletter</h2><p>New content.</p>');
    await expect(frame.getByRole('heading', {name: 'Updated newsletter'})).toBeVisible();
    await page.locator('[data-preview-width]').selectOption('mobile');
    const bounds = await page.locator('iframe[title="Email content preview"]').boundingBox();
    expect(bounds.width).toBeLessThanOrEqual(377);
    expect(remoteRequests).toBe(0);
    await page.evaluate(async () => {
        window.Espo = {Ui: {notify() {}, error(message) {throw new Error(message);}}, Ajax: {postRequest: async (url, input) => {
            if (url !== 'Nexa/marketing-emails/preview' || input.contactId !== 'allowed-contact') throw new Error('Wrong preview endpoint.');
            return {subject: 'Hello Preview', bodyHtml: '<p>Welcome Preview Customer</p><script>parent.previewCompromised=true</script>'};
        }}};
        await previewView.previewContact({id: 'allowed-contact', get: () => 'Preview Customer'});
    });
    await expect(page.locator('[data-preview-recipient]')).toHaveText('Preview for Preview Customer');
    await expect(frame.getByText('Welcome Preview Customer')).toBeVisible();
    expect(await page.evaluate(() => window.previewCompromised)).toBeUndefined();
    await page.locator('[name="bodyHtml"]').fill('<h2>Edited after personalization</h2>');
    await expect(frame.getByRole('heading', {name: 'Edited after personalization'})).toBeVisible();
    await expect(page.locator('[data-preview-recipient]')).toBeEmpty();
    await testInfo.attach('marketing-content-preview.png', {body: await page.screenshot(), contentType: 'image/png'});
});

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
    await page.evaluate(() => {
        reportView.workspace.permissions.approve = true;
        reportView.workspace.emails[0].lifecycleStatus = 'scheduled';
        reportView.renderEmails();
    });
    await expect(page.getByRole('button', {name: 'Pause delivery', exact: true})).toHaveCount(1);
    await page.evaluate(() => { reportView.workspace.emails[0].lifecycleStatus = 'paused'; reportView.renderEmails(); });
    await expect(page.getByRole('button', {name: 'Resume delivery', exact: true})).toHaveCount(1);
    await expect(page.getByRole('button', {name: 'Pause delivery', exact: true})).toHaveCount(0);
    await page.evaluate(() => { reportView.workspace.permissions.approve = false; reportView.renderEmails(); });
    await expect(page.locator('[data-action="control-delivery"]')).toHaveCount(0);
    await page.evaluate(async () => {
        window.Espo = {Ui: {success() {}, error(message) {throw new Error(message);}}, Ajax: {
            getRequest: async url => url.endsWith('/versions') ? {currentVersion: 2, canRestore: true, versions: [
                {version: 2, createdAt: '2026-10-05 10:00:00', createdByName: 'Tenant author'},
                {version: 1, createdAt: '2026-10-04 10:00:00', createdByName: 'Tenant author'},
            ]} : {subject: 'Historical newsletter', bodyHtml: '<p>Saved historical content</p><script>parent.historyCompromised=true</script>'},
            postRequest: async url => { window.restoredVersionUrl = url; },
        }};
        reportView.confirm = (options, callback) => callback();
        reportView.load = async () => {};
        await reportView.openVersionHistory({currentTarget: reportView.element.querySelector('[data-action="version-history"]')});
        const modal = reportView.element.querySelector('[data-email-history]');
        modal.querySelector('select').addEventListener('change', () => reportView.loadHistoryVersion());
        modal.querySelector('[data-action="restore-version"]').addEventListener('click', () => reportView.restoreHistoryVersion());
    });
    const history = page.getByRole('dialog', {name: 'Content versions'});
    await expect(history).toBeVisible();
    await expect(history.getByRole('button', {name: 'Restore as draft'})).toBeDisabled();
    await history.getByRole('combobox', {name: 'Content version'}).selectOption('1');
    await expect(history.getByRole('button', {name: 'Restore as draft'})).toBeEnabled();
    await expect(page.frameLocator('iframe[title="Content version preview"]').getByText('Saved historical content')).toBeVisible();
    expect(await page.evaluate(() => window.historyCompromised)).toBeUndefined();
    await testInfo.attach('email-content-history.png', {body: await page.screenshot(), contentType: 'image/png'});
    await history.getByRole('button', {name: 'Restore as draft'}).click();
    await expect(history).toBeHidden();
    expect(await page.evaluate(() => window.restoredVersionUrl)).toBe('Nexa/marketing-emails/report/versions/1/restore');
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
