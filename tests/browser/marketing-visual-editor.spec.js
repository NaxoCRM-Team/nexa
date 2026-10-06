const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');

test('Native visual editor integration preserves content through source, preview, template and save', async ({page}, testInfo) => {
    const template = fs.readFileSync(path.join(root, 'espocrm/client/custom/res/templates/marketing-email/workspace.tpl'), 'utf8');
    const css = fs.readFileSync(path.join(root, 'espocrm/client/custom/css/marketing-email-workspace.css'), 'utf8');
    await page.setContent(`<meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;font:14px Arial}.form-control{box-sizing:border-box;width:100%}.btn{padding:8px}.note-editable{min-height:240px;padding:12px}[hidden]{display:none!important}${css}</style>${template}`);
    await page.evaluate(() => {window.define = (name, deps, factory) => {window.MarketingView = factory(class {});};});
    await page.addScriptTag({content: fs.readFileSync(path.join(root, 'espocrm/client/custom/src/views/marketing-email/workspace.js'), 'utf8')});
    await page.evaluate(async () => {
        const view = window.view = new MarketingView();
        view.element = document.querySelector('main'); view.text = (selector, value) => {document.querySelector(selector).textContent = value;};
        view.syncCampaign = () => {}; view.once = () => {}; view.stopListening = () => {};
        window.errors = []; window.Espo = {Ui: {error: message => errors.push(message)}};
        // Isolate the host-view contract; native field rendering is provided by EspoCRM at runtime.
        view.getModelFactory = () => ({create: async type => {
            window.createdType = type;
            return {values: {}, get(name) {return this.values[name];}, set(name, value) {
                if (typeof name === 'object') Object.assign(this.values, name); else this.values[name] = value;
                if (this.element) this.element.innerHTML = this.values.body;
            }};
        }});
        view.createView = async (key, type, options) => {
            window.fieldType = type; window.fieldOptions = options;
            const field = {model: options.model, async render() {
                const holder = document.querySelector(options.fullSelector);
                holder.innerHTML = '<div class="note-editor"><div class="note-editable" contenteditable="true" role="textbox" aria-label="Email content"></div></div>';
                this.element = holder.querySelector('.note-editable'); this.element.innerHTML = this.model.get('body');
                this.model.element = this.element;
                this.element.addEventListener('input', () => this.onChange?.());
            }, fetchToModel() {this.model.values.body = this.element.innerHTML;}, remove() {this.element?.parentElement.remove();}};
            return field;
        };
        view.listenTo = (field, name, callback) => {field.onChange = callback;};
        view.clearView = () => {view.contentEditor?.remove();};
        document.querySelector('[name="campaignId"]').innerHTML = '<option value="campaign">Campaign</option>';
        document.querySelector('[data-email-editor]').hidden = false; document.querySelector('[data-email-editor]').style.display = 'grid';
        document.querySelector('[name="bodyHtml"]').value = '<p>Hello {Contact.firstName}</p>';
        await view.installContentEditor(); view.renderContentPreview();
        document.querySelector('[data-action="content-mode"]').addEventListener('click', () => view.toggleContentMode());
        document.querySelector('[name="bodyHtml"]').addEventListener('input', () => view.contentChanged());
    });
    expect(await page.evaluate(() => createdType)).toBe('EmailTemplate');
    expect(await page.evaluate(() => fieldType)).toBe('views/fields/wysiwyg');
    const editor = page.getByRole('textbox', {name: 'Email content', exact: true});
    await expect(editor).toBeVisible();
    await expect(page.locator('[name="bodyHtml"]')).toBeHidden();
    await editor.fill('Visual content');
    await expect(page.frameLocator('[title="Email content preview"]').getByText('Visual content')).toBeVisible();
    await page.getByRole('button', {name: 'HTML source', exact: true}).click();
    await expect(page.locator('[name="bodyHtml"]')).toHaveValue('<p>Visual content</p>');
    await page.locator('[name="bodyHtml"]').fill('<h2>Source content</h2>');
    await page.getByRole('button', {name: 'Visual editor', exact: true}).click();
    await expect(editor.getByRole('heading', {name: 'Source content'})).toBeVisible();
    await page.evaluate(() => {view.pendingTemplate = {name: 'Template', subject: 'Subject', bodyHtml: '<p>Applied template</p>'}; view.applyEmailTemplate();});
    await expect(editor).toContainText('Applied template');
    expect(await page.evaluate(() => view.payload().bodyHtml)).toBe('<p>Applied template</p>');
    const bounds = await editor.boundingBox();
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(page.viewportSize().width + 1);
    await testInfo.attach('marketing-editor-host.png', {body: await page.screenshot(), contentType: 'image/png'});
    await page.evaluate(() => view.closeEditor());
    expect(await page.evaluate(() => view.contentEditor)).toBeNull();
    expect(await page.evaluate(() => errors)).toEqual([]);
    await page.evaluate(async () => {
        view.getModelFactory = () => ({create: async () => {throw new Error('Editor load failure');}});
        const dialog = document.querySelector('[data-email-editor]'); dialog.hidden = false; dialog.style.display = 'grid';
        await view.installContentEditor();
    });
    await expect(page.locator('[name="bodyHtml"]')).toBeVisible();
    await expect(page.locator('[name="bodyHtml"]')).toHaveValue('<p>Applied template</p>');
    expect(await page.evaluate(() => errors[0])).toContain('HTML source editing is still available');
    await page.evaluate(() => {
        view.workspace = {permissions: {attachments: true}}; view.selectedAttachments = []; view.renderAttachments();
        view.createView = (name, type, options, callback) => {window.fileSelectorOptions = options; callback({render() {}});};
        view.listenToOnce = (selector, event, callback) => {if (event === 'insert') window.selectFile = callback;};
        document.querySelector('[data-action="choose-attachment"]').addEventListener('click', () => view.chooseAttachment());
    });
    await page.getByRole('button', {name: 'Add file', exact: true}).click();
    expect(await page.evaluate(() => fileSelectorOptions.maxFileSize)).toBe(5 * 1024 * 1024);
    expect(await page.evaluate(() => fileSelectorOptions.accept)).toContain('.pdf');
    await page.evaluate(() => selectFile({id: 'tenant-file', name: 'guide.txt', mimeType: 'text/plain', size: 100}));
    await expect(page.locator('[data-marketing-attachments]')).toContainText('guide.txt');
    expect(await page.evaluate(() => view.payload().attachmentsIds)).toEqual(['tenant-file']);
    await page.evaluate(() => selectFile({id: 'tenant-file', name: 'guide.txt', mimeType: 'text/plain', size: 100}));
    expect(await page.evaluate(() => view.selectedAttachments.length)).toBe(1);
    await page.evaluate(() => selectFile({id: 'too-large', name: 'large.txt', mimeType: 'text/plain', size: 6 * 1024 * 1024}));
    expect(await page.evaluate(() => view.selectedAttachments.length)).toBe(1);
    await page.evaluate(() => view.removeAttachment({currentTarget: {dataset: {id: 'tenant-file'}}}));
    expect(await page.evaluate(() => view.payload().attachmentsIds)).toEqual([]);
    await page.evaluate(async () => {
        window.uploadRequests = [];
        Espo.Ajax = {postRequest: async (url, input) => {
            uploadRequests.push({url, input});
            return {id: 'uploaded-file', name: input.name, mimeType: input.mimeType, size: 14};
        }};
        await view.uploadMarketingAttachment(new File(['Uploaded guide'], 'new-guide.txt', {type: 'text/plain'}));
    });
    await expect(page.locator('[data-marketing-attachments]')).toContainText('new-guide.txt');
    expect(await page.evaluate(() => uploadRequests[0].url)).toBe('Nexa/marketing-email-attachments');
    expect(await page.evaluate(() => atob(uploadRequests[0].input.data))).toBe('Uploaded guide');
    expect(await page.evaluate(() => view.payload().attachmentsIds)).toEqual(['uploaded-file']);
    await page.evaluate(async () => {
        await view.uploadMarketingAttachment(new File(['bad'], 'script.exe'));
        await view.uploadMarketingAttachment(new File([new Uint8Array(6 * 1024 * 1024)], 'too-large.txt', {type: 'text/plain'}));
    });
    expect(await page.evaluate(() => uploadRequests.length)).toBe(1);
    await page.evaluate(async () => {
        Espo.Ajax.postRequest = () => new Promise(resolve => {window.finishUpload = resolve;});
        window.pendingUpload = view.uploadMarketingAttachment(new File(['Late guide'], 'late.txt', {type: 'text/plain'}));
    });
    await expect(page.locator('[data-email-form] [type="submit"]')).toBeDisabled();
    await page.waitForFunction(() => !!window.finishUpload);
    await page.evaluate(async () => {
        view.contentEditorRequest = (view.contentEditorRequest || 0) + 1;
        view.selectedAttachments = [];
        finishUpload({id: 'late-file', name: 'late.txt', size: 10});
        await pendingUpload;
    });
    expect(await page.evaluate(() => view.selectedAttachments)).toEqual([]);
    await expect(page.locator('[data-email-form] [type="submit"]')).toBeEnabled();
});
