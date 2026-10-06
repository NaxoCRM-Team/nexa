const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');

test('Approved email test uses a Contact for personalization, never as the destination', async ({page}) => {
    await page.setContent('<main><div id="actions"></div><p role="status"></p></main>');
    await page.evaluate(() => { window.define = (name, deps, factory) => { window.MarketingView = factory(class {}); }; });
    await page.addScriptTag({content: fs.readFileSync(path.join(root, 'espocrm/client/custom/src/views/marketing-email/workspace.js'), 'utf8')});
    await page.evaluate(() => {
        window.requests = [];
        window.Espo = {Ui: {notify() {}, success(text) {document.querySelector('[role="status"]').textContent = text;}, error(text) {document.querySelector('[role="status"]').textContent = text;}}, Ajax: {
            postRequest: async (url, input) => {requests.push({url, input}); return {status: 'submitted', recipient: 'admin@example.test'};},
        }};
        const view = window.view = new MarketingView();
        view.element = document.querySelector('main'); view.workspace = {deliveryEnabled: true, permissions: {approve: true, edit: true}};
        view.escape = value => value; view.confirm = (options, callback) => {window.confirmation = options; callback();}; view.load = async () => {};
        view.createView = (key, name, options, callback) => { window.selector = options; callback({render() {}}); };
        view.listenToOnce = (selector, event, callback) => {window.selectContact = callback;};
        document.querySelector('#actions').innerHTML = view.actions({id: 'approved-email', lifecycleStatus: 'approved'});
        document.querySelector('[data-action="test-email"]').addEventListener('click', event => view.chooseTestContact(event));
    });
    await page.getByRole('button', {name: 'Send test to my email'}).click();
    expect(await page.evaluate(() => confirmation.message)).toContain('your own email address');
    expect(await page.evaluate(() => selector)).toMatchObject({scope: 'Contact', multiple: false, createButton: false});
    await page.evaluate(() => selectContact({id: 'preview-contact'}));
    await expect(page.getByRole('status')).toHaveText('Postal accepted the test for admin@example.test.');
    expect(await page.evaluate(() => requests)).toEqual([{url: 'Nexa/marketing-emails/approved-email/test', input: {contactId: 'preview-contact'}}]);
    await expect(page.getByRole('button', {name: 'Send test to my email'})).toBeEnabled();
    expect(await page.evaluate(() => view.actions({id: 'draft', lifecycleStatus: 'draft'}))).not.toContain('data-action="test-email"');
    await page.evaluate(() => {view.workspace.permissions.approve = false;});
    expect(await page.evaluate(() => view.actions({id: 'approved-email', lifecycleStatus: 'approved'}))).not.toContain('data-action="test-email"');
});
