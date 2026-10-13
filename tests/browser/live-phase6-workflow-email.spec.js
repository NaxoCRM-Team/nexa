const {test, expect} = require('@playwright/test');
const {spawn} = require('node:child_process');
const {randomUUID, randomBytes} = require('node:crypto');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const baseURL = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
test.use({trace:'off', screenshot:'off'});

function fixture(command, tenants, options = {}) {
    return new Promise((resolve, reject) => {
        const child = spawn(process.env.NEXA_PHP_PATH || 'php', ['-d','display_errors=stderr','-d','log_errors=0',path.join(root,'tests/tenant/AutomationAcceptanceFixture.php')], {
            cwd:root, env:process.env, windowsHide:true, stdio:['pipe','pipe','pipe'],
        });
        let output = '', errors = '';
        const timeout = setTimeout(() => {child.kill(); reject(new Error(`Fixture ${command} timed out.`));},45_000);
        child.stdout.on('data', data => {output += data;});
        child.stderr.on('data', data => {errors += data;});
        child.on('error', error => {clearTimeout(timeout); reject(error);});
        child.on('close', code => {
            clearTimeout(timeout);
            if (code !== 0) return reject(new Error(`Fixture ${command} failed (${code}): ${errors}`));
            try {resolve(JSON.parse(output));} catch {reject(new Error(`Fixture ${command} returned invalid JSON.`));}
        });
        child.stdin.end(JSON.stringify({command,tenants,...options})+'\n');
    });
}

async function api(page, method, url, data) {
    const result = await page.evaluate(async ({method,url,data}) => {
        try {return {ok:true,value:await Espo.Ajax[`${method}Request`](url,data)};}
        catch (error) {return {ok:false,status:error.status || error.statusCode || null};}
    },{method,url,data});
    expect(result.ok, `${method} ${url} must succeed`).toBe(true);
    return result.value;
}

