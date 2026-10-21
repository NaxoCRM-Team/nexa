const {test,expect}=require('@playwright/test');
const {spawn}=require('node:child_process');
const {randomUUID,randomBytes}=require('node:crypto');
const path=require('node:path');
const AxeBuilder=require('@axe-core/playwright').default;
const root=path.resolve(__dirname,'../..'),base=(process.env.NEXA_LIVE_URL||'').replace(/\/$/,'');
test.use({trace:'off',screenshot:'off',actionTimeout:15000,navigationTimeout:30000});
function fixture(command,tenants,extra={}){return new Promise((resolve,reject)=>{
    const child=spawn(process.env.NEXA_PHP_PATH||'php',[path.join(root,'tests/tenant/AutomationAcceptanceFixture.php')],{cwd:root,env:process.env,windowsHide:true});let out='',err='';const timer=setTimeout(()=>child.kill(),60000);
    child.stdout.on('data',v=>out+=v);child.stderr.on('data',v=>err+=v);child.on('error',reject);child.on('close',code=>{clearTimeout(timer);if(code)return reject(new Error(err||'WhatsApp fixture failed.'));try{resolve(JSON.parse(out));}catch{reject(new Error('Invalid fixture result.'));}});
    child.stdin.end(JSON.stringify({command,tenants,...extra})+'\n');
});}
test('WhatsApp inbox, template composer, tenant boundaries and draft cancellation',async({page},info)=>{
    test.setTimeout(180000);test.skip(process.env.NEXA_AUTOMATION_ACCEPTANCE!=='1'||!base,'Explicit local acceptance required.');expect(['localhost','127.0.0.1']).toContain(new URL(base).hostname);
    const suffix=randomBytes(6).toString('hex');let tenants=['a','b'].map(v=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${v}`}));let created=false;
    try{
        tenants=await fixture('setup',tenants);created=true;
        const first=await fixture('whatsapp-dispatch-seed',tenants),second=await fixture('whatsapp-dispatch-seed',tenants,{index:1});expect(first.posts).toBe(1);expect(second.posts).toBe(1);
        await page.goto(`${base}/login/`);await page.locator('#field-userName').fill(tenants[0].username);await page.locator('#field-password').fill(tenants[0].password);
        const authenticated=page.waitForResponse(r=>r.url().includes('/App/user')&&!!r.request().headers()['espo-authorization'],{timeout:60000});await page.locator('#login-form button[type="submit"]').click();expect((await authenticated).status()).toBe(200);
        await expect(page).toHaveURL(new RegExp(`/w/${tenants[0].slug}(?:/.*)?$`),{timeout:60000});await page.waitForFunction(()=>document.body.classList.contains('nexa-shell-ready'),null,{timeout:60000});
        await page.goto(`${base}/w/${tenants[0].slug}/NexaConversations/whatsapp`);
        await expect(page.locator('.nc-conversation')).toHaveCount(1);await page.locator('.nc-conversation').click();
        await expect(page.locator('.nc-thread-heading h4')).toHaveText('whatsapp:+14155550101');
        await expect(page.locator('.nc-message-body').filter({hasText:'Can you help with my service?'})).toContainText('<img src=x onerror=alert(1)>');await expect(page.locator('.nc-message-body img')).toHaveCount(0);
        await expect(page.locator('.sms-outgoing')).toHaveCount(2);await expect(page.getByRole('button',{name:'Queue WhatsApp',exact:true}).first()).toBeDisabled();
        await page.getByRole('combobox',{name:'Message type',exact:true}).selectOption(first.contentSid);await expect(page.locator('.wa-template-preview')).toHaveText('Hello {{1}}, your service is ready.');await page.getByLabel('Value 1',{exact:true}).fill('Ada');
        await expect(page.locator('.sms-body')).toBeHidden();await page.getByRole('combobox',{name:'Purpose',exact:true}).selectOption({index:1});
        expect((await new AxeBuilder({page}).include('.nexa-conversations').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze()).violations).toEqual([]);
        const boundary=await page.evaluate(async id=>{try{await Espo.Ajax.postRequest('Nexa/whatsapp/conversations',{op:'thread',id});return 200;}catch(error){return error.status;}},second.rootId);expect([403,404]).toContain(boundary);
        await page.locator(`[data-outbox-id="${first.draftId}"] [data-action="cancelSms"]`).click();await expect(page.locator(`[data-outbox-id="${first.draftId}"] .sms-delivery-status`)).toContainText('cancelled');
        await expect(page.locator(`[data-outbox-id="${first.queuedId}"] .sms-delivery-status`)).toContainText('queued');
        await page.locator('.nexa-conversations').screenshot({path:path.join(root,`test-results/whatsapp-inbox-${info.project.name}.png`)});
    }finally{if(created)await fixture('cleanup',tenants);}
});
