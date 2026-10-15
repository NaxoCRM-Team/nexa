const {test, expect} = require('@playwright/test');
const {spawn} = require('node:child_process');
const {randomBytes, randomUUID} = require('node:crypto');
const fs = require('node:fs/promises');
const path = require('node:path');
const https = require('node:https');
const root = path.resolve(__dirname, '../..');
const directory = path.join(root, '.local/personalization');
const configuration = path.join(directory, 'config.json');
const certificate = path.join(directory, 'root.crt');
const compose = ['compose', '-f', 'compose.personalization.yaml'];
test.use({trace: 'off', screenshot: 'off'});

function run(binary, args, input, timeout = 90000) {
    return new Promise((resolve, reject) => {
        const child = spawn(binary, args, {cwd: root, env: process.env, windowsHide: true, stdio: ['pipe', 'pipe', 'pipe']});
        let output = '', errors = '';
        const timer = setTimeout(() => {child.kill(); reject(new Error('Local website acceptance command timed out.'));}, timeout);
        child.stdout.on('data', chunk => output += chunk);
        child.stderr.on('data', chunk => errors += chunk);
        child.on('error', error => {clearTimeout(timer); reject(error);});
        child.on('close', code => {clearTimeout(timer); code === 0 ? resolve(output) : reject(new Error(`Local acceptance command failed: ${errors}`));});
        child.stdin.end(input || '');
    });
}
async function fixture(command, tenants, extras = {}) {
    return JSON.parse(await run(process.env.NEXA_PHP_PATH || 'php',
        [path.join(root, 'tests/tenant/AutomationAcceptanceFixture.php')], JSON.stringify({command, tenants, ...extras}) + '\n'));
}
function trustedGet(ca) {
    return new Promise((resolve, reject) => {
        const request = https.get('https://localhost:8444/a/', {ca, rejectUnauthorized: true, family: 4}, response => {
            response.resume(); response.on('end', () => resolve(response.statusCode));
        });
        request.setTimeout(10000, () => request.destroy(new Error('HTTPS demo timed out.')));
        request.on('error', reject);
    });
}

