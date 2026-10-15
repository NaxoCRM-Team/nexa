const {test,expect}=require('@playwright/test');
const {spawn}=require('node:child_process');
const {randomUUID,randomBytes}=require('node:crypto');
const path=require('node:path');
const root=path.resolve(__dirname,'../..');
const base=(process.env.NEXA_LIVE_URL||'').replace(/\/$/,'');
test.use({trace:'off',screenshot:'off'});
function fixture(command,tenants){return new Promise((resolve,reject)=>{
    const child=spawn(process.env.NEXA_PHP_PATH||'php',[path.join(root,'tests/tenant/AutomationAcceptanceFixture.php')],{cwd:root,env:process.env,windowsHide:true,stdio:['pipe','pipe','pipe']});
    let out='',err='';const timer=setTimeout(()=>{child.kill();reject(new Error('Personalization fixture timed out.'));},45000);
    child.stdout.on('data',data=>out+=data);child.stderr.on('data',data=>err+=data);
    child.on('error',error=>{clearTimeout(timer);reject(error);});
    child.on('close',code=>{clearTimeout(timer);if(code!==0)return reject(new Error(`Personalization fixture failed: ${err}`));try{resolve(JSON.parse(out));}catch{reject(new Error('Invalid fixture response.'));}});
    child.stdin.end(JSON.stringify({command,tenants})+'\n');
});}
test('Authenticated landing authoring delivers isolated public contextual variants',async({page,browser})=>{
    test.setTimeout(120000);test.skip(process.env.NEXA_AUTOMATION_ACCEPTANCE!=='1'||!base,'Use the explicit local acceptance runner.');
    expect(['localhost','127.0.0.1']).toContain(new URL(base).hostname);
    const suffix=randomBytes(6).toString('hex');let tenants=['a','b'].map(label=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${label}`}));let publicContext,failure;
    try {
        tenants=await fixture('setup',tenants);
        await page.goto(`${base}/login/`);await page.locator('#field-userName').fill(tenants[0].username);await page.locator('#field-password').fill(tenants[0].password);await page.locator('#login-form button[type="submit"]').click();
        await page.waitForURL(new RegExp(`/w/${tenants[0].slug}(?:/.*)?$`));
        await page.goto(`${base}/w/${tenants[0].slug}/NexaLandingPages`);
        await page.locator('[data-action="new-page"]').click();
        await page.locator('[data-action="start-blank"]').click();
        await page.locator('[data-page-settings] [name="name"]').fill('Live contextual page');
        await page.locator('[data-page-settings] [name="seoTitle"]').fill('Live contextual page');
        await page.locator('[data-builder-canvas] .nexa-preview-block').first().click();
        const rule=page.locator('[data-contextual-variant]');
        await rule.getByRole('combobox',{name:'Match visitor context'}).selectOption('utm_source');
        await rule.getByRole('textbox',{name:'Matching value'}).fill('newsletter');
        await rule.getByRole('textbox',{name:'Variant heading'}).fill('Newsletter visitor offer');
        await rule.getByRole('textbox',{name:'Variant body text'}).fill('A contextual offer without identifying the visitor.');
        await page.locator('[data-action="publish-page"]').click();
        await expect.poll(async()=>page.evaluate(async()=>{
            const workspace=await Espo.Ajax.getRequest('Nexa/landing-pages/workspace');return workspace.pages.some(row=>row.name==='Live contextual page'&&row.status==='published');
        })).toBe(true);
        const url=await page.evaluate(async()=>{const workspace=await Espo.Ajax.getRequest('Nexa/landing-pages/workspace');return workspace.pages.find(row=>row.name==='Live contextual page').url;});
        expect(url).toBeTruthy();
        publicContext=await browser.newContext();
        const visitor=await publicContext.newPage();
        await visitor.goto(`${url}?utm_source=newsletter`);await expect(visitor.getByRole('heading',{name:'Newsletter visitor offer',exact:true})).toBeVisible();
        await visitor.goto(url);await expect(visitor.getByRole('heading',{name:'Newsletter visitor offer',exact:true})).toHaveCount(0);
        const response=await publicContext.request.get(`${url}?utm_source=newsletter`,{headers:{'Sec-GPC':'1'}});
        expect(response.status()).toBe(200);expect(response.headers()['cache-control']).toContain('no-store');expect(await response.text()).not.toContain('Newsletter visitor offer');
        const spoof=await publicContext.request.get(`${url}?contactId=${tenants[0].contactId}`);expect(await spoof.text()).not.toContain('Newsletter visitor offer');
        await page.goto(`${base}/w/${tenants[0].slug}/NexaPersonalization`);
        await expect(page.getByRole('heading',{name:'Personalization',exact:true})).toBeVisible();
        await page.getByRole('combobox',{name:'Source',exact:true}).selectOption('landing');
        await expect(page.locator('[data-rows]')).toContainText('Live contextual page');
        await page.locator('[data-rows] summary').first().click();
        await expect(page.locator('[data-rows] details').first()).toContainText('newsletter');
        const history=await page.evaluate(()=>Espo.Ajax.getRequest('Nexa/personalization/history',{source:'landing'}));
        expect(history.list.length).toBeGreaterThan(0);expect(history.list.every(row=>row.clock==='UTC')).toBe(true);
        expect(history.policy.editable).toBe(false);
        const customerResult=await page.evaluate(async({contactId,visitorId})=>{
            const workspace=await Espo.Ajax.getRequest('Nexa/consent/cookies/workspace');
            const banner={...workspace.banner,isPublished:true};
            await Espo.Ajax.putRequest('Nexa/consent/cookies/settings',banner);
            await Espo.Ajax.postRequest('Nexa/public/cookies/receipts',{publicKey:banner.publicKey,visitorId,receiptKey:crypto.randomUUID(),choice:'custom',categories:{preferences:true,advertising:true}});
            const contact=await Espo.Ajax.getRequest(`Contact/${contactId}`);
            if(!contact.leadStatus)await Espo.Ajax.putRequest(`Contact/${contactId}`,{leadStatus:'New'});
            const created=await Espo.Ajax.postRequest('Nexa/landing-pages',{name:'Live customer page',slug:`customer-${visitorId}`,locale:'en',blocks:[{id:'customer-hero',type:'hero',heading:'Public default',text:'Default body',personalization:{field:'customer:leadStatus',value:contact.leadStatus||'New',heading:'Verified customer offer',text:'Customer body'}}]});
            await Espo.Ajax.postRequest(`Nexa/landing-pages/${created.id}/publish`);
            const input={pageId:created.id,visitorKey:visitorId};
            const before=await Espo.Ajax.postRequest('Nexa/personalization/resolve',input);
            const identified=await Espo.Ajax.postRequest('Nexa/personalization/identify',{visitorKey:visitorId,contactId,method:'authenticated_session',reference:`browser-acceptance-${visitorId}`});
            const matched=await Espo.Ajax.postRequest('Nexa/personalization/resolve',input);
            const privacy=await Espo.Ajax.postRequest('Nexa/personalization/resolve',{...input,privacyControl:true});
            await Espo.Ajax.postRequest('Nexa/personalization/identity/revoke',{visitorKey:visitorId});
            const revoked=await Espo.Ajax.postRequest('Nexa/personalization/resolve',input);
            return {before:before.blocks[0].heading,verified:identified.verified,matched:matched.blocks[0].heading,privacy:privacy.blocks[0].heading,revoked:revoked.blocks[0].heading};
        },{contactId:tenants[0].contactId,visitorId:randomUUID()});
        expect(customerResult).toEqual({before:'Public default',verified:true,matched:'Verified customer offer',privacy:'Public default',revoked:'Public default'});
    } catch(error){failure=error;} finally {
        await publicContext?.close();try{await fixture('cleanup',tenants);}catch(error){if(!failure)failure=error;}
    }
    if(failure)throw failure;
});
