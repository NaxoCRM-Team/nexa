const {test,expect:baseExpect}=require('@playwright/test');
const expect=baseExpect.configure({timeout:15000});
const AxeBuilder=require('@axe-core/playwright').default;
const {spawn}=require('node:child_process');
const {randomUUID,randomBytes}=require('node:crypto');
const path=require('node:path');
const root=path.resolve(__dirname,'../..'),base=(process.env.NEXA_LIVE_URL||'').replace(/\/$/,'');
test.use({trace:'off',screenshot:'off',actionTimeout:15000,navigationTimeout:30000});
let cleanupTenants;
function fixture(command,tenants){return new Promise((resolve,reject)=>{
    const child=spawn(process.env.NEXA_PHP_PATH||'php',[path.join(root,'tests/tenant/AutomationAcceptanceFixture.php')],{cwd:root,env:process.env,windowsHide:true,stdio:['pipe','pipe','pipe']});let out='',err='';
    const timer=setTimeout(()=>{child.kill();reject(new Error('Chat fixture timed out.'));},60000);
    child.stdout.on('data',value=>{out+=value;});child.stderr.on('data',value=>{err+=value;});child.on('error',error=>{clearTimeout(timer);reject(error);});
    child.on('close',code=>{clearTimeout(timer);if(code)return reject(new Error(err));try{resolve(JSON.parse(out));}catch{reject(new Error('Invalid chat fixture response.'));}});
    child.stdin.end(JSON.stringify({command,tenants})+'\n');
});}
test.afterEach(async({},info)=>{info.setTimeout(60000);if(cleanupTenants){await fixture('cleanup',cleanupTenants);cleanupTenants=null;}});
test('Published guided bot delivers choices and hands the visitor to staff',async({page,browser},info)=>{
    test.setTimeout(180000);test.skip(process.env.NEXA_AUTOMATION_ACCEPTANCE!=='1'||!base,'Explicit local chat acceptance is required.');
    expect(['localhost','127.0.0.1']).toContain(new URL(base).hostname);
    const suffix=randomBytes(6).toString('hex');let tenants=['a','b'].map(label=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${label}`}));
    let visitorContext;
    try {
        tenants=await fixture('setup',tenants);cleanupTenants=tenants;await login(page,tenants[0]);
        await page.goto(`${base}/w/${tenants[0].slug}/NexaConversations/widgets`);
        await expect(page.locator('.chat-widget-form')).toBeVisible({timeout:60000});await page.locator('[name="origins"]').fill('http://127.0.0.1:8099');await page.locator('[name="enabled"]').check();
        await page.locator('.chat-widget-form button[type="submit"]').click();await expect(page.locator('.chat-bot-form')).toBeVisible({timeout:15000});
        await page.locator('[name="botEnabled"]').check();await page.locator('[name="intro"]').fill('Automated welcome');
        await page.getByRole('button',{name:'Publish chat flow',exact:true}).click();
        await expect(page.getByText('Chat flow published',{exact:true})).toBeVisible();
        const widget=await page.evaluate(async()=> (await Espo.Ajax.postRequest('Nexa/chat',{op:'widgets'})).list[0]);
        expect(Number(widget.bot_enabled)).toBe(1);
        const endpoint=`${base}/api/v1/Nexa/public/chat/${widget.public_key}`;
        visitorContext=await browser.newContext({viewport:info.project.use.viewport});
        await visitorContext.grantPermissions(['local-network-access'],{origin:'http://127.0.0.1:8099'});
        const visitor=await visitorContext.newPage();let dropped=false;
        await visitor.route(`${base}/api/v1/Nexa/public/chat/*`,async route=>{
            if(route.request().method()==='POST'&&route.request().postDataJSON()?.op==='choose'&&!dropped){dropped=true;await route.fetch();await route.abort();return;}
            await route.continue();
        });
        await visitor.route('http://127.0.0.1:8099/',route=>route.fulfill({contentType:'text/html',body:`<!doctype html><html lang="en"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bot acceptance</title><body><h1>Support</h1><script src="${base}/client/custom/nexa-chat-widget.js" data-nexa-chat-api="${endpoint}" defer></script></body></html>`}));
        await visitor.goto('http://127.0.0.1:8099/');await visitor.getByRole('button',{name:'Open chat'}).click();
        await visitor.getByLabel('Message',{exact:true}).fill('Hello');await visitor.getByRole('button',{name:'Send',exact:true}).click();
        await expect(visitor.getByText('Automated welcome',{exact:false})).toBeVisible();
        await expect(visitor.getByRole('button',{name:'Speak to our team',exact:true})).toBeVisible();
        await visitor.getByRole('button',{name:'Speak to our team',exact:true}).click();
        // Lost choice response may be recovered by polling before the user retries.
        await expect(visitor.getByText('You are waiting for an available agent. You can continue writing here.',{exact:true})).toBeVisible({timeout:20000});
        await expect(visitor.getByRole('button',{name:'Speak to our team',exact:true})).toHaveCount(0);
        await expect(visitor.locator('[data-nexa-chat-widget]').locator('.message')).toHaveCount(5);
        const session=await visitor.evaluate(endpoint=>JSON.parse(sessionStorage.getItem('nexa-chat:'+endpoint)),endpoint);
        await page.goto(`${base}/w/${tenants[0].slug}/NexaConversations/chat`);
        await page.locator(`.nc-conversation[data-id="${session.id}"]`).click();
        await expect(page.getByText('Waiting for an available agent',{exact:true})).toBeVisible();
        await page.getByLabel('Reply',{exact:true}).fill('Your support agent is here.');await page.locator('.chat-compose button[type="submit"]').click();
        await expect(visitor.getByText('A staff member has replied.',{exact:true})).toBeVisible({timeout:20000});
        await identifyChat(page,tenants[0].contactId,session);
        const kinds=await page.evaluate(async contactId=>(await Espo.Ajax.getRequest(`Nexa/customer/Contact/${contactId}/foundation`)).liveChat.map(message=>message.author),tenants[0].contactId);
        expect(kinds).toContain('visitor');expect(kinds).toContain('staff');expect(kinds).toContain('bot');
        expect((await new AxeBuilder({page:visitor}).include('[data-nexa-chat-widget]').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze()).violations).toEqual([]);
        await visitor.screenshot({path:path.join(root,`test-results/live-chat-bot-${info.project.name}.png`)});
    } finally {await visitorContext?.close();if(cleanupTenants){await fixture('cleanup',cleanupTenants);cleanupTenants=null;}}
});
async function login(page,tenant){
    await page.goto(`${base}/login/`,{waitUntil:'domcontentloaded'});await page.locator('#field-userName').fill(tenant.username);await page.locator('#field-password').fill(tenant.password);
    await page.locator('#login-form button[type="submit"]').click();await expect(page).toHaveURL(new RegExp(`/w/${tenant.slug}(?:/.*)?$`),{timeout:30000});
    await page.waitForFunction(()=>document.body.classList.contains('nexa-shell-ready'),null,{timeout:30000});
}
async function identifyChat(page,contactId,session){
    await page.evaluate(async({contactId,session})=>{
        const workspace=await Espo.Ajax.getRequest('Nexa/consent/cookies/workspace');
        const banner={...workspace.banner,isPublished:true};
        await Espo.Ajax.putRequest('Nexa/consent/cookies/settings',banner);
        await Espo.Ajax.postRequest('Nexa/public/cookies/receipts',{publicKey:banner.publicKey,visitorId:session.visitorKey,receiptKey:crypto.randomUUID(),choice:'custom',categories:{preferences:true,advertising:true}});
        const proof=await Espo.Ajax.postRequest('Nexa/personalization/identify',{visitorKey:session.visitorKey,contactId,method:'authenticated_session',reference:`chat-acceptance-${session.id}`});
        if(!proof.verified)throw new Error('Synthetic visitor consent and backend proof are required.');
        await Espo.Ajax.postRequest('Nexa/chat',{op:'identify',id:session.id,visitorKey:session.visitorKey});
    },{contactId,session});
}
test('Website widget and native-role staff chat deliver safely across tenant boundaries',async({page,browser},info)=>{
    test.setTimeout(240000);test.skip(process.env.NEXA_AUTOMATION_ACCEPTANCE!=='1'||!base,'Explicit local chat acceptance is required.');
    expect(['localhost','127.0.0.1']).toContain(new URL(base).hostname);
    const suffix=randomBytes(6).toString('hex');let tenants=['a','b'].map(label=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${label}`}));
    let visitorContext,betaContext,failure;
    try{
        tenants=await fixture('setup',tenants);cleanupTenants=tenants;await login(page,tenants[0]);
        await page.goto(`${base}/w/${tenants[0].slug}/NexaConversations/widgets`);
        await expect(page.locator('.chat-widget-form')).toBeVisible({timeout:60000});
        await page.locator('[name="name"]').fill('Website support');await page.locator('[name="title"]').fill('Customer support');
        await page.locator('[name="welcome"]').fill('Hello, how can we help?');await page.locator('[name="origins"]').fill('http://127.0.0.1:8099');
        await page.locator('[name="enabled"]').check();await page.locator('.chat-widget-form button[type="submit"]').click();
        await expect(page.locator('#chat-embed-code')).toBeVisible();
        const widgets=await page.evaluate(()=>Espo.Ajax.postRequest('Nexa/chat',{op:'widgets'}));const widget=widgets.list[0];
        const endpoint=`${base}/api/v1/Nexa/public/chat/${widget.public_key}`;
        visitorContext=await browser.newContext({viewport:info.project.use.viewport||{width:390,height:844}});
        // Explicit localhost permission for this synthetic website, not a CORS/security bypass.
        await visitorContext.grantPermissions(['local-network-access'],{origin:'http://127.0.0.1:8099'});
        const visitor=await visitorContext.newPage();let dropped=false;
        await visitor.route(`${base}/api/v1/Nexa/public/chat/*`,async route=>{
            if(route.request().method()==='POST'&&route.request().postDataJSON()?.op==='start'&&!dropped){dropped=true;await route.fetch();await route.abort('failed');return;}
            await route.continue();
        });
        await visitor.route('http://127.0.0.1:8099/',route=>route.fulfill({contentType:'text/html',body:`<!doctype html><html lang="en"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Customer support test</title><body><h1>Customer support</h1><script src="${base}/client/custom/nexa-chat-widget.js" data-nexa-chat-api="${endpoint}" defer></script></body></html>`}));
        await visitor.goto('http://127.0.0.1:8099/');await visitor.getByRole('button',{name:'Open chat'}).click();
        await expect(visitor.getByText('Hello, how can we help?',{exact:true})).toBeVisible();
        const text=`A website question ${suffix} <img src=x onerror="window.chatXss=true">`;
        await visitor.getByLabel('Message',{exact:true}).fill(text);await visitor.getByRole('button',{name:'Send',exact:true}).click();
        await expect(visitor.getByRole('alert')).toBeVisible();await expect(visitor.getByLabel('Message',{exact:true})).toHaveValue(text);
        await visitor.getByRole('button',{name:'Send',exact:true}).click();await expect(visitor.locator('[data-nexa-chat-widget]').locator('.message')).toHaveCount(1);
        expect(await visitor.evaluate(()=>window.chatXss)).toBeUndefined();
        await visitor.getByRole('button',{name:'Request staff help',exact:true}).click();
        await expect(visitor.getByText('You are waiting for an available agent. You can continue writing here.',{exact:true})).toBeVisible();
        const session=await visitor.evaluate(endpoint=>JSON.parse(sessionStorage.getItem('nexa-chat:'+endpoint)),endpoint);
        await page.goto(`${base}/w/${tenants[0].slug}/NexaConversations/chat`);await expect(page.locator('.nc-conversation')).toHaveCount(1,{timeout:15000});
        await page.locator('[data-action="open"]').click();await expect(page.locator('.nc-message-body')).toHaveText(text);
        await page.getByRole('searchbox',{name:'Search chat staff'}).fill('Acceptance');
        await expect(page.locator('.chat-owner option')).toHaveCount(2);
        await page.getByLabel('Reply',{exact:true}).fill('Thanks. Our team can help.');await page.locator('.chat-compose button[type="submit"]').click();
        await expect(visitor.locator('[data-nexa-chat-widget]').locator('.message')).toHaveCount(2,{timeout:20000});
        await expect(visitor.getByText('Thanks. Our team can help.',{exact:false})).toBeVisible();
        await expect(visitor.getByText('A staff member has replied.',{exact:true})).toBeVisible();
        const native=await page.evaluate(async id=>{try{return {status:200,data:await Espo.Ajax.getRequest(`NexaChatConversation/${id}`)};}catch(error){return {status:error.status};}},session.id);
        if(native.status===200)for(const field of ['tokenHash','visitorKeyHash','startNonce','widgetId','origin'])expect(native.data).not.toHaveProperty(field);
        else expect([403,404]).toContain(native.status);
        betaContext=await browser.newContext();const betaPage=await betaContext.newPage();await login(betaPage,tenants[1]);
        const cross=await betaPage.evaluate(async id=>{try{await Espo.Ajax.postRequest('Nexa/chat',{op:'thread',id});return true;}catch{return false;}},session.id);expect(cross).toBe(false);
        const betaList=await betaPage.evaluate(()=>Espo.Ajax.postRequest('Nexa/chat',{op:'list'}));expect(betaList.list).toEqual([]);
        const forged=await visitor.request.post(endpoint,{headers:{Origin:'http://127.0.0.1:8099','X-Nexa-Chat-Token':session.token},data:{op:'send',id:session.id,nonce:randomUUID(),body:'Forged identity',contactId:'not-trusted'}});
        expect([400,403]).toContain(forged.status());
        const restored=visitor.waitForResponse(response=>response.url()===endpoint&&response.request().method()==='POST'&&response.request().postDataJSON()?.op==='read'&&response.request().postDataJSON()?.id===session.id&&response.request().postDataJSON()?.after===undefined&&response.request().postDataJSON()?.before===undefined,{timeout:30000});
        await visitor.reload();await visitor.getByRole('button',{name:'Open chat'}).click();
        const resumed=await restored;expect(resumed.status()).toBe(200);expect((await resumed.json()).messages).toHaveLength(2);
        await expect(visitor.locator('[data-nexa-chat-widget]').locator('.message')).toHaveCount(2);
        await visitor.getByLabel('Message',{exact:true}).fill('Another question');await visitor.getByRole('button',{name:'Send',exact:true}).click();
        await expect(visitor.locator('[data-nexa-chat-widget]').locator('.message')).toHaveCount(3);
        const launcher=visitor.getByRole('button',{name:'Open chat'});await visitor.getByRole('button',{name:'Close chat'}).click();
        const before=await launcher.boundingBox();await launcher.focus();await launcher.press('ArrowLeft');const after=await launcher.boundingBox();expect(after.x).toBeLessThan(before.x);
        await visitor.mouse.move(after.x+after.width/2,after.y+after.height/2);await visitor.mouse.down();
        await visitor.mouse.move(after.x+after.width/2-60,after.y+after.height/2-20,{steps:8});await visitor.mouse.up();
        expect((await launcher.boundingBox()).x).toBeLessThan(after.x-20);
        await launcher.click();await expect(visitor.getByRole('dialog',{name:'Website chat'})).toBeVisible();
        const bounds=await visitor.getByRole('dialog',{name:'Website chat'}).boundingBox();const viewport=visitor.viewportSize();expect(bounds.x).toBeGreaterThanOrEqual(0);expect(bounds.y).toBeGreaterThanOrEqual(0);expect(bounds.x+bounds.width).toBeLessThanOrEqual(viewport.width+1);expect(bounds.y+bounds.height).toBeLessThanOrEqual(viewport.height+1);
        await expect(page.locator('.nc-message')).toHaveCount(3,{timeout:20000});
        expect(await page.evaluate(async contactId=>(await Espo.Ajax.getRequest(`Nexa/customer/Contact/${contactId}/foundation`)).liveChat,tenants[0].contactId)).toEqual([]);
        await identifyChat(page,tenants[0].contactId,session);
        const linkedMessages=await page.evaluate(async contactId=>(await Espo.Ajax.getRequest(`Nexa/customer/Contact/${contactId}/foundation`)).liveChat,tenants[0].contactId);
        expect(linkedMessages).toHaveLength(3);expect(linkedMessages.map(message=>message.author)).toContain('staff');
        const contactPage=await page.context().newPage();
        try{
            await contactPage.goto(`${base}/w/${tenants[0].slug}/Contact/view/${tenants[0].contactId}`);
            await contactPage.locator('[data-nexa-tab="activity"]').click();
            const cards=contactPage.locator('[data-nexa-activity-id^="live-chat-"]');
            await expect(cards).toHaveCount(3,{timeout:30000});
            await contactPage.getByRole('combobox',{name:'Activity type',exact:true}).selectOption('live_chat');
            await contactPage.getByRole('searchbox',{name:'Search activities',exact:true}).fill(suffix);
            await expect(cards).toHaveCount(1);const card=cards.first();
            if(await card.locator('[data-nexa-activity-toggle]').getAttribute('aria-expanded')==='false')await card.locator('[data-nexa-activity-toggle]').click();
            await expect(card.locator('.nexa-activity-details')).toContainText(text);
            expect(await contactPage.evaluate(()=>window.chatXss)).toBeUndefined();
            await expect(card.locator('img,[data-nexa-activity-actions]')).toHaveCount(0);
            await expect(card).toHaveCSS('display','block');
            await contactPage.screenshot({path:path.join(root,`test-results/contact-live-chat-${info.project.name}.png`)});
            const remaining=await page.evaluate(async({contactId,visitorKey})=>{
                await Espo.Ajax.postRequest('Nexa/personalization/identity/revoke',{visitorKey});
                return (await Espo.Ajax.getRequest(`Nexa/customer/Contact/${contactId}/foundation`)).liveChat;
            },{contactId:tenants[0].contactId,visitorKey:session.visitorKey});
            expect(remaining).toEqual([]);
        }finally{await contactPage.close();}
        expect((await new AxeBuilder({page}).include('.nexa-chat-workspace').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze()).violations).toEqual([]);
        expect((await new AxeBuilder({page:visitor}).include('[data-nexa-chat-widget]').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze()).violations).toEqual([]);
        await page.screenshot({path:path.join(root,`test-results/live-chat-staff-${info.project.name}.png`)});await visitor.screenshot({path:path.join(root,`test-results/live-chat-widget-${info.project.name}.png`)});
        await visitor.evaluate(()=>document.dispatchEvent(new CustomEvent('nexa:chat-reset')));
        await expect(visitor.getByRole('dialog',{name:'Website chat'})).not.toBeVisible();
        expect(await visitor.evaluate(endpoint=>sessionStorage.getItem('nexa-chat:'+endpoint),endpoint)).toBeNull();
        await expect.poll(async()=>{const response=await visitor.request.post(endpoint,{headers:{Origin:'http://127.0.0.1:8099','X-Nexa-Chat-Token':session.token},data:{op:'read',id:session.id}});return response.status();},{timeout:10000}).toBe(404);
        await launcher.click();await expect(visitor.locator('[data-nexa-chat-widget]').locator('.message')).toHaveCount(0);
    }catch(error){failure=error;}
    finally{await visitorContext?.close();await betaContext?.close();try{await fixture('cleanup',tenants);cleanupTenants=null;}catch(error){if(!failure)failure=error;}}
    if(failure)throw failure;
});
