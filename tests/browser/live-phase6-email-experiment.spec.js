const {test,expect} = require('@playwright/test');
const {spawn} = require('node:child_process');
const {randomUUID,randomBytes} = require('node:crypto');
const path = require('node:path');
const root = path.resolve(__dirname,'../..');
const baseURL = (process.env.NEXA_LIVE_URL || '').replace(/\/$/,'');
test.use({trace:'off',screenshot:'off'});

function fixture(command,tenants,options={}) {
    return new Promise((resolve,reject)=>{
        const child=spawn(process.env.NEXA_PHP_PATH || 'php',['-d','display_errors=stderr','-d','log_errors=0',path.join(root,'tests/tenant/AutomationAcceptanceFixture.php')],{cwd:root,env:process.env,windowsHide:true,stdio:['pipe','pipe','pipe']});
        let output='',errors='';
        const timeout=setTimeout(()=>{child.kill();reject(new Error(`Fixture ${command} timed out.`));},45_000);
        child.stdout.on('data',chunk=>{output+=chunk;});child.stderr.on('data',chunk=>{errors+=chunk;});
        child.on('error',error=>{clearTimeout(timeout);reject(error);});
        child.on('close',code=>{clearTimeout(timeout);if(code!==0)return reject(new Error(`Fixture ${command} failed (${code}): ${errors}`));try{resolve(JSON.parse(output));}catch{reject(new Error(`Fixture ${command} returned invalid JSON.`));}});
        child.stdin.end(JSON.stringify({command,tenants,...options})+'\n');
    });
}
async function api(page,method,url,data) {
    return page.evaluate(async ({method,url,data})=>{
        try{return {ok:true,value:await Espo.Ajax[`${method}Request`](url,data)};}
        catch(error){return {ok:false,status:error.status || error.statusCode || null};}
    },{method,url,data});
}