test('Local HTTPS customer website uses consented server-only identity with isolated sessions', async ({browser}, testInfo) => {
    test.setTimeout(300000);
    test.skip(process.env.NEXA_WEBSITE_ACCEPTANCE !== '1', 'Run the explicit local Docker website acceptance command.');
    let tenants = ['a', 'b'].map(label => ({id: randomUUID(), slug: `automation-acceptance-${suffix}-${label}`}));
    let ownsConfig = false, ownsCertificate = false, ownsStack = false, context, anonymous, failure;
    try {
        const existing = await run('docker', ['ps', '-a', '--filter', 'label=com.docker.compose.project=nexa-personalization-local', '--format', '{{.ID}}']);
        if (existing.trim()) throw new Error('The demo project already exists; refusing to modify an existing stack.');
        await fs.mkdir(directory, {recursive: true});
        tenants = await fixture('setup', tenants);
        const accounts = {};
        for (let index = 0; index < tenants.length; index++) accounts[index ? 'b' : 'a'] = await fixture('website-personalization-setup', tenants, {index});
        const secrets = Object.fromEntries(Object.entries(accounts).map(([key, value]) => [key, Object.fromEntries(Object.entries(value).filter(([name]) => name !== 'password'))]));
        await fs.writeFile(configuration, JSON.stringify(secrets), {flag: 'wx', mode: 0o600}); ownsConfig = true;
        await fs.writeFile(certificate, '', {flag: 'wx'}); ownsCertificate = true;
        ownsStack = true;
        await run('docker', [...compose, 'up', '-d']);
        await expect.poll(async () => {
            try {
                await run('docker', [...compose, 'cp', 'proxy:/data/caddy/pki/authorities/local/root.crt', certificate]);
                return await trustedGet(await fs.readFile(certificate));
            } catch {return 0;}
        }, {timeout: 20000, intervals: [1000, 2000]}).toBe(200);
        // Chromium gets a local-only CA exception; Node above and PHP upstream both verify TLS.
        context = await browser.newContext({ignoreHTTPSErrors: true});
        anonymous = await browser.newContext({ignoreHTTPSErrors: true});
        const page = await context.newPage();
        const externalRequests = [];
        page.on('request', request => {if (new URL(request.url()).origin !== 'https://localhost:8444') externalRequests.push(request.url());});
        const response = await page.goto('https://localhost:8444/a/');
        expect(response.headers()['cache-control']).toContain('no-store');
        expect(response.headers()['vary']).toContain('Cookie');
        await expect(page.locator('[data-content]')).toContainText(`Public offer ${tenants[0].slug}`);
        const csrf = await page.locator('input[name=csrf]').first().inputValue();
        const forged = await context.request.post('https://localhost:8444/a/login', {form: {csrf: 'invalid', email: accounts.a.email, password: accounts.a.password}});
        expect(forged.status()).toBe(403);
        const foreignOrigin = await context.request.post('https://localhost:8444/a/login', {headers: {Origin: 'https://untrusted.example'}, form: {csrf, email: accounts.a.email, password: accounts.a.password}});
        expect(foreignOrigin.status()).toBe(403);
        const spoof = await context.request.post('https://localhost:8444/a/login', {headers: {Origin: 'https://localhost:8444'}, form: {csrf, email: accounts.a.email, password: accounts.a.password, contactId: accounts.b.contactId}});
        expect(spoof.status()).toBe(400);
        await page.getByLabel('Email', {exact: true}).fill(accounts.a.email);
        await page.getByLabel('Password', {exact: true}).fill('wrong-password');
        await page.getByRole('button', {name: 'Sign in', exact: true}).click();
        await expect(page.getByRole('alert')).toContainText('Email or password is incorrect.');
        await page.getByRole('button', {name: 'Allow personalization'}).click();
        await page.getByLabel('Email', {exact: true}).fill(accounts.a.email);
        await page.getByLabel('Password', {exact: true}).fill(accounts.a.password);
        const oldCookie = (await context.cookies()).find(cookie => cookie.name === 'nexa_demo_session');
        await page.getByRole('button', {name: 'Sign in', exact: true}).click();
        await expect(page.locator('[data-content]')).toContainText(`Verified offer ${tenants[0].slug}`);
        expect(await page.locator('[data-content] img, [data-content] script').count()).toBe(0);
        const html = await page.content();
        for (const account of Object.values(accounts)) {
            expect(html).not.toContain(account.apiKey); expect(html).not.toContain(account.contactId);
            expect(html).not.toContain(account.passwordHash);
        }
        const cookie = (await context.cookies()).find(item => item.name === 'nexa_demo_session');
        expect(cookie.secure && cookie.httpOnly && cookie.sameSite === 'Strict').toBe(true);
        expect(cookie.value).not.toBe(oldCookie.value);
        const visitor = await anonymous.newPage();
        await visitor.goto('https://localhost:8444/a/');
        await expect(visitor.locator('[data-content]')).toContainText(`Public offer ${tenants[0].slug}`);
        await visitor.getByLabel('Email', {exact: true}).fill(accounts.a.email);
        await visitor.getByLabel('Password', {exact: true}).fill(accounts.a.password);
        await visitor.getByRole('button', {name: 'Sign in', exact: true}).click();
        await expect(visitor.getByRole('button', {name: 'Sign out'})).toBeVisible();
        await expect(visitor.locator('[data-content]')).toContainText(`Public offer ${tenants[0].slug}`);
        await page.goto('https://localhost:8444/b/');
        await expect(page.locator('[data-content]')).toContainText(`Public offer ${tenants[1].slug}`);
        await page.getByRole('button', {name: 'Allow personalization'}).click();
        await page.getByLabel('Email', {exact: true}).fill(accounts.b.email);
        await page.getByLabel('Password', {exact: true}).fill(accounts.b.password);
        await page.getByRole('button', {name: 'Sign in', exact: true}).click();
        await expect(page.locator('[data-content]')).toContainText(`Verified offer ${tenants[1].slug}`);
        await context.setExtraHTTPHeaders({'Sec-GPC': '1'});
        await page.goto('https://localhost:8444/a/');
        await expect(page.locator('[data-content]')).toContainText(`Public offer ${tenants[0].slug}`);
        await context.setExtraHTTPHeaders({});
        await page.reload();
        await page.getByRole('button', {name: 'Withdraw consent'}).click();
        await expect(page.locator('[data-content]')).toContainText(`Public offer ${tenants[0].slug}`);
        await page.getByRole('button', {name: 'Allow personalization'}).click();
        await expect(page.locator('[data-content]')).toContainText(`Verified offer ${tenants[0].slug}`);
        await fixture('website-personalization-expire', tenants);
        await page.reload();
        await expect(page.locator('[data-content]')).toContainText(`Public offer ${tenants[0].slug}`);
        await page.getByRole('button', {name: 'Allow personalization'}).click();
        await page.getByRole('button', {name: 'Sign out'}).click();
        await expect(page.locator('[data-content]')).toContainText(`Public offer ${tenants[0].slug}`);
        await expect(page.getByRole('button', {name: 'Sign in', exact: true})).toBeVisible();
        await fs.writeFile(configuration, JSON.stringify({...secrets, a: {...secrets.a, apiKey: randomBytes(20).toString('hex')}}));
        try {
            const unavailable = await page.reload();
            expect(unavailable.status()).toBe(502);
            await expect(page.getByRole('alert')).toContainText('temporarily unavailable');
            await expect(page.locator('[data-content]')).not.toContainText('Verified offer');
            expect(await page.content()).not.toContain(accounts.a.apiKey);
        } finally {await fs.writeFile(configuration, JSON.stringify(secrets));}
        await page.goto('https://localhost:8444/a/');
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await testInfo.attach('signed-out-demo', {body: await page.screenshot({path: testInfo.outputPath('signed-out.png')}), contentType: 'image/png'});
        expect(externalRequests).toEqual([]);
        await testInfo.attach('local-https-acceptance', {body: JSON.stringify({tlsVerified: true, backendOnlyIdentity: true, tenantSessions: 2, consent: ['unknown', 'granted', 'withdrawn', 'GPC', 'expired', 'revoked'], publicDeployment: false}), contentType: 'application/json'});
    } catch (error) {failure = error;} finally {
        await context?.close(); await anonymous?.close();
        try {
            if (ownsStack) await run('docker', [...compose, 'down', '--volumes']);
            if (ownsConfig) await fs.unlink(configuration);
            if (ownsCertificate) await fs.unlink(certificate);
        } catch (error) {failure ||= error;}
        try {await fixture('cleanup', tenants);} catch (error) {failure ||= error;}
    }
    if (failure) throw failure;
});
const suffix = randomBytes(6).toString('hex');
