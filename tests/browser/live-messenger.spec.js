const {test,expect:baseExpect}=require('@playwright/test');
const expect=baseExpect.configure({timeout:15000});
const {spawn}=require('node:child_process');
const {randomUUID,randomBytes}=require('node:crypto');
const path=require('node:path');
const AxeBuilder=require('@axe-core/playwright').default;
const root=path.resolve(__dirname,'../..'),base=(process.env.NEXA_LIVE_URL||'').replace(/\/$/,'');
test.use({trace:'off',screenshot:'off',actionTimeout:15000,navigationTimeout:30000});
function fixture(command,tenants,extra={}){return new Promise((resolve,reject)=>{const child=spawn(process.env.NEXA_PHP_PATH||'php',[path.join(root,'tests/tenant/AutomationAcceptanceFixture.php')],{cwd:root,env:process.env,windowsHide:true});let out='',err='';const timer=setTimeout(()=>child.kill(),60000);child.stdout.on('data',v=>out+=v);child.stderr.on('data',v=>err+=v);child.on('error',reject);child.on('close',code=>{clearTimeout(timer);if(code)return reject(new Error(err||'Messenger fixture failed.'));try{resolve(JSON.parse(out));}catch{reject(new Error('Invalid Messenger fixture output.'));}});child.stdin.end(JSON.stringify({command,tenants,...extra})+'\n');});}
test('Messenger settings, verified webhook and native conversation UI work without enabling live sends',async({page},info)=>{
    test.setTimeout(180000);test.skip(process.env.NEXA_AUTOMATION_ACCEPTANCE!=='1'||!base,'Explicit local acceptance required.');expect(['localhost','127.0.0.1']).toContain(new URL(base).hostname);
    const suffix=randomBytes(6).toString('hex');let tenants=['a','b'].map(v=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${v}`}));let created=false;
    try{
        tenants=await fixture('setup',tenants);created=true;const seed=await fixture('messenger-seed',tenants);
        const callback=`${base}/api/v1/Nexa/public/messenger/${seed.connectionId}`;
        const challenge=await page.request.get(`${callback}?${seed.challenge}`);expect(challenge.status()).toBe(200);expect(await challenge.text()).toBe('123');
        const spoof=await page.request.post(callback,{headers:{'Content-Type':'application/json','X-Hub-Signature-256':'sha256='+'0'.repeat(64)},data:seed.raw});expect(spoof.status()).toBe(403);
        const received=await page.request.post(callback,{headers:{'Content-Type':'application/json','X-Hub-Signature-256':seed.signature},data:seed.raw});expect(received.status()).toBe(200);
        const repeat=await page.request.post(callback,{headers:{'Content-Type':'application/json','X-Hub-Signature-256':seed.signature},data:seed.raw});expect(repeat.status()).toBe(200);
        await page.goto(`${base}/login/`);await page.locator('#field-userName').fill(tenants[0].username);await page.locator('#field-password').fill(tenants[0].password);const auth=page.waitForResponse(r=>r.url().includes('/App/user')&&!!r.request().headers()['espo-authorization'],{timeout:60000});await page.locator('#login-form button[type="submit"]').click();expect((await auth).status()).toBe(200);await expect(page).toHaveURL(new RegExp(`/w/${tenants[0].slug}(?:/.*)?$`),{timeout:60000});await page.waitForFunction(()=>document.body.classList.contains('nexa-shell-ready'),null,{timeout:60000});
        await page.goto(`${base}/w/${tenants[0].slug}/NexaConversations/messengerSettings`);await expect(page.getByRole('heading',{name:'Messenger Connections',exact:true})).toBeVisible({timeout:60000});
        await page.locator('.messenger-choice').selectOption(seed.connectionId);await expect(page.getByLabel('Page access token',{exact:true})).toHaveValue('');await expect(page.getByLabel('App secret',{exact:true})).toHaveValue('');await expect(page.getByRole('button',{name:'Save reply setting',exact:true})).toBeVisible();
        await page.goto(`${base}/w/${tenants[0].slug}/NexaConversations/messenger`);await expect(page.locator('.nc-conversation')).toHaveCount(1);const rootId=await page.locator('.nc-conversation').getAttribute('data-id');await page.locator('.nc-conversation').click();
        await expect(page.locator('.nc-message-body')).toContainText('Customer asks <img');await expect(page.locator('.nc-message-body img')).toHaveCount(0);expect(await page.evaluate(()=>window.messengerXss)).toBeUndefined();await expect(page.getByRole('button',{name:'Send reply',exact:true})).toBeDisabled();
        await expect(page.getByRole('button',{name:'Link Contact',exact:true})).toBeVisible();
        const linked=await page.evaluate(async({rootId,contactId})=>{const thread=await Espo.Ajax.postRequest('Nexa/messenger/conversations',{op:'thread',id:rootId});await Espo.Ajax.postRequest('Nexa/messenger/conversations',{op:'linkContact',id:rootId,version:thread.conversation.version,contactId});return Espo.Ajax.getRequest(`Nexa/customer/Contact/${contactId}/foundation`);},{rootId,contactId:tenants[0].contactId});
        expect(linked.messaging.some(message=>message.channel==='messenger'&&message.body.includes('Customer asks'))).toBe(true);
        const requestKey=randomUUID();await fixture('messenger-fake-reply',tenants,{rootId,requestKey});await page.getByRole('button',{name:'Refresh Messenger',exact:true}).click();await expect(page.locator('.messenger-reply-status')).toContainText('accepted');
        const replay=await page.evaluate(async({rootId,requestKey})=>{const t=await Espo.Ajax.postRequest('Nexa/messenger/conversations',{op:'thread',id:rootId});return Espo.Ajax.postRequest('Nexa/messenger/conversations',{op:'reply',id:rootId,version:t.conversation.version,body:'Synthetic service response, no real delivery.',requestKey});},{rootId,requestKey});expect(replay).toMatchObject({duplicate:true,status:'accepted'});
        await fixture('messenger-enhance',tenants,{rootId});await page.getByRole('button',{name:'Refresh Messenger',exact:true}).click();await expect(page.locator('.messenger-reply-status')).toHaveText('read');
        await expect(page.locator('.messenger-opt-in-review')).toBeVisible();await page.locator('[data-action="downloadMedia"]').click();await expect(page.locator('.messenger-media img')).toHaveJSProperty('naturalWidth',2);
        await page.locator('.messenger-opt-in-review [name="reason"]').fill('Reviewed the renewed service request.');await page.locator('.messenger-opt-in-review [name="evidence"]').fill('The verified Page visitor sent START and requested support.');await page.locator('.messenger-opt-in-review [name="policyVersion"]').fill('1.0');
        expect(await page.evaluate(()=>{const history=document.querySelector('.nc-messages'),review=document.querySelector('.messenger-opt-in-review');return review.getBoundingClientRect().top>=history.getBoundingClientRect().bottom-1;})).toBe(true);
        expect((await new AxeBuilder({page}).include('.nexa-messenger-workspace').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze()).violations).toEqual([]);await page.locator('.nexa-messenger-workspace').screenshot({path:path.join(root,`test-results/messenger-${info.project.name}.png`)});
        await page.getByRole('button',{name:'Release service-reply suppression',exact:true}).click();await expect(page.locator('.messenger-opt-in-review')).toHaveCount(0);await expect(page.locator('.messenger-opt-out-status')).toHaveCount(0);await expect(page.getByRole('button',{name:'Send reply',exact:true})).toBeDisabled();
        const foundationLoaded=page.waitForResponse(response=>response.url().includes(`/Nexa/customer/Contact/${tenants[0].contactId}/foundation`),{timeout:30000});
        await page.goto(`${base}/w/${tenants[0].slug}/Contact/view/${tenants[0].contactId}`);
        const foundationResponse=await foundationLoaded;expect(foundationResponse.status()).toBe(200);expect((await foundationResponse.json()).messaging.some(message=>message.channel==='messenger')).toBe(true);
        await page.locator('[data-nexa-tab="activity"]').click();
        await page.locator('[data-nexa-activity-type]').selectOption('messenger');
        await expect(page.locator('[data-nexa-tab-panel="activity"]')).toContainText('Messenger received',{timeout:30000});
        await expect(page.locator('[data-nexa-tab-panel="activity"]')).toContainText('Customer asks');
        await page.locator('[data-nexa-tab-panel="activity"]').screenshot({path:path.join(root,`test-results/messenger-customer-timeline-${info.project.name}.png`)});
    }finally{if(created)await fixture('cleanup',tenants);}
});
