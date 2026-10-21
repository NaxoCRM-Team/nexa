const {test,expect:baseExpect}=require('@playwright/test');
const expect=baseExpect.configure({timeout:20000});
const AxeBuilder=require('@axe-core/playwright').default;
const {spawn}=require('node:child_process');
const {randomUUID,randomBytes}=require('node:crypto');
const path=require('node:path');
const root=path.resolve(__dirname,'../..'),base=(process.env.NEXA_LIVE_URL||'').replace(/\/$/,'');
test.use({trace:'off',screenshot:'off',actionTimeout:15000,navigationTimeout:30000});
let cleanupTenants;
function fixture(command,tenants,extra={}){return new Promise((resolve,reject)=>{
    const child=spawn(process.env.NEXA_PHP_PATH||'php',[path.join(root,'tests/tenant/AutomationAcceptanceFixture.php')],{cwd:root,env:process.env,windowsHide:true,stdio:['pipe','pipe','pipe']});let out='',err='';
    const timer=setTimeout(()=>child.kill(),60000);child.stdout.on('data',v=>out+=v);child.stderr.on('data',v=>err+=v);child.on('error',reject);
    child.on('close',code=>{clearTimeout(timer);if(code)return reject(new Error(err||'SMS fixture failed.'));try{resolve(JSON.parse(out));}catch{reject(new Error('Invalid fixture result.'));}});
    child.stdin.end(JSON.stringify({command,tenants,...extra})+'\n');
});}
test.afterEach(async({},info)=>{info.setTimeout(60000);if(cleanupTenants){await fixture('cleanup',cleanupTenants);cleanupTenants=null;}});
async function login(page,tenant){
    await page.goto(`${base}/login/`,{waitUntil:'domcontentloaded'});
    await expect(page.locator('#field-userName')).toBeVisible({timeout:60000});
    await page.locator('#field-userName').fill(tenant.username);await page.locator('#field-password').fill(tenant.password);
    const authenticated=page.waitForResponse(r=>r.url().includes('/App/user')&&!!r.request().headers()['espo-authorization'],{timeout:60000});
    await page.locator('#login-form button[type="submit"]').click();expect((await authenticated).status()).toBe(200);
    await expect(page).toHaveURL(new RegExp(`/w/${tenant.slug}(?:/.*)?$`),{timeout:60000});await page.waitForFunction(()=>document.body.classList.contains('nexa-shell-ready'),null,{timeout:30000});
}