test('Phase 6 authenticated workflow email, Postal callback, branch and cancellation close the local chain', async ({page,browser}) => {
    test.setTimeout(180_000);
    test.skip(process.env.NEXA_PHASE6_CLOSURE_ACCEPTANCE!=='1' || process.env.NEXA_POSTAL_CAPTURE_ACCEPTANCE!=='1' || !baseURL,
        'Run the local runner with -Suite Closure -LocalPostalCapture.');
    expect(['localhost','127.0.0.1']).toContain(new URL(baseURL).hostname);
    const suffix = randomBytes(6).toString('hex');
    let tenants = ['a','b'].map(label=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${label}`}));
    const call = (command, options={}) => fixture(command,tenants,options);
    let anonymous, failure;
    try {
        tenants = await call('setup');
        const source = await call('programmable-setup',{capture:true});
        await page.goto(`${baseURL}/login/`);
        await page.locator('#field-userName').fill(tenants[0].username);
        await page.locator('#field-password').fill(tenants[0].password);
        await page.locator('#login-form button[type="submit"]').click();
        await page.waitForURL(new RegExp(`/w/${tenants[0].slug}(?:/.*)?$`),{timeout:30_000});
        await page.goto(`${baseURL}/w/${tenants[0].slug}/NexaWorkflows`);
        await expect(page.getByRole('button',{name:'New workflow',exact:true})).toBeEnabled({timeout:30_000});
        for (const action of ['submit','approve']) await api(page,'post',`Nexa/marketing-emails/${source.sourceId}/transition`,{action});
        const reference = 'WORKFLOW-'+randomBytes(8).toString('hex');
        const definition = {
            schemaVersion:1,trigger:{event:'manual',reenrollment:'never'},entry:'send',nodes:[
                {id:'send',type:'action',action:'email.send',settings:{sourceId:source.sourceId,version:1,parameters:{reference},parameterBindings:{message:{field:'firstName',fallback:'Customer'}}},maxAttempts:1,retrySeconds:60,next:'delivery'},
                {id:'delivery',type:'wait_condition',condition:{field:'behavior:email.clicked',operator:'within_days',value:'1'},timeoutSeconds:3600,pollSeconds:60,yes:'update',no:'end'},
                {id:'update',type:'action',action:'score.increase',settings:{amount:7},maxAttempts:2,retrySeconds:60,next:'end'},
                {id:'end',type:'end'},
            ],
        };
        const create = async name => {
            const draft = await api(page,'post','Nexa/workflows',{name,definition});
            await api(page,'post',`Nexa/workflows/${draft.id}/publish`,{expectedRevision:draft.draftRevision});
            return draft.id;
        };
        const workflowId = await create(`Closure mail ${suffix}`);
        const run = await api(page,'post',`Nexa/workflows/${workflowId}/enroll`,{contactId:tenants[0].contactId,requestKey:randomUUID()});
        await call('tick',{ticks:2});
        let evidence = await call('inspect',{workflowId});
        expect(evidence.runs.find(row=>row.id===run.id).status).toBe('waiting');
        expect(evidence.score).toBe(10);
        let mail = await call('programmable-inspect');
        expect(mail.queues).toHaveLength(1);
        expect(mail.copies[0].subject).toBe('Acceptance '+reference);
        expect(mail.copies[0].body).toContain('Acceptance');
        expect(mail.copies[0].body).not.toContain('[[nexa:');
        await call('programmable-capture-worker');
        mail = await call('programmable-inspect');
        expect(mail.messages).toHaveLength(1);
        expect(mail.messages[0].status).toBe('submitted');
        anonymous = await browser.newContext();
        let captured = [];
        await expect.poll(async () => {
            const response = await anonymous.request.get('http://localhost:8026/api/v1/messages');
            expect(response.ok()).toBe(true);
            captured = (await response.json()).messages.filter(item=>item.Subject==='Acceptance '+reference);
            return captured.length;
        },{timeout:60_000,intervals:[500,1000,2000]}).toBe(1);
        const detail = await (await anonymous.request.get(`http://localhost:8026/api/v1/message/${encodeURIComponent(captured[0].ID)}`)).json();
        expect(detail.To[0].Address).toBe(tenants[0].slug+'-recipient@example.test');
        expect(detail.HTML).toContain('Acceptance Acceptance');
        const raw = await (await anonymous.request.get(`http://localhost:8026/api/v1/message/${encodeURIComponent(captured[0].ID)}/raw`)).text();
        expect(raw).toContain(`X-Nexa-Queue-Item: ${mail.queues[0].id}`);
        const callbackURL = `${baseURL}/api/v1/Nexa/public/marketing-delivery/postal/${source.callbackToken}`;
        const event = type => ({uuid:randomUUID(),event:type,payload:{timestamp:new Date().toISOString(),message:{message_id:mail.messages[0].provider_message_id,to:tenants[0].slug+'-recipient@example.test'},url:'https://example.test/closure'}});
        const submit = async payload => {
            const response = await anonymous.request.post(callbackURL,{data:payload});
            expect(response.status()).toBe(200);
            return response.json();
        };
        const delivery = event('MessageSent');
        const rejected = await anonymous.request.post(`${baseURL}/api/v1/Nexa/public/marketing-delivery/postal/${'0'.repeat(48)}`,{data:delivery});
        expect(rejected.status()).toBe(403);
        const mismatch = {...delivery,uuid:randomUUID(),payload:{...delivery.payload,message:{...delivery.payload.message,to:'not-the-recipient@example.test'}}};
        expect((await anonymous.request.post(callbackURL,{data:mismatch})).status()).toBe(400);
        expect((await submit(delivery)).duplicate).toBe(false);
        expect((await submit(delivery)).duplicate).toBe(true);
        await call('closure-wake',{workflowId});
        await call('tick');
        expect((await call('inspect',{workflowId})).score).toBe(10);
        const click = event('MessageLinkClicked');
        expect((await submit(click)).duplicate).toBe(false);
        expect((await submit(click)).duplicate).toBe(true);
        await call('closure-wake',{workflowId});
        await call('tick',{ticks:3});
        evidence = await call('inspect',{workflowId});
        expect(evidence.runs.find(row=>row.id===run.id).status).toBe('completed');
        expect(evidence.score).toBe(17);
        expect(evidence.events.filter(row=>row.node_id==='update' && row.event_type==='step.completed')).toHaveLength(1);
        const canceledId = await create(`Canceled closure mail ${suffix}`);
        const canceled = await api(page,'post',`Nexa/workflows/${canceledId}/enroll`,{contactId:tenants[0].contactId,requestKey:randomUUID()});
        await api(page,'post',`Nexa/workflows/${canceledId}/runs/${canceled.id}/cancel`,{});
        await call('tick',{ticks:3});
        await call('programmable-capture-worker');
        expect((await call('inspect',{workflowId:canceledId})).runs[0].status).toBe('cancelled');
        expect((await call('inspect',{workflowId})).score).toBe(17);
        expect((await call('programmable-inspect')).queues).toHaveLength(1);
        const final = await (await anonymous.request.get('http://localhost:8026/api/v1/messages')).json();
        expect(final.messages.filter(item=>item.Subject==='Acceptance '+reference)).toHaveLength(1);
    } catch (error) {failure=error; throw error;}
    finally {
        if (anonymous) await anonymous.close();
        try {await call('cleanup');}
        catch (error) {if (failure) throw new AggregateError([failure,error],'Closure scenario and cleanup failed.'); throw error;}
    }
});
