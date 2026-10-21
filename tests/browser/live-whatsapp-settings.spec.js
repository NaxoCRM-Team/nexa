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
test('WhatsApp settings distinguish approved sender proof, template inspection and disabled sending',async({page},info)=>{
    test.setTimeout(180000);test.skip(process.env.NEXA_AUTOMATION_ACCEPTANCE!=='1'||!base,'Explicit local acceptance required.');expect(['localhost','127.0.0.1']).toContain(new URL(base).hostname);
    const suffix=randomBytes(6).toString('hex');let tenants=['a','b'].map(v=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${v}`}));let created=false;
    try{
        tenants=await fixture('setup',tenants);created=true;
        await page.goto(`${base}/login/`);await page.locator('#field-userName').fill(tenants[0].username);await page.locator('#field-password').fill(tenants[0].password);
        const authenticated=page.waitForResponse(r=>r.url().includes('/App/user')&&!!r.request().headers()['espo-authorization'],{timeout:60000});await page.locator('#login-form button[type="submit"]').click();expect((await authenticated).status()).toBe(200);
        await expect(page).toHaveURL(new RegExp(`/w/${tenants[0].slug}(?:/.*)?$`),{timeout:60000});
        await page.waitForFunction(()=>document.body.classList.contains('nexa-shell-ready'),null,{timeout:60000});
        await page.goto(`${base}/w/${tenants[0].slug}/NexaConversations/messaging`);
        await page.getByLabel('Name',{exact:true}).fill('Synthetic WhatsApp sender');await page.getByRole('combobox',{name:'Channel',exact:true}).selectOption('whatsapp');
        await page.getByLabel('Account SID',{exact:true}).fill('AC'+randomBytes(16).toString('hex'));await page.getByLabel('Sender',{exact:true}).fill('whatsapp:+14155550100');await page.getByLabel('Auth token',{exact:true}).fill(randomBytes(16).toString('hex'));
        await page.getByRole('button',{name:'Save connection',exact:true}).click();await expect(page.getByRole('button',{name:'Verify WhatsApp sender',exact:true})).toBeVisible();
        await expect(page.getByLabel('WhatsApp Sender SID',{exact:true})).toBeVisible();await expect(page.locator('.whatsapp-template-form')).toHaveCount(0);
        const row=(await page.evaluate(()=>Espo.Ajax.postRequest('Nexa/messaging/connections',{op:'list'}))).list[0];
        await fixture('whatsapp-settings-proof',tenants,{connectionId:row.id});await page.reload();await page.waitForFunction(()=>document.body.classList.contains('nexa-shell-ready'),null,{timeout:60000});await page.locator('.messaging-choice').selectOption(row.id);
        await expect(page.getByText('WhatsApp sender verified',{exact:true})).toBeVisible();await expect(page.locator('.whatsapp-template-form')).toBeVisible();
        await expect(page.getByLabel('WhatsApp Sender SID',{exact:true})).toHaveValue('XE'+'7'.repeat(32));
        await expect(page.getByRole('button',{name:'Check template approval',exact:true})).toBeVisible();await expect(page.getByLabel('Enable WhatsApp sending',{exact:true})).toBeDisabled();
        expect((await new AxeBuilder({page}).include('.nexa-chat-settings').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze()).violations).toEqual([]);
        await page.locator('.nexa-chat-settings').screenshot({path:path.join(root,`test-results/whatsapp-settings-${info.project.name}.png`)});
    }finally{if(created)await fixture('cleanup',tenants);}
});
