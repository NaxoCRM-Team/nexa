const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');

test('Real newsletter block designer stages, edits and exports without remote content or autosave', async ({page}, testInfo) => {
    let remoteRequests = 0;
    await page.route('https://designer-attacker.example/**', route => {remoteRequests++; return route.abort();});
    await page.route('http://designer.test/**', async route => {
        const pathname = new URL(route.request().url()).pathname;
        if (pathname === '/fixture') {
            const template = fs.readFileSync(path.join(root, 'espocrm/client/custom/res/templates/marketing-email/workspace.tpl'), 'utf8');
            const css = fs.readFileSync(path.join(root, 'espocrm/client/custom/css/marketing-email-workspace.css'), 'utf8');
            return route.fulfill({contentType: 'text/html', body: `<html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;font:14px Arial}.btn{padding:8px}[hidden]{display:none!important}${css}</style></head><body>${template}</body></html>`});
        }
        const file = path.resolve(root, 'espocrm', pathname.replace(/^\/nexa\//, ''));
        const allowedRoot = path.resolve(root, 'espocrm/client/custom');
        if (!file.startsWith(allowedRoot + path.sep) || !fs.existsSync(file)) return route.abort();
        return route.fulfill({body: fs.readFileSync(file), contentType: file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'application/javascript' : 'text/html'});
    });
    await page.goto('http://designer.test/fixture');
    await page.evaluate(() => {window.define = (name, deps, factory) => {window.MarketingView = factory(class {});};});
    await page.addScriptTag({content: fs.readFileSync(path.join(root, 'espocrm/client/custom/src/views/marketing-email/workspace.js'), 'utf8')});
    await page.evaluate(() => {
        const image = {id: 'mail-published-image', name: 'Company logo', alt: 'Company', url: 'https://designer-attacker.example/approved-image'};
        const source = {id: 'asset-new-image', assetId: 'new-image', name: 'Campaign artwork', requiresPublication: true};
        const preview = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        window.errors = []; window.imageActions = []; window.currentImages = [image, source];
        window.Espo = {Ui: {error: message => errors.push(message)}, Ajax: {getRequest: async url => {
            if (url === 'Nexa/marketing-email-images') return {images: currentImages};
            const selected = currentImages.find(item => url === `Nexa/marketing-email-images/${item.id}`);
            if (selected) return {...selected, preview};
            throw new Error('Unexpected designer request');
        }, postRequest: async (url, payload) => {
            imageActions.push({url, payload});
            if (url === 'Nexa/marketing-email-images') {
                const published = {id:'mail-new-image', assetId:'new-image', name:'Campaign artwork copy', alt:'Artwork', url:'https://designer-attacker.example/new-image'};
                currentImages.push(published); return {...published, preview};
            }
            if (url === 'Nexa/marketing-email-images/mail-new-image/revoke') {
                window.currentImages = currentImages.filter(item => item.id !== 'mail-new-image'); return {revoked:true};
            }
            throw new Error('Unexpected designer mutation');
        }}};
        const view = window.view = new MarketingView();
        view.element = document.querySelector('main'); view.getBasePath = () => '/nexa/'; view.once = () => {};
        view.workspace = {permissions: {attachments: true}}; view.selectedAttachments = [{id: 'keep-file', name: 'guide.txt', size: 10}];
        document.querySelector('[data-email-editor]').hidden = false;
        document.querySelector('[data-email-editor]').style.display = 'grid';
        document.querySelector('[name="bodyHtml"]').value = '<p>Hello {Contact.firstName}</p><script>parent.compromised=true</script><img src="https://designer-attacker.example/pixel" onerror="parent.compromised=true"><form action="https://designer-attacker.example/send"><input></form>';
        view.renderContentPreview();
        view.element.addEventListener('click', event => {
            const action = event.target.closest('[data-action]')?.dataset.action;
            if (action === 'open-block-designer') view.openBlockDesigner();
            if (action === 'close-block-designer') view.closeBlockDesigner();
            if (action === 'apply-block-design') view.applyBlockDesign();
        });
    });
    const original = await page.locator('[name="bodyHtml"]').inputValue();
    await page.getByRole('button', {name: 'Design blocks', exact: true}).click();
    const designer = page.frameLocator('iframe[title="Email block designer"]');
    await expect(designer.locator('#status')).toHaveText('Ready');
    await expect(designer.locator('.gjs-block')).toHaveCount(9);
    const iframe = page.frames().find(frame => frame.url().endsWith('/email-designer/index.html'));
    const htmlBefore = await iframe.evaluate(() => grapesjs.editors[0].getHtml());
    expect(htmlBefore).toContain('{Contact.firstName}');
    expect(htmlBefore).not.toContain('<script'); expect(htmlBefore).not.toContain('onerror'); expect(htmlBefore).not.toContain('<form');
    expect(await page.evaluate(() => window.compromised)).toBeUndefined();
    await designer.locator('.gjs-block').first().click();
    expect(await iframe.evaluate(() => grapesjs.editors[0].getHtml())).toContain('<table');
    await designer.getByRole('button', {name: 'Undo', exact: true}).click();
    expect(await iframe.evaluate(() => grapesjs.editors[0].getHtml())).not.toContain('<table');
    await designer.getByRole('button', {name: 'Redo', exact: true}).click();
    expect(await iframe.evaluate(() => grapesjs.editors[0].getHtml())).toContain('<table');
    await designer.getByRole('combobox', {name: 'Preview device'}).selectOption('Mobile');
    expect(await iframe.evaluate(() => grapesjs.editors[0].getDevice())).toBe('Mobile');
    expect(await iframe.evaluate(() => localStorage.length)).toBe(0);
    await iframe.evaluate(() => parent.postMessage({type: 'nexa-email-designer-content', session: 'wrong-session', html: '<p>Forged export</p>'}, location.origin));
    await page.evaluate(() => window.postMessage({type: 'nexa-email-designer-content', session: view.blockDesigner.session, html: '<p>Wrong source</p>'}, location.origin));
    await expect(page.locator('[name="bodyHtml"]')).toHaveValue(original);
    if (testInfo.project.name === 'desktop') {
        await iframe.evaluate(() => grapesjs.editors[0].Blocks.get('text').set('content', '<p>Dragged email block</p>'));
        const block = await designer.getByRole('button', {name: 'Text', exact: true}).boundingBox();
        const canvas = await designer.locator('.gjs-frame').boundingBox();
        await page.mouse.move(block.x + block.width / 2, block.y + block.height / 2);
        await page.mouse.down();
        await page.mouse.move(canvas.x + canvas.width / 2, canvas.y + 300, {steps: 25});
        await page.mouse.up();
        await expect.poll(() => iframe.evaluate(() => grapesjs.editors[0].getHtml())).toContain('Dragged email block');
    }
    if (page.viewportSize().width < 600) {
        await designer.getByRole('combobox', {name: 'Designer panel'}).selectOption('settings');
        await expect(designer.getByRole('complementary', {name: 'Block settings'})).toBeVisible();
        await designer.getByRole('combobox', {name: 'Designer panel'}).selectOption('blocks');
    }
    await expect(page.locator('[name="bodyHtml"]')).toHaveValue(original);
    await page.getByRole('button', {name: 'Cancel design', exact: true}).click();
    await expect(page.locator('[name="bodyHtml"]')).toHaveValue(original);
    await page.getByRole('button', {name: 'Design blocks', exact: true}).click();
    await expect(designer.locator('#status')).toHaveText('Ready');
    const nextFrame = page.frames().find(frame => frame.url().endsWith('/email-designer/index.html'));
    await nextFrame.evaluate(() => {
        const editor = grapesjs.editors[0];
        editor.addComponents('<table style="width:100%;background-color:#e9f5ee"><tr><td style="padding:20px"><p>Approved designer content</p></td></tr></table>');
    });
    await designer.getByRole('searchbox', {name: 'Search email images'}).fill('absent');
    await expect(designer.locator('#images button')).toHaveCount(0);
    await designer.getByRole('searchbox', {name: 'Search email images'}).fill('logo');
    await designer.getByRole('button', {name: 'Company logo', exact: true}).click();
    await expect.poll(() => nextFrame.evaluate(() => grapesjs.editors[0].getHtml())).toContain('data:image/png;base64,');
    await expect.poll(() => nextFrame.evaluate(() => [...grapesjs.editors[0].Canvas.getDocument().images].map(image => ({alt: image.alt, width: image.naturalWidth, src: image.getAttribute('src')})))).toContainEqual({alt: 'Company', width: 1, src: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='});
    await designer.getByRole('searchbox', {name: 'Search email images'}).fill('artwork');
    await designer.getByRole('button', {name:'Campaign artwork', exact:true}).click();
    await expect(designer.getByRole('dialog', {name:'Publish an email image copy?'})).toBeVisible();
    await designer.getByRole('button', {name:'Cancel', exact:true}).click();
    expect(await page.evaluate(() => imageActions.length)).toBe(0);
    await designer.getByRole('button', {name:'Campaign artwork', exact:true}).click();
    await designer.locator('#image-confirm').press('Escape');
    await expect(page.locator('[data-block-designer]')).toHaveCount(1);
    expect(await page.evaluate(() => imageActions.length)).toBe(0);
    await designer.getByRole('button', {name:'Campaign artwork', exact:true}).click();
    await designer.getByRole('button', {name:'Publish and insert', exact:true}).click();
    await expect.poll(() => nextFrame.evaluate(() => [...grapesjs.editors[0].Canvas.getDocument().images].some(image => image.alt === 'Artwork' && image.naturalWidth === 1))).toBe(true);
    expect(await page.evaluate(() => imageActions)).toEqual([{url:'Nexa/marketing-email-images', payload:{assetId:'new-image', confirmPublicCopy:true}}]);
    await testInfo.attach('email-block-designer.png', {body: await page.screenshot({path: path.join(root, `test-results/email-designer-${testInfo.project.name}.png`)}), contentType: 'image/png'});
    const bounds = await page.locator('[data-block-designer]').boundingBox();
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(page.viewportSize().width + 1);
    await page.getByRole('button', {name: 'Apply design', exact: true}).click();
    await expect(page.locator('[data-block-designer]')).toHaveCount(0);
    const exported = await page.locator('[name="bodyHtml"]').inputValue();
    expect(exported).toContain('Approved designer content'); expect(exported).toContain('style=');
    expect(exported).toContain('{Contact.firstName}'); expect(exported).not.toContain('<script'); expect(exported).not.toContain('onerror');
    expect(exported).toContain('https://designer-attacker.example/approved-image');
    expect(exported).toContain('https://designer-attacker.example/new-image');
    expect(exported).not.toContain('data-nexa-email-image');
    expect(exported).not.toContain('data:image/png');
    expect(await page.evaluate(() => view.selectedAttachments.map(item => item.id))).toEqual(['keep-file']);
    expect(remoteRequests).toBe(0); expect(await page.evaluate(() => errors)).toEqual([]);
    await page.getByRole('button', {name: 'Design blocks', exact: true}).click();
    await expect(designer.locator('#status')).toHaveText('Ready');
    const reopened = page.frames().find(frame => frame.url().endsWith('/email-designer/index.html'));
    await expect.poll(() => reopened.evaluate(() => [...grapesjs.editors[0].Canvas.getDocument().images].some(image => image.alt === 'Company' && image.naturalWidth === 1))).toBe(true);
    await expect.poll(() => reopened.evaluate(() => [...grapesjs.editors[0].Canvas.getDocument().images].some(image => image.alt === 'Artwork' && image.naturalWidth === 1))).toBe(true);
    await designer.getByRole('button', {name:'Revoke Campaign artwork copy', exact:true}).click();
    await designer.getByRole('button', {name:'Cancel', exact:true}).click();
    expect(await page.evaluate(() => imageActions.length)).toBe(1);
    await designer.getByRole('button', {name:'Revoke Campaign artwork copy', exact:true}).click();
    await designer.getByRole('button', {name:'Revoke image', exact:true}).click();
    await expect(designer.locator('#image-status')).toContainText('revoked');
    await page.getByRole('button', {name:'Apply design', exact:true}).click();
    await expect.poll(() => page.evaluate(() => errors.length)).toBe(1);
    await expect(page.locator('[name="bodyHtml"]')).toHaveValue(exported);
    await page.evaluate(() => {view.contentEditorRequest = (view.contentEditorRequest || 0) + 1;});
    await page.getByRole('button', {name: 'Apply design', exact: true}).click();
    await expect(page.locator('[data-block-designer]')).toHaveCount(0);
    await expect(page.locator('[name="bodyHtml"]')).toHaveValue(exported);
});