test('Phase 6 authenticated email experiment captures stable test cohorts and winner rollout without replay duplicates',async ({page,browser})=>{
    test.setTimeout(240_000);
    test.skip(process.env.NEXA_PHASE6_CLOSURE_ACCEPTANCE!=='1' || process.env.NEXA_POSTAL_CAPTURE_ACCEPTANCE!=='1' || !baseURL,'Run -Suite Experiment -LocalPostalCapture against local WAMP and Postal.');
    expect(['localhost','127.0.0.1']).toContain(new URL(baseURL).hostname);
    const suffix=randomBytes(6).toString('hex');
    let tenants=['a','b'].map(label=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${label}`}));
    const call=(command,options={})=>fixture(command,tenants,options);
    const success=async (method,url,data)=>{const response=await api(page,method,url,data);expect(response.ok,`${method} ${url} must succeed`).toBe(true);return response.value;};
    let anonymous,failure;
    try {
        tenants=await call('setup');
        const setup=await call('programmable-setup',{capture:true});
        const audience=await call('experiment-setup');
        expect(audience.contactIds).toHaveLength(8);
        await page.goto(`${baseURL}/login/`);
        await page.locator('#field-userName').fill(tenants[0].username);
        await page.locator('#field-password').fill(tenants[0].password);
        await page.locator('#login-form button[type="submit"]').click();
        await page.waitForURL(new RegExp(`/w/${tenants[0].slug}(?:/.*)?$`),{timeout:30_000});
        await page.goto(`${baseURL}/w/${tenants[0].slug}/NexaWorkflows`);
        await expect(page.getByRole('button',{name:'New workflow',exact:true})).toBeEnabled({timeout:30_000});
        const reference='AB-'+suffix;
        const variants=[];
        for(const key of ['A','B']) {
            const draft=await success('post','Nexa/marketing-emails',{name:`Live variant ${key} ${suffix}`,deliveryMode:'campaign',campaignId:setup.campaignId,purposeId:setup.purposeId,subject:`${reference} ${key}`,bodyHtml:`<p>Variant ${key} for {Contact.firstName}</p>`,fromAddress:'newsletter@nexa.local.test',fromName:'Synthetic acceptance',startAt:new Date(Date.now()+3600_000).toISOString().slice(0,19).replace('T',' ')});
            for(const action of ['submit','approve'])await success('post',`Nexa/marketing-emails/${draft.id}/transition`,{action});
            variants.push(draft.id);
        }
        await page.goto(`${baseURL}/w/${tenants[0].slug}/NexaEmailExperiments`);
        await expect(page.getByRole('heading',{name:'Email experiments',exact:true})).toBeVisible({timeout:30_000});
        await expect(page.getByRole('button',{name:'New experiment',exact:true})).toBeEnabled();
        await expect(page.locator('.nexa-experiments [data-loading]')).toBeHidden();
        expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1)).toBe(true);
        const payload={name:`Live experiment ${suffix}`,sourceA:variants[0],versionA:1,sourceB:variants[1],versionB:1,testPercent:50,holdoutPercent:25,windowMinutes:1};
        expect((await api(page,'post','Nexa/email-experiments',{...payload,versionA:99})).ok).toBe(false);
        const experiment=await success('post','Nexa/email-experiments',payload);
        await page.locator('.nexa-experiments [data-action="refresh"]').click();
        await expect(page.getByRole('button',{name:payload.name,exact:true})).toBeVisible();
        await success('post',`Nexa/email-experiments/${experiment.id}/control`,{action:'start'});
        const before=await call('experiment-inspect',{experimentId:experiment.id});
        expect(before).toHaveLength(8);
        expect(new Set(before.map(row=>row.contact_id)).size).toBe(8);
        expect(before.filter(row=>row.cohort==='test')).toHaveLength(4);
        expect(before.filter(row=>row.cohort==='holdout')).toHaveLength(2);
        expect(before.filter(row=>row.cohort==='rollout')).toHaveLength(2);
        expect(before.filter(row=>row.cohort==='test' && row.variant_key==='A')).toHaveLength(2);
        expect(before.filter(row=>row.cohort==='test' && row.variant_key==='B')).toHaveLength(2);
        expect((await api(page,'post',`Nexa/marketing-emails/${variants[0]}/schedule`,{})).ok).toBe(false);
        await call('experiment-worker');
        expect((await success('get',`Nexa/email-experiments/${experiment.id}`)).experiment.status).toBe('awaiting_winner');
        expect((await api(page,'post',`Nexa/email-experiments/${experiment.id}/control`,{action:'winner',variant:'A',reason:'Live acceptance winner'})).ok).toBe(false);
        await call('experiment-capture-worker');
        anonymous=await browser.newContext();
        const captures=async ()=>{
            const response=await anonymous.request.get('http://localhost:8026/api/v1/messages');expect(response.ok()).toBe(true);
            return (await response.json()).messages.filter(message=>message.Subject.startsWith(reference+' '));
        };
        await expect.poll(async ()=>(await captures()).length,{timeout:60_000,intervals:[500,1000,2000]}).toBe(4);
        const testAssignments=await call('experiment-inspect',{experimentId:experiment.id});
        expect(testAssignments.filter(row=>row.cohort==='test').every(row=>row.status==='queued')).toBe(true);
        expect(testAssignments.filter(row=>row.cohort!=='test').every(row=>row.status==='pending')).toBe(true);
        const stable=rows=>rows.map(({contact_id,cohort,variant_key})=>({contact_id,cohort,variant_key}));
        expect(stable(testAssignments)).toEqual(stable(before));
        await call('experiment-worker');await call('experiment-capture-worker');
        expect(await call('experiment-inspect',{experimentId:experiment.id})).toEqual(testAssignments);
        expect(await captures()).toHaveLength(4);
        await call('experiment-expire-window',{experimentId:experiment.id});
        await success('post',`Nexa/email-experiments/${experiment.id}/control`,{action:'winner',variant:'A',reason:'Explicit fixture decision after bounded measurement window'});
        await call('experiment-worker');await call('experiment-capture-worker');
        await expect.poll(async ()=>(await captures()).length,{timeout:60_000,intervals:[500,1000,2000]}).toBe(6);
        const final=await call('experiment-inspect',{experimentId:experiment.id});
        expect(final.filter(row=>row.cohort==='rollout').every(row=>row.variant_key==='A' && row.status==='queued')).toBe(true);
        expect(final.filter(row=>row.cohort==='holdout').every(row=>row.status==='pending' && row.queue_item_id===null)).toBe(true);
        const queued=final.filter(row=>row.status==='queued');
        expect(new Set(queued.map(row=>row.queue_item_id)).size).toBe(6);
        const messages=await captures();const recipients=[];
        for(const message of messages) {
            const detail=await (await anonymous.request.get(`http://localhost:8026/api/v1/message/${encodeURIComponent(message.ID)}`)).json();
            recipients.push(detail.To[0].Address);
            expect(detail.From.Address).toBe('newsletter@nexa.local.test');
            expect(detail.HTML).toContain('for Acceptance');
            const raw=await (await anonymous.request.get(`http://localhost:8026/api/v1/message/${encodeURIComponent(message.ID)}/raw`)).text();
            expect(queued.some(row=>raw.includes(`X-Nexa-Queue-Item: ${row.queue_item_id}`))).toBe(true);
        }
        expect(new Set(recipients).size).toBe(6);
        for(const holdout of final.filter(row=>row.cohort==='holdout'))expect(recipients).not.toContain(audience.recipients[holdout.contact_id]);
        const detail=await success('get',`Nexa/email-experiments/${experiment.id}`);
        expect(detail.experiment.status).toBe('completed');expect(detail.experiment.winner_variant).toBe('A');
        await call('experiment-worker');await call('experiment-capture-worker');
        expect(await captures()).toHaveLength(6);
        expect(await call('experiment-inspect',{experimentId:experiment.id})).toEqual(final);
    }catch(error){failure=error;throw error;}
    finally {
        if(anonymous)await anonymous.close();
        try{await call('cleanup');}catch(error){if(failure)throw new AggregateError([failure,error],'Experiment acceptance and cleanup failed.');throw error;}
    }
});
