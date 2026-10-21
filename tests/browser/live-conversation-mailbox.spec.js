const {test, expect} = require('@playwright/test');
const {spawn} = require('node:child_process');
const {randomUUID, randomBytes} = require('node:crypto');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const base = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
test.use({trace: 'off', screenshot: 'off', actionTimeout: 15000, navigationTimeout: 30000});
let cleanupTenants;
test.afterEach(async ({}, info) => {
    info.setTimeout(60000);
    if (cleanupTenants) { await fixture('cleanup', cleanupTenants); cleanupTenants = null; }
});
function fixture(command, tenants) {
    return new Promise((resolve, reject) => {
        const child = spawn(process.env.NEXA_PHP_PATH || 'php', [path.join(root, 'tests/tenant/AutomationAcceptanceFixture.php')], {cwd: root, env: process.env, windowsHide: true, stdio: ['pipe', 'pipe', 'pipe']});
        let out = '', err = '';
        const timer = setTimeout(() => {child.kill(); reject(new Error('Local mailbox fixture timed out.'));}, 90000);
        child.stdout.on('data', value => {out += value;}); child.stderr.on('data', value => {err += value;});
        child.on('error', error => {clearTimeout(timer); reject(error);});
        child.on('close', code => {clearTimeout(timer); if (code) return reject(new Error(err)); try {resolve(JSON.parse(out));} catch {reject(new Error('Invalid mailbox fixture response.'));}});
        child.stdin.end(JSON.stringify({command, tenants}) + '\n');
    });
}
test('Native IMAP receive, browser reply, SMTP failure and retry stay tenant scoped', async ({page}, info) => {
    test.setTimeout(240000);
    test.skip(process.env.NEXA_MAILBOX_ACCEPTANCE !== '1' || !base, 'Explicit local SMTP/IMAP acceptance is required.');
    expect(['localhost', '127.0.0.1']).toContain(new URL(base).hostname);
    const suffix = randomBytes(6).toString('hex');
    let tenants = ['a', 'b'].map(label => ({id: randomUUID(), slug: `automation-acceptance-${suffix}-${label}`}));
    let failure;
    try {
        tenants = await fixture('setup', tenants);
        cleanupTenants = tenants;
        const received = await fixture('conversation-mailbox-setup', tenants);
        expect(received.map(row => row.count)).toEqual([1, 1]);
        expect(received[0].messageId).toBe(received[1].messageId);
        expect(received[0].emailId).not.toBe(received[1].emailId);
        expect((await fixture('conversation-mailbox-fetch', tenants)).map(row => row.count)).toEqual([1, 1]);
        await fixture('conversation-mailbox-imap-failure', tenants);
        await page.goto(`${base}/login/`);
        await page.locator('#field-userName').fill(tenants[0].username);
        await page.locator('#field-password').fill(tenants[0].password);
        await page.locator('#login-form button[type="submit"]').click();
        await page.waitForURL(new RegExp(`/w/${tenants[0].slug}(?:/.*)?$`));
        await page.goto(`${base}/w/${tenants[0].slug}/NexaConversations`);
        await expect(page.locator('.nc-conversation')).toHaveCount(1);
        await page.locator('[data-action="open"]').click();
        await expect(page.locator('.nc-message-body')).toContainText(received[0].subject);
        expect(await page.evaluate(async id => {try {await Espo.Ajax.getRequest(`Nexa/conversations/${id}`); return true;} catch {return false;}}, received[1].emailId)).toBe(false);
        await fixture('conversation-mailbox-smtp-fail', tenants);
        await page.getByRole('button', {name: 'Reply', exact: true}).click();
        const modal = page.locator('.modal:visible').last();
        await expect(modal).toBeVisible();
        await expect(modal.locator('select[data-name="from"] option[value="beta@conversation.test"]')).toHaveCount(0);
        await modal.locator('.field[data-name="from"] .selectize-input').click();
        await page.locator('.selectize-dropdown [data-value="alpha@conversation.test"]').click();
        await expect(modal.locator('select[data-name="from"]')).toHaveValue('alpha@conversation.test');
        await modal.locator('input[data-name="isHtml"]').check();
        await expect(modal.locator('.note-editable')).toBeVisible();
        await modal.locator('.note-editable').fill(`Reply acceptance ${suffix}`);
        const failed = page.waitForResponse(response => response.request().method() === 'POST' && /\/Email(?:\?|$)/.test(response.url()), {timeout: 20000});
        await modal.locator('[data-name="send"]').click();
        const failureResponse = await failed;
        expect(failureResponse.status()).toBe(500);
        const failureData = await failureResponse.json();
        expect(failureData.versionNumber).toBeGreaterThan(0);
        await expect(modal).toBeVisible();
        const draft = await page.evaluate(id => Espo.Ajax.getRequest(`Email/${id}`), failureData.id);
        expect(draft.status).toBe('Draft');
        expect(draft.versionNumber).toBe(failureData.versionNumber);
        expect((await fixture('conversation-mailbox-inspect', tenants)).filter(message => message.subject.includes(received[0].subject))).toHaveLength(0);
        await fixture('conversation-mailbox-smtp-restore', tenants);
        const sent = page.waitForResponse(response => ['POST', 'PUT', 'PATCH'].includes(response.request().method()) && /\/Email(?:\/[^/]+)?(?:\?|$)/.test(response.url()), {timeout: 20000});
        await modal.locator('[data-name="send"]').click();
        const sentResponse = await sent;
        expect(sentResponse.ok(), `Retry HTTP ${sentResponse.status()}: ${await sentResponse.text()}`).toBe(true);
        expect((await sentResponse.json()).id).toBe(failureData.id);
        await expect(modal).not.toBeVisible();
        const delivered = (await fixture('conversation-mailbox-inspect', tenants)).filter(message => message.subject.includes(received[0].subject));
        expect(delivered).toHaveLength(1);
        expect(delivered[0].from).toContain('alpha@conversation.test');
        expect(delivered[0].replyTo).toContain(received[0].messageId.replace(/[<>]/g, ''));
        expect(delivered[0].content).toContain(`Reply acceptance ${suffix}`);
        await expect(page.locator('.nc-messages')).toContainText(`Reply acceptance ${suffix}`);
        const conversation = await page.evaluate(id => Espo.Ajax.getRequest(`Nexa/conversations/${id}`), received[0].emailId);
        expect(conversation.messages).toHaveLength(2);
        expect(conversation.messages.filter(message => message.isSent)).toHaveLength(1);
        const staleSend = await page.evaluate(async suffix => {
            const draft = await Espo.Ajax.postRequest('Email', {name: `Stale draft ${suffix}`, from: 'alpha@conversation.test', to: 'customer@conversation.test', body: 'Initial draft', isHtml: false, status: 'Draft'});
            const options = {headers: {'X-Version-Number': draft.versionNumber}};
            await Espo.Ajax.putRequest(`Email/${draft.id}`, {body: 'Another authorized edit'}, options);
            let status = 0;
            try { await Espo.Ajax.putRequest(`Email/${draft.id}`, {body: 'Stale sender copy', status: 'Sending'}, options); }
            catch (error) { status = error.status; }
            const saved = await Espo.Ajax.getRequest(`Email/${draft.id}`);
            return {status, transport: saved.status, body: saved.body};
        }, suffix);
        expect(staleSend).toEqual({status: 409, transport: 'Draft', body: 'Another authorized edit'});
        expect(await fixture('conversation-mailbox-inspect', tenants)).toHaveLength(1);
        await page.locator('.nc-edit-status').selectOption('Closed');
        await expect(page.locator('.nc-edit-status')).toHaveValue('Closed');
        await fixture('conversation-mailbox-followup', tenants);
        await page.getByRole('button', {name: 'Refresh inbox'}).click();
        await expect(page.locator('.nc-edit-status')).toHaveValue('Open');
        await expect(page.locator('.nc-message')).toHaveCount(3);
        await expect(page.locator('.nc-list')).toHaveAttribute('aria-busy', 'false');
        await expect(page.locator('.nc-conversation')).toHaveCount(1);
        await page.screenshot({path: path.join(root, `test-results/conversation-mailbox-${info.project.name}.png`)});
    } catch (error) {failure = error;}
    finally {try {await fixture('cleanup', tenants); cleanupTenants = null;} catch (error) {if (!failure) failure = error;}}
    if (failure) throw failure;
});
