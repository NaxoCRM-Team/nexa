const {test, expect: baseExpect} = require('@playwright/test');
const expect = baseExpect.configure({timeout: 15000});
const AxeBuilder = require('@axe-core/playwright').default;
const {spawn} = require('node:child_process');
const {randomUUID, randomBytes} = require('node:crypto');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const base = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
test.use({trace: 'off', screenshot: 'off'});
function fixture(command, tenants, extra = {}) {
    return new Promise((resolve, reject) => {
        const child = spawn(process.env.NEXA_PHP_PATH || 'php', [path.join(root, 'tests/tenant/AutomationAcceptanceFixture.php')], {cwd: root, env: process.env, windowsHide: true, stdio: ['pipe', 'pipe', 'pipe']});
        let out = '', err = '';
        const timer = setTimeout(() => {child.kill(); reject(new Error('Conversation fixture timed out.'));}, 45000);
        child.stdout.on('data', v => {out += v;}); child.stderr.on('data', v => {err += v;});
        child.on('error', e => {clearTimeout(timer); reject(e);});
        child.on('close', code => {clearTimeout(timer); if (code) return reject(new Error(err)); try {resolve(JSON.parse(out));} catch {reject(new Error('Invalid fixture response.'));}});
        child.stdin.end(JSON.stringify({command, tenants, ...extra}) + '\n');
    });
}
test('Shared conversations use native threads, triage, staff search and permission boundaries', async ({page}, info) => {
    test.setTimeout(150000);
    test.skip(process.env.NEXA_AUTOMATION_ACCEPTANCE !== '1' || !base, 'Explicit local conversation acceptance is required.');
    expect(['localhost', '127.0.0.1']).toContain(new URL(base).hostname);
    const suffix = randomBytes(6).toString('hex');
    let tenants = ['a','b'].map(label => ({id: randomUUID(), slug: `automation-acceptance-${suffix}-${label}`}));
    let failure;
    try {
        tenants = await fixture('setup', tenants); const messages = await fixture('conversation-setup', tenants, {history: true});
        await page.goto(`${base}/login/`);
        await page.locator('#field-userName').fill(tenants[0].username);
        await page.locator('#field-password').fill(tenants[0].password);
        const authenticated = page.waitForResponse(response => response.url().includes('/App/user') && !!response.request().headers()['espo-authorization'], {timeout: 60000});
        await page.locator('#login-form button[type="submit"]').click();
        expect((await authenticated).status()).toBe(200);
        await page.waitForURL(new RegExp(`/w/${tenants[0].slug}(?:/.*)?$`));
        await page.waitForFunction(() => document.body.classList.contains('nexa-shell-ready'), null, {timeout: 60000});
        const listed = page.waitForResponse(response => new URL(response.url()).pathname.endsWith('/Nexa/conversations') && response.request().method() === 'GET', {timeout: 60000});
        await page.goto(`${base}/w/${tenants[0].slug}/NexaConversations`);
        const loaded = await listed; expect(loaded.status()).toBe(200); expect((await loaded.json()).list).toHaveLength(2);
        await expect(page.getByRole('heading', {name: 'Shared Inbox', exact: true})).toBeVisible();
        await expect(page.locator('.nc-conversation')).toHaveCount(2);
        await expect(page.locator('.nc-list')).not.toContainText('Private');
        await page.locator(`[data-action="open"][data-id="${messages[0].otherSharedId}"]`).click();
        await expect(page.locator('.nc-message')).toHaveCount(1);
        await expect(page.locator('.nc-messages')).toContainText('Only this shared branch is visible.');
        if (await page.locator('.nc-back').isVisible()) await page.getByRole('button', {name: 'Back to conversations'}).click();
        await page.locator('[data-action="open"]').filter({hasText: 'Delivery question'}).click();
        await expect(page.locator('.nc-messages')).toContainText('Can you confirm the delivery date?');
        await expect(page.locator('.nc-messages')).toContainText('We are checking your delivery.');
        await expect(page.locator('.nc-message')).toHaveCount(2);
        const updated = page.waitForResponse(r => r.url().includes('/Nexa/conversations/') && r.request().method() === 'POST' && !r.url().endsWith('/read'));
        await page.locator('.nc-edit-status').selectOption('Pending');
        expect((await updated).ok()).toBe(true);
        await expect(page.locator('.nc-edit-status')).toHaveValue('Pending');
        await expect(page.locator(`.nc-conversation[data-id="${messages[0].emailId}"]`)).toContainText('Pending', {timeout: 15000});
        await page.getByRole('searchbox', {name: 'Search staff'}).fill('Acceptance');
        await expect(page.locator('.nc-edit-owner option')).toHaveCount(2);
        const state = await page.evaluate(id => Espo.Ajax.getRequest(`Nexa/conversations/${id}`), messages[0].emailId);
        expect(state.conversation.conversationStatus).toBe('Pending');
        expect(state.conversation.versionNumber).toBeGreaterThan(0);
        await expect(page.locator('.nc-context a')).toContainText('Acceptance');
        await page.getByRole('button', {name: 'Mark unread', exact: true}).click();
        await expect(page.locator('.nc-thread .nc-empty')).toHaveText('Select a conversation');
        if (await page.locator('.nc-back').isVisible()) await page.getByRole('button', {name: 'Back to conversations'}).click();
        await page.getByRole('combobox', {name: 'Conversation view'}).selectOption('unread');
        await expect(page.locator('.nc-conversation')).toHaveCount(1);
        await page.locator('[data-action="open"]').click();
        await expect(page.locator('.nc-messages')).toContainText('We are checking your delivery.');
        const races = await page.evaluate(async id => {
            const c = (await Espo.Ajax.getRequest(`Nexa/conversations/${id}`)).conversation;
            return Promise.all(['Closed', 'Open'].map(async status => {
                try {await Espo.Ajax.postRequest(`Nexa/conversations/${id}`, {conversationStatus: status, assignedUserId: c.assignedUserId, expectedStatus: c.conversationStatus, expectedOwnerId: c.assignedUserId, expectedVersion: c.versionNumber}); return 200;}
                catch (error) {return error.status;}
            }));
        }, messages[0].emailId);
        expect(races.sort()).toEqual([200, 409]);
        await fixture('conversation-incoming', tenants, {emailId: messages[0].emailId});
        const reopened = await page.evaluate(id => Espo.Ajax.getRequest(`Nexa/conversations/${id}`), messages[0].emailId);
        expect(reopened.conversation.conversationStatus).toBe('Open');
        expect(reopened.messages).toHaveLength(3);
        expect(JSON.stringify(reopened)).not.toContain('PRIVATE');
        await page.getByRole('button', {name: 'Refresh inbox'}).click();
        await expect(page.locator('.nc-messages')).toContainText('Please confirm the updated date.');
        const results = await new AxeBuilder({page}).include('.nexa-conversations').withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
        expect(results.violations).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        if (info.project.name === 'mobile-landscape') {
            await page.locator('.nc-thread').evaluate(element => {element.scrollTop = element.scrollHeight;});
            await expect(page.locator('.nc-message-body').last()).toBeInViewport();
        }
        await page.screenshot({path: path.join(root, `test-results/conversations-${info.project.name}.png`)});
        for (const id of [messages[1].emailId, messages[0].privateId, messages[0].ancestorId, messages[0].branchId, messages[0].branchSentId]) {
            expect(await page.evaluate(async id => {try {await Espo.Ajax.getRequest(`Nexa/conversations/${id}`); return true;} catch {return false;}}, id)).toBe(false);
        }
        if (await page.locator('.nc-back').isVisible()) await page.getByRole('button', {name: 'Back to conversations'}).click();
        await page.getByRole('combobox', {name: 'Conversation view'}).selectOption('');
        await page.getByRole('searchbox', {name: 'Search conversations'}).fill('not-present');
        await expect(page.locator('.nc-list')).toContainText('No shared conversations found');
        await page.getByRole('searchbox', {name: 'Search conversations'}).fill('Delivery');
        await expect(page.locator('.nc-conversation')).toHaveCount(1);
        expect(await page.locator('.nc-list').innerText()).not.toContain('Private staff email');
    } catch (error) {failure = error;}
    finally {try {await fixture('cleanup', tenants);} catch (error) {if (!failure) failure = error;}}
    if (failure) throw failure;
});
