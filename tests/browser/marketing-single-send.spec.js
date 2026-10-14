const {test,expect} = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname,'../..');

test('Single-send draft and confirmation use the governed queue with safe cancellation and retries', async ({page},testInfo) => {
    const template = fs.readFileSync(path.join(root,'espocrm/client/custom/res/templates/marketing-email/workspace.tpl'),'utf8');
    const css = fs.readFileSync(path.join(root,'espocrm/client/custom/css/marketing-email-workspace.css'),'utf8');
    await page.setContent(`<meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;font:14px Arial}.form-control{box-sizing:border-box;width:100%}.btn{padding:8px}[hidden]{display:none!important}${css}</style>${template}`);
    await page.evaluate(() => {window.define = (name,deps,factory) => {window.MarketingView = factory(class {});};});
    await page.addScriptTag({content:fs.readFileSync(path.join(root,'espocrm/client/custom/src/views/marketing-email/workspace.js'),'utf8')});
    await page.evaluate(() => {
        window.requests = []; window.messages = []; window.listeners = {};
        window.Espo = {Ui:{success:message => messages.push(message)},Ajax:{postRequest:async (url,input) => {
            requests.push({url,input});
            if (window.rejectSend) throw new Error('This Contact has not granted email consent.');
            const result = {id:'single',queueItemId:'stable-queue',status:'Pending',replayed:requests.length>1};
            if (window.deferSend) return new Promise(resolve => {window.releaseSend = () => resolve(result);});
            return result;
        }}};
        const view = window.view = new MarketingView(); view.element = document.querySelector('main');
        view.workspace = {deliveryEnabled:true,permissions:{edit:true,approve:true},campaigns:[],emails:[
            {id:'single',name:'Personal invitation',fromAddress:'newsletter@example.test',deliveryMode:'single_send',lifecycleStatus:'approved'},
            {id:'campaign',name:'Audience update',deliveryMode:'campaign',lifecycleStatus:'approved'},
        ]};
        view.once = (event,fn) => {window.removal = fn;};
        view.load = async () => {window.reloads = (window.reloads || 0) + 1;};
        view.installContentEditor = () => {}; view.destroyContentEditor = () => {}; view.syncCampaign = () => {};
        view.createView = (name,type,options,callback) => {
            window.selectorOptions = {name,type,options}; window.listeners = {};
            callback({render(){},remove(){listeners.remove?.();}});
        };
        view.listenToOnce = (selector,event,callback) => {window.listeners[event] = callback;};
        view.element.querySelectorAll('[data-email-state]').forEach(node => {node.hidden = node.dataset.emailState !== 'ready';});
        view.element.querySelectorAll('.nexa-marketing-email-editor').forEach(node => {node.style.display='none';});
        view.renderEmails();
        view.element.addEventListener('click',event => {
            const button = event.target.closest('[data-action]');
            if (button?.dataset.action === 'single-send') view.chooseSingleSendContact({currentTarget:button});
            if (button?.dataset.action === 'close-single-send') view.closeSingleSend();
        });
        view.element.addEventListener('submit',event => {if (event.target.matches('[data-single-send-form]')) view.submitSingleSend(event);});
        view.element.addEventListener('change',event => {if (event.target.name==='deliveryMode') view.updateDeliveryMode();});
        window.selectContact = () => listeners.select({id:'contact',get:field => ({name:'Morgan <Customer>',emailAddress:'morgan@example.test'})[field]});
    });
    const trigger = page.getByRole('button',{name:'Send to one Contact',exact:true});
    await expect(trigger).toHaveCount(1);
    await expect(page.locator('[data-action="schedule-email"][data-id="single"]')).toHaveCount(0);
    await expect(page.locator('[data-action="schedule-email"][data-id="campaign"]')).toHaveCount(1);
    await page.evaluate(() => view.openEditor(null));
    const mode = page.getByRole('combobox',{name:'Delivery',exact:true});
    await expect(mode).toHaveValue('campaign');
    await mode.selectOption('single_send');
    await expect(page.locator('[name="startAt"]')).toBeHidden();
    expect(await page.evaluate(() => view.payload().deliveryMode)).toBe('single_send');
    expect(await page.evaluate(() => view.payload().startAt)).toBeNull();
    await mode.selectOption('transactional');
    await expect(page.locator('[name="campaignId"]')).toBeHidden();
    await expect(page.locator('[data-transactional-purpose] input')).toHaveValue('Customer service');
    await expect(page.locator('[data-transactional-purpose]')).toBeVisible();
    await expect(page.locator('[name="subject"]')).toBeVisible();
    await expect(page.locator('[name="fromAddress"]')).toBeVisible();
    expect(await page.evaluate(() => view.payload().deliveryMode)).toBe('transactional');
    expect(await page.evaluate(() => view.payload().startAt)).toBeNull();
    await mode.selectOption('campaign'); await expect(page.locator('[name="startAt"]')).toBeVisible();
    await expect(page.locator('[data-transactional-purpose]')).toBeHidden();
    await page.evaluate(() => view.openEditor({deliveryMode:'single_send',name:'Saved single send'}));
    await expect(mode).toHaveValue('single_send');
    await page.evaluate(() => view.closeEditor());
    await trigger.click();
    expect(await page.evaluate(() => selectorOptions.type)).toBe('views/modals/select-records');
    expect(await page.evaluate(() => selectorOptions.options)).toMatchObject({scope:'Contact',multiple:false,createButton:false});
    expect(await page.evaluate(() => requests.length)).toBe(0);
    await page.evaluate(() => selectContact());
    const dialog = page.getByRole('dialog',{name:'Send to one Contact',exact:true});
    await expect(dialog).toBeVisible();
    await expect(dialog.getByText('Morgan <Customer>',{exact:false})).toBeVisible();
    await expect(dialog.getByRole('button',{name:'Queue email',exact:true})).toBeFocused();
    await testInfo.attach('single-send-confirmation.png',{body:await page.screenshot({path:path.join(root,`test-results/single-send-${testInfo.project.name}.png`)}),contentType:'image/png'});
    const bounds = await dialog.boundingBox();
    expect(bounds.x).toBeGreaterThanOrEqual(0); expect(bounds.x+bounds.width).toBeLessThanOrEqual(page.viewportSize().width+1);
    expect(bounds.height).toBeLessThanOrEqual(page.viewportSize().height);
    await dialog.press('Escape'); await expect(dialog).toHaveCount(0); await expect(trigger).toBeFocused();
    expect(await page.evaluate(() => requests.length)).toBe(0);
    await trigger.click(); await page.evaluate(() => {selectContact(); window.rejectSend = true;});
    await dialog.getByRole('button',{name:'Queue email',exact:true}).click();
    await expect(dialog.getByRole('alert')).toHaveText('This Contact has not granted email consent.');
    await expect(dialog.getByRole('button',{name:'Queue email',exact:true})).toBeEnabled();
    expect(await page.evaluate(() => requests[0])).toEqual({url:'Nexa/marketing-emails/single/single-send',input:{contactId:'contact'}});
    await page.evaluate(() => {window.rejectSend = false; window.deferSend = true;});
    await dialog.getByRole('button',{name:'Queue email',exact:true}).click();
    await expect(dialog.getByRole('button',{name:'Queue email',exact:true})).toBeDisabled();
    await expect(dialog.getByRole('button',{name:'Cancel',exact:true})).toBeDisabled();
    await dialog.press('Escape'); await expect(dialog).toBeVisible();
    await page.evaluate(() => view.submitSingleSend({preventDefault(){}}));
    expect(await page.evaluate(() => requests.length)).toBe(2);
    await page.evaluate(() => releaseSend()); await expect(dialog).toHaveCount(0);
    expect(await page.evaluate(() => messages)).toEqual(['Existing email request: Pending.']);
    expect(await page.evaluate(() => reloads)).toBe(1);
    // Navigation invalidates late responses without displaying success in another workspace.
    await trigger.click(); await page.evaluate(() => selectContact());
    await dialog.getByRole('button',{name:'Queue email',exact:true}).click();
    await page.evaluate(() => {removal(); releaseSend();});
    await expect(dialog).toHaveCount(0);
    expect(await page.evaluate(() => messages.length)).toBe(1);
    await page.evaluate(() => {view.workspace.permissions.approve = false; view.renderEmails();});
    await expect(trigger).toHaveCount(0);
});
