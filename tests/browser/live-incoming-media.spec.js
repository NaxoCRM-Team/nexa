const {test,expect:baseExpect}=require('@playwright/test');
const expect=baseExpect.configure({timeout:20000});
const {spawn}=require('node:child_process');
const {randomUUID,randomBytes}=require('node:crypto');
const path=require('node:path');
const AxeBuilder=require('@axe-core/playwright').default;
const root=path.resolve(__dirname,'../..'),base=(process.env.NEXA_LIVE_URL||'').replace(/\/$/,'');
test.use({trace:'off',screenshot:'off',actionTimeout:20000,navigationTimeout:30000});
function fixture(command,tenants){return new Promise((resolve,reject)=>{const child=spawn(process.env.NEXA_PHP_PATH||'php',[path.join(root,'tests/tenant/AutomationAcceptanceFixture.php')],{cwd:root,env:process.env,windowsHide:true});let out='',err='';const timer=setTimeout(()=>child.kill(),60000);child.stdout.on('data',v=>out+=v);child.stderr.on('data',v=>err+=v);child.on('error',reject);child.on('close',code=>{clearTimeout(timer);if(code)return reject(new Error(err||'Incoming file fixture failed'));try{resolve(JSON.parse(out));}catch{reject(new Error('Invalid fixture response'));}});child.stdin.end(JSON.stringify({command,tenants})+'\n');});}
async function login(page,tenant){await page.goto(`${base}/login/`);await page.locator('#field-userName').fill(tenant.username);await page.locator('#field-password').fill(tenant.password);await page.locator('#login-form button[type="submit"]').click();await expect(page).toHaveURL(new RegExp(`/w/${tenant.slug}(?:/.*)?$`),{timeout:60000});await page.waitForFunction(()=>document.body.classList.contains('nexa-shell-ready'),null,{timeout:60000});}
test('Private SMS and WhatsApp attachments progress into previews and tenant-library downloads',async({page,browser},info)=>{
    test.setTimeout(240000);test.skip(process.env.NEXA_AUTOMATION_ACCEPTANCE!=='1'||!base,'Explicit local acceptance required.');expect(['localhost','127.0.0.1']).toContain(new URL(base).hostname);
    const suffix=randomBytes(6).toString('hex');let tenants=['a','b'].map(v=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${v}`})),created=false,foreignContext;
    try{
        tenants=await fixture('setup',tenants);created=true;const seed=await fixture('incoming-media-seed',tenants);await login(page,tenants[0]);
        await page.goto(`${base}/w/${tenants[0].slug}/NexaConversations/sms`);await page.locator(`.nc-conversation[data-id="${seed.sms.rootId}"]`).click();await expect(page.locator('.incoming-channel-media')).toContainText('processing');
        const workers=await Promise.all([fixture('incoming-media-run',tenants),fixture('incoming-media-run',tenants)]);expect(workers.reduce((n,r)=>n+r.fetched,0)).toBe(2);expect((await fixture('incoming-media-run',tenants)).ready).toBe(2);
        for(const channel of ['sms','whatsapp']){
            await page.goto(`${base}/w/${tenants[0].slug}/NexaConversations/${channel}`);await page.locator(`.nc-conversation[data-id="${seed[channel].rootId}"]`).click();
            const media=page.locator(`.incoming-channel-media[data-media-id="${seed[channel].mediaId}"]`);await media.getByRole('button').click();await expect(media.locator('img')).toHaveJSProperty('naturalWidth',64);
            const pixels=await media.locator('img').evaluate(img=>{const canvas=document.createElement('canvas');canvas.width=1;canvas.height=1;canvas.getContext('2d').drawImage(img,0,0);return [...canvas.getContext('2d').getImageData(0,0,1,1).data];});expect(pixels).toEqual([20,125,112,255]);
            const download=page.waitForEvent('download');await media.getByRole('link',{name:'Download',exact:true}).click();expect((await download).suggestedFilename()).toMatch(/^received-[a-f0-9]+\.png$/);
            expect((await new AxeBuilder({page}).include('.nexa-conversations').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze()).violations).toEqual([]);
            await media.locator('img').scrollIntoViewIfNeeded();await expect(media.locator('img')).toBeInViewport();
            await page.locator('.nexa-conversations').screenshot({path:path.join(root,`test-results/incoming-${channel}-${info.project.name}.png`)});
        }
        const files=await page.evaluate(()=>Espo.Ajax.getRequest('Nexa/files',{search:'received-'}));expect(files.list).toHaveLength(2);
        foreignContext=await browser.newContext();const foreign=await foreignContext.newPage();await login(foreign,tenants[1]);
        expect(await foreign.evaluate(async f=>{try{await Espo.Ajax.postRequest('Nexa/messaging/conversations',{op:'media',id:f.rootId,mediaId:f.mediaId});return 200;}catch(e){return e.status;}},seed.sms)).toBe(404);
        expect((await foreign.evaluate(()=>Espo.Ajax.getRequest('Nexa/files',{search:'received-'}))).list).toEqual([]);
    }finally{await foreignContext?.close();if(created)await fixture('cleanup',tenants);}
});
