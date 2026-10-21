const {test,expect}=require('@playwright/test');
const {spawn}=require('node:child_process');
const {randomUUID,randomBytes}=require('node:crypto');
const path=require('node:path');
const AxeBuilder=require('@axe-core/playwright').default;
const root=path.resolve(__dirname,'../..'),base=(process.env.NEXA_LIVE_URL||'').replace(/\/$/,'');
function fixture(command,tenants,extra={}){return new Promise((resolve,reject)=>{const child=spawn(process.env.NEXA_PHP_PATH||'php',[path.join(root,'tests/tenant/AutomationAcceptanceFixture.php')],{cwd:root,env:process.env,windowsHide:true});let out='',err='';const timer=setTimeout(()=>child.kill(),60000);child.stdout.on('data',v=>out+=v);child.stderr.on('data',v=>err+=v);child.on('error',reject);child.on('close',code=>{clearTimeout(timer);if(code)return reject(new Error(err||'Routing fixture failed'));try{resolve(JSON.parse(out));}catch{reject(new Error('Invalid fixture output'));}});child.stdin.end(JSON.stringify({command,tenants,...extra})+'\n');});}
test('Native team routing, explicit availability, concurrent workers and queue recovery',async({page},info)=>{
    test.setTimeout(180000);test.skip(process.env.NEXA_AUTOMATION_ACCEPTANCE!=='1'||!base,'Explicit local acceptance required.');expect(['localhost','127.0.0.1']).toContain(new URL(base).hostname);
    const suffix=randomBytes(6).toString('hex');let tenants=['a','b'].map(v=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${v}`})),created=false;
    try{
        tenants=await fixture('setup',tenants);created=true;await fixture('routing-seed',tenants);
        await page.goto(`${base}/login/`);await page.locator('#field-userName').fill(tenants[0].username);await page.locator('#field-password').fill(tenants[0].password);await page.locator('#login-form button[type="submit"]').click();await expect(page).toHaveURL(new RegExp(`/w/${tenants[0].slug}(?:/.*)?$`),{timeout:60000});await page.waitForFunction(()=>document.body.classList.contains('nexa-shell-ready'),null,{timeout:60000});
        await page.goto(`${base}/w/${tenants[0].slug}/NexaConversations/routing`);await expect(page.getByRole('heading',{name:'Conversation Routing',exact:true})).toBeVisible();await expect(page.locator('.routing-rule')).toHaveCount(4);
        await expect(page.locator('.routing-rule[data-channel="chat"] input[name="enabled"]')).toBeChecked();
        expect((await new AxeBuilder({page}).include('.nexa-conversation-routing').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze()).violations).toEqual([]);
        await page.locator('.nexa-conversation-routing').screenshot({path:path.join(root,`test-results/routing-settings-${info.project.name}.png`)});
        await page.goto(`${base}/w/${tenants[0].slug}/NexaConversations/chat`);const available=page.getByRole('checkbox',{name:'Available for conversations',exact:true});await expect(available).not.toBeChecked();
        expect((await fixture('routing-run',tenants)).assigned).toBe(0);
        const presence=page.waitForResponse(r=>r.url().includes('Nexa/conversation-routing')&&r.request().postDataJSON()?.available===true);await available.check();expect((await presence).status()).toBe(200);
        const workers=await Promise.all([fixture('routing-run',tenants),fixture('routing-run',tenants)]);expect(workers.reduce((total,r)=>total+r.assigned,0)).toBe(1);
        let state=await fixture('routing-state',tenants);expect(state.assignments).toBe(1);expect(state.list.filter(row=>row.assignedUserId===tenants[0].userId)).toHaveLength(1);expect(state.list.filter(row=>!row.assignedUserId)).toHaveLength(1);expect(state.list.every(row=>row.answeredAt===null)).toBe(true);
        const assigned=state.list.find(row=>row.assignedUserId);await page.getByRole('button',{name:'Refresh chat',exact:true}).click();await page.locator(`.nc-conversation[data-id="${assigned.id}"]`).click();await expect(page.locator('.chat-handoff-status')).toHaveText('Agent assigned; awaiting reply');
        await page.locator('.chat-status').selectOption('Closed');const saved=page.waitForResponse(r=>r.url().includes('/Nexa/chat')&&r.request().postDataJSON()?.op==='update');await page.getByRole('button',{name:'Update chat state',exact:true}).click();expect((await saved).status()).toBe(200);
        expect((await fixture('routing-run',tenants)).assigned).toBe(1);state=await fixture('routing-state',tenants);expect(state.assignments).toBe(2);
        await page.locator('.nexa-chat-workspace').screenshot({path:path.join(root,`test-results/routing-inbox-${info.project.name}.png`)});
        await available.uncheck();await expect(available).not.toBeChecked();
    }finally{if(created)await fixture('cleanup',tenants);}
});