test('Administrator reviews signed SMS opt-in without changing consent or enabling real dispatch',async({page},info)=>{
    test.setTimeout(180000);test.skip(process.env.NEXA_AUTOMATION_ACCEPTANCE!=='1'||!base,'Explicit localhost acceptance required.');
    expect(['localhost','127.0.0.1']).toContain(new URL(base).hostname);
    const suffix=randomBytes(6).toString('hex');let tenants=['a','b'].map(label=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${label}`}));
    try{
        tenants=await fixture('setup',tenants);cleanupTenants=tenants;const seed=await fixture('sms-dispatch-seed',tenants,{count:0,review:true});
        await login(page,tenants[0]);
        await page.goto(`${base}/w/${tenants[0].slug}/NexaConversations/sms`);await page.locator(`.nc-conversation[data-id="${seed.rootId}"]`).click();
        await expect(page.locator('.sms-opt-in-review')).toBeVisible();await expect(page.locator('.sms-compose')).toHaveCount(0);
        await page.getByRole('textbox',{name:'Review reason',exact:true}).fill('Reviewed signed START and fresh explicit purpose consent.');
        await page.getByRole('button',{name:'Release suppression',exact:true}).click();
        await expect(page.locator('.sms-opt-in-review')).toHaveCount(0);await expect(page.locator('.sms-review-status')).toContainText('Suppression released for:');
        await expect(page.locator('.sms-preference-status')).toContainText('Historical SMS opt-out retained.');await expect(page.locator('.sms-preference-status')).not.toHaveClass(/is-blocked/);
        await expect(page.locator('.sms-compose')).toBeVisible();await expect(page.getByRole('button',{name:'Queue SMS',exact:true})).toBeDisabled();
        expect((await new AxeBuilder({page}).include('.nexa-sms-workspace').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze()).violations).toEqual([]);
        await page.locator('.nc-thread').screenshot({path:path.join(root,`test-results/sms-opt-in-${info.project.name}.png`)});
    }finally{if(cleanupTenants){await fixture('cleanup',cleanupTenants);cleanupTenants=null;}}
});
test('SMS composer saves and cancels drafts, then tracks fake-worker delivery without activating real sending',async({page},info)=>{
    test.setTimeout(180000);test.skip(process.env.NEXA_AUTOMATION_ACCEPTANCE!=='1'||!base,'Explicit localhost acceptance required.');
    expect(['localhost','127.0.0.1']).toContain(new URL(base).hostname);
    const suffix=randomBytes(6).toString('hex');let tenants=['a','b'].map(label=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${label}`}));
    try{
        tenants=await fixture('setup',tenants);cleanupTenants=tenants;const seed=await fixture('sms-dispatch-seed',tenants,{count:0});
        await login(page,tenants[0]);
        await page.goto(`${base}/w/${tenants[0].slug}/NexaConversations/sms`);await page.locator(`.nc-conversation[data-id="${seed.rootId}"]`).click();
        await expect(page.locator('.sms-compose')).toBeVisible();await expect(page.getByRole('button',{name:'Queue SMS',exact:true})).toBeDisabled();
        await page.getByRole('combobox',{name:'Purpose',exact:true}).selectOption(seed.purposeId);
        await page.getByRole('textbox',{name:'Message',exact:true}).fill('A draft to cancel');await page.getByRole('button',{name:'Save draft',exact:true}).click();
        await expect(page.locator('.sms-outgoing')).toHaveCount(1);await expect(page.locator('.sms-outgoing')).toContainText('A draft to cancel');
        await page.getByRole('button',{name:'Cancel SMS',exact:true}).click();await expect(page.locator('.sms-outgoing .sms-delivery-status')).toContainText('cancelled');
        await page.getByRole('combobox',{name:'Purpose',exact:true}).selectOption(seed.purposeId);
        const body='SMS composer <img src=x onerror="window.smsComposerXss=true">';await page.getByRole('textbox',{name:'Message',exact:true}).fill(body);
        const prepared=page.waitForResponse(r=>r.url().includes('/Nexa/messaging/conversations')&&r.request().postDataJSON()?.op==='prepareSms');
        await page.getByRole('button',{name:'Save draft',exact:true}).click();const saved=await (await prepared).json();
        const outgoing=page.locator(`.sms-outgoing[data-outbox-id="${saved.id}"]`);await expect(outgoing).toContainText(body);
        expect(await page.evaluate(()=>window.smsComposerXss)).toBeUndefined();await expect(outgoing.locator('img')).toHaveCount(0);
        await fixture('sms-dispatch-release',tenants,{outboxId:saved.id});const ran=await fixture('sms-dispatch-run',tenants);expect(ran.posts).toBe(1);
        await expect(outgoing.locator('.sms-delivery-status')).toContainText('queued',{timeout:30000});
        await expect(outgoing.getByRole('button',{name:'Cancel SMS',exact:true})).toHaveCount(0);
        await fixture('sms-dispatch-poll',tenants,{outboxId:saved.id});await expect(outgoing.locator('.sms-delivery-status')).toContainText('delivered',{timeout:30000});
        const timeline=await page.evaluate(async id=>(await Espo.Ajax.getRequest(`Nexa/customer/Contact/${id}/foundation`)).messaging,tenants[0].contactId);
        expect(timeline).toHaveLength(1);expect(timeline[0]).toMatchObject({direction:'outgoing',deliveryStatus:'delivered',body});
        expect((await new AxeBuilder({page}).include('.nexa-sms-workspace').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze()).violations).toEqual([]);
        await page.locator('.nc-thread').screenshot({path:path.join(root,`test-results/sms-dispatch-${info.project.name}.png`)});
    }finally{if(cleanupTenants){await fixture('cleanup',cleanupTenants);cleanupTenants=null;}}
});
